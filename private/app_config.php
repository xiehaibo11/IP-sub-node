<?php

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = array_map('trim', explode('=', $line, 2));
        // KEY="value" or KEY='value': the quotes are not part of the value.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

function env($key, $default = '') {
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

define('DB_ServerName', env('DB_HOST', '127.0.0.1'));
define('DB_UserName', env('DB_USER', 'root'));
define('DB_Password', env('DB_PASSWORD', ''));
define('DB_Name', env('DB_NAME', 'clients'));
