# B2-P2 scoping — Add / Elements / Components / Navigator

> Scoping pass, 2026-10-08. Compares `02-BUILDER-2-PHASE.md` with what the code does **today** (after B2-P1, merged as 6a87276) and
> proposes a reduced, ordered scope with the decisions the product owner must make. Nothing here is implemented.
> `PLUGIN` = `01-client/plugins/studio-builder`, `UI` = `PLUGIN/ui/src`, `SB` = `01-client/src/Module/StudioBuilder`.

## 1. What the spec assumes vs. what exists

| Spec item | Today (verified in code) | Consequence |
|---|---|---|
| New `AddPanel/*` and `NavigatorPanel/*` (replace `BlockPalette`, `LibraryPanel`, `Outline`) | `BlockPalette.jsx` (542 lines) already has tabs Sections · Elements · Components · Dynamic · Media · AI, search and click/drag insert. `Outline.jsx` (458) is the Layers tree with rename/lock (P1) and keyboard parity. | Refactor and extend; do not rewrite. The six tabs differ from D6's four (Media/AI to a `+` menu). |
| Retire hard-coded `BLOCK_META` (R4) | Still in `BlockPalette.jsx:33` (icons, titles, descriptions). Manifest exposes `required_entitlement`, `style_capabilities`, `allowed_child_types`, but not title/icon/description/category. | Server manifest gains four metadata fields; palette reads them. |
| 16 composed section presets | `bin/seed-modern-website-kit.php` seeds 9 section presets (hero-modern, bento-features, stats, interactive-tabs, faq, testimonial-carousel, pricing-bento, cta-gradient, contact-hub) and 6 theme/chrome presets, **per tenant with `is_system = 0`**. | About 9 of the 16 named presets exist in some form. Missing: Hero Classic/Centered/Split/Video, Feature Split/List, Services Overview, About, Team, Gallery, Footer. |
| System templates need a schema decision (D-system-templates) | `studiobuilder_templates.is_system` **already exists** and `StudioTemplateService` already refuses to overwrite or delete system templates (`system_template_immutable`). The seed simply sets it to 0. | **No schema change needed**: seed per tenant with `is_system = 1`. The spec's tenant-0 convention is unnecessary. |
| New elements: Icon, Columns, Stack, List, Card, Quote, Table, Tooltip, Link, Countdown, Audio, Input/Textarea/Select | 29 block types are registered (core.*: hero, heading, text, rich_text, image, button, feature_list, container, form, form_field, gallery, video, stats, tabs, accordion, carousel, modal, query_loop; layout.*: section, container, flex, grid, offcanvas; booking.services, forms.form_card, membership.plans; theme.* ×5). | **Columns and Stack are already `layout.grid` / `layout.flex`**: ship them as presets, not new types (type strings are stored in documents forever). `core.form_field` already exists for inputs: add `select`/`textarea` as variants of it rather than three types. Genuinely new: Icon, List, Card, Quote, Table, Link, Countdown (+ Audio/Tooltip optional). |
| Page commands: create, rename, duplicate, archive, set homepage | Application service has `createPage`, `updatePageAddress` (title/slug/route_mode), `archivePage`, `listPages`. **The HTTP API only exposes `pages` (list) and `create_page`.** No duplicate, no set-homepage, and no "homepage" concept found in `StudioPageAddressService`. The top bar has a page switcher (`boot.allPages`) but there is no Pages tab. | Rename and archive need new API actions over existing service methods. Duplicate is new (copy the current document into a new page). "Set homepage" has no model: needs a decision or is dropped. |
| `MAX_BLOCK_DEPTH` 4 → approved value | `CanonicalDocumentSchema::MAX_NESTING_DEPTH = 4`. | Loosening is backward compatible. Needs render/size measurement first. |
| Touch reordering in the Layers sheet (M14) | P1 provides `SheetPrimitive` and `useSheetDrag` (pointer events, snap points). Layers uses HTML5 DnD on desktop; there is no touch reorder. | Pointer-event reorder with a handle plus move up/down buttons. |
| Entitlement-gated components | `required_entitlement` is declared for booking/forms/membership blocks and surfaced in the manifest; server refuses insertion. | Already works; the UI needs "disabled with reason" cards. |

## 2. Proposed scope (reduced) and order

The spec is roughly two phases of work. Proposed split into three PRs, each shippable and deployable on its own, with the same slice discipline as P1 (tests and e2e per slice, French and CHANGELOG as you go):

**P2a — Add panel and catalogue** *(user-visible first)*
1. Manifest metadata (title/icon/description/category) for every block; palette reads it; delete `BLOCK_META` (R4).
2. Section preset catalogue: author the missing presets (about 8 new, 9 existing polished), seed as `is_system = 1` per tenant, golden-render tests for each, card metadata and thumbnail.
3. Add panel UX: category rail and "View all" drill-in, search across tabs, insertion rules shown as disabled cards with a reason, session-only favourites.
4. Mobile: the Add sheet with category rail (M03–M05).

**P2b — Elements and nesting**
5. New blocks: Icon, List, Card, Quote, Table, Link, Countdown (+ Audio/Tooltip if approved), `core.form_field` variants; Columns/Stack as presets of grid/flex. Each with registry definition, renderer, escaping and determinism tests; `COMPILER_VERSION` bump with a recompile note.
6. Nesting depth raised (approved value) with validator and render tests, plus a size/render measurement.
7. Embed/Lottie only with an allow-listed host list, otherwise omitted (D6 restriction). I recommend omitting both in this phase.

**P2c — Navigator**
8. Layers | Pages tabs; Pages tab lists the tenant's pages with status chips and a current-page marker; create blank/from template, rename, archive, duplicate (new API actions with `authorize()` unchanged and audit).
9. Row ⋯ menu (rename, duplicate, lock, hide, move, save to library, delete); header/footer shown as partial references; pointer-event touch reorder with handle and move buttons; keyboard parity kept.
10. Quick page tools row (M14) as entry points into existing dialogs.

Dropped from the spec for this phase (no backing in the repo, so they would be fakes): Ecommerce/Products/API cards, Staff List / Location-Map / Customer Portal / Blog component shells unless you want empty-state shells (decision below), Dynamic tab beyond the read-only catalogue that already exists, and "set homepage" until a model exists.

## 3. Decisions needed from you

| ID | Question | Recommendation |
|---|---|---|
| D-nesting | New nesting limit (now 4; concept trees are 5 deep) | **6**, after a render/size measurement in slice 6 |
| D-thumb | How preset cards get thumbnails | **Schematic SVG wireframes generated from the preset document** (deterministic, no assets, tenant-safe, no media table). Alternative: authored images (needs a design deliverable and media refs). |
| D-system-templates | How system presets are marked | **`is_system = 1` per-tenant seeding** (column and protection already exist; no schema change). |
| D-elements | Which new elements | **Icon, List, Card, Quote, Table, Link, Countdown**; `select`/`textarea` as `core.form_field` variants; Columns/Stack as grid/flex presets; omit Audio, Tooltip, Lottie, Embed |
| D-components | Component cards without a data provider | **Only ship ones with backing** (Booking services, Membership plans, Forms, Testimonials); omit Staff/Map/Portal/Blog until P5 |
| D-addtabs | Tabs (now six: Sections·Elements·Components·Dynamic·Media·AI) vs D6's four | **Keep six for now**; move Media/AI behind a `+` menu only if the strip crowds on a phone |
| D-pages | "Set homepage" has no model | **Drop** from P2; revisit with navigation in P5 |
| D-split | One phase or three PRs | **Three PRs (P2a/P2b/P2c)** |

### Decided 2026-10-08

- **D-split:** three PRs (P2a Add panel and catalogue, P2b elements and nesting, P2c Navigator and Pages).
- **D-thumb:** generated SVG wireframes from the preset document.
- **D-nesting:** raise to 6, only after the size/render measurement in slice 6 (fall back to 5 if the measurement is bad).
- **D-elements / D-components:** only what has backing. New: Icon, List, Card, Quote, Table, Link, Countdown, plus select/textarea as `core.form_field` variants; Columns and Stack as grid/flex presets; components limited to Booking, Membership, Forms, Testimonials.
- Not asked, recommendations stand: D-system-templates = `is_system = 1` per-tenant seeding; D-addtabs = keep six tabs; D-pages = drop "set homepage".

## 4. Risks

- **Block type strings are permanent.** Every new type is stored in documents. Reusing grid/flex/form_field avoids three of them.
- **Compile invalidation.** New renderers bump `COMPILER_VERSION`, which recompiles every published page on next publish/serve; plan the note in the PR.
- **Presets look generic without design.** Text is mine; imagery and visual polish are a design deliverable. Wireframe thumbnails make this visible instead of hiding it.
- **Depth costs.** Deeper trees raise render time and document size; measure with the 250-block / 50-section fixture before approving 6.
- **Page commands touch tenant scoping.** Rename/archive/duplicate need tenant-isolation tests (cross-tenant attempts refused) and audit entries.

## 5. Must not change

`authorize()` order, `StudioRepository`, the revision/publish/compile transaction, AI restrictions, the canvas CSP/sandbox, document id patterns and canonical JSON, existing block `type` strings and versions, existing operations, `StudioCodePolicy`, the platform signature slot (as in `02-BUILDER-2-PHASE.md`).

## 6. Verification approach

Same as P1: UI unit tests (`node --test`), Studio PHP unit and integration suites, Playwright e2e (now a CI job), `check-i18n`, golden renders for every seeded preset and new block, then a deploy to `solaya` staging with backup and a real-device pass.

### Nesting measurement (P2b slice 1)

`bin/measure-nesting-depth.php` compiles the same ~250-block document shaped as chains of 1, 4, 5 and 6 levels (15 runs each, median):

| Depth | Blocks | Document JSON | Compiled HTML | Median compile |
|---|---|---|---|---|
| 4 | 248 | 84 474 B | 29 680 B | 14.9 ms |
| 5 | 250 | 84 838 B | 30 216 B | 15.2 ms |
| 6 | 246 | 83 162 B | 31 986 B | 15.3 ms |

Going from 4 to 6 costs about 3% render time and 8% HTML, with the document size unchanged, so the limit is **6** (no fallback to 5). The HTML importer keeps its own limit of 4 (`HtmlStructureMapper::MAX_CANONICAL_DEPTH`), so imports are unchanged.
