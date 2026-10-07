<?php
$cover = $catalog->cover($release);
$date = format_release_date(
    $release['release_year'] !== null ? (int) $release['release_year'] : null,
    $release['release_month'] !== null ? (int) $release['release_month'] : null,
    $release['release_day'] !== null ? (int) $release['release_day'] : null,
    (string) $release['release_date_precision']
);
$type = release_type_label($release['release_type'] ?? null);
$artistsLd = [];
foreach ($release['artists'] as $artist) {
    $artistsLd[] = ['@type' => 'MusicGroup', 'name' => $artist['name']];
}
$ld = [
    '@context' => 'https://schema.org',
    '@type' => 'MusicAlbum',
    'name' => $release['title'],
    'byArtist' => $artistsLd,
];
if ($release['release_date_precision'] === 'day' && $release['release_year'] && $release['release_month'] && $release['release_day']) {
    $ld['datePublished'] = sprintf('%04d-%02d-%02d', (int) $release['release_year'], (int) $release['release_month'], (int) $release['release_day']);
} elseif ($release['release_date_precision'] === 'month' && $release['release_year'] && $release['release_month']) {
    $ld['datePublished'] = sprintf('%04d-%02d', (int) $release['release_year'], (int) $release['release_month']);
} elseif ($release['release_year']) {
    $ld['datePublished'] = (string) (int) $release['release_year'];
}
?>
<article class="wrap section release">
  <p class="kicker"><a href="/releases">Releases</a></p>
  <div class="release-hero">
    <div class="cover lg">
      <?php if ($cover): ?>
        <img src="<?= e($cover['url']) ?>" alt="Cover: <?= e($release['title']) ?>" width="800" height="800">
      <?php else: ?>
        <span class="ph" aria-hidden="true"><?= e(mb_substr($release['title'], 0, 1)) ?></span>
      <?php endif; ?>
    </div>
    <div>
      <h1><?= e($release['title']) ?></h1>
      <p class="by lg">
        <?php foreach ($release['artists'] as $i => $artist): ?>
          <?php if ($i): ?>, <?php endif; ?>
          <?php if (($artist['status'] ?? '') === 'published'): ?>
            <a href="/artists/<?= e($artist['slug']) ?>"><?= e($artist['name']) ?></a>
          <?php else: ?><?= e($artist['name']) ?><?php endif; ?>
        <?php endforeach; ?>
      </p>
      <p class="meta"><?= e(trim($type . ($date ? ' · ' . $date : ''))) ?></p>
      <?php if ($release['formats']): ?>
        <ul class="formats">
          <?php foreach ($release['formats'] as $format): ?>
            <li><?= e($format['name']) ?><?= $format['details'] ? ' · ' . e($format['details']) : '' ?><?= $format['catalog_number'] ? ' · ' . e($format['catalog_number']) : '' ?><?= $format['upc'] ? ' · UPC ' . e($format['upc']) : '' ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($release['links']): ?>
        <p class="actions">
          <?php foreach ($release['links'] as $link): ?>
            <a class="btn btn-ghost" href="<?= e($link['url']) ?>" rel="noopener noreferrer"><?= e($link['label']) ?></a>
            <?php if (($link['provider'] ?? '') === 'spotify'): ?>
              <button class="btn btn-accent" type="button" data-embed="<?= e($link['url']) ?>">Player laden</button>
            <?php endif; ?>
          <?php endforeach; ?>
        </p>
        <div id="player-slot" class="player-slot" hidden></div>
      <?php endif; ?>
      <?php if ($cover && $cover['source'] === 'discogs'): ?>
        <p class="attr"><?php if ($cover['page']): ?><a href="<?= e($cover['page']) ?>" rel="noopener noreferrer">Cover und Daten: Discogs</a><?php else: ?>Cover: Discogs<?php endif; ?></p>
      <?php endif; ?>
    </div>
  </div>
  <?php if (trim(strip_tags($release['description_html'] ?? '')) !== ''): ?>
    <div class="prose"><?= $release['description_html'] ?></div>
  <?php endif; ?>
  <?php if ($release['tracks']): ?>
    <h2>Tracks</h2>
    <ol class="tracks">
      <?php foreach ($release['tracks'] as $track): ?>
        <li>
          <span><?= e($track['title']) ?></span>
          <?php if ($track['duration']): ?><span class="dur"><?= e($track['duration']) ?></span><?php endif; ?>
          <?php if ($track['isrc']): ?><span class="dur">ISRC <?= e($track['isrc']) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
  <?php if ($release['related']): ?>
    <h2>Weitere Releases</h2>
    <div class="grid">
      <?php foreach ($release['related'] as $rel): ?>
        <?php $release = $rel; $eager = false; include __DIR__ . '/partials/card.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</article>
<script type="application/ld+json" nonce="<?= e($GLOBALS['csp_nonce'] ?? '') ?>"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/embed.js"></script>
