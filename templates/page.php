<?php $eyebrow = ($current ?? '') === 'label' ? 'Music Services' : 'BleedingStar'; ?>
<article class="section section--page prose-page">
  <div class="wrap">
    <header class="section__head">
      <p class="eyebrow"><?= e($eyebrow) ?></p>
      <h1 class="section__title"><?= e($page['title'] ?? $title) ?></h1>
    </header>
    <div class="prose-page__body">
      <div class="prose"><?= $page['body_html'] ?? '<p>Inhalt noch zu ergänzen.</p>' ?></div>
      <aside class="prose-page__aside">
        <p class="eyebrow">Katalog</p>
        <p>Alles, was bisher bei BleedingStar erschienen ist, mit Cover, Tracks und Links zu den Plattformen.</p>
        <p class="actions"><a class="btn btn-accent" href="/releases">Releases ansehen</a> <a class="btn btn-ghost" href="/kontakt?thema=label">Anfrage</a></p>
      </aside>
    </div>
  </div>
</article>
