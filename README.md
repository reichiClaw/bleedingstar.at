# BleedingStar

Öffentliche Website für das Label, Production und Rental von BleedingStar Music Services. Der Katalog kommt aus MySQL. Normale Seitenaufrufe fragen keine Musik-API ab.

PHP rendert die Seiten. Es gibt kein Laravel, Symfony, WordPress, React oder Vue und keinen Node-Prozess, Docker oder Redis.

## Mindestumgebung

- PHP **8.2** oder neuer. Entwickelt mit PHP 8.3.6, zusätzlich mit PHP 8.5.11 geprüft (Tests, alle Seiten, Admin, Jobs: keine Deprecation-Meldungen). Keine Syntax, die nur in 8.3 oder neuer existiert.
- Erweiterungen: `pdo_mysql`, `mbstring`, `curl`, `gd`, `json`, `fileinfo`
- MySQL 8 oder MariaDB 10.6+ mit `utf8mb4`
- Apache mit `mod_rewrite` oder nginx mit einer Weiterleitung auf `public/index.php`
- Für das Kontaktformular die PHP-Funktion `mail()`, oder ein Host, der sie an einen SMTP-Dienst reicht
- Document Root ist `public/`. `config/`, `src/`, `bin/`, `storage/` und `database/` bleiben außerhalb davon

Der Produktionsserver braucht keinen Paketmanager. Es gibt keine Composer-Abhängigkeit.

## Gestaltung

Die drei erreichbaren Referenzseiten wurden vor dem Entwurf geladen:

- [reichi.com](https://reichi.com/) ist dunkel (`#0c0c0f`), mit warmer Schrift (`#f3efe7`), vermillion Akzent und einer knappen Mono-Navigation.
- [reichi.it](https://reichi.it/) ist hell, mit türkisem Akzent. Verwandt in der Zurückhaltung, nicht in der Fläche.
- [rstream.at](https://rstream.at/) ist dunkel mit violettem Akzent. Die im Brief geschriebene Adresse `htts://rstream.at` ist ein Tippfehler; `https://rstream.at` antwortet.

BleedingStar bleibt in dieser Familie: dunkle Fläche, warme Schrift, viel Luft. Die eigene Farbe ist Crimson `#e23d4f`, nicht das Vermillion von reichi.com. Die Schriften sind die des bisherigen Themes Replay: Oswald für Überschriften, Navigation und Buttons (`themex_heading_font`) und Open Sans für den Fließtext (`themex_content_font`). Beide liegen lokal unter der SIL Open Font License (`public/assets/fonts/OFL.txt`). Das weiße Logo aus dem bisherigen Auftritt liegt auf dem dunklen Kopf. Der Katalog ist ein Cover-Raster, kein Dashboard.

Übernommen wurden nur Inhalte aus `data/content.json`: der WordPress-Export von bleedingstar.at. Spam-Beiträge aus 2020 und 2024, Zugangsdaten und die kompromittierten Plugins sind nicht enthalten. Entwürfe bleiben Entwürfe. Der Platzhalter „Live Recording Info“ ist nicht veröffentlicht. Bei RME Digiface Dante steht im Archiv nur der Titel; die Seite sagt das.

## Installation

1. Code so ablegen, dass der Webserver nur `public/` ausliefert.
2. Datenbank anlegen, Zeichensatz `utf8mb4`, Collation `utf8mb4_unicode_ci`.
3. `config/config.example.php` nach `config/config.php` kopieren und Zugangsdaten, `base_url` und Mailadressen eintragen. `config/config.php` wird nicht versioniert.
4. Schema und Archiv importieren:

```bash
php bin/install.php --import
php bin/create-admin.php redaktion@example.com 'ein-langes-passwort'
```

`--import` liest `data/content.json`, legt Künstler, Releases, Weiterleitungen und die drei bestätigten Mietgeräte an und lädt vorhandene Cover von `bleedingstar.at` nach `storage/uploads/`. Ein zweiter Lauf überschreibt keine Felder, die im Admin als redaktionell gesperrt wurden. News, Events, Radio und Downloads der alten Seite werden nicht übernommen; sie bleiben nur im Export erhalten. Datenbanken, die vor dieser Änderung eingerichtet wurden, haben die Tabellen `news`, `events` und `documents` sowie die Seite `radio` noch; `php bin/remove-legacy-content.php` (oder `/jobs/run?token=…&task=remove-legacy-content`, `--dry-run` bzw. `&dry=1` zählt nur) entfernt alles samt der Weiterleitungen nach `/news/…`, `/events`, `/radio` und `/downloads`. Der Lauf ist wiederholbar und steht wie die Importe in `sync_runs`.

5. Schreibrechte für den Webserver-Benutzer:

```text
storage/logs
storage/locks
storage/cache
storage/uploads
storage/jobs
```

6. Apache: Document Root `public/`. Die mitgelieferte `public/.htaccess` leitet auf `index.php` und verbietet jede andere PHP-Datei. nginx zum Beispiel:

```nginx
root /var/www/bleedingstar/public;
index index.php;
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}
```

Medien unter `/media/…` laufen durch PHP, mit Pfadprüfung und einer Allowlist für Bilder und PDF. In `storage/uploads` abgelegte Dateien werden nicht als PHP ausgeführt.

### Produktion bei World4You

Der Server ist ein World4You-Webhosting (Apache, FTPS, kein SSH). FTP-Wurzel und Web-Wurzel sind dieselbe Ebene (`/home/.sites/288/site940/web`). Daraus ergibt sich diese Ablage:

```text
/                 ← public/        (index.php, .htaccess, assets)
/app/src          ← src/
/app/templates    ← templates/
/app/config       ← config/        (config.php wird nie überschrieben)
/app/bin          ← bin/
/app/database     ← database/
/app/data         ← data/
/app/storage      ← storage/       (logs, locks, jobs, cache werden nie überschrieben; uploads nur ergänzt)
/app/.htaccess    ← deploy/app.htaccess   (alles verboten)
/wp-content/uploads/.htaccess ← deploy/wp-uploads.htaccess (nur Bilder, PDF, Audio; kein PHP)
```

`public/index.php` erkennt `app/` neben sich und findet so `src/` und `config/`. Die Wurzel-`.htaccess` beantwortet `/app/…`, `/storage/…` und Dotfiles mit 404, leitet auf https um und erlaubt nur `index.php` als PHP.

Voraussetzungen, die nur im World4You-Kundenbereich erledigt werden können:

1. **PHP auf 8.2 oder neuer stellen.** Der Server liefert standardmäßig PHP 7.3. `AddHandler` in `.htaccess` funktioniert dort nicht (PHP würde als Text ausgeliefert). Bis zur Umstellung zeigt `index.php` eine Wartungsseite mit Status 503.
2. **Neue MySQL-Datenbank anlegen** (utf8mb4). Die alte WordPress-Datenbank ist MySQL 5.1 und ungeeignet. Zugangsdaten gehören in `/app/config/config.php`.
3. **Cron**: entweder `php /home/.sites/288/site940/web/app/bin/sync-releases.php` alle vier Stunden oder, wenn der Cron nur Adressen aufrufen kann, die eine `/jobs/run`-Adresse aus dem Abschnitt „Cron per URL“ (`cron_token` in `config.php` nötig). Jeder Cron-Lauf synchronisiert alle Quellen.

Ersteinrichtung ohne Shell: In `config.php` ein `setup_token` mit mindestens 32 zufälligen Zeichen eintragen, dann `https://www.bleedingstar.at/setup?token=…` aufrufen. Die Seite legt die Tabellen an, spielt auf Wunsch `data/content.json` ein und erstellt den Admin-Zugang. Danach entsteht `storage/install.done` und die Seite ist abgeschaltet; der Token kann aus der Config entfernt werden. Solange `install.done` fehlt, antworten alle anderen Adressen mit 503.

#### Deploy

`tools/deploy-ftp.py` (nur Python-Standardbibliothek) liest `bleedingstar_FTP_HOST`, `bleedingstar_FTP_USER` und `bleedingstar_FTP_PASS` aus der Umgebung.

```bash
python3 tools/deploy-ftp.py list            # Server-Inventar, eine Ebene bei großen Fremdordnern
python3 tools/deploy-ftp.py diff            # was sich ändern würde (Größe, dann SHA-256)
python3 tools/deploy-ftp.py deploy --dry-run
python3 tools/deploy-ftp.py deploy --config /pfad/zur/config.production.php   # Config nur, wenn sie fehlt
```

Regeln des Werkzeugs: erst Server listen, gleich große Dateien per SHA-256 vergleichen, `config.php` und Laufzeitdaten nie überschreiben, nichts löschen (Dateien, die nur am Server liegen, werden aufgelistet), Upload als `name.uploading~` plus Umbenennen, statische Dateien zuerst, PHP zuletzt, Größen prüfen. Pure-FTPd dort akzeptiert passive Datenverbindungen nur von der IP der Steuerverbindung; aus Cloud-Umgebungen mit NAT-Pool braucht es die Wiederhol-Logik dieses Clients, Standard-Clients hängen.

## Katalog

`/releases` filtert nach Text, Künstler, Jahr, Typ und Sortierung. Die Filter stehen in der URL und funktionieren ohne JavaScript. Mit JavaScript ersetzt ein Abruf nur die Ergebnisliste. Es werden 24 Releases pro Seite geladen.

Jede Veröffentlichung hat eine eigene Adresse `/releases/{slug}`. Alte Wurzel-Adressen wie `/supervision` oder `/service` stehen in der Tabelle `redirects`.

Daten stehen in getrennten Tabellen: eigene Redaktion (`artists`, `releases`, `tracks`, `pages`) und Anbieterbezüge (`external_ids`, `provider_records`, `import_reviews`). Der Discogs-Lauf speichert die komplette API-Antwort und lädt das größte von der API ausgelieferte Cover nach `storage/uploads/covers/discogs/`. Das ist das Feld `uri` (bei diesem Label typischerweise 600 Pixel an der langen Kante). Größere Adressen derselben CDN-Datei antworten mit 403 und werden nicht verwendet. Ist das Bild größer als 640 Pixel, entsteht daraus eine kleinere Datei für das Raster. Die Seiten binden nur diese lokalen Dateien ein. Eigene Archiv-Cover und Uploads bleiben vorrangig. Der vierstündliche Job aktualisiert die gespeicherten Discogs-Daten.

Ein unvollständiges Datum bleibt unvollständig. Ein bekanntes Jahr wird nicht zum 1. Januar. UPC und ISRC sind Zeichenketten. ISRC hängt am Track.

Der Katalog behauptet keine Vollständigkeit. Er ist das bisherige Archiv plus das, was ein späterer Import nach Prüfung übernimmt.

## Quellen: Discogs, Deezer, Apple Music und eigene Daten

`php bin/sync-releases.php` geht in einem Lauf alle Quellen durch: Discogs, Deezer, die Apple-Music-Verknüpfung und die Auffrischung des Discogs-Caches (Payload und Cover älter als `max_age_hours`). Jeder Lauf beginnt mit einer anderen Quelle (`storage/jobs/rotation`), damit unter einem knappen Zeitbudget jede Quelle an die Reihe kommt. Jede Quelle hat ihre eigene Fehlerbehandlung; `--source=discogs|deezer|apple|cache` beschränkt den Lauf auf eine. Für alle gilt: nur Releases mit dem Label aus der Allow-Liste, UPC verknüpft ein vorhandenes Release; derselbe Künstler mit demselben Titel (Groß-/Kleinschreibung, Satzzeichen, Akzente, ein „ - Single“-Zusatz und ein vorangestellter Künstlername im Titel zählen nicht) wird automatisch verknüpft und als erledigter Prüffall protokolliert; nur unscharfe oder mehrdeutige Treffer landen offen in `import_reviews` und werden im Admin entschieden; redaktionell gesperrte Felder bleiben unangetastet, es wird nie gelöscht. Ein erneuter Archiv-Import (`bin/install.php --import`) setzt Datum, Typ und Tracks, die ein Anbieterlauf ergänzt hat, nicht zurück.

### Deezer

Deezer führt jede digital vertriebene Veröffentlichung mit Labelname, UPC, exaktem Datum, Typ (Single, EP, Album), Tracks mit ISRC und einem großen Cover. Die öffentliche API braucht keine Zugangsdaten; der Adapter hält etwa sieben Anfragen pro Sekunde ein. Gesucht wird `label:"BleedingStar Records"` (`deezer.label_names`, Standard sind die Discogs-Labelnamen); jedes Album wird einzeln geladen und nur übernommen, wenn das Feld `label` exakt passt.

Ein per UPC oder Prüffall verknüpftes Release bekommt, was ihm fehlt: das genaue Datum, wenn bisher nur das Jahr bekannt war (und das Jahr übereinstimmt), den Typ, die Tracks samt ISRC, das Format „Digital“ mit UPC, den Deezer-Link und ein Cover nur dann, wenn noch keines da ist. Exakt gleicher Künstler und Titel (siehe oben; ein führendes „The“ beim Künstler zählt nicht) wird automatisch verknüpft. Werden zusätzlich Klammerzusätze und Endungen wie „EP“, „Single“ oder „Radio Edit“ ignoriert und passt es erst dann („High Tension (In Stereo)“ zu „High Tension“, „Pandemia EP“ zu „Pandemia“), entsteht nur ein Prüffall. Ebenso, wenn dem Release schon ein anderes Deezer-Album zugeordnet ist: Zwei Deezer-Alben sind nie dasselbe Release.

Cover landen unter `storage/uploads/covers/deezer/` (die CDN liefert bei Anfrage von 1800 px die größte vorhandene Datei, meist 1200 oder 1400 px), kleinere Rasterdateien entstehen wie bei Discogs. Genres aus Deezer erscheinen auf der Release-Seite.

### Apple Music

Für jedes Release mit UPC, das noch keinen Apple-Link hat, fragt der Lauf die iTunes-Lookup-API (`country=at`, ohne Zugangsdaten, höchstens 40 Abfragen pro Lauf im Abstand von gut drei Sekunden) und speichert den Link zur Album-Seite. Die Antwort liegt in `provider_records` (`apple`, `upc`); ein UPC ohne Treffer wird frühestens nach 30 Tagen erneut gefragt.

### Discogs

Der Adapter spricht nur `https://api.discogs.com` an. Die Label-ID ist **316841**. Ein Release wird nur übernommen, wenn ein Label aus `allow_label_ids` oder `allow_label_names` daran hängt. Gleicher Titel allein führt nicht zusammen. Dieselbe normalisierte Kombination aus Künstler und Titel wird mit dem einen passenden Archiv-Release verknüpft; passen mehrere, entsteht ein Prüffall. Eine UPC kann ein vorhandenes Release verknüpfen. Mehrere Discogs-Ausgaben mit derselben Master-ID werden eine Kachel plus Zeilen in `release_formats`.

Ohne Token liegt der Abstand bei 2,5 Sekunden (unter dem öffentlichen Richtwert von etwa 25 Anfragen pro Minute). Mit Token bei 1,05 Sekunden. `429` mit `Retry-After` wird begrenzt wiederholt. `429` ohne `Retry-After` gilt als erschöpftes Kontingent und beendet den Lauf mit Exit-Code 3. Ein leerer oder fehlgeschlagener Abruf löscht keine vorhandenen Releases.

```bash
php bin/sync-releases.php --dry-run --max-pages=1
php bin/sync-releases.php
php bin/sync-releases.php --source=deezer
php bin/refresh-provider-cache.php   # nur der Discogs-Cache, sonst Teil von sync-releases
```

Exit-Codes: `0` in Ordnung, `1` Fehler, `2` anderer Lauf hält die Sperre, `3` Kontingent. Beide Jobs teilen sich `storage/locks/catalog.lock`.

Ein einzelner unauthentifizierter Abruf von `/labels/316841/releases?per_page=1&page=1` am 7. Oktober 2026 antwortete mit `pagination.items = 18`. Ein vollständiger Live-Import wurde nicht ausgeführt. Der sichtbare Katalog ist der Archivimport.

Spotify ist in der Beispielkonfiguration aus; die Spotify-API verlangt eine registrierte App mit Client-ID und Secret und ist deshalb nicht angebunden. Manuell gesetzte Spotify-Links funktionieren ohne API. Der Player wird erst nach „Player laden“ eingebettet, nicht über dem Cover.

Eigene Releases, die in öffentlichen Datenbanken fehlen:

```bash
php bin/import-csv.php database/fixtures/own-releases.example.csv
```

Spalten: `title,artist,year,month,day,type,upc,spotify_url`. Derselbe Lauf legt keine zweite Zeile an, wenn UPC oder die Kombination aus Titel und erstem Künstler schon existiert. Auch das ist kein Vollständigkeitsbeleg.

MusicBrainz kennt das Label (`4bce058d-84a7-4511-91a3-385eb34a15ff`), führt aber nur fünf Releases, die alle auch bei Deezer stehen; es ist nicht angebunden.

## Cron

Gewünschte Planungszeitzone: **Europe/Vienna**. Wenn der Cron-Dienst in UTC läuft, `CRON_TZ` setzen. Sonst verschiebt sich 03:15 zwischen Winter- und Sommerzeit.

```cron
CRON_TZ=Europe/Vienna
20 */4 * * * /usr/bin/php /var/www/bleedingstar/bin/sync-releases.php >> /var/www/bleedingstar/storage/logs/sync.log 2>&1
```

Ein Job genügt: `sync-releases` enthält den Import aller Quellen und die Cache-Auffrischung. Pfade und das PHP-Binary an den Host anpassen. Der Web-Button im Admin schreibt nur `storage/jobs/sync.request`. Der nächste Lauf von `bin/sync-releases.php` (oder von `/jobs/run`) entfernt die Datei und arbeitet den Import ab. Ein normaler Webrequest startet den Import nicht.

### Cron per URL

Hosting ohne Shell (World4You „Web-Cronjobs“) kann nur Adressen aufrufen. Dafür gibt es `/jobs/run`, abgesichert mit `cron_token` in `config.php` (mindestens 32 zufällige Zeichen; `php -r 'echo bin2hex(random_bytes(24));'`). Ohne oder mit falschem Token antwortet die Adresse mit 404. Ein Aufruf synchronisiert alle Quellen (Discogs, Deezer, Apple Music, Discogs-Cache) und bleibt dabei innerhalb der Laufzeitgrenze (bei World4You gelten für Web- und Cron-Aufrufe `max_execution_time` 180 s und `memory_limit` 512 M); die Startquelle wechselt von Lauf zu Lauf:

```text
https://www.bleedingstar.at/jobs/run?token=…
```

Ein Web-Cronjob alle vier Stunden reicht. Wer die Quellen lieber getrennt plant, hängt `&source=discogs|deezer|apple|cache` an; dann erledigt ein Aufruf nur diese Quelle.

Die Antwort ist eine Textzeile wie in der CLI (`sync-releases run created=… updated=… reviews=…`), Status 200; 409, wenn ein anderer Lauf die Sperre hält; 500 bei einem Fehler. `&dry=1` zählt nur. Jeder Lauf steht wie die CLI-Läufe in `sync_runs` und im Admin. `&task=remove-legacy-content` führt statt des Imports die einmalige Bereinigung der alten Inhalte (News, Events, Radio, Downloads) aus (siehe Installation); andere Werte für `task` antworten mit 404.

Zeitbudget: Jede Quelle hört von sich aus auf, neue Einträge anzufassen, sobald `max_execution_time` minus 30 Sekunden erreicht ist (`job_time_budget` in `config.php` überschreibt das; `0` hebt es auf, im Shell-Cron ohne Limit gibt es keines). Die Meldung lautet dann `time budget reached, next run continues`; der nächste Lauf geht die Liste erneut durch, bereits bekannte Einträge sind schnell. Speicher: Cover werden einzeln geladen und verkleinert, 512 M reichen weit.

## Admin

`/admin` verlangt ein Passwort aus `password_hash`. Acht Fehlversuche pro IP innerhalb von 15 Minuten werden abgewiesen. Formulare nutzen CSRF-Tokens. Sessions heißen `bsid`, sind HttpOnly und SameSite=Lax, bei HTTPS zusätzlich Secure.

Pflegbar sind Release-Status, Hervorhebung, Künstlerzuordnung, Cover-Upload, Streaming-Links, Künstlertexte, die Seiten Label und Production sowie die Mietartikel. Speichern setzt `editorial_locked`, damit der nächste Import diese Felder nicht leert. Unscharfe oder mehrdeutige Treffer der Quellen liegen unter Prüfung.

## Kontakt, Rental, Rechtliches

Das Formular unterscheidet Label, Production, Rental und allgemeine Anfrage. Ein Honeypot, eine kurze Mindestzeit und höchstens fünf gespeicherte Anfragen pro IP und Stunde bremsen Missbrauch. Die Anfrage wird immer in `inquiries` gespeichert. `mail_status` ist `sent` oder `stored`, je nachdem ob `mail()` angenommen hat.

Rental zeigt die Textbeschreibung aus dem Archiv, derzeit ohne Geräteauflistung. Die Anfrage ist unverbindlich. Preise erscheinen nur, wenn sie gepflegt und als öffentlich markiert sind.

Impressum und Datenschutz nennen die bestätigten Angaben: Christian Reichinger, BleedingStar Music Services, Maria Aich 3, 4971 Aurolzmünster, UID ATU 67362668, Telefon und E-Mail. Unternehmensform, Firmenbuch, Kammer, Hosting-Anbieter und die Rechtsgrundlagen im Einzelnen sind als **noch zu ergänzen** markiert.

## Tests

```bash
php tests/run.php
```

Der Lauf prüft Filter, Pagination, Datumsgenauigkeit, doppelte External-IDs, Rollback, lokale Cover statt Discogs-Hotlinks, die Ableitung der 640-Pixel-Datei, den Erhalt gesperrter Texte, den Fixture-Import, die Sperre und den wiederholbaren CSV-Import. Fixture-Zeilen werden danach gelöscht.

## Sicherung

Sichern: die MySQL-Datenbank und `storage/uploads`. `config/config.php` separat, nicht im Repository. Wiederherstellen: Code ausspielen, `config.php` zurücklegen, Datenbank einspielen, Upload-Verzeichnis an denselben Ort, Schreibrechte setzen. Ein Import ist wiederholbar und ersetzt keine gesperrten redaktionellen Felder.

## Inhalt des Exports

`data/content.json` bleibt die Quelle des Erstimports. `scripts/dump_content.py` kann sie aus Tabellen-Dumps unter `/tmp/bs/export` neu bauen. 123 Spam-Beiträge sind ausgeschlossen.
