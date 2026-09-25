<?php
/**
 * Slate — ArrayTheme: a Theme built from plain arrays (Presentation, Phase 3B B2).
 *
 * The general-purpose Theme value object — construct it from token overrides, a
 * font pairing, a chrome preset, and component defaults. DefaultTheme and the
 * TenantThemeResolver both produce ArrayThemes. Immutable; pure (no DB, no I/O).
 *
 * @internal — a concrete implementation of the Slate\Presentation\Theme contract.
 */

declare(strict_types=1);

namespace Slate\Presentation\Theme;

use Slate\Presentation\Tokens\DesignTokens;

final class ArrayTheme implements Theme
{
    /**
     * @param array<string,string>                    $tokens
     * @param array{sans?:string,mono?:string}        $fontPairing
     * @param array<string,array<string,string>>      $componentDefaults
     */
    public function __construct(
        private readonly array $tokens = [],
        private readonly array $fontPairing = [],
        private readonly string $chrome = 'full',
        private readonly array $componentDefaults = [],
    ) {}

    public function tokens(): array
    {
        return $this->tokens;
    }

    /** @return array{sans:string,mono:string} */
    public function fontPairing(): array
    {
        $p = DesignTokens::primitives();
        return [
            'sans' => $this->fontPairing['sans'] ?? $p['slate-font-sans'],
            'mono' => $this->fontPairing['mono'] ?? $p['slate-font-mono'],
        ];
    }

    public function chrome(): string
    {
        return $this->chrome;
    }

    public function componentDefaults(): array
    {
        return $this->componentDefaults;
    }
}
