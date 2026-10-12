<section class="wrap section">
  <h1>Sync</h1>
  <?php if (!empty($spotifyConfigured)): ?>
    <p>Die Spotify-Zugangsdaten sind eingetragen. Jeder Lauf geht Discogs, Deezer, Apple Music, Spotify und den Discogs-Cache durch.</p>
  <?php else: ?>
    <p>Spotify ist noch nicht eingerichtet. Jeder Lauf geht Discogs, Deezer, Apple Music und den Discogs-Cache durch. Spotify kommt dazu, sobald die Zugangsdaten eingetragen sind.</p>
  <?php endif; ?>
  <p class="note">„Cron starten“ führt denselben Lauf sofort aus wie der geplante Cron. Er beginnt jedes Mal bei einer anderen Quelle, damit das Zeitbudget von 180&nbsp;s fair verteilt ist. Die Seite wartet, bis der Lauf fertig ist. Für Links und Cover gilt Spotify vor Apple Music, dann Deezer, dann Discogs. Hochgeladene und archivierte Cover bleiben.</p>
  <p class="note">„Sync anfordern“ legt nur eine Job-Datei an. Der nächste Cron-Lauf verarbeitet sie, ebenso die CLI:</p>
  <pre>php bin/sync-releases.php</pre>
  <?php if ($pending): ?><p class="note">Eine Anforderung wartet auf den nächsten Cron-Lauf.</p><?php endif; ?>
  <form method="post" action="/admin/sync" class="sync-actions">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <button class="btn btn-accent" type="submit" name="action" value="run">Cron starten</button>
    <button class="btn" type="submit" name="action" value="request">Sync anfordern</button>
  </form>
  <h2>Protokoll</h2>
  <ul class="lined">
    <?php foreach ($runs as $run): ?>
      <li>
        <span><?= e($run['started_at']) ?> · <?= e($run['job']) ?> · <?= e($run['status']) ?></span>
        <span>+<?= (int)$run['created_count'] ?> / ~<?= (int)$run['updated_count'] ?> / ?<?= (int)$run['review_count'] ?> / !<?= (int)$run['error_count'] ?></span>
      </li>
      <?php if ($run['message']): ?><li><?= e($run['message']) ?></li><?php endif; ?>
    <?php endforeach; ?>
  </ul>
</section>
