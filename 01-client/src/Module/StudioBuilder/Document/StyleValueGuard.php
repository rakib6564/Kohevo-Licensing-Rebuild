<?php
/**
 * Kohevo Studio (studio-builder) — typed CSS value guard.
 *
 * Every free-form style value an author can set (a length, a colour, a
 * gradient, a shadow, a font stack) is checked here against a closed grammar
 * before it is stored, and checked again before it is written into a page.
 * The grammar is deliberately smaller than CSS: a value that is not one of
 * these shapes is refused rather than sanitised, so nothing user-typed ever
 * reaches a stylesheet unless it is a plain length, colour, gradient, shadow
 * or font stack.
 *
 * Refused everywhere: `url(`, `image-set(`, `var(`, `env(`, `attr(`,
 * `@import`, comments, backslash escapes, and any function not named below.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

final class StyleValueGuard
{
    private const UNITS = 'px|rem|em|%|vw|vh|vmin|vmax|ch|ex|pt|cm|mm|in';

    private const LENGTH_KEYWORDS = ['auto', 'none', 'fit-content', 'min-content', 'max-content', 'inherit', 'initial', 'unset'];

    private const COLOR_FUNCTIONS    = ['rgb', 'rgba', 'hsl', 'hsla'];
    private const GRADIENT_FUNCTIONS = ['linear-gradient', 'radial-gradient', 'conic-gradient', 'rgb', 'rgba', 'hsl', 'hsla'];
    private const MATH_FUNCTIONS     = ['calc', 'clamp', 'min', 'max'];

    private const MAX_LENGTH = 300;

    /** A bare token reference (`space.4`, `text.primary`, `surface.dark`). */
    public static function isToken(string $value): bool
    {
        return preg_match(CanonicalDocumentSchema::TOKEN_REF_PATTERN, $value) === 1;
    }

    /** A single length: `12px`, `1.5rem`, `-4px`, `0`, `100%`, `auto`, or a calc/clamp/min/max expression. */
    public static function isLength(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) && abs((float) $value) <= 100000;
        }
        if (!is_string($value) || !self::plain($value)) {
            return false;
        }
        $value = trim($value);
        if (in_array(strtolower($value), self::LENGTH_KEYWORDS, true)) {
            return true;
        }
        if (preg_match('/^-?(\d+\.?\d*|\.\d+)(' . self::UNITS . ')?$/i', $value) === 1) {
            return true;
        }
        return self::isMath($value);
    }

    /** One to four space-separated lengths (padding/margin/radius shorthand). */
    public static function isLengthList(mixed $value, int $max = 4): bool
    {
        if (!is_string($value)) {
            return self::isLength($value);
        }
        if (!self::plain($value) || str_contains($value, '(')) {
            return self::isLength($value);
        }
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        if ($parts === [] || count($parts) > $max) {
            return false;
        }
        foreach ($parts as $part) {
            if (!self::isLength($part)) {
                return false;
            }
        }
        return true;
    }

    /** A length or a spacing/size token. */
    public static function isLengthOrToken(mixed $value): bool
    {
        if (is_string($value) && self::isToken($value)) {
            return true;
        }
        return self::isLengthList($value);
    }

    /** A unit-less number (line-height `1.5`) or a length. */
    public static function isLineHeight(mixed $value): bool
    {
        if (is_string($value) && strtolower(trim($value)) === 'normal') {
            return true;
        }
        return self::isLength($value);
    }

    public static function isLetterSpacing(mixed $value): bool
    {
        if (is_string($value) && strtolower(trim($value)) === 'normal') {
            return true;
        }
        return self::isLength($value);
    }

    public static function isBorderWidth(mixed $value): bool
    {
        if (is_string($value) && in_array(strtolower(trim($value)), ['thin', 'medium', 'thick'], true)) {
            return true;
        }
        return self::isLengthList($value);
    }

    /** `#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`, `rgb[a]()`/`hsl[a]()`, a colour keyword, or a colour token. */
    public static function isColor(mixed $value): bool
    {
        if (!is_string($value) || !self::plain($value)) {
            return false;
        }
        $value = trim($value);
        if (self::isToken($value)) {
            return true;
        }
        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1) {
            return true;
        }
        if (preg_match('/^[a-z]{3,24}$/i', $value) === 1) {
            return true; // red, transparent, currentcolor, rebeccapurple, ...
        }
        return self::functionalColor($value);
    }

    /** `linear|radial|conic-gradient(...)` built only from numbers, hex/rgb/hsl colours, keywords and percentages. */
    public static function isGradient(mixed $value): bool
    {
        if (!is_string($value) || !self::plain($value)) {
            return false;
        }
        $value = trim($value);
        if (preg_match('/^(linear|radial|conic)-gradient\(/i', $value) !== 1 || !str_ends_with($value, ')')) {
            return false;
        }
        return self::onlyFunctions($value, self::GRADIENT_FUNCTIONS)
            && preg_match('/^[a-z0-9#.,%() \-+]+$/i', $value) === 1
            && self::balanced($value);
    }

    /** Up to four comma-separated `[inset] x y [blur [spread]] colour` layers. */
    public static function isShadow(mixed $value): bool
    {
        if (!is_string($value) || !self::plain($value)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (!self::onlyFunctions($value, self::COLOR_FUNCTIONS)
            || preg_match('/^[a-z0-9#.,%() \-+]+$/i', $value) !== 1
            || !self::balanced($value)) {
            return false;
        }
        // Split on commas that are outside parentheses.
        $layers = [];
        $depth  = 0;
        $buf    = '';
        foreach (str_split($value) as $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $layers[] = $buf;
                $buf      = '';
                continue;
            }
            $buf .= $ch;
        }
        $layers[] = $buf;
        return count($layers) <= 4 && !in_array('', array_map('trim', $layers), true);
    }

    /** A font stack: families made of letters, digits, spaces, hyphens and underscores, optionally quoted, comma separated. */
    public static function isFontFamily(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 200) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (self::isToken($value)) {
            return true;
        }
        return preg_match('/^(?:[A-Za-z0-9 _-]+|\'[A-Za-z0-9 _-]+\'|"[A-Za-z0-9 _-]+")(?:\s*,\s*(?:[A-Za-z0-9 _-]+|\'[A-Za-z0-9 _-]+\'|"[A-Za-z0-9 _-]+"))*$/', $value) === 1;
    }

    /** A background colour field: colour, colour token, or surface token. */
    public static function isBackgroundColor(mixed $value): bool
    {
        return self::isColor($value);
    }

    /** Free text a style field may carry that must stay inert (presets/tokens only). */
    public static function isIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $value) === 1;
    }

    /**
     * Defence in depth for any value that still passes through a looser check:
     * true only when none of the CSS escape hatches appear.
     */
    public static function isInert(string $value): bool
    {
        return self::plain($value);
    }

    /**
     * Every free-form value in a block `style` object that this guard refuses,
     * as `path => value`. Read-only helper for the stored-document audit
     * (`bin/audit-style-values.php`); the validator and renderer apply the
     * same checks field by field.
     *
     * @param array<string, mixed> $style
     * @return array<string, string>
     */
    public static function styleIssues(array $style): array
    {
        $bad   = [];
        $check = static function (string $path, mixed $value, callable $ok) use (&$bad): void {
            if ($value !== null && !$ok($value)) {
                $bad[$path] = is_scalar($value) ? (string) $value : (string) json_encode($value);
            }
        };
        $typo = is_array($style['typography'] ?? null) ? $style['typography'] : [];
        $check('typography.size', $typo['size'] ?? null, static fn ($v) => !is_string($v) || self::isLength($v));
        $check('typography.line_height', $typo['line_height'] ?? null, [self::class, 'isLineHeight']);
        $check('typography.letter_spacing', $typo['letter_spacing'] ?? null, [self::class, 'isLetterSpacing']);
        $check('typography.color', $typo['color'] ?? null, [self::class, 'isColor']);
        $check('typography.font_family', $typo['font_family'] ?? null, [self::class, 'isFontFamily']);
        $check('color', $style['color'] ?? null, [self::class, 'isColor']);
        $bg = $style['background'] ?? null;
        if (is_string($bg)) {
            $check('background', $bg, static fn ($v) => self::isColor($v) || self::isGradient($v));
        } elseif (is_array($bg)) {
            $check('background.color', $bg['color'] ?? null, [self::class, 'isColor']);
            $check('background.gradient', $bg['gradient'] ?? null, [self::class, 'isGradient']);
        }
        foreach (is_array($style['spacing'] ?? null) ? $style['spacing'] : [] as $k => $v) {
            $check('spacing.' . $k, $v, [self::class, 'isLengthOrToken']);
        }
        $bd = is_array($style['border'] ?? null) ? $style['border'] : [];
        $check('border.width', $bd['width'] ?? null, [self::class, 'isBorderWidth']);
        $check('border.color', $bd['color'] ?? null, [self::class, 'isColor']);
        if (isset($bd['radius']) && !in_array((string) $bd['radius'], CanonicalDocumentSchema::ALLOWED_RADIUS_PRESETS, true)) {
            $check('border.radius', $bd['radius'], [self::class, 'isLengthList']);
        }
        if (isset($style['shadow']) && is_string($style['shadow']) && !in_array($style['shadow'], CanonicalDocumentSchema::ALLOWED_SHADOW_PRESETS, true)) {
            $check('shadow', $style['shadow'], [self::class, 'isShadow']);
        }
        foreach (is_array($style['dimensions'] ?? null) ? $style['dimensions'] : [] as $k => $v) {
            $check('dimensions.' . $k, $v, [self::class, 'isLength']);
        }
        return $bad;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** No length overrun, markup, statement separators, escapes, comments or fetching functions. */
    private static function plain(string $value): bool
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }
        if (preg_match('/[<>;{}\\\\@\x00-\x1f]/', $value) === 1) {
            return false;
        }
        if (str_contains($value, '/*') || str_contains($value, '*/')) {
            return false;
        }
        $lower = strtolower($value);
        foreach (['url(', 'image(', 'image-set(', 'src(', 'var(', 'env(', 'attr(', 'expression(', 'javascript:', 'behavior:', '-moz-binding', 'data:', '!important'] as $needle) {
            if (str_contains($lower, $needle)) {
                return false;
            }
        }
        return true;
    }

    private static function isMath(string $value): bool
    {
        if (preg_match('/^(calc|clamp|min|max)\(/i', $value) !== 1 || !str_ends_with($value, ')')) {
            return false;
        }
        return self::onlyFunctions($value, self::MATH_FUNCTIONS)
            && preg_match('/^[a-z0-9.,%()+\-*\/ ]+$/i', $value) === 1
            && self::balanced($value);
    }

    private static function functionalColor(string $value): bool
    {
        if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[0-9.]+(deg|%)?\s*[, ]\s*[0-9.]+%?\s*[, ]\s*[0-9.]+%?(\s*[,\/]\s*[0-9.]+%?)?\s*\)$/i', $value) === 1) {
            return true;
        }
        return false;
    }

    /** Every `name(` in the value must be on the list. */
    private static function onlyFunctions(string $value, array $allowed): bool
    {
        if (preg_match_all('/([a-z-]*)\(/i', $value, $m) === false) {
            return false;
        }
        foreach ($m[1] as $name) {
            // A bare `(` (calc grouping) has an empty name and is fine.
            if ($name !== '' && !in_array(strtolower($name), $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    private static function balanced(string $value): bool
    {
        $depth = 0;
        foreach (str_split($value) as $ch) {
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }
}
