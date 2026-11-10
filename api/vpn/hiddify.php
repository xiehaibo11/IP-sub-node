<?php
require_once __DIR__ . '/../../private/app_config.php';
require_once __DIR__ . '/../../private/subscription_auth.php';
require_subscription_access();
require_once __DIR__ . '/../../private/subscription_client.php';
require_once __DIR__ . '/../../private/subscription_nodes.php';

function respond_json_error($code, $message) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

try {
    $client = subscription_client();
    if (!$client) {
        respond_json_error(404, 'subscription not found');
    }

    ['vless' => $node, 'hy2' => $hy2, 'ws' => $mobileWs] = subscription_nodes($client);
    $tag = $node['name'];
    $hy2Tag = $hy2['name'] ?? null;
    $mobileWsTag = $mobileWs['name'] ?? null;

    $vlessOutbound = [
        'type' => 'vless',
        'tag' => $tag,
        'server' => $node['address'],
        'server_port' => (int)$node['port'],
        'uuid' => $node['uuid'],
        'packet_encoding' => 'xudp',
        'connect_timeout' => '5s',
        'tcp_fast_open' => true,
        'tls' => [
            'enabled' => true,
            'server_name' => $node['sni'],
            'utls' => [
                'enabled' => true,
                'fingerprint' => 'chrome'
            ]
        ]
    ];

    $finalOutbound = 'PROXY';
    $autoChoices = [];
    if ($hy2) $autoChoices[] = $hy2Tag;
    $autoChoices[] = $tag;
    if ($mobileWs) $autoChoices[] = $mobileWsTag;
    $outbounds = [
        [
            'type' => 'selector',
            'tag' => 'PROXY',
            'outbounds' => array_merge(['AUTO'], $autoChoices),
            'default' => 'AUTO'
        ],
        [
            'type' => 'urltest',
            'tag' => 'AUTO',
            'outbounds' => $autoChoices,
            'url' => 'https://www.gstatic.com/generate_204',
            'interval' => '30s',
            'tolerance' => 30,
            'interrupt_exist_connections' => false
        ]
    ];
    if ($hy2) {
        $hy2Outbound = [
            'type' => 'hysteria2',
            'tag' => $hy2Tag,
            'server' => $hy2['address'],
            'server_port' => (int)$hy2['port'],
            'password' => $hy2['password'],
            'obfs' => [
                'type' => 'salamander',
                'password' => $hy2['obfs_password']
            ],
            'tls' => [
                'enabled' => true,
                'server_name' => $hy2['sni'],
                'alpn' => ['h3']
            ]
        ];
        // Each rate on its own: a fixed upload rate wedges idle connections.
        if ($hy2['up_mbps'] > 0) {
            $hy2Outbound['up_mbps'] = $hy2['up_mbps'];
        }
        if ($hy2['down_mbps'] > 0) {
            $hy2Outbound['down_mbps'] = $hy2['down_mbps'];
        }
        $outbounds[] = $hy2Outbound;
    }
    if ($mobileWs) {
        $outbounds[] = [
            'type' => 'vless',
            'tag' => $mobileWs['name'],
            'server' => $mobileWs['address'],
            'server_port' => (int)$mobileWs['port'],
            'uuid' => $mobileWs['uuid'],
            'packet_encoding' => 'xudp',
            'connect_timeout' => '5s',
            'tcp_fast_open' => true,
            'tls' => [
                'enabled' => true,
                'server_name' => $mobileWs['sni'],
                'utls' => [
                    'enabled' => true,
                    'fingerprint' => 'chrome'
                ]
            ],
            'transport' => [
                'type' => 'ws',
                'path' => $mobileWs['path'],
                'headers' => [
                    'Host' => $mobileWs['host']
                ]
            ]
        ];
    }
    $outbounds[] = $vlessOutbound;

    $config = [
        'log' => [
            'level' => 'warn',
            'timestamp' => true
        ],
        'dns' => [
            'servers' => [
                [
                    'tag' => 'cloudflare',
                    'type' => 'https',
                    'server' => '1.1.1.1',
                    'detour' => $finalOutbound,
                    'tls' => [
                        'enabled' => true,
                        'server_name' => 'cloudflare-dns.com'
                    ]
                ],
                [
                    'tag' => 'google',
                    'type' => 'https',
                    'server' => '8.8.8.8',
                    'detour' => $finalOutbound,
                    'tls' => [
                        'enabled' => true,
                        'server_name' => 'dns.google'
                    ]
                ]
            ],
            'final' => 'cloudflare',
            'strategy' => 'prefer_ipv4'
        ],
        'inbounds' => [
            [
                'type' => 'tun',
                'tag' => 'tun-in',
                'address' => ['172.19.0.1/30', 'fdfe:dcba:9876::1/126'],
                'auto_route' => true,
                'strict_route' => true,
                'stack' => 'system'
            ],
            [
                'type' => 'mixed',
                'tag' => 'mixed-in',
                'listen' => '127.0.0.1',
                'listen_port' => 2080
            ]
        ],
        'outbounds' => $outbounds,
        'route' => [
            'auto_detect_interface' => true,
            'rules' => [
                [
                    'port' => 53,
                    'action' => 'hijack-dns'
                ]
            ],
            'default_domain_resolver' => [
                'server' => 'cloudflare',
                'strategy' => 'prefer_ipv4'
            ],
            'final' => $finalOutbound
        ]
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (SubscriptionConfigError $e) {
    respond_json_error(503, $e->getMessage());
} catch (Throwable $e) {
    error_log('[vpn-hiddify] ' . $e->getMessage());
    respond_json_error(500, 'server error');
}
