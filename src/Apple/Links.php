<?php

declare(strict_types=1);

namespace App\Apple;

use App\Database;

/**
 * Looks releases up in the iTunes Search API by UPC and stores the Apple Music
 * page as a link. The lookup is public and needs no credentials; the API tolerates
 * about 20 requests per minute, so calls are paced and limited per run.
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
            $url = $this->collectionUrl($result);
            if ($url === null) {
                $stats['skipped']++;
                continue;
            }
            $this->db->exec(
                'INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?,"Apple Music",?,"apple",0)',
                [(int) $row['release_id'], $url]
            );
            $stats['updated']++;
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

    private function collectionUrl(array $result): ?string
    {
        foreach ($result['results'] ?? [] as $item) {
            if (($item['wrapperType'] ?? '') !== 'collection') {
                continue;
            }
            $url = (string) ($item['collectionViewUrl'] ?? '');
            if (preg_match('#^https://(music|itunes)\.apple\.com/#', $url)) {
                return preg_replace('/[?&]uo=\d+$/', '', $url) ?? $url;
            }
        }
        return null;
    }
}
