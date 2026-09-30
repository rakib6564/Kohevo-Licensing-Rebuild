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
$sbVersion  = (string) (@filemtime($sbAssetDir . '/builder.js') ?: '0');
$sbMediaPicker = class_exists('PluginLoader') && PluginLoader::isActive('media-library') && Auth::can('media.view');
// Phase 7: the AI assistant entry point (the MCP gateway's admin chat) is offered only
// when that module is active and the signed-in admin may open it. The chat prepares
// DRAFT revisions; publishing stays this builder's own Publish action.
$sbAssistantUrl = class_exists('PluginLoader') && PluginLoader::isActive('mcp-gateway') && (Auth::can('mcp-gateway.manage') || Auth::isSuperAdmin())
    ? plugin_url('mcp-gateway', 'admin/chat.php')
    : null;

$sbBoot = [
    'pageId'     => $sbPageId,
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
