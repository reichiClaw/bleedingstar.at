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
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?><?= $title !== 'BleedingStar' ? ' · BleedingStar' : '' ?></title>
<meta name="description" content="<?= e($description) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<link rel="icon" href="/assets/img/favicon.png">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:type" content="website">
<?php if ($og): ?><meta property="og:image" content="<?= e($og) ?>"><?php endif; ?>
<link rel="stylesheet" href="/assets/css/site.css">
</head>
<body class="<?= $admin ? 'is-admin' : '' ?>">
<a class="skip" href="#inhalt">Zum Inhalt</a>
<header class="site-header">
  <div class="wrap bar">
    <a class="logo" href="<?= $admin ? '/admin' : '/' ?>"><img src="/assets/img/logo-white.png" alt="BleedingStar" width="156" height="44"></a>
    <?php if ($admin): ?>
      <nav class="nav" aria-label="Admin">
        <a href="/admin">Status</a>
        <a href="/admin/releases">Releases</a>
        <a href="/admin/artists">Artists</a>
        <a href="/admin/rental">Rental</a>
        <a href="/admin/reviews">Prüfung</a>
        <a href="/admin/sync">Sync</a>
        <a href="/admin/pages/label">Texte</a>
        <a href="/">Website</a>
        <?php if (!empty($adminUser)): ?>
          <form method="post" action="/admin/logout"><input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>"><button class="linkish" type="submit">Abmelden</button></form>
        <?php endif; ?>
      </nav>
    <?php else: ?>
      <nav class="nav" aria-label="Hauptnavigation">
        <a <?= $current === 'label' ? 'aria-current="page"' : '' ?> href="/label">Label</a>
        <a <?= $current === 'releases' ? 'aria-current="page"' : '' ?> href="/releases">Releases</a>
        <a <?= $current === 'artists' ? 'aria-current="page"' : '' ?> href="/artists">Artists</a>
        <a <?= $current === 'production' ? 'aria-current="page"' : '' ?> href="/production">Production</a>
        <a <?= $current === 'rental' ? 'aria-current="page"' : '' ?> href="/rental">Rental</a>
        <a class="nav-ask" <?= $current === 'kontakt' ? 'aria-current="page"' : '' ?> href="/kontakt">Anfrage</a>
      </nav>
    <?php endif; ?>
  </div>
</header>
<main id="inhalt">
<?php if (!empty($flash)): ?>
  <div class="wrap"><p class="error" role="alert"><?= e($flash) ?></p></div>
<?php endif; ?>
<?= $content ?>
</main>
<footer class="site-footer">
  <div class="wrap foot">
    <p>© BleedingStar Music Services</p>
    <nav aria-label="Fußzeile">
      <a href="/impressum">Impressum</a>
      <a href="/datenschutz">Datenschutz</a>
    </nav>
  </div>
</footer>
</body>
</html>
