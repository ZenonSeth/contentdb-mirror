<?php
// GET /api/packages/<author>/<name>/for-client/?protocol_version=<N>&engine_version=<ver>
//     &formspec_version=<N>&include_images=false
// Full package info fetched lazily by the Luanti client when viewing
// package details. Confirmed live shape against content.luanti.org
// (rudzik8/mcl_decor, 2026-08-05) - see doc/improvements.md item #3.
//
// protocol_version/engine_version/formspec_version/include_images are
// accepted but not used to filter anything here: since item #1 we only
// ever keep the single latest release per package, so there is no other
// release to choose between by compatibility, and include_images is
// always treated as false (images rendered as links - see lib/hypertext.php).
//
// reviews are always the hardcoded zero-counts, same decision as item #4
// (reviews are out of scope for this mirror). game_support is passed
// through verbatim from the blob stored at sync time (upstream's own
// resolved confidence/candidate data, including upstream URLs for the
// referenced games) rather than remapped to mirror URLs.

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/hypertext.php';

header('Content-Type: application/json');

$pdo = cdb_mirror_db();
$author = $_GET['author'] ?? '';
$name = $_GET['name'] ?? '';

$stmt = $pdo->prepare(
    'SELECT p.*, r.id AS release_id, r.size_bytes
     FROM packages p
     LEFT JOIN releases r ON r.id = p.latest_release_id
     WHERE p.cdb_author = ? AND p.cdb_name = ?'
);
$stmt->execute([$author, $name]);
$pkg = $stmt->fetch();

if (!$pkg) {
    http_response_code(404);
    echo json_encode(['error' => 'Package not found']);
    exit;
}

$tagStmt = $pdo->prepare('SELECT t.name FROM tags t JOIN package_tags pt ON pt.tag_id = t.id WHERE pt.package_id = ? ORDER BY t.name');
$tagStmt->execute([$pkg['id']]);
$tags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

$shotStmt = $pdo->prepare('SELECT local_path FROM screenshots WHERE package_id = ? ORDER BY sort_order');
$shotStmt->execute([$pkg['id']]);
$screenshotPaths = $shotStmt->fetchAll(PDO::FETCH_COLUMN);

$providesStmt = $pdo->prepare('SELECT name FROM package_provides WHERE package_id = ?');
$providesStmt->execute([$pkg['id']]);
$provides = $providesStmt->fetchAll(PDO::FETCH_COLUMN);

$dependsStmt = $pdo->prepare('SELECT name, is_optional FROM package_depends WHERE package_id = ?');
$dependsStmt->execute([$pkg['id']]);
$hardDepends = [];
$optionalDepends = [];
foreach ($dependsStmt->fetchAll() as $dep) {
    if ($dep['is_optional']) {
        $optionalDepends[] = $dep['name'];
    } else {
        $hardDepends[] = $dep['name'];
    }
}

$gameSupport = json_decode($pkg['game_support'] ?? '[]', true) ?: [];
$supportedGames = array_map(
    fn($g) => $g['game'],
    array_filter($gameSupport, fn($g) => !empty($g['supports']))
);

function format_download_size(?string $bytes): ?string
{
    if ($bytes === null) {
        return null;
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return round($value, ($i === 0) ? 0 : 1) . ' ' . $units[$i];
}

function iso_datetime(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    return (new DateTime($value))->format('Y-m-d\TH:i:s');
}

$base = 'https://' . $_SERVER['HTTP_HOST'];
$maintainers = json_decode($pkg['maintainers'] ?? '[]', true) ?: [];
$contentWarnings = json_decode($pkg['content_warnings'] ?? '[]', true) ?: [];

$infoHypertext = cdb_build_info_hypertext($pkg, $tags, $supportedGames, $hardDepends, $optionalDepends, $provides);
$longDescription = cdb_html_to_hypertext($pkg['long_description_html'] ?? '');

echo json_encode([
    'ai_disclosure' => $pkg['ai_disclosure'],
    'approved_at' => iso_datetime($pkg['cdb_approved_at']),
    'author' => $pkg['cdb_author'],
    'content_warnings' => $contentWarnings,
    'created_at' => iso_datetime($pkg['cdb_created_at']),
    'dev_state' => $pkg['dev_state'],
    'donate_url' => $pkg['donate_url'],
    'download_size' => format_download_size($pkg['size_bytes']),
    'downloads' => (int) $pkg['downloads'],
    'forum_url' => $pkg['forum_url'],
    'forums' => null,
    'game_support' => $gameSupport,
    'info_hypertext' => [
        'body' => $infoHypertext['body'],
        'head' => CDB_HYPERTEXT_HEAD,
        'image_tooltips' => new stdClass(),
        'images' => new stdClass(),
        'links' => (object) $infoHypertext['links'],
    ],
    'issue_tracker' => $pkg['issue_tracker'],
    'license' => $pkg['license'],
    'long_description' => [
        'body' => $longDescription['body'],
        'head' => CDB_HYPERTEXT_HEAD,
        'image_tooltips' => new stdClass(),
        'images' => new stdClass(),
        'links' => (object) $longDescription['links'],
    ],
    'maintainers' => $maintainers,
    'media_license' => $pkg['media_license'],
    'name' => $pkg['cdb_name'],
    'provides' => $provides,
    'release' => $pkg['release_id'] !== null ? (int) $pkg['release_id'] : null,
    'repo' => $pkg['repo'],
    'reviews' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
    'score' => $pkg['score'] !== null ? (float) $pkg['score'] : null,
    'screenshots' => array_map(fn($p) => ['title' => '', 'url' => $base . '/' . ltrim($p, '/')], $screenshotPaths),
    'short_description' => $pkg['short_description'],
    'state' => $pkg['state'],
    'supports_all_games' => (bool) $pkg['supports_all_games'],
    'tags' => $tags,
    'thumbnail' => $pkg['thumbnail_local_path'] ? $base . '/' . ltrim($pkg['thumbnail_local_path'], '/') : null,
    'title' => $pkg['title'],
    'translation_url' => $pkg['translation_url'],
    'type' => $pkg['type'],
    'url' => $pkg['release_id'] !== null
        ? $base . '/packages/' . rawurlencode($pkg['cdb_author']) . '/' . rawurlencode($pkg['cdb_name']) . '/releases/' . $pkg['release_id'] . '/download/'
        : null,
    'video_thumbnail_url' => $pkg['video_thumbnail_url'],
    'video_url' => $pkg['video_url'],
    'website' => $pkg['website'],
], JSON_UNESCAPED_SLASHES);
