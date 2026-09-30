<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8B: CSS value -> canonical value mapping.
 *
 * CSS is only ever EVIDENCE for choosing among the canonical schema's closed
 * enums and the CURRENT design-token registry; no CSS text is ever returned
 * for persistence. Two strategies, frozen by the Phase 8B contract:
 *
 *   - theme-valued tokens (surface / text / radius / shadow / font / space):
 *     EXACT match only, after deterministic normalization, against the
 *     tenant's read-only RESOLVED theme values. No colour quantization, no
 *     custom token creation. A ref is emitted only if it exists in the token
 *     registry (`ThemeResolver::DEFAULT_TOKENS`).
 *   - closed schema scales (section padding_y, gap, section width): nearest
 *     bucket (ties -> the smaller bucket); the caller reports
 *     `style_quantized` whenever the value was not exactly a bucket value.
 *
 * The scale values mirror `StudioStylesheet` (a unit test pins them).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Package\Html;

use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;

final class HtmlCssValues
{
    /** rem values of StudioStylesheet's padding utilities. */
    public const PADDING_SCALE = ['none' => 0.0, 'xs' => 0.5, 'sm' => 1.0, 'md' => 2.0, 'lg' => 4.0, 'xl' => 6.0, '2xl' => 8.0];
    /** rem values of StudioStylesheet's gap utilities. */
    public const GAP_SCALE     = ['none' => 0.0, 'xs' => 0.25, 'sm' => 0.5, 'md' => 1.0, 'lg' => 1.5, 'xl' => 2.0, '2xl' => 3.0];
    /** rem values of StudioStylesheet's container widths (`full` = no max-width). */
    public const WIDTH_SCALE   = ['narrow' => 42.0, 'normal' => 64.0, 'wide' => 80.0];

    /** Deterministic preference when several tokens share one value. */
    private const PREFERENCE = [
        'surface' => ['surface.primary', 'surface.page', 'surface.secondary', 'surface.muted', 'surface.accent', 'surface.inverse'],
        'text'    => ['text.primary', 'text.muted', 'text.accent', 'text.inverse'],
        'radius'  => ['radius.sm', 'radius.md', 'radius.lg', 'radius.full'],
        'shadow'  => ['shadow.sm', 'shadow.md'],
        'space'   => ['space.sm', 'space.md', 'space.lg'],
        'font'    => ['font.body', 'font.heading'],
    ];

    private const NAMED_COLORS = ['white' => '#ffffff', 'black' => '#000000'];

    /** @var array<string, array<string, string>> category => normalized value => token ref */
    private array $index = [];

    /** @param array<string, string> $themeTokens the tenant's RESOLVED theme (ref => sanitized CSS value) */
    public function __construct(array $themeTokens)
    {
        foreach (self::PREFERENCE as $category => $refs) {
            $this->index[$category] = [];
            foreach ($refs as $ref) {
                if (!array_key_exists($ref, ThemeResolver::DEFAULT_TOKENS) || !is_string($themeTokens[$ref] ?? null)) {
                    continue;
                }
                $key = $this->normalizeFor($category, $themeTokens[$ref]);
                if ($key !== null && !isset($this->index[$category][$key])) {
                    $this->index[$category][$key] = $ref;
                }
            }
        }
    }

    /** The registry token whose resolved value equals `$value` exactly (after normalization), or null. */
    public function token(string $category, string $value): ?string
    {
        $key = $this->normalizeFor($category, $value);
        return $key === null ? null : ($this->index[$category][$key] ?? null);
    }

    private function normalizeFor(string $category, string $value): ?string
    {
        return match ($category) {
            'surface', 'text' => self::color($value),
            'radius', 'space' => self::lengths($value),
            'shadow'          => self::generic($value),
            'font'            => self::font($value),
            default           => null,
        };
    }

    /** Lowercase hex (#abc expanded, opaque alpha dropped), opaque rgb() -> hex; null when not a colour. */
    public static function color(string $value): ?string
    {
        $v = strtolower(trim($value));
        if (isset(self::NAMED_COLORS[$v])) {
            return self::NAMED_COLORS[$v];
        }
        if ($v === 'transparent') {
            return $v;
        }
        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v, $m) === 1) {
            $hex = $m[1];
            if (strlen($hex) <= 4) {
                $hex = implode('', array_map(static fn(string $c): string => $c . $c, str_split($hex)));
            }
            if (strlen($hex) === 8 && substr($hex, 6) === 'ff') {
                $hex = substr($hex, 0, 6);
            }
            return '#' . $hex;
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*[,\s]\s*(\d{1,3})\s*(?:[,\/]\s*([0-9]*\.?[0-9]+)(%?)\s*)?\)$/', $v, $m) === 1) {
            $rgb = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if (max($rgb) > 255) {
                return null;
            }
            $alpha = isset($m[4]) && $m[4] !== '' ? (float) $m[4] / (($m[5] ?? '') === '%' ? 100 : 1) : 1.0;
            if ($alpha >= 1.0) {
                return sprintf('#%02x%02x%02x', ...$rgb);
            }
            return 'rgba(' . implode(',', $rgb) . ',' . self::number($alpha) . ')';
        }
        return null;
    }

    /** A single length in rem (px / 16), or null (percent, viewport units, calc(), unitless non-zero). */
    public static function rem(string $value): ?float
    {
        $v = strtolower(trim($value));
        if (preg_match('/^(-?[0-9]*\.?[0-9]+)(px|rem|em)?$/', $v, $m) !== 1) {
            return null;
        }
        $n = (float) $m[1];
        $unit = $m[2] ?? '';
        if ($unit === '') {
            return $n == 0.0 ? 0.0 : null;
        }
        return round($unit === 'px' ? $n / 16 : $n, 4);
    }

    /** Space-separated lengths normalized to rem ("0.5 1"); null when any part is not a length. */
    private static function lengths(string $value): ?string
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        if ($parts === [] || count($parts) > 4) {
            return null;
        }
        $out = [];
        foreach ($parts as $p) {
            $r = self::rem($p);
            if ($r === null) {
                return null;
            }
            $out[] = self::number($r);
        }
        return implode(' ', $out);
    }

    /** Lowercase, collapsed whitespace, no spaces around `(),`, `0px` -> `0`, `.5` -> `0.5`. */
    public static function generic(string $value): string
    {
        $v = strtolower(trim($value));
        $v = (string) preg_replace('/\s+/', ' ', $v);
        $v = (string) preg_replace('/\s*([(),\/])\s*/', '$1', $v);
        $v = (string) preg_replace('/(?<![0-9.])\.([0-9])/', '0.$1', $v);
        return (string) preg_replace('/(?<![0-9.])0(px|rem|em)\b/', '0', $v);
    }

    /** Font stack: families trimmed, unquoted, lowercased, comma-joined. */
    public static function font(string $value): ?string
    {
        $families = [];
        foreach (explode(',', $value) as $f) {
            $f = strtolower(trim(trim(trim($f), '"\'')));
            $f = (string) preg_replace('/\s+/', ' ', $f);
            if ($f === '') {
                return null;
            }
            $families[] = $f;
        }
        return $families === [] ? null : implode(',', $families);
    }

    /**
     * Nearest bucket of a closed scale (ties -> the smaller bucket).
     *
     * @param array<string, float> $scale ascending
     * @return array{0: string, 1: bool} [bucket, exact]
     */
    public static function quantize(float $rem, array $scale): array
    {
        $best = null;
        $bestDiff = INF;
        foreach ($scale as $name => $value) {
            $diff = abs($rem - $value);
            if ($diff < $bestDiff - 1e-9) {
                $best = (string) $name;
                $bestDiff = $diff;
            }
        }
        return [(string) $best, $bestDiff < 1e-9];
    }

    /** CSS `padding` shorthand -> [top, right, bottom, left] raw parts, or null. @return ?list<string> */
    public static function sides(string $value): ?array
    {
        $p = preg_split('/\s+/', trim($value)) ?: [];
        return match (count($p)) {
            1 => [$p[0], $p[0], $p[0], $p[0]],
            2 => [$p[0], $p[1], $p[0], $p[1]],
            3 => [$p[0], $p[1], $p[2], $p[1]],
            4 => [$p[0], $p[1], $p[2], $p[3]],
            default => null,
        };
    }

    public static function align(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'left', 'start' => 'left',
            'right', 'end'  => 'right',
            'center'        => 'center',
            'justify'       => 'justify',
            default         => null,
        };
    }

    /** `repeat(N, …)` or N explicit tracks (1..12), else null. */
    public static function columns(string $value): ?int
    {
        $v = strtolower(trim($value));
        if (preg_match('/^repeat\(\s*([0-9]{1,2})\s*,/', $v, $m) === 1) {
            $n = (int) $m[1];
            return $n >= 1 && $n <= 12 ? $n : null;
        }
        if (str_contains($v, '(') && !preg_match('/^(minmax\([^()]*\)|[0-9.]+(fr|px|rem|em|%)|auto|min-content|max-content)(\s+(minmax\([^()]*\)|[0-9.]+(fr|px|rem|em|%)|auto|min-content|max-content))*$/', $v)) {
            return null;
        }
        $depth = 0;
        $tracks = 0;
        $inTrack = false;
        for ($i = 0, $n = strlen($v); $i < $n; $i++) {
            $c = $v[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            if ($depth === 0 && ctype_space($c)) {
                $inTrack = false;
            } elseif (!$inTrack) {
                $inTrack = true;
                $tracks++;
            }
        }
        return $tracks >= 1 && $tracks <= 12 ? $tracks : null;
    }

    private static function number(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }
}
