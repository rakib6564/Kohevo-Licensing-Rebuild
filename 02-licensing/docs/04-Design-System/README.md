# 04 — Design System

**Status:** Draft · **Applies to:** Slate v1.x → v5.x · **Section index**

One design system for the whole platform — admin *and* public, server-rendered,
no build step, themeable per tenant. This section defines the styling vocabulary
(**Design Tokens**), the presentational primitives that consume it
(**Components**), how a **Theme** re-skins both by overriding values, and the
accessibility floor every rendered pixel must clear.

Read [00-Vision](../00-Vision/) (one-token-vocabulary principle, ADR-0008) and
[01-Architecture](../01-Architecture/) (Presentation subsystem) first. This
section is the bottom two layers of the render stack; the layers above it —
Blocks, Sections, Templates, Pages — live in [05-Rendering](../05-Rendering/).

---

## 1. Why this section exists (the problem it solves)

The current-state audit ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) found the styling
layer fractured into **five disjoint token vocabularies** and **three parallel
admin component kits**. The same button, card, and field are re-implemented per
surface, each reaching for a different set of custom properties:

| # | Vocabulary | Prefix | Where it lives today |
|---|---|---|---|
| 1 | Admin glass | `--accent`, `--glass-*` | `includes/ui_components.php`, admin shell |
| 2 | Content Builder | `--cb-*` | `content-builder` plugin, CMS pages |
| 3 | Storefront / SB kit | `--sb-*` | `small-business-kit`, shop storefront |
| 4 | Landing | landing-local vars | `includes/landing.php` |
| 5 | Storefront (shop) | shop-local vars | `shop` plugin templates |

| Admin component kit | Namespace | Where |
|---|---|---|
| `ui_components` | `.slate`, `.card` | admin lists, dashboard |
| `record_editor` | `.pv-*` | record editor pages |
| `portal_ui` | portal-local | customer portal |

**Consequence:** a token like `var(--accent)` renders a different color depending
on which vocabulary was emitted last (the documented Content Builder accent-source
drift is exactly this class of bug). A fix to focus-ring contrast has to be made
in three component kits. A tenant who wants their brand color everywhere has to
set it in five places. Every duplicated concept is, per the Vision, "a future
migration and a class of bugs."

**This section is the resolution of ADR-0008:** collapse the five vocabularies
into one `--slate-*` vocabulary and the three admin kits into one Component
library, consumed identically by admin and public.

---

## 2. Philosophy

- **One vocabulary, one owner.** A single `--slate-*` custom-property namespace is
  the *only* styling input. Components read tokens; nothing else. There is no
  second set of variables for "the public side." (→ [design-tokens.md](design-tokens.md))
- **One component library for admin and public.** A Button is a Button whether it
  renders in the plugins screen or a storefront checkout. Surfaces differ by
  *token values* (the active Theme), never by *which* component code runs.
  (→ [component-library.md](component-library.md))
- **Presentational, server-rendered, no build step.** Components are flat-PHP
  render functions emitting HTML + token-driven CSS at request time. No webpack,
  no JSX, no runtime framework — consistent with ADR-0001/0003. A contributor
  edits a component file and refreshes.
- **Theme carries values, not structure.** Per the [boundary table](../README.md#layer-boundary-table-what-belongs-where),
  a Theme supplies token *values*, font pairings, and variant presets. It never
  ships markup or logic. Re-skinning a tenant is a values swap at `:root`.
- **Components hold no content and no domain knowledge.** A Component has no
  memory of what it displays (boundary table: "a widget with no memory of
  content"). Editable content and data belong to Blocks, one layer up.
- **Accessible by construction.** WCAG 2.1 AA is a property of the tokens and
  components, not a per-page checklist. If you use the library correctly, contrast,
  focus, and tap targets are already right. (→ [accessibility.md](accessibility.md))

---

## 3. Where this sits in the render stack

```
Theme  ──emits values──▶  Design Tokens  (--slate-*)      ← this section
                               │  consumed only by
                               ▼
                          Components      (Button, Card…)  ← this section
                               │  composed only by
                               ▼
        Blocks ▶ Sections ▶ Templates ▶ Pages              ← 05-Rendering
```

**Reads down, never up.** A Component consumes Tokens and nothing else. It does
*not* know about Blocks, Sections, or Pages — those compose *it*. This one-way
dependency is what lets the same component serve every surface. The Theme Engine
that produces token values, and everything above Components, is documented in
[05-Rendering](../05-Rendering/).

---

## 4. Documents in this section

| Doc | What it defines |
|---|---|
| [design-tokens.md](design-tokens.md) | The single `--slate-*` vocabulary: naming scheme, primitive vs semantic tokens, per-tenant Theme override at `:root`, light/dark, single-emission rule, the token table |
| [component-library.md](component-library.md) | The one Component library: render-function contract, the catalogue (Button, Card, Field, Badge, Alert, Grid, Media, DataRow, Modal, Tabs…), variants, how admin + public consume it, relation up to Blocks and down to Tokens |
| [accessibility.md](accessibility.md) | WCAG 2.1 AA baseline: token-derived contrast, focus states, 44px tap targets, keyboard nav, ARIA conventions, reduced-motion |

---

## 5. What belongs here vs. next door

| Belongs in 04-Design-System | Belongs in [05-Rendering](../05-Rendering/) |
|---|---|
| Token vocabulary and values | The Theme Engine (how values are computed/loaded) |
| Component render primitives | Block registry, Sections, Templates, Pages |
| Variant catalogue (size/tone) | The Page Builder, asset pipeline, SEO rendering |
| Accessibility floor of components | Page-level a11y (landmarks, doc outline) |

**The dividing line:** below the line is *presentation with no content* (this
section); above it is *content and layout* (05). Tokens and Components never know
what they display; Blocks and above supply it.

---

## 6. Related decisions

- **[ADR-0008](../14-ADR/)** — One design-token vocabulary shared by admin +
  public. This section is its specification.
- **[ADR-0007](../14-ADR/)** — Section/Block content model. Establishes the layer
  directly above Components.
- **[ADR-0001](../14-ADR/) / [ADR-0003](../14-ADR/)** — Flat PHP, no build step,
  server-rendered. The reason Components are PHP render functions, not a JS
  component framework.
- Migration from the five vocabularies is a tracked effort in
  [09-Roadmap](../09-Roadmap/), not a big-bang rewrite.
