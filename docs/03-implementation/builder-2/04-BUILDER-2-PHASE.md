# B2-P4 — Responsive + Theme + Design System

> Planning specification only. See `00-BUILDER-2-MASTER-REVIEW.md` §16–17 (responsive and theme architecture), §24 decisions **D2** and **D-theme**. Paths: `PLUGIN` = `01-client/plugins/studio-builder`, `UI` = `PLUGIN/ui/src`, `SB` = `01-client/src/Module/StudioBuilder`.

## Objective
Make **Desktop / Tablet / Mobile editing first-class** — values per breakpoint with visible inheritance, overrides and one-click reset — and turn the Theme into a **real design system** (brand, colour scales and modes, typography scale, spacing/radius/shadow scales, component tokens) shared by desktop and mobile editors, while keeping platform identity untouched.

## User-visible outcome
- Switch Desktop/Tablet/Mobile and every control shows its value **for that device**, with a visible state: *own value*, *inherited from Desktop/Tablet*, or *overridden*; a **reset** button removes only the active device's override. The canvas shows the chosen device; the **physical** device is independent of the edited breakpoint (M01).
- A **Theme** editor (sheet on mobile, dialog/panel on desktop) with tabs Brand · Colors · Typography · Buttons · Spacing · Components, Light/Dark/Auto, live canvas updates, per-token reset to inherited, and a type-scale preview.
- "Dark mode preview" in the Responsive-view sheet works.

## Features
1. **Responsive style model (decision D2):** new optional block key **`style_responsive`** — desktop-first: the block's base `style` is Desktop; `tablet` and `mobile` hold partial-style overlays (same closed property set and validators as P3). Legacy mobile-first maps (`align`, `layout.columns`, `layout.padding_y`, `typography.size`) keep working unchanged and are shown read-only as "layout settings" in the Responsive section.
2. **Inheritance engine:** `valueAt(node, prop, device)` resolves Mobile → Tablet → Desktop, returning `{value, source: own|inherited-from, overridden}`; examples from the spec hold (*Desktop 64, Tablet 52, Mobile inherited → 52*; *Desktop 64, Tablet inherited → 64, Mobile 36*).
3. **Reset operations:** `reset_block_style_property` (active breakpoint only; Desktop reset only from Desktop) and bulk reset for a node.
4. **Responsive controls:** every P3 control gains a device chip, inherited ghost value, override dot, reset; visibility per device (existing) unified into the same pattern.
5. **Canvas device handling:** device presets (iPhone 14 390×844 etc.), custom width within limits, zoom 25–200 %, rotate; outlines/spacing guides/section labels (M01 sheet) as overlay features.
6. **Per-section responsive:** section layout overrides (width, columns, gap, padding) through the same mechanism.
7. **Theme model (decision D-theme):** extend tokens with typography scale (size/line-height/weight steps and named styles), spacing/radius/shadow scales, **component tokens** (button, input, card, nav), **modes** (light/dark) and semantic aliases; tokens may reference tokens (validated, no cycles).
8. **Theme UI (D11/M11 — no D11 image supplied):** Brand (logo, favicon, site name, tagline — sourced from tenant branding settings; platform identity read-only), Colors (primary, secondary, background, surface, border, text, muted, success, warning, danger), Typography, Buttons, Spacing, Components; one shared editor (replaces the `ThemeBottomSheet` facade).
9. **Optimistic concurrency for token saves** (open Phase 6 item); token history/restore via existing revision patterns if feasible (decision).
10. **Theme-aware canvas/preview:** token changes re-render via `canvasVersion`; dark mode preview toggles the mode in the iframe via a server-rendered variant (not script).

## Screens affected
D01/D07/D08 (responsive chips in Inspector), D11 (Theme), M01 (Responsive view sheet), M02, M07–M10 (device-aware inspectors), M11 (Theme), M12 (preview device/dark).

## Frontend work
- `UI/core/responsive.mjs` (`valueAt`, `setAt`, `resetAt`, device↔breakpoint map), `useBreakpoint()`/`useDevice()` implemented (stubbed in P1).
- Inspector controls updated to read/write via `valueAt`; device chip component; reset buttons; inheritance tooltips.
- Theme editor components: `ThemePanel` (tabs), `TypeScalePreview`, `ColourModeToggle`, `ComponentTokensEditor`; shared by desktop dialog and mobile sheet; unified save with conflict handling.
- Responsive-view sheet and canvas overlays for outlines/spacing/labels.

## Backend/domain integration
- **Schema:** `style_responsive` optional; `ALLOWED_BLOCK_KEYS`/`OPTIONAL_BLOCK_KEYS`; validator reuses P3 property validators per overlay; normalizer drops empty overlays; section `layout_responsive` analogue for sections.
- **Operations (additive):** `update_block_style_responsive`, `reset_block_style_property`, `update_section_layout_responsive`; mirrored in JS; lock-aware.
- **Renderer/compiler:** emit **desktop-first** rules: base declarations plain; `tablet` under `@media (max-width: 1023px)` and `mobile` under `@media (max-width: 767px)` (thresholds reconciled once with the canonical `base/sm/md/lg` = 640/768/1024 and the editor widths 1280/820/390 — decide and document); id-scoped; deduplicated; bump `COMPILER_VERSION` and `StudioStylesheet::VERSION`.
- **Theme:** extend `ThemeResolver` (new categories, scale expansion, mode variants via `:root[data-sb-mode=…]`/`prefers-color-scheme` strategy), `StudioThemeService` validation (sanitise per category, no free CSS), token reference resolution, `ThemeDialog` backend (`save_tokens`) with `expected` version.
- **Branding link:** continue mapping tenant branding → tokens (`BRANDING_TOKEN_MAP`); builder **cannot** write platform identity.

## Data/model impact
- Canonical document: additive optional keys (as P3); documents without them unchanged.
- **Token storage:** `studiobuilder_tokens.tokens_json` shape extends (flat `ref => value` → versioned structure with modes). Needs an **approved** change through `install.sql` + `StudioSchemaManager` (additive; no `db/migrations`) and a read-compat path for existing rows. *Not performed in this planning stage.*
- Compiled artifacts invalidated by the version bump; theme fingerprint already part of the compile key.

## Security considerations
- Token values sanitised per category (colours hex/rgb/hsl, lengths, shadows, font patterns, ≤200 chars); gradients/modes use structured values formatted server-side; no free CSS; token reference cycles rejected.
- Tokens must **never** reach the platform signature or security-required assets; extend signature-protection tests for new tokens.
- Media for logo/favicon via tenant-safe `media_ref` only; `compiled_css_vars` remains untrusted (always regenerated).
- Responsive overlays use the same validators; media-query values are **fixed server constants**, not user strings.
- Concurrency: token saves require `expected` version (fail closed on mismatch); permission `studio-builder.tokens`.

## Tests
- PHP: overlay validation/normalisation, emitter golden tests per breakpoint and per state combination, inheritance parity vectors, reset-operation tests, theme sanitiser tables (hostile values), mode CSS output determinism, token cycle tests, signature-protection tests, concurrency tests for token saves.
- JS: `valueAt` truth table (incl. both spec examples), reset semantics (**reset removes only the active breakpoint**), device/breakpoint mapping, theme editor state.
- DOM/e2e: edit at each device, verify canvas per device, inherited/override indicators, reset; theme editor flows on desktop and mobile; dark-mode preview.
- Visual regression of a styled page at 1280/820/390 in light and dark.

## Dependencies
B2-P3 (style surface, `valueAt` choke point, emitter). Decisions **D2, D-theme**; supply **D11** screen. Branding settings integration (existing).

## Files/modules likely to change
`SB/Document/CanonicalDocumentSchema.php`, `DocumentValidator.php`, `DocumentNormalizer.php`, `SB/Operation/*`, `SB/Document/LayerLock.php`, `SB/Render/DocumentRenderer.php`, `StudioStylesheet.php`, `RenderCollector.php`, `StudioCompiler.php`, `SB/Render/Theme/ThemeResolver.php`, `SB/Service/StudioThemeService.php`, `SB/Http/StudioAuthoringApi.php` (token versioning), `PLUGIN/install.sql` + `StudioSchemaManager` (**approval-gated**), `UI/core/responsive.mjs` (new), `UI/core/operations.mjs`, inspector controls, `ThemeDialog.jsx`/`ThemeBottomSheet.jsx` (unified), `CanvasArea.jsx`, i18n, tests, `CHANGELOG.md`, bundle.

## Files/modules that must NOT change
Canonical JSON, id patterns, `schema_version "1.0"`, legacy mobile-first maps and their rendering (until a deliberate deprecation), existing operations, `authorize()`, repositories, publish transaction, canvas sandbox/CSP, platform signature/identity code, `BRANDING_TOKEN_MAP` semantics for existing tenants, `StudioCodePolicy`.

## Acceptance criteria
1. For every responsive-capable control: Desktop value is the base; Tablet/Mobile inherit visibly; overriding and resetting behave exactly as in the Notion examples; resetting Tablet never alters Desktop.
2. Server output for each device matches the editor canvas at that device width (golden tests).
3. Legacy documents (using mobile-first maps) render identically before/after.
4. Theme changes (colours, type scale, component tokens, mode) apply to canvas, preview and public output; invalid values are rejected; concurrent token saves conflict safely.
5. The platform signature and identity are untouched by any theme/token input (tests).
6. Mobile Theme sheet persists everything it shows (no facade state).

## Definition of Done
Acceptance met; token schema change approved and applied via the Studio schema mechanism with read-compat; compile invalidation executed on staging; visual regression baselines committed; CHANGELOG, EN/FR; a11y (contrast checks in the Theme editor); CI green on both DBs; release notes include the recompile instructions.

## Verification plan
Edit a representative page at all three devices (desktop browser + phone emulation + a real phone if available); compare editor canvas vs preview vs public at 1280/820/390; toggle dark mode; run the full PHP unit + integration suites on a test DB; verify a pre-P4 published page is unchanged; hostile token and responsive payloads via the API and MCP.

## Risks
- **Desktop-first vs existing mobile-first** semantics confuse authors and code — the two systems must be clearly separated and documented; no silent migration.
- Breakpoint threshold reconciliation (1023/767 vs 640/768/1024 and editor widths) — one decision, one constant source.
- Token schema evolution with existing tenants' stored tokens — needs read-compat and a rollback path.
- CSS volume growth — deduplicate, cap overlays per node, measure.
- Missing D11 screen — Theme UI built from Notion + `m11`; may need rework when the desktop concept arrives.
