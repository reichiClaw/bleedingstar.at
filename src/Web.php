<?php

declare(strict_types=1);

namespace App;

final class Web
{
    public function __construct(
        private array $config,
        private CatalogRepository $catalog,
        private ContentRepository $content,
        private Mailer $mailer,
    ) {
    }

    public function dispatch(string $method, string $path): void
    {
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        if ($path === '/setup') {
            $this->setup($method);
            return;
        }
        if ($path === '/jobs/run') {
            $this->runJob();
            return;
        }
        if ($path !== '/' && ($target = $this->content->redirect($path))) {
            header('Location: ' . $target, true, 301);
            return;
        }

        $parts = $path === '/' ? [] : explode('/', trim($path, '/'));
        $head = $parts[0] ?? '';

        if ($head === 'admin') {
            (new Admin($this->config, app_db(), new Auth(app_db())))->dispatch($method, array_slice($parts, 1));
            return;
        }

        match (true) {
            $method === 'GET' && $path === '/' => $this->home(),
            $method === 'GET' && $path === '/label' => $this->page('label', 'Label', 'label'),
            $method === 'GET' && $path === '/releases' => $this->releases(),
            $method === 'GET' && $head === 'releases' && isset($parts[1]) => $this->release($parts[1]),
            $method === 'GET' && $path === '/artists' => $this->artists(),
            $method === 'GET' && $head === 'artists' && isset($parts[1]) => $this->artist($parts[1]),
            $method === 'GET' && $path === '/production' => $this->page('production', 'Production', 'production'),
            $method === 'GET' && $path === '/rental' => $this->rental(),
            $method === 'GET' && $head === 'rental' && isset($parts[1]) => $this->rentalItem($parts[1]),
            $method === 'POST' && $path === '/rental/cart' => $this->rentalCart(),
            $method === 'GET' && $path === '/kontakt' => $this->contact(),
            $method === 'POST' && $path === '/kontakt' => $this->contactSubmit(),
            $method === 'GET' && $path === '/impressum' => $this->impressum(),
            $method === 'GET' && $path === '/datenschutz' => $this->privacy(),
            $method === 'GET' && $path === '/sitemap.xml' => $this->sitemap(),
            $method === 'GET' && $path === '/robots.txt' => $this->robots(),
            $method === 'GET' && $head === 'media' => $this->media(implode('/', array_slice($parts, 1))),
            default => $this->notFound(),
        };
    }

    private function home(): void
    {
        $featured = $this->catalog->featured();
        $latest = $this->catalog->latest(9);
        if ($featured) {
            $latest = array_values(array_filter($latest, static fn (array $r): bool => (int) $r['id'] !== (int) $featured['id']));
        }
        $description = 'BleedingStar Music Services: Label, Production und Rental.';
        if ($featured) {
            $names = implode(', ', array_column($featured['artists'], 'name'));
            $description = 'Neu: ' . $featured['title'] . ($names !== '' ? ' von ' . $names : '') . '. ' . $description;
        }
        $this->render('home', [
            'title' => 'BleedingStar',
            'description' => $description,
            'current' => '',
            'featured' => $featured,
            'releases' => array_slice($latest, 0, 8),
            'total' => $this->catalog->count(),
            'artists' => $this->catalog->artistsWithReleases(),
            'label' => $this->content->page('label'),
            'production' => $this->content->page('production'),
            'rental' => $this->content->page('rental'),
            'ogImage' => $featured ? ($this->catalog->cover($featured)['url'] ?? null) : null,
        ]);
    }

    private function releases(): void
    {
        $result = $this->catalog->search($_GET);
        $data = [
            'title' => 'Releases',
            'description' => 'Veröffentlichungen von BleedingStar.',
            'current' => 'releases',
            'result' => $result,
            'artists' => $this->catalog->artistsWithReleases(),
            'years' => $this->catalog->years(),
            'catalog' => $this->catalog,
        ];
        if (isset($_GET['fragment'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo $this->view()->partial('partials/release-results', $data);
            return;
        }
        $this->render('releases', $data);
    }

    private function release(string $slug): void
    {
        $release = $this->catalog->findRelease($slug);
        if (!$release) {
            $this->notFound();
            return;
        }
        $this->render('release', [
            'title' => $release['title'],
            'description' => $release['title'] . ' auf BleedingStar.',
            'current' => 'releases',
            'release' => $release,
            'catalog' => $this->catalog,
            'ogImage' => ($this->catalog->cover($release)['url'] ?? null),
        ]);
    }

    private function artists(): void
    {
        $this->render('artists', [
            'title' => 'Artists',
            'description' => 'Künstler bei BleedingStar.',
            'current' => 'artists',
            'artists' => $this->catalog->artists(),
            'catalog' => $this->catalog,
        ]);
    }

    private function artist(string $slug): void
    {
        $artist = $this->catalog->artist($slug);
        if (!$artist) {
            $this->notFound();
            return;
        }
        $this->render('artist', [
            'title' => $artist['name'],
            'description' => $artist['name'] . ' bei BleedingStar.',
            'current' => 'artists',
            'artist' => $artist,
            'catalog' => $this->catalog,
        ]);
    }

    private function page(string $slug, string $fallback, string $current): void
    {
        $page = $this->content->page($slug);
        $this->render('page', [
            'title' => $page['title'] ?? $fallback,
            'description' => ($page['title'] ?? $fallback) . ' – BleedingStar.',
            'current' => $current,
            'page' => $page,
            'cta' => $slug === 'production',
        ]);
    }

    private function rental(): void
    {
        $page = $this->content->page('rental');
        $this->render('rental', [
            'title' => 'Rental',
            'description' => 'Rental von BleedingStar.',
            'current' => 'rental',
            'page' => $page,
        ]);
    }

    private function rentalItem(string $slug): void
    {
        $item = $this->content->rentalItem($slug);
        if (!$item) {
            $this->notFound();
            return;
        }
        $this->render('rental-item', [
            'title' => $item['name'],
            'description' => $item['summary'],
            'current' => 'rental',
            'item' => $item,
        ]);
    }

    private function rentalCart(): void
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->notFound();
            return;
        }
        $id = (int) ($_POST['item_id'] ?? 0);
        $qty = max(1, min(20, (int) ($_POST['qty'] ?? 1)));
        $action = $_POST['action'] ?? 'add';
        $cart = $_SESSION['rental_cart'] ?? [];
        if ($action === 'remove') {
            unset($cart[$id]);
        } elseif ($id > 0 && $this->content->rentalItemById($id)) {
            $cart[$id] = ['id' => $id, 'qty' => $qty];
        }
        $_SESSION['rental_cart'] = $cart;
        $back = $_POST['back'] ?? '/rental';
        if (!is_string($back) || !str_starts_with($back, '/')) {
            $back = '/rental';
        }
        header('Location: ' . $back, true, 303);
    }

    private function contact(): void
    {
        $this->render('contact', [
            'title' => 'Kontakt',
            'description' => 'Anfrage an BleedingStar.',
            'current' => 'kontakt',
            'sent' => isset($_GET['gesendet']),
            'errors' => [],
            'old' => ['topic' => is_string($_GET['thema'] ?? null) ? $_GET['thema'] : 'allgemein', 'name' => '', 'email' => '', 'message' => ''],
            'cart' => $this->cartLines(),
            'identity' => $this->config['identity'],
        ]);
    }

    private function contactSubmit(): void
    {
        $errors = [];
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $errors[] = 'Das Formular ist abgelaufen. Bitte erneut senden.';
        }
        if (trim((string) ($_POST['company_website'] ?? '')) !== '') {
            $errors[] = 'Die Anfrage konnte nicht gesendet werden.';
        }
        $started = (int) ($_POST['started'] ?? 0);
        if ($started > 0 && (time() - $started) < 3) {
            $errors[] = 'Bitte kurz warten und dann senden.';
        }
        $topic = $_POST['topic'] ?? '';
        if (!in_array($topic, ['label', 'production', 'rental', 'allgemein'], true)) {
            $errors[] = 'Bitte ein Anliegen wählen.';
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190) {
            $errors[] = 'Bitte einen Namen angeben.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if (mb_strlen($message) < 10) {
            $errors[] = 'Die Nachricht ist zu kurz.';
        }
        $ip = client_ip();
        $recent = app_db()->one(
            'SELECT COUNT(*) AS c FROM inquiries WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)',
            [$ip]
        );
        if ((int) ($recent['c'] ?? 0) >= 5) {
            $errors[] = 'Zu viele Anfragen. Bitte später noch einmal versuchen.';
        }
        $old = compact('topic', 'name', 'email', 'message');
        if ($errors) {
            $this->render('contact', [
                'title' => 'Kontakt',
                'description' => 'Anfrage an BleedingStar.',
                'current' => 'kontakt',
                'sent' => false,
                'errors' => $errors,
                'old' => $old,
                'cart' => $this->cartLines(),
                'identity' => $this->config['identity'],
            ]);
            return;
        }
        $from = trim((string) ($_POST['date_from'] ?? ''));
        $to = trim((string) ($_POST['date_to'] ?? ''));
        $lines = $topic === 'rental' ? $this->cartLines() : [];
        $payload = $lines ? json_encode(['from' => $from, 'to' => $to, 'items' => $lines], JSON_UNESCAPED_UNICODE) : null;
        $body = "Anliegen: $topic\nName: $name\nE-Mail: $email\n";
        if ($from !== '' || $to !== '') {
            $body .= "Zeitraum: $from – $to\n";
        }
        foreach ($lines as $line) {
            $body .= '- ' . $line['name'] . ' × ' . $line['qty'] . "\n";
        }
        $body .= "\n$message\n";
        $sent = $this->mailer->send(
            $this->config['mail_to'],
            'BleedingStar Anfrage: ' . $topic,
            $body
        );
        app_db()->exec(
            'INSERT INTO inquiries (topic, name, email, message, rental_payload, ip, created_at, mail_status) VALUES (?,?,?,?,?,?,NOW(),?)',
            [$topic, $name, $email, $message, $payload, $ip, $sent ? 'sent' : 'stored']
        );
        if ($topic === 'rental') {
            $_SESSION['rental_cart'] = [];
        }
        header('Location: /kontakt?gesendet=1', true, 303);
    }

    private function impressum(): void
    {
        $this->render('impressum', [
            'title' => 'Impressum',
            'description' => 'Impressum von BleedingStar.',
            'current' => '',
            'identity' => $this->config['identity'],
        ]);
    }

    private function privacy(): void
    {
        $this->render('datenschutz', [
            'title' => 'Datenschutz',
            'description' => 'Datenschutzhinweise von BleedingStar.',
            'current' => '',
            'identity' => $this->config['identity'],
        ]);
    }

    private function sitemap(): void
    {
        $base = rtrim($this->config['base_url'], '/');
        $urls = ['/', '/label', '/releases', '/artists', '/production', '/rental', '/kontakt', '/impressum', '/datenschutz'];
        foreach (app_db()->all("SELECT slug FROM releases WHERE status = 'published'") as $row) {
            $urls[] = '/releases/' . $row['slug'];
        }
        foreach (app_db()->all("SELECT slug FROM artists WHERE status = 'published'") as $row) {
            $urls[] = '/artists/' . $row['slug'];
        }
        foreach (app_db()->all("SELECT slug FROM rental_items WHERE status = 'published'") as $row) {
            $urls[] = '/rental/' . $row['slug'];
        }
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            echo '<url><loc>' . e($base . $url) . '</loc></url>';
        }
        echo '</urlset>';
    }

    private function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nAllow: /\nDisallow: /admin\nSitemap: " . rtrim($this->config['base_url'], '/') . "/sitemap.xml\n";
    }

    private function media(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, '..')) {
            $this->notFound();
            return;
        }
        $root = realpath(app_root() . '/storage/uploads');
        $full = realpath(app_root() . '/storage/uploads/' . $path);
        if (!$root || !$full || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
            $this->notFound();
            return;
        }
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        ];
        if (!isset($types[$ext])) {
            $this->notFound();
            return;
        }
        header('Content-Type: ' . $types[$ext]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        readfile($full);
    }

    private function cartLines(): array
    {
        $cart = $_SESSION['rental_cart'] ?? [];
        $lines = [];
        foreach ($cart as $row) {
            $item = $this->content->rentalItemById((int) $row['id']);
            if ($item) {
                $lines[] = ['id' => (int) $item['id'], 'name' => $item['name'], 'qty' => (int) $row['qty'], 'slug' => $item['slug']];
            }
        }
        return $lines;
    }

    /** One-shot installer for hosting without shell access; disabled once storage/install.done exists. */
    private function setup(string $method): void
    {
        $installer = new Installer(app_db(), app_root());
        $token = $method === 'POST' ? ($_POST['token'] ?? null) : ($_GET['token'] ?? null);
        if (!$installer->webAllowed($this->config, is_string($token) ? $token : null)) {
            $this->notFound();
            return;
        }
        header('X-Robots-Tag: noindex, nofollow');
        $data = ['title' => 'Einrichtung', 'description' => '', 'current' => '', 'token' => $token, 'errors' => [], 'done' => false, 'stats' => null];
        if ($method !== 'POST') {
            $this->render('setup', $data);
            return;
        }
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        try {
            $installer->schema();
            $stats = isset($_POST['import']) ? $installer->import($this->config['legacy_export']) : null;
            $installer->admin($email, $password);
            $installer->finish();
            $data['done'] = true;
            $data['stats'] = $stats;
        } catch (\Throwable $e) {
            error_log('setup: ' . $e->getMessage());
            $data['errors'][] = $e instanceof \RuntimeException ? $e->getMessage() : 'Die Einrichtung ist fehlgeschlagen. Details stehen im Fehlerprotokoll.';
        }
        $this->render('setup', $data);
    }

    /**
     * Web entry for the catalogue sync, for hosting whose cron can only call URLs.
     * Needs config['cron_token'] (32+ characters) and ?token=…; ?source= runs one
     * source so each request stays within the execution time limit. The sources
     * also stop on their own before JobRunner::deadline() and resume next time.
     */
    private function runJob(): void
    {
        $expected = (string) ($this->config['cron_token'] ?? '');
        $token = $_POST['token'] ?? $_GET['token'] ?? null;
        if (strlen($expected) < 32 || !is_string($token) || !hash_equals($expected, $token)) {
            $this->notFound();
            return;
        }
        $source = $_POST['source'] ?? $_GET['source'] ?? null;
        if (!in_array($source, JobRunner::SOURCES, true)) {
            $source = null;
        }
        $dry = isset($_GET['dry']) || isset($_POST['dry']);
        $task = $_POST['task'] ?? $_GET['task'] ?? 'sync-releases';
        if (!in_array($task, ['sync-releases', 'remove-legacy-content'], true)) {
            $this->notFound();
            return;
        }
        ignore_user_abort(true);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        $runner = new JobRunner(app_db(), app_root());
        $result = $task === 'remove-legacy-content'
            ? $runner->run($task, $dry, fn () => (new Installer(app_db(), app_root()))->removeLegacyContent($dry))
            : $runner->run($task, $dry, fn () => $runner->syncReleases($this->config, $dry, $source));
        http_response_code(match ($result['status']) {
            'ok', 'quota' => 200,
            'locked' => 409,
            default => 500,
        });
        echo $result['line'];
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('404', [
            'title' => 'Nicht gefunden',
            'description' => 'Seite nicht gefunden.',
            'current' => '',
        ]);
    }

    private function render(string $template, array $data): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->view()->render($template, $data);
    }

    private function view(): View
    {
        static $view;
        if (!$view) {
            $view = new View(app_root() . '/templates', [
                'baseUrl' => rtrim($this->config['base_url'], '/'),
                'identity' => $this->config['identity'],
                'catalog' => $this->catalog,
            ]);
        }
        return $view;
    }
}
