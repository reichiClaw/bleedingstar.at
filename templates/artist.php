<article class="wrap section">
  <p class="kicker"><a href="/artists">Artists</a></p>
  <div class="release-hero">
    <div class="cover lg">
      <?php if ($artist['image_path']): ?>
        <img src="/media/<?= e($artist['image_path']) ?>" alt="<?= e($artist['name']) ?>" width="800" height="800">
      <?php else: ?>
        <span class="ph"><?= e(mb_substr($artist['name'], 0, 1)) ?></span>
      <?php endif; ?>
    </div>
    <div>
      <h1><?= e($artist['name']) ?></h1>
      <?php if ($artist['roles']): ?><p class="meta"><?= e(implode(' · ', $artist['roles'])) ?></p><?php endif; ?>
      <p class="actions">
        <?php if (!empty($artist['website']) && preg_match('#^https?://#i', $artist['website'])): ?><a class="btn btn-ghost" href="<?= e($artist['website']) ?>" rel="noopener">Website</a><?php endif; ?>
        <?php if ($artist['facebook']): ?><a class="btn btn-ghost" href="<?= e($artist['facebook']) ?>">Facebook</a><?php endif; ?>
        <?php if ($artist['soundcloud']): ?><a class="btn btn-ghost" href="<?= e($artist['soundcloud']) ?>">SoundCloud</a><?php endif; ?>
        <?php if ($artist['twitter']): ?><a class="btn btn-ghost" href="<?= e($artist['twitter']) ?>">Twitter</a><?php endif; ?>
      </p>
    </div>
  </div>
  <?php if (trim(strip_tags((string) $artist['bio_html'])) !== ''): ?>
    <div class="prose"><?= $artist['bio_html'] ?></div>
  <?php endif; ?>
  <?php if ($artist['releases']): ?>
    <h2>Releases bei BleedingStar</h2>
    <div class="grid">
      <?php foreach ($artist['releases'] as $release): ?>
        <?php $eager = false; include __DIR__ . '/partials/card.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</article>
