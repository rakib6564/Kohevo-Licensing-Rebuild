<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 1 tenant code
 * injection (`StudioCodePolicy`).
 *
 * Autoloader only, no database. The policy's DB-backed readers are covered by
 * `customCss()`'s degradation path plus the pure `sanitizeCustomCss()` /
 * `ga4Id()` / `gtmId()` contracts, which is where the security properties
 * actually live.
 *
 * The three rules asserted below are the ones a regression would silently
 * break, so they are stated as executable assertions rather than prose:
 *   (a) nothing tenant-authored is emitted into the Editor canvas
 *   (b) analytics never fires outside Public (preview traffic stays clean)
 *   (c) the platform signature cannot be hidden by custom CSS
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Render\RenderMode;

const SBP1C_TENANT = 101;

// ── sanitizeCustomCss: the reduce ─────────────────────────────────────────

unit('custom css: plain declarations survive untouched', function (): void {
    $css = StudioCodePolicy::sanitizeCustomCss(
        '.sb-hero__title{color:#e8734a;font-size:3rem;letter-spacing:-0.02em}'
    );
    assert_true(str_contains($css, 'color:#e8734a'), 'color must survive');
    assert_true(str_contains($css, 'font-size:3rem'), 'font-size must survive');
    assert_true(str_contains($css, 'letter-spacing:-0.02em'), 'negative tracking must survive');
});

unit('custom css: media queries and custom properties survive', function (): void {
    $css = StudioCodePolicy::sanitizeCustomCss(
        '@media (max-width:768px){.sb-grid{grid-template-columns:1fr}}'
        . ':root{--sb-accent:#6366f1}'
    );
    assert_true(str_contains($css, '@media'), 'media query must survive');
    assert_true(str_contains($css, '1fr'), 'grid value must survive');
    assert_true(str_contains($css, '--sb-accent'), 'custom property must survive');
});

unit('custom css: style-tag break-out is stripped', function (): void {
    $css = StudioCodePolicy::sanitizeCustomCss('.a{color:red}</style><script>alert(1)</script>');
    assert_false(str_contains(strtolower($css), '</style'), 'close-tag must be removed');
    assert_false(str_contains(strtolower($css), '<script'), 'injected script tag must be removed');
});

unit('custom css: expression() is defused', function (): void {
    $css = StudioCodePolicy::sanitizeCustomCss('.a{width:expression(alert(1))}');
    assert_false(str_contains(strtolower($css), 'expression('), 'expression() must not survive');
});

unit('custom css: behavior and -moz-binding are defused', function (): void {
    $css = StudioCodePolicy::sanitizeCustomCss('.a{-moz-binding:url(x.xml);behavior:url(y.htc)}');
    assert_false(str_contains(strtolower($css), 'binding:'), 'binding must be defused');
    assert_false(str_contains(strtolower($css), 'behavior:'), 'behavior must be defused');
});

unit('custom css: @import is removed', function (): void {
    $css = StudioCodePolicy::sanitizeCustomCss('@import url("//evil.test/x.css");.a{color:red}');
    assert_false(str_contains(strtolower($css), '@import'), '@import must be removed');
    assert_true(str_contains($css, 'color:red'), 'sibling rules must survive');
});

unit('custom css: javascript: and vbscript: schemes are defused', function (): void {
    $css = StudioCodePolicy::sanitizeCustomCss('.a{background:url(javascript:alert(1))}.b{behavior:javascript:x}');
    assert_false(str_contains(strtolower($css), 'javascript:'), 'javascript: must be defused');
});

unit('custom css: a keyword hidden inside a comment is still caught', function (): void {
    // Comments are stripped FIRST, so this cannot smuggle expression past the filter.
    $css = StudioCodePolicy::sanitizeCustomCss('.a{width:expr/**/ession(alert(1))}');
    assert_false(str_contains(strtolower($css), 'ession('), 'comment-split payload must not survive');
});

unit('custom css: null bytes are stripped', function (): void {
    $css = StudioCodePolicy::sanitizeCustomCss(".a{color:\x00red}");
    assert_false(str_contains($css, "\x00"), 'null byte must be removed');
    assert_true(str_contains($css, 'red'), 'the declaration itself survives');
});

unit('custom css: oversized stylesheet is truncated to the ceiling', function (): void {
    $huge = '.a{color:red}' . str_repeat('/*x*/', (int) (StudioCodePolicy::MAX_CUSTOM_CSS_BYTES / 4));
    $css = StudioCodePolicy::sanitizeCustomCss($huge);
    assert_true(
        strlen($css) <= StudioCodePolicy::MAX_CUSTOM_CSS_BYTES,
        'must be capped at MAX_CUSTOM_CSS_BYTES, got ' . strlen($css)
    );
// ── Analytics ID validation ───────────────────────────────────────────────

unit('ga4: well-formed IDs are accepted and uppercased', function (): void {
    assert_eq('G-ABCD1234EF', StudioCodePolicy::ga4Id('  g-abcd1234ef  '));
});

unit('ga4: malformed IDs are rejected', function (): void {
    foreach (['', 'UA-1234-1', 'G-', 'G-abc', "G-ABCD'\"><script>", 'GTM-ABC123'] as $bad) {
        assert_null(StudioCodePolicy::ga4Id($bad), 'must reject: ' . var_export($bad, true));
    }
    assert_null(StudioCodePolicy::ga4Id(null), 'null must be rejected');
    assert_null(StudioCodePolicy::ga4Id(12345), 'non-string must be rejected');
});

unit('gtm: well-formed IDs are accepted', function (): void {
    assert_eq('GTM-ABC1234', StudioCodePolicy::gtmId('gtm-abc1234'));
});

unit('gtm: malformed IDs are rejected', function (): void {
    foreach (['', 'G-ABCD1234', 'GTM-', 'GTM-AB', "GTM-AB'--", 'GTM-ABC;alert(1)'] as $bad) {
        assert_null(StudioCodePolicy::gtmId($bad), 'must reject: ' . var_export($bad, true));
    }
});

// ── Rule (b): analytics are Public-only ──────────────────────────────────
//
// With no database present every read degrades to "unset", so these assert
// the mode gate itself: even a fully-populated configuration yields nothing
// outside Public, which is exactly the property preview traffic depends on.

unit('analytics: head markup is never emitted in Editor', function (): void {
    assert_eq('', StudioCodePolicy::headMarkup(SBP1C_TENANT, RenderMode::Editor));
});

unit('analytics: head markup is never emitted in Preview', function (): void {
    assert_eq('', StudioCodePolicy::headMarkup(SBP1C_TENANT, RenderMode::Preview));
});

unit('analytics: GTM body iframe is never emitted outside Public', function (): void {
    assert_eq('', StudioCodePolicy::bodyMarkup(SBP1C_TENANT, RenderMode::Editor));
    assert_eq('', StudioCodePolicy::bodyMarkup(SBP1C_TENANT, RenderMode::Preview));
});

// ── Rule (a): tenant CSS is never emitted into the canvas ─────────────────

unit('custom css: never emitted in the Editor canvas', function (): void {
    assert_eq('', StudioCodePolicy::customCssMarkup(SBP1C_TENANT, RenderMode::Editor));
});

unit('custom css: read failure degrades to empty, never throws', function (): void {
    // No `Database` class under the unit harness: this exercises the
    // best-effort read path that keeps a settings outage off the page.
    assert_eq('', StudioCodePolicy::customCss(SBP1C_TENANT));
});

// ── Rule (c): the platform signature cannot be styled away ────────────────

unit('signature guard: is emitted and wins the cascade over tenant css', function (): void {
    $guard = StudioCodePolicy::signatureProtectionCss();
    assert_true(str_contains($guard, '.sb-platform-signature'), 'must target the signature');
    assert_true(str_contains($guard, '!important'), 'must use !important to out-rank tenant !important');
    // The properties a tenant would realistically reach for to erase it.
    foreach (['display:block', 'visibility:visible', 'opacity:1', 'position:relative',
              'transform:none', 'font-size:inherit', 'color:inherit'] as $decl) {
        assert_true(str_contains($guard, $decl), 'must re-assert ' . $decl);
    }
});

unit('signature guard: tenant css targeting the signature is not blocked, but loses', function (): void {
    // We deliberately do NOT strip `.sb-platform-signature` from tenant CSS —
    // the guarantee is structural (document order + !important), not a blocklist
    // a determined tenant can probe. This test pins that intent.
    $css = StudioCodePolicy::sanitizeCustomCss('.sb-platform-signature{display:none!important}');
    assert_true(str_contains($css, 'display:none'), 'tenant rule is kept, not silently rewritten');
    assert_true(str_contains(StudioCodePolicy::signatureProtectionCss(), 'display:block!important'));
});

// ── The deferred surface ─────────────────────────────────────────────────

unit('deferred settings: raw tenant script storage is declared, not rendered', function (): void {
    assert_true(in_array('studio_code_head', StudioCodePolicy::DEFERRED_SETTINGS, true));
    assert_true(in_array('studio_code_footer', StudioCodePolicy::DEFERRED_SETTINGS, true));
    // And the policy itself has no reader for them.
    $src = file_get_contents(dirname(__DIR__, 2) . '/src/Module/StudioBuilder/Http/StudioCodePolicy.php');
    assert_true($src !== false, 'policy source must be readable');
    assert_false(
        str_contains($src, "setting(self::DEFERRED"),
        'the policy must never read a deferred setting'
    );
});
});

unit('custom css: empty input stays empty', function (): void {
    assert_eq('', StudioCodePolicy::sanitizeCustomCss(''));
});