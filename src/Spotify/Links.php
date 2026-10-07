<?php

declare(strict_types=1);

namespace App\Spotify;

use App\Database;

/**
 * Looks releases up in the Spotify Web API and stores the album page as a link.
 * Spotify has no public search, so the lookup needs a registered app (client id and
 * secret, Client Credentials flow; no user login). A release is found by UPC first;
 * without a UPC or without a hit, a title/artist search is accepted only when exactly
 * one result matches title and artist. A release without a hit is retried after 30 days.
 */
class Links
{
    private ?string $token = null;

    public function __construct(private Database $db, private array $config)
    {
    }

    public static function configured(array $config): bool
    {
        return !empty($config['enabled'])
            && trim((string) ($config['client_id'] ?? '')) !== ''
            && trim((string) ($config['client_secret'] ?? '')) !== '';
    }

    /** @param float|null $deadline unix time after which the run stops cleanly; the next run resumes */
    public function run(bool $dryRun, int $limit = 25, ?float $deadline = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'reviews' => 0, 'errors' => 0, 'skipped' => 0, 'message' => ''];
        if (!self::configured($this->config)) {
            $stats['message'] = 'Spotify not configured (client_id/client_secret)';
            return $stats;
        }
        $rows = $this->db->all(
            "SELECT r.id, r.title,
                    (SELECT f.upc FROM release_formats f WHERE f.release_id = r.id AND f.upc IS NOT NULL AND f.upc <> '' ORDER BY f.id LIMIT 1) AS upc,
                    (SELECT a.name FROM release_artists ra JOIN artists a ON a.id = ra.artist_id WHERE ra.release_id = r.id ORDER BY ra.position, a.id LIMIT 1) AS artist_name
             FROM releases r
             WHERE NOT EXISTS (SELECT 1 FROM release_links l WHERE l.release_id = r.id AND l.provider = 'spotify')
               AND NOT EXISTS (SELECT 1 FROM provider_records p WHERE p.provider = 'spotify' AND p.entity_type = 'release' AND p.external_id = CAST(r.id AS CHAR) AND p.fetched_at > (NOW() - INTERVAL 30 DAY))
             ORDER BY r.id DESC LIMIT " . max(1, $limit)
        );
        foreach ($rows as $row) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $stats['message'] = trim($stats['message'] . ' time budget reached, next run continues');
                break;
            }
            $upc = preg_replace('/\D+/', '', (string) ($row['upc'] ?? '')) ?? '';
            $title = (string) $row['title'];
            $artist = (string) ($row['artist_name'] ?? '');
            try {
                $album = $upc !== '' ? $this->byUpc($upc) : null;
                if ($album === null && $title !== '' && $artist !== '') {
                    $album = $this->byTitle($title, $artist);
                }
            } catch (SpotifyException $e) {
                $stats['errors']++;
                $stats['message'] = trim($stats['message'] . ' ' . $e->getMessage());
                if ($e->fatal) {
                    break;
                }
                continue;
            }
            if ($dryRun) {
                $album === null ? $stats['skipped']++ : $stats['updated']++;
                continue;
            }
            $this->db->exec(
                'INSERT INTO provider_records (provider, entity_type, external_id, payload_json, fetched_at)
                 VALUES ("spotify","release",?,?,NOW())
                 ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), fetched_at = NOW()',
                [(string) $row['id'], json_encode($album ?? ['results' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
            $url = $album === null ? '' : (string) ($album['external_urls']['spotify'] ?? '');
            if (!preg_match('#^https://open\.spotify\.com/album/[A-Za-z0-9]+#', $url)) {
                $stats['skipped']++;
                continue;
            }
            $this->db->exec(
                'INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?,"Spotify",?,"spotify",0)',
                [(int) $row['id'], strtok($url, '?')]
            );
            $stats['updated']++;
        }
        return $stats;
    }

    /** @throws SpotifyException */
    private function byUpc(string $upc): ?array
    {
        foreach ($this->search('upc:' . $upc, 5) as $item) {
            if (!empty($item['external_urls']['spotify'])) {
                return $item;
            }
        }
        return null;
    }

    /** @throws SpotifyException */
    private function byTitle(string $title, string $artist): ?array
    {
        $titleKey = $this->titleKey($title);
        $artistKey = normalize_match_key($artist);
        if ($titleKey === '' || $artistKey === '') {
            return null;
        }
        $query = sprintf('album:"%s" artist:"%s"', str_replace('"', '', $title), str_replace('"', '', $artist));
        $hits = [];
        foreach ($this->search($query, 10) as $item) {
            if ($this->titleKey((string) ($item['name'] ?? '')) !== $titleKey) {
                continue;
            }
            foreach ($item['artists'] ?? [] as $artistRow) {
                if (normalize_match_key((string) ($artistRow['name'] ?? '')) === $artistKey) {
                    $hits[] = $item;
                    break;
                }
            }
        }
        // Several exact hits (a single and a re-release, for example) stay unmatched.
        return count($hits) === 1 ? $hits[0] : null;
    }

    private function titleKey(string $title): string
    {
        $title = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*$/u', '', trim($title)) ?? $title;
        $title = preg_replace('/\s*-\s*(single|ep)\s*$/iu', '', $title) ?? $title;
        return normalize_match_key($title);
    }

    /**
     * Album items. The search endpoint accepts at most 10 results per call.
     *
     * @return array<int, array>
     * @throws SpotifyException
     */
    protected function search(string $query, int $limit): array
    {
        usleep(400_000);
        $market = preg_replace('/[^A-Z]/', '', strtoupper((string) ($this->config['market'] ?? 'AT'))) ?: 'AT';
        $url = 'https://api.spotify.com/v1/search?' . http_build_query([
            'q' => $query,
            'type' => 'album',
            'market' => $market,
            'limit' => max(1, min(10, $limit)),
        ]);
        [$status, $data] = $this->request($url, $this->token());
        if ($status === 401) {
            $this->token = null;
            [$status, $data] = $this->request($url, $this->token());
        }
        if ($status === 429) {
            throw new SpotifyException('Spotify quota reached, next run continues', true);
        }
        if ($status === 403) {
            throw new SpotifyException('Spotify rejected the app (HTTP 403); the owner account needs Spotify Premium while the app stays in development mode', true);
        }
        if ($status !== 200 || !is_array($data)) {
            throw new SpotifyException('Spotify search failed (HTTP ' . $status . ')', $status >= 500 || $status === 0);
        }
        return array_values(array_filter($data['albums']['items'] ?? [], 'is_array'));
    }

    /** @throws SpotifyException */
    protected function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $ch = curl_init('https://accounts.spotify.com/api/token');
        curl_setopt_array($ch, $this->curlOptions([
            'Authorization: Basic ' . base64_encode(trim((string) $this->config['client_id']) . ':' . trim((string) $this->config['client_secret'])),
            'Content-Type: application/x-www-form-urlencoded',
        ]) + [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'grant_type=client_credentials']);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $data = $raw === false ? null : json_decode((string) $raw, true);
        if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
            throw new SpotifyException('Spotify auth failed (HTTP ' . $status . '); check client_id and client_secret', true);
        }
        return $this->token = (string) $data['access_token'];
    }

    /** @return array{0:int, 1:mixed} status and decoded body */
    protected function request(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlOptions(['Authorization: Bearer ' . $token, 'Accept: application/json']));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$status, $raw === false ? null : json_decode((string) $raw, true)];
    }

    private function curlOptions(array $headers): array
    {
        $headers[] = 'User-Agent: ' . ($this->config['user_agent'] ?? 'BleedingStarCatalog/1.0');
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
        ];
    }
}
