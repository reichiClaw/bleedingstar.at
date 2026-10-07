<section class="hero wrap">
  <p class="kicker">Label · Production · Rental</p>
  <h1>Label, Ton und Equipment aus einer Hand.</h1>
  <p class="lede">BleedingStar ist das Label von Christian Reichinger und zugleich sein Rahmen für Live-Technik, Tourmanagement und Recording-Rental.</p>
  <p class="actions">
    <a class="btn btn-accent" href="/releases">Releases entdecken</a>
    <a class="btn btn-ghost" href="/production">Production &amp; Rental</a>
  </p>
</section>
<section class="wrap section">
  <div class="section-head">
    <h2>Neu im Katalog</h2>
    <a href="/releases">Alle Releases</a>
  </div>
  <div class="grid">
    <?php foreach ($releases as $i => $release): ?>
      <?php $eager = $i < 4; include __DIR__ . '/partials/card.php'; ?>
    <?php endforeach; ?>
  </div>
</section>
<section class="wrap section split">
  <article>
    <p class="kicker">Label</p>
    <h2>Vertrieb, Booking, Managing.</h2>
    <div class="prose"><?= $label['body_html'] ?? '' ?></div>
    <p><a class="text-link" href="/label">Zum Label</a></p>
  </article>
  <article>
    <p class="kicker">Production</p>
    <h2>Ton und Tour, nicht nur der Release.</h2>
    <p>Neben dem Label- und Publishing-Service arbeitet Reichinger weltweit als Live-Techniker und Tourmanager.</p>
    <p><a class="text-link" href="/production">Leistungen ansehen</a></p>
  </article>
</section>
