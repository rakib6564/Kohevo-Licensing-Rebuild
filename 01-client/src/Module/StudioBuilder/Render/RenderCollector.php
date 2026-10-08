<?php
/**
 * Kohevo Studio (studio-builder) — Per-render accumulator.
 *
 * Collects, during one render pass:
 *  - the token utility CSS rules the output actually uses (deterministic,
 *    generated only from validated token refs — never authored CSS), and
 *  - deferred dynamic nodes: each is replaced in the static HTML by an HTML
 *    comment marker carrying a random per-compilation nonce. Authored content
 *    can never produce such a marker (all text is escaped and the rich-text
 *    sanitizer drops comments), and the nonce makes it unguessable anyway.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class RenderCollector
{
    /** Token utility kinds: class infix => CSS property. */
    public const TOKEN_UTILITIES = [
        'bd'   => 'border-color',
        'bg'   => 'background-color',
        'fg'   => 'color',
        'font' => 'font-family',
        'pad'  => 'padding',
        'rad'  => 'border-radius',
        'shd'  => 'box-shadow',
    ];

    /** @var array<string, string> class => CSS rule */
    private array $cssRules = [];

    /** @var list<array<string, mixed>> */
    private array $deferred = [];

    public readonly string $nonce;

    public function __construct(?string $nonce = null)
    {
        $this->nonce = ($nonce !== null && preg_match('/^[a-f0-9]{16,64}$/', $nonce) === 1)
            ? $nonce
            : bin2hex(random_bytes(12));
    }

    /**
     * Utility class for a symbolic token, or '' when the theme cannot resolve it
     * (an unresolved token simply contributes no styling).
     */
    public function tokenClass(string $kind, mixed $ref, ResolvedTheme $theme): string
    {
        if (!isset(self::TOKEN_UTILITIES[$kind]) || !is_string($ref) || !$theme->has($ref)) {
            return '';
        }
        $class = 'sb-' . $kind . '--' . str_replace('.', '-', $ref);
        $this->cssRules[$class] = '.' . $class . '{' . self::TOKEN_UTILITIES[$kind] . ':var(' . ResolvedTheme::cssVarName($ref) . ')}';
        return $class;
    }

    /**
     * Register the generated rules for one block, scoped to a class derived
     * from its id. `$declarations` must come from `StyleSurface` (never from
     * authored text). Returns the class to put on the block wrapper.
     */
    public function scopedRule(string $blockId, string $declarations, bool $reduceMotion = false): string
    {
        if ($declarations === '' || preg_match(CanonicalDocumentSchema::BLOCK_ID_PATTERN, $blockId) !== 1) {
            return '';
        }
        $class = 'sb-b-' . substr($blockId, 4);
        $rule = '.' . $class . '{' . $declarations . '}';
        if ($reduceMotion) {
            $rule .= '@media (prefers-reduced-motion:reduce){.' . $class . '{transition:none}}';
        }
        $this->cssRules['~' . $class] = $rule;
        return $class;
    }

    /**
     * Defer a dynamic node; returns the marker to embed in static HTML.
     *
     * @param array<string, mixed> $block normalized block
     */
    public function defer(array $block): string
    {
        $index = count($this->deferred);
        $this->deferred[] = $block;
        return self::marker($this->nonce, $index);
    }

    public static function marker(string $nonce, int $index): string
    {
        return '<!--sb-dyn:' . $nonce . ':' . $index . '-->';
    }

    /** @return list<array<string, mixed>> */
    public function deferred(): array
    {
        return $this->deferred;
    }

    public function css(): string
    {
        ksort($this->cssRules, SORT_STRING);
        return implode('', $this->cssRules);
    }
}
