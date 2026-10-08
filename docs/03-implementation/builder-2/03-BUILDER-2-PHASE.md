# B2-P3 — Inspector & Visual Styling

> Planning specification only. See `00-BUILDER-2-MASTER-REVIEW.md` §7 (R3, R8), §16 (document impact), §22 (restrictions and **pre-existing issues**). Paths: `PLUGIN` = `01-client/plugins/studio-builder`, `UI` = `PLUGIN/ui/src`, `SB` = `01-client/src/Module/StudioBuilder`.

## Objective
Deliver the **contextual, collapsible Inspector** and the **closed, validated, token-first styling system** the Notion spec lists: layout, size, position, spacing, typography, backgrounds, borders/radius, shadows, effects/transforms/filters, interaction states, animation, advanced (tag/id/ARIA), content editing — for sections, elements and Kohevo components. Breakpoint-specific values are P4; this phase builds the **Desktop (base) value surface** and the **render/compile machinery** both phases rely on.

## User-visible outcome
Select anything → the Inspector shows a header (icon, type, name, ⋯), tabs **Content · Style · Advanced**, and only the **collapsible sections that apply** (Background, Layout, Typography, Spacing, Border & radius, Shadow, Effects, Visibility, Interactions, plus type-specific sections). Changes appear on the canvas instantly; hover/focus/active/disabled styling can be authored; sections gain background image/gradient/overlay; headings support a highlighted word; every control has a clear reset.

## Features
1. **Inspector shell:** header with node identity and ⋯ (rename, duplicate, lock, hide, save to library, delete); tab strip; **sections registry** (`appliesTo`, `summary`, `render`), persisted open/closed state (session only); search-in-inspector; "Open in full panel" for mobile (M01).
2. **Per-type layouts:** Heading/Text/Rich Text (content + typography quick controls), Image (media, fit/position/aspect/alt counter/link), Button (content, link, variant, interaction states), Container/Stack/Grid/Flex/Columns (layout), Section (settings, layout, background, spacing), Kohevo Component (*change component*, content, layout, visibility; D09/M09), Hero (composed content: eyebrow/heading/description/buttons/overlay).
3. **Style surface (additive keys, closed sets):**
   - **Layout:** `display`, flex direction/wrap/justify/align/gap/order/grow/shrink/basis, grid columns/rows/gaps (enums + length regex).
   - **Size:** width/min/max/height/min/max height/aspect-ratio/overflow/object-fit/position.
   - **Position:** static/relative/absolute/sticky/fixed (fixed guarded, see Security) + inset offsets + `z_index`.
   - **Spacing:** per-side margin/padding with linked/unlinked control.
   - **Typography:** family (token), size, weight, style, line-height, letter-spacing, align, transform, decoration (+ style/colour/thickness/offset), colour (token or validated literal), highlight.
   - **Background:** none/colour/gradient/image (fit, position, repeat, **focal point**, **overlay**) and **video** (allow-listed host or tenant media only).
   - **Border & radius:** style/width/colour, per-side and per-corner, linked/unlinked.
   - **Shadow:** presets + validated custom (x/y/blur/spread/colour).
   - **Effects:** opacity, transform (translate/rotate/scale/skew as numbers), filters (blur/brightness/contrast/saturate/grayscale), backdrop blur, blend mode, transition (duration/easing), cursor.
   - **States:** `hover`, `focus`, `active`, `disabled` partial-style overlays (`style_states`).
   - **Animation/interactions:** existing triggers + presets; add `move`/`reveal` presets; section-level interactions.
4. **Content editing:** rich-text field upgrades (highlight span in the allowlist), inline editing mapped through a **declared** per-block "inline text prop" (replace the guess-by-prop-name heuristic).
5. **Advanced tab:** semantic `tag` (enum), element `id` (unique), `classNames` (validated tokens), ARIA label/role, safe `data-*` — all **allow-listed**; "Custom CSS" **not** offered per element.
6. **Section styling:** optional section `style` (background, spacing) and section-level `attributes`.
7. **Mobile Inspector (M07–M10):** right-docked half-sheet or bottom sheet, same sections, touch controls (segmented, steppers, sliders).

## Screens affected
D01, D07, D08, D09 (planned from text), M02, M07 (Edit Heading — **no image**), M08, M09 (Edit Button — **no image**), M10 (Edit Component = file `m09`); D03/D06 Inspector states (empty state, Data Source stub).

## Frontend work
- New `UI/components/inspector/*`: `InspectorShell`, `SectionRegistry`, section components, `ControlLibrary` (SpacingBox, Segmented, SliderInput, TokenOrColour, GradientEditor, FocalPoint, ShadowEditor, RadiusEditor, TransformEditor, FilterEditor, StatesTabs).
- Replace fixed `BlockInspector` tab set with the registry; keep `FieldControl`/`ObjectFields` for content.
- `valueAt(node, prop)` abstraction (single choke point for P4 breakpoints).
- Live preview: extend `canvasLiveSync` for new properties where provably safe; otherwise rely on the existing reload path.
- Controls validate client-side with the **same limits** as the server (ranges/enums), showing inline errors.

## Backend/domain integration
- **First (security prerequisite):** `attributes` allow-list validation + renderer enforcement (see Security / master §22.3). Ship as its own PR before the rest if the gap is confirmed.
- **Schema (additive, all optional):** new `style` keys, `style_states`, block `tag`, section `style`/`attributes`; `ALLOWED_STYLE_KEYS` / `ALLOWED_BLOCK_KEYS` / `OPTIONAL_BLOCK_KEYS` / `ALLOWED_SECTION_KEYS` updated; `DocumentValidator` gets per-key validators (enum + numeric range + length regex); `DocumentNormalizer` canonicalises (drop defaults/empties, sort keys).
- **Operations (additive):** `update_block_style` already replaces `style` wholesale — extend validation; add `update_block_style_states`, `update_section_style`, `update_block_tag`, `reset_block_style_property`; mirror in `core/operations.mjs` `applyLocal` and in `LayerLock` (both server and client mirror).
- **Renderer/compiler:** emit generated rules **scoped by block id** (`[data-sb-b="…"]`) from the validated closed property set into `RenderCollector` CSS; hover/focus/active/disabled via pseudo-classes; **no free-form values reach CSS**. Bump `COMPILER_VERSION` and `StudioStylesheet::VERSION`.
- **Block definitions:** declare `style_capabilities` per block so irrelevant sections are hidden.

## Data/model impact
- Canonical document gains optional keys only; **unmodified documents stay byte-identical**; stored `compilations` invalidated by the version bump (republish/recompile policy needed — see Risks).
- No table changes. Document size budgets (1 MiB, 250 blocks) re-checked with realistic styled pages.
- Client/server duplication: control and operation definitions exist in both JS (`ui/src/core`) and PHP (`ControlSchema.php`) — change both and pin with tests.

## Security considerations
- **XSS gap (verify first):** `attributes` must be restricted to `aria-*`, `role`, `data-*`, `id`, `tabindex`, `title`, `lang`; reject `on*`, `style`, `srcdoc`, `href`/`src` with `javascript:`; id uniqueness enforced.
- **CSS injection:** generated CSS built from enums and numerics the server formats itself; **never** pass user strings into CSS; do not reuse `isSafeCssValue` for new fields (it does not block `url()`/`@import`); background images only via validated `media_ref`; video only allow-listed host or media.
- **Platform identity:** `position:fixed`/`z-index`/`overflow` cannot cover or hide the platform signature — extend `signatureProtectionCss` and add tests that a hostile style set cannot obscure it.
- No per-element Custom CSS/JS; site-level custom CSS remains under `StudioCodePolicy`.
- Operations pass `authorize()`; AI path uses the same validators; layer lock enforced for all new operations.
- Tenant scoping unchanged (no new data stores).

## Tests
- PHP: validator tables (valid/invalid for every new key incl. boundary values and hostile strings), normalizer idempotence, applier tests for each new operation incl. lock refusal, renderer golden tests (HTML + CSS) for each property and state, **hostile-input tests** (`url(`, `@import`, `</style`, `javascript:`, `onclick`, `srcdoc`, huge numbers, NaN), signature-protection tests, compiler-version invalidation test.
- JS: `valueAt`/operation mirrors parity tests against PHP-pinned fixtures; control validation; inspector registry `appliesTo` per block type.
- DOM/e2e: per-type Inspector layouts; keyboard operability; states authoring; mobile Inspector; reset behaviours.

## Dependencies
B2-P1 (Inspector region, selection incl. multi-select behaviours), B2-P2 (composed presets use these props). Decision **D-D09** (Component Inspector screen), **D-limits**. Pre-phase triage #1 (attributes) should merge before the Advanced tab.

## Files/modules likely to change
`SB/Document/CanonicalDocumentSchema.php`, `DocumentValidator.php`, `DocumentNormalizer.php`, `SB/Operation/DocumentOperation.php` + `DocumentOperationApplier.php`, `SB/Document/LayerLock.php`, `SB/Render/DocumentRenderer.php`, `SB/Render/StudioStylesheet.php`, `SB/Render/RenderCollector.php`, `SB/Render/StudioCompiler.php`, `SB/Schema/ControlSchema.php`, block definitions (`style_capabilities`), `SB/Mcp/StudioMcpAdapter.php` (op id-shape checks), `UI/core/operations.mjs`, `UI/core/layerLock.mjs`, `UI/core/canvasLiveSync.mjs`, `UI/components/inspector/**` (new), `inspectors/*` (retired/migrated), `builder.css`, i18n files, tests, `CHANGELOG.md`, bundle.

## Files/modules that must NOT change
Canonical JSON encoder, id patterns, `schema_version "1.0"`, existing operation names/payloads (add, never alter), `authorize()`, repositories, revision/publish transaction, canvas sandbox/CSP, `StudioCodePolicy`, token-class naming already in published artifacts (without a version bump), platform signature slot semantics.

## Acceptance criteria
1. For every block type, the Inspector shows exactly the applicable sections (driven by `style_capabilities`/type), collapsed by default except the most relevant.
2. Every property in the Notion lists (Layout, Size, Position, Spacing, Typography, Background, Border & Radius, Effects, Interaction states, Advanced) is authorable **or explicitly listed as deferred with reason**.
3. Every new value is validated server-side; hostile inputs are rejected; generated CSS contains no user-controlled string.
4. States (hover/focus/active/disabled) render only for the authored element and respect reduced motion.
5. The platform signature cannot be covered/hidden by any authorable combination (test).
6. Existing documents/pages render identically (golden diff) and republish cleanly after the version bump; all prior tests pass.

## Definition of Done
Acceptance met; operations mirrored client/server with parity tests; hostile-input suite green; compile invalidation plan executed on a staging tenant; CHANGELOG, EN/FR, docs; a11y pass on the Inspector; bundle rebuilt; CI green (both DBs); security review sign-off on the new CSS emitter.

## Verification plan
Author a page exercising every section on desktop and mobile; compare the canvas, preview and public output (identical styles); run golden/hostile suites; diff compiled CSS size (budget: < +30 % on a styled 250-block page); republish an old page and verify unchanged output; run `make test-unit`/`make test-integration` on a test DB; attempt the signature-obscuring and `url()` attacks manually.

## Risks
- **Compile invalidation** (every published artifact) — stage on a copy, plan recompile (`StudioCompiler`) and caches.
- Schema growth vs the 1 MiB/250-block budgets — measure; coalesce defaults out in the normalizer.
- Two implementations (JS/PHP) drifting — parity fixtures are mandatory.
- Scope temptation: free-form CSS/JS, per-element Custom CSS (concept d9) — **refuse**; offer tokens/presets instead.
- Missing screens (D09, M07, M09) — UI for those inspectors is designed from text until supplied.
