<?php
/**
 * Kohevo Studio (studio-builder) — Output Encoding Helpers.
 *
 * Every authored string reaches HTML through `e()`; every authored URL through
 * `safeUrl()` (re-validated at render time, not just trusted from write-time
 * validation). Self-contained — no dependency on the global `e()` helper — so
 * the renderer runs under the autoloader-only unit harness.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class Html
{
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** A URL that passes Studio's URL allowlist, or null. Callers still escape it. */
    public static function safeUrl(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        $url = trim($url);
        return ($url !== '' && FieldSchema::isSafeUrl($url)) ? $url : null;
    }

    /**
     * Render a normalized `link` field ({label, href, target, rel}) as an anchor,
     * or as plain escaped text when the href no longer passes the allowlist.
     *
     * @param array<string, mixed>|null $link
     */
    public static function link(?array $link, string $class = '', ?string $innerHtml = null): string
    {
        // `$innerHtml` replaces the escaped label: markup the server built itself (a button's icon and label spans).
        if ($link === null) {
            return '';
        }
        $label = (string) ($link['label'] ?? '');
        if (trim($label) === '') {
            return '';
        }
        $href = self::safeUrl($link['href'] ?? null);
        if ($href === null) {
            return '<span' . ($class !== '' ? ' class="' . self::e($class) . '"' : '') . '>' . ($innerHtml ?? self::e($label)) . '</span>';
        }
        $target = ($link['target'] ?? '_self') === '_blank' ? '_blank' : '_self';
        $relParts = [];
        $rel = $link['rel'] ?? null;
        if (is_string($rel) && preg_match('/^[a-zA-Z0-9 _-]{0,64}$/', $rel) === 1) {
            $relParts = array_filter(explode(' ', strtolower($rel)));
        }
        if ($target === '_blank') {
            $relParts[] = 'noopener';
            $relParts[] = 'noreferrer';
        }
        $relParts = array_values(array_unique($relParts));
        sort($relParts);

        return '<a'
            . ($class !== '' ? ' class="' . self::e($class) . '"' : '')
            . ' href="' . self::e($href) . '"'
            . ($target === '_blank' ? ' target="_blank"' : '')
            . ($relParts !== [] ? ' rel="' . self::e(implode(' ', $relParts)) . '"' : '')
            . '>' . ($innerHtml ?? self::e($label)) . '</a>';
    }

    /** The one class a highlight span carries; the only attribute a rich-text `span` may have. */
    public const HIGHLIGHT_CLASS = 'sb-hl';
    /** A literal highlight colour: hex, or rgb/rgba/hsl/hsla with plain numbers — nothing that could close a declaration. Empty is allowed (no custom colour). */
    public const HIGHLIGHT_COLOR_PATTERN = '/^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|(rgb|rgba|hsl|hsla)\(\s*[0-9.%\s,\/]{1,60}\))?$/';
    public const HIGHLIGHT_WEIGHTS = ['normal', 'medium', 'bold'];

    /**
     * Escaped text with the FIRST occurrence of `$highlight` wrapped in a highlight span. An empty or absent
     * needle, or one that is not in the text, leaves the text escaped and unwrapped. The needle is matched as
     * plain text (never as markup) and every part is escaped separately.
     */
    /**
     * `$value` with the first occurrence of `$highlight` wrapped in a span.
     *
     * @param array{color_class?: string, color_var?: string, color?: string, italic?: bool, weight?: string, underline?: bool} $look how the span looks
     *        (all optional; the colour is a theme token's class and `var()`, or a checked literal, never raw author text)
     *
     * What the author set is also written inline on the span, so it wins over a site's own CSS for highlights (a
     * stylesheet rule such as `.sb-body .sb-hl{…}` is more specific than the look classes); what they did not set
     * adds nothing, so the site's default look for a highlight still applies.
     */
    public static function highlighted(string $value, string $highlight, array $look = []): string
    {
        $highlight = trim($highlight);
        $at = $highlight === '' ? false : mb_strpos($value, $highlight, 0, 'UTF-8');
        if ($at === false) {
            return self::e($value);
        }
        $len = mb_strlen($highlight, 'UTF-8');

        $classes = [self::HIGHLIGHT_CLASS];
        if (!empty($look['italic'])) {
            $classes[] = 'sb-hl--italic';
        }
        if (!empty($look['underline'])) {
            $classes[] = 'sb-hl--underline';
        }
        if (in_array($look['weight'] ?? '', self::HIGHLIGHT_WEIGHTS, true)) {
            $classes[] = 'sb-hl--w-' . $look['weight'];
        }
        $custom = (string) ($look['color'] ?? '');
        $declarations = [];
        if ($custom !== '' && preg_match(self::HIGHLIGHT_COLOR_PATTERN, $custom) === 1) {
            $declarations[] = 'color:' . $custom; // a custom colour wins over a theme one
        } elseif (($look['color_class'] ?? '') !== '') {
            $classes[] = (string) $look['color_class'];
            if (preg_match('/^var\(--[a-z0-9-]+\)$/', (string) ($look['color_var'] ?? '')) === 1) {
                $declarations[] = 'color:' . $look['color_var'];
            }
        }
        if (!empty($look['italic'])) {
            $declarations[] = 'font-style:italic';
        }
        if (in_array($look['weight'] ?? '', self::HIGHLIGHT_WEIGHTS, true)) {
            $declarations[] = 'font-weight:' . ['normal' => '400', 'medium' => '500', 'bold' => '700'][$look['weight']];
        }
        if (!empty($look['underline'])) {
            $declarations[] = 'text-decoration:underline;text-underline-offset:.15em';
        }
        $style = $declarations === [] ? '' : ' style="' . self::e(implode(';', $declarations)) . '"';

        return self::e(mb_substr($value, 0, $at, 'UTF-8'))
            . '<span' . self::classAttr($classes) . $style . '>' . self::e($highlight) . '</span>'
            . self::e(mb_substr($value, $at + $len, null, 'UTF-8'));
    }

    /** The highlight's look from a block's props, ready for `highlighted()`. */
    public static function highlightLook(\Slate\Module\StudioBuilder\Render\Block\BlockRenderScope $scope): array
    {
        return [
            'color_class' => $scope->tokenClass('fg', $scope->prop('highlight_color_token')),
            'color_var'   => $scope->tokenVar($scope->prop('highlight_color_token')),
            'color'       => $scope->string('highlight_color'),
            'italic'      => $scope->bool('highlight_italic'),
            'weight'      => $scope->string('highlight_weight', 'inherit'),
            'underline'   => $scope->bool('highlight_underline'),
        ];
    }

    /**
     * A text block's flow, as the reference editor's Text Editor sets it: a drop cap adds a class, and more than one column
     * adds `sb-dtc` and returns a style attribute built only from the clamped integers (never authored text): the column
     * count per device as custom properties, and the gap.
     *
     * @param list<string> $classes
     */
    public static function textFlow(\Slate\Module\StudioBuilder\Render\Block\BlockRenderScope $scope, array &$classes): string
    {
        if ($scope->bool('drop_cap')) {
            $classes[] = 'sb-dropcap';
        }
        $columns = $scope->prop('columns', 1);
        $columns = is_int($columns) ? max(1, min(10, $columns)) : 1;
        if ($columns === 1 && !$scope->hasDeviceValue('columns')) {
            return '';
        }
        $gap = $scope->prop('columns_gap', 20);
        $gap = is_int($gap) ? max(0, min(200, $gap)) : 20;
        // Text in columns reads as one column on a phone unless the author set a count for it (and for a tablet).
        $devices = $scope->deviceCounts('columns', 1, 10, $columns, 1);
        $classes[] = 'sb-dtc';
        return ' style="' . \Slate\Module\StudioBuilder\Render\Block\DeviceColumns::vars('tc', $columns, $devices['tablet'], $devices['mobile']) . ';column-gap:' . $gap . 'px"';
    }

    /** Escaped multi-line text with newlines as `<br>`. */
    public static function text(string $value): string
    {
        return nl2br(self::e($value), false);
    }

    /**
     * @param list<string> $classes
     */
    public static function classAttr(array $classes): string
    {
        $clean = [];
        foreach ($classes as $class) {
            if ($class !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $class) === 1) {
                $clean[] = $class;
            }
        }
        return $clean === [] ? '' : ' class="' . self::e(implode(' ', array_values(array_unique($clean)))) . '"';
    }

    /** Translated UI copy when the i18n layer is up; the English default otherwise. */
    public static function t(string $key, string $default): string
    {
        if (\function_exists('__')) {
            try {
                return (string) \__($key, $default);
            } catch (\Throwable $ignored) {
            }
        }
        return $default;
    }

    /** A post date in the page's language ("Jul 15, 2025", "15 juillet 2025"); the language pack sets the format. */
    public static function date(int $timestamp): string
    {
        $format = self::t('studio_date_format', 'M j, Y');
        if (\class_exists(\Slate\Services\I18n\I18n::class)) {
            try {
                return \Slate\Services\I18n\I18n::localDate($format, $timestamp);
            } catch (\Throwable $ignored) {
            }
        }
        return \date($format, $timestamp);
    }
}
