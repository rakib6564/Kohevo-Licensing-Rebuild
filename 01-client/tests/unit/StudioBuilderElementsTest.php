<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — B2-P2b elements: core.icon, core.list, core.quote, core.link.
 *
 * Dependency-free (no database). Drives the real pipeline (registry, normalizer, validator, compiler, renderers).
 * Verifies: registration and manifest metadata, starter content, exact markup, escaping of every author-supplied
 * string, unsafe URLs, unknown icon names and enum values, empty blocks rendering nothing, and determinism.
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
}

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Icon\IconLibrary;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

const SBEL_TENANT = 104;
const SBEL_TYPES  = ['core.icon', 'core.list', 'core.quote', 'core.link'];

final class _SbelNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return null;
    }
}

/** @return array<string, mixed> */
function sbel_block(string $type, array $props = []): array
{
    return [
        'bindings' => [], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(), 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => $type, 'version' => 1,
        'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ];
}

/** @return array<string, mixed> */
function sbel_doc(array $blocks): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Elements');
    $doc['sections'] = [[
        'blocks' => $blocks, 'global_ref' => null, 'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'Main',
        'layout' => CanonicalDocumentSchema::defaultSectionLayout(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
    ]];
    return $doc;
}

/** Compile `$blocks` as a published page and return the body HTML. */
function sbel_render(array $blocks): string
{
    $tenants   = new TenantContext();
    $registry  = ModuleBlockDefinitions::studioRegistry();
    $renderers = BlockRendererRegistry::withStudioRenderers();
    $media     = new _SbelNoMedia();
    $documents = new DocumentRenderer($registry, $renderers, $media, new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    $compiler  = new StudioCompiler($tenants, $registry, $renderers, $documents, new ThemeResolver(null, static fn(): array => ['accent' => '#ff5500']), new ChromeResolver(), $media);
    $site      = new SiteContext('https://acme.test', 'Acme', 'https://acme.test/l.png', 'https://acme.test/f.png', 'en');
    $ctx       = RenderContext::forPublic(SBEL_TENANT, $site, static fn(): bool => true);
    $page      = new PageAddress(6, '11111111-2222-3333-4444-555555555557', 'Elements', 'elements', 'page', 'published', 'standalone', 9, 9);
    $doc       = sbel_doc($blocks);
    $compiled  = $tenants->runAs(SBEL_TENANT, static fn() => $compiler->compile($page, ['id' => 9, 'page_id' => 6, 'document_json' => CanonicalJson::encode($doc)], $ctx));
    return (string) $compiled->html;
}

unit('elements: the four types are registered with a title, description, category and icon', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $byType = [];
    foreach ($registry->editorManifests() as $m) {
        $byType[$m['type']] = $m;
    }
    foreach (SBEL_TYPES as $type) {
        assert_true($registry->has($type), "{$type} registered");
        assert_true(isset($byType[$type]), "{$type} in the manifest");
        foreach (['title', 'description', 'category', 'icon'] as $key) {
            assert_true(is_string($byType[$type][$key] ?? null) && $byType[$type][$key] !== '', "{$type} has {$key}");
        }
        assert_true($byType[$type]['allows_children'] === false, "{$type} is a leaf");
    }
});

unit('elements: starter content normalises in, so a fresh list and quote are visible', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $doc = DocumentNormalizer::normalize(sbel_doc([sbel_block('core.list'), sbel_block('core.quote'), sbel_block('core.icon')]), $registry);
    $byType = [];
    foreach ($doc['sections'][0]['blocks'] as $b) {
        $byType[$b['type']] = $b['props'];
    }
    assert_true(count($byType['core.list']['items']) === 3, 'list gets three starter items');
    assert_eq('bullet', $byType['core.list']['style']);
    assert_true($byType['core.quote']['text'] !== '', 'quote gets starter text');
    assert_eq('star', $byType['core.icon']['name']);
    assert_eq('md', $byType['core.icon']['size']);
});

unit('elements: validation rejects unknown icons, bad enums, oversize text and unknown props', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    assert_false($registry->get('core.icon')->validateProps(['name' => 'not-an-icon'])->isValid(), 'unknown icon name');
    assert_false($registry->get('core.icon')->validateProps(['size' => 'huge'])->isValid(), 'unknown size');
    assert_false($registry->get('core.icon')->validateProps(['name' => 'star', 'svg' => '<svg/>'])->isValid(), 'no raw svg prop');
    assert_false($registry->get('core.list')->validateProps(['style' => 'roman'])->isValid(), 'unknown list style');
    assert_false($registry->get('core.list')->validateProps(['items' => [['text' => str_repeat('a', 301)]]])->isValid(), 'list item too long');
    assert_false($registry->get('core.list')->validateProps(['items' => array_fill(0, 31, ['text' => 'x'])])->isValid(), 'too many list items');
    assert_false($registry->get('core.quote')->validateProps(['text' => str_repeat('q', 1001)])->isValid(), 'quote too long');
    assert_false($registry->get('core.link')->validateProps([])->isValid(), 'a link needs its link');
    assert_true($registry->get('core.link')->validateProps(['link' => ['href' => 'https://a.test', 'label' => 'Go', 'target' => '_self']])->isValid(), 'a valid link passes');
    foreach (IconLibrary::names() as $name) {
        assert_true($registry->get('core.icon')->validateProps(['name' => $name])->isValid(), "icon {$name} validates");
    }
});

unit('elements: core.icon renders only constant markup, with a11y rules', function (): void {
    $html = sbel_render([sbel_block('core.icon', ['name' => 'heart', 'size' => 'lg', 'align' => 'center'])]);
    assert_true(str_contains($html, 'class="sb-icon sb-icon--lg sb-icon--center"'), 'size and alignment classes');
    assert_true(str_contains($html, IconLibrary::markup('heart')), 'the heart markup');
    assert_true(str_contains($html, 'aria-hidden="true"'), 'decorative by default');
    assert_false(str_contains($html, 'role="img"'), 'no role when decorative');

    $labelled = sbel_render([sbel_block('core.icon', ['name' => 'phone', 'label' => 'Call "us" <b>'])]);
    assert_true(str_contains($labelled, 'role="img" aria-label="Call &quot;us&quot; &lt;b&gt;"'), 'labelled icons expose an escaped name');
    assert_false(str_contains($labelled, 'aria-hidden'), 'a labelled icon is not hidden');
});

unit('elements: core.icon with a name that is not in the library renders nothing', function (): void {
    $html = sbel_render([sbel_block('core.icon', ['name' => '"><script>alert(1)</script>'])]);
    assert_false(str_contains($html, '<script>alert'), 'never echoed');
    assert_false(str_contains($html, 'sb-icon__svg'), 'no svg for an unknown name');
});

unit('elements: core.list renders ul, ol, the check and plain styles, and escapes every item', function (): void {
    $items = [['text' => 'One <b>&</b>'], ['text' => 'Two lines']];
    $ul = sbel_render([sbel_block('core.list', ['style' => 'bullet', 'items' => $items])]);
    assert_true(str_contains($ul, '<ul class="sb-list sb-list--bullet">'), 'bullet is a ul');
    assert_true(str_contains($ul, '<li class="sb-list__item">One &lt;b&gt;&amp;&lt;/b&gt;</li>'), 'item escaped');
    assert_eq(2, substr_count($ul, 'sb-list__item'), 'one li per item');

    $ol = sbel_render([sbel_block('core.list', ['style' => 'number', 'items' => $items])]);
    assert_true(str_contains($ol, '<ol class="sb-list sb-list--number">'), 'number is an ol');
    assert_true(str_contains(sbel_render([sbel_block('core.list', ['style' => 'check', 'items' => $items])]), 'sb-list--check'), 'check style class');
    assert_false(str_contains(sbel_render([sbel_block('core.list', ['items' => [['text' => '']]])]), 'sb-list__item'), 'a blank item is never emitted');
});

unit('elements: core.quote escapes text, author and role, and omits an empty caption', function (): void {
    $html = sbel_render([sbel_block('core.quote', ['text' => 'It <i>works</i>', 'author' => 'A & B', 'role' => '<CEO>', 'align' => 'center'])]);
    assert_true(str_contains($html, '<figure class="sb-quote sb-quote--center">'), 'figure with alignment');
    assert_true(str_contains($html, '<blockquote class="sb-quote__text"><p>It &lt;i&gt;works&lt;/i&gt;</p></blockquote>'), 'quote text escaped');
    assert_true(str_contains($html, '<span class="sb-quote__author">A &amp; B</span>'), 'author escaped');
    assert_true(str_contains($html, '<span class="sb-quote__role">&lt;CEO&gt;</span>'), 'role escaped');

    $bare = sbel_render([sbel_block('core.quote', ['text' => 'Just words'])]);
    assert_false(str_contains($bare, 'figcaption'), 'no caption without author or role');
});

unit('elements: core.link renders a safe anchor and refuses unsafe schemes', function (): void {
    $html = sbel_render([sbel_block('core.link', ['link' => ['href' => 'https://acme.test/a?b=1&c=2', 'label' => 'Read <more>', 'target' => '_blank'], 'style' => 'arrow', 'align' => 'right'])]);
    assert_true(str_contains($html, '<p class="sb-link-row sb-link-row--right">'), 'row alignment');
    assert_true(str_contains($html, 'class="sb-link sb-link--arrow"'), 'style class');
    assert_true(str_contains($html, 'href="https://acme.test/a?b=1&amp;c=2"'), 'href escaped');
    assert_true(str_contains($html, 'target="_blank"') && str_contains($html, 'rel="noopener noreferrer"'), 'new-tab links get rel');
    assert_true(str_contains($html, '>Read &lt;more&gt;</a>'), 'label escaped');

    $js = sbel_render([sbel_block('core.link', ['link' => ['href' => 'javascript:alert(1)', 'label' => 'Click', 'target' => '_self']])]);
    assert_false(str_contains($js, 'javascript:'), 'a javascript: href never reaches the page');
    assert_false(str_contains($js, '<a class="sb-link'), 'and it is not an anchor');
});

unit('elements: output is deterministic and the document validates', function (): void {
    $blocks = [
        sbel_block('core.icon', ['name' => 'check']),
        sbel_block('core.list', ['items' => [['text' => 'A'], ['text' => 'B']]]),
        sbel_block('core.quote', ['text' => 'Q', 'author' => 'Me']),
        sbel_block('core.link', ['link' => ['href' => 'https://acme.test', 'label' => 'Home', 'target' => '_self']]),
    ];
    assert_eq(sbel_render($blocks), sbel_render($blocks), 'same input, same bytes');
    $doc = DocumentNormalizer::normalize(sbel_doc($blocks), ModuleBlockDefinitions::studioRegistry());
    assert_true(DocumentValidator::validate($doc, ModuleBlockDefinitions::studioRegistry())->isValid(), 'a document of the four elements validates');
});
