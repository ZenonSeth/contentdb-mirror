<?php
// Re-hashes every currently stored release and screenshot file and checks it
// against file_hashes. Prints one line per problem and exits non-zero if any:
//   MISMATCH  file on disk differs from the latest recorded hash
//   CONFLICT  the same source_url was recorded with more than one hash
//   MISSING   file referenced by the DB is not on disk
//   UNRECORDED no file_hashes row (run backfill_hashes.php)
//
// Usage: php cron/verify.php

require_once __DIR__ . '/../lib/db.php';

function main(): int
{
    $pdo = cdb_mirror_db();

    $hashes = [];
    foreach ($pdo->query('SELECT source_url, sha256 FROM file_hashes ORDER BY id') as $row) {
        $hashes[$row['source_url']][] = $row['sha256'];
    }

    $files = array_merge(
        $pdo->query("SELECT 'release' AS kind, source_url, local_path FROM releases WHERE local_path IS NOT NULL AND source_url IS NOT NULL")->fetchAll(),
        $pdo->query("SELECT 'screenshot' AS kind, source_url, local_path FROM screenshots WHERE local_path IS NOT NULL")->fetchAll()
    );

    $problems = 0;
    foreach ($files as $row) {
        $label = "{$row['kind']} {$row['local_path']} ({$row['source_url']})";
        $fsPath = __DIR__ . '/../public/' . $row['local_path'];
        $known = $hashes[$row['source_url']] ?? [];

        if (count(array_unique($known)) > 1) {
            echo "CONFLICT   $label: " . implode(' ', array_unique($known)) . "\n";
            $problems++;
        }
        if (!is_file($fsPath)) {
            echo "MISSING    $label\n";
            $problems++;
        } elseif (!$known) {
            echo "UNRECORDED $label\n";
            $problems++;
        } elseif (($actual = hash_file('sha256', $fsPath)) !== end($known)) {
            echo "MISMATCH   $label: recorded " . end($known) . ", on disk $actual\n";
            $problems++;
        }
    }

    printf("[%s] Checked %d files, %d problem(s).\n", gmdate('Y-m-d H:i:s') . ' UTC', count($files), $problems);
    return $problems > 0 ? 1 : 0;
}

exit(main());
