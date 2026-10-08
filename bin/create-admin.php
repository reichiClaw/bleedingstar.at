<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $result = (new App\Installer(app_db(), app_root()))->admin($argv[1] ?? '', $argv[2] ?? '');
} catch (RuntimeException $e) {
    fwrite(STDERR, "Usage: php bin/create-admin.php email password\n" . $e->getMessage() . "\n");
    exit(1);
}
echo $result === 'updated' ? "Password updated.\n" : "Admin created.\n";
