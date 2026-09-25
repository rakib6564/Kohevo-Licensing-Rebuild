<?php
/**
 * Unit tests for Phase 3B B1 — the --slate-* token vocabulary + emitter.
 *
 * The names/roles are the contract (design-tokens.md §7); semantics point only at
 * primitives (§3 one-direction rule); the set is emitted once, in one :root block,
 * with dark + theme overrides cascading last (§4/§6).
 */

declare(strict_types=1);

use Slate\Presentation\Tokens\DesignTokens;
use Slate\Presentation\Tokens\TokenEmitter;

// ── DesignTokens ──────────────────────────────────────────
unit('primitives carry the default accent + neutrals', function () {
    $p = DesignTokens::primitives();
    assert_eq('#2563eb', $p['slate-color-blue-600']);
    assert_eq('#ffffff', $p['slate-color-neutral-0']);
    assert_eq('#0f172a', $p['slate-color-neutral-900']);
});

unit('semantic accent points at a primitive; dark re-points it', function () {
    assert_eq('var(--slate-color-blue-600)', DesignTokens::semantics()['slate-color-accent']);
    assert_eq('var(--slate-color-blue-400)', DesignTokens::darkSemantics()['slate-color-accent']);
});

unit('the canonical role tokens are all present (design-tokens.md §7)', function () {
    $names = DesignTokens::semanticNames();
    foreach ([
        'slate-color-accent', 'slate-color-on-accent', 'slate-color-surface',
        'slate-color-surface-sunken', 'slate-color-canvas', 'slate-color-text',
        'slate-color-text-muted', 'slate-color-border', 'slate-color-success',
        'slate-color-warning', 'slate-color-danger', 'slate-color-info',
        'slate-color-focus-ring', 'slate-radius-control',
    ] as $role) {
        assert_true(in_array($role, $names, true), "missing role token: $role");
    }
});

unit('semantics + dark point ONLY at existing primitives (one-direction rule)', function () {
    $primitives = DesignTokens::primitives();
    $check = function (array $tokens) use ($primitives) {
        foreach ($tokens as $name => $value) {
            if (preg_match_all('/var\(--([a-z0-9-]+)\)/', (string) $value, $m)) {
                foreach ($m[1] as $ref) {
                    assert_true(array_key_exists($ref, $primitives),
                        "semantic '$name' references undefined primitive '--$ref'");
                }
            }
        }
    };
    $check(DesignTokens::semantics());
    $check(DesignTokens::darkSemantics());
});

// ── TokenEmitter::css ─────────────────────────────────────
unit('css() emits one wrapped :root block with light, dark, and forced variants', function () {
    $css = TokenEmitter::css();
    assert_true(str_starts_with($css, '<style id="slate-tokens">'), 'wrapped in the token style tag');
    assert_true(str_contains($css, 'color-scheme:light dark;'));
    assert_true(str_contains($css, '--slate-color-accent:var(--slate-color-blue-600);'), 'light accent present');
    assert_true(str_contains($css, '@media (prefers-color-scheme: dark){:root{'), 'dark media set present');
    assert_true(str_contains($css, ':root[data-theme="dark"]{'), 'forced-dark override present');
    assert_true(str_contains($css, ':root[data-theme="light"]{'), 'forced-light override present');
});

unit('css() applies Theme overrides last', function () {
    $css = TokenEmitter::css(['slate-color-accent' => '#0e7490']);
    // The override block is appended after the light/dark blocks.
    $overridePos = strrpos($css, '--slate-color-accent:#0e7490;');
    $lightPos    = strpos($css, '--slate-color-accent:var(--slate-color-blue-600);');
    assert_true($overridePos !== false, 'override value emitted');
    assert_true($overridePos > $lightPos, 'override cascades after the default');
});

unit('css(wrapStyle=false) returns bare CSS with no style tag', function () {
    $css = TokenEmitter::css([], false);
    assert_false(str_contains($css, '<style'));
    assert_true(str_starts_with($css, ':root{'));
});

unit('css(colorScheme=false) omits the behavioral color-scheme declaration', function () {
    assert_true(str_contains(TokenEmitter::css([], true, true), 'color-scheme:light dark;'));
    $inert = TokenEmitter::css([], true, false);
    // The `color-scheme:` DECLARATION is gone (the @media prefers-color-scheme
    // query legitimately still contains the substring — that is not behavioral).
    assert_false(str_contains($inert, 'color-scheme:light dark;'), 'no color-scheme declaration when suppressed');
    // Still defines the vocabulary.
    assert_true(str_contains($inert, '--slate-color-accent:var(--slate-color-blue-600);'));
});

// ── TokenEmitter::emitOnce (single emission guard) ────────
unit('emitOnce emits exactly once per request', function () {
    TokenEmitter::reset();
    assert_false(TokenEmitter::hasEmitted());

    ob_start();
    TokenEmitter::emitOnce();
    $first = ob_get_clean();
    assert_true($first !== '' && str_contains($first, 'slate-tokens'));
    assert_true(TokenEmitter::hasEmitted());

    ob_start();
    TokenEmitter::emitOnce();               // second call must no-op
    $second = ob_get_clean();
    assert_eq('', $second);

    TokenEmitter::reset();                   // leave global state clean for other tests
});
