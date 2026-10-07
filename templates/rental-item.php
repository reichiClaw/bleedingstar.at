<article class="wrap section">
  <p class="kicker"><a href="/rental">Rental</a> · <?= e($item['category_name']) ?></p>
  <h1><?= e($item['name']) ?></h1>
  <p class="lede"><?= e($item['summary']) ?></p>
  <div class="prose"><?= $item['description_html'] ?></div>
  <?php if (trim(strip_tags((string) $item['specs_html'])) !== ''): ?>
    <h2>Technik</h2>
    <div class="prose"><?= $item['specs_html'] ?></div>
  <?php endif; ?>
  <?php if ((int) $item['price_public'] === 1 && $item['price_cents'] !== null): ?>
    <p class="meta">Preis: <?= e(number_format(((int) $item['price_cents']) / 100, 2, ',', '.')) ?> €</p>
  <?php else: ?>
    <p class="note">Preis auf Anfrage. Es wird nichts reserviert.</p>
  <?php endif; ?>
  <?php if ($item['files']): ?>
    <h2>Downloads</h2>
    <ul class="lined">
      <?php foreach ($item['files'] as $file): ?>
        <li><a href="/media/<?= e($file['path']) ?>"><?= e($file['title']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <form method="post" action="/rental/cart" class="actions">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
    <input type="hidden" name="back" value="/kontakt?thema=rental">
    <label>Menge <input name="qty" type="number" min="1" max="20" value="1"></label>
    <button class="btn btn-accent" type="submit">Unverbindlich anfragen</button>
  </form>
</article>
