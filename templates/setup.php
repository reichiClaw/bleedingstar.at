<section class="wrap section narrow">
  <h1>Einrichtung</h1>
  <?php foreach ($errors as $error): ?>
    <p class="error" role="alert"><?= e($error) ?></p>
  <?php endforeach; ?>
  <?php if ($done): ?>
    <p class="note" role="status">Fertig. Datenbank und Admin-Zugang stehen bereit; diese Seite ist ab jetzt abgeschaltet.</p>
    <?php if (is_array($stats)): ?>
      <ul>
        <?php foreach ($stats as $key => $value): ?>
          <li><?= e((string) $key) ?>: <?= e(is_scalar($value) ? (string) $value : json_encode($value)) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p><a class="btn btn-accent" href="/admin/login">Zum Admin</a> <a class="btn btn-ghost" href="/">Zur Website</a></p>
  <?php else: ?>
    <p>Legt die Tabellen an, spielt auf Wunsch die gesicherten Inhalte der alten Website ein und erstellt den ersten Admin-Zugang. Der Vorgang läuft nur einmal.</p>
    <form method="post" action="/setup" class="form">
      <input type="hidden" name="token" value="<?= e((string) $token) ?>">
      <label>Admin E-Mail <input name="email" type="email" required autocomplete="username"></label>
      <label>Admin Passwort (mindestens 12 Zeichen) <input name="password" type="password" required minlength="12" autocomplete="new-password"></label>
      <label><input type="checkbox" name="import" checked> Inhalte der alten Website einspielen (Künstler, Releases, News, Texte)</label>
      <button class="btn btn-accent" type="submit">Einrichten</button>
    </form>
  <?php endif; ?>
</section>
