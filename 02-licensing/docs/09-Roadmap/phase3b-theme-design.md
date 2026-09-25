# Phase 3B — Theme Engine (design note)

**Status:** Implemented · **Date:** 2026-08-11 · **Branch:** `phase3a-content-foundation` (continues)

> **Outcome (all batches shipped, suite green 154 unit / 34 integration / 21 smoke):**
> - **B1** `--slate-*` vocabulary (`DesignTokens`) + single-emission `TokenEmitter` (primitives → semantics → dark → theme overrides; one-direction rule enforced).
> - **B2** `Theme` contract + `DefaultTheme`/`ArrayTheme` + `TenantThemeResolver` (brand color → token overrides, WCAG on-accent); threaded through `RenderContext`.
> - **B3** `Template` + `DocumentTemplate` (full-page frame, one token block in head) + `TemplateResolver` (content-type precedence) + `PageAssembler` (Page → document / bare fragment).
> - **B4** `slate_c_button/card/grid/media` Components + token-pure `components.css` (opt-in `slate_components_emit()`).
> - **B5** end-to-end proof: a Block composing Components renders through the full stack into a themed document (token block emitted once); fragment surface stays bare.
>
> **Kept strictly additive:** the new vocabulary/components live alongside the five legacy token sets and three kits — nothing removed, nothing auto-injected into live heads.
> **Deferred (each its own reviewed step):** consolidating/removing the legacy vocabularies + kits; migrating existing blocks onto Components (3C); auto-injecting tokens into admin/public heads; wiring `render.php` onto `PageAssembler` (the 3A render cutover, still pending).

## 0. Framing

3B gives the content spine its *skin*: the one `--slate-*` design-token vocabulary,
a per-tenant **Theme**, a **Template** (document frame + regions), and the start of
the one **Component** library. It fills the `theme` slot `RenderContext` already
carries and lets `PageRenderer` output become a full document instead of just the
content region.

The model is fully pre-specified — **ADR-0008**, `docs/04-Design-System/design-tokens.md`
(§7 is the canonical token set), `component-library.md`, and
`docs/05-Rendering/theme-and-template-engine.md`. 3B translates those into code.

## 1. The hard constraint: additive, no big-bang consolidation

The codebase has **five live token vocabularies** — `--cb-*` (14), `--sb-*` (35),
`--glass-*` (11), `--accent-*` (4) — and three admin component kits. ADR-0008 and
both design-system specs say the migration off them is **"incremental, not
big-bang."** This dir is production.

**So 3B builds the new layer strictly ALONGSIDE the old, changing nothing's
appearance.** The `--slate-*` set is emitted only through a new opt-in helper that
nothing calls in the live admin/public heads yet; existing pages keep their current
CSS untouched. Ripping out `--cb-*`/`--sb-*`/kits, and auto-injecting `--slate-*`
into the admin/public heads, are **separate reviewed cutover steps** (like the 3A
render cutover) — explicitly out of 3B.

## 2. Scope (batches)

Each = one green commit, `bash tests/run.sh`, additive + BC.

1. **B1 — Token vocabulary.** `Slate\Presentation\Tokens\{DesignTokens, TokenEmitter}`:
   the canonical `--slate-*` primitives → semantics → dark set (design-tokens.md §7),
   and an emitter that renders one `:root` `<style>` block (primitives → semantics →
   dark via `prefers-color-scheme` + `[data-theme]` → optional Theme overrides last).
   Pure/unit-tested; plus an idempotent `slate_tokens_emit()` global wrapper (single
   emission per response, the `slate_brand_accent_emit()` discipline). **Non-visual:
   nothing consumes it yet.**
2. **B2 — Theme.** `Slate\Presentation\Theme` interface (tokens/fontPairing/chrome/
   componentDefaults) + `DefaultTheme` + a tenant-override resolver that maps existing
   brand settings (accent, etc.) to `--slate-*` overrides. Wire a resolved Theme into
   `RenderContext`. Emitter takes the Theme's overrides.
3. **B3 — Template.** `Slate\Presentation\Template` interface (name/regions/render) +
   `DocumentTemplate` (the default `<html>/<head>/regions` frame, head = tokens +
   SEO slot + assets slot) + content-type-aware selection. A `PageAssembler` composes
   Template + Theme + `PageRenderer` content into a full document — available, not
   yet wired to production `render.php`.
4. **B4 — Component starter set.** `slate_c_button/card/grid/media` in
   `includes/components/*` consuming `--slate-*` semantics only (component-library.md
   §3 contract). Small, proves the contract; existing blocks are NOT migrated onto
   them here (that's 3C).
5. **B5 (optional) — End-to-end demo, gated.** Render one page through
   Template+Theme+Components+PageRenderer and assert it is a well-formed document with
   the token block emitted once. Opt-in/parity-style; **no production cutover.**

## 3. Open decisions for review

1. **Consolidation posture:** strictly additive in 3B (recommended), or begin
   migrating one old vocabulary now? — *recommend strictly additive; zero visual risk.*
2. **Component breadth in B4:** minimal starter (Button/Card/Grid/Media, recommended)
   vs the fuller catalogue (Field/Badge/Alert/Modal/Tabs…) now.
3. **Token loading:** keep `--slate-*` behind an opt-in emitter only (recommended), or
   auto-inject into the admin/public `<head>` this phase (a visible, reviewable change).

## 4. Non-goals (deferred)

Removing `--cb-*/--sb-*/--glass-*` or the three admin kits; migrating existing
blocks/pages onto Components; auto-injecting tokens into live heads; wiring
`render.php` onto the Template/PageAssembler (the 3A render cutover, still pending);
the visual builder (3E).
