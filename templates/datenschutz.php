<article class="wrap section prose-page">
  <h1>Datenschutz</h1>
  <div class="prose">
    <p>Verantwortlich: <?= e($identity['name']) ?>, <?= e($identity['street']) ?>, <?= e($identity['postal']) ?>, <?= e($identity['email']) ?>.</p>
    <h2>Hosting</h2>
    <p>Hosting-Anbieter: noch zu ergänzen. Beim Aufruf der Website verarbeitet der Server üblicherweise IP-Adresse, Zeitpunkt und die aufgerufene Adresse in Server-Logs.</p>
    <h2>Kontaktformular</h2>
    <p>Name, E-Mail, Nachricht, gewähltes Anliegen und, bei Rental, die Artikelliste werden gespeichert, um die Anfrage zu beantworten. Die IP-Adresse wird für höchstens die Missbrauchsbegrenzung mitgespeichert. Die Nachricht wird zusätzlich per E-Mail an <?= e($identity['email']) ?> gesendet, sofern der Serverversand funktioniert.</p>
    <h2>Sessions</h2>
    <p>Eine Session speichert die Rental-Anfrageliste und, im Adminbereich, die Anmeldung. Das Cookie ist ein Sitzungscookie, HttpOnly, SameSite=Lax.</p>
    <h2>Externe Inhalte</h2>
    <p>Spotify- oder andere Player werden nicht automatisch geladen. Ein Player startet erst nach einem Klick auf „Player laden“. Discogs-Cover werden nur angezeigt, solange der letzte Abgleich innerhalb des eingestellten Zeitfensters liegt, und sind als Discogs gekennzeichnet.</p>
    <h2>Cookies und Analyse</h2>
    <p>Es gibt kein Analyse- oder Werbe-Cookie. Rechtsgrundlagen im Einzelnen und eine Auftragsverarbeiterliste: noch zu ergänzen.</p>
  </div>
</article>
