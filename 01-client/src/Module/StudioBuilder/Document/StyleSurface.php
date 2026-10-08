<?php
/**
 * Kohevo Studio (studio-builder) — the closed style surface added in B2-P3.
 *
 * One table defines, per style key, every field an author may set, how it is
 * validated, and the single CSS declaration it produces. The validator and the
 * renderer both read this table, so a field cannot be accepted without a
 * known, server-formatted declaration, and nothing outside the table can reach
 * a stylesheet.
 *
 * Field kinds:
 *   enum    value must be one of a list; the CSS value is the value itself
 *   map     value must be a key of a map; the CSS value is the map's value
 *   length  a StyleValueGuard length (units or calc/clamp/min/max), optionally `auto`
 *   int     an integer within bounds
 *   number  an int/float within bounds, formatted by the server with a unit
 *   ratio   `w/h` with 1-3 digit integers
 *
 * `position: fixed` is deliberately absent (it could cover the platform
 * signature); `layout.display: none` is absent too (visibility has its own control).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Document;

final class StyleSurface
{
    /** Style keys introduced by B2-P3b, in the order their declarations are emitted. */
    public const KEYS = ['layout', 'position', 'effects'];

    /** Sub-fields B2-P3b adds to the existing `dimensions` object (the original four stay as they were). */
    public const DIMENSION_EXTRAS = ['min_width', 'max_height', 'aspect_ratio', 'overflow', 'object_fit', 'object_position'];

    private const JUSTIFY = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'between' => 'space-between', 'around' => 'space-around', 'evenly' => 'space-evenly'];
    private const ALIGN   = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'stretch' => 'stretch', 'baseline' => 'baseline'];
    private const POSITIONS = ['center' => 'center', 'top' => 'top', 'bottom' => 'bottom', 'left' => 'left', 'right' => 'right',
        'top-left' => 'top left', 'top-right' => 'top right', 'bottom-left' => 'bottom left', 'bottom-right' => 'bottom right'];
    private const EASINGS = ['ease' => 'ease', 'ease-in' => 'ease-in', 'ease-out' => 'ease-out', 'ease-in-out' => 'ease-in-out', 'linear' => 'linear'];
    private const BLENDS  = ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten'];
    private const CURSORS = ['auto', 'default', 'pointer', 'text', 'move', 'not-allowed', 'grab'];

    /**
     * @return array<string, array<string, array{0: string, 1: string, 2?: mixed, 3?: mixed, 4?: string}>>
     *   key => field => [kind, css property, param, param, template]
     */
    private static function fields(): array
    {
        return [
            'layout' => [
                'display'   => ['enum', 'display', ['block', 'flex', 'grid', 'inline-block', 'inline-flex']],
                'direction' => ['enum', 'flex-direction', ['row', 'row-reverse', 'column', 'column-reverse']],
                'wrap'      => ['enum', 'flex-wrap', ['nowrap', 'wrap', 'wrap-reverse']],
                'justify'   => ['map', 'justify-content', self::JUSTIFY],
                'align'     => ['map', 'align-items', self::ALIGN],
                'gap'       => ['length', 'gap'],
                'row_gap'   => ['length', 'row-gap'],
                'column_gap' => ['length', 'column-gap'],
                'order'     => ['int', 'order', -99, 99],
                'grow'      => ['int', 'flex-grow', 0, 10],
                'shrink'    => ['int', 'flex-shrink', 0, 10],
                'basis'     => ['length', 'flex-basis', true],
                'columns'   => ['int', 'grid-template-columns', 1, 12, 'repeat(%d,minmax(0,1fr))'],
                'rows'      => ['int', 'grid-template-rows', 1, 12, 'repeat(%d,minmax(0,1fr))'],
            ],
            'position' => [
                'mode'   => ['enum', 'position', ['static', 'relative', 'absolute', 'sticky']],
                'top'    => ['length', 'top', true],
                'right'  => ['length', 'right', true],
                'bottom' => ['length', 'bottom', true],
                'left'   => ['length', 'left', true],
            ],
            'dimensions' => [
                'min_width'       => ['length', 'min-width', true],
                'max_height'      => ['length', 'max-height', true],
                'aspect_ratio'    => ['ratio', 'aspect-ratio'],
                'overflow'        => ['enum', 'overflow', ['visible', 'hidden', 'auto', 'scroll']],
                'object_fit'      => ['enum', 'object-fit', ['cover', 'contain', 'fill', 'none', 'scale-down']],
                'object_position' => ['map', 'object-position', self::POSITIONS],
            ],
        ];
    }

    /** Numeric parts of `effects`: group => field => [min, max, unit]. */
    private const TRANSFORM = ['rotate' => [-360, 360, 'deg'], 'scale' => [0, 5, ''], 'skew_x' => [-90, 90, 'deg'], 'skew_y' => [-90, 90, 'deg']];
    private const FILTER    = ['blur' => [0, 50, 'px'], 'brightness' => [0, 300, '%'], 'contrast' => [0, 300, '%'], 'saturate' => [0, 300, '%'], 'grayscale' => [0, 100, '%']];

    // ── validation ────────────────────────────────────────────────────────────

    /**
     * Issues for the B2-P3b keys (and dimension extras) of a block `style`.
     *
     * @param array<string, mixed> $style
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function issues(array $style, string $path): array
    {
        $errors = [];
        foreach (['layout', 'position'] as $key) {
            if (!array_key_exists($key, $style) || $style[$key] === null) {
                continue;
            }
            self::checkObject($style[$key], self::fields()[$key], "{$path}.{$key}", $errors);
        }
        if (isset($style['dimensions']) && is_array($style['dimensions'])) {
            $extras = array_intersect_key(self::fields()['dimensions'], $style['dimensions']);
            foreach ($extras as $field => $def) {
                self::checkField($style['dimensions'][$field], $def, "{$path}.dimensions.{$field}", $errors);
            }
        }
        if (array_key_exists('effects', $style) && $style['effects'] !== null) {
            self::checkEffects($style['effects'], "{$path}.effects", $errors);
        }
        return $errors;
    }

    /**
     * @param array<string, array<int, mixed>> $defs
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkObject(mixed $value, array $defs, string $path, array &$errors): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = self::issue($path, "{$path} must be an object.");
            return;
        }
        foreach ($value as $field => $v) {
            if (!isset($defs[(string) $field])) {
                $errors[] = self::issue("{$path}.{$field}", "Unknown field '{$field}'.");
                continue;
            }
            self::checkField($v, $defs[(string) $field], "{$path}.{$field}", $errors);
        }
    }

    /**
     * @param array<int, mixed> $def
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkField(mixed $v, array $def, string $path, array &$errors): void
    {
        if (!self::fieldAccepts($v, $def)) {
            $errors[] = self::issue($path, 'Invalid value.');
        }
    }

    /** @param array<int, mixed> $def */
    private static function fieldAccepts(mixed $v, array $def): bool
    {
        switch ($def[0]) {
            case 'enum':
                return is_string($v) && in_array($v, $def[2], true);
            case 'map':
                return is_string($v) && array_key_exists($v, $def[2]);
            case 'length':
                return self::isMeasure($v, (bool) ($def[2] ?? false));
            case 'int':
                return is_int($v) && $v >= $def[2] && $v <= $def[3];
            case 'ratio':
                return is_string($v) && preg_match('/^[1-9][0-9]{0,2}\/[1-9][0-9]{0,2}$/', $v) === 1;
        }
        return false;
    }

    /** A length with no CSS-wide keywords; `auto` only where the property takes it. */
    private static function isMeasure(mixed $v, bool $allowAuto): bool
    {
        if (is_string($v) && strtolower(trim($v)) === 'auto') {
            return $allowAuto;
        }
        if (is_string($v) && in_array(strtolower(trim($v)), ['none', 'inherit', 'initial', 'unset', 'fit-content', 'min-content', 'max-content'], true)) {
            return false;
        }
        return StyleValueGuard::isLength($v);
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkEffects(mixed $fx, string $path, array &$errors): void
    {
        if (!is_array($fx) || ($fx !== [] && array_is_list($fx))) {
            $errors[] = self::issue($path, 'effects must be an object.');
            return;
        }
        foreach ($fx as $key => $value) {
            $p = "{$path}.{$key}";
            switch ((string) $key) {
                case 'transform':
                    self::checkNumberGroup($value, self::TRANSFORM + ['translate_x' => null, 'translate_y' => null], $p, $errors, true);
                    break;
                case 'filter':
                    self::checkNumberGroup($value, self::FILTER, $p, $errors, false);
                    break;
                case 'backdrop_blur':
                    if (!self::inRange($value, 0, 50)) {
                        $errors[] = self::issue($p, 'backdrop_blur must be a number from 0 to 50.');
                    }
                    break;
                case 'blend':
                    if (!is_string($value) || !in_array($value, self::BLENDS, true)) {
                        $errors[] = self::issue($p, 'Unknown blend mode.');
                    }
                    break;
                case 'cursor':
                    if (!is_string($value) || !in_array($value, self::CURSORS, true)) {
                        $errors[] = self::issue($p, 'Unknown cursor.');
                    }
                    break;
                case 'transition':
                    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                        $errors[] = self::issue($p, 'transition must be an object.');
                        break;
                    }
                    foreach ($value as $tk => $tv) {
                        if ($tk === 'duration_ms' && is_int($tv) && $tv >= 0 && $tv <= 4000) {
                            continue;
                        }
                        if ($tk === 'easing' && is_string($tv) && isset(self::EASINGS[$tv])) {
                            continue;
                        }
                        $errors[] = self::issue("{$p}.{$tk}", 'Invalid transition field.');
                    }
                    break;
                default:
                    $errors[] = self::issue($p, "Unknown effects field '{$key}'.");
            }
        }
    }

    /**
     * @param array<string, mixed> $ranges field => [min, max, unit] (null = a length)
     * @param list<array{path: string, code: string, message: string}> $errors
     */
    private static function checkNumberGroup(mixed $value, array $ranges, string $path, array &$errors, bool $allowLengths): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = self::issue($path, "{$path} must be an object.");
            return;
        }
        foreach ($value as $field => $v) {
            // A null entry means "a length", so absence is tested by key, not by `??`.
            $range = array_key_exists((string) $field, $ranges) ? $ranges[(string) $field] : false;
            if ($range === false) {
                $errors[] = self::issue("{$path}.{$field}", "Unknown field '{$field}'.");
            } elseif ($range === null) {
                if (!$allowLengths || !self::isMeasure($v, false)) {
                    $errors[] = self::issue("{$path}.{$field}", 'Invalid length.');
                }
            } elseif (!self::inRange($v, $range[0], $range[1])) {
                $errors[] = self::issue("{$path}.{$field}", "Must be a number from {$range[0]} to {$range[1]}.");
            }
        }
    }

    private static function inRange(mixed $v, int|float $min, int|float $max): bool
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) && $v >= $min && $v <= $max;
    }

    /** @return array{path: string, code: string, message: string} */
    private static function issue(string $path, string $message): array
    {
        return ['path' => $path, 'code' => 'invalid_style_value', 'message' => $message];
    }

    // ── emission ──────────────────────────────────────────────────────────────

    /**
     * The declarations (without braces) for the B2-P3b keys of a block `style`,
     * or '' when there are none. Every value that fails validation is skipped,
     * so a stored document can never write anything the table does not define.
     *
     * @param array<string, mixed> $style
     */
    public static function declarations(array $style): string
    {
        $out = [];
        foreach (['layout', 'position'] as $key) {
            if (isset($style[$key]) && is_array($style[$key])) {
                self::emitFields($style[$key], self::fields()[$key], $out);
            }
        }
        if (isset($style['dimensions']) && is_array($style['dimensions'])) {
            self::emitFields($style['dimensions'], self::fields()['dimensions'], $out);
        }
        if (isset($style['effects']) && is_array($style['effects'])) {
            self::emitEffects($style['effects'], $out);
        }
        return implode(';', $out);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<int, mixed>> $defs
     * @param list<string> $out
     */
    private static function emitFields(array $values, array $defs, array &$out): void
    {
        // Emit in table order, not document order, so the CSS is deterministic.
        foreach ($defs as $field => $def) {
            if (!array_key_exists($field, $values) || !self::fieldAccepts($values[$field], $def)) {
                continue;
            }
            $v = $values[$field];
            $css = match ($def[0]) {
                'map'  => $def[2][$v],
                'int'  => isset($def[4]) ? sprintf($def[4], $v) : (string) $v,
                'length' => trim((string) $v),
                default => (string) $v,
            };
            $out[] = $def[1] . ':' . $css;
        }
    }

    /**
     * @param array<string, mixed> $fx
     * @param list<string> $out
     */
    private static function emitEffects(array $fx, array &$out): void
    {
        $t = is_array($fx['transform'] ?? null) ? $fx['transform'] : [];
        $parts = [];
        foreach (['translate_x', 'translate_y'] as $axis) {
            if (isset($t[$axis]) && self::isMeasure($t[$axis], false)) {
                $parts['t'][$axis] = trim((string) $t[$axis]);
            }
        }
        $tx = $parts['t']['translate_x'] ?? null;
        $ty = $parts['t']['translate_y'] ?? null;
        $fn = [];
        if ($tx !== null || $ty !== null) {
            $fn[] = 'translate(' . ($tx ?? '0') . ',' . ($ty ?? '0') . ')';
        }
        if (isset($t['rotate']) && self::inRange($t['rotate'], -360, 360)) {
            $fn[] = 'rotate(' . self::num($t['rotate']) . 'deg)';
        }
        if (isset($t['scale']) && self::inRange($t['scale'], 0, 5)) {
            $fn[] = 'scale(' . self::num($t['scale']) . ')';
        }
        $sx = isset($t['skew_x']) && self::inRange($t['skew_x'], -90, 90) ? self::num($t['skew_x']) : null;
        $sy = isset($t['skew_y']) && self::inRange($t['skew_y'], -90, 90) ? self::num($t['skew_y']) : null;
        if ($sx !== null || $sy !== null) {
            $fn[] = 'skew(' . ($sx ?? '0') . 'deg,' . ($sy ?? '0') . 'deg)';
        }
        if ($fn !== []) {
            $out[] = 'transform:' . implode(' ', $fn);
        }

        $f = is_array($fx['filter'] ?? null) ? $fx['filter'] : [];
        $filters = [];
        foreach (self::FILTER as $name => [$min, $max, $unit]) {
            if (isset($f[$name]) && self::inRange($f[$name], $min, $max)) {
                $filters[] = $name . '(' . self::num($f[$name]) . $unit . ')';
            }
        }
        if ($filters !== []) {
            $out[] = 'filter:' . implode(' ', $filters);
        }
        if (isset($fx['backdrop_blur']) && self::inRange($fx['backdrop_blur'], 0, 50)) {
            $b = 'blur(' . self::num($fx['backdrop_blur']) . 'px)';
            $out[] = '-webkit-backdrop-filter:' . $b;
            $out[] = 'backdrop-filter:' . $b;
        }
        if (isset($fx['blend']) && is_string($fx['blend']) && in_array($fx['blend'], self::BLENDS, true)) {
            $out[] = 'mix-blend-mode:' . $fx['blend'];
        }
        $tr = is_array($fx['transition'] ?? null) ? $fx['transition'] : [];
        if ($tr !== []) {
            $ms = isset($tr['duration_ms']) && is_int($tr['duration_ms']) && $tr['duration_ms'] >= 0 && $tr['duration_ms'] <= 4000 ? $tr['duration_ms'] : 200;
            $ease = isset($tr['easing']) && is_string($tr['easing']) && isset(self::EASINGS[$tr['easing']]) ? self::EASINGS[$tr['easing']] : 'ease';
            $out[] = 'transition:all ' . $ms . 'ms ' . $ease;
        }
        if (isset($fx['cursor']) && is_string($fx['cursor']) && in_array($fx['cursor'], self::CURSORS, true)) {
            $out[] = 'cursor:' . $fx['cursor'];
        }
    }

    /** Server-formatted number: no exponent, at most 3 decimals, no trailing zeros. */
    private static function num(int|float $n): string
    {
        $s = rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** True when the block's style has a transition (so a reduced-motion override is needed). */
    public static function hasTransition(array $style): bool
    {
        return isset($style['effects']['transition']) && is_array($style['effects']['transition']) && $style['effects']['transition'] !== [];
    }
}
