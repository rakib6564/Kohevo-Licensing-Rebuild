<?php
/**
 * Slate — PageRenderer: the one core Renderer (Phase 3A B3).
 *
 * Implements the Slate\Presentation\Renderer contract — the single traversal
 * Page → Section → Block that ends the six divergent renderers (docs/05-Rendering/
 * rendering-pipeline.md, ADR-0007). It is STORAGE-AGNOSTIC: it consumes the Page/
 * Section value objects (produced by DocumentSchema) and never reads a table.
 *
 * Scope in 3A: content assembly only. renderPage returns the rendered CONTENT
 * region (concatenated Sections) — the Template frame, Theme skin, head assembly,
 * and asset emission are later phases (3B+). Blocks render their own markup; the
 * Component layer they will compose is 3B. RenderContext carries a (currently
 * null) theme slot so 3B plugs in without changing these signatures.
 *
 * Byte-parity design: a Section whose LayoutSpec is default renders TRANSPARENTLY
 * — just its blocks concatenated, no wrapper. Every legacy page normalizes to a
 * single default-layout implicit Section, so this renderer's output for existing
 * content is byte-identical to content-builder's Renderer::render() (the B5 parity
 * gate). A wrapper appears only for a Section that actually specifies layout.
 *
 * A block's own `style` (background/spacing/alignment/responsive overrides —
 * DocumentValidator's STYLE_KEYS) is applied via BlockStyleRenderer, not the
 * legacy content-builder \Renderer::applyStyle() this class used to defer to —
 * that class exists only in archive/plugins/content-builder/, so the check was
 * always false and every style customization silently never rendered outside
 * the editor's own client-side canvas preview. See BlockStyleRenderer's own
 * docblock.
 *
 * @internal — implementation detail below the Renderer contract
 * (platform-foundation §8). Depend on Slate\Presentation\Renderer.
 */

declare(strict_types=1);

namespace Slate\Presentation\Rendering;

use Slate\Presentation\BlockRegistry;
use Slate\Presentation\Page;
use Slate\Presentation\Renderer;
use Slate\Presentation\RenderContext;
use Slate\Presentation\Section;

final class PageRenderer implements Renderer
{
    private readonly BlockStyleRenderer $styles;

    public function __construct(
        private readonly BlockRegistry $registry,
    ) {
        $this->styles = new BlockStyleRenderer();
    }

    public function renderPage(Page $page, RenderContext $ctx): string
    {
        $out = '';
        foreach ($page->sections as $section) {
            if ($section instanceof Section) {
                $out .= $this->renderSection($section, $ctx);
            }
        }
        return $out;
    }

    public function renderSection(Section $section, RenderContext $ctx): string
    {
        $inner = '';
        foreach ($section->blocks as $block) {
            $inner .= $this->renderBlock($block, $ctx);
        }

        // Default layout arranges nothing → transparent (preserves legacy parity).
        if ($section->layout->isDefault()) {
            return $inner;
        }

        $layout = $section->layout;
        $attrs  = ' data-cols="' . $layout->cols . '"'
                . ' data-pad="' . self::attr($layout->pad) . '"'
                . ' data-width="' . self::attr($layout->width) . '"';
        if ($layout->bg !== '') {
            $attrs .= ' data-bg="' . self::attr($layout->bg) . '"';
        }

        return '<section class="slate-section"' . $attrs . '>'
             . '<div class="slate-section__inner">' . $inner . '</div>'
             . '</section>';
    }

    public function renderBlock(array $block, RenderContext $ctx): string
    {
        $type = (string) ($block['type'] ?? '');
        if ($type === '') {
            return '';
        }

        $def = $this->registry->get($type);
        if ($def === null) {
            return '<!-- unknown block "' . self::comment($type) . '" -->';
        }

        $props = (array) ($block['props'] ?? []);
        $props = $def->schema()->normalize($props);

        try {
            $html = $def->render($props, $ctx);
        } catch (\Throwable $e) {
            // Fail soft — one broken block must not blank the whole page
            // (matches content-builder's Renderer behavior).
            return '<!-- block "' . self::comment($type) . '" failed to render -->';
        }

        return $this->styles->apply($html, (array) ($block['style'] ?? []));
    }

    /** Escape a value destined for a double-quoted HTML attribute. */
    private static function attr(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    /** Neutralize a value interpolated into an HTML comment (no "--" / ">"). */
    private static function comment(string $v): string
    {
        return str_replace(['--', '>'], ['- -', ''], $v);
    }
}
