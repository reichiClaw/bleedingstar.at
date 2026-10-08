<?php
/** @var array $groups */
/** @var int $total */
/** @var array $filters */
/** @var array $artists */
/** @var array $years */
$types = ['single' => 'Single', 'ep' => 'EP', 'album' => 'Album', 'compilation' => 'Compilation'];
$statuses = ['published' => 'veröffentlicht', 'hidden' => 'verborgen', 'draft' => 'Entwurf', 'pending' => 'ausstehend'];
$statusOptions = ['published' => 'öffentlich', 'hidden' => 'verborgen', 'draft' => 'Entwurf', 'pending' => 'ausstehend'];
$active = $filters['q'] !== '' || $filters['artist'] > 0 || $filters['status'] !== '' || $filters['type'] !== '' || $filters['year'] > 0;
$groupCount = count($groups);
?>
<section class="wrap section">
  <h1>Releases</h1>
  <p class="meta"><?= (int) $total ?> <?= $total === 1 ? 'Release' : 'Releases' ?><?= $groupCount > 0 ? ' in ' . $groupCount . ($groupCount === 1 ? ' Gruppe' : ' Gruppen') : '' ?>.</p>

  <form method="post" action="/admin/releases/neu" class="form admin-create">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <label>Neue Veröffentlichung <input name="title" required maxlength="255" placeholder="Titel"></label>
    <button class="btn btn-ghost" type="submit">Anlegen</button>
  </form>

  <form class="filters" method="get" action="/admin/releases">
    <label>Suche
      <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Titel">
    </label>
    <label>Artist
      <select name="artist">
        <option value="">Alle</option>
        <?php foreach ($artists as $artist): ?>
          <option value="<?= (int) $artist['id'] ?>" <?= $filters['artist'] === (int) $artist['id'] ? 'selected' : '' ?>><?= e($artist['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Status
      <select name="status">
        <option value="">Alle</option>
        <?php foreach ($statusOptions as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Typ
      <select name="type">
        <option value="">Alle</option>
        <?php foreach ($types as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Jahr
      <select name="year">
        <option value="">Alle</option>
        <?php foreach ($years as $year): ?>
          <option value="<?= (int) $year ?>" <?= $filters['year'] === (int) $year ? 'selected' : '' ?>><?= (int) $year ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="filters__actions">
      <button class="btn btn-accent" type="submit">Filtern</button>
      <?php if ($active): ?><a class="btn btn-ghost" href="/admin/releases">Zurücksetzen</a><?php endif; ?>
    </div>
  </form>

  <?php if ($groups === []): ?>
    <p class="meta">Keine Releases für diese Auswahl.</p>
  <?php else: ?>
    <div class="admin-groups">
      <?php foreach ($groups as $group): ?>
        <section class="admin-group">
          <h2 class="admin-group__head">
            <?php if ($group['id']): ?>
              <a href="/admin/artists/<?= (int) $group['id'] ?>"><?= e($group['name']) ?></a>
            <?php else: ?>
              <span><?= e($group['name']) ?></span>
            <?php endif; ?>
            <span class="admin-group__count"><?= count($group['releases']) ?></span>
          </h2>
          <ul class="lined">
            <?php foreach ($group['releases'] as $row): ?>
              <?php
                $type = (string) ($row['type'] ?? '');
                $status = (string) ($row['status'] ?? '');
                $meta = array_filter([
                    $types[$type] ?? $type,
                    $row['year'] ? (string) $row['year'] : '',
                    $statuses[$status] ?? $status,
                    $row['featured'] ? 'featured' : '',
                    (string) $row['source'],
                ]);
                $others = array_slice($row['artists'], 1);
              ?>
              <li>
                <a href="/admin/releases/<?= (int) $row['id'] ?>"><?= e($row['title']) ?></a>
                <span><?= e(implode(' · ', $meta)) ?><?php if ($others): ?> · mit <?= e(implode(', ', $others)) ?><?php endif; ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
