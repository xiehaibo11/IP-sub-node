<?php

function subscription_access_error(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    if ($status === 401) {
        header('WWW-Authenticate: Bearer realm="vpn-subscription"');
    }
    echo $message . "\n";
    exit;
}

function require_subscription_access(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('Vary: Authorization, User-Agent, Accept');
    header_remove('Profile-Web-Page-Url');

    $expected = strtolower(trim((string)env('SUBSCRIPTION_TOKEN_SHA256')));
    if (!preg_match('/^[a-f0-9]{64}$/D', $expected)) {
        subscription_access_error(503, 'subscription access is not configured');
    }

    $presented = '';
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($authorization !== null) {
        if (!is_string($authorization) || !preg_match('/^Bearer ([a-f0-9]{64})$/iD', trim($authorization), $matches)) {
            subscription_access_error(401, 'unauthorized');
        }
        $presented = $matches[1];
    } elseif (array_key_exists('token', $_GET)) {
        if (!is_string($_GET['token'])) {
            subscription_access_error(401, 'unauthorized');
        }
        $presented = trim($_GET['token']);
    } else {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : null;
        if (is_string($path) && preg_match('~^/sub/([a-f0-9]{64})(?:/(?:client\.yaml|mobile\.json))?/?$~iD', $path, $matches)) {
            $presented = $matches[1];
        }
    }

    if (!preg_match('/^[a-f0-9]{64}$/D', $presented) || !hash_equals($expected, hash('sha256', $presented))) {
        subscription_access_error(401, 'unauthorized');
    }
}
