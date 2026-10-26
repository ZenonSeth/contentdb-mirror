<?php
// One-off: records a file_hashes row for every currently stored release and
// screenshot file that has none yet (files downloaded before sync.php started
// recording hashes). Safe to re-run - already-recorded URLs are skipped.
//
// Usage: php cron/backfill_hashes.php [--dry-run]

require_once __DIR__ . '/../lib/db.php';

function main(): void
{
    global $argv;
    $dryRun = in_array('--dry-run', $argv, true);
    $pdo = cdb_mirror_db();

    $recorded = array_flip($pdo->query('SELECT DISTINCT source_url FROM file_hashes')->fetchAll(PDO::FETCH_COLUMN));

    $files = array_merge(
        $pdo->query(
            "SELECT 'release' AS kind, package_id, id AS release_id, source_url, local_path
             FROM releases WHERE local_path IS NOT NULL AND source_url IS NOT NULL"
        )->fetchAll(),
        $pdo->query(
            "SELECT 'screenshot' AS kind, package_id, NULL AS release_id, source_url, local_path
             FROM screenshots WHERE local_path IS NOT NULL"
        )->fetchAll()
    );

    $insert = $pdo->prepare(
        'INSERT INTO file_hashes (kind, package_id, release_id, source_url, sha256, size_bytes, recorded_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $added = 0;
    $skipped = 0;
    $missing = 0;
    foreach ($files as $row) {
        if (isset($recorded[$row['source_url']])) {
            $skipped++;
            continue;
        }
        $fsPath = __DIR__ . '/../public/' . $row['local_path'];
        if (!is_file($fsPath)) {
            echo "missing: {$row['kind']} {$row['local_path']}\n";
            $missing++;
            continue;
        }
        if (!$dryRun) {
            $insert->execute([
                $row['kind'], $row['package_id'], $row['release_id'], $row['source_url'],
                hash_file('sha256', $fsPath), filesize($fsPath), gmdate('Y-m-d H:i:s'),
            ]);
        }
        $added++;
    }

    printf("%s %d, already recorded %d, missing on disk %d.\n", $dryRun ? 'Would add' : 'Added', $added, $skipped, $missing);
}

main();
