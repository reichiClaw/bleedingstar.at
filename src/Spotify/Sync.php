<?php

declare(strict_types=1);

namespace App\Spotify;

use App\Database;

/**
 * Creates label releases from Spotify. Every run searches the Spotify catalogue for
 * the label name and looks at each album the catalogue does not know yet: the UPC
 * or an identical artist and title link it to an existing release, an uncertain
 * match becomes a review, and anything else is created as a new release with its
 * artists, tracks, UPC, Spotify link and cover. A release whose date lies ahead is
 * stored as it is and shown as upcoming on the site until that day. Spotify lists a
 * release the moment it goes live in the first time zone, so a release day usually
 * shows up hours before the local midnight. Nothing is deleted or overwritten.
 */
final class Sync
{
    public const PROVIDER = 'spotify';

    private bool $halted = false;

    public function __construct(private Database $db, private Links $links, private array $config)
    {
    }

    /** True when the last run stopped on quota or authentication; the link pass should not start then. */
    public function halted(): bool
    {
        return $this->halted;
    }

    /** @param float|null $deadline unix time after which the run stops cleanly; the next run resumes */
    public function importLabel(bool $dryRun, ?float $deadline = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'reviews' => 0, 'errors' => 0, 'skipped' => 0, 'message' => ''];
        $this->halted = false;
        if (!Links::configured($this->config)) {
            $stats['message'] = 'Spotify not configured (client_id/client_secret)';
            return $stats;
        }
        $known = $this->knownAlbumIds();
        $fresh = [];
        foreach ($this->labelNames() as $labelName) {
            $query = sprintf('label:"%s"', str_replace('"', '', $labelName));
            $offset = 0;
            do {
                if ($deadline !== null && microtime(true) > $deadline) {
                    $stats['message'] = trim($stats['message'] . ' time budget reached, next run continues');
                    break 2;
                }
                try {
                    $page = $this->links->labelPage($query, $offset);
                } catch (SpotifyException $e) {
                    $stats['errors']++;
                    $stats['message'] = trim($stats['message'] . ' ' . $e->getMessage());
                    $this->halted = $e->fatal;
                    break 2;
                }
                foreach ($page['items'] as $item) {
                    $id = (string) ($item['id'] ?? '');
                    if ($id === '' || isset($known[$id]) || isset($fresh[$id])) {
                        continue;
                    }
                    $fresh[$id] = $item;
                }
                $offset += count($page['items']);
            } while ($page['items'] !== [] && $offset < $page['total'] && $offset < 1000);
        }
        // Newest first: a release of today is created before older gaps are filled.
        uasort($fresh, static fn (array $a, array $b): int => strcmp((string) ($b['release_date'] ?? ''), (string) ($a['release_date'] ?? '')));
        foreach ($fresh as $id => $item) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $stats['message'] = trim($stats['message'] . ' time budget reached, next run continues');
                break;
            }
            try {
                $this->importOne((string) $id, $dryRun, $stats);
            } catch (SpotifyException $e) {
                $stats['errors']++;
                $stats['message'] = trim($stats['message'] . ' ' . $e->getMessage());
                if ($e->fatal) {
                    $this->halted = true;
                    break;
                }
            }
        }
        if (($stats['merged'] ?? 0) > 0) {
            $stats['message'] = trim($stats['merged'] . ' auto-linked ' . $stats['message']);
        }
        return $stats;
    }

    /** @throws SpotifyException */
    private function importOne(string $albumId, bool $dryRun, array &$stats): void
    {
        $album = $this->links->album($albumId);
        if ($album === null || !$this->labelAllowed($album)) {
            $stats['skipped']++;
            return;
        }
        $upc = $this->upc($album);
        $byUpc = $upc !== null ? $this->db->one('SELECT release_id FROM release_formats WHERE upc = ? LIMIT 1', [$upc]) : null;
        if ($byUpc) {
            if (!$dryRun) {
                $this->enrich((int) $byUpc['release_id'], $album, $albumId, $upc);
                $this->closeOpenReview($albumId, (int) $byUpc['release_id'], 'Automatisch zugeordnet: über UPC (Spotify)');
            }
            $stats['updated']++;
            return;
        }
        $candidate = $this->fuzzyCandidate($album);
        if ($candidate) {
            $candidateId = (int) $candidate['id'];
            $reason = $candidate['exact'] ? null : 'Gleicher Künstler, ähnlicher Titel (Spotify)';
            if ($candidate['exact'] && $this->hasOtherSpotifyAlbum($candidateId, $albumId)) {
                $reason = 'Gleicher Künstler und Titel, aber dem Release ist schon ein anderes Spotify-Album zugeordnet';
            }
            if ($reason === null) {
                if (!$dryRun) {
                    $this->enrich($candidateId, $album, $albumId, $upc);
                    $this->resolveReview($albumId, $candidateId, $album, 'Automatisch zugeordnet: gleicher Künstler und Titel (Spotify)');
                }
                $stats['updated']++;
                $stats['merged'] = ($stats['merged'] ?? 0) + 1;
                return;
            }
            if (!$dryRun) {
                $this->queueReview($albumId, $candidateId, $album, $reason);
            }
            $stats['reviews']++;
            return;
        }
        if (!$dryRun) {
            $this->createRelease($album, $albumId, $upc);
        }
        $stats['created']++;
    }

    public function mergeReview(int $reviewId): bool
    {
        $review = $this->db->one("SELECT * FROM import_reviews WHERE id = ? AND status = 'open' AND provider = 'spotify'", [$reviewId]);
        if (!$review || !$review['candidate_release_id']) {
            return false;
        }
        $album = json_decode((string) $review['payload_json'], true);
        if (!is_array($album)) {
            return false;
        }
        $releaseId = (int) $review['candidate_release_id'];
        $this->db->transaction(function () use ($review, $album, $releaseId) {
            $this->enrich($releaseId, $album, (string) $review['external_id'], $this->upc($album));
            $this->db->exec("UPDATE import_reviews SET status = 'merged' WHERE id = ?", [$review['id']]);
        });
        return true;
    }

    /** Adds what the existing release lacks; never replaces editorial content. */
    private function enrich(int $releaseId, array $album, string $albumId, ?string $upc): void
    {
        $this->rememberId($releaseId, $albumId);
        $this->links->attachAlbum($releaseId, $album);
        $row = $this->db->one('SELECT * FROM releases WHERE id = ?', [$releaseId]);
        if (!$row) {
            return;
        }
        $this->addFormat($releaseId, $upc);
        if ((int) $row['editorial_locked'] === 1) {
            return;
        }
        $date = $this->parseDate($album);
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
        $count = $this->db->one('SELECT COUNT(*) AS c FROM tracks WHERE release_id = ?', [$releaseId]);
        if ((int) ($count['c'] ?? 0) === 0) {
            $this->insertTracks($releaseId, $album);
        }
    }

    private function createRelease(array $album, string $albumId, ?string $upc): void
    {
        $date = $this->parseDate($album);
        $title = trim((string) ($album['name'] ?? ''));
        if ($title === '') {
            $title = 'Ohne Titel';
        }
        $id = $this->db->insert(
            'INSERT INTO releases (slug, title, release_year, release_month, release_day, release_date_precision, release_type, status, source, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,"published","spotify",NOW(),NOW())',
            [
                $this->freeSlug(slugify($title)),
                $title,
                $date['year'], $date['month'], $date['day'], $date['precision'],
                $this->type($album),
            ]
        );
        $this->rememberId($id, $albumId);
        $this->attachArtists($id, $album);
        $this->insertTracks($id, $album);
        $this->addFormat($id, $upc);
        $this->links->attachAlbum($id, $album);
    }

    private function attachArtists(int $releaseId, array $album): void
    {
        $pos = 0;
        foreach ($album['artists'] ?? [] as $artist) {
            if (!is_array($artist)) {
                continue;
            }
            $name = trim((string) ($artist['name'] ?? ''));
            if ($name === '' || strcasecmp($name, 'Various Artists') === 0) {
                continue;
            }
            $externalId = (string) ($artist['id'] ?? '');
            $id = $this->matchArtist($name, $externalId);
            if ($id === null) {
                $id = $this->db->insert(
                    'INSERT INTO artists (slug, name, status, image_source, created_at, updated_at) VALUES (?,?,"published","spotify",NOW(),NOW())',
                    [$this->freeArtistSlug(slugify($name)), $name]
                );
            }
            if ($externalId !== '') {
                $this->db->exec(
                    'INSERT IGNORE INTO external_ids (provider, entity_type, entity_id, external_id) VALUES ("spotify","artist",?,?)',
                    [$id, $externalId]
                );
            }
            $this->db->exec('INSERT IGNORE INTO release_artists (release_id, artist_id, position) VALUES (?,?,?)', [$releaseId, $id, $pos++]);
        }
    }

    private function matchArtist(string $name, string $externalId): ?int
    {
        if ($externalId !== '') {
            $linked = $this->db->one(
                "SELECT entity_id FROM external_ids WHERE provider = 'spotify' AND entity_type = 'artist' AND external_id = ?",
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
        $tracks = array_values(array_filter($album['tracks']['items'] ?? [], 'is_array'));
        usort($tracks, static fn (array $a, array $b): int => [(int) ($a['disc_number'] ?? 1), (int) ($a['track_number'] ?? 0)] <=> [(int) ($b['disc_number'] ?? 1), (int) ($b['track_number'] ?? 0)]);
        $pos = 1;
        foreach ($tracks as $track) {
            $title = trim((string) ($track['name'] ?? ''));
            if ($title === '') {
                continue;
            }
            $seconds = intdiv((int) ($track['duration_ms'] ?? 0), 1000);
            $isrc = strtoupper(trim((string) ($track['external_ids']['isrc'] ?? '')));
            $this->db->exec(
                'INSERT IGNORE INTO tracks (release_id, position, title, duration, isrc) VALUES (?,?,?,?,?)',
                [$releaseId, $pos++, $title, $seconds > 0 ? sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60) : null, $isrc !== '' ? $isrc : null]
            );
        }
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

    /**
     * Album ids the catalogue already holds: recorded ids plus the ids in stored Spotify links.
     *
     * @return array<string, int>
     */
    private function knownAlbumIds(): array
    {
        $known = [];
        foreach ($this->db->all("SELECT entity_id, external_id FROM external_ids WHERE provider = 'spotify' AND entity_type = 'release'") as $row) {
            $known[(string) $row['external_id']] = (int) $row['entity_id'];
        }
        foreach ($this->db->all("SELECT release_id, url FROM release_links WHERE provider = 'spotify'") as $row) {
            if (preg_match('#open\.spotify\.com/album/([A-Za-z0-9]+)#', (string) $row['url'], $m)) {
                $known[$m[1]] = (int) $row['release_id'];
            }
        }
        return $known;
    }

    private function queueReview(string $albumId, int $candidateId, array $album, string $reason): void
    {
        $open = $this->db->one("SELECT id FROM import_reviews WHERE provider='spotify' AND external_id=? AND status='open'", [$albumId]);
        if ($open) {
            return;
        }
        $this->db->exec(
            'INSERT INTO import_reviews (provider, external_id, candidate_release_id, payload_json, reason, status, created_at) VALUES ("spotify",?,?,?,?,"open",NOW())',
            [$albumId, $candidateId, $this->payload($album), $reason]
        );
    }

    /**
     * Release of another source by the same artist with the same title. 'exact' is true when
     * exactly one release matches on the strict key; such a match is linked automatically.
     * A loose match or several strict matches only become a review.
     *
     * @return array{id:int|string, title:string, artist_name:string, exact:bool}|null
     */
    private function fuzzyCandidate(array $album): ?array
    {
        $artistName = trim((string) ($album['artists'][0]['name'] ?? ''));
        $title = (string) ($album['name'] ?? '');
        $strictKey = $this->strictKey($title, $artistName);
        $looseKey = $this->looseKey($title, $artistName);
        $artistKey = $this->artistKey($artistName);
        if ($looseKey === '' || $artistKey === '') {
            return null;
        }
        // Two Spotify albums are never the same release, so only releases from other sources are candidates.
        $rows = $this->db->all(
            "SELECT DISTINCT r.id, r.title, a.name AS artist_name
             FROM releases r
             JOIN release_artists ra ON ra.release_id = r.id
             JOIN artists a ON a.id = ra.artist_id
             WHERE r.source <> 'spotify'"
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

    private function hasOtherSpotifyAlbum(int $releaseId, string $albumId): bool
    {
        if ($this->db->one(
            "SELECT 1 FROM external_ids WHERE provider = 'spotify' AND entity_type = 'release' AND entity_id = ? AND external_id <> ?",
            [$releaseId, $albumId]
        ) !== null) {
            return true;
        }
        $link = $this->db->one("SELECT url FROM release_links WHERE release_id = ? AND provider = 'spotify' LIMIT 1", [$releaseId]);
        return $link !== null && !str_contains((string) $link['url'], '/album/' . $albumId);
    }

    /** Closes an open review for this album or records the automatic link so it can be traced. */
    private function resolveReview(string $albumId, int $candidateId, array $album, string $reason): void
    {
        if ($this->closeOpenReview($albumId, $candidateId, $reason)) {
            return;
        }
        $this->db->exec(
            'INSERT IGNORE INTO import_reviews (provider, external_id, candidate_release_id, payload_json, reason, status, created_at) VALUES ("spotify",?,?,?,?,"merged",NOW())',
            [$albumId, $candidateId, $this->payload($album), $reason]
        );
    }

    /** Marks the open review of this album as merged; returns false when there was none. */
    private function closeOpenReview(string $albumId, int $releaseId, string $reason): bool
    {
        $open = $this->db->one("SELECT id FROM import_reviews WHERE provider='spotify' AND external_id=? AND status='open'", [$albumId]);
        if (!$open) {
            return false;
        }
        $merged = $this->db->one("SELECT id FROM import_reviews WHERE provider='spotify' AND external_id=? AND status='merged'", [$albumId]);
        if ($merged) {
            $this->db->exec('DELETE FROM import_reviews WHERE id=?', [$open['id']]);
        } else {
            $this->db->exec("UPDATE import_reviews SET status='merged', candidate_release_id=?, reason=? WHERE id=?", [$releaseId, $reason, $open['id']]);
        }
        return true;
    }

    /** Review payload: the album plus the title and link keys the admin list reads. */
    private function payload(array $album): string
    {
        $album['title'] = (string) ($album['name'] ?? '');
        $album['link'] = (string) ($album['external_urls']['spotify'] ?? '');
        return (string) json_encode($album, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    private function strictKey(string $title, string $artistName): string
    {
        return normalize_match_key($this->withoutArtistPrefix($title, $artistName));
    }

    private function looseKey(string $title, string $artistName = ''): string
    {
        $title = $this->withoutArtistPrefix($title, $artistName);
        $title = preg_replace('/\s*[\(\[].*?[\)\]]/u', '', $title) ?? $title;
        $title = preg_replace('/\s*[-–:]?\s*\b(ep|single|radio edit|remastered|deluxe)\b\s*$/iu', '', $title) ?? $title;
        return normalize_match_key($title);
    }

    /**
     * The search already filters by label; the album object no longer carries the label name,
     * but its copyright lines do. An album whose lines name no allowed label is left out.
     */
    private function labelAllowed(array $album): bool
    {
        $lines = [];
        foreach ($album['copyrights'] ?? [] as $copyright) {
            $text = normalize_match_key((string) (is_array($copyright) ? ($copyright['text'] ?? '') : $copyright));
            if ($text !== '') {
                $lines[] = $text;
            }
        }
        if ($lines === []) {
            return true;
        }
        foreach ($this->labelNames() as $name) {
            $key = normalize_match_key($name);
            foreach ($lines as $line) {
                if ($key !== '' && str_contains($line, $key)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<string> */
    private function labelNames(): array
    {
        $names = $this->config['label_names'] ?? [];
        return array_values(array_filter(array_map('strval', (array) $names), static fn (string $n): bool => trim($n) !== ''));
    }

    private function upc(array $album): ?string
    {
        $upc = preg_replace('/[^A-Za-z0-9]+/', '', (string) ($album['external_ids']['upc'] ?? '')) ?? '';
        return $upc !== '' ? $upc : null;
    }

    /** Spotify files EPs as singles; four or more tracks make it an EP, as Spotify's own apps show it. */
    private function type(array $album): ?string
    {
        return match (strtolower((string) ($album['album_type'] ?? ''))) {
            'single' => (int) ($album['total_tracks'] ?? 0) >= 4 ? 'ep' : 'single',
            'album' => 'album',
            'compilation' => 'compilation',
            default => null,
        };
    }

    private function parseDate(array $album): array
    {
        $date = (string) ($album['release_date'] ?? '');
        $precision = (string) ($album['release_date_precision'] ?? '');
        if (($precision === 'day' || $precision === '') && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && (int) $m[2] > 0 && (int) $m[3] > 0) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3], 'precision' => 'day'];
        }
        if ($precision !== 'year' && preg_match('/^(\d{4})-(\d{2})/', $date, $m) && (int) $m[2] > 0) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => null, 'precision' => 'month'];
        }
        if (preg_match('/^(\d{4})/', $date, $m)) {
            return ['year' => (int) $m[1], 'month' => null, 'day' => null, 'precision' => 'year'];
        }
        return ['year' => null, 'month' => null, 'day' => null, 'precision' => 'unknown'];
    }

    private function rememberId(int $releaseId, string $albumId): void
    {
        $this->db->exec(
            'INSERT IGNORE INTO external_ids (provider, entity_type, entity_id, external_id) VALUES ("spotify","release",?,?)',
            [$releaseId, $albumId]
        );
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
