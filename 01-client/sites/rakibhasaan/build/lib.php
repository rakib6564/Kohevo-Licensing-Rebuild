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
const NAVY = '#0D1A3A';
const ORANGE = '#FF5A1F';
const RUST = '#D9480F';
const PERI = '#8EA2FF';
const MUTED = '#8F8A82';
const MUTED_BONE = '#6B665E';
const LINE = '#2A2926';
const LINE_BONE = '#D2CBBE';
const SANS = 'Geist, system-ui, sans-serif';
const MONO = 'Geist Mono, ui-monospace, monospace';

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
    foreach (['responsive', 'style_parts', 'classNames', 'style_states', 'interactions', 'animation'] as $k) { if (!empty($opt[$k])) { $b[$k] = $opt[$k]; } }
    if (!empty($opt['devices'])) { $b['visibility']['devices'] = $opt['devices']; }
    return $b;
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
    foreach (['responsive', 'interactions', 'classNames', 'animation'] as $k) { if (!empty($extra[$k])) { $c[$k] = $extra[$k]; } }
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
    if (!is_dir($dst)) { mkdir($dst, 0755, true); }
    foreach (glob("{$BUNDLE}/media/*") ?: [] as $file) {
        $name = basename($file);
        if (!is_file("{$dst}/{$name}")) { copy($file, "{$dst}/{$name}"); }
        if (MID($name) <= 0) {
            $size = @getimagesize("{$dst}/{$name}") ?: [null, null, 'mime' => mime_content_type("{$dst}/{$name}") ?: ''];
            Media::register(MEDIA_DIR . $name, ['mime' => (string) ($size['mime'] ?? ''), 'size_bytes' => filesize("{$dst}/{$name}"), 'width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'original_name' => $name]);
        }
    }
    say('  + media');
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
    $doc['containers'] = array_values(array_filter($containers));
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
    $runtime->app->publish($actor, $pageId, (int) $draft['revision']['id'], 'rakibhasaan.com build');
    say("  + {$pageType} /{$slug} (#{$pageId})");
    return $pageId;
}

// ── Pieces shared by every page ─────────────────────────────────────────────────

/** A column of blocks. */
function VBOX(array $kids, string $gap = '0px', array $style = [], array $opt = []): array
{
    return B('layout.flex', ['direction' => 'column', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], $kids, ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => $gap]] + $style, $opt);
}
/** A row of blocks that stacks on a phone. */
function ROW(array $kids, string $gap = '0px', array $style = [], array $layout = [], array $mobile = ['layout' => ['direction' => 'column']], array $opt = []): array
{
    return B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], $kids, ['layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => $gap] + $layout] + $style, ($mobile ? ['responsive' => ['mobile' => ['style' => $mobile]]] : []) + $opt);
}
/** A width inside a row; a phone gives it the full width. */
function W(array $b, string $w): array { $b['style']['dimensions']['width'] = $w; $b['responsive']['mobile']['style']['dimensions']['width'] = '100%'; return $b; }

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
    return B('core.text', ['content' => $text, 'size' => 'inherit'], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => SANS, 'weight' => '400', 'line_height' => '1.55'] + $more], $opt);
}
/** A pill button. */
function PILL(string $label, string $href, string $bg = ORANGE, string $fg = INK, array $opt = []): array
{
    return B('core.button', ['link' => L($label, $href), 'variant' => 'primary'] + ($opt['props'] ?? []), [], [], ['style_parts' => ['button' => ['background' => ['color' => $bg], 'color' => $fg, 'border' => ['width' => '0px', 'radius' => '999px'], 'padding' => ['top' => '13px', 'right' => '22px', 'bottom' => '13px', 'left' => '22px'], 'typography' => ['size' => '15px', 'weight' => 'medium', 'family' => 'body']]]] + array_diff_key($opt, ['props' => 1]));
}
function PIC(string $file, string $alt, string $h, string $mh = '', string $radius = '14px'): array
{
    return B('core.image', ['media' => IMGREF($file, $alt), 'aspect_ratio' => 'auto', 'rounded' => false], [], ['dimensions' => ['width' => '100%', 'height' => $h, 'object_fit' => 'cover'], 'border' => ['radius' => $radius]], $mh !== '' ? ['responsive' => ['mobile' => ['style' => ['dimensions' => ['height' => $mh]]]]] : []);
}
/** Section padding: 28px gutters, boxed at 1440px. */
function SEC(string $label, array $kids, string $top, string $bottom, array $more = [], array $extra = [], string $mtop = '96px', string $mbottom = '96px'): array
{
    $style = ['padding' => ['top' => $top, 'right' => '28px', 'bottom' => $bottom, 'left' => '28px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => '0px', 'content_width' => 'boxed', 'content_max' => '1440px']];
    $style = array_replace_recursive($style, $more);
    $extra['responsive']['mobile']['style']['padding'] = ['top' => $mtop, 'right' => '20px', 'bottom' => $mbottom, 'left' => '20px'];
    return CTOP($label, $kids, $style, $extra);
}
/** The colours a section gives the whole page while it is on screen. */
function THEME(string $name): array
{
    return ['page_colors' => match ($name) {
        'bone' => ['background' => BONE, 'text' => INK],
        'navy' => ['background' => NAVY, 'text' => '#E9ECF5'],
        'orange' => ['background' => ORANGE, 'text' => INK],
        default => ['background' => INK, 'text' => BONE],
    }];
}
