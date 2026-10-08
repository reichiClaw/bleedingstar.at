<section class="wrap section">
  <h1>Status</h1>
  <ul class="stat">
    <li><?= (int) $counts['releases'] ?> veröffentlichte Releases</li>
    <li><?= (int) $counts['artists'] ?> Künstler</li>
    <li><?= (int) $counts['reviews'] ?> offene Prüffälle</li>
  </ul>
  <p><a href="/admin/pages">Texte</a></p>
  <h2>Umgebung</h2>
  <ul class="lined">
    <li><span>PHP</span><span><?= e(PHP_VERSION) ?> (<?= e(PHP_SAPI) ?>)</span></li>
    <li><span>Datenbank</span><span><?= e($env['db']) ?></span></li>
    <li><span>Erweiterungen</span><span><?= e($env['extensions']) ?></span></li>
    <li><span>Schreibrechte storage/</span><span><?= e($env['writable']) ?></span></li>
  </ul>
  <h2>Letzte Jobs</h2>
  <?php if (!$runs): ?><p>Noch kein Sync.</p><?php endif; ?>
  <ul class="lined">
    <?php foreach ($runs as $run): ?>
      <li><span><?= e($run['job']) ?> · <?= e($run['status']) ?><?= (int) $run['dry_run'] ? ' · dry-run' : '' ?></span><span><?= e((string) $run['started_at']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
