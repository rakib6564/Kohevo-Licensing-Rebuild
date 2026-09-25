<?php
/**
 * Integration tests for DocumentTemplateRepository against the real
 * document_templates table (migration 0020) — the store backing admin/
 * templates.php's "Your Templates" (Save as Template / Use Template / Export /
 * Import). Create/find/documentOf round-trip and tenant isolation. Every probe
 * row is cleaned up.
 */

declare(strict_types=1);

use Slate\Services\Content\DocumentTemplateRepository;
use Slate\Tenancy\TenantContext;

const _TPL_PROBE_NAME = '__probe:document-template-test';

/** Remove every probe template (all tenants) — test hygiene. */
function _tpl_cleanup(): void
{
    Database::query('DELETE FROM document_templates WHERE name = ?', [_TPL_PROBE_NAME]);
}

$repo = new DocumentTemplateRepository(new TenantContext());

$DOCUMENT = ['schema' => 1, 'type' => 'page', 'template' => '', 'sections' => [
    ['id' => 's1', 'layout' => ['cols' => 1, 'bg' => '', 'pad' => 'normal', 'width' => 'normal'],
     'blocks' => [['type' => 'heading', 'props' => ['text' => 'Hello template']]]],
], 'seo' => []];

unit('create + find + documentOf round-trip the stored document exactly', function () use ($repo, $DOCUMENT) {
    _tpl_cleanup();
    try {
        $id = $repo->create(_TPL_PROBE_NAME, 'page', $DOCUMENT);
        assert_true($id > 0);

        $row = $repo->find($id);
        assert_true($row !== null, 'the created row is findable');
        assert_eq(_TPL_PROBE_NAME, $row['name']);
        assert_eq('page', $row['type']);

        $decoded = $repo->documentOf($row);
        assert_eq('Hello template', $decoded['sections'][0]['blocks'][0]['props']['text'], 'the document round-trips byte-for-byte through JSON storage');
    } finally {
        _tpl_cleanup();
    }
});

unit('documentOf returns null for a missing or corrupt document column rather than throwing', function () use ($repo) {
    assert_eq(null, $repo->documentOf(['document' => 'not json']));
    assert_eq(null, $repo->documentOf([]));
});

unit('all() lists templates for the current tenant only', function () use ($repo, $DOCUMENT) {
    _tpl_cleanup();
    try {
        $repo->create(_TPL_PROBE_NAME, 'page', $DOCUMENT);
        $names = array_column($repo->all(), 'name');
        assert_true(in_array(_TPL_PROBE_NAME, $names, true), 'a template created for the current tenant appears in all()');

        (new TenantContext())->runAs(999999, function () {
            $names = array_column((new DocumentTemplateRepository(new TenantContext()))->all(), 'name');
            assert_false(in_array(_TPL_PROBE_NAME, $names, true), 'a different tenant does not see this template (Repository auto-scoping)');
        });
    } finally {
        _tpl_cleanup();
    }
});
