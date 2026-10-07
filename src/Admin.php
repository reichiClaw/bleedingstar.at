<?php

declare(strict_types=1);

namespace App;

use App\Discogs\Sync;

final class Admin
{
    public function __construct(private array $config, private Database $db, private Auth $auth)
    {
    }

    public function dispatch(string $method, array $parts): void
    {
        $head = $parts[0] ?? '';
        if ($head === 'login') {
            $method === 'POST' ? $this->loginPost() : $this->loginForm();
            return;
        }
        if ($method === 'POST' && $head === 'logout') {
            $this->auth->logout();
            header('Location: /admin/login', true, 303);
            return;
        }
        if (!$this->auth->user()) {
            header('Location: /admin/login', true, 303);
            return;
        }
        if ($method === 'POST' && !Csrf::check($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            echo 'CSRF-Prüfung fehlgeschlagen.';
            return;
        }
        match (true) {
            $method === 'GET' && $head === '' => $this->dashboard(),
            $method === 'GET' && $head === 'releases' && !isset($parts[1]) => $this->releases(),
            $method === 'POST' && $head === 'releases' && ($parts[1] ?? '') === 'neu' => $this->releaseCreate(),
            $method === 'GET' && $head === 'releases' && isset($parts[1]) => $this->releaseForm((int) $parts[1]),
            $method === 'POST' && $head === 'releases' && isset($parts[1]) => $this->releaseSave((int) $parts[1]),
            $method === 'GET' && $head === 'artists' && !isset($parts[1]) => $this->artists(),
            $method === 'GET' && $head === 'artists' && isset($parts[1]) => $this->artistForm((int) $parts[1]),
            $method === 'POST' && $head === 'artists' && isset($parts[1]) => $this->artistSave((int) $parts[1]),
            $method === 'GET' && $head === 'pages' && isset($parts[1]) => $this->pageForm($parts[1]),
            $method === 'POST' && $head === 'pages' && isset($parts[1]) => $this->pageSave($parts[1]),
            $method === 'GET' && $head === 'rental' && !isset($parts[1]) => $this->rental(),
            $method === 'GET' && $head === 'rental' && isset($parts[1]) => $this->rentalForm((int) $parts[1]),
            $method === 'POST' && $head === 'rental' && isset($parts[1]) => $this->rentalSave((int) $parts[1]),
            $method === 'GET' && $head === 'reviews' => $this->reviews(),
            $method === 'POST' && $head === 'reviews' && isset($parts[1]) => $this->reviewAct((int) $parts[1]),
            $method === 'GET' && $head === 'sync' => $this->sync(),
            $method === 'POST' && $head === 'sync' => $this->syncRequest(),
            default => $this->missing(),
        };
    }

    private function loginForm(?string $error = null): void
    {
        $this->render('admin/login', ['error' => $error, 'title' => 'Admin']);
    }

    private function loginPost(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->loginForm('Ungültiges Formular.');
            return;
        }
        $ok = $this->auth->attempt(trim((string) ($_POST['email'] ?? '')), (string) ($_POST['password'] ?? ''), client_ip());
        if (!$ok) {
            $this->loginForm('Anmeldung fehlgeschlagen.');
            return;
        }
        header('Location: /admin', true, 303);
    }

    private function dashboard(): void
    {
        $runs = $this->db->all('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 8');
        $counts = [
            'releases' => $this->db->one("SELECT COUNT(*) AS c FROM releases WHERE status='published'")['c'] ?? 0,
            'artists' => $this->db->one("SELECT COUNT(*) AS c FROM artists WHERE status='published'")['c'] ?? 0,
            'reviews' => $this->db->one("SELECT COUNT(*) AS c FROM import_reviews WHERE status='open'")['c'] ?? 0,
        ];
        $this->render('admin/dashboard', ['runs' => $runs, 'counts' => $counts, 'title' => 'Admin']);
    }

    private function releases(): void
    {
        $rows = $this->db->all('SELECT id, title, status, featured, source, release_year, slug FROM releases ORDER BY id DESC LIMIT 200');
        $this->render('admin/releases', ['rows' => $rows, 'title' => 'Releases']);
    }

    private function releaseForm(int $id): void
    {
        $row = $this->db->one('SELECT * FROM releases WHERE id = ?', [$id]);
        if (!$row) {
            $this->missing();
            return;
        }
        $artists = $this->db->all('SELECT id, name FROM artists ORDER BY name');
        $linked = array_column($this->db->all('SELECT artist_id FROM release_artists WHERE release_id = ?', [$id]), 'artist_id');
        $links = $this->db->all('SELECT * FROM release_links WHERE release_id = ?', [$id]);
        $this->render('admin/release', compact('row', 'artists', 'linked', 'links') + ['title' => $row['title']]);
    }

    private function releaseSave(int $id): void
    {
        $row = $this->db->one('SELECT * FROM releases WHERE id = ?', [$id]);
        if (!$row) {
            $this->missing();
            return;
        }
        $status = $_POST['status'] ?? 'draft';
        if (!in_array($status, ['published', 'hidden', 'draft', 'pending'], true)) {
            $status = 'draft';
        }
        $precision = $_POST['precision'] ?? 'unknown';
        if (!in_array($precision, ['day', 'month', 'year', 'unknown'], true)) {
            $precision = 'unknown';
        }
        $type = $_POST['release_type'] ?? '';
        $type = in_array($type, ['single', 'ep', 'album', 'compilation'], true) ? $type : null;
        $yearRaw = trim((string) ($_POST['release_year'] ?? ''));
        $year = $yearRaw !== '' ? (int) $yearRaw : null;
        $month = $precision === 'day' || $precision === 'month' ? (int) ($_POST['release_month'] ?: 0) : null;
        $day = $precision === 'day' ? (int) ($_POST['release_day'] ?: 0) : null;
        if ($month === 0) {
            $month = null;
        }
        if ($day === 0) {
            $day = null;
        }
        $cover = $row['cover_path'];
        $source = $row['cover_source'];
        if (!empty($_FILES['cover']['name'])) {
            $stored = (new Uploader(app_root()))->image($_FILES['cover'], 'covers');
            if ($stored) {
                $cover = $stored;
                $source = 'upload';
            }
        }
        $this->db->exec(
            'UPDATE releases SET title=?, description_html=?, status=?, featured=?, release_type=?, release_year=?, release_month=?, release_day=?, release_date_precision=?, cover_path=?, cover_source=?, editorial_locked=1, updated_at=NOW() WHERE id=?',
            [
                trim((string) $_POST['title']),
                Html::clean($_POST['description_html'] ?? ''),
                $status,
                isset($_POST['featured']) ? 1 : 0,
                $type,
                $year,
                $month,
                $day,
                $precision,
                $cover,
                $source,
                $id,
            ]
        );
        $this->db->exec('DELETE FROM release_artists WHERE release_id = ?', [$id]);
        $pos = 0;
        foreach ((array) ($_POST['artist_ids'] ?? []) as $aid) {
            $this->db->exec('INSERT IGNORE INTO release_artists (release_id, artist_id, position) VALUES (?,?,?)', [$id, (int) $aid, $pos++]);
        }
        $label = trim((string) ($_POST['link_label'] ?? ''));
        $url = trim((string) ($_POST['link_url'] ?? ''));
        if ($label !== '' && preg_match('#^https?://#', $url)) {
            $provider = str_contains($url, 'spotify') ? 'spotify' : null;
            $this->db->exec(
                'INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?,?,?,?,1)',
                [$id, $label, $url, $provider]
            );
        }
        header('Location: /admin/releases/' . $id, true, 303);
    }

    private function releaseCreate(): void
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            header('Location: /admin/releases', true, 303);
            return;
        }
        $slug = slugify($title);
        $i = 2;
        $try = $slug;
        while ($this->db->one('SELECT id FROM releases WHERE slug = ?', [$try])) {
            $try = $slug . '-' . $i++;
        }
        $id = $this->db->insert(
            'INSERT INTO releases (slug, title, status, source, editorial_locked, release_date_precision, created_at, updated_at)
             VALUES (?,?,"draft","editorial",1,"unknown",NOW(),NOW())',
            [$try, $title]
        );
        header('Location: /admin/releases/' . $id, true, 303);
    }

    private function artists(): void
    {
        $rows = $this->db->all('SELECT id, name, status, slug FROM artists ORDER BY name');
        $this->render('admin/artists', ['rows' => $rows, 'title' => 'Artists']);
    }

    private function artistForm(int $id): void
    {
        $row = $this->db->one('SELECT * FROM artists WHERE id = ?', [$id]);
        if (!$row) {
            $this->missing();
            return;
        }
        $this->render('admin/artist', ['row' => $row, 'title' => $row['name']]);
    }

    private function artistSave(int $id): void
    {
        $status = $_POST['status'] ?? 'draft';
        if (!in_array($status, ['published', 'draft', 'hidden'], true)) {
            $status = 'draft';
        }
        $imageSql = '';
        $params = [
            trim((string) $_POST['name']),
            Html::clean($_POST['bio_html'] ?? ''),
            $status,
            trim((string) ($_POST['website'] ?? '')) ?: null,
        ];
        if (!empty($_FILES['image']['name'])) {
            $stored = (new Uploader(app_root()))->image($_FILES['image'], 'artists');
            if ($stored) {
                $imageSql = ', image_path=?, image_source="upload"';
                $params[] = $stored;
            }
        }
        $params[] = $id;
        $this->db->exec(
            "UPDATE artists SET name=?, bio_html=?, status=?, website=?, editorial_locked=1, updated_at=NOW() $imageSql WHERE id=?",
            $params
        );
        header('Location: /admin/artists/' . $id, true, 303);
    }

    private function pageForm(string $slug): void
    {
        if (!in_array($slug, ['label', 'production', 'radio'], true)) {
            $this->missing();
            return;
        }
        $row = $this->db->one('SELECT * FROM pages WHERE slug = ?', [$slug]);
        $this->render('admin/page', ['row' => $row, 'slug' => $slug, 'title' => $slug]);
    }

    private function pageSave(string $slug): void
    {
        if (!in_array($slug, ['label', 'production', 'radio'], true)) {
            $this->missing();
            return;
        }
        $this->db->exec(
            'UPDATE pages SET title=?, body_html=?, updated_at=NOW() WHERE slug=?',
            [trim((string) $_POST['title']), Html::clean($_POST['body_html'] ?? ''), $slug]
        );
        header('Location: /admin/pages/' . $slug, true, 303);
    }

    private function rental(): void
    {
        $rows = $this->db->all('SELECT i.*, c.name AS category_name FROM rental_items i JOIN rental_categories c ON c.id = i.category_id ORDER BY i.sort_order');
        $this->render('admin/rental', ['rows' => $rows, 'title' => 'Rental']);
    }

    private function rentalForm(int $id): void
    {
        $row = $this->db->one('SELECT * FROM rental_items WHERE id = ?', [$id]);
        if (!$row) {
            $this->missing();
            return;
        }
        $this->render('admin/rental-item', ['row' => $row, 'title' => $row['name']]);
    }

    private function rentalSave(int $id): void
    {
        $status = ($_POST['status'] ?? '') === 'published' ? 'published' : 'hidden';
        $public = isset($_POST['price_public']) ? 1 : 0;
        $cents = trim((string) ($_POST['price_euro'] ?? ''));
        $price = $cents === '' ? null : (int) round(((float) str_replace(',', '.', $cents)) * 100);
        $this->db->exec(
            'UPDATE rental_items SET name=?, summary=?, description_html=?, specs_html=?, status=?, price_cents=?, price_public=? WHERE id=?',
            [
                trim((string) $_POST['name']),
                trim((string) $_POST['summary']),
                Html::clean($_POST['description_html'] ?? ''),
                Html::clean($_POST['specs_html'] ?? ''),
                $status,
                $price,
                $public,
                $id,
            ]
        );
        header('Location: /admin/rental/' . $id, true, 303);
    }

    private function reviews(): void
    {
        $rows = $this->db->all(
            "SELECT rv.*, r.title AS candidate_title FROM import_reviews rv
             LEFT JOIN releases r ON r.id = rv.candidate_release_id
             WHERE rv.status = 'open' ORDER BY rv.id DESC"
        );
        $this->render('admin/reviews', ['rows' => $rows, 'title' => 'Prüffälle']);
    }

    private function reviewAct(int $id): void
    {
        $action = $_POST['do'] ?? '';
        if ($action === 'dismiss') {
            $this->db->exec("UPDATE import_reviews SET status='dismissed' WHERE id=? AND status='open'", [$id]);
        }
        if ($action === 'merge') {
            $sync = new Sync(app_db(), new \App\Discogs\Client($this->config['discogs']), $this->config['discogs']);
            $sync->mergeReview($id);
        }
        header('Location: /admin/reviews', true, 303);
    }

    private function sync(): void
    {
        $runs = $this->db->all('SELECT * FROM sync_runs ORDER BY id DESC LIMIT 20');
        $pending = is_file(app_root() . '/storage/jobs/sync.request');
        $this->render('admin/sync', ['runs' => $runs, 'pending' => $pending, 'title' => 'Sync']);
    }

    private function syncRequest(): void
    {
        $dir = app_root() . '/storage/jobs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/sync.request', date('c') . "\n");
        header('Location: /admin/sync', true, 303);
    }

    private function missing(): void
    {
        http_response_code(404);
        echo 'Nicht gefunden.';
    }

    private function render(string $template, array $data): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $view = new View(app_root() . '/templates', [
            'baseUrl' => rtrim($this->config['base_url'], '/'),
            'adminUser' => $this->auth->user(),
        ]);
        echo $view->render($template, $data + ['admin' => true]);
    }
}
