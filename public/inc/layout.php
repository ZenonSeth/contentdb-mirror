<?php

function layout_header(string $title, string $description = ''): void
{
    $lastSync = last_sync_completed_at();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<?php if ($description): ?><meta name="description" content="<?= h($description) ?>"><?php endif; ?>
<link rel="stylesheet" href="/assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
<header class="site-header">
<a href="/index.php"><h1>Luanti ContentDB Mirror</h1></a>
<div class="tagline">Unofficial read-only mirror of content.luanti.org, updated weekly<?php if ($lastSync): ?> - Last synchronized: <?= h($lastSync) ?><?php endif; ?></div>
<nav class="site-nav">
<a href="/index.php">Home</a>
<a href="/about.php">About</a>
<a href="/about_api.php">API</a>
</nav>
<div class="client-note">This mirror is usable within the Luanti client too, see instructions <a href="/about_api.php">here</a>.</div>
</header>
<main>
<?php
}

function layout_footer(): void
{
    ?>
</main>
<footer class="site-footer">
Content mirrored from <a href="https://content.luanti.org">content.luanti.org</a>.
All packages remain the property of their original authors under their stated licenses.
This is an unofficial, read-only mirror; not affiliated with the Luanti project.
</footer>
</body>
</html>
<?php
}
