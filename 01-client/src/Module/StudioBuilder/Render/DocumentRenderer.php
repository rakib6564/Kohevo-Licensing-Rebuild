<?php
/**
 * Kohevo Studio (studio-builder) — Block render walk.
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
 *
 * Phase 6 — live Global Component references: a top-level Container whose
 * `global_ref` is set owns no children; it renders the PUBLISHED content of the
 * referenced component (resolved by the compiler into `$components`, published
 * revision only) inside a placeholder. The embedded content is rendered by this
 * same walk (same validation, same renderers, same deferral of dynamic nodes)
 * but never emits editor node metadata of its own — in the builder canvas a
 * click inside a component selects the referencing Container, whose inspector
 * links to the component. A missing/unpublished component is an inert fallback
 * like any other unavailable node.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\ScrollEffects;
use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Document\StyleValueGuard;
use Slate\Module\StudioBuilder\Registry\BlockDefinitionInterface;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\WrapperClassesInterface;
use Slate\Module\StudioBuilder\Render\Block\WrapperStyleInterface;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class DocumentRenderer
{
    /** Render attribute: leave out a block whose renderer throws instead of failing the render (see StudioCompiler). */
    public const ISOLATE_FAILURES = 'isolate_block_failures';

    /** Marker key on a block rendered as part of an embedded Global Component (never a canonical document key). */
    public const EMBEDDED_KEY = '__sb_embedded';
    /** Set on a deferred block of the canvas's frame (RenderContext::framing()), so it is filled as framing too. */
    public const FRAMING_KEY = '__sb_framing';
    /** Marker on a Container at the top of a document (never a canonical document key). */
    private const TOP_KEY = '__sb_top';

    private const STYLE_UTILITIES = [
        'border_token'  => 'bd',
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
     * @param list<array<string, mixed>> $containers prepared (normalized) top-level Containers
     * @param array<string, list<array<string, mixed>>> $components ref => the referenced
     *        Global Component's PREPARED published Containers (resolved by the compiler);
     *        a ref absent from this map renders as unavailable
     */
    public function renderContainers(array $containers, RenderContext $context, ResolvedTheme $theme, RenderCollector $collector, bool $deferDynamic, array $components = []): string
    {
        $html = '';
        foreach ($containers as $container) {
            if (is_array($container)) {
                $html .= $this->renderTop($container, $context, $theme, $collector, $deferDynamic, $components);
            }
        }
        return SiteLinks::rebase($html, $context->site->basePath());
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
            // Filled later with the page's own context: a block of the canvas's frame stays framing (no node metadata).
            if ($context->mode === RenderMode::Editor && $context->attribute(RenderContext::FRAME_ONLY) === true) {
                $block[self::FRAMING_KEY] = true;
            }
            return $collector->defer($block);
        }
        if (!empty($block[self::FRAMING_KEY])) {
            $context = $context->framing();
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

        // When the compiler asks for it (a visitor's request rebuilding a page with no good stored copy), one block that
        // fails is left out instead of answering the visitor with a 500. Otherwise the failure propagates, so publishing
        // a page with a broken block fails and the author sees why.
        try {
            $rows = (is_array($block['bindings'] ?? null) && $block['bindings'] !== [])
                ? $this->bindings->resolve($block, $definition, $context)
                : [];

            $scope = new BlockRenderScope($block, $context, $this->media, $theme, $collector, $children, $rows);
            $inner = $renderer->render($scope);
            $wrapperClasses = $renderer instanceof WrapperClassesInterface ? $renderer->wrapperClasses($scope) : [];
            $wrapperStyle = $renderer instanceof WrapperStyleInterface ? $renderer->wrapperStyle($scope) : '';
        } catch (\Throwable $e) {
            if ($context->attribute(self::ISOLATE_FAILURES) !== true) {
                throw $e;
            }
            error_log('[studio-builder] block ' . preg_replace('/[^a-z0-9._-]/i', '', $type) . ' failed to render: ' . $e::class . ': ' . $e->getMessage());
            return $this->unavailable('render_failed', $context);
        }

        // Nothing to show leaves no box on a public page: a block that rendered nothing (a pay button with no payment
        // provider, an empty list) and a layout whose every child rendered nothing are left out, with their spacing and
        // backgrounds. The editor keeps them, so the author can still select them. A layout with no children at all is
        // a shape the author drew (a spacer, a coloured band) and always renders.
        if ($inner === '' && !$context->emitsNodeMetadata()
            && (!$definition->allowsChildren() || (is_array($block['children'] ?? null) && $block['children'] !== []))) {
            return '';
        }

        $customClasses = [];
        if (isset($block['classNames']) && is_array($block['classNames'])) {
            foreach ($block['classNames'] as $c) {
                if (is_string($c) && preg_match('/^[a-zA-Z0-9_-]+$/', $c) === 1) {
                    $customClasses[] = $c;
                }
            }
        }

        $responsiveClasses = [];
        if (isset($block['responsive']) && is_array($block['responsive'])) {
            foreach ($block['responsive'] as $device => $conf) {
                if (!in_array($device, CanonicalDocumentSchema::ALLOWED_RESPONSIVE_BREAKPOINTS, true) || !is_array($conf)) {
                    continue;
                }
                if (!empty($conf['hide'])) {
                    $responsiveClasses[] = 'sb-hide-' . $device;
                }
                if (!empty($conf['align']) && is_string($conf['align']) && in_array($conf['align'], CanonicalDocumentSchema::ALLOWED_ALIGNMENTS, true)) {
                    $responsiveClasses[] = 'sb-align-' . $device . '-' . $conf['align'];
                }
            }
        }

        ['animationClasses' => $animationClasses, 'interactionClasses' => $interactionClasses, 'interactionAttrs' => $interactionAttrs, 'motionVars' => $motionVars] = $this->motionOf($block);

        // B2-P3b surface: layout, position, size extras and effects become one
        // block-scoped rule in the page stylesheet. A block without any adds
        // nothing, so documents that never use them render exactly as before.
        $blockStyle   = is_array($block['style'] ?? null) ? $block['style'] : [];
        $stateRules   = is_array($block['style_states'] ?? null) ? StyleSurface::stateRules($block['style_states']) : [];
        $scopedClass  = $collector->scopedRule((string) ($block['id'] ?? ''), StyleSurface::declarations($blockStyle, $this->backgroundImageUrl($blockStyle), ScrollEffects::hasParallaxBackground($block)), StyleSurface::hasTransition($blockStyle), $stateRules, is_array($block['responsive'] ?? null) ? StyleSurface::responsiveRules($block['responsive']) : [], StyleSurface::partRules(is_array($block['style_parts'] ?? null) ? $block['style_parts'] : [], $type, is_array($block['responsive'] ?? null) ? $block['responsive'] : []));
        $scroll = ScrollEffects::render($block);
        $scopedClasses = $scopedClass !== '' ? [$scopedClass] : [];
        if (is_string($block['custom_css'] ?? null) && $block['custom_css'] !== '') {
            $elementClass = $collector->elementCss((string) ($block['id'] ?? ''), $block['custom_css']);
            if ($elementClass !== '') {
                $scopedClasses[] = $elementClass;
            }
        }

        $classes = array_merge(
            ['sb-block', 'sb-block--' . str_replace(['.', '_'], '-', $type)],
            !empty($block[self::TOP_KEY]) ? ['sb-top'] : [],
            $wrapperClasses,
            $scopedClasses,
            $this->styleClasses(is_array($block['style'] ?? null) ? $block['style'] : [], $theme, $collector),
            self::typographyMarkers($block),
            self::hideClasses($visibility),
            $customClasses,
            $responsiveClasses,
            $animationClasses,
            $interactionClasses,
            $scroll['classes'],
        );

        $attrs = $interactionAttrs . $scroll['attrs'];
        if (isset($block['attributes']) && is_array($block['attributes'])) {
            foreach ($block['attributes'] as $attrKey => $attrVal) {
                // Same allow-list the validator enforces; re-checked here so a
                // document stored before the rule existed can never emit a handler.
                if (CanonicalDocumentSchema::blockAttributeIssue($attrKey, $attrVal) === null
                    && ($attrKey !== 'id' || $collector->claimId((string) $attrVal))) {
                    $attrs .= ' ' . $attrKey . '="' . Html::e((string) $attrVal) . '"';
                }
            }
        }

        // One `style` attribute only: the motion variables ride along with the
        // block's own inline styles rather than opening a second one.
        $inlineStyles = $this->buildInlineStyles(is_array($block['style'] ?? null) ? $block['style'] : []);
        // Joined with a `;`: the inline styles end without one, and run together with the timing variables the last
        // inline declaration (often the z-index) became invalid and was dropped as soon as a block had animation timing.
        $combined     = implode(';', array_filter([trim($inlineStyles, " ;"), trim($motionVars, " ;"), implode(';', $scroll['vars']), $wrapperStyle], static fn(string $part): bool => $part !== ''));
        $styleAttr    = $combined !== '' ? ' style="' . Html::e($combined) . '"' : '';

        $metadata = empty($block[self::EMBEDDED_KEY]) ? $this->nodeMetadata($context, (string) ($block['id'] ?? ''), $type) : '';
        $tag = isset($block['tag']) && is_string($block['tag']) && in_array($block['tag'], CanonicalDocumentSchema::ALLOWED_BLOCK_TAGS, true) ? $block['tag'] : 'div';
        $href = '';
        if ($tag === 'a') {
            // A linked wrapper: its address passes the URL allowlist again here; without one it is a plain anchor.
            $link = is_array($block['link'] ?? null) ? $block['link'] : [];
            $url = Html::safeUrl($link['url'] ?? null);
            if ($url !== null) {
                $href = ' href="' . Html::e($url) . '"' . (($link['new_tab'] ?? false) === true ? ' target="_blank" rel="noopener noreferrer"' : '');
            }
        }
        return '<' . $tag . $href . Html::classAttr($classes) . $metadata . $attrs . $styleAttr . '>' . $inner . '</' . $tag . '>';
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
     * @param array<string, mixed> $container
     * @param array<string, list<array<string, mixed>>> $components
     */
    private function renderTop(array $container, RenderContext $context, ResolvedTheme $theme, RenderCollector $collector, bool $deferDynamic, array $components): string
    {
        $ref = $container['global_ref'] ?? null;
        if (is_string($ref) && $ref !== '') {
            return $this->renderGlobalContainer($container, $ref, $context, $theme, $collector, $deferDynamic, $components);
        }
        $container[self::TOP_KEY] = true;
        return $this->renderBlock($container, $context, $theme, $collector, $deferDynamic);
    }

    /**
     * The placeholder of a live Global Component reference. The consuming Container keeps only its visibility (where it
     * shows); layout and content come entirely from the component's own published Containers, rendered with embedded
     * blocks (no editor node metadata of their own).
     *
     * @param array<string, mixed> $container
     * @param array<string, list<array<string, mixed>>> $components
     */
    private function renderGlobalContainer(array $container, string $ref, RenderContext $context, ResolvedTheme $theme, RenderCollector $collector, bool $deferDynamic, array $components): string
    {
        $visibility = is_array($container['visibility'] ?? null) ? $container['visibility'] : [];
        if (!$context->includesAuthState((string) ($visibility['auth_state'] ?? 'any'))) {
            return '';
        }
        $classes = array_merge(['sb-block', 'sb-block--core-container', 'sb-top', 'sb-global'], self::hideClasses($visibility));
        $open = '<div' . Html::classAttr($classes) . $this->nodeMetadata($context, (string) ($container['id'] ?? ''), CanonicalDocumentSchema::CONTAINER_TYPE) . '>';

        if (!isset($components[$ref]) || !is_array($components[$ref])) {
            return $open . $this->unavailable('component_unavailable', $context) . '</div>';
        }

        $inner = '';
        foreach ($components[$ref] as $component) {
            if (!is_array($component) || !empty($component['global_ref'])) {
                continue; // references are one level deep — never resolved recursively
            }
            $embedded = self::markEmbedded([$component]);
            $inner .= $this->renderTop($embedded[0], $context, $theme, $collector, $deferDynamic, []);
        }
        return $open . $inner . '</div>';
    }

    /**
     * @param list<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    private static function markEmbedded(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $block[self::EMBEDDED_KEY] = true;
            if (is_array($block['children'] ?? null) && $block['children'] !== []) {
                $block['children'] = self::markEmbedded($block['children']);
            }
            $out[] = $block;
        }
        return $out;
    }

    /**
     * Marks the wrapper with one class for each typography property the author set, so the stylesheet can make the block's own
     * text elements inherit exactly those properties. Without it a theme rule on `h2`, `p` or `a` (a size, a colour, a family)
     * would beat the value on the wrapper and the Inspector control would appear to do nothing. A property nobody set gets no
     * class, so the theme's own look is left alone.
     *
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private static function typographyMarkers(array $block): array
    {
        $style = is_array($block['style'] ?? null) ? $block['style'] : [];
        $typo  = is_array($style['typography'] ?? null) ? $style['typography'] : [];
        $set   = static fn (mixed $v): bool => is_string($v) && $v !== '';
        $out   = [];

        $size = isset($typo['size']) && is_string($typo['size']) && StyleValueGuard::isLength($typo['size']);
        foreach (['tablet', 'mobile'] as $device) {
            $deviceSize = $block['responsive'][$device]['style']['typography']['size'] ?? null;
            $size = $size || (is_string($deviceSize) && StyleValueGuard::isLength($deviceSize));
        }
        if ($size) {
            $out[] = 'sb-ty-size';
        }
        if ($set($typo['color'] ?? null) || $set($style['color'] ?? null) || $set($style['text_token'] ?? null)) {
            $out[] = 'sb-ty-color';
        }
        if (isset($typo['line_height']) && StyleValueGuard::isLineHeight($typo['line_height'])) {
            $out[] = 'sb-ty-lh';
        }
        if (isset($typo['letter_spacing']) && is_string($typo['letter_spacing']) && StyleValueGuard::isLetterSpacing($typo['letter_spacing'])) {
            $out[] = 'sb-ty-ls';
        }
        if ($set($typo['font_family'] ?? null) || $set($style['font_token'] ?? null)) {
            $out[] = 'sb-ty-ff';
        }
        if (isset($typo['weight']) && in_array((string) $typo['weight'], CanonicalDocumentSchema::ALLOWED_FONT_WEIGHTS, true)) {
            $out[] = 'sb-ty-fw';
        }
        if (isset($typo['transform']) && in_array((string) $typo['transform'], CanonicalDocumentSchema::ALLOWED_TEXT_TRANSFORMS, true)) {
            $out[] = 'sb-ty-tt';
        }
        return $out;
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

        // Sprint 2 visual style classes
        if (isset($style['typography']) && is_array($style['typography'])) {
            $typo = $style['typography'];
            if (isset($typo['weight']) && in_array((string) $typo['weight'], CanonicalDocumentSchema::ALLOWED_FONT_WEIGHTS, true)) {
                $classes[] = 'sb-font-' . $typo['weight'];
            }
            if (isset($typo['transform']) && in_array((string) $typo['transform'], CanonicalDocumentSchema::ALLOWED_TEXT_TRANSFORMS, true)) {
                // `none` is the stylesheet's `sb-normal-case`: a bare `sb-none` has no rule, so an inherited uppercase would stay.
                $classes[] = $typo['transform'] === 'none' ? 'sb-normal-case' : 'sb-' . $typo['transform'];
            }
        }
        if (isset($style['border']) && is_array($style['border'])) {
            if (isset($style['border']['radius']) && in_array((string) $style['border']['radius'], CanonicalDocumentSchema::ALLOWED_RADIUS_PRESETS, true)) {
                $classes[] = 'sb-radius-' . $style['border']['radius'];
            }
            if (isset($style['border']['style']) && in_array((string) $style['border']['style'], CanonicalDocumentSchema::ALLOWED_BORDER_STYLES, true)) {
                $classes[] = 'sb-border-' . $style['border']['style'];
            }
        }
        if (isset($style['shadow']) && is_string($style['shadow']) && in_array($style['shadow'], CanonicalDocumentSchema::ALLOWED_SHADOW_PRESETS, true)) {
            $classes[] = 'sb-shadow-' . $style['shadow'];
        }

        return $classes;
    }

    /**
     * Build sanitized inline CSS style string for custom visual values.
     *
     * @param array<string, mixed> $style
     */
    /**
     * The motion a block or a section carries: entrance-animation classes, interaction classes and trigger
     * attribute, and the timing custom properties (already terminated by `;` when present).
     *
     * @param array<string, mixed> $node
     * @return array{animationClasses: list<string>, interactionClasses: list<string>, interactionAttrs: string, motionVars: string}
     */
    private function motionOf(array $node): array
    {
        $animationClasses = [];
        $motionVars = '';
        if (isset($node['animation']) && is_array($node['animation'])) {
            $anim = $node['animation'];
            $animType = (string) ($anim['type'] ?? '');
            if ($animType !== '' && $animType !== 'none') {
                $animationClasses[] = 'sb-animate-' . str_replace('_', '-', $animType);
                // Per-block timing must reach the stylesheet, or the inspector's
                // duration / delay / easing controls would store values that
                // never render — the exact "saved but did nothing" bug this
                // phase exists to close.
                $motionVars .= $this->motionVariables($anim);
            }
        }

        $interactionClasses = [];
        $interactionAttrs = '';
        if (isset($node['interactions']) && is_array($node['interactions'])) {
            $trigger = (string) ($node['interactions']['trigger'] ?? '');
            if ($trigger !== '') {
                $interactionClasses[] = 'sb-interaction-' . str_replace(['_', ' '], '-', $trigger);
                $interactionAttrs .= ' data-sb-interaction-trigger="' . Html::e($trigger) . '"';
            }
            if (isset($node['interactions']['animation']) && is_array($node['interactions']['animation'])) {
                $anim = $node['interactions']['animation'];
                $animType = (string) ($anim['type'] ?? '');
                if ($animType !== '' && $animType !== 'none') {
                    $animationClasses[] = 'sb-animate-' . str_replace('_', '-', $animType);
                    $motionVars .= $this->motionVariables($anim);
                }
            }
        }
        if ($motionVars !== '') {
            $motionVars .= ';';
        }
        return ['animationClasses' => $animationClasses, 'interactionClasses' => $interactionClasses, 'interactionAttrs' => $interactionAttrs, 'motionVars' => $motionVars];
    }

    /**
     * Per-block motion timing, emitted as CSS custom properties so
     * `StudioStylesheet`'s `.sb-animate-*` rules can read them:
     *
     *   --sb-anim-duration / --sb-anim-delay / --sb-anim-easing
     *
     * Values are validated here rather than trusted, because they land inside a
     * custom property that the stylesheet feeds straight into `animation:`:
     *   - timings are integers, clamped to the same 0–4000ms the inspector uses
     *   - easing is a fixed allowlist; an arbitrary string is DROPPED, not
     *     escaped, so there is no path from a document to a CSS value the
     *     server did not itself define
     * A document that somehow contains junk motion config simply renders with
     * the stylesheet defaults rather than being rejected.
     *
     * @param array<string, mixed> $anim
     */
    private function motionVariables(array $anim): string
    {
        static $easings = [
            'ease' => true, 'ease-in' => true, 'ease-out' => true, 'linear' => true,
            'ease-in-out' => true, 'cubic-bezier(.22,1,.36,1)' => true,
        ];

        $rules = [];

        foreach (['duration_ms', 'delay_ms'] as $key) {
            if (!isset($anim[$key]) || !is_numeric($anim[$key])) {
                continue;
            }
            $ms = max(0, min(4000, (int) round((float) $anim[$key])));
            $rules[] = '--sb-anim-' . ($key === 'duration_ms' ? 'duration' : 'delay') . ':' . $ms . 'ms';
        }

        if (isset($anim['easing']) && is_string($anim['easing']) && isset($easings[$anim['easing']])) {
            $rules[] = '--sb-anim-easing:' . $anim['easing'];
        }

        return $rules === [] ? '' : implode(';', $rules) . ';';
    }

    /**
     * The servable URL of `style.background.image` for the CURRENT tenant, or
     * null. The id goes through the same tenant-scoped resolver as an image
     * block; the resulting URL is checked again before it enters a stylesheet.
     *
     * @param array<string, mixed> $style
     */
    private function backgroundImageUrl(array $style): ?string
    {
        $image = $style['background']['image'] ?? null;
        if (!is_array($image) || !isset($image['media_id']) || !is_int($image['media_id']) || $image['media_id'] <= 0) {
            return null;
        }
        $resolved = $this->media->resolveImage($image['media_id']);
        return $resolved === null ? null : StyleSurface::safeCssUrl($resolved->url);
    }

    private function buildInlineStyles(array $style): string
    {
        // Every value goes through StyleValueGuard on the way out as well as on
        // the way in: a document stored before a rule existed (or written by a
        // path that skipped the validator) must still never emit `url(...)`,
        // `@import`, a comment or a stray declaration. A failing value is
        // dropped, not repaired.
        $rules = [];
        $emit  = static function (string $prop, mixed $value, bool $ok) use (&$rules): void {
            if ($ok && (is_string($value) || is_int($value) || is_float($value))) {
                $rules[] = $prop . ':' . $value;
            }
        };
        $isToken = static fn (mixed $v): bool => is_string($v) && StyleValueGuard::isToken($v);

        // Typography custom values
        if (isset($style['typography']) && is_array($style['typography'])) {
            $typo = $style['typography'];
            if (isset($typo['size']) && is_string($typo['size'])) {
                $emit('font-size', $typo['size'], StyleValueGuard::isLength($typo['size']));
            }
            if (isset($typo['color']) && is_string($typo['color']) && !str_starts_with($typo['color'], 'text.') && !str_starts_with($typo['color'], 'color.')) {
                $emit('color', $typo['color'], StyleValueGuard::isColor($typo['color']));
            }
            if (isset($typo['line_height'])) {
                $emit('line-height', $typo['line_height'], StyleValueGuard::isLineHeight($typo['line_height']));
            }
            if (isset($typo['letter_spacing']) && is_string($typo['letter_spacing'])) {
                $emit('letter-spacing', $typo['letter_spacing'], StyleValueGuard::isLetterSpacing($typo['letter_spacing']));
            }
            if (isset($typo['font_family']) && is_string($typo['font_family']) && !$isToken($typo['font_family'])) {
                $emit('font-family', $typo['font_family'], StyleValueGuard::isFontFamily($typo['font_family']));
            }
        }

        // Color
        if (isset($style['color']) && is_string($style['color']) && !str_starts_with($style['color'], 'text.') && !str_starts_with($style['color'], 'color.')) {
            $emit('color', $style['color'], StyleValueGuard::isColor($style['color']));
        }

        // Background
        if (isset($style['background'])) {
            if (is_string($style['background']) && !str_starts_with($style['background'], 'surface.') && !str_starts_with($style['background'], 'color.')) {
                $bg = $style['background'];
                $emit('background', $bg, StyleValueGuard::isColor($bg) || StyleValueGuard::isGradient($bg));
            } elseif (is_array($style['background'])) {
                if (isset($style['background']['color']) && is_string($style['background']['color']) && !$isToken($style['background']['color'])) {
                    $emit('background-color', $style['background']['color'], StyleValueGuard::isColor($style['background']['color']));
                }
                if (isset($style['background']['gradient']) && is_string($style['background']['gradient'])) {
                    $emit('background-image', $style['background']['gradient'], StyleValueGuard::isGradient($style['background']['gradient']));
                }
            }
        }

        // Border
        if (isset($style['border']) && is_array($style['border'])) {
            if (isset($style['border']['width'])) {
                $emit('border-width', $style['border']['width'], StyleValueGuard::isBorderWidth($style['border']['width']));
            }
            if (isset($style['border']['color']) && is_string($style['border']['color']) && !$isToken($style['border']['color'])) {
                $emit('border-color', $style['border']['color'], StyleValueGuard::isColor($style['border']['color']));
            }
            if (isset($style['border']['radius']) && !in_array((string) $style['border']['radius'], CanonicalDocumentSchema::ALLOWED_RADIUS_PRESETS, true)) {
                $emit('border-radius', $style['border']['radius'], StyleValueGuard::isLengthList($style['border']['radius']));
            }
        }

        // Shadow
        if (isset($style['shadow']) && is_string($style['shadow']) && !in_array($style['shadow'], CanonicalDocumentSchema::ALLOWED_SHADOW_PRESETS, true)) {
            $emit('box-shadow', $style['shadow'], StyleValueGuard::isShadow($style['shadow']));
        }

        // Dimensions
        if (isset($style['dimensions']) && is_array($style['dimensions'])) {
            foreach (['width', 'height', 'min_height', 'max_width'] as $dim) {
                if (isset($style['dimensions'][$dim])) {
                    $emit(str_replace('_', '-', $dim), $style['dimensions'][$dim], StyleValueGuard::isLength($style['dimensions'][$dim]));
                }
            }
        }

        // Opacity
        if (isset($style['opacity']) && is_numeric($style['opacity']) && $style['opacity'] >= 0 && $style['opacity'] <= 1) {
            $rules[] = 'opacity:' . (0 + $style['opacity']);
        }

        // Z-Index: clamped, not dropped, so a document stored under the old
        // 9999 ceiling still stacks the way its author meant, only never above
        // the platform signature (see StudioCodePolicy::SIGNATURE_Z_INDEX).
        if (isset($style['z_index']) && is_int($style['z_index'])) {
            $rules[] = 'z-index:' . max(CanonicalDocumentSchema::Z_INDEX_MIN, min(CanonicalDocumentSchema::Z_INDEX_MAX, $style['z_index']));
        }

        return implode(';', $rules);
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
