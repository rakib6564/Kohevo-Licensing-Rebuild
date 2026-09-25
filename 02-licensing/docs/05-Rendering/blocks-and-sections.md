# 05 — Blocks & Sections

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The content model beneath every page: **Blocks** (editable content units),
**Sections** (layout arrangements of blocks), and how a **Page** is a Template
plus an ordered list of Sections. This is the spine the visual
[Page Builder](page-builder.md) will edit — defined first, deliberately
(ADR-0007).

> **Problem being solved.** Today ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md))
> content-builder's `BlockRegistry`/`Renderer` is the right primitive, but
> "layout" is a flat JSON array of blocks with **no first-class Section or
> Template**, vertical `rx-*` blocks are baked into the core registry, and
> `small-business-kit` ships a parallel `sb-*` block system. This document
> promotes one registry to core and adds the missing Section/Template objects.

---

## 1. The hierarchy

```
Page  =  Template  +  [ Section, Section, … ]
Section  =  layout(cols/bg/spacing)  +  [ Block, Block, … ]
Block  =  field-schema  +  render() that COMPOSES Components
Component  =  presentational primitive (consumes Design Tokens only)
```

Each layer consumes only the one below ([boundary table](../README.md#layer-boundary-table-what-belongs-where)).
A Block never knows about page chrome; a Section never reaches inside a Block's
render; a Component never knows what content it holds.

---

## 2. Blocks

A **Block** is an editable content unit: a **field schema** (what the editor
exposes) plus a **render** that composes [Components](../04-Design-System/component-library.md).
Registered in the **core Block Registry**; modules add blocks via the
`blocks.register` extension point — **verticals live in their module**, never in
core.

```php
interface Block {
    public function type(): string;                 // 'hero', 'donation-form'
    public function schema(): FieldSchema;           // editable fields + defaults
    public function render(array $props, RenderContext $ctx): string; // composes Components
}

interface BlockRegistry {
    public function register(Block $block): void;    // via blocks.register
    public function get(string $type): ?Block;
    public function all(): array;
}
```

- **Field-driven.** The editor form is generated from `schema()`; the Page Builder
  needs no per-block editor code.
- **Renders through Components.** A `hero` block emits `Card`/`Button`/`Media`
  Components — not raw themed markup. Restyle the theme → the block restyles.
- **Owned by modules.** Shop registers `product-grid`; Booking registers
  `booking-widget`; the CMS registers content blocks. Core ships only generic
  blocks. (Fixes `rx-*`/`sb-*` in core.)

## 3. Sections

A **Section** is a first-class, saveable, reusable **layout container** arranging
one or more Blocks — columns, background, spacing, max-width. It is the object
today's flat block-array lacks.

```php
final class Section {
    public string $id;
    public LayoutSpec $layout;      // columns, gap, background token, padding, width
    public array $blocks;           // ordered Block instances (type + props)
    public ?string $savedAs = null; // reusable "pattern" name
}
```

- Sections carry **layout**, Blocks carry **content**. This separation is what
  lets a marketer rearrange a page without touching block internals.
- A Section can be **saved as a pattern** and reused across pages (a "Testimonials
  band" dropped onto many pages).

## 4. Pages

A **Page** binds a [Template](theme-and-template-engine.md) to an ordered list of
Sections, for a content type (page, post, product, landing, service):

```php
final class Page {
    public string $type;            // content type → template selection
    public string $template;        // document skeleton + regions
    public array $sections;         // ordered Section[]
    public MetaBag $seo;            // fed to the SEO Manager at head-assembly
}
```

Persistence is the ordered Section/Block tree as JSON (plus indexed columns for
routing/status). The [Renderer](rendering-pipeline.md) walks it top-down.

## 5. The Renderer

```php
interface Renderer {
    public function renderPage(Page $p, RenderContext $ctx): string;   // template → sections
    public function renderSection(Section $s, RenderContext $ctx): string; // layout → blocks
    public function renderBlock(array $block, RenderContext $ctx): string; // registry → block.render → components
}
```

One Renderer, one traversal, for CMS pages, storefront, and module public pages —
ending the six divergent renderers ([rendering-pipeline.md](rendering-pipeline.md)).

---

## Boundaries recap

| Layer | Owns | Must not |
|---|---|---|
| Block | content fields + render composing Components | arrange other blocks; know page chrome; hit the DB directly |
| Section | arrangement/layout of Blocks | a Block's internal render; page chrome |
| Page | Template choice + ordered Sections | rendering mechanics |

---

## Related

- [README.md](README.md) · [rendering-pipeline.md](rendering-pipeline.md) · [theme-and-template-engine.md](theme-and-template-engine.md) · [page-builder.md](page-builder.md)
- [04-Design-System/component-library.md](../04-Design-System/component-library.md) · [06-SDK/event-catalogue.md](../06-SDK/event-catalogue.md) · [ADR-0007](../14-ADR/)
