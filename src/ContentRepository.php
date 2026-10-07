<?php

declare(strict_types=1);

namespace App;

final class ContentRepository
{
    public function __construct(private Database $db)
    {
    }

    public function page(string $slug): ?array
    {
        return $this->db->one('SELECT * FROM pages WHERE slug = ?', [$slug]);
    }

    public function newsList(): array
    {
        return $this->db->all("SELECT slug, title, published_on, body_html FROM news WHERE status = 'published' ORDER BY published_on DESC, id DESC");
    }

    public function news(string $slug): ?array
    {
        return $this->db->one("SELECT * FROM news WHERE slug = ? AND status = 'published'", [$slug]);
    }

    public function events(): array
    {
        return $this->db->all(
            "SELECT e.*, a.name AS artist_name, a.slug AS artist_slug
             FROM events e
             LEFT JOIN artists a ON a.id = e.artist_id
             WHERE e.status = 'published'
             ORDER BY e.event_date DESC, e.id DESC"
        );
    }

    public function rentalCategories(): array
    {
        $cats = $this->db->all('SELECT * FROM rental_categories ORDER BY sort_order, name');
        $items = $this->db->all("SELECT * FROM rental_items WHERE status = 'published' ORDER BY sort_order, name");
        $by = [];
        foreach ($items as $item) {
            $by[$item['category_id']][] = $item;
        }
        foreach ($cats as &$cat) {
            $cat['items'] = $by[$cat['id']] ?? [];
        }
        return $cats;
    }

    public function rentalItem(string $slug): ?array
    {
        $item = $this->db->one(
            "SELECT i.*, c.name AS category_name, c.slug AS category_slug
             FROM rental_items i
             JOIN rental_categories c ON c.id = i.category_id
             WHERE i.slug = ? AND i.status = 'published'",
            [$slug]
        );
        if (!$item) {
            return null;
        }
        $item['files'] = $this->db->all('SELECT id, title, path FROM rental_files WHERE item_id = ? ORDER BY id', [$item['id']]);
        return $item;
    }

    public function rentalItemById(int $id): ?array
    {
        return $this->db->one("SELECT * FROM rental_items WHERE id = ? AND status = 'published'", [$id]);
    }

    public function documents(): array
    {
        return $this->db->all(
            'SELECT d.title, d.file_url, a.name AS artist_name, a.slug AS artist_slug
             FROM documents d
             LEFT JOIN artists a ON a.id = d.artist_id
             ORDER BY d.title'
        );
    }

    public function redirect(string $path): ?string
    {
        $row = $this->db->one('SELECT target_path FROM redirects WHERE source_path = ?', [$path]);
        return $row['target_path'] ?? null;
    }
}
