<article class="section section--page prose-page">
  <div class="wrap">
    <header class="section__head">
      <p class="eyebrow">Music Services</p>
      <h1 class="section__title"><?= e(trim((string) ($page['title'] ?? '')) !== '' ? (string) $page['title'] : 'Rental') ?></h1>
    </header>
    <div class="prose-page__body">
      <div class="prose"><?= $page['body_html'] ?? '<p>Beschreibung noch zu ergänzen.</p>' ?></div>
      <aside class="prose-page__aside">
        <p class="eyebrow">Anfrage</p>
        <p>Die Anfrage ist unverbindlich. Es wird nichts reserviert, Verfügbarkeit und Konditionen kommen per Antwort.</p>
        <p class="actions"><a class="btn btn-accent" href="/kontakt?thema=rental">Rental anfragen</a></p>
      </aside>
    </div>
  </div>
</article>
