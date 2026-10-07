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
$unknownLinked = $unknown ? $db->one("SELECT 1 FROM external_ids WHERE entity_type = 'release' AND entity_id = ?", [$unknown['id']]) : null;
check(
    $unknown !== null && ($unknownLinked
        ? $unknown['release_date_precision'] !== 'unknown'
        : ($unknown['release_date_precision'] === 'unknown' && $unknown['release_year'] === null)),
    $unknownLinked ? 'date filled by a provider survives the archive re-import' : 'missing date stays unknown'
);

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
$coverDir = sys_get_temp_dir() . '/bs-cover-test-' . bin2hex(random_bytes(3));
$saved = (new App\Discogs\Covers($coverDir, 'test'))->fromBytes('42', $png);
$gridInfo = $saved ? @getimagesize($coverDir . '/storage/uploads/' . $saved['grid']) : false;
check($saved !== null && is_file($coverDir . '/storage/uploads/' . $saved['full']) && $gridInfo && $gridInfo[0] === 640, 'largest cover is kept and a 640px file is generated');

$bioBefore = $db->one('SELECT bio_html FROM artists WHERE slug="supervision"');
$db->exec('UPDATE artists SET bio_html=?, editorial_locked=1 WHERE slug="supervision"', ['<p>LOCKED-BIO</p>']);
$linksBefore = (int) $db->one('SELECT COUNT(*) AS c FROM release_links l JOIN releases r ON r.id = l.release_id WHERE r.slug = "on-the-road-to-calipo-island"')['c'];
(new LegacyImporter($db, app_root()))->import(app_config()['legacy_export']);
$linksAfter = (int) $db->one('SELECT COUNT(*) AS c FROM release_links l JOIN releases r ON r.id = l.release_id WHERE r.slug = "on-the-road-to-calipo-island"')['c'];
check($linksBefore > 0 && $linksAfter === $linksBefore, 'reimport does not duplicate release links');
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
// Two archive releases with the same artist and title: a Discogs match cannot pick one automatically.
foreach (['a', 'b'] as $suffix) {
    $twiceId = $db->insert(
        'INSERT INTO releases (slug, title, status, source, release_date_precision, created_at, updated_at) VALUES (?,?,"published","legacy","unknown",NOW(),NOW())',
        [slugify($marker . ' twice ' . $suffix), $marker . ' Twice']
    );
    $db->exec('INSERT INTO release_artists (release_id, artist_id, position) VALUES (?,?,0)', [$twiceId, $artistId]);
}

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
        ['id' => 9001], ['id' => 9002], ['id' => 9003], ['id' => 9004], ['id' => 9005],
    ]],
    '/releases/9001' => $detail(9001, $marker . ' Shared', $marker . ' Artist', '88001'),
    '/releases/9002' => $detail(9002, $marker . ' Other Title', $marker . ' Other', '88002'),
    '/releases/9003' => $detail(9003, $marker . ' UPC', $marker . ' Nobody', '88003', '000TESTUPC'),
    '/releases/9004' => $detail(9004, $marker . ' Fresh', $marker . ' Fresh Artist', '88004', null, 70001),
    '/releases/9005' => $detail(9005, $marker . ' Twice', $marker . ' Artist', '88001'),
]);
$sync = new Sync($db, $client, $discogsConfig);
$stats = $sync->importLabel(false, 1);
check($stats['reviews'] === 1, 'same artist and title with two possible releases is queued');
check($stats['created'] === 2, 'distinct title and a new master are created');
check($stats['updated'] === 2 && ($stats['merged'] ?? 0) === 1 && str_contains($stats['message'], 'auto-linked'), 'matching UPC and the unique artist+title match link existing releases');
$created = (int) $db->one('SELECT COUNT(*) AS c FROM releases WHERE title = ?', [$marker . ' Shared'])['c'];
check($created === 1, 'exact match did not create a second Shared release');
check($db->one("SELECT id FROM external_ids WHERE provider='discogs' AND entity_type='release' AND external_id='9001' AND entity_id=?", [$legacyId]) !== null, 'exact Discogs match is linked to the archive release');
$auto = $db->one("SELECT status, reason FROM import_reviews WHERE provider='discogs' AND external_id='9001'");
check($auto !== null && $auto['status'] === 'merged' && str_starts_with($auto['reason'], 'Automatisch'), 'automatic link is recorded as a merged review');
check((int) $db->one('SELECT COUNT(*) AS c FROM releases WHERE title = ?', [$marker . ' Twice'])['c'] === 2, 'ambiguous match creates nothing and merges nothing');
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
$review = $db->one("SELECT id, status, candidate_release_id FROM import_reviews WHERE external_id='9005' AND status='open'");
check($review !== null, 'review row is open');
check($sync->mergeReview((int) $review['id']) === true, 'manual merge links the review');
$merged = $db->one("SELECT status FROM import_reviews WHERE id=?", [$review['id']]);
check($merged['status'] === 'merged', 'review is marked merged');
$linkedTracks = (int) $db->one('SELECT COUNT(*) AS c FROM tracks WHERE release_id=?', [$legacyId])['c'];
check($linkedTracks === 1, 'automatic link copies tracks only when the release had none');
check((int) $db->one('SELECT COUNT(*) AS c FROM tracks WHERE release_id=?', [$review['candidate_release_id']])['c'] === 1, 'manual merge copies tracks to the chosen release');

$fresh = $db->one('SELECT * FROM releases WHERE title=?', [$marker . ' Fresh']);
$db->exec('UPDATE releases SET editorial_locked=1, cover_remote_url=NULL, cover_source="upload", cover_path="covers/manual.jpg" WHERE id=?', [$fresh['id']]);
$sync->importLabel(false, 1);
$kept = $db->one('SELECT cover_path, cover_remote_url FROM releases WHERE id=?', [$fresh['id']]);
check($kept['cover_path'] === 'covers/manual.jpg' && $kept['cover_remote_url'] === null, 'locked cover is not replaced');

$cacheFresh = $sync->refreshCache(true);
check($cacheFresh['updated'] === 0 && $cacheFresh['errors'] === 0, 'cache refresh skips payloads the import just stored');
$db->exec("UPDATE provider_records SET fetched_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE provider='discogs' AND external_id IN ('9002','9004')");
$cacheStale = $sync->refreshCache(true);
check($cacheStale['updated'] === 2, 'cache refresh picks up payloads older than max_age_hours');
$cacheExpired = $sync->refreshCache(true, microtime(true) - 1);
check($cacheExpired['updated'] === 0 && str_contains($cacheExpired['message'], 'time budget'), 'cache refresh stops once the time budget is spent');

$rotationFile = app_root() . '/storage/jobs/rotation';
$rotationBackup = is_file($rotationFile) ? file_get_contents($rotationFile) : null;
@mkdir(dirname($rotationFile), 0775, true);
file_put_contents($rotationFile, '1');
$runner = new App\JobRunner($db, app_root());
// deadline in the past: every source reports "time budget" without a single network call
$rotated = $runner->syncReleases(app_config(), true, null, null, microtime(true) - 1);
$order = array_map(static fn (string $part): string => trim(explode(':', $part)[0]), explode('|', $rotated['message']));
check($order === ['deezer', 'apple', 'cache', 'discogs'], 'a full run starts at the stored rotation index and visits every source');
check(trim((string) file_get_contents($rotationFile)) === '2', 'the rotation pointer moves on by one for the next run');
check($runner->enabledSources(app_config()) === ['discogs', 'deezer', 'apple', 'cache'], 'enabled sources include the cache refresh');
if ($rotationBackup === null) {
    @unlink($rotationFile);
} else {
    file_put_contents($rotationFile, $rotationBackup);
}

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

// --- Deezer source -----------------------------------------------------------
final class FixtureDeezer extends App\Deezer\Client
{
    public function __construct(private array $map)
    {
        parent::__construct(['timeout' => 1]);
    }

    public function get(string $path): array
    {
        if (!isset($this->map[$path])) {
            throw new DiscogsException('missing Deezer fixture ' . $path, 800, false);
        }
        return $this->map[$path];
    }
}

$dzLegacyId = $db->insert(
    'INSERT INTO releases (slug, title, status, source, release_year, release_date_precision, created_at, updated_at) VALUES (?,?,"published","legacy",2020,"year",NOW(),NOW())',
    [slugify($marker . ' dz legacy'), $marker . ' Deezer Shared']
);
$dzBandId = $db->insert(
    'INSERT INTO artists (slug, name, status, image_source, created_at, updated_at) VALUES (?,?,"published","legacy",NOW(),NOW())',
    [slugify($marker . ' band'), 'The ' . $marker . ' Band']
);
$db->exec('INSERT INTO release_artists (release_id, artist_id, position) VALUES (?,?,0)', [$dzLegacyId, $dzBandId]);
$dzExactId = $db->insert(
    'INSERT INTO releases (slug, title, status, source, release_year, release_date_precision, created_at, updated_at) VALUES (?,?,"published","legacy",2020,"year",NOW(),NOW())',
    [slugify($marker . ' dz exact'), $marker . ' Exact - Single']
);
$db->exec('INSERT INTO release_artists (release_id, artist_id, position) VALUES (?,?,0)', [$dzExactId, $dzBandId]);

$dzAlbum = static function (int $id, string $title, string $artist, int $artistId, string $label, ?string $upc, string $type = 'single'): array {
    return [
        'id' => $id,
        'title' => $title,
        'upc' => $upc,
        'link' => 'https://www.deezer.com/album/' . $id,
        'cover_xl' => 'https://cdn-images.dzcdn.test/images/cover/abc/1000x1000-000000-80-0-0.jpg',
        'genres' => ['data' => [['id' => 152, 'name' => 'Rock']]],
        'label' => $label,
        'release_date' => '2020-05-06',
        'record_type' => $type,
        'artist' => ['id' => $artistId, 'name' => $artist],
        'contributors' => [['id' => $artistId, 'name' => $artist, 'role' => 'Main']],
    ];
};
$dzTracks = ['data' => [
    ['title' => 'Song One', 'duration' => 190, 'isrc' => 'ATTEST2000001', 'track_position' => 1, 'disk_number' => 1],
    ['title' => 'Song Two', 'duration' => 65, 'isrc' => 'ATTEST2000002', 'track_position' => 2, 'disk_number' => 1],
]];
$dzSearch = '/search/album?limit=100&q=' . rawurlencode('label:"BleedingStar Records"');
$dzClient = new FixtureDeezer([
    $dzSearch => ['data' => [['id' => 7001], ['id' => 7002], ['id' => 7003], ['id' => 7004], ['id' => 7005], ['id' => 7006]], 'total' => 6],
    // Shop-style title "<artist> - <title>" by the same band: unique exact match, linked automatically.
    '/album/7005' => $dzAlbum(7005, $marker . ' Band - ' . $marker . ' Exact', 'The ' . $marker . ' Band', 66001, 'BleedingStar Records', '000DZUPC5'),
    '/album/7005/tracks' => $dzTracks,
    // Same title again: the release already carries another Deezer album, so this one is a review.
    '/album/7006' => $dzAlbum(7006, $marker . ' Exact', 'The ' . $marker . ' Band', 66001, 'BleedingStar Records', '000DZUPC6'),
    '/album/7006/tracks' => $dzTracks,
    '/album/7001' => $dzAlbum(7001, $marker . ' Deezer Shared (Radio Edit)', $marker . ' Band', 66001, 'BleedingStar Records', '000DZUPC1'),
    '/album/7001/tracks' => $dzTracks,
    '/album/7002' => $dzAlbum(7002, $marker . ' Deezer Fresh', $marker . ' Newcomer', 66002, 'BleedingStar Records', '000DZUPC2', 'ep'),
    '/album/7002/tracks' => $dzTracks,
    '/album/7003' => $dzAlbum(7003, $marker . ' Foreign', $marker . ' Stranger', 66003, 'Some Other Label', '000DZUPC3'),
    '/album/7003/tracks' => $dzTracks,
    '/album/7004' => $dzAlbum(7004, $marker . ' UPC Hit', $marker . ' Nobody', 66004, 'BleedingStar Records', '000TESTUPC'),
    '/album/7004/tracks' => $dzTracks,
]);
$dzConfig = ['label_names' => ['BleedingStar Records'], 'user_agent' => 'test'];
// A review left over from an earlier run; the UPC link resolves it without a manual step.
$db->exec(
    'INSERT INTO import_reviews (provider, external_id, candidate_release_id, payload_json, reason, status, created_at) VALUES ("deezer","7004",?,"{}","alt","open",NOW())',
    [$legacyId]
);
$dz = new App\Deezer\Sync($db, $dzClient, $dzConfig);
$dzStats = $dz->importLabel(false);
$dzStale = $db->one("SELECT status, reason FROM import_reviews WHERE provider='deezer' AND external_id='7004'");
check($dzStale !== null && $dzStale['status'] === 'merged' && str_contains($dzStale['reason'], 'UPC'), 'a stale review is closed once the album is linked by UPC');
check($dzStats['skipped'] === 1, 'Deezer album of another label is skipped');
check($dzStats['created'] === 1, 'Deezer creates only the unknown release');
check($dzStats['updated'] === 2 && ($dzStats['merged'] ?? 0) === 1, 'Deezer UPC match and the exact artist+title match link existing releases');
check($dzStats['reviews'] === 2, 'Deezer similar title and a second album for a linked release become reviews');
check($db->one("SELECT id FROM external_ids WHERE provider='deezer' AND entity_type='release' AND external_id='7005' AND entity_id=?", [$dzExactId]) !== null, 'exact Deezer match with artist prefix in the title is linked automatically');
$dzExactRow = $db->one('SELECT release_date_precision, title FROM releases WHERE id = ?', [$dzExactId]);
check($dzExactRow['release_date_precision'] === 'day' && $dzExactRow['title'] === $marker . ' Exact - Single', 'automatic link enriches the date and keeps the archive title');
check((int) $db->one('SELECT COUNT(*) AS c FROM tracks WHERE release_id = ?', [$dzExactId])['c'] === 2, 'automatic link fills the empty tracklist');
$dzAuto = $db->one("SELECT status, reason FROM import_reviews WHERE provider='deezer' AND external_id='7005'");
check($dzAuto !== null && $dzAuto['status'] === 'merged' && str_starts_with($dzAuto['reason'], 'Automatisch'), 'automatic Deezer link is recorded as a merged review');
$dzSecond = $db->one("SELECT reason FROM import_reviews WHERE provider='deezer' AND external_id='7006' AND status='open'");
check($dzSecond !== null && str_contains($dzSecond['reason'], 'anderes Deezer-Album'), 'second Deezer album for the same release stays a review');
$dzFresh = $db->one('SELECT * FROM releases WHERE title = ?', [$marker . ' Deezer Fresh']);
check($dzFresh !== null && $dzFresh['release_date_precision'] === 'day' && (int) $dzFresh['release_day'] === 6 && $dzFresh['release_type'] === 'ep', 'Deezer release carries exact date and type');
$dzTrackRows = $db->all('SELECT title, duration, isrc FROM tracks WHERE release_id = ? ORDER BY position', [$dzFresh['id'] ?? 0]);
check(count($dzTrackRows) === 2 && $dzTrackRows[0]['duration'] === '3:10' && $dzTrackRows[1]['duration'] === '1:05' && $dzTrackRows[0]['isrc'] === 'ATTEST2000001', 'Deezer tracks keep duration and ISRC');
check($db->one('SELECT id FROM release_formats WHERE release_id = ? AND upc = "000DZUPC2"', [$dzFresh['id'] ?? 0]) !== null, 'Deezer UPC is stored as digital format');
check($db->one("SELECT id FROM release_links WHERE release_id = ? AND provider = 'deezer'", [$dzFresh['id'] ?? 0]) !== null, 'Deezer link is stored');
check($db->one("SELECT id FROM external_ids WHERE provider='deezer' AND entity_type='release' AND entity_id=?", [$legacyId]) !== null, 'UPC match remembers the Deezer id on the existing release');
check((int) $db->one('SELECT COUNT(*) AS c FROM releases WHERE title LIKE ?', [$marker . ' Deezer Shared%'])['c'] === 1, 'review did not create a duplicate');
check((int) $db->one('SELECT COUNT(*) AS c FROM artists WHERE name LIKE ?', ['%' . $marker . ' Band'])['c'] === 1, 'artist with and without "The" is the same artist');
$dzReview = $db->one("SELECT id FROM import_reviews WHERE provider='deezer' AND external_id='7001' AND status='open'");
check($dzReview !== null && $dz->mergeReview((int) $dzReview['id']) === true, 'Deezer review can be merged');
$dzMerged = $db->one('SELECT release_year, release_month, release_day, release_date_precision FROM releases WHERE id = ?', [$dzLegacyId]);
check($dzMerged['release_date_precision'] === 'day' && (int) $dzMerged['release_month'] === 5 && (int) $dzMerged['release_day'] === 6, 'merge upgrades a year-only date to the exact day');
check((int) $db->one('SELECT COUNT(*) AS c FROM tracks WHERE release_id = ?', [$dzLegacyId])['c'] === 2, 'merge fills the empty tracklist');
$dzAgain = $dz->importLabel(false);
check($dzAgain['created'] === 0 && $dzAgain['reviews'] === 1 && $dzAgain['updated'] === 4, 'second Deezer run only refreshes the linked releases and keeps the open review');
$db->exec('UPDATE releases SET editorial_locked=1, release_date_precision="year", release_month=NULL, release_day=NULL WHERE id=?', [$dzLegacyId]);
$dz->importLabel(false);
$dzLocked = $db->one('SELECT release_date_precision FROM releases WHERE id = ?', [$dzLegacyId]);
check($dzLocked['release_date_precision'] === 'year', 'editorial lock stops Deezer from changing the date');
$dzExpired = $dz->importLabel(false, microtime(true) - 1);
check($dzExpired['updated'] === 0 && str_contains($dzExpired['message'], 'time budget'), 'Deezer stops before the first album once the time budget is spent');
$appleExpired = (new App\Apple\Links($db, ['user_agent' => 'test']))->run(true, 5, microtime(true) - 1);
check($appleExpired['errors'] === 0 && $appleExpired['updated'] === 0, 'Apple lookup stops without network calls once the time budget is spent');
check(App\JobRunner::deadline(['job_time_budget' => 0]) === null, 'job_time_budget 0 means no deadline');
check(abs((App\JobRunner::deadline(['job_time_budget' => 150], 1000.0) ?? 0) - 1150.0) < 0.001, 'job_time_budget sets the deadline from the request start');
check(App\JobRunner::deadline([], 1000.0) === ((int) ini_get('max_execution_time') > 0 ? 1000.0 + max(20, (int) ini_get('max_execution_time') - 30) : null), 'without config the deadline follows max_execution_time');
$dzCovers = new App\Discogs\Covers(app_root(), 'test', 'deezer', ['dzcdn.net']);
check($dzCovers->existing('7002') === null || is_file(app_root() . '/storage/uploads/' . $dzCovers->existing('7002')['full']), 'existing cover lookup only reports files on disk');
check($dzCovers->existing('not-an-id') === null, 'existing cover lookup rejects non-numeric ids');

$idsToDrop = $db->all(
    'SELECT id FROM releases WHERE title LIKE ? OR id IN (?, ?, ?)',
    [$marker . '%', $legacyId, $dzLegacyId, $dzExactId]
);
foreach ($idsToDrop as $row) {
    $db->exec('DELETE FROM releases WHERE id=?', [$row['id']]);
}
$db->exec("DELETE FROM external_ids WHERE external_id IN ('9001','9002','9003','9004','88001','88002','88003','88004','70001','7001','7002','7003','7004','7005','7006','66001','66002','66003','66004','9005')");
$db->exec("DELETE FROM provider_records WHERE external_id IN ('9001','9002','9003','9004','9005','7001','7002','7003','7004','7005','7006')");
$db->exec('DELETE FROM artists WHERE name LIKE ? OR name LIKE ?', [$marker . '%', 'The ' . $marker . '%']);
$db->exec('DELETE FROM import_reviews WHERE external_id IN ("9001","9002","9003","9004","9005","7001","7002","7003","7004","7005","7006")');
$left = (int) $db->one('SELECT COUNT(*) AS c FROM releases WHERE title LIKE ?', [$marker . '%'])['c'];
check($left === 0, 'fixture releases removed');
check((int) $db->one("SELECT COUNT(*) AS c FROM releases WHERE status='published'")['c'] === $publishedReleases, 'public catalog count restored');

// --- HTML filter -----------------------------------------------------------
$dirty = '<p class="x" onclick="alert(1)">Hi <strong/onmouseover=alert(1)>there</strong></p>'
    . '<a href="javascript:alert(1)">bad</a><a href="//evil.example/x">proto</a><a href="/releases">ok</a>'
    . '<img src="data:image/png;base64,AAAA"><img src="/media/covers/a.jpg" alt="A" onerror="x">'
    . '<script>alert(1)</script><iframe src="https://x"></iframe>';
$cleaned = App\Html::clean($dirty);
check(!str_contains($cleaned, 'onclick') && !str_contains($cleaned, 'onmouseover') && !str_contains($cleaned, 'onerror'), 'event handler attributes are removed');
check(!str_contains($cleaned, 'class='), 'other attributes on allowed tags are removed');
check(!str_contains($cleaned, 'javascript') && !str_contains($cleaned, 'evil.example') && !str_contains($cleaned, 'data:'), 'unsafe URLs are dropped');
check(str_contains($cleaned, '<a href="/releases"') && str_contains($cleaned, '<img src="/media/covers/a.jpg" alt="A"'), 'safe links and images survive');
check(!str_contains($cleaned, '<script') && !str_contains($cleaned, '<iframe'), 'script and iframe are removed');

// --- Installer gate --------------------------------------------------------
$installer = new App\Installer($db, app_root());
$token = str_repeat('a', 40);
check(!$installer->webAllowed(['setup_token' => ''], 'x'), 'setup is closed without a token');
check(!$installer->webAllowed(['setup_token' => 'short'], 'short'), 'setup rejects a short token');
check($installer->installed() || $installer->webAllowed(['setup_token' => $token], $token), 'setup opens with the configured token while not installed');
check(!$installer->webAllowed(['setup_token' => $token], substr($token, 1) . 'b'), 'setup rejects a wrong token');
if ($installer->installed()) {
    check(!$installer->webAllowed(['setup_token' => $token], $token), 'setup is closed once installed');
}

// --- Legacy content removal (news table, radio page, news redirects) --------
$db->exec('CREATE TABLE IF NOT EXISTS news (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL)');
$db->exec("INSERT INTO news (title) VALUES ('alt 1'), ('alt 2')");
$db->exec("INSERT INTO pages (slug, title, body_html, updated_at) VALUES ('radio', 'Radio', '<p>alt</p>', NOW()) ON DUPLICATE KEY UPDATE title = VALUES(title)");
$db->exec("INSERT INTO redirects (source_path, target_path) VALUES ('/test-legacy-news', '/news/alt-1'), ('/test-legacy-radio', '/radio') ON DUPLICATE KEY UPDATE target_path = VALUES(target_path)");
$db->exec("INSERT INTO redirects (source_path, target_path) VALUES ('/test-legacy-keep', '/releases') ON DUPLICATE KEY UPDATE target_path = VALUES(target_path)");
$dryStats = $installer->removeLegacyContent(true);
check($dryStats['errors'] === 0 && str_contains($dryStats['message'], 'news: 2 Beiträge, Tabelle würde entfernt'), 'dry run counts the news posts');
check($db->pdo()->query("SHOW TABLES LIKE 'news'")->fetch() !== false, 'dry run keeps the news table');
check($db->one("SELECT 1 FROM pages WHERE slug = 'radio'") !== null, 'dry run keeps the radio page');
$realStats = $installer->removeLegacyContent(false);
check($realStats['errors'] === 0 && str_contains($realStats['message'], 'news: 2 Beiträge, Tabelle entfernt') && str_contains($realStats['message'], 'radio: Seite entfernt'), 'real run reports the removal: ' . $realStats['message']);
check($db->pdo()->query("SHOW TABLES LIKE 'news'")->fetch() === false, 'news table is dropped');
check($db->one("SELECT 1 FROM pages WHERE slug = 'radio'") === null, 'radio page is deleted');
check($db->one("SELECT 1 FROM redirects WHERE source_path IN ('/test-legacy-news', '/test-legacy-radio')") === null, 'news and radio redirects are deleted');
check($db->one("SELECT 1 FROM redirects WHERE source_path = '/test-legacy-keep'") !== null, 'other redirects survive');
$againStats = $installer->removeLegacyContent(false);
check($againStats['errors'] === 0 && $againStats['updated'] === 0 && str_contains($againStats['message'], 'bereits entfernt'), 'second run is a no-op');
$db->exec("DELETE FROM redirects WHERE source_path = '/test-legacy-keep'");

echo $failed === 0 ? "ALL PASSED\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
