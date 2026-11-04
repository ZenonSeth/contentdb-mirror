<?php

// Deliberately standalone: no DB or layout.php, so logs stay viewable when the DB is down.
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

header('X-Robots-Tag: noindex, nofollow');

const LOG_MAX_BYTES = 5 * 1024 * 1024;

$dir = realpath(__DIR__ . '/../../cron/logs');
$files = [];
if ($dir !== false) {
    foreach (glob($dir . '/sync-*.log') ?: [] as $path) {
        if (preg_match('/^sync-(\d{4}-\d{2}-\d{2}|undated)\.log$/', basename($path), $m)) {
            $files[$m[1]] = $path;
        }
    }
}
ksort($files);
if (isset($files['undated'])) {
    $files = ['undated' => $files['undated']] + $files;
}

function log_label(string $key): string
{
    if ($key === 'undated') {
        return 'Before timestamps';
    }
    $d = DateTime::createFromFormat('!Y-m-d', $key, new DateTimeZone('UTC'));
    return $d ? $d->format('D Y-m-d') : $key;
}

$keys = array_keys($files);
$sel = (string) ($_GET['f'] ?? '');
if (!isset($files[$sel])) {
    $sel = $keys ? end($keys) : '';
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sync logs - ContentDB Mirror</title>
<link rel="stylesheet" href="/assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/style.css') ?>">
<style>
.log-nav { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem; }
.log-nav a, .log-nav span.off, .log-nav button {
    padding: 0.4rem 0.9rem;
    border: 1px solid #ccc;
    border-radius: 4px;
    background: #fff;
    font-size: 0.9rem;
}
.log-nav span.off { opacity: 0.4; }
.log-nav select { padding: 0.4rem; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.log-meta { font-size: 0.85rem; color: #666; margin-bottom: 0.5rem; }
.log-box {
    max-height: 70vh;
    overflow: auto;
    margin: 0;
    padding: 0.75rem;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size: 0.8rem;
    line-height: 1.45;
    white-space: pre;
}
.log-box .err { color: #b3261e; font-weight: 600; }
@media (prefers-color-scheme: dark) {
    .log-nav a, .log-nav span.off, .log-nav button, .log-nav select { background: #212327; color: #e6e6e6; border-color: #3a3d44; }
    .log-meta { color: #999; }
    .log-box { background: #1b1c1f; border-color: #33363c; }
    .log-box .err { color: #ff8a80; }
}
</style>
</head>
<body>
<header class="site-header">
<h1>Sync logs</h1>
<nav class="site-nav"><a href="/index.php">Home</a></nav>
</header>
<main>

<?php if (!$files): ?>
<p>No log files found.</p>
<?php else:
    $pos = array_search($sel, $keys, true);
    $older = $keys[$pos - 1] ?? null;
    $newer = $keys[$pos + 1] ?? null;
    $path = $files[$sel];
    $size = (int) @filesize($path);
    $truncated = $size > LOG_MAX_BYTES;
    $text = (string) @file_get_contents($path, false, null, $truncated ? $size - LOG_MAX_BYTES : 0);
    $lines = $text === '' ? [] : explode("\n", rtrim($text, "\n"));
    if ($truncated) {
        array_shift($lines);
    }
?>
<form method="get" class="log-nav">
<?php if ($older !== null): ?><a href="?f=<?= h($older) ?>">&laquo; Older</a><?php else: ?><span class="off">&laquo; Older</span><?php endif; ?>
<select name="f" id="log-select" onchange="this.form.submit()">
<?php foreach (array_reverse($keys) as $k): ?>
<option value="<?= h($k) ?>"<?= $k === $sel ? ' selected' : '' ?>><?= h(log_label($k)) ?></option>
<?php endforeach; ?>
</select>
<noscript><button type="submit">Go</button></noscript>
<?php if ($newer !== null): ?><a href="?f=<?= h($newer) ?>">Newer &raquo;</a><?php else: ?><span class="off">Newer &raquo;</span><?php endif; ?>
</form>

<div class="log-meta">
<?= h(basename($path)) ?> &middot; <?= count($lines) ?> lines &middot; <?= h(number_format($size / 1024, 1)) ?> KB &middot; times are UTC<?= $truncated ? ' &middot; showing only the last ' . (LOG_MAX_BYTES / 1048576) . ' MB' : '' ?>
</div>

<pre class="log-box" tabindex="0"><?php
foreach ($lines as $line) {
    $e = h($line);
    if (preg_match('/failed|Error processing|mismatch|aborting/', $line)) {
        $e = '<span class="err">' . $e . '</span>';
    }
    echo $e, "\n";
}
?></pre>
<?php endif; ?>

</main>
</body>
</html>
