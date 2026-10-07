<section class="wrap section">
  <div class="section-head"><h1>News</h1></div>
  <p class="note">Archiv der redaktionellen Beiträge bis 2016. Spätere Einträge im alten System waren Spam und sind nicht übernommen.</p>
  <div class="news-list">
    <?php foreach ($items as $item): ?>
      <a href="/news/<?= e($item['slug']) ?>">
        <time><?= e((string) $item['published_on']) ?></time>
        <strong><?= e($item['title']) ?></strong>
      </a>
    <?php endforeach; ?>
  </div>
</section>
