<?php
/**
 * Kohevo Studio — carries a framed widget's design into its frame.
 *
 * `forms.embed` and `booking.embed` show the plugin's own page in a same-origin frame, so the block's part styles cannot
 * reach the fields from the page's stylesheet. The renderer compiles them here into one small sheet, named by its own
 * content and written under `uploads/studio-embed/`, and adds that name to the frame's address; the plugin's page prints
 * the sheet in its <head>. No CSS ever travels in an address, and a draft in Preview has a sheet of its own, so it never
 * touches what visitors see.
 *
 * The sheet holds the site's design tokens too, so a part may use them and the frame follows the site's type and text
 * colour. Each selector is prefixed to outweigh the plugin's own embed rules. Without a sheet the page keeps its default
 * look; a missing file is written again the next time the page renders.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Document\StyleSurface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;

final class EmbedStyle
{
    /** The address parameter the frame's page reads, and passes on from step to step. */
    public const PARAM = 'sb_style';

    private const FOLDER = '/uploads/studio-embed';
    private const NAME = '/^[a-f0-9]{32}$/';

    /** Outweighs `body.forms-bare .forms-public-card` and its like, whatever the part's own selector. */
    private const PREFIX = ':root:root body ';

    /** The sheet of one block: its tokens, then its part rules. */
    public static function compile(array $block, string $type, string $rootCss): string
    {
        $css = $rootCss
            . ':root{--font-sans:var(--sb-font-body);--text:var(--sb-text-body)}'
            . 'h1,h2,h3{font-family:var(--sb-font-heading)}';
        $parts = is_array($block['style_parts'] ?? null) ? $block['style_parts'] : [];
        $responsive = is_array($block['responsive'] ?? null) ? $block['responsive'] : [];
        foreach (StyleSurface::partRules($parts, $type, $responsive) as $rule) {
            $selectors = implode(',', array_map(static fn(string $s): string => self::PREFIX . $s, $rule['selectors']));
            $one = $selectors . '{' . $rule['declarations'] . '}';
            $css .= isset($rule['media']) ? '@media ' . $rule['media'] . '{' . $one . '}' : $one;
        }
        return $css;
    }

    /** The name of this block's sheet, written if new; '' when it cannot be kept (the frame then keeps its default look). */
    public static function publish(BlockRenderScope $scope, string $rootCss, ?string $root = null): string
    {
        $css = self::compile($scope->block(), $scope->type(), $rootCss);
        if (str_contains($css, '</')) {
            return '';
        }
        $name = md5($css);
        $dir = self::folder($root);
        $file = $dir . '/' . $name . '.css';
        if (is_file($file)) {
            return $name;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return '';
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $css) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            return '';
        }
        return $name;
    }

    /** This request's sheet name, or ''. Only a name that fits is ever passed on or opened. */
    public static function incoming(): string
    {
        $name = $_POST[self::PARAM] ?? $_GET[self::PARAM] ?? '';
        return is_string($name) && preg_match(self::NAME, $name) === 1 ? $name : '';
    }

    /** The <style> a framed page prints last in its <head>; '' with no sheet. */
    public static function styleTag(?string $root = null): string
    {
        $name = self::incoming();
        if ($name === '') {
            return '';
        }
        $css = @file_get_contents(self::folder($root) . '/' . $name . '.css');
        return is_string($css) && $css !== '' ? '<style id="sb-embed-style">' . $css . '</style>' : '';
    }

    private static function folder(?string $root): string
    {
        return rtrim($root ?? (defined('SLATE_ROOT') ? (string) SLATE_ROOT : ''), '/') . self::FOLDER;
    }
}
