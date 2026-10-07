<?php

declare(strict_types=1);

namespace App;

/**
 * Imports the WordPress content export. Re-runs update existing rows
 * and never replace a locked editorial field with an empty value.
 */
final class LegacyImporter
{
    public function __construct(private Database $db, private string $root)
    {
    }

    public function import(string $jsonPath): array
    {
        $data = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        $stats = ['artists' => 0, 'releases' => 0, 'news' => 0, 'events' => 0, 'images' => 0];

        $this->db->transaction(function () use ($data, &$stats) {
            $artistIds = [];
            foreach ($data['artists'] as $artist) {
                $id = $this->upsertArtist($artist);
                $artistIds[(string) $artist['id']] = $id;
                $stats['artists']++;
            }
            foreach ($data['releases'] as $release) {
                $this->upsertRelease($release, $artistIds, $stats);
                $stats['releases']++;
            }
            foreach ($data['news'] as $item) {
                if (($item['status'] ?? '') !== 'publish') {
                    continue;
                }
                $this->upsertNews($item);
                $stats['news']++;
            }
            foreach ($data['events'] as $item) {
                if (($item['status'] ?? '') !== 'publish') {
                    continue;
                }
                $this->upsertEvent($item, $artistIds);
                $stats['events']++;
            }
            $this->importDocuments($data['documents'] ?? [], $artistIds);
            $this->importPages($data);
            $this->importRental();
            $this->importRedirects($data, $artistIds);
            $this->importSidebars($data['site']['sidebars'] ?? [], $artistIds);
        });

        return $stats;
    }

    private function upsertArtist(array $artist): int
    {
        $status = ($artist['status'] ?? '') === 'publish' ? 'published' : 'draft';
        $slug = $this->uniqueSlug('artists', (string) $artist['slug'], (string) $artist['id']);
        $image = $this->storeRemote($artist['profile_image'] ?? $artist['image'] ?? null, 'artists');
        $bio = Html::clean($artist['content'] ?? '');
        $meta = $artist['meta'] ?? [];
        $existing = $this->db->one('SELECT id, editorial_locked, bio_html, image_path FROM artists WHERE legacy_id = ?', [(string) $artist['id']]);
        $now = date('Y-m-d H:i:s');
        if ($existing) {
            $locked = (int) $existing['editorial_locked'] === 1;
            $this->db->exec(
                'UPDATE artists SET slug=?, name=?, bio_html=?, status=?, website=?, facebook=?, soundcloud=?, twitter=?, image_path=?, updated_at=? WHERE id=?',
                [
                    $slug,
                    $artist['title'],
                    $locked && $existing['bio_html'] !== '' ? $existing['bio_html'] : ($bio !== '' ? $bio : $existing['bio_html']),
                    $status,
                    $meta['artist_website'] ?? null,
                    $meta['artist_facebook'] ?? null,
                    $meta['artist_soundcloud'] ?? null,
                    $meta['artist_twitter'] ?? null,
                    $locked && $existing['image_path'] ? $existing['image_path'] : ($image ?? $existing['image_path']),
                    $now,
                    $existing['id'],
                ]
            );
            $id = (int) $existing['id'];
        } else {
            $id = $this->db->insert(
                'INSERT INTO artists (legacy_id, slug, name, bio_html, status, website, facebook, soundcloud, twitter, image_path, image_source, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?)',
                [
                    (string) $artist['id'], $slug, $artist['title'], $bio, $status,
                    $meta['artist_website'] ?? null, $meta['artist_facebook'] ?? null,
                    $meta['artist_soundcloud'] ?? null, $meta['artist_twitter'] ?? null,
                    $image, 'legacy', $now, $now,
                ]
            );
        }
        $this->db->exec('DELETE FROM artist_roles WHERE artist_id = ?', [$id]);
        foreach ($artist['categories'] ?? [] as $cat) {
            $name = $cat['name'] ?? '';
            if ($name !== '') {
                $this->db->exec('INSERT IGNORE INTO artist_roles (artist_id, role_name) VALUES (?,?)', [$id, $name]);
            }
        }
        return $id;
    }

    private function upsertRelease(array $release, array $artistIds, array &$stats): void
    {
        $meta = $release['meta'] ?? [];
        $date = $this->parseDmy($meta['release_date'] ?? '');
        $type = $this->guessType((string) $release['title']);
        $status = ($release['status'] ?? '') === 'publish' ? 'published' : 'draft';
        $slug = $this->uniqueSlug('releases', (string) $release['slug'], (string) $release['id']);
        $cover = $this->coverFromThumb($meta['_thumbnail_id'] ?? null, $stats);
        $description = Html::wordpress($release['content'] ?? '');
        $existing = $this->db->one('SELECT * FROM releases WHERE legacy_id = ?', [(string) $release['id']]);
        $now = date('Y-m-d H:i:s');
        $fields = [
            $slug,
            $release['title'],
            $description,
            $date['year'], $date['month'], $date['day'], $date['precision'],
            $type,
            $status,
            $cover,
            $cover ? 'legacy' : null,
        ];
        if ($existing) {
            $locked = (int) $existing['editorial_locked'] === 1;
            if ($locked) {
                $id = (int) $existing['id'];
            } else {
                $this->db->exec(
                    'UPDATE releases SET slug=?, title=?, description_html=IF(? = "" AND description_html IS NOT NULL AND description_html != "", description_html, ?),
                     release_year=?, release_month=?, release_day=?, release_date_precision=?, release_type=?, status=?,
                     cover_path=IF(cover_source = "upload", cover_path, ?), cover_source=IF(cover_source = "upload", cover_source, ?), updated_at=?
                     WHERE id=?',
                    [
                        $slug, $release['title'], $description, $description,
                        $date['year'], $date['month'], $date['day'], $date['precision'],
                        $type, $status,
                        $cover, $cover ? 'legacy' : $existing['cover_source'],
                        $now, $existing['id'],
                    ]
                );
                $id = (int) $existing['id'];
            }
            if (!$locked) {
                $this->db->exec('DELETE FROM tracks WHERE release_id = ?', [$id]);
                $this->db->exec('DELETE FROM release_links WHERE release_id = ? AND manual = 1 AND provider IS NULL', [$id]);
                $this->db->exec('DELETE FROM release_artists WHERE release_id = ?', [$id]);
            }
        } else {
            $id = $this->db->insert(
                'INSERT INTO releases (legacy_id, slug, title, description_html, release_year, release_month, release_day, release_date_precision, release_type, status, cover_path, cover_source, source, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?, "legacy", ?, ?)',
                array_merge([(string) $release['id']], $fields, [$now, $now])
            );
        }
        if ($existing && (int) $existing['editorial_locked'] === 1) {
            return;
        }
        $position = 0;
        foreach ($this->parseList($meta['release_artists'] ?? '') as $legacyArtistId) {
            $aid = $artistIds[(string) $legacyArtistId] ?? null;
            if ($aid) {
                $this->db->exec(
                    'INSERT IGNORE INTO release_artists (release_id, artist_id, position) VALUES (?,?,?)',
                    [$id, $aid, $position++]
                );
            }
        }
        $pos = 1;
        foreach ($this->parseRepeater($meta['release_tracks'] ?? '') as $track) {
            $title = trim((string) ($track['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $this->db->exec(
                'INSERT INTO tracks (release_id, position, title, duration, stream_url) VALUES (?,?,?,?,?)',
                [$id, $pos++, $title, ($track['duration'] ?? '') !== '' ? $track['duration'] : null, ($track['url'] ?? '') !== '' ? $track['url'] : null]
            );
        }
        foreach ($this->parseRepeater($meta['release_links'] ?? '') as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $this->db->exec(
                'INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?,?,?,?,1)',
                [$id, ($link['label'] ?? '') !== '' ? $link['label'] : 'Link', $url, $this->providerFromUrl($url)]
            );
        }
    }

    private function upsertNews(array $item): void
    {
        $slug = $this->uniqueSlug('news', (string) $item['slug'], (string) $item['id']);
        $body = Html::wordpress($item['content'] ?? '');
        $date = substr((string) ($item['date'] ?? ''), 0, 10);
        $existing = $this->db->one('SELECT id FROM news WHERE legacy_id = ?', [(string) $item['id']]);
        if ($existing) {
            $this->db->exec(
                'UPDATE news SET slug=?, title=?, body_html=?, published_on=?, status="published" WHERE id=?',
                [$slug, $item['title'], $body, $date !== '' ? $date : null, $existing['id']]
            );
            return;
        }
        $this->db->insert(
            'INSERT INTO news (legacy_id, slug, title, body_html, published_on, status) VALUES (?,?,?,?,?,"published")',
            [(string) $item['id'], $slug, $item['title'], $body, $date !== '' ? $date : null]
        );
    }

    private function upsertEvent(array $item, array $artistIds): void
    {
        $meta = $item['meta'] ?? [];
        $slug = $this->uniqueSlug('events', (string) $item['slug'], (string) $item['id']);
        $legacyArtist = $this->parseList($meta['event_artists'] ?? '')[0] ?? null;
        $artistId = $legacyArtist ? ($artistIds[(string) $legacyArtist] ?? null) : null;
        $existing = $this->db->one('SELECT id FROM events WHERE legacy_id = ?', [(string) $item['id']]);
        $params = [
            $slug, $item['title'], $meta['event_date'] ?? null, $meta['event_place'] ?? null,
            $meta['event_status'] ?? null, $artistId,
        ];
        if ($existing) {
            $this->db->exec(
                'UPDATE events SET slug=?, title=?, event_date=?, place=?, event_status=?, artist_id=?, status="published" WHERE id=?',
                array_merge($params, [$existing['id']])
            );
            return;
        }
        $this->db->insert(
            'INSERT INTO events (legacy_id, slug, title, event_date, place, event_status, artist_id, status) VALUES (?,?,?,?,?,?,?,"published")',
            array_merge([(string) $item['id']], $params)
        );
    }

    private function importDocuments(array $documents, array $artistIds): void
    {
        $artists = $this->db->all('SELECT id, name FROM artists');
        usort($artists, static fn ($a, $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));
        $this->db->exec('DELETE FROM documents');
        foreach ($documents as $doc) {
            $url = $doc['file']['url'] ?? null;
            if (!$url) {
                continue;
            }
            $artistId = null;
            foreach ($artists as $artist) {
                if (mb_strlen($artist['name']) >= 4 && mb_stripos($doc['title'], $artist['name']) !== false) {
                    $artistId = (int) $artist['id'];
                    break;
                }
            }
            $this->db->exec(
                'INSERT INTO documents (title, file_url, artist_id) VALUES (?,?,?)',
                [$doc['title'], $url, $artistId]
            );
        }
    }

    private function importPages(array $data): void
    {
        $service = null;
        foreach ($data['pages'] as $page) {
            if ($page['slug'] === 'service' && $page['status'] === 'publish') {
                $service = Html::wordpress($page['content']);
            }
        }
        $label = '<p>BleedingStar ist das Label von Christian Reichinger. Im bisherigen Auftritt firmiert es als BleedingStar Music Services, Maria Aich 3, 4971 Aurolzmünster.</p>'
            . '<p>Neben dem Label- und Publishing-Service arbeitet er als Live-Tontechniker und Tourmanager.</p>'
            . '<p>Künstler sind im Archiv mit den Zuordnungen Vertrieb, Booking und Managing geführt. Ein längerer Labeltext ist dort nicht hinterlegt.</p>';
        $radio = '<p>Der bisherige Auftritt hat den Mixlr-Stream von Selecta Jahrusso eingebunden.</p>'
            . '<p><a href="https://mixlr.com/jahrusso">Mixlr: Selecta Jahrusso</a></p>';
        $this->upsertPage('label', 'Label', $label);
        $this->upsertPage('production', 'Production', $service ?: '<p>Beschreibung noch zu ergänzen.</p>');
        $this->upsertPage('radio', 'Radio', $radio);
    }

    private function importRental(): void
    {
        if ($this->db->one('SELECT id FROM rental_categories LIMIT 1')) {
            return;
        }
        $cat = $this->db->insert(
            'INSERT INTO rental_categories (slug, name, sort_order) VALUES ("recording","Recording",1)'
        );
        $items = [
            ['etherface', 'Etherface', 'High End Recording-Lösung für Midas Pro Consolen.', '<p>High End Recording-Lösung für Midas Pro Consolen.</p><p>Technische Details und Preis sind im Archiv nicht hinterlegt.</p>'],
            ['zoom-f8', 'Zoom F8', 'Mobile Recording-Lösung.', '<p>Mobile Recording-Lösung.</p><p>Technische Details und Preis sind im Archiv nicht hinterlegt.</p>'],
            ['rme-digiface-dante', 'RME Digiface Dante', 'Im Archiv nur als Titel geführt.', '<p>Beschreibung noch zu ergänzen. Technische Details und Preis sind nicht hinterlegt.</p>'],
        ];
        $i = 1;
        foreach ($items as [$slug, $name, $summary, $html]) {
            $this->db->insert(
                'INSERT INTO rental_items (category_id, slug, name, summary, description_html, status, sort_order) VALUES (?,?,?,?,?,"published",?)',
                [$cat, $slug, $name, $summary, $html, $i++]
            );
        }
    }

    private function importRedirects(array $data, array $artistIds): void
    {
        $map = [
            '/contact' => '/kontakt',
            '/service' => '/production',
            '/services' => '/production',
            '/newsletter' => '/kontakt',
            '/redeem' => '/kontakt',
            '/startseite' => '/',
        ];
        foreach ($data['artists'] as $artist) {
            if (($artist['status'] ?? '') === 'publish') {
                $row = $this->db->one('SELECT slug FROM artists WHERE legacy_id = ?', [(string) $artist['id']]);
                if ($row) {
                    $map['/' . $artist['slug']] = '/artists/' . $row['slug'];
                }
            }
        }
        foreach ($data['releases'] as $release) {
            if (($release['status'] ?? '') === 'publish') {
                $row = $this->db->one('SELECT slug FROM releases WHERE legacy_id = ?', [(string) $release['id']]);
                if ($row) {
                    $map['/' . $release['slug']] = '/releases/' . $row['slug'];
                }
            }
        }
        foreach ($data['news'] as $item) {
            if (($item['status'] ?? '') === 'publish') {
                $row = $this->db->one('SELECT slug FROM news WHERE legacy_id = ?', [(string) $item['id']]);
                if ($row) {
                    $map['/' . $item['slug']] = '/news/' . $row['slug'];
                }
            }
        }
        foreach ($map as $from => $to) {
            if ($from === $to || isset($map[$to])) {
                continue;
            }
            $this->db->exec(
                'INSERT INTO redirects (source_path, target_path) VALUES (?,?) ON DUPLICATE KEY UPDATE target_path = VALUES(target_path)',
                [$from, $to]
            );
        }
    }

    private function importSidebars(array $sidebars, array $artistIds): void
    {
        foreach ($sidebars as $bar) {
            $chunks = [];
            foreach ($bar['widgets'] ?? [] as $widget) {
                $text = trim((string) ($widget['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $title = trim((string) ($widget['title'] ?? ''));
                $chunks[] = ($title !== '' ? '<h2>' . e($title) . '</h2>' : '') . Html::wordpress(nl2br($text));
            }
            if (!$chunks) {
                continue;
            }
            $html = implode("\n", $chunks);
            foreach ($bar['page_ids'] ?? [] as $legacyId) {
                $artistId = $artistIds[(string) $legacyId] ?? null;
                if (!$artistId) {
                    continue;
                }
                $existing = $this->db->one('SELECT bio_html, editorial_locked FROM artists WHERE id = ?', [$artistId]);
                if (!$existing || (int) $existing['editorial_locked'] === 1) {
                    continue;
                }
                if (str_contains((string) $existing['bio_html'], 'data-booking')) {
                    continue;
                }
                $bio = rtrim((string) $existing['bio_html']) . '<section data-booking="1">' . $html . '</section>';
                $this->db->exec('UPDATE artists SET bio_html = ? WHERE id = ?', [$bio, $artistId]);
            }
        }
    }

    private function upsertPage(string $slug, string $title, string $html): void
    {
        $existing = $this->db->one('SELECT slug FROM pages WHERE slug = ?', [$slug]);
        if ($existing) {
            return;
        }
        $this->db->exec(
            'INSERT INTO pages (slug, title, body_html, updated_at) VALUES (?,?,?, NOW())',
            [$slug, $title, $html]
        );
    }

    private function parseDmy(string $value): array
    {
        $value = trim($value);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            return [
                'day' => (int) $m[1],
                'month' => (int) $m[2],
                'year' => (int) $m[3],
                'precision' => 'day',
            ];
        }
        if (preg_match('#^(\d{4})$#', $value, $m)) {
            return ['day' => null, 'month' => null, 'year' => (int) $m[1], 'precision' => 'year'];
        }
        return ['day' => null, 'month' => null, 'year' => null, 'precision' => 'unknown'];
    }

    private function guessType(string $title): ?string
    {
        if (preg_match('/\bsingle\b/i', $title)) {
            return 'single';
        }
        if (preg_match('/\bEP\b/', $title)) {
            return 'ep';
        }
        return null;
    }

    /** @return list<string> */
    private function parseList(string $value): array
    {
        if ($value === '') {
            return [];
        }
        parse_str($value, $parsed);
        $out = [];
        foreach ($parsed as $key => $item) {
            if (is_scalar($item) && (string) $item !== '' && (string) $item !== '0') {
                $out[] = (string) $item;
            }
        }
        return $out;
    }

    /** @return list<array<string,string>> */
    private function parseRepeater(string $value): array
    {
        if ($value === '') {
            return [];
        }
        parse_str($value, $parsed);
        $rows = [];
        foreach ($parsed as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }
        return $rows;
    }

    private function providerFromUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return match (true) {
            str_contains($host, 'spotify') => 'spotify',
            str_contains($host, 'apple') || str_contains($host, 'itunes') => 'apple',
            str_contains($host, 'amazon') => 'amazon',
            str_contains($host, 'youtube') || str_contains($host, 'youtu.be') => 'youtube',
            str_contains($host, 'bandcamp') => 'bandcamp',
            default => null,
        };
    }

    private function coverFromThumb(?string $attachmentId, array &$stats): ?string
    {
        if (!$attachmentId) {
            return null;
        }
        static $files = null;
        if ($files === null) {
            $path = $this->root . '/data/content.json';
            $data = json_decode((string) file_get_contents($path), true);
            $files = [];
            foreach ($data['attachments'] ?? [] as $att) {
                $files[(string) $att['id']] = $att['url'] ?? null;
            }
        }
        $url = $files[(string) $attachmentId] ?? null;
        $stored = $this->storeRemote($url, 'covers');
        if ($stored) {
            $stats['images']++;
        }
        return $stored;
    }

    private function storeRemote(?string $url, string $bucket): ?string
    {
        if (!$url || !preg_match('#^https?://(?:www\.)?bleedingstar\.at/#i', $url)) {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $name = basename($path);
        if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) {
            return null;
        }
        $rel = $bucket . '/' . $name;
        $dest = $this->root . '/storage/uploads/' . $rel;
        if (is_file($dest) && filesize($dest) > 0) {
            return $rel;
        }
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: BleedingStarImport\r\n"]]);
        $bytes = @file_get_contents($url, false, $ctx);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        file_put_contents($dest, $bytes);
        return $rel;
    }

    private function uniqueSlug(string $table, string $slug, string $legacyId): string
    {
        if (!in_array($table, ['artists', 'releases', 'news', 'events'], true)) {
            throw new \InvalidArgumentException('Unknown slug table.');
        }
        $slug = slugify($slug);
        $owned = $this->db->one("SELECT legacy_id FROM {$table} WHERE slug = ?", [$slug]);
        if (!$owned || (string) $owned['legacy_id'] === $legacyId) {
            return $slug;
        }
        return $slug . '-' . $legacyId;
    }
}
