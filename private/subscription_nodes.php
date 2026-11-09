<?php
// Node settings shared by the Clash (clash.php) and sing-box (hiddify.php)
// renderers: read from .env, validated, node hosts resolved to IPv4 here.
// Renderers only format the result, so both formats always carry the same nodes.

final class SubscriptionConfigError extends RuntimeException {}

function node_env(string $key, string $default = ''): string {
    return trim((string)env($key, $default));
}

function node_env_required(string $key): string {
    $value = node_env($key);
    if ($value === '') {
        throw new SubscriptionConfigError('subscription node is not configured');
    }
    return $value;
}

function node_env_enabled(string $key): bool {
    return in_array(strtolower(node_env($key, 'false')), ['1', 'true', 'yes', 'on'], true);
}

function node_port(string $key, string $default, string $what): int {
    $port = (int)node_env($key, $default);
    if ($port < 1 || $port > 65535) {
        throw new SubscriptionConfigError("invalid $what port");
    }
    return $port;
}

// Clients get the IP, so they never look the node up through local DNS.
function node_ipv4(string $host): string {
    static $resolved = [];
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $host;
    }
    if (!isset($resolved[$host])) {
        $addresses = ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) ? [] : (@gethostbynamel($host) ?: []);
        $ipv4 = array_values(array_filter($addresses, fn($a) => filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        if (!$ipv4) {
            throw new SubscriptionConfigError('subscription node IPv4 resolution failed');
        }
        $resolved[$host] = $ipv4[0];
    }
    return $resolved[$host];
}

// Returns ['vless' => node, 'hy2' => node|null, 'ws' => node|null].
function subscription_nodes(array $client): array {
    $uuid = node_env_required('SUBSCRIPTION_NODE_UUID');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
        throw new SubscriptionConfigError('invalid subscription node uuid');
    }
    $host = node_env_required('SUBSCRIPTION_NODE_HOST');
    $sni = node_env('SUBSCRIPTION_NODE_SNI', $host);
    $vless = [
        'name' => node_env('SUBSCRIPTION_NODE_NAME', '🇺🇸 美国-' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $client['client_name'])),
        'uuid' => $uuid,
        'address' => node_ipv4($host),
        'port' => node_port('SUBSCRIPTION_NODE_PORT', '8443', 'subscription node'),
        'sni' => $sni,
    ];

    $hy2 = null;
    if (node_env_enabled('HYSTERIA2_ENABLED')) {
        $password = node_env('HYSTERIA2_PASSWORD');
        $obfsPassword = node_env('HYSTERIA2_OBFS_PASSWORD');
        if ($password === '' || $obfsPassword === '') {
            throw new SubscriptionConfigError('hysteria2 node is not configured');
        }
        $hy2 = [
            'name' => node_env('HYSTERIA2_NAME', '🇺🇸 美国-HY2'),
            'address' => node_ipv4(node_env('HYSTERIA2_HOST', $host)),
            'port' => node_port('HYSTERIA2_PORT', node_env('SUBSCRIPTION_NODE_PORT', '8443'), 'hysteria2 node'),
            'sni' => node_env('HYSTERIA2_SNI', $sni),
            'password' => $password,
            'obfs_password' => $obfsPassword,
            // Whole Mbps; a fixed rate is declared only when both are > 0,
            // otherwise the client uses adaptive BBR.
            'up_mbps' => (int)node_env('HYSTERIA2_UP_MBPS', '0'),
            'down_mbps' => (int)node_env('HYSTERIA2_DOWN_MBPS', '0'),
        ];
    }

    $ws = null;
    if (node_env_enabled('MOBILE_WS_ENABLED')) {
        $path = node_env('MOBILE_WS_PATH', '/api/vless-ws');
        if ($path === '' || $path[0] !== '/') {
            throw new SubscriptionConfigError('invalid mobile websocket path');
        }
        $wsHost = node_env('MOBILE_WS_HOST', $host);
        $ws = [
            'name' => node_env('MOBILE_WS_NAME', '🇺🇸 美国-443'),
            'uuid' => $uuid,
            'host' => $wsHost,
            'address' => node_ipv4($wsHost),
            'port' => node_port('MOBILE_WS_PORT', '443', 'mobile websocket node'),
            'sni' => node_env('MOBILE_WS_SNI', $sni),
            'path' => $path,
        ];
    }

    return ['vless' => $vless, 'hy2' => $hy2, 'ws' => $ws];
}
