<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$installer = new App\Installer(app_db(), app_root());
$installer->schema();
echo "Schema ready.\n";

if (in_array('--import', $argv, true)) {
    $stats = $installer->import(app_config()['legacy_export']);
    echo 'Imported ' . json_encode($stats) . "\n";
}
$installer->finish();
