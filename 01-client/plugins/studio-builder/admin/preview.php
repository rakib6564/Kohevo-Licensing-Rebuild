<?php
/**
 * Kohevo Studio — authorized preview endpoint.
 *
 *   GET /plugins/studio-builder/admin/preview.php?page=<id>[&revision=<id>]
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
use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
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

if ($pageId === false || $pageId === null || $revisionId === false) {
    StudioHttpResponder::sendAuthoringError(404);
    exit;
}

try {
    $result = StudioRuntimeFactory::build()->app->renderPreview(StudioActor::fromCurrentSession(), $pageId, $revisionId);
    StudioHttpResponder::sendRender($result);
} catch (StudioException $e) {
    StudioHttpResponder::sendAuthoringError($e->httpStatus());
} catch (\Throwable $e) {
    if (function_exists('slate_log')) {
        slate_log('Studio preview failed: ' . get_class($e), 'error');
    }
    StudioHttpResponder::sendAuthoringError(500);
}
