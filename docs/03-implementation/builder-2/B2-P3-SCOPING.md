# B2-P3 scoping — Inspector & Visual Styling

> Scoping pass, 2026-10-08, against `main` at `c097738` (after B2-P2). Compares `03-BUILDER-2-PHASE.md` with what the code does **today**
> and proposes an ordered, reduced scope with the decisions the product owner must make. Nothing here is implemented.
> `PLUGIN` = `01-client/plugins/studio-builder`, `UI` = `PLUGIN/ui/src`, `SB` = `01-client/src/Module/StudioBuilder`.

## 1. What the spec assumes vs. what exists

| Spec item | Today (verified in code) | Consequence |
|---|---|---|
| "First: `attributes` allow-list" (master §22.3 #1) | **Already fixed.** `CanonicalDocumentSchema::blockAttributeIssue()` is enforced by `DocumentValidator` (l.653-668) and re-checked in `DocumentRenderer` (l.202-210) so old stored documents cannot emit a handler. | Drop the prerequisite PR. Keep the hostile-attribute tests; the Advanced tab can build on the existing allow-list. |
| "Never pass user strings into CSS" | **Not true today.** `DocumentRenderer::buildInlineStyles` (l.446-526) concatenates raw strings into the inline `style` attribute: `font-size`, `color`, `line-height`, `letter-spacing`, `font-family`, `background`, `background-image` (gradient), `border-width/color/radius`, `box-shadow`, `width/height/min-height/max-width`, `opacity`, `z-index`. The only gate is `DocumentValidator::isSafeCssValue` (l.954), which rejects `< > ; { }`, `javascript:`, `expression(`, `behavior:` and **accepts `url(...)`, `@import`, `image-set(`, `var(`**. | **Security work comes first** (slice P3a): typed validators, `url(` refused everywhere, and a renderer re-check for already-stored documents (same pattern as attributes). This is a pre-existing issue, independent of the new features. |
| Closed property set, CSS scoped by block id | Style is emitted inline on the block wrapper. There is no per-block stylesheet, so **hover/focus/active/disabled cannot be authored** with inline styles. | A scoped rule emitter in `RenderCollector` is the core backend piece (P3b). Everything stateful depends on it. |
| `ALLOWED_STYLE_KEYS` extended | 15 keys today: `align, surface_token, text_token, spacing_token, radius_token, shadow_token, font_token, typography, color, background, spacing, border, shadow, dimensions, opacity, z_index`. `dimensions` allows only width/height/min_height/max_width; `spacing`, `border`, `shadow` are shallow. | New keys are additive and optional. Existing keys keep their shape and meaning (legacy documents render identically). |
| New operations `update_block_style_states`, `update_section_style`, `update_block_tag`, `reset_block_style_property` | `update_block_style` replaces `style` wholesale; `update_block_attributes` exists. `LayerLock` is mirrored in PHP and `ui/src/core/layerLock.mjs`; `operations.mjs` `applyLocal` mirrors the applier. | Four ops, each mirrored three ways (PHP applier, JS `applyLocal`, lock rules), pinned by parity fixtures as in P1/P2. |
| New Inspector (`components/inspector/*`, sections registry) | `inspectors/BlockInspector.jsx` (306 lines, fixed tabs content · style · motion · advanced · responsive · visibility · data), `StyleControls.jsx` (452), `MotionInspector.jsx` (201), `SectionInspector.jsx` (130), `controls.jsx` (90). `RightPanel.jsx` (28 lines) exists but the inspector currently shares the single left panel (the P1 shell moved selection to the Inspector tab). | Refactor behind a registry, migrate existing sections one by one; do not rewrite. `FieldControl`/`ObjectFields` stay for content. |
| "Position: fixed (guarded)" and the signature guard | `StudioCodePolicy::signatureProtectionCss()` pins the signature (`position:static`, `z-index:auto`, `display:block`, …) but cannot stop a *tenant block* with `position:fixed/absolute` and a high `z-index` from painting **over** it. `z_index` is capped at -999…9999. | `position: fixed` is **deferred** (see decisions); absolute/sticky/relative allowed with `z_index` capped below the signature's stacking level (test: a hostile style set cannot cover it). |
| Background video (allow-listed host or media) | No background video. Image backgrounds need `media_ref`. | **Deferred.** Image + gradient + overlay + focal point only. |
| Inline editing through a declared per-block "inline text prop" | Heuristic by prop name in the canvas. | Declare `inline_text_prop` in the block definition / manifest (additive), drop the heuristic. |
| Highlight span in rich text | `RichTextSanitizer` allows a fixed tag set; only `<a>` keeps attributes. | Add one allowed span class (`sb-hl`) with no attributes beyond the class; test the sanitizer stays closed. |
| Section `style` and `attributes` | Sections have `layout` (`columns,width,gap,padding_y,background_token`) and `locked` only. `ALLOWED_SECTION_KEYS` = required + `locked`. | Add optional `style` (background, spacing) to sections; section `attributes` limited to the same allow-list as blocks. |
| Responsive / per-breakpoint values | `responsive` exists as an optional block key. | **P4**. P3 introduces the single `valueAt(node, prop)` choke point only. |

## 2. Proposed scope (reduced) and order

Four PRs, each shippable and deployable on its own, same slice discipline as P2 (tests and e2e per slice, French and CHANGELOG as you go).

**P3a — Safe style foundation** *(security first, no visible change)*
1. Typed value validators in `SB/Document` for every existing free-string style field: length (number + unit from an allow-list), colour (token, hex, `rgb[a]`, `hsl[a]`), gradient (structured or tightly anchored regex), font family (token or an allow-list of stacks), box-shadow (structured). `url(`, `@import`, `image-set(`, `var(`, `\`, comments refused.
2. Renderer re-check: a legacy stored value that fails the new validator is **not emitted** (like attributes), with a unit test per field. Audit script `bin/audit-style-values.php` lists affected stored documents before deploy.
3. Signature stacking test and `z_index` ceiling.
4. Golden diff: documents that were valid before render byte-identically.

**P3b — Schema, scoped CSS emitter, operations**
5. Additive style keys (layout, size, position, per-side spacing, typography extras, background image/overlay/focal, border/radius per side and corner, shadow custom, effects: opacity/transform/filter/backdrop/blend/transition/cursor).
6. `style_states` (`hover`, `focus`, `active`, `disabled`) partial overlays; rules emitted into a block-scoped stylesheet through `RenderCollector` (`[data-sb-b="…"]`), numbers and enums formatted by the server only; `prefers-reduced-motion` respected for transitions.
7. Section `style` and `attributes`; block `tag` (enum).
8. The four operations with PHP applier, JS mirror, `LayerLock`, MCP/AI id-shape checks, and `COMPILER_VERSION` / `StudioStylesheet::VERSION` bumps with the republish plan.
9. Normalizer drops defaults/empties and sorts keys; size and compile-time measurement on a styled 250-block page (budget < +30% CSS).

**P3c — Inspector shell and controls**
10. `InspectorShell` + `SectionRegistry` (`appliesTo`, `summary`, `render`), tabs Content · Style · Advanced (Motion stays reachable as a section), session-only open state, inspector search, header ⋯ reusing `RowMenu`.
11. Control library: SpacingBox (linked/unlinked), Segmented, SliderInput, TokenOrColour, GradientEditor, FocalPoint, ShadowEditor, RadiusEditor, TransformEditor, FilterEditor, StatesTabs; client validation with the same limits as the server; per-control reset.
12. Sections: Background, Layout, Size, Position, Typography, Spacing, Border & radius, Shadow, Effects, Visibility, Interactions; per-type layouts (Heading/Text, Image, Button, containers, Section, Hero, Component) driven by `style_capabilities`.
13. `valueAt(node, prop)` and `canvasLiveSync` extended only for properties provably safe to repaint client-side; the rest uses the existing server-render path.

**P3d — Content, Advanced and mobile**
14. Declared `inline_text_prop`, highlight span, rich-text upgrades.
15. Advanced tab: tag, id (unique), classNames, ARIA label/role, safe `data-*` (reuse `blockAttributeIssue`).
16. `move` / `reveal` animation presets and section-level interactions.
17. Mobile Inspector (M07–M10): sheet with the same registry and touch controls; "Open in full panel".

Dropped or deferred from the spec (no backing, or unsafe, so they would be fakes or risks): `position: fixed`, background **video**, per-element Custom CSS/JS (refused by design), skew/filters beyond the listed set, Data Source stub content (P5), D09 Component Inspector visuals (no screen supplied; planned from text).

## 3. Decisions needed from you

| ID | Question | Recommendation |
|---|---|---|
| D-split | One phase or four PRs | **Four PRs (P3a–P3d)**. P3a can merge and deploy alone and is the only one with a security payoff. |
| D-emitter | Where generated styling goes | **A block-scoped stylesheet through `RenderCollector`**; legacy inline keys keep rendering inline (re-validated). Required for states; keeps the HTML small. |
| D-literals | Which free values stay possible | **Tokens first; literals only through typed validators** (hex/rgb/hsl colours, number+unit lengths). No raw strings reach CSS. |
| D-legacy | Stored documents that fail the new validators | **Do not emit the offending value, keep the document loadable**, report it with the audit script; no automatic rewrite. |
| D-fixed | `position: fixed` | **Defer.** Offer relative/absolute/sticky; revisit with a visual signature-overlap test. |
| D-video | Background video | **Defer** (needs allow-list host policy). |
| D-signature | How to guarantee the signature stays visible | **Cap `z_index` below the signature level and extend the guard**; add a hostile-style test. Changing the signature slot's own semantics is out of scope. |
| D-recompile | After the `COMPILER_VERSION` bump | **Lazy recompile on next publish/serve**, staged first on solaya with a copy of a published page (Section 4 of the spec's risks). To verify in P3b: how stored `compilations` are invalidated today. |
| D-tabs | Inspector tabs | **Content · Style · Advanced** as in the spec; Motion, Visibility and Data become sections, Responsive waits for P4. |

## 4. Risks

- **Existing data.** P3a can make a stored value stop rendering. The audit script and the "not emitted, still loadable" rule bound the damage; run the audit on solaya before deploying.
- **Compile invalidation.** Same as P2: version bump recompiles on next publish/serve; plan the note in the PR.
- **Client/server drift.** Control limits and operations live in `ui/src/core` and `SB`; parity fixtures are mandatory (as for `max_nesting_depth` and the manifest).
- **Document and CSS budgets** (1 MiB, 250 blocks, +30% CSS): measure in P3b before building controls on top.
- **Scope temptation.** Free-form CSS, per-element custom CSS, JS: refused; offer tokens and presets.
- **Missing screens.** D09/M07/M09 are designed from text; expect a visual pass later.

## 5. Must not change

Same list as the spec: canonical JSON encoder, id patterns, `schema_version "1.0"`, existing operation names and payloads (add, never alter), `authorize()`, repositories, the revision/publish transaction, canvas sandbox/CSP, `StudioCodePolicy`, token-class names in published artifacts (without a version bump), platform signature slot semantics.

## 6. Verification approach

UI unit tests (`node --test`), Studio PHP unit and integration suites, Playwright e2e (CI job), `check-i18n`, golden renders for every property and state, a hostile-input suite (`url(`, `@import`, `</style`, `javascript:`, `onclick`, `srcdoc`, huge numbers, NaN), then deploy to `solaya` with backup and a real-device pass. Baselines to keep: UI node tests 177, Playwright 85, Studio PHP unit 799/804 and integration 602/626 (known unrelated failures).

## 7. Decided 2026-10-08

All recommendations in section 3 stand: D-split (four PRs P3a–P3d), D-emitter (block-scoped stylesheet), D-literals (tokens first, typed literals only), D-legacy (not emitted, still loadable, audit script), D-fixed and D-video (deferred), D-signature (z-index ceiling plus guard), D-recompile (lazy, staged on solaya; confirm the invalidation path in P3b), D-tabs (Content · Style · Advanced).
