<?php

declare(strict_types=1);

require __DIR__ . '/cli.php';

$args = cli_args($argv);
$request = app_root() . '/storage/jobs/sync.request';
if (is_file($request)) {
    unlink($request);
}

cli_job('sync-releases', $args['dry'], static function () use ($args) {
    $config = app_config();
    $sync = new App\Discogs\Sync(app_db(), new App\Discogs\Client($config['discogs']), $config['discogs']);
    return $sync->importLabel($args['dry'], $args['max_pages']);
});
