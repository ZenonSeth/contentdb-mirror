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

const DESCRIPTION_ALLOWED_TAGS = [
    'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code',
    'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'u', 's', 'del', 'sup', 'sub', 'kbd',
    'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div', 'details', 'summary', 'dl', 'dt', 'dd',
];
const DESCRIPTION_DROPPED_TAGS = [
    'script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript',
    'textarea', 'select', 'svg', 'math', 'head', 'title',
];

// Stored descriptions come from Parsedown without safe mode, so raw author HTML must be rebuilt from an allowlist.
function sanitize_description_html(string $html): string
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div>' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();

    // Walk body, not the wrapper: a stray author </div> closes the wrapper early.
    $root = $doc->getElementsByTagName('body')->item(0);
    return $root ? sanitize_description_children($root) : '';
}

function sanitize_description_children(DOMNode $node): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= sanitize_description_node($child);
    }
    return $out;
}

function sanitize_description_node(DOMNode $node): string
{
    if ($node instanceof DOMText) {
        return h($node->textContent);
    }
    if (!($node instanceof DOMElement)) {
        return '';
    }

    $tag = strtolower($node->tagName);
    if (in_array($tag, DESCRIPTION_DROPPED_TAGS, true)) {
        return '';
    }
    // Images become links, same as the for-client API, so we never hotlink upstream.
    if ($tag === 'img') {
        $src = description_link_url($node->getAttribute('src'));
        if ($src === null) {
            return '';
        }
        $alt = $node->getAttribute('alt') ?: 'image';
        return '<a href="' . h($src) . '" rel="nofollow noopener">[' . h($alt) . ']</a>';
    }

    $inner = sanitize_description_children($node);
    if ($tag === 'a') {
        $href = description_link_url($node->getAttribute('href'));
        return $href === null ? $inner : '<a href="' . h($href) . '" rel="nofollow noopener">' . $inner . '</a>';
    }
    if (!in_array($tag, DESCRIPTION_ALLOWED_TAGS, true)) {
        return $inner;
    }
    if ($tag === 'br' || $tag === 'hr') {
        return "<$tag>";
    }
    return "<$tag>$inner</$tag>";
}

// Only http(s)/mailto survive; site-relative paths resolve against upstream ContentDB.
function description_link_url(string $url): ?string
{
    $url = trim($url);
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return cdb_mirror_config()['site_base'] . $url;
    }
    return preg_match('#^(https?:|mailto:)#i', $url) ? $url : null;
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
