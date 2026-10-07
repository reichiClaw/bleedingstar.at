<article class="wrap section prose-page">
  <p class="kicker"><a href="/news">News</a></p>
  <h1><?= e($item['title']) ?></h1>
  <p class="meta"><?= e((string) $item['published_on']) ?></p>
  <div class="prose"><?= $item['body_html'] ?></div>
</article>
