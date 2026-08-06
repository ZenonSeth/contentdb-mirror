<?php
// GET /api/packages/<author>/<name>/dependencies/?only_hard=1&protocol_version=<N>&engine_version=<ver>
// Dependency graph with suggested candidate packages, the shape the Luanti
// client needs to resolve installs. Confirmed live against
// content.luanti.org (rudzik8/mcl_decor, 2026-08-05): a flat JSON object
// keyed by every "author/name" reachable in the graph starting from the
// requested package (root package included), each value a list of
// {is_optional, name, packages: [author/name, ...]} - "packages" being the
// candidates that provide that dependency name. We resolve candidates
// ourselves from package_provides/package_depends (synced via
// sync_dependencies() in cron/sync.php - see doc/improvements.md item #2)
// the same way ContentDB does it upstream, rather than mirroring an
// already-resolved response.

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/compat.php';

header('Content-Type: application/json');

$pdo = cdb_mirror_db();
$author = $_GET['author'] ?? '';
$name = $_GET['name'] ?? '';
$onlyHard = isset($_GET['only_hard']);
$protocolVersion = isset($_GET['protocol_version']) ? (int) $_GET['protocol_version'] : null;
$engineVersion = $_GET['engine_version'] ?? null;
$effectiveVersion = cdb_resolve_effective_engine_version($pdo, $engineVersion, $protocolVersion);

$stmt = $pdo->prepare('SELECT id FROM packages WHERE cdb_author = ? AND cdb_name = ?');
$stmt->execute([$author, $name]);
$rootId = $stmt->fetchColumn();
if (!$rootId) {
    http_response_code(404);
    echo json_encode(['error' => 'Package not found']);
    exit;
}

$dependsStmt = $pdo->prepare(
    'SELECT name, is_optional FROM package_depends WHERE package_id = ?'
    . ($onlyHard ? ' AND is_optional = 0' : '')
);

// Candidates providing a plain technical modname: any package (compatible,
// not removed, with a synced release) that lists it in package_provides.
$candidatesByProvidesStmt = $pdo->prepare(
    'SELECT p.id, p.cdb_author, p.cdb_name, r.min_engine_version, r.max_engine_version
     FROM package_provides pv
     JOIN packages p ON p.id = pv.package_id
     JOIN releases r ON r.id = p.latest_release_id
     WHERE pv.name = ? AND p.is_removed_upstream = 0'
);

// Candidate for a pinned "author/name" dependency: that exact package, if
// it exists, is not removed, and has a synced release.
$candidateByAuthorNameStmt = $pdo->prepare(
    'SELECT p.id, p.cdb_author, p.cdb_name, r.min_engine_version, r.max_engine_version
     FROM packages p
     JOIN releases r ON r.id = p.latest_release_id
     WHERE p.cdb_author = ? AND p.cdb_name = ? AND p.is_removed_upstream = 0'
);

$keyStmt = $pdo->prepare('SELECT cdb_author, cdb_name FROM packages WHERE id = ?');

$result = [];
$visited = [$rootId => true];
$queue = [$rootId];

while ($queue) {
    $packageId = array_shift($queue);

    $keyStmt->execute([$packageId]);
    $pkg = $keyStmt->fetch();
    $key = $pkg['cdb_author'] . '/' . $pkg['cdb_name'];

    $dependsStmt->execute([$packageId]);
    $deps = [];
    foreach ($dependsStmt->fetchAll() as $dep) {
        if (strpos($dep['name'], '/') !== false) {
            [$depAuthor, $depName] = explode('/', $dep['name'], 2);
            $candidateByAuthorNameStmt->execute([$depAuthor, $depName]);
            $candidateRows = $candidateByAuthorNameStmt->fetchAll();
        } else {
            $candidatesByProvidesStmt->execute([$dep['name']]);
            $candidateRows = $candidatesByProvidesStmt->fetchAll();
        }

        $candidates = [];
        foreach ($candidateRows as $row) {
            if (!cdb_engine_compatible($effectiveVersion, $row['min_engine_version'], $row['max_engine_version'])) {
                continue;
            }
            $candidates[] = $row['cdb_author'] . '/' . $row['cdb_name'];
            if (!isset($visited[$row['id']])) {
                $visited[$row['id']] = true;
                $queue[] = $row['id'];
            }
        }

        $deps[] = [
            'is_optional' => (bool) $dep['is_optional'],
            'name' => $dep['name'],
            'packages' => $candidates,
        ];
    }

    $result[$key] = $deps;
}

echo json_encode($result, JSON_UNESCAPED_SLASHES);
