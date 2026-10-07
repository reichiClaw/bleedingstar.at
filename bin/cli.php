<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function cli_args(array $argv): array
{
    $out = ['dry' => false, 'max_pages' => null, 'source' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $out['dry'] = true;
        } elseif (str_starts_with($arg, '--max-pages=')) {
            $out['max_pages'] = max(1, (int) substr($arg, 12));
        } elseif (str_starts_with($arg, '--source=')) {
            $source = substr($arg, 9);
            $out['source'] = in_array($source, ['discogs', 'deezer', 'apple'], true) ? $source : null;
        }
    }
    return $out;
}

/** Exit codes: 0 ok, 1 error, 2 another job holds the lock, 3 stopped at a provider quota. */
function cli_job(string $job, bool $dry, callable $work): void
{
    $result = (new App\JobRunner(app_db(), app_root()))->run($job, $dry, $work);
    fwrite($result['status'] === 'ok' || $result['status'] === 'quota' ? STDOUT : STDERR, $result['line']);
    exit(match ($result['status']) {
        'ok' => 0,
        'locked' => 2,
        'quota' => 3,
        default => 1,
    });
}
