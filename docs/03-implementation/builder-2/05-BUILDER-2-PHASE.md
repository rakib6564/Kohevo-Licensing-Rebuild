# B2-P5 — Kohevo Platform Features + Dynamic Data + Pages + AI / Import / Export

> Planning specification only. See `00-BUILDER-2-MASTER-REVIEW.md` §15, §18–19, §21–22 and decisions **D5, D7, D8, D9**. Paths: `PLUGIN` = `01-client/plugins/studio-builder`, `UI` = `PLUGIN/ui/src`, `SB` = `01-client/src/Module/StudioBuilder`.

## Objective
Make the Builder genuinely **Kohevo-native**: tenant-safe dynamic data across Services, Posts, Staff, Locations, Memberships, Booking, Forms and the Customer Portal with a proper collection/filter/sort/limit UX; **structured Navigation/menus**; a **Pages** panel and page settings; media management; **section and page export**; and an **in-builder AI assistant** (ASK/PLAN/EDIT/BUILD) that stays draft-only and review-gated — all consuming existing domain capabilities rather than duplicating business logic.

## User-visible outcome
- Choose a data source (Services, Posts/Blog, Staff, Locations, Membership Plans, Booking, FAQs/Testimonials *if real data exists*), set **Collection → Filters → Sort → Limit**, see a bounded **preview** of real items, and watch dynamic regions badged on the canvas.
- Add Staff List, Location/Map, Booking Calendar/CTA, Customer Portal entry as working components.
- Manage **menus** (header/footer/mobile; nested; page/external/anchor links; dropdowns) and place them in header/footer.
- **Pages** panel: create from blank/template/section/AI, rename, duplicate, archive, set homepage; **page settings** (details, background, layout, SEO entry); **Media** panel with search, replace, alt/title editing, focal point.
- **Save as template**, **Export page**, **Export section**.
- Open the **AI** panel: ask questions, plan, edit the selection, or build sections — every change arrives as a reviewable draft; only a human publishes.

## Features
1. **Provider hardening first (blocking step):** make Booking/Membership/Forms providers use the injected `TenantContext` (not global `current_tenant_id()`); apply row caps in SQL (`LIMIT`) for Posts/Authors/Taxonomy; bound taxonomy loads.
2. **Generic collection query:** provider parameter schema gains validated `filter`, `sort`, `limit` (allow-listed fields per provider); `DataProviderRegistry` keeps entitlement + permission + cap; new read-only `preview_binding` action returns ≤ cap normalised rows.
3. **New providers (adapters over existing modules):** `staff.members` (reuse instructor contacts / `studio_contact_roles` — [UNKNOWN] tenant guarding of `src/Module/Studio` must be verified first), `locations.places` (reuse `booking_resources` rooms/locations), `booking.calendar` (availability adapter via `BookingAPI`), `portal.links` (link-out adapter; the portal stays its own app), optional `content.faqs`/`content.testimonials` **only if** a real backing store is approved.
4. **Dynamic UX (D06/M06):** Source/Collection/Field/Filter/Sort/Limit panel, item preview with drag reorder where meaningful, **Dynamic ↔ Static** toggle, field mapping, canvas badges ("Dynamic: Services Section"). *Conditions / Display rules* map to `settings.conditions` (currently allowed but never validated/persisted — validate and persist or drop).
5. **Navigation (decision D8):** tenant-scoped menu entity (nested items; page/external/anchor/dropdown; placement header/footer/mobile) + `nav.menu` block + `content.menus` provider; header/footer partials reference menus; **dependency extraction** updated so menu edits invalidate dependent pages; menus never store HTML.
6. **Pages panel & page settings (M13/M14):** list, status, create (blank/template/section/AI), rename, duplicate, archive, set homepage, per-page SEO entry; page background/layout settings via `settings`/section 0 conventions (P3 props).
7. **Media (D07/M13):** Media panel over `window.SlateMedia`: search, upload, replace-in-place, library alt/title/description editing, **fallback to library alt** when a ref's alt is empty (renderer change), focal point; tenant-safe references only.
8. **Export:** `section` package kind (single section/component subtree with media/component maps) added to `StudioPackageService`; Page tools entries *Export page*, *Save as template*, *Export section*; import UX polish (dry-run report, conflicts).
9. **In-builder AI:** `ORIGIN_ADMIN_ASSISTANT` actor; modes **ASK** and **PLAN** (read tools only), **EDIT** (operations on current selection), **BUILD** (new sections/pages) — all via `applyDocumentOperation` with `expected_revision_id`, producing `ai_operation` drafts; a visible **plan/diff review** before applying to the working draft where applicable; undo via revision rollback; **no publish, no import, cannot lock/unlock**; re-evaluate AI exposure of `studio_archive_page` and `studio_save_tokens`.
10. **Out of scope (explicit):** API connections, third-party Integrations, Ecommerce/Products, User-data binding beyond portal link-outs, hosting/domain/static export/site-private visibility (D5/D7).

## Screens affected
D05, D06, D07/D10 (Pages tab), D12 entry points; M05, M06, M13, M14; AI panel (new; concept absent).

## Frontend work
- `UI/components/dynamic/*` (SourcePicker, CollectionPanel, FilterBuilder, SortLimit, PreviewList, DynamicBadge overlay).
- `UI/components/pages/*` (PagesPanel, PageSettings), `UI/components/media/*` (MediaPanel, MediaDetails), `UI/components/menus/*` (MenuManager, MenuItemEditor with drag/nesting limits).
- `UI/components/ai/*` (AiPanel with mode switch, plan/diff view, apply/discard, selection-aware EDIT).
- Export/import dialogs updated; Page-tools sheet (M13/M14) wired.

## Backend/domain integration
- `StudioApplicationService`: new methods + `StudioAuthoringApi` allow-listed actions — `preview_binding`, `list/create/update/archive_menu`, `duplicate_page`/`set_homepage` (if absent), `export_section`, `update_media_meta` (delegates to the media library API), `assistant_*` (ASK/PLAN read; EDIT/BUILD write drafts). All run `authorize()` unchanged; mutations audited; origin recorded.
- Providers call the owning modules' APIs (Booking, Membership, Forms, Studio domain) **through the injected `TenantContext`**; the Builder stores only references/params.
- MCP: extend catalogue only with read/draft tools consistent with Phase 7 rules.

## Data/model impact
- **Menus:** new tenant-scoped table(s) (`studiobuilder_menus`, `studiobuilder_menu_items` or a JSON document per menu) added **only** via `install.sql` + `StudioSchemaManager` (additive) after approval; `tenant_id NOT NULL`, no defaults; repository extends `StudioRepository`.
- **Documents:** new block type `nav.menu`; provider-bound blocks gain binding param shapes (existing mechanism); `settings.conditions` becomes validated or removed.
- **Packages:** package format stays `1.0` if the new kind is additive; otherwise bump with a compat reader.
- **Media:** no Studio table; uses `media_files` fields already present (`alt_text`, `title`, `description`).
- **Not performed now:** no migrations or schema work in this planning stage.

## Security considerations
- **Tenant isolation is the central risk:** every provider/endpoint derives the tenant from the server session; fail closed; adversarial cross-tenant tests (menus, providers, media, AI, exports).
- Providers: entitlement + permission + parameter schema + SQL caps; no raw SQL fragments from params; sort/filter fields allow-listed.
- No external fetching (no API connections); link fields via `Html::safeUrl`; menu external links `rel`-safe.
- AI: draft-only, no publish/import, cannot change locks; **per-token/per-user rate limits**; prompts/outputs never executed; all writes validated by `DocumentValidator`; privacy — no cross-tenant context in prompts; audit entries with origin.
- Media replace-in-place must preserve tenant ownership and managed-path rules; alt text sanitised.
- Export/import: size caps, media maps tenant-verified (existing), no binaries/foreign ids/fetching.
- Portal/Booking adapters must not expose personal data of other users (only link-outs or public-safe fields).

## Tests
- PHP: provider tenant tests (two tenants, same ids, entitlement on/off), SQL cap tests, parameter-schema fuzzing, menu validation/normalisation/dependency extraction, page command authorization, media meta authorization, section-package round trips (dry-run/commit, hostile inputs), AI actor tests (modes, forbidden operations, lock refusal, rate limits, no publish), `settings.conditions` validation.
- JS: filter/sort/limit builders, menu tree edit rules (depth/count), AI panel state machine.
- DOM/e2e: bind a Services collection and see the preview; create and place a menu; create/duplicate/archive a page; replace media and edit alt; AI EDIT flow producing a reviewable draft; export/import round trip.
- Load: provider endpoints with large datasets (caps hold); large menus.

## Dependencies
B2-P2 (Add/Components shells, Pages tab), B2-P3 (props to compose with), B2-P4 not strictly required. Decisions **D5, D7, D8, D9**; verification of the `src/Module/Studio` domain's tenant guarding; approval to extend the schema (menus).

## Files/modules likely to change
`SB/Provider/*` (registry + providers, incl. new), `SB/Registry/ModuleBlockDefinitions.php` (+ `nav.menu`), `SB/Application/StudioApplicationService.php`, `SB/Http/StudioAuthoringApi.php`, `SB/Application/StudioPackageService.php` + `SB/Package/*` (section kind), `SB/Mcp/*` (assistant + catalogue), `SB/Render/Media/*` (library alt fallback), `SB/Render/ChromeResolver.php`, `SB/Render/DependencyExtractor.php`, `SB/Repository/*` (menus), `PLUGIN/install.sql` + `StudioSchemaManager` (**approval-gated**), `PLUGIN/admin/*` (reviews fix), `UI/components/{dynamic,pages,media,menus,ai}/**`, i18n, tests, `CHANGELOG.md`, bundle.

## Files/modules that must NOT change
`authorize()` order, `StudioRepository` tenant forcing, revision/publish transaction, **AI publish/import prohibition**, `IssuerAuthority` scope intersection, canvas CSP/sandbox, `StudioCodePolicy`, platform signature/licensing gate, owning modules' business logic (Booking, Membership, Forms, Portal, Media library internals — consume their public APIs only), `db/migrations/` (no Studio tables there), canonical JSON/ids/`schema_version`.

## Acceptance criteria
1. No provider reads another tenant's data under any test (two-tenant harness); the global-tenant defect is gone.
2. Every collection query is bounded in SQL; unauthorised/unentitled tenants get nothing and cannot bind.
3. Staff, Locations, Booking and Portal components work from real data with bounded previews; unavailable sources are hidden, not faked.
4. Menus are structured data; editing a menu updates every dependent page after publish; header/footer reference menus; mobile nav renders correctly.
5. Pages panel commands work, are audited, and are tenant-safe; media alt/replace works and library alt falls back correctly.
6. Section/page export round-trips through import (dry-run + commit) with hostile inputs rejected.
7. AI EDIT/BUILD produce `ai_operation` drafts with a visible diff; no publish path exists; lock changes are refused; rate limits enforced.

## Definition of Done
Acceptance met; schema change approved and applied via the Studio mechanism; two-tenant adversarial suite in CI; CHANGELOG, EN/FR, docs and admin help updated; AI safety review signed off; performance budgets met for provider previews; CI green on both DBs; recompile/cache notes where block types changed.

## Verification plan
Two seeded tenants with overlapping slugs/ids; run the full adversarial suite; manual flows on desktop and phone; inspect SQL logs for caps; export a section and import into the other tenant (must remap/refuse correctly); run the AI flows against a stubbed model and a real token with restricted scopes; verify `reviews.php` filter fix; confirm no outbound requests from builder/preview except allow-listed media.

## Risks
- **Cross-tenant leakage** through provider adapters — highest-impact risk; test first.
- Dependence on modules whose API shape may change (Booking/Membership/Studio) — adapter boundary + contract tests.
- Menu model needs a schema decision; delaying it leaves Navigation static.
- AI scope creep (autonomy, tool breadth) — keep draft-only; cap operations per request (existing 50).
- Concept items with no backing (Ecommerce, API connections, Integrations, Products, Comments) — must be descoped explicitly, or they will silently expand the phase.
- `src/Module/Studio` guarding is **[UNKNOWN]** until inspected.
