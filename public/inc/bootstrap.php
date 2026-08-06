<?php

require_once __DIR__ . '/../../lib/db.php';

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Web-relative path to an asset, e.g. asset_url('storage/screenshots/12/thumbnail.png').
// Returns a placeholder for null/empty paths.
function asset_url(?string $localPath): ?string
{
    if (!$localPath) {
        return null;
    }
    return '/' . ltrim($localPath, '/');
}

function package_url(string $author, string $name, string $returnQuery = ''): string
{
    $url = '/package.php?author=' . urlencode($author) . '&name=' . urlencode($name);
    if ($returnQuery !== '') {
        $url .= '&back=' . urlencode($returnQuery);
    }
    return $url;
}

function type_label(string $type): string
{
    return match ($type) {
        'mod' => 'Mod',
        'game' => 'Game',
        'txp' => 'Texture Pack',
        default => ucfirst($type),
    };
}

// HTML-entity-encodes each character so raw-text scrapers don't match a
// plain email pattern, while browsers render/link it normally.
function obfuscate_email(string $email): string
{
    $out = '';
    foreach (str_split($email) as $char) {
        $out .= '&#' . ord($char) . ';';
    }
    return $out;
}

// Timestamp of the most recently completed sync run, or null if none yet.
function last_sync_completed_at(): ?string
{
    $pdo = cdb_mirror_db();
    $value = $pdo->query('SELECT run_at FROM sync_log ORDER BY run_at DESC LIMIT 1')->fetchColumn();
    if (!$value) {
        return null;
    }
    return (new DateTime($value))->format('Y-m-d H:i');
}

function format_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return '';
    }
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return round($value, 1) . ' ' . $units[$i];
}
