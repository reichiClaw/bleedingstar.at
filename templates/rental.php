<section class="wrap section">
  <div class="section-head"><h1>Rental</h1></div>
  <p class="note">Nur benannte Geräte aus dem bisherigen Archiv. Keine Preise, keine Live-Verfügbarkeit, keine verbindliche Buchung. Die Anfrage ist unverbindlich.</p>
  <?php foreach ($categories as $category): ?>
    <h2><?= e($category['name']) ?></h2>
    <div class="rental-list">
      <?php foreach ($category['items'] as $item): ?>
        <article class="rental-card">
          <h3><a href="/rental/<?= e($item['slug']) ?>"><?= e($item['name']) ?></a></h3>
          <p><?= e($item['summary']) ?></p>
          <?php if ((int) $item['price_public'] === 1 && $item['price_cents'] !== null): ?>
            <p class="meta"><?= e(number_format(((int) $item['price_cents']) / 100, 2, ',', '.')) ?> €</p>
          <?php endif; ?>
          <form method="post" action="/rental/cart">
            <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
            <input type="hidden" name="back" value="/rental/<?= e($item['slug']) ?>">
            <label>Menge <input name="qty" type="number" min="1" max="20" value="1"></label>
            <button class="btn btn-ghost" type="submit">Zur Anfrage</button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($cart): ?>
    <p><a class="btn btn-accent" href="/kontakt?thema=rental">Anfrage mit <?= count($cart) ?> Artikeln</a></p>
  <?php endif; ?>
</section>
