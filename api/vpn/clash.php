<?php
require_once __DIR__ . '/../../private/app_config.php';
require_once __DIR__ . '/../../private/subscription_auth.php';
require_subscription_access();
require_once __DIR__ . '/../../private/subscription_client.php';
require_once __DIR__ . '/../../private/subscription_nodes.php';

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

try {
    $client = subscription_client();
    if (!$client) {
        respond_yaml_error(404, 'subscription not found');
    }

    ['vless' => $node, 'hy2' => $hy2, 'ws' => $mobileWs] = subscription_nodes($client);
    $nodeName = $node['name'];
    $hy2Name = $hy2['name'] ?? null;
    $mobileWsName = $mobileWs['name'] ?? null;
    $nodeAddress = $node['address'];
    $hy2Address = $hy2['address'] ?? null;
    $mobileWsAddress = $mobileWs['address'] ?? null;

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
        // Each rate on its own: a fixed upload rate wedges idle connections.
        if ($hy2['up_mbps'] > 0) {
            echo "    up: '" . yaml_quote($hy2['up_mbps'] . ' Mbps') . "'\n";
        }
        if ($hy2['down_mbps'] > 0) {
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
} catch (SubscriptionConfigError $e) {
    respond_yaml_error(503, $e->getMessage());
} catch (Throwable $e) {
    error_log('[vpn-clash] ' . $e->getMessage());
    respond_yaml_error(500, 'server error');
}
