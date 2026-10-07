<section class="wrap section">
  <div class="section-head">
    <h1>Releases</h1>
  </div>
  <p class="note">Der Katalog zeigt das bisherige BleedingStar-Archiv. Er ist kein Abgleich mit einer vollständigen Vertriebsliste. Neue Titel aus Discogs erscheinen erst nach dem Import und, bei unsicherer Zuordnung, nach Prüfung.</p>
  <form class="filters" method="get" action="/releases" id="release-filter">
    <label>Suche
      <input type="search" name="q" value="<?= e($result['filters']['q']) ?>" placeholder="Titel oder Künstler">
    </label>
    <label>Artist
      <select name="artist">
        <option value="">Alle</option>
        <?php foreach ($artists as $artist): ?>
          <option value="<?= e($artist['slug']) ?>" <?= $result['filters']['artist'] === $artist['slug'] ? 'selected' : '' ?>><?= e($artist['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Jahr
      <select name="year">
        <option value="">Alle</option>
        <?php foreach ($years as $year): ?>
          <option value="<?= (int) $year ?>" <?= (string) $result['filters']['year'] === (string) $year ? 'selected' : '' ?>><?= (int) $year ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Typ
      <select name="type">
        <option value="">Alle</option>
        <?php foreach (['single' => 'Single', 'ep' => 'EP', 'album' => 'Album', 'compilation' => 'Compilation'] as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $result['filters']['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Sortierung
      <select name="sort">
        <option value="new" <?= $result['filters']['sort'] === 'new' ? 'selected' : '' ?>>Neueste</option>
        <option value="az" <?= $result['filters']['sort'] === 'az' ? 'selected' : '' ?>>A–Z</option>
      </select>
    </label>
    <button class="btn btn-accent" type="submit">Filtern</button>
  </form>
  <div id="release-results">
    <?php include __DIR__ . '/partials/release-results.php'; ?>
  </div>
</section>
<script src="/assets/js/catalog.js"></script>
