<?php
/**
 * Slate — Theme contract (Presentation).
 *
 * A Theme SKINS the frame without changing its structure (ADR-0008,
 * docs/05-Rendering/theme-and-template-engine.md §2): it supplies token VALUES,
 * font pairings, a chrome preset, and component variant defaults — and nothing
 * else. It ships no markup and no logic; it is a values document.
 *
 *   tokens()            --slate- overrides for this tenant (fed to TokenEmitter)
 *   fontPairing()       system-font stacks (no external fetch by default)
 *   chrome()            header/footer variant the Template consumes
 *   componentDefaults() default tone/size choices for Components
 *
 * "Values, never structure": a Theme may re-point a token or pick a documented
 * component variant; it may not add/remove a token name or reach into markup.
 *
 * Public API surface (platform-foundation §8). Concretes: DefaultTheme, ArrayTheme
 * (Phase 3B B2). The Theme lives in RenderContext and its tokens() are emitted once
 * per response by the TokenEmitter.
 */

declare(strict_types=1);

namespace Slate\Presentation\Theme;

interface Theme
{
    /**
     * Token overrides for this tenant, as bare names (without the leading --) =>
     * value, e.g. ['slate-color-accent' => '#0e7490']. Empty = the DesignTokens
     * defaults stand.
     *
     * @return array<string,string>
     */
    public function tokens(): array;

    /**
     * Font pairing (system stacks by default; no network fetch).
     *
     * @return array{sans:string,mono:string}
     */
    public function fontPairing(): array;

    /** Chrome preset name the Template consumes: full|minimal|widget|email. */
    public function chrome(): string;

    /**
     * Default component variant choices, e.g. ['button' => ['tone' => 'accent']].
     *
     * @return array<string,array<string,string>>
     */
    public function componentDefaults(): array;
}
