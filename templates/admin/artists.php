<section class="wrap section">
  <h1>Artists</h1>
  <ul class="lined">
    <?php foreach ($rows as $row): ?>
      <li><a href="/admin/artists/<?= (int)$row['id'] ?>"><?= e($row['name']) ?></a><span><?php if (!empty($row['roles'])): ?><?= e($row['roles']) ?> · <?php endif; ?><?= e($row['status']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
