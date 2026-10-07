<section class="wrap section">
  <div class="section-head"><h1>Artists</h1></div>
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
          <h2><?= e($artist['name']) ?></h2>
        </a>
        <?php if ($artist['roles']): ?><p class="meta"><?= e(implode(' · ', $artist['roles'])) ?></p><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</section>
