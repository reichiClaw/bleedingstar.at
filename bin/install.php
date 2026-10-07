<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "schema missing\n");
    exit(1);
}
$pdo = app_db()->pdo();
$schema = preg_replace('/^--.*$/m', '', $schema) ?? $schema;
foreach (preg_split('/;\s*\n/', $schema) ?: [] as $statement) {
    $statement = trim($statement);
    if ($statement === '') {
        continue;
    }
    $pdo->exec($statement);
}
echo "Schema ready.\n";

if (in_array('--import', $argv, true)) {
    $path = app_config()['legacy_export'];
    $stats = (new App\LegacyImporter(app_db(), app_root()))->import($path);
    echo 'Imported ' . json_encode($stats) . "\n";
}
