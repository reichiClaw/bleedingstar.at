<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function cli_args(array $argv): array
{
    $out = ['dry' => false, 'max_pages' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $out['dry'] = true;
        } elseif (str_starts_with($arg, '--max-pages=')) {
            $out['max_pages'] = max(1, (int) substr($arg, 12));
        }
    }
    return $out;
}

function cli_job(string $job, bool $dry, callable $work): void
{
    $lock = new App\Lock(app_root() . '/storage/locks/catalog.lock');
    if (!$lock->acquire()) {
        fwrite(STDERR, "Another catalog job is running.\n");
        exit(2);
    }
    $db = app_db();
    $runId = $db->insert(
        'INSERT INTO sync_runs (job, status, dry_run, started_at) VALUES (?, "running", ?, NOW())',
        [$job, $dry ? 1 : 0]
    );
    try {
        $stats = $work();
        $status = ($stats['errors'] ?? 0) > 0 ? 'error' : 'ok';
        $db->exec(
            'UPDATE sync_runs SET status=?, finished_at=NOW(), created_count=?, updated_count=?, review_count=?, error_count=?, message=? WHERE id=?',
            [
                $status,
                (int) ($stats['created'] ?? 0),
                (int) ($stats['updated'] ?? 0),
                (int) ($stats['reviews'] ?? 0),
                (int) ($stats['errors'] ?? 0),
                $stats['message'] ?? '',
                $runId,
            ]
        );
        $line = sprintf(
            "%s %s created=%d updated=%d reviews=%d skipped=%d errors=%d %s\n",
            $job,
            $dry ? 'dry-run' : 'run',
            $stats['created'] ?? 0,
            $stats['updated'] ?? 0,
            $stats['reviews'] ?? 0,
            $stats['skipped'] ?? 0,
            $stats['errors'] ?? 0,
            $stats['message'] ?? ''
        );
        echo $line;
        $lock->release();
        if (str_contains((string) ($stats['message'] ?? ''), 'quota')) {
            exit(3);
        }
        exit($status === 'ok' ? 0 : 1);
    } catch (Throwable $e) {
        $db->exec(
            'UPDATE sync_runs SET status="error", finished_at=NOW(), error_count=1, message=? WHERE id=?',
            [$e->getMessage(), $runId]
        );
        fwrite(STDERR, $e->getMessage() . "\n");
        $lock->release();
        exit(1);
    }
}
