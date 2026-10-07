<section class="wrap section">
  <h1>Releases</h1>
  <form method="post" action="/admin/releases/neu" class="form">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <label>Neue Veröffentlichung <input name="title" required maxlength="255"></label>
    <button class="btn btn-ghost" type="submit">Anlegen</button>
  </form>
  <ul class="lined">
    <?php foreach ($rows as $row): ?>
      <li>
        <a href="/admin/releases/<?= (int) $row['id'] ?>"><?= e($row['title']) ?></a>
        <span><?= e($row['status']) ?><?= (int) $row['featured'] ? ' · featured' : '' ?> · <?= e($row['source']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
