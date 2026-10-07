<?php

declare(strict_types=1);

namespace App\Deezer;

use App\Database;
use App\Discogs\Covers;
use App\Discogs\DiscogsException;

/**
 * Imports the label's digital releases from Deezer. Deezer lists every
 * distributed release with label name, UPC, exact date, type, tracks with
 * ISRC and a large cover, so it complements the Discogs catalogue.
 *
 * Rules are the same as for Discogs: the label must match the allow-list,
 * UPC matches link to the existing release, the same artist and title only
 * create a review, editorial locks are respected and nothing is deleted.
 */
final class Sync
{
    public const PROVIDER = 'deezer';

    public function __construct(private Database $db, private Client $client, private array $config, private string $root = '')
    {
        if ($this->root === '') {
            $this->root = app_root();
        }
    }

    /** @param float|null $deadline unix time after which the run stops cleanly; the next run resumes */
    public function importLabel(bool $dryRun, ?float $deadline = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'reviews' => 0, 'errors' => 0, 'skipped' => 0, 'message' => ''];
        $seen = [];
        foreach ($this->labelNames() as $labelName) {
            $path = '/search/album?limit=100&q=' . rawurlencode('label:"' . $labelName . '"');
            while ($path !== null) {
                if ($deadline !== null && microtime(true) > $deadline) {
                    $stats['message'] = trim($stats['message'] . ' time budget reached, next run continues');
                    break 2;
                }
                try {
                    $list = $this->client->get($path);
                } catch (DiscogsException $e) {
                    $stats['errors']++;
                    $stats['message'] = $e->getMessage();
                    break;
                }
                foreach ($list['data'] ?? [] as $row) {
                    $id = (string) ($row['id'] ?? '');
                    if ($id === '' || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    if ($deadline !== null && microtime(true) > $deadline) {
                        $stats['message'] = trim($stats['message'] . ' time budget reached, next run continues');
                        break 3;
                    }
                    try {
                        $this->importOne($id, $dryRun, $stats);
                    } catch (DiscogsException $e) {
                        $stats['errors']++;
                        $stats['message'] = $e->getMessage();
                        if ($e->isQuota()) {
                            break 3;
                        }
                    }
                }
                $next = (string) ($list['next'] ?? '');
                $path = $next !== '' ? $this->relative($next) : null;
            }
        }
        if (($stats['merged'] ?? 0) > 0) {
            $stats['message'] = trim($stats['merged'] . ' auto-linked ' . $stats['message']);
        }
        return $stats;
    }

    private function importOne(string $externalId, bool $dryRun, array &$stats): void
    {
        $album = $this->client->get('/album/' . rawurlencode($externalId));
        if (!$this->labelAllowed($album)) {
            $stats['skipped']++;
            return;
        }
        $album['tracks_full'] = $this->client->get('/album/' . rawurlencode($externalId) . '/tracks')['data'] ?? [];
        $existing = $this->entityId($externalId);
        $upc = $this->upc($album);

        $stored = null;
        if (!$dryRun) {
            $previousMd5 = $this->storedMd5($externalId);
            $this->savePayload($externalId, $album);
            $stored = $this->archiveCover($externalId, $album, $previousMd5);
        }
        if ($existing) {
            if (!$dryRun) {
                $this->enrich($existing, $album, $externalId, $upc, $stored);
                $this->closeStaleReview($externalId, $existing);
            }
            $stats['updated']++;
            return;
        }
        $byUpc = $upc ? $this->db->one('SELECT release_id FROM release_formats WHERE upc = ? LIMIT 1', [$upc]) : null;
        if ($byUpc) {
            if (!$dryRun) {
                $this->enrich((int) $byUpc['release_id'], $album, $externalId, $upc, $stored);
                $this->closeStaleReview($externalId, (int) $byUpc['release_id']);
            }
            $stats['updated']++;
            return;
        }
        $candidate = $this->fuzzyCandidate($album);
        if ($candidate) {
            $candidateId = (int) $candidate['id'];
            $reason = $candidate['exact'] ? null : 'Gleicher Künstler, ähnlicher Titel (Deezer)';
            if ($candidate['exact'] && $this->hasOtherDeezerAlbum($candidateId, $externalId)) {
                $reason = 'Gleicher Künstler und Titel, aber dem Release ist schon ein anderes Deezer-Album zugeordnet';
            }
            if ($reason === null) {
                if (!$dryRun) {
                    $this->enrich($candidateId, $album, $externalId, $upc, $stored);
                    $this->resolveReview($externalId, $candidateId, $album, 'Automatisch zugeordnet: gleicher Künstler und Titel (Deezer)');
                }
                $stats['updated']++;
                $stats['merged'] = ($stats['merged'] ?? 0) + 1;
                return;
            }
            if (!$dryRun) {
                $this->queueReview($externalId, $candidateId, $album, $reason);
            }
            $stats['reviews']++;
            return;
        }
        if (!$dryRun) {
            $this->createRelease($album, $externalId, $upc, $stored);
        }
        $stats['created']++;
    }

    public function mergeReview(int $reviewId): bool
    {
        $review = $this->db->one("SELECT * FROM import_reviews WHERE id = ? AND status = 'open' AND provider = 'deezer'", [$reviewId]);
        if (!$review || !$review['candidate_release_id']) {
            return false;
        }
        $album = json_decode($review['payload_json'], true);
        if (!is_array($album)) {
            return false;
        }
        $releaseId = (int) $review['candidate_release_id'];
        $this->db->transaction(function () use ($review, $album, $releaseId) {
            $externalId = (string) $review['external_id'];
            $this->enrich($releaseId, $album, $externalId, $this->upc($album), $this->archiveCover($externalId, $album, $this->storedMd5($externalId)));
            $this->db->exec("UPDATE import_reviews SET status = 'merged' WHERE id = ?", [$review['id']]);
        });
        return true;
    }

    /** Adds what the existing release lacks; never replaces editorial content. */
    private function enrich(int $releaseId, array $album, string $externalId, ?string $upc, ?array $stored): void
    {
        $this->rememberId($releaseId, $externalId);
        $this->addLink($releaseId, $album);
        $row = $this->db->one('SELECT * FROM releases WHERE id = ?', [$releaseId]);
        if (!$row) {
            return;
        }
        $this->addFormat($releaseId, $upc);
        if ((int) $row['editorial_locked'] === 1) {
            return;
        }
        $date = $this->parseDate((string) ($album['release_date'] ?? ''));
        $rank = ['unknown' => 0, 'year' => 1, 'month' => 2, 'day' => 3];
        $better = ($rank[$date['precision']] ?? 0) > ($rank[$row['release_date_precision']] ?? 0);
        $sameYear = $row['release_year'] === null || (int) $row['release_year'] === $date['year'];
        if ($better && $sameYear) {
            $this->db->exec(
                'UPDATE releases SET release_year=?, release_month=?, release_day=?, release_date_precision=?, updated_at=NOW() WHERE id=?',
                [$date['year'], $date['month'], $date['day'], $date['precision'], $releaseId]
            );
        }
        if (empty($row['release_type']) && ($type = $this->type($album)) !== null) {
            $this->db->exec('UPDATE releases SET release_type=? WHERE id=?', [$type, $releaseId]);
        }
        $this->applyStoredCover($releaseId, $stored, $album);
        $count = $this->db->one('SELECT COUNT(*) AS c FROM tracks WHERE release_id = ?', [$releaseId]);
        if ((int) ($count['c'] ?? 0) === 0) {
            $this->insertTracks($releaseId, $album);
        } else {
            $this->fillIsrc($releaseId, $album);
        }
    }

    private function createRelease(array $album, string $externalId, ?string $upc, ?array $stored): void
    {
        $date = $this->parseDate((string) ($album['release_date'] ?? ''));
        $title = trim((string) ($album['title'] ?? 'Ohne Titel'));
        $id = $this->db->insert(
            'INSERT INTO releases (slug, title, release_year, release_month, release_day, release_date_precision, release_type, status, cover_source, cover_path, cover_grid_path, cover_attribution, cover_page_url, cover_fetched_at, source, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,"published",?,?,?,?,?,?,"deezer",NOW(),NOW())',
            [
                $this->freeSlug(slugify($title)),
                $title,
                $date['year'], $date['month'], $date['day'], $date['precision'],
                $this->type($album),
                $stored ? 'deezer' : null,
                $stored['full'] ?? null,
                $stored['grid'] ?? null,
                $stored ? 'Cover: Deezer' : null,
                $stored ? ($album['link'] ?? null) : null,
                $stored ? date('Y-m-d H:i:s') : null,
            ]
        );
        $this->rememberId($id, $externalId);
        $this->attachArtists($id, $album);
        $this->insertTracks($id, $album);
        $this->addFormat($id, $upc);
        $this->addLink($id, $album);
    }

    private function attachArtists(int $releaseId, array $album): void
    {
        $contributors = array_values(array_filter(
            $album['contributors'] ?? [],
            static fn (array $c): bool => strcasecmp((string) ($c['role'] ?? 'Main'), 'Main') === 0
        ));
        if ($contributors === [] && !empty($album['artist']['name'])) {
            $contributors = [$album['artist']];
        }
        $pos = 0;
        foreach ($contributors as $artist) {
            $name = trim((string) ($artist['name'] ?? ''));
            if ($name === '' || strcasecmp($name, 'Various Artists') === 0) {
                continue;
            }
            $id = $this->matchArtist($name, (string) ($artist['id'] ?? ''));
            if ($id === null) {
                $id = $this->db->insert(
                    'INSERT INTO artists (slug, name, status, image_source, created_at, updated_at) VALUES (?,?,"published","deezer",NOW(),NOW())',
                    [$this->freeArtistSlug(slugify($name)), $name]
                );
            }
            if (!empty($artist['id'])) {
                $this->db->exec(
                    'INSERT IGNORE INTO external_ids (provider, entity_type, entity_id, external_id) VALUES ("deezer","artist",?,?)',
                    [$id, (string) $artist['id']]
                );
            }
            $this->db->exec('INSERT IGNORE INTO release_artists (release_id, artist_id, position) VALUES (?,?,?)', [$releaseId, $id, $pos++]);
        }
    }

    private function matchArtist(string $name, string $externalId): ?int
    {
        if ($externalId !== '') {
            $linked = $this->db->one(
                "SELECT entity_id FROM external_ids WHERE provider = 'deezer' AND entity_type = 'artist' AND external_id = ?",
                [$externalId]
            );
            if ($linked) {
                return (int) $linked['entity_id'];
            }
        }
        $key = $this->artistKey($name);
        foreach ($this->db->all('SELECT id, name FROM artists') as $row) {
            if ($this->artistKey((string) $row['name']) === $key) {
                return (int) $row['id'];
            }
        }
        return null;
    }

    private function insertTracks(int $releaseId, array $album): void
    {
        $pos = 1;
        foreach ($this->tracks($album) as $track) {
            $title = trim((string) ($track['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $seconds = (int) ($track['duration'] ?? 0);
            $isrc = trim((string) ($track['isrc'] ?? ''));
            $this->db->exec(
                'INSERT IGNORE INTO tracks (release_id, position, title, duration, isrc) VALUES (?,?,?,?,?)',
                [$releaseId, $pos++, $title, $seconds > 0 ? sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60) : null, $isrc !== '' ? $isrc : null]
            );
        }
    }

    private function fillIsrc(int $releaseId, array $album): void
    {
        $rows = $this->db->all('SELECT id, title FROM tracks WHERE release_id = ? AND (isrc IS NULL OR isrc = "")', [$releaseId]);
        if (!$rows) {
            return;
        }
        $byKey = [];
        foreach ($this->tracks($album) as $track) {
            $isrc = trim((string) ($track['isrc'] ?? ''));
            if ($isrc !== '') {
                $byKey[normalize_match_key((string) ($track['title'] ?? ''))] = $isrc;
            }
        }
        foreach ($rows as $row) {
            $isrc = $byKey[normalize_match_key((string) $row['title'])] ?? null;
            if ($isrc !== null) {
                $this->db->exec('UPDATE tracks SET isrc = ? WHERE id = ?', [$isrc, $row['id']]);
            }
        }
    }

    private function tracks(array $album): array
    {
        $tracks = $album['tracks_full'] ?? ($album['tracks']['data'] ?? []);
        usort($tracks, static fn (array $a, array $b): int => [(int) ($a['disk_number'] ?? 1), (int) ($a['track_position'] ?? 0)] <=> [(int) ($b['disk_number'] ?? 1), (int) ($b['track_position'] ?? 0)]);
        return $tracks;
    }

    private function addFormat(int $releaseId, ?string $upc): void
    {
        if ($upc === null) {
            return;
        }
        $exists = $this->db->one('SELECT id FROM release_formats WHERE release_id = ? AND upc = ? LIMIT 1', [$releaseId, $upc]);
        if ($exists) {
            return;
        }
        $this->db->exec(
            'INSERT INTO release_formats (release_id, name, catalog_number, upc, details) VALUES (?,"Digital",NULL,?,"Streaming, Download")',
            [$releaseId, $upc]
        );
    }

    private function addLink(int $releaseId, array $album): void
    {
        $url = trim((string) ($album['link'] ?? ''));
        if (!preg_match('#^https://(www\.)?deezer\.com/#', $url)) {
            return;
        }
        $exists = $this->db->one("SELECT id FROM release_links WHERE release_id = ? AND provider = 'deezer' LIMIT 1", [$releaseId]);
        if ($exists) {
            return;
        }
        $this->db->exec(
            'INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?,"Deezer",?,"deezer",0)',
            [$releaseId, $url]
        );
    }

    private function savePayload(string $externalId, array $album): void
    {
        $json = json_encode($album, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $this->db->exec(
            'INSERT INTO provider_records (provider, entity_type, external_id, payload_json, fetched_at)
             VALUES ("deezer","release",?,?,NOW())
             ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), fetched_at = NOW()',
            [$externalId, $json]
        );
    }

    /** md5_image from the payload of the previous run; Deezer changes it whenever the artwork changes. */
    private function storedMd5(string $externalId): ?string
    {
        $row = $this->db->one('SELECT payload_json FROM provider_records WHERE provider = "deezer" AND entity_type = "release" AND external_id = ?', [$externalId]);
        $payload = $row ? json_decode((string) $row['payload_json'], true) : null;
        $md5 = is_array($payload) ? (string) ($payload['md5_image'] ?? '') : '';
        return $md5 !== '' ? $md5 : null;
    }

    private function archiveCover(string $externalId, array $album, ?string $previousMd5 = null): ?array
    {
        $covers = new Covers($this->root, (string) ($this->config['user_agent'] ?? 'BleedingStarCatalog/1.0'), self::PROVIDER, ['dzcdn.net', 'deezer.com']);
        $md5 = (string) ($album['md5_image'] ?? '');
        if ($md5 !== '' && $md5 === $previousMd5 && ($kept = $covers->existing($externalId)) !== null) {
            return $kept;
        }
        $xl = (string) ($album['cover_xl'] ?? '');
        // The CDN serves the largest available file for an oversized request; try that first.
        $candidates = [];
        if ($xl !== '') {
            $candidates[] = preg_replace('#/\d+x\d+-#', '/1800x1800-', $xl) ?? $xl;
            $candidates[] = $xl;
        }
        foreach (array_unique($candidates) as $url) {
            $stored = $covers->store($externalId, $url);
            if ($stored !== null) {
                return $stored;
            }
        }
        return null;
    }

    private function applyStoredCover(int $releaseId, ?array $stored, array $album): void
    {
        if (!$stored || empty($stored['full'])) {
            return;
        }
        $row = $this->db->one('SELECT cover_path, cover_source, editorial_locked FROM releases WHERE id = ?', [$releaseId]);
        if (!$row || !empty($row['cover_path'])) {
            // An existing cover of any source wins; Deezer only fills gaps.
            return;
        }
        $this->db->exec(
            'UPDATE releases SET cover_source = "deezer", cover_path = ?, cover_grid_path = ?, cover_remote_url = NULL, cover_attribution = "Cover: Deezer", cover_page_url = ?, cover_fetched_at = NOW() WHERE id = ?',
            [$stored['full'], $stored['grid'], $album['link'] ?? null, $releaseId]
        );
    }

    private function queueReview(string $externalId, int $candidateId, array $album, string $reason): void
    {
        $open = $this->db->one("SELECT id FROM import_reviews WHERE provider='deezer' AND external_id=? AND status='open'", [$externalId]);
        if ($open) {
            return;
        }
        $this->db->exec(
            'INSERT INTO import_reviews (provider, external_id, candidate_release_id, payload_json, reason, status, created_at) VALUES ("deezer",?,?,?,?,"open",NOW())',
            [$externalId, $candidateId, json_encode($album, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $reason]
        );
    }

    /**
     * Release of another source by the same artist with the same title.
     * 'exact' is true when exactly one release matches on the strict key (case,
     * punctuation, accents, " - Single" and a leading "<artist> - " ignored); such a
     * match is linked automatically. A loose match (brackets, EP/Single/Edit suffixes
     * ignored) or several strict matches only become a review.
     *
     * @return array{id:int|string, title:string, artist_name:string, exact:bool}|null
     */
    private function fuzzyCandidate(array $album): ?array
    {
        $artistName = (string) ($album['artist']['name'] ?? '');
        $title = (string) ($album['title'] ?? '');
        $strictKey = $this->strictKey($title, $artistName);
        $looseKey = $this->looseKey($title, $artistName);
        $artistKey = $this->artistKey($artistName);
        if ($looseKey === '' || $artistKey === '') {
            return null;
        }
        // Two Deezer albums are never the same release, so only other sources are candidates.
        $rows = $this->db->all(
            "SELECT DISTINCT r.id, r.title, a.name AS artist_name
             FROM releases r
             JOIN release_artists ra ON ra.release_id = r.id
             JOIN artists a ON a.id = ra.artist_id
             WHERE r.source <> 'deezer'"
        );
        $strict = [];
        $loose = null;
        foreach ($rows as $row) {
            $rowArtist = (string) $row['artist_name'];
            if ($this->artistKey($rowArtist) !== $artistKey) {
                continue;
            }
            $rowTitle = (string) $row['title'];
            if ($strictKey !== '' && $this->strictKey($rowTitle, $rowArtist) === $strictKey) {
                $strict[(int) $row['id']] = $row;
            } elseif ($loose === null && $this->looseKey($rowTitle, $rowArtist) === $looseKey) {
                $loose = $row;
            }
        }
        if (count($strict) === 1) {
            return reset($strict) + ['exact' => true];
        }
        if ($strict !== []) {
            return reset($strict) + ['exact' => false];
        }
        return $loose !== null ? $loose + ['exact' => false] : null;
    }

    /** True when a different Deezer album is already linked to this release. */
    private function hasOtherDeezerAlbum(int $releaseId, string $externalId): bool
    {
        return $this->db->one(
            "SELECT 1 FROM external_ids WHERE provider = 'deezer' AND entity_type = 'release' AND entity_id = ? AND external_id <> ?",
            [$releaseId, $externalId]
        ) !== null;
    }

    /** An album linked by id or UPC needs no open review any more. */
    private function closeStaleReview(string $externalId, int $releaseId): void
    {
        $this->closeOpenReview($externalId, $releaseId, 'Automatisch zugeordnet: über UPC oder vorhandene Verknüpfung (Deezer)');
    }

    /** Closes an open review for this album or records the automatic link so it can be traced. */
    private function resolveReview(string $externalId, int $candidateId, array $album, string $reason): void
    {
        if ($this->closeOpenReview($externalId, $candidateId, $reason)) {
            return;
        }
        $this->db->exec(
            'INSERT IGNORE INTO import_reviews (provider, external_id, candidate_release_id, payload_json, reason, status, created_at) VALUES ("deezer",?,?,?,?,"merged",NOW())',
            [$externalId, $candidateId, json_encode($album, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $reason]
        );
    }

    /** Marks the open review of this album as merged; returns false when there was none. */
    private function closeOpenReview(string $externalId, int $releaseId, string $reason): bool
    {
        $open = $this->db->one("SELECT id FROM import_reviews WHERE provider='deezer' AND external_id=? AND status='open'", [$externalId]);
        if (!$open) {
            return false;
        }
        // (provider, external_id, status) is unique: when a merged row already exists, the open one is simply dropped.
        $merged = $this->db->one("SELECT id FROM import_reviews WHERE provider='deezer' AND external_id=? AND status='merged'", [$externalId]);
        if ($merged) {
            $this->db->exec('DELETE FROM import_reviews WHERE id=?', [$open['id']]);
        } else {
            $this->db->exec("UPDATE import_reviews SET status='merged', candidate_release_id=?, reason=? WHERE id=?", [$releaseId, $reason, $open['id']]);
        }
        return true;
    }

    /** Artist key that ignores a leading "The". */
    private function artistKey(string $name): string
    {
        $name = preg_replace('/^\s*the\s+/iu', '', trim($name)) ?? $name;
        return normalize_match_key($name);
    }

    /** Drops a leading "<artist> - " that some shops put into the title. */
    private function withoutArtistPrefix(string $title, string $artistName): string
    {
        $artistKey = $this->artistKey($artistName);
        if ($artistKey !== '' && preg_match('/^(.+?)\s+[-–:|]\s+(.+)$/u', trim($title), $m) && $this->artistKey($m[1]) === $artistKey) {
            return $m[2];
        }
        return $title;
    }

    /** Title key for automatic linking: case, punctuation, accents, " - Single" and an artist prefix do not matter. */
    private function strictKey(string $title, string $artistName): string
    {
        return normalize_match_key($this->withoutArtistPrefix($title, $artistName));
    }

    /** Title key that also ignores bracketed additions and EP/Single/Edit suffixes. Only used to propose reviews. */
    private function looseKey(string $title, string $artistName = ''): string
    {
        $title = $this->withoutArtistPrefix($title, $artistName);
        $title = preg_replace('/\s*[\(\[].*?[\)\]]/u', '', $title) ?? $title;
        $title = preg_replace('/\s*[-–:]?\s*\b(ep|single|radio edit|remastered|deluxe)\b\s*$/iu', '', $title) ?? $title;
        return normalize_match_key($title);
    }

    private function labelAllowed(array $album): bool
    {
        $label = mb_strtolower(trim((string) ($album['label'] ?? '')));
        if ($label === '') {
            return false;
        }
        foreach ($this->labelNames() as $name) {
            if (mb_strtolower($name) === $label) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function labelNames(): array
    {
        $names = $this->config['label_names'] ?? [];
        return array_values(array_filter(array_map('strval', (array) $names), static fn (string $n): bool => $n !== ''));
    }

    private function upc(array $album): ?string
    {
        $upc = preg_replace('/[^A-Za-z0-9]+/', '', (string) ($album['upc'] ?? '')) ?? '';
        return $upc !== '' ? $upc : null;
    }

    private function type(array $album): ?string
    {
        return match (strtolower((string) ($album['record_type'] ?? ''))) {
            'single' => 'single',
            'ep' => 'ep',
            'album' => 'album',
            'compile' => 'compilation',
            default => null,
        };
    }

    private function parseDate(string $date): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && (int) $m[2] > 0 && (int) $m[3] > 0) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3], 'precision' => 'day'];
        }
        if (preg_match('/^(\d{4})-(\d{2})/', $date, $m) && (int) $m[2] > 0) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => null, 'precision' => 'month'];
        }
        if (preg_match('/^(\d{4})/', $date, $m)) {
            return ['year' => (int) $m[1], 'month' => null, 'day' => null, 'precision' => 'year'];
        }
        return ['year' => null, 'month' => null, 'day' => null, 'precision' => 'unknown'];
    }

    private function rememberId(int $releaseId, string $externalId): void
    {
        $this->db->exec(
            'INSERT IGNORE INTO external_ids (provider, entity_type, entity_id, external_id) VALUES ("deezer","release",?,?)',
            [$releaseId, $externalId]
        );
    }

    private function entityId(string $externalId): ?int
    {
        $row = $this->db->one(
            "SELECT entity_id FROM external_ids WHERE provider = 'deezer' AND entity_type = 'release' AND external_id = ?",
            [$externalId]
        );
        return $row ? (int) $row['entity_id'] : null;
    }

    private function relative(string $url): string
    {
        $parts = parse_url($url);
        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function freeSlug(string $base): string
    {
        $slug = $base !== '' ? $base : 'release';
        $i = 2;
        $try = $slug;
        while ($this->db->one('SELECT id FROM releases WHERE slug = ?', [$try])) {
            $try = $slug . '-' . $i++;
        }
        return $try;
    }

    private function freeArtistSlug(string $base): string
    {
        $slug = $base !== '' ? $base : 'artist';
        $i = 2;
        $try = $slug;
        while ($this->db->one('SELECT id FROM artists WHERE slug = ?', [$try])) {
            $try = $slug . '-' . $i++;
        }
        return $try;
    }
}
