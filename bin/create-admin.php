<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$email = $argv[1] ?? '';
$password = $argv[2] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Usage: php bin/create-admin.php email password\nPassword must be at least 12 characters.\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = app_db()->one('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) {
    app_db()->exec('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $existing['id']]);
    echo "Password updated.\n";
    exit(0);
}
app_db()->insert('INSERT INTO users (email, password_hash, created_at) VALUES (?,?,NOW())', [$email, $hash]);
echo "Admin created.\n";
