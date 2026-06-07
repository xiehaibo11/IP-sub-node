<?php
require_once __DIR__ . '/../../private/app_config.php';
header('Content-Type: application/json; charset=utf-8');
$token = trim($_GET['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_token'], JSON_UNESCAPED_SLASHES);
    exit;
}
try {
    $pdo = new PDO(
        'mysql:host=' . DB_ServerName . ';dbname=' . DB_Name . ';charset=utf8mb4',
        DB_UserName,
        DB_Password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->prepare("SELECT client_name, username, vpn_address, vpn_ipv6_address, endpoint_host, endpoint_port, allowed_ips, dns, mtu, enabled, expires_at, created_at, last_config_viewed_at FROM vpn_clients WHERE token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
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
