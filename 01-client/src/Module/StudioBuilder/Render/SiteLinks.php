<?php
/**
 * Kohevo Studio — links on a site installed in a sub-folder.
 *
 * An author writes `/services` for the page at the site's own address. On an install at `https://example.com/site`
 * that link must go to `/site/services`, not to the parent site's `/services`, so every root-relative `href` and
 * `action` in rendered output is given the install's base path, and so is every root-relative `src` and `poster`
 * (a gallery or text image stored as `/uploads/...` would otherwise load from the parent site). `data-sb-href` is a
 * link only the runtime follows, such as a countdown's redirect.
 * Absolute URLs, `//host` URLs, fragments, `mailto:` and links that already carry the base path are left alone, so
 * running it twice changes nothing.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

final class SiteLinks
{
    public static function rebase(string $html, string $basePath): string
    {
        $basePath = '/' . trim($basePath, '/');
        if ($basePath === '/' || $html === '') {
            return $html;
        }
        return (string) preg_replace_callback(
            '~(\s(?:href|data-sb-href|action|src|poster)=")(/(?!/)[^"]*)"~',
            static function (array $m) use ($basePath): string {
                $path = $m[2];
                $already = $path === $basePath
                    || str_starts_with($path, $basePath . '/')
                    || str_starts_with($path, $basePath . '?')
                    || str_starts_with($path, $basePath . '#');
                return $m[1] . ($already ? $path : $basePath . $path) . '"';
            },
            $html
        );
    }
}
