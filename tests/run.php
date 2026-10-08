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

final class FixtureSpotify extends App\Spotify\Links
{
    public array $queries = [];

    public array $storedCovers = [];

    public function __construct(App\Database $db, private array $map)
    {
        parent::__construct($db, ['enabled' => true, 'client_id' => 'fixture-id', 'client_secret' => 'fixture-secret', 'market' => 'AT']);
    }

    protected function search(string $query, int $limit, string $type = 'album'): array
    {
        $this->queries[] = $query;
        return $this->map[$query] ?? [];
    }

    protected function albumById(string $id): ?array
    {
        return $this->map['album:' . $id] ?? null;
    }

    protected function storeCover(int $releaseId, string $url): ?array
    {
        $this->storedCovers[$releaseId] = $url;
        return ['full' => 'covers/spotify/' . $releaseId . '.jpg', 'grid' => null];
    }
}

final class QuotaSpotify extends App\Spotify\Links
{
    public function __construct(App\Database $db)
    {
        parent::__construct($db, ['enabled' => true, 'client_id' => 'fixture-id', 'client_secret' => 'fixture-secret']);
    }

    protected function search(string $query, int $limit, string $type = 'album'): array
    {
        throw new App\Spotify\SpotifyException('Spotify quota reached, next run continues', true);
    }

    protected function albumById(string $id): ?array
    {
        throw new App\Spotify\SpotifyException('Spotify quota reached, next run continues', true);
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

$cacheStamp = $db->all("SELECT external_id, fetched_at FROM provider_records WHERE provider = 'discogs' AND entity_type = 'release' AND external_id NOT IN ('9001','9002','9003','9004','9005')");
$db->exec("UPDATE provider_records SET fetched_at = NOW() WHERE provider = 'discogs' AND entity_type = 'release' AND external_id NOT IN ('9001','9002','9003','9004','9005')");
$cacheFresh = $sync->refreshCache(true);
check($cacheFresh['updated'] === 0 && $cacheFresh['errors'] === 0, 'cache refresh skips payloads the import just stored');
$db->exec("UPDATE provider_records SET fetched_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE provider='discogs' AND external_id IN ('9002','9004')");
$cacheStale = $sync->refreshCache(true);
check($cacheStale['updated'] === 2, 'cache refresh picks up payloads older than max_age_hours');
$cacheExpired = $sync->refreshCache(true, microtime(true) - 1);
check($cacheExpired['updated'] === 0 && str_contains($cacheExpired['message'], 'time budget'), 'cache refresh stops once the time budget is spent');
foreach ($cacheStamp as $stamp) {
    $db->exec(
        "UPDATE provider_records SET fetched_at = ? WHERE provider = 'discogs' AND entity_type = 'release' AND external_id = ?",
        [$stamp['fetched_at'], $stamp['external_id']]
    );
}

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

$spotifyOff = (new App\Spotify\Links($db, ['enabled' => true, 'client_id' => '', 'client_secret' => '']))->run(true, 1);
check($spotifyOff['errors'] === 0 && $spotifyOff['updated'] === 0 && str_contains($spotifyOff['message'], 'not configured'), 'Spotify stays idle without credentials');
$spotifyRunner = new App\JobRunner($db, app_root());
check(!in_array('spotify', $spotifyRunner->enabledSources(app_config()), true), 'a full run skips Spotify while no credentials are configured');
check(in_array('spotify', $spotifyRunner->enabledSources(['spotify' => ['enabled' => true, 'client_id' => 'id', 'client_secret' => 'secret']]), true), 'Spotify joins a full run once client id and secret are set');
$db->exec(
    "INSERT INTO provider_records (provider, entity_type, external_id, payload_json, fetched_at)
     SELECT 'spotify', 'release', CAST(id AS CHAR), '{\"fixture\":\"shield\"}', NOW() FROM releases r
     WHERE NOT EXISTS (SELECT 1 FROM provider_records p WHERE p.provider = 'spotify' AND p.entity_type = 'release' AND p.external_id = CAST(r.id AS CHAR))"
);
$spotifyArtist = $db->insert(
    'INSERT INTO artists (slug, name, status, image_source, created_at, updated_at) VALUES (?, ?, "published", "legacy", NOW(), NOW())',
    ['spotify-fixture-band', 'Jünger ' . 'Fixture']
);
$spotifyRelease = static function (string $slug, string $title, ?int $year = null, ?string $type = null) use ($db, $spotifyArtist): int {
    $id = $db->insert(
        'INSERT INTO releases (slug, title, status, source, release_year, release_type, created_at, updated_at) VALUES (?, ?, "published", "legacy", ?, ?, NOW(), NOW())',
        [$slug, $title, $year, $type]
    );
    $db->exec('INSERT INTO release_artists (release_id, artist_id, position) VALUES (?, ?, 0)', [$id, $spotifyArtist]);
    return $id;
};
check(cover_may_replace(['cover_path' => 'a.jpg', 'cover_source' => 'deezer', 'editorial_locked' => 0], 'spotify'), 'spotify replaces a deezer cover');
check(cover_may_replace(['cover_path' => 'a.jpg', 'cover_source' => 'discogs', 'editorial_locked' => 0], 'deezer'), 'deezer replaces a discogs cover');
check(cover_may_replace(['cover_path' => 'a.jpg', 'cover_source' => 'deezer', 'editorial_locked' => 0], 'apple'), 'apple replaces a deezer cover');
check(!cover_may_replace(['cover_path' => 'a.jpg', 'cover_source' => 'deezer', 'editorial_locked' => 0], 'discogs'), 'discogs does not replace a deezer cover');
check(!cover_may_replace(['cover_path' => 'a.jpg', 'cover_source' => 'spotify', 'editorial_locked' => 0], 'apple'), 'apple does not replace a spotify cover');
check(!cover_may_replace(['cover_path' => 'a.jpg', 'cover_source' => 'upload', 'editorial_locked' => 0], 'spotify'), 'an uploaded cover stays');
check(!cover_may_replace(['cover_path' => 'a.jpg', 'cover_source' => 'deezer', 'editorial_locked' => 1], 'spotify'), 'a locked cover stays');
check(cover_may_replace(['cover_path' => '', 'cover_source' => null, 'editorial_locked' => 0], 'discogs'), 'an empty cover is filled by discogs');
check(App\Apple\Links::largestArtwork('https://is1-ssl.mzstatic.com/image/thumb/Music/source/100x100bb.jpg') === 'https://is1-ssl.mzstatic.com/image/thumb/Music/source/1000x1000bb.jpg', 'apple artwork asks for the 1000px file');
$spotifyKept = $spotifyRelease('spotify-fixture-kept', 'Already Linked');
$db->exec('INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?, "Discogs", "https://www.discogs.com/release/1", "discogs", 0)', [$spotifyKept]);
$db->exec('INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?, "Deezer", "https://www.deezer.com/album/1", "deezer", 0)', [$spotifyKept]);
$db->exec('INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?, "Apple Music", "https://music.apple.com/album/1", "apple", 0)', [$spotifyKept]);
$db->exec('INSERT INTO release_links (release_id, label, url, provider, manual) VALUES (?, "Spotify", "https://open.spotify.com/album/alreadykept01", "spotify", 1)', [$spotifyKept]);
$spotifyOrder = array_column($db->all('SELECT provider FROM release_links WHERE release_id = ? ORDER BY ' . link_order_sql(), [$spotifyKept]), 'provider');
check($spotifyOrder === ['spotify', 'apple', 'deezer', 'discogs'], 'release links are ordered Spotify, Apple, Deezer, Discogs');
$spotifyUpc = $spotifyRelease('spotify-fixture-upc', 'Right Now');
$db->exec('INSERT INTO release_formats (release_id, name, upc) VALUES (?, "Digital", "012345678905")', [$spotifyUpc]);
$db->exec('UPDATE releases SET editorial_locked = 1, cover_source = "upload", cover_path = "covers/manual.jpg" WHERE id = ?', [$spotifyUpc]);
$spotifyTitle = $spotifyRelease('spotify-fixture-title', 'High Tension');
$db->exec('UPDATE releases SET cover_source = "deezer", cover_path = "covers/deezer/1.jpg" WHERE id = ?', [$spotifyTitle]);
$spotifyAmbiguous = $spotifyRelease('spotify-fixture-ambiguous', 'Pandemia', 2019, 'single');
$spotifyTied = $spotifyRelease('spotify-fixture-tied', 'Tied Release');
$spotifyRetry = $spotifyRelease('spotify-fixture-retry', 'Retry Me');
$db->exec(
    'INSERT INTO provider_records (provider, entity_type, external_id, payload_json, fetched_at) VALUES ("spotify","release",?,?,NOW())',
    [(string) $spotifyRetry, json_encode(['results' => []])]
);
$spotifyFresh = $spotifyRelease('spotify-fixture-fresh-miss', 'Fresh Miss');
$db->exec(
    'INSERT INTO provider_records (provider, entity_type, external_id, payload_json, fetched_at) VALUES ("spotify","release",?,?,NOW())',
    [(string) $spotifyFresh, json_encode(['matcher' => 2, 'results' => []])]
);
$spotifyAlbum = static function (string $id, string $name, string $artist, array $extra = []): array {
    return array_merge([
        'name' => $name,
        'external_urls' => ['spotify' => 'https://open.spotify.com/album/' . $id . '?si=tracking'],
        'artists' => [['name' => $artist]],
    ], $extra);
};
$spotify = new FixtureSpotify($db, [
    'upc:012345678905' => [$spotifyAlbum('upc0000000000000000001', 'Right Now', 'Someone Else', ['images' => [['url' => 'https://i.scdn.co/image/locked', 'width' => 640, 'height' => 640]]])],
    'album:"High Tension" artist:"Jünger Fixture"' => [$spotifyAlbum('title000000000000000001', 'High Tension (In Stereo)', 'Junger Fixture', ['images' => [['url' => 'https://i.scdn.co/image/hightension', 'width' => 640, 'height' => 640]]])],
    'album:"Pandemia" artist:"Jünger Fixture"' => [
        $spotifyAlbum('amb1000000000000000001', 'Pandemia', 'Jünger Fixture', ['album_type' => 'album', 'release_date' => '2020-01-01']),
        $spotifyAlbum('amb2000000000000000002', 'Pandemia - Single', 'Junger Fixture', ['album_type' => 'single', 'release_date' => '2019-05-01']),
    ],
    'album:"Tied Release" artist:"Jünger Fixture"' => [
        $spotifyAlbum('tie1000000000000000001', 'Tied Release', 'Jünger Fixture'),
        $spotifyAlbum('tie2000000000000000002', 'Tied Release', 'Junger Fixture'),
    ],
    'album:"Retry Me" artist:"Jünger Fixture"' => [$spotifyAlbum('retry000000000000000001', 'Retry Me', 'Jünger Fixture')],
]);
$spotifyStats = $spotify->run(false, 10);
check($spotifyStats['errors'] === 0 && $spotifyStats['updated'] === 5 && $spotifyStats['skipped'] === 0, 'Spotify links UPC, title, the matching edition, a tie and a previous miss: ' . $spotifyStats['message']);
$spotifyUpcUrl = $db->one('SELECT url FROM release_links WHERE release_id = ? AND provider = "spotify"', [$spotifyUpc]);
$spotifyTitleUrl = $db->one('SELECT url FROM release_links WHERE release_id = ? AND provider = "spotify"', [$spotifyTitle]);
$spotifyAmbiguousUrl = $db->one('SELECT url FROM release_links WHERE release_id = ? AND provider = "spotify"', [$spotifyAmbiguous]);
$spotifyTiedUrl = $db->one('SELECT url FROM release_links WHERE release_id = ? AND provider = "spotify"', [$spotifyTied]);
check(($spotifyUpcUrl['url'] ?? '') === 'https://open.spotify.com/album/upc0000000000000000001', 'UPC match stores the album URL without the tracking query');
check(($spotifyTitleUrl['url'] ?? '') === 'https://open.spotify.com/album/title000000000000000001', 'title match ignores a trailing bracket, accents and a Single suffix');
check(($spotifyAmbiguousUrl['url'] ?? '') === 'https://open.spotify.com/album/amb2000000000000000002', 'two exact Spotify hits: year and type pick the single');
check(($spotifyTiedUrl['url'] ?? '') === 'https://open.spotify.com/album/tie1000000000000000001', 'two equal Spotify hits keep the first result');
check($db->one('SELECT id FROM release_links WHERE release_id = ? AND provider = "spotify"', [$spotifyRetry]) !== null, 'a Spotify miss from the previous matcher is tried again');
check($db->one('SELECT id FROM release_links WHERE release_id = ? AND provider = "spotify"', [$spotifyFresh]) === null, 'a miss from the current matcher stays cached for 30 days');
check($db->one('SELECT id FROM release_links WHERE release_id = ? AND url LIKE "%alreadykept01"', [$spotifyKept]) !== null, 'an existing Spotify link is left alone');
$spotifyTitleCover = $db->one('SELECT cover_source, cover_path FROM releases WHERE id = ?', [$spotifyTitle]);
check(($spotifyTitleCover['cover_source'] ?? '') === 'spotify' && ($spotifyTitleCover['cover_path'] ?? '') === 'covers/spotify/' . $spotifyTitle . '.jpg', 'a Spotify image replaces the Deezer cover');
check(($spotify->storedCovers[$spotifyTitle] ?? '') === 'https://i.scdn.co/image/hightension', 'the largest Spotify image is the one stored');
$spotifyUpcCover = $db->one('SELECT cover_source, cover_path FROM releases WHERE id = ?', [$spotifyUpc]);
check(($spotifyUpcCover['cover_source'] ?? '') === 'upload' && ($spotifyUpcCover['cover_path'] ?? '') === 'covers/manual.jpg', 'a locked upload is not replaced by a Spotify image');
$spotifyQueries = $spotify->queries;
$spotifyAgain = $spotify->run(false, 10);
check($spotifyAgain['updated'] === 0 && $spotify->queries === $spotifyQueries, 'a second Spotify run does not look the same releases up again');
check(count($spotifyQueries) === 5, 'the first run searched the retry, the tie, the pair, the title and the UPC: ' . implode(' | ', $spotifyQueries));
$db->exec('DELETE FROM release_links WHERE release_id = ? AND provider = "spotify"', [$spotifyAmbiguous]);
$db->exec('DELETE FROM provider_records WHERE provider = "spotify" AND entity_type = "release" AND external_id = ?', [(string) $spotifyAmbiguous]);
$spotifyQuota = (new QuotaSpotify($db))->run(false, 1);
check($spotifyQuota['errors'] === 1 && str_contains($spotifyQuota['message'], 'quota'), 'a Spotify quota stop ends the source without deleting links');
check($db->one('SELECT id FROM release_links WHERE release_id = ? AND provider = "spotify"', [$spotifyTitle]) !== null, 'the quota stop keeps links already stored');
$spotifyExpired = (new App\Spotify\Links($db, ['enabled' => true, 'client_id' => 'id', 'client_secret' => 'secret']))->run(true, 1, microtime(true) - 1);
check($spotifyExpired['errors'] === 0 && $spotifyExpired['updated'] === 0 && str_contains($spotifyExpired['message'], 'time budget'), 'Spotify stops before the first request once the time budget is spent');
foreach ([$spotifyKept, $spotifyUpc, $spotifyTitle, $spotifyAmbiguous, $spotifyTied, $spotifyRetry, $spotifyFresh] as $spotifyId) {
    $db->exec('DELETE FROM provider_records WHERE provider = "spotify" AND entity_type = "release" AND external_id = ?', [(string) $spotifyId]);
    $db->exec('DELETE FROM releases WHERE id = ?', [$spotifyId]);
}
$db->exec('DELETE FROM artists WHERE id = ?', [$spotifyArtist]);
$db->exec("DELETE FROM provider_records WHERE provider = 'spotify' AND payload_json = '{\"fixture\":\"shield\"}'");
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
$db->exec('CREATE TABLE IF NOT EXISTS events (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL)');
$db->exec("INSERT INTO events (title) VALUES ('gig')");
$db->exec('CREATE TABLE IF NOT EXISTS documents (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(200) NOT NULL)');
$db->exec("INSERT INTO documents (title) VALUES ('rider'), ('logo'), ('presse')");
$db->exec("INSERT INTO pages (slug, title, body_html, updated_at) VALUES ('radio', 'Radio', '<p>alt</p>', NOW()) ON DUPLICATE KEY UPDATE title = VALUES(title)");
$db->exec("INSERT INTO redirects (source_path, target_path) VALUES ('/test-legacy-news', '/news/alt-1'), ('/test-legacy-radio', '/radio'), ('/test-legacy-events', '/events'), ('/test-legacy-downloads', '/downloads') ON DUPLICATE KEY UPDATE target_path = VALUES(target_path)");
$db->exec("INSERT INTO redirects (source_path, target_path) VALUES ('/test-legacy-keep', '/releases') ON DUPLICATE KEY UPDATE target_path = VALUES(target_path)");
$dryStats = $installer->removeLegacyContent(true);
check($dryStats['errors'] === 0 && str_contains($dryStats['message'], 'news: 2 Beiträge, Tabelle würde entfernt'), 'dry run counts the news posts');
check($db->pdo()->query("SHOW TABLES LIKE 'news'")->fetch() !== false, 'dry run keeps the news table');
check($db->one("SELECT 1 FROM pages WHERE slug = 'radio'") !== null, 'dry run keeps the radio page');
$realStats = $installer->removeLegacyContent(false);
check(
    $realStats['errors'] === 0
    && str_contains($realStats['message'], 'news: 2 Beiträge, Tabelle entfernt')
    && str_contains($realStats['message'], 'events: 1 Termine, Tabelle entfernt')
    && str_contains($realStats['message'], 'documents: 3 Dateien, Tabelle entfernt')
    && str_contains($realStats['message'], 'radio: Seite entfernt')
    && str_contains($realStats['message'], 'redirects: 4 entfernt'),
    'real run reports the removal: ' . $realStats['message']
);
check($db->pdo()->query("SHOW TABLES LIKE 'news'")->fetch() === false, 'news table is dropped');
check($db->pdo()->query("SHOW TABLES LIKE 'events'")->fetch() === false && $db->pdo()->query("SHOW TABLES LIKE 'documents'")->fetch() === false, 'events and documents tables are dropped');
check($db->one("SELECT 1 FROM pages WHERE slug = 'radio'") === null, 'radio page is deleted');
check($db->one("SELECT 1 FROM redirects WHERE source_path IN ('/test-legacy-news', '/test-legacy-radio', '/test-legacy-events', '/test-legacy-downloads')") === null, 'news, radio, events and downloads redirects are deleted');
check($db->one("SELECT 1 FROM redirects WHERE source_path = '/test-legacy-keep'") !== null, 'other redirects survive');
$againStats = $installer->removeLegacyContent(false);
check($againStats['errors'] === 0 && $againStats['updated'] === 0 && str_contains($againStats['message'], 'bereits entfernt'), 'second run is a no-op');
$db->exec("DELETE FROM redirects WHERE source_path = '/test-legacy-keep'");

// --- Admin release overview: grouped by lead artist, filters --------------
$adminView = new App\Admin(app_config(), $db, new App\Auth($db));
$leadName = $marker . ' Lead';
$sideName = $marker . ' Side';
$leadId = $db->insert('INSERT INTO artists (slug, name, status, image_source, created_at, updated_at) VALUES (?, ?, "published", "legacy", NOW(), NOW())', [slugify($leadName) . bin2hex(random_bytes(2)), $leadName]);
$sideId = $db->insert('INSERT INTO artists (slug, name, status, image_source, created_at, updated_at) VALUES (?, ?, "published", "legacy", NOW(), NOW())', [slugify($sideName) . bin2hex(random_bytes(2)), $sideName]);
$adminRelease = static function (string $title, string $status, ?int $year, ?string $type) use ($db, $marker): int {
    return $db->insert(
        'INSERT INTO releases (slug, title, status, source, release_year, release_type, release_date_precision, created_at, updated_at) VALUES (?, ?, ?, "editorial", ?, ?, "year", NOW(), NOW())',
        [slugify($title) . bin2hex(random_bytes(2)), $marker . ' ' . $title, $status, $year, $type]
    );
};
$sharedId = $adminRelease('Shared', 'published', 2020, 'single');
$sideOnlyId = $adminRelease('Sideonly', 'published', 2019, 'album');
$orphanId = $adminRelease('Orphan', 'draft', null, null);
$db->exec('INSERT INTO release_artists (release_id, artist_id, position) VALUES (?, ?, 0), (?, ?, 1)', [$sharedId, $leadId, $sharedId, $sideId]);
$db->exec('INSERT INTO release_artists (release_id, artist_id, position) VALUES (?, ?, 0)', [$sideOnlyId, $sideId]);
$overview = $adminView->releaseOverview(['q' => $marker]);
$names = array_column($overview['groups'], 'name');
check($overview['total'] === 3 && $names === [$leadName, $sideName, 'Ohne Artist'], 'admin overview groups by the first artist, uncredited last: ' . implode(' | ', $names));
check($overview['groups'][0]['releases'][0]['title'] === $marker . ' Shared' && $overview['groups'][0]['releases'][0]['artists'] === [$leadName, $sideName], 'a shared release is listed once, under the first artist');
$bySide = $adminView->releaseOverview(['q' => $marker, 'artist' => (string) $sideId]);
$sideTitles = [];
foreach ($bySide['groups'] as $group) {
    foreach ($group['releases'] as $release) {
        $sideTitles[] = $release['title'];
    }
}
sort($sideTitles);
check($sideTitles === [$marker . ' Shared', $marker . ' Sideonly'], 'artist filter includes releases where that artist is not first');
$drafts = $adminView->releaseOverview(['q' => $marker, 'status' => 'draft', 'type' => 'album', 'year' => '2019']);
check($drafts['total'] === 0, 'status, type and year filters combine');
$albums = $adminView->releaseOverview(['q' => $marker, 'type' => 'album', 'year' => '2019']);
check($albums['total'] === 1 && $albums['groups'][0]['name'] === $sideName, 'type and year filter the grouped list');
$renderScript = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1] . '/src/bootstrap.php';
$payload = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    if (($severity & (E_DEPRECATED | E_WARNING | E_USER_DEPRECATED)) !== 0) {
        $warnings[] = $message;
        return true;
    }
    return false;
});
$view = new App\View($argv[1] . '/templates', ['baseUrl' => 'http://localhost', 'adminUser' => null]);
$html = $view->partial('admin/releases', $payload);
echo json_encode([
    'warnings' => $warnings,
    'hasTitle' => str_contains($html, 'Ohne Typ'),
], JSON_THROW_ON_ERROR);
PHP;
$renderFile = tempnam(sys_get_temp_dir(), 'bs-null-offset');
file_put_contents((string) $renderFile, $renderScript);
$renderPayload = json_encode([
    'groups' => [[
        'id' => null,
        'name' => 'Ohne Artist',
        'releases' => [[
            'id' => 1,
            'title' => 'Ohne Typ',
            'status' => null,
            'featured' => 0,
            'source' => null,
            'year' => null,
            'type' => null,
            'artists' => [],
        ]],
    ]],
    'total' => 1,
    'filters' => ['q' => '', 'artist' => 0, 'status' => '', 'type' => '', 'year' => 0],
    'artists' => [],
    'years' => [],
], JSON_THROW_ON_ERROR);
$renderProc = proc_open(
    [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', (string) $renderFile, app_root(), $renderPayload],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $renderPipes
);
$renderOut = stream_get_contents($renderPipes[1]);
$renderErr = stream_get_contents($renderPipes[2]);
fclose($renderPipes[1]);
fclose($renderPipes[2]);
$renderCode = proc_close($renderProc);
@unlink((string) $renderFile);
$renderResult = json_decode($renderOut, true);
$renderWarnings = is_array($renderResult['warnings'] ?? null) ? implode("\n", $renderResult['warnings']) : $renderErr;
check(
    $renderCode === 0
    && ($renderResult['hasTitle'] ?? false) === true
    && !str_contains($renderWarnings, 'null as an array offset'),
    'admin overview renders a release with an empty type without a null offset: ' . $renderWarnings
);
foreach ([$sharedId, $sideOnlyId, $orphanId] as $dropId) {
    $db->exec('DELETE FROM releases WHERE id = ?', [$dropId]);
}
$db->exec('DELETE FROM artists WHERE id IN (?, ?)', [$leadId, $sideId]);

echo $failed === 0 ? "ALL PASSED\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
