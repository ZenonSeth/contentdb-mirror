<?php

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

layout_header('Supported API - ContentDB Mirror', 'Which parts of the ContentDB API this mirror implements, for use with the Luanti client\'s ContentDB URL setting.');
?>

<p><a href="/index.php">&laquo; Back to search</a></p>

<div class="long-description">
<h2>Using this mirror from the Luanti client</h2>
<p>
This site implements a read-only subset of the
<a href="https://content.luanti.org/help/api/">ContentDB API</a> - just
enough of it for the Luanti client itself to browse, install, and check for
updates to packages using this mirror instead of the real ContentDB. It
does not implement authenticated endpoints (uploading packages, posting
reviews, etc.) - this is a mirror, not a publishing platform.
</p>
<p>
To use it: in Luanti, go to <strong>Settings &gt; User Interfaces</strong>,
find <strong>ContentDB URL</strong>, and set it to
<code>https://contentdb.milcho.org</code> (default is
<code>https://content.luanti.org</code>).
</p>

<h2>Supported endpoints</h2>
<table class="releases">
<thead>
<tr><th>Endpoint</th><th>Status</th><th>Notes</th></tr>
</thead>
<tbody>
<tr>
<td><code>GET /api/packages/</code><br>List/search packages</td>
<td><span class="badge status-live">Live</span></td>
<td>Supports <code>type</code>, <code>q</code>, <code>author</code>,
<code>tag</code>, <code>protocol_version</code>, and
<code>engine_version</code> filtering. <code>hide</code> is accepted but
ignored (content flags aren't mirrored).</td>
</tr>
<tr>
<td><code>GET /api/packages/&lt;author&gt;/&lt;name&gt;/dependencies/</code><br>Package dependencies</td>
<td><span class="badge status-live">Live</span></td>
<td>Supports <code>only_hard</code>, <code>protocol_version</code>, and
<code>engine_version</code> filtering.</td>
</tr>
<tr>
<td><code>GET /api/packages/&lt;author&gt;/&lt;name&gt;/for-client/</code><br>Full package info</td>
<td><span class="badge status-planned">Planned</span></td>
<td>Not yet implemented.</td>
</tr>
<tr>
<td><code>GET /api/packages/&lt;author&gt;/&lt;name&gt;/for-client/reviews/</code><br>Package reviews</td>
<td><span class="badge status-live">Live</span></td>
<td>Reviews aren't mirrored - always returns a "no reviews" response
that links back to the real ContentDB page to leave one.</td>
</tr>
<tr>
<td><code>GET /api/updates/</code><br>Update check</td>
<td><span class="badge status-live">Live</span></td>
<td>Supports <code>type</code>, <code>protocol_version</code>, and
<code>engine_version</code> filtering.</td>
</tr>
<tr>
<td><code>GET /packages/&lt;author&gt;/&lt;name&gt;/releases/&lt;id&gt;/download/</code><br>Release download</td>
<td><span class="badge status-live">Live</span></td>
<td>Redirects to the mirrored release file.</td>
</tr>
</tbody>
</table>
</div>

<?php layout_footer(); ?>
