<?php
require_once __DIR__ . '/../../private/app_config.php';
require_once __DIR__ . '/../../private/subscription_auth.php';
require_subscription_access();
require_once __DIR__ . '/../../private/subscription_client.php';

function respond_json_error($code, $message) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

function csv_values($value) {
    return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
}

function vless_config_value($key, $default = '') {
    return trim((string)env($key, $default));
}

function vless_required($key) {
    $value = vless_config_value($key);
    if ($value === '') {
        respond_json_error(503, 'subscription node is not configured');
    }
    return $value;
}

function endpoint_ipv4_or_error($host) {
    static $resolvedHosts = [];

    $host = trim((string)$host);
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $host;
    }
    if (isset($resolvedHosts[$host])) {
        return $resolvedHosts[$host];
    }
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        respond_json_error(503, 'subscription node requires an IPv4 endpoint');
    }

    $addresses = @gethostbynamel($host);
    foreach ($addresses ?: [] as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $resolvedHosts[$host] = $address;
            return $address;
        }
    }
    respond_json_error(503, 'subscription node IPv4 resolution failed');
}

function vless_node_config($client) {
    $uuid = vless_required('SUBSCRIPTION_NODE_UUID');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
        respond_json_error(503, 'invalid subscription node uuid');
    }

    $host = vless_required('SUBSCRIPTION_NODE_HOST');
    $port = (int)vless_config_value('SUBSCRIPTION_NODE_PORT', '8443');
    if ($port < 1 || $port > 65535) {
        respond_json_error(503, 'invalid subscription node port');
    }

    $fallbackName = '🇺🇸 美国-' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $client['client_name']);
    return [
        'name' => vless_config_value('SUBSCRIPTION_NODE_NAME', $fallbackName),
        'uuid' => $uuid,
        'host' => $host,
        'address' => endpoint_ipv4_or_error($host),
        'port' => $port,
        'sni' => vless_config_value('SUBSCRIPTION_NODE_SNI', $host),
    ];
}

function config_enabled($key, $default = false) {
    $value = strtolower(vless_config_value($key, $default ? 'true' : 'false'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function hysteria2_node_config($fallbackHost, $fallbackSni) {
    if (!config_enabled('HYSTERIA2_ENABLED', false)) {
        return null;
    }

    $password = vless_config_value('HYSTERIA2_PASSWORD');
    $obfsPassword = vless_config_value('HYSTERIA2_OBFS_PASSWORD');
    if ($password === '' || $obfsPassword === '') {
        respond_json_error(503, 'hysteria2 node is not configured');
    }

    $port = (int)vless_config_value('HYSTERIA2_PORT', vless_config_value('SUBSCRIPTION_NODE_PORT', '8443'));
    if ($port < 1 || $port > 65535) {
        respond_json_error(503, 'invalid hysteria2 node port');
    }

    $host = vless_config_value('HYSTERIA2_HOST', $fallbackHost);
    return [
        'name' => vless_config_value('HYSTERIA2_NAME', '🇺🇸 美国-HY2'),
        'host' => $host,
        'address' => endpoint_ipv4_or_error($host),
        'port' => $port,
        'sni' => vless_config_value('HYSTERIA2_SNI', $fallbackSni),
        'password' => $password,
        'obfs_password' => $obfsPassword,
        'up_mbps' => (int)vless_config_value('HYSTERIA2_UP_MBPS', vless_config_value('HYSTERIA2_UP_Mbps', '0')),
        'down_mbps' => (int)vless_config_value('HYSTERIA2_DOWN_MBPS', vless_config_value('HYSTERIA2_DOWN_Mbps', '0')),
    ];
}

function mobile_ws_node_config($uuid, $fallbackHost, $fallbackSni) {
    if (!config_enabled('MOBILE_WS_ENABLED', false)) {
        return null;
    }

    $port = (int)vless_config_value('MOBILE_WS_PORT', '443');
    if ($port < 1 || $port > 65535) {
        respond_json_error(503, 'invalid mobile websocket node port');
    }

    $path = vless_config_value('MOBILE_WS_PATH', '/api/vless-ws');
    if ($path === '' || $path[0] !== '/') {
        respond_json_error(503, 'invalid mobile websocket path');
    }

    $host = vless_config_value('MOBILE_WS_HOST', $fallbackHost);
    return [
        'name' => vless_config_value('MOBILE_WS_NAME', '🇺🇸 美国-443'),
        'uuid' => $uuid,
        'host' => $host,
        'address' => endpoint_ipv4_or_error($host),
        'port' => $port,
        'sni' => vless_config_value('MOBILE_WS_SNI', $fallbackSni),
        'path' => $path,
    ];
}

try {
    $client = subscription_client();
    if (!$client) {
        respond_json_error(404, 'subscription not found');
    }

    $node = vless_node_config($client);
    $tag = $node['name'];
    $hy2 = hysteria2_node_config($node['host'], $node['sni']);
    $hy2Tag = $hy2 ? $hy2['name'] : null;
    $mobileWs = mobile_ws_node_config($node['uuid'], $node['host'], $node['sni']);
    $mobileWsTag = $mobileWs ? $mobileWs['name'] : null;

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
        if ($hy2['up_mbps'] > 0 && $hy2['down_mbps'] > 0) {
            $hy2Outbound['up_mbps'] = $hy2['up_mbps'];
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
} catch (Throwable $e) {
    error_log('[vpn-hiddify] ' . $e->getMessage());
    respond_json_error(500, 'server error');
}
