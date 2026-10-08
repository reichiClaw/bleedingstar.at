<article class="section section--page">
  <div class="wrap">
    <p class="eyebrow"><a href="/artists">Artists</a></p>
    <div class="release-hero">
      <div class="cover lg">
        <?php if ($artist['image_path']): ?>
          <img src="/media/<?= e($artist['image_path']) ?>" alt="<?= e($artist['name']) ?>" width="800" height="800">
        <?php else: ?>
          <span class="ph"><?= e(mb_substr($artist['name'], 0, 1)) ?></span>
        <?php endif; ?>
      </div>
      <div class="release-hero__body">
        <h1 class="release-hero__title"><?= e($artist['name']) ?></h1>
        <?php if ($artist['roles']): ?><p class="meta"><?= e(implode(' · ', $artist['roles'])) ?></p><?php endif; ?>
        <p class="actions">
          <?php if (!empty($artist['website']) && preg_match('#^https?://#i', $artist['website'])): ?><a class="btn btn-ghost" href="<?= e($artist['website']) ?>" rel="noopener">Website</a><?php endif; ?>
          <?php if ($artist['facebook']): ?><a class="btn btn-ghost" href="<?= e($artist['facebook']) ?>" rel="noopener">Facebook</a><?php endif; ?>
          <?php if ($artist['soundcloud']): ?><a class="btn btn-ghost" href="<?= e($artist['soundcloud']) ?>" rel="noopener">SoundCloud</a><?php endif; ?>
          <?php if ($artist['twitter']): ?><a class="btn btn-ghost" href="<?= e($artist['twitter']) ?>" rel="noopener">Twitter</a><?php endif; ?>
        </p>
        <?php if (trim(strip_tags((string) $artist['bio_html'])) !== ''): ?>
          <div class="prose release-hero__text"><?= $artist['bio_html'] ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($artist['releases']): ?>
      <section class="related" aria-labelledby="artist-releases">
        <header class="section__head section__head--split">
          <div>
            <p class="eyebrow">Katalog</p>
            <h2 class="section__title section__title--small" id="artist-releases">Releases bei BleedingStar</h2>
          </div>
          <p class="section__note"><?= count($artist['releases']) ?> Veröffentlichungen</p>
        </header>
        <div class="grid">
          <?php foreach ($artist['releases'] as $release): ?>
            <?php $eager = false; include __DIR__ . '/partials/card.php'; ?>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</article>
