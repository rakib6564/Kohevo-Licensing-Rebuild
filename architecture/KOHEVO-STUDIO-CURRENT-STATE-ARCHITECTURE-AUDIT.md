# Kohevo Studio — Current State Architecture Audit

**Date:** 2026-09-30  
**Scope:** `01-client` runtime + `02-licensing` licensing/control plane  
**Baseline:** GitHub repository `rakib6564/Kohevo-Licensing-Rebuild`, current `main` around release `1.6.2`, cross-checked against the supplied Client/Central LIVE snapshots.  
**Mode:** Read-only audit. No source, migration, dependency, or runtime changes were made.

## 1. Executive summary

Kohevo already has most of the platform primitives required to build Studio safely: tenant context and repository scoping, admin/customer authentication, RBAC permissions, module licensing, plugin lifecycle protection, tenant branding, platform identity, MCP routing/scopes, media, multilingual support, and mature business APIs for Booking, Forms, Membership, and Coaching.

The important architectural fact is that **the active GitHub `main` no longer contains a website/page-builder runtime**. Commit `808908088cfd8ff067a7611d84bfed30e5df75ed` removed the old Visual Page Editor, Content admin section, content-page services, public content route, and page-rendering engine. The migrations/tables were intentionally left in place. The supplied LIVE Client snapshot still contains the deleted editor/rendering implementation, so the snapshot is useful as historical evidence but must not be treated as the current GitHub runtime.

Therefore the new Kohevo Studio Builder is a **new commercial product capability**, not a reactivation of the deleted editor and not a continuation of the old Content Builder plugin.

## 2. Baseline reconciliation

| Source | What it represents | Audit treatment |
|---|---|---|
| GitHub `main` | Current engineering source of truth | **Primary** |
| LIVE Client snapshot | Earlier deployed/runtime shape containing the removed visual editor/content runtime | **Cross-check / historical evidence** |
| LIVE Central snapshot | Licensing server/runtime cross-check | **Cross-check** |

The supplied Client snapshot contains files that current `main` removed, including `admin/editor.php`, `src/Presentation/DocumentSchema.php`, `src/Services/Content/RevisionStore.php`, `ContentPublicationService.php`, `PublicContentRoute.php`, and related rendering files. This exact removal is documented by commit `8089080`.

## 3. What exists today in the active architecture

### 3.1 Multi-tenancy

`TenantContext` + the base `Repository` provide structural tenant scoping. Repository CRUD methods automatically add the current tenant boundary; `crossTenant()` is an explicit audited escape hatch intended for exceptional platform-level operations.

**Conclusion:** Studio must use the same primitives. Direct tenant IDs in Studio persistence/services should be exceptional and auditable.

### 3.2 Authentication and authorization

`Slate\Services\Auth\Auth` provides session authentication, permission checks, role handling, and platform-admin support. Existing route conventions include `Auth::require()` and `Auth::requirePerm()`.

**Conclusion:** Studio should add explicit Studio permissions rather than inventing a separate authorization system.

### 3.3 Commercial licensing

The current Client `EntitlementService` is remote-authority based and fails closed unless a trusted remote license snapshot is usable. `ModuleGuard` provides route/service enforcement with separate behaviors for admin, public, and API surfaces.

The current Central `ModuleCatalog` defines authoritative module metadata and currently classifies `editor` and `content` as future/non-commercial. Commercial module keys are independent from arbitrary filesystem presence, and the Client has a parallel authoritative `CommercialModuleRegistry`.

**Conclusion:** Studio needs a new explicit commercial module key and must be registered in both Central and Client catalogs. Reusing the legacy `editor` key would blur product history and the new commercial boundary.

### 3.4 Plugin lifecycle and protection

`PluginLoader` is the active plugin registry. Plugin activation/deactivation is server-enforced, and plugin destructive actions are protected by the plugin-management lock. The loader also supports manifest capability declarations.

**Conclusion:** The Studio runtime fits the existing module/plugin containment model, provided its plugin is explicitly mapped to one authoritative commercial entitlement.

### 3.5 Platform identity and tenant branding

`PlatformIdentity` is the canonical Kohevo platform identity. Tenant branding is a separate concern (`TenantBranding`). Tenant settings cannot override platform identity; white-label behavior is controlled through a licensing policy bridge and fails closed.

**Conclusion:** Studio's theme/branding system must consume tenant branding without changing platform identity semantics.

### 3.6 MCP

The MCP Gateway mounts under the core `/api/v1` path and has no public plugin directory. It has a separate environment kill switch and scope-aware tool registration. Business modules already expose MCP handlers and independently call `ModuleGuard` as defense in depth.

**Conclusion:** Studio AI/MCP actions should join this model, not create a parallel AI endpoint or direct database mutation path.

### 3.7 Business modules available to Studio

The active Client already exposes reusable application/data surfaces:

- Booking: services, categories, locations, providers, availability, appointments, customers, rendering helpers, etc.
- Forms: form listing/selection and an inline public form renderer.
- Membership: plans, membership state, member/profile surfaces, and an inline content renderer.
- Coaching: tenant-scoped program/business data and integrations with membership/booking.

The existing modules are therefore valid **data/service providers** for future Studio blocks. Their legacy `renderContentBlock()`/`content_register_blocks` integration should not be treated as the new canonical Studio block contract.

## 4. What was removed from current `main`

The current branch deliberately removed the old content/page runtime. The deleted surface included:

- Visual Page Editor admin route and preview route.
- Pages/posts/templates/navigation admin screens.
- Content revision/publication/compilation/dependency/preview services.
- Public `content_pages` route fallback.
- `src/Presentation` page rendering engine, except `CustomerNav`.
- Content-specific stylesheet and editor-only tests/fixtures.

The removal commit explicitly states that migrations and content tables were left untouched and no data was dropped.

**Architectural implication:** those tables are now dormant schema assets from the previous content experiment. Their existence alone does not prove there is a live website CMS runtime.

## 5. Historical content architecture found in the LIVE snapshot

The supplied Client snapshot contains a substantial but now-retired content stack:

- `DocumentSchema` v1: `{schema,type,template,sections,seo}`.
- `DocumentSchemaV2`: hierarchical sections/blocks with IDs, nested children, bindings, visibility, and settings.
- `DocumentValidator`: server-side shape/field validation for the v1 envelope.
- `DocumentOperations`: pure insert/move/update operations, but operating through v1 normalization.
- `RevisionStore`: immutable owner-agnostic revisions in `content_revisions`.
- `ContentPublicationService`: validate → snapshot → compile → dependency tracking → publish.
- `CompilationStore` / `DependencyStore`: compiled output and invalidation metadata.
- `PublicContentRoute` / `PublicContentService`: tenant-scoped public page lookup and rendering.
- `DocumentTemplateRepository`: tenant templates storing the same document family.
- Core Block/Section/Page/RenderContext contracts under `Slate\Presentation`.

These are valuable historical design inputs, but the audit found an important inconsistency even in that snapshot: **DocumentSchemaV2 existed as a prototype but the persistence/validation/editor path was still primarily wired around v1.** The old editor JavaScript also flattened the model. Therefore simply restoring those files would reproduce an incomplete architecture rather than deliver the requested Studio product.

## 6. Existing content database skeleton

Current `main` still contains these migrations:

- `0004_content_revisions.php`
- `0012_contentbuilder_draft_published.php`
- `0018_content_compilation_artifacts.php`
- `0019_content_pages.php`
- `0020_document_templates.php`

The historical migrations indicate an intended direction toward:

```text
content_pages (addressing / ownership row)
        |
        v
content_revisions (versioned document JSON)
        |
        +---- compilation artifacts
        +---- dependency keys
        +---- reusable document templates
```

But the active runtime no longer consumes this graph, and `0019_retire_content_builder.php` / `0020_retire_visual_page_editor.php` explicitly retire the legacy content builder and visual page editor.

**Conclusion (Approved Phase 1 Decision):** Existing dormant content migrations (`0004`, `0012`, `0018`, `0019`, `0020`) remain untouched in `01-client/db/migrations/`. Kohevo Studio (`studio-builder`) provisions its own isolated, tenant-scoped `studiobuilder_*` tables (`studiobuilder_pages`, `studiobuilder_revisions`, `studiobuilder_compilations`, `studiobuilder_dependencies`, `studiobuilder_templates`, `studiobuilder_tokens`, `studiobuilder_locks`) with `plugins/studio-builder/install.sql` as the single canonical baseline DDL authority and `Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager` as the version-gated additive upgrade authority. Every `studiobuilder_*` table enforces `tenant_id INT UNSIGNED NOT NULL` without `DEFAULT 1`.

## 7. Current gaps / risks

### P0 — No active Studio website runtime

There is no current page-editor/render/public-content pipeline on `main`. This is the primary architectural gap.

### P0 — No agreed commercial identity for Studio Builder

Central still treats `editor`/`content` as future modules. A new commercial product key must be explicitly chosen and registered.

### P1 — Dormant content schema has historical baggage

Some migrations represent older content-builder ownership and draft/published columns. Reusing them without an explicit data-lineage decision risks carrying dead concepts forward.

### P1 — No current canonical render contract

The old `Slate\Presentation` contracts are absent from active `main`. Their historical form is useful, but the exact Studio document/block/render contract must be ratified before implementation.

### P1 — SEO runtime is not present

The snapshot contains `src/Services/Seo/.gitkeep` and `src/Contracts/Seo/.gitkeep`, not a production SEO manager. Studio's requested SEO system therefore cannot be assumed to exist.

### P1 — Import pipelines are not present

No production import architecture for Figma, arbitrary HTML, React/Next/v0 ZIPs, or project translation into the Kohevo canonical document was found.

### P1 — Reusable global components/design-system persistence is incomplete

Historical code/docs describe `global_ref` and template concepts, but no current authoritative runtime/store exists on `main` for the requested cross-page component/design-system system.

### P2 — Legacy business-module block hooks need a new adapter boundary

Booking/Forms/Membership already know how to render into the retired content system. Studio should expose a new block-provider contract and adapt those modules through it rather than carrying forward legacy hook shapes.

### P2 — Internal Slate terminology remains in code

The repository documents `Slate` as an internal engineering codename while product-facing identity is Kohevo. This is acceptable as an internal implementation detail, but new user-visible Studio terminology should use Kohevo consistently and must not introduce a second platform brand.

## 8. Current-state architecture map

```text
                    ┌──────────────────────────────┐
                    │        Kohevo Client          │
                    │  Auth / RBAC / TenantContext  │
                    │  Repository / Platform ID    │
                    │  Plugins / Licensing / MCP   │
                    └───────────────┬──────────────┘
                                    │
                  Existing business │ services/data
        ┌──────────────┬────────────┼────────────┬──────────────┐
        │              │            │            │              │
      Booking        Forms      Membership    Coaching       Media
        │              │            │            │              │
        └──────────────┴────────────┼────────────┴──────────────┘
                                    │
                         [NEW] Kohevo Studio
                                    │
               ┌────────────────────┴────────────────────┐
               │                                         │
       Studio document/runtime                     Studio Builder UI
       (not active today)                          (not active today)
               │                                         │
       Render / Publish / SEO                     Human interactions
       / Dynamic bindings                          AI / MCP / Import
```

## 9. Audit conclusion

The safe direction is **not** “restore the old visual editor.” The safe direction is:

1. establish one Studio-owned canonical website document contract;
2. reuse the existing tenant/auth/licensing/plugin/MCP primitives;
3. create one Studio application/command boundary through which Human UI, AI, MCP, and import workflows all mutate documents;
4. build one render/publish pipeline from that canonical document;
5. adapt Booking/Forms/Membership/other modules as registered data/block providers;
6. keep third-party editor state strictly downstream of the canonical model.

This preserves the project principle that Kohevo remains the platform identity and prevents a new builder from becoming an independent CMS, React CMS, Figma CMS, or AI-owned content store.

