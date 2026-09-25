<?php
/**
 * Slate — DesignTokens: the canonical --slate-* vocabulary (Phase 3B B1).
 *
 * The single styling vocabulary for admin AND public (ADR-0008,
 * docs/04-Design-System/design-tokens.md). Two tiers:
 *
 *   primitives  — raw palette + scales, no usage opinion (--slate-color-blue-600…)
 *   semantics   — role tokens that POINT AT primitives (--slate-color-accent…);
 *                 Components consume ONLY these.
 *   darkSemantics — the dark value set: the same semantic names re-pointed.
 *
 * The names/roles here are the contract (design-tokens.md §7); the values are the
 * default Theme (light). A per-tenant Theme (Phase 3B B2) overrides values only.
 *
 * Pure data — no DB, no emission. TokenEmitter turns these into the one :root
 * block. STRICTLY ADDITIVE: this introduces a new vocabulary alongside the legacy
 * "--cb-", "--sb-", and "--glass-" sets; it removes nothing and, until something
 * opts in to emit it, changes no pixel.
 */

declare(strict_types=1);

namespace Slate\Presentation\Tokens;

final class DesignTokens
{
    /**
     * Raw reference values — the palette and scales semantics point at.
     * @return array<string,string>  token name (without leading --) => value
     */
    public static function primitives(): array
    {
        return [
            // Palette — accent ramp
            'slate-color-blue-400'    => '#60a5fa',
            'slate-color-blue-500'    => '#3b82f6',
            'slate-color-blue-600'    => '#2563eb',
            'slate-color-blue-700'    => '#1d4ed8',
            // Palette — neutrals
            'slate-color-neutral-0'   => '#ffffff',
            'slate-color-neutral-50'  => '#f8fafc',
            'slate-color-neutral-100' => '#f1f5f9',
            'slate-color-neutral-200' => '#e2e8f0',
            'slate-color-neutral-300' => '#cbd5e1',
            'slate-color-neutral-400' => '#94a3b8',
            'slate-color-neutral-500' => '#64748b',
            'slate-color-neutral-600' => '#475569',
            'slate-color-neutral-700' => '#334155',
            'slate-color-neutral-800' => '#1e293b',
            'slate-color-neutral-900' => '#0f172a',
            'slate-color-neutral-950' => '#020617',
            // Palette — status
            'slate-color-green-500'   => '#22c55e',
            'slate-color-green-600'   => '#16a34a',
            'slate-color-amber-500'   => '#f59e0b',
            'slate-color-red-500'     => '#ef4444',
            'slate-color-red-600'     => '#dc2626',

            // Spacing scale (0.25rem → 4rem)
            'slate-space-1'  => '0.25rem',
            'slate-space-2'  => '0.5rem',
            'slate-space-3'  => '0.75rem',
            'slate-space-4'  => '1rem',
            'slate-space-5'  => '1.25rem',
            'slate-space-6'  => '1.5rem',
            'slate-space-7'  => '1.75rem',
            'slate-space-8'  => '2rem',
            'slate-space-9'  => '2.25rem',
            'slate-space-10' => '2.5rem',
            'slate-space-11' => '3rem',
            'slate-space-12' => '4rem',

            // Radii
            'slate-radius-sm'   => '4px',
            'slate-radius-md'   => '8px',
            'slate-radius-lg'   => '16px',
            'slate-radius-pill' => '999px',

            // Type
            'slate-font-sans' => 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
            'slate-font-mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
            // Headings follow the body family unless a theme says otherwise.
            // A var() rather than a duplicated stack: custom properties resolve
            // at use time, so declaration order does not matter, and there is
            // only one system stack to keep correct. Themes override this the
            // way they override any token — through Theme::tokens(), which is
            // what actually reaches the emitted CSS. (Theme::fontPairing() looks
            // like the mechanism for this and is unreached; see the follow-ups.)
            'slate-font-heading' => 'var(--slate-font-sans)',
            'slate-font-size-sm'   => '0.875rem',
            'slate-font-size-md'   => '1rem',
            'slate-font-size-lg'   => '1.125rem',
            'slate-font-size-xl'   => '1.25rem',
            'slate-font-size-2xl'  => '1.5rem',
            'slate-font-size-3xl'  => '2rem',
            'slate-font-weight-normal'    => '400',
            'slate-font-weight-medium'    => '500',
            // 600 and 800 are in use by the SBK typography presets. 300 is not,
            // and was left out rather than added "for completeness" — an unused
            // primitive is a token Phase E would have to delete later.
            'slate-font-weight-semibold'  => '600',
            'slate-font-weight-bold'      => '700',
            'slate-font-weight-extrabold' => '800',
            'slate-line-height-tight'  => '1.2',
            'slate-line-height-normal' => '1.5',

            // Elevation
            'slate-shadow-1' => '0 1px 2px rgba(15,23,42,.06)',
            'slate-shadow-2' => '0 2px 8px rgba(15,23,42,.08)',
            'slate-shadow-3' => '0 8px 24px rgba(15,23,42,.12)',

            // Motion
            'slate-motion-fast' => '120ms',
            'slate-motion-base' => '200ms',
            'slate-motion-ease' => 'cubic-bezier(.2,0,0,1)',

            // Z-index
            'slate-z-base'     => '0',
            'slate-z-sticky'   => '100',
            'slate-z-dropdown' => '200',
            'slate-z-modal'    => '1000',
            'slate-z-toast'    => '1100',
        ];
    }

    /**
     * Role tokens (the light default). Components read ONLY these; each points at
     * a primitive so a Theme re-brands by overriding the primitive.
     * @return array<string,string>
     */
    public static function semantics(): array
    {
        return [
            'slate-color-accent'         => 'var(--slate-color-blue-600)',
            'slate-color-on-accent'      => 'var(--slate-color-neutral-0)',
            'slate-color-surface'        => 'var(--slate-color-neutral-0)',
            'slate-color-surface-sunken' => 'var(--slate-color-neutral-100)',
            'slate-color-canvas'         => 'var(--slate-color-neutral-50)',
            'slate-color-text'           => 'var(--slate-color-neutral-900)',
            'slate-color-text-muted'     => 'var(--slate-color-neutral-600)',
            'slate-color-border'         => 'var(--slate-color-neutral-200)',
            'slate-color-success'        => 'var(--slate-color-green-600)',
            'slate-color-warning'        => 'var(--slate-color-amber-500)',
            'slate-color-danger'         => 'var(--slate-color-red-600)',
            'slate-color-info'           => 'var(--slate-color-blue-500)',
            'slate-color-focus-ring'     => 'var(--slate-color-blue-600)',
            'slate-radius-control'       => 'var(--slate-radius-md)',

            // Typographic ROLES. A theme sets these to whatever literal it
            // wants — '750' is a real value in the SBK bold preset and is not a
            // step on any scale — so these are defaults, not a set of values
            // themes must round onto. That distinction is the whole reason the
            // weights below are semantics and not extra primitives.
            //
            // Defaults are the editorial preset's values, which three of the
            // five SBK themes already use.
            'slate-font-weight-body'     => 'var(--slate-font-weight-normal)',
            'slate-font-weight-heading'  => 'var(--slate-font-weight-medium)',
            'slate-font-weight-display'  => 'var(--slate-font-weight-medium)',
            'slate-font-weight-nav'      => 'var(--slate-font-weight-medium)',
            'slate-font-weight-button'   => 'var(--slate-font-weight-semibold)',
            'slate-font-weight-strong'   => 'var(--slate-font-weight-semibold)',

            // Tracking is a genuine typographic axis with independent consumers,
            // unlike text-transform, which had one consumer and became a
            // component default instead of a token.
            'slate-tracking-heading'     => '-.015em',
            'slate-tracking-nav'         => '.01em',

            // Secondary colour roles. --slate-* could not express these, which
            // is why --sb-accent-2 and --sb-ink-2 had no migration target.
            //
            // text-secondary sits BETWEEN text and text-muted, and that ordering
            // is the point: it was added because --sb-ink-2 is a second dark ink
            // (#164232, #1f2937) and not a muted grey (#5a6b7d, #6b7280), so
            // mapping it onto text-muted would have flattened two distinct
            // colours and lightened every secondary heading.
            // A darkened accent, for the accent used as TEXT. The bright accent
            // is a background colour: on a light canvas it is unreadable as type
            // (#FFCB70 on white is 1.50:1). Links, prices, card titles and
            // outline-button labels read this instead.
            'slate-color-accent-strong'    => 'var(--slate-color-blue-700)',
            'slate-color-accent-secondary' => 'var(--slate-color-blue-500)',
            'slate-color-text-secondary'   => 'var(--slate-color-neutral-700)',

            'slate-shadow-focus'         => '0 0 0 3px rgba(37,99,235,.4)',
        ];
    }

    /**
     * The dark value set — the same semantic names, re-pointed for AA on dark
     * (design-tokens.md §4). A Component author writes zero dark CSS.
     * @return array<string,string>
     */
    public static function darkSemantics(): array
    {
        return [
            'slate-color-accent'         => 'var(--slate-color-blue-400)',   // lifted for AA on dark
            'slate-color-on-accent'      => 'var(--slate-color-neutral-950)',
            'slate-color-surface'        => 'var(--slate-color-neutral-900)',
            'slate-color-surface-sunken' => 'var(--slate-color-neutral-950)',
            'slate-color-canvas'         => 'var(--slate-color-neutral-950)',
            'slate-color-text'           => 'var(--slate-color-neutral-0)',
            'slate-color-text-muted'     => 'var(--slate-color-neutral-400)',
            'slate-color-border'         => 'var(--slate-color-neutral-700)',
            'slate-color-focus-ring'     => 'var(--slate-color-blue-400)',
            'slate-shadow-focus'         => '0 0 0 3px rgba(96,165,250,.5)',
        ];
    }

    /** The semantic role-token names Components are guaranteed to find. */
    public static function semanticNames(): array
    {
        return array_keys(self::semantics());
    }
}
