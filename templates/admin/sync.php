<section class="wrap section">
  <h1>Sync</h1>
  <p>Ein Klick startet keinen langen Import im Browser. Er legt nur eine Job-Datei an. Der CLI-Lauf verarbeitet sie:</p>
  <pre>php bin/sync-releases.php</pre>
  <?php if ($pending): ?><p class="note">Eine Anforderung wartet auf den nächsten CLI-Lauf.</p><?php endif; ?>
  <form method="post" action="/admin/sync">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <button class="btn btn-accent" type="submit">Sync anfordern</button>
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
