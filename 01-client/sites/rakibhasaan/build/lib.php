<?php
// rakibhasaan.com in Kohevo Studio: shared helpers for the build scripts. Run from the site root.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require_once getcwd() . '/config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

set_exception_handler(static function (\Throwable $e): void { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n"); exit(1); });
$tid = current_tenant_id();
PluginLoader::activePlugins();
$runtime = StudioRuntimeFactory::build();
$adminId = (int) Database::value("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? AND u.status = 'active' ORDER BY r.is_system DESC, u.id ASC LIMIT 1", [$tid]);
$actor = StudioActor::authenticated($adminId, StudioPermissions::ALL, true);
$defs = [];
foreach ($runtime->registry->editorManifests() as $m) { $defs[$m['type']] = $m; }
$BUNDLE = dirname(__DIR__);

const MEDIA_DIR = '/uploads/media/rakib/';

// The site's palette and type (the original's four themes: ink, bone, navy, orange).
const INK = '#0B0B0C';
const BONE = '#EDE8DF';
const NAVY = INK; // was navy #0D1A3A; the user asked for black instead (2026-11-08). The 'navy' theme now matches 'ink'.
const ORANGE = '#FF5A1F';
const RUST = '#AE3A0B'; // the original #D9480F darkened to 4.6:1 on the light cards (5:1 on BONE), so small accent text reads
const PERI = ORANGE; // was periwinkle #8EA2FF; the user asked for no blue on the site (2026-11-08)
const MUTED = '#8F8A82';
const MUTED_BONE = '#625D55'; // a shade darker than the original #6B665E, for 4.5:1 on the light cards too
const LINE = '#2A2926';
const LINE_BONE = '#D2CBBE';
const SANS = 'Geist, GeistFallback, system-ui, sans-serif';
const MONO = 'Geist Mono, GeistMonoFallback, ui-monospace, monospace';

function say(string $s): void { echo $s . "\n"; }

/** A block. `$opt` may carry responsive, style_parts, classNames, style_states, interactions and animation. */
function B(string $type, array $props = [], array $children = [], array $style = [], array $opt = []): array
{
    global $defs;
    if (!isset($defs[$type])) { throw new RuntimeException("No block type {$type}"); }
    $d = $defs[$type];
    $b = [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => (int) ($d['version'] ?? 1),
        'props' => array_replace((array) ($d['default_props'] ?? []), $props),
        'style' => $style + ['align' => ['base' => 'left']],
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings' => [], 'children' => array_values(array_filter($children)),
    ];
    foreach (['responsive', 'style_parts', 'classNames', 'style_states', 'interactions', 'animation', 'dynamic', 'attributes'] as $k) { if (!empty($opt[$k])) { $b[$k] = $opt[$k]; } }
    if (!empty($opt['devices'])) { $b['visibility']['devices'] = $opt['devices']; }
    // Shown only when the item being drawn has a value in this field (a Single or Loop template).
    if (!empty($opt['when_field'])) { $b['visibility']['when_field'] = $opt['when_field']; }
    return scale_tracking($b);
}

/** A background colour that hides what is behind it: a hex colour, or rgba() at half opacity or more. */
function solid_background(mixed $bg): bool
{
    $c = is_array($bg) ? trim((string) ($bg['color'] ?? '')) : '';
    if ($c === '' || $c === 'transparent') { return false; }
    if (preg_match('/^rgba\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*,\s*([\d.]+)\s*\)$/i', $c, $m)) { return (float) $m[1] >= 0.5; }
    return str_starts_with($c, '#') || str_starts_with(strtolower($c), 'rgb(');
}

/**
 * Tight tracking written in px suits the desktop size only: a device that sets its own font size gets the same
 * tracking in proportion (-5px at 96px is -2.7px at 52px), unless it sets one itself.
 */
function scale_tracking(array $b): array
{
    $t = $b['style']['typography'] ?? null;
    if (!is_array($t) || !preg_match('/^(-?[\d.]+)px$/', (string) ($t['letter_spacing'] ?? ''), $ls) || !preg_match('/^([\d.]+)px$/', (string) ($t['size'] ?? ''), $sz) || (float) $sz[1] <= 0) {
        return $b;
    }
    foreach (['tablet', 'mobile'] as $d) {
        $dt = $b['responsive'][$d]['style']['typography'] ?? null;
        if (is_array($dt) && isset($dt['size']) && !isset($dt['letter_spacing']) && preg_match('/^([\d.]+)px$/', (string) $dt['size'], $m)) {
            $b['responsive'][$d]['style']['typography']['letter_spacing'] = round((float) $ls[1] * (float) $m[1] / (float) $sz[1], 2) . 'px';
        }
    }
    return $b;
}

/**
 * Tablet and phone sizes for display text. Above 24px (tablet) or 20px (phone) a size steps down on a curve, capped at
 * 64px on a tablet and 38px on a phone, and never above a size the block already sets for that screen or the tablet's.
 * Letter spacing scales with it. Body sizes are left alone.
 */
function fit_size(float $px, string $device): float
{
    return $device === 'mobile'
        ? ($px <= 20 ? $px : min($px, 38.0, 18 + ($px - 18) * 0.36))
        : ($px <= 24 ? $px : min($px, 64.0, 22 + ($px - 22) * 0.58));
}
function fit_type(array $node): array
{
    $sz = $node['style']['typography']['size'] ?? null;
    if (is_string($sz) && preg_match('/^([\d.]+)px$/', $sz, $m) && (float) $m[1] > 20) {
        $base = (float) $m[1];
        $ls = $node['style']['typography']['letter_spacing'] ?? null;
        $lsv = is_string($ls) && preg_match('/^(-?[\d.]+)px$/', $ls, $l) ? (float) $l[1] : null;
        $ceiling = $base;
        foreach (['tablet', 'mobile'] as $d) {
            $cur = $node['responsive'][$d]['style']['typography']['size'] ?? null;
            $curv = is_string($cur) && preg_match('/^([\d.]+)px$/', $cur, $c) ? (float) $c[1] : null;
            $want = round(min(fit_size($base, $d), $ceiling, $curv ?? INF));
            if ($curv === null || $want < $curv) {
                $node['responsive'][$d]['style']['typography']['size'] = $want . 'px';
                if ($lsv !== null) { $node['responsive'][$d]['style']['typography']['letter_spacing'] = round($lsv * $want / $base, 2) . 'px'; }
                $curv = $want;
            }
            $ceiling = $curv;
        }
    }
    foreach (($node['children'] ?? []) as $i => $child) { if (is_array($child)) { $node['children'][$i] = fit_type($child); } }
    return $node;
}

/** A top-level section. `$extra` may carry responsive, interactions, classNames. */
function CTOP(string $label, array $children, array $style, array $extra = []): array
{
    $c = CanonicalDocumentSchema::newContainer([
        'metadata' => ['label' => mb_substr($label, 0, 60)],
        'style' => $style,
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'children' => array_values(array_filter($children)),
    ]);
    foreach (['responsive', 'interactions', 'classNames', 'animation', 'attributes'] as $k) { if (!empty($extra[$k])) { $c[$k] = $extra[$k]; } }
    if (!empty($extra['when_field'])) { $c['visibility']['when_field'] = $extra['when_field']; }
    $pc = $extra['interactions']['page_colors'] ?? null;
    if (is_array($pc) && isset($pc['text'], $pc['muted'], $pc['line'], $pc['accent'])) {
        $map = [strtolower($pc['text']) => 'text.body', strtolower($pc['muted']) => 'text.muted', strtolower($pc['line']) => 'border.default'];
        $map += [strtolower($pc['accent']) => 'text.accent'];
        foreach ($c['children'] as $i => $child) { $c['children'][$i] = follow_page($child, $map); }
    }
    return $c;
}

function MID(string $file): int { global $tid; return (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, MEDIA_DIR . $file]); }
function IMGREF(string $file, string $alt = ''): array
{
    $id = MID($file);
    if ($id <= 0) { throw new RuntimeException("No image {$file}"); }
    return ['media_id' => $id, 'alt' => $alt, 'focal_point' => [0.5, 0.5]];
}
function L(string $label, string $href): array { return ['label' => $label, 'href' => $href, 'target' => str_starts_with($href, 'http') ? '_blank' : '_self']; }

/** Copy the bundle's media into uploads and register each file once. */
function import_media(): void
{
    global $BUNDLE;
    $dst = getcwd() . '/uploads/media/rakib';
    $made = 0;
    if (!is_dir($dst)) { mkdir($dst, 0755, true); }
    foreach (glob("{$BUNDLE}/media/*") ?: [] as $file) {
        $name = basename($file);
        if (!is_file("{$dst}/{$name}")) { copy($file, "{$dst}/{$name}"); }
        if (MID($name) <= 0) {
            $size = @getimagesize("{$dst}/{$name}") ?: [null, null, 'mime' => mime_content_type("{$dst}/{$name}") ?: ''];
            Media::register(MEDIA_DIR . $name, ['mime' => (string) ($size['mime'] ?? ''), 'size_bytes' => filesize("{$dst}/{$name}"), 'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'original_name' => $name]);
        }
        // The library's WebP sizes, as an upload would make them: pages then serve srcsets instead of the full file.
        global $tid;
        if (Media::sizesEnabled() && Database::value('SELECT variants FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, MEDIA_DIR . $name]) === null) {
            $made++;
            Media::generateVariants(MID($name), false);
        }
    }
    say('  + media (' . $made . ' sized)');
}

function check_doc(array $doc): void
{
    global $runtime;
    try {
        \Slate\Module\StudioBuilder\Document\ValidatedDocument::from($doc, $runtime->registry, ['allow_locked' => true]);
    } catch (\Slate\Module\StudioBuilder\Exception\StudioValidationException $e) {
        foreach (array_slice($e->errors(), 0, 12) as $i) { say('   ! ' . ($i['path'] ?? '') . ' ' . ($i['code'] ?? '') . ': ' . ($i['message'] ?? '')); }
        throw new RuntimeException('invalid document');
    }
}

/** Create or replace a page and publish it. */
function build_page(string $slug, string $title, string $pageType, string $route, array $containers, array $opt = []): int
{
    global $runtime, $actor, $tid;
    $doc = CanonicalDocumentSchema::emptyDocument($pageType, 'default', ($opt['seo_title'] ?? '') ?: $title);
    $doc['containers'] = array_map('fit_type', array_values(array_filter($containers)));
    $doc['settings'] = array_replace($doc['settings'], $opt['settings'] ?? []);
    if (!empty($opt['seo_desc'])) { $doc['seo']['description'] = mb_substr((string) $opt['seo_desc'], 0, 300); }
    if (!empty($opt['image']) && ($id = MID($opt['image'])) > 0) { $doc['seo']['og_image_media_id'] = $id; }
    check_doc($doc);
    $old = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND page_type = ? AND status <> 'archived'", [$tid, $slug, $pageType]);
    if ($old) {
        $pageId = (int) $old['id'];
        Database::query('UPDATE studiobuilder_pages SET title = ? WHERE id = ?', [$title, $pageId]);
        $head = (int) Database::value('SELECT active_draft_revision_id FROM studiobuilder_pages WHERE id = ?', [$pageId]);
        $draft = $runtime->app->saveDraft($actor, $pageId, $doc, $head, 'manual', 'rakibhasaan.com build');
    } else {
        $created = $runtime->app->createPage($actor, $title, $slug, $pageType, $route);
        $pageId = (int) $created['page']['id'];
        $draft = $runtime->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id'], 'manual', 'rakibhasaan.com build');
    }
    if (!empty($opt['type'])) {
        $r = \Slate\Module\StudioBuilder\Meta\PageMeta::save($tid, $pageId, (string) $opt['type'], $opt['fields'] ?? []);
        if ($r['errors']) { throw new RuntimeException($slug . ': ' . json_encode($r['errors'])); }
    }
    $runtime->app->publish($actor, $pageId, (int) $draft['revision']['id'], 'rakibhasaan.com build');
    if (!empty($opt['date'])) { $runtime->app->setPublishDate($actor, $pageId, (string) $opt['date']); }
    say("  + {$pageType} /{$slug} (#{$pageId})");
    return $pageId;
}

// ── Pieces shared by every page ─────────────────────────────────────────────────

/** A column of blocks. */
function VBOX(array $kids, string $gap = '0px', array $style = [], array $opt = []): array
{
    // A layout the caller gives (a grid item's span, say) joins the column's own.
    return B('layout.flex', ['direction' => 'column', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], $kids, array_replace_recursive(['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => $gap]], $style), $opt);
}
/** A row of blocks that stacks on a phone. */
function ROW(array $kids, string $gap = '0px', array $style = [], array $layout = [], array $mobile = ['layout' => ['direction' => 'column']], array $opt = []): array
{
    // Stacked on a phone, a row's items line up on the left, as the original's do: a column would otherwise carry the
    // row's own alignment (centre, end) across, and text set right in the row reads left once stacked.
    if (($mobile['layout']['direction'] ?? '') === 'column') {
        $mobile['layout'] += ['align' => 'start'];
        foreach ($kids as $i => $k) {
            $a = $k['style']['align']['base'] ?? 'left';
            if (is_array($k) && $a !== 'left' && !isset($k['responsive']['mobile']['align'])) { $kids[$i]['responsive']['mobile']['align'] = 'left'; }
        }
    }
    return B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], $kids, ['layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => $gap] + $layout] + $style, ($mobile ? ['responsive' => ['mobile' => ['style' => $mobile]]] : []) + $opt);
}
/** A width inside a row; a phone gives it the full width. */
function W(array $b, string $w, bool $halfOnPhone = false): array { $b['style']['dimensions']['width'] = $w; $b['responsive']['mobile']['style']['dimensions']['width'] = $halfOnPhone ? 'calc(50% - 20px)' : '100%'; return $b; }

/** The small mono label ("01 — The idea"). */
function EYE(string $text, string $color = MUTED, string $align = 'left'): array
{
    return B('core.text', ['content' => $text, 'size' => 'inherit'], [], ['color' => $color, 'typography' => ['size' => '12px', 'font_family' => MONO, 'weight' => '500', 'transform' => 'uppercase', 'letter_spacing' => '1.4px', 'line_height' => '1.4'], 'align' => ['base' => $align]]);
}
/** A thin display heading in Geist 200, tight tracking. */
function H(string $text, string $level, string $size, string $msize, string $color = BONE, array $opt = [], string $weight = '200'): array
{
    $em = (float) $size;
    return B('core.heading', ['text' => $text, 'level' => $level], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => SANS, 'weight' => $weight, 'line_height' => '0.98', 'letter_spacing' => round(-0.05 * $em, 1) . 'px']], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => $msize]]]]] + $opt);
}
function P(string $text, string $color = BONE, string $size = '18px', array $more = [], array $opt = []): array
{
    // Lead text over 18px reads at about four fifths of its size on a phone, as the original's does.
    $px = (float) $size;
    if (str_ends_with($size, 'px') && $px >= 20 && !isset($opt['responsive']['mobile']['style']['typography']['size'])) {
        $opt['responsive']['mobile']['style']['typography']['size'] = max(16, (int) round($px * 0.78)) . 'px';
    }
    return B('core.text', ['content' => $text, 'size' => 'inherit'], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => SANS, 'weight' => '400', 'line_height' => '1.55'] + $more], $opt);
}
/** A pill button. */
function PILL(string $label, string $href, string $bg = ORANGE, string $fg = INK, array $opt = []): array
{
    // A caller's own parts (an outline, say) go over the pill's.
    $parts = array_replace_recursive(['button' => ['background' => ['color' => $bg], 'color' => $fg, 'border' => ['width' => '0px', 'radius' => '999px'], 'padding' => ['top' => '13px', 'right' => '22px', 'bottom' => '13px', 'left' => '22px'], 'typography' => ['size' => '15px', 'weight' => 'medium', 'family' => 'body']]], $opt['style_parts'] ?? []);
    return B('core.button', ['link' => L($label, $href), 'variant' => 'primary'] + ($opt['props'] ?? []), [], [], ['style_parts' => $parts] + array_diff_key($opt, ['props' => 1, 'style_parts' => 1]));
}
function PIC(string $file, string $alt, string $h, string $mh = '', string $radius = '14px'): array
{
    return B('core.image', ['media' => IMGREF($file, $alt), 'aspect_ratio' => 'auto', 'rounded' => false], [], ['dimensions' => ['width' => '100%', 'height' => $h, 'object_fit' => 'cover'], 'border' => ['radius' => $radius]], $mh !== '' ? ['responsive' => ['mobile' => ['style' => ['dimensions' => ['height' => $mh]]]]] : []);
}
/**
 * A photo behind a hero's words (the hero takes the class rk-photo-hero; site.css lays the photo under it with a shade).
 * Decorative: the words say it all. `$devices` shows it on some widths only, so a phone can get a picture of its own.
 */
function BGPIC(string $file, string $pos, bool $priority, array $devices = ['base', 'sm', 'md', 'lg']): array
{
    return B('core.image', ['media' => IMGREF($file, ''), 'aspect_ratio' => 'auto', 'rounded' => false, 'priority' => $priority], [], ['dimensions' => ['width' => '100%', 'height' => '100%', 'object_fit' => 'cover']],
        ['classNames' => ['rk-bgpic', 'rk-bgpic--' . $pos], 'devices' => $devices]);
}
/** Section padding: 28px gutters, boxed at 1440px. */
function SEC(string $label, array $kids, string $top, string $bottom, array $more = [], array $extra = [], string $mtop = '72px', string $mbottom = '72px'): array
{
    $style = ['padding' => ['top' => $top, 'right' => '28px', 'bottom' => $bottom, 'left' => '28px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '0px', 'content_width' => 'boxed', 'content_max' => '1440px']];
    $style = array_replace_recursive($style, $more);
    $extra['responsive']['mobile']['style']['padding'] = ['top' => $mtop, 'right' => '20px', 'bottom' => $mbottom, 'left' => '20px'];
    return CTOP($label, $kids, $style, $extra);
}
/** Each section theme's colours: text, muted text, lines and accent, as the original's four themes. */
const PALETTE = [
    'ink'    => ['bg' => INK, 'fg' => BONE, 'muted' => MUTED, 'line' => LINE, 'accent' => ORANGE],
    'bone'   => ['bg' => BONE, 'fg' => INK, 'muted' => MUTED_BONE, 'line' => LINE_BONE, 'accent' => RUST],
    'navy'   => ['bg' => NAVY, 'fg' => BONE, 'muted' => MUTED, 'line' => LINE, 'accent' => PERI],
    'orange' => ['bg' => ORANGE, 'fg' => INK, 'muted' => '#4A1806', 'line' => '#E04A12', 'accent' => INK],
];
/** The colours a section gives the whole page while it is on screen: all five, so the theme's text, muted, line and accent colours follow. */
function THEME(string $name): array
{
    $p = PALETTE[$name] ?? PALETTE['ink'];
    return ['page_colors' => ['background' => $p['bg'], 'text' => $p['fg'], 'muted' => $p['muted'], 'line' => $p['line'], 'accent' => $p['accent']]];
}
/**
 * Inside a themed section, a text, muted, line or accent colour written as the theme's own becomes the matching theme
 * colour (text.body, text.muted, border.default, text.accent), so it changes with the page as the original's do and a
 * light section never shows dark text on the dark page. Backgrounds and buttons keep their colours.
 */
function follow_page(array $node, array $map): array
{
    if (($node['type'] ?? '') === 'core.button') { return $node; }
    // Text on a background of its own (a dark caption pill on a photo) keeps its colours: the page's would not show on it.
    if (solid_background($node['style']['background'] ?? null)) { return $node; }
    $swap = static function (mixed $v) use ($map): mixed { return is_string($v) && isset($map[strtolower($v)]) ? $map[strtolower($v)] : $v; };
    // A part's colour takes a theme colour as written. A block's own takes it as its text or border colour token
    // (`text_token`, `border_token`): its plain `color` and `border.color` take literal colours only.
    $part = static function (array $st) use ($swap): array {
        if (isset($st['color'])) { $st['color'] = $swap($st['color']); }
        if (isset($st['border']['color'])) { $st['border']['color'] = $swap($st['border']['color']); }
        return $st;
    };
    $own = static function (array $st) use ($swap): array {
        foreach ([['color'], ['typography', 'color']] as $path) {
            $v = $path === ['color'] ? ($st['color'] ?? null) : ($st['typography']['color'] ?? null);
            $t = $swap($v);
            if (is_string($t) && $t !== $v) {
                if ($path === ['color']) { unset($st['color']); } else { unset($st['typography']['color']); }
                $st['text_token'] = $t;
            }
        }
        $b = $st['border']['color'] ?? null;
        $t = $swap($b);
        if (is_string($t) && $t !== $b && $t === 'border.default') { unset($st['border']['color']); $st['border_token'] = $t; }
        return $st;
    };
    if (isset($node['style']) && is_array($node['style'])) { $node['style'] = $own($node['style']); }
    foreach (($node['style_parts'] ?? []) as $k => $p) { if (is_array($p)) { $node['style_parts'][$k] = $part($p); } }
    foreach (($node['children'] ?? []) as $i => $child) { if (is_array($child)) { $node['children'][$i] = follow_page($child, $map); } }
    return $node;
}

/** A Field value block. */
function FV(string $key, string $color, string $size, string $tag = 'div', array $style = [], array $props = [], array $opt = []): array
{
    return B('theme.field_value', ['field' => $key, 'tag' => $tag] + $props, [], array_replace_recursive(['color' => $color, 'typography' => ['size' => $size, 'font_family' => SANS, 'line_height' => '1.6']], $style), $opt);
}
/** A mono label set as a Field value ("Year 2023"). */
function FVM(string $key, string $color, array $props = [], array $style = []): array
{
    return FV($key, $color, '11px', 'span', array_replace_recursive(['typography' => ['size' => '11px', 'font_family' => MONO, 'transform' => 'uppercase', 'letter_spacing' => '1.1px', 'line_height' => '1.3']], $style), $props);
}
