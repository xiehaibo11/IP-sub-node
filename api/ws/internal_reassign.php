<?php
// 内部接口：仅允许 WebSocket 服务端调用，用于更新设备归属
// 通过 WS_DEVICE_AUTH_TOKEN 验证调用方身份

date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', 0);
error_reporting(0);

require_once '../../private/app_config.php';

header("Content-Type: application/json; charset=utf-8");

// 只允许 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"error":"method_not_allowed"}';
    exit;
}

// 只允许本机调用
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remoteIp !== '127.0.0.1' && $remoteIp !== '::1') {
    http_response_code(403);
    echo '{"error":"forbidden"}';
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) $data = [];

// 验证内部密钥
$internalKey = $data['internal_key'] ?? '';
$expectedKey = defined('WS_DEVICE_AUTH_TOKEN') ? WS_DEVICE_AUTH_TOKEN : (getenv('WS_DEVICE_AUTH_TOKEN') ?: '');
if (empty($expectedKey) || !hash_equals($expectedKey, $internalKey)) {
    http_response_code(401);
    echo '{"error":"unauthorized"}';
    exit;
}

$phoneId = trim($data['phone_id'] ?? '');
$newEmail = trim($data['new_email'] ?? '');

if ($phoneId === '' || $newEmail === '') {
    http_response_code(400);
    echo '{"error":"phone_id and new_email required"}';
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_ServerName . ";dbname=" . DB_Name . ";charset=utf8mb4",
        DB_UserName, DB_Password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 通过 email 查 usrname
    $stmt = $pdo->prepare("SELECT usrname FROM users WHERE email = :email LIMIT 1");
    $stmt->bindValue(':email', $newEmail, PDO::PARAM_STR);
    $stmt->execute();
    $targetUser = $stmt->fetchColumn();

    if (!$targetUser) {
        http_response_code(404);
        echo '{"error":"user_not_found"}';
        exit;
    }

    // 更新设备归属
    $stmtUp = $pdo->prepare("UPDATE phones SET usrname = :usrname WHERE phone_id = :phone_id");
    $stmtUp->bindValue(':usrname', $targetUser, PDO::PARAM_STR);
    $stmtUp->bindValue(':phone_id', $phoneId, PDO::PARAM_STR);
    $stmtUp->execute();

    echo json_encode(['status' => 'success', 'usrname' => $targetUser]);
} catch (Exception $e) {
    http_response_code(500);
    echo '{"error":"db_error"}';
    error_log('[InternalReassign] ' . $e->getMessage());
}
