<?php
/**
 * Phase 2 editor migration — revision history (list_revisions) and restore
 * (restore_revision), both required to go only through RevisionStore, never
 * a raw UPDATE. Restore re-enters ContentPublicationService::save() with the
 * older document, so it is itself a new append-only revision, not a mutation
 * of history.
 */

declare(strict_types=1);

use Slate\Services\Content\RevisionStore;
use Slate\Tenancy\TenantContext;

function ep2r_probe(string $query, array $fields, int $roleId = 1): array
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

function ep2r_make_page(): int
{
    return (int) Database::insert('content_pages', ['type' => 'page', 'title' => 'Rev probe', 'slug' => 'ep2r-' . bin2hex(random_bytes(4))]);
}

function ep2r_cleanup(int $pageId): void
{
    Database::query('DELETE FROM content_dependencies WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
    Database::query('DELETE FROM content_compilations WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
    Database::query('DELETE FROM content_revisions WHERE owner_type = ? AND owner_id = ?', ['content_pages', $pageId]);
    Database::query('DELETE FROM content_pages WHERE id = ?', [$pageId]);
}

unit('list_revisions returns newest-first history without leaking the raw document blob', function () {
    $pageId = ep2r_make_page();
    try {
        foreach (['v1', 'v2', 'v3'] as $text) {
            ep2r_probe('id=' . $pageId, [
                '_editor_action' => 'save_draft',
                'layout' => json_encode([['type' => 'paragraph', 'props' => ['text' => $text]]]),
                'title' => '', 'slug' => '',
            ]);
        }
        $res = ep2r_probe('id=' . $pageId, ['_editor_action' => 'list_revisions']);
        assert_true((bool) ($res['json']['ok'] ?? false));
        $revs = $res['json']['revisions'];
        assert_eq(3, count($revs));
        assert_true($revs[0]['revision'] > $revs[1]['revision'], 'newest first');
        assert_false(array_key_exists('document', $revs[0]), 'the document blob must not be returned in the list');
    } finally {
        ep2r_cleanup($pageId);
    }
});

unit('restore_revision makes an older document the new working draft via a new revision', function () {
    $pageId = ep2r_make_page();
    try {
        ep2r_probe('id=' . $pageId, [
            '_editor_action' => 'save_draft',
            'layout' => json_encode([['type' => 'paragraph', 'props' => ['text' => 'original']]]),
            'title' => '', 'slug' => '',
        ]);
        $store = new RevisionStore(new TenantContext());
        $original = $store->working('content_pages', $pageId);
        $originalId = (int) $original['id'];

        ep2r_probe('id=' . $pageId, [
            '_editor_action' => 'save_draft',
            'layout' => json_encode([['type' => 'paragraph', 'props' => ['text' => 'changed']]]),
            'title' => '', 'slug' => '',
        ]);
        assert_eq('changed', RevisionStore::documentOf($store->working('content_pages', $pageId))['sections'][0]['blocks'][0]['props']['text']);

        $res = ep2r_probe('id=' . $pageId, ['_editor_action' => 'restore_revision', 'revision_id' => $originalId]);
        assert_true((bool) ($res['json']['ok'] ?? false), 'restore should succeed: ' . json_encode($res['json']));
        assert_eq('original', $res['json']['layout'][0]['props']['text'] ?? null);

        $countBefore = 2; // original + changed
        $rows = $store->listForOwner('content_pages', $pageId);
        assert_true(count($rows) > $countBefore, 'restore must append a new revision, never overwrite an old one');
        assert_eq('original', RevisionStore::documentOf($store->working('content_pages', $pageId))['sections'][0]['blocks'][0]['props']['text']);
    } finally {
        ep2r_cleanup($pageId);
    }
});

unit('restore_revision refuses a revision id belonging to a different post', function () {
    $pageA = ep2r_make_page();
    $pageB = ep2r_make_page();
    try {
        ep2r_probe('id=' . $pageA, [
            '_editor_action' => 'save_draft',
            'layout' => json_encode([['type' => 'paragraph', 'props' => ['text' => 'page-a-content']]]),
            'title' => '', 'slug' => '',
        ]);
        $store = new RevisionStore(new TenantContext());
        $revA = (int) $store->working('content_pages', $pageA)['id'];

        // Attempt to restore page A's revision onto page B.
        $res = ep2r_probe('id=' . $pageB, ['_editor_action' => 'restore_revision', 'revision_id' => $revA]);
        assert_false((bool) ($res['json']['ok'] ?? true), 'a revision belonging to another post must be refused');
        assert_eq(null, $store->working('content_pages', $pageB), 'page B must remain untouched');
    } finally {
        ep2r_cleanup($pageA);
        ep2r_cleanup($pageB);
    }
});
