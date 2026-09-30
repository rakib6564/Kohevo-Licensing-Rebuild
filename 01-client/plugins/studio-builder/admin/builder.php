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
    } catch (\Throwable $e) {
        if (function_exists('slate_log')) {
            slate_log('Studio builder shell failed: ' . get_class($e), 'error');
        }
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
