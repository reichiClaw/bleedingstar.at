<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('bsid');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
$cspNonce = bin2hex(random_bytes(16));
$GLOBALS['csp_nonce'] = $cspNonce;
header("Content-Security-Policy: default-src 'self'; img-src 'self' https:; style-src 'self'; script-src 'self' 'nonce-$cspNonce'; frame-src https://open.spotify.com; form-action 'self'; base-uri 'self'");

$config = app_config();
$web = new App\Web(
    $config,
    new App\CatalogRepository(app_db(), $config),
    new App\ContentRepository(app_db()),
    new App\Mailer($config['mail_from'])
);
$web->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', request_path());
