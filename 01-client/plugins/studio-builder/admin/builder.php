<?php
/**
 * Kohevo Studio — Builder shell host page.
 *
 *   GET /plugins/studio-builder/admin/builder.php?page=<id>
 *
 * Serves only the empty application shell: the prebuilt React bundle, its
 * stylesheet and a small non-executable JSON boot block (endpoint URLs, the
 * page id, the session CSRF token). The page document itself is NOT embedded:
 * the builder always loads it from the server through the command/query API,
 * so a reload reconstructs the editor exclusively from the canonical revision.
 *
 * Access runs the full application pipeline up front (tenant ->
 * authentication -> studio-builder entitlement -> studio-builder.edit -> page
 * ownership) so an unauthorized user never even receives the shell.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Http\StudioCanvasPolicy;
use Slate\Module\StudioBuilder\Runtime\StudioLog;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();

$sbPageId = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$sbStatus = 200;
$sbTitle  = '';
if ($sbPageId === false || $sbPageId === null) {
    $sbStatus = 404;
} else {
    try {
        $sbState = StudioRuntimeFactory::build()->app->loadEditorDocument(StudioActor::fromCurrentSession(), $sbPageId);
        $sbTitle = (string) $sbState['page']['title'];
        unset($sbState);
    } catch (StudioException $e) {
        $sbStatus = $e->httpStatus();
        if ($sbStatus >= 500) {
            StudioLog::failure('builder', 'shell', $e);
        }
    } catch (\Throwable $e) {
        StudioLog::failure('builder', 'shell', $e);
        $sbStatus = 500;
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' https: data:; font-src 'self' https: data:; connect-src 'self'; frame-src 'self'; "
    . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
    true
);

$sbPagesUrl = plugin_url('studio-builder', 'admin/index.php');

if ($sbStatus !== 200) {
    http_response_code(in_array($sbStatus, [401, 403, 404], true) ? $sbStatus : 500);
    $sbMessage = match ($sbStatus) {
        403     => __('studio_builder_forbidden', 'You do not have access to edit this page.'),
        404     => __('studio_builder_not_found', 'This page was not found.'),
        default => __('studio_builder_unavailable', 'The builder could not be opened right now.'),
    };
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . e(__('studio_builder', 'Kohevo Studio')) . '</title></head><body style="font-family:system-ui,sans-serif;padding:2rem">'
        . '<p>' . e($sbMessage) . '</p><p><a href="' . e($sbPagesUrl) . '">' . e(__('studio_back_to_pages', 'Back to Studio pages')) . '</a></p></body></html>';
    exit;
}

$sbAssetDir = dirname(__DIR__) . '/assets/builder';
$sbVersion  = (string) max(
    (int) (@filemtime($sbAssetDir . '/builder.js') ?: 0),
    (int) (@filemtime($sbAssetDir . '/builder.css') ?: 0),
    (int) (@filemtime(__FILE__) ?: 0)
);
$sbMediaPicker = class_exists('PluginLoader') && PluginLoader::isActive('media-library') && Auth::can('media.view');
// Phase 7: the AI assistant entry point (the MCP gateway's admin chat) is offered only
// when that module is active and the signed-in admin may open it. The chat prepares
// DRAFT revisions; publishing stays this builder's own Publish action.
$sbAssistantUrl = class_exists('PluginLoader') && PluginLoader::isActive('mcp-gateway') && (Auth::can('mcp-gateway.manage') || Auth::isSuperAdmin())
    ? plugin_url('mcp-gateway', 'admin/chat.php')
    : null;

$sbAllPages = [];
try {
    $sbAllPagesList = StudioRuntimeFactory::build()->app->listPages(StudioActor::fromCurrentSession());
    foreach ($sbAllPagesList as $p) {
        $sbAllPages[] = [
            'id'           => (int) $p['id'],
            'title'        => (string) $p['title'],
            'slug'         => (string) ($p['slug'] ?? ''),
            'is_published' => !empty($p['is_published']),
        ];
    }
} catch (\Throwable $e) {
    // Graceful fallback
}

$sbBoot = [
    'pageId'     => $sbPageId,
    'allPages'   => $sbAllPages,
    'apiUrl'     => plugin_url('studio-builder', 'admin/api.php'),
    'canvasUrl'  => plugin_url('studio-builder', 'admin/canvas.php'),
    'previewUrl' => plugin_url('studio-builder', 'admin/preview.php'),
    'pagesUrl'   => $sbPagesUrl,
    'siteUrl'    => rtrim((string) SLATE_URL, '/'),
    'csrfToken'  => csrf_token(),
    'canvasSandbox' => StudioCanvasPolicy::IFRAME_SANDBOX,
    'mediaPicker'   => $sbMediaPicker,
    'builderUrl'    => plugin_url('studio-builder', 'admin/builder.php'),
    'brandingUrl'   => rtrim((string) SLATE_URL, '/') . '/admin/settings.php?tab=branding',
    'assistantUrl'  => $sbAssistantUrl,
    // Phase 8B: translated builder strings (the UI's setMessages(); English is the UI's own fallback).
    'messages'      => [
        'import_source'          => __('studio_ui_import_source', 'Import from'),
        'import_source_package'  => __('studio_ui_import_source_package', 'Kohevo package (.json)'),
        'import_source_html'     => __('studio_ui_import_source_html', 'HTML/CSS'),
        'import_html_hint'       => __('studio_ui_import_html_hint', 'Choose an HTML file and, optionally, a CSS file. The page structure is converted into Studio blocks: scripts, forms, embeds and unsupported styling are removed, external images are never downloaded, and nothing is published.'),
        'import_html_file'       => __('studio_ui_import_html_file', 'HTML file'),
        'import_css_file'        => __('studio_ui_import_css_file', 'CSS file (optional)'),
        'import_title'           => __('studio_ui_import_title', 'Page title'),
        'import_slug'            => __('studio_ui_import_slug', 'Page address (slug)'),
        'import_html_conversion' => __('studio_ui_import_html_conversion', '{stripped} element(s) removed for security, {unsupported} unsupported element(s) and {hidden} hidden element(s) left out.'),
        'source_empty'           => __('studio_ui_source_empty', 'The file is empty.'),
        'source_too_large'       => __('studio_ui_source_too_large', 'This file is too large to import.'),
        'seo_title_label' => __('studio_ui_seo_title_label', 'SEO title'),
        'seo_description_label' => __('studio_ui_seo_description_label', 'Meta description'),
        'seo_canonical_label' => __('studio_ui_seo_canonical_label', 'Canonical URL'),
        'seo_canonical_hint' => __('studio_ui_seo_canonical_hint', 'Optional. A path on this site (/about) or a full address on this site. Leave empty to use this page\'s own address. Other websites are ignored: Studio never declares another domain as canonical.'),
        'seo_canonical_invalid' => __('studio_ui_seo_canonical_invalid', 'Enter a path starting with / or a full http(s) address.'),
        'seo_og_image_label' => __('studio_ui_seo_og_image_label', 'Social share image'),
        'seo_og_image_hint' => __('studio_ui_seo_og_image_hint', 'Shown when the page is shared. Choose an image from your media library.'),
        'seo_robots_label' => __('studio_ui_seo_robots_label', 'Search engine visibility'),
        'seo_draft_note' => __('studio_ui_seo_draft_note', 'Search settings belong to the draft. They go live only when you publish.'),
        'seo_remove_image' => __('studio_ui_seo_remove_image', 'Remove image'),
        'view_mode' => __('studio_ui_view_mode', 'View mode'),
        'mode_edit' => __('studio_ui_mode_edit', 'Edit'),
        'mode_edit_hint' => __('studio_ui_mode_edit_hint', 'Edit mode: select, drag and edit on the canvas'),
        'mode_preview' => __('studio_ui_mode_preview', 'Preview'),
        'mode_preview_hint' => __('studio_ui_mode_preview_hint', 'Editor preview: the canvas becomes read-only and the builder stays open'),
        'mode_visitor' => __('studio_ui_mode_visitor', 'Visitor'),
        'mode_visitor_hint' => __('studio_ui_mode_visitor_hint', 'Visitor preview: the page as a visitor sees it, with minimal editor chrome'),
        'inspector_show' => __('studio_ui_inspector_show', 'Show the editor panel'),
        'inspector_hide' => __('studio_ui_inspector_hide', 'Hide the editor panel for a full-width canvas'),
        'open_new_tab' => __('studio_ui_open_new_tab', 'Open in a new tab'),
        'visitor_preview' => __('studio_ui_visitor_preview', 'Visitor preview'),
        'back_to_editing' => __('studio_ui_back_to_editing', 'Back to editing'),
        'reload' => __('studio_ui_reload', 'Reload'),
        'visitor_preview_stale' => __('studio_ui_visitor_preview_stale', 'The preview shows the last saved draft. Save to see your latest edits.'),
        'save_draft' => __('studio_ui_save_draft', 'Save draft'),
        'breadcrumb' => __('studio_ui_breadcrumb', 'Selection path'),
        'page' => __('studio_ui_page', 'Page'),
        'grid' => __('studio_ui_grid', 'Grid'),
        'grid_toggle' => __('studio_ui_grid_toggle', 'Show or hide the column grid'),
        'bulk_actions' => __('studio_ui_bulk_actions', 'Actions for the selected layers'),
        'bulk_selected' => __('studio_ui_bulk_selected', '{count} selected'),
        'multi_select_hint' => __('studio_ui_multi_select_hint', 'Select a single layer to edit its properties. Use the Layers panel to lock, duplicate or remove the selection.'),
        'edit_inline' => __('studio_ui_edit_inline', 'Edit text'),
        'selection_toolbar' => __('studio_ui_selection_toolbar', 'Actions for the selected layer'),
        'fit' => __('studio_ui_fit', 'Fit'),
        'zoom_fit' => __('studio_ui_zoom_fit', 'Fit the canvas to the screen'),
        'zoom_in' => __('studio_ui_zoom_in', 'Zoom in'),
        'zoom_out' => __('studio_ui_zoom_out', 'Zoom out'),
        'zoom_level' => __('studio_ui_zoom_level', 'Canvas zoom'),
        'shortcuts_help' => __('studio_ui_shortcuts_help', 'Keyboard shortcuts'),
        'shortcuts_title' => __('studio_ui_shortcuts_title', 'Keyboard shortcuts'),
        'shortcut_undo' => __('studio_ui_shortcut_undo', 'Undo'),
        'shortcut_redo' => __('studio_ui_shortcut_redo', 'Redo'),
        'shortcut_save' => __('studio_ui_shortcut_save', 'Save draft'),
        'shortcut_duplicate' => __('studio_ui_shortcut_duplicate', 'Duplicate the selection'),
        'shortcut_multi' => __('studio_ui_shortcut_multi', 'Add to the selection'),
        'shortcut_range' => __('studio_ui_shortcut_range', 'Select a range of layers'),
        'shortcut_rename' => __('studio_ui_shortcut_rename', 'Rename the layer (Layers panel)'),
        'shortcut_delete' => __('studio_ui_shortcut_delete', 'Remove the layer (Layers panel)'),
        'shortcut_deselect' => __('studio_ui_shortcut_deselect', 'Clear the selection'),
        'bulk_remove_confirm' => __('studio_ui_bulk_remove_confirm', 'Remove {count} selected items?'),
        'clear_selection' => __('studio_ui_clear_selection', 'Clear'),
        'palette_dynamic' => __('studio_ui_palette_dynamic', 'Dynamic'),
        'palette_media' => __('studio_ui_palette_media', 'Media'),
        'palette_ai' => __('studio_ui_palette_ai', 'AI'),
        'dynamic_data_from' => __('studio_ui_dynamic_data_from', 'Data from'),
        'dynamic_page_context' => __('studio_ui_dynamic_page_context', 'Uses the current page or post'),
        'dynamic_note' => __('studio_ui_dynamic_note', 'These blocks read live data from your site. Studio only stores the connection; each module keeps its own data and your tenant boundary.'),
        'media_add_image' => __('studio_ui_media_add_image', 'Add an image from the media library'),
        'media_unavailable' => __('studio_ui_media_unavailable', 'The media library is not available to your account. Insert a media block and enter an image id.'),
        'media_note' => __('studio_ui_media_note', 'Only the image id is stored on the page, never a file path.'),
        'ai_panel_note' => __('studio_ui_ai_panel_note', 'The AI assistant prepares changes as a draft revision. Nothing goes live until you review and publish it yourself.'),
        'ai_unavailable' => __('studio_ui_ai_unavailable', 'The AI assistant is not enabled for your account.'),
        'lock_layer' => __('studio_ui_lock_layer', 'Lock'),
        'unlock_layer' => __('studio_ui_unlock_layer', 'Unlock'),
        'locked_by_parent' => __('studio_ui_locked_by_parent', 'Locked by a parent layer. Unlock the parent first.'),
        'layer_locked_note' => __('studio_ui_layer_locked_note', 'This layer is locked. Unlock it to edit, move, rename or remove it.'),
        'layer_locked_by_parent' => __('studio_ui_layer_locked_by_parent', 'A parent layer is locked, so this layer cannot be edited. Unlock the parent first.'),
        'announce_locked' => __('studio_ui_announce_locked', 'This layer is locked. Unlock it first.'),
        'announce_layer_locked' => __('studio_ui_announce_layer_locked', '{label} locked'),
        'announce_layer_unlocked' => __('studio_ui_announce_layer_unlocked', '{label} unlocked'),
        'announce_renamed' => __('studio_ui_announce_renamed', 'Renamed to {label}'),
        'layer_name_cleared' => __('studio_ui_layer_name_cleared', 'Layer name cleared'),
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="data:,">
<title><?= e($sbTitle) ?> — <?= e(__('studio_builder', 'Kohevo Studio')) ?></title>
<link rel="stylesheet" href="<?= e(plugin_url('studio-builder', 'assets/builder/builder.css')) ?>?v=<?= e($sbVersion) ?>">
<?php if ($sbMediaPicker): ?>
<link rel="stylesheet" href="<?= e(plugin_url('media-library', 'assets/css/picker.css')) ?>">
<script src="<?= e(plugin_url('media-library', 'assets/js/picker.js')) ?>" defer></script>
<?php endif; ?>
<script type="application/json" id="sb-builder-boot"><?= json_encode($sbBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<script type="module" src="<?= e(plugin_url('studio-builder', 'assets/builder/builder.js')) ?>?v=<?= e($sbVersion) ?>"></script>
</head>
<body class="sbx-body">
<div id="sb-builder-root">
  <noscript><?= e(__('studio_builder_needs_js', 'Kohevo Studio needs JavaScript enabled.')) ?></noscript>
  <p class="sbx-boot-loading" role="status"><?= e(__('studio_builder_loading', 'Loading the builder…')) ?></p>
</div>
</body>
</html>
