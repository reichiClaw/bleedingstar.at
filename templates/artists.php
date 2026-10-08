<section class="section section--page">
  <div class="wrap">
    <header class="section__head section__head--split">
      <div>
        <p class="eyebrow">Roster</p>
        <h1 class="section__title">Artists</h1>
      </div>
      <p class="section__note"><?= count($artists) ?> Künstlerinnen, Künstler und Bands, die bei BleedingStar veröffentlicht haben.</p>
    </header>
    <div class="grid artists">
      <?php foreach ($artists as $artist): ?>
        <article class="card person">
          <a class="cover-link" href="/artists/<?= e($artist['slug']) ?>">
            <span class="cover">
              <?php if ($artist['image_path']): ?>
                <img src="/media/<?= e($artist['image_path']) ?>" alt="" width="600" height="600" loading="lazy">
              <?php else: ?>
                <span class="ph" aria-hidden="true"><?= e(mb_substr($artist['name'], 0, 1)) ?></span>
              <?php endif; ?>
            </span>
            <h2 class="card__title"><?= e($artist['name']) ?></h2>
          </a>
          <?php if ($artist['roles']): ?><p class="meta"><?= e(implode(' · ', $artist['roles'])) ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
