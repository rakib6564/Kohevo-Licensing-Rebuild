<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 6 Templates, Global
 * Components and Design System.
 *
 * Dependency-free (no database): the canonical validator's live-reference
 * rules, dependency extraction for chrome/components, copy semantics
 * (DocumentCopier / block-preset children), template extraction, the renderer
 * with pre-resolved components (embedded content, placeholder metadata,
 * unavailable fallbacks), design-token validation/sanitization, the platform
 * signature's independence from tenant tokens, and the builder API transport
 * rules for the new commands. Persistence, tenancy and invalidation are
 * covered by the Phase 6 integration suite.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
    $studioP6UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Dependency\DependencyExtractor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentCopier;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Component\ComponentSource;
use Slate\Module\StudioBuilder\Render\Component\GlobalComponentResolver;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderDocumentPreparer;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
use Slate\Module\StudioBuilder\Service\StudioThemeService;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

const SBP6U_TENANT = 101;
const SBP6U_REF    = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

final class _Sbp6uNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia { return null; }
}

/** @return array<string, mixed> */
function sbp6u_block(string $type, array $props = [], array $extra = []): array
{
    return array_merge([
        'bindings'   => [],
        'children'   => [],
        'id'         => CanonicalDocumentSchema::newBlockId(),
        'props'      => $props,
        'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
        'type'       => $type,
        'version'    => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ], $extra);
}

/** @return array<string, mixed> */
function sbp6u_section(array $blocks, ?string $globalRef = null, string $label = 'Main'): array
{
    return [
        'blocks'     => $blocks,
        'global_ref' => $globalRef,
        'id'         => CanonicalDocumentSchema::newSectionId(),
        'label'      => $label,
        'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ];
}

/** @return array<string, mixed> */
function sbp6u_doc(array $sections, string $type = 'page', array $settings = []): array
{
    $doc = CanonicalDocumentSchema::emptyDocument($type, 'default', 'Doc');
    $doc['settings'] = array_merge($doc['settings'], $settings);
    $doc['sections'] = $sections;
    return $doc;
}

function sbp6u_heading(string $text): array
{
    return sbp6u_block('core.heading', ['text' => $text, 'level' => 'h2']);
}

function sbp6u_site(): SiteContext
{
    return new SiteContext('https://acme.test', 'Acme Studio', '', '', 'en');
}

function sbp6u_renderer(): DocumentRenderer
{
    $registry = ModuleBlockDefinitions::studioRegistry();
    return new DocumentRenderer($registry, BlockRendererRegistry::withStudioRenderers(), new _Sbp6uNoMedia(), new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext()));
}

/** Render prepared sections with a pre-resolved component map (what the compiler hands the renderer). */
function sbp6u_render_sections(array $doc, RenderContext $ctx, array $components): string
{
    $registry = ModuleBlockDefinitions::studioRegistry();
    $prepared = RenderDocumentPreparer::prepare($doc, $registry)['document'];
    $preparedComponents = [];
    foreach ($components as $ref => $componentDoc) {
        $preparedComponents[$ref] = RenderDocumentPreparer::prepare($componentDoc, $registry)['document']['sections'];
    }
    return sbp6u_renderer()->renderSections($prepared['sections'], $ctx, new ResolvedTheme('default', ThemeResolver::DEFAULT_TOKENS), new RenderCollector(), true, $preparedComponents);
}

function sbp6u_editor_ctx(): RenderContext
{
    return RenderContext::forEditor(SBP6U_TENANT, sbp6u_site(), StudioActor::authenticated(3, [StudioPermissions::VIEW, StudioPermissions::EDIT]), static fn(string $m): bool => true);
}

function sbp6u_public_ctx(): RenderContext
{
    return RenderContext::forPublic(SBP6U_TENANT, sbp6u_site(), static fn(string $m): bool => true);
}

/** @return list<string> */
function sbp6u_codes(array $doc, array $options = []): array
{
    return array_column(DocumentValidator::validate($doc, ModuleBlockDefinitions::studioRegistry(), $options)->errors(), 'code');
}

// ── 1. Validator: live references ────────────────────────────────────────────

unit('phase6 validator: a global reference owns no blocks, exists in the tenant, and is only allowed in referencing document types', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $exists = ['partial_exists' => static fn(string $ref): bool => $ref === SBP6U_REF];

    $ok = sbp6u_doc([sbp6u_section([], SBP6U_REF, 'Shared')]);
    assert_eq([], sbp6u_codes($ok, $exists), 'a page may hold an empty section referencing an existing component');
    assert_eq([], sbp6u_codes(sbp6u_doc([sbp6u_section([], SBP6U_REF)], 'landing'), $exists));

    $withBlocks = sbp6u_doc([sbp6u_section([sbp6u_heading('Local')], SBP6U_REF)]);
    assert_true(in_array('global_ref_owns_no_blocks', sbp6u_codes($withBlocks, $exists), true), 'local blocks next to a reference are rejected (no blurred copy/reference)');

    $foreign = sbp6u_doc([sbp6u_section([], 'ffffffff-0000-4000-8000-000000000000')]);
    assert_true(in_array('cross_tenant_or_missing_partial', sbp6u_codes($foreign, $exists), true), 'a reference that does not resolve in the active tenant fails closed');

    foreach (['section_preset', 'header_partial', 'footer_partial'] as $type) {
        $nested = sbp6u_doc([sbp6u_section([], SBP6U_REF)], $type);
        assert_true(in_array('global_ref_not_allowed', sbp6u_codes($nested, $exists), true), "a {$type} document may not reference (one level deep, no cycles)");
    }

    $normalized = DocumentNormalizer::normalize($ok, $registry);
    assert_eq(SBP6U_REF, $normalized['sections'][0]['global_ref'], 'normalization keeps the reference');
    assert_eq([], $normalized['sections'][0]['blocks']);
    assert_true(preg_match(CanonicalDocumentSchema::COMPONENT_REF_PATTERN, SBP6U_REF) === 1, 'a component ref is a v4 uuid');
    assert_true(preg_match(CanonicalDocumentSchema::GLOBAL_REF_PATTERN, SBP6U_REF) === 1, 'and a valid global_ref');
});

// ── 2. Dependencies: components and chrome ──────────────────────────────────

unit('phase6 dependencies: a reference indexes the component uuid; chrome regions are indexed unless hidden; presets index nothing', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $doc = DocumentNormalizer::normalize(sbp6u_doc([sbp6u_section([], SBP6U_REF), sbp6u_section([sbp6u_heading('x')])]), $registry);
    $sigs = array_map(static fn($d): string => $d->uniqueSignature(), DependencyExtractor::extract($doc, $registry));
    assert_true(in_array($doc['sections'][0]['id'] . '|partial|' . SBP6U_REF, $sigs, true), 'component reference recorded under the existing partial type');
    assert_true(in_array(DependencyExtractor::ROOT_NODE_ID . '|partial|' . DependencyExtractor::CHROME_HEADER_KEY, $sigs, true));
    assert_true(in_array(DependencyExtractor::ROOT_NODE_ID . '|partial|' . DependencyExtractor::CHROME_FOOTER_KEY, $sigs, true));

    $hidden = DocumentNormalizer::normalize(sbp6u_doc([sbp6u_section([sbp6u_heading('x')])], 'page', ['header_mode' => 'hidden', 'footer_mode' => 'custom']), $registry);
    $sigs = array_map(static fn($d): string => $d->uniqueSignature(), DependencyExtractor::extract($hidden, $registry));
    assert_false(in_array(DependencyExtractor::ROOT_NODE_ID . '|partial|' . DependencyExtractor::CHROME_HEADER_KEY, $sigs, true), 'a hidden header is not a dependency (no needless rebuild)');
    assert_true(in_array(DependencyExtractor::ROOT_NODE_ID . '|partial|' . DependencyExtractor::CHROME_FOOTER_KEY, $sigs, true), 'custom mode depends on footer partials (it falls back to the site footer)');

    foreach (['header_partial', 'footer_partial', 'section_preset'] as $type) {
        $bare = DocumentNormalizer::normalize(sbp6u_doc([sbp6u_section([sbp6u_heading('x')])], $type), $registry);
        $sigs = array_map(static fn($d): string => $d->uniqueSignature(), DependencyExtractor::extract($bare, $registry));
        assert_true(!in_array(DependencyExtractor::ROOT_NODE_ID . '|partial|' . DependencyExtractor::CHROME_HEADER_KEY, $sigs, true), "{$type} documents have no chrome");
    }

    $refs = GlobalComponentResolver::referencesIn(['sections' => [['global_ref' => SBP6U_REF], ['global_ref' => null], ['global_ref' => SBP6U_REF], ['global_ref' => '<bad>']]]);
    assert_eq([SBP6U_REF], $refs, 'distinct, well-formed refs only');
    assert_eq(ComponentSource::MISSING, (new GlobalComponentResolver())->resolve(SBP6U_REF)->kind, 'without repositories every reference is missing (fail closed)');
});

// ── 3. Copy semantics ───────────────────────────────────────────────────────

unit('phase6 copy: DocumentCopier re-mints every id, drops any reference, and block presets carry their subtree with fresh ids', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $inner = sbp6u_heading('Inner');
    $container = sbp6u_block('core.container', ['direction' => 'vertical', 'gap' => 'md'], ['children' => [$inner]]);
    $section = sbp6u_section([$container, sbp6u_heading('Top')], SBP6U_REF, 'Src');

    $copy = DocumentCopier::copySection($section);
    assert_true($copy['id'] !== $section['id'] && preg_match(CanonicalDocumentSchema::SECTION_ID_PATTERN, $copy['id']) === 1);
    assert_null($copy['global_ref'], 'a copy owns its content — never a live reference');
    assert_true($copy['blocks'][0]['id'] !== $container['id'] && $copy['blocks'][0]['children'][0]['id'] !== $inner['id'], 'nested ids are re-minted');
    assert_eq('Inner', $copy['blocks'][0]['children'][0]['props']['text'], 'content is preserved');
    assert_true(DocumentCopier::copySection($section)['id'] !== $copy['id'], 'every copy is independent');

    // insert_block with children (a block preset): fresh ids for the whole subtree, only into child-capable blocks.
    $doc = sbp6u_doc([sbp6u_section([])]);
    $sec = $doc['sections'][0]['id'];
    $op = new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, ['parent_id' => $sec, 'index' => 0, 'block' => $container]);
    $out = DocumentOperationApplier::apply($doc, [$op], $registry);
    $inserted = $out['sections'][0]['blocks'][0];
    assert_eq(1, count($inserted['children']), 'children of a container preset are inserted');
    assert_true($inserted['id'] !== $container['id'] && $inserted['children'][0]['id'] !== $inner['id']);
    assert_true(DocumentValidator::validate($out, $registry)->isValid(), 'the result is a valid canonical document');

    $leafWithKids = sbp6u_block('core.heading', ['text' => 'H', 'level' => 'h2'], ['children' => [$inner]]);
    $out2 = DocumentOperationApplier::apply($doc, [new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, ['parent_id' => $sec, 'index' => 0, 'block' => $leafWithKids])], $registry);
    assert_eq([], $out2['sections'][0]['blocks'][0]['children'], 'a non-container never receives children');
});

unit('phase6 templates: extraction builds page / section / block presets from a document and refuses to preset a live reference', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $h = sbp6u_heading('Hello');
    $local = sbp6u_section([$h], null, 'Local');
    $ref = sbp6u_section([], SBP6U_REF, 'Shared');
    $doc = sbp6u_doc([$local, $ref]);

    $page = StudioTemplateService::extractTemplateDocument($doc, null, 'page_template');
    assert_eq(2, count($page['sections']));
    assert_true($page['sections'][0]['id'] !== $local['id'], 'owned content is copied');
    assert_eq(SBP6U_REF, $page['sections'][1]['global_ref'], 'a page template keeps live references as references');

    $sec = StudioTemplateService::extractTemplateDocument($doc, $local['id'], 'section_preset');
    assert_eq('section_preset', $sec['document_type']);
    assert_eq(1, count($sec['sections']));
    assert_eq('Hello', $sec['sections'][0]['blocks'][0]['props']['text']);
    assert_true(DocumentValidator::validate($sec, $registry)->isValid());

    $blk = StudioTemplateService::extractTemplateDocument($doc, $h['id'], 'block_preset');
    assert_eq(1, count($blk['sections'][0]['blocks']));
    assert_true($blk['sections'][0]['blocks'][0]['id'] !== $h['id']);

    assert_throws(StudioValidationException::class, static fn() => StudioTemplateService::extractTemplateDocument($doc, $ref['id'], 'section_preset'), 'a reference cannot become a copy preset');
    assert_throws(StudioValidationException::class, static fn() => StudioTemplateService::extractTemplateDocument($doc, $h['id'], 'section_preset'), 'kind mismatch');
    assert_throws(StudioValidationException::class, static fn() => StudioTemplateService::extractTemplateDocument($doc, null, 'block_preset'), 'a block preset needs a node');
    assert_throws(StudioValidationException::class, static fn() => StudioTemplateService::extractTemplateDocument($doc, null, 'evil_type'));
});

// ── 4. Renderer: live references ────────────────────────────────────────────

unit('phase6 renderer: a referencing section renders the component\'s published content embedded, without its own node metadata', function (): void {
    $componentDoc = sbp6u_doc([sbp6u_section([sbp6u_heading('Shared Header Text')], null, 'Comp')], 'section_preset');
    $pageDoc = sbp6u_doc([sbp6u_section([], SBP6U_REF, 'Placeholder'), sbp6u_section([sbp6u_heading('Local text')])]);
    $placeholderId = $pageDoc['sections'][0]['id'];
    $componentBlockId = $componentDoc['sections'][0]['blocks'][0]['id'];

    $editor = sbp6u_render_sections($pageDoc, sbp6u_editor_ctx(), [SBP6U_REF => $componentDoc]);
    assert_true(str_contains($editor, 'Shared Header Text'), 'component content is rendered into the page');
    assert_true(str_contains($editor, 'class="sb-section sb-section--global" data-sb-node="' . $placeholderId . '" data-sb-type="section"'), 'the placeholder section is selectable in the canvas');
    assert_true(!str_contains($editor, 'data-sb-node="' . $componentBlockId . '"'), 'embedded blocks expose no node metadata of their own');
    assert_true(!str_contains($editor, 'data-sb-node="' . $componentDoc['sections'][0]['id'] . '"'), 'nor do embedded sections');
    assert_true(str_contains($editor, 'Local text'), 'local sections render as before');

    $public = sbp6u_render_sections($pageDoc, sbp6u_public_ctx(), [SBP6U_REF => $componentDoc]);
    assert_true(str_contains($public, 'Shared Header Text') && !str_contains($public, 'data-sb-node'), 'public output embeds the content with no editor metadata');

    // Missing / unpublished component: inert fallback (notice when authoring, nothing in public).
    $missingEditor = sbp6u_render_sections($pageDoc, sbp6u_editor_ctx(), []);
    assert_true(str_contains($missingEditor, 'component_unavailable') && str_contains($missingEditor, 'data-sb-node="' . $placeholderId . '"'));
    $missingPublic = sbp6u_render_sections($pageDoc, sbp6u_public_ctx(), []);
    assert_true(!str_contains($missingPublic, 'unavailable') && !str_contains($missingPublic, 'Shared Header Text'), 'public output leaks nothing about a missing component');

    // A component's own nested references are never followed (one level).
    $nestedComponent = sbp6u_doc([['blocks' => [], 'global_ref' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'n', 'layout' => CanonicalDocumentSchema::defaultSectionLayout(), 'visibility' => CanonicalDocumentSchema::defaultVisibility()]], 'page');
    $registry = ModuleBlockDefinitions::studioRegistry();
    $preparedNested = RenderDocumentPreparer::prepare($nestedComponent, $registry)['document']['sections'];
    $html = sbp6u_renderer()->renderSections(RenderDocumentPreparer::prepare($pageDoc, $registry)['document']['sections'], sbp6u_editor_ctx(), new ResolvedTheme('default', ThemeResolver::DEFAULT_TOKENS), new RenderCollector(), true, [SBP6U_REF => $preparedNested]);
    assert_true(str_contains($html, 'sb-section--global') && !str_contains($html, 'bbbbbbbb-bbbb'), 'nested references render nothing and never recurse');
});

// ── 5. Design tokens ────────────────────────────────────────────────────────

unit('phase6 tokens: only supported refs and sanitized values are accepted; layers keep defaults → branding → studio order; the platform signature ignores tokens', function (): void {
    $editable = StudioThemeService::editableTokens();
    assert_eq(array_keys(ThemeResolver::DEFAULT_TOKENS), $editable, 'the editable set is exactly the platform-defined token set');

    assert_eq('#112233', ThemeResolver::sanitizeValue('surface.page', '#112233'));
    assert_eq('1.25rem', ThemeResolver::sanitizeValue('space.md', '1.25rem'));
    assert_eq('Inter, sans-serif', ThemeResolver::sanitizeValue('font.body', 'Inter, sans-serif'));
    foreach (['red; background:url(x)', '#fff}</style><script>', 'url(https://evil)', 'expression(1)', 'var(--x)', "#fff\n;color:red", '#ffffff /* x */'] as $bad) {
        assert_null(ThemeResolver::sanitizeValue('surface.page', $bad), "'{$bad}' must be rejected");
    }
    assert_null(ThemeResolver::sanitizeValue('platform.signature', '#000'), 'no token can address anything outside the token vocabulary');
    assert_null(ThemeResolver::sanitizeValue('font.body', '<script>'), 'font families cannot carry markup');

    $resolver = new ThemeResolver(null, static fn(): array => ['accent' => '#ff5500', 'ink' => 'red;}</style>']);
    $layers = $resolver->layers('default');
    assert_eq('#ff5500', $layers['branding']['color.accent'], 'branding feeds tokens');
    assert_true(!isset($layers['branding']['text.primary']), 'a hostile branding value never becomes a layer value');
    assert_eq([], $layers['stored'], 'no token store → no overrides');
    assert_eq(ThemeResolver::DEFAULT_TOKENS, $layers['defaults']);
    $resolved = $resolver->resolve('default');
    assert_eq('#ff5500', $resolved->value('color.accent'), 'branding beats the neutral default');
    assert_eq(ThemeResolver::DEFAULT_TOKENS['text.primary'], $resolved->value('text.primary'));

    // Platform identity: the signature slot's CSS does not depend on any tenant-controllable token.
    $css = StudioStylesheet::css();
    preg_match_all('/\.sb-platform-signature[^{]*\{[^}]*\}/', $css, $m);
    assert_true(count($m[0]) >= 1, 'the signature slot has explicit styling');
    foreach ($m[0] as $rule) {
        assert_true(!str_contains($rule, 'var(--sb-'), 'the platform signature must not be styled by tenant tokens: ' . $rule);
    }
    assert_true(!str_contains((new ResolvedTheme('default', ['surface.page' => '#000']))->rootCss(), 'sb-platform'), 'tokens only emit --sb-* custom properties');
});

// ── 6. API transport rules for the new commands ─────────────────────────────

unit('phase6 api: the new commands are POST-only, allowlisted, CSRF-guarded, and every document write requires expected_revision_id', function (): void {
    $api = new StudioAuthoringApi(StudioRuntimeFactory::build()->app);
    $editor = StudioActor::authenticated(7, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
    $post = static fn(string $action, array $body, bool $csrf = true): StudioApiRequest => new StudioApiRequest('POST', $action, [], (string) json_encode($body), 'application/json', $csrf, 'same-origin');
    $first = static fn($r): array => $r->payload['error']['details']['errors'][0];

    foreach (['apply_template', 'insert_template', 'save_template', 'delete_template', 'create_component', 'detach_component', 'save_tokens'] as $cmd) {
        assert_eq(405, $api->handle(new StudioApiRequest('GET', $cmd), $editor)->status, "{$cmd} is POST-only");
        assert_eq(403, $api->handle($post($cmd, ['template_key' => 'x'], false), $editor)->status, "{$cmd} needs CSRF");
    }
    foreach (['components', 'chrome', 'tokens'] as $q) {
        assert_eq(405, $api->handle($post($q, []), $editor)->status, "{$q} is a GET query");
    }

    $r = $api->handle($post('apply_template', ['page_id' => 1, 'template_key' => 'promo']), $editor);
    assert_eq(422, $r->status);
    assert_eq('required_field', $first($r)['code']);
    assert_eq('$.expected_revision_id', $first($r)['path'], 'applying a template without the expected revision is refused before anything runs');

    $r = $api->handle($post('insert_template', ['page_id' => 1, 'template_key' => 'promo', 'index' => 0]), $editor);
    assert_eq('required_field', $first($r)['code'], 'inserting a preset requires expected_revision_id too');
    $r = $api->handle($post('detach_component', ['page_id' => 1, 'section_id' => 'sec_0123456789abcdef01234567']), $editor);
    assert_eq('required_field', $first($r)['code']);
    $r = $api->handle($post('create_component', ['title' => 'T', 'slug' => 't', 'page_id' => 1, 'section_id' => 'sec_0123456789abcdef01234567']), $editor);
    assert_eq('required_field', $first($r)['code'], 'creating a component FROM a section is a document write');

    $r = $api->handle($post('insert_template', ['page_id' => 1, 'template_key' => 'promo', 'index' => -1, 'expected_revision_id' => 1]), $editor);
    assert_eq('invalid_index', $first($r)['code']);
    $r = $api->handle($post('insert_template', ['page_id' => 1, 'template_key' => 'promo', 'index' => 0, 'parent_id' => '../etc', 'expected_revision_id' => 1]), $editor);
    assert_eq('invalid_field', $first($r)['code'], 'parent ids are shape-checked');
    $r = $api->handle($post('apply_template', ['page_id' => 1, 'template_key' => 'Promo; DROP', 'expected_revision_id' => 1]), $editor);
    assert_eq('invalid_template_key', $first($r)['code']);
    $r = $api->handle($post('apply_template', ['page_id' => 1, 'template_key' => 'promo', 'expected_revision_id' => 1, 'tenant_id' => 5]), $editor);
    assert_eq('unknown_field', $first($r)['code'], 'a tenant_id is never accepted');
    $r = $api->handle($post('save_template', ['page_id' => 1, 'template_key' => 'k', 'template_type' => 'page_template', 'name' => 'N', 'document' => []]), $editor);
    assert_eq('unknown_field', $first($r)['code'], 'templates are saved from the stored page document, never from a client document');

    $r = $api->handle($post('save_tokens', ['group' => 'default', 'tokens' => ['#fff']]), $editor);
    assert_eq('invalid_tokens', $first($r)['code']);
    $r = $api->handle($post('save_tokens', ['group' => 'default', 'tokens' => ['surface.page' => str_repeat('a', 201)]]), $editor);
    assert_eq('invalid_token_value', $first($r)['code']);
    $r = $api->handle($post('save_tokens', ['group' => 'bad group!', 'tokens' => []]), $editor);
    assert_eq('invalid_token_group', $first($r)['code']);
    $r = $api->handle($post('save_tokens', ['group' => 'default', 'tokens' => ['<style>' => '#fff']]), $editor);
    assert_eq('invalid_tokens', $first($r)['code'], 'token refs are symbolic names');
});

unit('phase6 manifest: the UI fixture carries the Phase 6 vocabulary (permissions.tokens, template types, component types)', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/manifest.json'), true);
    assert_eq(['admin', 'edit', 'publish', 'tokens', 'view'], array_keys($fixture['permissions']));
    assert_eq(StudioTemplateService::INSERTABLE_TYPES, $fixture['templates']['insertable_types']);
    assert_eq(CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, $fixture['components']['document_type']);
    assert_eq(CanonicalDocumentSchema::GLOBAL_REF_DOCUMENT_TYPES, $fixture['components']['referencing_types']);
});

unit('phase6 transactions: no audit write or audited command runs inside an application-owned transaction (the platform audit log resolves the session user, whose lazy schema check implicitly commits)', function (): void {
    $src = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Application/StudioApplicationService.php');
    $offset = 0;
    $blocks = 0;
    while (($start = strpos($src, '$pdo->beginTransaction();', $offset)) !== false) {
        $end = strpos($src, '$pdo->commit();', $start);
        assert_true($end !== false, 'every owned transaction commits');
        $body = substr($src, $start, $end - $start);
        foreach (['AuditLog::record(', '$this->applyDocumentOperation(', '$this->saveDraft(', '$this->createPage(', '$this->publish(', '$this->rollback('] as $forbidden) {
            assert_true(!str_contains($body, $forbidden), "'{$forbidden}' must not run inside an owned transaction (verified web-path hazard: implicit commit → \"There is no active transaction\")");
        }
        $blocks++;
        $offset = $end;
    }
    assert_true($blocks >= 2, 'publish() and createGlobalComponent() own transactions');
});

if (!empty($studioP6UnitStandalone)) {
    exit(unit_summary());
}
