<?php
/** @var array $result */
$filters = $result['filters'];
$chips = [];
if ($filters['q'] !== '') {
    $chips[] = ['label' => 'Suche: ' . $filters['q'], 'drop' => 'q'];
}
if ($filters['artist'] !== '') {
    $artistLabel = $filters['artist'];
    foreach ($artists ?? [] as $artist) {
        if ($artist['slug'] === $filters['artist']) {
            $artistLabel = $artist['name'];
            break;
        }
    }
    $chips[] = ['label' => 'Artist: ' . $artistLabel, 'drop' => 'artist'];
}
if ($filters['year'] !== '') {
    $chips[] = ['label' => (string) $filters['year'], 'drop' => 'year'];
}
if ($filters['type'] !== '') {
    $chips[] = ['label' => release_type_label($filters['type']), 'drop' => 'type'];
}
?>
<div class="results-meta" id="results">
  <p><?= (int) $result['total'] ?> Veröffentlichungen</p>
  <?php if ($chips): ?>
    <ul class="chips">
      <?php foreach ($chips as $chip): ?>
        <?php $next = $filters; $next[$chip['drop']] = ''; unset($next['page']); ?>
        <li><a href="/releases?<?= e(http_build_query(array_filter($next, static fn ($v) => $v !== '' && $v !== null))) ?>"><?= e($chip['label']) ?> <span aria-hidden="true">×</span></a></li>
      <?php endforeach; ?>
      <li><a href="/releases">Zurücksetzen</a></li>
    </ul>
  <?php endif; ?>
</div>
<?php if (!$result['items']): ?>
  <p class="empty">Keine Veröffentlichungen für diese Auswahl.</p>
<?php else: ?>
  <div class="grid">
    <?php foreach ($result['items'] as $i => $release): ?>
      <?php $eager = $i < 4; include __DIR__ . '/card.php'; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($result['pages'] > 1): ?>
  <nav class="pager" aria-label="Seiten">
    <?php for ($p = 1; $p <= $result['pages']; $p++): ?>
      <?php $q = $filters; $q['page'] = $p; ?>
      <?php if ($p === $result['page']): ?>
        <span aria-current="page"><?= $p ?></span>
      <?php else: ?>
        <a href="/releases?<?= e(http_build_query(array_filter($q, static fn ($v) => $v !== '' && $v !== null))) ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </nav>
<?php endif; ?>
