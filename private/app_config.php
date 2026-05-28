<?php

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = array_map('trim', explode('=', $line, 2));
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

define('Secrit_Key', env('SECRET_KEY'));
define('SIV', env('SECRET_IV'));
define('SIV_jec', env('SECRET_IV_JEC'));

define('Admin_Key', env('ADMIN_KEY'));

$currenthost = env('APP_DOMAIN', $_SERVER['SERVER_NAME'] ?? 'localhost');
if (strpos($currenthost, '.site') !== false) {
    define('Email_Host', env('EMAIL_HOST_SITE', 'email.yoursite.site'));
    define('My_Name', env('EMAIL_NAME_SITE', 'yoursite Support'));
    define('Email_Name', env('EMAIL_ADDRESS_SITE', 'support@yoursite.site'));
    define('Email_Pass', env('EMAIL_PASS_SITE', ''));
} else {
    define('Email_Host', env('EMAIL_HOST_COM', 'email.yoursite.com'));
    define('My_Name', env('EMAIL_NAME_COM', 'yoursite Support'));
    define('Email_Name', env('EMAIL_ADDRESS_COM', 'support@yoursite.com'));
    define('Email_Pass', env('EMAIL_PASS_COM', ''));
}

define('SplitLINE', '[>L<]');
define('SplitARRAY', '[>A<]');

define('IMG_OK', 'lime');
define('IMG_NO', 'red');

define('CORS_ALLOWED_ORIGINS', env('CORS_ALLOWED_ORIGINS', ''));

define('APP_BASE_PATH', env('APP_BASE_PATH', '/var/www/html'));
define('APK_STUB_PATH', env('APK_STUB_PATH', APP_BASE_PATH . '/private/apkstub/apkstub.zip'));

define('WS_ADMIN_TOKEN', env('WS_ADMIN_TOKEN', ''));
define('WS_TRUSTED_CIDRS', env('WS_TRUSTED_CIDRS', '127.0.0.1/32,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'));
define('ENABLE_BUILDER', env('ENABLE_BUILDER', 'false'));
define('WS_DEVICE_BUILD_TOKEN', env('WS_DEVICE_BUILD_TOKEN', ''));
define('DEVICE_API_KEY', env('DEVICE_API_KEY', ''));
define('BUILDER_CALLBACK_TOKEN', env('BUILDER_CALLBACK_TOKEN', ''));
define('ADMIN_LOGIN_ALLOWED_IPS', env('ADMIN_LOGIN_ALLOWED_IPS', ''));
define('ADMIN_PANEL_ACCESS_KEY', env('ADMIN_PANEL_ACCESS_KEY', ''));
define('ADMIN_PANEL_STRICT_GATE', env('ADMIN_PANEL_STRICT_GATE', 'false'));
define('WS_ENFORCE_BLOCKLIST', env('WS_ENFORCE_BLOCKLIST', 'true'));
define('ALLOW_GET_TOKEN', env('ALLOW_GET_TOKEN', 'false'));
define('ERROR_API_KEY', env('ERROR_API_KEY', ''));
define('PASSWORD_POLICY_STRICT', env('PASSWORD_POLICY_STRICT', 'true'));
define('PASSWORD_MIN_LENGTH', env('PASSWORD_MIN_LENGTH', '8'));
