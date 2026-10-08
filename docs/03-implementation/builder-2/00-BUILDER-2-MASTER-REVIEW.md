# Kohevo Visual Website Studio — Builder 2.0: Master Review Report

**Status:** planning only. Nothing in the codebase was changed, installed, migrated or refactored to produce this report.
**Date:** 2026-10-08 · **Repo baseline:** `main` at `444ba9b` (after PR #1 shell/preview and PR #2 layer lock/rename).
**Companion documents:** `01-BUILDER-2-PHASE.md` … `06-BUILDER-2-PHASE.md` (same folder).
**Naming:** the repository already has Studio *Phases 0–9D*. To avoid collision, Builder 2.0 phases are written **B2-P1 … B2-P6**.

Tags used throughout: **[EXISTS] [PARTIAL] [MISSING] [REFACTOR] [DO NOT CHANGE] [UNKNOWN]**.
Source-of-truth order (as instructed): 1. the Kohevo codebase, 2. the Notion spec, 3. the D/M screens, 4. labelled assumptions.

---

## 0. Evidence basis and honesty notes

- **Repository:** inspected by the author (shell, preview, layer lock, operations, normalizer, validator, canvas, outline, tests — much of it written or read first-hand this session) and by four read-only inspections (frontend, document model/renderer, platform/security, architecture docs). Findings from those inspections carry file:line evidence in their own reports; where I did not re-verify a claim I say so. Nothing was executed in a browser, so **runtime behaviour is unverified**.
- **Notion spec:** read in full from the export `ExportBlock-…zip` (one page).
- **Desktop screens — only nine of twelve were supplied.** `PC-…zip` contains `d01, d02, d3 … d9`. There is **no image for the Theme screen (D11), the Preview/Publish screen (D12), the Component Inspector (D09) or a separate Navigator (D10)**. The Navigator appears inside `d7`. Details in §12. The Google Drive folder could not be read (it needs a Google sign-in; the browser pane is not signed in). **D09, D11, D12 are therefore planned from Notion + the mobile equivalents only, and are marked as such.**
- **Mobile screens:** all fourteen supplied (`m01 … m14`), but the file numbering does **not** match the Notion numbering (e.g. `m10` is a *Publish site* sheet, not *Edit Component*). The mapping is in §13.
- **The sources disagree with each other** on shell layout, bottom navigation, Add-panel tabs and the responsive model. These are catalogued in §24 as decisions the product owner must make; this report states a recommendation for each but does not treat any as settled.
- **Screens are UX direction, not proof of capability.** Anything that appears only in a screen is classified *visual-only* until a backend capability is identified.

---

## 1. Executive summary

Kohevo already has a **mature, security-first Studio platform**: a canonical document model, a single authoring boundary (`StudioApplicationService`), immutable revisions with atomic publish+compile, tenant-scoped repositories, an allow-listed dynamic-data layer, a draft-only AI/MCP channel, package/HTML import, templates and global components, a token theme layer, and — as of this week — an in-app Edit/Preview/Visitor preview, Layers multi-select, layer lock and block rename.

What it does **not** yet have is the *premium canvas-first experience* the Notion spec and the 26 screens describe. The distance is mostly **front-end experience and styling depth**, not platform:

| Area | Verdict |
|---|---|
| Platform (revisions, publish, tenancy, AI boundary, import) | **Strong — extend, do not touch** |
| Shell (3-panel layout, compact chrome, mobile dock) | **PARTIAL** — one docked left panel today; needs the split Add / Inspector layout and a selection model |
| Inspector & styling | **PARTIAL** — fixed long form; style surface lacks layout, position, states, transforms, per-side spacing |
| Responsive | **PARTIAL and a model decision** — mobile-first buckets in code vs Desktop→Tablet→Mobile inheritance in the spec |
| Theme / design system | **PARTIAL** — tokens exist; typography scales, component tokens and modes do not; the mobile Theme sheet is partly a facade |
| Navigation / menus | **MISSING** (no model at all) |
| Dynamic data | **PARTIAL** — 4 domains; Staff, Locations, Booking calendar, Customer Portal missing; three providers ignore the injected tenant |
| Publish checks, scheduling, redirects, hreflang, JSON-LD | **MISSING / stored-but-not-enforced** |
| In-builder AI (ASK/EDIT/BUILD/PLAN) | **MISSING** — but the actor origin and draft-only review pipeline already exist |

**The six phases are sufficient** for the intended product, *provided* the open decisions in §24 are answered before B2-P1 starts, and provided two **pre-existing security/consistency defects** (§22.3) are triaged immediately rather than waiting for their phase.

---

## 2. What Builder 2.0 should be

A **canvas-first, contextual, premium design application** inside Kohevo:

**Pick → Place → Select → Edit → Responsive → Preview → Publish.**

- *Persistent creation:* an Add panel (Sections · Elements · Components · Dynamic) always one click away on desktop; a bottom-sheet equivalent on mobile.
- *Large canvas, minimal chrome:* compact top bar, a canvas toolbar/breadcrumb, a bottom zoom/navigator bar; hiding the Inspector immediately widens the canvas (D02).
- *Contextual editing:* selecting a thing shows only the controls that apply to it, grouped in collapsible sections, with progressive disclosure.
- *Responsive as a first-class mode:* a device switch edits the same node at Desktop / Tablet / Mobile with visible inheritance and a one-click reset.
- *Kohevo-native:* Services, Booking, Memberships, Forms, Posts, Staff, Locations and the Customer Portal are first-class, tenant-scoped building blocks — the Builder **references** them; the owning modules keep the business logic.
- *Safe by construction:* every edit is a canonical operation through the application service; AI proposes drafts a human reviews and publishes; no arbitrary code.
- *Desktop/Mobile parity of capability*, with mobile designed as a touch editor (bottom nav, sheets, touch-sized controls), not a squeezed desktop.

---

## 3. Current Kohevo architecture (relevant to the Builder)

- **Two apps, one shell:** `01-client` (customer app, contains Studio) and `02-licensing`. Studio lives **only in the client**; the "two-tree" rule does not bite Studio code.
- **Module:** `01-client/src/Module/StudioBuilder` (PHP, PSR-4 `Slate\Module\StudioBuilder`) + plugin `01-client/plugins/studio-builder` (admin pages, `install.sql`, React UI in `ui/`, committed prebuilt bundle in `assets/builder`).
- **Boundary [DO NOT CHANGE]:** `StudioApplicationService` is the only command/query surface. `authorize()` order is *tenant → authentication → entitlement (`EntitlementService::canAccess`) → permission*. UI (`admin/api.php` → `StudioAuthoringApi`, a fixed `ACTIONS` allowlist, 1.5 MiB body, ≤50 ops per request, CSRF, rate limit), MCP (`StudioMcpAdapter`), preview/canvas endpoints all go through it.
- **Tenancy:** `StudioRepository` forces `tenant_id` on insert, strips it on update, fails closed on an invalid scope; all seven `studiobuilder_*` tables carry `tenant_id`; cache keys include the tenant; documents may never contain `tenant_id`.
- **Document:** schema `"1.0"` only; `sections[] → blocks[]` (children nest); limits 1 MiB / 50 sections / 250 blocks / nesting 4 / JSON depth 32. Canonical JSON (sorted keys, fixed encode flags) underpins fingerprints and `content_hash`.
- **Operations:** 25 allow-listed operations applied purely by `DocumentOperationApplier`; the applier now enforces the **layer lock** for every caller.
- **Revisions:** immutable; kinds autosave/manual/publish/rollback/ai_operation/import; SHA-256 autosave dedupe; optimistic concurrency (`expected_revision_id`) with row locking; publish and compile are one transaction; rollback makes a new draft only.
- **Render:** one `DocumentRenderer` walk serves editor, preview and public; `StudioStylesheet` (static, versioned) + `ThemeResolver` (`:root{--sb-*}`) + per-page token classes from `RenderCollector`; dynamic blocks are nonce markers resolved per request; compile output is cached per published revision and invalidated by `COMPILER_VERSION` / `StudioStylesheet::VERSION`.
- **Canvas:** same-origin iframe of `renderForEditor`, `sandbox="allow-same-origin"` (no scripts), CSP `script-src 'none'`, no-store, noindex; nodes carry `data-sb-node` / `data-sb-type`.
- **Security spine:** `StudioCodePolicy` (custom CSS only, no raw JS, validated GA4/GTM ids, public output only), `RichTextSanitizer`, platform signature slot outside tenant regions with `!important` protection CSS, licensing gate, `AuditLog` + `StudioDenialAudit`.
- **AI/MCP:** 20 tools; writes forced to `ai_operation` drafts; no publish and no import tool; token scopes intersected with the issuer's *current* permissions; per-token rate limits; `ORIGIN_ADMIN_ASSISTANT` already exists for an in-builder assistant.

---

## 4. Current Builder state (frontend)

*(from the frontend inspection + this session's work)*

- **State:** `StudioShell` (≈670 lines) owns selection (a single node id), viewport, manifest, dialog, library; one `SyncEngine` with `base → inflight → pending → working` layers, autosave 900 ms (structural 0 ms), retry backoff, conflict state with no merge; components read via `useEngineState` (`useSyncExternalStore`).
- **Undo/redo:** server rollbacks to immutable revisions (cap 50) — every undo is a network round trip and creates a revision. **[DO NOT CHANGE]** without an explicit decision (§24, D-undo).
- **Shell:** one docked **left** panel with tabs Add / Layers / Style / Library / Settings (360 px); `RightPanel.jsx` exists but is unused. Top bar 56 px with three-way Edit/Preview/Visitor, viewport buttons, Inspector toggle, history/theme/packages, save/publish.
- **Canvas:** iframe bridge, hover/selected overlays as CSS classes, an `innerHTML` selection toolbar injected *inside* the selected node (fragile, not keyboard reachable), plain-text inline editing, drag/drop insertion, live DOM patching + reload fallback, zoom Fit/100/75/50, fixed device widths 1280/820/390, breadcrumb, grid overlay. No resize handles, free positioning, canvas multi-select or marquee.
- **Layers (`Outline.jsx`):** strong — ARIA tree, drag reorder/nest, Alt+arrow moves, F2 rename, search, hide, lock, duplicate, remove, Shift/Ctrl multi-select with bulk lock/duplicate/remove.
- **Inspector:** manifest-driven (`style_capabilities`, field schema); fixed tab set (Content, Style, Motion, Advanced, Responsive, Visibility, Data); long form.
- **Add panel:** Sections / Elements / Components / Dynamic / Media / AI tabs; section "presets" insert **single primitives**, not composed sections; `BLOCK_META` hard-coded titles/icons; AI tab is a note only.
- **Theme:** `ThemeDialog` (desktop) is the real token editor; `ThemeBottomSheet` (mobile) persists colours only — site name/tagline/fonts/mode are local state and the site name defaults to a placeholder. **[REFACTOR]**
- **Mobile:** `MobileDock` (Blocks/Edit/Theme/Preview/More), `visualViewport` keyboard inset, one `safe-area-inset-bottom` use, sheets not draggable, hard-coded `window.innerWidth <= 860`, selection auto-opens the Inspector sheet over the canvas.
- **Tests:** `node:test` only (≈117 cases: core, sync, canvas patch, shell state, layer lock, library, packages, SEO, import). **No DOM/React rendering, no canvas-bridge, no Outline keyboard, no mobile, no ARIA tests.**
- **Bundle:** `builder.js` ≈ 464 KB, `builder.css` ≈ 60 KB, lazy rich-text chunk ≈ 282 KB (Lexical).

---

## 5. Reusable systems (build on these)

1. `StudioApplicationService` + `StudioAuthoringApi` action allowlist.
2. `SyncEngine` (autosave, coalescing, remap `tmp_`→real ids, conflict, retry).
3. Canonical operations + `DocumentOperationApplier` + `LayerLock` (client mirror `core/layerLock.mjs`).
4. Block registry + manifest-driven inspector/palette (`style_capabilities`, `binding_slots`, `allowed_child_types`).
5. Template/global-component/library services; `seed-modern-website-kit` seeder (bin).
6. `DataProviderRegistry` (entitlement, permission, parameter schema, row cap).
7. `ThemeResolver` / `StudioThemeService` / `ThemeDialog`.
8. `StudioPackageService` (+ HTML importer) for page/section/template export.
9. Preview endpoints + `VisitorPreview` + `shell-state` view modes.
10. MCP catalog + `ORIGIN_ADMIN_ASSISTANT` + review diff UI for AI.
11. Media: `CoreMediaResolver` (tenant-safe refs) + `window.SlateMedia` picker + focal-point control.
12. `Dialog`, `LiveRegion`, `messages.mjs` + `boot.messages` + `fr.php` i18n pipeline.

---

## 6. Gaps / missing capabilities

(Full matrix in §11.) Headline gaps: split shell and selection model · composed section/element/component library · layout/position/state/transform styling · responsive style model and reset · theme scales/component tokens/modes · Navigation/menus · Staff/Location/Booking-calendar/Portal providers and a generic collection UX · Pages panel · media metadata/replace · section export · publish checks + scheduling + redirects · hreflang/JSON-LD · authoring a11y checks · in-builder AI · comments/reviews · DOM-level test harness.

**Important capabilities present in the sources but absent from the brief's list:** Pages panel (create/rename/duplicate/delete/page switcher); version history & restore UI; Page settings (background, layout, details); Responsive-view tools (outlines, spacing guides, section labels, dark-mode preview, device size presets); Quick actions/shortcuts; Save-as-template; Global/reusable blocks management; Conditions and Display rules (D6/M06); Integrations; Help; Draft/Unsaved/Published status chips; Site URL/visibility/publish destination (M10).

---

## 7. Required refactors

| # | Refactor | Why | Risk |
|---|---|---|---|
| R1 | Split `StudioShell`/`ShellLayout`; restore Add-left / Inspector-right layout; introduce a **selection model** (primary, multi, hover, focus) in context | Premium layout, multi-select on canvas, parity | Medium — touches the whole shell |
| R2 | Replace the in-iframe `innerHTML` toolbar with a **parent-layer overlay** positioned from the frame's `getBoundingClientRect` | Keyboard/ARIA access, no clipping, touch targets | Medium — frame stays script-less |
| R3 | Inspector: composable **sections registry** (Background, Layout, Typography, Spacing, Border, Shadow, Effects, Responsive, Visibility, Interactions, Advanced) instead of fixed tabs | Contextual progressive disclosure | Low/Medium |
| R4 | Move `BLOCK_META` into the **server manifest** (title, icon, description, category) | New blocks must not fall back | Low |
| R5 | Make `ThemeBottomSheet` real (or share one theme editor between desktop/mobile) | Facade persists nothing | Low |
| R6 | i18n sweep of hard-coded English (Icons, dock, panel, palette) into `messages.mjs` | Locked house rule | Low |
| R7 | Mobile breakpoint hook (replace `innerWidth <= 860`), draggable sheets, safe-area everywhere | Mobile parity | Low |
| R8 | Generic `style_responsive` / `style_states` emission in renderer + `StudioStylesheet` | Per-property responsive and states | **High** — bumps compiler/stylesheet versions, invalidates all artifacts |
| R9 | Provider tenant fix (use injected `TenantContext`) and SQL-level limits | Violates locked tenant rule | Medium |
| R10 | Context value memoisation / selector split | Re-render cost on every selection change | Low |

---

## 8. Protected / untouched systems

**[DO NOT CHANGE]** (each is a locked decision or an invariant other code depends on):

- `StudioApplicationService::authorize()` order; `StudioRepository` tenant forcing and the `studiobuilder_` table-prefix guard.
- `expected_revision_id` check + row locking; atomic publish+compile transaction; revision immutability.
- **No AI publish, no AI import** (`draftKind`/`importKind`); `IssuerAuthority` scope intersection; assistants cannot lock/unlock layers.
- Canvas CSP + iframe sandbox (`script-src 'none'`); `data-sb-node`/`data-sb-type`.
- Platform signature slot, its protection CSS, the licensing gate; `StudioCodePolicy` (no raw scripts).
- Canonical JSON encoding, id patterns (`sec_…`, `blk_…`, 16–32 chars), `ns.name` block types, block `version` = definition version, normalizer idempotency, `schema_version "1.0"`, no `tenant_id` in documents, `global_ref` one level deep.
- Operation vocabulary and payload shapes (new ops may be **added**, existing ones not altered); the `STRUCTURAL` set; ≤1 insert per batch; `tmp_` remap.
- `COMPILER_VERSION` / `StudioStylesheet::VERSION` contract (bump, never reuse).
- Manifest-driven contracts: `style_capabilities`, `permissions`, `binding_slots`; breakpoint vocabulary `base/sm/md/lg` (640/768/1024).
- Boot JSON keys in `admin/builder.php`; `sbx-` CSS prefix and `data-testid`s; committed bundle in `assets/builder`; Lexical rich-text allowlist; "no browser persistence"; `StudioReservedRoutes`; `studiobuilder` schema authority = `install.sql` + `StudioSchemaManager` (never `db/migrations/`).
- Document limits (any change is a deliberate, tested decision — see D-nesting).

---

## 9. Desktop UX architecture

**Layout (recommended; see decision D1):**

```
┌ Top bar 52–56px ────────────────────────────────────────────────────────────┐
│ Logo · Page ▾ · Page settings · + · Undo Redo · [Desktop|Tablet|Mobile] ·    │
│ Layers · Preview · Publish ▾ · ⋮ · Avatar                                    │
├─ Add panel ─┬────────────── Canvas ───────────────┬─ Inspector (hideable) ──┤
│ 230–250px   │ selection overlay + contextual bar   │ 320–360px               │
│ Sections    │                                      │ Content | Style |       │
│ Elements    │                                      │ Advanced                │
│ Components  │                                      │ collapsible sections    │
│ Dynamic     │                                      │                         │
├─────────────┴── Bottom bar: breadcrumb · fit/zoom · guides · shortcuts ──────┤
```

- **Add panel (D03–D06):** tab strip *Sections · Elements · Components · Dynamic*; search; category groups with "View all"; thumbnail cards; click-to-insert **and** drag-to-place. *Media*, *Templates*, *AI* are secondary entry points (top-bar `+` menu and the Add search), not extra tabs, to keep the strip short — recommendation, see D6.
- **Inspector (D01/D07/D08):** header (node icon, type, name, ⋯), tab strip, collapsible sections; quick controls first. **Hiding it widens the canvas** (D02). Optional floating mode is a *preference*, not the default.
- **Navigator (D07):** panel replacing the Add panel (or opened from `Layers`): tabs *Layers | Pages*, search, tree with visibility/lock/⋯, breadcrumb.
- **Contextual toolbar on canvas:** name chip + move, duplicate, delete, ⋯; resize handles are decorative until a sizing model exists (see D-handles).
- **Canvas:** device frame at 1280/820/390 with fit/zoom (50–150 %), guides toggle, breadcrumb, outlines/spacing guides (M01 "Responsive view" options).
- **Keyboard:** ⌘Z/⌘⇧Z, ⌘D, Delete, F2, ⌘K, Esc, arrows in Layers — all announced via `LiveRegion`.

## 10. Mobile UX architecture

Not a compressed desktop: **canvas-first + bottom navigation + contextual sheets**.

- **Bottom nav (decision D2m): Blocks · Edit · Theme · Preview · More** (Notion; also the nav on `m02`, `m11`, `m12`, `m13`, `m14`). `m01`, `m03–m10` show a *different* nav (Add · Elements · Pages · Layers · Theme · More, with Data/Inspector/Publish replacing "More" contextually); this is treated as concept noise.
- **Sheets:** Add (sections/elements/components with a left category rail), Inspector (right-docked half-sheet in the concept; bottom sheet recommended for small phones), Theme, Preview (full screen, device switch, *Exit preview*), More (page settings, site tools, history & collaboration, advanced), Layers & page tools (with lock/hide/move/delete action row).
- **Principles:** 44 px targets, no hover dependence, safe-area + keyboard inset, persistent selection, easy return to canvas, **physical device ≠ edited breakpoint** (a phone can edit the Desktop values).
- **Auto-open rule:** selecting a node must *not* always cover the canvas; show the contextual bar and open the Inspector on tap of **Edit**.

---

## 11. Capability matrix

Phase column = where it is **delivered** (B2-P#). *Evidence* abbreviations: FE = frontend inspection, DM = document/renderer inspection, PL = platform inspection, SG = this session's code.

| Capability | Status | Evidence (short) | Phase |
|---|---|---|---|
| Builder workspace (3-panel, compact bars) | PARTIAL | one docked left panel; `RightPanel` unused (FE) | P1 |
| Canvas (iframe, live patch, zoom, device widths) | PARTIAL | no custom width/free zoom (FE) | P1 |
| Selection model (single→multi/hover/focus) | PARTIAL | single id; multi only in Outline | P1 |
| Contextual toolbar | PARTIAL/REFACTOR | in-iframe `innerHTML` bar | P1 |
| Sections library (composed) | PARTIAL | presets insert single primitives | P2 |
| Elements (Stack, Columns, Icon, List, Card, Quote, Table, Tooltip, Link, Countdown, Input/Textarea/Select, Audio) | PARTIAL | 28 blocks; those missing (DM) | P2 |
| Lottie / Embed / Code / HTML | MISSING + **restricted** | no unrestricted embed/JS | P2 (constrained) |
| Kohevo components (Booking Calendar/CTA, Service Card/List, Membership, Staff, Location, Posts, Portal, Contact form, Testimonials) | PARTIAL | module blocks: services, plans, form card | P2 (blocks) / P5 (data) |
| Dynamic data (collection, filters, sort, limit, preview) | PARTIAL | provider params only for Posts | P5 |
| Providers: Staff, Locations, Customer Portal, Booking calendar | MISSING | no providers (PL) | P5 |
| Navigator/Layers | EXISTS (strong) | `Outline.jsx` | P2 (Pages tab, touch) |
| Multi-select (canvas + inspector) | PARTIAL | Outline only | P1/P2 |
| Drag & drop, nesting, reorder | EXISTS | palette + outline + canvas | P2 (touch, depth) |
| Layer lock / rename | EXISTS | PR #2 | — |
| Inspector (contextual, collapsible) | PARTIAL/REFACTOR | fixed tabs | P3 |
| Content editing (rich text, inline) | PARTIAL | plain-text inline only | P3 |
| Layout controls (display/flex/grid/align/order) | MISSING | style keys absent (DM) | P3 |
| Size, Position (relative/absolute/sticky/offsets) | PARTIAL/MISSING | only `dimensions`, `z_index` | P3 |
| Spacing (per-side margin/padding, linked) | PARTIAL | `spacing_token` only | P3 |
| Typography (complete set) | PARTIAL | size/weight/transform/align | P3 |
| Backgrounds (image/gradient/video/overlay/focal) | PARTIAL | block: color/gradient/image; section: token only | P3 |
| Borders/Radius (per-side/per-corner) | PARTIAL | presets only | P3 |
| Shadows | PARTIAL | presets only | P3 |
| Effects/Transforms/Filters/Transition/Cursor | MISSING | none | P3 |
| Interaction states (hover/focus/active/disabled) | MISSING | fixed hover lift only | P3 |
| Animation | EXISTS | trigger + presets; no "move/reveal" | P3 |
| Responsive editing (inherit/override/reset) | PARTIAL | few properties; no reset | P4 |
| Visibility per breakpoint | EXISTS | `sb-hide-*` | — |
| Theme / design tokens | PARTIAL | flat tokens, no scales/modes | P4 |
| Global UI tokens (buttons, forms, cards, nav) | MISSING | none | P4 |
| Reusable content / templates / global components | EXISTS | Phase 6 | P2 (seed/system templates) |
| Pages panel (create/rename/duplicate/delete/templates) | PARTIAL | page switcher only | P5 |
| Navigation / menus | MISSING | no model | P5 |
| Media (browse/upload/replace/alt/focal) | PARTIAL | alt/metadata not editable in library | P5 |
| Page settings (details/background/layout) | PARTIAL | `PageInspector` SEO | P5 |
| Page export / Save as template | EXISTS / PARTIAL | package service; template save | P5 |
| Section export | MISSING | no section package kind | P5 |
| Preview (editor + visitor, device) | EXISTS | PR #1 | P6 (polish) |
| Publish (validate → compile → checks → publish) | PARTIAL | checks stage missing | P6 |
| Scheduling / unpublish | PARTIAL | schema only, no publisher | P6 |
| Version history / compare / restore | EXISTS | History dialog, diff | P6 (UX) |
| Comments / reviews | MISSING | no backend | P6 (**approval needed**) |
| SEO (title/desc/canonical/robots/og) | EXISTS | `SeoHead` | — |
| hreflang, JSON-LD, social title/desc | MISSING | grep: none | P6 |
| Redirects (enforcement, slug-change) | PARTIAL | stored, never read | P6 |
| Accessibility — output | PARTIAL | lang, ARIA on interactives | P6 |
| Accessibility — authoring checks | MISSING | none | P6 |
| Accessibility — builder UI | PARTIAL | canvas toolbar unreachable | P1/P6 |
| AI (ASK/EDIT/BUILD/PLAN) | MISSING | MCP only; origin exists | P5 |
| Import/export (HTML, package) | EXISTS | Phase 8A/8B | P5 (UX) |
| Security / multi-tenancy | EXISTS (strong) | repo scoping, CSP | all (guard) |
| Permissions / entitlements | EXISTS | view/edit/publish/tokens/admin | all |
| Performance | PARTIAL | context rebuild, iframe reloads | P1/P6 |
| Error handling / conflict | PARTIAL | block-only conflict, no merge | P1/P6 |
| Undo/redo | EXISTS (server rollback) | cap 50, round trip | decision D-undo |
| Concurrency (null `expected_revision_id` on publish) | PARTIAL | check skipped when null | P6 |
| DOM/e2e test harness | MISSING | node:test only | P1 |

---

## 12. D01–D12 analysis

**Mapping of supplied files to spec screens.** Supplied: `d01, d02, d3, d4, d5, d6, d7, d8, d9`.

| File | Shows | Spec screen |
|---|---|---|
| d01 | Add/Sections + canvas with selected Hero + Inspector Style (Background, Layout, Typography, Spacing, Border & radius, Shadow, Responsive, Visibility) | **D01** |
| d02 | Same with Inspector hidden; canvas full width | **D02** |
| d3 | Add/Sections (grouped: Hero/Features/Services, tabs Sections·Elements·Components·Dynamic), nothing selected → empty inspector ("No element selected") | **D03** |
| d4 | Add/Elements (Basic, Layout, Media, Content, Form, Interactive groups) | **D04** |
| d5 | Add/Components ("Kohevo Components for your business") | **D05** |
| d6 | Add/Dynamic ("Dynamic Data") + Inspector "Data Source" (Dynamic/Static, Collection, Filters, Sort, Limit, Preview items) | **D06** |
| d7 | **Navigator** (Layers\|Pages tree) + Hero Section inspector *Content* tab | **D10 and D07** (merged) |
| d8 | Add with tabs *Blocks·Elements·Templates* + Section *Content* inspector (Section Settings, Layout, Background) on a Services page | variant of **D08** (conflicts with d3's tab set) |
| d9 | Add *Blocks·Elements·Templates* + Section *Style* inspector (Background, Spacing, Container, Visibility, Interactions, Scroll animation, **Custom CSS**) | variant of **D08** |
| — | *(none)* | **D09 Component Inspector — no image** |
| — | *(none)* | **D11 Theme / Design System — no image** |
| — | *(none)* | **D12 Preview / Publish — no image** |

Each screen below: *Purpose · Intent · Controls · Interactions/state · Relation · Domain capability · Reusable now · Missing work · Visual-only.*

### D01 — Builder Default Workspace
- **Purpose/intent:** the main editing surface; "see the page, change the page."
- **Controls:** top bar (Kohevo mark, *Page: Home ▾*, settings ⚙, **+**, undo/redo, Desktop/Tablet/Mobile, **Layers**, **Preview**, **Publish ▾**, ⋮, avatar); Add panel (✕ closes it); canvas with name-chip toolbar (move, duplicate, delete, ⋯) and 8 resize handles; Inspector; bottom bar (breadcrumb Home › Section › Hero Section, fit-to-screen icons, − 100 % +, keyboard-shortcut and help icons).
- **Interactions:** selection updates breadcrumb + Inspector; zoom/fit; device switch re-lays canvas.
- **Domain:** operations, SyncEngine, viewport model — all exist.
- **Reusable:** `TopBar`, `CanvasArea` (zoom, breadcrumb), `Outline`, selection overlay, `RightPanel` stub.
- **Missing:** split layout; resize-handle semantics; shortcuts/help popover; Publish ▾ menu (Publish / Schedule / Unpublish).
- **Visual-only:** resize handles (no sizing model), Publish ▾ options beyond Publish, avatar menu.

### D02 — Inspector Hidden
- **Purpose:** maximise canvas. **Controls:** same shell; Add panel remains, right column absent. **State:** `inspectorOpen=false` → canvas width recomputed instantly (stage ResizeObserver already exists).
- **Reusable:** toggle + collapse logic from PR #1. **Missing:** persisted preference (per user, in-memory only per the no-browser-persistence rule — keep session-only or server-side user pref → decision).

### D03 — Add / Sections
- **Purpose:** drop in prebuilt sections. **Controls:** search, tabs, grouped categories (Hero, Features, Services…) with **View all**, thumbnail cards. **Interactions:** click inserts after selection; drag places.
- **Domain:** section presets/templates exist (`section_preset`, seeder). **Missing:** *composed* presets (today single primitives), thumbnails (generated or authored), category metadata, "View all" drill-in, favorites persistence (server-side).
- **Visual-only:** thumbnails are art; need real preview images or a render-to-thumbnail path.

### D04 — Add / Elements
- **Controls:** groups Basic (Text, Heading, Image, Button, Icon, Divider, Spacer, Rich Text), Layout (Container, Grid, Columns, Stack, Flex, Tabs, Accordion, Carousel), Media (Video, Audio, Gallery, Lottie), Content (List, Quote, Code, HTML), Form (Form, Input, Textarea, Select), Interactive (Link, Modal, Tooltip, Countdown).
- **Domain:** 28 blocks exist; **missing:** Icon, Columns, Stack, Audio, Lottie, List, Quote, Code, HTML, Input/Textarea/Select as distinct elements, Link, Tooltip, Countdown.
- **Restricted:** *Code* and *HTML* **must not** become raw-code/raw-HTML blocks (locked decisions: no raw JS, constrained HTML import). Lottie/Audio/Embed only via allow-listed media sources (decision D6).

### D05 — Add / Components
- **Controls:** "Kohevo Components — For your business": Booking Calendar, Service List, Service Card, Membership Plans, Staff List, Contact Form, Location/Map, Testimonials, Blog/Posts, Customer Portal.
- **Domain:** Services (list), Membership Plans, Form card exist; Booking Calendar/CTA, Staff, Location, Portal, Testimonials as distinct components **do not** (PL). Booking/Membership/Forms providers ignore the injected tenant (fix first).
- **Reusable:** `ModuleBlockDefinitions`, entitlement gating, provider registry.

### D06 — Add / Dynamic Data
- **Controls (left):** Services, Posts/Blog, Staff/Team, Locations, Membership Plans, Booking Calendar, Testimonials, FAQs, Gallery/Media. **(Right Inspector "Data Source"):** Dynamic|Static, Collection, Filters (+ Add filter), Sort, Limit, **Preview items** (drag handle, ✕).
- **Interactions:** choosing a collection binds a slot; changing filter/sort/limit re-resolves a bounded preview; dynamic regions are badged on canvas ("Dynamic: Services Section").
- **Domain:** provider registry exists (params schema, entitlement, cap) but filter/sort/limit exist only for Posts and the cap is applied *after* execute. Need generic query params + SQL limits.
- **Missing:** Staff/Locations/FAQs/Testimonials providers; preview endpoint with bounded rows; canvas badges. **Visual-only:** FAQs/Testimonials/Gallery sources unless backed by real data.

### D07 — Hero Inspector (with Navigator, `d7`)
- **Purpose:** specialised editing of a composed Hero. **Controls:** Content tab: Background (Image/Video/Gradient/Solid, change/edit image, overlay 40 %, overlay gradient), Content (Eyebrow, Heading with highlighted word, Description), Buttons (Primary: text+link; Secondary(Video): text+link).
- **Domain:** Hero is `core.hero` today; "highlighted word" needs rich-text spans; video button needs a media/URL field (allow-listed hosts).
- **Missing:** composed Hero schema (eyebrow/heading/desc/buttons/stats) as structured props; overlay + gradient controls; highlight markup in the rich-text allowlist.

### D08 — Element Inspector (`d8`/`d9` variants)
- **Controls (variants):** Section Settings (Title, Heading, Description) · Layout (Container Wide, Columns 3, Column gap, Row gap) · Background (Color/Gradient/Image, gradient style, angle) · Spacing (linked padding box 80/120) · Container · Visibility · Interactions · **Scroll animation (Fade up)** · **Custom CSS**.
- **Conflicts:** two tab vocabularies (Content/Style/Advanced vs Content/Style/Settings) and an Add tab set (*Blocks·Elements·Templates*) that contradicts d3–d6. **Custom CSS per element contradicts the locked "tokens over arbitrary CSS" rule** → not implemented per element; site-level custom CSS stays in `StudioCodePolicy`.
- **Missing:** everything in §6 styling gaps.

### D09 — Component Inspector — **no image supplied**
- Planned from Notion + `m09`: component header with *Change component*; Component configuration (content props), Media, Layout, Visibility, Advanced; *save/global reuse*; for a **global component reference**, show detach / open master / publish master.
- **Reusable:** `GlobalSectionPanel`, global component services. **Missing:** component-specific config forms, "change component" swap (rebind preserving content where fields match).

### D10 — Navigator / Layers (inside `d7`)
- **Controls:** tabs *Layers | Pages*, search, tree (Page: Home → Header → Hero Section → Background Image, Overlay, Container → Content → Eyebrow Text, Heading, Description, Buttons → Primary/Video Button; Stats, Featured Projects, Services, Testimonials, CTA, Footer), per-row eye + ⋯.
- **Domain:** exists. **Notice:** the concept tree is **5 levels deep under a Section**; the current document nesting limit is **4** — D-nesting.
- **Missing:** *Pages* tab, row ⋯ menu content, header/footer rows as partial references, non-block pseudo-rows (Background Image, Overlay) which are **properties, not nodes** (visual-only unless modelled).

### D11 — Theme / Design System — **no image supplied**
- Planned from Notion + `m11`: Brand (logo, favicon, site name, tagline), Colors (primary, secondary, background, surface, border, text, muted, success, warning, danger; Light/Dark/Auto), Typography (heading/body/mono fonts, scale), Buttons, Spacing, Components (cards, forms, nav).
- **Domain:** 23 flat tokens; branding maps via `BRANDING_TOKEN_MAP`. **Cannot express** typography scales, gradients, breakpoint values, modes, semantic aliases. **Protected:** tenant theme must not reach the platform signature.

### D12 — Preview / Publish — **no image supplied**
- Planned from Notion + `m12`/`m10`: Editor Preview, Visitor Preview (device switch, *Exit preview*, *Draft Preview* chip), Publish flow. Preview exists (PR #1). `m10`'s "Publish site" sheet shows Kohevo Hosting / Custom Domain / Static Export / Site URL / Public-Private — **platform-level hosting features not present in the repo → out of Builder 2.0 scope (decision D5)**.

---

## 13. M01–M14 analysis

**Mapping of supplied files to spec screens.**

| File | Shows | Spec screen |
|---|---|---|
| m01 | 4-up overview: workspace with Hero selected + Add sheet + Section inspector + **Responsive view sheet** | M01 (+ overview) |
| m02 | Block selected: bar *Home · Unsaved*, contextual bar (move, edit, duplicate, delete, ⋯), nav Blocks·**Edit**·Theme·Preview·More | M02 |
| m03 | Add sheet / Sections with category rail | M03 |
| m04 | Add / Elements with category rail | M04 |
| m05 | Add / Components (incl. Navbar Basic/Centered, Footer) | M05 |
| m06 | **Dynamic data** sheet — Data sources, Collections, API connections, Site/User data, Form submissions, Ecommerce, Booking, Dynamic fields, Conditions, Display rules | M06 |
| m07 | Right-docked Inspector for **Hero Section** (Content/Style/Settings; Layout per breakpoint, padding box, Background, Elements (3)) | stands in for M07 (*Edit Heading* image **missing**) |
| m08 | Image inspector (Image, Image settings, Link, Size & spacing, Border & radius, Effects, Visibility, Responsive, Advanced) | M08 |
| m09 | Hero **Component** inspector (Change component, Content, Media, Layout…) | **M10** *(spec number differs)*; *Edit Button* image **missing** |
| m10 | **Publish site** sheet (destination, site details, visibility) | not in spec |
| m11 | Theme sheet (Brand, Colors, Typography, Buttons, Spacing, Components; Light/Dark/Auto) | M11 |
| m12 | Preview (Draft Preview chip, device switch, *Exit preview*) | M12 |
| m13 | More options (Page settings, Site tools, History & collaboration, Advanced) | M13 |
| m14 | Layers & page tools (tree with lock/hide, action row, quick page tools) | M14 |

**Spec screens with no image:** M07 *Edit Heading*, M09 *Edit Button* (and M10 *Edit Component* is `m09`).

Per screen:

- **M01 — Mobile Builder.** Compact two-row top bar (menu ≡, Kohevo ▾, device switcher, Publish ▾; page back, status chip, undo/redo), canvas, bottom nav. *Reusable:* `TopBar`, `MobileDock`. *Missing:* two-row compact bar, status chip (Unsaved/Saved/Published), **Responsive-view sheet** (device size preset e.g. "iPhone 14 (390 × 844)", zoom, element outlines, spacing guides, section labels, **dark-mode preview**, *Preview in new tab*, quick actions undo/redo/duplicate/delete). *Visual-only:* dark-mode preview (needs theme modes, P4).
- **M02 — Block Selected.** Contextual floating bar (move, edit, duplicate, delete, ⋯) above the selection; resize handles. *Missing:* touch drag handle semantics; "edit" = open Inspector. 
- **M03 — Add Block.** Bottom sheet with Sections/Elements/Components tabs, search, left category rail (All, Hero, Features, CTA, Content, Pricing, Testimonials, Team, FAQ, Gallery, Contact), preview cards. *Missing:* real section catalogue (P2).
- **M04 — Elements.** Category rail (Basic, Media, Content, Buttons, Forms, Navigation, Layout, Interactive, Data, Social, Other) + grid. *Note:* *Navigation* and *Social* categories imply elements that do not exist (menu, social links).
- **M05 — Components.** Featured + category rail (Hero, Navigation, Footer, Blog, **Ecommerce**, Booking, Forms, Membership, Testimonials, Pricing, Team, FAQ, Gallery, CTA). *Ecommerce has no Kohevo backing in this repo → visual-only / decision.* Navbar components require the menu model (P5) or static link lists (P2 interim).
- **M06 — Dynamic Data.** Data sources list with item counts and "last updated" (Blog Posts, Gallery, Products, Bookings, Team Members) + Add source (Collection, Media Library, **API Connection**, Site Data, User Data, Form Submissions). *Constraint:* **API connections = external fetching → forbidden** (restriction list §8/§22). *Products/Ecommerce → no backing.* *Conditions/Display rules* map to `settings.conditions` (validated-but-never-persisted today).
- **M07 — Edit Heading.** *No image.* Planned: inline text + typography quick controls (size per breakpoint, weight, alignment, colour token), highlight word.
- **M08 — Edit Image.** Image, fit/position/aspect ratio, alt text (28/125 counter), Link (type/page/new tab), then collapsible Size & spacing, Border & radius, Effects, Visibility, Responsive, Advanced. *Reusable:* `MediaControl`, `FocalPointControl`, `UrlControl`.
- **M09/M10 — Edit Button / Edit Component.** Button *no image*. Component: *Change component*, Content, Primary/Secondary button, Button type (Video), Video URL. *Video URL: allow-listed hosts only.*
- **M11 — Theme.** Sheet with Brand (logo, site name, tagline, favicon), Global colour palette (Light/Dark/Auto), Typography with type-scale preview. *Facade today (R5).*
- **M12 — Preview.** Full-screen draft preview; device toggles; *Exit preview*. *Reusable:* `VisitorPreview`.
- **M13 — More.** Page settings (details, background, layout, duplicate page, save as template, export page), Site tools (Navigation/Menus, Media library, Reusable blocks, Global components, Templates), History & collaboration (Version history, **Comments & reviews**), Advanced (SEO, **Integrations (third-party)**, Help). *Integrations = external → not in scope; Comments → needs backend.*
- **M14 — Layers & page tools.** Tree with drag handles, eye, **lock**, ⋯, expand; action row (Add section, Duplicate, Move up/down, Hide, Lock, Delete); Quick page tools. *Reusable:* `Outline`, lock (PR #2). *Missing:* touch drag-reorder, row actions as a bar.

---

## 14. Frontend architecture plan

1. **Shell:** `AppShell` (data/engine/dialog owner) separated from `WorkspaceLayout` (pure layout). Panels: `AddPanel`, `NavigatorPanel`, `InspectorPanel`, `CanvasStage`, `TopBar`, `BottomBar`, `MobileSheets`. Existing components are *moved*, not rewritten.
2. **Selection model** (`core/selection.mjs`): `{primary, ids[], hover, focus}`; derived `isMulti`; operations act on `ids`; canvas, Outline and Inspector all read it. Tests in `node:test`.
3. **Canvas overlay layer** (`CanvasOverlay.jsx`): parent-DOM overlay for selection box, handles, name chip, toolbar, drop indicators; rect source = `getBoundingClientRect` of `[data-sb-node]` (frame is same-origin); iframe stays script-less.
4. **Inspector sections registry** (`inspectors/sections/*.jsx`): each section declares `appliesTo(def, node)`, `summary(node)`, `render`. Capability data from the manifest.
5. **Control library**: spacing box (linked), segmented, slider+input, colour/token picker, gradient, focal point, shadow/radius presets, per-breakpoint chip with "inherited" state.
6. **Responsive UI:** one `useBreakpoint()` (edited breakpoint) and one `useDevice()` (physical); every control reads/writes through a `valueAt(node, prop, bp)` helper with inheritance info.
7. **Mobile:** `useIsMobileShell()` replacing the 860 literal; draggable sheet primitive; safe-area utility.
8. **i18n:** all strings via `messages.mjs` + `boot.messages` + `fr.php`.
9. **Testing:** add a DOM-level harness (decision D-test) for shell, overlay, Outline keyboard, mobile.
10. **Performance:** split context (selection vs document vs actions); memoised selectors; overlay positions computed in rAF; keep live-sync patching.
11. **Build:** continue esbuild-only, committed bundle; add a CI drift check between `ui/src` and `assets/builder` (open item from Phase 6).

## 15. Backend / domain integration plan

- **Everything goes through `StudioApplicationService`.** New capabilities are added as: new allow-listed operations (validated, applied purely), new `StudioAuthoringApi` actions (explicit allowlist), new application-service methods that run the unchanged `authorize()` pipeline.
- **New operations (additive):** `update_block_style_responsive`, `update_block_style_states`, `reset_block_style_property`, `update_section_style`, `update_section_attributes`, `update_block_tag` (names indicative). Mirror each in `core/operations.mjs` (`applyLocal`) and `LayerLock`.
- **Providers:** fix tenant scoping and SQL limits first; add generic query params (`filter`, `sort`, `limit`) validated by the existing parameter schema; add Staff, Locations, Booking (calendar/CTA adapters), Portal (link-out adapters). Reuse `studio_contact_roles` (instructors) and `booking_resources` (rooms) rather than inventing tables.
- **Pages/Navigation:** reuse page types (`header_partial`/`footer_partial`); add a menu entity only after approval (§16, §19).
- **AI:** in-builder assistant calls the same operations as `ORIGIN_ADMIN_ASSISTANT`, producing `ai_operation` drafts; ASK/PLAN are read-only; EDIT/BUILD write drafts; **publish stays human**.
- **Preview/publish:** add a *checks* service (pure, read-only) between validate and publish; scheduling via a publisher job + `unpublishPage`.

## 16. Canonical document impact

Principle: **extend, never replace** (locked). All additions are **optional keys**, so unmodified documents remain byte-identical.

| Need | Minimal backwards-compatible change | Risk |
|---|---|---|
| Per-property responsive overrides | new optional block key `style_responsive` (`tablet`/`mobile` partial-style overlays; Desktop is the base `style`), emitted desktop-first with `@media (max-width)` scoped by block id | Compiler/stylesheet version bump; naming map; size budgets; keep legacy mobile-first maps working |
| Interaction states | optional `style_states` (`hover/focus/active/disabled` partial style) emitted via the same id-scoped emitter | existing hover-lift is the reduced-motion target |
| Layout/position | new `ALLOWED_STYLE_KEYS` (`layout`, `position`, `overflow`) with enum + length-regex validation | `fixed`/`z_index` vs platform signature; sticky vs scroll-margin; depth 4 |
| Transforms/filters | `style.transform`, `style.filter` as numeric objects formatted by the server — never strings | `isSafeCssValue` does not block `url()` — do not reuse |
| Tag/ID/ARIA | allow-list validation for `attributes` (`aria-*`, `role`, `data-*`, `id`, `tabindex`, `title`, `lang`) + a block `tag` enum | **closes an existing gap** (§22.3); id uniqueness |
| Section style | optional section `style` (background image/gradient/overlay, spacing) | section keys are a closed list today |
| Navigation | `nav.menu` block + `content.menus` provider and a tenant menu table | schema authority (`install.sql` + `StudioSchemaManager`), dependency extraction |
| Nesting depth | raise `MAX_BLOCK_DEPTH` 4 → a tested higher value (concept tree is 5 deep) | document size, render cost, nesting tests |
| Block labels/lock | **done** (`metadata`) | — |

**No migration of existing documents is required by any of the above.** (MASTER §4's recursive `nodes[]` model would require one; it is **not** recommended — decision D3.)

## 17. Responsive / styling / theme architecture

- **Storage vocabulary stays `base/sm/md/lg`** (thresholds 640/768/1024) for legacy maps. The UI exposes **Desktop / Tablet / Mobile** (Notion) by a fixed mapping (Desktop≙lg, Tablet≙md, Mobile≙base) with canvas widths 1280/820/390.
- **Inheritance direction (decision D2):** the Notion examples (*Desktop 64, Tablet 52, Mobile inherited → 52; or Desktop 64, Tablet inherited → 64, Mobile 36*) require a **desktop-first cascade**. The current style maps are **mobile-first**, which cannot express "Mobile inherits Tablet". Recommendation: the **new** `style_responsive` object is desktop-first and independent; legacy keys remain untouched and are surfaced read-only in the Responsive inspector as "inherited from layout settings". `ALLOWED_RESPONSIVE_BREAKPOINTS` already accepts `desktop/tablet/mobile` aliases, which supports this.
- **Reset:** deleting the active-breakpoint key via a dedicated operation; Desktop base values are never reset by a breakpoint reset.
- **Styling surface:** closed, validated property set; token references preferred; literals only where validated; **no free-text CSS per element**.
- **Theme:** extend `studiobuilder_tokens` categories (needs a schema-authority-approved change) to add typography scale (size/line-height/weight steps), component tokens (button, form field, card, nav), spacing/radius/shadow scales, and **modes** (light/dark/auto). Tokens never reach the platform signature. Add optimistic concurrency to token saves (open Phase 6 item). One theme editor shared by desktop and mobile.

## 18. Components / dynamic data architecture

- **Reusable sections & components:** sections are copied (ids re-minted); global components are live references (`global_ref`, one level). Builder 2.0 adds a *system* template catalogue (seeded from `seed-modern-website-kit`) alongside tenant templates.
- **Kohevo components** are `ModuleBlockDefinitions` entries, entitlement-gated, each declaring `binding_slots` and `allowed_binding_providers`. Components never contain business logic; they reference providers.
- **Dynamic UX:** *Source → Entity/Collection → Field → Filter → Sort → Limit* mapped onto provider params; bounded preview (≤ provider cap) via a read-only action; tenant-scoped; entitlement-gated; canvas badge on dynamic regions.
- **Excluded:** arbitrary API connections; ecommerce (no domain); user-data binding beyond what the portal exposes (decision).

## 19. Pages / navigation / media architecture

- **Pages panel:** list with status, create (blank/template/section/AI), rename, duplicate, archive, set homepage — over existing page services (`createPage`, `updatePageAddress`, `archive`); duplicate page and "set as homepage" need verification (**[UNKNOWN]**).
- **Navigation:** menus as *structured data* (not copied HTML): nested items, page/external/anchor links, dropdowns, header/footer placement, mobile nav. Recommended model: tenant-scoped menu entity + `nav.menu` block bound by a provider. **Requires schema approval.** Interim (P2): navbar components with static link lists in block props.
- **Media:** keep the tenant-safe `media_ref {media_id, alt, focal_point}`; add library-level alt/title editing, replace-in-place, and fall back to library alt when the ref's alt is empty (renderer currently ignores library alt).
- **Redirects:** enforce at the public router; auto-create on slug change; tenant-scoped.

## 20. Preview / publish architecture

- **Preview:** Editor Preview (read-only canvas, chrome kept) and Visitor Preview (framed authorized preview endpoint) exist. Add device frames, *Draft Preview* chip, "open in new tab", and honour unsaved edits by auto-saving before opening (or showing the saved-draft notice, as today).
- **Publish pipeline:** *Save draft → Validate → Compile → Checks → Publish* with truthful status. **Checks** (new, read-only service): SEO completeness, heading hierarchy, missing alt, unlabeled controls, broken internal links, unresolved bindings/entitlements, contrast warnings, empty sections. Errors block; warnings do not.
- **Publish ▾ menu:** Publish, Schedule, Unpublish; **Schedule/Unpublish require the missing publisher job and `unpublishPage()`**.
- **Concurrency:** require `expected_revision_id` on publish (currently skipped when null).
- **History:** compare/restore UI polish; retention policy decision.
- **Not in scope:** hosting destinations, custom domains, static export, site-level private visibility (M10) — platform features, decision D5.

## 21. AI / import / export architecture

- **AI:** a Builder assistant panel with modes **ASK** (read), **PLAN** (read; proposes), **EDIT** (applies operations to the current selection), **BUILD** (creates sections/pages) — all via `ORIGIN_ADMIN_ASSISTANT`, `expected_revision_id`, `ai_operation` drafts, review diff, **human publish**; cannot lock/unlock layers; `studio_archive_page` and `studio_save_tokens` remain AI-exposed today and should be re-evaluated (caution flagged by the platform inspection).
- **Import/export:** package v1.0 (page/component/template/tokens) exists; add **section export** kind and UI entry points (Page tools, Section ⋯). HTML import stays constrained. React/JSX/ZIP/Figma importers (8C–8E) remain out of Builder 2.0 unless separately approved.

## 22. Security + multi-tenancy

### 22.1 What must NOT be introduced
- Arbitrary uploaded/server-side code execution; unrestricted JavaScript (no raw `<script>`, no `javascript:`/event handlers, no per-element Custom JS); **raw HTML/"Code" blocks**.
- AI direct DB mutation; AI/MCP bypassing `StudioApplicationService`; AI publish/import.
- Duplicated Kohevo business logic in the Builder (providers reference modules).
- **Unrestricted external fetching** — this rules out *API Connections*, third-party *Integrations*, remote Lottie/embed URLs outside an allow-list.
- Tenant control over platform identity, the signature slot, or licensing.
- Replacing the canonical document with third-party editor state (GrapesJS/Puck/Lexical JSON must never be persisted).
- Per-element free-text CSS; `position:fixed` that can cover the platform signature; `url()` in free values.
- Browser persistence of document state; unscoped repository access; client-supplied tenant ids.
- Unnecessary rewrites/dependencies; any change to Studio tables via `db/migrations/`.

### 22.2 Multi-tenancy requirements for each phase
Every new endpoint/provider/job: tenant from the server session only; fail closed; cache keys include tenant; adversarial cross-tenant tests (open Phase 10 item) added to CI.

### 22.3 Pre-existing issues to triage immediately (not feature work)
1. **`attributes` allow-list gap [CONFIRMED BY CODE READING, not yet exercised at runtime]:** `DocumentValidator` checks only that `block.attributes` is a JSON object, and `DocumentRenderer` (the block-wrapper code around lines 201–206) emits every attribute whose *name* matches `^[a-zA-Z][a-zA-Z0-9_-]*$` with an HTML-escaped value. Escaping the value does not stop an attribute *name* such as `onclick`, `onmouseover`, `style` or `srcdoc` from being rendered. Public pages run the Studio runtime script, so this looks like a **stored-XSS path today for anyone with `studio-builder.edit` (and for AI/MCP operations, which use `update_block_attributes`)**, independent of Builder 2.0. *Action (before any other work):* write a failing test that renders `{"attributes":{"onclick":"alert(1)"}}`, then hot-fix with an allow-list (`aria-*`, `role`, `data-*`, `id`, `tabindex`, `title`, `lang`) in both validator and renderer, and review existing stored documents for violations.
2. **`isSafeCssValue` does not block `url(` or `@import`** for raw style values — external-fetch risk; tighten when the style surface expands.
3. **Provider tenant scoping:** Booking/Membership/Forms providers call APIs that read the *global* `current_tenant_id()`, not the injected `TenantContext` (violates the locked tenant rule; latent).
4. `PostsProvider` unbounded SELECT; `TaxonomyProvider` loads all rows; the row cap is applied after execute.
5. `publishWorkingRevision` skips the concurrency check when `expected_revision_id` is null.
6. `P/admin/redirects.php` etc. bypass the application-service pipeline; redirects stored but never enforced.
7. `reviews.php:85` filters `revision_kind 'ai'` (enum is `ai_operation`).
8. `ThemeBottomSheet` facade; stale `OPEN-QUESTIONS`/ROADMAP docs; missing Phase 9A–C docs.

## 23. Performance + testing

- **Budgets:** keep `builder.js` ≤ ~600 KB min (currently ≈ 464 KB), rich-text chunk lazy; selection change must not re-render the whole tree (split context); overlay updates in `requestAnimationFrame`; canvas reload only on structural/non-patchable changes; typing latency < 50 ms; mobile first-interaction < 2 s on a mid-range device (target, to be measured).
- **Server:** compile cache keyed per revision; breakpoint/state CSS emitted once per page, id-scoped, deduplicated; row caps in SQL.
- **Tests (existing style, no new deps unless approved):** PHP unit (`make test-unit`), JS `node --test`. **Add (decision D-test):** a DOM-level harness (jsdom or Playwright, dev-only) for shell, overlay, Outline keyboard, mobile sheets, ARIA; golden-render tests for every new emitter; adversarial tenant tests; load tests for large documents (250 blocks / 50 sections); a11y checks (axe) in the harness; CI bundle-drift check.
- **Known CI baseline:** five unrelated unit failures exist (licensing remote client, archived plugins, session handler); each phase's DoD is "no *new* failures".

## 24. Risks + unknowns + decisions required

### Decisions the product owner must make before B2-P1
| ID | Question | Recommendation |
|---|---|---|
| **D1** | Shell: current merged left panel vs Add-left/Inspector-right (concept D01/D02, MASTER §36) vs *floating* Inspector (Notion) | Add-left + Inspector-right, hide toggle widens canvas; floating as optional preference |
| **D2** | Responsive model: keep mobile-first buckets vs Desktop→Tablet→Mobile inheritance | New desktop-first `style_responsive` alongside untouched legacy maps; UI says Desktop/Tablet/Mobile |
| **D2m** | Mobile bottom nav: Blocks·Edit·Theme·Preview·More vs Add·Elements·Pages·Layers·Theme·More | Notion's five (consistent with today's dock); Layers/Pages inside More |
| **D3** | Document shape: keep sections→blocks vs recursive `nodes[]` | Keep (extend); do not migrate |
| **D-nesting** | Raise block nesting depth 4 → ? (concept tree is 5 deep) | Raise to 6 after render/size tests |
| **D5** | Hosting/domain/static export/site-private visibility (m10) | Out of scope; separate platform decision |
| **D6** | Add-panel taxonomy (Sections·Elements·Components·Dynamic vs Blocks·Elements·Templates) and where Media/Templates/AI live | Four tabs; Media/Templates/AI via `+` menu and search |
| **D7** | Ecommerce, API connections, Integrations, User data (visual-only items) | Exclude (no backing / forbidden) |
| **D8** | Navigation model approval (new tenant table + provider) | Approve a tenant-scoped menu entity |
| **D9** | Comments & reviews (needs new table) | Defer unless prioritised; P6 optional |
| **D-undo** | Keep server-rollback undo or add an in-memory inverse-op stack | Keep (locked); revisit only if latency proves unacceptable |
| **D-test** | Approve a dev-only DOM/e2e test dependency | Approve |
| **D-theme** | Approve token-schema extension (categories, modes) | Approve |
| **D-D09/11/12** | Supply the missing desktop screens (Component Inspector, Theme, Preview/Publish) and mobile Edit Heading/Edit Button | Needed before B2-P3/P4/P6 UI work; planned from Notion until then |
| **D-limits** | Section/block budget increases | Hold unless measured |

### Top risks
1. Responsive/state emitter (R8) invalidates every compiled page (version bump) — needs a staged rollout and a recompile plan.
2. Spec/concept contradictions (D1, D2, D2m, D6) cause rework if not decided up front.
3. Missing screens (D09/D11/D12, M07/M09) → UI built from text only.
4. Section presets currently insert single primitives; composed presets need real content/thumbnails (design and asset work, not just code).
5. Regression risk without a DOM-level test harness.
6. Navigation/theme/menus need schema changes under the *existing* schema authority (`install.sql` + `StudioSchemaManager`) — approvals required; user instruction forbids migrations at this stage.
7. Scope creep from visual-only items (ecommerce, API connections, hosting).

### Unknowns [UNKNOWN]
Whether `src/Module/Studio` (dance-studio domain) is tenant-guarded; pagination/archive routes for dynamic pages; real behaviour of the `⌘K` badge; whether `sb-align-*` desktop/tablet/mobile classes are a latent bug; duplicate-page and set-homepage commands; exact runtime performance.

## 25. Recommended implementation order

0. **Pre-phase triage (not part of the six):** items 22.3 #1 (verify + hot-fix), #5, #7; answer D1–D9.
1. **B2-P1** Foundation & Workspace → 2. **B2-P2** Add / Elements / Components / Navigator → 3. **B2-P3** Inspector & Visual Styling → 4. **B2-P4** Responsive + Theme + Design System → 5. **B2-P5** Platform features + Dynamic + Pages + Navigation + AI/Import/Export → 6. **B2-P6** Preview + Publish + SEO/A11y + Hardening.

Dependencies: P1 (selection/overlay/shell) underlies everything; P2's section catalogue needs P3-style props to feel real but ships useful with today's props; **P3 introduces the schema/compile changes that P4 builds on**; P5 needs the provider fixes (P5 step 1) and menu approval; P6 hardens and ships.

## 26. Exactly six implementation phases

| Phase | Name | One-line outcome |
|---|---|---|
| **B2-P1** | Builder Foundation & Workspace | Premium 3-panel shell, selection model, canvas overlay, compact chrome, mobile shell foundation, test harness |
| **B2-P2** | Add / Elements / Components / Navigator | A real library: composed sections, full element set, Kohevo components (shells), Pages & Layers panels |
| **B2-P3** | Inspector & Visual Styling | Contextual collapsible Inspector over a closed, validated, token-first style system incl. layout/position/states/effects |
| **B2-P4** | Responsive + Theme + Design System | Desktop/Tablet/Mobile editing with inheritance and reset; real Theme with scales, component tokens and modes |
| **B2-P5** | Kohevo Platform Features + Dynamic Data + Pages + AI/Import/Export | Staff/Locations/Booking/Portal data, generic collection UX, menus, media, pages, in-builder AI, section/page export |
| **B2-P6** | Preview + Publish + SEO/Accessibility + Production Hardening | Checks-gated publish, scheduling, redirects, hreflang/JSON-LD, a11y checks, performance, adversarial tenant tests |

Each phase is specified in its own document (`01`–`06-BUILDER-2-PHASE.md`).

---

## Final summary

- **Understood:** Builder 2.0 is a canvas-first, contextual, premium editor on top of Kohevo's existing safe platform; the work is mainly front-end experience, a richer closed styling model, responsive/theme depth, navigation/data breadth, and publish/SEO/a11y hardening — not a platform rewrite.
- **Final product feel:** pick a section, drop it, click anything, tweak only what matters, flip devices and see/reset overrides, preview as a visitor, publish with confidence — on desktop or phone.
- **Exists:** platform, document/ops/revisions, publish, AI draft channel, templates/components, tokens, import/export, previews, layer lock/rename, multi-select Layers.
- **Missing:** split shell + selection model, composed library, layout/position/state/effects styling, responsive inheritance/reset, theme scales/modes, navigation, new providers, pages/media panels, section export, publish checks/scheduling/redirects, hreflang/JSON-LD, authoring a11y checks, in-builder AI, comments.
- **Six phases:** B2-P1 … B2-P6 above.
- **Major risks:** compiler/stylesheet invalidation; unresolved spec/concept contradictions; missing screens; schema approvals; no DOM-level tests; visual-only scope creep; **an `attributes` stored-XSS gap that I confirmed by reading the validator and renderer and that should be fixed now, before any Builder 2.0 work**.
- **Open questions:** §24 decision table.
- **Sufficiency:** yes — six phases cover the full intended product **if** D1–D9 are decided first and the missing screens (D09, D11, D12, M07, M09) are supplied before the phases that depend on them. I do **not** claim implementation readiness for B2-P3, B2-P4 and B2-P6 UI work until those screens exist, and nothing here has been verified in a running browser.
