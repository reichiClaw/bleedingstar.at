<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

/**
 * CSV columns: title,artist,year,month,day,type,upc,spotify_url
 * Own label data for releases that public databases do not list.
 * This does not claim the catalog is complete.
 */
$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php bin/import-csv.php file.csv\n");
    exit(1);
}
$fh = fopen($path, 'r');
$header = fgetcsv($fh);
if (!$header) {
    fwrite(STDERR, "Empty CSV\n");
    exit(1);
}
$header = array_map('trim', $header);
$db = app_db();
$created = 0;
while (($row = fgetcsv($fh)) !== false) {
    $data = array_combine($header, array_pad($row, count($header), ''));
    $title = trim((string) ($data['title'] ?? ''));
    $artist = trim((string) ($data['artist'] ?? ''));
    if ($title === '' || str_starts_with($title, '#')) {
        continue;
    }
    $upc = preg_replace('/\s+/', '', (string) ($data['upc'] ?? '')) ?? '';
    if ($upc !== '' && $db->one('SELECT id FROM release_formats WHERE upc = ? LIMIT 1', [$upc])) {
        continue;
    }
    if ($artist !== '' && $db->one(
        'SELECT r.id FROM releases r
         JOIN release_artists ra ON ra.release_id = r.id AND ra.position = 0
         JOIN artists a ON a.id = ra.artist_id
         WHERE r.title = ? AND a.name = ? LIMIT 1',
        [$title, $artist]
    )) {
        continue;
    }
    $year = ($data['year'] ?? '') !== '' ? (int) $data['year'] : null;
    $month = ($data['month'] ?? '') !== '' ? (int) $data['month'] : null;
    $day = ($data['day'] ?? '') !== '' ? (int) $data['day'] : null;
    $precision = $day ? 'day' : ($month ? 'month' : ($year ? 'year' : 'unknown'));
    $slug = slugify($title);
    $i = 2;
    $try = $slug;
    while ($db->one('SELECT id FROM releases WHERE slug = ?', [$try])) {
        $try = $slug . '-' . $i++;
    }
    $id = $db->insert(
        'INSERT INTO releases (slug, title, release_year, release_month, release_day, release_date_precision, release_type, status, source, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,"published","csv",NOW(),NOW())',
        [$try, $title, $year, $month, $day, $precision, ($data['type'] ?? '') !== '' ? $data['type'] : null]
    );
    if ($artist !== '') {
        $aslug = slugify($artist);
        $ar = $db->one('SELECT id FROM artists WHERE slug = ? OR name = ?', [$aslug, $artist]);
        $aid = $ar ? (int) $ar['id'] : $db->insert(
            'INSERT INTO artists (slug, name, status, image_source, created_at, updated_at) VALUES (?,?,"published","csv",NOW(),NOW())',
            [$aslug, $artist]
        );
        $db->exec('INSERT IGNORE INTO release_artists (release_id, artist_id, position) VALUES (?,?,0)', [$id, $aid]);
    }
    if ($upc !== '') {
        $db->exec('INSERT INTO release_formats (release_id, name, upc) VALUES (?,"Digital",?)', [$id, $upc]);
    }
    $spotify = trim((string) ($data['spotify_url'] ?? ''));
    if (preg_match('#^https?://#', $spotify)) {
        $db->exec(
            'INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?,"Spotify",?,"spotify",1)',
            [$id, $spotify]
        );
    }
    $created++;
}
fclose($fh);
echo "Imported $created CSV rows. This is not a completeness claim.\n";
