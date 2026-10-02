# KOHEVO STUDIO BUILDER — ALL-IN-ONE MASTER IMPLEMENTATION SPECIFICATION

**Document:** `KOHEVO-STUDIO-BUILDER-MASTER-IMPLEMENTATION.md`  
**Status:** Master implementation specification  
**Target:** Kohevo Studio Builder  
**Goal:** Transform the existing Studio Builder foundation into a full-flexible, Elementor-class visual website builder while preserving Kohevo's tenant isolation, schema-driven documents, revisions, security boundaries, dynamic providers, package system, AI/MCP workflow, and public rendering architecture.

---

## 0. Executive Summary

Kohevo Studio Builder should evolve from a structured content/document builder into a complete visual website-building platform.

The target experience is:

> **Create → Structure → Drag/Drop → Configure → Style → Responsive → Dynamic → Preview → Review → Publish**

The system must support:

- arbitrary nested layouts;
- sections, containers, flex, grid, columns and stacks;
- a first-class widget/component registry;
- Elementor-style Content / Style / Advanced controls;
- desktop/tablet/mobile responsive values;
- global design tokens and global styles;
- reusable components and templates;
- headers, footers, single templates, archives, search, 404 and other theme templates;
- dynamic content and query/loop rendering;
- forms and actions;
- media, galleries, video and embeds;
- interactions and animations through declarative configuration;
- popups, modals and off-canvas UI;
- custom CSS classes/attributes;
- developer-extensible widgets;
- safe third-party extensions;
- import/export;
- revision history and optimistic concurrency;
- AI/MCP authoring with human-controlled publishing;
- strong tenant isolation;
- safe rendering and sanitization;
- production-grade testing, observability and deployment hardening.

### Non-negotiable principle

**Do not replace the current Studio Builder core. Extend it.**

The existing foundation already provides valuable architecture around:

- tenant-scoped persistence;
- canonical document/schema validation;
- immutable revisions;
- optimistic concurrency;
- rendering/compilation;
- dynamic data-provider boundaries;
- package validation;
- HTML/CSS import boundaries;
- rich-text sanitization;
- permissions;
- MCP/AI controls.

The implementation should build a visual composition layer above those foundations.

---

# 1. Product Vision

## 1.1 Product definition

Kohevo Studio Builder is a:

> **Schema-driven, extensible, responsive visual website builder and website composition runtime.**

It is not merely a page editor.

A complete implementation should allow a non-developer to create an entire website while allowing developers to extend the system without modifying core builder code.

## 1.2 Target user experience

The editor should resemble a professional visual builder:

```text
┌─────────────────────────────────────────────────────────────────────┐
│ Logo │ Pages │ Templates │ Preview │ Device │ Undo │ Redo │ Publish │
├───────────────┬───────────────────────────────────────┬─────────────┤
│               │                                       │             │
│   Widgets     │              Canvas                   │  Inspector  │
│               │                                       │             │
│ Search        │       ┌─────────────────────┐         │ Content     │
│               │       │                     │         │ Style       │
│ Layout        │       │       WEBSITE       │         │ Advanced    │
│ Basic         │       │                     │         │             │
│ Media         │       │                     │         │             │
│ Content       │       └─────────────────────┘         │             │
│ Forms         │                                       │             │
│ Marketing     │                                       │             │
│ Pro/Custom    │                                       │             │
│               │                                       │             │
└───────────────┴───────────────────────────────────────┴─────────────┘
```

## 1.3 Success definition

A user should be able to create, without writing code:

- portfolio sites;
- agency sites;
- SaaS marketing sites;
- corporate sites;
- landing pages;
- blogs;
- magazines;
- documentation-style pages;
- directories;
- membership-style pages;
- product/catalog sites;
- campaign sites;
- highly custom editorial layouts.

A developer should additionally be able to extend the system with custom widgets and data providers.

---

# 2. Architecture Principles

## 2.1 Preserve existing core

Do not bypass:

- `StudioRepository`;
- tenant context;
- canonical document validation;
- revision repository;
- application service;
- rendering pipeline;
- permission system;
- dynamic provider registry;
- package format;
- sanitization layer;
- MCP adapter.

All new capabilities must flow through these boundaries.

## 2.2 Schema first

The builder must not store arbitrary editor state as the source of truth.

The source of truth is a validated canonical document.

```text
Editor UI
   ↓
Editor State
   ↓
Operation / Command
   ↓
Application Service
   ↓
Canonical Document
   ↓
Validation
   ↓
Revision
   ↓
Renderer
   ↓
Public HTML
```

## 2.3 Declarative over executable

Widgets, layouts, interactions and dynamic bindings must be represented as declarative data.

Do not allow arbitrary JavaScript execution from saved page documents.

## 2.4 Server remains authoritative

The browser may provide:

- preview;
- editing;
- drag/drop;
- local transient state;
- optimistic UI.

The server remains authoritative for:

- permissions;
- schema validity;
- tenant ownership;
- revision state;
- publish state;
- dynamic data access;
- package validation;
- public rendering.

## 2.5 Extension points must be explicit

Third-party widgets should register through a controlled extension API.

Never create generic arbitrary PHP class/function execution based on user-controlled document values.

---

# 3. Target System Architecture

```text
                         KOHEVO STUDIO
                              │
       ┌──────────────────────┼──────────────────────┐
       │                      │                      │
   Authoring UI          Application Layer       Public Runtime
       │                      │                      │
       ├─ Canvas              ├─ Documents          ├─ Renderer
       ├─ Navigator           ├─ Operations         ├─ Compiler
       ├─ Widgets             ├─ Revisions          ├─ Dynamic Data
       ├─ Inspector           ├─ Templates          ├─ Cache
       ├─ Responsive          ├─ Permissions        └─ SEO
       └─ Preview             └─ Packages
                              │
                  ┌───────────┼───────────┐
                  │           │           │
             Widget API   Provider API  Extension API
                  │           │           │
                  └───────────┼───────────┘
                              │
                      Tenant / Security
```

---

# 4. Canonical Document Model

The canonical document is the heart of the system.

## 4.1 Example document

```json
{
  "schema": "kohevo.studio.document",
  "version": 2,
  "id": "page_home",
  "type": "page",
  "settings": {
    "title": "Home",
    "layout": "default"
  },
  "nodes": [
    {
      "id": "section_1",
      "type": "layout.section",
      "props": {},
      "style": {},
      "responsive": {},
      "children": [
        {
          "id": "container_1",
          "type": "layout.container",
          "props": {},
          "children": [
            {
              "id": "heading_1",
              "type": "core.heading",
              "props": {
                "text": "Build something remarkable"
              },
              "style": {
                "typography": {
                  "size": {
                    "desktop": "64px",
                    "tablet": "48px",
                    "mobile": "36px"
                  }
                }
              }
            }
          ]
        }
      ]
    }
  ]
}
```

## 4.2 Node requirements

Every node should support:

```text
id
type
props
style
responsive
visibility
children
bindings
conditions
attributes
classNames
metadata
```

Not every field needs to be persisted when empty.

## 4.3 Stable IDs

Node IDs must:

- be unique within a document;
- remain stable across normal edits;
- survive undo/redo where semantically appropriate;
- be safe for references;
- never encode tenant IDs or sensitive information.

---

# 5. Layout Engine

The layout engine is the highest-priority new capability.

## 5.1 Required layout primitives

### Section

Responsibilities:

- full-width region;
- background;
- width constraints;
- spacing;
- positioning;
- responsive behavior.

### Container

Responsibilities:

- max-width;
- width;
- alignment;
- padding;
- responsive behavior.

### Flex

Controls:

- direction;
- wrap;
- justify-content;
- align-items;
- align-content;
- gap;
- order;
- grow;
- shrink;
- basis.

### Grid

Controls:

- columns;
- rows;
- template;
- gap;
- alignment;
- auto placement;
- responsive column counts.

### Stack

Controls:

- vertical/horizontal direction;
- gap;
- alignment.

### Columns

A convenience abstraction over flex/grid.

### Absolute layer

Advanced layout capability:

- position;
- inset;
- width;
- height;
- z-index;
- anchor/reference.

Use only where needed and keep it declarative.

## 5.2 Nesting

Nesting must be effectively unlimited subject to safe document/resource limits.

Example:

```text
Section
 └─ Container
     └─ Grid
         ├─ Card
         │   ├─ Image
         │   ├─ Heading
         │   └─ Button
         └─ Card
             ├─ Image
             ├─ Heading
             └─ Button
```

## 5.3 Layout constraints

Support:

- min-width;
- max-width;
- min-height;
- max-height;
- aspect ratio;
- overflow;
- display;
- visibility;
- position;
- z-index.

---

# 6. Widget System

## 6.1 Widget registry

Create a first-class registry:

```text
WidgetRegistry
 ├─ register()
 ├─ get()
 ├─ has()
 ├─ all()
 ├─ categories()
 └─ resolve()
```

A widget definition should contain:

```text
key
version
category
label
icon
description
schema
controls
defaults
renderer
editorPreview
supports
permissions
assets
```

## 6.2 Widget categories

### Basic

- Heading
- Text
- Rich Text
- Button
- Link
- Image
- Icon
- Divider
- Spacer

### Layout

- Section
- Container
- Flex
- Grid
- Columns
- Stack

### Media

- Image
- Gallery
- Carousel
- Video
- Audio
- Lightbox
- SVG
- Lottie

### Content

- Post List
- Post Grid
- Post Card
- Author
- Category
- Tags
- Breadcrumbs
- Search
- Pagination
- Related Content

### Marketing

- Hero
- CTA
- Features
- Testimonials
- Team
- Logo Grid
- Pricing
- Stats
- Progress
- Countdown
- Comparison

### Navigation

- Header
- Navigation Menu
- Mobile Menu
- Breadcrumb
- Footer
- Sidebar

### Forms

- Form
- Input
- Textarea
- Select
- Checkbox
- Radio
- File Upload
- Submit

### Advanced

- HTML-safe block
- Dynamic Content
- Query Loop
- Conditional Visibility
- Anchor
- Popup trigger
- Custom attributes

### Future Commerce

- Product
- Product Grid
- Product Image
- Price
- Sale Price
- Add to Cart
- Cart
- Checkout
- Account
- Product Search
- Product Categories

---

# 7. Widget Control System

Every widget should expose structured controls.

## 7.1 Control groups

```text
Content
Style
Layout
Responsive
Advanced
Dynamic
Conditions
```

## 7.2 Example Button controls

```text
Content
 ├─ Text
 ├─ URL
 ├─ Link target
 └─ Icon

Style
 ├─ Typography
 ├─ Text color
 ├─ Background
 ├─ Border
 ├─ Radius
 └─ Shadow

Layout
 ├─ Width
 ├─ Height
 ├─ Alignment
 ├─ Margin
 └─ Padding

Advanced
 ├─ CSS class
 ├─ ID
 ├─ Attributes
 ├─ Z-index
 └─ Position

Responsive
 ├─ Desktop
 ├─ Tablet
 └─ Mobile
```

## 7.3 Control types

Implement reusable controls:

- text;
- textarea;
- rich text;
- number;
- unit;
- color;
- typography;
- spacing;
- dimension;
- select;
- multi-select;
- toggle;
- slider;
- URL;
- icon picker;
- media picker;
- repeater;
- query builder;
- dynamic binding;
- responsive value;
- conditions;
- code-safe text/class attribute.

---

# 8. Responsive Engine

## 8.1 Required breakpoints

Default:

```text
Desktop
Tablet
Mobile
```

Allow project-level breakpoint configuration later.

## 8.2 Responsive value model

Values should support:

```json
{
  "desktop": "64px",
  "tablet": "48px",
  "mobile": "36px"
}
```

If a value is missing, inherit from the next wider breakpoint.

## 8.3 Responsive visibility

Support:

```text
show desktop
show tablet
show mobile
```

## 8.4 Responsive ordering

Support:

- grid order;
- flex order;
- alignment;
- direction;
- column count.

---

# 9. Global Design System

Create global tokens:

```text
Colors
Typography
Spacing
Sizes
Radii
Shadows
Container widths
Transitions
Breakpoints
```

Example:

```json
{
  "colors": {
    "primary": "#7C3AED",
    "text": "#18181B",
    "muted": "#71717A"
  },
  "radius": {
    "sm": "6px",
    "md": "10px",
    "lg": "16px"
  }
}
```

## 9.1 Token references

Nodes should be able to reference tokens:

```text
var(--studio-color-primary)
var(--studio-radius-md)
```

## 9.2 Global styles

Support global:

- headings;
- body;
- links;
- buttons;
- forms;
- containers.

---

# 10. Theme Builder

Theme Builder is mandatory for full-site capability.

## 10.1 Template types

Support:

```text
Header
Footer
Single Page
Single Post
Archive
Category
Tag
Author
Search
404
Custom Content Type
Product
Product Archive
```

## 10.2 Template conditions

Example:

```text
Template:
Single Post

Condition:
Post Type = Article
```

Another:

```text
Template:
Product Header

Condition:
Product Category = Shoes
```

Conditions must be declarative and server-validated.

## 10.3 Template precedence

Define deterministic precedence:

```text
Specific template
      ↓
Content-type template
      ↓
Global template
      ↓
Default system template
```

---

# 11. Dynamic Content System

The existing provider registry should become the foundation of a complete dynamic binding system.

## 11.1 Binding model

```json
{
  "binding": {
    "provider": "post",
    "path": "title"
  }
}
```

## 11.2 Supported sources

Initially:

- current page;
- current post;
- author;
- taxonomy;
- site settings;
- registered data providers.

Future:

- products;
- users;
- custom content types;
- external API providers through controlled integrations.

## 11.3 Binding safety

Never permit arbitrary:

```text
class
function
method
SQL
file
URL fetch
```

selection from user-controlled document data.

Only registered providers may execute.

---

# 12. Query Builder / Loop

## 12.1 Query configuration

```text
Source
Content type
Filters
Taxonomy
Search
Author
Date
Ordering
Limit
Pagination
```

## 12.2 Loop renderer

Example:

```text
Query
 └─ Grid
     └─ Card template
          ├─ Featured Image
          ├─ Title
          ├─ Excerpt
          └─ Button
```

## 12.3 Query limits

Every query must have:

- maximum result count;
- execution timeout;
- permission checks;
- tenant context;
- safe parameter normalization.

---

# 13. Reusable Components

Support:

```text
Global Component
Local Component
Detached Instance
```

## 13.1 Global component

Changing the source updates all instances.

## 13.2 Local component

Reusable within one document/site scope.

## 13.3 Detach

Convert a global component instance into an independent node tree.

---

# 14. Templates and Sections Library

Users should be able to save:

- sections;
- blocks;
- cards;
- headers;
- footers;
- page templates;
- complete site templates.

Each template needs:

```text
id
name
type
schema version
thumbnail
tags
category
document payload
created_by
updated_at
```

---

# 15. Navigator / Layers Panel

Implement a tree view:

```text
Page
├── Header
├── Hero
│   ├── Container
│   │   ├── Heading
│   │   └── Button
├── Features
│   ├── Card
│   ├── Card
│   └── Card
└── Footer
```

Features:

- select;
- rename;
- hide;
- lock;
- duplicate;
- move;
- delete;
- collapse;
- drag/reorder.

---

# 16. Drag and Drop

## 16.1 Requirements

Support:

- widget → canvas;
- widget → container;
- node → node;
- reorder;
- nesting;
- duplicate;
- move across containers.

## 16.2 Drop validation

A widget must declare:

```text
allowed parents
allowed children
maximum nesting
```

The server must validate resulting documents.

---

# 17. Selection and Editing Model

Support:

- single selection;
- multi-selection where safe;
- parent selection;
- breadcrumb selection;
- inline editing;
- keyboard navigation.

Example:

```text
Canvas
 → Section
   → Container
     → Heading
```

Clicking the heading should expose its exact node in the inspector.

---

# 18. Undo / Redo

Use operation-based history.

Each operation should be:

```text
operation
target node
before
after
metadata
timestamp
```

The server does not need to store every browser keystroke.

Persist meaningful document revisions server-side.

---

# 19. Autosave

Autosave should:

- debounce writes;
- use expected revision ID;
- fail safely on stale revision;
- never silently overwrite newer server state;
- display save status.

States:

```text
Saved
Saving
Unsaved
Conflict
Failed
```

---

# 20. Revision and Publishing Model

Maintain:

```text
Draft
Review
Published
Archived
```

AI-created changes remain draft unless explicitly published by an authorized human.

Publishing requires:

- permission;
- valid document;
- valid revision;
- tenant context;
- render validation.

---

# 21. Preview System

Support:

```text
Desktop
Tablet
Mobile
Published
Draft
```

Preview should never bypass server-side validation.

---

# 22. Forms

Create a declarative form system.

## 22.1 Field types

- text;
- email;
- number;
- tel;
- URL;
- textarea;
- select;
- checkbox;
- radio;
- file.

## 22.2 Form actions

Initially:

- internal submission;
- email notification through approved integration;
- success state.

Future:

- webhook through approved integration;
- CRM integrations.

Never allow arbitrary server-side code as a form action.

---

# 23. Interactions and Animation

Use a declarative model.

Example:

```json
{
  "interaction": {
    "trigger": "hover",
    "animation": {
      "property": "transform",
      "to": "scale(1.03)",
      "duration": 300
    }
  }
}
```

Supported triggers:

```text
hover
focus
click
viewport-enter
```

Future:

```text
scroll
load
```

Animations must be bounded and sanitized.

Respect:

```text
prefers-reduced-motion
```

---

# 24. Popup / Modal / Offcanvas

Support:

- modal;
- popup;
- drawer;
- announcement bar;
- newsletter popup.

Triggers:

- button click;
- link click;
- page load;
- viewport enter.

Future triggers can include controlled exit-intent behavior.

---

# 25. SEO

Page-level settings:

```text
Title
Meta description
Canonical URL
Robots
Open Graph
Twitter/X metadata
```

Theme/system:

```text
Sitemap
Structured data
Breadcrumb schema
```

All generated metadata must be safely escaped.

---

# 26. Accessibility

Target:

- semantic HTML;
- keyboard navigation;
- visible focus;
- labels;
- alt text;
- heading hierarchy;
- ARIA only where needed;
- reduced motion;
- contrast-aware defaults.

The builder should warn users about obvious accessibility problems without blocking legitimate advanced use unless required for safety.

---

# 27. Security Architecture

## 27.1 Tenant isolation

Every read/write must remain tenant scoped.

Continue using the existing fail-closed tenant repository behavior.

Never accept client-provided tenant ownership as authoritative.

## 27.2 Permissions

Use granular capabilities:

```text
studio-builder.view
studio-builder.edit
studio-builder.publish
studio-builder.tokens
studio-builder.admin
```

Future:

```text
studio-builder.templates
studio-builder.manage-widgets
studio-builder.manage-theme
```

## 27.3 CSRF

All state-changing browser requests must require CSRF protection.

## 27.4 XSS

Sanitize:

- rich text;
- URLs;
- HTML attributes;
- SVG;
- imported markup;
- custom content.

Never trust editor data because it came from an authenticated editor.

## 27.5 SSRF

No arbitrary URL fetching from document content.

External references must remain references unless a specifically authorized integration fetches them.

## 27.6 SQL safety

All persistence must remain parameterized and repository/application-service based.

## 27.7 File safety

Uploads must validate:

- MIME;
- extension;
- size;
- path;
- tenant ownership;
- permissions.

Never permit filesystem traversal.

## 27.8 Rate limiting

Apply limits to:

- authoring reads;
- writes;
- renders;
- imports;
- package operations;
- dynamic providers;
- MCP operations;
- public expensive endpoints where applicable.

---

# 28. HTML/CSS Import

Retain the current safe import architecture.

Requirements:

- bounded source size;
- `LIBXML_NONET`;
- no arbitrary external network fetch;
- sanitized HTML;
- restricted CSS;
- canonical document conversion;
- dry-run;
- validation report.

Import must never persist arbitrary raw HTML as the trusted document source.

---

# 29. Package Import / Export

Package must support:

```text
Document
Templates
Components
Tokens
Media references
Metadata
```

Continue enforcing:

- no tenant ownership injection;
- size limits;
- item limits;
- media path validation;
- traversal prevention;
- hash/integrity validation.

---

# 30. Media System

Create a media abstraction:

```text
MediaLibrary
 ├─ image
 ├─ video
 ├─ audio
 ├─ svg
 └─ document
```

Widgets should reference media IDs/approved URLs rather than embedding unsafe filesystem paths.

Support:

- alt text;
- caption;
- focal point;
- responsive image sizes;
- lazy loading;
- dimensions.

---

# 31. Developer Widget SDK

Create a documented SDK.

Example conceptual registration:

```php
Studio::widgets()->register([
    'key' => 'acme.testimonial',
    'version' => '1.0.0',
    'category' => 'marketing',
    'label' => 'Testimonial',
    'schema' => [...],
    'controls' => [...],
    'renderer' => ...
]);
```

The actual implementation should follow Kohevo's existing plugin/module conventions.

## 31.1 Widget contract

Every widget must define:

```text
identity
schema
defaults
editor preview
server renderer
allowed children
controls
assets
version
```

## 31.2 Widget versioning

Widget definitions must be versioned.

Document migration should support:

```text
widget v1 → widget v2
```

Never silently reinterpret old saved documents.

---

# 32. Third-Party Extension Security

Third-party widgets must not automatically gain:

- database access;
- filesystem access;
- arbitrary network access;
- tenant switching;
- publish privileges.

Permissions and capabilities must be explicit.

---

# 33. Editor Performance

The editor should remain responsive with large documents.

Implement:

- normalized transient editor state where useful;
- memoized node rendering;
- virtualized navigator for very large trees;
- debounced persistence;
- lazy-loaded widget panels;
- lazy-loaded heavy widgets;
- minimal rerendering;
- incremental preview updates.

Set documented resource limits.

---

# 34. Public Rendering Performance

Public rendering should:

- generate deterministic HTML;
- avoid editor-only JavaScript;
- avoid unnecessary PHP sessions for anonymous requests;
- support cacheability;
- support asset bundling;
- minimize CSS/JS;
- use responsive images.

Do not let public rendering execute editor logic unnecessarily.

---

# 35. Asset Pipeline

Create a controlled asset manifest.

Each widget declares required:

```text
CSS
JS
fonts
icons
```

Only assets used by the document should be loaded where practical.

Avoid globally loading every widget's JavaScript.

---

# 36. Design System for the Editor UI

The builder UI should have:

```text
Left:
Widget Library

Center:
Canvas

Right:
Inspector

Top:
Global toolbar

Bottom/optional:
Responsive / status / breadcrumbs
```

Inspector tabs:

```text
Content
Style
Advanced
```

For advanced widgets:

```text
Dynamic
Conditions
```

---

# 37. Command / Operation API

Create a controlled command set:

```text
node.create
node.update
node.delete
node.move
node.duplicate
node.wrap
node.unwrap
style.update
responsive.update
binding.update
condition.update
template.insert
component.detach
```

Every command must:

1. validate target;
2. validate payload;
3. verify permission;
4. apply tenant context;
5. validate resulting document;
6. produce deterministic result;
7. support expected revision;
8. produce audit information where security relevant.

---

# 38. Collaboration Foundation

Not required for first release, but architecture should leave room for:

- lock state;
- presence;
- concurrent editing;
- conflict resolution.

The existing lock/revision architecture should remain compatible with future collaboration.

---

# 39. AI / MCP Integration

AI should operate through the same safe command layer.

Allowed examples:

```text
Create section
Insert widget
Change heading
Change colors
Create template
Generate content
Rearrange layout
```

AI must not receive unrestricted code execution.

## 39.1 AI workflow

```text
AI request
 ↓
Permission check
 ↓
Tenant context
 ↓
Command generation
 ↓
Schema validation
 ↓
Draft revision
 ↓
Human review
 ↓
Publish
```

---

# 40. Testing Strategy

Testing must be treated as a release requirement.

## 40.1 Unit tests

Cover:

- document schema;
- node validation;
- responsive values;
- style controls;
- widget schemas;
- query normalization;
- dynamic bindings;
- template conditions;
- package validation;
- URL validation;
- sanitization.

## 40.2 Integration tests

Cover:

- create document;
- edit;
- autosave;
- revision;
- publish;
- rollback;
- tenant isolation;
- permissions;
- dynamic provider execution;
- template rendering;
- package import/export;
- HTML import.

## 40.3 Security tests

Explicitly test:

- cross-tenant reads;
- cross-tenant writes;
- tenant ID injection;
- CSRF;
- XSS;
- unsafe URLs;
- SVG/script injection;
- HTML import attacks;
- CSS injection;
- path traversal;
- package traversal;
- oversized payloads;
- unauthorized publish;
- MCP scope escalation;
- dynamic provider abuse.

## 40.4 Browser tests

Use real browser verification for:

- drag/drop;
- responsive preview;
- widget insertion;
- inspector controls;
- undo/redo;
- autosave;
- template insertion;
- publish flow;
- mobile layout.

---

# 41. CI / Release Gates

A Studio Builder release should not be considered complete without:

```text
PHP unit tests
Integration tests
UI tests
Build
Static analysis
Security tests
Browser smoke tests
Package validation
```

The exact current `main` branch must have a fresh successful CI result before calling a release production-ready.

---

# 42. Production Hardening

Complete these items before declaring the full builder production-ready:

1. CSP using nonce/hash-compatible architecture.
2. Remove developer-only UI source from production packages where practical.
3. Ensure web-server rules cannot expose source files.
4. Remove unnecessary anonymous PHP session initialization.
5. Public abuse/rate limiting.
6. Media/cache invalidation strategy.
7. Slug/redirect handling.
8. Expanded SEO protections.
9. Complete localized public rendering.
10. Fresh CI verification for the exact release commit.
11. Deployment-level Apache/Nginx review.

---

# 43. Database / Persistence Model

The implementation should keep Studio-specific persistence isolated.

Recommended conceptual entities:

```text
studio_documents
studio_revisions
studio_templates
studio_components
studio_tokens
studio_locks
studio_media_refs
studio_audit_events
studio_widget_metadata
```

Exact names and migrations must follow the existing Kohevo conventions.

## 43.1 Important indexes

Plan indexes for:

```text
tenant_id
document_id
revision_id
status
slug
template_type
updated_at
created_by
```

Never create an index that permits tenant ambiguity in application queries.

---

# 44. Caching

Cache keys must include tenant and relevant document/template identity.

Example conceptual key:

```text
studio:{tenant}:{document}:{revision}:{locale}
```

Never use a cache key that can cause one tenant's rendered document to be returned to another tenant.

---

# 45. Internationalization

Architecture should support:

- locale;
- translated content;
- translated templates;
- locale-aware URLs;
- language metadata.

Future:

- hreflang;
- localized sitemap;
- locale-specific SEO metadata.

---

# 46. Localization-safe Document Model

Avoid storing UI labels inside canonical content when they should be translatable.

Use stable keys and locale-specific values.

---

# 47. Accessibility of Builder UI

The editor itself must support:

- keyboard navigation;
- accessible widget library;
- focus management;
- screen-reader labels;
- keyboard movement where feasible;
- accessible dialogs;
- accessible inspector controls.

---

# 48. Migration Strategy

Do not rewrite the existing Studio Builder in one large change.

Use incremental phases.

## Phase 1 — Foundation Audit

Deliver:

- exact file inventory;
- existing schema inventory;
- endpoint inventory;
- widget/component inventory;
- test inventory;
- dependency inventory.

Acceptance:

- every existing Studio Builder path is mapped.

## Phase 2 — Canonical Node/Layout Model

Deliver:

- stable node model;
- section;
- container;
- flex;
- grid;
- stack;
- responsive properties.

Acceptance:

- arbitrary nested layout can be persisted and rendered.

## Phase 3 — Widget Registry

Deliver:

- registry;
- schema;
- control metadata;
- renderer contract;
- widget discovery.

Acceptance:

- widgets can be added without modifying core editor logic.

## Phase 4 — Core Widgets

Implement:

```text
Heading
Text
Rich Text
Image
Button
Icon
Divider
Spacer
```

Acceptance:

- complete marketing hero can be built.

## Phase 5 — Style / Inspector System

Deliver:

- Content;
- Style;
- Advanced;
- typography;
- colors;
- spacing;
- borders;
- shadows;
- dimensions.

Acceptance:

- user can visually reproduce complex designs without code.

## Phase 6 — Responsive Engine

Deliver:

- desktop;
- tablet;
- mobile;
- visibility;
- responsive values.

Acceptance:

- page can have materially different responsive layouts.

## Phase 7 — Templates / Components

Deliver:

- saved sections;
- reusable components;
- page templates;
- global components.

## Phase 8 — Dynamic Content

Deliver:

- dynamic bindings;
- query builder;
- loops;
- provider-driven content.

## Phase 9 — Theme Builder

Deliver:

- header;
- footer;
- single;
- archive;
- search;
- 404;
- conditional templates.

## Phase 10 — Advanced UI

Deliver:

- interactions;
- animations;
- popups;
- modals;
- offcanvas;
- advanced positioning.

## Phase 11 — Forms / Media / SEO

Deliver:

- form widgets;
- media library integration;
- SEO controls;
- metadata.

## Phase 12 — Developer SDK

Deliver:

- widget API;
- extension API;
- versioning;
- documentation;
- example custom widget.

## Phase 13 — AI/MCP

Expand existing safe AI capabilities through the same command system.

## Phase 14 — Production Hardening

Complete:

- CSP;
- packaging;
- public sessions;
- rate limiting;
- cache invalidation;
- SEO;
- localization;
- deployment verification.

---

# 49. Definition of Done — Full Builder

The project is not complete merely because the editor can create a few blocks.

Full completion requires all of the following:

### Layout

- [ ] Section
- [ ] Container
- [ ] Flex
- [ ] Grid
- [ ] Stack
- [ ] Columns
- [ ] Nested layouts
- [ ] Advanced positioning

### Widgets

- [ ] Core widgets
- [ ] Media widgets
- [ ] Content widgets
- [ ] Marketing widgets
- [ ] Navigation widgets
- [ ] Form widgets
- [ ] Advanced widgets

### Controls

- [ ] Content
- [ ] Style
- [ ] Layout
- [ ] Advanced
- [ ] Dynamic
- [ ] Conditions

### Responsive

- [ ] Desktop
- [ ] Tablet
- [ ] Mobile
- [ ] Visibility
- [ ] Responsive spacing
- [ ] Responsive typography
- [ ] Responsive layout

### Dynamic

- [ ] Data bindings
- [ ] Query builder
- [ ] Loop
- [ ] Dynamic fields
- [ ] Provider limits

### Templates

- [ ] Header
- [ ] Footer
- [ ] Single
- [ ] Archive
- [ ] Search
- [ ] 404
- [ ] Conditional templates

### Reuse

- [ ] Saved sections
- [ ] Global components
- [ ] Local components
- [ ] Templates
- [ ] Detach

### Editor

- [ ] Drag/drop
- [ ] Navigator
- [ ] Inspector
- [ ] Inline editing
- [ ] Undo/redo
- [ ] Autosave
- [ ] Preview

### Runtime

- [ ] Secure rendering
- [ ] Caching
- [ ] Responsive assets
- [ ] SEO
- [ ] Accessibility
- [ ] Localization

### Security

- [ ] Tenant isolation
- [ ] Permissions
- [ ] CSRF
- [ ] XSS protection
- [ ] URL validation
- [ ] Import security
- [ ] Package security
- [ ] Rate limiting
- [ ] MCP security

### Developer platform

- [ ] Widget SDK
- [ ] Extension API
- [ ] Versioning
- [ ] Documentation
- [ ] Example extension

### Quality

- [ ] Unit tests
- [ ] Integration tests
- [ ] Security tests
- [ ] Browser tests
- [ ] CI
- [ ] Production verification

---

# 50. Recommended Repository Organization

The exact paths must be reconciled with the current repository before implementation, but the target organization should conceptually resemble:

```text
01-client/
├── plugins/
│   └── studio-builder/
│       ├── StudioBuilder.php
│       ├── admin/
│       ├── api/
│       ├── widgets/
│       ├── templates/
│       ├── extensions/
│       └── ui/
│           └── src/
│               ├── app/
│               ├── canvas/
│               ├── widgets/
│               ├── inspector/
│               ├── navigator/
│               ├── responsive/
│               ├── commands/
│               ├── state/
│               └── core/
│
└── src/
    └── Module/
        └── StudioBuilder/
            ├── Application/
            ├── Document/
            ├── Widget/
            ├── Layout/
            ├── Template/
            ├── Component/
            ├── Query/
            ├── Provider/
            ├── Render/
            ├── Repository/
            ├── Package/
            ├── Security/
            ├── Http/
            └── Mcp/
```

Do not mechanically create this structure if existing Kohevo conventions dictate a different placement. The architectural boundaries are more important than the exact folder names.

---

# 51. Migration / Compatibility Rules

Existing Studio documents must continue rendering.

Rules:

1. Never break old canonical documents without migration.
2. Add schema versions.
3. Add migration functions.
4. Test old documents against new renderer.
5. Keep widget versions explicit.
6. Keep package import backward compatible where feasible.
7. Do not silently mutate published content during migration.

---

# 52. Performance Budgets

Set documented limits for:

- maximum document size;
- maximum node count;
- maximum nested depth;
- maximum query results;
- maximum imported HTML size;
- maximum CSS size;
- maximum package size;
- maximum media references;
- maximum render time.

Limits should fail safely with useful errors.

---

# 53. Error Handling

Use safe, stable error codes.

Example:

```text
STUDIO_INVALID_DOCUMENT
STUDIO_INVALID_NODE
STUDIO_PERMISSION_DENIED
STUDIO_REVISION_CONFLICT
STUDIO_TENANT_SCOPE_REQUIRED
STUDIO_WIDGET_NOT_FOUND
STUDIO_PROVIDER_NOT_FOUND
STUDIO_QUERY_LIMIT
STUDIO_IMPORT_REJECTED
STUDIO_PACKAGE_INVALID
```

Do not expose internal stack traces or database details to end users.

---

# 54. Observability

Log security-relevant events:

- permission denial;
- publish;
- rollback;
- package import rejection;
- HTML import rejection;
- MCP denial;
- tenant scope violation;
- suspicious rate-limit activity.

Do not log secrets or sensitive user content unnecessarily.

---

# 55. Documentation Requirements

Create documentation for:

```text
Studio Builder architecture
Canonical document schema
Widget SDK
Control SDK
Renderer API
Dynamic provider API
Template API
Package format
Security model
MCP commands
Migration/versioning
Testing
Deployment
```

Every public extension API must have at least one working example.

---

# 56. Implementation Rules for AI Coding Agents

Any AI agent working on this repository must:

1. Read this master specification before modifying Studio Builder.
2. Read the current Studio architecture documents.
3. Inspect existing implementation before creating new abstractions.
4. Reuse existing repositories/services/security boundaries.
5. Never bypass tenant isolation.
6. Never bypass permission checks.
7. Never introduce arbitrary code execution through document data.
8. Never introduce arbitrary network fetching from saved content.
9. Add tests with every meaningful feature.
10. Keep migrations backward compatible.
11. Never silently change published content.
12. Keep changes small and reviewable.
13. Run relevant tests after each phase.
14. Do not mark a phase complete without its acceptance criteria.
15. Never claim CI is green without a fresh CI result for the exact commit.

---

# 57. First Implementation Sprint

The first coding sprint should NOT attempt the entire builder.

Start with:

### Sprint 1

1. Inventory existing Studio document schema.
2. Inventory current components/templates.
3. Define node/layout contracts.
4. Define WidgetRegistry interface.
5. Define ControlSchema interface.
6. Define responsive value representation.
7. Implement Section.
8. Implement Container.
9. Implement Flex.
10. Implement Grid.
11. Implement Heading.
12. Implement Text.
13. Implement Button.
14. Add schema tests.
15. Add renderer tests.
16. Add browser smoke test.
17. Verify tenant/security boundaries.

### Sprint acceptance

A developer must be able to create:

```text
Hero Section
 └─ Container
     ├─ Heading
     ├─ Text
     └─ Button
```

and configure:

```text
Desktop
Tablet
Mobile
```

with the resulting document safely persisted and publicly rendered.

---

# 58. Second Implementation Sprint

Implement:

- Inspector;
- Content controls;
- Style controls;
- Advanced controls;
- typography;
- colors;
- spacing;
- borders;
- shadows;
- responsive overrides.

Acceptance:

A user can visually reproduce a complex modern landing-page hero without custom CSS.

---

# 59. Third Implementation Sprint

Implement:

- Navigator;
- drag/drop;
- duplicate;
- move;
- delete;
- undo/redo;
- autosave;
- revision conflict handling.

Acceptance:

The editor feels like a real visual builder rather than a form-based document editor.

---

# 60. Fourth Implementation Sprint

Implement:

- reusable sections;
- components;
- templates;
- global tokens;
- global styles.

Acceptance:

A user can build a consistent multi-page website from reusable design primitives.

---

# 61. Fifth Implementation Sprint

Implement:

- dynamic bindings;
- query builder;
- loop;
- posts;
- authors;
- taxonomy;
- pagination.

Acceptance:

A user can create a blog/archive website without manually creating every content card.

---

# 62. Sixth Implementation Sprint

Implement:

- theme builder;
- header;
- footer;
- single;
- archive;
- search;
- 404;
- template conditions.

Acceptance:

A user can build the structure of an entire website, not just individual pages.

---

# 63. Seventh Implementation Sprint

Implement:

- interactions;
- animation;
- popup;
- modal;
- offcanvas;
- forms;
- media improvements;
- SEO.

Acceptance:

The platform can support a complete production marketing website.

---

# 64. Eighth Implementation Sprint

Implement:

- widget SDK;
- third-party widget loading;
- widget versioning;
- documentation;
- example plugin.

Acceptance:

A developer can create and install a new widget without modifying core Studio Builder files.

---

# 65. Final Product Architecture

The final system should conceptually become:

```text
                         ┌─────────────────────┐
                         │   STUDIO WEBSITE    │
                         │       BUILDER       │
                         └──────────┬──────────┘
                                    │
             ┌──────────────────────┼──────────────────────┐
             │                      │                      │
        VISUAL EDITOR          DOCUMENT ENGINE        RUNTIME
             │                      │                      │
       ┌─────┼─────┐          ┌─────┼─────┐          ┌─────┼─────┐
       │     │     │          │     │     │          │     │     │
    Widgets Layout Style   Schema Revision Template Renderer Cache SEO
       │     │     │          │     │     │          │     │     │
       └─────┼─────┘          └─────┼─────┘          └─────┼─────┘
             │                      │                      │
             └──────────────────────┼──────────────────────┘
                                    │
                         ┌──────────┴──────────┐
                         │ SECURITY / TENANT  │
                         │ PERMISSIONS / CSRF │
                         └──────────┬──────────┘
                                    │
                    ┌───────────────┼────────────────┐
                    │               │                │
                Providers        Packages          MCP/AI
```

---

# 66. Final Engineering Direction

The objective is **not** to copy Elementor's implementation.

The objective is to reproduce the capabilities users expect from a modern visual website builder while keeping Kohevo's own architecture:

```text
Elementor-class UX
        +
Kohevo schema-driven architecture
        +
strict tenant isolation
        +
safe dynamic providers
        +
revision-first publishing
        +
developer-extensible widgets
        +
AI/MCP authoring
        =
Kohevo Studio Builder
```

The final product should allow:

> **A non-developer to build a complete website visually, while a developer can extend the builder with widgets, data providers, templates and integrations without compromising the security or integrity of the platform.**

---

# 67. Master Acceptance Statement

The Studio Builder implementation can be considered **Full Flexible Website Builder v1** only when a tester can start with an empty site and, using the visual editor, build and publish a responsive multi-page website containing:

- custom header;
- navigation;
- hero;
- nested containers;
- flex/grid layouts;
- typography;
- images;
- buttons;
- cards;
- reusable components;
- global design tokens;
- responsive desktop/tablet/mobile rules;
- dynamic content;
- query loops;
- blog/archive pages;
- custom footer;
- form;
- popup/modal;
- SEO metadata;
- accessible markup;
- template conditions;

without requiring custom code for the normal website-building workflow.

At the same time, the resulting site must pass:

- tenant isolation tests;
- authorization tests;
- XSS/sanitization tests;
- import/package security tests;
- concurrency/revision tests;
- dynamic-provider security tests;
- browser UI tests;
- production rendering tests.

**This document is the implementation source of truth for the Full Kohevo Studio Builder initiative.**
