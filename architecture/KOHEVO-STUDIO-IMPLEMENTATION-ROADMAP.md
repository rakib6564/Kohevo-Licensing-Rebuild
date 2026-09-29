# Kohevo Studio — Implementation Roadmap

**Status:** Phase 0 Approved; Phase 1 Implemented (awaiting Phase 1 sign-off)  
**Implementation:** Phase 1 complete (`studio-builder` foundation)

## Phase 0 — Architecture approval (Completed)

### Goal
Freeze the minimum set of architecture decisions before coding.

### Approved decisions

- Commercial module key & plugin slug: `studio-builder`
- PHP namespace: `Slate\Module\StudioBuilder\`
- Tenant-scoped tables under `studiobuilder_*` prefix (`studiobuilder_pages`, `studiobuilder_revisions`, `studiobuilder_compilations`, `studiobuilder_dependencies`, `studiobuilder_templates`, `studiobuilder_tokens`, `studiobuilder_locks`) with `tenant_id INT UNSIGNED NOT NULL` (no `DEFAULT 1`)
- Single baseline DDL authority: `01-client/plugins/studio-builder/install.sql`
- Additive schema upgrade authority: `Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager`
- No Studio schema in `01-client/db/migrations/`; dormant content migrations remain untouched

---

## Phase 1 — Commercial module and containment foundation (Implemented)

### Goal
Create the new Studio commercial boundary, baseline schema authority, and tenant-scoped repository foundation without exposing editor functionality yet.

### Scope

- Central `ModuleCatalog` registration for `studio-builder`.
- Client `CommercialModuleRegistry` registration for `studio-builder`.
- Plugin manifest (`plugins/studio-builder/plugin.json`), `install.sql`, `uninstall.sql`, and `StudioBuilder.php` bootstrap.
- `StudioSchemaManager` verification and additive upgrade foundation reading `install.sql` as the single baseline DDL authority.
- Tenant-isolated repository foundation (`StudioRepository` and 7 table repositories).
- Studio-specific RBAC permission definitions (`studio-builder.view`, `studio-builder.edit`, `studio-builder.publish`, `studio-builder.tokens`, `studio-builder.admin`).
- `ModuleGuard` entitlement helper (`StudioBuilder::isEntitled()`).
- No visual editor, no public routing, no MCP tools in Phase 1.

### Acceptance criteria

- `studio-builder` registered in both Central and Client commercial module catalogs.
- All 7 `studiobuilder_*` tables created cleanly from `install.sql` with `tenant_id INT UNSIGNED NOT NULL` (no `DEFAULT 1`).
- Plugin activation does not grant entitlement without a valid signed license snapshot; entitlement does not bypass plugin activation or RBAC permissions.
- Existing modules and dormant content migrations remain unchanged.

---

## Phase 2 — Canonical document domain

### Goal
Implement the canonical Studio document contract without a visual editor.

### Scope

- document schema/normalizer;
- validator;
- immutable IDs;
- operation model;
- page address model;
- template model;
- revision model;
- repository/services around the chosen existing content tables or approved replacement/evolution;
- JSON serialization contract;
- backward-compatibility importer for any preserved legacy document data that must remain readable.

### Important constraint

Do not implement a second `studio_pages`/`studio_blocks` CMS if the approved design evolves the existing content spine instead.

### Acceptance criteria

- normalize is deterministic and idempotent;
- malformed writes fail closed;
- unknown block types cannot execute arbitrary data;
- tenant scope is enforced at repository and service layers;
- every persisted authored state is traceable to a revision;
- published revisions are immutable.

---

## Phase 3 — Block/component registry and data-provider contracts

### Goal
Make the document useful to modules and safe for the future builder.

### Scope

- core block contract;
- block registry;
- builder-safe registry projection;
- field schema contract;
- component primitives;
- theme/token contract;
- dynamic data-provider contract;
- adapters for initial Booking / Forms / Membership blocks;
- media reference contract;
- global component/template models.

### Acceptance criteria

- no PHP/SQL/executable implementation details cross the editor boundary;
- Booking/Forms/Membership are service providers, not alternate content stores;
- dynamic providers enforce tenant, entitlement, permission, and result limits;
- block metadata is transport-safe;
- raw HTML/script fields are absent from normal blocks.

---

## Phase 4 — Server renderer, preview and public runtime

### Goal
Bring the canonical document to production output.

### Scope

- renderer;
- theme resolution;
- block rendering;
- dynamic data resolution;
- page template/chrome;
- SEO head assembly;
- preview service;
- public route resolution;
- compilation artifacts;
- dependency invalidation;
- cache headers and cache invalidation.

### Acceptance criteria

- preview and published output use the same render pipeline;
- production output is tenant-scoped;
- published pages do not execute draft state;
- dependency changes invalidate affected output;
- public route enumeration cannot leak disabled-module state.

---

## Phase 5 — Builder shell

### Goal
Deliver the visual editor as a thin consumer of the canonical domain.

### Scope

- React-based editor shell or approved alternative;
- canvas/iframe preview;
- section/block selection;
- drag/drop reorder;
- block insertion;
- property panel generation from field schemas;
- responsive viewport controls;
- command API client;
- optimistic local state with server reconciliation;
- autosave/dirty state;
- keyboard/accessibility behavior.

### Explicit non-goals

- client-side canonical persistence;
- localStorage as source of truth;
- editor-specific JSON persistence;
- direct repository/API mutation outside command endpoints.

### Acceptance criteria

- every editor mutation maps to a canonical operation;
- reload reconstructs state from server document;
- published page output matches preview source;
- editor state can be discarded without data loss beyond unsaved changes.

---

## Phase 6 — Templates, global components and design system UI

### Goal
Add reusable authoring primitives.

### Scope

- template library UI;
- reusable sections/patterns;
- global components;
- global header/footer/layout bindings;
- design token controls;
- tenant theme settings integration;
- dependency tracking for global references.

### Acceptance criteria

- global references do not duplicate canonical content into pages;
- global updates trigger dependent recompilation;
- platform identity cannot be overridden by Studio theme controls.

---

## Phase 7 — AI + MCP

### Goal
Allow AI to operate Studio through the same application boundary as humans.

### Scope

- Studio MCP tools;
- Studio command serialization;
- natural-language assistant actions;
- preview-before-publish workflow;
- structured diff/approval UX;
- audit log attribution to AI/MCP actor.

### Acceptance criteria

- AI cannot bypass Studio permissions;
- MCP cannot publish without publish permission/scope;
- AI cannot inject arbitrary JS/SQL/HTML through ordinary props;
- all AI mutations create normal revisions/audit events.

---

## Phase 8 — Imports

### Goal
Convert external website assets into the canonical Studio model.

### Recommended order

1. Kohevo JSON/template import/export.
2. HTML/CSS constrained import.
3. React/Next/v0 source import through an intermediate representation.
4. ZIP project import.
5. Figma mapping.

Each importer should be separately permissioned and size/risk limited.

---

## Phase 9 — SEO, multilingual and production hardening

### Scope

- SEO field system;
- sitemap/canonical/robots;
- localized page revisions;
- locale fallback policy;
- translation workflows;
- accessibility audits;
- XSS/HTML sanitization review;
- performance and cache review;
- observability;
- failure recovery;
- migration tooling.

---

## Phase 10 — Commercial release hardening

### Scope

- Central licensing QA;
- client lock/expiry behavior;
- install/upgrade/uninstall behavior;
- backup/restore;
- migration rollback strategy;
- compatibility matrix;
- browser/device QA;
- adversarial tenant-isolation tests;
- public-route anti-enumeration tests;
- load/performance tests.

## Implementation discipline for every phase

For each phase:

```text
INSPECT
  -> PLAN
  -> ASK / APPROVAL
  -> IMPLEMENT
  -> TEST
  -> SECURITY AUDIT
  -> REGRESSION
  -> REPORT
  -> WAIT
```

No phase should silently start the next one.

## Recommended MVP boundary

For the first production Studio release, avoid combining the full vision into one delivery. The most defensible MVP is:

```text
Commercial module gate
+ canonical document
+ sections/blocks
+ templates
+ media
+ responsive styles
+ preview
+ publish
+ public rendering
+ Booking/Forms/Membership blocks
+ revisions/rollback
```

AI/MCP and large external imports should be layered after the canonical runtime is stable. This reduces the risk that AI or import complexity defines the storage model.

