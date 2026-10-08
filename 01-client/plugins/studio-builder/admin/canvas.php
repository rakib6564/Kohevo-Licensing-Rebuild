<?php
/**
 * Kohevo Studio — builder canvas document.
 *
 *   GET /plugins/studio-builder/admin/canvas.php?page=<id>[&v=<cache-buster>]
 *
 * The page's CURRENT working draft rendered by the Phase 4 server renderer in
 * Editor mode (`StudioApplicationService::renderForEditor()`: tenant ->
 * authentication -> studio-builder entitlement -> studio-builder.edit -> page
 * ownership). The builder shows it in a same-origin iframe sandboxed without
 * scripts, and reads `data-sb-node` / `data-sb-type` for selection — the
 * canvas never re-implements block rendering and never renders from browser
 * state. `v` only defeats caching between revisions and is otherwise ignored.
 * Never the public runtime, never cached, never indexed, no side effects.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';

// The editing canvas is not a visitor page: no language-switcher widget (and its inline script) may be added to it.
if (!defined('SLATE_NO_LANG_SWITCHER')) {
    define('SLATE_NO_LANG_SWITCHER', true);
}

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Http\StudioCanvasPolicy;
use Slate\Module\StudioBuilder\Render\RenderResult;
use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
use Slate\Module\StudioBuilder\Runtime\StudioLog;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    StudioHttpResponder::sendAuthoringError(400);
    exit;
}

if (!Auth::check()) {
    StudioHttpResponder::sendAuthoringError(401);
    exit;
}

$pageId = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($pageId === false || $pageId === null) {
    StudioHttpResponder::sendAuthoringError(404);
    exit;
}

try {
    $result = StudioRuntimeFactory::build()->app->renderForEditor(StudioActor::fromCurrentSession(), $pageId);
    StudioHttpResponder::sendRender(new RenderResult($result->mode, $result->html, StudioCanvasPolicy::headers($result)));
} catch (StudioException $e) {
    if ($e->httpStatus() >= 500) {
        StudioLog::failure('canvas', 'render', $e);
    }
    StudioHttpResponder::sendAuthoringError($e->httpStatus());
} catch (\Throwable $e) {
    StudioLog::failure('canvas', 'render', $e);
    StudioHttpResponder::sendAuthoringError(500);
}
