<section class="wrap section">
  <div class="section-head"><h1>Downloads</h1></div>
  <p class="note">Dateien aus dem bisherigen Dokumentenarchiv. Die Links zeigen auf die vorhandenen Dateien.</p>
  <ul class="lined">
    <?php foreach ($documents as $doc): ?>
      <li>
        <a href="<?= e($doc['file_url']) ?>"><?= e($doc['title']) ?></a>
        <?php if ($doc['artist_slug']): ?><span><a href="/artists/<?= e($doc['artist_slug']) ?>"><?= e($doc['artist_name']) ?></a></span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
