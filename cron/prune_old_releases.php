<?php
// Brings the releases table down to latest-only: for each package, keeps
// only the release row matching packages.latest_release_id and deletes any
// other release rows (and their local files) for that package. Safe to
// re-run anytime - a no-op once every package is already latest-only, since
// sync.php's sync_releases() maintains that invariant going forward.
//
// Usage: php cron/prune_old_releases.php [--dry-run]

require_once __DIR__ . '/../lib/db.php';

function main(): void
{
    global $argv;
    $dryRun = in_array('--dry-run', $argv, true);
    $pdo = cdb_mirror_db();

    // Packages with no known latest_release_id (never had a release, or
    // upstream now reports none) keep whatever release they have stored -
    // only prune when we know which release id should be the sole survivor.
    $stmt = $pdo->query(
        'SELECT r.id, r.package_id, r.local_path
         FROM releases r
         JOIN packages p ON p.id = r.package_id
         WHERE p.latest_release_id IS NOT NULL AND r.id != p.latest_release_id'
    );
    $toDelete = $stmt->fetchAll();

    if (!$toDelete) {
        echo "Nothing to prune.\n";
        return;
    }

    $bytesFreed = 0;
    $deleteStmt = $pdo->prepare('DELETE FROM releases WHERE id = ?');

    foreach ($toDelete as $row) {
        $fsPath = $row['local_path'] ? __DIR__ . '/../public/' . $row['local_path'] : null;
        $size = ($fsPath && is_file($fsPath)) ? filesize($fsPath) : 0;

        if ($dryRun) {
            printf("Would delete release %d (package %d)%s\n", $row['id'], $row['package_id'], $fsPath ? " and $fsPath" : '');
        } else {
            if ($fsPath) {
                @unlink($fsPath);
            }
            $deleteStmt->execute([$row['id']]);
        }
        $bytesFreed += $size;
    }

    printf(
        "%s %d old release row(s), %.2f GB%s.\n",
        $dryRun ? 'Would delete' : 'Deleted',
        count($toDelete),
        $bytesFreed / 1024 / 1024 / 1024,
        $dryRun ? '' : ' freed'
    );
}

main();
