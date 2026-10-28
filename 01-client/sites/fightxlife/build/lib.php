<?php
// Shared helpers for the FightxLife installer: documents built from real Studio blocks, saved through the application service.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require_once getcwd() . '/config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

$tid = current_tenant_id();
PluginLoader::activePlugins();
$runtime = StudioRuntimeFactory::build();
$adminId = (int) Database::value("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.tenant_id = ? AND u.status = 'active' ORDER BY r.is_system DESC, u.id ASC LIMIT 1", [$tid]);
$actor = StudioActor::authenticated($adminId, StudioPermissions::ALL, true);
$defs = [];
foreach ($runtime->registry->editorManifests() as $m) { $defs[$m['type']] = $m; }

function say(string $s): void { echo $s . "\n"; }

/** A block of `$type` with its manifest defaults overridden by `$props`; `$cls` are its class names. */
function B(string $type, array $props = [], array $children = [], string|array $cls = [], array $opt = []): array
{
    global $defs;
    if (!isset($defs[$type])) { throw new RuntimeException("No block type {$type}"); }
    $d = $defs[$type];
    $block = [
        'id' => $opt['id'] ?? CanonicalDocumentSchema::newBlockId(), 'type' => $type, 'version' => (int) ($d['version'] ?? 1),
        'props' => array_replace((array) ($d['default_props'] ?? []), $props),
        'style' => $opt['style'] ?? ['align' => ['base' => 'left']],
        'visibility' => $opt['visibility'] ?? ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'bindings' => $opt['bindings'] ?? [], 'children' => $children,
    ];
    $cls = is_string($cls) ? array_filter(explode(' ', $cls)) : $cls;
    if ($cls) { $block['classNames'] = array_values($cls); }
    foreach (['responsive', 'animation', 'tag', 'link', 'interactions'] as $k) { if (isset($opt[$k])) { $block[$k] = $opt[$k]; } }
    return $block;
}

require __DIR__ . '/native.php';

/**
 * A full-width section. Its container is boxed with no width of its own, so it holds the content to the site's content
 * width (Site settings › Layout, 1170px in site.php) with a 32px gutter (22px on a tablet, 18px on a phone), and the
 * design's class names become native style (native.php). A long-form body is laid into a template column, so it is not boxed.
 */
function SEC(string $label, array $blocks, string|array $cls = []): array
{
    $cls = is_string($cls) ? array_filter(explode(' ', $cls)) : $cls;
    // A WRAP() marker is not a block: its children become the section's own, its classes the section's.
    $flat = [];
    foreach ($blocks as $b) {
        if (isset($b['__wrap'])) {
            array_push($flat, ...$b['children']);
            $cls = array_merge($cls, array_filter(explode(' ', $b['cls'])));
        } else {
            $flat[] = $b;
        }
    }
    $blocks = $flat;
    $s = [
        'id' => CanonicalDocumentSchema::newSectionId(), 'label' => $label, 'global_ref' => null,
        'layout' => ['background_token' => 'surface.primary', 'columns' => ['base' => 1, 'md' => 1], 'gap' => 'none', 'padding_y' => ['base' => 'none'], 'width' => 'full'],
        'visibility' => ['auth_state' => 'any', 'devices' => ['base', 'sm', 'md', 'lg']],
        'blocks' => [B('layout.container', ['width' => 'full', 'padding' => 'none'], $blocks, $cls)],
    ];
    $box = &$s['blocks'][0];
    $boxed = !in_array('fx-body', $cls, true);
    if ($boxed) {
        $box['style']['layout'] = ['content_width' => 'boxed'];
        $box['responsive']['tablet']['style']['padding'] = ['left' => '22px', 'right' => '22px'];
        $box['responsive']['mobile']['style']['padding'] = ['left' => '18px', 'right' => '18px'];
    }
    native_apply($box, []);
    // The desktop gutter, unless the design gives the section a padding or a width of its own.
    if ($boxed && !isset($box['style']['layout']['content_max'])) {
        $box['style']['padding']['left'] ??= '32px';
        $box['style']['padding']['right'] ??= '32px';
    }
    if (in_array('yg-header-sec', $cls, true)) {
        $box['style']['background'] = ['color' => '#ffffff'];
    }
    unset($box);
    return $s;
}

/** A block id that stays the same from one build to the next (a form's id names its Forms entry). */
function STABLE_ID(string $name): string { return 'blk_' . substr(md5('fightxlife:' . $name), 0, 24); }

function L(string $label, string $href, string $target = '_self'): array { return ['label' => $label, 'href' => $href, 'target' => $target]; }
function T(string $text, string $cls = '', string $size = 'inherit'): array { return B('core.text', ['content' => $text, 'size' => $size], [], $cls); }
function H(string $text, string $level = 'h2', string $cls = '', string $hl = ''): array { return B('core.heading', ['text' => $text, 'level' => $level, 'highlight' => $hl], [], $cls); }
function BOX(array $children, string $cls = '', string $dir = 'vertical'): array { return B('core.container', ['direction' => $dir, 'gap' => 'none'], $children, $cls); }
function BTN(string $label, string $href, string $cls = 'yg-btn', string $variant = 'primary'): array { return B('core.button', ['link' => L($label, $href), 'variant' => $variant], [], $cls); }
function LNK(string $label, string $href, string $cls = '', string $style = 'plain'): array { return B('core.link', ['link' => L($label, $href), 'style' => $style], [], $cls); }

/** A media-library image by file name. */
function IMG(string $file, string $alt, string $cls = '', array $extra = []): array
{
    global $tid;
    $id = (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, "/uploads/media/fxl/{$file}"]);
    if ($id <= 0) { throw new RuntimeException("media {$file} missing"); }
    return B('core.image', array_replace(['media' => ['media_id' => $id, 'alt' => $alt, 'focal_point' => [0.5, 0.5]], 'aspect_ratio' => 'auto', 'rounded' => false], $extra), [], $cls);
}
function MURL(string $file): string { return '/uploads/media/fxl/' . $file; }

function check_doc(array $doc): void
{
    global $runtime;
    try {
        \Slate\Module\StudioBuilder\Document\ValidatedDocument::from($doc, $runtime->registry, ['allow_locked' => true]);
    } catch (\Slate\Module\StudioBuilder\Exception\StudioValidationException $e) {
        foreach ($e->errors() as $i) { say('   ! ' . ($i['path'] ?? '') . ' ' . ($i['code'] ?? '') . ': ' . ($i['message'] ?? '')); }
        throw new RuntimeException('invalid document');
    }
}

/** Create (or replace) a page and publish `$sections`. */
function build_page(string $slug, string $title, string $pageType, string $route, array $sections, array $settings = [], string $seoTitle = '', string $seoDesc = '', ?int $ogId = null): int
{
    global $runtime, $actor, $tid;
    $doc = CanonicalDocumentSchema::emptyDocument($pageType, 'default', $seoTitle !== '' ? $seoTitle : $title);
    $doc['sections'] = $sections;
    $doc['settings'] = array_replace($doc['settings'], $settings);
    if ($pageType === 'system') { $doc['seo']['robots'] = 'noindex,nofollow'; }
    if ($seoDesc !== '') { $doc['seo']['description'] = mb_substr($seoDesc, 0, 300); }
    if ($ogId) { $doc['seo']['og_image_media_id'] = $ogId; }
    check_doc($doc);
    $old = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND page_type = ? AND status <> 'archived'", [$tid, $slug, $pageType]);
    if ($old) {
        $pageId = (int) $old['id'];
        $head = (int) Database::value('SELECT active_draft_revision_id FROM studiobuilder_pages WHERE id = ?', [$pageId]);
        $draft = $runtime->app->saveDraft($actor, $pageId, $doc, $head, 'manual', 'FightxLife site');
    } else {
        $created = $runtime->app->createPage($actor, $title, $slug, $pageType, $route);
        $pageId = (int) $created['page']['id'];
        $draft = $runtime->app->saveDraft($actor, $pageId, $doc, (int) $created['revision']['id'], 'manual', 'FightxLife site');
    }
    $runtime->app->publish($actor, $pageId, (int) $draft['revision']['id'], 'FightxLife site');
    say("  + {$pageType} /{$slug} (#{$pageId})");
    return $pageId;
}

/** Media id of a library file by its public path (/uploads/media/fxl/x.jpg), or 0. */
function MID(string $path): int
{
    global $tid;
    return (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, $path]);
}
