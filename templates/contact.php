<section class="wrap section section--page split contact">
  <div class="contact__intro">
    <p class="eyebrow">Anfrage</p>
    <h1 class="section__title">Kontakt</h1>
    <p class="section__note">Release, Tour, Aufnahme, Download-Codes oder Equipment: eine kurze Nachricht reicht, Rückmeldung kommt direkt.</p>
    <address class="contact__address">
      <p><strong><?= e($identity['name']) ?></strong><br><?= e($identity['brand']) ?><br><?= e($identity['street']) ?><br><?= e($identity['postal']) ?></p>
      <p><a href="tel:<?= e($identity['phone_href']) ?>"><?= e($identity['phone']) ?></a><br>
      <a href="mailto:<?= e($identity['email']) ?>"><?= e($identity['email']) ?></a></p>
      <p class="meta">UID <?= e($identity['uid']) ?> · <a href="<?= e($identity['personal_site']) ?>" rel="noopener">reichi.com</a></p>
    </address>
  </div>
  <div class="contact__form">
    <?php if ($sent): ?>
      <p class="note" role="status">Die Anfrage ist eingegangen. Wenn der Mailversand auf diesem Server eingerichtet ist, liegt sie zusätzlich im Postfach.</p>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
      <p class="error" role="alert"><?= e($error) ?></p>
    <?php endforeach; ?>
    <?php if (!$sent): ?>
    <form method="post" action="/kontakt" class="form">
      <input type="hidden" name="_csrf" value="<?= e(App\Csrf::token()) ?>">
      <input type="hidden" name="started" value="<?= time() ?>">
      <p class="hp"><label>Website <input name="company_website" tabindex="-1" autocomplete="off"></label></p>
      <label>Anliegen
        <select name="topic" required>
          <?php foreach (contact_topics() as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= ($old['topic'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Name <input name="name" required maxlength="190" value="<?= e($old['name'] ?? '') ?>"></label>
      <label>E-Mail <input name="email" type="email" required maxlength="190" value="<?= e($old['email'] ?? '') ?>"></label>
      <?php if ($cart): ?>
        <fieldset>
          <legend>Rental-Anfrage</legend>
          <ul>
            <?php foreach ($cart as $line): ?>
              <li><?= e($line['name']) ?> × <?= (int) $line['qty'] ?></li>
            <?php endforeach; ?>
          </ul>
          <label>Von <input name="date_from" type="date"></label>
          <label>Bis <input name="date_to" type="date"></label>
        </fieldset>
      <?php endif; ?>
      <label>Nachricht <textarea name="message" required minlength="10" rows="6"><?= e($old['message'] ?? '') ?></textarea></label>
      <button class="btn btn-accent" type="submit">Senden</button>
    </form>
    <?php endif; ?>
  </div>
</section>
