<?php
// GET /api/packages/<author>/<name>/for-client/reviews/
// Reviews are out of scope for this mirror (see doc/improvements.md item
// #2, endpoint 4) - always return the same hardcoded "no reviews" hypertext
// ContentDB itself returns for a review-less package, with link_1 pointing
// back to the real ContentDB review page since that's the only place a
// review can actually be left.

require_once __DIR__ . '/../../lib/db.php';

header('Content-Type: application/json');

$pdo = cdb_mirror_db();
$author = $_GET['author'] ?? '';
$name = $_GET['name'] ?? '';

$stmt = $pdo->prepare('SELECT 1 FROM packages WHERE cdb_author = ? AND cdb_name = ?');
$stmt->execute([$author, $name]);
if (!$stmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['error' => 'Package not found']);
    exit;
}

$reviewUrl = 'https://content.luanti.org/packages/' . rawurlencode($author) . '/' . rawurlencode($name) . '/review/';

echo json_encode([
    'body' => "<action name=link_1>Leave a review</action>\n\nNo reviews available.",
    'head' => '<tag name=code color=#7bf font=mono> <tag name=action color=#4CDAFA hovercolor=#97EAFC>',
    'image_tooltips' => new stdClass(),
    'images' => new stdClass(),
    'links' => ['link_1' => $reviewUrl],
], JSON_UNESCAPED_SLASHES);
