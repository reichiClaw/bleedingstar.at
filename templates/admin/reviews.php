<section class="wrap section">
  <h1>Prüffälle</h1>
  <p class="note">Gleiche Titel werden nicht automatisch zusammengeführt.</p>
  <?php if (!$rows): ?><p>Keine offenen Fälle.</p><?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <article class="rental-card">
      <h2><?= e(ucfirst($row['provider'])) ?> <?= e($row['external_id']) ?></h2>
      <p><?= e($row['reason']) ?></p>
      <?php if (!empty($row['incoming'])): ?>
        <p>Eingehend: <?php if (!empty($row['incoming_url'])): ?><a href="<?= e($row['incoming_url']) ?>" rel="noopener noreferrer"><?= e($row['incoming']) ?></a><?php else: ?><?= e($row['incoming']) ?><?php endif; ?></p>
      <?php endif; ?>
      <?php if ($row['candidate_title']): ?><p>Vorhanden: <a href="/releases/<?= e($row['candidate_slug']) ?>"><?= e($row['candidate_title']) ?></a></p><?php endif; ?>
      <form method="post" action="/admin/reviews/<?= (int)$row['id'] ?>" class="actions">
        <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
        <button class="btn btn-accent" name="do" value="merge" type="submit">Zuordnen</button>
        <button class="btn btn-ghost" name="do" value="dismiss" type="submit">Verwerfen</button>
      </form>
    </article>
  <?php endforeach; ?>
</section>
