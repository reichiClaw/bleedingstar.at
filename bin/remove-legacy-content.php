<?php

declare(strict_types=1);

require __DIR__ . '/cli.php';

/*
 * Removes the News and Radio content of the old site from the database (news table,
 * page 'radio', redirects into /news/… and /radio). Safe to repeat; --dry-run only
 * counts. Web cron: /jobs/run?token=…&task=remove-legacy-content[&dry=1].
 */
$args = cli_args($argv);
cli_job('remove-legacy-content', $args['dry'], static function () use ($args) {
    return (new App\Installer(app_db(), app_root()))->removeLegacyContent($args['dry']);
});
