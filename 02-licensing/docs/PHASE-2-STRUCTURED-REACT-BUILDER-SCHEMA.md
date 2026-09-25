# Phase 2 — Structured React Builder and Block Contracts

**Status:** Proposed design  
**Applies to:** Slate multi-tenant CMS, Phase 2  
**Author:** Manus AI  
**Baseline:** `Slate\Presentation\DocumentSchema` version 1, `Page → Section → Block`, `FieldSchema`, `LayoutSpec`, and the single rendering pipeline

## Executive decision

Phase 2 should use the existing Slate document envelope as its persisted contract. The React builder must edit the same `Page`, `Section`, and `Block` tree that the server renderer consumes. It must not introduce a second page format, a React-specific manifest, or client-owned markup.

The canonical stored document is:

```json
{
  "schema": 1,
  "type": "page",
  "template": "document",
  "sections": [],
  "seo": {}
}
```

A block stores a stable type key and JSON data props:

```json
{
  "type": "hero",
  "props": {}
}
```

The builder may keep editor-only state such as selection, undo history, viewport, drag state, Craft.js node IDs, dnd-kit sensors, drag indicators, and expanded panels, but that state must never be persisted in the content document. The bridge serializer must project only canonical `sections[]`, `blocks[]`, `props`, `style`, and approved global references into `DocumentSchema` JSON. The server remains authoritative for schema validation, normalization, permissions, publishing, rendering, and tenant isolation.

> **Core invariant:** one normalized document is the source for server HTML, React preview, static compilation, revisions, and API responses. Global blocks are references into that same document graph, not copied markup.

## 1. Scope and non-goals

Phase 2 defines the structured content model and the contracts required by the React editor. It covers document envelopes, sections, blocks, typed props, responsive values, media references, validation, permissions, unknown-block behavior, revision compatibility, and preview behavior.

Phase 2 does not define the final visual design of the editor, the theme manifest format, SEO metadata fields, demo-import ZIP format, or MCP tool transport. Those systems may consume this contract later.

| In scope | Out of scope |
|---|---|
| Canonical JSON document | React component styling system |
| Block registry contract | Theme preset authoring UI |
| Typed field definitions | SEO scoring algorithms |
| Section layout data | ZIP/demo import protocol |
| Responsive prop representation | MCP transport or authentication |
| Server-side validation | Arbitrary PHP execution |
| Draft preview and revisions | Final static-hosting release format |

## 2. Canonical document model

### 2.1 Document envelope

The existing `DocumentSchema::VERSION` is `1`. Phase 2 ratifies that version rather than introducing a new version for the React builder.

The envelope has five persisted members. Theme binding is stored in the tenant theme record, not duplicated in every page document. A theme may bind `header_block_id` and `footer_block_id` to published global-block records.

| Member | Type | Required | Meaning |
|---|---|---:|---|
| `schema` | integer | yes | Document schema version. Current value is `1`. |
| `type` | string | yes | Content type used for rendering and template selection. |
| `template` | string | yes | Template hint. Empty string means use normal precedence. |
| `sections` | array | yes | Ordered section list. |
| `seo` | object | yes | Structured page SEO metadata reserved for the SEO module. |

Addressing data must remain on the owning database row and must not be duplicated inside the document. The row remains authoritative for tenant, route, locale, slug, status, and ownership.

### 2.2 JSON Schema-style document definition

The following is the normative shape. It is expressed in JSON Schema Draft 2020-12 style for implementation guidance; the PHP validator may use an equivalent native validator.

```json
{
  "$id": "https://slate.local/schemas/document-1.json",
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "Slate Page Document",
  "type": "object",
  "additionalProperties": false,
  "required": ["schema", "type", "template", "sections", "seo"],
  "properties": {
    "schema": {
      "const": 1
    },
    "type": {
      "type": "string",
      "pattern": "^[a-z][a-z0-9_-]{0,63}$"
    },
    "template": {
      "type": "string",
      "pattern": "^(|[a-z][a-z0-9_-]{0,63})$"
    },
    "sections": {
      "type": "array",
      "maxItems": 200,
      "items": {
        "$ref": "#/$defs/section"
      }
    },
    "seo": {
      "type": "object",
      "additionalProperties": true,
      "maxProperties": 64
    }
  },
  "$defs": {
    "section": {
      "type": "object",
      "additionalProperties": false,
      "required": ["id", "layout", "blocks"],
      "properties": {
        "id": {
          "type": "string",
          "pattern": "^s[1-9][0-9]{0,5}$"
        },
        "layout": {
          "$ref": "#/$defs/layout"
        },
        "blocks": {
          "type": "array",
          "maxItems": 100,
          "items": {
            "$ref": "#/$defs/block"
          }
        },
        "savedAs": {
          "type": "string",
          "pattern": "^[a-z][a-z0-9_-]{0,63}$"
        }
      }
    },
    "layout": {
      "type": "object",
      "additionalProperties": false,
      "required": ["cols", "bg", "pad", "width"],
      "properties": {
        "cols": {
          "type": "integer",
          "minimum": 1,
          "maximum": 12
        },
        "bg": {
          "type": "string",
          "pattern": "^[a-z][a-z0-9_.-]{0,63}$"
        },
        "pad": {
          "enum": ["compact", "normal", "spacious"]
        },
        "width": {
          "enum": ["narrow", "normal", "wide", "full"]
        }
      }
    },
    "block": {
      "type": "object",
      "additionalProperties": false,
      "required": ["type", "props"],
      "properties": {
        "type": {
          "type": "string",
          "pattern": "^[a-z][a-z0-9_.-]{0,127}$"
        },
        "props": {
          "type": "object",
          "maxProperties": 128
        },
        "style": {
          "type": "object",
          "additionalProperties": false,
          "properties": {
            "visible": {
              "$ref": "#/$defs/responsiveBoolean"
            },
            "align": {
              "$ref": "#/$defs/responsiveAlign"
            },
            "className": {
              "type": "string",
              "pattern": "^[A-Za-z0-9_ -]{0,120}$"
            }
          }
        }
      }
    },
    "responsiveBoolean": {
      "oneOf": [
        {"type": "boolean"},
        {"$ref": "#/$defs/responsiveObject"}
      ]
    },
    "responsiveAlign": {
      "oneOf": [
        {"enum": ["start", "center", "end"]},
        {
          "type": "object",
          "additionalProperties": false,
          "properties": {
            "base": {"enum": ["start", "center", "end"]},
            "sm": {"enum": ["start", "center", "end"]},
            "md": {"enum": ["start", "center", "end"]},
            "lg": {"enum": ["start", "center", "end"]}
          },
          "minProperties": 1
        }
      ]
    },
    "responsiveObject": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "base": {},
        "sm": {},
        "md": {},
        "lg": {}
      },
      "minProperties": 1
    }
  }
}
```

The JSON Schema above describes the outer shape. Block-specific prop validation is delegated to the registered block definition and is mandatory before persistence.

### 2.3 Normalization requirements

Normalization is a pure server-side function. It must be deterministic and idempotent:

```text
normalize(normalize(document)) === normalize(document)
```

The normalizer must:

1. Decode JSON strings and reject malformed JSON for writes.
2. Stamp `schema: 1`.
3. Fill the document `type` from the owning row if absent.
4. Normalize an accepted legacy flat block array into one implicit section for reads.
5. Assign missing section IDs positionally as `s1`, `s2`, and so on.
6. Normalize missing section layout to the default layout.
7. Normalize missing block props to an object.
8. Apply block schema defaults without changing explicit falsy values.
9. Preserve only explicitly supported top-level members.
10. Return the same canonical output for equivalent inputs.

For writes, malformed or structurally invalid data should produce a validation error rather than silently becoming an empty document. Fail-soft behavior remains appropriate for public rendering of already-stored legacy data, but not for an editor save request.

## 3. Section contract

A section is a layout container. It arranges blocks but does not interpret their internal props. A section may also identify a reusable global section through `savedAs`, but `savedAs` is a stable alias and not an inline copy of the global content. Theme header/footer bindings should prefer explicit global-block IDs; aliases remain useful for named patterns and migration compatibility.

### 3.1 Global block and layout-slot contract

A global block is a published, tenant-scoped template part addressed by an immutable record ID and an optional stable alias:

```json
{
  "type": "global_ref",
  "props": {
    "$ref": "global-header"
  }
}
```

The compact section form is also accepted for reusable patterns:

```json
{
  "id": "s1",
  "savedAs": "global-header",
  "layout": {"cols": 1, "bg": "", "pad": "normal", "width": "full"},
  "blocks": []
}
```

Global references are resolved server-side within the active tenant. A page document must never embed the resolved global block as a second source of truth. When a global block changes, every page, template, and compiled `content_html` record that references it must be invalidated and recompiled.

The theme manifest or theme settings record owns layout-slot bindings:

```json
{
  "header_block_id": 41,
  "footer_block_id": 42,
  "header_alias": "global-header",
  "footer_alias": "global-footer"
}
```

`header_block_id` and `footer_block_id` are authoritative. Aliases are fallback lookup keys and must resolve to a single published global block in the same tenant. A theme binding cannot point to a draft, trashed, missing, or cross-tenant block. Header and footer blocks render through the same block registry and renderer as page content, but they occupy the document template's `header` and `footer` regions rather than the page's `content` region.

```json
{
  "id": "s1",
  "layout": {
    "cols": 2,
    "bg": "surface-muted",
    "pad": "normal",
    "width": "wide"
  },
  "blocks": [],
  "savedAs": "feature-band"
}
```

The current `LayoutSpec` defines `cols`, `bg`, `pad`, and `width`. Phase 2 should retain this contract and add no pixel values to persisted section layout.

### Section validation rules

- `id` is stable within the document and is used by the builder, revision diffing, and resolved dynamic data.
- The server may rewrite missing IDs positionally during normalization.
- Duplicate IDs are invalid on write.
- IDs must not contain user-generated labels, tenant identifiers, or database IDs.
- `cols` must be an integer from `1` through `12`.
- `bg` is a design-token name, not a color, URL, CSS expression, or class list.
- `pad` must be one of `compact`, `normal`, or `spacious`.
- `width` must be one of `narrow`, `normal`, `wide`, or `full`.
- `blocks` must be an ordered list of valid block instances.
- `savedAs` is a reusable-pattern key and not executable content.
- A document may contain empty sections in a draft, but publishing may optionally reject trailing empty sections.
- A section must not contain another section. Nested arrangement belongs to a block contract such as `columns` or `container`.

## 4. Block instance contract

A block instance is deliberately small:

```json
{
  "type": "hero",
  "props": {
    "eyebrow": "Nutrition made practical",
    "heading": "Build a healthier routine",
    "media": {
      "key": "hero.primary",
      "alt": "A bowl of fresh food",
      "focal": [0.5, 0.4]
    }
  },
  "style": {
    "visible": {
      "base": true,
      "md": true
    },
    "align": "start"
  }
}
```

The persisted block contract contains no React component name, PHP class name, HTML string, database query, or tenant ID. The stable `type` key resolves through the server and client registries.

### Block instance validation rules

- `type` is required and must match the registered block key format.
- `props` is required and must be a JSON object.
- `props` must be validated against the block’s `FieldSchema` and prop constraints.
- Unknown prop keys are rejected for newly authored blocks unless the block explicitly declares an extension namespace. This is stricter than the current permissive `FieldSchema::normalize()` behavior and prevents accidental data drift from the React editor.
- Existing unknown props may be preserved during a compatibility save only when the caller is performing a migration-safe edit and the server can associate them with the same block type. They must not be rendered as executable content.
- `style` is optional and limited to the declared style schema. Arbitrary CSS is not allowed in a block instance.
- A block must not contain `children` unless its registry definition declares a nested-block field.
- A block must not contain raw HTML in ordinary props. The dedicated `html` block remains permission-gated.
- A block must not contain a direct remote URL for media when a logical media reference can be used.
- A block must not contain tenant, user, permission, or server-generated audit fields.

## 5. Block definition contract

The PHP contract already exists as `Slate\Presentation\Block`:

```php
interface Block
{
    public function type(): string;
    public function schema(): FieldSchema;
    public function render(array $props, RenderContext $ctx): string;
}
```

The Phase 2 React builder needs a transport-safe metadata form of the same contract. The server should expose a registry projection, not serialize PHP objects or closures.

### 5.1 Registry metadata

```json
{
  "type": "hero",
  "version": "1.2.0",
  "label": "Hero",
  "category": "Content",
  "icon": "image",
  "description": "A prominent heading with supporting text and media.",
  "capabilities": {
    "responsive": true,
    "nested": false,
    "dynamic": false,
    "serverOnly": false
  },
  "permissions": [],
  "fields": [],
  "defaults": {},
  "preview": {
    "placeholder": "hero"
  }
}
```

The metadata projection may include localized labels and editor hints. It must not include executable PHP, arbitrary JavaScript, database credentials, or untrusted HTML templates.

### 5.2 Definition rules

- `type` is globally unique within the active registry.
- A module owns its block types and registers them through the core `blocks.register` extension point.
- Replacing an existing type requires an explicit compatible version and must be rejected when the schema is incompatible with published documents.
- `version` follows semantic versioning for the block contract.
- A major block-schema change requires a migration or a new type key.
- `schema()` defines editor fields and defaults.
- `render()` receives normalized props and returns an HTML fragment without echoing.
- A block must compose approved Components and consume theme tokens rather than hardcoding tenant-specific colors or fonts.
- A block must not arrange sibling blocks or access page chrome.
- A block must not query the database directly. Dynamic data must be resolved through a service before rendering.
- The client editor may render a preview component, but the server remains the publication authority.

## 6. Field schema contract

A field descriptor is the shared contract between the PHP registry, the React form generator, and server validation.

```json
{
  "key": "heading",
  "type": "text",
  "label": "Heading",
  "required": true,
  "maxLength": 160,
  "default": "",
  "help": "Keep the heading concise.",
  "responsive": false
}
```

### 6.1 Required field descriptor members

| Member | Type | Rule |
|---|---|---|
| `key` | string | Unique within the block; lower camel case or snake case. |
| `type` | enum | Must be a supported field type. |
| `label` | string | Human-readable and translatable. |
| `required` | boolean | Defaults to false. |
| `default` | JSON value | Must pass the same validator as the field. |
| `responsive` | boolean | Defaults to false. |

### 6.2 Initial field types

| Type | Stored value | Validation |
|---|---|---|
| `text` | string | Length and optional pattern constraints. HTML escaped on render. |
| `textarea` | string | Length limit; line breaks preserved as text. |
| `richtext` | structured value | Allowlisted marks/nodes only; no arbitrary tags or attributes. |
| `number` | number | Minimum, maximum, integer, and step constraints. |
| `boolean` | boolean | No string coercion except defined form transport values. |
| `select` | scalar | Must match one declared option or a server-resolved option. |
| `url` | string | Relative or approved absolute URL; reject dangerous schemes. |
| `colorToken` | string | Must match a theme token name; raw colors require a separate permissioned field type. |
| `media` | object | Logical media key, alt text, and bounded focal coordinates. |
| `repeater` | array | Each item validates against its nested field schema and has a maximum count. |
| `blocks` | array | Nested block instances only when the block declares this capability. |
| `postReference` | object | Tenant-scoped ID/type reference resolved by the server. |
| `icon` | string | Must match an allowlisted icon key. |

The initial release should not support arbitrary `object`, `function`, `script`, `expression`, or `html` field types.

### 6.3 Field validation rules

Every field validator must validate the raw JSON type before coercion. Form values such as `"0"` and `"false"` must not silently become truthy values.

The validator must enforce:

- Maximum string length.
- Maximum array length.
- Maximum object property count.
- Maximum nesting depth.
- Allowed enum values.
- Numeric range and precision.
- Pattern constraints.
- URL scheme and host rules.
- Media-key ownership and tenant scope.
- Nested field recursion limits.
- No control characters except permitted whitespace.
- No serialized PHP, JavaScript, SQL, or template expressions.

The server may normalize safe presentation values such as line endings or numeric integer forms, but normalization must not turn invalid content into valid content without reporting the correction to the caller.

## 7. Responsive values

Responsive behavior must be data-driven and token-based. The builder should not store viewport-specific CSS strings.

### 7.1 Representation

A field that declares `responsive: true` accepts either one scalar value or a breakpoint map:

```json
"padding": "normal"
```

or:

```json
"padding": {
  "base": "compact",
  "md": "normal",
  "lg": "spacious"
}
```

The supported breakpoint keys are:

```text
base, sm, md, lg
```

The effective value uses mobile-first fallback:

```text
lg → md → sm → base
```

The builder may display Desktop, Tablet, and Mobile controls, but those labels map to named breakpoints. They must not be persisted as arbitrary pixel widths.

### 7.2 Responsive rules

- Only fields declared responsive by the block schema may receive breakpoint maps.
- A breakpoint map must contain at least one key.
- Unknown breakpoint keys are invalid.
- Each breakpoint value is validated using the field’s base validator.
- Responsive maps may not be nested inside another responsive map.
- `base` is the fallback value for all larger breakpoints.
- The server renderer and React renderer must use identical fallback rules.
- Responsive visibility is allowed only through the `style.visible` contract or a declared boolean field.
- Arbitrary media queries, CSS declarations, and viewport strings are prohibited in content JSON.

## 8. Media references

Media must be referenced by logical key rather than a storage URL:

```json
{
  "key": "hero.primary",
  "alt": "A bowl of fresh food",
  "focal": [0.5, 0.4]
}
```

Rules:

- `key` is required and tenant-scoped.
- `alt` is required for meaningful images and may be empty only for explicitly decorative media.
- `focal` contains two numbers between `0` and `1`.
- The renderer resolves the key to a tenant-authorized URL.
- New editor writes must not use raw `src` or `image` URL props.
- Legacy raw URL props may be read through a compatibility resolver but must not be emitted by the new builder.
- A media reference must not permit path traversal or arbitrary filesystem access.

## 9. Nested blocks and dynamic blocks

Nested blocks are an explicit capability, not an accidental consequence of arbitrary JSON.

A container block may declare:

```json
{
  "capabilities": {
    "nested": true,
    "allowedChildren": ["heading", "paragraph", "button", "image"]
  }
}
```

Validation must enforce:

- Maximum nesting depth of 8.
- Maximum total block count of 1,000 per document.
- Allowed child types.
- No cycles, because the document is a tree and references are by value.
- No nested block type that requires a permission the caller lacks.
- The same block schema validation at every nesting level.

Dynamic blocks such as `post-list` must not put fetched records into the canonical document. The API may return a sibling `resolved` map keyed by stable section position, as already specified by ADR-0013:

```json
{
  "resolved": {
    "s1.2": {
      "items": []
    }
  }
}
```

The resolved map is request output, not persisted editor content. It must be generated with the caller’s tenant and publication permissions.

## 10. Validation pipeline

Every save, preview, publish, import, and MCP operation must use the same server validation pipeline.

```text
request JSON
  → transport/body validation
  → tenant and authorization context
  → document shape validation
  → section/layout validation
  → block registry resolution
  → block prop validation
  → nested-block validation
  → media/reference validation
  → permission filtering
  → canonical normalization
  → semantic validation
  → revision/save
  → cache invalidation
```

### 10.1 Validation stages

| Stage | Responsibility | Failure behavior |
|---|---|---|
| Transport | Confirm valid JSON and request size | Reject with `400` |
| Context | Resolve tenant, user, role, and content target | Reject with `401`/`403` |
| Shape | Validate document envelope and basic types | Return field-path errors |
| Registry | Resolve every block type | Reject unknown blocks on publish; preserve only under migration policy |
| Props | Validate each block against `FieldSchema` and constraints | Return block field-path errors |
| Security | Enforce HTML, URL, media, nested-block, and permission rules | Reject or strip only explicitly migratable fields |
| Normalize | Produce canonical deterministic JSON | Return canonical document and warnings |
| Semantic | Validate template, references, required content, and publish rules | Reject publish or save according to policy |
| Persistence | Store revision and current draft | Transactional failure; no partial save |

### 10.2 Error format

Validation errors should be machine-readable and usable by the React editor:

```json
{
  "ok": false,
  "error": "validation_failed",
  "errors": [
    {
      "path": "sections[0].blocks[1].props.heading",
      "code": "max_length",
      "message": "Heading must be 160 characters or fewer.",
      "expected": 160,
      "actual": 193
    }
  ],
  "warnings": []
}
```

Paths must identify sections, blocks, and fields. Error messages may be localized, but `code` and `path` must remain stable.

### 10.3 Save versus publish policy

Draft saves may allow incomplete content while maintaining structural validity. Publishing requires:

- No unknown block types.
- No invalid or unauthorized block props.
- No unresolved required media references.
- No invalid template reference.
- No privileged raw HTML without `content.publish`.
- No invalid dynamic-block configuration.
- No duplicate section IDs.
- No tenant-crossing references.

An unknown block in an existing published document may render a safe placeholder or empty state for compatibility, but a publish operation that introduces a new unknown block must fail.

## 11. Security rules

### 11.1 Tenant isolation

Tenant context is obtained from the authenticated request and never trusted from document JSON. The validator must reject or ignore any attempted `tenant_id`, `site_id`, owner, or user override inside the document.

All references are resolved within the active tenant:

- Media keys
- Post references
- Menu references
- Template parts
- Dynamic data
- Revisions
- Cached preview output

A document ID from another tenant must resolve as not found, not as a permission distinction that leaks existence.

### 11.2 Raw HTML and React escape hatches

The `html` block is the only block permitted to carry raw markup, and it requires the `content.publish` capability. This authorization is enforced on the server during save and publish. Client-side hiding is not a security boundary.

The `react` block may carry a registered component name and JSON props, but it must also declare an approved server-rendered fallback. Component names are allowlisted registry keys. The fallback is escaped text or a server-approved fragment, never arbitrary client markup.

### 11.3 URLs and classes

URL fields must reject:

```text
javascript:
data:
file:
vbscript:
```

unless a future field type explicitly defines a safe, validated data URL use case. CSS class fields are restricted to an allowlisted grammar and must not accept CSS declarations, selectors, or injection syntax.

### 11.4 Resource limits

The server must enforce limits before recursive validation:

| Resource | Proposed limit |
|---|---:|
| Request body | 2 MiB |
| Document JSON | 1 MiB |
| Sections | 200 |
| Blocks per section | 100 |
| Total blocks | 1,000 |
| Nesting depth | 8 |
| Props per block | 128 |
| Repeater items | 100 per field |
| String value | 100,000 characters, field-specific limits are lower |
| SEO properties | 64 |

These limits are configuration values, but lowering them must remain backward-compatible for existing documents.

## 12. React builder contract

The React builder is a client of the document and registry APIs.

### 12.1 Registry endpoint

The builder should receive a tenant-authorized registry projection:

```json
{
  "schema": 1,
  "blocks": [],
  "layout": {
    "backgroundTokens": [],
    "paddingTokens": ["compact", "normal", "spacious"],
    "widthTokens": ["narrow", "normal", "wide", "full"]
  },
  "breakpoints": ["base", "sm", "md", "lg"]
}
```

Only blocks the current user may add should appear in the palette. The server must still revalidate a submitted document because a user may submit a handcrafted request.

### 12.2 DnD state serialization

Craft.js and dnd-kit state is an editor implementation detail. The bridge must maintain a one-way serializer:

```text
editor node tree + transient UI state
  → validate node type and parent capability
  → read canonical block props
  → derive ordered sections and blocks
  → strip node IDs, selection, drag handles, measurements, and history
  → DocumentSchema::normalize()
  → server validator
```

The serializer must reject an editor node with no registered block type, duplicate canonical placement, an unauthorized nested child, or a non-serializable prop. It must not copy arbitrary node metadata into `props`.

### 12.3 Immutable operations and exact paths

Client edits must dispatch immutable operations whose paths use the same addressing convention as server validation:

```text
sections[0].blocks[1].props.heading
sections[0].blocks[1].style.visible.md
```

`insertBlock`, `moveBlock`, and `updateBlockProps` must produce a new document value and a structured operation record. The server may return a canonical replacement document after save; the client must reconcile against that response rather than patching transient editor state into the returned JSON.

### 12.4 Editing operations

The client should manipulate pure document operations rather than arbitrary object mutation:

```text
insertSection(index, section)
removeSection(sectionId)
moveSection(sectionId, destinationIndex)
updateSectionLayout(sectionId, patch)
insertBlock(sectionId, index, block)
removeBlock(sectionId, blockPath)
moveBlock(sourcePath, destinationPath)
updateBlockProps(blockPath, patch)
updateBlockStyle(blockPath, patch)
```

Each operation should be replayable for undo/redo and should produce a new immutable document state. The client may optimistically update its local state, but save responses must replace it with the server’s canonical normalized document.

### 12.3 Preview

Preview must call the same rendering pipeline as public output with:

- Working revision as the content source.
- Cache bypassed.
- `noindex` enabled.
- Tenant and user authorization preserved.
- Draft-only references blocked from public output.

The React canvas may provide an interactive editing shell, but the actual preview should be generated by the shared renderer or a renderer proven equivalent through fixtures. The builder must not maintain an independent visual interpretation of block props.

## 13. Static HTML compilation and parity

`content_html` is a tenant-scoped derived artifact. It is never accepted as authoritative input from the browser or MCP caller.

A compilation record should carry at least:

```json
{
  "content_html": "<main>...</main>",
  "content_fingerprint": "sha256:...",
  "renderer_version": "1.0.0",
  "theme_version": "42",
  "dependencies": ["content:17", "global:41", "theme:9"]
}
```

The compiler receives the canonical document, resolved theme, published global blocks, tenant context, and renderer registry. It must use the same block rendering path as preview and public fallback rendering. It must not execute browser-only editor code.

The public request path follows this rule:

```text
published row + matching fingerprint + content_html → serve content_html
otherwise → compile synchronously, persist derived artifact, serve result
```

The public artifact must be zero-JavaScript by default. Interactive behavior may be progressively enhanced by separately registered assets, but content rendering must not require React, Craft.js, dnd-kit, or a browser runtime. A stale or missing artifact is a cache miss, not permission to serve stale content.

## 13. Versioning and migrations

There are two independent version axes:

1. **Document schema version**, currently `1`, which changes only when the envelope or tree contract changes.
2. **Block contract version**, owned by each block type and changed when its props or rendering contract changes.

A block schema change must follow these rules:

| Change | Required action |
|---|---|
| Add optional field with default | Minor block version; old documents remain valid |
| Add new enum option | Minor version if renderers support it safely |
| Rename field | Migration or compatibility alias |
| Change stored type | Migration or new block type |
| Remove field | Deprecate, migrate, then remove in a major version |
| Change meaning of existing value | New block type or explicit migration |
| Change renderer only | Patch version if output remains contract-compatible |

The server should store migration metadata with revisions or block registry definitions, but the document should not contain arbitrary migration scripts.

### Unknown blocks

- Read/render: preserve the original instance for round-trip safety and render a safe placeholder or empty state.
- Draft save: allow preservation only when the unknown block was already present and unchanged.
- New insertion: reject unless the type is registered and authorized.
- Publish: reject any unknown block that was newly introduced or modified.
- Migration: use a registered server migration, never client-side ad hoc transformation.

## 14. Persistence and cache invalidation

The stored JSON is canonical after server normalization. The server may additionally store compiled HTML, but compiled HTML is a cache and never the source of truth.

A successful save should:

1. Validate and normalize the document.
2. Create a revision snapshot.
3. Persist the working document transactionally.
4. Compile the canonical document through the shared renderer into `content_html`.
5. Persist `content_html` only after compilation succeeds.
6. Emit a content-saved event.
7. Invalidate tenant-scoped cache tags for the content row and referenced blocks.
8. Return the canonical document, revision, compiled HTML metadata, warnings, and cache metadata.

`normalize()` itself remains pure and side-effect free. The save/publish application service invokes normalization followed immediately by compilation in the same transaction boundary. This preserves deterministic normalization while guaranteeing that a successful write does not leave stale `content_html`.

A global-block save or publish performs dependency invalidation for every tenant-scoped page and template-part reference, then recompiles affected `content_html` records before marking the operation complete.

A successful publish should additionally invalidate public cache tags and update publication metadata.

Suggested cache tags include:

```text
tenant:{tenantId}
content:{contentId}
revision:{revisionId}
block:{blockType}
template:{templateKey}
theme:{themeVersion}
media:{mediaKey}
```

## 15. Compatibility with existing Slate code

This design intentionally aligns with the current repository:

- `DocumentSchema` remains the single persisted-layout normalizer.
- `Section` remains the first-class layout container.
- `LayoutSpec` remains token-based and pixel-free.
- `FieldSchema` remains the block editor schema source.
- `Block` and `BlockRegistry` remain the server contracts.
- `DocumentTemplate` remains the full-document assembly layer.
- `ContentCoreBridge` can adapt archived content-builder blocks into the core registry.
- Legacy flat arrays remain readable as one implicit section.
- Dynamic block results remain outside the document in a `resolved` sibling map.

The primary implementation change required by Phase 2 is not a new content format. It is the addition of a strict validator, a transport-safe registry projection, a React operation model, and parity fixtures for the shared renderer.

## 16. Acceptance criteria

Phase 2 is complete when all of the following hold:

1. The server accepts and returns canonical schema-1 document envelopes.
2. Normalization is deterministic and idempotent.
3. Legacy flat layouts still render through an implicit section.
4. Every active block has a typed field schema and a server renderer.
5. The React builder generates forms from registry metadata rather than block-specific editor code.
6. The server rejects unknown block types on new writes and publish.
7. The server enforces block permissions independently of the client.
8. Responsive values use named breakpoints and identical fallback rules in both renderers.
9. Media uses tenant-scoped logical keys for new writes.
10. Nested blocks have explicit capability declarations and depth/count limits.
11. Raw HTML remains capability-gated server-side.
12. Preview uses the same rendering pipeline and tenant context as public output.
13. Draft saves create revisions and return canonical normalized JSON.
14. Published content invalidates tenant-scoped cache tags.
15. HTML and React renderers pass the same document fixtures and produce semantically equivalent output.
16. A tenant cannot read, reference, preview, or publish another tenant’s content, media, template, or dynamic data.

## 17. Recommended implementation sequence

The implementation should proceed in this order:

1. Freeze the schema-1 envelope and write JSON fixtures for valid, invalid, legacy, responsive, nested, media, dynamic, and privileged documents.
2. Add a pure validator around `DocumentSchema`, `LayoutSpec`, `FieldSchema`, and the block registry.
3. Add block-specific constraints without changing the existing PHP `Block` interface.
4. Add a transport-safe registry projection for the React editor.
5. Implement immutable client operations and server canonicalization round trips.
6. Implement responsive field widgets using the named breakpoint contract.
7. Add preview requests through the existing rendering pipeline.
8. Add revision and cache invalidation integration.
9. Add unknown-block compatibility handling and registered migrations.
10. Add parity tests for server HTML and React output against shared fixtures.

## 18. Discipline-gated implementation status

The contract-first portion of Steps 1–9 is now represented in the core presentation layer without bypassing the repository's service and persistence boundaries:

| Area | Implemented contract |
|---|---|
| Schema and field validation | `DocumentValidator` with strict types, limits, media checks, responsive tokens, and legacy unknown-block compatibility |
| Registry projection | `BlockRegistryProjection` exposes only transport-safe block metadata and `FieldSchema` data |
| Client bridge | `DocumentOperations` provides immutable insert, move, and prop-update operations over canonical documents |
| Derived HTML seam | `ContentCompiler` defines the renderer capability; `CompilationMetadata` produces deterministic dependency fingerprints |
| Save/publish orchestration | `ContentPublicationService` snapshots, compiles, stores dependencies, and commits atomically |
| Preview/public delivery | `PreviewService` uses no-store/noindex; `PublicContentService` serves only fresh compiled HTML and recompiles stale artifacts |
| Global invalidation | `DependencyInvalidationService` enumerates tenant-scoped dependents and delegates recompilation |
| Unknown blocks | Existing unchanged unknown blocks may be preserved with an explicit compatibility option; new or modified unknown blocks fail closed |
| Architecture record | ADR-0014 records the single-document and derived-HTML decisions |

The React UI itself remains a client integration concern; its server-facing serializer and immutable operation contract are implemented. Database-backed revisions, compilation artifacts, dependency indexes, save/publish orchestration, preview behavior, and the public `content_html` freshness gate are now application-service seams backed by migration `0018`. Wiring these services into a specific HTTP route or admin screen remains a deployment/composition-root concern, not a change to the canonical document model.

## References

[1]: ../src/Presentation/DocumentSchema.php "Slate DocumentSchema implementation"

[2]: ../src/Presentation/Block.php "Slate Block contract"

[3]: ../src/Presentation/BlockRegistry.php "Slate BlockRegistry contract"

[4]: ../src/Presentation/FieldSchema.php "Slate FieldSchema implementation"

[5]: ../src/Presentation/LayoutSpec.php "Slate LayoutSpec implementation"

[6]: ../src/Presentation/Section.php "Slate Section implementation"

[7]: 05-Rendering/blocks-and-sections.md "Slate Blocks and Sections design"

[8]: 05-Rendering/page-builder.md "Slate Visual Page Builder design"

[9]: 05-Rendering/rendering-pipeline.md "Slate Rendering Pipeline design"

[10]: 14-ADR/0013-content-document-and-content-api.md "ADR-0013 — The content document and the content API"
