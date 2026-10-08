<?php

declare(strict_types=1);

namespace App\Spotify;

use App\Database;
use App\Discogs\Covers;

/**
 * Looks releases up in the Spotify Web API, stores the album page as a link and
 * keeps the largest album image locally. Spotify has no public search, so the
 * lookup needs a registered app (client id and secret, Client Credentials; no user
 * login). A release is found by UPC, then by ISRC, then by title and artist.
 * Several exact hits are resolved by type, year and track count; a remaining tie
 * keeps the earliest release Spotify returned. A release without a hit is retried
 * after 30 days. Covers from Spotify replace Apple, Deezer and Discogs artwork.
 * Uploads, archive covers and a locked cover stay.
 */
class Links
{
    private const MATCHER = 3;

    private ?string $token = null;

    private int $covers = 0;

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
        $this->covers = 0;
        if ($this->linkMissing($dryRun, max(1, $limit), $deadline, $stats) && ($deadline === null || microtime(true) <= $deadline)) {
            $this->coverBackfill($dryRun, 40, $deadline, $stats);
        }
        if ($this->covers > 0) {
            $stats['message'] = trim($stats['message'] . ' covers ' . $this->covers);
        }
        return $stats;
    }

    /** @return bool false when the run must stop (quota or auth); a time budget still allows the cover pass to notice it */
    private function linkMissing(bool $dryRun, int $limit, ?float $deadline, array &$stats): bool
    {
        $rows = $this->db->all(
            "SELECT r.id, r.title, r.release_year, r.release_type,
                    (SELECT COUNT(*) FROM tracks t WHERE t.release_id = r.id) AS track_count,
                    (SELECT f.upc FROM release_formats f WHERE f.release_id = r.id AND f.upc IS NOT NULL AND f.upc <> '' ORDER BY f.id LIMIT 1) AS upc,
                    (SELECT t.isrc FROM tracks t WHERE t.release_id = r.id AND t.isrc IS NOT NULL AND t.isrc <> '' ORDER BY t.position LIMIT 1) AS isrc1,
                    (SELECT t.isrc FROM tracks t WHERE t.release_id = r.id AND t.isrc IS NOT NULL AND t.isrc <> '' ORDER BY t.position LIMIT 1 OFFSET 1) AS isrc2,
                    (SELECT GROUP_CONCAT(a.name ORDER BY ra.position, a.id SEPARATOR '\n')
                       FROM release_artists ra JOIN artists a ON a.id = ra.artist_id WHERE ra.release_id = r.id) AS artist_names
             FROM releases r
             WHERE NOT EXISTS (SELECT 1 FROM release_links l WHERE l.release_id = r.id AND l.provider = 'spotify')
               AND NOT EXISTS (
                    SELECT 1 FROM provider_records p
                    WHERE p.provider = 'spotify' AND p.entity_type = 'release' AND p.external_id = CAST(r.id AS CHAR)
                      AND p.fetched_at > (NOW() - INTERVAL 30 DAY)
                      AND (p.payload_json LIKE ? OR p.payload_json LIKE ? OR p.payload_json LIKE ?)
               )
             ORDER BY r.id DESC LIMIT " . $limit,
            ['%"matcher":' . self::MATCHER . '%', '%"fixture"%', '%open.spotify.com/album/%']
        );
        foreach ($rows as $row) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $stats['message'] = trim($stats['message'] . ' time budget reached, next run continues');
                break;
            }
            try {
                $album = $this->choose($row);
            } catch (SpotifyException $e) {
                $stats['errors']++;
                $stats['message'] = trim($stats['message'] . ' ' . $e->getMessage());
                if ($e->fatal) {
                    return false;
                }
                continue;
            }
            if ($dryRun) {
                $album === null ? $stats['skipped']++ : $stats['updated']++;
                continue;
            }
            $this->remember((int) $row['id'], $album);
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
            if ($this->applyCover((int) $row['id'], $album)) {
                $this->covers++;
            }
        }
        return true;
    }

    /** Releases that already have a Spotify link, but a cover from a lower-ranked source. */
    private function coverBackfill(bool $dryRun, int $limit, ?float $deadline, array &$stats): void
    {
        $rows = $this->db->all(
            "SELECT r.id, MIN(l.url) AS url, MAX(p.payload_json) AS payload_json
             FROM releases r
             JOIN release_links l ON l.release_id = r.id AND l.provider = 'spotify'
             LEFT JOIN provider_records p ON p.provider = 'spotify' AND p.entity_type = 'release' AND p.external_id = CAST(r.id AS CHAR)
             WHERE (r.cover_path IS NULL OR r.cover_path = '' OR r.cover_source NOT IN ('spotify', 'legacy', 'upload'))
               AND NOT (r.editorial_locked = 1 AND r.cover_path IS NOT NULL AND r.cover_path <> '')
             GROUP BY r.id
             ORDER BY r.id DESC LIMIT " . max(1, $limit)
        );
        foreach ($rows as $row) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $stats['message'] = trim($stats['message'] . ' time budget reached, next run continues');
                break;
            }
            $album = $this->storedAlbum((string) ($row['payload_json'] ?? ''));
            if ($album === null) {
                $id = $this->albumIdFromUrl((string) $row['url']);
                if ($id === null) {
                    continue;
                }
                try {
                    $album = $this->albumById($id);
                } catch (SpotifyException $e) {
                    $stats['errors']++;
                    $stats['message'] = trim($stats['message'] . ' ' . $e->getMessage());
                    if ($e->fatal) {
                        break;
                    }
                    continue;
                }
            }
            if ($album === null || $this->imageUrl($album) === null) {
                continue;
            }
            if ($dryRun) {
                $stats['updated']++;
                continue;
            }
            if ($this->applyCover((int) $row['id'], $album)) {
                $this->covers++;
                $stats['updated']++;
            }
        }
    }

    /** @throws SpotifyException */
    private function choose(array $row): ?array
    {
        $upc = preg_replace('/\D+/', '', (string) ($row['upc'] ?? '')) ?? '';
        if ($upc !== '') {
            $hit = $this->byUpc($upc);
            if ($hit !== null) {
                return $hit;
            }
        }
        $title = (string) $row['title'];
        $artists = array_slice($this->artistNames($row), 0, 3);
        foreach (['isrc1', 'isrc2'] as $column) {
            $isrc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($row[$column] ?? '')) ?? '');
            if ($isrc === '') {
                continue;
            }
            $candidates = $artists !== [] ? $artists : [''];
            foreach ($candidates as $artist) {
                $hit = $this->byIsrc($isrc, $title, $artist);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }
        foreach ($artists as $artist) {
            $hit = $this->byTitle($title, $artist, $row);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
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
    private function byIsrc(string $isrc, string $title, string $artist): ?array
    {
        $titleKey = $this->titleKey($title);
        $artistKey = $artist === '' ? '' : normalize_match_key($artist);
        foreach ($this->search('isrc:' . $isrc, 5, 'track') as $track) {
            $album = $track['album'] ?? null;
            if (!is_array($album) || empty($album['external_urls']['spotify'])) {
                continue;
            }
            if ($artistKey !== '' && !$this->namesMatch($artistKey, array_merge($album['artists'] ?? [], $track['artists'] ?? []))) {
                continue;
            }
            $albumTitle = $this->titleKey((string) ($album['name'] ?? ''));
            $trackTitle = $this->titleKey((string) ($track['name'] ?? ''));
            if ($albumTitle === $titleKey || $trackTitle === $titleKey) {
                return $album;
            }
        }
        return null;
    }

    /** @throws SpotifyException */
    private function byTitle(string $title, string $artist, array $row): ?array
    {
        $cleanTitle = str_replace('"', '', $title);
        $cleanArtist = str_replace('"', '', $artist);
        $quoted = sprintf('album:"%s" artist:"%s"', $cleanTitle, $cleanArtist);
        $chosen = $this->fromHits($this->search($quoted, 10), $title, $artist, $row);
        if ($chosen !== null) {
            return $chosen;
        }
        $broad = sprintf('%s artist:"%s"', $cleanTitle, $cleanArtist);
        if ($broad !== $quoted) {
            $chosen = $this->fromHits($this->search($broad, 10), $title, $artist, $row);
            if ($chosen !== null) {
                return $chosen;
            }
            // A release can be missing from the Austrian market and still have a page and artwork.
            $chosen = $this->fromHits($this->search($broad, 10, 'album', false), $title, $artist, $row);
            if ($chosen !== null) {
                return $chosen;
            }
        }
        $byArtist = sprintf('artist:"%s"', $cleanArtist);
        return $this->fromHits($this->search($byArtist, 10, 'album', false), $title, $artist, $row);
    }

    private function fromHits(array $items, string $title, string $artist, array $row): ?array
    {
        $titleKey = $this->titleKey($title);
        $looseKey = $this->looseKey($title);
        $artistKey = normalize_match_key($artist);
        if ($titleKey === '' || $artistKey === '') {
            return null;
        }
        $exact = [];
        $loose = [];
        foreach ($items as $item) {
            if (!$this->namesMatch($artistKey, $item['artists'] ?? [])) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            if ($this->titleKey($name) === $titleKey) {
                $exact[] = $item;
            } elseif ($looseKey !== '' && $this->looseKey($name) === $looseKey) {
                $loose[] = $item;
            }
        }
        if (count($exact) === 1) {
            return $exact[0];
        }
        if (count($exact) > 1) {
            return $this->prefer($exact, $row);
        }
        if (count($loose) === 1) {
            return $loose[0];
        }
        if (count($loose) > 1) {
            return $this->prefer($loose, $row);
        }
        return null;
    }

    /** Picks the hit that best matches type, year and track count. A tie keeps the earliest date, then Spotify's order. */
    private function prefer(array $hits, array $row): array
    {
        $scored = [];
        foreach ($hits as $index => $hit) {
            $scored[] = ['hit' => $hit, 'score' => $this->score($hit, $row), 'index' => $index];
        }
        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            $left = (string) ($a['hit']['release_date'] ?? '');
            $right = (string) ($b['hit']['release_date'] ?? '');
            $left = $left !== '' ? $left : '9999';
            $right = $right !== '' ? $right : '9999';
            if ($left !== $right) {
                return $left <=> $right;
            }
            return $a['index'] <=> $b['index'];
        });
        return $scored[0]['hit'];
    }

    private function score(array $hit, array $row): int
    {
        $score = 0;
        $wanted = (string) ($row['release_type'] ?? '');
        $albumType = (string) ($hit['album_type'] ?? '');
        $mapped = ['single' => 'single', 'album' => 'album', 'compilation' => 'compilation', 'ep' => 'album'][$wanted] ?? '';
        if ($mapped !== '' && $mapped === $albumType) {
            $score += 4;
        }
        $year = (int) ($row['release_year'] ?? 0);
        if ($year > 0 && str_starts_with((string) ($hit['release_date'] ?? ''), (string) $year)) {
            $score += 3;
        }
        $tracks = (int) ($row['track_count'] ?? 0);
        if ($tracks > 0 && (int) ($hit['total_tracks'] ?? 0) === $tracks) {
            $score += 2;
        }
        if ($this->imageUrl($hit) !== null) {
            $score += 1;
        }
        return $score;
    }

    private function titleKey(string $title): string
    {
        $title = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*$/u', '', trim($title)) ?? $title;
        $title = preg_replace('/\s*-\s*(single|ep)\s*$/iu', '', $title) ?? $title;
        return normalize_match_key($title);
    }

    private function looseKey(string $title): string
    {
        $title = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/u', '', $title) ?? $title;
        $title = preg_replace('/\s*-\s*(single|ep|radio edit|edit|remix)\s*$/iu', '', $title) ?? $title;
        return normalize_match_key($title);
    }

    private function namesMatch(string $artistKey, array $artists): bool
    {
        foreach ($artists as $artistRow) {
            if (is_array($artistRow) && normalize_match_key((string) ($artistRow['name'] ?? '')) === $artistKey) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function artistNames(array $row): array
    {
        $names = [];
        foreach (explode("\n", (string) ($row['artist_names'] ?? '')) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function remember(int $releaseId, ?array $album): void
    {
        $payload = $album ?? ['matcher' => self::MATCHER, 'results' => []];
        $this->db->exec(
            'INSERT INTO provider_records (provider, entity_type, external_id, payload_json, fetched_at)
             VALUES ("spotify","release",?,?,NOW())
             ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), fetched_at = NOW()',
            [(string) $releaseId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    private function storedAlbum(string $json): ?array
    {
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || $this->imageUrl($data) === null) {
            return null;
        }
        return $data;
    }

    private function applyCover(int $releaseId, array $album): bool
    {
        $url = $this->imageUrl($album);
        if ($url === null) {
            return false;
        }
        $row = $this->db->one('SELECT cover_path, cover_source, editorial_locked FROM releases WHERE id = ?', [$releaseId]);
        if (!cover_may_replace($row, 'spotify')) {
            return false;
        }
        $stored = $this->storeCover($releaseId, $url);
        if ($stored === null || empty($stored['full'])) {
            return false;
        }
        $page = (string) ($album['external_urls']['spotify'] ?? '');
        $page = $page !== '' ? strtok($page, '?') : null;
        $this->db->exec(
            'UPDATE releases SET cover_source = "spotify", cover_path = ?, cover_grid_path = ?, cover_remote_url = NULL, cover_attribution = NULL, cover_page_url = ?, cover_fetched_at = NOW() WHERE id = ?',
            [$stored['full'], $stored['grid'], $page ?: null, $releaseId]
        );
        return true;
    }

    private function imageUrl(array $album): ?string
    {
        $best = null;
        $bestEdge = -1;
        foreach ($album['images'] ?? [] as $image) {
            if (!is_array($image)) {
                continue;
            }
            $url = (string) ($image['url'] ?? '');
            if (!preg_match('#^https://#', $url)) {
                continue;
            }
            $edge = max((int) ($image['width'] ?? 0), (int) ($image['height'] ?? 0));
            if ($best === null || $edge > $bestEdge) {
                $best = $url;
                $bestEdge = $edge;
            }
        }
        return $best;
    }

    private function albumIdFromUrl(string $url): ?string
    {
        if (preg_match('#open\.spotify\.com/album/([A-Za-z0-9]+)#', $url, $match)) {
            return $match[1];
        }
        return null;
    }

    /** @return array{full:string, grid:?string}|null */
    protected function storeCover(int $releaseId, string $url): ?array
    {
        $covers = new Covers(
            app_root(),
            (string) ($this->config['user_agent'] ?? 'BleedingStarCatalog/1.0'),
            'spotify',
            ['scdn.co', 'spotifycdn.com']
        );
        return $covers->store((string) $releaseId, $url);
    }

    /**
     * Album items, or track items when $type is track. The search endpoint accepts at most 10 results per call.
     *
     * @return array<int, array>
     * @throws SpotifyException
     */
    protected function search(string $query, int $limit, string $type = 'album', bool $withMarket = true): array
    {
        if (!in_array($type, ['album', 'track'], true)) {
            $type = 'album';
        }
        usleep(400_000);
        $market = preg_replace('/[^A-Z]/', '', strtoupper((string) ($this->config['market'] ?? 'AT'))) ?: 'AT';
        $params = [
            'q' => $query,
            'type' => $type,
            'limit' => max(1, min(10, $limit)),
        ];
        if ($withMarket) {
            $params['market'] = $market;
        }
        $url = 'https://api.spotify.com/v1/search?' . http_build_query($params);
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
        $key = $type === 'track' ? 'tracks' : 'albums';
        return array_values(array_filter($data[$key]['items'] ?? [], 'is_array'));
    }

    /** @throws SpotifyException */
    protected function albumById(string $id): ?array
    {
        if (!preg_match('/^[A-Za-z0-9]+$/', $id)) {
            return null;
        }
        usleep(400_000);
        $market = preg_replace('/[^A-Z]/', '', strtoupper((string) ($this->config['market'] ?? 'AT'))) ?: 'AT';
        $url = 'https://api.spotify.com/v1/albums/' . rawurlencode($id) . '?' . http_build_query(['market' => $market]);
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
        if ($status !== 200 || !is_array($data) || empty($data['external_urls']['spotify'])) {
            return null;
        }
        return $data;
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
