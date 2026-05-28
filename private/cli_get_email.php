<?php
/**
 * CLI API ONLY for WebSocket Server (Node.js) 
 * Fetches encrypted email by username via PDO
 */
if (php_sapi_name() !== 'cli') {
    die("ERR: CLI strictly enforced");
}

require_once __DIR__ . '/app_config.php';

$username = isset($argv[1]) ? trim($argv[1]) : '';
if (empty($username)) {
    die("ERR: Username required");
}

try {
    $pdo = new PDO("mysql:host=" . DB_ServerName . ";dbname=" . DB_Name . ";charset=utf8mb4", DB_UserName, DB_Password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Limit to 1, exactly like the original mysql -e "SELECT email FROM users WHERE usrname='X' LIMIT 1"
    $stmt = $pdo->prepare("SELECT email FROM users WHERE usrname = :usr LIMIT 1");
    $stmt->bindParam(':usr', $username);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['email'])) {
        echo "OK:" . $result['email'];
    } else {
        echo "ERR: User not found or empty email";
    }
} catch (PDOException $e) {
    echo "ERR: DB error " . $e->getMessage();
}
