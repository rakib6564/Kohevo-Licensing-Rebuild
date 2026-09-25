# Phase E — token migration inventory

Read-only survey of the three token vocabularies, taken before Phase E starts so
it opens with a map rather than a survey. Nothing here changes code.

**Phase E's definition of done** (ADR-0013 §6): one `--slate-*` vocabulary, both
the `cb-*` and `sb-*` block families migrated to consume it, and the alias layer
deleted.

## The headline: the target vocabulary is not yet a superset

`--slate-*` cannot express everything `--sb-*` does. Phase E is therefore **not**
a rename — it must extend `--slate-*` first, or the migration will silently drop
typographic control that themes currently rely on.

Concretely, `--sb-*` carries seven weight tokens and `--slate-*` has two; `--sb-*`
carries tracking and text-transform for headings and nav, and `--slate-*` has no
equivalent at all.

## Scale

| vocabulary | declared | consumed | files consuming |
| --- | ---: | ---: | ---: |
| `--cb-*` | 13 | 156 | 7 |
| `--sb-*` | 32 | 210 | 2 |
| `--slate-*` | 42 | 62 | 3 |

`--sb-*` consumption is concentrated: 142 of 210 uses are in one file,
`small-business-kit/assets/css/sb.css`. `--cb-*` is more spread — 84 in
`content-builder/assets/css/public.css`, the rest across six other files.

## Where they are declared

| vocabulary | declared in | how |
| --- | --- | --- |
| `--cb-*` | `content-builder/lib/Branding.php` | CSS declarations, from site settings |
| | `shop/ShopAPI.php` | **outside content-builder** — see leakage below |
| `--sb-*` | `small-business-kit/lib/Themes.php` | PHP array keys, one set per theme (5 themes) |
| | `small-business-kit/assets/css/sb.css` | layout/size tokens, static |
| | `small-business-kit/lib/Blocks.php`, `lib/blocks/sb-hero.php` | inline, per block |
| `--slate-*` | `src/Presentation/Tokens/DesignTokens.php` | the core vocabulary |

A note on method: the `--sb-*` colour tokens are **array keys in PHP**, not CSS
declarations, so a CSS-shaped search misses them entirely. An inventory built by
grepping for `--sb-…:` would have reported nine tokens instead of thirty-two and
concluded the migration was a third of its real size.

## Two leaks across plugin boundaries

Neither vocabulary is contained by the plugin that owns it:

- **`shop/ShopAPI.php` declares `--cb-cols`.** A plugin that does not own the
  `cb` vocabulary defines a token in it. Phase E has to decide whether that
  token belongs to the shop or to the engine.
- **`sb.css` consumes `--cb-*`** (2 uses). small-business-kit reads
  content-builder's vocabulary, so the two are already coupled — migrating one
  without the other will break those rules.

## Concept mapping

Where the three vocabularies express the same idea. `--slate-*` targets marked
**(none)** do not exist yet and must be added before that concept can migrate.

| concept | `--cb-*` | `--sb-*` | `--slate-*` target |
| --- | --- | --- | --- |
| accent | `accent` | `accent` | `color-accent` |
| accent, secondary | `accent-dark` | `accent-2` | **(none)** |
| text / ink | `ink` | `ink` | `color-text` |
| text, secondary | — | `ink-2` | **(none)** |
| muted text | `muted` | `muted` | `color-neutral-600` |
| surface | `surface` | `surface` | `color-surface` |
| surface, sunken | `surface-2` | `surface-2` | `color-surface-sunken` |
| page background | `page-bg` | `page` | `color-neutral-0` |
| border | — | `line` | `color-border` |
| body font | `font-body` | `font-body` | `font-sans` |
| heading font | `font-heading` | `font-head` | **(none)** |
| radius | `radius` | `radius` | `radius-md` |
| radius, large | — | `radius-lg` | `radius-lg` |
| control radius | `btn-radius` | `btn-radius` | `radius-control` |
| type scale | `scale` | — | `font-size-*` (4 steps) |
| font weights | — | `weight-*` (7) | `font-weight-*` (**2 only**) |
| heading tracking | — | `h1-tracking` | **(none)** |
| nav tracking | — | `nav-tracking` | **(none)** |
| nav transform | — | `nav-transform` | **(none)** |
| container width | — | `container` | **(none)** |
| gutter | — | `gutter` | **(none)** |
| hero type sizes | — | `hero-h1-size`, `hero-lede-size`, `sub-size` | `font-size-*` (partial) |
| button type size | — | `btn-fs` | `font-size-*` (partial) |
| grid columns | `cols` (in shop) | — | **(none)** |

Seven concepts have no `--slate-*` home, and font weights are expressible only in
part. That is the work that has to precede any find-and-replace.

## Suggested order for Phase E

1. **Extend `--slate-*`** to cover the seven missing concepts and the weight
   scale. Until this lands, nothing else can migrate without losing control the
   themes already exercise.
2. **Resolve the two leaks** — decide who owns `--cb-cols`, and remove `sb.css`'s
   dependency on `--cb-*`.
3. **Migrate `--cb-*`** (13 tokens, 156 uses, 7 files). Smaller and more spread.
4. **Migrate `--sb-*`** (32 tokens, 210 uses, concentrated in `sb.css`). Larger
   but in fewer places; the five theme definitions in `Themes.php` become
   `--slate-*` value sets.
5. **Delete the alias layer** Phase A introduces. This is the definition of done,
   and ADR-0013 §6 requires it not outlive the consolidation.

## What this survey does not cover

Only `--cb-*`, `--sb-*` and `--slate-*`. ADR-0008 names **five** disjoint
vocabularies, including the admin's `--accent`/`--glass` set, the landing page's,
and the storefront's. Those are outside Phase E as currently scoped, and someone
should confirm that is deliberate rather than an oversight — ADR-0008's stated
goal is one vocabulary for admin *and* public, which is broader than the two
block families named in ADR-0013 §6.
