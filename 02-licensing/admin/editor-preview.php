<?php
/**
 * Slate — Draft preview for the Phase 2 visual editor.
 *
 * Route: /admin/editor-preview.php?id=<postId>
 *
 * Renders the WORKING (unpublished) revision through PreviewService, which
 * uses the same rendering pipeline as public output with cache bypassed and
 * noindex forced (rendering-pipeline.md §5: "preview is the same path with two
 * inputs flipped"). Replaces the previous preview link, which pointed at the
 * archived content-builder plugin's canvas-preview.php (equally dead today).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

use Slate\Presentation\RenderContext;
use Slate\Services\Content\ContentPageRepository;
use Slate\Services\Content\ContentServiceFactory;
use Slate\Services\Content\PublicContentRoute;
use Slate\Services\Content\RevisionStore;

Auth::require();
Auth::requirePerm('content.edit');

$postId = (int)($_GET['id'] ?? 0);
$pages  = ContentServiceFactory::pageRepository();
$post   = $postId ? $pages->find($postId) : null;
if (!$post) {
    http_response_code(404);
    die('Post not found.');
}

$revisions = ContentServiceFactory::revisionStore();
$working   = $revisions->working(ContentPageRepository::OWNER_TYPE, $postId);
$document  = $working !== null
    ? RevisionStore::documentOf($working)
    : ['schema' => 1, 'type' => $post['type'], 'template' => '', 'sections' => [], 'seo' => []];
$document['type'] = $post['type'];

$context = RenderContext::for(current_tenant_id(), RenderContext::SURFACE_PAGE)
    ->withPreview(true)
    ->withTheme(ContentServiceFactory::theme());

try {
    $result = ContentServiceFactory::previewService()->render($document, $context);
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    die('Preview unavailable: ' . e($e->getMessage()));
}

foreach ($result['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
// Same treatment PublicContentRoute gives real published output — a draft
// preview that doesn't carry the block-rendering stylesheet or the tenant's
// actual brand isn't a real preview of what publishing will produce.
echo PublicContentRoute::withBlockStylesheet($result['html']);
