<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the media library's alt text and "Used on" list (media library Phase 1).
 *
 * Autoloader only, no database. An image whose block leaves the alt text empty takes the library's alt text; a block's
 * own alt text always wins. The details panel's Studio rows link each page to its editor.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Dependency\StudioMediaUsage;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

final class _SbmlaMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return match ($mediaId) {
            7 => new ResolvedMedia(7, 'https://acme.test/uploads/media/2026/11/team.jpg', 800, 600, 'The team at the gym'),
            8 => new ResolvedMedia(8, 'https://acme.test/uploads/media/2026/11/plain.jpg', 800, 600),
            default => null,
        };
    }
}

function sbmla_render(string $type, array $props): string
{
    $block = [
        'id' => 'blk_dddddddddddddddd', 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [],
    ];
    $renderer = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new _SbmlaMedia(), new ProviderBindingResolver(new DataProviderRegistry(), new TenantContext()));
    return $renderer->renderBlock($block, RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), new RenderCollector(), false);
}

unit('media alt: an image block with no alt text of its own takes the library\'s', function (): void {
    $html = sbmla_render('core.image', ['media' => ['media_id' => 7, 'alt' => '', 'focal_point' => [0.5, 0.5]]]);
    assert_true(str_contains($html, 'alt="The team at the gym"'), $html);
});

unit('media alt: the block\'s own alt text wins over the library\'s', function (): void {
    $html = sbmla_render('core.image', ['media' => ['media_id' => 7, 'alt' => 'Coach Sam', 'focal_point' => [0.5, 0.5]]]);
    assert_true(str_contains($html, 'alt="Coach Sam"'), $html);
    assert_false(str_contains($html, 'The team at the gym'), $html);
});

unit('media alt: blank on both sides stays empty, and a block alt of spaces counts as empty', function (): void {
    assert_true(str_contains(sbmla_render('core.image', ['media' => ['media_id' => 8, 'alt' => '', 'focal_point' => [0.5, 0.5]]]), 'alt=""'));
    assert_true(str_contains(sbmla_render('core.image', ['media' => ['media_id' => 7, 'alt' => '   ', 'focal_point' => [0.5, 0.5]]]), 'alt="The team at the gym"'));
});

unit('media alt: the hero\'s image falls back the same way', function (): void {
    $html = sbmla_render('core.hero', ['heading' => 'Hi', 'media' => ['media_id' => 7, 'alt' => '', 'focal_point' => [0.5, 0.5]]]);
    assert_true(str_contains($html, 'alt="The team at the gym"'), $html);
});

unit('media alt: a resolved image without library alt text has an empty one', function (): void {
    assert_eq('', (new ResolvedMedia(1, '/uploads/media/a.jpg'))->alt);
});

unit('media used on: each Studio page is one row with its title, type, status and an editor link', function (): void {
    $rows = StudioMediaUsage::detailRows([
        ['id' => 12, 'title' => 'Home', 'page_type' => 'page', 'status' => 'published'],
        ['id' => 0, 'title' => 'broken'],
        ['id' => 13, 'title' => '', 'page_type' => 'header_partial', 'status' => 'draft'],
    ]);
    assert_eq(2, count($rows));
    assert_eq('Studio', $rows[0]['source']);
    assert_eq('Home (page, published)', $rows[0]['label']);
    assert_eq('#13 (header_partial, draft)', $rows[1]['label'], 'an untitled page shows its id');
    assert_true(str_contains(StudioMediaUsage::DETAILS_SQL, "p.status <> 'archived'"), 'the same rule as the count');
});
