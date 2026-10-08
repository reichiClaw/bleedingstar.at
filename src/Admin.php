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
            $method === 'GET' && $head === 'pages' && !isset($parts[1]) => $this->texts(),
            $method === 'POST' && $head === 'pages' && !isset($parts[1]) => $this->textsSave(),
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
        $missing = array_filter(['pdo_mysql', 'mbstring', 'curl', 'gd', 'json', 'fileinfo'], static fn (string $ext) => !extension_loaded($ext));
        $unwritable = array_filter(['logs', 'locks', 'cache', 'uploads', 'jobs'], static fn (string $dir) => !is_writable(app_root() . '/storage/' . $dir));
        $env = [
            'db' => (string) $this->db->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
            'extensions' => $missing ? 'fehlt: ' . implode(', ', $missing) : 'vollständig',
            'writable' => $unwritable ? 'fehlt: ' . implode(', ', $unwritable) : 'in Ordnung',
        ];
        $this->render('admin/dashboard', ['runs' => $runs, 'counts' => $counts, 'env' => $env, 'title' => 'Admin']);
    }

    private function releases(): void
    {
        $this->render('admin/releases', $this->releaseOverview($_GET) + ['title' => 'Releases']);
    }

    /**
     * All releases, grouped by the first credited artist, with the admin filters applied.
     *
     * @return array{groups:array, total:int, filters:array, artists:array, years:array}
     */
    public function releaseOverview(array $query): array
    {
        $q = trim((string) ($query['q'] ?? ''));
        $artist = (int) ($query['artist'] ?? 0);
        $status = (string) ($query['status'] ?? '');
        $type = (string) ($query['type'] ?? '');
        $year = (int) ($query['year'] ?? 0);
        if (!in_array($status, ['published', 'hidden', 'draft', 'pending'], true)) {
            $status = '';
        }
        if (!in_array($type, ['single', 'ep', 'album', 'compilation'], true)) {
            $type = '';
        }

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = "r.title LIKE ? ESCAPE '\\\\'";
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        }
        if ($artist > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM release_artists ra WHERE ra.release_id = r.id AND ra.artist_id = ?)';
            $params[] = $artist;
        }
        if ($status !== '') {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
        if ($type !== '') {
            $where[] = 'r.release_type = ?';
            $params[] = $type;
        }
        if ($year > 0) {
            $where[] = 'r.release_year = ?';
            $params[] = $year;
        }

        $rows = $this->db->all(
            'SELECT r.id, r.title, r.status, r.featured, r.source, r.release_year, r.release_type,
                    ra.position, a.id AS artist_id, a.name AS artist_name
             FROM releases r
             LEFT JOIN release_artists ra ON ra.release_id = r.id
             LEFT JOIN artists a ON a.id = ra.artist_id
             WHERE ' . ($where !== [] ? implode(' AND ', $where) : '1=1') . '
             ORDER BY r.release_year IS NULL, r.release_year DESC, r.title ASC, r.id DESC, ra.position ASC, a.id ASC',
            $params
        );

        $releases = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($releases[$id])) {
                $releases[$id] = [
                    'id' => $id,
                    'title' => $row['title'],
                    'status' => $row['status'],
                    'featured' => (int) $row['featured'],
                    'source' => $row['source'],
                    'year' => $row['release_year'] !== null ? (int) $row['release_year'] : null,
                    'type' => $row['release_type'],
                    'artists' => [],
                    'lead_id' => null,
                    'lead_name' => null,
                ];
            }
            if ($row['artist_id'] !== null) {
                $releases[$id]['artists'][] = (string) $row['artist_name'];
                if ($releases[$id]['lead_id'] === null) {
                    $releases[$id]['lead_id'] = (int) $row['artist_id'];
                    $releases[$id]['lead_name'] = (string) $row['artist_name'];
                }
            }
        }

        $groups = [];
        foreach ($releases as $release) {
            $key = $release['lead_id'] ?? 0;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'id' => $release['lead_id'],
                    'name' => $release['lead_name'] ?? 'Ohne Artist',
                    'releases' => [],
                ];
            }
            $groups[$key]['releases'][] = $release;
        }
        uasort($groups, static function (array $a, array $b): int {
            if ($a['id'] === null) {
                return 1;
            }
            if ($b['id'] === null) {
                return -1;
            }
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'groups' => array_values($groups),
            'total' => count($releases),
            'filters' => ['q' => $q, 'artist' => $artist, 'status' => $status, 'type' => $type, 'year' => $year],
            'artists' => $this->db->all(
                'SELECT DISTINCT a.id, a.name FROM artists a JOIN release_artists ra ON ra.artist_id = a.id ORDER BY a.name'
            ),
            'years' => array_column(
                $this->db->all('SELECT DISTINCT release_year FROM releases WHERE release_year IS NOT NULL ORDER BY release_year DESC'),
                'release_year'
            ),
        ];
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
        $links = $this->db->all('SELECT * FROM release_links WHERE release_id = ? ORDER BY ' . link_order_sql(), [$id]);
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
        $month = $precision === 'day' || $precision === 'month' ? (int) $this->post('release_month') : null;
        $day = $precision === 'day' ? (int) $this->post('release_day') : null;
        if ($month === 0) {
            $month = null;
        }
        if ($day === 0) {
            $day = null;
        }
        $cover = $row['cover_path'];
        $source = $row['cover_source'];
        if (!empty($_FILES['cover']['name'])) {
            $stored = $this->upload($_FILES['cover'], 'covers');
            if ($stored) {
                $cover = $stored;
                $source = 'upload';
            }
        }
        $this->db->exec(
            'UPDATE releases SET title=?, description_html=?, status=?, featured=?, release_type=?, release_year=?, release_month=?, release_day=?, release_date_precision=?, cover_path=?, cover_source=?, editorial_locked=1, updated_at=NOW() WHERE id=?',
            [
                $this->post('title'),
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
            $this->post('name'),
            Html::clean($_POST['bio_html'] ?? ''),
            $status,
            $this->website($this->post('website')),
        ];
        if (!empty($_FILES['image']['name'])) {
            $stored = $this->upload($_FILES['image'], 'artists');
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

    /** @var array<string, string> */
    private const TEXT_PAGES = [
        'label' => 'Label',
        'rental' => 'Rental',
    ];

    /**
     * @return list<array{slug: string, label: string, title: string, body_html: string}>
     */
    public function textEditor(): array
    {
        $pages = [];
        foreach (self::TEXT_PAGES as $slug => $label) {
            $row = $this->db->one('SELECT title, body_html FROM pages WHERE slug = ?', [$slug]);
            $pages[] = [
                'slug' => $slug,
                'label' => $label,
                'title' => (string) ($row['title'] ?? $label),
                'body_html' => (string) ($row['body_html'] ?? ''),
            ];
        }
        return $pages;
    }

    /** @param array<string, mixed> $input */
    public function saveTexts(array $input): void
    {
        foreach (self::TEXT_PAGES as $slug => $label) {
            $item = $input[$slug] ?? null;
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                $title = $label;
            }
            if (mb_strlen($title) > 190) {
                $title = mb_substr($title, 0, 190);
            }
            $body = Html::clean((string) ($item['body_html'] ?? ''));
            $this->db->exec(
                'INSERT INTO pages (slug, title, body_html, updated_at) VALUES (?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE title = VALUES(title), body_html = VALUES(body_html), updated_at = NOW()',
                [$slug, $title, $body]
            );
        }
    }

    private function texts(): void
    {
        $this->render('admin/pages', ['pages' => $this->textEditor(), 'title' => 'Texte']);
    }

    private function textsSave(): void
    {
        $posted = $_POST['pages'] ?? [];
        $this->saveTexts(is_array($posted) ? $posted : []);
        header('Location: /admin/pages', true, 303);
    }

    private function pageForm(string $slug): void
    {
        if (!isset(self::TEXT_PAGES[$slug])) {
            $this->missing();
            return;
        }
        header('Location: /admin/pages#' . $slug, true, 302);
    }

    private function pageSave(string $slug): void
    {
        if (!isset(self::TEXT_PAGES[$slug])) {
            $this->missing();
            return;
        }
        $this->saveTexts([
            $slug => [
                'title' => $this->post('title'),
                'body_html' => (string) ($_POST['body_html'] ?? ''),
            ],
        ]);
        header('Location: /admin/pages#' . $slug, true, 303);
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
                $this->post('name'),
                $this->post('summary'),
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
            "SELECT rv.*, r.title AS candidate_title, r.slug AS candidate_slug FROM import_reviews rv
             LEFT JOIN releases r ON r.id = rv.candidate_release_id
             WHERE rv.status = 'open' ORDER BY rv.id DESC"
        );
        foreach ($rows as &$row) {
            $payload = json_decode((string) $row['payload_json'], true) ?: [];
            $artist = $payload['artist']['name'] ?? ($payload['artists'][0]['name'] ?? '');
            $row['incoming'] = trim(($artist !== '' ? $artist . ' – ' : '') . ($payload['title'] ?? ''));
            $row['incoming_url'] = $payload['link'] ?? ($payload['uri'] ?? '');
        }
        unset($row);
        $this->render('admin/reviews', ['rows' => $rows, 'title' => 'Prüffälle']);
    }

    private function reviewAct(int $id): void
    {
        $action = $_POST['do'] ?? '';
        if ($action === 'dismiss') {
            $this->db->exec("UPDATE import_reviews SET status='dismissed' WHERE id=? AND status='open'", [$id]);
        }
        if ($action === 'merge') {
            $review = $this->db->one("SELECT provider FROM import_reviews WHERE id = ? AND status = 'open'", [$id]);
            if (($review['provider'] ?? '') === 'deezer') {
                $deezer = source_config($this->config, 'deezer');
                (new \App\Deezer\Sync(app_db(), new \App\Deezer\Client($deezer), $deezer))->mergeReview($id);
            } elseif ($review) {
                $sync = new Sync(app_db(), new \App\Discogs\Client($this->config['discogs']), $this->config['discogs']);
                $sync->mergeReview($id);
            }
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

    private function post(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private function website(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->flash('Die Website-Adresse wurde nicht übernommen (ungültige URL).');
            return null;
        }
        return mb_substr($url, 0, 255);
    }

    private function upload(array $file, string $folder): ?string
    {
        try {
            return (new Uploader(app_root()))->image($file, $folder);
        } catch (\Throwable $e) {
            $this->flash('Bild nicht übernommen: ' . $e->getMessage());
            return null;
        }
    }

    private function flash(string $message): void
    {
        $_SESSION['admin_flash'] = $message;
    }

    private function render(string $template, array $data): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $view = new View(app_root() . '/templates', [
            'baseUrl' => rtrim($this->config['base_url'], '/'),
            'adminUser' => $this->auth->user(),
        ]);
        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);
        echo $view->render($template, $data + ['admin' => true, 'flash' => $flash]);
    }
}
