<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the Text Editor, Image and Icon options of their Elementor-style panels.
 *
 * Autoloader only, no database. A text block's drop cap and columns, an image's link (none, the file, or a custom
 * address), and an icon's stacked or framed view with its size, padding, border, rotation and link.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
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
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Tenancy\TenantContext;

final class _SbtiiMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return $mediaId === 7 ? new ResolvedMedia(7, 'https://acme.test/uploads/media/2026/01/view.jpg', 800, 600) : null;
    }
}

function sbtii_block(string $type, array $props, array $style = []): array
{
    return [
        'id' => 'blk_cccccccccccccccc', 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => $style + CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => [],
    ];
}

function sbtii_valid(array $block): bool
{
    $doc = ['schema_version' => '1.0', 'document_type' => 'page', 'template_key' => 'default', 'settings' => [], 'seo' => [], 'sections' => [[
        'id' => 'sec_cccccccccccccccc', 'label' => 'S', 'global_ref' => null, 'layout' => CanonicalDocumentSchema::defaultSectionLayout(),
        'visibility' => CanonicalDocumentSchema::defaultVisibility(), 'blocks' => [$block],
    ]]];
    return DocumentValidator::validate($doc, BlockRegistry::withAllCoreBlocks())->isValid();
}

function sbtii_render(array $block): string
{
    $tenants  = new TenantContext();
    $renderer = new DocumentRenderer(BlockRegistry::withAllCoreBlocks(), BlockRendererRegistry::withCoreRenderers(), new _SbtiiMedia(), new ProviderBindingResolver(new DataProviderRegistry(), $tenants));
    return $renderer->renderBlock($block, RenderContext::forPublic(101, new SiteContext('https://example.test', 'T')), (new ThemeResolver())->resolve('default'), new RenderCollector(), false);
}

// ── Text Editor and Text ──────────────────────────────────────────────────

unit('text editor: a drop cap is a class, and more than one column a style built only from clamped integers', function (): void {
    $html = sbtii_render(sbtii_block('core.rich_text', ['content' => '<p>Once</p>', 'drop_cap' => true, 'columns' => 3, 'columns_gap' => 32]));
    assert_true(str_contains($html, '<div class="sb-rich-text sb-dropcap" style="column-count:3;column-gap:32px"><p>Once</p></div>'), $html);
    $plain = sbtii_render(sbtii_block('core.rich_text', ['content' => '<p>Once</p>']));
    assert_true(str_contains($plain, '<div class="sb-rich-text"><p>Once</p></div>'), 'untouched, it renders as it did: ' . $plain);
    assert_false(sbtii_valid(sbtii_block('core.rich_text', ['content' => '<p>x</p>', 'columns' => 11])), 'at most 10 columns');
    assert_false(sbtii_valid(sbtii_block('core.rich_text', ['content' => '<p>x</p>', 'columns_gap' => '20px;color:red'])), 'a gap is a whole number');
});

unit('text: the same drop cap and columns on its paragraph', function (): void {
    $html = sbtii_render(sbtii_block('core.text', ['content' => 'Hello', 'drop_cap' => true, 'columns' => 2]));
    assert_true(str_contains($html, '<p class="sb-text sb-text--base sb-dropcap" style="column-count:2;column-gap:20px">Hello</p>'), $html);
});

// ── Image ─────────────────────────────────────────────────────────────────

unit('image: a link to the file or to a custom address wraps the picture, after the URL allowlist', function (): void {
    $media = ['media_id' => 7, 'alt' => 'View'];
    $file = sbtii_render(sbtii_block('core.image', ['media' => $media, 'link_to' => 'media', 'new_tab' => true]));
    assert_true(str_contains($file, '<a class="sb-image__link" href="https://acme.test/uploads/media/2026/01/view.jpg" target="_blank" rel="noopener noreferrer"><img src="https://acme.test/uploads/media/2026/01/view.jpg"'), $file);
    $custom = sbtii_render(sbtii_block('core.image', ['media' => $media, 'link_to' => 'custom', 'url' => '/menu']));
    assert_true(str_contains($custom, '<a class="sb-image__link" href="/menu"><img'), $custom);
    $none = sbtii_render(sbtii_block('core.image', ['media' => $media, 'link_to' => 'none', 'url' => '/menu']));
    assert_false(str_contains($none, '<a'), 'no link unless asked for: ' . $none);
    assert_false(sbtii_valid(sbtii_block('core.image', ['media' => $media, 'link_to' => 'custom', 'url' => 'javascript:alert(1)'])), 'an unsafe link is refused');
    assert_false(str_contains(sbtii_render(sbtii_block('core.image', ['media' => $media, 'link_to' => 'custom', 'url' => 'javascript:alert(1)'])), '<a'), 'and never rendered, even if stored');
});

// ── Icon ──────────────────────────────────────────────────────────────────

unit('icon: the default view renders as it did; stacked and framed put it in a shaped frame', function (): void {
    $plain = sbtii_render(sbtii_block('core.icon', ['name' => 'star']));
    assert_true(preg_match('~<div class="sb-icon sb-icon--md sb-icon--left"><svg class="sb-icon__svg" viewBox="0 0 24 24"[^>]*aria-hidden="true" focusable="false">~', $plain) === 1, $plain);
    assert_false(str_contains($plain, 'sb-icon__frame'), 'no frame by default');
    $stacked = sbtii_render(sbtii_block('core.icon', ['name' => 'star', 'view' => 'stacked', 'shape' => 'square', 'frame_padding' => 12, 'secondary_token' => 'text.primary']));
    assert_true(str_contains($stacked, '<div class="sb-icon sb-icon--md sb-icon--left sb-icon--stacked sb-icon--square"><span class="sb-icon__frame" style="padding:12px"><svg class="sb-icon__svg sb-fg-'), $stacked);
    $framed = sbtii_render(sbtii_block('core.icon', ['name' => 'star', 'view' => 'framed', 'frame_border' => 4]));
    assert_true(str_contains($framed, 'sb-icon--framed sb-icon--circle"><span class="sb-icon__frame" style="border-width:4px">'), $framed);
    assert_false(sbtii_valid(sbtii_block('core.icon', ['name' => 'star', 'view' => 'floating'])), 'only the three views');
});

unit('icon: a pixel size and a rotation are clamped integers, and a link goes through the URL allowlist', function (): void {
    $html = sbtii_render(sbtii_block('core.icon', ['name' => 'star', 'size_px' => 48, 'rotate' => -45, 'url' => '/contact', 'new_tab' => true]));
    assert_true(str_contains($html, '<a class="sb-icon__link" href="/contact" target="_blank" rel="noopener noreferrer"><svg class="sb-icon__svg"'), $html);
    assert_true(str_contains($html, 'style="width:48px;height:48px;transform:rotate(-45deg)"'), $html);
    assert_false(sbtii_valid(sbtii_block('core.icon', ['name' => 'star', 'size_px' => 999])), 'size within 0-300');
    assert_false(sbtii_valid(sbtii_block('core.icon', ['name' => 'star', 'rotate' => 12.5])), 'a whole number of degrees');
    assert_false(str_contains(sbtii_render(sbtii_block('core.icon', ['name' => 'star', 'url' => 'javascript:alert(1)'])), '<a'), 'an unsafe link never renders');
});

unit('icon: its colour sits on the block (zero specificity), so the Style colour and its hover state reach the icon', function (): void {
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, ':where(.sb-block--core-icon){color:var(--sb-color-accent)}'), 'the default accent is overridable');
    assert_false(str_contains($css, '.sb-icon{display:flex;color:'), 'the icon itself no longer pins a colour');
});
