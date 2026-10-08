# B2-P2 — Add / Elements / Components / Navigator

> Planning specification only. See `00-BUILDER-2-MASTER-REVIEW.md` (§11 matrix, §12–13 screens, §22 restrictions). Paths: `PLUGIN` = `01-client/plugins/studio-builder`, `UI` = `PLUGIN/ui/src`, `SB` = `01-client/src/Module/StudioBuilder`.

## Objective
Turn the Add panel and Navigator into a **real library and structure tool**: composed section presets with thumbnails, the complete element set, Kohevo component blocks (data wiring comes in P5), a Pages tab, a touch-capable Layers panel, and deeper nesting — all using the existing registry/manifest, templates and operations.

## User-visible outcome
- **Sections** tab shows categorised, thumbnailed, *fully composed* sections (Hero Classic/Centered/Split/Video, Feature Grid/Split/List, Services Overview, About, Testimonials, Pricing, CTA, FAQ, Team, Gallery, Contact, Footer) — one click inserts a real, editable section, not a single heading.
- **Elements** tab lists Basic · Layout · Media · Content · Form · Interactive groups with every element in D04/M04 that the platform can safely support.
- **Components** tab lists Kohevo components (Booking Calendar/CTA, Service List/Card, Membership Plans, Staff List, Location/Map, Testimonials, Blog/Posts, Contact Form, Customer Portal entry) and global components; entitlement-gated ones are hidden or marked unavailable.
- **Navigator** has *Layers | Pages* tabs, search, rows with visibility/lock/⋯ menus, deeper nesting, touch reordering on mobile (M14).
- **Dynamic** tab shows the available data sources (read-only catalogue here; binding UX arrives in P5).

## Features
1. **Composed section presets:** author each as a `section_preset` template (blocks + props + styles using only P2-available props) and seed them as **system templates** (reuse `bin/seed-modern-website-kit.php`); card metadata (title, description, category, thumbnail).
2. **Thumbnails:** authored static images stored as tenant-safe media/asset refs, or a server "render to preview" at seed time (decision D-thumb); no live per-keystroke rendering.
3. **Server-side card metadata:** block `title/icon/description/category` come from the **manifest** (retire hard-coded `BLOCK_META`, R4).
4. **Elements (new blocks, registry definitions):** Icon, Columns, Stack, List, Card, Quote, Table, Tooltip, Link (element), Countdown, Audio, and distinct Input/Textarea/Select form fields; **Lottie/Embed** only through an allow-list of media hosts (decision D6) — otherwise omitted. **Code/HTML blocks are not built** (restriction).
5. **Kohevo components (block shells):** Service Card, Booking CTA/Calendar shell, Staff List, Location/Map, Testimonials, Customer Portal entry. They render with sample/empty states until P5 provides providers; entitlement-gated via `ModuleBlockDefinitions`.
6. **Navbar/Footer components (M05):** interim static-link navbars as header/footer *partials* (existing `header_partial`/`footer_partial` + `ChromeResolver`); upgrade to structured menus in P5.
7. **Add panel UX:** search across tabs, category "View all" drill-in, favourites (server-side or session-only — decision), click-to-insert after selection, drag-to-place with indicators, **insertion rules** (parent allows child; entitlement; limits) shown as disabled cards with reason.
8. **Navigator:** Pages tab (list, status chips, current-page marker, create blank/from template, rename, duplicate, archive, set homepage — only commands that exist; verify missing ones), row ⋯ menu (rename, duplicate, lock, hide, move up/down, save to library, delete), header/footer shown as partial references, touch drag handles and move up/down buttons (M14), keyboard parity retained.
9. **Nesting depth (Blocking decision D-nesting):** raise `MAX_BLOCK_DEPTH` from 4 to the approved value with tests (concept trees are 5 deep).
10. **Quick page tools row (M14):** Page settings, Navigation, Media library, Templates, Export page, Version history as entry points (targets implemented in P5/P6; stubs route to existing dialogs).

## Screens affected
D03, D04, D05, D06 (catalogue), D07/D10 (Navigator), M03, M04, M05, M06 (catalogue only), M14.

## Frontend work
- `UI/components/AddPanel/*` (tabs, search, category rail, cards, drill-in), `UI/components/NavigatorPanel/*` (Layers, Pages), refactor of `BlockPalette.jsx`/`LibraryPanel.jsx`/`Outline.jsx` into these.
- Card component reading manifest metadata; thumbnail with lazy loading and fixed aspect ratio; skeleton states.
- Insertion preflight using existing `canInsertBlock`/`insertionPoint` and server entitlement flags from the manifest.
- Mobile: category-rail sheets (M03–M05), full-height Layers sheet with action row.
- Touch DnD: pointer-event based reorder with handle; keep HTML5 DnD on desktop.
- All strings i18n.

## Backend/domain integration
- **Block registry:** new `BlockDefinition`s (declarative) for the elements above; `style_capabilities`, `allowed_child_types`, `required_entitlement` declared; manifest exposes `title/icon/description/category`.
- **Templates:** extend `StudioTemplateService` seeding path for system templates (read-only to tenants, copyable). Needs a way to mark `system` templates **without a schema change** (e.g. a reserved `template_key` prefix + a tenant-0/system tenant convention) — *requires an approved design; otherwise tenants receive seeded copies per tenant* (decision D-system-templates).
- **Page commands:** use existing `createPage`, `updatePageAddress`, archive; add duplicate/set-homepage **only if absent**, via new `StudioAuthoringApi` allow-listed actions + application-service methods running `authorize()` unchanged.
- **Limits:** if D-nesting approved, change `CanonicalDocumentSchema` limit constants and the validator depth check with tests.

## Data/model impact
- **Document:** new block *types* only (no new keys); preset documents are ordinary canonical documents; depth limit constant (if raised) — backwards compatible (loosening).
- **Tables:** none required if system templates use tenant seeding (otherwise a tiny approved change under `install.sql` + `StudioSchemaManager`).
- **Compile:** new renderers ⇒ bump `COMPILER_VERSION`; if stylesheet changes, bump `StudioStylesheet::VERSION`.

## Security considerations
- No raw HTML/Code/JS block; Lottie/Embed only via allow-listed hosts and `media_ref`/validated URL fields (`Html::safeUrl`); no remote fetch at render or authoring time.
- New blocks go through `FieldSchema` validation, rich-text sanitizer, `WidgetSecurityValidator` as applicable.
- Components respect `required_entitlement`; hidden for unlicensed tenants; direct insertion by API still refused server-side.
- Page commands: tenant-scoped, permission `edit`; archive refuses referenced global components (existing).
- Thumbnails: tenant-safe asset references, no external URLs.

## Tests
- PHP: registry/manifest tests for every new block (props validation, children rules, entitlement, normalization idempotence), renderer determinism + escaping tests, template seeding tests, depth-limit tests, page-command authorization/tenant tests; `StudioBuilderPhase5BuilderTest` fixture regenerated and pinned.
- JS (`node:test`): manifest-driven card building, insertion preflight, Pages tab state, mobile reorder maths.
- DOM/e2e: insert each preset and each element on an empty page; drag-drop and keyboard insertion; Pages tab flows; mobile sheets; axe checks.
- Golden renders for every seeded preset (stable HTML snapshot).

## Dependencies
B2-P1 (panels, selection, overlay, harness). Decisions D6, D-nesting, D-thumb, D-system-templates. Pre-phase triage of provider tenant scoping is *not* required here (components are shells) but required before P5.

## Files/modules likely to change
`UI/components/BlockPalette.jsx` (replaced), `LibraryPanel.jsx`, `Outline.jsx`, `LeftPanel.jsx`, new `AddPanel/*`, `NavigatorPanel/*`; `SB/Registry/*` and `SB/Registry/ModuleBlockDefinitions.php` (new definitions), `SB/Document/CanonicalDocumentSchema.php` + `DocumentValidator.php` (depth only), `SB/Render/Block/*` (renderers), `SB/Render/StudioCompiler.php`/`StudioStylesheet.php` (version bumps), `SB/Service/StudioTemplateService.php`, `SB/Http/StudioAuthoringApi.php` (+ page commands), `bin/seed-modern-website-kit.php`, `PLUGIN/lang/fr.php`, `PLUGIN/admin/builder.php`, tests, `CHANGELOG.md`, rebuilt bundle.

## Files/modules that must NOT change
`authorize()` order, `StudioRepository`, revision/publish/compile transaction, AI restrictions, canvas CSP/sandbox, document id patterns/canonical JSON/`schema_version`, existing block `type` strings and versions (changing one orphans stored pages), existing operations, `StudioCodePolicy`, platform signature slot.

## Acceptance criteria
1. Every section card in D03/M03 inserts a composed, valid, editable section; no card inserts a bare primitive.
2. Every element in the approved D04/M04 list is insertable, validates, renders escaped and deterministic, and is keyboard- and touch-insertable.
3. Entitlement-gated components do not appear (or are disabled with reason) for unlicensed tenants and cannot be inserted via API.
4. Pages tab shows the tenant's pages only; create/rename/duplicate/archive work and are audited; cross-tenant access is impossible (test).
5. Nesting at the approved depth validates and renders; one level deeper is rejected with a clear error.
6. No change to existing documents (existing fixtures validate/normalize byte-identically); all prior tests pass.

## Definition of Done
Acceptance met; manifest fixture updated and PHP drift test green; compiler/stylesheet versions bumped with a recompile note in the PR; seeded presets reviewed visually against the concept; CHANGELOG, EN/FR strings, docs updated; bundle rebuilt; CI green on both databases; accessibility pass for new panels.

## Verification plan
Seed a clean tenant; insert every preset/element/component on desktop and mobile; open the page in preview and public routes; run all new golden tests; run `make test-unit` and `make test-integration` (needs DB) on a test database only; compare Add/Elements/Components/Navigator against D03–D07/M03–M05/M14; confirm no network requests to third parties from builder or rendered pages.

## Risks
- Presets look generic without real design assets (thumbnail/art dependency) — treat as a design deliverable.
- New block types are forever (type strings are stored in documents) — name carefully; version from day one.
- System-template mechanism may need a schema decision; fallback is per-tenant seeding.
- Depth increase raises render/size cost — measure before approving.
- Ecommerce/Products/API cards in the concepts have **no backing**: omit, do not fake.
