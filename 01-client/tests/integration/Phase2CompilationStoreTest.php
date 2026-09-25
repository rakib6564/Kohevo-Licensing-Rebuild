<?php
declare(strict_types=1);

use Slate\Presentation\ContentCompiler;
use Slate\Presentation\RenderContext;
use Slate\Services\Content\CompilationStore;
use Slate\Services\Content\CompiledContentReader;
use Slate\Services\Content\ContentPublicationService;
use Slate\Services\Content\DependencyStore;
use Slate\Services\Content\PublicContentService;
use Slate\Services\Content\RevisionStore;
use Slate\Tenancy\TenantContext;

const _PHASE2_OWNER = '__probe:phase2';

function _phase2_cleanup(): void
{
    Database::query('DELETE FROM content_dependencies WHERE owner_type = ?', [_PHASE2_OWNER]);
    Database::query('DELETE FROM content_compilations WHERE owner_type = ?', [_PHASE2_OWNER]);
    Database::query('DELETE FROM content_revisions WHERE owner_type = ?', [_PHASE2_OWNER]);
}

$phase2Document = ['schema' => 1, 'type' => 'page', 'template' => '', 'sections' => [], 'seo' => []];
$phase2Compiler = new class implements ContentCompiler {
    public function compile(array $document, RenderContext $context): string
    {
        return '<main data-schema="' . (int)$document['schema'] . '"></main>';
    }
};
$phase2Tenants = new TenantContext();
$phase2Revisions = new RevisionStore($phase2Tenants);
$phase2Compilations = new CompilationStore($phase2Tenants);
$phase2Dependencies = new DependencyStore($phase2Tenants);
$phase2Service = new ContentPublicationService($phase2Revisions, $phase2Compilations, $phase2Dependencies, $phase2Compiler, []);

unit('save compiles canonical content and records dependencies atomically', function () use ($phase2Service, $phase2Compilations, $phase2Dependencies, $phase2Document) {
    _phase2_cleanup();
    try {
        $result = $phase2Service->save(_PHASE2_OWNER, 901, $phase2Document, RenderContext::for(1), 'theme-1', ['global:header'], 7);
        assert_true($result['revision_id'] > 0);
        assert_true($result['compilation_id'] > 0);
        assert_eq('<main data-schema="1"></main>', $result['content_html']);
        assert_eq('global:header', $phase2Dependencies->dependents('global:header')[0]['dependency_key']);
        assert_eq('<main data-schema="1"></main>', $phase2Compilations->latest(_PHASE2_OWNER, 901)['content_html']);
    } finally {
        _phase2_cleanup();
    }
});

unit('public reader serves only a matching compilation fingerprint', function () use ($phase2Service, $phase2Compilations, $phase2Document, $phase2Tenants) {
    _phase2_cleanup();
    try {
        $result = $phase2Service->save(_PHASE2_OWNER, 902, $phase2Document, RenderContext::for(1), 'theme-1', [], null);
        $metadata = $result['metadata'];
        $reader = new CompiledContentReader($phase2Compilations);
        assert_eq('<main data-schema="1"></main>', $reader->read(_PHASE2_OWNER, 902, $metadata['content_fingerprint'], '1.0.0', 'theme-1'));
        assert_eq(null, $reader->read(_PHASE2_OWNER, 902, 'stale', '1.0.0', 'theme-1'));
    } finally {
        _phase2_cleanup();
    }
});

unit('compilation artifacts and dependencies are tenant-isolated', function () use ($phase2Service, $phase2Compilations, $phase2Document, $phase2Tenants) {
    _phase2_cleanup();
    try {
        $phase2Service->save(_PHASE2_OWNER, 903, $phase2Document, RenderContext::for(1), 'theme-1', ['global:footer']);
        $other = current_tenant_id() + 90001;
        $seen = $phase2Tenants->runAs($other, fn () => (new CompilationStore(new TenantContext()))->latest(_PHASE2_OWNER, 903));
        assert_eq(null, $seen);
        assert_true($phase2Compilations->latest(_PHASE2_OWNER, 903) !== null);
    } finally {
        _phase2_cleanup();
    }
});

unit('public coordinator serves fresh HTML and recompiles stale revisions', function () use ($phase2Service, $phase2Compilations, $phase2Document, $phase2Compiler) {
    _phase2_cleanup();
    try {
        $result = $phase2Service->save(_PHASE2_OWNER, 904, $phase2Document, RenderContext::for(1), 'theme-1', [], null);
        $public = new PublicContentService($phase2Compilations, $phase2Compiler);
        $hit = $public->render(_PHASE2_OWNER, 904, $result['revision_id'], $phase2Document, RenderContext::for(1), 'theme-1');
        assert_true($hit['cache_hit']);
        assert_eq('<main data-schema="1"></main>', $hit['html']);
        $miss = $public->render(_PHASE2_OWNER, 904, $result['revision_id'] + 1, $phase2Document, RenderContext::for(1), 'theme-1');
        assert_false($miss['cache_hit']);
    } finally {
        _phase2_cleanup();
    }
});
