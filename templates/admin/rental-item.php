<section class="wrap section">
  <h1><?= e($row['name']) ?></h1>
  <form method="post" class="form" action="/admin/rental/<?= (int)$row['id'] ?>">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <label>Name <input name="name" value="<?= e($row['name']) ?>"></label>
    <label>Kurztext <input name="summary" value="<?= e($row['summary']) ?>"></label>
    <label>Beschreibung <textarea name="description_html" rows="6"><?= e($row['description_html']) ?></textarea></label>
    <label>Technik <textarea name="specs_html" rows="4"><?= e((string)$row['specs_html']) ?></textarea></label>
    <label>Status <select name="status"><option value="published" <?= $row['status']==='published'?'selected':'' ?>>published</option><option value="hidden" <?= $row['status']==='hidden'?'selected':'' ?>>hidden</option></select></label>
    <label>Preis in Euro, nur wenn freigegeben <input name="price_euro" value="<?= $row['price_cents']!==null ? e(number_format(((int)$row['price_cents'])/100, 2, '.', '')) : '' ?>"></label>
    <label><input type="checkbox" name="price_public" <?= (int)$row['price_public']?'checked':'' ?>> Preis öffentlich zeigen</label>
    <button class="btn btn-accent" type="submit">Speichern</button>
  </form>
</section>
