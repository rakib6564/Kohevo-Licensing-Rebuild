# Kohevo Studio widget and field test matrix

> **Regenerated 2026-10-17 from source.** The earlier version of this matrix named 10 classes that do not exist (`ParagraphRenderer`, `ColumnsRenderer`, `FormBlockRenderer`, `BookingCalendarRenderer`, `MembershipPricingRenderer`, `CoachingFeedRenderer`, `PortfolioRenderer`, `TestimonialRenderer`, `TextValidator`, plus an "HTML Purifier" dependency), and it used block names that are not real type ids (`header`, `paragraph`, `columns`, `booking-calendar`, `membership-pricing`, `coaching-feed`, `testimonial-slider`). Everything below was extracted from the code: each renderer's `type()` return value, each plugin block's `'type' => …` definition, and `FormsAPI::SUPPORTED_TYPES`. "Tests" counts the test files that reference the type id literally. It shows that a test touches the block, not that every behaviour of the block is covered.

## Status key

- **Implemented**: a renderer or definition exists in source and at least one test references the type id.
- **Not implemented**: claimed earlier, no code exists.

## Core, layout and theme blocks (`src/Module/StudioBuilder/Render/Block/CoreRenderers/`)

| Type id | Renderer class | Test files | Example tests | Status |
|---|---|---|---|---|
| `core.accordion` | `AccordionRenderer` | 2 | StudioBuilderBlockLibraryTest.php, add-panel.test.mjs | Implemented |
| `theme.archive_title` | `ArchiveTitleRenderer` | 3 | StudioBuilderPhase4RuntimeTest.php, StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint6ThemeBuilderTest.php | Implemented |
| `core.button` | `ButtonRenderer` | 20 | StudioBuilderElementManagerTest.php, StudioBuilderElementsTest.php, StudioBuilderInlineTextTest.php | Implemented |
| `core.card` | `CardRenderer` | 3 | StudioBuilderElementsTest.php, inline-text.test.mjs, inspector-sections.test.mjs | Implemented |
| `core.carousel` | `CarouselRenderer` | 1 | StudioBuilderBlockLibraryTest.php | Implemented |
| `core.container` | `ContainerRenderer` | 15 | StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase5BuilderApiTest.php | Implemented |
| `core.countdown` | `CountdownRenderer` | 3 | StudioBuilderElementManagerTest.php, StudioBuilderElementsTest.php, element-manager.test.mjs | Implemented |
| `core.divider` | `DividerRenderer` | 2 | StudioBuilderElementsTest.php, inspector-sections.test.mjs | Implemented |
| `core.feature_list` | `FeatureListRenderer` | 8 | StudioBuilderInlineTextTest.php, StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase4RendererTest.php | Implemented |
| `theme.field_value` | `FieldValueRenderer` | 1 | StudioBuilderFieldValueTest.php | Implemented |
| `layout.flex` | `FlexRenderer` | 5 | StudioBuilderElementManagerTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php, element-manager.test.mjs | Implemented |
| `core.form_field` | `FormFieldRenderer` | 2 | StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php | Implemented |
| `core.form` | `FormRenderer` | 2 | StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php | Implemented |
| `core.gallery` | `GalleryRenderer` | 3 | StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php, inspector-sections.test.mjs | Implemented |
| `layout.grid` | `GridRenderer` | 5 | StudioBuilderElementManagerTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php, add-panel.test.mjs | Implemented |
| `core.heading` | `HeadingRenderer` | 62 | StudioBuilderBlockAttributesTest.php, StudioBuilderElementManagerTest.php, StudioBuilderElementsTest.php | Implemented |
| `core.hero` | `HeroRenderer` | 14 | StudioBuilderElementManagerTest.php, StudioBuilderElementsTest.php, StudioBuilderInlineTextTest.php | Implemented |
| `core.icon` | `IconRenderer` | 4 | StudioBuilderElementsTest.php, canvasLiveSync.test.mjs, inspector-sections.test.mjs | Implemented |
| `core.image` | `ImageRenderer` | 12 | StudioBuilderInlineTextTest.php, StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase4RendererTest.php | Implemented |
| `layout.container` | `LayoutContainerRenderer` | 11 | StudioBuilderLayerLockTest.php, StudioBuilderSprint1AcceptanceTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php | Implemented |
| `core.link` | `LinkRenderer` | 3 | StudioBuilderElementsTest.php, canvasLiveSync.test.mjs, wireframe.test.mjs | Implemented |
| `core.list` | `ListRenderer` | 3 | StudioBuilderElementsTest.php, canvasLiveSync.test.mjs, wireframe.test.mjs | Implemented |
| `core.map` | `MapRenderer` | 2 | StudioBuilderElementsTest.php, inspector-sections.test.mjs | Implemented |
| `core.modal` | `ModalRenderer` | 2 | StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php | Implemented |
| `core.nav_menu` | `NavMenuRenderer` | 2 | StudioBuilderElementsTest.php, StudioBuilderPhase4RuntimeTest.php | Implemented |
| `layout.offcanvas` | `OffcanvasRenderer` | 2 | StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php | Implemented |
| `theme.post_content` | `PostContentRenderer` | 1 | StudioBuilderSprint6ThemeBuilderTest.php | Implemented |
| `theme.post_meta` | `PostMetaRenderer` | 2 | StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint6ThemeBuilderTest.php | Implemented |
| `theme.post_title` | `PostTitleRenderer` | 3 | StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint6ThemeBuilderTest.php, add-panel.test.mjs | Implemented |
| `core.query_loop` | `QueryLoopRenderer` | 2 | StudioBuilderSprint5AcceptanceTest.php, StudioBuilderSprint5DynamicQueryLoopTest.php | Implemented |
| `core.quote` | `QuoteRenderer` | 6 | StudioBuilderElementsTest.php, StudioBuilderInlineTextTest.php, canvasLiveSync.test.mjs | Implemented |
| `core.rich_text` | `RichTextRenderer` | 8 | StudioBuilderPhase2DocumentDomainTest.php, StudioBuilderPhase5BuilderApiTest.php, StudioBuilderPhase7AiMcpTest.php | Implemented |
| `theme.search_box` | `SearchBoxRenderer` | 2 | StudioBuilderSprint6AcceptanceTest.php, StudioBuilderSprint6ThemeBuilderTest.php | Implemented |
| `layout.section` | `SectionRenderer` | 3 | StudioBuilderSprint1AcceptanceTest.php, StudioBuilderSprint1LayoutAndWidgetsTest.php, StudioBuilderSprint2AcceptanceTest.php | Implemented |
| `core.spacer` | `SpacerRenderer` | 2 | StudioBuilderElementsTest.php, inspector-sections.test.mjs | Implemented |
| `core.stats` | `StatsRenderer` | 1 | StudioBuilderBlockLibraryTest.php | Implemented |
| `core.table` | `TableRenderer` | 1 | StudioBuilderElementsTest.php | Implemented |
| `core.tabs` | `TabsRenderer` | 1 | StudioBuilderBlockLibraryTest.php | Implemented |
| `core.text` | `TextRenderer` | 16 | StudioBuilderElementManagerTest.php, StudioBuilderInlineTextTest.php, StudioBuilderSectionPresetsTest.php | Implemented |
| `core.video` | `VideoRenderer` | 4 | StudioBuilderSectionPresetsTest.php, StudioBuilderSprint7AcceptanceTest.php, StudioBuilderSprint7AdvancedUiTest.php | Implemented |

## Module blocks (`src/Module/StudioBuilder/Render/Block/ModuleRenderers/`)

| Type id | Renderer class | Test files | Example tests | Status |
|---|---|---|---|---|
| `booking.embed` | `BookingEmbedRenderer` | 5 | StudioBuilderEmbedBlocksTest.php, add-panel.test.mjs, canvasLiveSync.test.mjs | Implemented |
| `booking.services` | `BookingServicesRenderer` | 9 | StudioBuilderPhase3ApplicationBoundaryTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase4RuntimeTest.php | Implemented |
| `forms.form_card` | `FormCardRenderer` | 3 | StudioBuilderPhase4RuntimeTest.php, add-panel.test.mjs, core.test.mjs | Implemented |
| `forms.embed` | `FormEmbedRenderer` | 4 | StudioBuilderEmbedBlocksTest.php, StudioBuilderPhase2DocumentDomainTest.php, add-panel.test.mjs | Implemented |
| `membership.plans` | `MembershipPlansRenderer` | 6 | StudioBuilderPhase3ApplicationBoundaryTest.php, StudioBuilderPhase4RendererTest.php, StudioBuilderPhase4RuntimeTest.php | Implemented |
| `payment.button` | `PaymentButtonRenderer` | 3 | StudioBuilderPaymentBlocksTest.php, add-panel.test.mjs, canvasLiveSync.test.mjs | Implemented |
| `payment.link` | `PaymentLinkRenderer` | 3 | StudioBuilderPaymentBlocksTest.php, add-panel.test.mjs, canvasLiveSync.test.mjs | Implemented |

`PaymentButtonRenderer` has no test hook. The audit had added a public static `$stripeReadyOverride` to it; that seam was removed on 2026-10-17, and `StudioBuilderPaymentBlocksTest` now drives Stripe readiness through its own `StripePaymentAPI` stand-in.

## Widget SDK blocks from plugins

| Plugin | Type ids | Test files per type | Status |
|---|---|---|---|
| `studio-freelancer-portfolio` | `portfolio.experience_timeline`, `portfolio.eyebrow`, `portfolio.footer`, `portfolio.nav_header`, `portfolio.project_card`, `portfolio.reassurance_badges`, `portfolio.service_card`, `portfolio.skill_grid`, `portfolio.stat_highlight`, `portfolio.status_pill`, `portfolio.testimonial_card` | 2 each | Implemented |
| `studio-builder` (`StudioBuilderPortfolioWidgets.php`) | `portfolio.rakib_showcase` | 0 | Implemented, untested (no test references the type id; added 2026-10-17) |
| `studio-testimonial-widget` | `acme.testimonial` (example Widget SDK block) | 2 | Implemented |

## Claimed earlier but not implemented

| Earlier name | Finding |
|---|---|
| `coaching-feed` / `CoachingFeedRenderer` | There is no coaching block. |
| `testimonial-slider` / `TestimonialRenderer` | The only testimonial block is `acme.testimonial`, an SDK example. Slider, star-rating and avatar behaviour was not verified. |
| `TextValidator`, HTML Purifier | Forms validation lives in `FormsAPI`. The project has no HTML Purifier dependency. |

## Forms field types (`FormsAPI::SUPPORTED_TYPES`)

`text`, `email`, `tel`, `intlphone`, `url`, `number`, `date`, `time`, `address`, `textarea`, `select`, `radio`, `checkbox`, `checkboxes`, `range`, `rating`, `file`, `signature`, `heading`, `hidden`, `calc`, `step`, `disclaimer`. There is no `checkbox_group`; the multi-choice type is `checkboxes`.

## Not verified here

- Visual/responsive behaviour in a browser: Playwright e2e (`npm run test:e2e`) was not run.
- Per-block sanitisation claims made in the earlier matrix (URL filtering, escaping, clamping) were not re-audited block by block. They are not carried forward.
