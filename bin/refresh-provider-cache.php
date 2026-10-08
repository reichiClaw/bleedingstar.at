<?php

declare(strict_types=1);

require __DIR__ . '/cli.php';

$args = cli_args($argv);
cli_job('refresh-provider-cache', $args['dry'], static function () use ($args) {
    $config = app_config();
    $sync = new App\Discogs\Sync(app_db(), new App\Discogs\Client($config['discogs']), $config['discogs']);
    return $sync->refreshCache($args['dry']);
});
