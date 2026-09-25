<?php
/**
 * Slate — BlockStyleRenderer: applies a block's `style` (DocumentValidator's
 * STYLE_KEYS — see DocumentValidator::validateStyle()) to its rendered HTML.
 *
 * PageRenderer::renderBlock() previously called `\Renderer::applyStyle()` —
 * a class that only ever existed in archive/plugins/content-builder/lib/
 * Renderer.php. `class_exists('\Renderer')` was always false in any live
 * environment (that plugin directory doesn't exist under plugins/), so every
 * style customization made through the editor's Style panel was validated and
 * saved successfully but then silently never rendered — on the editor's own
 * initial page load, on Preview, and on the published public page alike (only
 * the editor canvas's *own* client-side JS, applyBlockStyleToEl(), ever showed
 * it, which is why it looked like it worked while actually editing).
 *
 * This is a live, from-scratch reimplementation — not a straight port of the
 * archived class — because it additionally understands the
 * `style.responsive.{tablet,mobile}` overrides the Style panel has written
 * and DocumentValidator has accepted since this session's earlier
 * validateStyle() fix; the archived Renderer predates that feature entirely
 * and would have silently dropped it.
 *
 * A responsive override can only take effect at a given viewport width via
 * real CSS (an inline style="" attribute cannot vary by breakpoint), so a
 * block that has one gets a per-instance id and a small scoped <style> block
 * with the matching @media rules, appended right after its wrapper — plain
 * base styles need neither and stay a single inline style="" attribute.
 *
 * Pure by design (Presentation layer): no DB, no globals beyond the
 * pre-existing slate_safe_url()/e() helpers already used throughout
 * LegacyBlockBridge for exactly this "trust but re-check at render time"
 * reason.
 */

declare(strict_types=1);

namespace Slate\Presentation\Rendering;

final class BlockStyleRenderer
{
    /** Per-request instance counter for responsive-override element ids. */
    private int $counter = 0;

    /** @param array<string,mixed> $style */
    public function apply(string $html, array $style): string
    {
        if ($style === []) {
            return $html;
        }

        $classes = ['cb-block-wrapper', 've-block-style'];
        if (!empty($style['hideDesktop'])) $classes[] = 've-hide-desktop';
        if (!empty($style['hideTablet']))  $classes[] = 've-hide-tablet';
        if (!empty($style['hideMobile']))  $classes[] = 've-hide-mobile';
        if (!empty($style['customClass']) && is_string($style['customClass'])) {
            // DocumentValidator's `class_name` rule already restricts this to a
            // safe charset at save time; \e() below still HTML-escapes it into
            // the class attribute regardless, so this holds even for an older
            // document saved before that rule existed.
            $classes[] = $style['customClass'];
        }

        $baseCss = self::declarationsFor($style);

        $responsive   = is_array($style['responsive'] ?? null) ? $style['responsive'] : [];
        $tabletCss  = is_array($responsive['tablet'] ?? null) ? self::declarationsFor($responsive['tablet']) : '';
        $mobileCss  = is_array($responsive['mobile'] ?? null) ? self::declarationsFor($responsive['mobile']) : '';

        // A user-chosen CSS ID (Advanced tab) wins over the auto-generated one
        // a responsive override would otherwise need — one real id either way,
        // never both on the same element.
        $id = !empty($style['cssId']) && is_string($style['cssId']) ? $style['cssId'] : '';

        $idAttr = '';
        $styleTag = '';
        if ($tabletCss !== '' || $mobileCss !== '') {
            if ($id === '') {
                $this->counter++;
                $id = 'cb-blk-' . $this->counter . '-' . substr(md5($html . $this->counter), 0, 6);
            }
            $idAttr = ' id="' . \e($id) . '"';
            $styleTag = '<style>';
            // Matches the breakpoints the editor's own device switcher/canvas
            // frame widths use (768px tablet frame, 390px mobile frame — see
            // admin/editor.php's DEVICE_LABELS) so what an author sees while
            // choosing "Tablet"/"Mobile" in the Style panel is what a real
            // tablet/phone visitor gets.
            if ($tabletCss !== '') $styleTag .= '@media (max-width:1024px){#' . $id . '{' . $tabletCss . '}}';
            if ($mobileCss !== '') $styleTag .= '@media (max-width:640px){#' . $id . '{' . $mobileCss . '}}';
            $styleTag .= '</style>';
        } elseif ($id !== '') {
            $idAttr = ' id="' . \e($id) . '"';
        }

        $classAttr = 'class="' . \e(implode(' ', $classes)) . '"';
        $styleAttr = $baseCss !== '' ? ' style="' . \e($baseCss) . '"' : '';

        return '<div ' . $classAttr . $idAttr . $styleAttr . '>' . $html . '</div>' . $styleTag;
    }

    /**
     * The CSS declarations (no selector, no braces) for one style layer —
     * shared between the base style and each responsive override, since both
     * carry the same key set (DocumentValidator::validateStyle()'s STYLE_KEYS).
     *
     * @param array<string,mixed> $style
     */
    private static function declarationsFor(array $style): string
    {
        $css = '';

        if (!empty($style['bgColor']) && is_string($style['bgColor'])) {
            $op  = isset($style['bgOpacity']) ? max(0, min(100, (int) $style['bgOpacity'])) / 100 : 1.0;
            $hex = ltrim($style['bgColor'], '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (strlen($hex) === 6 && ctype_xdigit($hex)) {
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                $css .= "background-color:rgba($r,$g,$b,$op);";
            }
        }
        if (!empty($style['bgImage']) && is_string($style['bgImage'])) {
            $bgUrl = \slate_safe_url($style['bgImage']);
            if ($bgUrl !== '#') {
                if (!empty($style['bgOverlay']) && is_string($style['bgOverlay'])) {
                    $overlay = $style['bgOverlay'];
                    $css .= "background-image:linear-gradient($overlay,$overlay),url('" . addslashes($bgUrl) . "');";
                } else {
                    $css .= "background-image:url('" . addslashes($bgUrl) . "');";
                }
                $css .= 'background-size:cover;background-position:center;';
            }
        }
        if (!empty($style['textColor']) && is_string($style['textColor'])) {
            $css .= 'color:' . self::cssValue($style['textColor']) . ';';
        }
        if (!empty($style['textAlign']) && in_array($style['textAlign'], ['left', 'center', 'right', 'justify'], true)) {
            $css .= 'text-align:' . $style['textAlign'] . ';';
        }
        foreach (['paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight', 'marginTop', 'marginBottom', 'borderRadius'] as $key) {
            if (isset($style[$key]) && $style[$key] !== '' && is_numeric($style[$key])) {
                $prop = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $key));
                $css .= $prop . ':' . (int) $style[$key] . 'px;';
            }
        }
        if (!empty($style['maxWidth']) && is_string($style['maxWidth'])) {
            $mw = $style['maxWidth'];
            $css .= 'max-width:' . self::cssValue($mw) . (is_numeric($mw) ? 'px' : '') . ';';
        }
        if (isset($style['zIndex']) && $style['zIndex'] !== '' && is_numeric($style['zIndex'])) {
            // z-index is a no-op on a plain block-level element unless it's
            // positioned — matches Elementor's own behavior of implying
            // position:relative the moment a z-index is set.
            $css .= 'position:relative;z-index:' . (int) $style['zIndex'] . ';';
        }

        return $css;
    }

    /**
     * A value already restricted by DocumentValidator (hex color / CSS-length
     * pattern) still gets neutralized here against ; and { } — belt-and-braces
     * against building a CSS declaration from a value some other, older
     * validation path let through unchecked.
     */
    private static function cssValue(string $v): string
    {
        return (string) preg_replace('/[;{}]/', '', $v);
    }
}
