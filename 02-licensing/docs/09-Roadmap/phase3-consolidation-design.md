# Phase 3 — Token consolidation (design note, incremental)

**Status:** Slice 1 (accent) Implemented & LIVE · **Date:** 2026-08-11 · **Branch:** `phase3a-content-foundation` (continues)

> **Slice 1 outcome — accent aliased, `token_bridge` = 'on' in production.**
> Verification corrected one assumption in §2/§3: the content-builder **public** CSS
> (`public.css`/`builder.css`) does **not** consume `var(--accent)` at all (public blocks
> use `--cb-*`/`--sb-*`/`rx-*`), so the public bridge is **inert** — confirmed live: on
> `/p/home` the only diff vs baseline is the added `slate-token-bridge` `<style>` (every
> other diff line is Cloudflare per-request nonce noise), with **zero** `var(--accent)`
> consumers on the page. The real `--accent` consumer is the **admin** shell, wired via the
> `admin_head` hook (fires after `slate_brand_accent_emit`, so the alias wins). Admin is
> **value-preserving by construction**: admin `--accent` already = `brand_accent_color` =
> `--slate-color-accent`; `--on-accent` uses the *identical* WCAG-luminance formula; the
> derived `--accent-deep/-soft/-hover/-ring` are left untouched. Kill-switch: site setting
> `token_bridge` != 'on'. (Authed admin was verified by construction + matching values, not
> a live screenshot — worth an eyeball on next admin login.)

## 0. Goal & the hard constraint

ADR-0008 wants **one** styling vocabulary (`--slate-*`) that admin and public both
read. Today there are five: `--cb-*` (14), `--sb-*` (35), `--glass-*` (11),
`--accent-*` (4), plus the landing/shop locals. ADR-0008 is explicit that the
migration off them is **"incremental, not big-bang"** — and this dir is production.

So this is **not** a rewrite of ~300 templates. It is a sequence of small, reversible,
**value-preserving** slices, each verified live, each behind a flag.

## 1. Strategy: ALIAS, don't rewrite

Instead of editing every `.cb-*`/`.sb-*` rule to read `--slate-*`, redefine the legacy
tokens as **aliases** that source from the `--slate-*` single source:

```css
/* bridge block, emitted AFTER the legacy definitions so it wins the cascade */
:root {
  --accent:    var(--slate-color-accent);
  --on-accent: var(--slate-color-on-accent);
}
```

Now every existing rule that reads `--accent` transparently sources the one
`--slate-color-accent` value — the ADR-0008 win (one override re-brands everything)
with **zero template churn**. Later slices alias more concepts; only much later, once
everything sources from `--slate-*`, do we delete the legacy *definitions*.

## 2. Why this is safe here (the value-preserving property)

The accent concept is already single-sourced in practice — it just isn't *named* once:

- Admin base default is `--accent: #2563EB`; `slate_brand_accent_emit()` overrides it
  to `brand_accent_color` when set.
- `--slate-color-accent` defaults to `#2563eb` and my `TenantThemeResolver` overrides
  it to the **same** `brand_accent_color`.

So `--accent` and `--slate-color-accent` **already resolve to the same value** in both
the default and branded cases. Aliasing `--accent → var(--slate-color-accent)` is a
**visual no-op** — it changes the *source of truth*, not the rendered color.

## 3. First slice (proposed)

**Scope: the ACCENT concept only, admin + public, alias-only.**

- Alias `--accent` and `--on-accent` → the `--slate-*` equivalents, emitted after the
  legacy accent emission (public: append to the `injectHeadTokens` block; admin: a new
  emit right after `slate_brand_accent_emit()`).
- **Not** in this slice: the derived `--accent-deep/-soft/-hover/-ring` (they are
  PHP-computed via `color-mix` from the accent at emit time, independent declarations)
  and the `--cb-*`/`--sb-*` accent names (their values are theme-preset-specific and
  need per-value mapping — a later slice). Keeping slice 1 to `--accent`/`--on-accent`
  keeps it provably value-preserving.

**Flag:** site setting `token_bridge` (default `off`). Turn on only after verifying.
**Reversible:** removing the alias block (or flag `off`) restores the prior state exactly.

## 4. Verification (per slice)

1. Capture live `/p/home`, `/p/demo`, and an admin page before.
2. Enable the flag; capture after.
3. Assert the only diff is the added alias lines and that **computed colors are
   identical** (spot-check the accent-colored elements). A visual no-op passes.
4. Unit test: the bridge block aliases the intended legacy names to `var(--slate-*)`
   and is emitted after the sources.

## 5. Rollout order (later slices, each its own commit + review gate)

1. **Accent** (`--accent`, `--on-accent`) — this note.
2. **Surface/canvas/text/border** (the neutral roles) — needs value mapping since
   legacy neutrals may differ from the `--slate-*` neutral defaults; map so values are
   preserved, or accept a reviewed, tiny delta.
3. **`--cb-*` semantic set** → alias to `--slate-*`.
4. **`--sb-*` semantic set** → alias to `--slate-*`.
5. **`--glass-*`** (admin) → alias where a `--slate-*` equivalent exists.
6. Only after a vocabulary is fully aliased and nothing defines it independently:
   **delete its legacy definitions**.

Each slice: value-preserving, flagged, live-verified, reversible. No big-bang.

## 6. Non-goals

Rewriting template class names; removing any legacy *definition* in an early slice;
migrating blocks onto Components (that's 3C); touching the render/token work already
shipped (render cutover + inert token injection are done and unaffected).

## 7. Open questions for review

1. **Approve the alias-not-rewrite strategy?** (recommend yes — it's the only
   zero-churn, reversible path on a live site.)
2. **First slice = accent-only, value-preserving?** (recommend yes.)
3. **Flag default** `off` with me flipping it on after live verification (recommend),
   or ship `on` given the proven no-op?
4. **Admin verification surface** — which admin page should I diff before/after
   (dashboard? settings?) as the representative check?
