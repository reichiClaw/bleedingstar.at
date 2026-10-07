<?php
/*
 * Front controller. This file stays parseable by PHP 7 on purpose: on a host that
 * still runs an old PHP it shows a maintenance page instead of a parse error.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Shared hosting keeps the code next to the document root in /app; the
// repository layout keeps it one level above public/.
$appRoot = is_dir(__DIR__ . '/app/src') ? __DIR__ . '/app' : dirname(__DIR__);
$logDir = $appRoot . '/storage/logs';
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('error_log', $logDir . '/php-error.log');
}

function bs_offline(int $status, string $headline, string $text): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if ($status === 503) {
        header('Retry-After: 3600');
    }
    $h = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
    $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>BleedingStar</title><meta name="robots" content="noindex">'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0c0d10;color:#f2efea;font:400 1.05rem/1.55 "Open Sans",Arial,sans-serif}'
        . 'main{max-width:34rem;padding:2rem}img{width:156px;height:auto}h1{font:400 2.2rem/1.1 Oswald,"Arial Narrow",Impact,sans-serif;margin:1.5rem 0 .6rem}'
        . 'p{margin:0 0 .8rem;color:#b7b1a8}a{color:#f2efea}</style></head><body><main>'
        . '<img src="/assets/img/logo-white.png" alt="BleedingStar" width="156" height="44">'
        . '<h1>' . $h . '</h1><p>' . $t . '</p>'
        . '<p>Kontakt: <a href="mailto:reichi@bleedingstar.at">reichi@bleedingstar.at</a> · <a href="tel:+436644385462">+43 664 4385462</a></p>'
        . '</main></body></html>';
}

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    error_log('bleedingstar: PHP ' . PHP_VERSION . ' is too old, 8.2 is required');
    bs_offline(503, 'Die Seite wird gerade eingerichtet.', 'Der Server läuft noch mit einer älteren PHP-Version. Die Inhalte sind in Kürze wieder erreichbar.');
    exit;
}

require $appRoot . '/src/bootstrap.php';

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

try {
    $config = app_config();
    $db = app_db();
} catch (Throwable $e) {
    error_log('bleedingstar: not configured: ' . $e->getMessage());
    bs_offline(503, 'Die Seite wird gerade eingerichtet.', 'Die Datenbank ist noch nicht angebunden. Die Inhalte sind in Kürze wieder erreichbar.');
    exit;
}
if (!is_file($appRoot . '/storage/install.done') && request_path() !== '/setup') {
    bs_offline(503, 'Die Seite wird gerade eingerichtet.', 'Die Inhalte werden gerade eingespielt und sind in Kürze wieder erreichbar.');
    exit;
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('bsid');
// The cookie is only issued when a form or the admin needs it (see Csrf::token and Web::rentalCart).
if (isset($_COOKIE['bsid'])) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($secure) {
    header('Strict-Transport-Security: max-age=31536000');
}
$cspNonce = bin2hex(random_bytes(16));
$GLOBALS['csp_nonce'] = $cspNonce;
header("Content-Security-Policy: default-src 'self'; img-src 'self' https:; style-src 'self'; script-src 'self' 'nonce-$cspNonce'; frame-src https://open.spotify.com; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");

try {
    $web = new App\Web(
        $config,
        new App\CatalogRepository($db, $config),
        new App\ContentRepository($db),
        new App\Mailer($config['mail_from'])
    );
    $web->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', request_path());
} catch (Throwable $e) {
    error_log('bleedingstar: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        bs_offline(500, 'Da ist etwas schiefgegangen.', 'Der Fehler wurde protokolliert. Bitte später noch einmal versuchen.');
    }
}
