<?php
require_once __DIR__ . '/../../private/app_config.php';

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
    $host = trim((string)$host);
    if ($host === '') {
        return $host;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $host;
    }
    $resolved = gethostbyname($host);
    if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $resolved;
    }
    return $host;
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
        'up_mbps' => vless_config_value('HYSTERIA2_UP_MBPS', vless_config_value('HYSTERIA2_UP_Mbps', '100')),
        'down_mbps' => vless_config_value('HYSTERIA2_DOWN_MBPS', vless_config_value('HYSTERIA2_DOWN_Mbps', '500')),
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

$token = trim($_GET['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    respond_yaml_error(400, 'invalid token');
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_ServerName . ';dbname=' . DB_Name . ';charset=utf8mb4',
        DB_UserName,
        DB_Password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->prepare("SELECT * FROM vpn_clients WHERE token = :token AND enabled = 1 AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1");
    $stmt->execute([':token' => $token]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        respond_yaml_error(404, 'subscription not found');
    }
    $pdo->prepare("UPDATE vpn_clients SET last_config_viewed_at = UTC_TIMESTAMP() WHERE id = :id")->execute([':id' => $client['id']]);

    $dns = array_filter(array_map('trim', explode(',', (string)$client['dns'])));
    if (!$dns) $dns = ['1.1.1.1', '8.8.8.8'];
    $node = vless_node_config($client);
    $nodeName = $node['name'];
    $hy2 = hysteria2_node_config($node['host'], $node['sni']);
    $hy2Name = $hy2 ? $hy2['name'] : null;
    $mobileWs = mobile_ws_node_config($node['uuid'], $node['host'], $node['sni']);
    $mobileWsName = $mobileWs ? $mobileWs['name'] : null;

    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo "mixed-port: 7890\n";
    echo "allow-lan: false\n";
    echo "mode: rule\n";
    echo "log-level: info\n";
    echo "ipv6: false\n";
    echo "unified-delay: true\n";
    echo "tcp-concurrent: true\n";
    echo "keep-alive-idle: 120\n";
    echo "keep-alive-interval: 30\n";
    echo "profile:\n";
    echo "  store-selected: true\n";
    echo "  store-fake-ip: true\n";
    echo "dns:\n";
    echo "  enable: true\n";
    echo "  ipv6: false\n";
    echo "  enhanced-mode: fake-ip\n";
    echo "  fake-ip-range: 198.18.0.1/16\n";
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
    echo "    - '223.5.5.5'\n";
    echo "    - '119.29.29.29'\n";
    echo "  nameserver:\n";
    echo "    - 'https://dns.alidns.com/dns-query'\n";
    echo "    - 'https://doh.pub/dns-query'\n";
    echo "  fallback:\n";
    foreach ($dns as $server) {
        echo "    - '" . yaml_quote($server) . "'\n";
    }
    echo "  fallback-filter:\n";
    echo "    geoip: true\n";
    echo "    geoip-code: CN\n";
    echo "proxies:\n";
    echo "  - name: '" . yaml_quote($nodeName) . "'\n";
    echo "    type: vless\n";
    echo "    server: '" . yaml_quote($node['host']) . "'\n";
    echo "    port: " . (int)$node['port'] . "\n";
    echo "    uuid: '" . yaml_quote($node['uuid']) . "'\n";
    echo "    encryption: none\n";
    echo "    udp: true\n";
    echo "    tls: true\n";
    echo "    servername: '" . yaml_quote($node['sni']) . "'\n";
    echo "    network: tcp\n";
    echo "    ip-version: ipv4\n";
    echo "    tfo: true\n";
    echo "    client-fingerprint: chrome\n";
    if ($mobileWs) {
        echo "  - name: '" . yaml_quote($mobileWsName) . "'\n";
        echo "    type: vless\n";
        echo "    server: '" . yaml_quote($mobileWs['host']) . "'\n";
        echo "    port: " . (int)$mobileWs['port'] . "\n";
        echo "    uuid: '" . yaml_quote($mobileWs['uuid']) . "'\n";
        echo "    encryption: none\n";
        echo "    udp: true\n";
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
        echo "    server: '" . yaml_quote($hy2['host']) . "'\n";
        echo "    port: " . (int)$hy2['port'] . "\n";
        echo "    password: '" . yaml_quote($hy2['password']) . "'\n";
        echo "    up: '" . yaml_quote($hy2['up_mbps'] . ' Mbps') . "'\n";
        echo "    down: '" . yaml_quote($hy2['down_mbps'] . ' Mbps') . "'\n";
        echo "    obfs: salamander\n";
        echo "    obfs-password: '" . yaml_quote($hy2['obfs_password']) . "'\n";
        echo "    sni: '" . yaml_quote($hy2['sni']) . "'\n";
        echo "    skip-cert-verify: false\n";
        echo "    alpn:\n";
        echo "      - h3\n";
    }
    echo "proxy-groups:\n";
    if ($hy2) {
        echo "  - name: 'AUTO'\n";
        echo "    type: url-test\n";
        echo "    url: 'https://cp.cloudflare.com/generate_204'\n";
        echo "    interval: 180\n";
        echo "    tolerance: 50\n";
        echo "    proxies:\n";
        if ($mobileWs) {
            echo "      - '" . yaml_quote($mobileWsName) . "'\n";
        }
        echo "      - '" . yaml_quote($hy2Name) . "'\n";
        echo "      - '" . yaml_quote($nodeName) . "'\n";
    }
    echo "  - name: 'PROXY'\n";
    echo "    type: select\n";
    echo "    proxies:\n";
    if ($mobileWs) {
        echo "      - '" . yaml_quote($mobileWsName) . "'\n";
    }
    if ($hy2) {
        echo "      - AUTO\n";
        echo "      - '" . yaml_quote($hy2Name) . "'\n";
    }
    echo "      - '" . yaml_quote($nodeName) . "'\n";
    echo "      - DIRECT\n";
    echo "  - name: 'GLOBAL'\n";
    echo "    type: select\n";
    echo "    proxies:\n";
    echo "      - PROXY\n";
    if ($mobileWs) {
        echo "      - '" . yaml_quote($mobileWsName) . "'\n";
    }
    if ($hy2) {
        echo "      - AUTO\n";
        echo "      - '" . yaml_quote($hy2Name) . "'\n";
    }
    echo "      - '" . yaml_quote($nodeName) . "'\n";
    echo "      - DIRECT\n";
    echo "rules:\n";
    echo "  - DOMAIN-SUFFIX,local,DIRECT\n";
    echo "  - DOMAIN-SUFFIX,localhost,DIRECT\n";
    echo "  - IP-CIDR,127.0.0.0/8,DIRECT,no-resolve\n";
    echo "  - IP-CIDR,10.0.0.0/8,DIRECT,no-resolve\n";
    echo "  - IP-CIDR,172.16.0.0/12,DIRECT,no-resolve\n";
    echo "  - IP-CIDR,192.168.0.0/16,DIRECT,no-resolve\n";
    echo "  - IP-CIDR,169.254.0.0/16,DIRECT,no-resolve\n";
    echo "  - IP-CIDR,224.0.0.0/4,DIRECT,no-resolve\n";
    echo "  - MATCH,PROXY\n";
} catch (Throwable $e) {
    error_log('[vpn-clash] ' . $e->getMessage());
    respond_yaml_error(500, 'server error');
}
