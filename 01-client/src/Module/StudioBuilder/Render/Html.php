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
    public static function link(?array $link, string $class = ''): string
    {
        if ($link === null) {
            return '';
        }
        $label = (string) ($link['label'] ?? '');
        if (trim($label) === '') {
            return '';
        }
        $href = self::safeUrl($link['href'] ?? null);
        if ($href === null) {
            return '<span' . ($class !== '' ? ' class="' . self::e($class) . '"' : '') . '>' . self::e($label) . '</span>';
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
            . '>' . self::e($label) . '</a>';
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
}
