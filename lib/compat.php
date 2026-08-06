<?php
// Shared protocol_version/engine_version compatibility logic, used by any
// endpoint that needs to filter releases by client compatibility (updates
// check, list/search, for-client).

require_once __DIR__ . '/db.php';

// Resolves a client-declared protocol_version into an engine version
// string comparable against releases.min/max_engine_version, by finding
// the lowest minetest_versions.name sharing that protocol_version (prefers
// non-dev rows, falls back to dev rows if that's all there is). Multiple
// engine versions can share one protocol_version (patch releases before
// the next protocol bump) - the lowest one is the version that introduced
// it, which is what a client reporting only a protocol_version actually
// guarantees it can run. Returns null if unresolvable (unknown protocol
// version, or none given) - callers should then skip this filter rather
// than falsely exclude content.
function cdb_resolve_protocol_to_engine_version(PDO $pdo, ?int $protocolVersion): ?string
{
    if ($protocolVersion === null) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT name FROM minetest_versions WHERE protocol_version = ? AND is_dev = 0');
    $stmt->execute([$protocolVersion]);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$names) {
        $stmt = $pdo->prepare('SELECT name FROM minetest_versions WHERE protocol_version = ?');
        $stmt->execute([$protocolVersion]);
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    if (!$names) {
        return null;
    }

    usort($names, 'version_compare');
    return $names[0];
}

// Effective engine version to filter releases against: an explicit
// engine_version query param wins if given (most precise), otherwise
// resolved from protocol_version, otherwise null (no filtering).
function cdb_resolve_effective_engine_version(PDO $pdo, ?string $engineVersion, ?int $protocolVersion): ?string
{
    if ($engineVersion !== null && $engineVersion !== '') {
        return $engineVersion;
    }
    return cdb_resolve_protocol_to_engine_version($pdo, $protocolVersion);
}

// True if $effectiveVersion falls within [$minEngineVersion, $maxEngineVersion]
// (either bound null = unbounded on that side). Always true if
// $effectiveVersion itself is null - permissive default so an
// unresolvable client version never falsely excludes content.
function cdb_engine_compatible(?string $effectiveVersion, ?string $minEngineVersion, ?string $maxEngineVersion): bool
{
    if ($effectiveVersion === null) {
        return true;
    }
    if ($minEngineVersion !== null && version_compare($effectiveVersion, $minEngineVersion, '<')) {
        return false;
    }
    if ($maxEngineVersion !== null && version_compare($effectiveVersion, $maxEngineVersion, '>')) {
        return false;
    }
    return true;
}
