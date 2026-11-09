<?php
require_once __DIR__ . '/../../private/app_config.php';
require_once __DIR__ . '/../../private/subscription_auth.php';
require_subscription_access();
require_once __DIR__ . '/../../private/subscription_client.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = subscription_database();
    $stmt = $pdo->prepare("SELECT client_name, username, vpn_address, vpn_ipv6_address, endpoint_host, endpoint_port, allowed_ips, dns, mtu, enabled, expires_at, created_at, last_config_viewed_at FROM vpn_clients WHERE enabled = 1 AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        http_response_code(404);
        echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    echo json_encode(['status' => 'ok', 'client' => $client], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[vpn-info] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_UNESCAPED_SLASHES);
}
