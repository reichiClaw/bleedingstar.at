<section class="wrap section">
  <h1>Rental</h1>
  <p>Der Text der Rental-Seite steht unter <a href="/admin/pages#rental">Texte</a>.</p>
  <ul class="lined">
    <?php foreach ($rows as $row): ?>
      <li><a href="/admin/rental/<?= (int)$row['id'] ?>"><?= e($row['name']) ?></a><span><?= e($row['status']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
