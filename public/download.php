<?php
// GET /packages/<author>/<name>/releases/<release_id>/download/
// Maps the upstream ContentDB release-download URL shape to our locally
// mirrored release file (see doc/improvements.md item #2, endpoint #6).

require_once __DIR__ . '/../lib/db.php';

$pdo = cdb_mirror_db();
$author = $_GET['author'] ?? '';
$name = $_GET['name'] ?? '';
$releaseId = (int) ($_GET['release'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT r.local_path
     FROM releases r
     JOIN packages p ON p.id = r.package_id
     WHERE r.id = ? AND p.cdb_author = ? AND p.cdb_name = ?'
);
$stmt->execute([$releaseId, $author, $name]);
$localPath = $stmt->fetchColumn();

if (!$localPath) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Release not found';
    exit;
}

header('Location: /' . ltrim($localPath, '/'));
