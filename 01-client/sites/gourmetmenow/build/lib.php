<?php
// Chef Gregory's Gourmet Me Now (gourmetmenow.com) in Kohevo Studio: the pieces every page is made of.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require_once getcwd() . '/config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

set_exception_handler(static function (\Throwable $e): void { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n"); exit(1); });
$tid = current_tenant_id();
Database::query("UPDATE plugins SET status = 'inactive' WHERE slug IN ('coaching', 'multilang-translate')");
PluginLoader::activePlugins();
$runtime = StudioRuntimeFactory::build();
$adminId = (int) Database::value("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? AND u.status = 'active' ORDER BY r.is_system DESC, u.id ASC LIMIT 1", [$tid]);
$actor = StudioActor::authenticated($adminId, StudioPermissions::ALL, true);
$defs = [];
foreach ($runtime->registry->editorManifests() as $m) { $defs[$m['type']] = $m; }

const MEDIA_DIR = '/uploads/media/gmn/';
// Candy-shop craft: brittle butterscotch, cream, chocolate and kraft, with the label's cherry red and checkerboard.
const CHOC = '#3a2317';
const CREAM = '#fbf4e6';
const KRAFT = '#ead7b5';
const BUTTER = '#e3a33b';
const CARAMEL = '#b8691f';
const CHERRY = '#c43a2c';
const COCOA = '#6b4a37';
// Native styles for the house look (no custom CSS): a kraft label card and a photo with rounded corners and a paper shadow.
const CARD = ['background' => ['color' => '#fffaf0'], 'border' => ['width' => '1px', 'style' => 'solid', 'color' => '#e2cfae', 'radius' => '22px'], 'dimensions' => ['overflow' => 'hidden']];
const PHOTO = ['border' => ['radius' => '22px'], 'dimensions' => ['overflow' => 'hidden'], 'shadow' => ['x' => '0px', 'y' => '18px', 'blur' => '40px', 'spread' => '-22px', 'color' => 'rgba(58,35,23,0.55)']];
const SERIF = 'Fraunces, serif';
const BODY = 'Karla, sans-serif';
const HAND = 'Caveat, cursive';

function say(string $s): void { echo $s . "\n"; }

function B(string $type, array $props = [], array $children = [], array $style = [], array $opt = []): array
{
    global $defs;
    if (!isset($defs[$type])) { throw new RuntimeException("No block type {$type}"); }
    $d = $defs[$type];
    $b = [
        'id' => CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => (int) ($d['version'] ?? 1),
        'props' => array_replace((array) ($d['default_props'] ?? []), $props),
        'style' => $style + ['align' => ['base' => 'left']],
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']] + (isset($opt['when_field']) ? ['when_field' => $opt['when_field']] : []),
        'bindings' => [], 'children' => array_values($children),
    ];
    foreach (['responsive', 'style_parts', 'classNames', 'style_states', 'attributes'] as $k) { if (!empty($opt[$k])) { $b[$k] = $opt[$k]; } }
    return $b;
}

function MID(string $file): int { global $tid; return (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, MEDIA_DIR . $file]); }
function IMGREF(string $file, string $alt = ''): array
{
    $id = MID($file);
    if ($id <= 0) { throw new RuntimeException("No image {$file}"); }
    return ['media_id' => $id, 'alt' => $alt, 'focal_point' => [0.5, 0.5]];
}
function L(string $label, string $href): array { return ['label' => $label, 'href' => $href, 'target' => str_starts_with($href, 'http') ? '_blank' : '_self']; }

/** A full-width section, boxed to the site's width. */
function CTOP(string $label, array $children, array $style, array $responsive = [], array $attributes = []): array
{
    $c = CanonicalDocumentSchema::newContainer([
        'metadata' => ['label' => mb_substr(trim((string) preg_replace('/[^\p{L}\p{N} ,.&-]+/u', ' ', $label)) ?: 'Section', 0, 60)],
        'style' => $style,
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'children' => array_values(array_filter($children)),
    ]);
    if ($responsive) { $c['responsive'] = $responsive; }
    if ($attributes) { $c['attributes'] = $attributes; }
    return $c;
}
function SEC(string $label, array $kids, string $bg, string $pad = '96px', string $mob = '64px', string $id = '', string $gap = '0px', string $max = '1240px'): array
{
    $c = CTOP($label, $kids, ['background' => ['color' => $bg], 'padding' => ['top' => $pad, 'right' => '24px', 'bottom' => $pad, 'left' => '24px'], 'layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => $gap, 'content_width' => 'boxed', 'content_max' => $max]], ['mobile' => ['style' => ['padding' => ['top' => $mob, 'right' => '16px', 'bottom' => $mob, 'left' => '16px']]]], $id !== '' ? ['id' => $id] : []);
    return $c;
}
function VBOX(array $kids, string $gap = '0px', array $style = [], array $opt = []): array { return B('layout.flex', ['direction' => 'column', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], array_values(array_filter($kids)), ['layout' => ['display' => 'flex', 'direction' => 'column', 'gap' => $gap]] + $style, $opt); }
function ROW(array $kids, string $gap = '0px', array $layout = [], array $mobile = ['layout' => ['direction' => 'column']], array $style = [], array $opt = []): array
{
    return B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'wrap' => 'nowrap', 'align' => 'stretch'], array_values(array_filter($kids)), ['layout' => ['display' => 'flex', 'direction' => 'row', 'gap' => $gap] + $layout] + $style, $opt + ($mobile ? ['responsive' => ['mobile' => ['style' => $mobile]]] : []));
}
function GRID(array $kids, int $cols, string $gap = '24px', int $tablet = 2, int $mobile = 1, string $mgap = '16px', array $style = []): array
{
    return B('layout.flex', ['direction' => 'row', 'gap' => 'none', 'align' => 'stretch'], array_values(array_filter($kids)), ['layout' => ['display' => 'grid', 'columns' => $cols, 'gap' => $gap, 'align' => 'stretch']] + $style, ['responsive' => ['tablet' => ['style' => ['layout' => ['columns' => $tablet]]], 'mobile' => ['style' => ['layout' => ['columns' => $mobile, 'gap' => $mgap]]]]]);
}
/** Column width inside a row; a phone stacks it at full width. */
function W(array $b, string $w): array { $b['style']['dimensions']['width'] = $w; $b['responsive']['mobile']['style']['dimensions']['width'] = '100%'; return $b; }
/** Lets a block fill the spare height of its column, so what comes after it (a card's button) lines up across cards. */
function GROW(array $b): array { $b['style']['layout']['grow'] = 1; return $b; }

function EYE(string $text, string $color = CHERRY, string $align = 'left'): array { return B('core.text', ['content' => $text, 'size' => 'inherit'], [], ['color' => $color, 'typography' => ['size' => '14px', 'font_family' => BODY, 'weight' => '800', 'transform' => 'uppercase', 'letter_spacing' => '2.2px', 'line_height' => '1.3'], 'align' => ['base' => $align]]); }
function H(string $text, string $level, string $size, string $msize, string $color = CHOC, string $align = 'left'): array
{
    return B('core.heading', ['text' => $text, 'level' => $level], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => SERIF, 'weight' => '600', 'line_height' => '1.06', 'letter_spacing' => '-0.5px'], 'align' => ['base' => $align]], ['responsive' => ['mobile' => ['style' => ['typography' => ['size' => $msize]]]]]);
}
function P(string $text, string $color = COCOA, string $size = '18px', string $align = 'left', string $weight = '400'): array { return B('core.text', ['content' => $text, 'size' => 'inherit'], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => BODY, 'weight' => $weight, 'line_height' => '1.6'], 'align' => ['base' => $align]]); }
/** A hand-lettered line, like the writing on a kraft label. */
function MARK(string $text, string $color = CARAMEL, string $size = '30px', string $align = 'left'): array { return B('core.text', ['content' => $text, 'size' => 'inherit'], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => HAND, 'weight' => '600', 'line_height' => '1.15'], 'align' => ['base' => $align]]); }
/** A pill button: cherry (main), butter, chocolate, or a cream outline (`line`) for dark sections. */
function BTN(string $label, string $href, string $kind = 'red'): array
{
    // Each kind with its hover colours: the text always reads on the hover background.
    [$bg, $fg, $line, $hbg, $hfg] = match ($kind) {
        'red' => [CHERRY, '#ffffff', CHERRY, CHOC, CREAM],
        'butter' => [BUTTER, CHOC, BUTTER, CHOC, CREAM],
        'choc' => [CHOC, CREAM, CHOC, CHERRY, '#ffffff'],
        'outline' => ['rgba(0,0,0,0)', CHOC, CHOC, CHOC, CREAM],
        default => ['rgba(0,0,0,0)', CREAM, CREAM, CREAM, CHOC],
    };
    return B('core.button', ['link' => L($label, $href), 'variant' => 'primary'], [], [], ['style_parts' => BTN_PARTS(
        ['background' => ['color' => $bg], 'color' => $fg, 'border' => ['width' => '2px', 'style' => 'solid', 'color' => $line, 'radius' => '999px'], 'padding' => ['top' => '13px', 'right' => '26px', 'bottom' => '13px', 'left' => '26px'], 'typography' => ['size' => '16px', 'weight' => 'bold', 'letter_spacing' => '0.4px']],
        $hbg, $hfg)]);
}
/** A button's part styles with its hover: new colours, a 2px lift and a soft shadow, eased. */
function BTN_PARTS(array $base, string $hoverBg, string $hoverFg): array
{
    return [
        'button' => $base + ['effects' => ['transition' => ['duration_ms' => 160, 'easing' => 'ease']]],
        'button:hover' => ['background' => ['color' => $hoverBg], 'color' => $hoverFg, 'border' => ['color' => $hoverBg],
            'shadow' => ['x' => '0px', 'y' => '10px', 'blur' => '18px', 'spread' => '-10px', 'color' => 'rgba(58,35,23,0.55)'], 'effects' => ['transform' => ['translate_y' => '-2px']]],
    ];
}
/** A block shown only when the page's item gives `$field` a value (Studio's visibility, when_field). */
function gm_when(array $block, string $field): array { $block['visibility']['when_field'] = $field; return $block; }
function BTNS(array $buttons, string $mt = '10px', bool $center = false): array { return ROW($buttons, '12px', ['wrap' => 'wrap'] + ($center ? ['justify' => 'center'] : []), ['layout' => ['direction' => 'row', 'wrap' => 'wrap']], ['margin' => ['top' => $mt]]); }
/** A photo: rounded, with a paper shadow; `$tilt` leans it a little. All native style settings. */
function PIC(string $file, string $alt, string $h, string $mh = '', bool $tilt = false): array
{
    $style = array_replace_recursive(PHOTO, ['dimensions' => ['width' => '100%', 'height' => $h, 'object_fit' => 'cover']]);
    if ($tilt) { $style['effects'] = ['transform' => ['rotate' => -1.5]]; }
    $mobile = $mh !== '' ? ['dimensions' => ['height' => $mh]] : [];
    return B('core.image', ['media' => IMGREF($file, $alt), 'aspect_ratio' => 'auto', 'rounded' => false], [], $style, $mobile !== [] ? ['responsive' => ['mobile' => ['style' => $mobile]]] : []);
}
function LNK(string $label, string $href, string $color, string $size = '18px', array $more = []): array { return B('core.link', ['link' => L($label, $href), 'style' => 'plain'], [], ['color' => $color, 'typography' => ['size' => $size, 'font_family' => BODY, 'weight' => '500', 'line_height' => '1.35']] + $more, ['style_states' => ['hover' => ['typography' => ['decoration' => 'underline']]]]); }
function FV(string $key, array $style = [], array $opt = [], string $tag = 'div'): array { return B('theme.field_value', ['field' => $key, 'tag' => $tag], [], $style, $opt); }
function TYPE(string $font, string $size, string $weight = '400', array $more = []): array { return ['typography' => ['size' => $size, 'font_family' => $font, 'weight' => $weight, 'line_height' => '1.35'] + $more]; }

/** A Query Loop that draws a chosen Loop Item. */
function LOOP(string $type, string $tpl, int $columns, int $per, string $category = '', int $tablet = 0, string $gap = 'lg'): array
{
    $mob = ['mobile' => ['props' => ['columns' => 1]]] + ($tablet ? ['tablet' => ['props' => ['columns' => $tablet]]] : []);
    return B('core.query_loop', ['content_type' => $type, 'loop_template' => $tpl, 'category' => $category, 'columns' => $columns, 'per_page' => $per, 'gap' => $gap, 'order_by' => 'published_at', 'order' => 'asc', 'enable_pagination' => false, 'show_author' => false, 'show_category' => false, 'show_date' => false], [], [], ['responsive' => $mob]);
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

/** Create or replace a page and publish it. `$opt['type']`: the content type of an item. */
function build_page(string $slug, string $title, string $pageType, string $route, array $containers, array $opt = []): int
{
    global $runtime, $actor, $tid;
    $doc = CanonicalDocumentSchema::emptyDocument($pageType, 'default', ($opt['seo_title'] ?? '') ?: $title);
    $doc['containers'] = array_values(array_filter($containers));
    $doc['settings'] = array_replace($doc['settings'], $opt['settings'] ?? []);
    if ($pageType === 'system') { $doc['seo']['robots'] = 'noindex,nofollow'; }
    if (!empty($opt['seo_desc'])) { $doc['seo']['description'] = mb_substr((string) $opt['seo_desc'], 0, 300); }
    if (!empty($opt['image']) && ($id = MID($opt['image'])) > 0) { $doc['seo']['og_image_media_id'] = $id; }
    check_doc($doc);
    $old = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND page_type = ? AND status <> 'archived'", [$tid, $slug, $pageType]);
    if ($old) {
        $pageId = (int) $old['id'];
        Database::query('UPDATE studiobuilder_pages SET title = ? WHERE id = ?', [$title, $pageId]);
        $head = (int) Database::value('SELECT active_draft_revision_id FROM studiobuilder_pages WHERE id = ?', [$pageId]);
        $draft = $runtime->app->saveDraft($actor, $pageId, $doc, $head, 'manual', 'Smokehouse site');
    } else {
        $created = $runtime->app->createPage($actor, $title, $slug, $pageType, $route);
        $pageId = (int) $created['page']['id'];
        $draft = $runtime->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id'], 'manual', 'Smokehouse site');
    }
    if (!empty($opt['type'])) {
        $r = \Slate\Module\StudioBuilder\Meta\PageMeta::save($tid, $pageId, (string) $opt['type'], $opt['fields'] ?? []);
        if ($r['errors']) { throw new RuntimeException($slug . ': ' . json_encode($r['errors'])); }
    }
    $runtime->app->publish($actor, $pageId, (int) $draft['revision']['id'], 'Smokehouse site');
    if (!empty($opt['date'])) { $runtime->app->setPublishDate($actor, $pageId, date('Y-m-d H:i:s', strtotime((string) $opt['date']))); }
    say("  + {$pageType} /{$slug} (#{$pageId})");
    return $pageId;
}
function TEMPLATE(string $slug, string $title, string $kind, string $type, array $containers): int
{
    return build_page($slug, $title, 'system', 'standalone', $containers, ['settings' => ['template_type' => $kind, 'conditions' => ['rules' => [['action' => 'include', 'condition' => 'singular', 'value' => $type]]]]]);
}
function UUID(int $pageId): string { return (string) Database::value('SELECT uuid FROM studiobuilder_pages WHERE id = ?', [$pageId]); }
