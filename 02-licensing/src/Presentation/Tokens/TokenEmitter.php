<?php
/**
 * Slate — TokenEmitter: the single --slate-* emission (Phase 3B B1).
 *
 * Writes the whole token set to ONE `:root` block, once per response, in the head
 * before any component renders (design-tokens.md §6). Order inside the block:
 * primitives → light semantics → dark set (via prefers-color-scheme AND an explicit
 * [data-theme] override) → active Theme overrides last, so later rules cascade
 * legitimately over earlier ones.
 *
 * WHY single emission: the legacy vocabularies were injected piecemeal so the last
 * emission silently won and `var(--accent)` became order-dependent. One authoritative
 * `:root` removes order-dependence by construction.
 *
 * `css()` is pure (unit-tested without a DB). `emitOnce()` adds the idempotent,
 * once-per-request guard — the same discipline as slate_brand_accent_emit().
 *
 * STRICTLY ADDITIVE / OPT-IN: no admin or public head calls this yet, so emitting
 * the new vocabulary changes nothing until a surface deliberately opts in (a later,
 * reviewed step). It removes none of the legacy "--cb-", "--sb-", "--glass-" CSS.
 */

declare(strict_types=1);

namespace Slate\Presentation\Tokens;

final class TokenEmitter
{
    /** Per-request guard for emitOnce(). */
    private static bool $emitted = false;

    /**
     * The full token `<style>` block.
     *
     * @param array<string,string> $overrides  Theme token overrides (name without --),
     *                                          applied last (Phase 3B B2 supplies these)
     * @param bool $wrapStyle   wrap in <style id="slate-tokens">…; false = bare CSS
     * @param bool $colorScheme emit `color-scheme: light dark` on :root. This is the
     *                          one behavioral declaration (it changes native control/
     *                          scrollbar theming under OS dark mode); pass false when
     *                          injecting the vocabulary onto EXISTING pages that don't
     *                          yet consume the tokens, to keep the injection non-visual.
     */
    public static function css(array $overrides = [], bool $wrapStyle = true, bool $colorScheme = true): string
    {
        $rootBase = $colorScheme ? ['color-scheme' => 'light dark'] : [];
        $light = self::block(':root', array_merge(
            $rootBase,
            self::prefixed(DesignTokens::primitives()),
            self::prefixed(DesignTokens::semantics()),
        ));

        $darkVars   = self::prefixed(DesignTokens::darkSemantics());
        $darkMedia  = "@media (prefers-color-scheme: dark){" . self::block(':root', $darkVars) . "}";
        // An explicit tenant/user choice wins over the OS preference (higher specificity).
        $darkForced = self::block(':root[data-theme="dark"]', $darkVars);
        // Forcing light re-asserts the light semantics over an OS-dark preference.
        $lightForced = self::block(':root[data-theme="light"]', self::prefixed(DesignTokens::semantics()));

        $css = $light . $darkMedia . $darkForced . $lightForced;

        if ($overrides !== []) {
            $css .= self::block(':root', self::prefixed($overrides));
        }

        return $wrapStyle ? '<style id="slate-tokens">' . $css . '</style>' : $css;
    }

    /**
     * Echo the token block exactly once per request; subsequent calls no-op.
     * A surface that needs tokens *ensures* the block exists via this entry point;
     * it never emits its own copy.
     *
     * @param array<string,string> $overrides
     */
    public static function emitOnce(array $overrides = []): void
    {
        if (self::$emitted) {
            return;
        }
        self::$emitted = true;
        echo self::css($overrides);
    }

    /** Test/lifecycle hook: reset the once-per-request guard. @internal */
    public static function reset(): void
    {
        self::$emitted = false;
    }

    public static function hasEmitted(): bool
    {
        return self::$emitted;
    }

    // ── internals ─────────────────────────────────────────────

    /** Prefix bare token names with `--` for CSS custom-property declarations. */
    private static function prefixed(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $name => $value) {
            // color-scheme is a real property, not a custom property.
            $out[str_starts_with($name, 'slate-') ? '--' . $name : $name] = $value;
        }
        return $out;
    }

    /** @param array<string,string> $decls  property => value */
    private static function block(string $selector, array $decls): string
    {
        $body = '';
        foreach ($decls as $prop => $value) {
            $body .= $prop . ':' . $value . ';';
        }
        return $selector . '{' . $body . '}';
    }
}
