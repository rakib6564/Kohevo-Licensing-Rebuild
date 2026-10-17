# Kohevo Studio Builder: containers and layout audit (r11, Part 1)

Read-only, source-driven audit. Nothing was run, edited or committed.

- Tree: `wt-final/01-client` (git worktree, HEAD `71d4e83`)
- Paths below are relative to that tree.
- Line numbers are from that tree.

## 0. Summary

| Severity | Count |
|---|---|
| High | 4 |
| Medium | 12 |
| Low | 9 |
| Info | 7 |

Container types found: 5 layout block types plus 1 structural document node.
- `core.container`
- `layout.section`
- `layout.container`
- `layout.flex`
- `layout.grid`
- the document **section** (`section.layout`). It is not a block, but it is the outermost layout box.

Several other blocks also hold children (`allowsChildren: true`): `core.card`, `core.modal`, `core.form`, `core.query_loop`, `layout.offcanvas`, `theme.search_box` and `core.link` (BlockRegistry.php:373-888).

Main conclusions:

1. **One renderer, one stylesheet, one compiler for editor, preview and public.** The editor canvas is the server's Editor-mode render. The client predicts style edits and is pinned to server-generated fixtures. Divergence comes only from editor overlay CSS and the client live-sync shortcuts (section 4).
2. **Up to 4 competing sources for the same layout value** (section 6):
   - block props, which become utility classes
   - `style.layout`, which becomes a scoped rule
   - inline `style.dimensions`
   - per-device overrides, which use `!important`

   On top of those come section/page widths and site tokens.
3. **The page "Width" setting (`settings.container_width`) does nothing.** The base stylesheet has no rule for `.sb-main--*`. The Site settings hint even tells authors that it controls page width.
4. **Width, height and min/max sizes cannot vary per device.** They are also written inline, so neither device overrides nor class-level custom CSS can beat them.
5. **There are 3 separate responsive models and 3 hide systems.** `responsive.<device>.align` classes are emitted without any media query, so the last-defined device always wins at every width.

---

## 1. The container model

### 1.1 Document section (outer box)

- **Render:** `DocumentRenderer::renderSection` (DocumentRenderer.php:235-290).
  - Output: `<section class="sb-section …"><div class="sb-section__inner sb-w-{width} sb-gap-{gap} sb-{bp-}cols-N">`.
- **Inner box CSS:** `.sb-section__inner{margin:0 auto;padding-left:1rem;padding-right:1rem;display:grid}` and `.sb-section__inner>*{min-width:0}` (StudioStylesheet.php:53-54).
  - The section's direct blocks are always grid items.
- **Width:** `layout.width` takes `narrow|normal|wide|full` (CanonicalDocumentSchema.php:82). It produces `sb-w-*`, which is `max-width:var(--sb-layout-{name},fallback)`; `full` gives `none` (StudioStylesheet.php:420-424). Fallbacks: 42rem, 64rem, 80rem.
- **Columns:** `layout.columns` is a mobile-first breakpoint map (`base|sm|md|lg`, 1-12).
  - It is capped at the block count: `min($cols, $blockCount)` (DocumentRenderer.php:278-284).
  - It produces `sb-cols-N` / `sb-md-cols-N` (StudioStylesheet.php:432-435, 451-464).
- **Gap:** `layout.gap` uses the spacing scale and produces `sb-gap-*` (StudioStylesheet.php:425-427). Values: none 0, xs .25rem, sm .5rem, md 1rem, lg 1.5rem, xl 2rem, 2xl 3rem (StudioStylesheet.php:33).
- **Vertical padding:** `layout.padding_y` is a breakpoint map that produces `sb-{bp-}py-*` (DocumentRenderer.php:263-268). Values: 0 / .5 / 1 / 2 / 4 / 6 / 8rem (StudioStylesheet.php:32).
- **Background:** `layout.background_token` produces a token class `sb-bg--surface-primary` (DocumentRenderer.php:262; RenderCollector.php:54-62).
- **Optional section style:** `section.style` takes `{background, padding}` and becomes a scoped rule (StyleSurface.php:680-737; DocumentRenderer.php:257-258).
- **Default layout:** `{background_token: surface.primary, columns: {base:1, md:12}, gap: md, padding_y: {base: md, md: lg}, width: wide}`.
  - Server: CanonicalDocumentSchema.php:379-388.
  - Client mirror: operations.mjs:82-83.
- **Inspector:** SectionInspector.jsx:107-157.
  - Layout: width, gap, columns, padding_y, background token.
  - Background: colour and image.
  - Advanced: padding.
- **No per-device style.** Sections have no `responsive` key (CanonicalDocumentSchema.php:105-121), so section padding and background cannot vary per device. Only the breakpoint maps above vary.

### 1.2 Block containers (the block's own wrapper element is the box)

Every block renders as `<{tag} class="sb-block sb-block--{type} {wrapperClasses} {scoped sb-x-…} …" style="…">` (DocumentRenderer.php:171-204). Container renderers return only `childrenHtml()`. Their layout comes from `wrapperClasses()`:

| Type | Props → classes | CSS | File |
|---|---|---|---|
| `core.container` | `direction` vertical/horizontal, `gap` | `.sb-stack{display:flex}`, `--vertical{flex-direction:column}`, `--horizontal{flex-direction:row;flex-wrap:wrap}` | ContainerRenderer.php:33-41; StudioStylesheet.php:151 |
| `layout.flex` | direction, wrap, justify, align, gap | `.sb-flex*` | FlexRenderer.php:40-77; StudioStylesheet.php:183-201 |
| `layout.grid` | columns 1-12, align, gap | `.sb-grid{display:grid}`, `sb-cols-N`, `.sb-grid--align-*` | GridRenderer.php:37-62; StudioStylesheet.php:202-206 |
| `layout.container` | width full/constrained/compact, alignment, padding | `.sb-container{width:100%}`, `--constrained{max-width:75rem}`, `--compact{48rem}`, `--full{100%}`, `.sb-pad-*` | LayoutContainerRenderer.php:38-65; StudioStylesheet.php:176-182 |
| `layout.section` (block) | tag, content_width boxed/full/narrow, min_height, padding_y, background_token | `.sb-layout-section--boxed{max-width:80rem}`, `--narrow{48rem}`, min-height 16-48rem / 50vh / 100vh | SectionRenderer.php:34-77; StudioStylesheet.php:166-175 |

The B2-P3b **style surface** applies to any block. It is one closed table and server-formatted (StyleSurface.php:51-88, 100-141):

- **Layout:** `display` (block, flex, grid, inline-block, inline-flex), `direction`, `wrap`, `justify`, `align`, `align_content`, `gap`, `row_gap`, `column_gap`.
- **Item:** `order`, `grow`, `shrink`, `basis`, `align_self`.
- **Grid:** `columns` and `rows`, each `repeat(N,minmax(0,1fr))`. These are equal tracks only, with no custom templates and no spans.
- **Position:** static, relative, absolute, sticky. `fixed` is deliberately absent (StyleSurface.php:19).
- **Spacing:** per-side `margin` (`auto` allowed, negative allowed) and per-side `padding` (no negatives, `padlen`).
- **Dimension extras:** `min_width`, `max_height`, `aspect_ratio`, `overflow`, `object_fit`, `object_position`.

Emission order is fixed by the table, not the document (StyleSurface.php:583-600). Rules are content-addressed classes `sb-x-<sha16>` (RenderCollector.php:106-140).

**The original dimensions are not part of the style surface.** `width`, `height`, `min_height` and `max_width` are written as an **inline `style` attribute** (DocumentRenderer.php:619-626). Their validator is DocumentValidator.php:1202-1213.

**Inspector exposure:**
- Content tab:
  - the container's props, through icon presenters (propPresenters.mjs:5-28)
  - Layout ("Flex & grid", LayoutPane, SurfaceControls.jsx:265-291)
- Style tab, Dimensions section:
  - width, min-height, max-width (StyleControls.jsx:554-581)
  - min-width, max-height, aspect, overflow, object-fit/position (SurfaceControls.jsx:430-437)
- Advanced tab: margin/padding (BlockInspector.jsx:237-240) and Position (SurfaceControls.jsx:311-329).
- `dimensions.height` is validated and rendered but has **no** UI control.

### 1.3 Behaviour matrix

| Property | Where it can be set | CSS output |
|---|---|---|
| display | `style.layout.display`; default flex/grid by type in the inspector only (BlockInspector.jsx:36) | scoped |
| direction | props (flex, container) **and** `style.layout.direction` | class **and** scoped |
| wrap | props (flex) **and** `style.layout.wrap`; core.container horizontal forces `wrap` | class **and** scoped |
| justify / align | props (flex, grid align) **and** `style.layout.*` | class **and** scoped |
| gap | props (spacing scale) **and** `style.layout.gap/row_gap/column_gap` (free length) | class **and** scoped |
| padding | `layout.container.padding` prop, `style.padding.*`, `spacing_token`, section `padding_y`, `section.style.padding` | class / scoped / token class |
| margin | `style.margin.*` | scoped |
| width / max-width | `style.dimensions.width/max_width` (inline); section `layout.width`; `layout.container.width`; `layout.section.content_width`; page `settings.container_width` (inert) | inline / class |
| min/max-width extras | `style.dimensions.min_width` | scoped |
| height / min-height / max-height | `dimensions.height`, `min_height` (inline); `max_height` (scoped); `layout.section.min_height` (class) | mixed |
| overflow | `style.dimensions.overflow` | scoped |
| columns | section `layout.columns` (responsive map); grid `columns` prop (not responsive); `style.layout.columns` (device-overridable) | class / scoped |

---

## 2. Nesting, drag-and-drop, selection, duplication, clipboard, undo/redo

### Nesting
- `MAX_NESTING_DEPTH = 6` (CanonicalDocumentSchema.php:22). It is enforced by:
  - the validator (DocumentValidator.php:55)
  - the render preparer (RenderDocumentPreparer.php:115)
  - the client `canContain` (doc.mjs:97-108)
  - clipboard paste (clipboard.mjs:205-209)
- The manifest passes the limit to the client (StudioApplicationService.php:1452).

### Drag and drop
- **Layers panel:** pointer and HTML5 drag. Geometry is in outlineDrag.mjs:9-16 (Outline.jsx:250, 287, 364). Containers take "inside" on the 30-70% band.
  - Unit tests: tests/outline-drag.test.mjs (4 tests).
  - e2e: e2e/reorder.spec.mjs (reorder, lock, cross-section, touch).
- **Canvas:** drop target only (canvas.mjs:345-400 → CanvasArea.jsx:306-336). Dragging *from* the canvas is blocked (`dragstart` is prevented, canvas.mjs:406). Moving a node starts from Layers or the palette.
- **Defect:** see F-L6. The canvas decides "container" by `type.startsWith('layout.')` or a `<section>` tag (canvas.mjs:352).

### Selection
- Immutable model with primary, multi-select, shift ranges and actions on the top-level selection only (selection.mjs:1-60).
  - Unit tests: tests/selection.test.mjs (12 tests).
  - e2e: e2e/selection.spec.mjs (multi-select, Escape, foreign-node refusal, nearest owned node).

### Duplication
- Operations `duplicate_section` / `duplicate_block` are applied server-side (DocumentOperationApplier.php:71, 79). The client triggers them from StudioShell.jsx:320-325 and 631.
- Tests: tests/unit/StudioBuilderSprint3NavigatorAndOperationsTest.php and tests/integration/StudioBuilderSprint3AcceptanceTest.php.

### Clipboard
- Cross-page JSON envelope (clipboard.mjs).
  - Ids are dropped and new ones minted.
  - Keys are allow-listed and prototype keys stripped.
  - Size, node and depth caps apply: 256 KiB, 600 nodes, depth 12.
  - Paste goes through `planPaste`, then the server.
- Tests: tests/clipboard.test.mjs (12 tests, including the nesting limit).

### Undo/redo
- Server rollbacks to immutable revisions, holding at most 50 entries (sync.mjs:23-37, 308-348). No documents are stored in the browser.
- Tests: tests/sync.test.mjs:168-198.
- Granularity is the confirmed autosave batch, not each keystroke (sync.mjs:230-232).

---

## 3. Responsive values

There are **3 separate responsive mechanisms**:

| Model | Storage | Cascade | Emission |
|---|---|---|---|
| A. Breakpoint maps (mobile-first) | `section.layout.columns`, `section.layout.padding_y`, `style.align` as `{base, sm, md, lg}` | min-width cascade 640/768/1024 | prefixed utility classes inside `@media (min-width)` (StudioStylesheet.php:23, 432-435; DocumentRenderer.php:263-284, 395-406) |
| B. Device overrides (desktop-first) | `block.responsive.{tablet,mobile}.style`, limited to `typography.size`, `padding.*`, `margin.*` and `layout.*` except display (StyleSurface.php:415-427) | desktop = base style; tablet `(max-width:1023.98px)` also applies on mobile; mobile `(max-width:767.98px)` is emitted later, so it wins (StyleSurface.php:412) | inside the block's scoped rule as `@media q{.sb-x{… !important}}` (StyleSurface.php:456-476; RenderCollector.php:131-136) |
| C. Device flags | `block.responsive.{desktop,tablet,mobile,base,sm,md,lg}.hide` / `.align` | none | classes `sb-hide-{device}` (with queries, StudioStylesheet.php:436-441) and `sb-align-{device}-{v}` (**no queries**, StudioStylesheet.php:245-247) |

Visibility is a 4th variant: `visibility.devices` (base/sm/md/lg) produces `sb-hide-{bp}` (DocumentRenderer.php:647-660).

### Editor handling
- DeviceStyle.jsx wraps control groups. The device switch is the top-bar viewport (DeviceStyle.jsx:19-27).
- Tablet and mobile show **only their own values** (responsiveStyle.mjs:30-37). The inherited value is not shown.
- Viewports: desktop 1280, tablet 820, mobile 390 (viewport.mjs:12-16). There is no `sm` (640-767) preset.
- The canvas iframe width drives the same media queries, so device overrides display correctly in the canvas.

### Tests
- tests/responsive-style.test.mjs (6 tests)
- tests/unit/StudioBuilderStyleSurfaceTest.php:570-640 (accept/refuse matrix, hide/align classes still emitted)
- e2e/flex-container.spec.mjs:44 (flex direction differs on phone)
- e2e/device-switcher.spec.mjs

---

## 4. Editor vs frontend parity

### Same path (verified)
- Editor canvas: `admin/canvas.php:51` → `StudioApplicationService::renderForEditor` (StudioApplicationService.php:1174) → `StudioRenderService::renderRevision` (StudioRenderService.php:65-74).
- Public: `renderPublished` (StudioRenderService.php:80-103).
- Both call `StudioCompiler::compile` (StudioCompiler.php:84-131).
  - That uses the same `DocumentRenderer`, `StudioStylesheet::css()`, `ResolvedTheme::rootCss()` and `RenderCollector::css()` (StudioCompiler.php:103).
  - Then `PageDocumentAssembler::assemble`.
- Tenant custom CSS is emitted in **all** modes. The Editor adds a guard (StudioCodePolicy.php:423-430, 376-380).

### Client prediction
- `core/liveStyle.mjs` re-implements `StyleSurface::declarations`, `buildInlineStyles`, token classes and `responsiveRules` (liveStyle.mjs:1-10, 47, 274-314).
- It is pinned against `tests/fixtures/live-style.json`, which PHP writes:
  - tests/live-style.test.mjs
  - tests/unit/StudioBuilderLiveStyleParityTest.php
- An unknown case returns `covered:false` and the server repaints.

### Divergences found
- Editor overlay CSS (canvas.mjs:18-62): `.sbx-selected{position:relative !important; box-shadow:… !important}`, empty containers `min-height:160px`, and drop-inside `background … !important`. See F-L7.
- `canvasLiveSync` alignment shortcut:
  - adds class names the stylesheet does not define (`sb-align-{bp}-{v}`)
  - adds `sb-heading--align-*` / `sb-text--align-*` to any block
  - sets inline `text-align` from `align[activeBreakpoint] || align.base`, without the mobile-first cascade
  - (canvasLiveSync.mjs:398-416). See F-L12.
- The interaction runtime script is not loaded in the Editor (PageDocumentAssembler.php:100-106), by design.

---

## 5. Legacy section/column compatibility

- Schema 1.0 **is** a section → blocks model. Columns are equal-width grid tracks on the section inner, with no per-block spans (DocumentRenderer.php:275-284). This is the only "column" concept. There is no column node.
- `LegacyDocumentConverter` converts v1/v2 envelopes but is **not wired** into any runtime path. Its only reference is tests/unit/StudioBuilderPhase2DocumentDomainTest.php.
  - It replaces any legacy layout with `defaultSectionLayout()` (LegacyDocumentConverter.php:94). Legacy widths and columns are dropped. See F-L15.
- `style.spacing` (legacy key) is validated (DocumentValidator.php:1144-1155; StyleValueGuard.php:251) but **never rendered**. Neither `buildInlineStyles` nor `StyleSurface` read it. See F-L13.
- `WidgetRegistry::withCoreWidgets()` holds a second, parallel definition of `layout.section` and other blocks (WidgetRegistry.php:135-175). The render runtime does not use it; the only reference is an optional parameter in WidgetSdk.php:114. See F-L14.

---

## 6. Competing sources of the same layout value

### 6.1 Observed cascade (what actually wins)

Each rule set below is in document order. Equal-specificity rules are resolved by order.

1. **Base utilities:** `StudioStylesheet::css()`. This includes the classes produced by container **props** (`sb-flex--row`, `sb-gap-md`, `sb-cols-3`, `sb-w-wide`, `sb-container--constrained`, `sb-layout-section--boxed`, `sb-py-*`) (StudioCompiler.php:103).
2. **Theme custom properties:** `:root{--sb-…}`.
3. **Collector rules.** Keys are sorted, and scoped keys are prefixed `~`, so they always come last (RenderCollector.php:138, 165-169):
   1. token classes: `sb-bg--*`, `sb-pad--*`, `sb-rad--*`, and so on
   2. scoped `sb-x-*` rules: `style.layout/margin/padding/position/dimension extras`, section style, and device `@media` blocks with `!important`
4. **Tenant custom CSS** `studio_code_custom_css` (PageDocumentAssembler.php:71).
5. **Inline `style=""`:** `dimensions.width/height/min_height/max_width`, literal colour/background/border/radius/shadow, opacity, z-index, literal typography (DocumentRenderer.php:546-641).

`!important` exceptions:
- background image: `background-image/size/repeat/position` (StyleSurface.php:216-227)
- device overrides (StyleSurface.php:473)
- `sb-ty-*` inheritance (StudioStylesheet.php:479-496)
- `sb-hide-*`

### 6.2 Conflict list

| # | Value | Competing sources (evidence) | Winner at runtime |
|---|---|---|---|
| C1 | Page content width | `settings.container_width` → `.sb-main--{w}` (StudioCompiler.php:99-102), but no CSS exists for `.sb-main--*` (StudioStylesheet.php:52, only `.sb-main{display:block}`); section `layout.width` → `sb-w-*` (DocumentRenderer.php:271-273); site tokens `layout.narrow/normal/wide` (ThemeResolver.php:45-47) | **Section `layout.width`**, sized by the site `layout.*` token. The page setting is inert, unless a template stylesheet such as assets/portfolio/portfolio.css:152-190 defines `.sb-main--*`. |
| C2 | Max width of a nested container | section inner `sb-w-*` (token, 42/64/80rem) vs `layout.section` boxed/narrow (hard 80/48rem) vs `layout.container` constrained/compact (hard 75/48rem) vs inline `dimensions.max_width` | The **narrowest** applies, because they nest. Inline `max_width` overrides the block's own class. Only `sb-w-*` follows Site settings. A "Full" `layout.section` is still capped by its parent section width and the 1rem inner padding (StudioStylesheet.php:53, 168). |
| C3 | Flex direction / wrap / justify / align | `layout.flex` props → `sb-flex--*` classes (FlexRenderer.php:67-74) vs `style.layout.*` → scoped rule (StyleSurface.php:55-60); both editable in the same Content tab (propPresenters.mjs:6-11; SurfaceControls.jsx:219-231) | **`style.layout.*`**: same specificity, later in source. The prop control keeps showing its own value while the visible layout ignores it. `core.container` `direction` (ContainerRenderer.php:39) vs `style.layout.direction` behaves the same way. |
| C4 | Gap | prop `gap` (spacing scale, `sb-gap-*`) on container/flex/grid vs `style.layout.gap/row_gap/column_gap` (free length) vs section `layout.gap` (applies only to the section inner) | **`style.layout.gap`** for the block. The prop is overridden silently. |
| C5 | Grid columns | `layout.grid` prop `columns` → unprefixed `sb-cols-N`, applied at **every** width (GridRenderer.php:39-58) vs `style.layout.columns` (scoped, device-overridable) vs section `layout.columns` (breakpoint map, capped at block count) | **`style.layout.columns`** when set. Otherwise the prop applies at every width, with no mobile collapse. |
| C6 | Block padding | `layout.container.padding` → `sb-pad-*` (LayoutContainerRenderer.php:50-62) vs `spacing_token` → `sb-pad--space-*` (token class) vs `style.padding.*` (scoped) vs device override (`!important`) | Device override, then `style.padding`, then token class, then prop class. `tokenStyle.setSpacingToken` deletes the literal padding when a token is picked (tokenStyle.mjs:59-63). The **prop** is not cleared. |
| C7 | Section vertical padding | `layout.padding_y` (breakpoint classes) vs `section.style.padding.top/bottom` (scoped) | **`section.style.padding`** at all breakpoints. It is later in source than the `@media (min-width)` utility classes. |
| C8 | Section background | `layout.background_token` (token class, default `surface.primary`, CanonicalDocumentSchema.php:382) vs `section.style.background.color/image` (scoped; the image uses `!important`) | **`section.style.background`**. Both are in the inspector (SectionInspector.jsx:137-152). |
| C9 | Block width/height/min-height/max-width | inline `style.dimensions` vs device overrides (cannot target dimensions, StyleSurface.php:415-427) vs tenant custom CSS | **Inline always wins.** It cannot vary per device, and class-level custom CSS needs `!important`. |
| C10 | Hide on device | `visibility.devices` (base/sm/md/lg) vs `responsive.{desktop,tablet,mobile}.hide` vs `responsive.{base..lg}.hide`, all in **one** inspector section (BlockInspector.jsx:206-232) | Any of them hides the block (OR). Overlapping vocabularies: md is the same range as tablet, base+sm is the same as mobile. |
| C11 | Text alignment per device | `style.align` breakpoint map (mobile-first, works) vs `responsive.{device}.align` → `sb-align-{device}-*` with **no media query** (StudioStylesheet.php:245-247; DocumentRenderer.php:155-157) | `responsive.*.align`: the class defined last in the stylesheet (mobile) wins at **every** width. See F-H3. |
| C12 | Hover/focus state vs base colour | base literal colour/background/border/shadow/opacity is **inline** (DocumentRenderer.php:561-631); state rules are scoped and **not `!important`** (StyleSurface.php:840-883; RenderCollector.php:120-129) | **The inline base wins.** A state colour/background/border-colour/shadow/opacity does nothing when the base value is a literal. A token or class base can be overridden. |
| C13 | Chrome (header/footer partial) box | partial template sections vs `.sb-site-header,.sb-site-footer{padding:1rem; border…}` applied to the partial wrapper (StudioCompiler.php:334; StudioStylesheet.php:55-57) | **Both apply.** A custom header partial always gets an extra 1rem padding and a border. The partial's own `settings.container_width` and `token_group` are ignored: the page's theme is used (StudioCompiler.php:95-97). |
| C14 | Typography size per device | `style.typography.size` as a responsive map (accepted, DocumentValidator.php:1083-1095) vs `responsive.{device}.style.typography.size` | **Only the device override renders.** A map value is dropped, because `buildInlineStyles` emits only a string (DocumentRenderer.php:564-566). |

### 6.3 Recommended ownership and precedence model (not implemented)

From weakest to strongest. Each value should have exactly one owner per level.

1. **Site default.** The `StudioStylesheet` base. It should hold no hard widths: every width and scale value should read a token, with a fallback.
2. **Global token.** `layout.*`, `space.*`, `radius.*`, `shadow.*` (Site settings, `studiobuilder_tokens`). Scale presets (`sb-pad-md`, `sb-radius-md`, `sb-shadow-md`, `sb-container--constrained`, `sb-layout-section--boxed`) should resolve to these variables.
3. **Template / page.** One page-level content width. Either give `settings.container_width` real CSS that sets the default `sb-w-*` for sections that do not set their own, or remove it from the Page inspector. Chrome partials should not get the default-chrome padding.
4. **Container / widget.** One field per property. Container props such as direction, wrap, justify, align, gap and columns should become `style.layout` defaults through a one-way migration, or be hidden when `style.layout` is set. The inspector should show one control.
5. **Responsive override.** `responsive.{tablet,mobile}.style` only, covering dimensions too. Move `dimensions` from inline into the scoped rule so overrides and custom CSS can work. Retire `responsive.*.align` and `responsive.*.hide` in favour of `style.align` maps and `visibility.devices`. Alternatively, give `sb-align-{device}-*` the device queries.

Inline styles should be reserved for runtime-only variables (`--sb-anim-*`).

---

## 7. Findings

| ID | Sev | Finding | Evidence | Remediation |
|---|---|---|---|---|
| F-H1 | High | Page "Width" (`settings.container_width`) is editable but never applied. The core CSS has no rule for `.sb-main--*`. The Site settings hint says pages pick their width there. | PageInspector.jsx:177; StudioCompiler.php:99-102; StudioStylesheet.php:52; messages.mjs:518 (`ss_hint_layout`); only assets/portfolio/portfolio.css:152-190 styles it | Define the semantics (default width for sections without their own), or remove the control and fix the hint. |
| F-H2 | High | Container props and `style.layout` are two controls for the same flex/grid value, both on the Content tab. `style.layout` silently wins. The prop control shows a value the canvas does not reflect. | FlexRenderer.php:40-77 vs StyleSurface.php:55-70; propPresenters.mjs:5-28; SurfaceControls.jsx:219-291; BlockInspector.jsx:181 | Pick one owner. Migrate props to `style.layout` defaults, or show props read-only when `style.layout` is set. |
| F-H3 | High | `responsive.{desktop,tablet,mobile}.align` classes have no media query. All three apply at every width, and the last one in CSS (mobile) wins. Tests only check that the class is emitted. | StudioStylesheet.php:245-247; DocumentRenderer.php:155-157; tests/integration/StudioBuilderSprint1AcceptanceTest.php:293-295 (desktop center + mobile left = left everywhere) | Wrap them in the device queries, or migrate to `style.align` maps and deprecate. Add a computed-style test. |
| F-H4 | High | Block width/height/min-height/max-width are inline styles. They cannot be overridden per device, because the responsive table excludes dimensions. Class-level custom CSS also loses to them. | DocumentRenderer.php:619-626; StyleSurface.php:415-427; responsiveStyle.mjs:15-22 | Move `dimensions` into StyleSurface (scoped rule) and add it to `responsiveFields()`. |
| F-M1 | Medium | Only section inner widths follow the site `layout.*` tokens. `layout.section` boxed/narrow (80/48rem) and `layout.container` constrained/compact (75/48rem) are hard-coded and use a different vocabulary. | StudioStylesheet.php:167-178 vs 420-424 | Map them to `var(--sb-layout-*)` and unify the names (narrow/normal/wide/full). |
| F-M2 | Medium | A "Full width" `layout.section` block is capped by its document section (`sb-w-wide` = 80rem) plus 1rem inner padding. The block description promises "Full-width". | BlockRegistry.php:286; StudioStylesheet.php:53, 168; DocumentRenderer.php:271-273 | Document the nesting, or let `full` break out (negative inline margin or full-bleed utility). |
| F-M3 | Medium | `layout.grid` `columns` prop emits unprefixed `sb-cols-N`, so a 4-column grid stays 4 columns on phones. Unlike `query_loop` (StudioStylesheet.php:249), there is no default collapse. | GridRenderer.php:39-58; StudioStylesheet.php:451-456 | Add mobile defaults, or emit `sb-cols-1 sb-md-cols-N`. |
| F-M4 | Medium | Hover/focus/active state colour, background, border-colour, shadow and opacity cannot override an inline literal base value. | DocumentRenderer.php:561-631; StyleSurface.php:840-883; RenderCollector.php:120-129 | Emit literal base values in the scoped rule instead of inline, or mark state declarations `!important`. |
| F-M5 | Medium | 3 responsive models (breakpoint maps, device overrides, device flags) plus `visibility.devices`. Two overlapping "hide" controls sit in one inspector section. | §3; BlockInspector.jsx:206-232; CanonicalDocumentSchema.php:160-161 | Converge on device overrides plus `visibility.devices`, and remove `responsive.*.hide` from the UI. |
| F-M6 | Medium | Canvas drag-over treats only `layout.*` types (or a `<section>` tag) as containers. `core.container`, `core.card`, `core.modal`, `core.form` and `core.query_loop` never offer "inside". A leaf block with `tag: section` offers "inside" (refused later). The Layers panel uses the definition's `allows_children`. | canvas.mjs:351-353 vs outlineDrag.mjs:15; BlockRegistry.php:268, 888 | Pass the manifest's `allows_children` into `attachCanvas`. |
| F-M7 | Medium | `responsive.*.style` for `desktop`/`base`/`sm`/`md`/`lg` is neither validated nor rendered (only tablet/mobile). `style.typography.size` breakpoint maps are validated and then dropped. | DocumentValidator.php:730-734, 1083-1095; StyleSurface.php:456-462; DocumentRenderer.php:564-566 | Reject the unused shapes in the validator, or render them. |
| F-M8 | Medium | Section padding and section background have two owners each (`padding_y` vs `style.padding`; `background_token` vs `style.background`). Both are in the inspector, and the style always wins. | SectionInspector.jsx:121-155; DocumentRenderer.php:257-268 | Show one control per property, or indicate the override. |
| F-M9 | Medium | Custom header/footer partials get the default chrome's `padding:1rem` and border. The partial's own width and token group are ignored. | StudioCompiler.php:332-336; StudioStylesheet.php:55-57 | Scope the chrome rules to non-partial output (`:not(.sb-site-header--partial)`). |
| F-M10 | Medium | Editor overlay forces `position:relative !important` on the selected node. A selected `absolute`/`sticky` block moves while selected, so the canvas no longer matches public. | canvas.mjs:29-33 | Draw selection with an overlay element, which CanvasOverlay already exists for, instead of mutating the node. |
| F-M11 | Medium | Prop and style values with the same names map differently. Prop gap `md` = 1rem, padding `md` = 2rem, token `space.md` = 1.5rem, `layout.container` padding `md` = 2rem. | StudioStylesheet.php:32-33; ThemeResolver.php:54-56 | Single spacing scale backed by tokens. |
| F-M12 | Medium | `core.container` horizontal forces `flex-wrap:wrap`. `layout.flex` defaults to `nowrap`. The same visual "row" behaves differently depending on block type. | StudioStylesheet.php:151, 189 | Align defaults, or expose wrap on `core.container`. |
| F-L1 | Low | Tablet/mobile inspector views show empty fields when inheriting. There is no placeholder with the inherited value. | responsiveStyle.mjs:30-37 | Show the inherited value as a placeholder. |
| F-L2 | Low | No `sm` (640-767) editor viewport, though breakpoint maps and visibility support `sm`. | viewport.mjs:12-16 | Add a preset, or drop `sm` from the vocabulary. |
| F-L3 | Low | `dimensions.height` renders but has no inspector control. | DocumentRenderer.php:621; StyleControls.jsx:554-581 | Add a control or remove it. |
| F-L4 | Low | Default section `columns: {base:1, md:12}` puts every top-level block of a section side by side from 768px. The inspector shows `{base:1}` when absent, which hides the md default. | CanonicalDocumentSchema.php:383; SectionInspector.jsx:122-130 | Make the default explicit in the UI. |
| F-L5 | Low | Sections cannot vary padding or background per device (no `responsive` key). | CanonicalDocumentSchema.php:105-121 | Extend device overrides to sections. |
| F-L6 | Low | Canvas container detection is a type-prefix heuristic (see F-M6). It also makes "inside" depend on `block.tag`. | canvas.mjs:352 | Same as F-M6. |
| F-L7 | Low | Overlay `!important` rules (selected `box-shadow`, drop-inside `background`, empty-container `min-height:160px`) temporarily hide author shadow/background in the editor. | canvas.mjs:18-62 | Use overlay layers. |
| F-L12 | Low | `canvasLiveSync` alignment writes undefined class names and an inline `text-align` without the mobile-first cascade. It stays stale after a viewport switch until the server repaints. | canvasLiveSync.mjs:398-416 | Reuse liveStyle's server-identical class computation. |
| F-L13 | Low | Legacy `style.spacing` is accepted but inert. | DocumentValidator.php:1144-1155; DocumentRenderer.php:546-641 | Reject or migrate. |
| F-I1 | Info | Same compile path for editor, preview and public (§4). The live-style parity fixture is written by PHP. | StudioRenderService.php:65-103; StudioCompiler.php:103 | Keep. |
| F-I2 | Info | `PageDocumentAssembler` comment says tenant CSS is "never in Editor". The code emits it in the Editor with a guard (recent canvas change). | PageDocumentAssembler.php:40-45 vs StudioCodePolicy.php:423-430 | Update the comment. |
| F-I3 | Info | Template stylesheet `portfolio_style.css` has `.sb-body--editor` rules, a deliberate editor-only divergence owned by that template. | assets/portfolio_style.css:334-343 | Accept, or move into the canvas overlay. |
| F-L14 | Info | `WidgetRegistry::withCoreWidgets()` is a parallel, unused definition of the layout primitives. | WidgetRegistry.php:135-175; WidgetSdk.php:114 | Remove, or derive from BlockRegistry. |
| F-L15 | Info | `LegacyDocumentConverter` is test-only and discards legacy layout. | LegacyDocumentConverter.php:1-14, 94 | Fine while there is no runtime use. |
| F-I4 | Info | `position: fixed` and `display: none` are deliberately excluded from the style surface. | StyleSurface.php:19-20 | n/a |
| F-I5 | Info | Undo/redo uses server revisions in confirmed autosave batches, so very quick sequential edits merge into one step. | sync.mjs:152, 230-232 | n/a |

---

## 8. Not verified

- Actual rendered output in a browser. No app run was allowed. Specificity and cascade conclusions come from reading the source and CSS rules.
- Whether any live tenant has a stored `responsive.*.align`, `style.spacing` or typography-size maps (no DB access).
- Whether Theme templates (single/archive) supply their own wrapper CSS that gives `.sb-main--*` meaning, beyond the bundled portfolio assets.
- The behaviour of e2e specs. They were read, not executed.
- `HtmlStructureMapper` / package import mapping of external widths, flex and grid into these fields. Not audited in depth.
