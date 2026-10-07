<?php
/** @var ?array $featured */
/** @var array $releases */
/** @var array $artists */
/** @var int $total */
/** @var App\CatalogRepository $catalog */
$services = [
    [
        'index' => '01',
        'tag' => 'Label',
        'name' => 'Label &amp; Publishing',
        'text' => 'Vertrieb, Booking und Managing für Bands und Musikerinnen und Musiker – von der ersten Single bis zum Album, digital auf allen Plattformen.',
        'href' => '/label',
        'cta' => 'Zum Label',
    ],
    [
        'index' => '02',
        'tag' => 'Production',
        'name' => 'Ton &amp; Tour',
        'text' => 'Live-Technik, Recording und Tourmanagement. Neben dem Label arbeitet Christian Reichinger weltweit als Tontechniker und Tourmanager.',
        'href' => '/production',
        'cta' => 'Leistungen ansehen',
    ],
    [
        'index' => '03',
        'tag' => 'Rental',
        'name' => 'Equipment',
        'text' => 'Mobile Recording-Lösungen und Live-Equipment zum Ausleihen. Was verfügbar ist, steht auf der Rental-Seite – die Anfrage ist unverbindlich.',
        'href' => '/rental',
        'cta' => 'Rental ansehen',
    ],
];
?>
<?php if ($featured): ?>
  <?php
    $cover = $catalog->cover($featured);
    $date = format_release_date(
        $featured['release_year'] !== null ? (int) $featured['release_year'] : null,
        $featured['release_month'] !== null ? (int) $featured['release_month'] : null,
        $featured['release_day'] !== null ? (int) $featured['release_day'] : null,
        (string) $featured['release_date_precision']
    );
    $type = release_type_label($featured['release_type'] ?? null);
    $tracks = $featured['tracks'] ?? [];
    $trackCount = count($tracks);
    $catalogNumber = '';
    foreach ($featured['formats'] ?? [] as $format) {
        if (!empty($format['catalog_number'])) {
            $catalogNumber = (string) $format['catalog_number'];
            break;
        }
    }
    $streams = array_values(array_filter($featured['links'] ?? [], static fn (array $l): bool => preg_match('#^https?://#i', (string) $l['url']) === 1));
    $description = excerpt($featured['description_html'] ?? '', 220);
    $facts = array_filter([
        $type,
        $date,
        $trackCount ? ($trackCount === 1 ? '1 Track' : $trackCount . ' Tracks') : '',
    ]);
  ?>
  <section class="hero release-hero-home" aria-labelledby="hero-title">
    <div class="hero__spot" aria-hidden="true"></div>
    <div class="wrap hero__inner">
      <div class="hero__copy">
        <p class="eyebrow hero__eyebrow"><?= (int) $featured['featured'] === 1 ? 'Featured Release' : 'Neues Release' ?></p>
        <h1 class="hero__title" id="hero-title"><?= e($featured['title']) ?></h1>
        <ul class="hero__roles" aria-label="Artists">
          <?php foreach ($featured['artists'] as $artist): ?>
            <li>
              <?php if (($artist['status'] ?? 'published') === 'published'): ?>
                <a href="/artists/<?= e($artist['slug']) ?>"><?= e($artist['name']) ?></a>
              <?php else: ?><?= e($artist['name']) ?><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($facts): ?><p class="hero__facts"><?= e(implode(' · ', $facts)) ?></p><?php endif; ?>
        <?php if ($description !== ''): ?>
          <p class="hero__intro"><?= e($description) ?></p>
        <?php elseif ($trackCount <= 1): ?>
          <?php $names = implode(', ', array_column($featured['artists'], 'name')); ?>
          <p class="hero__intro"><?= e(($type !== '' ? $type : 'Release') . ($names !== '' ? ' von ' . $names : '') . ($date !== '' ? ', erschienen am ' . $date : '')) ?> bei BleedingStar.</p>
        <?php else: ?>
          <ol class="hero__tracks">
            <?php foreach (array_slice($tracks, 0, 4) as $track): ?>
              <li><span><?= e($track['title']) ?></span><?php if ($track['duration']): ?><span class="dur"><?= e($track['duration']) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
            <?php if ($trackCount > 4): ?><li class="hero__tracks-more">+ <?= $trackCount - 4 ?> weitere</li><?php endif; ?>
          </ol>
        <?php endif; ?>
        <div class="hero__actions">
          <a class="btn btn-accent" href="/releases/<?= e($featured['slug']) ?>">Zum Release <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
          <?php foreach (array_slice($streams, 0, 3) as $link): ?>
            <a class="btn btn-ghost" href="<?= e($link['url']) ?>" rel="noopener noreferrer"><?= e($link['label']) ?></a>
          <?php endforeach; ?>
        </div>
        <p class="hero__more"><a href="/releases">Alle <?= (int) $total ?> Releases im Katalog</a></p>
      </div>
      <figure class="hero__figure">
        <a class="hero__cover" href="/releases/<?= e($featured['slug']) ?>" tabindex="-1" aria-hidden="true">
          <?php if ($cover): ?>
            <img class="hero__img" src="<?= e($cover['url']) ?>" alt="" width="1000" height="1000" fetchpriority="high">
          <?php else: ?>
            <span class="ph hero__img"><?= e(mb_substr($featured['title'], 0, 1)) ?></span>
          <?php endif; ?>
        </a>
        <figcaption class="hero__caption">
          <span><?php if ($catalogNumber !== ''): ?><?= e($catalogNumber) ?><?php elseif ($cover && $cover['external'] && $cover['attribution']): ?><?= e($cover['attribution']) ?><?php else: ?>Cover<?php endif; ?></span>
          <span class="hero__caption-meta"><span class="hero__dot" aria-hidden="true"></span><?= $date !== '' ? e($date) : 'BleedingStar' ?></span>
        </figcaption>
      </figure>
    </div>
  </section>
<?php else: ?>
  <section class="hero" aria-labelledby="hero-title">
    <div class="wrap hero__inner">
      <div class="hero__copy">
        <p class="eyebrow hero__eyebrow">Label · Production · Rental</p>
        <h1 class="hero__title" id="hero-title">BleedingStar</h1>
        <p class="hero__intro">Label, Ton und Equipment aus einer Hand.</p>
        <div class="hero__actions"><a class="btn btn-accent" href="/releases">Releases entdecken</a></div>
      </div>
    </div>
  </section>
<?php endif; ?>

<section class="section catalog" aria-labelledby="catalog-title">
  <div class="wrap">
    <header class="section__head section__head--split">
      <div>
        <p class="eyebrow">Katalog</p>
        <h2 class="section__title" id="catalog-title">Neu im Katalog</h2>
      </div>
      <p class="section__note"><?= (int) $total ?> Veröffentlichungen, von der ersten Single bis zum aktuellen Album. <a href="/releases">Alle Releases</a></p>
    </header>
    <div class="grid">
      <?php foreach ($releases as $i => $release): ?>
        <?php $eager = $i < 4; include __DIR__ . '/partials/card.php'; ?>
      <?php endforeach; ?>
    </div>
    <ul class="chips chips--types" aria-label="Nach Typ filtern">
      <li><a href="/releases?type=album">Alben</a></li>
      <li><a href="/releases?type=ep">EPs</a></li>
      <li><a href="/releases?type=single">Singles</a></li>
      <li><a href="/releases?type=compilation">Compilations</a></li>
      <li><a href="/releases?sort=az">A–Z</a></li>
    </ul>
  </div>
</section>

<?php if ($artists): ?>
<section class="section roster" aria-labelledby="roster-title">
  <div class="wrap">
    <header class="section__head">
      <p class="eyebrow">Artists</p>
      <h2 class="section__title" id="roster-title">Wer bei BleedingStar veröffentlicht.</h2>
    </header>
    <ul class="roster__list">
      <?php foreach ($artists as $artist): ?>
        <li><a href="/artists/<?= e($artist['slug']) ?>"><?= e($artist['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <p class="section__more"><a class="text-link" href="/artists">Alle Artists</a></p>
  </div>
</section>
<?php endif; ?>

<section class="section services" aria-labelledby="services-title">
  <div class="wrap">
    <header class="section__head section__head--split">
      <div>
        <p class="eyebrow">Music Services</p>
        <h2 class="section__title" id="services-title">Label, Ton und Equipment aus einer Hand.</h2>
      </div>
      <p class="section__note">BleedingStar ist das Label von Christian Reichinger und zugleich sein Rahmen für Live-Technik, Tourmanagement und Recording-Rental.</p>
    </header>
    <ol class="services__list">
      <?php foreach ($services as $service): ?>
        <li class="services__item">
          <div class="services__head">
            <span class="services__index" aria-hidden="true"><?= e($service['index']) ?></span>
            <span class="services__tag"><?= e($service['tag']) ?></span>
          </div>
          <h3 class="services__name"><?= $service['name'] ?></h3>
          <p class="services__text"><?= e($service['text']) ?></p>
          <a class="services__link" href="<?= e($service['href']) ?>"><?= e($service['cta']) ?> <svg class="btn__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section class="section hire" aria-labelledby="hire-title">
  <div class="wrap hire__inner">
    <div>
      <p class="eyebrow">Anfrage</p>
      <h2 class="section__title" id="hire-title">Release geplant, Tour in Sicht, Equipment gesucht?</h2>
      <p class="section__note">Eine kurze Nachricht reicht. Rückmeldung kommt direkt von Christian Reichinger.</p>
    </div>
    <div class="hire__actions">
      <a class="btn btn-accent" href="/kontakt">Anfrage senden</a>
      <a class="btn btn-ghost" href="mailto:<?= e($identity['email'] ?? 'reichi@bleedingstar.at') ?>"><?= e($identity['email'] ?? 'reichi@bleedingstar.at') ?></a>
    </div>
  </div>
</section>
