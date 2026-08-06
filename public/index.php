<?php

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$pdo = cdb_mirror_db();

$q = trim($_GET['q'] ?? '');
$type = in_array($_GET['type'] ?? '', ['mod', 'game', 'txp'], true) ? $_GET['type'] : '';
$tag = trim($_GET['tag'] ?? '');
$sort = $_GET['sort'] ?? 'score';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 24;

$sortColumns = [
    'score' => 'p.score DESC',
    'downloads' => 'p.downloads DESC',
    'title' => 'p.title ASC',
    'newest' => 'p.cdb_created_at DESC',
];
$orderBy = $sortColumns[$sort] ?? $sortColumns['score'];

$where = ['1=1'];
$params = [];

if ($q !== '') {
    $boolQuery = implode(' ', array_map(
        fn($word) => '+' . str_replace(['+', '-', '*', '"'], '', $word) . '*',
        preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY)
    ));
    $where[] = '(MATCH(p.title, p.short_description) AGAINST (:boolq IN BOOLEAN MODE)
        OR p.id IN (SELECT pt.package_id FROM package_tags pt JOIN tags t ON t.id = pt.tag_id WHERE t.name LIKE :qlike))';
    $params['boolq'] = $boolQuery;
    $params['qlike'] = '%' . $q . '%';
}

if ($type !== '') {
    $where[] = 'p.type = :type';
    $params['type'] = $type;
}

if ($tag !== '') {
    $where[] = 'p.id IN (SELECT pt.package_id FROM package_tags pt JOIN tags t ON t.id = pt.tag_id WHERE t.name = :tag)';
    $params['tag'] = $tag;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM packages p WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT p.* FROM packages p WHERE $whereSql ORDER BY $orderBy LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$packages = $stmt->fetchAll();

$tags = $pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$returnQuery = $_SERVER['QUERY_STRING'] ?? '';

function build_query(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
}

layout_header('Luanti ContentDB Mirror', 'Search and browse a read-only mirror of Luanti ContentDB packages.');
?>

<form class="search-bar" method="get" action="/index.php">
    <input type="text" name="q" placeholder="Search title, description or tags..." value="<?= h($q) ?>">
    <select name="type">
        <option value="">All types</option>
        <option value="mod" <?= $type === 'mod' ? 'selected' : '' ?>>Mods</option>
        <option value="game" <?= $type === 'game' ? 'selected' : '' ?>>Games</option>
        <option value="txp" <?= $type === 'txp' ? 'selected' : '' ?>>Texture packs</option>
    </select>
    <select name="tag">
        <option value="">All tags</option>
        <?php foreach ($tags as $t): ?>
            <option value="<?= h($t) ?>" <?= $tag === $t ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="sort">
        <option value="score" <?= $sort === 'score' ? 'selected' : '' ?>>Top rated</option>
        <option value="downloads" <?= $sort === 'downloads' ? 'selected' : '' ?>>Most downloaded</option>
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
        <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title A-Z</option>
    </select>
    <button type="submit">Search</button>
</form>

<p><?= number_format($total) ?> package<?= $total === 1 ? '' : 's' ?> found.</p>

<div class="package-grid">
<?php foreach ($packages as $p): ?>
    <a class="package-card" href="<?= h(package_url($p['cdb_author'], $p['cdb_name'], $returnQuery)) ?>">
        <?php $thumb = asset_url($p['thumbnail_local_path']); ?>
        <?php if ($thumb): ?>
            <img class="thumb" src="<?= h($thumb) ?>" alt="<?= h($p['title']) ?>" loading="lazy">
        <?php else: ?>
            <div class="thumb"></div>
        <?php endif; ?>
        <div class="body">
            <span class="badge type-<?= h($p['type']) ?>"><?= h(type_label($p['type'])) ?></span>
            <?php if ($p['is_removed_upstream']): ?><span class="badge removed">removed upstream</span><?php endif; ?>
            <h3><?= h($p['title']) ?></h3>
            <div class="meta">by <?= h($p['cdb_author']) ?></div>
            <div class="desc"><?= h($p['short_description']) ?></div>
        </div>
    </a>
<?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?><a href="<?= h(build_query(['page' => $page - 1])) ?>">&laquo; Prev</a><?php endif; ?>
    <span class="current">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="<?= h(build_query(['page' => $page + 1])) ?>">Next &raquo;</a><?php endif; ?>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
