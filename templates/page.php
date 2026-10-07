<article class="wrap section prose-page">
  <h1><?= e($page['title'] ?? $title) ?></h1>
  <div class="prose"><?= $page['body_html'] ?? '<p>Inhalt noch zu ergänzen.</p>' ?></div>
  <?php if (!empty($cta)): ?>
    <p class="actions"><a class="btn btn-accent" href="/kontakt?thema=production">Projekt anfragen</a> <a class="btn btn-ghost" href="/rental">Rental ansehen</a></p>
  <?php endif; ?>
</article>
