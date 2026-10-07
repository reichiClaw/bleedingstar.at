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
            $method === 'GET' && $path === '/news' => $this->news(),
            $method === 'GET' && $head === 'news' && isset($parts[1]) => $this->newsItem($parts[1]),
            $method === 'GET' && $path === '/events' => $this->events(),
            $method === 'GET' && $path === '/radio' => $this->page('radio', 'Radio', ''),
            $method === 'GET' && $path === '/downloads' => $this->downloads(),
            $method === 'GET' && $path === '/sitemap.xml' => $this->sitemap(),
            $method === 'GET' && $path === '/robots.txt' => $this->robots(),
            $method === 'GET' && $head === 'media' => $this->media(implode('/', array_slice($parts, 1))),
            default => $this->notFound(),
        };
    }

    private function home(): void
    {
        $this->render('home', [
            'title' => 'BleedingStar',
            'description' => 'BleedingStar Music Services: Label, Production und Rental.',
            'current' => '',
            'releases' => $this->catalog->latest(8),
            'label' => $this->content->page('label'),
            'production' => $this->content->page('production'),
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
        $this->render('rental', [
            'title' => 'Rental',
            'description' => 'Mietequipment von BleedingStar.',
            'current' => 'rental',
            'categories' => $this->content->rentalCategories(),
            'cart' => $_SESSION['rental_cart'] ?? [],
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
            'old' => ['topic' => $_GET['thema'] ?? 'allgemein', 'name' => '', 'email' => '', 'message' => ''],
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

    private function news(): void
    {
        $this->render('news', [
            'title' => 'News',
            'description' => 'Nachrichtenarchiv von BleedingStar.',
            'current' => '',
            'items' => $this->content->newsList(),
        ]);
    }

    private function newsItem(string $slug): void
    {
        $item = $this->content->news($slug);
        if (!$item) {
            $this->notFound();
            return;
        }
        $this->render('news-item', [
            'title' => $item['title'],
            'description' => $item['title'],
            'current' => '',
            'item' => $item,
        ]);
    }

    private function events(): void
    {
        $this->render('events', [
            'title' => 'Events',
            'description' => 'Vergangene Termine aus dem BleedingStar-Archiv.',
            'current' => '',
            'events' => $this->content->events(),
        ]);
    }

    private function downloads(): void
    {
        $this->render('downloads', [
            'title' => 'Downloads',
            'description' => 'Pressetexte und Rider aus dem Archiv.',
            'current' => '',
            'documents' => $this->content->documents(),
        ]);
    }

    private function sitemap(): void
    {
        $base = rtrim($this->config['base_url'], '/');
        $urls = ['/', '/label', '/releases', '/artists', '/production', '/rental', '/kontakt', '/impressum', '/datenschutz', '/news', '/events', '/downloads'];
        foreach (app_db()->all("SELECT slug FROM releases WHERE status = 'published'") as $row) {
            $urls[] = '/releases/' . $row['slug'];
        }
        foreach (app_db()->all("SELECT slug FROM artists WHERE status = 'published'") as $row) {
            $urls[] = '/artists/' . $row['slug'];
        }
        foreach (app_db()->all("SELECT slug FROM rental_items WHERE status = 'published'") as $row) {
            $urls[] = '/rental/' . $row['slug'];
        }
        foreach (app_db()->all("SELECT slug FROM news WHERE status = 'published'") as $row) {
            $urls[] = '/news/' . $row['slug'];
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
