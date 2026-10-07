<section class="wrap section">
  <div class="section-head"><h1>Events</h1></div>
  <p class="note">Historische Termine aus dem Archiv. Keine aktuelle Verfügbarkeit.</p>
  <table class="sheet">
    <thead><tr><th>Datum</th><th>Event</th><th>Ort</th><th>Artist</th></tr></thead>
    <tbody>
      <?php foreach ($events as $event): ?>
        <tr>
          <td><?= e((string) $event['event_date']) ?></td>
          <td><?= e($event['title']) ?></td>
          <td><?= e((string) $event['place']) ?></td>
          <td><?php if ($event['artist_slug']): ?><a href="/artists/<?= e($event['artist_slug']) ?>"><?= e((string) $event['artist_name']) ?></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
