<?php
// GET /api/packages/?type=mod&type=game&type=txp&q=<query>&author=<author>
//     &tag=<tag>&protocol_version=<N>&engine_version=<ver>
// Flat JSON array of package objects, the shape the Luanti client's content
// browser (contentdb.fetch_pkgs) expects - confirmed against the real
// content.luanti.org response (no fmt= given): author, name, title,
// short_description, type, release, thumbnail, aliases. Packages without a
// synced release, or flagged is_removed_upstream, are excluded - nothing to
// install for either. `hide` (content-flag filtering upstream) is accepted
// but ignored: we don't mirror content flags, so there's nothing to filter.

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/compat.php';

header('Content-Type: application/json');

// PHP's $_GET collapses repeated non-bracketed keys (type=mod&type=game)
// down to just the last value, but that's exactly the shape the client
// sends - so read every value straight out of QUERY_STRING instead.
function multi_query_param(string $name): array
{
    $values = [];
    foreach (explode('&', $_SERVER['QUERY_STRING'] ?? '') as $pair) {
        if ($pair === '') {
            continue;
        }
        [$key, $val] = array_pad(explode('=', $pair, 2), 2, '');
        if (urldecode($key) === $name) {
            $values[] = urldecode($val);
        }
    }
    return $values;
}

$pdo = cdb_mirror_db();

$types = array_values(array_intersect(multi_query_param('type'), ['mod', 'game', 'txp']));
$q = trim($_GET['q'] ?? '');
$author = trim($_GET['author'] ?? '');
$tag = trim($_GET['tag'] ?? '');
$protocolVersion = isset($_GET['protocol_version']) ? (int) $_GET['protocol_version'] : null;
$engineVersion = $_GET['engine_version'] ?? null;

$effectiveVersion = cdb_resolve_effective_engine_version($pdo, $engineVersion, $protocolVersion);

$where = ['p.is_removed_upstream = 0'];
$params = [];

if ($types) {
    $where[] = 'p.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
    array_push($params, ...$types);
}

if ($q !== '') {
    $boolQuery = implode(' ', array_map(
        fn($word) => '+' . str_replace(['+', '-', '*', '"'], '', $word) . '*',
        preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY)
    ));
    $where[] = '(MATCH(p.title, p.short_description) AGAINST (? IN BOOLEAN MODE)
        OR p.id IN (SELECT pt.package_id FROM package_tags pt JOIN tags t ON t.id = pt.tag_id WHERE t.name LIKE ?))';
    $params[] = $boolQuery;
    $params[] = '%' . $q . '%';
}

if ($author !== '') {
    $where[] = 'p.cdb_author = ?';
    $params[] = $author;
}

if ($tag !== '') {
    $where[] = 'p.id IN (SELECT pt.package_id FROM package_tags pt JOIN tags t ON t.id = pt.tag_id WHERE t.name = ?)';
    $params[] = $tag;
}

$whereSql = implode(' AND ', $where);

$sql = "SELECT p.cdb_author, p.cdb_name, p.title, p.short_description, p.type,
               p.thumbnail_local_path, r.id AS release_id, r.min_engine_version, r.max_engine_version
        FROM packages p
        JOIN releases r ON r.id = p.latest_release_id
        WHERE $whereSql
        ORDER BY p.score DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$base = 'https://' . $_SERVER['HTTP_HOST'];

$result = [];
foreach ($stmt->fetchAll() as $row) {
    if (!cdb_engine_compatible($effectiveVersion, $row['min_engine_version'], $row['max_engine_version'])) {
        continue;
    }
    $result[] = [
        'aliases' => [],
        'author' => $row['cdb_author'],
        'name' => $row['cdb_name'],
        'release' => (int) $row['release_id'],
        'short_description' => $row['short_description'],
        'thumbnail' => $row['thumbnail_local_path'] ? $base . '/' . ltrim($row['thumbnail_local_path'], '/') : null,
        'title' => $row['title'],
        'type' => $row['type'],
    ];
}

echo json_encode($result, JSON_UNESCAPED_SLASHES);
