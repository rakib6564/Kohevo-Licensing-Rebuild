<?php
/**
 * Slate — DefaultTheme: the platform default skin (Presentation, Phase 3B B2).
 *
 * The zero-override Theme: it adds no token overrides, so the DesignTokens light
 * defaults (and the dark set) stand as-is; system font pairing; full chrome; no
 * component variant overrides. This is what RenderContext carries when a tenant
 * has not customized anything.
 *
 * Implements the contract directly (rather than subclassing the final ArrayTheme)
 * so both stay final value objects.
 *
 * @internal — a concrete implementation of the Slate\Presentation\Theme contract.
 */

declare(strict_types=1);

namespace Slate\Presentation\Theme;

use Slate\Presentation\Tokens\DesignTokens;

final class DefaultTheme implements Theme
{
    public function tokens(): array
    {
        return [];
    }

    /** @return array{sans:string,mono:string} */
    public function fontPairing(): array
    {
        $p = DesignTokens::primitives();
        return ['sans' => $p['slate-font-sans'], 'mono' => $p['slate-font-mono']];
    }

    public function chrome(): string
    {
        return 'full';
    }

    public function componentDefaults(): array
    {
        return [];
    }
}
