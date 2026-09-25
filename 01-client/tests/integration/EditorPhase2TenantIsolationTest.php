<?php
/**
 * Phase 2 editor migration — tenant isolation across the whole admin/editor.php
 * surface. A page (content_pages row) created under one tenant must be
 * completely unreachable from another: not loadable, not saveable, not
 * previewable, not listable in revision history, not restorable.
 *
 * content_pages/content_revisions/content_compilations/content_dependencies
 * are all tenant-scoped via the base Repository — this proves the admin page
 * actually goes through that scoping rather than trusting a posted id.
 */

declare(strict_types=1);

use Slate\Tenancy\TenantContext;

function ep2t_probe(string $page, string $query, array $fields, int $tenantOverride): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-with-query-probe.php') . ' '
         . escapeshellarg($page) . ' '
         . escapeshellarg($query) . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . '1 0 ' . escapeshellarg((string) $tenantOverride) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)\n/', $out, $m);
    return ['status' => (int) $m[1], 'json' => json_decode(substr($out, strlen($m[0])), true), 'body' => substr($out, strlen($m[0]))];
}

unit('a page created under tenant A is unreachable (404) when loaded as tenant B', function () {
    $tenants = new TenantContext();
    $tid = current_tenant_id();
    $otherTenant = $tid + 87001;

    $pageId = (int) Database::insert('content_pages', ['tenant_id' => $tid, 'type' => 'page', 'title' => 'Tenant A page', 'slug' => 'ep2t-' . bin2hex(random_bytes(4))]);
    try {
        $res = ep2t_probe('admin/editor.php', 'id=' . $pageId, ['_editor_action' => 'save_draft', 'layout' => '[]', 'title' => '', 'slug' => ''], $otherTenant);
        assert_eq(404, $res['status'], 'a cross-tenant id must 404, not silently operate on someone else\'s page');
    } finally {
        Database::query('DELETE FROM content_pages WHERE id = ?', [$pageId]);
    }
});

unit('save_draft under tenant A never becomes visible to tenant B\'s RevisionStore', function () {
    $tid = current_tenant_id();
    $otherTenant = $tid + 87002;

    $pageId = (int) Database::insert('content_pages', ['tenant_id' => $tid, 'type' => 'page', 'title' => 'Isolated', 'slug' => 'ep2t-iso-' . bin2hex(random_bytes(4))]);
    try {
        ep2t_probe('admin/editor.php', 'id=' . $pageId, [
            '_editor_action' => 'save_draft',
            'layout' => json_encode([['type' => 'paragraph', 'props' => ['text' => 'tenant-a-secret']]]),
            'title' => '', 'slug' => '',
        ], $tid);

        $tenants = new TenantContext();
        $seenFromOther = $tenants->runAs($otherTenant, function () use ($pageId) {
            return (new \Slate\Services\Content\RevisionStore(new TenantContext()))->working('content_pages', $pageId);
        });
        assert_eq(null, $seenFromOther, 'another tenant must never see this revision, even by the same owner_id');
    } finally {
        Database::query('DELETE FROM content_dependencies WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
        Database::query('DELETE FROM content_compilations WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
        Database::query('DELETE FROM content_revisions WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
        Database::query('DELETE FROM content_pages WHERE id = ?', [$pageId]);
    }
});

unit('editor-preview.php also 404s across tenants', function () {
    $tid = current_tenant_id();
    $otherTenant = $tid + 87003;
    $pageId = (int) Database::insert('content_pages', ['tenant_id' => $tid, 'type' => 'page', 'title' => 'Preview isolation', 'slug' => 'ep2t-prev-' . bin2hex(random_bytes(4))]);
    try {
        // admin-page-probe.php has no tenant-override arg; the query-aware
        // POST probe works fine here too — editor-preview.php never branches
        // on REQUEST_METHOD.
        $res = ep2t_probe('admin/editor-preview.php', 'id=' . $pageId, [], $otherTenant);
        assert_eq(404, $res['status']);
    } finally {
        Database::query('DELETE FROM content_pages WHERE id = ?', [$pageId]);
    }
});
