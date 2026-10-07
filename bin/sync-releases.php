<?php

declare(strict_types=1);

require __DIR__ . '/cli.php';

/*
 * One run walks every enabled source (Discogs, Deezer, Apple, Spotify when configured,
 * Discogs cache refresh), starting with a different one each time so a tight time budget
 * is shared fairly. Each source has its own error handling, so a Discogs quota stop does
 * not prevent the Deezer import. --source=discogs|deezer|apple|spotify|cache limits the
 * run to one of them; the /jobs/run URL does the same for web cron jobs.
 */
$args = cli_args($argv);
cli_job('sync-releases', $args['dry'], static function () use ($args) {
    return (new App\JobRunner(app_db(), app_root()))->syncReleases(app_config(), $args['dry'], $args['source'], $args['max_pages']);
});
