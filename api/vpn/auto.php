<?php

$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$format = strtolower(trim($_GET['format'] ?? ''));

if ($format === 'hiddify' || $format === 'sing-box' || $format === 'singbox') {
    require __DIR__ . '/hiddify.php';
    exit;
}

if ($format === 'clash' || $format === 'mihomo' || $format === 'clash-meta') {
    require __DIR__ . '/clash.php';
    exit;
}

if (strpos($ua, 'hiddify') !== false) {
    require __DIR__ . '/hiddify.php';
    exit;
}

require __DIR__ . '/clash.php';
