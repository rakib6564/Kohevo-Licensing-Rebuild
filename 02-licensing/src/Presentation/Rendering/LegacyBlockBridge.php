<?php
/**
 * Slate — LegacyBlockBridge: registers a minimal, representative set of native
 * Slate\Presentation\Block implementations for admin/editor.php's Phase 2
 * migration.
 *
 * The archived content-builder plugin (archive/plugins/content-builder/) shipped
 * ~25 block types as array-defined blocks with a `tpl` PHP template, rendered by
 * its own Renderer::renderBlock(). That plugin no longer exists in plugins/, and
 * neither the old array-based BlockRegistry nor the new Slate\Presentation\
 * BlockRegistry has any live block registered anywhere in the app. Porting all
 * ~25 is out of scope for this migration (tracked as deferred, see the plan);
 * this bridge ports exactly 5 representative types — heading, paragraph, image,
 * button, hero — enough to prove the full validate/save/preview/publish/restore
 * pipeline with real, renderable content.
 *
 * Each render closure is a faithful, direct port of the corresponding archived
 * template (archive/plugins/content-builder/lib/blocks/{type}.php) — all five
 * were already pure functions of props -> HTML with no DB/ContentBuilderAPI
 * dependency — so output markup matches what the existing canvas CSS
 * (plugins/content-builder/assets/css/public.css, still inlined by
 * admin/editor.php) already styles. CallbackBlock is the existing adapter this
 * repo already ships for exactly this bridging purpose (see its own docblock).
 *
 * Pure registration — no DB, no side effects beyond populating the registry
 * instance handed in.
 */

declare(strict_types=1);

namespace Slate\Presentation\Rendering;

use Slate\Presentation\FieldSchema;
use Slate\Presentation\RenderContext;

final class LegacyBlockBridge
{
    public static function register(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock(
            'heading',
            FieldSchema::of(
                [
                    ['key' => 'text', 'type' => 'text', 'label' => 'Text'],
                    ['key' => 'level', 'type' => 'select', 'label' => 'Level', 'options' => [
                        ['v' => '1', 'l' => 'H1'], ['v' => '2', 'l' => 'H2'],
                        ['v' => '3', 'l' => 'H3'], ['v' => '4', 'l' => 'H4'],
                    ]],
                    ['key' => 'align', 'type' => 'select', 'label' => 'Alignment', 'options' => [
                        ['v' => 'left', 'l' => 'Left'], ['v' => 'center', 'l' => 'Center'], ['v' => 'right', 'l' => 'Right'],
                    ]],
                ],
                ['text' => 'Heading', 'level' => '2', 'align' => 'left'],
            ),
            static function (array $props, RenderContext $ctx): string {
                $lvl = max(1, min(6, (int) ($props['level'] ?? 2)));
                $align = self::choice($props['align'] ?? 'left', ['left', 'center', 'right'], 'left');
                $style = $align !== 'left' ? ' style="text-align:' . $align . '"' : '';
                return sprintf('<h%1$d class="cb-heading"%3$s>%2$s</h%1$d>', $lvl, \e((string) ($props['text'] ?? '')), $style);
            },
        ));

        $registry->register(new CallbackBlock(
            'paragraph',
            FieldSchema::of(
                [['key' => 'text', 'type' => 'textarea', 'label' => 'Text']],
                ['text' => 'Write something…'],
            ),
            static function (array $props, RenderContext $ctx): string {
                return '<p class="cb-paragraph">' . nl2br(\e((string) ($props['text'] ?? ''))) . '</p>';
            },
        ));

        $registry->register(new CallbackBlock(
            'image',
            FieldSchema::of(
                [
                    ['key' => 'media', 'type' => 'media', 'label' => 'Image'],
                    ['key' => 'width', 'type' => 'select', 'label' => 'Width', 'options' => [
                        ['v' => 'full', 'l' => 'Full'], ['v' => 'wide', 'l' => 'Wide'], ['v' => 'normal', 'l' => 'Normal'],
                    ]],
                ],
                ['width' => 'full'],
            ),
            static function (array $props, RenderContext $ctx): string {
                $media = is_array($props['media'] ?? null) ? $props['media'] : null;
                $url = self::resolveMediaUrl($media);
                if ($url === '') {
                    return '';
                }
                $wv = (string) ($props['width'] ?? 'full');
                $w = in_array($wv, ['full', 'wide', 'normal'], true) ? $wv : 'full';
                $alt = (string) ($media['alt'] ?? '');
                $style = '';
                $focal = $media['focal'] ?? null;
                if (is_array($focal) && count($focal) === 2) {
                    $style = sprintf(' style="object-position:%.2F%% %.2F%%"', (float) $focal[0] * 100, (float) $focal[1] * 100);
                }
                return sprintf(
                    '<figure class="cb-image cb-image-%s"><img src="%s" alt="%s" loading="lazy"%s></figure>',
                    \e($w),
                    \e(\slate_safe_url($url)),
                    \e($alt),
                    $style,
                );
            },
        ));

        $registry->register(new CallbackBlock(
            'button',
            FieldSchema::of(
                [
                    ['key' => 'text', 'type' => 'text', 'label' => 'Label'],
                    ['key' => 'href', 'type' => 'url', 'label' => 'Link URL'],
                    ['key' => 'style', 'type' => 'select', 'label' => 'Style', 'options' => [
                        ['v' => 'primary', 'l' => 'Primary'], ['v' => 'secondary', 'l' => 'Secondary'],
                    ]],
                ],
                ['text' => 'Click me', 'href' => '#', 'style' => 'primary'],
            ),
            static function (array $props, RenderContext $ctx): string {
                $st = (($props['style'] ?? 'primary') === 'secondary') ? 'btn-secondary' : 'btn-primary';
                return sprintf(
                    '<p class="cb-button"><a class="btn %s" href="%s">%s</a></p>',
                    $st,
                    \e(\slate_safe_url((string) ($props['href'] ?? '#'))),
                    \e((string) ($props['text'] ?? 'Button')),
                );
            },
        ));

        $registry->register(new CallbackBlock(
            'hero',
            FieldSchema::of(
                [
                    ['key' => 'pad', 'type' => 'select', 'label' => 'Section spacing', 'options' => [
                        ['v' => 'compact', 'l' => 'Compact'], ['v' => 'normal', 'l' => 'Normal'], ['v' => 'spacious', 'l' => 'Spacious'],
                    ]],
                    ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => [
                        ['v' => 'split', 'l' => 'Split (image + text)'], ['v' => 'banner', 'l' => 'Banner (text over image)'],
                    ]],
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                    ['key' => 'subheading', 'type' => 'textarea', 'label' => 'Subheading'],
                    ['key' => 'btnText', 'type' => 'text', 'label' => 'Button label'],
                    ['key' => 'btnHref', 'type' => 'url', 'label' => 'Button link'],
                    ['key' => 'btn2Text', 'type' => 'text', 'label' => 'Second button label'],
                    ['key' => 'btn2Href', 'type' => 'url', 'label' => 'Second button link'],
                    ['key' => 'media', 'type' => 'media', 'label' => 'Image'],
                    ['key' => 'mediaSide', 'type' => 'select', 'label' => 'Image side', 'options' => [
                        ['v' => 'left', 'l' => 'Left'], ['v' => 'right', 'l' => 'Right'],
                    ]],
                    ['key' => 'overlay', 'type' => 'select', 'label' => 'Image darkness', 'options' => [
                        ['v' => 'light', 'l' => 'Light'], ['v' => 'medium', 'l' => 'Medium'], ['v' => 'dark', 'l' => 'Dark'],
                    ]],
                    ['key' => 'height', 'type' => 'select', 'label' => 'Height', 'options' => [
                        ['v' => 'normal', 'l' => 'Normal'], ['v' => 'tall', 'l' => 'Tall'], ['v' => 'short', 'l' => 'Short'],
                    ]],
                ],
                [
                    'pad' => 'normal', 'layout' => 'split', 'eyebrow' => '', 'heading' => 'Your big headline',
                    'subheading' => 'A short supporting sentence that sells the idea.',
                    'btnText' => 'Get started', 'btnHref' => '#', 'btn2Text' => '', 'btn2Href' => '',
                    'mediaSide' => 'left', 'overlay' => 'medium', 'height' => 'normal',
                ],
            ),
            static function (array $props, RenderContext $ctx): string {
                return self::renderHero($props);
            },
        ));

        self::registerArchivedFallbacks($registry);
    }

    /**
     * Keep archived content portable even when a specialized renderer is not
     * available in the active application. These contracts deliberately expose
     * only safe text fields; richer blocks can be upgraded without changing the
     * stored type or document shape later.
     */
    private static function registerArchivedFallbacks(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock('cta', FieldSchema::of([
            ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
            ['key' => 'text', 'type' => 'textarea', 'label' => 'Text'],
            ['key' => 'btnText', 'type' => 'text', 'label' => 'Button label'],
            ['key' => 'btnHref', 'type' => 'url', 'label' => 'Button link'],
            ['key' => 'pad', 'type' => 'select', 'label' => 'Spacing', 'options' => [['v' => 'compact', 'l' => 'Compact'], ['v' => 'normal', 'l' => 'Normal'], ['v' => 'spacious', 'l' => 'Spacious']]],
        ], ['eyebrow' => 'A better way forward', 'heading' => 'Ready to get started?', 'text' => 'Give your visitors a clear next step.', 'btnText' => 'Get started', 'btnHref' => '#', 'pad' => 'normal']),
            static fn (array $p, RenderContext $c): string => '<section class="cb-pad-' . self::choice($p['pad'] ?? 'normal', ['compact', 'normal', 'spacious'], 'normal') . ' cb-cta"><div class="cb-cta-inner">' . (($p['eyebrow'] ?? '') !== '' ? '<span class="cb-eyebrow">' . \e((string)$p['eyebrow']) . '</span>' : '') . '<h2 class="cb-cta-title">' . \e((string)($p['heading'] ?? 'Ready to get started?')) . '</h2><p class="cb-cta-text">' . \e((string)($p['text'] ?? '')) . '</p><a class="cb-btn cb-btn-primary" href="' . \e(\slate_safe_url((string)($p['btnHref'] ?? '#'))) . '">' . \e((string)($p['btnText'] ?? 'Get started')) . '</a></div></section>'
        ));

        $registry->register(new CallbackBlock('divider', FieldSchema::of([
            ['key' => 'style', 'type' => 'select', 'label' => 'Style', 'options' => [['v' => 'solid', 'l' => 'Solid'], ['v' => 'dashed', 'l' => 'Dashed']]],
            ['key' => 'width', 'type' => 'number', 'label' => 'Width (px)'],
        ], ['style' => 'solid', 'width' => 100]), static fn (array $p, RenderContext $c): string => '<div style="padding:24px 0"><hr style="width:' . max(10, min(100, (int)($p['width'] ?? 100))) . '%;border:0;border-top:2px ' . self::choice($p['style'] ?? 'solid', ['solid', 'dashed'], 'solid') . ' currentColor;opacity:.18;margin:auto"></div>'));
        $registry->register(new CallbackBlock('spacer', FieldSchema::of([['key' => 'height', 'type' => 'number', 'label' => 'Height (px)']], ['height' => 48]), static fn (array $p, RenderContext $c): string => '<div class="cb-block cb-spacer" style="height:' . max(8, min(400, (int)($p['height'] ?? 48))) . 'px" aria-hidden="true"></div>'));
        $registry->register(new CallbackBlock('html', FieldSchema::of([['key' => 'content', 'type' => 'textarea', 'label' => 'Text content']], ['content' => 'Add custom content here']), static fn (array $p, RenderContext $c): string => '<div class="cb-section-inner"><p>' . nl2br(\e((string)($p['content'] ?? ''))) . '</p></div>'));
        $registry->register(new CallbackBlock('testimonial', FieldSchema::of([
            ['key' => 'quote', 'type' => 'textarea', 'label' => 'Quote'], ['key' => 'author', 'type' => 'text', 'label' => 'Author'], ['key' => 'role', 'type' => 'text', 'label' => 'Role'],
        ], ['quote' => 'A thoughtful experience from start to finish.', 'author' => 'Happy customer', 'role' => 'Client']), static fn (array $p, RenderContext $c): string => '<section class="cb-testimonial"><figure class="cb-testimonial-inner"><blockquote class="cb-testimonial-quote">&ldquo;' . \e((string)($p['quote'] ?? '')) . '&rdquo;</blockquote><figcaption class="cb-testimonial-cite"><span><b class="cb-testimonial-author">' . \e((string)($p['author'] ?? '')) . '</b><span class="cb-testimonial-role">' . \e((string)($p['role'] ?? '')) . '</span></span></figcaption></figure></section>'));
        $colsCountOptions = [['v' => '', 'l' => 'Same as desktop'], ['v' => '1', 'l' => '1'], ['v' => '2', 'l' => '2'], ['v' => '3', 'l' => '3'], ['v' => '4', 'l' => '4']];
        $registry->register(new CallbackBlock('columns', FieldSchema::of([
            ['key' => 'sectionName', 'type' => 'text', 'label' => 'Section name'], ['key' => 'columns', 'type' => 'number', 'label' => 'Columns (desktop)'], ['key' => 'gap', 'type' => 'number', 'label' => 'Gap (px)'],
            // Per-device column count override — previously the same column
            // count applied at every breakpoint (mobile always hard-stacked to 1
            // via a global !important rule in content-blocks.css, tablet had no
            // control at all). Empty string means "inherit the desktop count /
            // the old always-stack-on-mobile default", so existing documents
            // render byte-identical until a user actually picks an override.
            ['key' => 'columnsTablet', 'type' => 'select', 'label' => 'Columns (tablet)', 'options' => $colsCountOptions],
            ['key' => 'columnsMobile', 'type' => 'select', 'label' => 'Columns (mobile)', 'options' => $colsCountOptions],
            // Each column's nested block list, addressed via a DocumentOperations
            // path like ['s1', 0, 'props', 'cols', 0, 'blocks']. Declared as
            // 'blocks' so DocumentValidator accepts the list rather than rejecting
            // it as an undeclared property (it previously wasn't declared here at
            // all, so saving any columns section failed outright). Note
            // capabilities.nested stays false (see
            // ContentServiceFactory::validatorRegistry()), so the nested blocks
            // themselves are not yet structurally validated against their own
            // schemas — only that 'cols' itself is a list.
            ['key' => 'cols', 'type' => 'blocks', 'label' => 'Columns content'],
        ], ['sectionName' => 'Columns section', 'columns' => 2, 'columnsTablet' => '', 'columnsMobile' => '', 'gap' => 24, 'cols' => [['blocks' => []], ['blocks' => []]]]), static function (array $p, RenderContext $c): string {
            $n = max(1, min(4, (int)($p['columns'] ?? count((array)($p['cols'] ?? [])) ?: 2)));
            $cols = is_array($p['cols'] ?? null) ? $p['cols'] : [];
            $items = '';
            for ($i = 0; $i < $n; $i++) {
                $blocks = is_array($cols[$i]['blocks'] ?? null) ? $cols[$i]['blocks'] : [];
                $inner = self::renderNestedBlocks($blocks);
                $items .= '<div class="cb-col" data-col-index="' . $i . '">' . ($inner !== '' ? $inner : '<div class="cb-card"><h3 class="cb-card-title">Column ' . ($i + 1) . '</h3><p class="cb-card-text">Drop widgets here</p></div>') . '</div>';
            }

            $tabletN = self::choice($p['columnsTablet'] ?? '', ['', '1', '2', '3', '4'], '');
            $mobileN = self::choice($p['columnsMobile'] ?? '', ['', '1', '2', '3', '4'], '');
            $id = '';
            $overrideCss = '';
            if ($tabletN !== '' || $mobileN !== '') {
                $id = 'cb-cols-' . substr(md5(json_encode($p) ?: uniqid('', true)), 0, 8);
                if ($tabletN !== '') $overrideCss .= '@media (max-width:1024px){#' . $id . '{grid-template-columns:repeat(' . (int) $tabletN . ',1fr)!important}}';
                if ($mobileN !== '') $overrideCss .= '@media (max-width:640px){#' . $id . '{grid-template-columns:repeat(' . (int) $mobileN . ',1fr)!important}}';
            }

            return '<div class="cb-columns cb-cols-' . $n . '"' . ($id !== '' ? ' id="' . $id . '"' : '') . ' style="gap:' . max(0, min(80, (int)($p['gap'] ?? 24))) . 'px">' . $items . '</div>'
                . ($overrideCss !== '' ? '<style>' . $overrideCss . '</style>' : '');
        }));
        $registry->register(new CallbackBlock('container', FieldSchema::of([
            ['key' => 'sectionName', 'type' => 'text', 'label' => 'Section name'], ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'], ['key' => 'content', 'type' => 'textarea', 'label' => 'Content'],
            ['key' => 'contentWidth', 'type' => 'select', 'label' => 'Content width', 'options' => [['v' => 'boxed', 'l' => 'Boxed'], ['v' => 'full', 'l' => 'Full width']]],
            ['key' => 'direction', 'type' => 'select', 'label' => 'Direction', 'options' => [['v' => 'column', 'l' => 'Column'], ['v' => 'row', 'l' => 'Row']]],
            ['key' => 'gap', 'type' => 'number', 'label' => 'Gap (px)'],
            // Same reasoning as 'cols' above: this container's nested block list,
            // previously in the defaults but never declared as a field, so any
            // container with actual children failed validation the same way.
            ['key' => 'children', 'type' => 'blocks', 'label' => 'Container content'],
        ], ['sectionName' => 'Container', 'heading' => 'Container', 'content' => 'Add content inside this container', 'children' => [], 'contentWidth' => 'boxed', 'direction' => 'column', 'gap' => 24]), static fn (array $p, RenderContext $c): string => '<div class="cb-section-inner cb-container" style="width:' . (($p['contentWidth'] ?? 'boxed') === 'full' ? '100%' : 'min(100%,1180px)') . ';display:flex;flex-direction:' . self::choice($p['direction'] ?? 'column', ['column', 'row'], 'column') . ';gap:' . max(0, min(120, (int)($p['gap'] ?? 24))) . 'px;padding:24px;border:1px dashed #94a3b8;border-radius:10px"><div><h3>' . \e((string)($p['heading'] ?? 'Container')) . '</h3><p>' . nl2br(\e((string)($p['content'] ?? ''))) . '</p></div>' . self::renderNestedBlocks(is_array($p['children'] ?? null) ? $p['children'] : []) . '</div>'));
        self::registerIconGrid($registry);
        self::registerImageGrid($registry);
        self::registerRxGallery($registry);
        self::registerRxHero($registry);
        self::registerRxMarquee($registry);
        self::registerRxMenu($registry);
        self::registerRxReviews($registry);
        self::registerRxStory($registry);
        self::registerRxVisit($registry);

        foreach (['post-list', 'react'] as $type) {
            $registry->register(new CallbackBlock($type, FieldSchema::of([
                ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'], ['key' => 'content', 'type' => 'textarea', 'label' => 'Content'],
            ], ['heading' => ucfirst(str_replace('-', ' ', $type)), 'content' => 'Add content for this section']), static function (array $p, RenderContext $c) use ($type): string {
                return '<section class="cb-section cb-legacy-' . \e($type) . '"><div class="cb-section-inner"><span class="cb-eyebrow">' . \e(ucfirst(str_replace('-', ' ', $type))) . '</span><h2 class="cb-section-title">' . \e((string)($p['heading'] ?? '')) . '</h2><p>' . nl2br(\e((string)($p['content'] ?? ''))) . '</p></div></section>';
            }));
        }
    }

    private static function choice(mixed $value, array $allowed, string $fallback): string
    {
        return in_array((string)$value, $allowed, true) ? (string)$value : $fallback;
    }

    /**
     * rx-gallery — a real image gallery (upload via the Media Library picker,
     * a repeater of {media, label, size} tiles) with a dependency-free,
     * CSS-only lightbox. Faithfully ported from the archived template
     * (archive/plugins/content-builder/lib/blocks/rx-gallery.php) — same
     * markup/classes (.rx-gallery-grid/.rx-tile/.rx-tone-*), so it renders
     * through content-blocks.css's existing rx-* styling — plus the lightbox,
     * which the archived version never had.
     *
     * Previously this type was just a generic heading+content placeholder (see
     * the loop below) — a real photo gallery with no way to add photos.
     */
    private static function registerRxGallery(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock(
            'rx-gallery',
            FieldSchema::of(
                [
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                    ['key' => 'tone', 'type' => 'select', 'label' => 'Tone', 'options' => [
                        ['v' => 'light', 'l' => 'Light'], ['v' => 'dark', 'l' => 'Dark'], ['v' => 'surface', 'l' => 'Surface'],
                    ]],
                    ['key' => 'images', 'type' => 'repeater', 'label' => 'Images', 'itemLabel' => 'Image', 'maxItems' => 24, 'item' => [
                        ['key' => 'media', 'type' => 'media', 'label' => 'Image', 'required' => true],
                        ['key' => 'label', 'type' => 'text', 'label' => 'Caption (optional)', 'maxLength' => 120],
                        ['key' => 'size', 'type' => 'select', 'label' => 'Tile size', 'options' => [
                            ['v' => 'normal', 'l' => 'Normal'], ['v' => 'wide', 'l' => 'Wide'], ['v' => 'tall', 'l' => 'Tall'],
                        ]],
                    ]],
                ],
                ['eyebrow' => '', 'heading' => 'Gallery', 'tone' => 'light', 'images' => []],
            ),
            static function (array $props, RenderContext $ctx): string {
                $tone = self::choice($props['tone'] ?? 'light', ['light', 'dark', 'surface'], 'light');
                $eyebrow = (string) ($props['eyebrow'] ?? '');
                $heading = (string) ($props['heading'] ?? '');
                $images = is_array($props['images'] ?? null) ? $props['images'] : [];

                // A stable-enough id namespace per block instance so multiple
                // galleries on one page don't collide on their lightbox anchors.
                $gid = 'rxg' . substr(md5(json_encode($images) ?: uniqid('', true)), 0, 8);

                $head = '';
                if ($eyebrow !== '') $head .= '<span class="rx-eyebrow">' . \e($eyebrow) . '</span>';
                if ($heading !== '') $head .= '<h2 class="rx-h2">' . \e($heading) . '</h2>';

                $tiles = '';
                $lightboxes = '';
                foreach ($images as $i => $item) {
                    if (!is_array($item)) continue;
                    $media = is_array($item['media'] ?? null) ? $item['media'] : null;
                    $url = self::resolveMediaUrl($media);
                    if ($url === '') continue;
                    $label = (string) ($item['label'] ?? '');
                    $size = self::choice($item['size'] ?? 'normal', ['normal', 'wide', 'tall'], 'normal');
                    $sizeClass = $size !== 'normal' ? ' rx-tile-' . $size : '';
                    $alt = (string) ($media['alt'] ?? $label);
                    $anchorId = $gid . '-' . $i;

                    $tiles .= '<a class="rx-tile' . $sizeClass . '" href="#' . \e($anchorId) . '" style="background-image:url(\'' . \e($url) . '\')">'
                        . '<span class="rx-tile-zoom" aria-hidden="true">⤢</span>'
                        . ($label !== '' ? '<span class="rx-tile-label">' . \e($label) . '</span>' : '')
                        . '</a>';

                    $lightboxes .= '<div class="rx-lightbox" id="' . \e($anchorId) . '">'
                        . '<a href="#" class="rx-lightbox-close" aria-label="Close">✕</a>'
                        . '<img src="' . \e($url) . '" alt="' . \e($alt) . '" loading="lazy">'
                        . ($label !== '' ? '<div class="rx-lightbox-cap">' . \e($label) . '</div>' : '')
                        . '</div>';
                }

                return '<section class="rx rx-gallery rx-tone-' . \e($tone) . '"><div class="rx-inner">'
                    . ($head !== '' ? '<div class="rx-head">' . $head . '</div>' : '')
                    . '<div class="rx-gallery-grid">' . $tiles . '</div>'
                    . '</div></section>' . $lightboxes;
            },
        ));
    }

    /**
     * icon-grid — a grid of icon+title+text cards, each independently editable
     * (icon choice, title, text, optional link) via a repeater. Previously
     * (cardGridSchema/renderCardGrid) every icon-grid on the page rendered the
     * exact same 3 hardcoded cards ("Simple and clear" / "Built for you" /
     * "Made to last") regardless of what was configured — the "very basic"
     * placeholder the user flagged. Ported from
     * archive/plugins/content-builder/lib/blocks/icon-grid.php.
     */
    private static function registerIconGrid(InMemoryBlockRegistry $registry): void
    {
        $iconOptions = [];
        foreach (array_keys(self::ICON_GRID_ICONS) as $name) {
            $iconOptions[] = ['v' => $name, 'l' => ucfirst($name)];
        }
        $registry->register(new CallbackBlock(
            'icon-grid',
            FieldSchema::of(
                [
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                    ['key' => 'cols', 'type' => 'select', 'label' => 'Columns', 'options' => [['v' => '2', 'l' => '2'], ['v' => '3', 'l' => '3'], ['v' => '4', 'l' => '4']]],
                    ['key' => 'bg', 'type' => 'select', 'label' => 'Background', 'options' => [['v' => 'page', 'l' => 'Page'], ['v' => 'white', 'l' => 'White'], ['v' => 'tint', 'l' => 'Tint'], ['v' => 'tint2', 'l' => 'Tint 2'], ['v' => 'dark', 'l' => 'Dark']]],
                    ['key' => 'pad', 'type' => 'select', 'label' => 'Spacing', 'options' => [['v' => 'compact', 'l' => 'Compact'], ['v' => 'normal', 'l' => 'Normal'], ['v' => 'spacious', 'l' => 'Spacious']]],
                    ['key' => 'items', 'type' => 'repeater', 'label' => 'Icon items', 'itemLabel' => 'Icon item', 'maxItems' => 12, 'item' => [
                        ['key' => 'icon', 'type' => 'select', 'label' => 'Icon', 'options' => $iconOptions],
                        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Text'],
                        ['key' => 'linkText', 'type' => 'text', 'label' => 'Link text (optional)'],
                        ['key' => 'linkHref', 'type' => 'url', 'label' => 'Link URL (optional)'],
                    ]],
                ],
                ['eyebrow' => 'What we offer', 'heading' => 'Explore the possibilities', 'cols' => '3', 'bg' => 'white', 'pad' => 'normal', 'items' => []],
            ),
            static function (array $p, RenderContext $c): string {
                $bg = self::choice($p['bg'] ?? 'white', ['page', 'white', 'tint', 'tint2', 'dark'], 'white');
                $cols = self::choice($p['cols'] ?? '3', ['2', '3', '4'], '3');
                $pad = self::choice($p['pad'] ?? 'normal', ['compact', 'normal', 'spacious'], 'normal');
                $items = is_array($p['items'] ?? null) ? $p['items'] : [];
                $cards = '';
                foreach ($items as $it) {
                    if (!is_array($it)) continue;
                    $iconName = self::choice($it['icon'] ?? 'star', array_keys(self::ICON_GRID_ICONS), 'star');
                    $path = self::ICON_GRID_ICONS[$iconName];
                    $title = (string) ($it['title'] ?? '');
                    $text = (string) ($it['text'] ?? '');
                    $linkText = (string) ($it['linkText'] ?? '');
                    $linkHref = (string) ($it['linkHref'] ?? '');
                    $cards .= '<div class="cb-card">'
                        . '<span class="cb-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" width="26" height="26">' . $path . '</svg></span>'
                        . ($title !== '' ? '<h3 class="cb-card-title">' . \e($title) . '</h3>' : '')
                        . ($text !== '' ? '<p class="cb-card-text">' . \e($text) . '</p>' : '')
                        . ($linkText !== '' && $linkHref !== '' ? '<a class="cb-card-link" href="' . \e(\slate_safe_url($linkHref)) . '">' . \e($linkText) . ' →</a>' : '')
                        . '</div>';
                }
                return '<section class="cb-pad-' . $pad . ' cb-section cb-icon-grid cb-bg-' . $bg . '"><div class="cb-section-inner">'
                    . (($p['eyebrow'] ?? '') !== '' ? '<span class="cb-eyebrow">' . \e((string) $p['eyebrow']) . '</span>' : '')
                    . (($p['heading'] ?? '') !== '' ? '<h2 class="cb-section-title">' . \e((string) $p['heading']) . '</h2><div class="cb-divider"></div>' : '')
                    . '<div class="cb-grid cb-grid-' . $cols . '">' . $cards . '</div>'
                    . '</div></section>';
            },
        ));
    }

    private const ICON_GRID_ICONS = [
        'star' => '<path d="M12 2l3 7 7 .8-5.4 4.8L18 22l-6-3.5L6 22l1.4-7.4L2 9.8 9 9z"/>',
        'box' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v9"/>',
        'truck' => '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'check' => '<path d="M20 6L9 17l-5-5"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>',
        'bolt' => '<path d="M13 2L3 14h7l-1 8 10-12h-7z"/>',
        'shield' => '<path d="M12 3l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V7z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'leaf' => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4"/><path d="M12 8v13M5 12v9h14v-9M12 8a3 3 0 1 0-3-3M12 8a3 3 0 1 1 3-3"/>',
    ];

    /**
     * image-grid — a grid of image+title+text cards (optionally linked), each
     * independently editable via a repeater. Same fix as icon-grid: previously
     * shared the same generic 3-hardcoded-card renderer. Ported from
     * archive/plugins/content-builder/lib/blocks/image-grid.php.
     */
    private static function registerImageGrid(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock(
            'image-grid',
            FieldSchema::of(
                [
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                    ['key' => 'cols', 'type' => 'select', 'label' => 'Columns', 'options' => [['v' => '2', 'l' => '2'], ['v' => '3', 'l' => '3'], ['v' => '4', 'l' => '4']]],
                    ['key' => 'bg', 'type' => 'select', 'label' => 'Background', 'options' => [['v' => 'page', 'l' => 'Page'], ['v' => 'white', 'l' => 'White'], ['v' => 'tint', 'l' => 'Tint'], ['v' => 'tint2', 'l' => 'Tint 2'], ['v' => 'dark', 'l' => 'Dark']]],
                    ['key' => 'pad', 'type' => 'select', 'label' => 'Spacing', 'options' => [['v' => 'compact', 'l' => 'Compact'], ['v' => 'normal', 'l' => 'Normal'], ['v' => 'spacious', 'l' => 'Spacious']]],
                    ['key' => 'items', 'type' => 'repeater', 'label' => 'Image items', 'itemLabel' => 'Image item', 'maxItems' => 12, 'item' => [
                        ['key' => 'media', 'type' => 'media', 'label' => 'Image'],
                        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Text'],
                        ['key' => 'href', 'type' => 'url', 'label' => 'Link URL (optional)'],
                    ]],
                ],
                ['eyebrow' => '', 'heading' => 'Explore the possibilities', 'cols' => '3', 'bg' => 'tint', 'pad' => 'normal', 'items' => []],
            ),
            static function (array $p, RenderContext $c): string {
                $bg = self::choice($p['bg'] ?? 'tint', ['page', 'white', 'tint', 'tint2', 'dark'], 'tint');
                $cols = self::choice($p['cols'] ?? '3', ['2', '3', '4'], '3');
                $pad = self::choice($p['pad'] ?? 'normal', ['compact', 'normal', 'spacious'], 'normal');
                $items = is_array($p['items'] ?? null) ? $p['items'] : [];
                $cards = '';
                foreach ($items as $it) {
                    if (!is_array($it)) continue;
                    $media = is_array($it['media'] ?? null) ? $it['media'] : null;
                    $url = self::resolveMediaUrl($media);
                    $title = (string) ($it['title'] ?? '');
                    $text = (string) ($it['text'] ?? '');
                    $href = trim((string) ($it['href'] ?? ''));
                    $tag = $href !== '' ? 'a' : 'div';
                    $attr = $href !== '' ? ' href="' . \e(\slate_safe_url($href)) . '"' : '';
                    $media_html = $url !== ''
                        ? '<img class="cb-image-card-img" src="' . \e($url) . '" alt="' . \e($title) . '" loading="lazy">'
                        : '<span class="cb-image-card-ph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" width="30" height="30"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg></span>';
                    $cards .= '<' . $tag . ' class="cb-image-card"' . $attr . '>' . $media_html
                        . '<div class="cb-image-card-body">'
                        . ($title !== '' ? '<h3 class="cb-image-card-title">' . \e($title) . '</h3>' : '')
                        . ($text !== '' ? '<p class="cb-image-card-text">' . \e($text) . '</p>' : '')
                        . '</div></' . $tag . '>';
                }
                return '<section class="cb-pad-' . $pad . ' cb-section cb-image-grid cb-bg-' . $bg . '"><div class="cb-section-inner">'
                    . (($p['eyebrow'] ?? '') !== '' ? '<span class="cb-eyebrow">' . \e((string) $p['eyebrow']) . '</span>' : '')
                    . (($p['heading'] ?? '') !== '' ? '<h2 class="cb-section-title">' . \e((string) $p['heading']) . '</h2><div class="cb-divider"></div>' : '')
                    . '<div class="cb-grid cb-grid-' . $cols . '">' . $cards . '</div>'
                    . '</div></section>';
            },
        ));
    }

    /**
     * rx-hero — restaurant-style hero with an eyebrow/headline/lead, up to two
     * buttons, an optional rating line, an optional photo, and an optional
     * floating info card. Ported from
     * archive/plugins/content-builder/lib/blocks/rx-hero.php. Previously a
     * generic heading+content placeholder (see the removed foreach loop).
     */
    private static function registerRxHero(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock(
            'rx-hero',
            FieldSchema::of(
                [
                    ['key' => 'tone', 'type' => 'select', 'label' => 'Tone', 'options' => [['v' => 'dark', 'l' => 'Dark'], ['v' => 'light', 'l' => 'Light']]],
                    ['key' => 'media', 'type' => 'media', 'label' => 'Background photo (optional)'],
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                    ['key' => 'accentLine', 'type' => 'text', 'label' => 'Accent line (optional, shown italic on its own line)'],
                    ['key' => 'headingEnd', 'type' => 'text', 'label' => 'Heading, line 3 (optional)'],
                    ['key' => 'lead', 'type' => 'textarea', 'label' => 'Supporting text'],
                    ['key' => 'btnText', 'type' => 'text', 'label' => 'Button label'],
                    ['key' => 'btnHref', 'type' => 'url', 'label' => 'Button link'],
                    ['key' => 'btn2Text', 'type' => 'text', 'label' => 'Second button label (optional)'],
                    ['key' => 'btn2Href', 'type' => 'url', 'label' => 'Second button link'],
                    ['key' => 'rating', 'type' => 'text', 'label' => 'Trust line (optional, e.g. "4.9 · 2,300+ reviews")'],
                    ['key' => 'cardBadge', 'type' => 'text', 'label' => 'Floating card badge (optional)'],
                    ['key' => 'cardTitle', 'type' => 'text', 'label' => 'Floating card title'],
                    ['key' => 'cardNote', 'type' => 'text', 'label' => 'Floating card note'],
                    ['key' => 'cardPrice', 'type' => 'text', 'label' => 'Floating card price'],
                ],
                [
                    'tone' => 'dark', 'eyebrow' => '', 'heading' => 'A table worth the trip', 'accentLine' => '', 'headingEnd' => '',
                    'lead' => 'Seasonal plates, a short walk from downtown.', 'btnText' => 'Reserve a table', 'btnHref' => '#',
                    'btn2Text' => '', 'btn2Href' => '', 'rating' => '', 'cardBadge' => '', 'cardTitle' => '', 'cardNote' => '', 'cardPrice' => '',
                ],
            ),
            static function (array $p, RenderContext $c): string {
                $tone = self::choice($p['tone'] ?? 'dark', ['dark', 'light'], 'dark');
                $media = is_array($p['media'] ?? null) ? $p['media'] : null;
                $bgUrl = self::resolveMediaUrl($media);
                $style = $bgUrl !== '' ? ' style="--rx-hero-img:url(\'' . \e($bgUrl) . '\')"' : '';

                $copy = '';
                if (($p['eyebrow'] ?? '') !== '') $copy .= '<span class="rx-eyebrow">' . \e((string) $p['eyebrow']) . '</span>';
                $copy .= '<h1 class="rx-hero-title">' . \e((string) ($p['heading'] ?? ''));
                if (($p['accentLine'] ?? '') !== '') $copy .= '<br><em>' . \e((string) $p['accentLine']) . '</em>';
                if (($p['headingEnd'] ?? '') !== '') $copy .= '<br>' . \e((string) $p['headingEnd']);
                $copy .= '</h1>';
                if (($p['lead'] ?? '') !== '') $copy .= '<p class="rx-hero-lead">' . nl2br(\e((string) $p['lead'])) . '</p>';
                $actions = '';
                if (($p['btnText'] ?? '') !== '') $actions .= '<a class="rx-btn rx-btn-primary" href="' . \e(\slate_safe_url((string) ($p['btnHref'] ?? '#'))) . '">' . \e((string) $p['btnText']) . '</a>';
                if (($p['btn2Text'] ?? '') !== '') $actions .= '<a class="rx-btn rx-btn-ghost" href="' . \e(\slate_safe_url((string) ($p['btn2Href'] ?? '#'))) . '">' . \e((string) $p['btn2Text']) . '</a>';
                $copy .= '<div class="rx-actions">' . $actions . '</div>';
                if (($p['rating'] ?? '') !== '') $copy .= '<div class="rx-trust"><span class="rx-stars">★★★★★</span><small>' . \e((string) $p['rating']) . '</small></div>';

                $card = '';
                if (($p['cardTitle'] ?? '') !== '' || ($p['cardBadge'] ?? '') !== '') {
                    $card = '<aside class="rx-hero-card">';
                    if (($p['cardBadge'] ?? '') !== '') $card .= '<span class="rx-hero-badge">' . \e((string) $p['cardBadge']) . '</span>';
                    $card .= '<div class="rx-hero-plate"><div>';
                    if (($p['cardTitle'] ?? '') !== '') $card .= '<b>' . \e((string) $p['cardTitle']) . '</b>';
                    if (($p['cardNote'] ?? '') !== '') $card .= '<span>' . \e((string) $p['cardNote']) . '</span>';
                    $card .= '</div>';
                    if (($p['cardPrice'] ?? '') !== '') $card .= '<div class="rx-hero-price">' . \e((string) $p['cardPrice']) . '</div>';
                    $card .= '</div></aside>';
                }

                return '<section class="rx rx-hero rx-tone-' . $tone . ($bgUrl !== '' ? ' rx-hero-photo' : '') . '"' . $style . '>'
                    . '<div class="rx-hero-inner"><div class="rx-hero-copy">' . $copy . '</div>' . $card . '</div></section>';
            },
        ));
    }

    /** rx-marquee — a scrolling strip of short phrases. Ported from archive/plugins/content-builder/lib/blocks/rx-marquee.php. */
    private static function registerRxMarquee(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock(
            'rx-marquee',
            FieldSchema::of(
                [
                    ['key' => 'tone', 'type' => 'select', 'label' => 'Tone', 'options' => [['v' => 'surface', 'l' => 'Surface'], ['v' => 'dark', 'l' => 'Dark']]],
                    ['key' => 'items', 'type' => 'repeater', 'label' => 'Phrases', 'itemLabel' => 'Phrase', 'maxItems' => 20, 'item' => [
                        ['key' => 'text', 'type' => 'text', 'label' => 'Text', 'required' => true],
                    ]],
                ],
                ['tone' => 'surface', 'items' => []],
            ),
            static function (array $p, RenderContext $c): string {
                $items = is_array($p['items'] ?? null) ? $p['items'] : [];
                $phrases = [];
                foreach ($items as $it) {
                    $t = trim((string) (is_array($it) ? ($it['text'] ?? '') : ''));
                    if ($t !== '') $phrases[] = $t;
                }
                if (!$phrases) return '';
                $tone = self::choice($p['tone'] ?? 'surface', ['surface', 'dark'], 'surface');
                $track = '';
                for ($r = 0; $r < 2; $r++) {
                    foreach ($phrases as $ph) $track .= '<span class="rx-marquee-item">' . \e($ph) . '</span>';
                }
                return '<div class="rx rx-marquee rx-mq-' . \e($tone) . '"><div class="rx-marquee-track">' . $track . '</div></div>';
            },
        ));
    }

    /**
     * rx-menu — a menu grid where each dish is a repeater row (tag, name,
     * price, text, photo). Ported from
     * archive/plugins/content-builder/lib/blocks/rx-menu.php.
     */
    private static function registerRxMenu(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock(
            'rx-menu',
            FieldSchema::of(
                [
                    ['key' => 'tone', 'type' => 'select', 'label' => 'Tone', 'options' => [['v' => 'surface', 'l' => 'Surface'], ['v' => 'dark', 'l' => 'Dark']]],
                    ['key' => 'cols', 'type' => 'select', 'label' => 'Columns', 'options' => [['v' => '2', 'l' => '2'], ['v' => '3', 'l' => '3']]],
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                    ['key' => 'intro', 'type' => 'textarea', 'label' => 'Intro'],
                    ['key' => 'items', 'type' => 'repeater', 'label' => 'Dishes', 'itemLabel' => 'Dish', 'maxItems' => 24, 'item' => [
                        ['key' => 'media', 'type' => 'media', 'label' => 'Photo (optional)'],
                        ['key' => 'tag', 'type' => 'text', 'label' => 'Tag (optional, e.g. "Chef\'s pick")'],
                        ['key' => 'name', 'type' => 'text', 'label' => 'Dish name', 'required' => true],
                        ['key' => 'price', 'type' => 'text', 'label' => 'Price'],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Description'],
                    ]],
                ],
                ['tone' => 'surface', 'cols' => '3', 'eyebrow' => '', 'heading' => 'From the kitchen', 'intro' => '', 'items' => []],
            ),
            static function (array $p, RenderContext $c): string {
                $tone = self::choice($p['tone'] ?? 'surface', ['surface', 'dark'], 'surface');
                $cols = self::choice($p['cols'] ?? '3', ['2', '3'], '3');
                $dishes = is_array($p['items'] ?? null) ? $p['items'] : [];
                $cards = '';
                foreach ($dishes as $d) {
                    if (!is_array($d)) continue;
                    $media = is_array($d['media'] ?? null) ? $d['media'] : null;
                    $img = self::resolveMediaUrl($media);
                    $tag = (string) ($d['tag'] ?? '');
                    $name = (string) ($d['name'] ?? '');
                    $price = (string) ($d['price'] ?? '');
                    $text = (string) ($d['text'] ?? '');
                    $cards .= '<article class="rx-dish"><div class="rx-dish-pic"' . ($img !== '' ? ' style="background-image:url(\'' . \e($img) . '\')"' : '') . '>'
                        . ($tag !== '' ? '<span class="rx-dish-tag">' . \e($tag) . '</span>' : '')
                        . '</div><div class="rx-dish-body"><div class="rx-dish-row"><h3>' . \e($name) . '</h3>'
                        . ($price !== '' ? '<span class="rx-dish-price">' . \e($price) . '</span>' : '') . '</div>'
                        . ($text !== '' ? '<p>' . \e($text) . '</p>' : '') . '</div></article>';
                }
                $head = '<div class="rx-head rx-head-center">';
                if (($p['eyebrow'] ?? '') !== '') $head .= '<span class="rx-eyebrow">' . \e((string) $p['eyebrow']) . '</span>';
                if (($p['heading'] ?? '') !== '') $head .= '<h2 class="rx-h2">' . \e((string) $p['heading']) . '</h2>';
                if (($p['intro'] ?? '') !== '') $head .= '<p class="rx-head-sub">' . \e((string) $p['intro']) . '</p>';
                $head .= '</div>';
                return '<section class="rx rx-menu rx-tone-' . $tone . '"><div class="rx-inner">' . $head
                    . '<div class="rx-menu-grid rx-cols-' . $cols . '">' . $cards . '</div></div></section>';
            },
        ));
    }

    /**
     * rx-reviews — testimonial cards, each a repeater row (rating, quote,
     * name, meta, avatar). Ported from
     * archive/plugins/content-builder/lib/blocks/rx-reviews.php.
     */
    private static function registerRxReviews(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock(
            'rx-reviews',
            FieldSchema::of(
                [
                    ['key' => 'tone', 'type' => 'select', 'label' => 'Tone', 'options' => [['v' => 'surface', 'l' => 'Surface'], ['v' => 'dark', 'l' => 'Dark']]],
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                    ['key' => 'items', 'type' => 'repeater', 'label' => 'Reviews', 'itemLabel' => 'Review', 'maxItems' => 24, 'item' => [
                        ['key' => 'avatar', 'type' => 'media', 'label' => 'Avatar (optional)'],
                        ['key' => 'rating', 'type' => 'select', 'label' => 'Rating', 'options' => [
                            ['v' => '5', 'l' => '★★★★★'], ['v' => '4', 'l' => '★★★★☆'], ['v' => '3', 'l' => '★★★☆☆'], ['v' => '2', 'l' => '★★☆☆☆'], ['v' => '1', 'l' => '★☆☆☆☆'],
                        ]],
                        ['key' => 'quote', 'type' => 'textarea', 'label' => 'Quote', 'required' => true],
                        ['key' => 'name', 'type' => 'text', 'label' => 'Name'],
                        ['key' => 'meta', 'type' => 'text', 'label' => 'Meta (optional, e.g. date or location)'],
                    ]],
                ],
                ['tone' => 'surface', 'eyebrow' => '', 'heading' => 'What guests say', 'items' => []],
            ),
            static function (array $p, RenderContext $c): string {
                $tone = self::choice($p['tone'] ?? 'surface', ['surface', 'dark'], 'surface');
                $revs = is_array($p['items'] ?? null) ? $p['items'] : [];
                $cards = '';
                foreach ($revs as $r) {
                    if (!is_array($r)) continue;
                    $n = max(1, min(5, (int) ($r['rating'] ?? 5)));
                    $name = trim((string) ($r['name'] ?? ''));
                    $meta = (string) ($r['meta'] ?? '');
                    $quote = (string) ($r['quote'] ?? '');
                    $avatar = is_array($r['avatar'] ?? null) ? $r['avatar'] : null;
                    $avatarUrl = self::resolveMediaUrl($avatar);
                    $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '★';
                    $cards .= '<blockquote class="rx-review"><span class="rx-stars">' . str_repeat('★', $n) . str_repeat('☆', 5 - $n) . '</span>'
                        . ($quote !== '' ? '<p>' . \e($quote) . '</p>' : '')
                        . '<footer class="rx-review-by"><span class="rx-avatar"' . ($avatarUrl !== '' ? ' style="background-image:url(\'' . \e($avatarUrl) . '\')"' : '') . '>' . ($avatarUrl === '' ? \e($initial) : '') . '</span>'
                        . '<span>' . ($name !== '' ? '<b>' . \e($name) . '</b>' : '') . ($meta !== '' ? '<small>' . \e($meta) . '</small>' : '') . '</span></footer></blockquote>';
                }
                $head = '<div class="rx-head rx-head-center">';
                if (($p['eyebrow'] ?? '') !== '') $head .= '<span class="rx-eyebrow">' . \e((string) $p['eyebrow']) . '</span>';
                if (($p['heading'] ?? '') !== '') $head .= '<h2 class="rx-h2">' . \e((string) $p['heading']) . '</h2>';
                $head .= '</div>';
                return '<section class="rx rx-reviews rx-tone-' . $tone . '"><div class="rx-inner">' . $head
                    . '<div class="rx-reviews-grid">' . $cards . '</div></div></section>';
            },
        ));
    }

    /**
     * rx-story — a split "our story" section: a stat medallion or photo, copy,
     * and a repeater of small feature callouts. Ported from
     * archive/plugins/content-builder/lib/blocks/rx-story.php.
     */
    private static function registerRxStory(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock(
            'rx-story',
            FieldSchema::of(
                [
                    ['key' => 'tone', 'type' => 'select', 'label' => 'Tone', 'options' => [['v' => 'light', 'l' => 'Light'], ['v' => 'dark', 'l' => 'Dark']]],
                    ['key' => 'media', 'type' => 'media', 'label' => 'Photo (optional — a stat medallion shows if empty)'],
                    ['key' => 'statBig', 'type' => 'text', 'label' => 'Stat (big number, optional)'],
                    ['key' => 'statLabel', 'type' => 'text', 'label' => 'Stat label'],
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                    ['key' => 'body', 'type' => 'textarea', 'label' => 'Body'],
                    ['key' => 'items', 'type' => 'repeater', 'label' => 'Features', 'itemLabel' => 'Feature', 'maxItems' => 8, 'item' => [
                        ['key' => 'icon', 'type' => 'text', 'label' => 'Icon (emoji or short text)'],
                        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
                        ['key' => 'text', 'type' => 'text', 'label' => 'Text'],
                    ]],
                ],
                ['tone' => 'light', 'statBig' => '', 'statLabel' => '', 'eyebrow' => '', 'heading' => 'Our story', 'body' => '', 'items' => []],
            ),
            static function (array $p, RenderContext $c): string {
                $tone = self::choice($p['tone'] ?? 'light', ['light', 'dark'], 'light');
                $media = is_array($p['media'] ?? null) ? $p['media'] : null;
                $img = self::resolveMediaUrl($media);
                $statBig = (string) ($p['statBig'] ?? '');
                $statLabel = (string) ($p['statLabel'] ?? '');

                $visual = '<div class="rx-story-visual"' . ($img !== '' ? ' style="background-image:url(\'' . \e($img) . '\')"' : '') . '>';
                if ($img === '') {
                    $visual .= '<div class="rx-story-medallion"><div class="rx-stat-big">' . \e($statBig) . '</div><small>' . \e($statLabel) . '</small></div>';
                } elseif ($statBig !== '') {
                    $visual .= '<div class="rx-story-chip"><b>' . \e($statBig) . '</b> <span>' . \e($statLabel) . '</span></div>';
                }
                $visual .= '</div>';

                $copy = '';
                if (($p['eyebrow'] ?? '') !== '') $copy .= '<span class="rx-eyebrow">' . \e((string) $p['eyebrow']) . '</span>';
                if (($p['heading'] ?? '') !== '') $copy .= '<h2 class="rx-h2">' . \e((string) $p['heading']) . '</h2>';
                if (($p['body'] ?? '') !== '') $copy .= '<div class="rx-prose">' . nl2br(\e((string) $p['body'])) . '</div>';

                $feats = is_array($p['items'] ?? null) ? $p['items'] : [];
                if ($feats) {
                    $grid = '';
                    foreach ($feats as $f) {
                        if (!is_array($f)) continue;
                        $icon = (string) ($f['icon'] ?? '');
                        $title = (string) ($f['title'] ?? '');
                        $text = (string) ($f['text'] ?? '');
                        $grid .= '<div class="rx-feat">'
                            . ($icon !== '' ? '<span class="rx-feat-ic">' . \e($icon) . '</span>' : '')
                            . ($title !== '' ? '<b>' . \e($title) . '</b>' : '')
                            . ($text !== '' ? '<span class="rx-feat-text">' . \e($text) . '</span>' : '')
                            . '</div>';
                    }
                    $copy .= '<div class="rx-feat-grid">' . $grid . '</div>';
                }

                return '<section class="rx rx-story rx-tone-' . $tone . '"><div class="rx-story-inner">' . $visual . '<div class="rx-story-copy">' . $copy . '</div></div></section>';
            },
        ));
    }

    /**
     * rx-visit — a closing CTA with address/phone and an editable hours table
     * (a repeater of label/value rows, each optionally highlighted as "open
     * now"). Ported from archive/plugins/content-builder/lib/blocks/rx-visit.php.
     */
    private static function registerRxVisit(InMemoryBlockRegistry $registry): void
    {
        $registry->register(new CallbackBlock(
            'rx-visit',
            FieldSchema::of(
                [
                    ['key' => 'tone', 'type' => 'select', 'label' => 'Tone', 'options' => [['v' => 'surface', 'l' => 'Surface'], ['v' => 'dark', 'l' => 'Dark']]],
                    ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Eyebrow'],
                    ['key' => 'heading', 'type' => 'text', 'label' => 'Heading'],
                    ['key' => 'text', 'type' => 'textarea', 'label' => 'Text'],
                    ['key' => 'btnText', 'type' => 'text', 'label' => 'Button label'],
                    ['key' => 'btnHref', 'type' => 'url', 'label' => 'Button link'],
                    ['key' => 'btn2Text', 'type' => 'text', 'label' => 'Second button label (optional)'],
                    ['key' => 'btn2Href', 'type' => 'url', 'label' => 'Second button link'],
                    ['key' => 'address', 'type' => 'text', 'label' => 'Address (optional)'],
                    ['key' => 'phone', 'type' => 'text', 'label' => 'Phone (optional)'],
                    ['key' => 'items', 'type' => 'repeater', 'label' => 'Hours', 'itemLabel' => 'Row', 'maxItems' => 10, 'item' => [
                        ['key' => 'label', 'type' => 'text', 'label' => 'Label (e.g. "Mon–Fri")', 'required' => true],
                        ['key' => 'value', 'type' => 'text', 'label' => 'Value (e.g. "11am – 10pm")'],
                        ['key' => 'highlight', 'type' => 'select', 'label' => 'Highlight as open now?', 'options' => [['v' => '', 'l' => 'No'], ['v' => 'true', 'l' => 'Yes']]],
                    ]],
                ],
                ['tone' => 'surface', 'eyebrow' => '', 'heading' => 'Come visit', 'text' => '', 'btnText' => 'Get directions', 'btnHref' => '#', 'btn2Text' => '', 'btn2Href' => '', 'address' => '', 'phone' => '', 'items' => []],
            ),
            static function (array $p, RenderContext $c): string {
                $tone = self::choice($p['tone'] ?? 'surface', ['surface', 'dark'], 'surface');
                $address = (string) ($p['address'] ?? '');
                $phone = (string) ($p['phone'] ?? '');

                $copy = '';
                if (($p['eyebrow'] ?? '') !== '') $copy .= '<span class="rx-eyebrow">' . \e((string) $p['eyebrow']) . '</span>';
                if (($p['heading'] ?? '') !== '') $copy .= '<h2 class="rx-h2">' . \e((string) $p['heading']) . '</h2>';
                if (($p['text'] ?? '') !== '') $copy .= '<p class="rx-head-sub">' . nl2br(\e((string) $p['text'])) . '</p>';
                $actions = '';
                if (($p['btnText'] ?? '') !== '') $actions .= '<a class="rx-btn rx-btn-primary" href="' . \e(\slate_safe_url((string) ($p['btnHref'] ?? '#'))) . '">' . \e((string) $p['btnText']) . '</a>';
                if (($p['btn2Text'] ?? '') !== '') $actions .= '<a class="rx-btn rx-btn-ghost" href="' . \e(\slate_safe_url((string) ($p['btn2Href'] ?? '#'))) . '">' . \e((string) $p['btn2Text']) . '</a>';
                $copy .= '<div class="rx-actions">' . $actions . '</div>';
                if ($address !== '' || $phone !== '') {
                    $copy .= '<p class="rx-visit-meta">';
                    if ($address !== '') $copy .= '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> ' . \e($address);
                    if ($address !== '' && $phone !== '') $copy .= ' &nbsp;·&nbsp; ';
                    if ($phone !== '') $copy .= '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M6.6 3.6L9.4 8l-2 2.2a12.8 12.8 0 0 0 6.4 6.4l2.2-2 4.4 2.8v3a1.6 1.6 0 0 1-1.75 1.6A17.6 17.6 0 0 1 3 5.35 1.6 1.6 0 0 1 4.6 3.6z"/></svg> ' . \e($phone);
                    $copy .= '</p>';
                }

                $hours = is_array($p['items'] ?? null) ? $p['items'] : [];
                $hoursHtml = '';
                if ($hours) {
                    $rows = '';
                    foreach ($hours as $h) {
                        if (!is_array($h)) continue;
                        $hi = !empty($h['highlight']) && $h['highlight'] !== 'false';
                        $rows .= '<li' . ($hi ? ' class="rx-hours-open"' : '') . '><span>' . \e((string) ($h['label'] ?? '')) . '</span><b>' . \e((string) ($h['value'] ?? '')) . '</b></li>';
                    }
                    $hoursHtml = '<ul class="rx-hours">' . $rows . '</ul>';
                }

                return '<section class="rx rx-visit rx-tone-' . $tone . '"><div class="rx-inner"><div class="rx-visit-card"><div class="rx-visit-copy">' . $copy . '</div>' . $hoursHtml . '</div></div></section>';
            },
        ));
    }

    private static function renderNestedBlocks(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            $type = (string)($block['type'] ?? '');
            $p = is_array($block['props'] ?? null) ? $block['props'] : [];
            if ($type === 'heading') {
                $level = max(1, min(6, (int)($p['level'] ?? 2)));
                $out .= '<h' . $level . ' class="cb-heading">' . \e((string)($p['text'] ?? 'Heading')) . '</h' . $level . '>';
            } elseif ($type === 'paragraph') {
                $out .= '<p class="cb-paragraph">' . nl2br(\e((string)($p['text'] ?? 'Paragraph text'))) . '</p>';
            } elseif ($type === 'button') {
                $out .= '<a class="cb-btn cb-btn-primary" href="' . \e(\slate_safe_url((string)($p['href'] ?? '#'))) . '">' . \e((string)($p['text'] ?? 'Button')) . '</a>';
            } elseif ($type === 'container' || $type === 'columns') {
                $out .= '<div class="cb-nested-widget">' . self::renderNestedBlocks($type === 'container' ? (array)($p['children'] ?? []) : self::flattenColumns($p)) . '</div>';
            } else {
                $label = ucfirst(str_replace('-', ' ', $type ?: 'Widget'));
                $out .= '<div class="cb-nested-widget"><strong>' . \e($label) . '</strong></div>';
            }
        }
        return $out;
    }

    private static function flattenColumns(array $props): array
    {
        $out = [];
        foreach ((array)($props['cols'] ?? []) as $col) foreach ((array)($col['blocks'] ?? []) as $block) $out[] = $block;
        return $out;
    }

    /** @param array<string,mixed>|null $media */
    private static function resolveMediaUrl(?array $media): string
    {
        $key = trim((string) ($media['key'] ?? ''));
        if ($key === '') {
            return '';
        }
        // Logical key -> a path under uploads/, never an arbitrary host or an
        // escape from it (Phase 2 spec §8: "must not permit path traversal").
        $key = ltrim($key, '/');
        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0")) {
            return '';
        }
        $base = defined('SLATE_URL') ? \SLATE_URL : '';
        return rtrim($base, '/') . '/uploads/' . $key;
    }

    /** @param array<string,mixed> $props */
    private static function renderHero(array $props): string
    {
        $layout = (string) ($props['layout'] ?? 'banner');
        $layout = in_array($layout, ['split', 'banner'], true) ? $layout : 'banner';
        $overlay = (string) ($props['overlay'] ?? 'medium');
        $overlay = in_array($overlay, ['light', 'medium', 'dark'], true) ? $overlay : 'medium';
        $height = (string) ($props['height'] ?? 'normal');
        $height = in_array($height, ['short', 'normal', 'tall'], true) ? $height : 'normal';
        $mediaSide = (($props['mediaSide'] ?? 'left') === 'right') ? 'right' : 'left';
        $pad = (string) ($props['pad'] ?? 'normal');
        $pad = in_array($pad, ['compact', 'normal', 'spacious'], true) ? $pad : 'normal';

        $media = is_array($props['media'] ?? null) ? $props['media'] : null;
        $imageUrl = self::resolveMediaUrl($media);
        $hasImg = $imageUrl !== '';

        $eyebrow = (string) ($props['eyebrow'] ?? '');
        $heading = (string) ($props['heading'] ?? '');
        $subheading = (string) ($props['subheading'] ?? '');
        $btnText = (string) ($props['btnText'] ?? '');
        $btnHref = (string) ($props['btnHref'] ?? '#');
        $btn2Text = (string) ($props['btn2Text'] ?? '');
        $btn2Href = (string) ($props['btn2Href'] ?? '#');

        $actions = '<div class="cb-hero-actions">';
        if ($btnText !== '') {
            $actions .= sprintf('<a class="cb-btn cb-btn-primary" href="%s">%s</a>', \e(\slate_safe_url($btnHref)), \e($btnText));
        }
        if ($btn2Text !== '') {
            $extra = ($hasImg && $layout === 'banner') ? ' style="color:#fff;box-shadow:inset 0 0 0 1.5px #fff"' : '';
            $actions .= sprintf('<a class="cb-btn cb-btn-outline" href="%s"%s>%s</a>', \e(\slate_safe_url($btn2Href)), $extra, \e($btn2Text));
        }
        $actions .= '</div>';

        $eyebrowHtml = $eyebrow !== '' ? '<span class="cb-eyebrow">' . \e($eyebrow) . '</span>' : '';
        $headingHtml = $heading !== '' ? '<h1 class="cb-hero-title">' . \e($heading) . '</h1>' : '';
        $subHtml = $subheading !== '' ? '<p class="cb-hero-sub">' . \e($subheading) . '</p>' : '';

        if ($layout === 'split' && $hasImg) {
            $cls = 'cb-hero cb-hero-split cb-hero-media-' . $mediaSide;
            return sprintf(
                '<section class="cb-pad-%s %s"><div class="cb-hero-inner">'
                    . '<div class="cb-hero-media"><img src="%s" alt="%s"></div>'
                    . '<div class="cb-hero-body">%s%s%s%s</div>'
                    . '</div></section>',
                \e($pad), \e($cls), \e(\slate_safe_url($imageUrl)), \e($heading),
                $eyebrowHtml, $headingHtml, $subHtml, $actions,
            );
        }

        $cls = 'cb-hero cb-hero-banner cb-hero-h-' . $height;
        $style = '';
        if ($hasImg) {
            $cls .= ' cb-overlay-' . $overlay;
            $style = ' style="background-image:url(\'' . \e(\slate_safe_url($imageUrl)) . '\')"';
        } else {
            $cls .= ' cb-hero-plain';
        }

        return sprintf(
            '<section class="cb-pad-%s %s"%s><div class="cb-hero-inner">%s%s%s%s</div></section>',
            \e($pad), \e($cls), $style, $eyebrowHtml, $headingHtml, $subHtml, $actions,
        );
    }
}
