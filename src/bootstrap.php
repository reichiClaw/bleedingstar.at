<?php

declare(strict_types=1);

require __DIR__ . '/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

function app_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }
    $file = dirname(__DIR__) . '/config/config.php';
    if (!is_file($file)) {
        throw new RuntimeException('config/config.php fehlt. Siehe config/config.example.php.');
    }
    $config = require $file;
    date_default_timezone_set($config['timezone'] ?? 'Europe/Vienna');
    return $config;
}

function app_db(): App\Database
{
    static $db;
    if (!$db) {
        $db = App\Database::connect(app_config()['db']);
    }
    return $db;
}

function app_root(): string
{
    return dirname(__DIR__);
}
