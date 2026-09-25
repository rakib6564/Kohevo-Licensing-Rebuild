<?php
/**
 * Slate Components — shared escaping helpers.
 *
 * The one Component library (docs/04-Design-System/component-library.md) renders
 * server-side, escapes its own text, and reads only --slate-* semantic tokens.
 * These helpers keep escaping uniform across the slate_c_* functions. Self-
 * contained (no config, no DB) so components are unit-testable in isolation.
 */

declare(strict_types=1);

if (!function_exists('slate_c_e')) {
    /** Escape a value for HTML text / double-quoted attribute context. */
    function slate_c_e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('slate_c_attrs')) {
    /**
     * Build a passthrough attribute string from a name => value map, escaped.
     * Boolean true renders a bare attribute; null/false is skipped.
     *
     * @param array<string,scalar|bool|null> $attrs
     */
    function slate_c_attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/', (string) $name) || $value === null || $value === false) {
                continue;
            }
            if ($value === true) {
                $out .= ' ' . $name;
                continue;
            }
            $out .= ' ' . $name . '="' . slate_c_e((string) $value) . '"';
        }
        return $out;
    }
}

if (!function_exists('slate_c_classes')) {
    /**
     * Join class names, dropping empties/dupes, escaped.
     * @param array<int,string> $classes
     */
    function slate_c_classes(array $classes): string
    {
        $seen = [];
        foreach ($classes as $c) {
            $c = trim((string) $c);
            if ($c !== '' && !isset($seen[$c])) {
                $seen[$c] = true;
            }
        }
        return slate_c_e(implode(' ', array_keys($seen)));
    }
}
