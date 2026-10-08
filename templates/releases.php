<section class="section section--page">
  <div class="wrap">
    <header class="section__head section__head--split">
      <div>
        <p class="eyebrow">Katalog</p>
        <h1 class="section__title">Releases</h1>
      </div>
      <p class="section__note"><?= (int) $result['total'] ?> Veröffentlichungen aus dem BleedingStar-Katalog – Singles, EPs, Alben und Compilations, nach Artist, Jahr oder Typ filterbar.</p>
    </header>
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
  </div>
</section>
<script src="/assets/js/catalog.js"></script>
