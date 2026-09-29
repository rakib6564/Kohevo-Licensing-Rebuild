# Kohevo Studio — Security Architecture

**Status:** Architecture proposal for approval  
**Date:** 2026-09-30

## 1. Security objectives

Studio must preserve existing Kohevo security boundaries while adding a high-power authoring surface that can influence public HTML.

Primary goals:

1. strict tenant isolation;
2. server-side authorization and entitlement enforcement;
3. safe document parsing/normalization;
4. no arbitrary code execution from authored content;
5. safe dynamic-data access;
6. safe media references;
7. controlled publishing;
8. immutable revision history;
9. AI/MCP parity with human authorization;
10. platform identity protection.

## 2. Trust boundaries

```text
Browser / Editor UI
        |
        | untrusted JSON / commands
        v
Studio API / Application Layer
        |
        +-- Auth / session
        +-- TenantContext
        +-- Permission
        +-- ModuleGuard
        +-- Document validation
        +-- Policy checks
        v
Canonical Domain + DB
        |
        +-- Renderer
        +-- Data providers
        +-- Compiler
        v
Public HTML
```

AI providers, MCP clients, import ZIPs, HTML files, React source, and Figma exports are also untrusted inputs and must enter through the same boundary.

## 3. Tenant isolation

### Required

- every Studio repository query is tenant-scoped;
- every page/template/component/revision lookup is tenant-scoped;
- every media reference is resolved within tenant scope;
- every dynamic data provider receives the active tenant context from server state;
- cross-tenant access requires an explicit platform-admin path and audit event;
- tenant IDs are never accepted from browser state as authorization truth.

### Prohibited

- trusting `tenant_id` supplied by editor JSON;
- resolving global references by alias without tenant context;
- sharing cached rendered output between tenants without a tenant-safe cache key;
- using a public route parameter as proof of tenant identity.

## 4. Authentication and permissions

Studio should use existing `Auth` and RBAC mechanisms.

Recommended permissions:

```text
studio.view
studio.edit
studio.template_manage
studio.component_manage
studio.preview
studio.publish
studio.import
studio.export
```

The exact permission set should be minimized to the product workflow.

Permission hierarchy must not silently imply entitlement.

## 5. Entitlement enforcement

The Studio route, service, API, background, and MCP paths must all be independently safe.

```text
Global License Guard
      +
Studio ModuleGuard
      +
Action-specific permission
      +
Optional MCP scope
```

A valid MCP scope must never grant an unlicensed Studio module.

Likewise, an active Studio plugin must never be treated as proof of license entitlement.

## 6. Public-route anti-enumeration

For anonymous requests, a disabled/unlicensed Studio feature should normally behave as an ordinary non-existent route where practical.

Do not expose:

- module entitlement status;
- license IDs;
- internal tenant IDs;
- page database IDs;
- revision IDs;
- unpublished page metadata.

## 7. Canonical document validation

All write paths must validate server-side.

The validator must enforce:

- maximum document size;
- section/block counts;
- maximum nesting depth;
- known document members only;
- known block types;
- block prop schemas;
- field type correctness before coercion;
- string length bounds;
- responsive-value structure;
- safe token names;
- reference formats;
- allowed binding providers;
- bounded repeater sizes.

Malformed data must fail closed on write. Public rendering may retain narrowly scoped fail-soft compatibility behavior for old trusted data, but that behavior must never convert malformed new writes into an empty document silently.

## 8. XSS and executable content

### Default rule

Studio-authored blocks must not accept arbitrary executable HTML/JS.

### Rich text

Use an allowlisted node/mark model. Sanitize dangerous URLs and attributes. Do not allow `script`, event-handler attributes, arbitrary iframes, or CSS expressions.

### HTML escape hatch

An explicit raw-HTML block, if ever supported, must be separately permissioned, clearly labeled, audited, and ideally restricted to trusted administrators. It must not be the default output path of imports or AI.

### CSS

Persist symbolic design-token references by default. Arbitrary CSS is a privileged escape hatch with a strict size limit and sanitization policy.

## 9. Dynamic data provider security

Dynamic blocks must not store SQL or executable expressions.

Safe contract:

```text
provider key
+ typed parameters
+ mapping
+ server resolver
```

Every provider must:

- use existing tenant-scoped repositories/services;
- enforce module entitlement;
- enforce provider-level permissions where needed;
- limit records and payload size;
- normalize output before rendering;
- expose only explicitly approved fields;
- have deterministic empty/error states.

No `eval`, arbitrary query DSL, PHP callables, or client-supplied service class names.

## 10. Media security

Prefer logical media references:

```json
{
  "key": "hero.primary",
  "alt": "...",
  "focal": [0.5, 0.4]
}
```

The server resolves the key to a tenant-owned asset.

Validate:

- asset existence;
- tenant ownership;
- acceptable media type;
- focal coordinate range;
- output URL safety.

Do not allow authored document props to point arbitrarily at server filesystem paths.

## 11. Import security

Imports are untrusted packages.

### ZIP import

Require:

- extension allowlist;
- compressed/uncompressed size limits;
- file-count limits;
- path traversal checks;
- symlink rejection;
- no direct execution of extracted files;
- no `php`, server config, or executable script ingestion into the runtime tree;
- antivirus/content scanning policy where required.

### HTML/React/Next/v0 import

Treat source as data. Parse to an intermediate representation; never execute imported server code just to discover page structure.

## 12. AI security

AI-generated content must be untrusted.

AI is allowed to propose a structured command such as:

```text
insert block
update props
move block
create template
```

AI is not allowed to:

- write raw database queries;
- select arbitrary PHP classes;
- create server files;
- modify plugin code;
- change license state;
- bypass permissions;
- inject arbitrary JavaScript through normal block props.

A publish-capable AI workflow should support a preview/diff boundary so an authorized actor can verify material changes before release.

## 13. MCP security

Studio MCP tools must:

- require the MCP Gateway to be active/licensed where applicable;
- require the Studio entitlement;
- require explicit scopes;
- execute the same application commands as the browser;
- log actor/tool invocation;
- enforce the same tenant context;
- reject unknown commands/fields;
- rate-limit expensive render/import/publish operations.

No MCP tool should directly update Studio tables.

## 14. Publishing security

Publishing is a privileged state transition.

Required:

- `studio.publish` permission;
- valid working revision;
- full server validation before publish;
- atomic publication transaction;
- immutable published revision;
- compilation after/inside an atomic publication workflow;
- cache invalidation tied to the published revision;
- audit record including actor and source revision.

## 15. Revision and rollback security

Revisions should be append-only from normal application flows.

Rollback should create a new working revision based on the selected historical snapshot rather than mutating history.

This preserves an audit trail of:

```text
old revision -> rollback command -> new working revision -> optional publish
```

## 16. Caching

Cache keys must include at minimum the tenant/site/page/revision context required to prevent cross-tenant output reuse.

Never cache a draft page in a public cache namespace.

Dynamic data with user-specific/private state must not be placed in shared public page cache.

## 17. Platform identity boundary

Studio theme controls may affect tenant branding but must never write or override `PlatformIdentity` values.

White-label behavior remains governed by `PlatformIdentityPolicy`/entitlement logic.

No Studio document field should be interpreted as a platform identity override.

## 18. Security observability

Audit events should include:

- page creation/deletion;
- document mutation;
- template/component changes;
- publish/unpublish;
- rollback;
- import/export;
- AI/MCP mutation;
- permission/entitlement denial where meaningful;
- suspicious import failures.

Logs should avoid storing full sensitive payloads unnecessarily.

## 19. Abuse limits

Set server-side limits for:

- document bytes;
- blocks/sections;
- nested depth;
- command batch size;
- autosave frequency;
- preview rate;
- publish rate;
- import size/count;
- dynamic provider result size;
- AI command length and batch size.

## 20. Security test requirements

Before production release, add adversarial tests for:

- cross-tenant page ID access;
- cross-tenant revision ID access;
- cross-tenant global reference;
- forged tenant in document JSON;
- unentitled Studio access;
- entitled-but-no-permission access;
- MCP scope without entitlement;
- public 404 anti-enumeration;
- raw HTML/script injection;
- dangerous URL schemes;
- arbitrary binding provider IDs;
- malformed responsive values;
- oversized documents;
- ZIP traversal/symlink attacks;
- draft leakage through public routes/cache;
- platform identity override attempts.

## 21. Fail-safe rules

When uncertain, Studio should prefer:

```text
unknown block -> non-executable placeholder / validation error
unknown binding -> no data
missing entitlement -> deny
missing tenant -> deny
invalid page route -> 404
invalid publish state -> deny
compilation failure -> do not publish partial state
license exception -> fail closed
```

