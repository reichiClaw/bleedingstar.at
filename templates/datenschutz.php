<article class="wrap section prose-page prose-page--legal">
  <header class="section__head"><p class="eyebrow">Rechtliches</p><h1 class="section__title">Datenschutz</h1></header>
  <div class="prose">
    <p>Verantwortlich: <?= e($identity['name']) ?>, <?= e($identity['brand']) ?>, <?= e($identity['street']) ?>, <?= e($identity['postal']) ?>, <a href="mailto:<?= e($identity['email']) ?>"><?= e($identity['email']) ?></a>.</p>
    <h2>Hosting</h2>
    <p>Die Website liegt bei World4You Internet Services GmbH, Hafenstraße 35, 4020 Linz, Österreich. Beim Aufruf verarbeitet der Server IP-Adresse, Zeitpunkt, aufgerufene Adresse, Browserkennung und Verweisadresse in Server-Logs. Rechtsgrundlage ist das berechtigte Interesse an einem sicheren und stabilen Betrieb (Art. 6 Abs. 1 lit. f DSGVO). World4You verarbeitet diese Daten als Auftragsverarbeiter.</p>
    <h2>Kontaktformular</h2>
    <p>Name, E-Mail, Nachricht und das gewählte Anliegen werden gespeichert, um die Anfrage zu beantworten (Art. 6 Abs. 1 lit. b DSGVO). Die IP-Adresse wird nur zur Missbrauchsbegrenzung mitgespeichert. Die Nachricht wird zusätzlich per E-Mail an <?= e($identity['email']) ?> gesendet. Anfragen werden gelöscht, sobald sie erledigt sind und keine gesetzliche Aufbewahrungspflicht besteht.</p>
    <h2>Sessions</h2>
    <p>Ein Sitzungscookie (<code>bsid</code>) wird nur gesetzt, wenn es gebraucht wird: für eine Rental-Anfrage und im Adminbereich für die Anmeldung. Es ist HttpOnly, SameSite=Lax und gilt nur für die Dauer der Sitzung. Es gibt kein Analyse- oder Werbe-Cookie.</p>
    <h2>Externe Inhalte</h2>
    <p>Beim Aufruf der Seiten werden keine Daten an Dritte übertragen. Schriften und Bilder liegen auf diesem Server. Cover und Angaben aus den Katalogen von Discogs und Deezer sowie Links zu Apple Music werden serverseitig von unseren Jobs abgerufen und von hier ausgeliefert; beim Besuch der Seite findet kein Abruf bei diesen Diensten statt. Die Quelle eines Covers ist auf der Release-Seite angegeben. Ein Spotify-Player wird erst geladen, wenn auf „Player laden“ geklickt wird; ab diesem Zeitpunkt gilt die <a href="https://www.spotify.com/at/legal/privacy-policy/">Datenschutzerklärung von Spotify</a>.</p>
    <h2>Rechte</h2>
    <p>Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit und Widerspruch können jederzeit unter <?= e($identity['email']) ?> geltend gemacht werden. Beschwerden nimmt die Österreichische Datenschutzbehörde entgegen.</p>
  </div>
</article>
