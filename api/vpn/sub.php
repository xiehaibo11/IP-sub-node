<?php
require_once __DIR__ . '/../../private/app_config.php';

function respond($code, $message) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function ipv4_csv_values($value) {
    return array_values(array_filter(array_map('trim', explode(',', (string)$value)), function ($item) {
        return $item !== '' && strpos($item, ':') === false;
    }));
}

$token = trim($_GET['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    respond(400, "invalid token\n");
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
        respond(404, "subscription not found\n");
    }
    $pdo->prepare("UPDATE vpn_clients SET last_config_viewed_at = UTC_TIMESTAMP() WHERE id = :id")->execute([':id' => $client['id']]);

    $addresses = array_filter([$client['vpn_address'] ?? '']);
    $allowed = ipv4_csv_values($client['allowed_ips'] ?? '');
    if (!$allowed) $allowed = ['0.0.0.0/0'];
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $client['client_name']);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.conf"');
    echo "[Interface]\n";
    echo "PrivateKey = " . $client['private_key'] . "\n";
    echo "Address = " . implode(', ', $addresses) . "\n";
    echo "DNS = " . $client['dns'] . "\n";
    if (!empty($client['mtu'])) echo "MTU = " . (int)$client['mtu'] . "\n";
    echo "\n[Peer]\n";
    echo "PublicKey = " . $client['server_public_key'] . "\n";
    echo "PresharedKey = " . $client['preshared_key'] . "\n";
    echo "Endpoint = " . $client['endpoint_host'] . ":" . (int)$client['endpoint_port'] . "\n";
    echo "AllowedIPs = " . implode(', ', $allowed) . "\n";
    echo "PersistentKeepalive = 25\n";
} catch (Throwable $e) {
    error_log('[vpn-sub] ' . $e->getMessage());
    respond(500, "server error\n");
}
