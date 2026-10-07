<section class="wrap section">
  <h1><?= e($row['name']) ?></h1>
  <form method="post" enctype="multipart/form-data" class="form" action="/admin/artists/<?= (int)$row['id'] ?>">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <label>Name <input name="name" value="<?= e($row['name']) ?>" required></label>
    <label>Status
      <select name="status"><?php foreach (['published','draft','hidden'] as $st): ?><option <?= $row['status']===$st?'selected':'' ?>><?= $st ?></option><?php endforeach; ?></select>
    </label>
    <label>Website <input name="website" value="<?= e((string)$row['website']) ?>"></label>
    <label>Text <textarea name="bio_html" rows="10"><?= e($row['bio_html']) ?></textarea></label>
    <label>Bild <input type="file" name="image" accept="image/*"></label>
    <button class="btn btn-accent" type="submit">Speichern</button>
  </form>
</section>
