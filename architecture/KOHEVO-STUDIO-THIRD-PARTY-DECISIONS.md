# Kohevo Studio — Third-Party Decisions

**Status:** Proposal for approval  
**Date:** 2026-09-30  
**Rule:** Third-party libraries may implement editor mechanics; they must never become Kohevo's persistence authority.

## 1. Decision criteria

A candidate should be evaluated against:

- canonical JSON mapping quality;
- ability to remain storage-agnostic;
- nested sections/blocks;
- responsive editing;
- keyboard/accessibility support;
- iframe/same-origin preview isolation;
- extensibility;
- bundle/build impact on Kohevo's current PHP/no-runtime-build deployment model;
- licensing compatibility with commercial redistribution;
- ability to disable or bypass built-in persistence;
- long-term maintenance risk;
- resistance to HTML/CSS-centric data becoming the canonical model.

## 2. Current ecosystem evidence

### dnd-kit

Current official documentation describes `@dnd-kit/react` as a thin React integration over the drag/drop library, with draggable, droppable, and sortable primitives. It supports sortable state and multiple sortable groups/lists. This makes it a **mechanics layer**, not a page/CMS model. Official docs: https://dndkit.com/react/quickstart/ and related sortable documentation.

**Architectural fit:** strong as a low-level interaction layer because Kohevo retains ownership of the document model.

**Trade-off:** we must build the editor shell, selection model, property panels, canvas coordination, persistence client, and command adapter ourselves.

### Puck

Puck is documented as an open-source React visual editor. Its data model is its own component-oriented representation, and it exposes nested components, external data sources, viewports, and permissions. The project states that the core is MIT licensed and that users own their data. Official docs: https://puckeditor.com/docs and https://puckeditor.com/docs/api-reference/data-model.

**Architectural fit:** good candidate for a fast editor shell, provided its data model remains an adapter-only concern and Kohevo storage/commands stay authoritative.

**Trade-off:** we would be integrating a second component/data vocabulary that needs explicit translation to the Kohevo canonical document. That translation becomes a long-term compatibility surface.

### GrapesJS

GrapesJS is a mature web-builder framework with its own component/project model and Storage Manager. Its official documentation says the JSON project data should be used to persist/reload editor projects and explicitly warns that HTML/CSS should not be relied on as the persistence layer. Official docs: https://grapesjs.com/docs/modules/Storage.html and https://grapesjs.com/docs/modules/Components.

**Architectural fit:** technically capable, but its component/HTML/CSS-centric project model is materially farther from Kohevo's semantic block/data-provider contract.

**Risk:** strong temptation to let GrapesJS project JSON become the canonical document, creating a second CMS vocabulary and increasing translation/upgrade risk.

### Lexical

Lexical is a lightweight editor framework focused on rich text rather than complete page layout. Its JSON serialization is intended for persistent storage, and its extension model allows custom nodes. Official source/docs: https://lexical.dev/.

**Architectural fit:** appropriate as a **rich-text field editor inside Studio**, not as the page-builder engine.

## 3. Recommended stack

### Primary recommendation

**Custom Kohevo Studio React editor shell + dnd-kit for drag/drop primitives + Lexical for rich text fields.**

Rationale:

- Kohevo keeps the canonical document contract.
- dnd-kit supplies low-level interaction mechanics without imposing a CMS storage model.
- Lexical solves the hard rich-text interaction problem without defining page structure.
- The Studio app can be deliberately thin around the server command/query API.

### Alternative

**Puck as the editor shell**, with a strict adapter that translates Puck state into the canonical Kohevo document and never persists Puck state server-side.

This may reduce editor implementation effort, but it creates a stronger dependency on the third-party component model.

### Not recommended as the primary architecture

**GrapesJS as the canonical editor/data model.**

It can still be considered for a sandboxed import/conversion tool or a future HTML-oriented editing mode, but not as the source of truth for Studio pages.

## 4. Build/deployment decision

The current Kohevo Client is intentionally a PHP application with no Node build required on customer runtime installs. A React Studio shell therefore introduces a developer/build concern, but it does not have to introduce a runtime build requirement.

Recommended deployment pattern:

```text
Developer CI/build
    -> compile Studio JS/CSS assets
    -> versioned static assets in plugin package
    -> customer install serves prebuilt assets
```

Do not require customers' cPanel/CloudLinux environments to run Node just to load Studio.

## 5. Persistence rule for third-party editors

Regardless of library choice:

```text
Third-party editor state
       |
       v
Kohevo serializer/adapter
       |
       v
Canonical Studio Document
       |
       v
Studio Command API
       |
       v
Server persistence
```

Never:

```text
Editor library -> localStorage -> database -> public site
```

## 6. Licensing and legal verification before dependency adoption

Before implementation, verify exact production versions and licenses in the chosen lockfile/build configuration. Record:

- package name/version;
- license;
- direct and transitive dependencies;
- security advisories;
- update/upgrade policy;
- bundle size impact;
- browser support;
- whether commercial redistribution is permitted.

No dependency should be treated as approved solely because the project website currently states an open-source license; the exact version shipped by Kohevo must be checked.

## 7. Third-party decision to approve

**Preferred:** dnd-kit + Lexical + Kohevo-owned editor shell.  
**Fallback:** Puck as editor shell with a strict adapter.  
**Avoid as canonical source:** GrapesJS project data.

Approval should be explicit before the Builder UI phase begins.

