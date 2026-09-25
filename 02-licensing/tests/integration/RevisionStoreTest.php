<?php
/**
 * Integration tests for RevisionStore against the real content_revisions table
 * (migration 0004). Append-only history, working→published promotion, monotonic
 * revision numbering, and tenant isolation. Every probe row is cleaned up.
 */

declare(strict_types=1);

use Slate\Services\Content\RevisionStore;
use Slate\Tenancy\TenantContext;

const _REV_OWNER = '__probe:revtest';

/** Remove every probe revision (all tenants) — test hygiene. */
function _rev_cleanup(): void
{
    Database::query('DELETE FROM content_revisions WHERE owner_type = ?', [_REV_OWNER]);
}

$store = new RevisionStore(new TenantContext());
$tid   = current_tenant_id();

$DOC1 = ['schema' => 1, 'type' => 'page', 'template' => '', 'sections' => [
    ['id' => 's1', 'layout' => ['cols' => 1, 'bg' => '', 'pad' => 'normal', 'width' => 'normal'],
     'blocks' => [['type' => 'heading', 'props' => ['text' => 'v1']]]],
], 'seo' => []];
$DOC2 = $DOC1;
$DOC2['sections'][0]['blocks'][0]['props']['text'] = 'v2';

unit('snapshot appends monotonic revisions; latest + get round-trip the document', function () use ($store, $DOC1, $DOC2) {
    _rev_cleanup();
    try {
        $id1 = $store->snapshot(_REV_OWNER, 42, $DOC1, RevisionStore::STATUS_WORKING, null, 'first', 1);
        $id2 = $store->snapshot(_REV_OWNER, 42, $DOC2, RevisionStore::STATUS_WORKING, null, 'second', 1);
        assert_true($id1 > 0 && $id2 > $id1);

        $latest = $store->latest(_REV_OWNER, 42);
        assert_eq(2, (int) $latest['revision'], 'revision numbering is monotonic per owner');
        assert_eq('v2', RevisionStore::documentOf($latest)['sections'][0]['blocks'][0]['props']['text']);

        $first = $store->get($id1);
        assert_eq(1, (int) $first['revision']);
        assert_eq('v1', RevisionStore::documentOf($first)['sections'][0]['blocks'][0]['props']['text']);

        assert_eq(2, $store->countForOwner(_REV_OWNER, 42));
    } finally {
        _rev_cleanup();
    }
});

unit('revision numbering is independent per owner_id', function () use ($store, $DOC1) {
    _rev_cleanup();
    try {
        $store->snapshot(_REV_OWNER, 100, $DOC1);
        $store->snapshot(_REV_OWNER, 100, $DOC1);
        $store->snapshot(_REV_OWNER, 200, $DOC1);
        assert_eq(2, (int) $store->latest(_REV_OWNER, 100)['revision']);
        assert_eq(1, (int) $store->latest(_REV_OWNER, 200)['revision']);
    } finally {
        _rev_cleanup();
    }
});

unit('publish copies the working draft into a new published revision', function () use ($store, $DOC1, $DOC2) {
    _rev_cleanup();
    try {
        assert_eq(null, $store->publish(_REV_OWNER, 7), 'nothing to publish yet');

        $store->snapshot(_REV_OWNER, 7, $DOC1, RevisionStore::STATUS_WORKING);
        $store->snapshot(_REV_OWNER, 7, $DOC2, RevisionStore::STATUS_WORKING);   // working = v2

        $pubId = $store->publish(_REV_OWNER, 7, 99);
        assert_true($pubId > 0);

        $pub = $store->published(_REV_OWNER, 7);
        assert_eq(3, (int) $pub['revision'], 'published is the next revision in sequence');
        assert_eq('published', $pub['status']);
        assert_eq('v2', RevisionStore::documentOf($pub)['sections'][0]['blocks'][0]['props']['text'],
            'publish copies the latest working document');

        // Working pointer still resolves to the last working revision (rev 2).
        assert_eq(2, (int) $store->working(_REV_OWNER, 7)['revision']);
    } finally {
        _rev_cleanup();
    }
});

unit('listForOwner returns newest-first history', function () use ($store, $DOC1) {
    _rev_cleanup();
    try {
        for ($i = 0; $i < 3; $i++) $store->snapshot(_REV_OWNER, 5, $DOC1);
        $rows = $store->listForOwner(_REV_OWNER, 5);
        assert_eq(3, count($rows));
        assert_eq(3, (int) $rows[0]['revision']);
        assert_eq(1, (int) $rows[2]['revision']);
    } finally {
        _rev_cleanup();
    }
});

unit('a non-JSON document string is rejected', function () use ($store) {
    assert_throws(\InvalidArgumentException::class, fn () => $store->snapshot(_REV_OWNER, 1, 'not json'));
});

unit('revisions are tenant-isolated', function () use ($store, $tid, $DOC1) {
    _rev_cleanup();
    try {
        $store->snapshot(_REV_OWNER, 500, $DOC1);   // written as the current tenant
        $tenants = new TenantContext();
        $other = $tid + 90000;
        $seen = $tenants->runAs($other, fn () => (new RevisionStore(new TenantContext()))->latest(_REV_OWNER, 500));
        assert_eq(null, $seen, 'another tenant must not see this revision');
        // And our own tenant still sees it.
        assert_true($store->latest(_REV_OWNER, 500) !== null);
    } finally {
        _rev_cleanup();
    }
});
