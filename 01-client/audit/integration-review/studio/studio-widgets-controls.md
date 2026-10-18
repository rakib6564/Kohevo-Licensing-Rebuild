# Kohevo Studio Builder: widgets, blocks and controls audit

Source-driven and read-only. Tree: `wt-final/01-client` (git worktree of the combined release tree). Every path below is relative to `01-client/`. Nothing in the repository was edited, the app was not run, and no database was touched. The only code executed was a set of read-only PHP scripts kept in the scratchpad (`r11/tools/`). They load `src/autoload.php` with `SLATE_TESTING` defined and dump the registries (`ModuleBlockDefinitions::studioRegistry()`, `BlockRendererRegistry::withStudioRenderers()`, `WidgetSdk`) and `StudioStylesheet::css()`.

Companion CSV: `r11/studio-widget-controls.csv`, 333 rows. It holds 272 content-control rows over all 60 types, 46 rows for the shared style/advanced controls (type_id `*all*`), and 15 rows for renderer inputs that have no control.

## 1. Method and counts

**How the registrations were found:**

1. `grep -rn -A2 "function type()" src plugins --include='*.php'` and collect the `return '<ns>.<name>'` values. This gives 47 literal ids: 40 in `Render/Block/CoreRenderers/` and 7 in `Render/Block/ModuleRenderers/`. Four more `type()` methods return a property and are generic (`BlockRenderScope`, `DeclarativeBlockDefinition`, `DeclarativeWidgetDefinition`, `CustomWidgetDefinition`/`CustomWidgetRendererAdapter`). `ModuleRenderers/CatalogFormat.php` is a helper, not a renderer.
2. `grep -rnE "'type'\s*=>\s*'[a-z0-9_-]+\.[a-z0-9_.-]+'" plugins src` gives 13 plugin widget ids registered through `Studio::widgets()->register()` / `WidgetSdk::register()`:
   - 11 in `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:45-200`
   - 1 in `plugins/studio-builder/StudioBuilderPortfolioWidgets.php:36`
   - 1 in `plugins/studio-testimonial-widget/StudioTestimonialWidget.php:24`

   The other hits (`admin/templates.php`, `admin/ai-mcp.php`, the `WidgetSdk.php` docblock) are document content or comments, not registrations.
3. Cross-check: `ModuleBlockDefinitions::studioRegistry()` returns 47 definitions. With the three plugins booted, the SDK adds 13. `BlockRendererRegistry::withStudioRenderers()` has a renderer for each of the 60. Every definition has a renderer and every renderer has a definition.

**Total: 60 registered block type ids.** The existing matrix (`audit/kohevo-widget-test-matrix.md`) also lists 60. The id sets and the per-type test-file counts match exactly, using its method of quote-bounded literal ids in `tests/` and `plugins/studio-builder/ui/tests`, with `fixtures/` excluded. See section 9 for where the matrix is silent or too optimistic.

**Where the "control schema" lives.** The editor has no hand-written per-block control catalogue. `ui/src/core/fields.mjs:1-8` says so and maps FieldSchema types to controls at `fields.mjs:12-16`. The per-block content controls are the PHP `FieldSchema` declared in:

- `src/Module/StudioBuilder/Registry/BlockRegistry.php`: 40 core, layout and theme blocks
- `src/Module/StudioBuilder/Registry/ModuleBlockDefinitions.php`: 7 business blocks
- each plugin's `'schema' => [...]` array: 13 SDK widgets

They are projected by `DeclarativeBlockDefinition::toEditorManifest()` (`DeclarativeBlockDefinition.php:230-256`) and drawn by `ui/src/components/fields/FieldControl.jsx:59-169` (`ObjectFields` at `:173-199`, `propPresenters.mjs` for container icons and tiles). The style and advanced controls are shared. They are described in section 3.

A second, unused copy of seven definitions exists in `Registry/WidgetRegistry.php:137-389` (finding F32).

## 2. All registered types

Column notes:

- **renderer**: the line of the `type()` return. For SDK widgets, the callable is in the plugin file.
- **schema**: the line that declares the type.
- **controls**: rows in the CSV, with nested repeater and object fields counted.
- **test files**: test files, not fixtures, that contain the literal id.

| # | type_id | renderer (file:line) | schema (file:line) | controls | children | dynamic / entitlement | test files |
|---|---|---|---|---|---|---|---|
| 1 | `acme.testimonial` | StudioTestimonialWidget.php (SDK callable) | StudioTestimonialWidget.php:24 | 6 | no | static | 2 |
| 2 | `booking.embed` | BookingEmbedRenderer.php:36 | ModuleBlockDefinitions.php:99 | 1 | no | dynamic / booking | 5 |
| 3 | `booking.services` | BookingServicesRenderer.php:23 | ModuleBlockDefinitions.php:36 | 3 | no | dynamic / booking | 9 |
| 4 | `core.accordion` | AccordionRenderer.php:27 | BlockRegistry.php:683 | 4 | no | static | 2 |
| 5 | `core.button` | ButtonRenderer.php:21 | BlockRegistry.php:197 | 4 | no | static | 20 |
| 6 | `core.card` | CardRenderer.php:21 | BlockRegistry.php:876 | 3 | yes | static | 3 |
| 7 | `core.carousel` | CarouselRenderer.php:30 | BlockRegistry.php:715 | 9 | no | static | 1 |
| 8 | `core.container` | ContainerRenderer.php:19 | BlockRegistry.php:257 | 2 | yes | static | 15 |
| 9 | `core.countdown` | CountdownRenderer.php:25 | BlockRegistry.php:921 | 3 | no | static | 3 |
| 10 | `core.divider` | DividerRenderer.php:25 | BlockRegistry.php:938 | 4 | no | static | 2 |
| 11 | `core.feature_list` | FeatureListRenderer.php:18 | BlockRegistry.php:217 | 9 | no | static | 8 |
| 12 | `core.form` | FormRenderer.php:18 | BlockRegistry.php:550 | 5 | yes | static | 2 |
| 13 | `core.form_field` | FormFieldRenderer.php:20 | BlockRegistry.php:569 | 7 | no | static | 2 |
| 14 | `core.gallery` | GalleryRenderer.php:22 | BlockRegistry.php:590 | 8 | no | static | 3 |
| 15 | `core.heading` | HeadingRenderer.php:22 | BlockRegistry.php:138 | 8 | no | static | 62 |
| 16 | `core.hero` | HeroRenderer.php:18 | BlockRegistry.php:111 | 12 | no | static | 14 |
| 17 | `core.icon` | IconRenderer.php:22 | BlockRegistry.php:794 | 5 | no | static | 4 |
| 18 | `core.image` | ImageRenderer.php:24 | BlockRegistry.php:178 | 4 | no | static | 12 |
| 19 | `core.link` | LinkRenderer.php:21 | BlockRegistry.php:858 | 3 | no | static | 3 |
| 20 | `core.list` | ListRenderer.php:20 | BlockRegistry.php:813 | 3 | no | static | 3 |
| 21 | `core.map` | MapRenderer.php:22 | BlockRegistry.php:1002 | 5 | no | static | 2 |
| 22 | `core.modal` | ModalRenderer.php:20 | BlockRegistry.php:512 | 5 | yes | static | 2 |
| 23 | `core.nav_menu` | NavMenuRenderer.php:23 | BlockRegistry.php:971 | 8 | no | static | 2 |
| 24 | `core.query_loop` | QueryLoopRenderer.php:26 | BlockRegistry.php:373 | 18 | yes | dynamic | 2 |
| 25 | `core.quote` | QuoteRenderer.php:20 | BlockRegistry.php:839 | 4 | no | static | 6 |
| 26 | `core.rich_text` | RichTextRenderer.php:18 | BlockRegistry.php:162 | 1 | no | static | 8 |
| 27 | `core.spacer` | SpacerRenderer.php:21 | BlockRegistry.php:956 | 1 | no | static | 2 |
| 28 | `core.stats` | StatsRenderer.php:25 | BlockRegistry.php:760 | 6 | no | static | 1 |
| 29 | `core.table` | TableRenderer.php:23 | BlockRegistry.php:893 | 5 | no | static | 1 |
| 30 | `core.tabs` | TabsRenderer.php:25 | BlockRegistry.php:643 | 5 | no | static | 1 |
| 31 | `core.text` | TextRenderer.php:23 | BlockRegistry.php:353 | 4 | no | static | 16 |
| 32 | `core.video` | VideoRenderer.php:20 | BlockRegistry.php:621 | 6 | no | static | 4 |
| 33 | `forms.embed` | FormEmbedRenderer.php:31 | ModuleBlockDefinitions.php:86 | 0 | no | dynamic / forms | 4 |
| 34 | `forms.form_card` | FormCardRenderer.php:24 | ModuleBlockDefinitions.php:70 | 2 | no | dynamic / forms | 3 |
| 35 | `layout.container` | LayoutContainerRenderer.php:24 | BlockRegistry.php:300 | 3 | yes | static | 11 |
| 36 | `layout.flex` | FlexRenderer.php:26 | BlockRegistry.php:317 | 5 | yes | static | 5 |
| 37 | `layout.grid` | GridRenderer.php:23 | BlockRegistry.php:336 | 3 | yes | static | 5 |
| 38 | `layout.offcanvas` | OffcanvasRenderer.php:20 | BlockRegistry.php:531 | 5 | yes | static | 2 |
| 39 | `layout.section` | SectionRenderer.php:26 | BlockRegistry.php:281 | 5 | yes | static | 3 |
| 40 | `membership.plans` | MembershipPlansRenderer.php:18 | ModuleBlockDefinitions.php:53 | 3 | no | dynamic / membership | 6 |
| 41 | `payment.button` | PaymentButtonRenderer.php:24 | ModuleBlockDefinitions.php:114 | 5 | no | static | 3 |
| 42 | `payment.link` | PaymentLinkRenderer.php:23 | ModuleBlockDefinitions.php:130 | 2 | no | static | 3 |
| 43 | `portfolio.experience_timeline` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:168 | 4 | no | static | 2 |
| 44 | `portfolio.eyebrow` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:90 | 2 | no | static | 2 |
| 45 | `portfolio.footer` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:62 | 3 | no | static | 2 |
| 46 | `portfolio.nav_header` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:45 | 5 | no | static | 2 |
| 47 | `portfolio.project_card` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:119 | 9 | no | static | 2 |
| 48 | `portfolio.rakib_showcase` | StudioBuilderPortfolioWidgets.php (SDK callable) | StudioBuilderPortfolioWidgets.php:36 | 11 | no | static | 0 |
| 49 | `portfolio.reassurance_badges` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:104 | 3 | no | static | 2 |
| 50 | `portfolio.service_card` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:184 | 4 | no | static | 2 |
| 51 | `portfolio.skill_grid` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:154 | 2 | no | static | 2 |
| 52 | `portfolio.stat_highlight` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:140 | 2 | no | static | 2 |
| 53 | `portfolio.status_pill` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:77 | 1 | no | static | 2 |
| 54 | `portfolio.testimonial_card` | StudioFreelancerPortfolio.php (SDK callable) | StudioFreelancerPortfolio.php:200 | 5 | no | static | 2 |
| 55 | `theme.archive_title` | ArchiveTitleRenderer.php:20 | BlockRegistry.php:479 | 2 | no | dynamic | 3 |
| 56 | `theme.field_value` | FieldValueRenderer.php:23 | BlockRegistry.php:460 | 4 | no | dynamic | 1 |
| 57 | `theme.post_content` | PostContentRenderer.php:18 | BlockRegistry.php:425 | 1 | no | dynamic | 1 |
| 58 | `theme.post_meta` | PostMetaRenderer.php:18 | BlockRegistry.php:441 | 4 | no | dynamic | 2 |
| 59 | `theme.post_title` | PostTitleRenderer.php:21 | BlockRegistry.php:407 | 3 | no | dynamic | 3 |
| 60 | `theme.search_box` | SearchBoxRenderer.php:18 | BlockRegistry.php:496 | 2 | no | static | 2 |

## 3. Shared control groups

**Capabilities.** All 60 types declare the same 22 `style_capabilities`. This is the constructor default `CanonicalDocumentSchema::ALLOWED_STYLE_KEYS` (`CanonicalDocumentSchema.php:269-292`; default at `DeclarativeBlockDefinition.php:52`). No definition narrows it, and SDK widgets inherit it through `CustomWidgetDefinition::toBlockDefinition()` (`CustomWidgetDefinition.php:211-226`).

**Which sections show.** The editor decides per block type, in `ui/src/core/inspectorSections.mjs:77-101`:

- Typography is hidden for `MEDIA_TYPES` (image, video, gallery) and `NO_TEXT_TYPES` (divider, spacer, icon, map) (`:29`, `:35`, `:85`).
- Background is hidden for `core.map` (`:36`).
- Focus and Active states are offered only for `INTERACTIVE_TYPES` (`:43-53`). Hover is offered everywhere.

The server still accepts every key for every block.

### 3.1 Where each group is defined and how it reaches CSS

| Group | Editor control (file:line) | Document path | Server validation | Emitter (public page) |
|---|---|---|---|---|
| Alignment (per breakpoint) | `ResponsiveSelect`, `BlockInspector.jsx:95-105`, `controls.jsx:42-96` | `style.align` `{base,sm,md,lg}` | `DocumentValidator.php:1028-1046` | `DocumentRenderer::styleClasses` `DocumentRenderer.php:392-404` produces `sb-[bp-]align-*`. CSS from `StudioStylesheet::responsive()` `:451-463` is min-width, mobile-first. |
| Typography (size, line height, spacing, family, colour) | `StyleControls.jsx:316-391` | `style.typography.*`, `style.color`, `text_token` | `DocumentValidator::validateVisualStyles` `:1070-1118`, `StyleValueGuard` | Inline `style=""` from `DocumentRenderer::buildInlineStyles` `:546-644`. `sb-ty-*` markers (`:352-386`) make inner text inherit, via `StudioStylesheet::typographyInheritCss` `:479-496`. |
| Typography (weight, transform) | `BlockInspector.jsx:107-136` | `style.typography.weight/transform` | `DocumentValidator.php:1098-1103` | Classes `sb-font-*`, `sb-<transform>` (`DocumentRenderer.php:411-419`), CSS at `StudioStylesheet.php:236-238` |
| Typography extras (italic, decoration) | `TypographyExtras`, `SurfaceControls.jsx:444-460` | `style.typography.style/decoration*` | `StyleSurface::issues` (table `StyleSurface.php:100-149`) | Block-scoped rule `StyleSurface::declarations` `:536-577`, written as `.sb-x-<hash>` by `RenderCollector::scopedRule` (`RenderCollector.php:106-140`) |
| Colour (text) | `ColorField`, `StyleControls.jsx:44-93`, `:377-388` | `typography.color` / `color` / `text_token` | `StyleValueGuard::isColor` | Inline `color`, or token class `sb-fg--*` (`RenderCollector::tokenClass` `:54-62`) |
| Background | `StyleControls.jsx:403-464`, `BackgroundWidgets.jsx` | `style.background.{color,gradient,image,fit,repeat,position,overlay}`, `surface_token` | `StyleSurface::backgroundIssues` `:168-199` | Inline `background-color` / `background-image` (`DocumentRenderer.php:586-599`). The image goes through `StyleSurface::emitBackgroundImage` `:201-240` with a tenant-resolved URL (`DocumentRenderer.php:536-547`). |
| Border | `StyleControls.jsx:467-517`, `BorderExtras` `SurfaceControls.jsx:370-397` | `style.border.{style,radius,width,color}`, `.{top..left}.*`, `.radius_corners.*`, `border_token` | `validateVisualStyles` + `StyleSurface` | Classes `sb-border-*`, `sb-radius-*` (`DocumentRenderer.php:421-428`). Inline width, colour and custom radius (`:601-612`). Per-side values and corners in the scoped rule (`StyleSurface.php:555-565`). |
| Shadow | `StyleControls.jsx:519-550`, `ShadowExtras` `SurfaceControls.jsx:399-423` | `style.shadow` (preset, CSS string or object) | `ALLOWED_SHADOW_PRESETS` / `isShadow` / `checkShadowObject` | Class `sb-shadow-*`. Inline custom string (`DocumentRenderer.php:614-617`). Object form in the scoped rule (`StyleSurface.php:569-574`). |
| Spacing (margin, padding) | `SpacingPane` `SurfaceControls.jsx:295-309` inside `DeviceStyle` (`BlockInspector.jsx:238-240`) | `style.margin/padding.{top..left}`, `spacing_token` | `StyleSurface` table (`moreFields` `:100-149`) | Scoped rule, plus `@media` with `!important` per device (`StyleSurface::responsiveRules` `:955-976`) |
| Dimensions | `StyleControls.jsx:553-578`, `DimensionsExtras` `SurfaceControls.jsx:425-442` | `style.dimensions.*` | `StyleValueGuard::isLength` + `StyleSurface` | Inline width, height, min/max (`DocumentRenderer.php:619-626`). Extras in the scoped rule. |
| Layout (flex/grid container and item) | `LayoutPane` `SurfaceControls.jsx:265-293` | `style.layout.*` | `StyleSurface::fields()['layout']` `:51-98` | Scoped rule plus per-device `@media` (`display` excluded, `StyleSurface.php:914-929`) |
| Position | `PositionPane` `SurfaceControls.jsx:311-333` | `style.position.*` (`fixed` deliberately absent) | `StyleSurface` | Scoped rule |
| Transform, filter, blend, cursor, transition | `EffectsPane` `SurfaceControls.jsx:335-368` | `style.effects.*` | `StyleSurface::checkEffects` `:440-493` | Scoped rule (`StyleSurface::emitEffects` `:607-665`), plus a `prefers-reduced-motion` override (`RenderCollector.php:117`) |
| Opacity, z-index | `StyleControls.jsx:581-598`, `BlockInspector.jsx:241-257` | `style.opacity`, `style.z_index` | numeric ranges; z-index clamped `-999..999` | Inline (`DocumentRenderer.php:628-637`) |
| States (hover, focus, active, disabled) | `StatesPane` `SurfaceControls.jsx:472-512` | `block.style_states` | `StyleSurface::stateIssues` `:750-812` | `StyleSurface::stateRules` `:891-912` maps to `:hover`, `:focus-within`, `:active`, `:has(:disabled)` (`STATE_SELECTORS` `:97`), inside `scopedRule` |
| Visibility (per breakpoint) | `VisibilityControls`, `controls.jsx:105-130` | `block.visibility.devices` `[base,sm,md,lg]` + `auth_state` | `ALLOWED_VISIBILITY_KEYS` | `DocumentRenderer::hideClasses` `:647-660` produces `sb-hide-<bp>` (`StudioStylesheet.php:436-438`). Auth state is filtered server-side. |
| Device hide | Device overrides checkboxes, `BlockInspector.jsx:209-233` | `block.responsive.{desktop,tablet,mobile}.hide` | loose (`DocumentValidator.php:717-736`) | `DocumentRenderer.php:146-159` produces `sb-hide-desktop|tablet|mobile` (`StudioStylesheet.php:439-441`) |
| Motion (entrance, interaction) | `MotionInspector.jsx:24-225` | `block.animation`, `block.interactions` | `ALLOWED_ANIMATION_TYPES` / `ALLOWED_INTERACTION_TRIGGERS` | Classes `sb-animate-*` / `sb-interaction-*` and `--sb-anim-*` variables, clamped (`DocumentRenderer.php:448-531`) |
| Identity, attributes, classes, tag | `AdvancedControls.jsx:42-168`, `BlockInspector.jsx:185-192` | `attributes`, `classNames`, `tag` | `CanonicalDocumentSchema::blockAttributeIssue` `:220-246` (re-checked on render, `DocumentRenderer.php:184-193`) | Wrapper attributes. The id is made unique by `RenderCollector::claimId`. |

### 3.2 Editor preview and server output

- **Markup comes from one path.** The canvas is the server render in `RenderMode::Editor`. `ui/src/core/liveFragment.mjs:1-5` fetches `render_block` / `render_section` for new nodes. `canvasMorph.mjs` swaps in the server's re-render after each save. Renderers branch on mode in only a few places (embed, payment and form preview cards, and diagnostics).
- **Style has a second, client-side path.** `ui/src/core/liveStyle.mjs` (357 lines) is a hand-kept JavaScript mirror of `StyleSurface::declarations`, `DocumentRenderer::buildInlineStyles`, `styleClasses`, `typographyMarkers` and `StyleSurface::responsiveRules`. It predicts style edits instantly (`liveStyle.mjs:1-10`), and the server render then replaces it. It reports `covered:false`, and the canvas falls back to the server, for anything it cannot predict: background images, `style_states`, unknown keys and missing tokens (`:298-316`).
- **The mirror is pinned, not shared.** `tests/unit/StudioBuilderLiveStyleParityTest.php` and `ui/tests/live-style.test.mjs` test it against `ui/tests/fixtures/live-style.json`. `StudioBuilderStyleSurfaceParityTest.php` and `style-surface.test.mjs` pin `styleSurface.mjs` against `fixtures/style-surface.json`. Because it is a faithful copy, it copies the server's bugs too: the class-to-CSS gaps in F2 and F23 exist in both, and `MANAGED_CLASS` (`liveStyle.mjs:272`) even lists `sb-border-solid`.
- **Text edits have a third path.** `canvasLiveSync.mjs` and `canvasPatch.mjs` patch text content directly in the DOM for some props. See F33.

### 3.3 Responsive model (four mechanisms)

1. **`style.align`**: a map keyed by `base`/`sm`/`md`/`lg` (640/768/1024 px), mobile-first, with UI.
2. **`block.responsive.{tablet,mobile}.style`**: allowed paths are `typography.size`, `padding.*`, `margin.*` and `layout.*` except `display` (`StyleSurface.php:914-953`; editor `responsiveStyle.mjs:12-68`, `DeviceStyle.jsx`). Emitted as `@media (max-width:1023.98px)` / `(max-width:767.98px)` rules with `!important` (`StyleSurface.php:955-976`; JavaScript mirror `liveStyle.mjs:276-290`). The editor offers per-device editing for font size, spacing, layout gap and container/item layout only.
3. **`block.visibility.devices`**: the `base`/`sm`/`md`/`lg` buckets. **`block.responsive.{desktop,tablet,mobile}.hide`**: a second vocabulary with different cut-offs. Both appear in the same Responsive section (F12).
4. **`block.responsive.<device>.align`**: read by the renderer only (`DocumentRenderer.php:155-157`). No UI writes it, and its CSS is not scoped to a media query (F4).

All content props are device-independent. No FieldSchema field supports per-device values. The validator accepts a breakpoint map for `typography.size` (`DocumentValidator.php:1084-1096`), but the renderer drops it (F23).

### 3.4 Dynamic data ("dynamic tags") and global styles

- **Dynamic data is binding providers.** A block that declares `binding_slots` gets the Inspector's Data section (`inspectorSections.mjs:79`; `BindingsEditor` `BlockInspector.jsx:290-325`). Six blocks do: `core.query_loop`, `booking.services`, `booking.embed`, `membership.plans`, `forms.form_card` and `forms.embed`.
- **Bindings without a UI.** Nine more types declare `allowedBindingProviders` but no slot: `core.heading`, `core.text`, `core.button`, `core.image`, `core.rich_text`, `theme.post_title`, `theme.post_content`, `theme.post_meta` and `theme.archive_title`. For these, `BlockRenderScope::prop()` (`BlockRenderScope.php:67-81`) substitutes `bindings.<slot>.mapping` values from provider rows for any prop. The validator checks only the shape of a mapping, not whether its key is a declared prop (`DocumentValidator.php:1310-1322`). These bindings have no editor UI (F39).
- **Global styles are theme tokens.** `token_ref` props (`accent_token`, `color_token`, `surface_token`, `background_token`, `highlight_color_token`) and the `*_token` style keys go through `RenderCollector::tokenClass`, which writes `.sb-<kind>--<ref>{prop:var(--sb-…)}` (`RenderCollector.php:54-62`). The editor token picker filters categories with a regular expression on the field key (`FieldControl.jsx:135`; see F31). The site-wide token editor is `SiteSettings.jsx` / `ThemeBottomSheet.jsx`. That is out of scope here.

## 4. Per-type details

Format for each type:

- **Class**: renderer class, `file:line` of `type()`.
- **Schema**: the file and line of the first field.
- **Controls**: `key` FieldSchema type -> editor control [accepted values / constraints] default. **Bold** marks partial or dead reads.
- **Renderer inputs with no control**.
- **Notes**: escaping, sanitisation, dynamic behaviour, localisation and accessibility.
- **Tests**: file names.

Validation for every content control is FieldSchema on save (`FieldSchema.php:459-614`, unknown keys rejected at `:144-154`, wired at `DocumentValidator.php:860`). The CSV states the rule per field. Style and advanced controls are the shared groups in section 3. They are the same for every type.

#### `acme.testimonial`

- Class: `StudioTestimonialWidget::renderTestimonial (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-testimonial-widget/StudioTestimonialWidget.php:42`
- Schema: `plugins/studio-testimonial-widget/StudioTestimonialWidget.php` (first field line 30)
- Controls (6): `author` string -> text [max_length=120] def "Alex Rivera"; `role` string -> text [max_length=120] def "VP of Engineering"; `company` string -> text [max_length=120] def "InnovateLabs"; `quote` text -> textarea [max_length=1000]; `avatar_url` url -> url def null; `rating` number -> number [min=1; max=5; integer] def 5
- Notes: SDK example; raw HTML from plugin callable (no post-render sanitization by the adapter). avatar_url is `url` type. Rating div has aria-label without a role (StudioTestimonialWidget.php:55).
- Tests (2): StudioBuilderSprint8AcceptanceTest.php, StudioBuilderSprint8WidgetSdkTest.php

#### `booking.embed`

- Class: `Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\BookingEmbedRenderer` - `src/Module/StudioBuilder/Render/Block/ModuleRenderers/BookingEmbedRenderer.php:36`
- Schema: `src/Module/StudioBuilder/Registry/ModuleBlockDefinitions.php` (first field line 107)
- Controls (1): `min_height` number -> number [min=200; max=2400; integer] def 560
- Notes: Dynamic (isDynamic=true, entitlement booking). Binding slot `service` -> booking.embed_target. Editor mode renders a preview card; public renders an iframe to `book?embed=1` plus a once-per-page `<script>`. min_height clamped 200..2400 in renderer. All text Html::e / Html::t. Unit "min" hard-coded (BookingEmbedRenderer.php:77).
- Tests (5): add-panel.test.mjs, canvasLiveSync.test.mjs, embed-blocks.test.mjs, live-structure.test.mjs, StudioBuilderEmbedBlocksTest.php

#### `booking.services`

- Class: `Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\BookingServicesRenderer` - `src/Module/StudioBuilder/Render/Block/ModuleRenderers/BookingServicesRenderer.php:23`
- Schema: `src/Module/StudioBuilder/Registry/ModuleBlockDefinitions.php` (first field line 44)
- Controls (3): `heading` string -> text [max_length=200] def ""; `show_prices` boolean -> checkbox def true; `cta_label` string -> text [max_length=80] def ""
- Notes: Dynamic, entitlement booking, slot `items`. Rows from provider; all output Html::e/Html::text; CTA href from SiteContext. "min" hard-coded (BookingServicesRenderer.php:51); price via CatalogFormat::price (fixed `.`/`,` format).
- Tests (9): add-panel.test.mjs, core.test.mjs, StudioBuilderPhase3ApplicationBoundaryTest.php, StudioBuilderPhase4RuntimeTest.php, StudioBuilderPhase5BuilderApiTest.php, StudioBuilderPhase8PackageTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase5BuilderTest.php, StudioBuilderPhase8HtmlImportTest.php

#### `core.accordion`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\AccordionRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/AccordionRenderer.php:27`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 692)
- Controls (4): `items` repeater -> repeater [max_items=20]; `items[].heading` string -> text [max_length=200]; `items[].body` text -> textarea [max_length=2000] def ""; `allow_multiple` boolean -> checkbox def false
- Notes: Static. `<h3>` heading level is fixed (AccordionRenderer.php:67). Button has aria-expanded/aria-controls; panel role=region + hidden. Runtime initAccordion handles toggling. Fallback label "Item N" not localized (AccordionRenderer.php:78).
- Tests (2): add-panel.test.mjs, StudioBuilderBlockLibraryTest.php

#### `core.button`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ButtonRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/ButtonRenderer.php:21`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 205)
- Controls (4): `link` link -> link; `variant` enum -> select [primary|secondary|outline|ghost] def "primary"; `size` enum -> select [sm|md|lg] def "md"; `full_width` boolean -> checkbox def false
- Renderer inputs with no control: `url` (src/Module/StudioBuilder/Render/Block/CoreRenderers/ButtonRenderer.php:33), `text` (src/Module/StudioBuilder/Render/Block/CoreRenderers/ButtonRenderer.php:34), `target` (src/Module/StudioBuilder/Render/Block/CoreRenderers/ButtonRenderer.php:45)
- Notes: Static. Html::link re-checks isSafeUrl and adds rel=noopener noreferrer for _blank. Legacy url/text/target props read with no schema entry. Dynamic binding allowed (6 providers) but no binding slot, so no UI.
- Tests (20): core.test.mjs, element-manager.test.mjs, inline-text.test.mjs, inspector-sections.test.mjs, StudioBuilderPhase5BuilderApiTest.php, StudioBuilderPhase7AiMcpTest.php, StudioBuilderSprint1AcceptanceTest.php, StudioBuilderSprint2AcceptanceTest.php, StudioBuilderSprint3AcceptanceTest.php, StudioBuilderSprint4AcceptanceTest.php, StudioBuilderElementManagerTest.php, StudioBuilderElementsTest.php, StudioBuilderInlineTextTest.php, StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderSectionPresetsTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php, StudioBuilderSprint3NavigatorAndOperationsTest.php, StudioBuilderSprint4TemplatesTokensComponentsTest.php

#### `core.card`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\CardRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/CardRenderer.php:21`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 884)
- Controls (3): `variant` enum -> select [outlined|filled|shadow] def "outlined"; `padding` enum -> select [sm|md|lg] def "md"; `surface_token` token_ref -> token def null
- Notes: Container (allows children). surface_token -> token class. Duplicates style.surface_token / Background section (two controls for the card surface).
- Tests (3): inline-text.test.mjs, inspector-sections.test.mjs, StudioBuilderElementsTest.php

#### `core.carousel`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\CarouselRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/CarouselRenderer.php:30`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 724)
- Controls (9): `slides` repeater -> repeater [max_items=20]; `slides[].image` media_ref -> media def null; `slides[].quote` text -> textarea [max_length=600] def ""; `slides[].author` string -> text [max_length=120] def ""; `slides[].caption` string -> text [max_length=255] def ""; `options` object -> object; `options.per_view` number -> number [min=1; max=3; integer] def 1; `options.loop` boolean -> checkbox def true; `options.autoplay_ms` number -> number [min=0; max=60000; integer] def 0 **partial: runtime only autoplays when loop=true (studio-runtime.js restart())**
- Renderer inputs with no control: `options.label` (src/Module/StudioBuilder/Render/Block/CoreRenderers/CarouselRenderer.php:111)
- Notes: Static markup + runtime. Server dots are replaced at runtime with English labels (studio-runtime.js:874). options.label read but not in schema. Autoplay needs loop=true (studio-runtime.js:883); pause on hover/focus only when prefersHover() (studio-runtime.js:890); no visible pause control.
- Tests (1): StudioBuilderBlockLibraryTest.php

#### `core.container`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ContainerRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/ContainerRenderer.php:19`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 265)
- Controls (2): `gap` enum -> select [none|xs|sm|md|lg|xl|2xl] def "md"; `direction` enum -> select [vertical|horizontal] def "vertical"
- Notes: Container. gap/direction emitted as wrapper classes (sb-stack, sb-gap-*). Overlaps style.layout (direction/gap) in the Flex/Grid section.
- Tests (15): canvasLiveSync.test.mjs, clipboard.test.mjs, helpers.mjs, inspector-sections.test.mjs, library.test.mjs, row-menu.test.mjs, StudioBuilderPhase5BuilderApiTest.php, StudioBuilderPhase6TemplatesComponentsTest.php, StudioBuilderPhase8PackageTest.php, StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase6TemplatesComponentsTest.php, StudioBuilderPhase7AiMcpTest.php, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderPhase8PackageTest.php

#### `core.countdown`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\CountdownRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/CountdownRenderer.php:25`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 929)
- Controls (3): `target` string -> text [max_length=40; pattern /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])T([01]\d|2[0-3]):[0...] def "2030-01-01T00:00:00Z"; `label` string -> text [max_length=120] def "Starts in"; `done_text` string -> text [max_length=160] def ""
- Notes: Static; runtime ticks. target validated by TARGET_PATTERN at save and render; impossible dates refused. role=timer aria-live=off. Unit names localized via Html::t.
- Tests (3): element-manager.test.mjs, StudioBuilderElementManagerTest.php, StudioBuilderElementsTest.php

#### `core.divider`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\DividerRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/DividerRenderer.php:25`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 946)
- Controls (4): `style` enum -> select [solid|dashed|dotted] def "solid"; `weight` enum -> select [thin|medium|thick] def "thin"; `width` enum -> select [full|wide|narrow|short] def "full"; `align` enum -> select [left|center|right] def "center"
- Notes: Static `<hr>` with enum classes; all values have CSS.
- Tests (2): inspector-sections.test.mjs, StudioBuilderElementsTest.php

#### `core.feature_list`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FeatureListRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/FeatureListRenderer.php:18`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 225)
- Controls (9): `title` string -> text [max_length=200] def ""; `columns` number -> number [min=1; max=4; integer] def 3; `items` repeater -> repeater [max_items=12] def []; `items[].heading` string -> text [max_length=150]; `items[].body` text -> textarea [max_length=600] def ""; `items[].url` url -> url def null; `card_options` object -> object; `card_options.bordered` boolean -> checkbox def true; `card_options.surface_token` token_ref -> token def null
- Notes: Static. url via Html::safeUrl; "Learn more" via Html::t. card_options.surface_token -> token class.
- Tests (8): components.test.mjs, inline-text.test.mjs, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderPhase9LocaleTest.php, StudioBuilderInlineTextTest.php, StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase8HtmlImportTest.php

#### `core.form`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FormRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/FormRenderer.php:18`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 558)
- Controls (5): `action` string -> text [max_length=500] def ""; `method` enum -> select [post|get] def "post"; `form_name` string -> text [max_length=64] def "contact_form"; `submit_text` string -> text [max_length=60] def "Submit"; `submit_variant` enum -> select [primary|secondary|outline] def "primary"
- Notes: Container. action is a `string` field (not `url`): no isSafeUrl, only the executable filter; any external host accepted. submit_variant escaped but not re-validated (FormRenderer.php). No CSRF token / handler in the block.
- Tests (2): StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php

#### `core.form_field`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FormFieldRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/FormFieldRenderer.php:20`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 577)
- Controls (7): `name` string -> text [max_length=64] def "field_name"; `label` string -> text [max_length=120] def "Field Label"; `field_type` enum -> select [text|email|tel|number|url|textarea|select|checkbox|radio] def "text"; `placeholder` string -> text [max_length=120] def ""; `required` boolean -> checkbox def false; `help_text` string -> text [max_length=255] def ""; `options` string -> text [max_length=500] def "" **partial: used for select only; radio renders a single option-less input**
- Notes: Static. Non-deterministic id via random_bytes (FormFieldRenderer.php:41) - output differs on every render. help_text not linked with aria-describedby (FormFieldRenderer.php:82). radio ignores options.
- Tests (2): StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php

#### `core.gallery`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\GalleryRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/GalleryRenderer.php:22`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 598)
- Controls (8): `columns` enum -> select [2|3|4|6] def "3"; `gap` enum -> select [none|xs|sm|md|lg] def "md"; `aspect_ratio` enum -> select [auto|1:1|4:3|16:9] def "1:1"; `rounded` boolean -> checkbox def true; `images` repeater -> repeater [max_items=50] def []; `images[].url` url -> url [max_length=1000]; `images[].alt` string -> text [max_length=200] def ""; `images[].caption` string -> text [max_length=255] def ""
- Renderer inputs with no control: `images[].src` (src/Module/StudioBuilder/Render/Block/CoreRenderers/GalleryRenderer.php:63)
- Notes: Static. Image urls validated as `url` on save; alt/caption escaped. With no images the PUBLIC page shows grey "Image N" placeholders with inline style (GalleryRenderer.php:80). Runtime adds a lightbox.
- Tests (3): inspector-sections.test.mjs, StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php

#### `core.heading`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\HeadingRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/HeadingRenderer.php:22`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 146)
- Controls (8): `text` string -> text [max_length=300] def "Section Heading"; `highlight` string -> text [max_length=100] def ""; `highlight_color_token` token_ref -> token def null; `highlight_color` string -> color [max_length=40; pattern /^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8}...] def ""; `highlight_italic` boolean -> checkbox def false; `highlight_weight` enum -> select [inherit|normal|medium|bold] def "inherit"; `highlight_underline` boolean -> checkbox def false; `level` enum -> select [h1|h2|h3|h4|h5|h6] def "h2"
- Renderer inputs with no control: `size` (src/Module/StudioBuilder/Render/Block/CoreRenderers/HeadingRenderer.php:37), `align` (src/Module/StudioBuilder/Render/Block/CoreRenderers/HeadingRenderer.php:41), `color_token` (src/Module/StudioBuilder/Render/Block/CoreRenderers/HeadingRenderer.php:45)
- Notes: Static. Html::highlighted escapes every part; custom colour re-checked against HIGHLIGHT_COLOR_PATTERN. Renderer also reads size/align/color_token that have no schema entry. Dynamic binding allowed but no slot/UI.
- Tests (62): add-panel.test.mjs, canvas-patch.test.mjs, canvasLiveSync.test.mjs, clipboard.test.mjs, core.test.mjs, element-manager.test.mjs, helpers.mjs, inline-text.test.mjs, inspector-bar.test.mjs, inspector-sections.test.mjs, layer-lock.test.mjs, library.test.mjs, live-style-sync.test.mjs, prop-presenters.test.mjs, row-menu.test.mjs, sync.test.mjs, wireframe.test.mjs, StudioBuilderPagesApiTest.php, StudioBuilderPhase4RuntimeTest.php, StudioBuilderPhase5BuilderApiTest.php, StudioBuilderPhase6TemplatesComponentsTest.php, StudioBuilderPhase7AiMcpTest.php, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderPhase8PackageTest.php, StudioBuilderPhase9LocaleTest.php, StudioBuilderPhase9ProductionTest.php, StudioBuilderPhase9RuntimeHardeningTest.php, StudioBuilderPhase9SeoTest.php, StudioBuilderSectionPresetsTest.php, StudioBuilderSprint1AcceptanceTest.php, StudioBuilderSprint2AcceptanceTest.php, StudioBuilderSprint3AcceptanceTest.php, StudioBuilderSprint4AcceptanceTest.php, StudioBuilderSprint5AcceptanceTest.php, StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint7AcceptanceTest.php, live_style_fixture.php, StudioBuilderBlockAttributesTest.php, StudioBuilderElementManagerTest.php, StudioBuilderElementsTest.php, StudioBuilderInlineTextTest.php, StudioBuilderLayerLockTest.php, StudioBuilderOperationsP3bTest.php, StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase4MotionTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase5BuilderTest.php, StudioBuilderPhase5StylesTest.php, StudioBuilderPhase6TemplatesComponentsTest.php, StudioBuilderPhase7AiMcpTest.php, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderSectionPresetsTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php, StudioBuilderSprint2ControlsAndInspectorTest.php, StudioBuilderSprint3NavigatorAndOperationsTest.php, StudioBuilderSprint4TemplatesTokensComponentsTest.php, StudioBuilderSprint5DynamicQueryLoopTest.php, StudioBuilderSprint7AdvancedUiTest.php, StudioBuilderStyleAttributesTest.php, StudioBuilderStyleAuditTest.php, StudioBuilderStyleSectionMotionTest.php, StudioBuilderStyleSurfaceTest.php

#### `core.hero`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\HeroRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/HeroRenderer.php:18`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 119)
- Controls (12): `eyebrow` string -> text [max_length=120] def ""; `heading` string -> text [max_length=255] def "Welcome"; `highlight` string -> text [max_length=100] def ""; `highlight_color_token` token_ref -> token def null; `highlight_color` string -> color [max_length=40; pattern /^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8}...] def ""; `highlight_italic` boolean -> checkbox def false; `highlight_weight` enum -> select [inherit|normal|medium|bold] def "inherit"; `highlight_underline` boolean -> checkbox def false; `subheading` text -> textarea [max_length=1000] def ""; `primary_cta` link -> link def null; `media` media_ref -> media def null; `accent_token` token_ref -> token def null
- Notes: Static. heading via Html::highlighted, subheading Html::text, CTA via Html::link, media via tenant resolver; h1 is fixed.
- Tests (14): add-panel.test.mjs, components.test.mjs, inline-text.test.mjs, StudioBuilderPhase2RevisionDomainTest.php, StudioBuilderPhase3ApplicationBoundaryTest.php, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderPhase8PackageTest.php, StudioBuilderElementManagerTest.php, StudioBuilderElementsTest.php, StudioBuilderInlineTextTest.php, StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderPhase8PackageTest.php

#### `core.icon`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\IconRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/IconRenderer.php:22`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 802)
- Controls (5): `name` enum -> select [check|check-circle|star|heart|arrow-right|mail|phone|map-pin|clock|calendar|user|users|...] def "star"; `size` enum -> select [sm|md|lg|xl] def "md"; `align` enum -> select [left|center|right] def "left"; `color_token` token_ref -> token def null; `label` string -> text [max_length=120] def ""
- Notes: Static. name must be in IconLibrary; decorative by default (aria-hidden) or role=img + aria-label when `label` set.
- Tests (4): canvasLiveSync.test.mjs, inspector-sections.test.mjs, wireframe.test.mjs, StudioBuilderElementsTest.php

#### `core.image`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ImageRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/ImageRenderer.php:24`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 186)
- Controls (4): `media` media_ref -> media; `caption` string -> text [max_length=300] def ""; `aspect_ratio` enum -> select [auto|16:9|4:3|1:1|3:4] def "auto"; `rounded` boolean -> checkbox def true
- Notes: Static. Media resolved per tenant; alt from media_ref; focal point numbers only. Missing image -> diagnostics only.
- Tests (12): core.test.mjs, inline-text.test.mjs, inspector-sections.test.mjs, StudioBuilderPhase4RuntimeTest.php, StudioBuilderPhase7AiMcpTest.php, StudioBuilderPhase8PackageTest.php, StudioBuilderInlineTextTest.php, StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderPhase8PackageTest.php, StudioBuilderSectionPresetsTest.php

#### `core.link`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\LinkRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/LinkRenderer.php:21`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 866)
- Controls (3): `link` link -> link; `style` enum -> select [underline|plain|arrow] def "underline"; `align` enum -> select [left|center|right] def "left"
- Notes: Static. Html::link (isSafeUrl). allowedBindingProviders explicitly [].
- Tests (3): canvasLiveSync.test.mjs, wireframe.test.mjs, StudioBuilderElementsTest.php

#### `core.list`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ListRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/ListRenderer.php:20`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 821)
- Controls (3): `style` enum -> select [bullet|number|check|none] def "bullet"; `items` repeater -> repeater [max_items=30]; `items[].text` string -> text [max_length=300]
- Notes: Static. Items Html::text; ol for number.
- Tests (3): canvasLiveSync.test.mjs, wireframe.test.mjs, StudioBuilderElementsTest.php

#### `core.map`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\MapRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/MapRenderer.php:22`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 1010)
- Controls (5): `latitude` number -> number [min=-85; max=85] def 50.8503; `longitude` number -> number [min=-180; max=180] def 4.3517; `zoom` number -> number [min=1; max=19; integer] def 13; `height` enum -> select [sm|md|lg] def "md"; `title` string -> text [max_length=120] def "Map"
- Notes: Static OSM iframe; lat/lon/zoom re-clamped in renderer; title from prop (default "Map", English schema default).
- Tests (2): inspector-sections.test.mjs, StudioBuilderElementsTest.php

#### `core.modal`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ModalRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/ModalRenderer.php:20`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 520)
- Controls (5): `modal_id` string -> text [max_length=64] def "modal-1"; `title` string -> text [max_length=255] def "Modal Title"; `size` enum -> select [sm|md|lg|full] def "md"; `trigger_text` string -> text [max_length=100] def "Open Modal"; `trigger_variant` enum -> select [primary|secondary|outline|ghost] def "primary"
- Notes: Container. role=dialog aria-modal, aria-labelledby; runtime focus trap + Escape (studio-runtime.js:314-360). Close links aria-label="Close" not localized (ModalRenderer.php:51,55); `<h3>` fixed; modal_id not de-duplicated via RenderCollector::claimId.
- Tests (2): StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php

#### `core.nav_menu`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\NavMenuRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/NavMenuRenderer.php:23`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 979)
- Controls (8): `menu` string -> select (choices injected by StudioApplicationService) [max_length=63; pattern /^([a-z0-9][a-z0-9-]{0,62})?$/] def ""; `aria_label` string -> text [max_length=80] def "Menu"; `layout` enum -> select [horizontal|vertical] def "horizontal"; `align` enum -> select [left|center|right] def "left"; `style` enum -> select [plain|underline|pill] def "plain"; `items` repeater -> repeater [max_items=12]; `items[].label` string -> text [max_length=80]; `items[].url` url -> url [max_length=1000]
- Notes: Static. Site menu via StudioMenus or hand links; Html::link per item. `menu` gets choices injected by StudioApplicationService.
- Tests (2): StudioBuilderPhase4RuntimeTest.php, StudioBuilderElementsTest.php

#### `core.query_loop`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/QueryLoopRenderer.php:26`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 381)
- Controls (18): `source` enum -> select [posts|pages|custom] def "posts"; `category` string -> text [max_length=80] def ""; `tag` string -> text [max_length=80] def ""; `author_id` number -> number [integer] def 0; `order_by` enum -> select [published_at|title|created_at] def "published_at"; `order` enum -> select [desc|asc] def "desc"; `per_page` number -> number [min=1; max=24; integer] def 6; `columns` number -> number [min=1; max=6; integer] def 3; `gap` enum -> select [none|xs|sm|md|lg|xl|2xl] def "md"; `card_variant` enum -> select [card|minimal|horizontal] def "card" **partial: class emitted; .sb-post-card--minimal/--horizontal have no CSS**; `show_featured_image` boolean -> checkbox def true; `show_date` boolean -> checkbox def true; `show_author` boolean -> checkbox def true; `show_category` boolean -> checkbox def true; `show_excerpt` boolean -> checkbox def true; `read_more_text` string -> text [max_length=80] def "Read More"; `enable_pagination` boolean -> checkbox def true **yes, but emits a static fake pager (always page 1, link to ?page=2)**; `empty_message` string -> text [max_length=200] def "No posts found."
- Renderer inputs with no control: `page` (src/Module/StudioBuilder/Render/Block/CoreRenderers/QueryLoopRenderer.php:50)
- Notes: isDynamic=true. Fake static pager (QueryLoopRenderer.php:157-161). Falls back to PostsProvider only when $GLOBALS[SLATE_TENANT_OVERRIDE] is set (line 53). `gap`/`card_variant` escaped but not re-validated (line 151). aria-label="Pagination" not localized.
- Tests (2): StudioBuilderSprint5AcceptanceTest.php, StudioBuilderSprint5DynamicQueryLoopTest.php

#### `core.quote`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QuoteRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/QuoteRenderer.php:20`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 847)
- Controls (4): `text` text -> textarea [max_length=1000]; `author` string -> text [max_length=120] def ""; `role` string -> text [max_length=120] def ""; `align` enum -> select [left|center] def "left"
- Notes: Static. Html::text / Html::e.
- Tests (6): canvasLiveSync.test.mjs, inline-text.test.mjs, inspector-sections.test.mjs, wireframe.test.mjs, StudioBuilderElementsTest.php, StudioBuilderInlineTextTest.php

#### `core.rich_text`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\RichTextRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/RichTextRenderer.php:18`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 170)
- Controls (1): `content` rich_text -> richtext def "<p></p>"
- Notes: Static. FieldSchema::validateRichText on save, RichTextSanitizer::sanitize on render.
- Tests (8): StudioBuilderPhase5BuilderApiTest.php, StudioBuilderPhase7AiMcpTest.php, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderPhase8PackageTest.php, StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase7AiMcpTest.php, StudioBuilderPhase8HtmlImportTest.php, StudioBuilderSectionPresetsTest.php

#### `core.spacer`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\SpacerRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/SpacerRenderer.php:21`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 964)
- Controls (1): `size` enum -> select [xs|sm|md|lg|xl|2xl] def "md"
- Notes: Static, aria-hidden.
- Tests (2): inspector-sections.test.mjs, StudioBuilderElementsTest.php

#### `core.stats`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\StatsRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/StatsRenderer.php:25`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 769)
- Controls (6): `items` repeater -> repeater [max_items=6]; `items[].value` number -> number [min=-1000000000; max=1000000000]; `items[].label` string -> text [max_length=120]; `items[].prefix` string -> text [max_length=8] def ""; `items[].suffix` string -> text [max_length=8] def ""; `items[].decimals` number -> number [min=0; max=3; integer] def 0
- Notes: Static + runtime counter. number_format fixed separators (not locale aware).
- Tests (1): StudioBuilderBlockLibraryTest.php

#### `core.table`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\TableRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/TableRenderer.php:23`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 901)
- Controls (5): `caption` string -> text [max_length=160] def ""; `header` string -> text [max_length=400] def "Plan | Price | Seats"; `striped` boolean -> checkbox def true; `rows` repeater -> repeater [max_items=50]; `rows[].cells` string -> text [max_length=600]
- Notes: Static. Pipe-separated cells escaped; th scope=col; caption.
- Tests (1): StudioBuilderElementsTest.php

#### `core.tabs`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\TabsRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/TabsRenderer.php:25`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 652)
- Controls (5): `items` repeater -> repeater [max_items=8]; `items[].label` string -> text [max_length=60]; `items[].content` text -> textarea [max_length=2000] def ""; `options` object -> object; `options.position` enum -> select [top|bottom] def "top" **partial: value read (TabsRenderer) but .sb-tabs-block--bottom has no CSS in StudioStylesheet; bottom has no effect**
- Notes: Static + runtime (arrow/Home/End keys, studio-runtime.js:690-693). options.position=bottom has no CSS. Fallback "Tab N" not localized (TabsRenderer.php:46).
- Tests (1): StudioBuilderBlockLibraryTest.php

#### `core.text`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\TextRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/TextRenderer.php:23`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 361)
- Controls (4): `content` text -> textarea [max_length=5000] def ""; `size` enum -> select [xs|sm|base|lg|xl|lead] def "base"; `align` enum -> select [left|center|right|justify] def "left"; `color_token` token_ref -> token def null
- Notes: Static. Html::text. color_token duplicates the Typography text colour / text_token.
- Tests (16): add-panel.test.mjs, canvasLiveSync.test.mjs, clipboard.test.mjs, inline-text.test.mjs, inspector-bar.test.mjs, inspector-sections.test.mjs, live-structure.test.mjs, StudioBuilderSprint1AcceptanceTest.php, StudioBuilderSprint2AcceptanceTest.php, StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint7AcceptanceTest.php, StudioBuilderElementManagerTest.php, StudioBuilderInlineTextTest.php, StudioBuilderSectionPresetsTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php, StudioBuilderSprint5DynamicQueryLoopTest.php

#### `core.video`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\VideoRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/VideoRenderer.php:20`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 629)
- Controls (6): `url` string -> text [max_length=1000]; `aspect_ratio` enum -> select [16:9|4:3|1:1] def "16:9"; `autoplay` boolean -> checkbox def false; `controls` boolean -> checkbox def true **partial: ignored for Vimeo embeds**; `muted` boolean -> checkbox def false; `loop` boolean -> checkbox def false
- Notes: Static. YouTube/Vimeo rewritten to fixed embed hosts with cleaned ids; direct links http(s) only. Placeholder "Video unavailable" and iframe title="Video" hard-coded English (VideoRenderer.php:43,66). `.ogg` passes buildEmbedUrl (line 127) but only .mp4/.webm get `<video>` (line 48), so .ogg ends up in an iframe. Default URL is a YouTube video id dQw4w9WgXcQ (BlockRegistry.php:629).
- Tests (4): inspector-sections.test.mjs, StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSectionPresetsTest.php, StudioBuilderSprint7AdvancedUiTest.php

#### `forms.embed`

- Class: `Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\FormEmbedRenderer` - `src/Module/StudioBuilder/Render/Block/ModuleRenderers/FormEmbedRenderer.php:31`
- Schema: `src/Module/StudioBuilder/Registry/ModuleBlockDefinitions.php` (first field line 86)
- Controls (1): (no content controls; binding slot only)
- Notes: Dynamic, entitlement forms; no props, binding slot `form`. Preview card in editor; iframe to forms/<slug>?embed=1 in public mode.
- Tests (4): add-panel.test.mjs, embed-blocks.test.mjs, StudioBuilderEmbedBlocksTest.php, StudioBuilderPhase2DocumentDomainTest.php

#### `forms.form_card`

- Class: `Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\FormCardRenderer` - `src/Module/StudioBuilder/Render/Block/ModuleRenderers/FormCardRenderer.php:24`
- Schema: `src/Module/StudioBuilder/Registry/ModuleBlockDefinitions.php` (first field line 78)
- Controls (2): `button_label` string -> text [max_length=80] def ""; `show_description` boolean -> checkbox def true
- Notes: Dynamic, entitlement forms. Slug pattern re-checked; link to forms/<slug>.
- Tests (3): add-panel.test.mjs, core.test.mjs, StudioBuilderPhase4RuntimeTest.php

#### `layout.container`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\LayoutContainerRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/LayoutContainerRenderer.php:24`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 308)
- Controls (3): `width` enum -> select [full|constrained|compact] def "constrained"; `alignment` enum -> select [left|center|right] def "center"; `padding` enum -> select [none|xs|sm|md|lg|xl|2xl] def "none"
- Notes: Container; wrapper classes only.
- Tests (11): canvasLiveSync.test.mjs, live-structure.test.mjs, wireframe.test.mjs, StudioBuilderSprint1AcceptanceTest.php, StudioBuilderSprint2AcceptanceTest.php, StudioBuilderSprint3AcceptanceTest.php, StudioBuilderSprint4AcceptanceTest.php, StudioBuilderLayerLockTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php, StudioBuilderSprint3NavigatorAndOperationsTest.php, StudioBuilderSprint4TemplatesTokensComponentsTest.php

#### `layout.flex`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FlexRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/FlexRenderer.php:26`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 325)
- Controls (5): `direction` enum -> select [row|column|row_reverse|column_reverse] def "row"; `wrap` enum -> select [nowrap|wrap|wrap_reverse] def "nowrap"; `justify` enum -> select [start|center|end|between|around|evenly] def "start"; `align` enum -> select [start|center|end|stretch|baseline] def "start"; `gap` enum -> select [none|xs|sm|md|lg|xl|2xl] def "md"
- Notes: Container; wrapper classes only. Same concepts (direction/wrap/justify/align/gap) also exist in style.layout with per-device support.
- Tests (5): element-manager.test.mjs, inspector-sections.test.mjs, prop-presenters.test.mjs, StudioBuilderElementManagerTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php

#### `layout.grid`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\GridRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/GridRenderer.php:23`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 344)
- Controls (3): `columns` number -> number [min=1; max=12; integer] def 2; `gap` enum -> select [none|xs|sm|md|lg|xl|2xl] def "md"; `align` enum -> select [start|center|end|stretch] def "stretch"
- Notes: Container; wrapper classes only (sb-cols-N, not responsive). style.layout.columns is the responsive equivalent.
- Tests (5): add-panel.test.mjs, inspector-sections.test.mjs, wireframe.test.mjs, StudioBuilderElementManagerTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php

#### `layout.offcanvas`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\OffcanvasRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/OffcanvasRenderer.php:20`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 539)
- Controls (5): `drawer_id` string -> text [max_length=64] def "drawer-1"; `title` string -> text [max_length=255] def "Menu"; `position` enum -> select [left|right|top|bottom] def "right" **partial: left/right styled; top/bottom have no .sb-offcanvas--top/--bottom CSS**; `trigger_text` string -> text [max_length=100] def "Open Menu"; `trigger_variant` enum -> select [primary|secondary|outline|ghost] def "outline"
- Notes: Container. Same dialog/focus-trap runtime as modal. top/bottom positions unstyled. Close aria-labels English (OffcanvasRenderer.php:51,55).
- Tests (2): StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php

#### `layout.section`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\SectionRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/SectionRenderer.php:26`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 289)
- Controls (5): `tag` enum -> select [section|header|footer|article|aside|div] def "section"; `content_width` enum -> select [boxed|full|narrow] def "boxed"; `min_height` enum -> select [auto|screen|half_screen|sm|md|lg|xl] def "auto"; `padding_y` enum -> select [none|xs|sm|md|lg|xl|2xl] def "md"; `background_token` token_ref -> token def null
- Notes: Container block (not the page section). tag/width/min_height/padding_y enums re-validated in renderer.
- Tests (3): StudioBuilderSprint1AcceptanceTest.php, StudioBuilderSprint2AcceptanceTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php

#### `membership.plans`

- Class: `Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\MembershipPlansRenderer` - `src/Module/StudioBuilder/Render/Block/ModuleRenderers/MembershipPlansRenderer.php:18`
- Schema: `src/Module/StudioBuilder/Registry/ModuleBlockDefinitions.php` (first field line 61)
- Controls (3): `heading` string -> text [max_length=200] def ""; `show_prices` boolean -> checkbox def true; `cta_label` string -> text [max_length=80] def ""
- Notes: Dynamic, entitlement membership, slot `items`. Same CatalogFormat escaping as booking.services.
- Tests (6): add-panel.test.mjs, canvasLiveSync.test.mjs, StudioBuilderPhase3ApplicationBoundaryTest.php, StudioBuilderPhase4RuntimeTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase5BuilderTest.php

#### `payment.button`

- Class: `Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\PaymentButtonRenderer` - `src/Module/StudioBuilder/Render/Block/ModuleRenderers/PaymentButtonRenderer.php:24`
- Schema: `src/Module/StudioBuilder/Registry/ModuleBlockDefinitions.php` (first field line 122)
- Controls (5): `label` string -> text [max_length=80] def "Pay now"; `amount` number -> number [min=0.5; max=999999] def 10; `currency` enum -> select [USD|EUR|GBP|CAD|AUD|CHF|SEK|NOK|DKK|PLN|CZK|NZD] def "USD"; `description` string -> text [max_length=120] def ""; `success_path` string -> text [max_length=120; pattern ~^(?:[A-Za-z0-9][A-Za-z0-9_\-]*(?:/[A-Za-z0-9][A-Za-z0-9_\-]*){...] def ""
- Notes: isDynamic=false although output depends on Stripe readiness and a server signature at render time (PaymentButtonRenderer.php stripeReady()); compiled pages keep the state they were compiled with until invalidation/max-age. Amount/currency/description/path read via PaymentOffer::fromProps.
- Tests (3): add-panel.test.mjs, canvasLiveSync.test.mjs, StudioBuilderPaymentBlocksTest.php

#### `payment.link`

- Class: `Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\PaymentLinkRenderer` - `src/Module/StudioBuilder/Render/Block/ModuleRenderers/PaymentLinkRenderer.php:23`
- Schema: `src/Module/StudioBuilder/Registry/ModuleBlockDefinitions.php` (first field line 138)
- Controls (2): `label` string -> text [max_length=80] def "Pay now"; `url` string -> text [max_length=600; pattern !^(?:https://(?:buy|checkout|donate|billing)\.stripe\.com/[A-Za...] def ""
- Notes: Static. URL must pass PaymentLinkUrl::isAllowed both on save (pattern) and render.
- Tests (3): add-panel.test.mjs, canvasLiveSync.test.mjs, StudioBuilderPaymentBlocksTest.php

#### `portfolio.experience_timeline`

- Class: `StudioFreelancerPortfolio::renderExperienceTimeline (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:404`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 174)
- Controls (4): `role` string -> text [max_length=120] def "Principal Product Designer & Engineer"; `company` string -> text [max_length=120]; `period` string -> text [max_length=60] def "2023 — Present"; `description` text -> textarea [max_length=500]
- Notes: SDK. Escaped.
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.eyebrow`

- Class: `StudioFreelancerPortfolio::renderEyebrow (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:302`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 96)
- Controls (2): `text` string -> text [max_length=100] def "// SELECTED WORK"; `anchor_id` string -> text [max_length=50] def ""
- Notes: SDK. anchor_id emitted as a wrapper-level id with no pattern and no RenderCollector::claimId de-duplication (StudioFreelancerPortfolio.php:306).
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.footer`

- Class: `StudioFreelancerPortfolio::renderFooter (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:274`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 68)
- Controls (3): `copyright` string -> text [max_length=200]; `status` string -> text [max_length=120] def "Available for Select Q4 Engagements"; `location` string -> text [max_length=120] def "San Francisco, CA · Remote Worldwide"
- Notes: SDK. Social links are hard-coded (https://github.com, linkedin, twitter, mailto:contact@<owner-domain>) with no control (StudioFreelancerPortfolio.php:286-289).
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.nav_header`

- Class: `StudioFreelancerPortfolio::renderNavHeader (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:239`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 51)
- Controls (5): `monogram` string -> text [max_length=10] def "ER"; `name` string -> text [max_length=100] def "Elena Rostova"; `role` string -> text [max_length=120] def "Principal Product Engineer"; `cta_label` string -> text [max_length=60] def "Book Intro ↗"; `cta_href` string -> text [max_length=255] def "#contact" **partial: used only when SLATE_URL is undefined; otherwise replaced by base/contact**
- Notes: SDK. Nav labels "Work/Services/Case Studies/Reviews/Contact" hard-coded English (StudioFreelancerPortfolio.php:264-268). cta_href only used when SLATE_URL is undefined (line 253).
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.project_card`

- Class: `StudioFreelancerPortfolio::renderProjectCard (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:326`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 125)
- Controls (9): `title` string -> text [max_length=120] def "Solaya Analytics Platform"; `category` string -> text [max_length=80] def "Fintech / SaaS Analytics"; `year` string -> text [max_length=20] def "2026"; `description` text -> textarea [max_length=600]; `image_url` url -> url; `metric` string -> text [max_length=60] def "+185% Daily Active Users"; `tags` string -> text [max_length=200]; `link_url` url -> url def "/case-studies"; `link_text` string -> text [max_length=60] def "View Case Study →"
- Notes: SDK. image_url/link_url are `url` type; default image hot-links images.unsplash.com.
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.rakib_showcase`

- Class: `StudioBuilderPortfolioWidgets::renderShowcase (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-builder/StudioBuilderPortfolioWidgets.php:59`
- Schema: `plugins/studio-builder/StudioBuilderPortfolioWidgets.php` (first field line 42)
- Controls (11): `title` string -> text **no (renderShowcase never reads it)**; `brand_text` string -> text def "Portfolio / 2026"; `first_name` string -> text def "rakib"; `last_name` string -> text def "hasan"; `role` text -> textarea def "Freelance developer\n& designer"; `vertical_text` string -> text def "Digital design • Development"; `location` text -> textarea def "Based in\nBangladesh"; `coordinates` text -> textarea def "23.8103° N\n90.4125° E"; `domain` string -> text def "<owner-domain>"; `domain_url` string -> text def "https://<owner-domain>"; `email` string -> text def "hello@<owner-domain>"
- Notes: SDK, untested. Interpolates props into a static HTML asset by exact str_replace (silently no-ops if the asset changes). domain_url is a `string` (not `url`) used as href (StudioBuilderPortfolioWidgets.php:51,119); email unvalidated in mailto: (lines 52,124,129); `title` never read; emits a `<link rel=stylesheet>` inside the block body (line 133); error text English (line 64); no max_length on any field.
- Tests (0): none

#### `portfolio.reassurance_badges`

- Class: `StudioFreelancerPortfolio::renderReassuranceBadges (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:313`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 110)
- Controls (3): `badge1` string -> text [max_length=80] def "Strict NDA Guaranteed"; `badge2` string -> text [max_length=80] def "Direct Reply Within 24h"; `badge3` string -> text [max_length=80] def "Senior Leadership Only"
- Notes: SDK. Three fixed badge strings, escaped.
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.service_card`

- Class: `StudioFreelancerPortfolio::renderServiceCard (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:421`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 190)
- Controls (4): `number` string -> text [max_length=10] def "01"; `title` string -> text [max_length=120] def "Product Design & Architecture"; `description` text -> textarea [max_length=500]; `deliverables` string -> text [max_length=400] def ""
- Notes: SDK. Comma list, escaped.
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.skill_grid`

- Class: `StudioFreelancerPortfolio::renderSkillGrid (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:388`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 160)
- Controls (2): `group_title` string -> text [max_length=80] def "Frontend & Web Engineering"; `skills` string -> text [max_length=300]
- Notes: SDK. Comma list, escaped.
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.stat_highlight`

- Class: `StudioFreelancerPortfolio::renderStatHighlight (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:377`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 146)
- Controls (2): `number` string -> text [max_length=40] def "9+"; `label` string -> text [max_length=120] def "Years Crafting Products"
- Notes: SDK. Escaped.
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.status_pill`

- Class: `StudioFreelancerPortfolio::renderStatusPill (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:294`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 83)
- Controls (1): `text` string -> text [max_length=120]
- Notes: SDK. Escaped text.
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `portfolio.testimonial_card`

- Class: `StudioFreelancerPortfolio::renderTestimonialCard (via Sdk\CustomWidgetRendererAdapter)` - `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php:449`
- Schema: `plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php` (first field line 206)
- Controls (5): `quote` text -> textarea [max_length=1000] def ""; `author` string -> text [max_length=120] def ""; `role` string -> text [max_length=120] def ""; `rating` number -> number [min=1; max=5] def 5; `avatar_url` string -> text [max_length=500] def ""
- Notes: SDK. avatar_url is a `string` (not `url`) used as img src (StudioFreelancerPortfolio.php:210); "✓ Verified Client" always shown, English (line 462); stars have no text alternative.
- Tests (2): StudioBuilderFreelancerPortfolioTest.php, StudioBuilderFreelancerPortfolioTest.php

#### `theme.archive_title`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\ArchiveTitleRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/ArchiveTitleRenderer.php:20`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 487)
- Controls (2): `level` enum -> select [h1|h2|h3] def "h1"; `title` string -> text [max_length=255] def ""
- Notes: Dynamic (context). Default titles "Search Results:", "Category:", "Tag:", "Author:", "Archive" hard-coded English (ArchiveTitleRenderer.php:43-51).
- Tests (3): StudioBuilderPhase4RuntimeTest.php, StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint6ThemeBuilderTest.php

#### `theme.field_value`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FieldValueRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/FieldValueRenderer.php:23`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 468)
- Controls (4): `field` string -> text [max_length=64; pattern /^([a-z0-9_]{1,64})?$/] def ""; `show_label` boolean -> checkbox def false; `fallback` string -> text [max_length=255] def ""; `tag` enum -> select [div|p|span|h2|h3|h4] def "div"
- Notes: Dynamic (page meta). Values escaped per meta type; URLs limited to /path or http(s). "Yes"/"No" and diagnostics English (FieldValueRenderer.php:54,84); image/gallery alt="" always.
- Tests (1): StudioBuilderFieldValueTest.php

#### `theme.post_content`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\PostContentRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/PostContentRenderer.php:18`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 433)
- Controls (1): `content` text -> textarea [max_length=50000] def "" **yes, OVERRIDES live content although labelled "Fallback"; emitted as raw HTML when it contains tags**
- Notes: Dynamic. Raw HTML output when content contains tags (PostContentRenderer.php:33) - no RichTextSanitizer. The "Fallback Content" prop wins over the live post content. Public placeholder "Lorem ipsum" when nothing is available (line 30).
- Tests (1): StudioBuilderSprint6ThemeBuilderTest.php

#### `theme.post_meta`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\PostMetaRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/PostMetaRenderer.php:18`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 449)
- Controls (4): `show_author` boolean -> checkbox def true; `show_date` boolean -> checkbox def true; `show_category` boolean -> checkbox def true; `separator` string -> text [max_length=10] def " • "
- Renderer inputs with no control: `author_name` (src/Module/StudioBuilder/Render/Block/CoreRenderers/PostMetaRenderer.php:33), `published_at` (src/Module/StudioBuilder/Render/Block/CoreRenderers/PostMetaRenderer.php:34), `category` (src/Module/StudioBuilder/Render/Block/CoreRenderers/PostMetaRenderer.php:35)
- Notes: Dynamic. "By " prefix English (PostMetaRenderer.php:39); date fallback is server date("M j, Y") (line 34).
- Tests (2): StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint6ThemeBuilderTest.php

#### `theme.post_title`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\PostTitleRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/PostTitleRenderer.php:21`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 415)
- Controls (3): `level` enum -> select [h1|h2|h3|h4|h5|h6] def "h1"; `align` enum -> select [left|center|right] def "left"; `text` string -> text [max_length=255] def "" **yes, but OVERRIDES the live post title although labelled "Fallback Title"**
- Renderer inputs with no control: `tag` (src/Module/StudioBuilder/Render/Block/CoreRenderers/PostTitleRenderer.php:31), `link_to_post` (src/Module/StudioBuilder/Render/Block/CoreRenderers/PostTitleRenderer.php:49)
- Notes: Dynamic. "Fallback Title" prop overrides the live title. link_to_post read with no UI.
- Tests (3): add-panel.test.mjs, StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint6ThemeBuilderTest.php

#### `theme.search_box`

- Class: `Slate\Module\StudioBuilder\Render\Block\CoreRenderers\SearchBoxRenderer` - `src/Module/StudioBuilder/Render/Block/CoreRenderers/SearchBoxRenderer.php:18`
- Schema: `src/Module/StudioBuilder/Registry/BlockRegistry.php` (first field line 504)
- Controls (2): `placeholder` string -> text [max_length=120] def "Search articles..."; `button_text` string -> text [max_length=60] def "Search"
- Notes: isDynamic=false but reads search_query from context. action="/search" hard-coded (SearchBoxRenderer.php:32), ignores install sub-path. Input has only a placeholder, no label/aria-label (line 34).
- Tests (2): StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint6ThemeBuilderTest.php

## 5. Findings

Severity:

- **High**: a security issue, or a visible control or feature that is broken on public pages.
- **Medium**: a dead or misleading control, a significant accessibility or localisation gap, or a correctness risk.
- **Low**: limited impact or edge cases.
- **Info**: design notes.

Every finding was checked against the source lines cited.

### 5.1 High

**F1 (High): Stored XSS through the `theme.post_content` "Fallback Content" field.**
- The prop is a `text` field (`BlockRegistry.php:433`, max 50 000 chars).
- Its only content filter is `FieldSchema::containsExecutableOrSqlFragment` (`FieldSchema.php:311-346`). That blocks `<script`, `javascript:`, `eval(` and similar, but not event-handler attributes. `<img src=x onerror=alert(1)>` passes.
- `PostContentRenderer.php:33` emits the value **unescaped** whenever it contains a tag: `strip_tags($content) === $content ? nl2br(Html::e($content)) : $content`. It never goes through `RichTextSanitizer`.
- The same raw path applies to provider data mapped onto `content` through a binding (`BlockRenderScope.php:67-81`; the block allows `content.posts`).
- Effect: anyone with `studio-builder.edit` can store script that runs for visitors and for admins previewing the page.
- Compare `core.rich_text`, which sanitises on render (`RichTextRenderer.php:13`).

**F2 (High): Border style "Solid", "Dotted" and "Double" have no effect on any block.**
- The Style tab offers `none|solid|dashed|dotted|double` (`StyleControls.jsx:475-484`; per-side copy at `SurfaceControls.jsx:387` uses `OPTIONS.borderStyle`, `styleSurface.mjs:23`).
- The validator accepts all five (`CanonicalDocumentSchema.php:297`).
- The renderer emits `sb-border-<style>` (`DocumentRenderer.php:426`).
- The base stylesheet defines only `.sb-border`, `.sb-border-2`, `.sb-border-4`, `.sb-border-none` and `.sb-border-dashed` (`StudioStylesheet.php:243`). Confirmed mechanically against `StudioStylesheet::css()` (r11/tools/css.php).
- A width plus colour with style "solid" therefore renders no border. Per-side styles, which go through the scoped rule, do work.
- The JavaScript mirror reproduces the same classes (`liveStyle.mjs:245`), so the canvas has the same result.

**F3 (High): Query Loop pagination is a hard-coded fake.**
- With `enable_pagination` (default `true`, `BlockRegistry.php:397`), the renderer always prints "Previous" (disabled), page "1" and links to `?page=2` (`QueryLoopRenderer.php:155-162`).
- It ignores the real page, the result count and `per_page`.
- The real page number is read from raw `$_GET['page']` (`:50`) only on the fallback path.

### 5.2 Medium

**F4 (Medium): `responsive.<device>.align` CSS is not media-scoped.**
- `DocumentRenderer.php:155-157` emits `sb-align-<device>-<value>`.
- `StudioStylesheet.php:245-247` defines `.sb-align-desktop-*`, `.sb-align-tablet-*` and `.sb-align-mobile-*` **outside any `@media`**. A tablet or mobile value therefore applies at every width, and the last one defined wins (mobile).
- `justify` and the `base|sm|md|lg` keys (allowed by `ALLOWED_RESPONSIVE_BREAKPOINTS`, `CanonicalDocumentSchema.php:161`) produce classes with no CSS.
- No editor control writes this path (the device-overrides UI writes only `hide`, `BlockInspector.jsx:209-233`). It is reachable through API, MCP or import.

**F5 (Medium): Off-canvas "Drawer Position" top and bottom are unstyled.**
- Allowed values: `BlockRegistry.php:541`.
- CSS: `StudioStylesheet.php:392-394` styles only `--right` and `--left`. The panel always sits full-height with no horizontal anchor.

**F6 (Medium): Tabs "Tab Position: bottom" is dead.**
- `BlockRegistry.php:673`; renderer adds `sb-tabs-block--bottom` (`TabsRenderer.php:56,93`).
- No rule for `.sb-tabs-block--*` exists in `StudioStylesheet::css()`.

**F7 (Medium): Several interaction triggers do nothing.**
- The Motion UI offers `scroll`, `click` and `load` (`ui/src/core/motion.mjs:29-37`).
- `sb-interaction-scroll` has no CSS and no runtime handler (no match in `StudioStylesheet.php` or `assets/public/studio-runtime.js`).
- `click` and `load` get only a `transition` declaration and no actual effect. The source comment admits this (`StudioStylesheet.php:297-300`).

**F8 (Medium): Block field labels, option labels and schema default copy are not localised.**
- `StudioApplicationService::translateBlockCopy` (`StudioApplicationService.php:1334-1348`) translates only the Add-panel `title` and `description`.
- `label`, every `field_schema[].label`, repeater item labels and English default content reach the Inspector raw:
  - `FieldControl.jsx:71-165` prints `field.label`
  - example literals: `BlockRegistry.php:119` "Eyebrow", `:980` "Menu name (for screen readers)"
  - SDK labels
- Motion option labels are English literals outside `t()` (`motion.mjs:16-37`, used at `MotionInspector.jsx:87,116,176`).

**F9 (Medium): The runtime overwrites the server's localised carousel labels with English.**
- `CarouselRenderer.php:90-122` uses `Html::t()` for dot, previous/next and "carousel" labels.
- `studio-runtime.js:871-875` clears the dots (`dotsHost.innerHTML=''`) and recreates them with `'Go to slide ' + (i+1)`.
- `:797` and `:800` hard-code `aria-roledescription` "carousel" and "slide".

**F10 (Medium): Hard-coded English strings in public output.** Renderers bypass `Html::t()` in these places:

| Where | String |
|---|---|
| `ArchiveTitleRenderer.php:43-51` | "Search Results:", "Category:", "Tag:", "Author:", "Archive" |
| `PostMetaRenderer.php:39` | "By " |
| `FieldValueRenderer.php:54,84` | diagnostics, "Yes"/"No" |
| `VideoRenderer.php:43,66` | "Video unavailable", iframe `title="Video"` |
| `ModalRenderer.php:51,55` / `OffcanvasRenderer.php:51,55` | `aria-label="Close"` |
| `QueryLoopRenderer.php:157` | `aria-label="Pagination"` |
| `TabsRenderer.php:46` | "Tab N" |
| `AccordionRenderer.php:78` | "Item N" |
| `GalleryRenderer.php:80` | "Image N" |
| `BookingServicesRenderer.php:51` / `BookingEmbedRenderer.php:77` | " min" |
| `CustomWidgetRendererAdapter.php:60` | "Error rendering widget" |
| `StudioFreelancerPortfolio.php:264-268,462` | navigation labels, "Verified Client" |
| `StudioBuilderPortfolioWidgets.php:64` | asset-missing error |

**F11 (Medium): "Fallback" props override the live data.**
- `theme.post_title` "Fallback Title" (`BlockRegistry.php:417`): `PostTitleRenderer.php:43-46` uses `prop('text')` first and the context title only when the prop is empty.
- `theme.post_content` "Fallback Content" (`BlockRegistry.php:433`): `PostContentRenderer.php:28-31` does the same.
- The labels promise the opposite of the behaviour.

**F12 (Medium): Two "hide on device" mechanisms with different vocabularies and breakpoints, side by side in the Responsive section.**
- `visibility.devices` uses `base|sm|md|lg` (cut-offs 640/768/1024, `StudioStylesheet.php:25-30`).
- `responsive.{desktop,tablet,mobile}.hide` uses cut-offs 768/1024 (`StudioStylesheet.php:439-441`).
- Both render in `BlockInspector.jsx:206-235`. Per-device style overrides use a third naming (`tablet`/`mobile`, `max-width:1023.98/767.98`, `StyleSurface.php:911`).

**F13 (Medium): Duplicate flex and grid controls for the same concept.**
- `layout.flex` props (`direction|wrap|justify|align|gap`, `BlockRegistry.php:325-329`), `layout.grid` props (`columns|gap|align`, `:344-346`) and `core.container` props (`:265-266`) emit utility classes (`FlexRenderer.php`, `GridRenderer.php`, `ContainerRenderer.php` `wrapperClasses`).
- For the same block, the Flex/Grid section writes `style.layout.direction|justify|align|gap|columns` with per-device support (`SurfaceControls.jsx:225-243`; `StyleSurface.php:51-98`).
- The two compete on one element. The scoped rule wins on specificity order, so the Content-tab value silently stops mattering.
- `layout.grid` columns are not responsive while `style.layout.columns` is.

**F14 (Medium): `payment.button` is static but depends on runtime state.**
- `isDynamic()` returns false (`PaymentButtonRenderer.php:29`). Output depends on `stripeReady()` (`:41`, `:60-63`) and on a `slate_sign` signature made at render time.
- Static nodes are baked into compiled artifacts. `StudioCompilationInvalidator.php` has no Stripe or settings dependency (it greps clean for "stripe").
- A page compiled before Stripe was configured keeps showing no button until the artifact ages out (`StudioCompiler::MAX_ARTIFACT_AGE_SECONDS = 3600`, `StudioCompiler.php:60`) or is invalidated.

**F15 (Medium): `theme.search_box` has no accessible name and a hard-coded endpoint.**
- The input has only a `placeholder`, with no `<label>` or `aria-label` (`SearchBoxRenderer.php:34`).
- `action="/search"` is absolute (`:32`), so the install sub-path or `SLATE_URL` is ignored.
- The block is static (`isDynamic` false) but reads `search_query` from context. See Not verified.

**F16 (Medium): Carousel accessibility gaps.**
- Server markup puts `role="tablist"` on a container of plain `<button>`s (`CarouselRenderer.php:104`). It is only valid after the runtime adds `role="tab"` (`studio-runtime.js:873`).
- There is no visible pause or stop control. Autoplay pauses on hover and focus only when `prefersHover()` (`studio-runtime.js:890-895`), so touch users cannot stop it (WCAG 2.2.2).
- Autoplay silently requires loop (`:883`; see F27).

**F17 (Medium): `core.form_field` output is not deterministic.**
- The id uses `random_bytes` (`FormFieldRenderer.php:41`), so every render of the same document differs.
- This defeats the content-addressed compile and the canvas's "same markup means no repaint" logic, and gives the `<label for>` a new target each render.

**F18 (Medium): `portfolio.rakib_showcase` has unchecked inputs and no tests.**
- `domain_url` is a `string`, not a `url` (`StudioBuilderPortfolioWidgets.php:51`), and is written into `href` after `htmlspecialchars` only (`:84,119`). Only the generic executable filter stands in for `isSafeUrl`.
- `email` goes into `mailto:` unvalidated (`:52,124,129`). No field has `max_length`.
- `title` (`:42`) is never read.
- Interpolation is exact-string `str_replace` against a static asset, which silently no-ops if the asset changes (`:87-131`).
- The block emits a `<link rel=stylesheet>` inside its body (`:133`).
- No test references the type id.

**F19 (Medium): Theme single-post blocks get no post data on the public path.**
- Partly verified. `PostContentRenderer` reads context `content` / `post_content` and falls back to "Lorem ipsum…" (`PostContentRenderer.php:30`).
- The only producers of context attributes found are:
  - `StudioPublicRuntime.php:114,127-132,145-148`: 404, archive and search only
  - `TemplatePreviewContext.php:69-73`: single, preview only, and it sets `post_title`, `post_url` and `category` but not `content`
- So the public body is the "Fallback Content" or Lorem ipsum, and `theme.post_title` shows "Single Post Title" unless a prop is set.

**F20 (Medium): The Query Loop fallback depends on a global.**
- Without a binding row set, the loop calls `PostsProvider` only when `$GLOBALS['SLATE_TENANT_OVERRIDE']` is set (`QueryLoopRenderer.php:53`). Otherwise it shows the empty message.
- It also reads `$_GET['page']` directly inside a renderer (`:50`).
- `gap` and `card_variant` are escaped but not re-validated (`:151`, and the card class at `:138`).

**F28 (Medium): The `portfolio.footer` widget hard-codes links with no controls.**
- It publishes `https://github.com`, LinkedIn, Twitter and **`mailto:contact@<owner-domain>`** on every site that uses it (`StudioFreelancerPortfolio.php:286-289`).
- `portfolio.nav_header` `cta_href` is used only when `SLATE_URL` is undefined (`:253`). In a normal install it is a dead control.

### 5.3 Low

| ID | Finding | Evidence |
|---|---|---|
| F21 | `core.form` "Action URL" is a `string`, not a `url`, so it gets no `isSafeUrl` check and form data can post to any host. The block has no CSRF token and no server handler, and `submit_variant` is not re-validated. | `BlockRegistry.php:558`; `FormRenderer.php:34` |
| F22 | Renderer inputs with no schema entry. FieldSchema rejects unknown props (`FieldSchema.php:144-154`), so these are reachable only through binding mappings or legacy documents, and are dead code otherwise. | `HeadingRenderer.php:37,41,45` (size, align, color_token); `ButtonRenderer.php:33-45` (url, text, target); `PostTitleRenderer.php:31,49` (tag, link_to_post); `PostMetaRenderer.php:33-35`; `CarouselRenderer.php:111` (options.label); `GalleryRenderer.php:63` (src) |
| F23 | Style values that are accepted but never rendered: (a) the `style.spacing` key (`CanonicalDocumentSchema.php:281`, validated at `StyleValueGuard.php:251`) has no emitter and no UI; (b) a `typography.size` breakpoint map is validated (`DocumentValidator.php:1084-1096`) but only a string is emitted (`DocumentRenderer.php:564-566`); (c) `typography.weight` 100-900 is accepted but only named weights have CSS; (d) `transform:"none"` becomes `sb-none` with no CSS (`StudioStylesheet.php:238` defines `sb-normal-case`); (e) `responsive.desktop.style` is accepted but only tablet and mobile are emitted (`StyleSurface.php:911,955`). | as cited |
| F24 | Query Loop "Card Variant" minimal and horizontal have no CSS. | `BlockRegistry.php:390`; no `.sb-post-card--*` in `StudioStylesheet::css()` |
| F25 | `core.video`: `controls` is ignored for Vimeo; `.ogg` passes `buildEmbedUrl` (`VideoRenderer.php:127`) but only `.mp4`/`.webm` get `<video>` (`:48`), so `.ogg` lands in an iframe; the iframe title is fixed (`:66`); the schema default is a specific YouTube video id. | `BlockRegistry.php:629` |
| F26 | `core.form_field` "radio" renders one option-less radio and ignores `options` (`FormFieldRenderer.php:45-52`; options are used only for select at `:65`); `help_text` is not tied to the field with `aria-describedby` (`:82`). | as cited |
| F27 | Carousel `autoplay_ms` is dead when `loop=false` (the runtime returns early). | `studio-runtime.js:883` |
| F29 | `portfolio.testimonial_card` `avatar_url` is a `string` used as `img src` (`StudioFreelancerPortfolio.php:210`); "Verified Client" is always displayed (`:462`); `portfolio.eyebrow` `anchor_id` has no pattern and skips `RenderCollector::claimId`, so duplicate ids are possible (`:306`). | as cited |
| F30 | Modal and off-canvas ids (`modal_id`/`drawer_id`) are not de-duplicated through `claimId`; `trigger_variant` is not re-validated (class filtered by `Html::classAttr`). | `ModalRenderer.php:43`; `OffcanvasRenderer.php:43` |
| F31 | The token picker chooses categories by regular expression on the field key; `layout.section.background_token` matches none of them, so every category (space, radius, font…) is offered for a background. | `FieldControl.jsx:135` |
| F32 | `WidgetRegistry::withCoreWidgets()` duplicates seven definitions and has drifted: its `core.heading` has no highlight fields and its inspector names an `align` control that is not in the schema. Only `StudioBuilderSprint1LayoutAndWidgetsTest.php:67` uses it. | `WidgetRegistry.php:137-389` (heading at `:279-300`) |
| F33 | The canvas text patcher targets a non-existent `core.paragraph` and assumes prop `text` for `core.text` (real prop `content`) and `core.button` (real `link.label`), so those patches always fall back to a reload. | `ui/src/core/canvasPatch.mjs:49` |
| F34 | FieldSchema `pattern` is not exported to the editor manifest, so the Inspector cannot pre-validate countdown target, nav menu key, payment path or Stripe URL; the author sees a save-time error instead. | `FieldSchema.php:224` |
| F35 | Fixed heading levels break page outline control: accordion `<h3>` (`AccordionRenderer.php:67`), modal and off-canvas `<h3>` (`ModalRenderer.php:54`, `OffcanvasRenderer.php:54`), hero `<h1>` (`HeroRenderer.php:38`). | as cited |
| F36 | Public placeholders: the gallery shows grey "Image N" tiles with inline styles when empty (`GalleryRenderer.php:77-82`); post content shows Lorem ipsum (`PostContentRenderer.php:30`); post meta shows "Author" and today's server date (`PostMetaRenderer.php:33-34`). | as cited |
| F37 | Rating accessibility: `acme.testimonial` puts `aria-label` on a role-less `div` (`StudioTestimonialWidget.php:55`); the star glyphs in `portfolio.testimonial_card` (`StudioFreelancerPortfolio.php:457`) have no text alternative. | as cited |
| F38 | Number formatting is not locale-aware: stats and prices use fixed `.`/`,` separators. | `StatsRenderer.php:67`; `CatalogFormat.php:21` |

### 5.4 Info

| ID | Note | Evidence |
|---|---|---|
| F39 | Dynamic-data bindings for nine types (heading, text, button, image, rich_text, theme.*) work server-side but have no editor UI: no `binding_slots`, so the Data section is hidden, and `BindingsEditor` has no mapping control. Mapped values bypass FieldSchema validation. | `inspectorSections.mjs:79`; `BlockRegistry.php:156`; `DocumentValidator.php:1310-1322` |
| F40 | SDK widget HTML is trusted: `CustomWidgetRendererAdapter::render` returns the plugin string as-is. Escaping is each plugin's job. The three plugins audited use `Html::e`/`htmlspecialchars` on every prop. | `CustomWidgetRendererAdapter.php:50-64` |
| F41 | Style preview is a duplicate implementation (`liveStyle.mjs`, `styleSurface.mjs`) kept equal to PHP by fixture parity tests, not shared code. It is correct by construction only for what the fixtures cover. | section 3.2 |
| F42 | The editor caps transition duration at 2000 ms; the server allows 4000. | `SurfaceControls.jsx:361`; `StyleSurface.php:657` |
| F43 | `core.card` `surface_token` and `core.text` / `core.heading` (renderer) `color_token` duplicate the Style-tab Background and Text colour token controls, giving two controls for one concept. | `BlockRegistry.php:886`, `:364` |

Accessibility done well (no finding):

- Tabs: APG keyboard model (arrows, Home, End), `studio-runtime.js:690-693`.
- Accordion: `aria-expanded`, `aria-controls` and `hidden`.
- Modal and off-canvas: focus trap, Escape and focus return, `studio-runtime.js:314-360`.
- Countdown: `role=timer`.
- Icon: decorative by default, with an optional label.
- Map: iframe title.
- Spacer: `aria-hidden`.
- Repeater controls: labelled buttons.

## 6. Disagreements with `audit/kohevo-widget-test-matrix.md`

- **No disagreement on the facts:**
  - the 60 ids
  - the class names
  - the per-type test-file counts, when fixtures are excluded as the matrix does. With fixtures, most core types gain one more file, `ui/tests/fixtures/manifest.json`.
- **"Status: Implemented" is too generous.** The matrix defines it as "a renderer exists and at least one test references the id". Under that definition it marks as implemented:
  - `core.query_loop`, whose pagination is fake (F3)
  - `theme.post_content`, which has an XSS path (F1) and no live data (F19)
  - `core.tabs` and `layout.offcanvas`, which have dead options (F5, F6)
- **Single-test types.** It counts `core.table`, `core.tabs`, `core.carousel`, `core.stats`, `theme.field_value` and `theme.post_content` at 1 test file each. Those single files do not exercise the dead options above. Spot-checked by grep: no test mentions `sb-tabs-block--bottom`, `sb-offcanvas--top`, or `?page=` pagination behaviour.
- **Its "Not verified" list** says per-block sanitisation was not re-audited. Section 4 and findings F1, F18, F21 and F29 cover that gap.

## 7. Not verified

- Visual behaviour in a browser (no Playwright or e2e run), including whether F13's competing classes and scoped rules resolve exactly as described for every combination.
- Whether `theme.search_box` (static) bakes a reflected `search_query` into a compiled artifact shared across searches (`StudioCompiler` cache key versus `dynamicContext`). The value is escaped, so this is not XSS, but it could show another visitor's query.
- Whether any non-Studio path (theme or chrome routing for single posts) supplies `content` or `post_content` to `theme.post_content` (F19 is based on a grep of `src/` and `plugins/studio-builder`).
- `PostsProvider` validation of `order_by`, `source`, `category` and `tag` (passed straight from props, `QueryLoopRenderer.php:43-50`).
- Whether the posts module sanitises post HTML before it would reach F1's raw path through a binding.
- `RichTextSanitizer` and `FieldSchema::validateRichText` robustness (read, not fuzzed).
- How `core.form` submissions are handled, if at all.
- CSP compatibility of inline `<style>` (freelancer portfolio `renderStylesOnce`), inline `style=""` attributes and the booking embed `<script>`.
- The static `$stylesInjected` flag in `StudioFreelancerPortfolio::renderStylesOnce` with compiled or deferred rendering: CSS could be missing from a fragment or a compiled section.
- AI and MCP paths (`StudioBuilderMcpHandler.php`, `admin/ai-mcp.php`) that may write `responsive.*.align` or binding mappings (F4, F39).
- The `i18n-parity` coverage of `messages.mjs` across locales.
