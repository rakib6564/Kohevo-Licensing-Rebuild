<?php
/**
 * Kohevo Studio (studio-builder) — Resolved Theme.
 *
 * The final symbolic-token -> CSS value map a render uses. Every value in here
 * has already passed `ThemeResolver::sanitizeValue()`; authored documents only
 * ever reference these by NAME (`surface.primary`), and the renderer only ever
 * emits `var(--sb-…)` references plus one `:root{}` block built from this map.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Theme;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;

final class ResolvedTheme
{
    /** @var array<string, string> */
    private readonly array $tokens;

    /**
     * @param array<string, string> $tokens token ref => sanitized CSS value
     */
    public function __construct(
        public readonly string $tokenGroup,
        array $tokens,
    ) {
        $clean = [];
        foreach ($tokens as $ref => $value) {
            if (is_string($ref) && preg_match(CanonicalDocumentSchema::TOKEN_REF_PATTERN, $ref) === 1 && is_string($value)) {
                $clean[$ref] = $value;
            }
        }
        ksort($clean, SORT_STRING);
        $this->tokens = $clean;
    }

    public function has(string $ref): bool
    {
        return isset($this->tokens[$ref]);
    }

    public function value(string $ref): ?string
    {
        return $this->tokens[$ref] ?? null;
    }

    /** @return array<string, string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * `surface.primary` -> `--sb-surface-primary`. Token refs are `[a-z0-9_.]`
     * only, and `-` never appears in them, so `.` -> `-` is injective.
     */
    public static function cssVarName(string $ref): string
    {
        return '--sb-' . str_replace('.', '-', $ref);
    }

    public function rootCss(): string
    {
        $decls = [];
        foreach ($this->tokens as $ref => $value) {
            $decls[] = self::cssVarName($ref) . ':' . $value;
        }
        return ':root{' . implode(';', $decls) . '}';
    }

    public function fingerprint(): string
    {
        return hash('sha256', CanonicalJson::encode(['group' => $this->tokenGroup, 'tokens' => $this->tokens]));
    }
}
