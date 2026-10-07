<section class="wrap section">
  <h1><?= e($slug) ?></h1>
  <form method="post" class="form" action="/admin/pages/<?= e($slug) ?>">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <label>Titel <input name="title" value="<?= e($row['title'] ?? '') ?>"></label>
    <label>Text <textarea name="body_html" rows="14"><?= e($row['body_html'] ?? '') ?></textarea></label>
    <button class="btn btn-accent" type="submit">Speichern</button>
  </form>
</section>
