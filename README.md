# bleedingstar.at

Content export from the old WordPress database. The site itself is not rebuilt here.

`data/content.json` holds the pages, artists, releases, events, news, documents and the media library index. Text is the original post content. Image and file fields point at the existing `wp-content/uploads` URLs.

WordPress was theme Replay, language `de_DE`, front page the Releases page. The database is `bleedingstaratdb5` on `mysqlsvr33.world4you.com`, table prefix `wp_`. The database password stays in `wp-config.php` on the server.

123 posts from 2020 and 2024 are injected spam and are not in this export. Accounts, passwords and plugin data are not included. The WordPress install also had randomly named plugins and injected sidebar widgets; none of that is in this export.

Regenerate the file from the table dumps with `python3 scripts/dump_content.py` (dumps are read from `/tmp/bs/export`).
