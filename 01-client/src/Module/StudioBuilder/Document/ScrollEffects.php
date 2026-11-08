<?php

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

/**
 * Sticky and parallax, the two scroll effects a block may carry in its `interactions`.
 *
 *   interactions.sticky   = {edge: top|bottom, offset: 0-400 (px), devices: [desktop, tablet, mobile],
 *                            shadow: bool, background: colour, hide_on_scroll: bool, shrink: bool}
 *   interactions.parallax = {layer: background|element, speed: 5-80 (% of the scroll), direction: up|down,
 *                            devices: [desktop, tablet, mobile]}
 *   interactions.scroll   = {vertical, horizontal, rotate, scale: -10 to 10; transparency, blur: 0 to 10;
 *                            devices: [desktop, tablet, mobile]}  each follows the node's way through the viewport
 *   interactions.mouse    = {track: -10 to 10, tilt: 0 to 10, magnet: 0 to 10}  the node follows the pointer, tilts
 *                            towards it, or is pulled towards it while it is near; desktop only
 *   interactions.page_colors = {background, text, muted, line, accent: colour}  the page takes these colours while the node is in the middle
 *                            of the screen, with a short blend
 *   interactions.adapt    = true  the node takes the page colours of the section beneath its bottom edge (a header)
 *   interactions.spy      = true  of the node's links to sections of this page, the one whose section is on screen is
 *                            marked current (a chapter nav)
 *   interactions.progress = {edge: top|bottom, color: colour, height: 1-12 (px)}  a bar along that edge that fills as
 *                            the page scrolls
 *   interactions.text     = {effect: light_words|lines, dim: 5-60 (%), step_ms: 0-400}  light_words lights the words up
 *                            one by one as the node crosses the screen (the rest at `dim` opacity); lines raises each
 *                            line from a mask as the node comes into view, `step_ms` apart
 *   interactions.text     = {effect: wave, low: 100-900, high: 100-900, radius: 40-600 (px)}  each letter of a
 *                            variable font grows from the `low` weight to the `high` one as the pointer comes within
 *                            `radius` of it; on a touch screen the weight rolls through the letters by itself
 *   interactions.stagger  = {step_ms: 20-600}  the entrance animations inside the node play in turn, `step_ms` apart,
 *                            once the node comes into view
 *   interactions.glow     = {color: colour, size: 80-800 (px)}  a soft light follows the pointer over the node
 *   interactions.marquee  = {speed: 1-10, direction: left|right, scroll: bool, pause: bool, width: 80-800 (px)}  the
 *                            node's children run past in an endless line; `scroll` speeds them up and turns them with
 *                            the page's scroll, `pause` slows them while the pointer is over them, `width` sizes each
 *   interactions.horizontal = {offset: 0-400 (px), width: 80-1200 (px)}  on large screens the node pins (`offset` below
 *                            the top) and scrolling the page moves its children sideways, in one row
 *   interactions.hover_image = {size: 120-600 (px)}  on large screens with a mouse, each child's picture is hidden in
 *                            the row and follows the pointer while it is over that row
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
    /** Scrolling and mouse effects: the attribute letter of each, and the lowest level it takes (the highest is 10). */
    public const SCROLL_FIELDS = ['vertical' => ['y', -10], 'horizontal' => ['x', -10], 'rotate' => ['r', -10], 'scale' => ['s', -10], 'transparency' => ['o', 0], 'blur' => ['b', 0]];
    public const MOUSE_FIELDS  = ['track' => ['t', -10], 'tilt' => ['i', 0], 'magnet' => ['m', 0]];
    public const TEXT_EFFECTS  = ['light_words', 'lines', 'wave'];

    /** interactions.page_colors keys and the custom property suffix each travels as (`--sb-page-<suffix>`). */
    public const PAGE_COLOURS = ['background' => 'bg', 'text' => 'fg', 'muted' => 'muted', 'line' => 'line', 'accent' => 'accent'];
    public const WAVE_WEIGHT   = [100, 900];
    public const WAVE_RADIUS   = [40, 600];
    public const DIM_RANGE     = [5, 60];
    public const TEXT_STEP     = [0, 400];
    public const STAGGER_STEP  = [20, 600];
    public const PROGRESS_HEIGHT = [1, 12];
    public const GLOW_SIZE     = [80, 800];
    public const MARQUEE_SPEED = [1, 10];
    public const MARQUEE_DIRECTIONS = ['left', 'right'];
    public const ITEM_WIDTH    = [80, 800];
    public const PANEL_WIDTH   = [80, 1200];
    public const HOVER_IMAGE_SIZE = [120, 600];

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
        if (array_key_exists('scroll', $interactions)) {
            self::levelIssues($interactions['scroll'], self::SCROLL_FIELDS, "{$path}.scroll", $errors);
        }
        if (array_key_exists('mouse', $interactions)) {
            self::levelIssues($interactions['mouse'], self::MOUSE_FIELDS, "{$path}.mouse", $errors);
        }
        foreach (['adapt', 'spy'] as $flag) {
            if (array_key_exists($flag, $interactions) && !is_bool($interactions[$flag])) {
                $errors[] = self::issue("{$path}.{$flag}", "{$flag} must be true or false.");
            }
        }
        $shapes = [
            'page_colors' => ['background' => 'colour', 'text' => 'colour', 'muted' => 'colour', 'line' => 'colour', 'accent' => 'colour'],
            'progress'    => ['edge' => self::EDGES, 'color' => 'colour', 'height' => self::PROGRESS_HEIGHT],
            'text'        => ['effect' => self::TEXT_EFFECTS, 'dim' => self::DIM_RANGE, 'step_ms' => self::TEXT_STEP, 'low' => self::WAVE_WEIGHT, 'high' => self::WAVE_WEIGHT, 'radius' => self::WAVE_RADIUS],
            'stagger'     => ['step_ms' => self::STAGGER_STEP],
            'glow'        => ['color' => 'colour', 'size' => self::GLOW_SIZE],
            'marquee'     => ['speed' => self::MARQUEE_SPEED, 'direction' => self::MARQUEE_DIRECTIONS, 'scroll' => 'bool', 'pause' => 'bool', 'width' => self::ITEM_WIDTH],
            'horizontal'  => ['offset' => [0, self::MAX_OFFSET], 'width' => self::PANEL_WIDTH],
            'hover_image' => ['size' => self::HOVER_IMAGE_SIZE],
        ];
        $needs = ['progress' => 'edge', 'text' => 'effect', 'stagger' => 'step_ms', 'glow' => 'color', 'marquee' => 'speed', 'horizontal' => 'offset', 'hover_image' => 'size'];
        if (array_key_exists('marquee', $interactions) && array_key_exists('horizontal', $interactions)) {
            $errors[] = self::issue("{$path}.horizontal", 'A node is a marquee or scrolls sideways, not both.');
        }
        foreach ($shapes as $key => $fields) {
            if (array_key_exists($key, $interactions)) {
                self::shapeIssues($interactions[$key], $fields, $needs[$key] ?? null, "{$path}.{$key}", $errors);
            }
        }
        if (array_key_exists('page_colors', $interactions) && is_array($interactions['page_colors'])
            && !isset($interactions['page_colors']['background']) && !isset($interactions['page_colors']['text'])) {
            $errors[] = self::issue("{$path}.page_colors", 'Page colours need a background or a text colour.');
        }
        return $errors;
    }

    /**
     * @param array<string, array{0: string, 1: int}> $fields
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function levelIssues(mixed $effect, array $fields, string $path, array &$errors): void
    {
        if (!self::isObject($effect)) {
            $errors[] = self::issue($path, 'This effect must be an object.');
            return;
        }
        foreach ($effect as $key => $value) {
            if ($key === 'devices') {
                $ok = self::devicesValid($value);
            } elseif (isset($fields[$key])) {
                $ok = is_int($value) && $value >= $fields[$key][1] && $value <= 10;
            } else {
                $errors[] = self::issue("{$path}.{$key}", "Unknown setting '{$key}'.");
                continue;
            }
            if (!$ok) {
                $errors[] = self::issue("{$path}.{$key}", "Invalid {$key}.");
            }
        }
    }

    /**
     * An effect made of fixed fields: a colour ('colour'), a choice (a list) or a whole number in a range ([min, max]).
     *
     * @param array<string, string|list<string>|array{0: int, 1: int}> $fields
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function shapeIssues(mixed $effect, array $fields, ?string $required, string $path, array &$errors): void
    {
        if (!self::isObject($effect)) {
            $errors[] = self::issue($path, 'This effect must be an object.');
            return;
        }
        foreach ($effect as $key => $value) {
            if (!isset($fields[$key])) {
                $errors[] = self::issue("{$path}.{$key}", "Unknown setting '{$key}'.");
            } elseif (!self::fieldValid($fields[$key], $value)) {
                $errors[] = self::issue("{$path}.{$key}", "Invalid {$key}.");
            }
        }
        if ($required !== null && !isset($effect[$required])) {
            $errors[] = self::issue("{$path}.{$required}", "This effect needs a {$required}.");
        }
    }

    /** @param string|list<string>|array{0: int, 1: int} $field */
    private static function fieldValid(string|array $field, mixed $value): bool
    {
        if ($field === 'colour') {
            return StyleValueGuard::isColor($value);
        }
        if ($field === 'bool') {
            return is_bool($value);
        }
        if (is_array($field) && count($field) === 2 && is_int($field[0] ?? null) && is_int($field[1] ?? null)) {
            return is_int($value) && $value >= $field[0] && $value <= $field[1];
        }
        return in_array($value, $field, true);
    }

    /** The checked field of an effect, or null when it is missing or invalid (a stored document is never trusted). */
    private static function field(mixed $effect, string $key, string|array $field): mixed
    {
        if (!self::isObject($effect) || !array_key_exists($key, $effect)) {
            return null;
        }
        return self::fieldValid($field, $effect[$key]) ? $effect[$key] : null;
    }

    /**
     * The `y:3 o:5` value of a scroll or mouse attribute: checked integers only, so it needs no escaping. Empty when no
     * effect is set.
     *
     * @param array<string, array{0: string, 1: int}> $fields
     */
    private static function levels(mixed $effect, array $fields): string
    {
        $out = [];
        foreach (self::isObject($effect) ? $fields : [] as $key => [$letter, $min]) {
            $value = $effect[$key] ?? 0;
            if (is_int($value) && $value !== 0 && $value >= $min && $value <= 10) {
                $out[] = $letter . ':' . $value;
            }
        }
        return implode(' ', $out);
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
                'shadow', 'hide_on_scroll', 'shrink' => is_bool($value),
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
            if ($edge === 'top' && ($sticky['shrink'] ?? false) === true) {
                $effects[] = 'shrink';
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

        $levels = self::levels($interactions['scroll'] ?? null, self::SCROLL_FIELDS);
        if ($levels !== '') {
            $attrs .= ' data-sb-scroll="' . $levels . '"';
            $off = self::offDevices($interactions['scroll']['devices'] ?? null);
            if ($off !== []) {
                $attrs .= ' data-sb-scroll-off="' . implode(' ', $off) . '"';
            }
        }
        $levels = self::levels($interactions['mouse'] ?? null, self::MOUSE_FIELDS);
        if ($levels !== '') {
            $attrs .= ' data-sb-mouse="' . $levels . '"';
        }

        // Colours travel as custom properties on the node, in its one style attribute; the runtime reads them back.
        // The attribute lists the colours the node sets, so the stylesheet only points theme colours at ones that exist.
        $page = $interactions['page_colors'] ?? null;
        $set = [];
        foreach (self::PAGE_COLOURS as $key => $name) {
            $value = self::field($page, $key, 'colour');
            if ($value !== null) {
                $set[] = $name;
                $vars[] = '--sb-page-' . $name . ':' . StyleSurface::colourCss((string) $value);
            }
        }
        if ($set !== []) {
            $attrs .= ' data-sb-page-colors="' . implode(' ', $set) . '"';
        }
        if (($interactions['adapt'] ?? false) === true) {
            $classes[] = 'sb-adapt';
            $attrs .= ' data-sb-adapt';
        }
        if (($interactions['spy'] ?? false) === true) {
            $attrs .= ' data-sb-spy';
        }

        $progress = $interactions['progress'] ?? null;
        $edge = self::field($progress, 'edge', self::EDGES);
        if ($edge !== null) {
            $attrs .= ' data-sb-progress="' . $edge . '"';
            $color = self::field($progress, 'color', 'colour');
            if ($color !== null) {
                $vars[] = '--sb-progress-color:' . StyleSurface::colourCss((string) $color);
            }
            $height = self::field($progress, 'height', self::PROGRESS_HEIGHT);
            if ($height !== null) {
                $vars[] = '--sb-progress-h:' . $height . 'px';
            }
        }

        $text = $interactions['text'] ?? null;
        $effect = self::field($text, 'effect', self::TEXT_EFFECTS);
        if ($effect === 'light_words') {
            $attrs .= ' data-sb-text="words:' . (self::field($text, 'dim', self::DIM_RANGE) ?? 18) . '"';
        } elseif ($effect === 'lines') {
            $attrs .= ' data-sb-text="lines:' . (self::field($text, 'step_ms', self::TEXT_STEP) ?? 100) . '"';
        } elseif ($effect === 'wave') {
            $low = self::field($text, 'low', self::WAVE_WEIGHT) ?? 300;
            $high = self::field($text, 'high', self::WAVE_WEIGHT) ?? 800;
            $attrs .= ' data-sb-wave="' . $low . ',' . $high . ',' . (self::field($text, 'radius', self::WAVE_RADIUS) ?? 160) . '"';
            $vars[] = '--sb-wave-low:' . $low;
            $vars[] = '--sb-wave-high:' . $high;
        }

        $step = self::field($interactions['stagger'] ?? null, 'step_ms', self::STAGGER_STEP);
        if ($step !== null) {
            $attrs .= ' data-sb-stagger="' . $step . '"';
            $vars[] = '--sb-stagger-step:' . $step . 'ms';
        }

        $glow = $interactions['glow'] ?? null;
        $glowColor = self::field($glow, 'color', 'colour');
        if ($glowColor !== null) {
            $classes[] = 'sb-glow-on';
            $attrs .= ' data-sb-glow';
            $vars[] = '--sb-glow-color:' . StyleSurface::colourCss((string) $glowColor);
            $size = self::field($glow, 'size', self::GLOW_SIZE);
            if ($size !== null) {
                $vars[] = '--sb-glow-size:' . $size . 'px';
            }
        }

        // Marquee and sideways scrolling rearrange the children, so a node does one; sideways wins, as the validator says.
        $horizontal = $interactions['horizontal'] ?? null;
        $offset = self::field($horizontal, 'offset', [0, self::MAX_OFFSET]);
        $marquee = $interactions['marquee'] ?? null;
        $speed = self::field($marquee, 'speed', self::MARQUEE_SPEED);
        if ($offset !== null) {
            $attrs .= ' data-sb-horizontal';
            $vars[] = '--sb-hs-offset:' . $offset . 'px';
            $width = self::field($horizontal, 'width', self::PANEL_WIDTH);
            if ($width !== null) {
                $vars[] = '--sb-item-w:' . $width . 'px';
            }
        } elseif ($speed !== null) {
            $parts = [(string) $speed, self::field($marquee, 'direction', self::MARQUEE_DIRECTIONS) ?? 'left'];
            if (self::field($marquee, 'scroll', 'bool') === true) {
                $parts[] = 'scroll';
            }
            if (self::field($marquee, 'pause', 'bool') === true) {
                $parts[] = 'pause';
            }
            $attrs .= ' data-sb-ticker="' . implode(' ', $parts) . '"';
            $width = self::field($marquee, 'width', self::ITEM_WIDTH);
            if ($width !== null) {
                $vars[] = '--sb-item-w:' . $width . 'px';
            }
        }

        $size = self::field($interactions['hover_image'] ?? null, 'size', self::HOVER_IMAGE_SIZE);
        if ($size !== null) {
            $attrs .= ' data-sb-hover-image';
            $vars[] = '--sb-hover-size:' . $size . 'px';
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
