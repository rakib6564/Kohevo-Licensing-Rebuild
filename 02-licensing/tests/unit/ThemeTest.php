<?php
/**
 * Unit tests for Phase 3B B2 — Theme contract, DefaultTheme/ArrayTheme, and the
 * TenantThemeResolver. A Theme supplies token VALUES only; overriding the accent
 * role re-points it; the emitter applies the overrides last. (theme-and-template-
 * engine.md §2, design-tokens.md §5.)
 */

declare(strict_types=1);

use Slate\Presentation\Theme\Theme;
use Slate\Presentation\Theme\DefaultTheme;
use Slate\Presentation\Theme\ArrayTheme;
use Slate\Presentation\Theme\TenantThemeResolver;
use Slate\Presentation\Tokens\TokenEmitter;
use Slate\Presentation\Tokens\DesignTokens;
use Slate\Presentation\RenderContext;

// ── DefaultTheme ──────────────────────────────────────────
unit('DefaultTheme adds no overrides and uses system fonts + full chrome', function () {
    $t = new DefaultTheme();
    assert_true($t instanceof Theme);
    assert_eq([], $t->tokens());
    assert_eq('full', $t->chrome());
    assert_eq([], $t->componentDefaults());
    $fp = $t->fontPairing();
    assert_eq(DesignTokens::primitives()['slate-font-sans'], $fp['sans']);
    assert_true(str_contains($fp['mono'], 'monospace'));
});

// ── ArrayTheme ────────────────────────────────────────────
unit('ArrayTheme returns its tokens and falls back to default fonts', function () {
    $t = new ArrayTheme(
        tokens: ['slate-color-accent' => '#123456'],
        chrome: 'minimal',
        componentDefaults: ['button' => ['tone' => 'accent']],
    );
    assert_eq('#123456', $t->tokens()['slate-color-accent']);
    assert_eq('minimal', $t->chrome());
    assert_eq('accent', $t->componentDefaults()['button']['tone']);
    // Font pairing not supplied → default sans stack.
    assert_eq(DesignTokens::primitives()['slate-font-sans'], $t->fontPairing()['sans']);
});

// ── TenantThemeResolver ───────────────────────────────────
unit('resolver returns DefaultTheme for null/empty/default accent', function () {
    assert_eq([], TenantThemeResolver::fromBrandAccent(null)->tokens());
    assert_eq([], TenantThemeResolver::fromBrandAccent('')->tokens());
    assert_eq([], TenantThemeResolver::fromBrandAccent('#2563EB')->tokens());
    assert_eq([], TenantThemeResolver::fromBrandAccent('not-a-hex')->tokens());
});

unit('resolver maps a dark brand color to accent + white on-accent + focus ring', function () {
    $t = TenantThemeResolver::fromBrandAccent('#0e7490');   // teal (dark)
    $tokens = $t->tokens();
    assert_eq('#0E7490', $tokens['slate-color-accent'], 'hex upper-cased');
    assert_eq('#FFFFFF', $tokens['slate-color-on-accent'], 'dark accent → white text');
    assert_eq('#0E7490', $tokens['slate-color-focus-ring']);
});

unit('resolver picks near-black on-accent for a light brand color', function () {
    $t = TenantThemeResolver::fromBrandAccent('#FFFF00');    // yellow (light)
    assert_eq('#16181D', $t->tokens()['slate-color-on-accent']);
});

// ── Theme → emitter integration ───────────────────────────
unit('a themed accent override is emitted last by the TokenEmitter', function () {
    $theme = TenantThemeResolver::fromBrandAccent('#0e7490');
    $css   = TokenEmitter::css($theme->tokens());
    $overridePos = strrpos($css, '--slate-color-accent:#0E7490;');
    $defaultPos  = strpos($css, '--slate-color-accent:var(--slate-color-blue-600);');
    assert_true($overridePos !== false, 'themed accent present');
    assert_true($overridePos > $defaultPos, 'theme override cascades last');
});

// ── RenderContext carries the Theme ───────────────────────
unit('RenderContext.withTheme threads a Theme without changing signatures', function () {
    $theme = new DefaultTheme();
    $ctx = RenderContext::for(1)->withTheme($theme);
    assert_true($ctx->theme instanceof Theme);
    assert_eq(1, $ctx->tenantId);
});
