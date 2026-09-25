<?php
/**
 * Phase 2 editor migration — public route + dependency-invalidation wiring
 * added on top of the editor migration (PublicRouter -> PublicContentRoute,
 * DependencyInvalidationService -> PageContentRecompiler).
 *
 * Regression coverage for a real bug found before merging that work: both
 * PublicContentRoute::dispatch() and PageContentRecompiler::recompile() were
 * keyed to the owner_type literal 'page', while every content_pages revision
 * is actually stored under ContentPageRepository::OWNER_TYPE ('content_pages')
 * — so the public route 404'd every published page, and global-dependency
 * invalidation silently no-op'd, with no test catching either. Fixed by
 * routing both through the shared ContentPageRepository::OWNER_TYPE constant.
 */

declare(strict_types=1);

use Slate\Presentation\RenderContext;
use Slate\Services\Content\ContentPageRepository;
use Slate\Services\Content\ContentServiceFactory;

function ep2pub_probe(string $slug): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/public-page-probe.php') . ' '
         . escapeshellarg('public.php') . ' '
         . escapeshellarg('_path=' . $slug) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

function ep2pub_make_page(string $slug): int
{
    return ContentServiceFactory::pageRepository()->create('page', 'Public route probe', $slug);
}

function ep2pub_cleanup(int $pageId): void
{
    Database::query('DELETE FROM content_dependencies WHERE owner_type = ? AND owner_id = ?', [ContentPageRepository::OWNER_TYPE, $pageId]);
    Database::query('DELETE FROM content_compilations WHERE owner_type = ? AND owner_id = ?', [ContentPageRepository::OWNER_TYPE, $pageId]);
    Database::query('DELETE FROM content_revisions WHERE owner_type = ? AND owner_id = ?', [ContentPageRepository::OWNER_TYPE, $pageId]);
    Database::query('DELETE FROM content_pages WHERE id = ?', [$pageId]);
}

unit('a published content page is served publicly at its slug', function () {
    $slug = 'ep2pub-' . bin2hex(random_bytes(4));
    $pageId = ep2pub_make_page($slug);
    try {
        $document = \Slate\Presentation\EditorStateSerializer::serialize([
            'document' => [['type' => 'heading', 'props' => ['text' => 'Published Public Page', 'level' => '1']]],
        ]);
        $document['type'] = 'page';
        $context = RenderContext::for(current_tenant_id(), RenderContext::SURFACE_PAGE);
        $publication = ContentServiceFactory::publicationService();
        $publication->save(ContentPageRepository::OWNER_TYPE, $pageId, $document, $context, '1');
        $publication->publish(ContentPageRepository::OWNER_TYPE, $pageId, $context, '1');
        ContentServiceFactory::pageRepository()->markPublished($pageId);

        $res = ep2pub_probe($slug);
        assert_eq(200, $res['status'], 'a published page must be reachable at its slug');
        assert_true(str_contains($res['body'], 'Published Public Page'), 'the response must contain the compiled block content');
    } finally {
        ep2pub_cleanup($pageId);
    }
});

unit('a draft-only (never published) page 404s publicly', function () {
    $slug = 'ep2pub-draft-' . bin2hex(random_bytes(4));
    $pageId = ep2pub_make_page($slug);
    try {
        $document = \Slate\Presentation\EditorStateSerializer::serialize([
            'document' => [['type' => 'heading', 'props' => ['text' => 'Draft Only', 'level' => '1']]],
        ]);
        $document['type'] = 'page';
        $context = RenderContext::for(current_tenant_id(), RenderContext::SURFACE_PAGE);
        ContentServiceFactory::publicationService()->save(ContentPageRepository::OWNER_TYPE, $pageId, $document, $context, '1');

        $res = ep2pub_probe($slug);
        assert_eq(404, $res['status'], 'a draft that was never published must not be publicly reachable');
    } finally {
        ep2pub_cleanup($pageId);
    }
});

unit('an unknown slug 404s without a fatal error', function () {
    $res = ep2pub_probe('ep2pub-does-not-exist-' . bin2hex(random_bytes(4)));
    assert_eq(404, $res['status']);
});

unit('a published page links the block-rendering stylesheet (regression: DocumentTemplate never emitted it)', function () {
    // DocumentTemplate::render() only ever emitted the --slate-* token block,
    // never the hero/card/cta/testimonial/columns/rx-* CSS the blocks'
    // rendered markup actually depends on — every published Phase 2 page
    // rendered with zero of that styling until PublicContentRoute started
    // linking assets/css/content-blocks.css into the head itself.
    $slug = 'ep2pub-css-' . bin2hex(random_bytes(4));
    $pageId = ep2pub_make_page($slug);
    try {
        $document = \Slate\Presentation\EditorStateSerializer::serialize([
            'document' => [['type' => 'hero', 'props' => ['heading' => 'Styled Hero']]],
        ]);
        $document['type'] = 'page';
        $context = RenderContext::for(current_tenant_id(), RenderContext::SURFACE_PAGE);
        $publication = ContentServiceFactory::publicationService();
        $publication->save(ContentPageRepository::OWNER_TYPE, $pageId, $document, $context, '1');
        $publication->publish(ContentPageRepository::OWNER_TYPE, $pageId, $context, '1');
        ContentServiceFactory::pageRepository()->markPublished($pageId);

        $res = ep2pub_probe($slug);
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], 'assets/css/content-blocks.css'), 'the published page must link the block-rendering stylesheet');
        assert_true(str_contains($res['body'], '</head>'), 'the stylesheet link must land inside a real <head>, not be appended blindly');
    } finally {
        ep2pub_cleanup($pageId);
    }
});

unit('a block\'s Style-panel customization actually renders on the published page (regression: PageRenderer deferred to the archive-only \\Renderer::applyStyle())', function () {
    $slug = 'ep2pub-style-' . bin2hex(random_bytes(4));
    $pageId = ep2pub_make_page($slug);
    try {
        $document = \Slate\Presentation\EditorStateSerializer::serialize([
            'document' => [[
                'type' => 'heading',
                'props' => ['text' => 'Styled Heading', 'level' => '2'],
                'style' => ['bgColor' => '#22aa66', 'responsive' => ['mobile' => ['textAlign' => 'center']]],
            ]],
        ]);
        $document['type'] = 'page';
        $context = RenderContext::for(current_tenant_id(), RenderContext::SURFACE_PAGE);
        $publication = ContentServiceFactory::publicationService();
        $publication->save(ContentPageRepository::OWNER_TYPE, $pageId, $document, $context, '1');
        $publication->publish(ContentPageRepository::OWNER_TYPE, $pageId, $context, '1');
        ContentServiceFactory::pageRepository()->markPublished($pageId);

        $res = ep2pub_probe($slug);
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['body'], 'background-color:rgba(34,170,102,1)'), 'the saved background color must actually render: ' . $res['body']);
        assert_true((bool) preg_match('/@media \(max-width:640px\)\{#[a-z0-9-]+\{text-align:center;\}\}/', $res['body']), 'the mobile responsive override must render as a real scoped @media rule: ' . $res['body']);
    } finally {
        ep2pub_cleanup($pageId);
    }
});

function ep2pub_editor_post(int $pageId, array $fields): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-with-query-probe.php') . ' '
         . escapeshellarg('admin/editor.php') . ' '
         . escapeshellarg('id=' . $pageId) . ' '
         . escapeshellarg(json_encode($fields)) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) ($m[1] ?? 0), 'body' => substr($out, strlen($m[0] ?? ''))];
}

unit('admin/editor.php\'s own Publish action compiles a real page, not a bare fragment (regression: it reused the canvas\'s SURFACE_FRAGMENT context, so the cached HTML — served verbatim to real visitors whenever its fingerprint/version matched — had no <html>/<head>/theme/stylesheet at all)', function () {
    $slug = 'ep2pub-realpublish-' . bin2hex(random_bytes(4));
    $pageId = ep2pub_make_page($slug);
    try {
        $layout = [['type' => 'heading', 'props' => ['text' => 'Real Publish Test', 'level' => '1']]];
        $res = ep2pub_editor_post($pageId, ['_editor_action' => 'publish', 'layout' => json_encode($layout), 'title' => 'Real Publish Test', 'slug' => $slug]);
        assert_eq(200, $res['status'], $res['body']);
        $json = json_decode($res['body'], true);
        assert_true(is_array($json) && ($json['ok'] ?? false) === true, 'publish must succeed: ' . $res['body']);

        $compiled = ContentServiceFactory::compilationStore()->latest(ContentPageRepository::OWNER_TYPE, $pageId);
        assert_true($compiled !== null, 'a compilation row must exist after publish');
        assert_true(str_contains((string) $compiled['content_html'], '<head>'), 'the compiled HTML must be a full document, not a bare content fragment: ' . $compiled['content_html']);
        assert_true(str_contains((string) $compiled['content_html'], 'Real Publish Test'), 'the compiled HTML must contain the actual content');
    } finally {
        ep2pub_cleanup($pageId);
    }
});

unit('PageContentRecompiler actually recompiles a content_pages owner (owner_type must match, not silently no-op)', function () {
    $slug = 'ep2pub-recompile-' . bin2hex(random_bytes(4));
    $pageId = ep2pub_make_page($slug);
    try {
        $document = \Slate\Presentation\EditorStateSerializer::serialize([
            'document' => [['type' => 'heading', 'props' => ['text' => 'Recompile Me', 'level' => '1']]],
        ]);
        $document['type'] = 'page';
        $context = RenderContext::for(current_tenant_id(), RenderContext::SURFACE_PAGE);
        $publication = ContentServiceFactory::publicationService();
        $publication->save(ContentPageRepository::OWNER_TYPE, $pageId, $document, $context, '1', ['global:probe-widget']);
        $publication->publish(ContentPageRepository::OWNER_TYPE, $pageId, $context, '1', ['global:probe-widget']);
        ContentServiceFactory::pageRepository()->markPublished($pageId);

        // Wipe the compilation artifact written by publish() so we can prove
        // recompile() itself writes a fresh one, rather than observing a stale
        // row left over from the publish step above.
        Database::query('DELETE FROM content_compilations WHERE owner_type = ? AND owner_id = ?', [ContentPageRepository::OWNER_TYPE, $pageId]);
        assert_eq(null, ContentServiceFactory::compilationStore()->latest(ContentPageRepository::OWNER_TYPE, $pageId), 'precondition: no compilation row left');

        $recompiled = ContentServiceFactory::invalidationService()->invalidate('global:probe-widget');
        assert_true($recompiled >= 1, 'invalidate() must find this page as a dependent');

        $fresh = ContentServiceFactory::compilationStore()->latest(ContentPageRepository::OWNER_TYPE, $pageId);
        assert_true($fresh !== null, 'PageContentRecompiler must actually write a compilation row for a content_pages owner, not silently return');
        assert_true(str_contains((string) $fresh['content_html'], 'Recompile Me'), 'the recompiled HTML must reflect the published document');
    } finally {
        ep2pub_cleanup($pageId);
    }
});
