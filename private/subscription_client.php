<?php

function subscription_database(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_ServerName . ';dbname=' . DB_Name . ';charset=utf8mb4',
            DB_UserName,
            DB_Password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_TIMEOUT => 2,
            ]
        );
    }
    return $pdo;
}

function subscription_client(): ?array {
    $pdo = subscription_database();
    // Recheck enabled/expiry on every request; never cache access decisions.
    $client = $pdo->query(
        "SELECT id, client_name,
            (last_config_viewed_at IS NULL OR
             last_config_viewed_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND) AS refresh_viewed_at
         FROM vpn_clients
         WHERE enabled = 1 AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
         ORDER BY id ASC LIMIT 1"
    )->fetch();
    if (!$client) {
        return null;
    }

    if ((int)$client['refresh_viewed_at'] === 1) {
        // The predicate also prevents concurrent refreshes from repeatedly writing the row.
        $pdo->prepare(
            "UPDATE vpn_clients SET last_config_viewed_at = UTC_TIMESTAMP()
             WHERE id = :id AND (last_config_viewed_at IS NULL OR
                 last_config_viewed_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND)"
        )->execute([':id' => $client['id']]);
    }
    return $client;
}
