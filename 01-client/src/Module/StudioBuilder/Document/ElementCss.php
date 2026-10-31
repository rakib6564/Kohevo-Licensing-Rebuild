<?php

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;

/**
 * An element's own CSS (`block.custom_css`). As in the reference editor the author writes `selector` for the element;
 * it becomes the element's class. It passes the site stylesheet's sanitizer at render time, so what is stored can never
 * close the style element, and only administrators may change it (StudioApplicationService).
 */
final class ElementCss
{
    /** block id => css, across a document: what a save may not change without the right. */
    public static function collect(array $document): array
    {
        $document = DocumentUpgrader::structure($document);
        $found = [];
        foreach (is_array($document['containers'] ?? null) ? $document['containers'] : [] as $container) {
            self::walk(is_array($container) ? $container : [], $found);
        }
        ksort($found);
        return $found;
    }

    /** @param array<string, string> $found */
    private static function walk(array $node, array &$found): void
    {
        if (is_string($node['custom_css'] ?? null) && $node['custom_css'] !== '') {
            $found[(string) ($node['id'] ?? '')] = $node['custom_css'];
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::walk($value, $found);
            }
        }
    }

    /** The rules for one element, under its class; '' when there are none. */
    public static function compile(string $css, string $class): string
    {
        $clean = StudioCodePolicy::sanitizeCustomCss(trim($css));
        return $clean === '' ? '' : (preg_replace('/(?<![-\w.#])selector(?![-\w])/', '.' . $class, $clean) ?? '');
    }
}
