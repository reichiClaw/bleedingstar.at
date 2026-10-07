<article class="wrap section prose-page prose-page--legal">
  <header class="section__head"><p class="eyebrow">Rechtliches</p><h1 class="section__title">Impressum</h1></header>
  <div class="prose">
    <p><?= e($identity['name']) ?><br><?= e($identity['brand']) ?><br><?= e($identity['street']) ?><br><?= e($identity['postal']) ?><br>Österreich</p>
    <p>Telefon: <a href="tel:<?= e($identity['phone_href']) ?>"><?= e($identity['phone']) ?></a><br>
    E-Mail: <a href="mailto:<?= e($identity['email']) ?>"><?= e($identity['email']) ?></a><br>
    UID: <?= e($identity['uid']) ?></p>
    <p>Unternehmensform: noch zu ergänzen.<br>
    Firmenbuchnummer: noch zu ergänzen.<br>
    Kammerzugehörigkeit: noch zu ergänzen.</p>
    <p>Die Angaben zu Name, Anschrift, Telefon, E-Mail und UID stammen aus dem bisherigen Kontakt auf bleedingstar.at.</p>
  </div>
</article>
