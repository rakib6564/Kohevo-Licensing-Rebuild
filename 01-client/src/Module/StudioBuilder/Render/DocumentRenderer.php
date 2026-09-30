<?php
/**
 * Kohevo Studio (studio-builder) — Section/block render walk.
 *
 * The ONE walk every runtime (Editor, Preview, Public) uses. For each block:
 *
 *   1. prepared-unavailable marker   -> non-executable fallback
 *   2. audience visibility (auth_state) -> skipped for this audience
 *   3. registered definition + renderer -> else non-executable fallback
 *   4. exact version compatibility    -> else non-executable fallback
 *   5. dynamic? (provider bindings / module entitlement / dynamic renderer)
 *        compile pass: deferred to a nonce marker, never baked
 *        request pass: entitlement checked live -> bindings resolved through
 *                      DataProviderRegistry -> rendered
 *   6. renderer output wrapped with symbolic style / visibility classes
 *
 * Fallbacks are empty in public output (no hint about module or licence
 * state) and a short labelled notice in authoring contexts.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Registry\BlockDefinitionInterface;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class DocumentRenderer
{
    private const STYLE_UTILITIES = [
        'font_token'    => 'font',
        'radius_token'  => 'rad',
        'shadow_token'  => 'shd',
        'spacing_token' => 'pad',
        'surface_token' => 'bg',
        'text_token'    => 'fg',
    ];

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly BlockRendererRegistry $renderers,
        private readonly MediaResolverInterface $media,
        private readonly ProviderBindingResolver $bindings,
    ) {}

    /**
     * @param list<array<string, mixed>> $sections prepared (normalized) sections
     */
    public function renderSections(array $sections, RenderContext $context, ResolvedTheme $theme, RenderCollector $collector, bool $deferDynamic): string
    {
        $html = '';
        foreach ($sections as $section) {
            if (is_array($section)) {
                $html .= $this->renderSection($section, $context, $theme, $collector, $deferDynamic);
            }
        }
        return $html;
    }

    /**
     * @param array<string, mixed> $block prepared (normalized) block
     */
    public function renderBlock(array $block, RenderContext $context, ResolvedTheme $theme, RenderCollector $collector, bool $deferDynamic): string
    {
        if (isset($block[RenderDocumentPreparer::UNAVAILABLE_KEY])) {
            return $this->unavailable((string) $block[RenderDocumentPreparer::UNAVAILABLE_KEY], $context);
        }

        $visibility = is_array($block['visibility'] ?? null) ? $block['visibility'] : [];
        if (!$context->includesAuthState((string) ($visibility['auth_state'] ?? 'any'))) {
            return '';
        }

        $type       = (string) ($block['type'] ?? '');
        $definition = $this->registry->get($type);
        $renderer   = $this->renderers->get($type);
        if ($definition === null || $renderer === null) {
            return $this->unavailable('unknown_block_type', $context);
        }
        if (($block['version'] ?? null) !== $definition->version()) {
            return $this->unavailable('unsupported_block_version', $context);
        }

        if ($deferDynamic && self::isDynamicNode($block, $definition, $renderer)) {
            return $collector->defer($block);
        }

        $entitlement = $definition->requiredEntitlement();
        if ($entitlement !== null && !$context->isEntitled($entitlement)) {
            return $this->unavailable('module_not_entitled', $context);
        }

        $children = '';
        if ($definition->allowsChildren()) {
            foreach ((array) ($block['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $children .= $this->renderBlock($child, $context, $theme, $collector, $deferDynamic);
                }
            }
        }

        $rows = (is_array($block['bindings'] ?? null) && $block['bindings'] !== [])
            ? $this->bindings->resolve($block, $definition, $context)
            : [];

        $inner = $renderer->render(new BlockRenderScope($block, $context, $this->media, $theme, $collector, $children, $rows));

        $classes = array_merge(
            ['sb-block', 'sb-block--' . str_replace(['.', '_'], '-', $type)],
            $this->styleClasses(is_array($block['style'] ?? null) ? $block['style'] : [], $theme, $collector),
            self::hideClasses($visibility),
        );

        return '<div' . Html::classAttr($classes) . $this->nodeMetadata($context, (string) ($block['id'] ?? ''), $type) . '>' . $inner . '</div>';
    }

    /**
     * Blocks whose output depends on request-time state and so must never be
     * baked into a shared compiled artifact.
     *
     * @param array<string, mixed> $block
     */
    public static function isDynamicNode(array $block, BlockDefinitionInterface $definition, BlockRendererInterface $renderer): bool
    {
        return $renderer->isDynamic()
            || $definition->requiredEntitlement() !== null
            || (is_array($block['bindings'] ?? null) && $block['bindings'] !== []);
    }

    /** Non-executable fallback: nothing in public output, a labelled notice when authoring. */
    public function unavailable(string $reason, RenderContext $context): string
    {
        if (!$context->showsDiagnostics()) {
            return '';
        }
        $reason = preg_match('/^[a-z_]{1,64}$/', $reason) === 1 ? $reason : 'unavailable';
        return '<div class="sb-unavailable" role="note">'
            . Html::e(Html::t('studio_block_unavailable', 'This block is unavailable')) . ' (' . Html::e($reason) . ')</div>';
    }

    /**
     * @param array<string, mixed> $section
     */
    private function renderSection(array $section, RenderContext $context, ResolvedTheme $theme, RenderCollector $collector, bool $deferDynamic): string
    {
        $visibility = is_array($section['visibility'] ?? null) ? $section['visibility'] : [];
        if (!$context->includesAuthState((string) ($visibility['auth_state'] ?? 'any'))) {
            return '';
        }

        $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
        $inner = '';
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $inner .= $this->renderBlock($block, $context, $theme, $collector, $deferDynamic);
            }
        }

        $layout = is_array($section['layout'] ?? null) ? $section['layout'] : CanonicalDocumentSchema::defaultSectionLayout();

        $outer = ['sb-section', $collector->tokenClass('bg', $layout['background_token'] ?? null, $theme)];
        foreach ((array) ($layout['padding_y'] ?? []) as $bp => $value) {
            $prefix = StudioStylesheet::prefix((string) $bp);
            if ($prefix !== null && in_array($value, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true)) {
                $outer[] = 'sb-' . $prefix . 'py-' . $value;
            }
        }
        $outer = array_merge($outer, self::hideClasses($visibility));

        $width = in_array($layout['width'] ?? null, CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS, true) ? $layout['width'] : 'wide';
        $gap   = in_array($layout['gap'] ?? null, CanonicalDocumentSchema::ALLOWED_SPACING_SCALE, true) ? $layout['gap'] : 'md';
        $innerClasses = ['sb-section__inner', 'sb-w-' . $width, 'sb-gap-' . $gap];

        // Schema 1.0 has no per-block column spans: blocks are equal-width grid
        // items, and a breakpoint's track count is capped at the block count so
        // a lone block is never squeezed into 1/12th of the row.
        $blockCount = max(1, count($blocks));
        foreach ((array) ($layout['columns'] ?? []) as $bp => $cols) {
            $prefix = StudioStylesheet::prefix((string) $bp);
            if ($prefix !== null && is_int($cols) && $cols >= 1 && $cols <= 12) {
                $innerClasses[] = 'sb-' . $prefix . 'cols-' . min($cols, $blockCount);
            }
        }

        return '<section' . Html::classAttr($outer) . $this->nodeMetadata($context, (string) ($section['id'] ?? ''), 'section') . '>'
            . '<div' . Html::classAttr($innerClasses) . '>' . $inner . '</div></section>';
    }

    /**
     * @param array<string, mixed> $style
     * @return list<string>
     */
    private function styleClasses(array $style, ResolvedTheme $theme, RenderCollector $collector): array
    {
        $classes = [];
        $align = $style['align'] ?? null;
        if (is_string($align)) {
            $align = ['base' => $align];
        }
        if (is_array($align)) {
            foreach ($align as $bp => $value) {
                $prefix = StudioStylesheet::prefix((string) $bp);
                if ($prefix !== null && in_array($value, CanonicalDocumentSchema::ALLOWED_ALIGNMENTS, true)) {
                    $classes[] = 'sb-' . $prefix . 'align-' . $value;
                }
            }
        }
        foreach (self::STYLE_UTILITIES as $key => $kind) {
            $classes[] = $collector->tokenClass($kind, $style[$key] ?? null, $theme);
        }
        return $classes;
    }

    /**
     * @param array<string, mixed> $visibility
     * @return list<string>
     */
    private static function hideClasses(array $visibility): array
    {
        $devices = $visibility['devices'] ?? CanonicalDocumentSchema::ALLOWED_BREAKPOINTS;
        if (!is_array($devices)) {
            return [];
        }
        $classes = [];
        foreach (CanonicalDocumentSchema::ALLOWED_BREAKPOINTS as $bp) {
            if (!in_array($bp, $devices, true)) {
                $classes[] = 'sb-hide-' . $bp;
            }
        }
        return $classes;
    }

    private function nodeMetadata(RenderContext $context, string $nodeId, string $type): string
    {
        if (!$context->emitsNodeMetadata() || $nodeId === '') {
            return '';
        }
        return ' data-sb-node="' . Html::e($nodeId) . '" data-sb-type="' . Html::e($type) . '"';
    }
}
