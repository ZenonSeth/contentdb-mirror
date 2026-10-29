<?php

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$pdo = cdb_mirror_db();

// Releases withheld after the Oct 2026 ContentDB incident; their files are moved out of public/.
$quarantinedReleases = [
    39631 => 'Temporarily withheld',
    39672 => 'Temporarily withheld',
    35058 => 'Temporarily withheld',
    39626 => 'Temporarily withheld',
    39647 => 'Temporarily withheld',
    39651 => 'Temporarily withheld',
    39633 => 'Temporarily withheld',
    39641 => 'Temporarily withheld',
    39646 => 'Temporarily withheld',
    39670 => 'Temporarily withheld',
    39673 => 'Temporarily withheld',
];

$author = $_GET['author'] ?? '';
$name = $_GET['name'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM packages WHERE cdb_author = ? AND cdb_name = ?');
$stmt->execute([$author, $name]);
$pkg = $stmt->fetch();

if (!$pkg) {
    $backQuery404 = $_GET['back'] ?? '';
    $backUrl404 = '/index.php' . ($backQuery404 !== '' ? '?' . $backQuery404 : '');
    http_response_code(404);
    layout_header('Package not found');
    echo '<p>No such package in the mirror.</p><p><a href="' . h($backUrl404) . '">Back to search</a></p>';
    layout_footer();
    exit;
}

$tagStmt = $pdo->prepare(
    'SELECT t.name FROM tags t JOIN package_tags pt ON pt.tag_id = t.id WHERE pt.package_id = ? ORDER BY t.name'
);
$tagStmt->execute([$pkg['id']]);
$tags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

$shotStmt = $pdo->prepare('SELECT * FROM screenshots WHERE package_id = ? ORDER BY sort_order');
$shotStmt->execute([$pkg['id']]);
$screenshots = $shotStmt->fetchAll();

$relStmt = $pdo->prepare('SELECT * FROM releases WHERE package_id = ? ORDER BY release_date DESC, id DESC');
$relStmt->execute([$pkg['id']]);
$releases = $relStmt->fetchAll();

$backQuery = $_GET['back'] ?? '';
$backUrl = '/index.php' . ($backQuery !== '' ? '?' . $backQuery : '');

layout_header($pkg['title'] . ' - ContentDB Mirror', $pkg['short_description']);
?>

<p><a href="<?= h($backUrl) ?>">&laquo; Back to search</a></p>

<div class="package-detail">
    <span class="badge type-<?= h($pkg['type']) ?>"><?= h(type_label($pkg['type'])) ?></span>
    <?php if ($pkg['is_removed_upstream']): ?>
        <span class="badge removed">No longer listed on ContentDB</span>
    <?php endif; ?>

    <h2><?= h($pkg['title']) ?></h2>
    <p class="meta">
        by <?= h($pkg['cdb_author']) ?>
        <?php if ($pkg['score'] !== null): ?>&middot; score <?= number_format((float) $pkg['score'], 1) ?><?php endif; ?>
        <?php if ($pkg['license']): ?>&middot; license: <?= h($pkg['license']) ?><?php endif; ?>
    </p>

    <?php $thumb = asset_url($pkg['thumbnail_local_path']); ?>
    <?php if ($thumb): ?>
        <img class="thumb-large" src="<?= h($thumb) ?>" alt="<?= h($pkg['title']) ?>">
    <?php endif; ?>

    <?php if ($releases && isset($quarantinedReleases[(int) $releases[0]['id']])): ?>
        <p class="download-cta"><span class="badge removed"><?= h($quarantinedReleases[(int) $releases[0]['id']]) ?></span></p>
    <?php elseif ($releases && ($latestDl = asset_url($releases[0]['local_path']))): ?>
        <p class="download-cta"><a class="btn-download" href="<?= h($latestDl) ?>">Download latest release</a></p>
    <?php endif; ?>

    <p><?= h($pkg['short_description']) ?></p>

    <?php if ($tags): ?>
        <p>
            <?php foreach ($tags as $t): ?>
                <a class="badge" href="/index.php?tag=<?= urlencode($t) ?>"><?= h($t) ?></a>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <?php if ($screenshots): ?>
        <div class="screenshot-gallery">
            <?php foreach ($screenshots as $s): ?>
                <?php $url = asset_url($s['local_path']); ?>
                <?php if ($url): ?><img class="gallery-thumb" src="<?= h($url) ?>" alt="screenshot" loading="lazy"><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="lightbox" id="lightbox" hidden>
            <img id="lightbox-img" src="" alt="">
        </div>
    <?php endif; ?>

    <div class="tabs">
        <button type="button" class="tab-btn active" data-tab="description">Description</button>
        <button type="button" class="tab-btn" data-tab="info">Info</button>
        <button type="button" class="tab-btn" data-tab="download">Download</button>
    </div>

    <div class="tab-panel" id="tab-description">
        <?php if ($pkg['long_description_html']): ?>
            <div class="long-description">
                <?= sanitize_description_html($pkg['long_description_html']) ?>
            </div>
        <?php else: ?>
            <p>No description available.</p>
        <?php endif; ?>
    </div>

    <div class="tab-panel" id="tab-info" hidden>
        <h3>Links</h3>
        <ul class="links-list">
            <?php if ($pkg['repo']): ?><li>Source: <a href="<?= h($pkg['repo']) ?>"><?= h($pkg['repo']) ?></a></li><?php endif; ?>
            <?php if ($pkg['forum_url']): ?><li>Forum: <a href="<?= h($pkg['forum_url']) ?>"><?= h($pkg['forum_url']) ?></a></li><?php endif; ?>
            <?php if ($pkg['issue_tracker']): ?><li>Issues: <a href="<?= h($pkg['issue_tracker']) ?>"><?= h($pkg['issue_tracker']) ?></a></li><?php endif; ?>
            <?php if ($pkg['website']): ?><li>Website: <a href="<?= h($pkg['website']) ?>"><?= h($pkg['website']) ?></a></li><?php endif; ?>
            <?php if ($pkg['donate_url']): ?><li>Donate: <a href="<?= h($pkg['donate_url']) ?>"><?= h($pkg['donate_url']) ?></a></li><?php endif; ?>
            <li>Original ContentDB page: <a href="https://content.luanti.org/packages/<?= h($pkg['cdb_author']) ?>/<?= h($pkg['cdb_name']) ?>/">content.luanti.org/packages/<?= h($pkg['cdb_author']) ?>/<?= h($pkg['cdb_name']) ?>/</a></li>
        </ul>

        <h3>License</h3>
        <p><?= h($pkg['license'] ?: 'Not specified') ?></p>
        <?php if ($pkg['media_license'] && $pkg['media_license'] !== $pkg['license']): ?>
            <p>Media license: <?= h($pkg['media_license']) ?></p>
        <?php endif; ?>

        <?php if ($pkg['dev_state']): ?>
            <h3>Development state</h3>
            <p><?= h($pkg['dev_state']) ?></p>
        <?php endif; ?>
    </div>

    <div class="tab-panel" id="tab-download" hidden>
        <?php if ($releases): ?>
            <div class="table-scroll">
            <table class="releases">
                <thead>
                    <tr><th>Version</th><th>Date</th><th>Min engine</th><th>Size</th><th>Download</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($releases as $r): ?>
                        <tr>
                            <td><?= h($r['title'] ?: $r['name']) ?></td>
                            <td><?= h($r['release_date']) ?></td>
                            <td><?= h($r['min_engine_version']) ?></td>
                            <td><?= format_bytes($r['size_bytes'] !== null ? (int) $r['size_bytes'] : null) ?></td>
                            <td>
                                <?php $dl = asset_url($r['local_path']); ?>
                                <?php if (isset($quarantinedReleases[(int) $r['id']])): ?>
                                    withheld
                                <?php elseif ($dl): ?>
                                    <a href="<?= h($dl) ?>">Download</a>
                                <?php else: ?>
                                    unavailable
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <p>No releases mirrored yet.</p>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
        document.querySelectorAll('.tab-panel').forEach(function (p) { p.hidden = true; });
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).hidden = false;
    });
});

var lightbox = document.getElementById('lightbox');
if (lightbox) {
    var lightboxImg = document.getElementById('lightbox-img');
    document.querySelectorAll('.gallery-thumb').forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            lightboxImg.src = thumb.src;
            lightbox.hidden = false;
        });
    });
    lightbox.addEventListener('click', function () {
        lightbox.hidden = true;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            lightbox.hidden = true;
        }
    });
}
</script>

<?php layout_footer(); ?>
