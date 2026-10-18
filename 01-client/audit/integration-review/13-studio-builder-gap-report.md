# Studio Builder feature-gap report (consolidated)

## 1. Scope

- **What this is:** a product gap audit of the Kohevo Studio Builder, the Elementor-style page and theme builder. It asks whether each control, layout value, token and template setting does what the editor says it does, on the public page and in the canvas.
- **What it is not:** the security audit. Security items show up here only when they are also product defects (W-F1, TB-02, TB-03, ST-01). Their full record stays in the findings register.
- **Method:** read the source only. Nothing was run in a browser, no tests were run and no database was opened. Every CSS-cascade conclusion comes from reading the emitted CSS strings and the order they are assembled in.
- **Tree:** branch `integration/release-check-highs`. All paths are relative to the repo root (`01-client/...`).
- **Inputs:** the read-only audit reports in `01-client/audit/integration-review/studio/`, covering widgets and controls, containers and layout, design tokens, Theme Builder, and templates and import/export, plus `12-findings-register.md`. I treated their claims as unproven and re-opened every cited line I kept. Line numbers below come from this tree.
- **IDs:** I reused the register and report IDs. Container and layout Medium items have no register ID of their own (the register groups them as "CL/DT Medium"), so they appear as `CL-Mn`, where n is the container report's `F-Mn`. Design-token Medium items appear as `DT-Mn`, from the token report's `T-Mn`.

## 2. Coverage

| Area | Status | What was read |
|---|---|---|
| Containers and nested layouts | Audited | `DocumentRenderer::renderSection`, the container renderers (`ContainerRenderer`, `FlexRenderer`, `GridRenderer`, `LayoutContainerRenderer`, `SectionRenderer`), the `StudioStylesheet` base CSS, the nesting limits, canvas and Layers drag, clipboard |
| Competing layout values and responsive behaviour | Audited | `StyleSurface` (fields, responsive fields, device rules, states), the `RenderCollector` rule order, the `StudioCompiler` CSS assembly order, `BlockInspector`/`SectionInspector`/`SurfaceControls`, `responsiveStyle.mjs` |
| Design tokens and global styles | Audited | `ThemeResolver`, `ResolvedTheme`, `StudioThemeService`, `TenantBranding`, `admin/settings.php` Branding, `SiteSettings.jsx`, `ThemeBottomSheet.jsx` |
| Widget inventory and control schemas | Audited (schemas); partially audited (behaviour) | All 60 registered types and their FieldSchema (333-row CSV in the source report). Per-option CSS was checked mechanically only for the options cited below. |
| Theme Builder templates and display conditions | Audited | `ThemeTemplateResolver`, `TemplateConditionMatcher`, `ChromeResolver`, `StudioPublicRuntime`, `PublicRouter`, `public.php`, `admin/templates.php`, `TemplatePreviewContext` |
| Template persistence, import/export, compatibility | Audited | `StudioTemplateService`, `StudioRevisionService`, `StudioPackageService`, `StudioPackageFormat`, `DocumentValidator` (versions) |
| Forms, Membership, Booking and other system-plugin widgets | **Partially audited** | Only block schemas, escaping and the `isDynamic` flags (`forms.*`, `booking.*`, `membership.plans`, `payment.*`). The providers and render paths that fetch module data were not read. Submission handling for `core.form` was not traced. |
| Editor preview vs frontend rendering | Audited | Same compile path for canvas, preview and public. The client mirror is `liveStyle.mjs`. Overlay CSS is in `canvas.mjs`. |
| Accessibility | **Partially audited** | Only what the widget report noted: search box, carousel, form field, headings, ratings. No full pass and no screen-reader or contrast run. |
| Sanitization | **Partially audited** | Field validation and render escaping per widget, import/export envelope. `RichTextSanitizer` was read but not fuzzed. |
| Performance | **Not audited** | The only input is a note in the Theme Builder report that there are two candidate queries per public request (TB-16, Info). Nothing was measured. |
| Regression coverage | **Partially audited** | The source reports mapped existing tests per area. No tests were run, and the e2e specs were read but not executed. |

## 3. Findings

### Format

Every finding below has the same fields:

- **Sev / status:** severity, and Open or Fixed (with branch and commit).
- **Evidence:** file and line in this tree.
- **Now:** current behaviour.
- **Expected:** what it should do.
- **Fix:** a small, concrete change.
- **Test:** a test sketch, or manual browser steps.

PHP tests use the repo's `unit('…', function (): void { … })` harness with `assert_true`. Node tests live in `01-client/plugins/studio-builder/ui/tests/`.

### 3A. Competing layout values

#### CL-01: Page "Width" setting does nothing
- **Sev / status:** High · Open. The token report's T-H3, a false Site settings hint, has the same root cause.
- **Evidence:**
  - The Page inspector offers the control: `plugins/studio-builder/ui/src/components/inspectors/PageInspector.jsx:177`.
  - The compiler writes `sb-main--{width}`: `src/Module/StudioBuilder/Render/Compile/StudioCompiler.php:99-102`.
  - The base CSS has only `.sb-main{display:block}`: `src/Module/StudioBuilder/Render/StudioStylesheet.php:52`.
  - The only rules for `.sb-main--*` are in `plugins/studio-builder/assets/portfolio_style.css:151-189`, which only `bin/seed-rakib-portfolio.php:90` loads.
  - The hint says each page picks its width there: `plugins/studio-builder/ui/src/core/messages.mjs:518`.
- **Now:** changing Narrow/Normal/Wide/Full in Page settings has no visible effect. Section `layout.width` (`sb-w-*`) decides the width.
- **Expected:** either the page width sets the default for sections that do not set their own, or the control is gone and the hint is honest.
- **Fix:** have `DocumentRenderer::renderSection` fall back to the page's `settings.container_width` when `section.layout.width` is absent (today it falls back to `wide`, `DocumentRenderer.php:271`). If that is not wanted, remove the select and reword `ss_hint_layout`.
- **Test:**
  - Where: `tests/unit/StudioBuilderStyleSurfaceTest.php` (or a new `StudioBuilderPageWidthTest.php`).
  - Compile a document with `settings.container_width='narrow'` and one section with no `layout.width`.
  - Assert the section inner carries `sb-w-narrow`, or assert that `StudioStylesheet::css()` contains a `.sb-main--narrow` rule.

#### CL-02: Container props and `style.layout` edit the same flex/grid values; `style.layout` silently wins
- **Sev / status:** High · Open. Same issue as W-F13.
- **Evidence:**
  - Props become classes: `src/Module/StudioBuilder/Render/Block/CoreRenderers/FlexRenderer.php:40-77` (classes at 68-72), `ContainerRenderer.php:33-41`, `GridRenderer.php:37-62`.
  - `style.layout` becomes a scoped rule: `src/Module/StudioBuilder/Document/StyleSurface.php:54-75`.
  - Scoped rules are keyed `~` and sorted last: `src/Module/StudioBuilder/Render/RenderCollector.php:138,167`. The collector CSS comes after the base CSS: `StudioCompiler.php:103`.
  - Both editors sit on the Content tab for containers: `plugins/studio-builder/ui/src/core/inspectorSections.mjs:78,82`, `propPresenters.mjs:5-28`, `BlockInspector.jsx:181`.
- **Now:** set `layout.flex` direction to Row (prop) and Column in "Flex & grid" (`style.layout.direction`). The page shows Column, and the prop control keeps showing Row. The same holds for wrap, justify, align, gap and grid columns.
- **Expected:** one owner per value, or the Inspector shows clearly which value applies.
- **Fix:** in the Inspector, render the prop control read-only with a "set in Flex & grid" note when the matching `style.layout.*` key is set. A one-way migration to `style.layout` can follow later.
- **Test:**
  - Where: `plugins/studio-builder/ui/tests/inspector-sections.test.mjs`.
  - Render the props for a `layout.flex` whose `style.layout.direction` is set.
  - Assert the direction prop control is disabled.
  - PHP: assert the compiled HTML has both `sb-flex--row` and a `sb-x-*` class whose rule contains `flex-direction:column`, which documents the precedence.

#### CL-03: Per-device alignment classes have no media query; the mobile value applies at every width
- **Sev / status:** High · Open. Same issue as W-F4, which the register lists as Medium.
- **Evidence:**
  - `src/Module/StudioBuilder/Render/StudioStylesheet.php:245-247` defines `.sb-align-{desktop,tablet,mobile}-*` outside any `@media`.
  - The classes are emitted at `src/Module/StudioBuilder/Render/DocumentRenderer.php:155-156`.
  - The test only checks that the class is present: `tests/integration/StudioBuilderSprint1AcceptanceTest.php:293-295`.
- **Now:** desktop "center" with mobile "left" renders left-aligned everywhere, because the rule defined last wins. No editor control writes this path; it arrives through the API, MCP or import.
- **Expected:** each device value applies only in its range, as `sb-hide-{device}` already does (`StudioStylesheet.php:439-441`).
- **Fix:** wrap the three lines in the same queries used for `sb-hide-desktop|tablet|mobile`.
- **Test:**
  - Where: `tests/unit/StudioBuilderStyleSurfaceTest.php`.
  - Assert that `StudioStylesheet::css()` contains `.sb-align-mobile-left` only inside `@media (max-width:767.98px){…}`, and `.sb-align-desktop-center` only inside `@media (min-width:1024px){…}`.

#### CL-04: Width, height, min-height and max-width are inline and cannot vary per device
- **Sev / status:** High · Open.
- **Evidence:**
  - Inline emission: `src/Module/StudioBuilder/Render/DocumentRenderer.php:620-623`.
  - The device-overridable fields leave out `dimensions`: `src/Module/StudioBuilder/Document/StyleSurface.php:914-923`. The editor mirrors this at `plugins/studio-builder/ui/src/core/responsiveStyle.mjs:14-21`.
- **Now:** a block's width cannot change on tablet or mobile. Tenant custom CSS also cannot beat it without `!important`.
- **Expected:** dimensions behave like padding and margin: scoped rule, device overridable.
- **Fix:**
  - Emit `dimensions.width|height|min_height|max_width` through `StyleSurface::declarations` (scoped) instead of `buildInlineStyles`.
  - Add `dimensions` to `responsiveFields()` and to `RESPONSIVE_SCOPES`.
  - Update the live-style fixture.
- **Test:**
  - Where: `tests/unit/StudioBuilderStyleSurfaceTest.php`.
  - A block with `style.dimensions.width='600px'` and `responsive.mobile.style.dimensions.width='100%'`.
  - Assert the HTML has no inline `width:` and the collector CSS has `@media (max-width:767.98px){.sb-x-…{width:100% !important}}`.
  - Regenerate `ui/tests/fixtures/live-style.json`.

#### CL-M4: Hover/focus state colours lose to inline base colours
- **Sev / status:** Medium · Open (containers report C12/F-M4).
- **Evidence:**
  - Literal colour, background and opacity are inline: `DocumentRenderer.php:560-600`.
  - State declarations carry no `!important`: `StyleSurface.php:839` (`stateDeclarations`) and `:891-903` (`stateRules`).
  - Wrapped by `RenderCollector.php:119-127`.
- **Now:** a hover background on a block whose base background is a literal colour never shows.
- **Expected:** the state value applies on hover.
- **Fix:** emit literal base colour, background, border-colour, shadow and opacity into the scoped rule instead of inline (the same move as CL-04). Alternatively, add `!important` to state declarations only.
- **Test:**
  - Where: `tests/unit/StudioBuilderStyleSurfaceTest.php`.
  - Block `style.background.color='#fff'` plus `style_states.hover.background='#000'`.
  - Assert the HTML has no inline `background-color`, or that the hover rule contains `!important`.
  - Manual: hover the block on the public page.

#### CL-M8: Section padding and background each have two owners; the style always wins
- **Sev / status:** Medium · Open (C7, C8, F-M8).
- **Evidence:**
  - `DocumentRenderer.php:257-268`: the `section.style` scoped rule, plus the `background_token` class and `padding_y` classes on the same element.
  - Both appear in the inspector: `SectionInspector.jsx:133-142` (layout tab) and `:146-162` (background and style padding).
- **Now:** once Background colour or style padding is set, the Layout tab's "Background" token and "Vertical padding" stop doing anything, with no indication.
- **Expected:** one visible control per property, or an "overridden" marker.
- **Fix:** in `SectionInspector`, show the layout `background_token` and `padding_y` as overridden (disabled, with a "Reset style" link) when `style.background` or `style.padding.top|bottom` is set.
- **Test:**
  - Where: `ui/tests/inspector-sections.test.mjs`.
  - Section with `style.background.color` set: assert the token select is disabled.
  - Same for `style.padding.top` set: assert `padding_y` is disabled.

#### CL-M5: Two "hide on device" mechanisms with different breakpoints in one panel
- **Sev / status:** Medium · Open. Same issue as W-F12.
- **Evidence:**
  - `visibility.devices` (base/sm/md/lg) produces `sb-hide-{bp}`, with queries at `StudioStylesheet.php:25-30,436-438`.
  - `responsive.{desktop,tablet,mobile}.hide` uses `:439-441`.
  - Both are rendered at `BlockInspector.jsx:206-232`.
- **Now:** "md" and "tablet" cover the same range under two names; base+sm equals mobile. Either one hides the block.
- **Expected:** one vocabulary.
- **Fix:** keep `visibility.devices` in the UI. Stop offering the `responsive.*.hide` checkboxes for new edits, but keep rendering stored values.
- **Test:** `ui/tests/inspector-sections.test.mjs`: the Responsive section renders no `hide_on_tablet` checkbox for a block without a stored `responsive.tablet.hide`.

#### CL-M3: `layout.grid` columns are not responsive
- **Sev / status:** Medium · Open (C5/F-M3).
- **Evidence:** `GridRenderer.php:39-58` emits an unprefixed `sb-cols-N`. The CSS from `StudioStylesheet.php:450-452` applies at every width.
- **Now:** a 4-column grid stays 4 columns on a phone.
- **Expected:** a mobile default of 1 column, as the section columns and Query Loop already have.
- **Fix:** emit `sb-cols-1 sb-md-cols-N` for N > 2, or `sb-cols-1 sb-sm-cols-N`.
- **Test:** `tests/unit/StudioBuilderPhase4RendererTest.php`: render `layout.grid` with `columns=4` and assert the classes contain `sb-cols-1` and `sb-md-cols-4`.

#### CL-M1: `layout.section` and `layout.container` widths are hard-coded and ignore the `layout.*` tokens
- **Sev / status:** Medium · Open. Same issue as DT-M3.
- **Evidence:** `StudioStylesheet.php:167-169,177-179` (80/48rem and 75/48rem) versus the token-driven `.sb-w-*` at `:420-424`.
- **Now:** changing "Page widths" in Site settings resizes only document sections, not these blocks.
- **Expected:** all widths follow the tokens.
- **Fix:** write `max-width:var(--sb-layout-wide,80rem)` for boxed and constrained, and `var(--sb-layout-narrow,48rem)` for narrow and compact.
- **Test:** assert that `StudioStylesheet::css()` contains `.sb-layout-section--boxed{max-width:var(--sb-layout-wide`.

#### CL-M2: A "Full-width" `layout.section` block is capped by its parent section
- **Sev / status:** Medium · Open.
- **Evidence:**
  - The description says "Full-width layout section": `BlockRegistry.php:285`.
  - The parent inner is `sb-w-wide` with 1rem padding: `StudioStylesheet.php:53`, `DocumentRenderer.php:271-273`.
  - `--full` is only `width:100%`: `StudioStylesheet.php:168`.
- **Now:** a full-width block is at most 80rem wide, minus the inner padding.
- **Expected:** edge to edge, or a description that says otherwise.
- **Fix:** add a full-bleed rule to `--full`: `margin-inline:calc(50% - 50vw);width:100vw`. Or change the description.
- **Test:** manual. Put a `layout.section` with `content_width=full` in a default section, view the page at 1600px wide, and check that its background reaches both window edges.

#### CL-M7: Typography size breakpoint maps are accepted and then dropped
- **Sev / status:** Medium · Open. Same issue as W-F23(b); F-M7.
- **Evidence:** the validator accepts a `{base,sm,md,lg}` map at `DocumentValidator.php:1080-1096`. The renderer emits only a string: `DocumentRenderer.php:564-566`.
- **Now:** a stored size map produces no font size at all. It can only arrive through the API, MCP or import.
- **Expected:** render it, or reject it.
- **Fix:** reject the map in the validator with `invalid_style_value`, since device overrides already cover per-device size.
- **Test:** `tests/unit/StudioBuilderPhase2DocumentDomainTest.php`: `ValidatedDocument::from` with `typography.size={base:'1rem',md:'2rem'}` returns an error.

#### CL-M9: Custom header/footer partials get the default chrome's padding and border
- **Sev / status:** Medium · Open.
- **Evidence:**
  - Partial wrapper class: `StudioCompiler.php:330,334` (`sb-site-header sb-site-header--partial`).
  - The rules apply to `.sb-site-header`: `StudioStylesheet.php:55-57`.
- **Now:** every custom header has an extra 1rem padding and a bottom border.
- **Expected:** a partial renders exactly as designed.
- **Fix:** scope the rules to `.sb-site-header:not(.sb-site-header--partial)`, and the same for the footer.
- **Test:** assert that `StudioStylesheet::css()` contains `.sb-site-header:not(.sb-site-header--partial)`.

#### CL-M11: The same size names mean different sizes
- **Sev / status:** Medium · Open. Same issue as DT-M2.
- **Evidence:** padding `md` is 2rem and gap `md` is 1rem (`StudioStylesheet.php:32-33`), while the token `space.md` is 1.5rem (`ThemeResolver.php:54-56`).
- **Now:** "md" spacing has three values depending on which control set it.
- **Expected:** one token-backed scale.
- **Fix:** point `PADDING` and `GAP` md/sm/lg at `var(--sb-space-*, fallback)`, keeping today's values as fallbacks.
- **Test:** assert `.sb-pad-md{padding:var(--sb-space-md` in `StudioStylesheet::css()`.

#### CL-M12: `core.container` horizontal wraps; `layout.flex` row does not
- **Sev / status:** Medium · Open.
- **Evidence:** `StudioStylesheet.php:151` (`.sb-stack--horizontal{…flex-wrap:wrap}`) versus `:189` (`.sb-flex--nowrap`, the default).
- **Now:** two "row" containers behave differently on narrow screens.
- **Expected:** the same default, or wrap exposed on `core.container`.
- **Fix:** add a `wrap` prop to `core.container` with default `wrap`, so the existing behaviour stays and the option is visible.
- **Test:** `tests/unit/StudioBuilderPhase4RendererTest.php`: `core.container` with `wrap=nowrap` emits a nowrap class.

#### CL-M6: The canvas offers "drop inside" only for `layout.*` types
- **Sev / status:** Medium · Open (editor only).
- **Evidence:** `plugins/studio-builder/ui/src/core/canvas.mjs:352` (`type.startsWith('layout.')` or a `<section>` tag), versus Layers using `allows_children` (`outlineDrag.mjs:15`).
- **Now:** you cannot drop into `core.container`, `core.card`, `core.modal`, `core.form` or `core.query_loop` on the canvas. A leaf block with `tag: section` offers "inside", and the drop is refused later.
- **Expected:** the same container rule as Layers.
- **Fix:** pass the manifest's `allows_children` map into `attachCanvas` and use it in place of the prefix test.
- **Test:** a new `ui/tests/canvas-drop.test.mjs`. With a stub manifest where `core.card.allows_children=true`, the drop-position helper returns `inside` for a card at 50% height.

#### CL-M10: A selected absolute or sticky block moves in the canvas
- **Sev / status:** Medium · Open (editor vs frontend).
- **Evidence:** `plugins/studio-builder/ui/src/core/canvas.mjs:31-35` (`.sbx-selected{position: relative !important; …}`).
- **Now:** selecting a positioned block changes its layout in the editor only.
- **Expected:** selection never changes layout.
- **Fix:** drop `position: relative !important` and draw the selection ring through the existing `CanvasOverlay` layer.
- **Test:** manual. Set a block to Position = absolute with top 0 and right 0, select it, and confirm it does not move. Node: assert that `OVERLAY_CSS` contains no `position: relative !important`.

### 3B. Theme Builder persistence and conditions

#### TB-01: Draft display conditions and `template_type` went live without publishing
- **Sev / status:** High · **Fixed** on `fix/studio-theme-published-conditions`, commit `72fd527`.
- **Evidence:**
  - `src/Module/StudioBuilder/Theme/ThemeTemplateResolver.php:96` and `:237` now call `publishedSettings()` (`:298-313`). It reads the published revision's settings whenever the working draft differs.
  - Test: `tests/unit/StudioBuilderSprint6ThemeBuilderTest.php:410`.
- **Residual (Open, Low):**
  - When the host page's published settings carry no `category`, `resolveChrome` still falls back to the host page's draft `settings_json` for `category` and `tags`: `ThemeTemplateResolver.php:55-76`.
  - The context from `StudioCompiler.php:259-267` keeps string settings only, so `tags` always come from that draft row.
- **Fix for residual:** pass `tags` from the published document in `StudioCompiler::plan`, and drop the `settings_json` fallback.
- **Test for residual:**
  - Where: `StudioBuilderSprint6ThemeBuilderTest.php`.
  - Publish a page with no category, then save a draft with `category=A`.
  - Assert a header conditioned on `category=A` is not selected.

#### TI-02: Import `replace_draft`, `applyTemplate` and rollback changed live template selection
- **Sev / status:** High · **Fixed** by the same commit `72fd527`. The root cause was the same as TB-01.
- **Evidence:**
  - These paths still rewrite `pages.settings_json` through `createDraftRevision`: `StudioRevisionService.php:161-162` (draft) and `:384-385` (rollback).
  - The resolver no longer trusts that column when draft ≠ published (`ThemeTemplateResolver.php:298-313`).
- **Remaining gap:** no test covers the import, apply or rollback paths specifically.
- **Test:**
  - Where: `tests/integration/StudioBuilderPhase8PackageTest.php`.
  - Run `replace_draft` on a published header partial with different conditions.
  - Assert `resolveChrome` still returns the old winner.

#### TB-02: Template delete matched the wrong backing page and hard-deleted its revisions
- **Sev / status:** High · **Fixed** on `fix/studio-template-delete`, commit `885d23e`. Same issue as TI-03.
- **Evidence:**
  - `plugins/studio-builder/admin/templates.php:355-394` now does three things:
    - refuses unknown or system ids (`:357-361`);
    - keeps only candidates whose decoded `template_id` matches exactly or whose slug is `template-<key>` (`:375-382`);
    - calls `deleteTemplate` and `archivePage` (`:388-390`), so no revisions are deleted.
  - Test: `tests/integration/StudioTemplateDeleteTest.php`.
- **Test:** already present; no further work.

#### TB-03: `admin/templates.php` bypassed Studio permission levels
- **Sev / status:** High · **Fixed** on `fix/studio-admin-permissions`, commit `4aa8550`. Same issue as TI-01.
- **Evidence:**
  - Per-action permission: `templates.php:95-103` (create and delete need ADMIN; publish and unpublish need PUBLISH).
  - Unpublish is limited to Theme Builder pages, audited and invalidated: `:333-353`.
  - Test: `tests/integration/StudioAdminPermissionsTest.php:84,114`.
- **Residual:** unpublish is still a raw `UPDATE` (`:345`) rather than a service call. There is no `unpublishPage` in `StudioApplicationService`. This is tracked under TI-06.

#### ST-01: Studio Content page had no permission check beyond login
- **Sev / status:** High · **Fixed** by commit `4aa8550`.
- **Evidence:** `plugins/studio-builder/admin/content.php:29` (`Auth::requirePerm('studio-builder.edit')`). Test: `tests/integration/StudioAdminPermissionsTest.php:133-139`.

#### TI-04: `applyTemplate` copies the template's conditions and type onto the target page
- **Sev / status:** Medium · Open.
- **Evidence:** `src/Module/StudioBuilder/Service/StudioTemplateService.php:310-313` takes the template document whole and replaces only `document_type`.
- **Now:** applying a template saved from a header or template page gives the target page `settings.conditions` and `template_type`. Since `72fd527` this goes live on the next publish rather than at once, but the author is never told.
- **Expected:** the target page keeps its own `settings` (at least `conditions`, `template_type` and `category`) and its `seo`.
- **Fix:** before validation, replace `$rawDocument['settings']` and `['seo']` with the target page's current draft values.
- **Test:**
  - Where: `tests/integration/StudioBuilderPhase6TemplatesComponentsTest.php`.
  - Apply a template whose settings hold `template_type='search'` to a page.
  - Assert the new draft's `settings` has no `template_type`.

#### TB-09: Candidate queries apply `LIMIT` before filtering
- **Sev / status:** Medium · Open.
- **Evidence:**
  - `src/Module/StudioBuilder/Repository/PageRepository.php:56-65` orders by title and limits, then removes archived rows.
  - The resolver asks for 100 header/footer rows (`ThemeTemplateResolver.php:80`) and 50 system plus 100 page rows (`:223-224`), then drops drafts and non-role rows.
- **Now:** on a site with more than 100 pages, a search or archive template titled late in the alphabet is silently ignored.
- **Expected:** every published template is considered.
- **Fix:** add a repository method `publishedTemplateCandidates(array $pageTypes)` that filters `status='published'` in SQL and has no small limit. Optionally filter on a JSON predicate for `template_type`/`conditions`.
- **Test:**
  - Where: `StudioBuilderSprint6ThemeBuilderTest.php`.
  - Seed 120 published `page` rows titled "A…" plus a page-type search template titled "Z search".
  - Assert `resolveSearch('x')` returns it.

#### TB-06: Several condition types never match, or match the wrong page
- **Sev / status:** Medium · Open.
- **Evidence:**
  - No producer sets `content_type`, `author_id` or `is_front_page`: a grep of `src/Module/StudioBuilder` finds them only in the matcher, the schema and the query loop.
  - `TemplateConditionMatcher.php:98-102`: `front_page` is true for slug `''`, `home` or `index`. That makes every search and 404 context (which has no slug) match `front_page` at score 300, while a homepage under another slug never matches.
  - `:150-174`: `singular` falls back to `page_type`, then to a slug equal to the type name, at score 300.
  - Author rules can never match.
- **Now:** the admin options "All Blog Posts / All Portfolio Items" behave like "the page whose slug is `post` / `portfolio`".
- **Expected:** conditions match the real page.
- **Fix:**
  - Build the evaluation context once per request: `is_front_page` from `route_mode=homepage`, and `author_id` from `created_by`.
  - Treat an empty slug as "not front page".
  - Remove the slug fallback in `singular`.
  - Honour `archive_type` when `term` is set.
- **Test:** a data provider in `StudioBuilderSprint6ThemeBuilderTest.php` over `TemplateConditionMatcher::score` with these cases:
  - homepage slug `welcome` with `is_front_page=true` matches `front_page`;
  - `{is_search:true}` does not match `front_page`;
  - `singular=portfolio` does not match slug `portfolio`;
  - `term=news` with `archive_type=category` does not match a tag archive.

#### TB-08: "Single" templates are never applied, yet become public, sitemap-listed pages
- **Sev / status:** Medium · Open.
- **Evidence:**
  - `resolveSingle` (`ThemeTemplateResolver.php:156`) has no caller outside its definition.
  - The admin screen creates single templates as `page_type='page'`, slug `template-<key>` (`templates.php:50-57`), with `seo_json={"noindex":true}` (`:273`).
  - Publish rewrites `seo_json` from the document (`StudioRevisionService.php:277`).
  - The sitemap skips only `seo.robots` noindex (`StudioSitemapService.php:119`).
- **Now:** a published single template is reachable at `/template-<key>` and listed in `sitemap.xml`. It never wraps any content.
- **Expected:** either the template applies to single content, or the type is not offered.
- **Fix (small):**
  - Remove `single` from the admin `$typeConfigs`.
  - For existing rows, set `seo.robots='noindex,nofollow'` in the starter document.
  - Wiring `resolveSingle` into the page render is a larger feature.
- **Test:**
  - Where: `tests/integration/StudioBuilderThemeTemplatesAdminTest.php`.
  - Publish a single template.
  - Assert `StudioSitemapService` output does not contain `template-`.

#### TB-04: Custom 404 templates are never served
- **Sev / status:** Medium · Open.
- **Evidence:**
  - `src/Kernel/Http/PublicRouter.php:87-96` renders `renderNotFound()` whenever `public_fallback` returns false.
  - `StudioPublicRuntime::handleNotFound()` (`src/Module/StudioBuilder/Runtime/StudioPublicRuntime.php:104-115`) is called only from `tests/integration/StudioBuilderSprint6AcceptanceTest.php:316,334`.
- **Now:** the admin screen offers a "404 Error" template that never appears.
- **Expected:** unknown paths render the published 404 template with status 404.
- **Fix:** in `StudioBuilder::servePublicPath` (`plugins/studio-builder/StudioBuilder.php:146-159`), when `handlePath` returns null, try `handleNotFound()` and send it. It already sets status 404.
- **Test:**
  - Where: `tests/integration/StudioBuilderSprint6AcceptanceTest.php`.
  - Call `StudioBuilder::servePublicPath(false, 'no-such-page')` with a published 404 template.
  - Assert it returns true, with status 404 and the template body.

#### TB-05: The live search template never receives the query
- **Sev / status:** Medium · Open.
- **Evidence:**
  - `public.php:30-35` strips `?…` before `PublicRouter::dispatch`.
  - `StudioPublicRuntime.php:73-89` parses `q`/`s` only from the path string.
  - `StudioBuilder.php:158` passes only the path.
- **Now:** `/search?q=studio` shows "Search" and an empty box.
- **Expected:** "Search Results: studio".
- **Fix:** in `servePublicPath`, when the path is `search`, append `'?q=' . rawurlencode(mb_substr((string) ($_GET['q'] ?? ''), 0, 191))` to the path, or add a `$query` parameter to `handlePath`.
- **Test:** an integration test with `$_GET['q']='studio'` calling `servePublicPath(false,'search')`; assert the body contains "studio". The existing test (`tests/unit/StudioBuilderSprint6ThemeBuilderTest.php:268`) only calls `resolveSearch` directly.

#### TB-07: The search box is static and would bake one visitor's query into the shared artifact
- **Sev / status:** Medium (latent until TB-05 is fixed) · Open.
- **Evidence:** `src/Module/StudioBuilder/Render/Block/CoreRenderers/SearchBoxRenderer.php:21-24` (`isDynamic()` returns false) while `:30,34` read `search_query` from the request context.
- **Now:** harmless today, because the query is always empty (TB-05). After TB-05 is fixed, the first query after artifact expiry would show for every visitor.
- **Expected:** a per-request value.
- **Fix:** make `isDynamic()` return true. Fix this together with TB-05.
- **Test:** `tests/unit/StudioBuilderPhase4RendererTest.php`: assert `(new SearchBoxRenderer())->isDynamic() === true`.

#### TB-10: Ties between templates are decided by title
- **Sev / status:** Low · Open.
- **Evidence:** strict `>` at `ThemeTemplateResolver.php:108,262`, over `ORDER BY title, id` (`PageRepository.php:59-60`).
- **Now:** of two "entire site" headers, the one first alphabetically wins, and renaming one can flip the live header.
- **Expected:** a stable, explicit tie-break.
- **Fix:** break ties on the newest `published_at` (or the highest id), and show a "conflicts with …" note in the conditions dialog.
- **Test:** two published headers with equal conditions. Assert the winner is unchanged after swapping their titles.

#### TB-12: A misspelled exclusion becomes an inclusion
- **Sev / status:** Low · Open.
- **Evidence:**
  - The validator accepts any `action`/`type` matching `^[a-z_0-9]{1,40}$`: `DocumentValidator.php:343-347`.
  - The matcher treats only `exclude` as exclusion (`TemplateConditionMatcher.php:59-60`), and a non-include/exclude `type` becomes the scope (`:85-91`).
- **Now:** `action:"excludes"` includes the page. `type:"categroy"` silently never matches.
- **Expected:** validation errors.
- **Fix:** require `action` to be in {include, exclude}. Check a scope-like `type` against `ALLOWED_CONDITION_SCOPES`.
- **Test:** `tests/unit/StudioBuilderPhase2DocumentDomainTest.php`: `{action:'excludes'}` and `{type:'categroy'}` both fail validation.

#### TB-11: Archive and search templates are resolved before the entitlement check
- **Sev / status:** Low · Open.
- **Evidence:** `StudioPublicRuntime.php:117-126,135-144` resolve first. `serveTemplate` checks entitlement at `:286-294`.
- **Now:** unentitled tenants still run the candidate queries, and repository exceptions are not caught.
- **Expected:** check entitlement first, inside try/catch, as `serve()` does.
- **Fix:** move the `isEntitled` check into `handleArchive`, `handleSearch` and `handleNotFound` before resolution, and wrap the call in try/catch.
- **Test:** a stub tenant with no entitlement and a pages repository that throws. Assert `handleArchive('category','x')` returns null without throwing.

#### TB-13: The archive route accepts any term, uncapped
- **Sev / status:** Low · Open.
- **Evidence:** `StudioPublicRuntime.php:78-79` (`urldecode($m[2])`, no cap). Preview caps at 191 (`TemplatePreviewContext.php:45-83`).
- **Now:** `/category/<anything>` returns 200 with that text in the title (escaped).
- **Expected:** capped, control characters stripped.
- **Fix:** run the term through the same cleaner as `TemplatePreviewContext::text`.
- **Test:** `handleArchive('category', str_repeat('a', 300))` gives a title of at most 191 characters.

#### TB-14: Preview ignores conditions and the host page's hidden chrome
- **Sev / status:** Low · Open.
- **Evidence:** `StudioCompiler.php:279-285` swaps in `chrome_override` after checking only the page type, not `header_mode='hidden'`. Preview never evaluates conditions (`StudioApplicationService::renderPreview`).
- **Now:** "Preview as page" can show a header the live page hides, and a template the live site would never select.
- **Expected:** preview matches the live behaviour.
- **Fix:** skip the override when the host's `header_mode`/`footer_mode` is `hidden`.
- **Test:** preview a header on a host with `header_mode=hidden`; assert no `<header` in the output.

#### TI-05: `saveTemplate` silently overwrites an existing key
- **Sev / status:** Low · Open.
- **Evidence:** `StudioTemplateService.php:97-119` (update when the key exists; no version check).
- **Now:** a second admin saving under the same key replaces the first admin's template.
- **Expected:** the overwrite is refused unless it is explicit.
- **Fix:** require an `overwrite=true` flag, or `expected_updated_at`, when the key exists.
- **Test:** two `saveTemplate` calls with the same key; the second without the flag raises `template_exists`.

#### TI-06: The admin create path still skips canonical validation
- **Sev / status:** Low · Open (the permission part was fixed in `4aa8550`).
- **Evidence:**
  - Raw inserts; uuids from `mt_rand` (`templates.php:226-232`).
  - A 16-bit key suffix (`:123`).
  - Exception text shown to the user (`:306,312,330,352,392`).
  - The name is not validated.
- **Now:** a DB error message can reach the flash, and a long or odd name is stored unchecked.
- **Expected:** behave like `saveTemplate`.
- **Fix:** use `random_bytes`-based uuids, show generic error flashes, and validate the name (no `<>`, at most 191 characters).
- **Test:** `tests/integration/StudioAdminPermissionsTest.php`: an admin POSTs `create_template` with a 300-character name; assert refusal and a flash without SQL text.

#### TI-07: There is no upgrade path for older packages or block versions
- **Sev / status:** Low · Open.
- **Evidence:**
  - `StudioPackageFormat.php:105-106` (exactly "1.0").
  - `DocumentValidator.php:107-113` (schema exactly "1.0") and `:846-850` (`invalid_block_version`).
- **Now:** any block version bump makes earlier exports unimportable.
- **Expected:** a migration step, or at least a precise report.
- **Fix:** add a per-block `upgrade(array $props, int $from)` hook, called before validation.
- **Test:** a fixture package with block version N-1 imports after a bump, or is refused with an `invalid_block_version` report that names the block.

### 3C. Other findings, by severity

#### W-F1: Stored XSS through the `theme.post_content` fallback
- **Sev / status:** High · **Fixed** on `fix/studio-post-content-xss`, commit `c19c9d5`.
- **Evidence:**
  - `src/Module/StudioBuilder/Render/Block/CoreRenderers/PostContentRenderer.php:39`: markup now goes through `RichTextSanitizer::sanitize`; plain text is escaped.
  - Test: `tests/unit/StudioBuilderSprint6ThemeBuilderTest.php:373`.

#### W-F2: Border styles "Solid", "Dotted" and "Double" have no CSS
- **Sev / status:** High · Open.
- **Evidence:**
  - Options: `plugins/studio-builder/ui/src/components/inspectors/StyleControls.jsx:480-483`.
  - Allowed values: `CanonicalDocumentSchema.php:297`.
  - The class is emitted at `DocumentRenderer.php:426`.
  - The base CSS has only `.sb-border`, `-2`, `-4`, `-none` and `-dashed`: `StudioStylesheet.php:243`.
  - The client mirror does the same: `liveStyle.mjs:245`.
- **Now:** a border with width, colour and style Solid shows no border.
- **Expected:** every offered style renders.
- **Fix:** add `.sb-border-solid{border-style:solid}.sb-border-dotted{border-style:dotted}.sb-border-double{border-style:double}` to line 243.
- **Test:** assert that `StudioStylesheet::css()` contains `.sb-border-solid{` and `.sb-border-double{`.

#### W-F3: Query Loop pagination is a hard-coded fake
- **Sev / status:** High · Open.
- **Evidence:** `QueryLoopRenderer.php:157-161` always prints Previous (disabled), page 1, and links to `?page=2`. The real page is read from raw `$_GET['page']` (`:50`).
- **Now:** the pager ignores the page count and the current page. It is on by default.
- **Expected:** a real pager, or none.
- **Fix:**
  - Short term: default `enable_pagination` to false, and render no pager when the result count is at most `per_page`.
  - Then build links from the provider's total and the current page.
- **Test:** `tests/unit/StudioBuilderSprint5DynamicQueryLoopTest.php`: 3 rows with `per_page=6` gives output without `sb-pagination`.

#### DT-01: The mobile Theme sheet can never save
- **Sev / status:** High · Open.
- **Evidence:**
  - The sheet edits `color.primary|secondary|background|surface|border|text`: `plugins/studio-builder/ui/src/components/ThemeBottomSheet.jsx:14-19`.
  - It sends them on save: `:63-71`.
  - The server rejects unknown refs and throws on any error: `src/Module/StudioBuilder/Service/StudioThemeService.php:144-147,159-161`. The allowed list is `DEFAULT_TOKENS` (`:48-50`).
  - Site name is local state defaulting to "Northstar" (`:29`). Logo calls `alert('Media Library')` (`:123`). Colour mode is unused (`:28,168-178`).
  - The sheet is reachable from the mobile dock: `MobileDock.jsx:25`, `StudioShell.jsx:906`.
- **Now:** every save fails with `unknown_token`.
- **Expected:** the mobile Theme tab edits real tokens.
- **Fix:** render `SiteSettingsForm` inside the sheet, as `ThemeDialog` does, or remove the dock tab.
- **Test:** `ui/tests/site-settings.test.mjs` (or a new `theme-sheet.test.mjs`): every ref the sheet can submit is in the manifest token list.

#### DT-02: The branding layer is mostly dead
- **Sev / status:** High · Open.
- **Evidence:**
  - Studio reads `brand_text_color`, `brand_canvas_color`, `brand_heading_font` and `brand_body_font` (`src/Services/Content/TenantBranding.php:35-41`; mapped at `ThemeResolver.php:70-76`). A repo grep finds no writer for them.
  - The Branding form writes `brand_font_family` and `brand_border_radius` (`admin/settings.php:523,529`), but only `includes/ui_components.php` reads them.
  - The editor says branding "applies first": `messages.mjs:1148`.
- **Now:** only the accent colour flows from Branding into Studio.
- **Expected:** the Branding font and radius reach Studio, or the note stops promising it.
- **Fix:** map `brand_font_family` to `font.body` and `font.heading`, and `brand_border_radius` to `radius.md`, through `BRANDING_TOKEN_MAP` and `TenantBranding::KEYS`. Drop the four unwritten keys.
- **Test:** `tests/unit/StudioBuilderThemeTest.php` (new). With `brand_font_family` set, `ThemeResolver::resolve('default')` returns that family for `font.body`.

#### W-F5: Off-canvas "Drawer Position" top and bottom are unstyled
- **Sev / status:** Medium · Open.
- **Evidence:** allowed values at `BlockRegistry.php:541`; CSS only for `--right` and `--left` at `StudioStylesheet.php:393-394`.
- **Now:** top and bottom drawers render as a full-height panel with no anchor.
- **Expected:** top and bottom drawers.
- **Fix:** add `.sb-offcanvas--top .sb-offcanvas__panel{top:0;left:0;right:0;height:auto;max-height:80vh}` and the bottom equivalent.
- **Test:** assert `.sb-offcanvas--top` exists in `StudioStylesheet::css()`. Manual: open a top drawer.

#### W-F6: Tabs "Tab Position: bottom" does nothing
- **Sev / status:** Medium · Open.
- **Evidence:** `BlockRegistry.php:673`; class at `TabsRenderer.php:93`; no `.sb-tabs-block--` rule in `StudioStylesheet.php`.
- **Now:** tabs always sit on top.
- **Expected:** a bottom tab list.
- **Fix:** add `.sb-tabs-block--bottom{display:flex;flex-direction:column-reverse}`, or remove the option.
- **Test:** assert that `StudioStylesheet::css()` contains `.sb-tabs-block--bottom`.

#### W-F7: The "scroll", "click" and "load" interaction triggers do nothing
- **Sev / status:** Medium · Open.
- **Evidence:**
  - Offered at `plugins/studio-builder/ui/src/core/motion.mjs:33-36`.
  - No `sb-interaction-scroll` rule or runtime handler exists (`StudioStylesheet.php:291-315`; `studio-runtime.js`).
  - Click and load get only a transition, and the source comment says so (`StudioStylesheet.php:297-300`).
- **Now:** choosing these triggers has no effect.
- **Expected:** only working triggers are offered.
- **Fix:** hide `scroll`, `click` and `load` in `motion.mjs` until the runtime supports them.
- **Test:** `ui/tests/option-values.test.mjs`: every trigger in the Motion options has a CSS rule or a runtime handler.

#### W-F8: Block field labels and option labels are not translated
- **Sev / status:** Medium · Open.
- **Evidence:**
  - `translateBlockCopy` translates only `title` and `description`: `StudioApplicationService.php:1334-1349`.
  - `FieldControl.jsx` prints `field.label` raw.
  - Motion labels are English literals: `motion.mjs:33-36`.
- **Now:** the Inspector is English in every locale.
- **Expected:** localised labels.
- **Fix:** extend `translateBlockCopy` to `field_schema[].label`, using the key `studio_field_{type}_{key}` with the English label as default. Wrap the Motion labels in `t()`.
- **Test:** `ui/tests/i18n-parity.test.mjs`: the Motion option labels resolve through `t()`. PHP: a manifest built with a stub `__` returns translated field labels.

#### W-F9: The runtime replaces translated carousel labels with English
- **Sev / status:** Medium · Open.
- **Evidence:**
  - The server uses `Html::t` (`CarouselRenderer.php:90-122`).
  - The runtime clears the dots and writes `'Go to slide ' + (i + 1)`: `plugins/studio-builder/assets/public/studio-runtime.js:867-874`.
  - It also hard-codes `aria-roledescription` at `:797,800`.
- **Now:** localised carousels become English after JavaScript runs.
- **Expected:** server labels kept.
- **Fix:** reuse the existing dot buttons (or copy the server `aria-label` prefix from a `data-` attribute) instead of rebuilding them.
- **Test:** manual. On a non-English site, inspect a dot's `aria-label` after load.

#### W-F10: Hard-coded English strings in public output
- **Sev / status:** Medium · Open.
- **Evidence (sample, each line re-opened):**
  - `ArchiveTitleRenderer.php:43-51` ("Search Results:", "Category:", "Archive")
  - `PostMetaRenderer.php:39` ("By ")
  - `ModalRenderer.php:51,55` and `OffcanvasRenderer.php:51,55` (`aria-label="Close"`)
  - `VideoRenderer.php:43,66`
  - `QueryLoopRenderer.php:157`
- **Now:** these appear in English on every site.
- **Expected:** `Html::t()` keys.
- **Fix:** wrap each string in `Html::t('studio_…', '<English>')`.
- **Test:** `tests/unit/StudioBuilderPhase9LocaleTest.php`: render each block under a stub locale and assert the stub strings appear.

#### W-F11: "Fallback" props override live post data
- **Sev / status:** Medium · Open.
- **Evidence:** `PostTitleRenderer.php:43-46` and `PostContentRenderer.php:29-32` use the prop first and the context value only when the prop is empty.
- **Now:** a filled "Fallback Title" replaces every real title.
- **Expected:** context first, fallback when the context is empty.
- **Fix:** swap the order: context attribute, then the prop, then the default.
- **Test:** `StudioBuilderSprint6ThemeBuilderTest.php`: a context `title='Real'` with prop `text='Fallback'` renders "Real".

#### W-F14: `payment.button` is cached as static
- **Sev / status:** Medium · Open.
- **Evidence:**
  - `isDynamic()` returns false: `src/Module/StudioBuilder/Render/Block/ModuleRenderers/PaymentButtonRenderer.php:27-30`.
  - Output depends on `stripeReady()`: `:41`.
  - The invalidator has no Stripe dependency (`StudioCompilationInvalidator.php`); artifacts live 3600 s (`StudioCompiler.php:60`).
- **Now:** a page compiled before Stripe setup shows no button for up to an hour.
- **Expected:** the button appears once Stripe is ready.
- **Fix:** return true from `isDynamic()`.
- **Test:** `tests/unit/StudioBuilderPhase4RendererTest.php`: assert `PaymentButtonRenderer::isDynamic() === true`.

#### W-F15: Search box has no accessible name and a fixed `/search` action
- **Sev / status:** Medium · Open.
- **Evidence:** `SearchBoxRenderer.php:32` (`action="/search"`) and `:34` (placeholder only).
- **Now:** the input has no label for screen readers, and installs under a sub-path post to the wrong URL.
- **Expected:** an `aria-label`, and a site-relative action.
- **Fix:** add `aria-label="' . Html::e($placeholder ?: Html::t('studio_search','Search')) . '"`, and build the action from `SiteContext::absoluteUrl('/search')`.
- **Test:** `tests/unit/StudioBuilderPhase4RendererTest.php`: the output contains `aria-label=`.

#### W-F16: Carousel accessibility gaps
- **Sev / status:** Medium · Open.
- **Evidence:**
  - `role="tablist"` on plain buttons in server markup: `CarouselRenderer.php:104`.
  - Autoplay pauses only when `prefersHover()`: `studio-runtime.js:890`.
  - Autoplay requires loop: `:883`.
- **Now:** touch users cannot stop autoplay (WCAG 2.2.2), and the server markup is invalid ARIA without JavaScript.
- **Expected:** a pause control, and valid roles.
- **Fix:** render the dots as a plain group (`role="group"`), and add a visible pause button when `autoplay_ms > 0`.
- **Test:** manual. On a phone, with autoplay on, confirm a pause button exists and stops the slides.

#### W-F17: `core.form_field` output is not deterministic
- **Sev / status:** Medium · Open (the register lists it under W-Low).
- **Evidence:** `FormFieldRenderer.php:41` (`random_bytes` in the id).
- **Now:** every render differs, which defeats "same markup, no repaint" and content-addressed compiles.
- **Expected:** a stable id.
- **Fix:** derive the id from the block id (`'sb_f_' . $name . '_' . substr(hash('sha256', $blockId), 0, 8)`), and claim it through `RenderCollector::claimId`.
- **Test:** render the same document twice and assert identical HTML.

#### W-F18: `portfolio.rakib_showcase` has unchecked link inputs and no tests
- **Sev / status:** Medium · Open.
- **Evidence:**
  - `domain_url` and `email` are `string`, not `url`, and have no `max_length`: `plugins/studio-builder/StudioBuilderPortfolioWidgets.php:51-52`.
  - They go into `href`/`mailto:` after `htmlspecialchars` only: `:84-85,124,129`.
  - A `<link rel=stylesheet>` is emitted inside the block body: `:159`.
  - Both defaults are the owner's own domain and address.
- **Now:** any scheme the generic filter misses can reach `href`, and other tenants inherit the owner's contact details by default.
- **Expected:** URL-typed fields with neutral defaults.
- **Fix:** change `domain_url` to type `url`, validate `email` with a pattern, set the defaults to `''`, and add `max_length`.
- **Test:** `tests/unit/StudioBuilderSprint8WidgetSdkTest.php`: validation rejects `domain_url='javascript:x'`, and the defaults are empty.

#### W-F19: Theme single-post blocks get no post data on the public path
- **Sev / status:** Medium · Open (partly inferred; see section 5).
- **Evidence:**
  - `PostContentRenderer.php:31` falls back to Lorem ipsum.
  - The only context producers are the 404, archive and search handlers (`StudioPublicRuntime.php:104-149`) and the preview (`TemplatePreviewContext.php:70`, which sets `post_title` but not `content`).
  - `resolveSingle` has no caller (TB-08).
- **Now:** post blocks show fallback or placeholder text on live pages.
- **Expected:** real post data where a single template applies.
- **Fix:** depends on TB-08. Until then, hide `theme.post_*` in the Add panel for non-template pages.
- **Test:** an integration test renders a published page containing `theme.post_content` and asserts no "Lorem ipsum" appears.

#### W-F20: The Query Loop fallback depends on a global and on raw `$_GET`
- **Sev / status:** Medium · Open.
- **Evidence:** `QueryLoopRenderer.php:50` (`$_GET['page']`) and `:53` (`$GLOBALS['SLATE_TENANT_OVERRIDE']`).
- **Now:** without a binding, the loop shows the empty message unless that global is set.
- **Expected:** use the scoped tenant context.
- **Fix:** inject `TenantContext` through the render context, and read the page from `RenderContext` attributes.
- **Test:** `StudioBuilderSprint5DynamicQueryLoopTest.php`: with no global set and seeded posts, the loop renders the posts.

#### W-F28: `portfolio.footer` hard-codes social and contact links
- **Sev / status:** Medium · Open.
- **Evidence:** `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:286-289` (generic GitHub, LinkedIn and X links, and `mailto:contact@<owner-domain>`).
- **Now:** every site using the widget shows the owner's contact address.
- **Expected:** editable links, empty by default.
- **Fix:** add a `links` repeater (label and url) and an `email` field, both empty by default; render only non-empty entries.
- **Test:** render `portfolio.footer` with default props and assert the output has no `mailto:`.

#### DT-M1: Radius and shadow presets ignore the tokens
- **Sev / status:** Medium · Open.
- **Evidence:** fixed values at `StudioStylesheet.php:241-242`; tokens at `ThemeResolver.php:48-53`. `radius-sm`, `radius-full` and `shadow-sm` are never referenced by the base CSS.
- **Now:** editing "Medium corner" in Site settings does not change blocks set to the Medium preset.
- **Expected:** presets follow the tokens.
- **Fix:** `.sb-radius-md{border-radius:var(--sb-radius-md,.5rem)}`, and likewise for sm, lg, full and the shadows.
- **Test:** assert `.sb-radius-md{border-radius:var(--sb-radius-md` in `StudioStylesheet::css()`.

#### DT-M4: Primary button contrast is not derived from the accent
- **Sev / status:** Medium · Open.
- **Evidence:** `StudioStylesheet.php:67` (white text on `--sb-surface-accent`); the admin derivation is `includes/brand_tokens.php:36-82`.
- **Now:** a light brand accent gives unreadable primary buttons.
- **Expected:** readable text, as the admin already gets.
- **Fix:** when the branding accent is applied, set `text.inverse` from `slate_brand_accent_tokens()`'s on-accent value.
- **Test:** `ThemeResolver` with accent `#ffeb3b` gives a `text.inverse` that is not `#ffffff`.

#### DT-M5: Two CSS variables are used but never defined
- **Sev / status:** Medium · Open.
- **Evidence:** `--sb-text-secondary` and `--sb-text-default` at `StudioStylesheet.php:258,266`; neither is in `DEFAULT_TOKENS` (`ThemeResolver.php:40-67`).
- **Now:** post excerpt and post content colours ignore the site text tokens.
- **Expected:** they follow `text.muted` and `text.primary`.
- **Fix:** replace them with `--sb-text-muted` and `--sb-text-primary`.
- **Test:** assert that `StudioStylesheet::css()` does not contain `--sb-text-secondary`.

#### DT-M6: Saving Branding untouched stores `#111111` and overrides Studio's accent
- **Sev / status:** Medium · Open.
- **Evidence:**
  - Form default: `admin/settings.php:911-912`.
  - Saved as-is: `:496-500`.
  - The admin treats `#111111` as unset (`includes/ui_components.php:578`); Studio does not (`ThemeResolver.php:94-101`).
- **Now:** Studio accents turn near-black after an unrelated Branding save.
- **Expected:** the same "unset" sentinel on both sides.
- **Fix:** skip `#111111` in `TenantBranding::resolve()` for `accent`, or store `''` for the default.
- **Test:** with `brand_accent_color='#111111'`, `ThemeResolver` returns the default `#1f2937` for `color.accent`.

#### DT-M7: Several tokens have no default consumer
- **Sev / status:** Medium · Open.
- **Evidence:** `--sb-surface-muted`, `-surface-inverse`, `-text-accent`, `-radius-sm`, `-radius-full`, `-shadow-sm` and `-space-*` appear 0 times in `StudioStylesheet.php`.
- **Now:** editing them in Site settings changes nothing unless a block picked that token.
- **Expected:** either they are used, or the UI says so.
- **Fix:** label these rows "used only by blocks that choose this token" in `SiteSettings.jsx`. DT-M1 and CL-M11 give most of them a consumer.
- **Test:** `ui/tests/site-settings.test.mjs`: these rows render the hint.

#### W-F23: Accepted style values that never render
- **Sev / status:** Low · Open.
- **Evidence:**
  - The `style.spacing` key is allowed (`CanonicalDocumentSchema.php:281`) but has no emitter.
  - `transform:"none"` becomes `sb-none`, but the CSS defines `sb-normal-case` (`StudioStylesheet.php:238`).
  - `responsive.desktop.style` is accepted, but only tablet and mobile are emitted (`StyleSurface.php:911`).
- **Now:** these values are stored and ignored.
- **Expected:** rejected or rendered.
- **Fix:** reject `style.spacing` and `responsive.desktop.style` in the validator, and map `none` to `sb-normal-case`.
- **Test:** `StudioBuilderPhase2DocumentDomainTest.php`: `style.spacing` fails validation.

#### W-F32: `WidgetRegistry::withCoreWidgets()` is a drifted duplicate
- **Sev / status:** Low · Open.
- **Evidence:** `src/Module/StudioBuilder/Registry/WidgetRegistry.php:137` onward; only `tests/unit/StudioBuilderSprint1LayoutAndWidgetsTest.php:66-67` uses it.
- **Now:** a second, outdated definition of seven blocks.
- **Expected:** one registry.
- **Fix:** delete `withCoreWidgets()` and point the test at `BlockRegistry`.
- **Test:** the existing Sprint1 test passes against `BlockRegistry`.

## 4. Recommended order of work

1. **CL-03 and W-F2.** Each is a one-line CSS fix (media-scope the align classes; add the solid, dotted and double border rules), plus a CSS-string unit test.
2. **CL-02.** Make the prop control read-only when `style.layout` owns the value, so the Inspector stops showing a value that does not apply.
3. **CL-01.** Give `settings.container_width` real meaning as the default section width, or remove the control and fix the hint.
4. **CL-04, then CL-M4.** Move dimensions and literal colours from inline styles into the scoped rule. This one change unlocks per-device sizes and working hover states. Update the live-style fixture.
5. **TB-09.** Query published template candidates in SQL without the small `LIMIT`, so templates stop disappearing on larger sites.
6. **TI-04 and the TB-01 residual.** Keep the target page's own settings in `applyTemplate`, and drop the draft `settings_json` fallback in `resolveChrome`. Add the import/apply/rollback regression test for TI-02.
7. **TB-06.** Build one evaluation context (front page, author, content type) and remove the slug fallbacks.
8. **TB-05 together with TB-07.** Pass the search query through, and make the search box dynamic in the same change.
9. **TB-04 and TB-08.** Wire the 404 template into the fallback. Stop offering "Single" templates, or keep them noindex.
10. **DT-01, then W-F3.** Replace the mobile Theme sheet with the working Site settings form. Default the fake pager off.

## 5. Not yet audited

- **System-plugin widget render paths.** The data providers behind `forms.*`, `booking.*`, `membership.plans` and `payment.*`, their entitlement-off output, and how `core.form` submissions are handled. Only schemas and escaping were reviewed.
- **Performance.** Compile cost, artifact size, per-request template resolution (two candidate queries per public request, TB-16), runtime JS size, and CSS growth from content-addressed rules. Nothing was measured.
- **Accessibility as a whole.** No keyboard, screen-reader or contrast pass beyond the widget-level items above.
- **Browser behaviour.** Nothing was rendered, so every cascade conclusion (CL-02, CL-03, CL-M4, CL-M8) is from source. The manual steps above are the way to confirm them.
- **AI/MCP writers.** Whether the MCP tools write `responsive.*.align`, size maps or binding mappings (CL-03, CL-M7). The adapter was not read in full.
- **Stored data.** Whether live tenants hold any of the affected shapes. No database was opened.
- **`HtmlStructureMapper` / HTML import.** How external widths, flex and grid map into these fields.
- **Lower-priority items not repeated here.** The source reports hold further Low and Info items that I did not re-verify for this report and that remain in those reports:
  - containers report: F-L1 to F-L15, F-I1 to F-I5
  - design-tokens report: T-L1 to T-L6, T-I1 to T-I4
  - widgets report: F21, F22, F24 to F27, F29 to F31, F33 to F43
  - Theme Builder report: TB-15, TB-16
  - templates report: TI-08 to TI-10

## 6. Could not confirm

- **W-F19, only partly confirmed.** I confirmed there is no producer of `content`/`post_content` inside `src/Module/StudioBuilder` and `plugins/studio-builder`. I did not rule out a producer elsewhere in the platform.
- **TB-09's practical impact.** Which templates get cut depends on the database collation ordering of `"[Theme] …"` titles. The truncation itself is confirmed.
- **The containers report's citation `assets/portfolio/portfolio.css:152-190`.** That file is not where the `.sb-main--*` rules are. The rules are in `plugins/studio-builder/assets/portfolio_style.css:151-189`, and I cite that instead.
- **No claim was dropped outright.** Every kept finding's main evidence was re-opened and confirmed.

### Evidence corrections (line drift against the source reports)

- **Page width CSS:** `assets/portfolio/portfolio.css:152-190` → `plugins/studio-builder/assets/portfolio_style.css:151-189`.
- **`StyleSurface` device-overridable fields:** 415-427 → 914-923.
- **Device `@media` rules:** 456-476 → 955-976.
- **State rules:** 840-883 → 839 (`stateDeclarations`) and 891-903 (`stateRules`).
- **`TabsRenderer` bottom class:** 56,93 → 93.
- **`StudioBuilderPortfolioWidgets` stylesheet link:** 133 → 159.
- **Canvas overlay selected rule:** 29-33 → 31-35.
- **`StudioThemeService` rejection:** 140-147 → 144-147 plus the throw at 159-161.
- **`ThemeTemplateResolver` tie comparisons:** 116, 278 → 108, 262.
- **Candidate queries:** 229-233 → 223-224.
- **`admin/templates.php` after the fixes:**
  - permission gate: 23 → 24 and 95-103
  - unpublish: 320-329 → 333-353
  - delete: 330-347 → 355-394
  - uuid generation: 213-219 → 226-232
  - error echo: → 306, 312, 330, 352, 392
- **`TenantBranding` keys:** 36-42 → 35-41.
- **`PublicRouter` fallback:** 92-96 → 87-96.
