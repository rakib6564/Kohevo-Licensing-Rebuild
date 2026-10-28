<?php

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

/**
 * Sticky and parallax, the two scroll effects a block or a section may carry in its `interactions`.
 *
 *   interactions.sticky   = {edge: top|bottom, offset: 0-400 (px), devices: [desktop, tablet, mobile],
 *                            shadow: bool, background: colour, hide_on_scroll: bool}
 *   interactions.parallax = {layer: background|element, speed: 5-80 (% of the scroll), direction: up|down,
 *                            devices: [desktop, tablet, mobile]}
 *
 * The renderer turns them into classes, `data-sb-*` attributes and custom properties only; the stylesheet holds the CSS and
 * studio-runtime.js the scroll work (the stuck state, hiding on scroll, the parallax offset). Without JavaScript a sticky
 * node still sticks (it is plain `position: sticky`) and a parallax background still shows, standing still.
 */
final class ScrollEffects
{
    public const DEVICES    = ['desktop', 'tablet', 'mobile'];
    public const EDGES      = ['top', 'bottom'];
    public const LAYERS     = ['background', 'element'];
    public const DIRECTIONS = ['up', 'down'];
    public const MAX_OFFSET = 400;
    public const MIN_SPEED  = 5;
    public const MAX_SPEED  = 80;

    /**
     * @param array<string, mixed> $interactions
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function issues(array $interactions, string $path): array
    {
        $errors = [];
        if (array_key_exists('sticky', $interactions)) {
            self::stickyIssues($interactions['sticky'], "{$path}.sticky", $errors);
        }
        if (array_key_exists('parallax', $interactions)) {
            self::parallaxIssues($interactions['parallax'], "{$path}.parallax", $errors);
        }
        return $errors;
    }

    /** @param list<array{path: string, code: string, message: string}> $errors */
    private static function stickyIssues(mixed $sticky, string $path, array &$errors): void
    {
        if (!self::isObject($sticky)) {
            $errors[] = self::issue($path, 'sticky must be an object.');
            return;
        }
        foreach ($sticky as $key => $value) {
            $p = "{$path}.{$key}";
            $ok = match ($key) {
                'edge'           => in_array($value, self::EDGES, true),
                'offset'         => is_int($value) && $value >= 0 && $value <= self::MAX_OFFSET,
                'devices'        => self::devicesValid($value),
                'shadow', 'hide_on_scroll' => is_bool($value),
                'background'     => StyleValueGuard::isColor($value),
                default          => null,
            };
            if ($ok === null) {
                $errors[] = self::issue($p, "Unknown sticky setting '{$key}'.");
            } elseif (!$ok) {
                $errors[] = self::issue($p, "Invalid sticky {$key}.");
            }
        }
        if (!isset($sticky['edge'])) {
            $errors[] = self::issue("{$path}.edge", 'A sticky node needs an edge: top or bottom.');
        }
    }

    /** @param list<array{path: string, code: string, message: string}> $errors */
    private static function parallaxIssues(mixed $parallax, string $path, array &$errors): void
    {
        if (!self::isObject($parallax)) {
            $errors[] = self::issue($path, 'parallax must be an object.');
            return;
        }
        foreach ($parallax as $key => $value) {
            $p = "{$path}.{$key}";
            $ok = match ($key) {
                'layer'     => in_array($value, self::LAYERS, true),
                'speed'     => is_int($value) && $value >= self::MIN_SPEED && $value <= self::MAX_SPEED,
                'direction' => in_array($value, self::DIRECTIONS, true),
                'devices'   => self::devicesValid($value),
                default     => null,
            };
            if ($ok === null) {
                $errors[] = self::issue($p, "Unknown parallax setting '{$key}'.");
            } elseif (!$ok) {
                $errors[] = self::issue($p, "Invalid parallax {$key}.");
            }
        }
        if (!isset($parallax['speed'])) {
            $errors[] = self::issue("{$path}.speed", 'Parallax needs a speed.');
        }
    }

    /**
     * What a node's scroll effects add to its wrapper. A document stored with junk here renders without the effect rather
     * than failing: each value is checked again, as the validator does.
     *
     * @param array<string, mixed> $node
     * @return array{classes: list<string>, attrs: string, vars: list<string>}
     */
    public static function render(array $node): array
    {
        $classes = [];
        $attrs = '';
        $vars = [];
        $interactions = is_array($node['interactions'] ?? null) ? $node['interactions'] : [];

        $sticky = $interactions['sticky'] ?? null;
        if (self::isObject($sticky) && in_array($sticky['edge'] ?? null, self::EDGES, true)) {
            $edge = $sticky['edge'];
            $classes[] = 'sb-sticky';
            $classes[] = 'sb-sticky--' . $edge;
            foreach (self::offDevices($sticky['devices'] ?? null) as $device) {
                $classes[] = 'sb-sticky-off-' . $device;
            }
            $effects = [];
            if (($sticky['shadow'] ?? false) === true) {
                $effects[] = 'shadow';
            }
            if (isset($sticky['background']) && StyleValueGuard::isColor($sticky['background'])) {
                $effects[] = 'background';
                $vars[] = '--sb-stuck-bg:' . StyleSurface::colourCss((string) $sticky['background']);
            }
            if ($edge === 'top' && ($sticky['hide_on_scroll'] ?? false) === true) {
                $effects[] = 'hide';
            }
            $attrs .= ' data-sb-sticky="' . $edge . '"';
            if ($effects !== []) {
                $attrs .= ' data-sb-sticky-effect="' . implode(' ', $effects) . '"';
            }
            $offset = $sticky['offset'] ?? 0;
            if (is_int($offset) && $offset > 0 && $offset <= self::MAX_OFFSET) {
                $vars[] = '--sb-sticky-offset:' . $offset . 'px';
            }
        }

        $parallax = $interactions['parallax'] ?? null;
        $speed = self::isObject($parallax) ? ($parallax['speed'] ?? null) : null;
        if (is_int($speed) && $speed >= self::MIN_SPEED && $speed <= self::MAX_SPEED) {
            $layer = ($parallax['layer'] ?? 'background') === 'element' ? 'element' : 'background';
            $ratio = $speed / 100 * (($parallax['direction'] ?? 'up') === 'down' ? -1 : 1);
            // A computed number, nothing from the document: it needs no escaping.
            $value = rtrim(rtrim(number_format($ratio, 2, '.', ''), '0'), '.');
            $attrs .= $layer === 'element'
                ? ' data-sb-parallax="' . $value . '"'
                : ' data-sb-parallax-bg="' . $value . '"';
            if ($layer === 'background') {
                // The layer is taller than the node by the room it moves in: half the speed, on each side.
                $classes[] = 'sb-parallax-bg';
                $vars[] = '--sb-pbg-room:' . intdiv($speed, 2) . '%';
            }
            $off = self::offDevices($parallax['devices'] ?? null);
            if ($off !== []) {
                $attrs .= ' data-sb-parallax-off="' . implode(' ', $off) . '"';
            }
        }

        return ['classes' => $classes, 'attrs' => $attrs, 'vars' => $vars];
    }

    /** True when a node's background moves on scroll: its image is then painted by the parallax layer, not the node. */
    public static function hasParallaxBackground(array $node): bool
    {
        $parallax = is_array($node['interactions'] ?? null) ? ($node['interactions']['parallax'] ?? null) : null;
        return self::isObject($parallax) && ($parallax['layer'] ?? 'background') === 'background'
            && is_int($parallax['speed'] ?? null) && $parallax['speed'] >= self::MIN_SPEED && $parallax['speed'] <= self::MAX_SPEED;
    }

    /** @return list<string> the devices a `devices` list leaves out (none when the list is absent or invalid) */
    private static function offDevices(mixed $devices): array
    {
        if (!self::devicesValid($devices)) {
            return [];
        }
        return array_values(array_diff(self::DEVICES, $devices));
    }

    private static function devicesValid(mixed $devices): bool
    {
        if (!is_array($devices) || $devices === [] || !array_is_list($devices)) {
            return false;
        }
        foreach ($devices as $device) {
            if (!in_array($device, self::DEVICES, true)) {
                return false;
            }
        }
        return count(array_unique($devices)) === count($devices);
    }

    private static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }

    /** @return array{path: string, code: string, message: string} */
    private static function issue(string $path, string $message): array
    {
        return ['path' => $path, 'code' => 'invalid_scroll_effect', 'message' => $message];
    }
}
