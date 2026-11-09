<?php
require_once __DIR__ . '/../../private/app_config.php';
require_once __DIR__ . '/../../private/subscription_auth.php';
require_subscription_access();

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ua = is_string($ua) ? strtolower($ua) : '';
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$accept = is_string($accept) ? strtolower($accept) : '';
$format = $_GET['format'] ?? '';
if (!is_string($format)) {
    subscription_access_error(400, 'invalid format');
}
$format = strtolower(trim($format));

if ($format === 'hiddify' || $format === 'sing-box' || $format === 'singbox') {
    require __DIR__ . '/hiddify.php';
    exit;
}

if ($format === 'clash' || $format === 'mihomo' || $format === 'clash-meta') {
    require __DIR__ . '/clash.php';
    exit;
}

if (strpos($ua, 'hiddify') !== false || strpos($ua, 'sing-box') !== false || strpos($ua, 'singbox') !== false) {
    require __DIR__ . '/hiddify.php';
    exit;
}

if (strpos($ua, 'clash') !== false || strpos($ua, 'mihomo') !== false) {
    require __DIR__ . '/clash.php';
    exit;
}

if (strpos($accept, 'application/json') !== false) {
    require __DIR__ . '/hiddify.php';
    exit;
}

require __DIR__ . '/clash.php';
