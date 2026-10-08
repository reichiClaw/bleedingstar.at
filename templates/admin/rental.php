<section class="wrap section">
  <h1>Rental</h1>
  <ul class="lined">
    <?php foreach ($rows as $row): ?>
      <li><a href="/admin/rental/<?= (int)$row['id'] ?>"><?= e($row['name']) ?></a><span><?= e($row['status']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
