# 04 — Design Tokens

**Status:** Draft · **Applies to:** Slate v1.x → v5.x · **The one styling vocabulary**

A **Design Token** is a themeable CSS custom property in the single `--slate-*`
namespace — per the [glossary](../README.md#canonical-glossary-fixed-vocabulary),
*the single styling vocabulary for admin and public.* This document defines that
vocabulary: how tokens are named, the primitive-vs-semantic split, how a per-tenant
**Theme** overrides values at `:root`, how light/dark is expressed, and the rule
that the token set is emitted exactly once per response.

Read the [section README](README.md) (the five-vocabulary problem, ADR-0008) and
[00-Vision §2](../00-Vision/#2-core-philosophy) (*one concept, one owner*) first.
This is the bottom layer of the [render stack](../05-Rendering/#2-the-render-stack):
Components consume these tokens and nothing else.

---

## 1. WHAT — one vocabulary, one namespace

There is exactly one styling input for the entire platform: custom properties
prefixed `--slate-`. Every color, space, radius, font, shadow, motion value, and
z-index that any surface uses is named here. Admin and public read the *same*
tokens; they differ only in which **Theme** supplied the values (see §5).

```
--slate-<category>-<name>[-<state|scale>]
```

| Category | Prefix | Examples |
|---|---|---|
| Color | `--slate-color-*` | `--slate-color-accent`, `--slate-color-surface`, `--slate-color-text` |
| Space | `--slate-space-*` | `--slate-space-2`, `--slate-space-4`, `--slate-space-8` |
| Radius | `--slate-radius-*` | `--slate-radius-sm`, `--slate-radius-md`, `--slate-radius-pill` |
| Font | `--slate-font-*` | `--slate-font-sans`, `--slate-font-size-md`, `--slate-font-weight-bold` |
| Shadow | `--slate-shadow-*` | `--slate-shadow-1`, `--slate-shadow-2`, `--slate-shadow-focus` |
| Motion | `--slate-motion-*` | `--slate-motion-fast`, `--slate-motion-ease` |
| Z-index | `--slate-z-*` | `--slate-z-sticky`, `--slate-z-modal`, `--slate-z-toast` |

**The naming contract:** a token name describes *role and scale*, never the surface
it happens to appear on. There is no `--slate-color-admin-accent` and no
`--slate-color-storefront-bg`. The admin shell and a storefront both read
`--slate-color-accent`; if they must look different, that difference lives in the
**Theme values**, not in a second token name. This is the rule the five old
vocabularies broke.

---

## 2. WHY — the five-vocabulary problem this replaces

The [audit](../AUDIT-BRIEFING.md) found the styling layer split across five disjoint
token sets, each re-naming the same concepts:

| Old vocabulary | Prefix | "Accent" was called | "Surface" was called |
|---|---|---|---|
| Admin glass | `--accent` / `--glass-*` | `--accent` | `--glass-bg` |
| Content Builder | `--cb-*` | `--cb-accent` | `--cb-surface` |
| Storefront / SB kit | `--sb-*` | `--sb-primary` | `--sb-card` |
| Landing | landing-local | `--accent` (redefined) | `--surface` |
| Storefront (shop) | shop-local | `--bg` / hardcoded hex | `--surface` |

Three failures follow directly, and each maps to a Vision principle:

1. **Ambiguous resolution.** `var(--accent)` resolves to whichever vocabulary was
   emitted *last* in the response — the documented Content Builder accent-source
   drift is exactly this. A token's meaning must not depend on emission order.
   (→ *one concept, one owner*.)
2. **Fan-out edits.** A single change — say, raising focus-ring contrast to pass
   AA — has to be repeated in five places, and drifts the moment one is missed.
3. **Five-place tenant branding.** A tenant who wants their brand color everywhere
   must set it in five vocabularies; miss one and a surface renders off-brand.

Collapsing to `--slate-*` makes the meaning of a token **global and
order-independent**, makes a cross-cutting fix a *one-line* change, and makes a
tenant's brand color a *single* override. This is the specification of
[ADR-0008](../14-ADR/).

---

## 3. HOW — primitive vs semantic tokens (two tiers)

Tokens come in two tiers. Components consume **semantic** tokens only; a Theme
usually overrides **primitives**.

- **Primitive (reference) tokens** — the raw palette and scales. Values with no
  opinion about usage: `--slate-color-blue-600: #2563eb`,
  `--slate-space-4: 1rem`, `--slate-radius-md: 0.5rem`. A Theme mostly sets these.
- **Semantic (system) tokens** — role-named tokens that *point at* primitives:
  `--slate-color-accent: var(--slate-color-blue-600)`,
  `--slate-color-surface: var(--slate-color-neutral-0)`,
  `--slate-color-text: var(--slate-color-neutral-900)`. **Components reference
  only these.**

```css
:root {
  /* ── Primitives: raw values, no usage opinion ── */
  --slate-color-blue-600:   #2563eb;
  --slate-color-neutral-0:  #ffffff;
  --slate-color-neutral-900:#0f172a;
  --slate-space-4:          1rem;
  --slate-radius-md:        0.5rem;

  /* ── Semantics: roles Components consume ── */
  --slate-color-accent:     var(--slate-color-blue-600);
  --slate-color-surface:    var(--slate-color-neutral-0);
  --slate-color-text:       var(--slate-color-neutral-900);
  --slate-color-on-accent:  var(--slate-color-neutral-0);
  --slate-radius-control:   var(--slate-radius-md);
}
```

**WHY the indirection.** A Component that reads `--slate-color-accent` never needs
to know whether the tenant's brand is blue or teal — it reads a *role*. A Theme
re-points that role by overriding one primitive. This is what lets the boundary
table's rule hold: *Theme = values, Component = structure.* The Component's markup
never changes; only what the semantic token resolves to does.

**The one direction:** semantics point at primitives; Components point at
semantics; nothing points back up. A Component must never read a primitive
directly (e.g. `--slate-color-blue-600`) — that hardcodes a palette choice into
structure and re-creates the old drift one component at a time.

---

## 4. HOW — light/dark as a token override, not a code fork

Light and dark are two sets of *values* for the same semantic tokens. No component,
block, or page has a light branch and a dark branch — they read the same role
tokens and the environment decides the values.

```css
:root { color-scheme: light dark; }

/* Light is the default value set (see §3). Dark re-points the same semantics. */
@media (prefers-color-scheme: dark) {
  :root {
    --slate-color-surface: var(--slate-color-neutral-900);
    --slate-color-text:    var(--slate-color-neutral-0);
    --slate-color-accent:  var(--slate-color-blue-400); /* lifted for AA on dark */
  }
}

/* An explicit tenant/user choice wins over the OS preference. */
:root[data-theme="dark"]  { /* same dark overrides */ }
:root[data-theme="light"] { /* force the light set */ }
```

**WHY at the token layer.** Because dark mode is a *values* concern, it lands
entirely in the Theme's job (boundary table). Contrast obligations
(→ [accessibility.md](accessibility.md#2-contrast-is-a-token-guarantee)) are
satisfied per value set: the dark set must independently clear WCAG AA, which is
why `--slate-color-accent` is lifted to a lighter primitive in dark. A Component
author writes zero dark-mode CSS and still ships a correct dark surface.

---

## 5. HOW — per-tenant Theme override at `:root`

A **Theme** (boundary table: *token values + font pairings + chrome presets +
component variants*) skins a tenant by overriding token values at `:root`, and
nothing else. It ships **no markup and no logic** — it is a values document.

```css
/* Theme "Acme" — emitted for tenant 42. Values only; structure untouched. */
:root {
  --slate-color-accent:    #0e7490;                 /* Acme teal */
  --slate-color-on-accent: #ffffff;
  --slate-font-sans:       "Inter", system-ui, sans-serif;
  --slate-radius-control:  var(--slate-radius-pill); /* Acme prefers pills */
}
```

- **One place, whole platform.** Overriding `--slate-color-accent` here re-brands
  the admin shell, CMS pages, storefront, booking widget, and transactional email
  in one edit — the exact opposite of the old five-place problem (§2).
- **Values, never structure.** A Theme may re-point a token or select a documented
  component *variant*; it may not add a token name, remove one, or reach into
  component markup. If a design needs a shape no token/variant expresses, that is a
  new token or variant proposed to this section — not a Theme hack. (See the
  Theme↔Components seam in [05-Rendering §4.1](../05-Rendering/#41-theme--components).)
- **Tenant-scoped.** The Theme Engine that computes and loads a tenant's values
  lives one layer up in [05-Rendering](../05-Rendering/#3-how-a-theme-skins-it-the-orthogonal-axis);
  this document only fixes *what the tokens are* and *that a Theme sets them at
  `:root`.*

---

## 6. HOW — emitted once per response

The full `--slate-*` set is written to the document **exactly once**, in a single
`:root` block in the head, before any component renders.

**WHY once.** The old vocabularies were emitted piecemeal — each renderer injected
its own variables when its fragment rendered — so the *last* emission silently won
and `var(--accent)` became position-dependent (§2, item 1). A single authoritative
emission removes order-dependence by construction: there is one `:root`, so there
is one value for every token, everywhere on the page.

- **Idempotent guard.** The emitter is called through one entry point that no-ops
  if it has already run this request (the same discipline as
  `slate_brand_accent_emit()` today). A component that needs tokens *ensures* the
  block exists; it never emits its own copy.
- **Order:** primitives → semantics → dark overrides → active Theme overrides, all
  inside the one `:root`, so later rules legitimately cascade over earlier ones.
- **No inline per-component variables.** A Component styles itself purely by
  *reading* tokens. It never defines a `--slate-*` value inline — that would fork
  the vocabulary back apart at the component level.
- **No build step.** The block is composed in PHP at request time and cached, per
  [00-Vision §4](../00-Vision/#4-real-world-constraints-non-negotiable) — no
  webpack, no preprocessor. Contributors edit token values and refresh.

---

## 7. The token table (canonical set)

The role tokens Components are guaranteed to find. Values shown are the **default
Theme, light** value set; a tenant Theme (§5) and the dark set (§4) re-point them.

| Token | Role | Default (light) |
|---|---|---|
| `--slate-color-accent` | primary brand / interactive color | `#2563eb` |
| `--slate-color-on-accent` | text/icon on an accent fill | `#ffffff` |
| `--slate-color-surface` | default panel/card background | `#ffffff` |
| `--slate-color-surface-sunken` | inset/well background | `#f1f5f9` |
| `--slate-color-canvas` | page background behind surfaces | `#f8fafc` |
| `--slate-color-text` | primary body text | `#0f172a` |
| `--slate-color-text-muted` | secondary/label text | `#475569` |
| `--slate-color-border` | hairlines, control borders | `#e2e8f0` |
| `--slate-color-success` / `-warning` / `-danger` / `-info` | status tones | green / amber / red / blue |
| `--slate-color-focus-ring` | focus outline color | `#2563eb` |
| `--slate-space-1 … -12` | spacing scale (0.25rem → 4rem) | `0.25rem … 4rem` |
| `--slate-radius-sm` / `-md` / `-lg` / `-pill` | corner radii | `4px / 8px / 16px / 999px` |
| `--slate-radius-control` | semantic radius for buttons/inputs | `var(--slate-radius-md)` |
| `--slate-font-sans` / `-mono` | font families | `system-ui, …` / `ui-monospace, …` |
| `--slate-font-size-sm … -3xl` | type scale | `0.875rem … 2rem` |
| `--slate-font-weight-normal` / `-medium` / `-bold` | weights | `400 / 500 / 700` |
| `--slate-line-height-tight` / `-normal` | line heights | `1.2 / 1.5` |
| `--slate-shadow-1` / `-2` / `-3` | elevation ramp | subtle → pronounced |
| `--slate-shadow-focus` | focus-ring shadow token | `0 0 0 3px …` |
| `--slate-motion-fast` / `-base` | transition durations | `120ms / 200ms` |
| `--slate-motion-ease` | standard easing | `cubic-bezier(.2,0,0,1)` |
| `--slate-z-base` / `-sticky` / `-dropdown` / `-modal` / `-toast` | stacking order | `0 / 100 / 200 / 1000 / 1100` |

Values are illustrative of the default Theme; the *names and roles* are the
contract. Adding a role token is an amendment to this table (and thus reviewed);
inventing one inline in a component or plugin is the drift this section exists to
prevent.

---

## 8. Cross-references

- The primitives/semantics these tokens feed: [component-library.md](component-library.md)
  (Components consume semantics only).
- Contrast, focus, and reduced-motion obligations on the color/shadow/motion
  tokens: [accessibility.md](accessibility.md).
- The Theme Engine that computes and loads per-tenant values, and single-emission
  in the pipeline: [05-Rendering](../05-Rendering/#3-how-a-theme-skins-it-the-orthogonal-axis).
- The decision this specifies: [ADR-0008](../14-ADR/) (one token vocabulary);
  no-build-step rationale: [ADR-0001 / ADR-0003](../14-ADR/).
- Migration off the five vocabularies: [09-Roadmap](../09-Roadmap/) (incremental,
  not big-bang).
