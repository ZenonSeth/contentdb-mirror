<?php

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

layout_header('About - ContentDB Mirror', 'About this unofficial read-only mirror of Luanti ContentDB, and how to request removal of content.');
?>

<p><a href="/index.php">&laquo; Back to search</a></p>

<div class="long-description">
<h2>About this site</h2>
<p>
This is an unofficial, read-only mirror of
<a href="https://content.luanti.org">Luanti ContentDB</a>, the package
repository for the Luanti (formerly Minetest) engine. It republishes
public package metadata, screenshots, and release files fetched via
ContentDB's public API, refreshed on a weekly automated schedule.
</p>
<p>
This site is not affiliated with, endorsed by, or officially connected to
the Luanti project or ContentDB. All mirrored packages remain the property
of their original authors and are distributed here under the same license
each author chose on ContentDB. If you are a package author or copyright
holder and this mirror is a problem for you, contact us below and we will
resolve it promptly.
</p>

<h2>Removal / takedown requests</h2>
<p>
If you are the author of a mirrored package, or otherwise have a
legitimate request to have content removed or corrected on this site,
please email:
</p>
<p><strong><a href="<?= obfuscate_email('mailto:admin@contentdb.milcho.org') ?>"><?= obfuscate_email('admin@contentdb.milcho.org') ?></a></strong></p>
<p>
Please include a link to the package on this mirror (or on ContentDB) and
a brief description of your request. We aim to respond promptly.
</p>
</div>

<?php layout_footer(); ?>
