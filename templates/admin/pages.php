<section class="wrap section">
  <h1>Texte</h1>
  <form method="post" class="form" action="/admin/pages">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <?php foreach ($pages as $page): ?>
      <fieldset id="<?= e($page['slug']) ?>">
        <legend><?= e($page['label']) ?></legend>
        <label>Titel <input name="pages[<?= e($page['slug']) ?>][title]" value="<?= e($page['title']) ?>"></label>
        <label>Text <textarea name="pages[<?= e($page['slug']) ?>][body_html]" rows="12"><?= e($page['body_html']) ?></textarea></label>
      </fieldset>
    <?php endforeach; ?>
    <button class="btn btn-accent" type="submit">Speichern</button>
  </form>
</section>
