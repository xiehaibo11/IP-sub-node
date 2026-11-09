<?php
require_once __DIR__ . '/../../private/app_config.php';
require_once __DIR__ . '/../../private/subscription_auth.php';
require_subscription_access();
require_once __DIR__ . '/../../private/subscription_client.php';

function respond_yaml_error($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $message . "\n";
    exit;
}

function yaml_quote($value) {
    return str_replace("'", "''", (string)$value);
}

function strip_cidr($address) {
    $parts = explode('/', trim((string)$address), 2);
    return $parts[0];
}

function ipv4_csv_values($value) {
    return array_values(array_filter(array_map('trim', explode(',', (string)$value)), function ($item) {
        return $item !== '' && strpos($item, ':') === false;
    }));
}

function endpoint_ipv4_or_host($host) {
    static $resolvedHosts = [];
    $host = trim((string)$host);
    if ($host === '') {
        respond_yaml_error(503, 'subscription node address cannot be resolved');
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $host;
    }
    if (isset($resolvedHosts[$host])) {
        return $resolvedHosts[$host];
    }
    $resolved = gethostbyname($host);
    if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $resolvedHosts[$host] = $resolved;
        return $resolved;
    }
    respond_yaml_error(503, 'subscription node address cannot be resolved');
}

function vless_config_value($key, $default = '') {
    return trim((string)env($key, $default));
}

function vless_required($key) {
    $value = vless_config_value($key);
    if ($value === '') {
        respond_yaml_error(503, 'subscription node is not configured');
    }
    return $value;
}

function vless_node_config($client) {
    $uuid = vless_required('SUBSCRIPTION_NODE_UUID');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
        respond_yaml_error(503, 'invalid subscription node uuid');
    }

    $host = vless_required('SUBSCRIPTION_NODE_HOST');
    $port = (int)vless_config_value('SUBSCRIPTION_NODE_PORT', '8443');
    if ($port < 1 || $port > 65535) {
        respond_yaml_error(503, 'invalid subscription node port');
    }

    $fallbackName = '🇺🇸 美国-' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $client['client_name']);
    return [
        'name' => vless_config_value('SUBSCRIPTION_NODE_NAME', $fallbackName),
        'uuid' => $uuid,
        'host' => $host,
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
        respond_yaml_error(503, 'hysteria2 node is not configured');
    }

    $port = (int)vless_config_value('HYSTERIA2_PORT', vless_config_value('SUBSCRIPTION_NODE_PORT', '8443'));
    if ($port < 1 || $port > 65535) {
        respond_yaml_error(503, 'invalid hysteria2 node port');
    }

    return [
        'name' => vless_config_value('HYSTERIA2_NAME', '🇺🇸 美国-HY2'),
        'host' => vless_config_value('HYSTERIA2_HOST', $fallbackHost),
        'port' => $port,
        'sni' => vless_config_value('HYSTERIA2_SNI', $fallbackSni),
        'password' => $password,
        'obfs_password' => $obfsPassword,
        'up_mbps' => vless_config_value('HYSTERIA2_UP_MBPS', vless_config_value('HYSTERIA2_UP_Mbps', '0')),
        'down_mbps' => vless_config_value('HYSTERIA2_DOWN_MBPS', vless_config_value('HYSTERIA2_DOWN_Mbps', '0')),
    ];
}

function mobile_ws_node_config($uuid, $fallbackHost, $fallbackSni) {
    if (!config_enabled('MOBILE_WS_ENABLED', false)) {
        return null;
    }

    $port = (int)vless_config_value('MOBILE_WS_PORT', '443');
    if ($port < 1 || $port > 65535) {
        respond_yaml_error(503, 'invalid mobile websocket node port');
    }

    $path = vless_config_value('MOBILE_WS_PATH', '/api/vless-ws');
    if ($path === '' || $path[0] !== '/') {
        respond_yaml_error(503, 'invalid mobile websocket path');
    }

    return [
        'name' => vless_config_value('MOBILE_WS_NAME', '🇺🇸 美国-443'),
        'uuid' => $uuid,
        'host' => vless_config_value('MOBILE_WS_HOST', $fallbackHost),
        'port' => $port,
        'sni' => vless_config_value('MOBILE_WS_SNI', $fallbackSni),
        'path' => $path,
    ];
}

try {
    $client = subscription_client();
    if (!$client) {
        respond_yaml_error(404, 'subscription not found');
    }

    $node = vless_node_config($client);
    $nodeName = $node['name'];
    $hy2 = hysteria2_node_config($node['host'], $node['sni']);
    $hy2Name = $hy2 ? $hy2['name'] : null;
    $mobileWs = mobile_ws_node_config($node['uuid'], $node['host'], $node['sni']);
    $mobileWsName = $mobileWs ? $mobileWs['name'] : null;
    $nodeAddress = endpoint_ipv4_or_host($node['host']);
    $hy2Address = $hy2 ? endpoint_ipv4_or_host($hy2['host']) : null;
    $mobileWsAddress = $mobileWs ? endpoint_ipv4_or_host($mobileWs['host']) : null;

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo "mixed-port: 7890\n";
    echo "allow-lan: false\n";
    echo "bind-address: 127.0.0.1\n";
    echo "mode: rule\n";
    echo "log-level: warning\n";
    echo "ipv6: true\n";
    echo "unified-delay: true\n";
    echo "tcp-concurrent: true\n";
    echo "keep-alive-idle: 120\n";
    echo "keep-alive-interval: 30\n";
    echo "profile:\n";
    echo "  store-selected: false\n";
    echo "  store-fake-ip: true\n";
    echo "tun:\n";
    echo "  enable: true\n";
    echo "  stack: gvisor\n";
    echo "  auto-route: true\n";
    echo "  strict-route: true\n";
    echo "  auto-detect-interface: true\n";
    echo "  inet6-address:\n";
    echo "    - 'fdfe:dcba:9876::1/126'\n";
    echo "  dns-hijack:\n";
    echo "    - 'any:53'\n";
    echo "    - 'tcp://any:53'\n";
    echo "dns:\n";
    echo "  enable: true\n";
    echo "  ipv6: true\n";
    echo "  cache-algorithm: arc\n";
    echo "  prefer-h3: false\n";
    echo "  enhanced-mode: fake-ip\n";
    echo "  fake-ip-range: 198.18.0.1/16\n";
    echo "  fake-ip-range6: '2001:2::/64'\n";
    echo "  fake-ip-filter:\n";
    echo "    - '*.lan'\n";
    echo "    - '*.local'\n";
    echo "    - '*.home.arpa'\n";
    echo "    - 'localhost'\n";
    echo "    - 'captive.apple.com'\n";
    echo "    - 'time-ios.apple.com'\n";
    echo "    - 'time.apple.com'\n";
    echo "    - '+.pool.ntp.org'\n";
    echo "    - '+.msftconnecttest.com'\n";
    echo "    - '+.msftncsi.com'\n";
    echo "    - 'connectivitycheck.gstatic.com'\n";
    echo "    - 'connect.rom.miui.com'\n";
    echo "    - 'detectportal.firefox.com'\n";
    echo "  default-nameserver:\n";
    echo "    - 'https://1.1.1.1/dns-query#PROXY'\n";
    echo "  respect-rules: true\n";
    echo "  proxy-server-nameserver:\n";
    echo "    - 'https://1.1.1.1/dns-query#PROXY'\n";
    echo "  nameserver:\n";
    echo "    - 'https://1.1.1.1/dns-query#PROXY'\n";
    echo "    - 'https://8.8.8.8/dns-query#PROXY'\n";
    echo "proxies:\n";
    echo "  - name: '" . yaml_quote($nodeName) . "'\n";
    echo "    type: vless\n";
    echo "    server: '" . yaml_quote($nodeAddress) . "'\n";
    echo "    port: " . (int)$node['port'] . "\n";
    echo "    uuid: '" . yaml_quote($node['uuid']) . "'\n";
    echo "    encryption: none\n";
    echo "    udp: true\n";
    echo "    packet-encoding: xudp\n";
    echo "    tls: true\n";
    echo "    servername: '" . yaml_quote($node['sni']) . "'\n";
    echo "    network: tcp\n";
    echo "    ip-version: ipv4\n";
    echo "    tfo: true\n";
    echo "    client-fingerprint: chrome\n";
    if ($mobileWs) {
        echo "  - name: '" . yaml_quote($mobileWs['name']) . "'\n";
        echo "    type: vless\n";
        echo "    server: '" . yaml_quote($mobileWsAddress) . "'\n";
        echo "    port: " . (int)$mobileWs['port'] . "\n";
        echo "    uuid: '" . yaml_quote($mobileWs['uuid']) . "'\n";
        echo "    encryption: none\n";
        echo "    udp: true\n";
        echo "    packet-encoding: xudp\n";
        echo "    tls: true\n";
        echo "    servername: '" . yaml_quote($mobileWs['sni']) . "'\n";
        echo "    network: ws\n";
        echo "    ip-version: ipv4\n";
        echo "    tfo: true\n";
        echo "    client-fingerprint: chrome\n";
        echo "    ws-opts:\n";
        echo "      path: '" . yaml_quote($mobileWs['path']) . "'\n";
        echo "      headers:\n";
        echo "        Host: '" . yaml_quote($mobileWs['host']) . "'\n";
    }
    if ($hy2) {
        echo "  - name: '" . yaml_quote($hy2Name) . "'\n";
        echo "    type: hysteria2\n";
        echo "    server: '" . yaml_quote($hy2Address) . "'\n";
        echo "    port: " . (int)$hy2['port'] . "\n";
        echo "    password: '" . yaml_quote($hy2['password']) . "'\n";
        if ((float)$hy2['up_mbps'] > 0 && (float)$hy2['down_mbps'] > 0) {
            echo "    up: '" . yaml_quote($hy2['up_mbps'] . ' Mbps') . "'\n";
            echo "    down: '" . yaml_quote($hy2['down_mbps'] . ' Mbps') . "'\n";
        }
        echo "    obfs: salamander\n";
        echo "    obfs-password: '" . yaml_quote($hy2['obfs_password']) . "'\n";
        echo "    sni: '" . yaml_quote($hy2['sni']) . "'\n";
        echo "    skip-cert-verify: false\n";
        echo "    alpn:\n";
        echo "      - h3\n";
    }
    $proxyOrder = [$nodeName];
    if ($hy2) {
        array_unshift($proxyOrder, $hy2Name);
    }
    if ($mobileWs) {
        $proxyOrder[] = $mobileWsName;
    }
    echo "proxy-groups:\n";
    echo "  - name: 'AUTO'\n";
    echo "    type: url-test\n";
    echo "    url: 'https://www.gstatic.com/generate_204'\n";
    echo "    interval: 30\n";
    echo "    timeout: 3000\n";
    echo "    tolerance: 30\n";
    echo "    expected-status: 204\n";
    echo "    max-failed-times: 2\n";
    echo "    lazy: false\n";
    echo "    proxies:\n";
    foreach ($proxyOrder as $proxyName) {
        echo "      - '" . yaml_quote($proxyName) . "'\n";
    }
    echo "  - name: 'PROXY'\n";
    echo "    type: select\n";
    echo "    proxies:\n";
    echo "      - AUTO\n";
    foreach ($proxyOrder as $proxyName) {
        echo "      - '" . yaml_quote($proxyName) . "'\n";
    }
    echo "  - name: 'GLOBAL'\n";
    echo "    type: select\n";
    echo "    proxies:\n";
    // Global mode must reach health-checked failover even when PROXY is pinned manually.
    echo "      - AUTO\n";
    echo "      - PROXY\n";
    foreach ($proxyOrder as $proxyName) {
        echo "      - '" . yaml_quote($proxyName) . "'\n";
    }
    echo "rules:\n";
    echo "  - MATCH,PROXY\n";
} catch (Throwable $e) {
    error_log('[vpn-clash] ' . $e->getMessage());
    respond_yaml_error(500, 'server error');
}
