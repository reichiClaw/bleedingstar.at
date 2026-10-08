<?php

declare(strict_types=1);

namespace App\Apple;

use App\Database;
use App\Discogs\Covers;

/**
 * Looks releases up in the iTunes Search API by UPC and stores the Apple Music
 * page as a link. When Spotify has no cover, the largest Apple artwork is stored
 * locally and replaces Deezer or Discogs artwork. The lookup is public and needs
 * no credentials; the API tolerates about 20 requests per minute, so calls are
 * paced and limited per run.
 */
class Links
{
    public function __construct(private Database $db, private array $config)
    {
    }

    /** @param float|null $deadline unix time after which the run stops cleanly; the next run resumes */
    public function run(bool $dryRun, int $limit = 40, ?float $deadline = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'reviews' => 0, 'errors' => 0, 'skipped' => 0, 'message' => ''];
        $rows = $this->db->all(
            "SELECT DISTINCT f.release_id, f.upc FROM release_formats f
             WHERE f.upc IS NOT NULL AND f.upc <> ''
               AND NOT EXISTS (SELECT 1 FROM release_links l WHERE l.release_id = f.release_id AND l.provider = 'apple')
               AND NOT EXISTS (SELECT 1 FROM provider_records p WHERE p.provider = 'apple' AND p.entity_type = 'upc' AND p.external_id = f.upc AND p.fetched_at > (NOW() - INTERVAL 30 DAY))
             ORDER BY f.release_id DESC LIMIT " . max(1, $limit)
        );
        foreach ($rows as $row) {
            $upc = preg_replace('/\D+/', '', (string) $row['upc']) ?? '';
            if ($upc === '') {
                continue;
            }
            if ($deadline !== null && microtime(true) > $deadline) {
                $stats['message'] = trim($stats['message'] . ' time budget reached, next run continues');
                break;
            }
            $result = $this->lookup($upc);
            if ($result === null) {
                $stats['errors']++;
                $stats['message'] = 'Apple lookup failed for UPC ' . $upc;
                continue;
            }
            if ($dryRun) {
                $stats['updated']++;
                continue;
            }
            $this->db->exec(
                'INSERT INTO provider_records (provider, entity_type, external_id, payload_json, fetched_at)
                 VALUES ("apple","upc",?,?,NOW())
                 ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), fetched_at = NOW()',
                [$upc, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
            $collection = $this->collection($result);
            $url = $collection === null ? null : $this->collectionUrl($collection);
            if ($url === null) {
                $stats['skipped']++;
                continue;
            }
            $this->db->exec(
                'INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?,"Apple Music",?,"apple",0)',
                [(int) $row['release_id'], $url]
            );
            $stats['updated']++;
            $this->applyCover((int) $row['release_id'], $collection);
        }
        if ($deadline === null || microtime(true) <= $deadline) {
            $this->coverBackfill($dryRun, 20, $deadline);
        }
        return $stats;
    }

    protected function lookup(string $upc): ?array
    {
        usleep(3_200_000);
        $country = preg_replace('/[^a-z]/', '', strtolower((string) ($this->config['country'] ?? 'at'))) ?: 'at';
        $ch = curl_init('https://itunes.apple.com/lookup?upc=' . rawurlencode($upc) . '&country=' . $country . '&entity=album');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . ($this->config['user_agent'] ?? 'BleedingStarCatalog/1.0'), 'Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false || $status !== 200) {
            return null;
        }
        $data = json_decode((string) $raw, true);
        return is_array($data) && isset($data['resultCount']) ? $data : null;
    }

    /** Asks the CDN for a large file. iTunes returns a 100px thumb; the same path serves 1000px. */
    public static function largestArtwork(string $url): string
    {
        if (!preg_match('#^https://#', $url)) {
            return '';
        }
        $large = preg_replace('#/\d+x\d+(bb)?(?=\.)#', '/1000x1000bb', $url);
        return is_string($large) && $large !== '' ? $large : $url;
    }

    private function collection(array $result): ?array
    {
        foreach ($result['results'] ?? [] as $item) {
            if (!is_array($item) || ($item['wrapperType'] ?? '') !== 'collection') {
                continue;
            }
            $url = (string) ($item['collectionViewUrl'] ?? '');
            if (preg_match('#^https://(music|itunes)\.apple\.com/#', $url)) {
                return $item;
            }
        }
        return null;
    }

    private function collectionUrl(array $item): ?string
    {
        $url = (string) ($item['collectionViewUrl'] ?? '');
        if (!preg_match('#^https://(music|itunes)\.apple\.com/#', $url)) {
            return null;
        }
        return preg_replace('/[?&]uo=\d+$/', '', $url) ?? $url;
    }

    /** Covers already fetched with an earlier lookup, for releases Spotify did not illustrate. */
    private function coverBackfill(bool $dryRun, int $limit, ?float $deadline): void
    {
        $rows = $this->db->all(
            "SELECT r.id, p.payload_json
             FROM releases r
             JOIN release_formats f ON f.release_id = r.id AND f.upc IS NOT NULL AND f.upc <> ''
             JOIN provider_records p ON p.provider = 'apple' AND p.entity_type = 'upc' AND p.external_id = f.upc
             WHERE (r.cover_path IS NULL OR r.cover_path = '' OR r.cover_source NOT IN ('spotify', 'apple', 'legacy', 'upload'))
               AND NOT (r.editorial_locked = 1 AND r.cover_path IS NOT NULL AND r.cover_path <> '')
             ORDER BY r.id DESC LIMIT " . max(1, $limit * 3)
        );
        $seen = [];
        $done = 0;
        foreach ($rows as $row) {
            if ($done >= $limit) {
                break;
            }
            $id = (int) $row['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }
            $payload = json_decode((string) $row['payload_json'], true);
            $collection = is_array($payload) ? $this->collection($payload) : null;
            if ($collection === null) {
                continue;
            }
            $done++;
            if (!$dryRun) {
                $this->applyCover($id, $collection);
            }
        }
    }

    private function applyCover(int $releaseId, array $collection): void
    {
        $url = self::largestArtwork((string) ($collection['artworkUrl100'] ?? $collection['artworkUrl60'] ?? ''));
        if ($url === '') {
            return;
        }
        $row = $this->db->one('SELECT cover_path, cover_source, editorial_locked FROM releases WHERE id = ?', [$releaseId]);
        if (!cover_may_replace($row, 'apple')) {
            return;
        }
        $covers = new Covers(
            app_root(),
            (string) ($this->config['user_agent'] ?? 'BleedingStarCatalog/1.0'),
            'apple',
            ['mzstatic.com']
        );
        $stored = $covers->store((string) $releaseId, $url);
        if ($stored === null || empty($stored['full'])) {
            return;
        }
        $page = $this->collectionUrl($collection);
        $this->db->exec(
            'UPDATE releases SET cover_source = "apple", cover_path = ?, cover_grid_path = ?, cover_remote_url = NULL, cover_attribution = NULL, cover_page_url = ?, cover_fetched_at = NOW() WHERE id = ?',
            [$stored['full'], $stored['grid'], $page, $releaseId]
        );
    }
}
