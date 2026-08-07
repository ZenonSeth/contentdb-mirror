<?php
// Weekly (or first-run) sync of ContentDB into the local mirror database.
// Mode is auto-detected: empty packages table -> full pull, otherwise ->
// incremental update driven by GET /api/updates/.
//
// Usage: php cron/sync.php

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/contentdb_api.php';

function ext_from_url(string $url, string $fallback): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: $url;
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    return $ext !== '' ? $ext : $fallback;
}

function parse_cdb_datetime(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    try {
        return (new DateTime($value))->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function upsert_package(PDO $pdo, array $detail): int
{
    $stmt = $pdo->prepare('SELECT id, thumbnail_source_url FROM packages WHERE cdb_author = ? AND cdb_name = ?');
    $stmt->execute([$detail['author'], $detail['name']]);
    $existing = $stmt->fetch();

    $fields = [
        'type' => $detail['type'],
        'title' => $detail['title'],
        'short_description' => $detail['short_description'] ?? '',
        'long_description_md' => $detail['long_description'] ?? null,
        'long_description_html' => isset($detail['long_description'])
            ? (new Parsedown())->text($detail['long_description'])
            : null,
        'license' => $detail['license'] ?? null,
        'media_license' => $detail['media_license'] ?? null,
        'forum_url' => $detail['forum_url'] ?? null,
        'repo' => $detail['repo'] ?? null,
        'issue_tracker' => $detail['issue_tracker'] ?? null,
        'website' => $detail['website'] ?? null,
        'donate_url' => $detail['donate_url'] ?? null,
        'thumbnail_source_url' => $detail['thumbnail'] ?? null,
        'score' => $detail['score'] ?? null,
        'downloads' => $detail['downloads'] ?? 0,
        'state' => $detail['state'] ?? null,
        'dev_state' => $detail['dev_state'] ?? null,
        'cdb_created_at' => parse_cdb_datetime($detail['created_at'] ?? null),
        'cdb_approved_at' => parse_cdb_datetime($detail['approved_at'] ?? null),
        'is_removed_upstream' => 0,
    ];

    if ($existing) {
        $packageId = (int) $existing['id'];
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE packages SET $set, last_synced_at = NOW() WHERE id = :id");
        $stmt->execute([...$fields, 'id' => $packageId]);
    } else {
        $fields['cdb_author'] = $detail['author'];
        $fields['cdb_name'] = $detail['name'];
        $cols = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        $stmt = $pdo->prepare("INSERT INTO packages ($cols) VALUES ($placeholders)");
        $stmt->execute($fields);
        $packageId = (int) $pdo->lastInsertId();
    }

    sync_tags($pdo, $packageId, $detail['tags'] ?? []);
    sync_screenshots($pdo, $packageId, $detail['screenshots'] ?? []);
    sync_thumbnail($pdo, $packageId, $detail['thumbnail'] ?? null, $existing ? $existing['thumbnail_source_url'] : null);

    return $packageId;
}

function sync_tags(PDO $pdo, int $packageId, array $tagNames): void
{
    $pdo->prepare('DELETE FROM package_tags WHERE package_id = ?')->execute([$packageId]);
    if (!$tagNames) {
        return;
    }
    $insertTag = $pdo->prepare('INSERT INTO tags (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $link = $pdo->prepare('INSERT IGNORE INTO package_tags (package_id, tag_id) VALUES (?, ?)');
    foreach ($tagNames as $tagName) {
        $insertTag->execute([$tagName]);
        $tagId = (int) $pdo->lastInsertId();
        $link->execute([$packageId, $tagId]);
    }
}

// Downloads any screenshot URLs not already stored locally for this package.
// Never deletes rows for screenshots that disappeared upstream.
function sync_screenshots(PDO $pdo, int $packageId, array $urls): void
{
    $config = cdb_mirror_config();
    $stmt = $pdo->prepare('SELECT source_url FROM screenshots WHERE package_id = ?');
    $stmt->execute([$packageId]);
    $existingUrls = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM screenshots WHERE package_id = ?');
    $stmt->execute([$packageId]);
    $sortOrder = (int) $stmt->fetchColumn();

    $insert = $pdo->prepare('INSERT INTO screenshots (package_id, source_url, local_path, sort_order) VALUES (?, ?, ?, ?)');

    foreach ($urls as $url) {
        if (isset($existingUrls[$url])) {
            continue;
        }
        $sortOrder++;
        $ext = ext_from_url($url, 'png');
        $webPath = "{$config['storage_web_prefix']['screenshots']}/$packageId/shot-$sortOrder.$ext";
        $localPath = __DIR__ . "/../public/$webPath";
        try {
            cdb_download_file($url, $localPath);
            $insert->execute([$packageId, $url, $webPath, $sortOrder]);
        } catch (CdbApiException $e) {
            fwrite(STDERR, "  screenshot download failed for package $packageId: {$e->getMessage()}\n");
        }
    }
}

function sync_thumbnail(PDO $pdo, int $packageId, ?string $url, ?string $previousUrl): void
{
    if (!$url || $url === $previousUrl) {
        return;
    }
    $config = cdb_mirror_config();
    $ext = ext_from_url($url, 'png');
    $webPath = "{$config['storage_web_prefix']['screenshots']}/$packageId/thumbnail.$ext";
    $localPath = __DIR__ . "/../public/$webPath";
    try {
        cdb_download_file($url, $localPath);
        $pdo->prepare('UPDATE packages SET thumbnail_local_path = ? WHERE id = ?')->execute([$webPath, $packageId]);
    } catch (CdbApiException $e) {
        fwrite(STDERR, "  thumbnail download failed for package $packageId: {$e->getMessage()}\n");
    }
}

// Keeps exactly one release per package - the latest - and owns
// packages.latest_release_id as the record of what we actually have
// confirmed stored (NOT a raw copy of upstream's reported release id -
// upsert_package() no longer writes this column at all). If
// $latestReleaseId is null, there's nothing upstream to sync (either the
// package has never had a release, or it did and now reports none - either
// way we leave whatever we have stored, and packages.latest_release_id,
// alone). If it matches what we already have stored, there's nothing new.
// Otherwise: fetch the release metadata and download the zip, insert the
// new row, update packages.latest_release_id, and only then delete the old
// release row(s)/file(s) - in that order, so a failure at any point along
// the way (metadata fetch, download) leaves both the previous release and
// packages.latest_release_id untouched, and the next sync run will retry
// automatically since upstream's id still won't match what's stored.
// Returns 1 if a new release was fetched and stored, 0 otherwise.
function sync_releases(PDO $pdo, int $packageId, string $author, string $name, ?int $latestReleaseId): int
{
    if ($latestReleaseId === null) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT id, local_path FROM releases WHERE package_id = ?');
    $stmt->execute([$packageId]);
    $existing = $stmt->fetchAll();

    foreach ($existing as $row) {
        if ((int) $row['id'] === $latestReleaseId) {
            return 0;
        }
    }

    $config = cdb_mirror_config();
    try {
        $release = cdb_api_get('/packages/' . rawurlencode($author) . '/' . rawurlencode($name) . '/releases/' . $latestReleaseId . '/');
    } catch (CdbApiException $e) {
        fwrite(STDERR, "  release metadata fetch failed for package $packageId release $latestReleaseId: {$e->getMessage()}\n");
        return 0;
    }
    if (!is_array($release)) {
        return 0;
    }

    $localPath = null;
    $downloadedAt = null;
    if (!empty($release['url'])) {
        $ext = ext_from_url($release['url'], 'zip');
        $webPath = "{$config['storage_web_prefix']['releases']}/$packageId/$latestReleaseId.$ext";
        $fsPath = __DIR__ . "/../public/$webPath";
        try {
            cdb_download_file($release['url'], $fsPath);
            $localPath = $webPath;
            $downloadedAt = date('Y-m-d H:i:s');
        } catch (CdbApiException $e) {
            fwrite(STDERR, "  release download failed for package $packageId release $latestReleaseId: {$e->getMessage()}\n");
            return 0;
        }
    }

    $minVersion = $release['min_minetest_version']['name'] ?? null;
    $maxVersion = $release['max_minetest_version']['name'] ?? null;

    $insert = $pdo->prepare(
        'INSERT INTO releases
            (id, package_id, name, title, release_notes, release_date,
             min_engine_version, max_engine_version, size_bytes, source_url, local_path, downloaded_at)
         VALUES
            (:id, :package_id, :name, :title, :release_notes, :release_date,
             :min_engine_version, :max_engine_version, :size_bytes, :source_url, :local_path, :downloaded_at)'
    );
    $insert->execute([
        'id' => $latestReleaseId,
        'package_id' => $packageId,
        'name' => $release['name'] ?? null,
        'title' => $release['title'] ?? null,
        'release_notes' => $release['release_notes'] ?? null,
        'release_date' => parse_cdb_datetime($release['release_date'] ?? null),
        'min_engine_version' => $minVersion,
        'max_engine_version' => $maxVersion,
        'size_bytes' => $release['size'] ?? null,
        'source_url' => $release['url'] ?? null,
        'local_path' => $localPath,
        'downloaded_at' => $downloadedAt,
    ]);

    $pdo->prepare('UPDATE packages SET latest_release_id = ? WHERE id = ?')->execute([$latestReleaseId, $packageId]);

    $deleteOld = $pdo->prepare('DELETE FROM releases WHERE id = ?');
    foreach ($existing as $row) {
        if ($row['local_path']) {
            @unlink(__DIR__ . '/../public/' . $row['local_path']);
        }
        $deleteOld->execute([$row['id']]);
    }

    return 1;
}

// Mirrors GET /api/minetest_versions/ - a small global reference table (not
// per-package data), used to translate a client's protocol_version into an
// engine-version range. Fetches the full list first; only if that succeeds
// does it open a transaction to replace the table's contents, so a failed
// fetch leaves the previously-synced table untouched.
function sync_minetest_versions(PDO $pdo): void
{
    echo "Syncing minetest_versions...\n";
    try {
        $versions = cdb_api_get('/minetest_versions/');
    } catch (CdbApiException $e) {
        fwrite(STDERR, "  minetest_versions fetch failed: {$e->getMessage()}\n");
        return;
    }
    if (!is_array($versions)) {
        fwrite(STDERR, "  minetest_versions fetch returned unexpected shape\n");
        return;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM minetest_versions');
        $insert = $pdo->prepare('INSERT IGNORE INTO minetest_versions (name, is_dev, protocol_version) VALUES (?, ?, ?)');
        foreach ($versions as $v) {
            $insert->execute([$v['name'], !empty($v['is_dev']) ? 1 : 0, (int) $v['protocol_version']]);
        }
        $pdo->commit();
        echo '  stored ' . count($versions) . " versions\n";
    } catch (PDOException $e) {
        $pdo->rollBack();
        fwrite(STDERR, "  minetest_versions DB update failed, rolled back: {$e->getMessage()}\n");
    }
}

// Mirrors GET /api/dependencies/ - raw provides/depends/optional_depends for
// every package reachable in the dependency graph, paged (currently ~34
// pages). Pages are fetched by page number rather than following
// urls.next, since that's an absolute URL and cdb_api_get() takes a path
// relative to api_base. All pages are accumulated in memory before touching
// the DB; only once the full set is fetched successfully does it open a
// transaction that replaces package_provides/package_depends wholesale, so
// a failure partway through paging leaves the previous run's data intact
// (same "don't regress on partial failure" principle as sync_releases()).
// Items for an author/name we don't have locally are skipped - the
// dependency graph can reference packages outside what this mirror stores.
function sync_dependencies(PDO $pdo): void
{
    echo "Syncing dependencies...\n";
    $items = [];
    try {
        $first = cdb_api_get('/dependencies/?page=1');
        if (!is_array($first) || !isset($first['items'], $first['page_count'])) {
            fwrite(STDERR, "  dependencies fetch returned unexpected shape\n");
            return;
        }
        $items = $first['items'];
        $pageCount = (int) $first['page_count'];
        for ($page = 2; $page <= $pageCount; $page++) {
            echo "  page $page/$pageCount\n";
            $resp = cdb_api_get('/dependencies/?page=' . $page);
            $items = array_merge($items, $resp['items']);
        }
    } catch (CdbApiException $e) {
        fwrite(STDERR, "  dependencies fetch failed, aborting step: {$e->getMessage()}\n");
        return;
    }

    $stmt = $pdo->query('SELECT id, cdb_author, cdb_name FROM packages');
    $packageIds = [];
    foreach ($stmt->fetchAll() as $row) {
        $packageIds[$row['cdb_author'] . '/' . $row['cdb_name']] = (int) $row['id'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM package_provides');
        $pdo->exec('DELETE FROM package_depends');

        // INSERT IGNORE: upstream data isn't guaranteed dedup'd (an item's own
        // provides/depends list could repeat a name), and a plain INSERT would
        // throw on the (package_id, name[, is_optional]) primary key and abort
        // the whole transaction over a harmless repeat.
        $insertProvides = $pdo->prepare('INSERT IGNORE INTO package_provides (package_id, name) VALUES (?, ?)');
        $insertDepends = $pdo->prepare('INSERT IGNORE INTO package_depends (package_id, name, is_optional) VALUES (?, ?, ?)');

        $skipped = 0;
        foreach ($items as $item) {
            $key = $item['author'] . '/' . $item['name'];
            if (!isset($packageIds[$key])) {
                $skipped++;
                continue;
            }
            $packageId = $packageIds[$key];

            foreach ($item['provides'] ?? [] as $provideName) {
                $insertProvides->execute([$packageId, $provideName]);
            }
            foreach ($item['depends'] ?? [] as $dependName) {
                $insertDepends->execute([$packageId, $dependName, 0]);
            }
            foreach ($item['optional_depends'] ?? [] as $dependName) {
                $insertDepends->execute([$packageId, $dependName, 1]);
            }
        }
        $pdo->commit();

        echo '  stored dependency data for ' . (count($items) - $skipped) . ' packages, skipped '
            . $skipped . " not found locally\n";
    } catch (PDOException $e) {
        $pdo->rollBack();
        fwrite(STDERR, "  dependencies DB update failed, rolled back: {$e->getMessage()}\n");
    }
}

function process_package(PDO $pdo, string $author, string $name, array &$stats): void
{
    try {
        $detail = cdb_api_get('/packages/' . rawurlencode($author) . '/' . rawurlencode($name) . '/');
        // Check existence by the detail's own canonical author/name, not the
        // (possibly stale/aliased) $author/$name we were called with - e.g.
        // ContentDB's /api/updates/ has been observed to report an author
        // string ("Minetest") that differs from what /packages/<...>/ itself
        // returns as canonical ("Luanti") for the same underlying account.
        // Checking against the raw caller-supplied key would misreport an
        // existing package as new.
        $stmtCheck = $pdo->prepare('SELECT 1 FROM packages WHERE cdb_author = ? AND cdb_name = ?');
        $stmtCheck->execute([$detail['author'], $detail['name']]);
        $isNew = $stmtCheck->fetchColumn() === false;

        $packageId = upsert_package($pdo, $detail);
        $latestReleaseId = isset($detail['release']) ? (int) $detail['release'] : null;
        $addedReleases = sync_releases($pdo, $packageId, $author, $name, $latestReleaseId);

        $stats['releases_added'] += $addedReleases;
        if ($isNew) {
            $stats['packages_added']++;
        } else {
            $stats['packages_updated']++;
        }
    } catch (CdbApiException $e) {
        fwrite(STDERR, "Error processing $author/$name: {$e->getMessage()}\n");
        $stats['errors_count']++;
    }
}

function run_full_sync(PDO $pdo, array &$stats): void
{
    echo "Full sync: fetching package list...\n";
    $list = cdb_api_get('/packages/');
    $stats['packages_seen'] = count($list);
    echo 'Found ' . count($list) . " packages.\n";

    // The upstream list has occasionally been observed to repeat the same
    // author/name (e.g. around an author rename/alias); skip repeats so we
    // don't process the same package twice in one run.
    $seen = [];

    foreach ($list as $i => $entry) {
        $author = $entry['author'];
        $name = $entry['name'];
        $key = "$author/$name";
        printf("[%d/%d] %s/%s\n", $i + 1, count($list), $author, $name);

        if (isset($seen[$key])) {
            echo "  skipping duplicate list entry for $key\n";
            continue;
        }
        $seen[$key] = true;

        process_package($pdo, $author, $name, $stats);
    }
}

function run_incremental_sync(PDO $pdo, array &$stats): void
{
    echo "Incremental sync: fetching update map...\n";
    $updates = cdb_api_get('/updates/');
    $stats['packages_seen'] = count($updates);

    $stmt = $pdo->query('SELECT id, cdb_author, cdb_name, latest_release_id FROM packages');
    $known = [];
    foreach ($stmt->fetchAll() as $row) {
        $known[$row['cdb_author'] . '/' . $row['cdb_name']] = $row;
    }

    $seenKeys = [];
    foreach ($updates as $key => $releaseId) {
        $seenKeys[$key] = true;
        [$author, $name] = explode('/', $key, 2);

        if (!isset($known[$key])) {
            // Not necessarily actually new - process_package() re-checks
            // against the package detail's own canonical author/name before
            // deciding, since this $key comes from /api/updates/ which can
            // report a stale/aliased author for an existing package.
            echo "Not found under this key, checking: $key\n";
            process_package($pdo, $author, $name, $stats);
            continue;
        }

        if ((int) $known[$key]['latest_release_id'] !== (int) $releaseId) {
            echo "Updated package: $key\n";
            process_package($pdo, $author, $name, $stats);
        }
    }

    // Packages we have locally but that no longer appear upstream: flag,
    // never delete.
    $flagStmt = $pdo->prepare('UPDATE packages SET is_removed_upstream = 1 WHERE id = ? AND is_removed_upstream = 0');
    foreach ($known as $key => $row) {
        if (!isset($seenKeys[$key])) {
            $flagStmt->execute([$row['id']]);
            if ($flagStmt->rowCount() > 0) {
                $stats['packages_removed_flagged']++;
                echo "Flagged as removed upstream: $key\n";
            }
        }
    }
}

function main(): void
{
    $pdo = cdb_mirror_db();
    $startedAt = microtime(true);

    $stats = [
        'packages_seen' => 0,
        'packages_added' => 0,
        'packages_updated' => 0,
        'releases_added' => 0,
        'packages_removed_flagged' => 0,
        'errors_count' => 0,
    ];

    $packageCount = (int) $pdo->query('SELECT COUNT(*) FROM packages')->fetchColumn();
    $mode = $packageCount === 0 ? 'full' : 'incremental';
    echo "Mode: $mode\n";

    if ($mode === 'full') {
        run_full_sync($pdo, $stats);
    } else {
        run_incremental_sync($pdo, $stats);
    }

    sync_minetest_versions($pdo);
    sync_dependencies($pdo);

    $duration = (int) round(microtime(true) - $startedAt);

    $log = $pdo->prepare(
        'INSERT INTO sync_log
            (mode, packages_seen, packages_added, packages_updated, releases_added,
             packages_removed_flagged, errors_count, duration_seconds, notes)
         VALUES (:mode, :packages_seen, :packages_added, :packages_updated, :releases_added,
             :packages_removed_flagged, :errors_count, :duration_seconds, :notes)'
    );
    $log->execute([
        'mode' => $mode,
        'packages_seen' => $stats['packages_seen'],
        'packages_added' => $stats['packages_added'],
        'packages_updated' => $stats['packages_updated'],
        'releases_added' => $stats['releases_added'],
        'packages_removed_flagged' => $stats['packages_removed_flagged'],
        'errors_count' => $stats['errors_count'],
        'duration_seconds' => $duration,
        'notes' => null,
    ]);

    echo "\nDone in {$duration}s. " . json_encode($stats) . "\n";
}

require_once __DIR__ . '/../lib/Parsedown.php';
main();
