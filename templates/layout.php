<?php
/** @var string $content */
/** @var string $title */
/** @var string $baseUrl */
$current = $current ?? '';
$description = $description ?? '';
$qs = $_GET;
unset($qs['fragment']);
$path = request_path();
$canonical = $baseUrl . $path . ($qs ? ('?' . http_build_query($qs)) : '');
$og = $ogImage ?? null;
if ($og && str_starts_with($og, '/')) {
    $og = $baseUrl . $og;
}
$admin = $admin ?? false;
$identity = $identity ?? [];
$nav = [
    'releases' => ['Releases', '/releases'],
    'artists' => ['Artists', '/artists'],
    'label' => ['Label', '/label'],
    'production' => ['Production', 'https://www.reichi.com/'],
    'rental' => ['Rental', '/rental'],
    'projekte' => ['Projekte', '/#projekte'],
];
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?><?= $title !== 'BleedingStar' ? ' · BleedingStar' : '' ?></title>
<meta name="description" content="<?= e($description) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<link rel="icon" href="/assets/img/favicon.png">
<meta name="theme-color" content="#0c0c10">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="BleedingStar">
<?php if ($og): ?><meta property="og:image" content="<?= e($og) ?>"><?php endif; ?>
<link rel="preload" href="/assets/fonts/Oswald-500.ttf" as="font" type="font/ttf" crossorigin>
<link rel="stylesheet" href="/assets/css/site.css?v=<?= e(asset_version('/assets/css/site.css')) ?>">
<script src="/assets/js/site.js?v=<?= e(asset_version('/assets/js/site.js')) ?>" defer></script>
</head>
<body class="<?= $admin ? 'is-admin' : '' ?><?= $current === '' && !$admin ? ' page-home' : '' ?>">
<a class="skip" href="#inhalt">Zum Inhalt springen</a>
<header class="site-header" id="top">
  <div class="wrap bar">
    <a class="brand" href="<?= $admin ? '/admin' : '/' ?>" aria-label="BleedingStar – Startseite">
      <img class="brand__logo" src="/assets/img/logo-white.png" alt="BleedingStar" width="156" height="44">
      <?php if ($admin): ?><span class="brand__tag">Admin</span><?php endif; ?>
    </a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav" hidden>
      <span class="nav-toggle__bars" aria-hidden="true"><span></span><span></span><span></span></span>
      <span>Menü</span>
    </button>
    <?php if ($admin): ?>
      <nav class="nav site-nav" id="site-nav" aria-label="Admin">
        <ul class="nav__list">
          <li><a href="/admin">Status</a></li>
          <li><a href="/admin/releases">Releases</a></li>
          <li><a href="/admin/artists">Artists</a></li>
          <li><a href="/admin/rental">Rental</a></li>
          <li><a href="/admin/reviews">Prüfung</a></li>
          <li><a href="/admin/sync">Sync</a></li>
          <li><a href="/admin/pages">Texte</a></li>
          <li><a href="/">Website</a></li>
        </ul>
        <?php if (!empty($adminUser)): ?>
          <form method="post" action="/admin/logout"><input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>"><button class="btn btn-ghost btn-small" type="submit">Abmelden</button></form>
        <?php endif; ?>
      </nav>
    <?php else: ?>
      <nav class="nav site-nav" id="site-nav" aria-label="Hauptnavigation">
        <ul class="nav__list">
          <?php foreach ($nav as $key => [$label, $href]): ?>
            <?php $external = str_starts_with($href, 'http'); ?>
            <li><a <?= !$external && $current === $key ? 'aria-current="page"' : '' ?> href="<?= e($href) ?>"<?= $external ? ' rel="noopener noreferrer" target="_blank"' : '' ?>><?= e($label) ?></a></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-small nav__cta<?= $current === 'kontakt' ? ' is-current' : '' ?>" href="/kontakt">Anfrage</a>
      </nav>
    <?php endif; ?>
  </div>
</header>
<main id="inhalt" tabindex="-1">
<?php if (!empty($flash)): ?>
  <div class="wrap"><p class="error" role="alert"><?= e($flash) ?></p></div>
<?php endif; ?>
<?= $content ?>
</main>
<footer class="site-footer">
  <div class="wrap site-footer__inner">
    <div>
      <a class="brand brand--footer" href="/"><img class="brand__logo" src="/assets/img/logo-white.png" alt="BleedingStar" width="156" height="44"></a>
      <p class="site-footer__meta">Label, Publishing und Live-Produktion aus Oberösterreich. Releases, Artists und Equipment unter einem Dach.</p>
      <p class="site-footer__meta">Teil von <a href="https://www.reichi.com" rel="noopener">reichi.com</a></p>
    </div>
    <div>
      <h2 class="site-footer__heading">Katalog</h2>
      <ul class="site-footer__links">
        <li><a href="/releases">Releases</a></li>
        <li><a href="/artists">Artists</a></li>
        <li><a href="/releases?type=album">Alben</a></li>
        <li><a href="/releases?type=single">Singles</a></li>
      </ul>
    </div>
    <div>
      <h2 class="site-footer__heading">Services</h2>
      <ul class="site-footer__links">
        <li><a href="/label">Label</a></li>
        <li><a href="https://www.reichi.com/" rel="noopener noreferrer" target="_blank">Production</a></li>
        <li><a href="/rental">Rental</a></li>
        <li><a href="/kontakt">Anfrage</a></li>
      </ul>
    </div>
    <div>
      <h2 class="site-footer__heading">Kontakt</h2>
      <address class="site-footer__address">
        <?= e($identity['brand'] ?? 'BleedingStar Music Services') ?><br>
        <?php if (!empty($identity['street'])): ?><?= e($identity['street']) ?><br><?= e($identity['postal'] ?? '') ?><br><?php endif; ?>
        <?php if (!empty($identity['email'])): ?><a href="mailto:<?= e($identity['email']) ?>"><?= e($identity['email']) ?></a><?php endif; ?>
      </address>
    </div>
  </div>
  <div class="site-footer__bottom">
    <div class="wrap">
      <p>© <?= date('Y') ?> BleedingStar Music Services</p>
      <nav aria-label="Fußzeile">
        <a href="/impressum">Impressum</a>
        <a href="/datenschutz">Datenschutz</a>
      </nav>
    </div>
  </div>
</footer>
</body>
</html>
