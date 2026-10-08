<?php
/** @var array $release */
/** @var App\CatalogRepository $catalog */
$cover = $catalog->cover($release, 'grid');
$date = format_release_date(
    $release['release_year'] !== null ? (int) $release['release_year'] : null,
    $release['release_month'] !== null ? (int) $release['release_month'] : null,
    $release['release_day'] !== null ? (int) $release['release_day'] : null,
    (string) $release['release_date_precision']
);
$type = release_type_label($release['release_type'] ?? null);
$year = $date !== '' ? $date : (string) ($release['release_year'] ?? '');
?>
<article class="card">
  <a class="cover-link" href="/releases/<?= e($release['slug']) ?>">
    <span class="cover">
      <?php if ($cover): ?>
        <img src="<?= e($cover['url']) ?>" alt="" width="600" height="600" <?= !empty($eager) ? '' : 'loading="lazy"' ?>>
      <?php else: ?>
        <span class="ph" aria-hidden="true"><?= e(mb_substr($release['title'], 0, 1)) ?></span>
      <?php endif; ?>
      <?php if ($type): ?><span class="card__type"><?= e($type) ?></span><?php endif; ?>
    </span>
    <h2 class="card__title"><?= e($release['title']) ?></h2>
  </a>
  <p class="by">
    <?php foreach ($release['artists'] as $i => $artist): ?>
      <?php if ($i): ?>, <?php endif; ?>
      <?php if (($artist['status'] ?? 'published') === 'published'): ?>
        <a href="/artists/<?= e($artist['slug']) ?>"><?= e($artist['name']) ?></a>
      <?php else: ?>
        <?= e($artist['name']) ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </p>
  <?php if ($year !== ''): ?><p class="meta"><?= e($year) ?></p><?php endif; ?>
</article>
