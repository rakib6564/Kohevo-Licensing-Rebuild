<?php

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block;

use Slate\Module\StudioBuilder\Document\DeviceProps;

/**
 * A count that can differ per device — grid columns, text columns, slides per view — written as three custom properties on
 * the element that uses them (desktop, tablet, mobile), which a class in the stylesheet reads in its media queries
 * (`.sb-dcols`, `.sb-dtc`, `.sb-dpv`).
 *
 * All three are always set on the element itself, so a grid inside another grid never inherits its parent's columns.
 * A device the author left alone takes the renderer's default for it (a Gallery's six columns are two on a phone).
 */
final class DeviceColumns
{
    /**
     * @param string $prefix the custom property family: `dc` (grid columns), `tc` (text columns) or `pv` (per view)
     * @param string $path   the prop path an author may override per device (DeviceProps)
     * @return array{tablet: int, mobile: int} the counts tablet and mobile get, before they are written
     */
    public static function resolve(array $block, string $path, int $min, int $max, int $tabletDefault, int $mobileDefault): array
    {
        $over = DeviceProps::overrides($block, $path);
        $count = static function (mixed $v, int $fallback) use ($min, $max): int {
            if (is_string($v) && ctype_digit($v)) {
                $v = (int) $v;
            }
            return is_int($v) ? max($min, min($max, $v)) : $fallback;
        };
        $tablet = $count($over['tablet'] ?? null, $tabletDefault);
        return ['tablet' => $tablet, 'mobile' => $count($over['mobile'] ?? null, min($mobileDefault, array_key_exists('tablet', $over) ? $tablet : $mobileDefault))];
    }

    /** True when the author set a value for `$path` on tablet or mobile. */
    public static function overridden(array $block, string $path): bool
    {
        return DeviceProps::overrides($block, $path) !== [];
    }

    /** `--sb-<prefix>-d:3;--sb-<prefix>-t:2;--sb-<prefix>-m:1` — integers only. */
    public static function vars(string $prefix, int $desktop, int $tablet, int $mobile): string
    {
        return '--sb-' . $prefix . '-d:' . $desktop . ';--sb-' . $prefix . '-t:' . $tablet . ';--sb-' . $prefix . '-m:' . $mobile;
    }
}
