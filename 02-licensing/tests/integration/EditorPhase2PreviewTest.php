<?php
/**
 * Phase 2 editor migration — draft preview via admin/editor-preview.php +
 * PreviewService. Preview must render the WORKING (unpublished) document,
 * bypass cache, and set noindex — the same rendering pipeline as public
 * output with two inputs flipped (rendering-pipeline.md §5).
 */

declare(strict_types=1);

use Slate\Services\Content\RevisionStore;
use Slate\Tenancy\TenantContext;

function ep2v_probe_post(string $query, array $fields, int $roleId = 1): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-with-query-probe.php') . ' '
         . escapeshellarg('admin/editor.php') . ' '
         . escapeshellarg($query) . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' 0 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) $m[1], 'json' => json_decode(substr($out, strlen($m[0])), true)];
}

function ep2v_probe_get(string $page, string $query, int $roleId = 1): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($query) . ' '
         . escapeshellarg((string) $roleId) . ' 0 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) $m[1], 'headers_and_body' => substr($out, strlen($m[0]))];
}

function ep2v_cleanup(int $pageId): void
{
    Database::query('DELETE FROM content_dependencies WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
    Database::query('DELETE FROM content_compilations WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
    Database::query('DELETE FROM content_revisions WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
    Database::query('DELETE FROM content_pages WHERE id = ?', [$pageId]);
}

unit('preview renders the working draft, not the published revision', function () {
    $pageId = (int) Database::insert('content_pages', ['type' => 'page', 'title' => 'Preview probe', 'slug' => 'ep2v-' . bin2hex(random_bytes(4))]);
    try {
        // Publish v1 first.
        ep2v_probe_post('id=' . $pageId, [
            '_editor_action' => 'save_draft',
            'layout' => json_encode([['type' => 'heading', 'props' => ['text' => 'Published Version', 'level' => '1']]]),
            'title' => '', 'slug' => '',
        ]);
        ep2v_probe_post('id=' . $pageId, [
            '_editor_action' => 'publish',
            'layout' => json_encode([['type' => 'heading', 'props' => ['text' => 'Published Version', 'level' => '1']]]),
            'title' => '', 'slug' => '',
        ]);
        // Now save a NEW, unpublished draft change.
        ep2v_probe_post('id=' . $pageId, [
            '_editor_action' => 'save_draft',
            'layout' => json_encode([['type' => 'heading', 'props' => ['text' => 'Unpublished Draft Edit', 'level' => '1']]]),
            'title' => '', 'slug' => '',
        ]);

        $res = ep2v_probe_get('admin/editor-preview.php', 'id=' . $pageId);
        assert_eq(200, $res['status']);
        assert_true(str_contains($res['headers_and_body'], 'Unpublished Draft Edit'), 'preview must reflect the unpublished working draft');
        assert_false(str_contains($res['headers_and_body'], 'Published Version'), 'preview must not show the stale published text');
    } finally {
        ep2v_cleanup($pageId);
    }
});

unit('editor-preview.php is gated by content.edit like the editor itself', function () {
    $pageId = (int) Database::insert('content_pages', ['type' => 'page', 'title' => 'Gate probe', 'slug' => 'ep2v-gate-' . bin2hex(random_bytes(4))]);
    try {
        $res = ep2v_probe_get('admin/editor-preview.php', 'id=' . $pageId, 999999);
        assert_true($res['status'] === 403, 'an unprivileged role must not reach the preview');
    } finally {
        ep2v_cleanup($pageId);
    }
});

unit('editor-preview.php 404s for a nonexistent page id', function () {
    $res = ep2v_probe_get('admin/editor-preview.php', 'id=999999999');
    assert_eq(404, $res['status']);
});
