# 04 — Component Library

**Status:** Draft · **Applies to:** Slate v1.x → v5.x · **The one presentational primitive layer**

A **Component** is a presentational, server-rendered UI primitive consuming only
tokens — per the [glossary](../README.md#canonical-glossary-fixed-vocabulary),
*no domain knowledge, no editable content.* This document defines the **single**
Component library that serves admin and public alike: the render-function
contract every Component obeys, the catalogue (Button, Card, Field, Badge, Alert,
Grid, Media, DataRow, Modal, Tabs…), the `size`/`tone` variant scheme, how both
surfaces consume it, and how it relates **up** to Blocks (Blocks compose
Components) and **down** to Tokens (Components consume tokens only).

Read the [section README](README.md) (the three-kit problem, ADR-0008),
[design-tokens.md](design-tokens.md) (the vocabulary Components consume), and the
[boundary table](../README.md#layer-boundary-table-what-belongs-where) first.
This is the layer directly above [Design Tokens](design-tokens.md) and directly
below Blocks; the Block layer and everything above it live in
[05-Rendering](../05-Rendering/).

---

## 1. WHAT — one library, consumed by admin and public

There is exactly one Component library for the whole platform. A Button is a
Button whether it renders on the plugins screen, a booking widget, a storefront
checkout, or a transactional email. Every Component is a **flat-PHP render
function** that takes a props array, reads only `--slate-*` [semantic
tokens](design-tokens.md#3-how--primitive-vs-semantic-tokens-two-tiers), and
returns an escaped HTML string. It holds no content, touches no database, and
knows nothing about the domain it is decorating.

```
Blocks (content)          ← compose Components, one layer up (05-Rendering)
   │ pass props
   ▼
Components (this doc)      ← Button, Card, Field, Badge, Alert, Grid, Media…
   │ read only
   ▼
Design Tokens (--slate-*)  ← the single styling vocabulary (design-tokens.md)
```

**Reads down, never up.** A Component consumes semantic tokens and nothing else.
It does not know that Blocks, Sections, or Pages exist — they compose *it*. This
one-way dependency is exactly what lets the *same* component serve every surface:
the surface differs only by which **Theme** supplied the token values, never by
which component code runs.

---

## 2. WHY — the three-kit problem this replaces

The [audit](../AUDIT-BRIEFING.md) found the admin styling layer split across
**three parallel component kits**, each re-implementing the same Button, Card,
and Field against a different set of variables and class names:

| Old kit | Namespace | Renders where | Reaches for |
|---|---|---|---|
| `ui_components` | `.slate`, `.card` | admin lists, dashboard | `--accent`, `--glass-*` |
| `record_editor` | `.pv-*` | record editor pages | portal-local + glass mix |
| `portal_ui` | portal-local | customer portal | portal-local vars |

Public surfaces (Content Builder, storefront, landing) each grew a *fourth,
fifth, sixth* ad-hoc set of card/button markup on top of their own token
vocabularies. Three failures follow, each mapping to a Vision principle:

1. **Fan-out fixes.** Raising focus-ring contrast to pass AA, or adding a loading
   state to buttons, has to be done in every kit — and drifts the moment one is
   missed. (→ *one concept, one owner*.)
2. **Inconsistent surfaces.** The "same" card looks subtly different in the
   dashboard, the editor, and the portal because three authors hand-tuned three
   markups. A tenant's brand can't land uniformly on markup that isn't uniform.
3. **No shared a11y floor.** Keyboard traps, missing `aria-*`, and sub-44px tap
   targets have to be audited three times and re-fixed three times.

**This document is the component half of [ADR-0008](../14-ADR/):** collapse the
three admin kits (and the public one-offs) into one library that admin and public
consume identically. A cross-cutting fix — contrast, focus, a new variant —
becomes a *one-place* change. (Migration off the old kits is a tracked effort in
[09-Roadmap](../09-Roadmap/), not a big-bang rewrite.)

---

## 3. HOW — the render-function contract

Every Component is a pure render function under one namespace. The contract is
deliberately small so it holds across ~300 flat-PHP files with no build step:

```php
/**
 * Contract every Component obeys.
 *
 *  - Pure: same props → same HTML. No globals, no DB, no session, no I/O.
 *  - Presentational: emits markup + reads --slate-* semantics. No domain logic.
 *  - Content-blind: renders whatever props it is handed; remembers nothing.
 *  - Escaping is the Component's job: all interpolated text is escaped here,
 *    so callers (Blocks) pass raw values, not pre-escaped HTML.
 *  - Accessible by construction: sets the roles/labels/focus its a11y row
 *    mandates (see accessibility.md), not the caller.
 *
 * @param array $props  variant keys (size/tone/…) + slot content + passthrough attrs
 * @return string       one escaped, self-contained HTML fragment
 */
function slate_c_button(array $props = []): string;
```

Conventions that make the contract enforceable:

- **One namespace, one file each.** `slate_c_<name>()` in
  `includes/components/<name>.php`. A grep for `slate_c_` is the whole catalogue;
  there is no second `render_card()` anywhere.
- **Props, not positional args.** Every Component takes a single `$props` array so
  variants and attributes extend without breaking call sites (BC policy,
  [03-Standards](../03-Standards/)). Unknown keys in a reserved `attrs` slot are
  passed through as escaped HTML attributes.
- **Slots are strings.** Content is passed as already-rendered child HTML
  (`'body' => slate_c_field([...])`). A Component composes children by
  concatenation; it never reaches back into a child's internals.
- **No token emission.** A Component *reads* tokens and never defines a
  `--slate-*` value inline — that would fork the vocabulary at the component
  level. It relies on the [single `:root` emission](design-tokens.md#6-how--emitted-once-per-response)
  already in the head.
- **Styling is class-based and static.** Each Component ships one static CSS rule
  block keyed by its own class (`.slate-btn`, `.slate-btn--lg`), registered once
  with the [Asset Manager](../05-Rendering/#5-where-each-layer-is-specified) and
  emitted once per response. Variants are *modifier classes*, not inline styles.

```php
// Illustrative Button. Structure is fixed; all look comes from tokens + variants.
function slate_c_button(array $p = []): string {
    $size = $p['size'] ?? 'md';                 // sm | md | lg
    $tone = $p['tone'] ?? 'accent';             // accent | neutral | success | danger | ghost
    $label = htmlspecialchars($p['label'] ?? '', ENT_QUOTES);
    $tag  = isset($p['href']) ? 'a' : 'button'; // renders as link or control, same skin
    $cls  = "slate-btn slate-btn--{$size} slate-btn--{$tone}";
    $attrs = slate_c_attrs($p['attrs'] ?? []);  // escaped passthrough (id, aria-*, data-*)
    $href = isset($p['href']) ? ' href="'.htmlspecialchars($p['href'],ENT_QUOTES).'"' : ' type="button"';
    // Icon-only buttons MUST carry an accessible name (see accessibility.md §6).
    return "<{$tag} class=\"{$cls}\"{$href}{$attrs}>{$label}</{$tag}>";
}
```

```css
/* Component CSS reads ONLY semantic tokens — never a primitive, never a hex. */
.slate-btn {
  font: var(--slate-font-weight-medium) var(--slate-font-size-md)/1 var(--slate-font-sans);
  padding: var(--slate-space-2) var(--slate-space-4);
  min-height: 44px;                              /* tap-target floor (accessibility.md §4) */
  border-radius: var(--slate-radius-control);
  transition: background var(--slate-motion-fast) var(--slate-motion-ease);
}
.slate-btn--accent { background: var(--slate-color-accent); color: var(--slate-color-on-accent); }
.slate-btn--lg     { font-size: var(--slate-font-size-lg); padding: var(--slate-space-3) var(--slate-space-6); }
.slate-btn:focus-visible { outline: 2px solid var(--slate-color-focus-ring); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) { .slate-btn { transition: none; } }
```

---

## 4. HOW — the catalogue

The library is intentionally small and orthogonal: a handful of primitives that
compose into everything. Each row names the Component, its render function, its
slots, and the [accessibility.md](accessibility.md) obligation it must satisfy by
construction.

| Component | Function | Purpose | Key slots / props | Built-in a11y obligation |
|---|---|---|---|---|
| **Button** | `slate_c_button` | Actions and links wearing one skin | `label`, `href?`, `icon?` | Accessible name; `:focus-visible` ring; 44px min |
| **Card** | `slate_c_card` | Elevated surface container | `header?`, `body`, `footer?` | Heading in header region; not a landmark by itself |
| **Field** | `slate_c_field` | Labeled input wrapper (any control) | `label`, `control`, `hint?`, `error?` | `<label for>`; `aria-describedby` hint/error; `aria-invalid` |
| **Badge** | `slate_c_badge` | Compact status/count pill | `label`, `tone` | Tone never the *only* status signal (text too) |
| **Alert** | `slate_c_alert` | Inline banner message | `body`, `tone`, `dismissible?` | `role="status"`/`"alert"` by tone; icon has label |
| **Grid** | `slate_c_grid` | Responsive column/flow layout | `cols`, `gap`, `items[]` | Source order = reading order; wraps, no h-scroll |
| **Media** | `slate_c_media` | Image/video with aspect box | `src`, `alt`, `ratio?` | `alt` required (empty string if decorative) |
| **DataRow** | `slate_c_datarow` | The card-row list item (not a table) | `title`, `meta[]`, `actions?` | Row is a group; actions keyboard-reachable |
| **Modal** | `slate_c_modal` | Focus-trapped overlay dialog | `title`, `body`, `actions?` | `role="dialog"` `aria-modal`; focus trap; Esc closes |
| **Tabs** | `slate_c_tabs` | Tabbed panel switcher | `tabs[]{label,panel}` | `role="tablist"`; arrow-key nav; `aria-selected` |
| **Table** | `slate_c_table` | Genuinely tabular data | `columns[]`, `rows[]` | `<th scope>`; caption; horizontal scroll wrapper |
| **Avatar** | `slate_c_avatar` | Person/tenant image or initials | `name`, `src?` | Name as accessible label; initials `aria-hidden` |
| **Spinner** | `slate_c_spinner` | Busy indicator | `label?` | `role="status"`; respects reduced-motion |
| **Toast** | `slate_c_toast` | Transient notification | `body`, `tone` | `aria-live="polite"`; auto-dismiss pauses on focus |
| **Icon** | `slate_c_icon` | Inline SVG glyph | `name`, `label?` | `aria-hidden` when decorative; `title` when meaningful |

> **DataRow, not `<table>`, for lists.** Per the admin
> [card-row convention](../AUDIT-BRIEFING.md#8-admin--user-workflows) and the
> app-wide "wrap, don't horizontally scroll" rule, record *lists* use `DataRow`
> inside a `Grid`. `Table` is reserved for genuinely tabular data (columns that
> mean the same thing across rows) and always wraps its overflow in an
> `overflow-x:auto` container so the page body never side-scrolls.

**Composition example — a Component composing Components (still content-blind):**

```php
echo slate_c_card([
  'header' => slate_c_badge(['label' => 'Active', 'tone' => 'success']),
  'body'   => slate_c_field([
      'label'   => 'Email',
      'control' => '<input type="email" class="slate-input" id="f-email" name="email">',
      'hint'    => 'We never share it.',
  ]),
  'footer' => slate_c_button(['label' => 'Save', 'tone' => 'accent', 'size' => 'lg']),
]);
```

Nothing here knows *whose* email it is or where it is stored — that is the Block's
job (§6). The Card just arranges the Badge, Field, and Button it was handed.

---

## 5. HOW — the variant system (size / tone, no domain logic)

Variants are the *only* sanctioned way a Component varies. They are a fixed,
documented vocabulary — two orthogonal axes plus a few component-specific
booleans — never free-form styling and never a hook for domain behaviour.

| Axis | Values | Meaning | Applies to |
|---|---|---|---|
| `size` | `sm` · `md` (default) · `lg` | Density / type scale step | Button, Field, Badge, Avatar, Modal |
| `tone` | `accent` · `neutral` · `success` · `warning` · `danger` · `info` · `ghost` | Semantic colour role (maps to a status token) | Button, Badge, Alert, Toast, DataRow |
| `emphasis` | `solid` · `soft` · `outline` | Fill strength within a tone | Button, Badge, Alert |

Rules that keep variants from re-becoming the old drift:

- **Variants select tokens; they never invent them.** `tone="danger"` resolves to
  `--slate-color-danger`; it does not carry a hex. Adding a tone is an amendment
  to [design-tokens.md](design-tokens.md#7-the-token-table-canonical-set) *and*
  this table, reviewed — not an inline one-off.
- **A Theme may pick a default variant, never new markup.** Per the boundary
  table and [05-Rendering §4.1](../05-Rendering/#41-theme--components), a Theme
  can say "buttons default to `pill` radius / `soft` emphasis." It cannot add a
  Component, remove one, or alter its DOM. *Swapping the active Theme must never
  change which HTML elements exist — only their computed styles.*
- **No domain variants.** There is no `tone="premium"` or `size="checkout"`. If a
  surface needs a semantic that isn't presentational, that meaning belongs in the
  Block that chose the variant, not in the Component.
- **Every variant clears the a11y floor.** A `ghost`/`soft` Button must still meet
  contrast; a `sm` control must still meet the 44px tap target. Variants may not
  opt out of [accessibility.md](accessibility.md) — the floor is a property of the
  Component, not a variant choice.

---

## 6. HOW — the seam up to Blocks

The line between a Component and a Block is the line between *presentation with no
content* and *content*. It is the same seam settled in
[05-Rendering §4.2](../05-Rendering/#42-components--blocks); restated from the
Component side:

| | Component (this doc) | Block ([05-Rendering](../05-Rendering/)) |
|---|---|---|
| Owns | markup + variants, reading tokens | an editable **field schema** + author content |
| Knows about content | nothing — renders whatever props it's handed | everything — stores and validates it |
| Data / DB | never | reads its own module's data via contracts |
| Reusable | on every surface, admin and public | within its module's content model |
| Testable | in a style-guide harness with zero domain data | needs its schema + stored content |

**Blocks compose Components; Components never reach back.** A Block's render pulls
its stored content, then *hands it to Components as props* — it never re-emits
markup a Component already provides, and it never subclasses or edits a Component.

```php
// A Block (lives in a module, one layer up). It owns content; it composes Components.
final class TestimonialBlock implements Block {
    public function render(array $content, RenderContext $ctx): string {
        return slate_c_card([                    // ← composes the Component
            'body'   => htmlspecialchars($content['quote']),
            'footer' => slate_c_avatar(['name' => $content['author']]),
        ]);
    }
}
```

**The enforcing test** (from the boundary table's "widget with no memory of
content"): *every Component in §4 must render in a style-guide harness with zero
domain data.* If a Component can't render without a database, a session, or a
tenant, it has leaked content or domain logic and belongs, in part, in a Block.

---

## 7. HOW — one library, two surfaces (admin *and* public)

The payoff of a single library is that "admin" and "public" stop being separate
front-ends and become *the same components under different token values*:

- **Same functions, both sides.** `admin/plugins.php` and a storefront checkout
  both call `slate_c_button()` / `slate_c_card()` / `slate_c_datarow()`. There is
  no `admin_button()` vs `public_button()`.
- **Difference is the Theme, not the code.** The admin shell's glassmorphism and a
  tenant storefront's brand are two *value sets* for the same semantic tokens
  (design-tokens.md §5). The admin "glass" look is a Theme preset that re-points
  `--slate-color-surface` and shadows — it is **not** a `--glass-*` fork or a
  separate kit. (Note the standing constraint that `backdrop-filter` never goes on
  the fixed-modal container; that is a Theme value choice the Modal component's
  contract documents, not per-page CSS.)
- **Emails included.** Transactional email templates ([templates/](../08-Modules/))
  render the same Components with inlined token values, so a tenant's brand lands
  in the inbox without a fourth markup set.
- **Responsive by default.** Components wrap and reflow (Grid, DataRow) rather than
  horizontally scrolling, honouring the app-wide "scrollbars hidden; wrap, don't
  h-scroll" rule; only `Table` and code blocks scroll, inside their own
  `overflow-x:auto` wrapper.

---

## 8. Cross-references

- The tokens Components consume (semantics only, never primitives):
  [design-tokens.md](design-tokens.md).
- The a11y obligations each Component satisfies by construction — contrast, focus,
  44px targets, keyboard nav, ARIA, reduced-motion: [accessibility.md](accessibility.md).
- The seam up to Blocks, and the whole render stack above Components:
  [05-Rendering §4.2](../05-Rendering/#42-components--blocks) and
  [blocks-and-sections.md](../05-Rendering/#5-where-each-layer-is-specified).
- The Theme↔Component boundary (values/variants vs structure):
  [05-Rendering §4.1](../05-Rendering/#41-theme--components).
- The decision this specifies (one shared kit): [ADR-0008](../14-ADR/); the
  flat-PHP / no-build-step rationale for render functions:
  [ADR-0001 / ADR-0003](../14-ADR/).
- Migration off `ui_components` / `record_editor` / `portal_ui`:
  [09-Roadmap](../09-Roadmap/) (incremental, not big-bang).
