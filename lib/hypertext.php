<?php
// Converts stored data into Luanti's hypertext markup (the formspec
// `hypertext[]` element language - see doc/lua_api.md "Markup Language",
// and doc/improvements.md item #3's #3 research notes) for the for-client
// API endpoint's info_hypertext/long_description fields.
//
// Scope cuts from upstream's own renderer, documented in doc/improvements.md:
// no self-anchor links on headers, no "Languages" line (we don't mirror
// translation catalogs), images always rendered as links rather than
// respecting include_images (avoids depending on the client's media cache).

const CDB_HYPERTEXT_HEAD = '<tag name=code color=#7bf font=mono> <tag name=action color=#4CDAFA hovercolor=#97EAFC>';

// Backslash-escape literal <, >, \ per the markup language's escaping rule.
function cdb_hypertext_escape(string $text): string
{
    return preg_replace('/([<>\\\\])/', '\\\\$1', $text);
}

// Converts already-rendered HTML (our stored long_description_html, from
// Parsedown - see cron/sync.php) into a hypertext body + links map.
function cdb_html_to_hypertext(string $html): array
{
    $links = [];
    $linkCounter = 0;

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div>' . $html . '</div>',
        LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();

    $root = $doc->getElementsByTagName('div')->item(0);
    $body = $root ? cdb_hypertext_walk_children($root, $links, $linkCounter) : '';

    // Collapse runs of blank lines left by block-element spacing.
    $body = trim(preg_replace('/\n{3,}/', "\n\n", $body));

    return ['body' => $body, 'links' => $links];
}

function cdb_hypertext_walk_children(DOMNode $node, array &$links, int &$linkCounter): string
{
    $out = '';
    foreach ($node->childNodes as $child) {
        $out .= cdb_hypertext_walk($child, $links, $linkCounter);
    }
    return $out;
}

function cdb_hypertext_walk(DOMNode $node, array &$links, int &$linkCounter): string
{
    if ($node instanceof DOMText) {
        return cdb_hypertext_escape($node->textContent);
    }
    if (!($node instanceof DOMElement)) {
        return '';
    }

    // Regular closures with explicit `use (&...)`, not arrow functions -
    // arrow functions auto-capture by value, which would silently drop any
    // links registered by nested recursive calls instead of accumulating
    // them back into the caller's array.
    $inner = function () use ($node, &$links, &$linkCounter): string {
        return cdb_hypertext_walk_children($node, $links, $linkCounter);
    };
    $registerLink = function (string $url) use (&$links, &$linkCounter): string {
        $linkCounter++;
        $name = 'link_' . $linkCounter;
        $links[$name] = $url;
        return $name;
    };

    switch ($node->tagName) {
        case 'p':
        case 'div':
            return "\n\n" . $inner() . "\n\n";
        case 'br':
            return "\n";
        case 'strong':
        case 'b':
            return '<b>' . $inner() . '</b>';
        case 'em':
        case 'i':
            return '<i>' . $inner() . '</i>';
        case 'code':
            return '<mono>' . $inner() . '</mono>';
        case 'h1':
        case 'h2':
        case 'h3':
        case 'h4':
        case 'h5':
        case 'h6':
            return "\n\n<big><b>" . $inner() . "</b></big>\n";
        case 'blockquote':
            return "\n<i>" . $inner() . "</i>\n";
        case 'hr':
            return "\n---\n";
        case 'ul':
        case 'ol':
            $lines = '';
            $i = 0;
            foreach ($node->childNodes as $li) {
                if (!($li instanceof DOMElement) || $li->tagName !== 'li') {
                    continue;
                }
                $i++;
                $prefix = $node->tagName === 'ol' ? "$i. " : '* ';
                $lines .= "\n" . $prefix . cdb_hypertext_walk_children($li, $links, $linkCounter);
            }
            return "\n" . $lines . "\n";
        case 'a':
            $href = $node->getAttribute('href');
            if ($href === '') {
                return $inner();
            }
            $name = $registerLink($href);
            return "<action name=$name><u>" . $inner() . '</u></action>';
        case 'img':
            $src = $node->getAttribute('src');
            if ($src === '') {
                return '';
            }
            $alt = $node->getAttribute('alt') ?: 'image';
            $name = $registerLink($src);
            return "<action name=$name><u>[" . cdb_hypertext_escape($alt) . ']</u></action>';
        default:
            return $inner();
    }
}

// Builds the info_hypertext metadata block (Type/Tags/Supported Games/
// Dependencies/.../Provides), matching upstream's line structure. All
// inputs are already-resolved local data - no upstream calls here.
function cdb_build_info_hypertext(array $pkg, array $tags, array $supportedGames, array $hardDepends, array $optionalDepends, array $provides): array
{
    $links = [];
    $linkCounter = 0;
    $registerLink = function (string $url) use (&$links, &$linkCounter): string {
        $linkCounter++;
        $name = 'link_' . $linkCounter;
        $links[$name] = $url;
        return $name;
    };

    $typeLabels = ['mod' => 'Mod', 'game' => 'Game', 'txp' => 'Texture Pack'];
    $devStateLabels = [
        'WIP' => 'Work in Progress',
        'ACTIVELY_DEVELOPED' => 'Actively Developed',
        'MAINTENANCE_ONLY' => 'Maintenance Only',
        'AS_IS' => 'As-is',
        'DEPRECATED' => 'Deprecated',
    ];
    $aiDisclosureLabels = ['NONE' => 'None', 'ASSISTED' => 'AI-assisted', 'GENERATED' => 'AI-generated'];

    $lines = [];

    $lines[] = 'Type: <b>' . cdb_hypertext_escape($typeLabels[$pkg['type']] ?? ucfirst($pkg['type'])) . '</b>';

    if ($tags) {
        $labels = array_map(fn($t) => '<b>' . cdb_hypertext_escape(ucfirst($t)) . '</b>', $tags);
        $lines[] = 'Tags: ' . implode(', ', $labels);
    }

    if ($supportedGames) {
        $labels = array_map(function ($g) use ($registerLink) {
            $name = $registerLink('/package.php?author=' . urlencode($g['author']) . '&name=' . urlencode($g['name']));
            return '<b><action name=' . $name . '><u>' . cdb_hypertext_escape($g['title']) . '</u></action></b>';
        }, $supportedGames);
        $lines[] = 'Supported Games: ' . implode(', ', $labels);
    }

    if ($hardDepends) {
        sort($hardDepends);
        $lines[] = 'Dependencies: ' . implode(', ', array_map(fn($d) => '<b>' . cdb_hypertext_escape($d) . '</b>', $hardDepends));
    }

    if ($optionalDepends) {
        sort($optionalDepends);
        $lines[] = 'Optional dependencies: ' . implode(', ', array_map(fn($d) => '<b>' . cdb_hypertext_escape($d) . '</b>', $optionalDepends));
    }

    if ($pkg['license']) {
        $licenseText = $pkg['media_license'] && $pkg['media_license'] !== $pkg['license']
            ? $pkg['license'] . ' for code, ' . $pkg['media_license'] . ' for media.'
            : $pkg['license'];
        $lines[] = 'License: <b>' . cdb_hypertext_escape($licenseText) . '</b>';
    }

    if ($pkg['dev_state']) {
        $lines[] = 'Maintenance State: <b>' . cdb_hypertext_escape($devStateLabels[$pkg['dev_state']] ?? $pkg['dev_state']) . '</b>';
    }

    if ($pkg['ai_disclosure']) {
        $lines[] = 'AI Disclosure: <b>' . cdb_hypertext_escape($aiDisclosureLabels[$pkg['ai_disclosure']] ?? $pkg['ai_disclosure']) . '</b>';
    }

    if ($pkg['cdb_created_at']) {
        $lines[] = 'Added: <b>' . (new DateTime($pkg['cdb_created_at']))->format('Y-m-d\TH:i:s\Z') . '</b>';
    }

    $maintainers = json_decode($pkg['maintainers'] ?? '[]', true) ?: [];
    if ($maintainers) {
        $lines[] = 'Maintainers: <b>' . cdb_hypertext_escape(implode(', ', $maintainers)) . '</b>';
    }

    if ($provides) {
        sort($provides);
        $lines[] = 'Provides: <b>' . cdb_hypertext_escape(implode(', ', $provides)) . '</b>';
    }

    return ['body' => implode("\n\n", $lines) . "\n\n", 'links' => $links];
}
