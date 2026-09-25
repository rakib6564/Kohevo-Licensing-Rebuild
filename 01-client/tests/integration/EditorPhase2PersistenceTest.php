<?php
/**
 * Phase 2 editor migration — canonical save/publish/invalid-document coverage
 * for admin/editor.php's AJAX handlers, now backed by ContentPublicationService/
 * DocumentValidator/RevisionStore instead of the archived ContentBuilderAPI.
 *
 * Drives the real page through admin-page-post-with-query-probe.php (a child
 * process, real CSRF, real Auth session) rather than calling the services
 * directly — the point of these tests is that the ADMIN PAGE wiring works,
 * which unit-level service tests (RevisionStoreTest.php, Phase2CompilationStoreTest.php,
 * DocumentValidatorTest.php) don't exercise.
 */

declare(strict_types=1);

use Slate\Services\Content\RevisionStore;
use Slate\Tenancy\TenantContext;

function ep2p_probe(string $query, array $fields, int $roleId = 1): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-with-query-probe.php') . ' '
         . escapeshellarg('admin/editor.php') . ' '
         . escapeshellarg($query) . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' 0 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    $body = substr($out, strlen($m[0]));
    $json = json_decode($body, true);
    return ['status' => (int) $m[1], 'json' => $json, 'body' => $body];
}

function ep2p_make_page(string $slug): int
{
    return (int) Database::insert('content_pages', [
        'type'  => 'page',
        'title' => 'EP2 Probe',
        'slug'  => $slug,
    ]);
}

function ep2p_cleanup(int $pageId): void
{
    Database::query('DELETE FROM content_dependencies WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
    Database::query('DELETE FROM content_compilations WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
    Database::query('DELETE FROM content_revisions WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
    Database::query('DELETE FROM content_pages WHERE id = ?', [$pageId]);
}

unit('save_draft validates, normalizes, and snapshots a working revision via RevisionStore', function () {
    $pageId = ep2p_make_page('ep2-save-' . bin2hex(random_bytes(4)));
    try {
        $layout = [['type' => 'heading', 'props' => ['text' => 'Hello Phase 2', 'level' => '2']]];
        $res = ep2p_probe('id=' . $pageId, [
            '_editor_action' => 'save_draft',
            'layout'         => json_encode($layout),
            'title'          => 'Updated Title',
            'slug'           => '',
        ]);
        assert_eq(200, $res['status']);
        assert_true((bool) ($res['json']['ok'] ?? false), 'save_draft should succeed: ' . $res['body']);
        assert_eq('draft_saved', $res['json']['action'] ?? null);

        $store = new RevisionStore(new TenantContext());
        $working = $store->working('content_pages', $pageId);
        assert_true($working !== null, 'a working revision must exist after save_draft');
        $doc = RevisionStore::documentOf($working);
        assert_eq(1, count($doc['sections']), 'flat layout upconverts to exactly one implicit section');
        assert_eq('heading', $doc['sections'][0]['blocks'][0]['type']);
        assert_eq('Hello Phase 2', $doc['sections'][0]['blocks'][0]['props']['text']);

        $page = Database::row('SELECT title FROM content_pages WHERE id = ?', [$pageId]);
        assert_eq('Updated Title', $page['title'], 'save_draft also updates the owning row title');
    } finally {
        ep2p_cleanup($pageId);
    }
});

unit('publish promotes the working document into a new published revision and marks the row published', function () {
    $pageId = ep2p_make_page('ep2-publish-' . bin2hex(random_bytes(4)));
    try {
        $layout = [['type' => 'paragraph', 'props' => ['text' => 'v1']]];
        ep2p_probe('id=' . $pageId, [
            '_editor_action' => 'save_draft', 'layout' => json_encode($layout), 'title' => '', 'slug' => '',
        ]);

        $res = ep2p_probe('id=' . $pageId, [
            '_editor_action' => 'publish', 'layout' => json_encode($layout), 'title' => '', 'slug' => '',
        ]);
        assert_true((bool) ($res['json']['ok'] ?? false), 'publish should succeed: ' . $res['body']);
        assert_eq('published', $res['json']['action'] ?? null);

        $store = new RevisionStore(new TenantContext());
        $published = $store->published('content_pages', $pageId);
        assert_true($published !== null, 'a published revision must exist');
        assert_eq('v1', RevisionStore::documentOf($published)['sections'][0]['blocks'][0]['props']['text']);

        $page = Database::row('SELECT status FROM content_pages WHERE id = ?', [$pageId]);
        assert_eq('published', $page['status']);
    } finally {
        ep2p_cleanup($pageId);
    }
});

unit('publish is refused without content.publish (role without super-admin bypass)', function () {
    $pageId = ep2p_make_page('ep2-nopub-' . bin2hex(random_bytes(4)));
    try {
        $layout = [['type' => 'paragraph', 'props' => ['text' => 'x']]];
        // A non-existent role id has no role_permissions rows and no super-
        // admin bypass, so it must be denied — either by requirePerm() at the
        // top of the page (a plain 403, not JSON) or by the in-handler
        // Auth::can('content.publish') check (a JSON {ok:false}). Either
        // outcome is an acceptable "refused"; what must NEVER happen is a
        // published revision appearing.
        $res = ep2p_probe('id=' . $pageId, [
            '_editor_action' => 'publish', 'layout' => json_encode($layout), 'title' => '', 'slug' => '',
        ], 999999);
        $refused = $res['status'] === 403 || (($res['json']['ok'] ?? null) === false);
        assert_true($refused, 'an unprivileged role must not be able to publish: ' . $res['body']);

        $store = new RevisionStore(new TenantContext());
        assert_eq(null, $store->published('content_pages', $pageId), 'no published revision must exist');
    } finally {
        ep2p_cleanup($pageId);
    }
});

unit('save_draft rejects a document with a newly-inserted unknown block type', function () {
    $pageId = ep2p_make_page('ep2-invalid-' . bin2hex(random_bytes(4)));
    try {
        $layout = [['type' => 'totally-bogus-block-type', 'props' => ['x' => 1]]];
        $res = ep2p_probe('id=' . $pageId, [
            '_editor_action' => 'save_draft', 'layout' => json_encode($layout), 'title' => '', 'slug' => '',
        ]);
        assert_false((bool) ($res['json']['ok'] ?? true), 'an unregistered block type must not save');
        assert_eq('validation_failed', $res['json']['error'] ?? null);
        assert_true(!empty($res['json']['errors']), 'structured field-path errors must be returned');

        $store = new RevisionStore(new TenantContext());
        assert_eq(null, $store->working('content_pages', $pageId), 'nothing should have been persisted');
    } finally {
        ep2p_cleanup($pageId);
    }
});

unit('save_draft rejects an oversized prop value', function () {
    $pageId = ep2p_make_page('ep2-oversized-' . bin2hex(random_bytes(4)));
    try {
        // Keep the payload above DocumentValidator::MAX_STRING_LENGTH while
        // remaining below Linux's argv limit used by this child-process probe.
        $layout = [['type' => 'heading', 'props' => ['text' => str_repeat('x', 110000), 'level' => '1']]];
        $res = ep2p_probe('id=' . $pageId, [
            '_editor_action' => 'save_draft', 'layout' => json_encode($layout), 'title' => '', 'slug' => '',
        ]);
        assert_false((bool) ($res['json']['ok'] ?? true), 'an oversized string prop must fail validation');
        assert_eq('validation_failed', $res['json']['error'] ?? null);
    } finally {
        ep2p_cleanup($pageId);
    }
});
