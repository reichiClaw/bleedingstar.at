<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\CatalogRepository;
use App\Database;
use App\Discogs\Client;
use App\Discogs\DiscogsException;
use App\Discogs\Sync;
use App\LegacyImporter;
use App\Lock;

$failed = 0;

function check(bool $ok, string $message): void
{
    global $failed;
    if ($ok) {
        echo "ok  $message\n";
        return;
    }
    $failed++;
    echo "FAIL $message\n";
}

final class FixtureDiscogs extends Client
{
    public function __construct(private array $map)
    {
        parent::__construct(['token' => 'fixture', 'timeout' => 1, 'user_agent' => 'test']);
    }

    public function get(string $path): array
    {
        if (!isset($this->map[$path])) {
            throw new DiscogsException('missing fixture ' . $path, 404, false);
        }
        $value = $this->map[$path];
        if ($value instanceof DiscogsException) {
            throw $value;
        }
        return $value;
    }
}

$db = app_db();
$catalog = new CatalogRepository($db, app_config());

$publishedArtists = (int) $db->one("SELECT COUNT(*) AS c FROM artists WHERE status='published'")['c'];
$archiveArtists = (int) $db->one("SELECT COUNT(*) AS c FROM artists WHERE status='published' AND image_source='legacy'")['c'];
$draftArtists = (int) $db->one("SELECT COUNT(*) AS c FROM artists WHERE status='draft'")['c'];
$publishedReleases = (int) $db->one("SELECT COUNT(*) AS c FROM releases WHERE status='published'")['c'];
$archiveReleases = (int) $db->one("SELECT COUNT(*) AS c FROM releases WHERE status='published' AND source='legacy'")['c'];
check($archiveArtists === 18, '18 published archive artists');
check($draftArtists === 3, '3 draft artists stay in the database');
check($archiveReleases === 39, '39 published archive releases');
check($catalog->artist('gangbanggang') === null, 'draft artist is not public');

$all = $catalog->search([]);
check($all['total'] === $publishedReleases, 'unfiltered total matches published releases');
check(count($all['items']) === min(24, $publishedReleases), 'first page is capped at 24');
$expectedPages = max(1, (int) ceil($publishedReleases / 24));
check($all['pages'] === $expectedPages, 'pagination page count matches the catalog');
$page2 = $catalog->search(['page' => 2]);
$remainder = $publishedReleases - 24;
check($expectedPages === 1 || count($page2['items']) === min(24, $remainder), 'second page has the next slice');
$ids = array_map(static fn ($row) => $row['id'], $all['items']);
foreach ($page2['items'] as $row) {
    if (in_array($row['id'], $ids, true)) {
        check(false, 'pages do not overlap');
        break;
    }
}
$covered = count(array_unique(array_merge($ids, array_column($page2['items'], 'id'))));
check($expectedPages > 2 || $covered === $publishedReleases, 'the loaded pages cover the catalog once');

$supervision = $catalog->search(['artist' => 'supervision']);
check($supervision['total'] === 5, 'artist filter Supervision');
$combined = $catalog->search(['artist' => 'supervision', 'q' => 'Right Now', 'year' => 2011]);
check($combined['total'] === 1 && $combined['items'][0]['slug'] === 'right-now', 'artist, text and year filters combine');
check($catalog->search(['q' => 'dieser titel existiert nicht'])['total'] === 0, 'empty search result');
check($catalog->search(['q' => 'N_w'])['total'] === 0, 'like wildcards are escaped');

$az = $catalog->search(['sort' => 'az']);
$titles = array_column($az['items'], 'title');
$sorted = $titles;
sort($sorted, SORT_FLAG_CASE | SORT_STRING);
check($titles === $sorted, 'alphabetical sort on the first page');

$right = $catalog->findRelease('right-now');
check($right !== null && $right['release_date_precision'] === 'day', 'Right Now keeps a full date');
check((int) $right['release_year'] === 2011 && (int) $right['release_month'] === 6 && (int) $right['release_day'] === 1, 'date stays 1 June 2011, not a guessed January');
check($right['tracks'] !== [], 'tracklist is present');
check($right['related'] !== [] && !in_array('right-now', array_column($right['related'], 'slug'), true), 'related releases are other label releases');

$unknown = $catalog->findRelease('feeling-to-rock');
check($unknown !== null && $unknown['release_date_precision'] === 'unknown' && $unknown['release_year'] === null, 'missing date stays unknown');

$db->exec(
    'INSERT INTO external_ids (provider, entity_type, entity_id, external_id) VALUES ("discogs","release",1,"fixture-dup")'
);
$db->exec(
    'INSERT IGNORE INTO external_ids (provider, entity_type, entity_id, external_id) VALUES ("discogs","release",1,"fixture-dup")'
);
$dupes = (int) $db->one("SELECT COUNT(*) AS c FROM external_ids WHERE external_id='fixture-dup'")['c'];
check($dupes === 1, 'duplicate external ids are rejected');
$db->exec("DELETE FROM external_ids WHERE external_id='fixture-dup'");

$before = $db->one('SELECT title FROM releases WHERE slug="right-now"');
try {
    $db->transaction(function (Database $tx) {
        $tx->exec('UPDATE releases SET title=? WHERE slug="right-now"', ['SHOULD NOT STICK']);
        throw new RuntimeException('import failed');
    });
} catch (RuntimeException) {
}
$after = $db->one('SELECT title FROM releases WHERE slug="right-now"');
check($after['title'] === $before['title'], 'failed transaction does not replace the title');

$remote = [
    'cover_source' => 'discogs',
    'cover_path' => null,
    'cover_grid_path' => null,
    'cover_remote_url' => 'https://i.discogs.com/example.jpg',
    'cover_attribution' => 'Discogs',
    'cover_page_url' => 'https://www.discogs.com/release/1',
    'cover_fetched_at' => date('Y-m-d H:i:s'),
];
check($catalog->cover($remote) === null, 'discogs covers are served from local files only');
$localDiscogs = $remote;
$localDiscogs['cover_path'] = 'covers/discogs/1.jpg';
$localDiscogs['cover_grid_path'] = 'covers/discogs/1-640.jpg';
check(str_contains((string) ($catalog->cover($localDiscogs)['url'] ?? ''), '/media/covers/discogs/1.jpg'), 'detail cover uses the stored original');
check(str_contains((string) ($catalog->cover($localDiscogs, 'grid')['url'] ?? ''), '/media/covers/discogs/1-640.jpg'), 'grid uses the smaller derived cover');
$legacy = ['cover_source' => 'legacy', 'cover_path' => 'covers/example.jpg', 'cover_remote_url' => null, 'cover_fetched_at' => null];
check(str_contains((string) ($catalog->cover($legacy)['url'] ?? ''), '/media/covers/example.jpg'), 'legacy covers stay local');
$img = imagecreatetruecolor(900, 400);
imagefilledrectangle($img, 0, 0, 899, 399, imagecolorallocate($img, 200, 20, 40));
ob_start();
imagepng($img);
$png = (string) ob_get_clean();
imagedestroy($img);
$coverDir = sys_get_temp_dir() . '/bs-cover-test-' . bin2hex(random_bytes(3));
$saved = (new App\Discogs\Covers($coverDir, 'test'))->fromBytes('42', $png);
$gridInfo = $saved ? @getimagesize($coverDir . '/storage/uploads/' . $saved['grid']) : false;
check($saved !== null && is_file($coverDir . '/storage/uploads/' . $saved['full']) && $gridInfo && $gridInfo[0] === 640, 'largest cover is kept and a 640px file is generated');

$bioBefore = $db->one('SELECT bio_html FROM artists WHERE slug="supervision"');
$db->exec('UPDATE artists SET bio_html=?, editorial_locked=1 WHERE slug="supervision"', ['<p>LOCKED-BIO</p>']);
(new LegacyImporter($db, app_root()))->import(app_config()['legacy_export']);
$locked = $db->one('SELECT bio_html, editorial_locked FROM artists WHERE slug="supervision"');
check($locked['bio_html'] === '<p>LOCKED-BIO</p>' && (int) $locked['editorial_locked'] === 1, 'manual artist text survives reimport');
$db->exec('UPDATE artists SET bio_html=?, editorial_locked=0 WHERE slug="supervision"', [$bioBefore['bio_html']]);

$discogsConfig = [
    'label_id' => 316841,
    'allow_label_ids' => [316841],
    'allow_label_names' => ['BleedingStar Records'],
    'max_age_hours' => 4,
];
$marker = 'ZZTEST ' . bin2hex(random_bytes(3));
$legacyId = $db->insert(
    'INSERT INTO releases (slug, title, status, source, release_date_precision, created_at, updated_at) VALUES (?,?,"published","legacy","unknown",NOW(),NOW())',
    [slugify($marker . ' legacy'), $marker . ' Shared']
);
$artistId = $db->insert(
    'INSERT INTO artists (slug, name, status, image_source, created_at, updated_at) VALUES (?,?,"published","legacy",NOW(),NOW())',
    [slugify($marker . ' artist'), $marker . ' Artist']
);
$db->exec('INSERT INTO release_artists (release_id, artist_id, position) VALUES (?,?,0)', [$legacyId, $artistId]);
$db->exec('INSERT INTO release_formats (release_id, name, upc) VALUES (?,"Digital","000TESTUPC")', [$legacyId]);

$listPath = '/labels/316841/releases?per_page=100&page=1';
$detail = static function (int $id, string $title, string $artist, string $artistId, ?string $upc = null, int $master = 0): array {
    $identifiers = $upc ? [['type' => 'Barcode', 'value' => $upc]] : [];
    return [
        'id' => $id,
        'title' => $title,
        'master_id' => $master,
        'year' => 2020,
        'released' => '2020',
        'uri' => 'https://www.discogs.com/release/' . $id,
        'artists' => [['id' => $artistId, 'name' => $artist]],
        'labels' => [['id' => 316841, 'name' => 'BleedingStar Records', 'catno' => 'BS-TEST']],
        'formats' => [['name' => 'Vinyl', 'descriptions' => ['LP']]],
        'tracklist' => [['type_' => 'track', 'title' => 'Track A', 'duration' => '3:10']],
        'identifiers' => $identifiers,
        'images' => [['type' => 'primary', 'uri' => 'https://img.discogs.test/' . $id . '.jpg']],
    ];
};
$client = new FixtureDiscogs([
    $listPath => ['pagination' => ['pages' => 1], 'releases' => [
        ['id' => 9001], ['id' => 9002], ['id' => 9003], ['id' => 9004],
    ]],
    '/releases/9001' => $detail(9001, $marker . ' Shared', $marker . ' Artist', '88001'),
    '/releases/9002' => $detail(9002, $marker . ' Other Title', $marker . ' Other', '88002'),
    '/releases/9003' => $detail(9003, $marker . ' UPC', $marker . ' Nobody', '88003', '000TESTUPC'),
    '/releases/9004' => $detail(9004, $marker . ' Fresh', $marker . ' Fresh Artist', '88004', null, 70001),
]);
$sync = new Sync($db, $client, $discogsConfig);
$stats = $sync->importLabel(false, 1);
check($stats['reviews'] === 1, 'same artist and title is queued, not merged');
check($stats['created'] === 2, 'distinct title and a new master are created');
check($stats['updated'] === 1, 'matching UPC links the existing release');
$created = (int) $db->one('SELECT COUNT(*) AS c FROM releases WHERE title = ?', [$marker . ' Shared'])['c'];
check($created === 1, 'fuzzy match did not create a second Shared release');
$yearOnly = $db->one('SELECT release_year, release_month, release_day, release_date_precision FROM releases WHERE title = ?', [$marker . ' Fresh']);
check(
    $yearOnly
    && (int) $yearOnly['release_year'] === 2020
    && $yearOnly['release_month'] === null
    && $yearOnly['release_day'] === null
    && $yearOnly['release_date_precision'] === 'year',
    'year-only Discogs date does not become 1 January'
);
$vinylCount = (int) $db->one('SELECT COUNT(*) AS c FROM releases WHERE title = ?', [$marker . ' Fresh'])['c'];
check($vinylCount === 1, 'one tile for the new release');
$statsAgain = $sync->importLabel(false, 1);
check($statsAgain['created'] === 0, 'second import creates no duplicates');
$externalCount = (int) $db->one("SELECT COUNT(*) AS c FROM external_ids WHERE external_id='9004' AND entity_type='release'")['c'];
check($externalCount === 1, 'external release id stays unique');
$review = $db->one("SELECT id, status FROM import_reviews WHERE external_id='9001' AND status='open'");
check($review !== null, 'review row is open');
check($sync->mergeReview((int) $review['id']) === true, 'manual merge links the review');
$merged = $db->one("SELECT status FROM import_reviews WHERE id=?", [$review['id']]);
check($merged['status'] === 'merged', 'review is marked merged');
$linkedTracks = (int) $db->one('SELECT COUNT(*) AS c FROM tracks WHERE release_id=?', [$legacyId])['c'];
check($linkedTracks === 1, 'merge copies tracks only when the release had none');

$fresh = $db->one('SELECT * FROM releases WHERE title=?', [$marker . ' Fresh']);
$db->exec('UPDATE releases SET editorial_locked=1, cover_remote_url=NULL, cover_source="upload", cover_path="covers/manual.jpg" WHERE id=?', [$fresh['id']]);
$sync->importLabel(false, 1);
$kept = $db->one('SELECT cover_path, cover_remote_url FROM releases WHERE id=?', [$fresh['id']]);
check($kept['cover_path'] === 'covers/manual.jpg' && $kept['cover_remote_url'] === null, 'locked cover is not replaced');

$boom = new FixtureDiscogs([
    $listPath => new DiscogsException('Discogs quota exhausted', 429, true),
]);
$quota = (new Sync($db, $boom, $discogsConfig))->importLabel(false, 1);
check($quota['errors'] === 1 && str_contains($quota['message'], 'quota'), 'quota stops the import without deleting rows');
check($db->one('SELECT id FROM releases WHERE id=?', [$legacyId]) !== null, 'existing release remains after a failed import');

$lockA = new Lock(app_root() . '/storage/locks/catalog.lock');
$lockB = new Lock(app_root() . '/storage/locks/catalog.lock');
check($lockA->acquire() === true, 'lock can be acquired');
check($lockB->acquire() === false, 'second job sees the lock');
$lockA->release();
check($lockB->acquire() === true, 'lock is available after release');
$lockB->release();

$csv = tempnam(sys_get_temp_dir(), 'bs');
file_put_contents($csv, "title,artist,year,month,day,type,upc,spotify_url\n{$marker} CSV,{$marker} CSV Artist,2019,,,album,999TESTUPC,\n");
passthru('php ' . escapeshellarg(dirname(__DIR__) . '/bin/import-csv.php') . ' ' . escapeshellarg($csv), $csvCode);
passthru('php ' . escapeshellarg(dirname(__DIR__) . '/bin/import-csv.php') . ' ' . escapeshellarg($csv), $csvCode2);
unlink($csv);
$csvRows = (int) $db->one('SELECT COUNT(*) AS c FROM releases WHERE title=?', [$marker . ' CSV'])['c'];
check($csvCode === 0 && $csvCode2 === 0 && $csvRows === 1, 'CSV import is repeatable');
$csvRelease = $db->one('SELECT release_day, release_date_precision FROM releases WHERE title=?', [$marker . ' CSV']);
check($csvRelease['release_day'] === null && $csvRelease['release_date_precision'] === 'year', 'CSV year does not invent a day');

$idsToDrop = $db->all(
    'SELECT id FROM releases WHERE title LIKE ? OR id = ?',
    [$marker . '%', $legacyId]
);
foreach ($idsToDrop as $row) {
    $db->exec('DELETE FROM releases WHERE id=?', [$row['id']]);
}
$db->exec("DELETE FROM external_ids WHERE external_id IN ('9001','9002','9003','9004','88001','88002','88003','88004','70001')");
$db->exec("DELETE FROM provider_records WHERE external_id IN ('9001','9002','9003','9004')");
$db->exec('DELETE FROM artists WHERE name LIKE ?', [$marker . '%']);
$db->exec('DELETE FROM import_reviews WHERE external_id IN ("9001","9002","9003","9004")');
$left = (int) $db->one('SELECT COUNT(*) AS c FROM releases WHERE title LIKE ?', [$marker . '%'])['c'];
check($left === 0, 'fixture releases removed');
check((int) $db->one("SELECT COUNT(*) AS c FROM releases WHERE status='published'")['c'] === $publishedReleases, 'public catalog count restored');

echo $failed === 0 ? "ALL PASSED\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
