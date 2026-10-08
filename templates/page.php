<?php $eyebrow = ['label' => 'Music Services', 'production' => 'Music Services'][$current ?? ''] ?? 'BleedingStar'; ?>
<article class="section section--page prose-page">
  <div class="wrap">
    <header class="section__head">
      <p class="eyebrow"><?= e($eyebrow) ?></p>
      <h1 class="section__title"><?= e($page['title'] ?? $title) ?></h1>
    </header>
    <div class="prose-page__body">
      <div class="prose"><?= $page['body_html'] ?? '<p>Inhalt noch zu ergänzen.</p>' ?></div>
      <aside class="prose-page__aside">
        <?php if (!empty($cta)): ?>
          <p class="eyebrow">Anfrage</p>
          <p>Projekt, Tour oder Aufnahme in Planung? Eine kurze Nachricht reicht.</p>
          <p class="actions"><a class="btn btn-accent" href="/kontakt?thema=production">Projekt anfragen</a> <a class="btn btn-ghost" href="/rental">Rental ansehen</a></p>
        <?php else: ?>
          <p class="eyebrow">Katalog</p>
          <p>Alles, was bisher bei BleedingStar erschienen ist, mit Cover, Tracks und Links zu den Plattformen.</p>
          <p class="actions"><a class="btn btn-accent" href="/releases">Releases ansehen</a> <a class="btn btn-ghost" href="/kontakt?thema=label">Anfrage</a></p>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</article>
