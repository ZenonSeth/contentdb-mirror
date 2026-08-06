<?php
// One-off maintenance script: reconciles public/storage/releases/<id>/ folders
// against the releases table, deleting any local file that no longer has a
// matching row (leftovers from the double-processed-package release bug).
// Not scheduled by cron - run manually once via: ea-php84 cron/cleanup_extra_releases.php

require_once __DIR__ . '/../lib/db.php';

function main(): void
{
    $pdo = cdb_mirror_db();
    $config = cdb_mirror_config();
    $releasesDir = $config['storage']['releases'];

    $stmt = $pdo->query('SELECT package_id, local_path FROM releases WHERE local_path IS NOT NULL');
    $keepByPackage = [];
    foreach ($stmt->fetchAll() as $row) {
        $keepByPackage[(int) $row['package_id']][] = basename($row['local_path']);
    }

    $filesDeleted = 0;
    $bytesFreed = 0;
    $foldersChecked = 0;

    foreach (new DirectoryIterator($releasesDir) as $dirEntry) {
        if ($dirEntry->isDot() || !$dirEntry->isDir()) {
            continue;
        }
        $packageId = (int) $dirEntry->getFilename();
        $folderPath = $dirEntry->getPathname();
        $foldersChecked++;

        $keep = $keepByPackage[$packageId] ?? [];

        foreach (new FilesystemIterator($folderPath) as $fileEntry) {
            if (!$fileEntry->isFile()) {
                continue;
            }
            $filename = $fileEntry->getFilename();
            if (in_array($filename, $keep, true)) {
                continue;
            }
            $bytesFreed += $fileEntry->getSize();
            unlink($fileEntry->getPathname());
            $filesDeleted++;
        }
    }

    printf(
        "Checked %d folders. Deleted %d orphaned release files, freed %.2f GB.\n",
        $foldersChecked,
        $filesDeleted,
        $bytesFreed / 1024 / 1024 / 1024
    );
}

main();
