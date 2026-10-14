<section class="wrap section">
  <h1>Einstellungen</h1>
  <form method="post" action="/admin/settings" class="form">
    <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
    <fieldset>
      <legend>Filmkorn</legend>
      <div class="checks">
        <label><input type="radio" name="grain" value="live" <?= ($grainMode ?? 'live') !== 'static' ? 'checked' : '' ?>> Bewegt</label>
        <label><input type="radio" name="grain" value="static" <?= ($grainMode ?? '') === 'static' ? 'checked' : '' ?>> Statisch</label>
      </div>
    </fieldset>
    <button class="btn btn-accent" type="submit">Speichern</button>
  </form>
  <p class="note">Bewegt legt etwa zwölfmal pro Sekunde ein neues Korn über die Seite, so wie auf reichi.com. Statisch bleibt die feste Kachel. Wer reduzierte Bewegung eingestellt hat, sieht immer das statische Korn.</p>
</section>
