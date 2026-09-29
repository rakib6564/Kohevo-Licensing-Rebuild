# Kohevo Studio — Target Architecture

**Status:** Architecture proposal for approval; no implementation started.  
**Date:** 2026-09-30

## 1. Target outcome

Kohevo Studio is a **new commercial visual website creation capability** layered on the existing Kohevo client platform. It is not the old Visual Page Editor, not the existing dance/arts `src/Module/Studio` domain, and not a separate CMS.

The target architecture enforces one invariant:

> **One canonical Kohevo Studio document is the only authored website representation.**

The builder UI, AI, MCP, imports, preview, server rendering, static compilation, revisions, and publish operations all consume or produce that same canonical document through controlled application services.

## 2. Target architecture at a glance

```text
 Human Builder ─────┐
 AI Assistant ──────┤
 MCP Tools ─────────┤
 Importers ─────────┤
                    v
        ┌──────────────────────────┐
        │ Studio Application Layer │
        │ Commands / Queries       │
        │ Auth / Permission /      │
        │ Entitlement / Validation │
        └────────────┬─────────────┘
                     v
        ┌──────────────────────────┐
        │ Canonical Studio Domain  │
        │ Page Address             │
        │ Document                 │
        │ Components / Templates   │
        │ Bindings / Design Tokens │
        │ Revisions                │
        └────────────┬─────────────┘
                     │
          ┌──────────┼──────────────┐
          v          v              v
      Preview      Compile        Publish
          │          │              │
          └──────────┼──────────────┘
                     v
        ┌──────────────────────────┐
        │ Public Renderer / Route  │
        │ SSR + SEO + Tenant Theme │
        │ + resolved live data     │
        └──────────────────────────┘
```

## 3. Domain boundaries

### 3.1 Studio Page Address

Addressing belongs to a page/site ownership record, not to rendered HTML and not to the editor state.

Minimum conceptual identity:

```text
tenant
site / site-context (one tenant may start with one default site)
page id
type
slug / route
locale
status
```

The exact database shape should be finalized after confirming whether Kohevo needs one site per tenant or multiple sites per tenant in the first commercial release.

### 3.2 Canonical Studio Document

The document should be hierarchical and versioned. The historical v1 envelope is too narrow for the requested product, while the historical v2 prototype is not production-complete. The recommendation is therefore to ratify a **single supported schema-2 contract** rather than reusing the v2 prototype unchanged.

Illustrative shape:

```json
{
  "schema": 2,
  "type": "page",
  "template": "default",
  "settings": {
    "container": "wide"
  },
  "seo": {
    "title": "...",
    "description": "...",
    "canonical": "..."
  },
  "sections": [
    {
      "id": "s_01H...",
      "layout": {
        "cols": 12,
        "width": "wide",
        "gap": "md"
      },
      "blocks": [
        {
          "id": "b_01H...",
          "type": "hero",
          "props": {},
          "style": {},
          "visibility": {},
          "bindings": {}
        }
      ]
    }
  ]
}
```

This is a **target contract sketch**, not an implementation schema. Final field names and supported semantics require approval and a schema review before coding.

### 3.3 Stable identifiers

Every section/block/component instance should have stable opaque IDs. IDs are for editor targeting, revision diffs, analytics attribution, and operation addressing. They must not encode tenant IDs, user IDs, database queries, or labels.

## 4. Document ownership and persistence

Recommended persistence rule:

```text
Page row                     = address / lifecycle metadata
Revision row                 = immutable canonical document snapshot
Template library row         = authored canonical document preset
Global component row         = reusable canonical component document/pattern
Compilation row              = derived output, never source of truth
Dependency row               = invalidation metadata, never source of truth
```

The canonical document is JSON because the page is authored, versioned, validated, and rendered as one tree. Relational section/block rows would create a second assembly model and add unnecessary coupling.

### 4.1 Approved Studio Schema & Single Authority (`studiobuilder_*`)

Phase 1 establishes the approved persistence model under the `studio-builder` commercial plugin:

- **Single Baseline DDL Authority:** `01-client/plugins/studio-builder/install.sql` is the sole source of `CREATE TABLE` definitions for Studio.
- **Additive Upgrade Authority:** `Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager` executes `install.sql` for baseline table creation and runs version-gated, idempotent additive alterations (`ensureColumn`, `ensureIndex`) on upgrade. It never duplicates `CREATE TABLE` DDL in PHP.
- **No Core Migration Pollution:** Zero Studio tables are added to `01-client/db/migrations/`, and historical dormant content migrations remain untouched.
- **Strict Tenant Scoping:** All 7 tables (`studiobuilder_pages`, `studiobuilder_revisions`, `studiobuilder_compilations`, `studiobuilder_dependencies`, `studiobuilder_templates`, `studiobuilder_tokens`, `studiobuilder_locks`) declare `tenant_id INT UNSIGNED NOT NULL` with no `DEFAULT 1`, accessed via `Slate\Module\StudioBuilder\Repository\StudioRepository` subclasses.

## 5. Studio Application Layer

The application/command layer is the main architectural addition.

### Commands

Illustrative command families:

```text
CreatePage
UpdatePageAddress
ApplyDocumentOperation
InsertBlock
MoveBlock
UpdateBlockProps
UpdateSectionLayout
SaveTemplate
ApplyTemplate
CreateGlobalComponent
UpdateGlobalComponent
DeleteGlobalComponent
SaveDraft
PublishPage
RollbackRevision
RestoreRevision
```

The UI should never call repositories directly. AI and MCP should never call repositories directly. Importers should never bypass the command layer.

Every command must pass through:

```text
TenantContext
  -> Authentication / actor
  -> Permission
  -> Commercial entitlement
  -> Input validation
  -> Document normalization
  -> Domain/business rules
  -> Transactional persistence
  -> Revision/audit event
```

## 6. Queries

Read-side services should provide explicitly shaped models for:

- page list/details;
- current working revision;
- published revision;
- revision history;
- builder palette / block registry metadata;
- template library;
- global components;
- media picker;
- theme/design tokens;
- dynamic data provider metadata;
- preview rendering;
- publish status.

Builder API responses should never expose PHP classes, closures, SQL, internal credentials, or arbitrary executable renderer metadata.

## 7. Block registry

Each block is a platform/module-owned definition with:

```text
type key
version
label/category/icon
fields/defaults
capabilities
permissions
render adapter
optional data provider
```

A block definition is registered by an active module through a controlled extension point.

### Block rules

A block:

- owns its own props;
- validates props against its schema;
- renders deterministic output;
- may consume resolved data supplied by a provider;
- must not issue ad-hoc database queries from the persisted document;
- must not store tenant IDs or server credentials in props;
- must not use arbitrary CSS/JS as its normal contract;
- must not become a hidden mini-CMS.

## 8. Dynamic data bindings

Studio needs live Booking/Forms/Membership/Customers/Staff/Locations data without allowing arbitrary executable expressions.

Recommended pattern:

```json
{
  "type": "booking.services",
  "params": {
    "active": true,
    "category": "wellness"
  },
  "mapping": {
    "title": "name",
    "description": "description",
    "ctaUrl": "url"
  }
}
```

The server resolves bindings through registered **data providers**. The document stores the declaration, not the resolved rows.

Provider contract should enforce:

- provider key allowlist;
- parameter schema;
- tenant-scoped queries;
- permission and entitlement checks;
- bounded result size;
- deterministic output shape;
- cache/dependency metadata;
- safe empty states.

## 9. Global components, sections, templates

These should be separate concepts even when they share the same canonical document primitives.

### Template

A complete page/document preset used to create a page.

### Reusable Section / Pattern

A tenant-owned authored section that can be inserted into pages as a copy or reference according to the product's approved semantics.

### Global Component

A reusable authored object referenced by stable ID; one update should affect every consuming page after validation and recompilation.

The target model must prevent accidental duplication of global content into each page revision.

## 10. Design system / theme

Theme values should be symbolic in authored documents:

```text
color token
font token
spacing token
radius token
shadow token
container width
breakpoint semantics
```

Tenant branding is the source for tenant-controlled visual identity. Platform identity remains separately controlled by `PlatformIdentity`/`PlatformIdentityPolicy`.

Raw arbitrary CSS should be a privileged escape hatch, not the normal styling contract.

## 11. Rendering and compilation

One canonical render pipeline should support at least:

```text
Canonical Document
   -> validate/normalize
   -> resolve template
   -> resolve theme tokens
   -> resolve allowed dynamic data
   -> render blocks/components
   -> assemble document chrome
   -> SEO head
   -> platform/tenant identity rules
   -> final HTML
```

Preview uses the same renderer and compilation path as publish/public rendering, with only the source revision/context changed.

Static compilation may cache derived HTML, but compiled HTML is never the authoring source.

## 12. Publish model

Recommended:

```text
working revision
      |
      | publish command
      v
validated published revision
      |
      +--> compilation artifact
      +--> dependency index
      +--> public cache invalidation
```

Publish must be atomic from the author's perspective. A failed compilation must not result in half-published state.

## 13. Preview

Preview should be a first-class application service, not a client-only canvas render.

Required properties:

- working revision source;
- same block registry as production;
- same tenant theme/branding rules;
- noindex/nofollow;
- no-store or equivalent cache control;
- no mutation side effects;
- explicit preview authorization.

## 14. Public routing

The current `main` intentionally has no `content_pages` fallback. Studio therefore needs an explicit public route architecture.

Recommended flow:

```text
Incoming request
   -> route/site resolution
   -> tenant/site context
   -> page address lookup
   -> published revision
   -> render pipeline
   -> cache
```

Unknown or disabled public routes must remain indistinguishable from ordinary 404s where module/license enumeration would otherwise leak information.

## 15. AI and MCP architecture

AI should be a client of the Studio Application Layer, not a second editor.

```text
AI intent
  -> structured Studio command
  -> same validation/permission/entitlement path
  -> canonical document mutation
  -> revision
```

MCP tool families should be explicit, for example:

```text
studio.page.read
studio.page.create
studio.document.apply_operation
studio.template.list
studio.template.apply
studio.component.list
studio.preview.render
studio.publish
```

Publishing and destructive global-component operations should have separate scopes/permissions from ordinary edit operations.

## 16. Import architecture

Every importer follows:

```text
Source (HTML / React / Next / ZIP / Figma / AI)
       |
       v
Import parser
       |
       v
Intermediate normalized representation
       |
       v
Studio canonical document mapper
       |
       v
Server validation + permission + tenant checks
       |
       v
Revision / draft
```

No importer is permitted to create a parallel persistence model.

## 17. Responsive editing

Responsive values should use symbolic breakpoint buckets and normalized values, not duplicate page documents.

Example concept:

```json
{
  "fontSize": {
    "base": "lg",
    "md": "xl",
    "lg": "2xl"
  }
}
```

Exact token set and breakpoint names require design-system approval.

## 18. Accessibility

Studio should generate semantically valid HTML and preserve keyboard-accessible editor controls. The editor itself requires keyboard movement, focus management, selection semantics, and announcements for drag operations.

## 19. Performance

Initial target architecture should prefer:

- bounded document depth;
- bounded block count;
- explicit dynamic-data result limits;
- compiled output caching;
- dependency-based invalidation;
- lazy builder palette previews;
- debounced draft persistence;
- immutable revisions rather than mutation-in-place of published data.

## 20. Recommended module boundary

**Product display name:** `Kohevo Studio`  
**Recommended commercial module key:** `studio-builder`  
**Recommended plugin slug:** `studio-builder`

This name deliberately avoids reusing `editor`, which Central currently defines as a future/non-commercial historical category, and avoids colliding with the existing `src/Module/Studio` dance/arts domain.

**Approval required:** confirm the commercial module key and plugin slug before any licensing/catalog implementation.

## 21. Architecture principles that are non-negotiable

1. Canonical document is Kohevo-owned.
2. Editor state is transient.
3. AI/MCP/imports use the same command boundary as humans.
4. Published output is derived, never authored truth.
5. Tenant isolation is structural.
6. Platform identity remains outside tenant branding.
7. Dynamic data uses allowlisted providers, not executable expressions.
8. Licenses and permissions are enforced server-side on every relevant entry point.
9. No parallel CMS tables without a documented lineage decision.
10. Third-party libraries are implementation details, never the persistence authority.

