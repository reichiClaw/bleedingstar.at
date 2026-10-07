<section class="wrap section">
  <h1><?= e($row['title']) ?></h1>
  <p class="meta">Quelle: <?= e($row['source']) ?><?= $row['cover_fetched_at'] ? ' · Cover geprüft ' . e($row['cover_fetched_at']) : '' ?></p>
  <form method="post" action="/admin/releases/<?= (int) $row['id'] ?>" enctype="multipart/form-data" class="form">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <label>Titel <input name="title" value="<?= e($row['title']) ?>" required></label>
    <label>Status
      <select name="status">
        <?php foreach (['published','hidden','draft','pending'] as $st): ?>
          <option value="<?= $st ?>" <?= $row['status']===$st?'selected':'' ?>><?= $st ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><input type="checkbox" name="featured" <?= (int)$row['featured']?'checked':'' ?>> Hervorheben</label>
    <label>Typ
      <select name="release_type">
        <option value="">unbekannt</option>
        <?php foreach (['single','ep','album','compilation'] as $st): ?>
          <option value="<?= $st ?>" <?= ($row['release_type']??'')===$st?'selected':'' ?>><?= $st ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Genauigkeit
      <select name="precision">
        <?php foreach (['unknown','year','month','day'] as $st): ?>
          <option value="<?= $st ?>" <?= $row['release_date_precision']===$st?'selected':'' ?>><?= $st ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Jahr <input name="release_year" value="<?= e((string)$row['release_year']) ?>"></label>
    <label>Monat <input name="release_month" value="<?= e((string)$row['release_month']) ?>"></label>
    <label>Tag <input name="release_day" value="<?= e((string)$row['release_day']) ?>"></label>
    <label>Beschreibung <textarea name="description_html" rows="8"><?= e($row['description_html']) ?></textarea></label>
    <fieldset>
      <legend>Künstler</legend>
      <?php foreach ($artists as $artist): ?>
        <label><input type="checkbox" name="artist_ids[]" value="<?= (int)$artist['id'] ?>" <?= in_array($artist['id'], $linked)?'checked':'' ?>> <?= e($artist['name']) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label>Eigenes Cover <input type="file" name="cover" accept="image/*"></label>
    <label>Link-Bezeichnung <input name="link_label" placeholder="Spotify"></label>
    <label>Link-URL <input name="link_url" placeholder="https://"></label>
    <button class="btn btn-accent" type="submit">Speichern</button>
  </form>
  <?php if ($links): ?>
    <ul><?php foreach ($links as $link): ?><li><?= e($link['label']) ?>: <?= e($link['url']) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <p><a href="/releases/<?= e($row['slug']) ?>">Öffentliche Seite</a></p>
</section>
