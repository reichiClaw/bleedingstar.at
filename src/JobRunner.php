<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * Runs one catalogue job under the shared lock and records it in sync_runs.
 * Used by the CLI scripts and by the token-protected /jobs/run URL for hosts
 * whose cron can only call web addresses.
 */
final class JobRunner
{
    public function __construct(private Database $db, private string $root)
    {
    }

    /**
     * @param callable():array $work returns the stats array (created, updated, reviews, errors, skipped, message)
     * @return array{status:string, stats:array, line:string}
     */
    public function run(string $job, bool $dry, callable $work): array
    {
        $lock = new Lock($this->root . '/storage/locks/catalog.lock');
        if (!$lock->acquire()) {
            return ['status' => 'locked', 'stats' => [], 'line' => "Another catalog job is running.\n"];
        }
        $runId = $this->db->insert(
            'INSERT INTO sync_runs (job, status, dry_run, started_at) VALUES (?, "running", ?, NOW())',
            [$job, $dry ? 1 : 0]
        );
        try {
            $stats = $work();
            $status = ($stats['errors'] ?? 0) > 0 ? 'error' : 'ok';
            $this->db->exec(
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
            if (str_contains((string) ($stats['message'] ?? ''), 'quota')) {
                $status = 'quota';
            }
            return ['status' => $status, 'stats' => $stats, 'line' => $line];
        } catch (Throwable $e) {
            $this->db->exec(
                'UPDATE sync_runs SET status="error", finished_at=NOW(), error_count=1, message=? WHERE id=?',
                [$e->getMessage(), $runId]
            );
            return ['status' => 'error', 'stats' => ['errors' => 1, 'message' => $e->getMessage()], 'line' => $e->getMessage() . "\n"];
        } finally {
            $lock->release();
        }
    }

    /** Walks the enabled release sources; $only limits the run to one of them. */
    public function syncReleases(array $config, bool $dry, ?string $only = null, ?int $maxPages = null): array
    {
        $request = $this->root . '/storage/jobs/sync.request';
        if (is_file($request)) {
            unlink($request);
        }
        $deadline = self::deadline($config);
        $results = [];
        if ($only === null || $only === 'discogs') {
            $sync = new Discogs\Sync($this->db, new Discogs\Client($config['discogs']), $config['discogs'], $this->root);
            $results['discogs'] = $sync->importLabel($dry, $maxPages, $deadline);
        }
        $deezer = source_config($config, 'deezer');
        if (($only === null && $deezer['enabled']) || $only === 'deezer') {
            $sync = new Deezer\Sync($this->db, new Deezer\Client($deezer), $deezer, $this->root);
            $results['deezer'] = $sync->importLabel($dry, $deadline);
        }
        $apple = source_config($config, 'apple');
        if (($only === null && $apple['enabled']) || $only === 'apple') {
            $results['apple'] = (new Apple\Links($this->db, $apple))->run($dry, (int) ($apple['per_run'] ?? 25), $deadline);
        }
        return self::mergeStats($results);
    }

    /**
     * Wall-clock point at which the sources stop taking on new items. Shared hosting
     * ends web and cron requests after max_execution_time (180 s at World4You), so
     * the budget is that limit minus a reserve for the request in flight; config
     * 'job_time_budget' (seconds, 0 = unlimited) overrides it.
     */
    public static function deadline(array $config, ?float $start = null): ?float
    {
        $start ??= (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        if (array_key_exists('job_time_budget', $config)) {
            $budget = (int) $config['job_time_budget'];
        } else {
            $limit = (int) ini_get('max_execution_time');
            $budget = $limit > 0 ? $limit - 30 : 0;
        }
        return $budget > 0 ? $start + max(20, $budget) : null;
    }

    /** Sums the counters of several sources and joins their messages with the source name. */
    public static function mergeStats(array $results): array
    {
        $total = ['created' => 0, 'updated' => 0, 'reviews' => 0, 'errors' => 0, 'skipped' => 0, 'message' => ''];
        $messages = [];
        foreach ($results as $source => $stats) {
            foreach (['created', 'updated', 'reviews', 'errors', 'skipped'] as $key) {
                $total[$key] += (int) ($stats[$key] ?? 0);
            }
            $messages[] = sprintf('%s: +%d ~%d ?%d !%d', $source, $stats['created'] ?? 0, $stats['updated'] ?? 0, $stats['reviews'] ?? 0, $stats['errors'] ?? 0)
                . (($stats['message'] ?? '') !== '' ? ' ' . $stats['message'] : '');
        }
        $total['message'] = implode(' | ', $messages);
        return $total;
    }
}
