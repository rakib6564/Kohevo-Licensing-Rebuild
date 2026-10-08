# Builder 2.0 Spec — Gap Analysis

- **Date:** 2026-10-08
- **Spec:** Notion page "Kohevo Visual Website Studio — Builder 2.0 Master UI & Product Overview" (Notion export)
- **Audited:** `01-client/plugins/studio-builder` (UI in `ui/src`, PHP in `01-client/src/Module/StudioBuilder`)
- **Method:** read-only source review by three parallel passes. Nothing was run; the built bundle (`assets/builder`) was not inspected. Items marked *unverified* were not traced fully.
- **Status key:** DONE / PARTIAL / MISSING

## Summary

The platform side is strong: drafts, publish, history, tenant scoping, import safety and AI review. The shell and styling depth diverge most from the spec.

### Key divergences

1. **Floating Inspector (D01, D02).** The Inspector is a tab in a docked 360px left panel. There is no floating surface and no show/hide toggle that expands the canvas. The spec asks for a 320–360px floating Inspector and a 230–250px Add panel. Commit `83e96c9` ("unify single-sided left builder") suggests this may be deliberate; decide whether spec or code wins before treating it as a gap.
2. **Layout and position controls.** Blocks have no flex, grid, order, offsets or sticky. Per-side margin/padding is missing (spacing token only).
3. **Interaction states.** No hover, focus, active or disabled styling.
4. **Add panel tabs.** Only Sections, Elements, Components. Dynamic, Media and AI are missing, so D06 and M06 are missing.
5. **Preview.** No in-app Editor or Visitor Preview; Preview opens an external tab. The mobile Preview dock item does nothing useful (M12).
6. **Responsive model.** Cascade is mobile-first (base → sm → md → lg), not Desktop → Tablet → Mobile. Few properties are responsive; no explicit reset control.
7. **Navigation.** No structured menu model (no table, nesting or dropdowns).

## 1. Shell and screens

Paths relative to `plugins/studio-builder/ui/src` (S = `components/StudioShell.jsx`, T = `TopBar.jsx`, L = `LeftPanel.jsx`, B = `BlockPalette.jsx`, O = `Outline.jsx`, C = `CanvasArea.jsx`).

| Item | Status | Evidence / note |
|---|---|---|
| D01 Default workspace | PARTIAL | S:480-505. Left panel 360px (css:497); inspector is a tab, not floating |
| D02 Inspector hidden | MISSING | `.sbx-right{display:none}` (css:519); RightPanel.jsx unused; only toggle collapses the whole left panel (S:469) |
| D03 Add / Sections | DONE | B:316, B:334; search B:300-310 |
| D04 Add / Elements | DONE | B:369 |
| D05 Add / Components | DONE | B:400 |
| D06 Add / Dynamic Data | MISSING | No Dynamic tab (B:316-318) |
| D07 Hero Inspector | PARTIAL | BlockInspector/SectionInspector only; no Hero-specific one (unverified) |
| D08 Element Inspector | PARTIAL | BlockInspector.jsx, StyleControls.jsx; see section 2 |
| D09 Component Inspector | PARTIAL | ComponentDialog.jsx; contextual-inspector behaviour unverified |
| D10 Navigator / Layers | PARTIAL | See Layers rows below |
| D11 Theme / Design System | PARTIAL | ThemeDialog.jsx; see section 2 |
| D12 Preview / Publish | PARTIAL | See rows below |
| M01 Mobile builder | DONE | MobileDock (S:511) |
| M02 Block selected | PARTIAL | Sheet opens at ≤860px (S:471-479); no touch-specific actions found |
| M03 Add a block | DONE | Bottom sheet via Blocks dock item |
| M04 Elements library | PARTIAL | Reuses B palette; no mobile picker |
| M05 Components library | PARTIAL | Same as M04 |
| M06 Dynamic data | MISSING | No UI |
| M07–M10 Edit heading/image/button/component | PARTIAL | All reuse generic BlockInspector; no per-type sheets |
| M11 Theme | DONE | ThemeBottomSheet (S:535-540) — but see persistence bug in section 2 |
| M12 Preview | MISSING | Dock item only closes the sheet (S:520-523) |
| M13 More options | DONE | MoreBottomSheet.jsx (S:542-557) |
| M14 Layers / page tools | PARTIAL | Reachable via More; drag/arrow keys only (O:165-168, O:292) |
| Top bar | PARTIAL | 48px (css:118-120) vs 52–56px; no Inspector toggle; account control not found |
| Canvas toolbar | PARTIAL | Zoom present (C:244-273); breadcrumb style exists (css:1098) but unused; no comments or grid |
| Floating Inspector | MISSING | See D01/D02 |
| Navigator / zoom | PARTIAL | Zoom exists (C:32); bottom navigator bar not found |
| Mobile bottom nav | DONE | MobileDock.jsx:23-27 |
| Bottom sheets | DONE | max-height 84vh (css:1479) |
| Safe-area | PARTIAL | Dock only (css:1575); sheets unchecked |
| Keyboard-safe | MISSING | No visualViewport/focusin handling |
| Layers: reorder, hide, collapse | DONE | O:165-168, O:18/147, O:90-255 |
| Layers: rename | PARTIAL | Section label only (O:152, O:343); block rename not found |
| Layers: lock | MISSING | `core/lock.mjs` is a page edit-lock, not a layer lock |
| Layers: multi-select | MISSING | Single id selection (O:286) |
| Preview editor vs visitor | PARTIAL | Edit/Preview toggle and Preview link both open `previewUrl` in a new tab (T:194-216, T:323) |
| Publish flow UI | PARTIAL | Button gated by `permissions.publish` (T:348); AiReviewDialog, HistoryDialog exist; no staged Validate/Compile/Checks UI |

## 2. Sections, elements, styling, inspector, responsive, theme

### Sections

| Item | Status | Evidence / note |
|---|---|---|
| Categories | PARTIAL | `BlockPalette.jsx:204-250` presets: Hero, Features, Services, Image+Text, Testimonials, Pricing, FAQ, Gallery |
| Team, Booking, Contact, CTA, Footer | MISSING | No preset ("CTA banner" label sits on `core.button`) |
| Preset behaviour | PARTIAL | Inserts one primitive, not a composed section; Testimonials→`core.text`, FAQ→`core.rich_text` |
| Content/layout/spacing/responsive | PARTIAL | `SectionInspector.jsx:92-127`: width, gap, columns, padding_y |
| Section background | PARTIAL | Token select only (SectionInspector:119); no image/gradient |
| Visibility / delete | DONE | SectionInspector:73-75,124 |
| Duplicate section | MISSING | Only page duplicate (MCP layer) |
| Section effects/interactions | MISSING | MotionInspector is block-only |
| Save to library | DONE | SectionInspector:67-72 (export not audited here; see section 3) |

### Elements

| Group | Status | Present / missing |
|---|---|---|
| Basic | PARTIAL | Present: container, flex, grid, section, heading, text, image, button, divider, spacer. Missing: Stack, Columns, Icon |
| Content | PARTIAL | Present: rich_text, video, feature_list, stats. Missing: List, Card, Quote, Table |
| Interactive | PARTIAL | Present: tabs, accordion, modal, carousel, offcanvas. Missing: Link, Tooltip, Countdown |
| Forms | PARTIAL | `core.form`, `core.form_field` only; per-field types unconfirmed |
| Media | PARTIAL | Image, video, gallery. Missing: Lottie/embeds |

### Styling system

Style surface is capped by `ALLOWED_STYLE_KEYS` in `Document/CanonicalDocumentSchema.php:170-186`.

| Group | Status | Evidence / note |
|---|---|---|
| Typography | PARTIAL | Size, weight, transform, align. Missing: style, line height, letter spacing, decoration, highlight |
| Background | PARTIAL | Color, 2-stop gradient (StyleControls:105), image, focal point. No overlay/repeat/video |
| Border/radius | PARTIAL | Style, width, color, radius presets. No per-corner radius or per-side border |
| Effects | PARTIAL | Shadow presets, opacity. Missing: transform, filters, blend, transition, cursor |
| Size | PARTIAL | `dimensions` (StyleControls:406); keys not enumerated |
| Layout (flex/grid/order) | MISSING | Only section columns and gap |
| Position | MISSING | Only z-index |
| Spacing | PARTIAL | `spacing_token` only; no per-side or linked control |
| Interaction states | MISSING | No state model |
| Visibility | DONE | `controls.jsx:41-68` |
| Advanced | PARTIAL | Classes, z-index, free-text `k=v` attributes. No dedicated tag/ID/ARIA/role fields; validator only checks `attributes` is an object (DocumentValidator.php:649-653) |
| No arbitrary JS | DONE | No free-text CSS box (StyleControls:11-17); `javascript:`, `expression(`, `behavior:` rejected (DocumentValidator.php:919) |

### Contextual Inspector

PARTIAL. `BlockInspector.jsx:44-52` shows the same tabs for every block (Content, Style, Motion, Advanced, Responsive, Visibility, plus Data when bindable). Only controls are capability-gated (`def.style_capabilities`). No per-type layout; no Interactions tab for Button. Kohevo Component has only `GlobalSectionPanel` (detach, publish, visibility) with no layout/spacing/background.

### Responsive

| Item | Status | Evidence / note |
|---|---|---|
| Desktop/Tablet/Mobile viewports | DONE | `core/viewport.mjs:13-17` |
| Inheritance direction | PARTIAL | Mobile-first (`viewport.mjs:36-42`); spec example "Mobile=36, Tablet inherited=64" can't be expressed |
| Per-node isolation | DONE | `controls.jsx:13` |
| Reset active breakpoint only | PARTIAL | "Inherit" option deletes one key (`controls.jsx:16-18`); no reset button |
| Coverage | PARTIAL | Only align, section columns, padding_y are responsive |
| Hide keys vs breakpoints | Unverified | Hide checkboxes write `desktop/tablet/mobile`; canonical breakpoints are `base/sm/md/lg` |

### Theme

PARTIAL. `ThemeDialog.jsx` saves server design tokens (colors validated, permission-gated). **Bug:** Brand and Typography tabs in `ThemeBottomSheet.jsx:28-31,224-255` are local state only; `siteName` defaults to "Northstar", `tagline` to "Independent Creative Studio"; `handleSave` (:61-75) sends only color swatches, so brand/logo/favicon/fonts are not persisted. Missing: type/weight/line-height scales, global UI tokens (buttons, forms, cards, nav), spacing/radius/shadow tokens.

## 3. Backend and platform

Paths relative to `01-client`. R = `src/Module/StudioBuilder`, P = `plugins/studio-builder`.

| Area | Status | Evidence / note |
|---|---|---|
| Dynamic data providers | PARTIAL | DONE: `booking.services`, `membership.plans`, `forms.form`, `content.posts` (R/Provider/DataProviderRegistry.php:80-115). MISSING: Staff, Locations, Customer Portal |
| Source→entity→field→filter→sort→limit | PARTIAL | Bindings are slot→provider+params (BlockInspector.jsx:45,261); filter/sort/limit only in PostsProvider.php:60-75; no field picker |
| Dynamic tenant scoping | PARTIAL | Posts/Authors/Taxonomy use `$tenants->id()`; Booking and Membership ignore it and use global `current_tenant_id()` (BookingServicesProvider.php:3-8, 51-56) |
| Kohevo components | PARTIAL | DONE: Services, Membership Plans, Form Card. MISSING: Service Card, Staff, Location, Booking CTA, Booking Calendar, Customer Portal |
| Global component reuse | DONE | StudioGlobalComponentService; `create_component`, `detach_component` (R/Http/StudioAuthoringApi.php:85-86) |
| Navigation as structured data | MISSING | No menu entity/table; header/footer only as partial pages (install.sql:9); `layout.offcanvas` has no link tree |
| Media | PARTIAL | Browse/upload tenant-filtered (P/admin/media.php:19-99); tenant-safe refs (R/Render/Media/CoreMediaResolver.php:36-50); focal point + alt per ref. No library alt/metadata editing or replace |
| Templates / reusable | DONE | Types in install.sql:84; Save to Library separate from Export (`export_package`/`import_package`, StudioAuthoringApi.php:90-91). Components stored as `section_preset` (CanonicalDocumentSchema.php:45) |
| Page export | DONE | R/Application/StudioPackageService.php |
| Section export UI | Unverified | Not traced |
| Interactions | PARTIAL | Triggers done (DocumentValidator.php:670); presets fade/scale/slide done; no declarative action model; no "move"/"reveal" presets; reduced-motion respected (StudioStylesheet.php:233) |
| AI | PARTIAL | 21 MCP tools (R/Mcp/StudioMcpToolCatalog.php:63-246); drafts only, cannot publish (StudioMcpScopes.php:46-47); review UI AiReviewDialog.jsx; tenant guard StudioMcpAdapter.php:136-138. No in-builder ASK/EDIT/BUILD/PLAN modes |
| Publish: save, validate, compile | DONE | Atomic publish+compile, failure keeps old state (StudioApplicationService.php:625-677) |
| Publish: Checks stage | MISSING | Validation only |
| History/compare/restore/rollback | DONE | HistoryDialog.jsx; RevisionDiff.php; rollback creates a new draft |
| Publish scheduling | MISSING | `scheduled` status/`scheduled_for` in schema only; no endpoint or cron |
| SEO core | DONE | Title, description, canonical (same-host), og:image, robots (R/Render/Seo/SeoHead.php:60-144); sitemap |
| Drafts not indexable | DONE | `forMode` forces noindex (SeoHead.php:123); X-Robots-Tag (StudioCanvasPolicy.php:44) |
| hreflang, JSON-LD | MISSING | No matches |
| Redirects | PARTIAL | Stored per tenant (P/admin/redirects.php:26-82); runtime enforcement not found (unverified) |
| Output accessibility | PARTIAL | `<html lang>`, ARIA on interactive blocks, reduced-motion. No heading-order, required-alt, contrast or skip-link checks |
| Tenant scoping (repos) | DONE | StudioRepository asserts scope (R/Repository/StudioRepository.php:44-127); all tables have `tenant_id` |
| Import safety | DONE | Caps (StudioPackageFormat.php:47-49); media/component maps tenant-verified (StudioPackageService.php ~601-626); HTML import strips script/iframe/object/embed (HtmlSourceReader.php:57); no eval/unserialize |
| Platform identity | DONE | Signature slot rendered last, protected (PageDocumentAssembler.php:8-56). Licensing-gate enforcement not verified |
| Preview tenant check | Unverified | preview.php/canvas.php token handling not traced |

## Bugs and risks found

- `P/admin/reviews.php:85` filters `revision_kind IN ('ai', …)` but the enum value is `ai_operation`; the AI filter never matches.
- `PostsProvider.php:72-160` fetches all published rows with no SQL LIMIT, then filters in PHP; the "max 50 rows" claim isn't enforced in SQL.
- Booking/Membership providers rely on global `current_tenant_id()` rather than the passed TenantContext (latent isolation risk).
- `ThemeBottomSheet` brand/typography state is not persisted (see section 2).
- Block hide keys vs canonical breakpoint keys may mismatch (unverified).

## Suggested order

1. Decide whether the floating Inspector stays in the spec; then fix shell and Preview (Add tabs, Visitor Preview, Inspector toggle).
2. Add layout, position, spacing and interaction-state styling controls.
3. Add the Navigation data model.
4. Add a pre-publish Checks stage (SEO, accessibility, links), then scheduling.
5. Fix the small bugs above.
