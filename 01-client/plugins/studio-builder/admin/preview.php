<?php
/**
 * Kohevo Studio — authorized preview endpoint.
 *
 *   GET /plugins/studio-builder/admin/preview.php?page=<id>[&revision=<id>][&as_page=<id>]
 *
 * `as_page` (header/footer): show THAT page with this header or footer swapped in; (single template): show the
 * template for that page. `archive`+`term` (archive template) and `q` (search template) are sample request values.
 *
 * A thin HTTP adapter over StudioApplicationService::renderPreview(), which
 * enforces, in order: active tenant -> authenticated actor -> studio-builder
 * entitlement -> studio-builder.view -> page ownership (tenant-scoped lookup)
 * -> revision ownership (the revision must belong to THAT page of THAT tenant)
 * -> revision validity (render pipeline validation). The revision id is never
 * trusted on its own.
 *
 * Output is private, no-store, noindex/nofollow, rendered by the SAME pipeline
 * as the public page, and has no side effect (no revision, no compilation, no
 * audit row). Errors never include internal detail.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Http\StudioPreviewPolicy;
use Slate\Module\StudioBuilder\Render\RenderResult;
use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
use Slate\Module\StudioBuilder\Runtime\StudioLog;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    StudioHttpResponder::sendAuthoringError(400);
    exit;
}

Auth::require();

$pageId     = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$revisionId = isset($_GET['revision'])
    ? filter_var($_GET['revision'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;

$asPageId = isset($_GET['as_page'])
    ? filter_var($_GET['as_page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : null;

// A sample for a template's preview: archive type and term, or search words. Plain strings; the service cuts them.
$sample = [];
foreach (['archive_type' => 'archive', 'term' => 'term', 'search_query' => 'q'] as $key => $param) {
    if (isset($_GET[$param]) && is_string($_GET[$param])) {
        $sample[$key] = $_GET[$param];
    }
}

if ($pageId === false || $pageId === null || $revisionId === false || $asPageId === false) {
    StudioHttpResponder::sendAuthoringError(404);
    exit;
}

try {
    $result = StudioRuntimeFactory::build()->app->renderPreview(StudioActor::fromCurrentSession(), $pageId, $revisionId, $asPageId, $sample);
    // The builder frames this with scripts allowed: only the platform's own script files may run (see StudioPreviewPolicy).
    StudioHttpResponder::sendRender(new RenderResult($result->mode, $result->html, StudioPreviewPolicy::headers($result), $result->etag));
} catch (StudioException $e) {
    if ($e->httpStatus() >= 500) {
        StudioLog::failure('preview', 'render', $e);
    }
    StudioHttpResponder::sendAuthoringError($e->httpStatus());
} catch (\Throwable $e) {
    StudioLog::failure('preview', 'render', $e);
    StudioHttpResponder::sendAuthoringError(500);
}
