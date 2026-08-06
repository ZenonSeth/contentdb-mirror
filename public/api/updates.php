<?php
// GET /api/updates/?type=mod&type=game&type=txp&protocol_version=<N>
// Flat {"author/name": latest_release_id} lookup table, filtered by
// package type and, if given, protocol_version/engine_version
// compatibility. Same shape cron/sync.php itself parses when polling
// upstream - this is the client polling us instead.

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

$types = multi_query_param('type');
$protocolVersion = isset($_GET['protocol_version']) ? (int) $_GET['protocol_version'] : null;
$engineVersion = $_GET['engine_version'] ?? null;

$effectiveVersion = cdb_resolve_effective_engine_version($pdo, $engineVersion, $protocolVersion);

$sql = 'SELECT p.cdb_author, p.cdb_name, r.id AS release_id, r.min_engine_version, r.max_engine_version
        FROM packages p
        JOIN releases r ON r.id = p.latest_release_id';
$params = [];
if ($types) {
    $sql .= ' WHERE p.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
    $params = $types;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$result = [];
foreach ($stmt->fetchAll() as $row) {
    if (!cdb_engine_compatible($effectiveVersion, $row['min_engine_version'], $row['max_engine_version'])) {
        continue;
    }
    $result[$row['cdb_author'] . '/' . $row['cdb_name']] = (int) $row['release_id'];
}

echo json_encode($result, JSON_FORCE_OBJECT);
