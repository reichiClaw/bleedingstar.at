<?php

declare(strict_types=1);

namespace App\Discogs;

use App\Database;

final class Sync
{
    public function __construct(private Database $db, private Client $client, private array $config, private string $root = '')
    {
        if ($this->root === '') {
            $this->root = app_root();
        }
    }

    /** @param float|null $deadline unix time after which the run stops cleanly; the next run resumes */
    public function importLabel(bool $dryRun, ?int $maxPages = null, ?float $deadline = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'reviews' => 0, 'errors' => 0, 'skipped' => 0, 'message' => ''];
        $labelId = (int) $this->config['label_id'];
        $page = 1;
        $pages = 1;
        do {
            try {
                $list = $this->client->get('/labels/' . $labelId . '/releases?per_page=100&page=' . $page);
            } catch (DiscogsException $e) {
                $stats['errors']++;
                $stats['message'] = $e->getMessage();
                if ($e->isQuota()) {
                    $stats['message'] .= ' (resume is safe; nothing was deleted)';
                }
                break;
            }
            $pages = (int) ($list['pagination']['pages'] ?? 1);
            foreach ($list['releases'] ?? [] as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                if ($deadline !== null && microtime(true) > $deadline) {
                    $stats['message'] = trim($stats['message'] . ' time budget reached, next run continues');
                    break 2;
                }
                try {
                    $this->importOne($id, $dryRun, $stats);
                } catch (DiscogsException $e) {
                    $stats['errors']++;
                    $stats['message'] = $e->getMessage();
                    if ($e->isQuota()) {
                        break 2;
                    }
                }
            }
            $page++;
        } while ($page <= $pages && ($maxPages === null || $page <= $maxPages));

        return $stats;
    }

    public function refreshCache(bool $dryRun): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'reviews' => 0, 'errors' => 0, 'skipped' => 0, 'message' => ''];
        $rows = $this->db->all(
            "SELECT external_id FROM external_ids WHERE provider = 'discogs' AND entity_type = 'release'"
        );
        foreach ($rows as $row) {
            try {
                $detail = $this->client->get('/releases/' . rawurlencode($row['external_id']));
            } catch (DiscogsException $e) {
                $stats['errors']++;
                $stats['message'] = $e->getMessage();
                if ($e->isQuota()) {
                    break;
                }
                continue;
            }
            $releaseId = $this->entityId('release', $row['external_id']);
            if (!$releaseId) {
                continue;
            }
            if ($dryRun) {
                $stats['updated']++;
                continue;
            }
            $this->savePayload($row['external_id'], $detail);
            $stored = $this->archiveCover($row['external_id'], $detail);
            $this->applyStoredCover($releaseId, $stored, $detail);
            $stats['updated']++;
        }
        return $stats;
    }

    private function importOne(string $externalId, bool $dryRun, array &$stats): void
    {
        $existing = $this->entityId('release', $externalId);
        $detail = $this->client->get('/releases/' . rawurlencode($externalId));
        if (!$this->labelAllowed($detail)) {
            $stats['skipped']++;
            return;
        }
        $stored = null;
        if (!$dryRun) {
            $this->savePayload($externalId, $detail);
            $stored = $this->archiveCover($externalId, $detail);
        }
        $masterId = (string) ($detail['master_id'] ?? '');
        $groupedId = $masterId !== '' && $masterId !== '0' ? $this->entityId('master', $masterId) : null;
        if ($existing || $groupedId) {
            if (!$dryRun) {
                $this->updateExisting((int) ($existing ?: $groupedId), $detail, $externalId, $masterId, $stored);
            }
            $stats['updated']++;
            return;
        }
        $upc = $this->barcode($detail);
        $byUpc = $upc ? $this->db->one('SELECT release_id FROM release_formats WHERE upc = ? LIMIT 1', [$upc]) : null;
        if ($byUpc) {
            if (!$dryRun) {
                $this->linkExisting((int) $byUpc['release_id'], $detail, $externalId, $masterId, $upc, $stored);
            }
            $stats['updated']++;
            return;
        }
        $candidate = $this->fuzzyCandidate($detail);
        if ($candidate) {
            if (!$dryRun) {
                $this->queueReview($externalId, (int) $candidate['id'], $detail, 'Gleicher normalisierter Künstler und Titel');
            }
            $stats['reviews']++;
            return;
        }
        if (!$dryRun) {
            $this->createRelease($detail, $externalId, $masterId, $upc, $stored);
        }
        $stats['created']++;
    }

    private function updateExisting(int $releaseId, array $detail, string $externalId, string $masterId, ?array $stored): void
    {
        $this->rememberIds($releaseId, $externalId, $masterId);
        $row = $this->db->one('SELECT editorial_locked, cover_source FROM releases WHERE id = ?', [$releaseId]);
        if (!$row || (int) $row['editorial_locked'] === 1) {
            return;
        }
        $this->applyStoredCover($releaseId, $stored, $detail);
        $this->fillNotes($releaseId, $detail);
        $this->addDiscogsLink($releaseId, $detail);
        $this->addVideos($releaseId, $detail);
        $this->addFormats($releaseId, $detail);
        $count = $this->db->one('SELECT COUNT(*) AS c FROM tracks WHERE release_id = ?', [$releaseId]);
        if ((int) ($count['c'] ?? 0) === 0) {
            $this->replaceTracks($releaseId, $detail);
        }
    }

    private function linkExisting(int $releaseId, array $detail, string $externalId, string $masterId, ?string $upc, ?array $stored = null): void
    {
        $this->rememberIds($releaseId, $externalId, $masterId);
        $this->addFormats($releaseId, $detail, $upc);
        $this->addDiscogsLink($releaseId, $detail);
        $this->addVideos($releaseId, $detail);
        $this->fillNotes($releaseId, $detail);
        if ($stored === null) {
            $stored = $this->archiveCover($externalId, $detail);
        }
        $this->applyStoredCover($releaseId, $stored, $detail);
    }

    private function createRelease(array $detail, string $externalId, string $masterId, ?string $upc, ?array $stored): void
    {
        $date = $this->parseReleased((string) ($detail['released'] ?? ''), (int) ($detail['year'] ?? 0));
        $title = trim((string) ($detail['title'] ?? 'Ohne Titel'));
        $slug = $this->freeSlug(slugify($title));
        $type = $this->typeFromFormats($detail);
        $id = $this->db->insert(
            'INSERT INTO releases (slug, title, description_html, release_year, release_month, release_day, release_date_precision, release_type, status, cover_source, cover_path, cover_grid_path, cover_attribution, cover_page_url, cover_fetched_at, source, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,"published",?,?,?,?,?,?,"discogs",NOW(),NOW())',
            [
                $slug,
                $title,
                $this->notesHtml($detail) ?: null,
                $date['year'], $date['month'], $date['day'], $date['precision'], $type,
                $stored ? 'discogs' : null,
                $stored['full'] ?? null,
                $stored['grid'] ?? null,
                $stored ? 'Cover: Discogs' : null,
                $stored ? ($detail['uri'] ?? null) : null,
                $stored ? date('Y-m-d H:i:s') : null,
            ]
        );
        $this->rememberIds($id, $externalId, $masterId);
        $this->attachArtists($id, $detail);
        $this->replaceTracks($id, $detail);
        $this->addFormats($id, $detail, $upc);
        $this->addDiscogsLink($id, $detail);
        $this->addVideos($id, $detail);
    }

    private function queueReview(string $externalId, int $candidateId, array $detail, string $reason): void
    {
        $open = $this->db->one(
            "SELECT id FROM import_reviews WHERE provider='discogs' AND external_id=? AND status='open'",
            [$externalId]
        );
        if ($open) {
            return;
        }
        $this->db->exec(
            'INSERT INTO import_reviews (provider, external_id, candidate_release_id, payload_json, reason, status, created_at) VALUES ("discogs",?,?,?,?,"open",NOW())',
            [$externalId, $candidateId, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $reason]
        );
    }

    public function mergeReview(int $reviewId): bool
    {
        $review = $this->db->one("SELECT * FROM import_reviews WHERE id = ? AND status = 'open'", [$reviewId]);
        if (!$review || !$review['candidate_release_id']) {
            return false;
        }
        $detail = json_decode($review['payload_json'], true);
        if (!is_array($detail)) {
            return false;
        }
        $releaseId = (int) $review['candidate_release_id'];
        $master = (string) ($detail['master_id'] ?? '');
        $this->db->transaction(function () use ($review, $detail, $releaseId, $master) {
            $this->linkExisting($releaseId, $detail, (string) $review['external_id'], $master, $this->barcode($detail));
            $count = $this->db->one('SELECT COUNT(*) AS c FROM tracks WHERE release_id = ?', [$releaseId]);
            if ((int) ($count['c'] ?? 0) === 0) {
                $this->replaceTracks($releaseId, $detail);
            }
            $this->db->exec("UPDATE import_reviews SET status = 'merged' WHERE id = ?", [$review['id']]);
        });
        return true;
    }

    private function attachArtists(int $releaseId, array $detail): void
    {
        $pos = 0;
        foreach ($detail['artists'] ?? [] as $artist) {
            $name = trim((string) ($artist['name'] ?? ''));
            $name = preg_replace('/\s+\(\d+\)$/', '', $name) ?? $name;
            if ($name === '') {
                continue;
            }
            $slug = slugify($name);
            $id = $this->matchArtist($name, $slug, (string) ($artist['id'] ?? ''));
            if ($id === null) {
                $id = $this->db->insert(
                    'INSERT INTO artists (slug, name, status, image_source, created_at, updated_at) VALUES (?,?,"published","discogs",NOW(),NOW())',
                    [$this->freeArtistSlug($slug), $name]
                );
            }
            if (!empty($artist['id'])) {
                $this->db->exec(
                    'INSERT IGNORE INTO external_ids (provider, entity_type, entity_id, external_id) VALUES ("discogs","artist",?,?)',
                    [$id, (string) $artist['id']]
                );
            }
            $this->db->exec(
                'INSERT IGNORE INTO release_artists (release_id, artist_id, position) VALUES (?,?,?)',
                [$releaseId, $id, $pos++]
            );
        }
    }

    private function matchArtist(string $name, string $slug, string $externalId): ?int
    {
        if ($externalId !== '') {
            $linked = $this->db->one(
                "SELECT entity_id FROM external_ids WHERE provider = 'discogs' AND entity_type = 'artist' AND external_id = ?",
                [$externalId]
            );
            if ($linked) {
                return (int) $linked['entity_id'];
            }
        }
        $byName = $this->db->one('SELECT id FROM artists WHERE name = ?', [$name]);
        if ($byName) {
            return (int) $byName['id'];
        }
        if ($slug !== '') {
            $bySlug = $this->db->one('SELECT id, name FROM artists WHERE slug = ?', [$slug]);
            if ($bySlug && normalize_match_key((string) $bySlug['name']) === normalize_match_key($name)) {
                return (int) $bySlug['id'];
            }
        }
        return null;
    }

    private function replaceTracks(int $releaseId, array $detail): void
    {
        $pos = 1;
        foreach ($detail['tracklist'] ?? [] as $track) {
            if (($track['type_'] ?? 'track') !== 'track') {
                continue;
            }
            $title = trim((string) ($track['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $this->db->exec(
                'INSERT INTO tracks (release_id, position, title, duration) VALUES (?,?,?,?)',
                [$releaseId, $pos++, $title, ($track['duration'] ?? '') !== '' ? $track['duration'] : null]
            );
        }
    }

    private function addFormats(int $releaseId, array $detail, ?string $upc = null): void
    {
        $upc = $upc ?? $this->barcode($detail);
        $catno = null;
        foreach ($detail['labels'] ?? [] as $label) {
            if (in_array((int) ($label['id'] ?? 0), $this->config['allow_label_ids'] ?? [], true)) {
                $catno = ($label['catno'] ?? '') !== '' ? $label['catno'] : null;
                break;
            }
        }
        foreach ($detail['formats'] ?? [] as $format) {
            $name = trim((string) ($format['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $details = isset($format['descriptions']) ? implode(', ', (array) $format['descriptions']) : null;
            $exists = $this->db->one(
                'SELECT id FROM release_formats WHERE release_id = ? AND name = ? AND (catalog_number <=> ?) LIMIT 1',
                [$releaseId, $name, $catno]
            );
            if ($exists) {
                continue;
            }
            $this->db->exec(
                'INSERT INTO release_formats (release_id, name, catalog_number, upc, details) VALUES (?,?,?,?,?)',
                [$releaseId, $name, $catno, $upc, $details]
            );
            $upc = null;
        }
    }

    private function savePayload(string $externalId, array $detail): void
    {
        $json = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $this->db->exec(
            'INSERT INTO provider_records (provider, entity_type, external_id, payload_json, fetched_at)
             VALUES ("discogs","release",?,?,NOW())
             ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), fetched_at = NOW()',
            [$externalId, $json]
        );
    }

    private function archiveCover(string $externalId, array $detail): ?array
    {
        $image = $this->largestImage($detail);
        if (!$image || empty($image['uri'])) {
            return null;
        }
        $covers = new Covers($this->root, (string) ($this->config['user_agent'] ?? 'BleedingStarCatalog/1.0'));
        return $covers->store($externalId, (string) $image['uri']);
    }

    private function applyStoredCover(int $releaseId, ?array $stored, array $detail): void
    {
        if (!$stored || empty($stored['full'])) {
            return;
        }
        $row = $this->db->one('SELECT cover_path, cover_source, editorial_locked FROM releases WHERE id = ?', [$releaseId]);
        if (!$row) {
            return;
        }
        $own = !empty($row['cover_path']) && in_array($row['cover_source'], ['legacy', 'upload'], true);
        if ($own || ((int) $row['editorial_locked'] === 1 && !empty($row['cover_path']))) {
            return;
        }
        $this->db->exec(
            'UPDATE releases SET cover_source = "discogs", cover_path = ?, cover_grid_path = ?, cover_remote_url = NULL, cover_attribution = "Cover: Discogs", cover_page_url = ?, cover_fetched_at = NOW() WHERE id = ?',
            [$stored['full'], $stored['grid'], $detail['uri'] ?? null, $releaseId]
        );
    }

    private function fillNotes(int $releaseId, array $detail): void
    {
        $html = $this->notesHtml($detail);
        if ($html === '') {
            return;
        }
        $this->db->exec(
            'UPDATE releases SET description_html = ? WHERE id = ? AND (description_html IS NULL OR description_html = "")',
            [$html, $releaseId]
        );
    }

    private function notesHtml(array $detail): string
    {
        $notes = trim((string) ($detail['notes'] ?? ''));
        if ($notes === '') {
            return '';
        }
        $html = '';
        foreach (preg_split("/\n{2,}/", str_replace("\r", '', $notes)) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $html .= '<p>' . nl2br(e($part)) . '</p>';
        }
        return $html;
    }

    private function addVideos(int $releaseId, array $detail): void
    {
        foreach ($detail['videos'] ?? [] as $video) {
            $url = trim((string) ($video['uri'] ?? ''));
            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }
            $exists = $this->db->one(
                'SELECT id FROM release_links WHERE release_id = ? AND url = ? LIMIT 1',
                [$releaseId, $url]
            );
            if ($exists) {
                continue;
            }
            $label = trim((string) ($video['title'] ?? ''));
            $this->db->exec(
                'INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?,?,?,"youtube",0)',
                [$releaseId, $label !== '' ? $label : 'Video', $url]
            );
        }
    }

    private function largestImage(array $detail): ?array
    {
        $pool = [];
        foreach ($detail['images'] ?? [] as $image) {
            if (!empty($image['uri'])) {
                $pool[] = $image;
            }
        }
        $primary = array_values(array_filter(
            $pool,
            static fn (array $image): bool => ($image['type'] ?? '') === 'primary'
        ));
        $candidates = $primary !== [] ? $primary : $pool;
        $best = null;
        $bestArea = -1;
        foreach ($candidates as $image) {
            $area = (int) ($image['width'] ?? 0) * (int) ($image['height'] ?? 0);
            if ($best === null || $area > $bestArea) {
                $best = $image;
                $bestArea = $area;
            }
        }
        return $best;
    }

    private function addDiscogsLink(int $releaseId, array $detail): void
    {
        $uri = $detail['uri'] ?? '';
        if ($uri === '') {
            return;
        }
        $exists = $this->db->one(
            "SELECT id FROM release_links WHERE release_id = ? AND provider = 'discogs' LIMIT 1",
            [$releaseId]
        );
        if ($exists) {
            return;
        }
        $this->db->exec(
            'INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?,"Discogs",?,"discogs",0)',
            [$releaseId, $uri]
        );
    }

    private function rememberIds(int $releaseId, string $externalId, string $masterId): void
    {
        $this->db->exec(
            'INSERT IGNORE INTO external_ids (provider, entity_type, entity_id, external_id) VALUES ("discogs","release",?,?)',
            [$releaseId, $externalId]
        );
        if ($masterId !== '' && $masterId !== '0') {
            $this->db->exec(
                'INSERT IGNORE INTO external_ids (provider, entity_type, entity_id, external_id) VALUES ("discogs","master",?,?)',
                [$releaseId, $masterId]
            );
        }
    }

    private function labelAllowed(array $detail): bool
    {
        $ids = array_map('intval', $this->config['allow_label_ids'] ?? []);
        $names = array_map('mb_strtolower', $this->config['allow_label_names'] ?? []);
        foreach ($detail['labels'] ?? [] as $label) {
            if (in_array((int) ($label['id'] ?? 0), $ids, true)) {
                return true;
            }
            $name = mb_strtolower(trim((string) ($label['name'] ?? '')));
            if ($name !== '' && in_array($name, $names, true)) {
                return true;
            }
        }
        return false;
    }

    private function fuzzyCandidate(array $detail): ?array
    {
        $titleKey = normalize_match_key((string) ($detail['title'] ?? ''));
        $artistName = trim((string) (($detail['artists'][0]['name'] ?? '')));
        $artistName = preg_replace('/\s+\(\d+\)$/', '', $artistName) ?? $artistName;
        $artistKey = normalize_match_key($artistName);
        if ($titleKey === '' || $artistKey === '') {
            return null;
        }
        $rows = $this->db->all(
            "SELECT r.id, r.title, a.name AS artist_name
             FROM releases r
             JOIN release_artists ra ON ra.release_id = r.id AND ra.position = 0
             JOIN artists a ON a.id = ra.artist_id
             WHERE r.source = 'legacy'"
        );
        foreach ($rows as $row) {
            if (normalize_match_key($row['title']) === $titleKey && normalize_match_key($row['artist_name']) === $artistKey) {
                return $row;
            }
        }
        return null;
    }

    private function parseReleased(string $released, int $year): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $released, $m)) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => (int) $m[3], 'precision' => 'day'];
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $released, $m)) {
            return ['year' => (int) $m[1], 'month' => (int) $m[2], 'day' => null, 'precision' => 'month'];
        }
        if (preg_match('/^(\d{4})$/', $released, $m) || $year > 0) {
            return ['year' => $year > 0 ? $year : (int) ($m[1] ?? $year), 'month' => null, 'day' => null, 'precision' => 'year'];
        }
        return ['year' => null, 'month' => null, 'day' => null, 'precision' => 'unknown'];
    }

    private function typeFromFormats(array $detail): ?string
    {
        $blob = '';
        foreach ($detail['formats'] ?? [] as $format) {
            $blob .= ' ' . ($format['name'] ?? '') . ' ' . implode(' ', (array) ($format['descriptions'] ?? []));
        }
        if (preg_match('/\bSingle\b/i', $blob)) {
            return 'single';
        }
        if (preg_match('/\bEP\b/', $blob)) {
            return 'ep';
        }
        if (preg_match('/\bAlbum\b|\bLP\b/i', $blob)) {
            return 'album';
        }
        return null;
    }

    private function barcode(array $detail): ?string
    {
        foreach ($detail['identifiers'] ?? [] as $id) {
            if (strcasecmp((string) ($id['type'] ?? ''), 'Barcode') === 0) {
                $value = preg_replace('/\s+/', '', (string) ($id['value'] ?? '')) ?? '';
                return $value !== '' ? $value : null;
            }
        }
        return null;
    }

    private function entityId(string $type, string $externalId): ?int
    {
        $row = $this->db->one(
            "SELECT entity_id FROM external_ids WHERE provider = 'discogs' AND entity_type = ? AND external_id = ?",
            [$type, $externalId]
        );
        return $row ? (int) $row['entity_id'] : null;
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
