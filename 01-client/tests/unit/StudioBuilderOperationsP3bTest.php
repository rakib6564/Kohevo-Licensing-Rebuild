<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P3b operations, section style and block tag.
 *
 * Autoloader only, no database.
 *
 * `update_block_style_states`, `update_block_tag`, `update_section_style` and
 * `reset_block_style_property` are mirrored in the editor (`core/operations.mjs`).
 * Both sides run the SAME cases from `ui/tests/fixtures/operations-p3b.json`, so
 * the optimistic copy can never disagree with what the server stores.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;

final class SbopMedia implements \Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface
{
    /** @param array<int, string> $urls media id => url */
    public function __construct(private array $urls) {}

    public function resolveImage(int $id): ?\Slate\Module\StudioBuilder\Render\Media\ResolvedMedia
    {
        return isset($this->urls[$id]) ? new \Slate\Module\StudioBuilder\Render\Media\ResolvedMedia($id, $this->urls[$id], 800, 600) : null;
    }
}

/** Key-sorted so key order never decides equality. */
function sbop_canon(mixed $v): string
{
    return (string) json_encode(CanonicalJson::sortKeysRecursively(is_array($v) ? $v : ['v' => $v]));
}

function sbop_set(array $tree, array $path, mixed $value): array
{
    $head = array_shift($path);
    $tree[$head] = $path === [] ? $value : sbop_set($tree[$head], $path, $value);
    return $tree;
}

function sbop_unset(array $tree, array $path): array
{
    $head = array_shift($path);
    if ($path === []) {
        unset($tree[$head]);
    } else {
        $tree[$head] = sbop_unset($tree[$head], $path);
    }
    return $tree;
}

/** @return array<string, mixed> */
function sbop_fixture(): array
{
    $f = json_decode((string) file_get_contents(__DIR__ . '/../../plugins/studio-builder/ui/tests/fixtures/operations-p3b.json'), true);
    assert_true(is_array($f) && count($f['cases']) >= 25, 'fixture loads');
    return $f;
}

unit('p3b ops: the PHP applier produces the same document as the editor for every shared case', function (): void {
    $f = sbop_fixture();
    $registry = BlockRegistry::withAllCoreBlocks();
    foreach ($f['cases'] as $c) {
        $operation = new DocumentOperation($c['op'], $c['payload']);
        if (!empty($c['error'])) {
            assert_throws(StudioValidationException::class, static fn () => DocumentOperationApplier::applyOne($f['document'], $operation, $registry), $c['name'] . ' should be refused');
            continue;
        }
        $expected = $f['document'];
        foreach ($c['set'] as $s) {
            $expected = sbop_set($expected, $s['path'], $s['value']);
        }
        foreach ($c['unset'] as $u) {
            $expected = sbop_unset($expected, $u);
        }
        assert_eq(sbop_canon($expected), sbop_canon(DocumentOperationApplier::applyOne($f['document'], $operation, $registry)), $c['name']);
    }
});

unit('p3b ops: they are in the operation vocabulary, so the assistant tools list them too', function (): void {
    foreach (['update_block_style_states', 'update_block_tag', 'update_section_style', 'reset_block_style_property', 'update_section_animation', 'update_section_interactions'] as $op) {
        assert_true(in_array($op, DocumentOperation::ALLOWED_OPS, true), "{$op} is an allowed operation");
    }
    assert_throws(StudioValidationException::class, static fn () => new DocumentOperation('update_block_tag', ['block_id' => 'blk_x']), 'a missing payload key is refused up front');
    assert_throws(StudioValidationException::class, static fn () => new DocumentOperation('reset_block_style_property', ['block_id' => 'blk_x']), 'reset needs a property');
});

unit('p3b ops: the layer lock refuses them on a locked node', function (): void {
    $f = sbop_fixture();
    $registry = BlockRegistry::withAllCoreBlocks();
    $locked = $f['document'];
    $locked['sections'][0]['blocks'][0]['metadata'] = ['locked' => true];
    $target = $f['document']['sections'][0]['blocks'][0]['id'];
    $tried = 0;
    foreach ($f['cases'] as $c) {
        if (($c['payload']['block_id'] ?? null) !== $target || !empty($c['error'])) {
            continue;
        }
        $tried++;
        try {
            DocumentOperationApplier::applyOne($locked, new DocumentOperation($c['op'], $c['payload']), $registry);
            assert_true(false, $c['name'] . ' must be refused on a locked block');
        } catch (StudioValidationException $e) {
            assert_eq('node_locked', $e->errors()[0]['code'] ?? null, $c['name']);
        }
    }
    assert_true($tried > 0, 'some cases target the locked block');

    $sec = $f['document'];
    $sec['sections'][1]['locked'] = true;
    assert_throws(StudioValidationException::class, static fn () => DocumentOperationApplier::applyOne($sec, new DocumentOperation('update_section_style', ['section_id' => $sec['sections'][1]['id'], 'style' => []]), $registry), 'a locked section keeps its style');
    DocumentOperationApplier::applyOne($sec, new DocumentOperation('update_section_style', ['section_id' => $sec['sections'][0]['id'], 'style' => []]), $registry);
});

// ── Validation of the new schema keys ────────────────────────────────────

/** A one-section document with the given section extras and block extras. */
function sbop_doc(array $sectionExtra = [], array $blockExtra = [], array $blockStyle = []): array
{
    return ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => [[
        'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'S', 'global_ref' => null, 'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'blocks' => [[
            'id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'x', 'level' => 'h2'],
            'style' => $blockStyle + CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => [],
        ] + $blockExtra],
    ] + $sectionExtra]];
}

unit('p3b schema: block tag is an allow-listed element name', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    foreach (CanonicalDocumentSchema::ALLOWED_BLOCK_TAGS as $tag) {
        assert_true(DocumentValidator::validate(sbop_doc([], ['tag' => $tag]), $registry)->isValid(), "tag {$tag} is valid");
    }
    foreach (['script', 'iframe', 'a', 'div onclick=x', 'DIV', '', 5, ['div'], null] as $bad) {
        assert_false(DocumentValidator::validate(sbop_doc([], ['tag' => $bad]), $registry)->isValid(), 'tag ' . json_encode($bad) . ' must be refused');
    }
});

unit('p3b schema: section style takes a background and padding, nothing else', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    foreach ([
        ['padding' => ['top' => '2rem', 'bottom' => '2rem']],
        ['background' => ['color' => '#0b0c0f']],
        ['background' => ['gradient' => 'linear-gradient(135deg, #e8734a, #8a3d24)']],
        ['background' => ['image' => ['media_id' => 7, 'alt' => ''], 'fit' => 'cover', 'overlay' => ['color' => 'rgba(0,0,0,.4)']], 'padding' => ['left' => '1rem']],
    ] as $style) {
        $r = DocumentValidator::validate(sbop_doc(['style' => $style]), $registry);
        assert_true($r->isValid(), json_encode($style) . ' → ' . json_encode($r->errors()));
    }
    foreach ([
        'unknown key'      => ['margin' => ['top' => '1rem']],
        'layout key'       => ['layout' => ['display' => 'flex']],
        'colour url'       => ['background' => ['color' => 'url(x)']],
        'colour token'     => ['background' => ['color' => 'surface.dark']],
        'gradient url'     => ['background' => ['gradient' => 'linear-gradient(url(x), red)']],
        'image string'     => ['background' => ['image' => 'https://evil.test/x.png']],
        'image + gradient' => ['background' => ['image' => ['media_id' => 7, 'alt' => ''], 'gradient' => 'linear-gradient(red, blue)']],
        'padding negative' => ['padding' => ['top' => '-1rem']],
        'padding unknown'  => ['padding' => ['middle' => '1rem']],
        'bg unknown'       => ['background' => ['video' => 'x.mp4']],
        'a list'           => [['padding']],
    ] as $label => $style) {
        assert_false(DocumentValidator::validate(sbop_doc(['style' => $style]), $registry)->isValid(), "must refuse: {$label}");
    }
    assert_false(DocumentValidator::validate(sbop_doc(['style' => 'padding:0']), $registry)->isValid(), 'a string is not a style');
});

unit('p3b schema: a section background image must belong to the tenant', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $doc = sbop_doc(['style' => ['background' => ['image' => ['media_id' => 99, 'alt' => '']]]]);
    $own = static fn (int $id): bool => $id === 7;
    assert_false(DocumentValidator::validate($doc, $registry, ['media_exists' => $own])->isValid(), 'media 99 is not this tenant\'s');
    $doc['sections'][0]['style']['background']['image']['media_id'] = 7;
    assert_true(DocumentValidator::validate($doc, $registry, ['media_exists' => $own])->isValid(), 'media 7 is');
});

unit('p3b schema: the normalizer keeps a section style and a tag only when they say something', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $out = DocumentNormalizer::normalize(sbop_doc(['style' => ['padding' => ['top' => '2rem'], 'background' => []]], ['tag' => 'section']), $registry);
    assert_eq(['padding' => ['top' => '2rem']], $out['sections'][0]['style'], 'the empty background is dropped');
    assert_eq('section', $out['sections'][0]['blocks'][0]['tag']);
    $plain = DocumentNormalizer::normalize(sbop_doc(['style' => []], ['tag' => 'div']), $registry);
    assert_false(array_key_exists('style', $plain['sections'][0]), 'an empty section style is not stored');
    assert_false(array_key_exists('tag', $plain['sections'][0]['blocks'][0]), 'the default tag is not stored');
});

unit('p3b schema: documents that use none of this are normalized exactly as before', function (): void {
    $registry = BlockRegistry::withAllCoreBlocks();
    $out = DocumentNormalizer::normalize(sbop_doc(), $registry);
    assert_false(array_key_exists('style', $out['sections'][0]));
    assert_false(array_key_exists('tag', $out['sections'][0]['blocks'][0]));
    assert_false(array_key_exists('style_states', $out['sections'][0]['blocks'][0]));
});

// ── The whole pipeline: the stored document → the public page ─────────────

unit('p3b render: tag, section style and states reach the page through the real compiler', function (): void {
    $tenants = new \Slate\Tenancy\TenantContext();
    $reg     = \Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions::studioRegistry();
    $rr      = \Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry::withStudioRenderers();
    $media   = new SbopMedia([7 => '/uploads/t101/hero.jpg']);
    $docr    = new \Slate\Module\StudioBuilder\Render\DocumentRenderer($reg, $rr, $media, new \Slate\Module\StudioBuilder\Render\ProviderBindingResolver(new \Slate\Module\StudioBuilder\Provider\DataProviderRegistry(), $tenants));
    $comp    = new \Slate\Module\StudioBuilder\Render\Compile\StudioCompiler($tenants, $reg, $rr, $docr, new \Slate\Module\StudioBuilder\Render\Theme\ThemeResolver(null, static fn () => []), new \Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver(), $media);
    $page    = new \Slate\Module\StudioBuilder\Domain\PageAddress(5, '11111111-2222-4333-8444-555555555555', 'T', 't', 'page', 'published', 'standalone', 9, 9);
    $ctx     = \Slate\Module\StudioBuilder\Render\RenderContext::forPublic(101, new \Slate\Module\StudioBuilder\Render\SiteContext('https://a.test', 'A', 'https://a.test/l.png', 'https://a.test/f.png', 'en'), static fn () => true);

    $doc = sbop_doc(
        ['style' => ['padding' => ['top' => '2rem'], 'background' => ['image' => ['media_id' => 7, 'alt' => ''], 'overlay' => ['color' => 'rgba(0,0,0,.4)']]]],
        ['tag' => 'article', 'style_states' => ['hover' => ['opacity' => 0.8]]],
        ['effects' => ['cursor' => 'pointer'], 'background' => ['image' => ['media_id' => 7, 'alt' => '']]],
    );
    $out = $tenants->runAs(101, static fn () => $comp->compile($page, ['id' => 9, 'page_id' => 5, 'document_json' => CanonicalJson::encode($doc)], $ctx));

    assert_true(preg_match('/<section class="sb-section (sb-x-[0-9a-f]{16})/', $out->html, $sm) === 1, 'scoped section class: ' . $out->html);
    assert_true(preg_match('/<article class="sb-block[^"]*(sb-x-[0-9a-f]{16})/', $out->html, $bm) === 1, 'scoped block class: ' . $out->html);
    $sClass = $sm[1];
    $bClass = $bm[1];
    assert_true(str_contains($out->html, '<article class="sb-block'), 'the block wrapper is an <article>: ' . $out->html);
    assert_true(str_contains($out->html, '</article>'), 'and closes as one');
    assert_true(str_contains($out->css, '.' . $sClass . '{background-image:linear-gradient(rgba(0,0,0,.4),rgba(0,0,0,.4)),url("/uploads/t101/hero.jpg");background-size:cover;background-repeat:no-repeat;background-position:center;padding-top:2rem}'), $out->css);
    assert_true(str_contains($out->css, '.' . $bClass . ':hover{opacity:0.8}'), 'the state rule survives normalization: ' . substr($out->css, -400));
    assert_true(str_contains($out->css, 'cursor:pointer'), 'the base rule survives normalization');
    assert_false(str_contains($out->html, 'hero.jpg'), 'the image URL is only in the stylesheet');
});

unit('p3b render: a stored block with a refused value is left out of the page entirely', function (): void {
    // The compiler validates before it renders, and a block that fails validation becomes "unavailable"
    // (empty on a public page). That is what happens to a value the typed guard now refuses, so it is
    // pinned here rather than assumed: it is also why bin/audit-style-values.php must run before a deploy.
    $tenants = new \Slate\Tenancy\TenantContext();
    $reg     = \Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions::studioRegistry();
    $rr      = \Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry::withStudioRenderers();
    $media   = new SbopMedia([]);
    $docr    = new \Slate\Module\StudioBuilder\Render\DocumentRenderer($reg, $rr, $media, new \Slate\Module\StudioBuilder\Render\ProviderBindingResolver(new \Slate\Module\StudioBuilder\Provider\DataProviderRegistry(), $tenants));
    $comp    = new \Slate\Module\StudioBuilder\Render\Compile\StudioCompiler($tenants, $reg, $rr, $docr, new \Slate\Module\StudioBuilder\Render\Theme\ThemeResolver(null, static fn () => []), new \Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver(), $media);
    $page    = new \Slate\Module\StudioBuilder\Domain\PageAddress(5, '11111111-2222-4333-8444-555555555555', 'T', 't', 'page', 'published', 'standalone', 9, 9);
    $ctx     = \Slate\Module\StudioBuilder\Render\RenderContext::forPublic(101, new \Slate\Module\StudioBuilder\Render\SiteContext('https://a.test', 'A', 'https://a.test/l.png', 'https://a.test/f.png', 'en'), static fn () => true);

    $doc = sbop_doc([], [], ['typography' => ['size' => 'url(https://evil.test/x)']]);
    $doc['sections'][0]['blocks'][] = ['id' => CanonicalDocumentSchema::newBlockId(), 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'KEEP ME', 'level' => 'h2'], 'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'bindings' => [], 'children' => []];
    $out = $tenants->runAs(101, static fn () => $comp->compile($page, ['id' => 9, 'page_id' => 5, 'document_json' => CanonicalJson::encode($doc)], $ctx));
    assert_true(str_contains($out->html, 'KEEP ME'), 'the clean sibling still renders');
    assert_false(str_contains($out->html, 'evil.test') || str_contains($out->css, 'evil.test'), 'the refused value is nowhere in the output');
    assert_false(str_contains($out->html, '>x<'), 'and the block that carried it is not rendered');
});
