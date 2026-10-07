<?php

declare(strict_types=1);

namespace App;

final class CatalogRepository
{
    public function __construct(private Database $db, private array $config)
    {
    }

    public function search(array $filters): array
    {
        $where = ["r.status = 'published'"];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(r.title LIKE :q ESCAPE '\\\\' OR EXISTS (
                SELECT 1 FROM release_artists ra
                JOIN artists a ON a.id = ra.artist_id
                WHERE ra.release_id = r.id AND a.name LIKE :qartist ESCAPE '\\\\'
            ))";
            $params['q'] = '%' . $this->like($q) . '%';
            $params['qartist'] = $params['q'];
        }
        $artist = trim((string) ($filters['artist'] ?? ''));
        if ($artist !== '') {
            $where[] = "EXISTS (
                SELECT 1 FROM release_artists ra
                JOIN artists a ON a.id = ra.artist_id
                WHERE ra.release_id = r.id AND a.slug = :artist AND a.status = 'published'
            )";
            $params['artist'] = $artist;
        }
        $year = (int) ($filters['year'] ?? 0);
        if ($year > 0) {
            $where[] = 'r.release_year = :year';
            $params['year'] = $year;
        }
        $type = trim((string) ($filters['type'] ?? ''));
        if (in_array($type, ['single', 'ep', 'album', 'compilation'], true)) {
            $where[] = 'r.release_type = :type';
            $params['type'] = $type;
        }

        $sort = ($filters['sort'] ?? 'new') === 'az' ? 'az' : 'new';
        $order = $sort === 'az'
            ? 'r.title ASC, r.id ASC'
            : 'r.release_year IS NULL, r.release_year DESC, r.release_month IS NULL, r.release_month DESC, r.release_day IS NULL, r.release_day DESC, r.title ASC';

        $sqlWhere = implode(' AND ', $where);
        $count = $this->db->one("SELECT COUNT(*) AS c FROM releases r WHERE $sqlWhere", $params);
        $total = (int) ($count['c'] ?? 0);
        $perPage = 24;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, (int) ($filters['page'] ?? 1));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all(
            "SELECT r.* FROM releases r WHERE $sqlWhere ORDER BY $order LIMIT $perPage OFFSET $offset",
            $params
        );
        $this->attachArtists($rows);

        return [
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'filters' => [
                'q' => $q,
                'artist' => $artist,
                'year' => $year ?: '',
                'type' => $type,
                'sort' => $sort,
            ],
        ];
    }

    public function latest(int $limit): array
    {
        $rows = $this->db->all(
            "SELECT * FROM releases WHERE status = 'published'
             ORDER BY featured DESC, release_year IS NULL, release_year DESC,
                      release_month IS NULL, release_month DESC,
                      release_day IS NULL, release_day DESC, id DESC
             LIMIT " . (int) $limit
        );
        $this->attachArtists($rows);
        return $rows;
    }

    public function findRelease(string $slug): ?array
    {
        $row = $this->db->one("SELECT * FROM releases WHERE slug = ? AND status = 'published'", [$slug]);
        if (!$row) {
            return null;
        }
        $rows = [$row];
        $this->attachArtists($rows);
        $row = $rows[0];
        $row['tracks'] = $this->db->all(
            'SELECT title, duration, stream_url, isrc, position FROM tracks WHERE release_id = ? ORDER BY position',
            [$row['id']]
        );
        $row['links'] = $this->db->all(
            'SELECT label, url, provider FROM release_links WHERE release_id = ? ORDER BY id',
            [$row['id']]
        );
        $row['formats'] = $this->db->all(
            'SELECT name, catalog_number, upc, details FROM release_formats WHERE release_id = ? ORDER BY id',
            [$row['id']]
        );
        $row['related'] = $this->related($row);
        $row['facts'] = $this->discogsFacts((int) $row['id']);
        return $row;
    }

    public function artistsWithReleases(): array
    {
        return $this->db->all(
            "SELECT a.id, a.slug, a.name FROM artists a
             WHERE a.status = 'published'
               AND EXISTS (
                 SELECT 1 FROM release_artists ra
                 JOIN releases r ON r.id = ra.release_id
                 WHERE ra.artist_id = a.id AND r.status = 'published'
               )
             ORDER BY a.name"
        );
    }

    public function years(): array
    {
        $rows = $this->db->all(
            "SELECT DISTINCT release_year AS y FROM releases
             WHERE status = 'published' AND release_year IS NOT NULL
             ORDER BY y DESC"
        );
        return array_map(static fn ($row) => (int) $row['y'], $rows);
    }

    public function artist(string $slug): ?array
    {
        $artist = $this->db->one("SELECT * FROM artists WHERE slug = ? AND status = 'published'", [$slug]);
        if (!$artist) {
            return null;
        }
        $artist['roles'] = array_column(
            $this->db->all('SELECT role_name FROM artist_roles WHERE artist_id = ? ORDER BY role_name', [$artist['id']]),
            'role_name'
        );
        $artist['releases'] = $this->db->all(
            "SELECT r.* FROM releases r
             JOIN release_artists ra ON ra.release_id = r.id
             WHERE ra.artist_id = ? AND r.status = 'published'
             ORDER BY r.release_year IS NULL, r.release_year DESC, r.title",
            [$artist['id']]
        );
        $this->attachArtists($artist['releases']);
        $artist['documents'] = $this->db->all(
            'SELECT title, file_url FROM documents WHERE artist_id = ? ORDER BY title',
            [$artist['id']]
        );
        return $artist;
    }

    public function artists(): array
    {
        $rows = $this->db->all("SELECT * FROM artists WHERE status = 'published' ORDER BY name");
        if (!$rows) {
            return [];
        }
        $ids = array_map(static fn ($row) => (int) $row['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $roles = $this->db->all("SELECT artist_id, role_name FROM artist_roles WHERE artist_id IN ($in)", $ids);
        $by = [];
        foreach ($roles as $role) {
            $by[$role['artist_id']][] = $role['role_name'];
        }
        foreach ($rows as &$row) {
            $row['roles'] = $by[$row['id']] ?? [];
        }
        return $rows;
    }

    public function cover(array $release, string $size = 'full'): ?array
    {
        $path = (string) ($release['cover_path'] ?? '');
        if ($size === 'grid' && !empty($release['cover_grid_path'])) {
            $path = (string) $release['cover_grid_path'];
        }
        if ($path === '') {
            return null;
        }
        $source = (string) ($release['cover_source'] ?? '');
        return [
            'url' => '/media/' . ltrim($path, '/'),
            'source' => $source,
            'attribution' => $source === 'discogs' ? ($release['cover_attribution'] ?: 'Discogs') : null,
            'page' => $source === 'discogs' ? ($release['cover_page_url'] ?: null) : null,
        ];
    }

    public function discogsCoverFresh(array $release): bool
    {
        if (empty($release['cover_fetched_at'])) {
            return false;
        }
        $fetched = strtotime((string) $release['cover_fetched_at']);
        $hours = (int) ($this->config['discogs']['max_age_hours'] ?? 4);
        return $fetched !== false && $fetched >= time() - ($hours * 3600);
    }

    private function discogsFacts(int $releaseId): array
    {
        $rows = $this->db->all(
            "SELECT p.payload_json FROM provider_records p
             JOIN external_ids e ON e.provider = p.provider AND e.entity_type = p.entity_type AND e.external_id = p.external_id
             WHERE e.provider = 'discogs' AND e.entity_type = 'release' AND e.entity_id = ?",
            [$releaseId]
        );
        $genres = [];
        $styles = [];
        $credits = [];
        $country = '';
        foreach ($rows as $row) {
            $detail = json_decode((string) $row['payload_json'], true);
            if (!is_array($detail)) {
                continue;
            }
            foreach ($detail['genres'] ?? [] as $genre) {
                $genres[(string) $genre] = (string) $genre;
            }
            foreach ($detail['styles'] ?? [] as $style) {
                $styles[(string) $style] = (string) $style;
            }
            if ($country === '' && !empty($detail['country'])) {
                $country = (string) $detail['country'];
            }
            foreach ($detail['extraartists'] ?? [] as $artist) {
                $name = trim((string) ($artist['name'] ?? ''));
                $role = trim((string) ($artist['role'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $credits[$role . '|' . $name] = ['role' => $role, 'name' => $name];
            }
        }
        return [
            'genres' => array_values($genres),
            'styles' => array_values($styles),
            'country' => $country,
            'credits' => array_values($credits),
        ];
    }

    private function related(array $release): array
    {
        $ids = array_map(static fn ($a) => (int) $a['id'], $release['artists'] ?? []);
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [(int) $release['id']]);
        $rows = $this->db->all(
            "SELECT DISTINCT r.* FROM releases r
             JOIN release_artists ra ON ra.release_id = r.id
             WHERE ra.artist_id IN ($in) AND r.id <> ? AND r.status = 'published'
             ORDER BY r.release_year IS NULL, r.release_year DESC
             LIMIT 8",
            $params
        );
        $this->attachArtists($rows);
        return $rows;
    }

    private function attachArtists(array &$rows): void
    {
        if (!$rows) {
            return;
        }
        $ids = array_map(static fn ($row) => (int) $row['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $links = $this->db->all(
            "SELECT ra.release_id, a.id, a.slug, a.name, a.status
             FROM release_artists ra
             JOIN artists a ON a.id = ra.artist_id
             WHERE ra.release_id IN ($in)
             ORDER BY ra.position, a.name",
            $ids
        );
        $by = [];
        foreach ($links as $link) {
            $by[$link['release_id']][] = $link;
        }
        foreach ($rows as &$row) {
            $row['artists'] = $by[$row['id']] ?? [];
        }
    }

    private function like(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
