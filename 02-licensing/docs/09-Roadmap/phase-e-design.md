# Phase E — one `--slate-*` vocabulary

**Status:** Design, for review · **Scope:** `--cb-*` and `--sb-*` only

Phase E is the last phase of the consolidation. Its final commit deletes the
alias layer, which [ADR-0013 §6](../14-ADR/0013-content-document-and-content-api.md)
calls *"the last commit of the project, not a follow-up someone schedules later."*

Nothing here has been implemented. This document exists to be reviewed **before**
368 consumer sites are edited, because the migration is appearance-sensitive
across all five SBK themes and the mistakes are silent ones.

---

## Definition of done

From [ADR-0008's amendment](../14-ADR/0008-one-design-token-vocabulary.md) and
ADR-0013 §6:

1. `--slate-*` can express everything `--cb-*` and `--sb-*` express.
2. Both families' consumers read `--slate-*`.
3. The alias layer (`ContentCoreBridge::bridgeCss()`) and the `token_bridge`
   switch are deleted.

`--glass-*` (11 tokens, 45 uses) and `--accent-*` (5 tokens, 169 uses) are
**deferred by the ADR-0008 amendment** and are not in scope. They are named here
only so that "one vocabulary" is not mistaken for "one vocabulary everywhere":
after Phase E, three vocabularies still exist, deliberately.

---

## Where things stand

Measured on `develop` at 1ffec1a, from the PHP sources rather than by grepping
CSS — the `--sb-*` and `--slate-*` tokens are PHP array keys, and a CSS-shaped
search reports a third of the real count:

| vocabulary | declared | consumed | files |
| --- | ---: | ---: | ---: |
| `--slate-*` | 76 | 85 | 4 |
| `--sb-*` | 35 | 212 | 3 |
| `--cb-*` | 13 | 156 | 7 |

**The `--sb-*` 35 is two different things, and only one of them is Phase E's
problem.** Twenty-three are declared per theme in `lib/Themes.php` — those are
design tokens and they need `--slate-*` targets. The other twelve are not tokens
at all:

```
per-block author overrides, set inline from block props:
  --sb-h1-weight  --sb-h2-weight  --sb-hero-h1-weight
  --sb-sub-size   --sb-hero-h1-size  --sb-hero-lede-size
  --sb-btn-fs     --sb-btn-pad

static layout constants in sb.css, never themed:
  --sb-container  --sb-gutter

consumed only with a literal fallback, declared nowhere:
  --sb-h1-size    --sb-h2-size
```

They are CSS custom properties used as a *mechanism* — an author sets a weight on
one heading block and it overrides the theme through
`var(--sb-h1-weight, var(--sb-weight-display, 800))`. Migrating them to
`--slate-*` would be a category error: there is nothing to unify, because no
theme declares them.

What they DO need is their fallback chains repointed, so the value they fall back
to is the `--slate-*` role token rather than the `--sb-*` one. That is a
one-line-per-site change inside `sb.css` and the block templates, and it is the
only way step 3 can ever assert "no `--sb-` substring remains".

**`--slate-*` is still not a superset.** It has grown 42 → 76 across Phases A–C,
but the specific gap the original inventory identified is untouched:

| concept | `--sb-*` has | `--slate-*` has |
| --- | ---: | ---: |
| font weight | 8 | 3 |
| letter tracking | 2 | 0 |
| text transform | 1 | 0 |

Migrating today would silently drop typographic control every SBK theme relies
on. Extend first.

---

## Decisions taken

**Text transform is not a token.** `--sb-nav-transform` (uppercase nav) is a
component presentation decision with exactly one consumer and no reuse. It gets
folded into the nav component's own default when the nav is migrated, and the
token disappears. Minting `--slate-*-transform` would add a token Phase E then
could not delete.

**Letter tracking is a token.** It is a real typographic axis with two
independent consumers, so it earns `--slate-tracking-*` semantics.

**Roles stay in semantics, values stay in primitives.** The eight `--sb-*`
weights are not eight values; they are roles (body, heading, display, nav,
button, strong) over a small scale. `DesignTokens` already separates
`primitives()` from `semantics()`, with semantics pointing only at primitives.
Keeping that layering makes the later migration a mapping rather than a redesign.

---

## Step 1 — extend `--slate-*` (additive, zero visual risk)

Nothing consumes the new tokens yet, so this step cannot change a rendered byte.
That is what makes it safe to land on its own.

**Primitives to add** — only where a role needs a value the three weights cannot
express:

> **Superseded — see "Verification catches" below.** The list that was here
> (300/600/800 weights, tight/normal/wide tracking) was drafted before the theme
> values were read, and three of four tracking values and one weight do not land
> on it. The corrected set is:

```
slate-font-weight-semibold   600      (in use)
slate-font-weight-extrabold  800      (in use)
slate-font-heading           (heading family; theme-overridden via tokens())
```

`300` is dropped — no theme uses it. No tracking primitives are added beyond
defaults, because tracking is set per theme as a literal on the role token.

**Semantics to add** — roles pointing at primitives:

```
slate-font-weight-body      slate-font-weight-heading   slate-font-weight-display
slate-font-weight-nav       slate-font-weight-button    slate-font-weight-strong
slate-tracking-heading      slate-tracking-nav
```

The exact primitive values were the reviewable part, and reading them caught a
real defect in the draft — see catch A. The conclusion is that role tokens carry
theme literals and primitives are only defaults, so "every theme value must land
on a primitive" was the wrong requirement: nothing needs to round, because
nothing is being snapped to a scale.

What step 1's PR must still show is the landing proof itself: the two typography
presets' actual weight and tracking values, beside the tokens being added, so a
reviewer can see that no theme value changes.

---

## Step 2 prerequisites — 2a and 2b

Step 2 was written as "renames under a parity gate". Both halves of that were
incomplete, and both were proven so rather than argued.

### 2b — the gate could not fail

The envelope goldens render `DocumentTemplate`, which emits the token block and
inlines **none** of the stylesheets that consume it. Demonstrated: rewriting 14
`var(--cb-ink)` sites in `public.css` — a change that moves the text colour on
every branded tenant — produced **no golden diff and a fully green suite**.

`Theme::renderPage()` inlines `Branding::cssVars()` and the whole of
`public.css`, so a document rendered through it carries all 103 `var(--cb-*)`
consumers. That is what `expected/site/branded.html` renders, under seven pinned
brand settings for determinism. With it in place the same deliberate miss fails
the suite, and reverting returns it to green.

A branded fixture on the envelope lane alone was not enough: varying the tokens
does nothing if the bytes never contain the CSS that reads them.

### 2a — the rename is not value-preserving yet

`Branding` emits twelve tenant values (`--cb-ink`, `--cb-surface`, `--cb-page-bg`,
`--cb-muted`, `--cb-radius`, the font families…). `TenantThemeResolver` overrides
**three** `--slate-*` tokens: accent, on-accent, focus-ring. So
`--slate-color-text` is a fixed `neutral-900` while `--cb-ink` is whatever the
tenant chose, and renaming a consumer from one to the other changes the colour on
every branded site.

2a maps Branding's remaining values onto their `--slate-*` counterparts, doing
for the other twelve what the resolver already does for three. Only then does
`var(--cb-ink)` resolve identically to `var(--slate-color-text)` on every tenant.

**2a is a visible change, not neutral.** Sixty existing `--slate-*` consumers —
37 in `includes/components/components.css`, 23 in the membership plans block —
currently render at slate defaults and would begin following tenant branding.
That is the intent, but it is the owner's to see, so it ships as its own commit
with the branded golden's before/after as the reviewed diff, and with an AA
contrast check: once text and surface both follow tenant brand, a pair the
components were designed around at defaults can drop below AA.

**Sequence: 2b → 2a → batch 1…N.** Only after 2a does a rename preserve value,
and only with 2b does a batch's zero-diff mean anything.

---

## Step 2 — migrate consumers, in file-sized batches

368 sites across 10 files. One file per commit, each parity-gated with a rendered
output diff, because the whole change is supposed to be appearance-neutral and
that claim has to be asserted rather than assumed — the same gate used for the
render and document cutovers.

### `--cb-*` → `--slate-*`

| `--cb-*` | target | note |
| --- | --- | --- |
| `--cb-accent` | `slate-color-accent` | |
| `--cb-ink` | `slate-color-text` | |
| `--cb-muted` | `slate-color-text-muted` | |
| `--cb-surface` | `slate-color-surface` | |
| `--cb-surface-2` | `slate-color-surface-sunken` | |
| `--cb-page-bg` | `slate-color-canvas` | |
| `--cb-radius` | `slate-radius-md` | |
| `--cb-btn-radius` | `slate-radius-control` | |
| `--cb-font-body` | `slate-font-sans` | |
| `--cb-font-heading` | `slate-font-heading` | **new in step 1** |
| `--cb-accent-dark` | — | **unresolved, see below** |
| `--cb-cols` | — | **not a token, see below** |
| `--cb-scale` | — | **unresolved, see below** |

### `--sb-*` → `--slate-*` (the 23 theme tokens)

| `--sb-*` | target | note |
| --- | --- | --- |
| `--sb-accent` | `slate-color-accent` | |
| `--sb-ink` | `slate-color-text` | |
| `--sb-muted` | `slate-color-text-muted` | |
| `--sb-line` | `slate-color-border` | |
| `--sb-page` | `slate-color-canvas` | |
| `--sb-surface` | `slate-color-surface` | |
| `--sb-surface-2` | `slate-color-surface-sunken` | |
| `--sb-radius` | `slate-radius-md` | |
| `--sb-radius-lg` | `slate-radius-lg` | |
| `--sb-btn-radius` | `slate-radius-control` | |
| `--sb-font-body` | `slate-font-sans` | |
| `--sb-font-head` | `slate-font-heading` | **new in step 1** |
| `--sb-weight-body` | `slate-font-weight-body` | **new in step 1** |
| `--sb-weight-heading` | `slate-font-weight-heading` | **new in step 1** |
| `--sb-weight-display` | `slate-font-weight-display` | **new in step 1** |
| `--sb-weight-nav` | `slate-font-weight-nav` | **new in step 1** |
| `--sb-weight-btn` | `slate-font-weight-button` | **new in step 1** |
| `--sb-weight-strong` | `slate-font-weight-strong` | **new in step 1** |
| `--sb-h1-tracking` | `slate-tracking-heading` | **new in step 1** |
| `--sb-nav-tracking` | `slate-tracking-nav` | **new in step 1** |
| `--sb-nav-transform` | — | **dropped**, folded into the nav component |
| `--sb-accent-2` | — | **unresolved, see below** |
| `--sb-ink-2` | — | **unresolved, see below** |

Twenty-one of twenty-three have a clean target or a decided disposition. Two do
not.

---

## Tokens with no clean target — settle these before code

These are the ones that will otherwise get decided badly, mid-migration, by
whoever is holding the file.

1. **`--cb-accent-dark` — is it a fill or a text colour?**
   Slate has no darkened-accent semantic. This matters beyond naming: a
   darkened-brand token used as a *gradient fill* under `--on-accent` text fails
   AA contrast on light brands. Adding `slate-color-accent-strong` without
   settling which role it plays reproduces that bug in the new vocabulary.

2. **`--sb-accent-2` — a second accent slate has no room for.**
   Slate declares one accent. Either add `slate-color-accent-2` as a semantic, or
   establish that the secondary accent is a component concern. All five themes
   declare it, so it cannot simply be dropped.

3. **`--sb-ink-2` is a second INK, not a muted grey.**
   Checked rather than assumed: across the themes `--sb-ink-2` is dark
   (`#164232`, `#1a2f44`, `#1f2937`, `#3d2a20`) while `--sb-muted` is mid-grey
   (`#5a6b7d`, `#5e7468`, `#6b7280`, `#7a665c`). Mapping `ink-2` to
   `text-muted` would flatten two distinct colours into one and lighten every
   secondary heading. It needs its own target — likely a
   `slate-color-text-secondary` between `text` and `text-muted`.

4. **`--cb-cols` is not a design token and is a known leak.** It is a column
   count, and it is declared by `shop/ShopAPI.php` — a plugin that does not own
   the `cb` vocabulary. ADR-0008's amendment makes resolving that leak part of
   Phase E. It should become a block prop, not a `--slate-*` token.

5. **`--cb-scale`** is a typographic scale multiplier, not a value any component
   reads directly. Likely computed rather than tokenised.

Several of these produce **no** `--slate-*` token. That is the point: Phase E should
end with fewer tokens than the arithmetic suggests, because several `--cb-*` and
`--sb-*` entries are component decisions that were tokenised by habit.

---

## Step 3 — delete the alias layer

Only once no consumer references `--cb-*` or `--sb-*`:

- delete `ContentCoreBridge::bridgeCss()` and its call sites
- remove the `token_bridge` site setting and its documentation
- delete the `--cb-*`/`--sb-*` declarations in `Branding.php` and `Themes.php`

Gate: a test asserting that a rendered page contains no `--cb-` or `--sb-`
substring. Until that passes, step 3 has not happened, whatever the diff says.

---

## What review should focus on

The counts in this document are only trustworthy if every mapping is. The
mapping table is where the correctness lives — a wrong row is a silent
appearance change on a live tenant, and the parity gate will catch it only if
the token was mapped to something, rather than dropped.

Related: [phase-e-token-inventory](../04-Design-System/phase-e-token-inventory.md)
(the earlier survey this supersedes — its 32-token `--sb-*` figure counts block
overrides alongside theme tokens),
ADR-0008, ADR-0013 §6.

---

## Decisions on the five open items (accepted)

**1. `--cb-accent-dark` → `slate-color-accent-strong`,** named by role, not
lightness. Fill-vs-text was settled from the consumers rather than assumed, and
it is **both**:

```
fill:  .cb-btn-primary:hover { background: var(--cb-accent-dark) }   ×3, incl. shop
text:  .cb-cta .cb-btn-primary { color: var(--cb-accent-dark) }      ×2
       .cb-card-link:hover     { color: var(--cb-accent-dark) }
```

and `.cb-btn-primary` sets `color: #fff`, so the fill sits under white text. The
AA obligation therefore applies: it ships paired with an on-colour, and step 2's
gate gains a contrast assertion.

Two things found while checking, both of which matter more than the naming:

- `Branding.php:90` — when a site sets a custom brand hex, `$accentDark = $ac`.
  **Accent-strong equals accent on those sites**, so the hover state does not
  darken at all and the pair is contrast-identical to the base. A token named
  `-strong` that is frequently not stronger is a trap of its own; the migration
  should derive it rather than carry the aliased value through.
- `shop/ShopAPI.php:1219` consumes `--cb-accent-dark`. That is a **second**
  cross-plugin leak alongside `--cb-cols`, from the same plugin.

**2. `--sb-accent-2` → `slate-color-accent-secondary`** as a semantic. All five
themes declare it, so it is a theme-level value by definition.

**3. `--sb-ink-2` → `slate-color-text-secondary`,** between `text` and
`text-muted`.

**4. `--cb-cols` → a block prop.** Convert the `shop/ShopAPI.php:1098` consumer
to read its own prop with a default, confirm no CSS reads `--cb-cols`, then drop
the declaration.

**5. `--cb-scale` — neither uniform nor per-theme.** It is a **site setting**:

```php
$scaleKey = getSiteSetting('type_scale', 'm');
$scaleMap = ['s' => 0.9, 'm' => 1.0, 'l' => 1.12, 'xl' => 1.25];
```

A site owner picks small / medium / large / xl and every font size scales.
Resolving it into fixed `--slate-font-size-*` steps would **delete a user-facing
type-size preference**, not retire a token. It stays a runtime multiplier; the
only question for Phase E is what it is called.

---

## Verification catches, before step 1

### A — the weights and tracking do NOT land on the draft primitives

The five themes share **two** typography presets (`$bold`, `$editorial`) merged
in via `array_merge`, so there are two distinct type sets, not five.

| | values in use |
| --- | --- |
| weight | 400, 500, 600, 700, **750**, 800 |
| tracking | **-.015em**, **-.025em**, **.01em**, .08em |

Against the primitives this document originally proposed
(300/400/500/600/700/800, tight −0.02 / normal 0 / wide 0.08):

- **750 is not a scale step.** Rounding it to 700 or 800 lightens or heavies
  every heading in both `$bold` themes.
- **Three of four tracking values miss.** Only `.08em` lands on `wide`. `-.015`,
  `-.025` and `.01` would all round.
- **300 is not used by any theme** — it was invented.

**The design correction this forces:** do not try to make every theme value land
on a primitive. The role tokens are what a theme *sets*, and it sets literals —
`--sb-weight-heading => '750'` is a theme value, not a scale step, and always
was. So:

> primitives supply **defaults** for the role semantics; a theme overrides a role
> with whatever literal it wants.

That is exactly the existing model, it removes the rounding risk entirely, and it
means step 1 adds far fewer primitives than drafted: `600` and `800` for the
common scale, and no tracking primitives beyond defaults.

### B — `slate-font-heading` must be theme-set, and `fontPairing()` cannot do it

`slate-font-sans` is a primitive with a system stack. `Theme::fontPairing()`
looks like the per-theme font mechanism — and it is **unreached**: nothing emits
it. `TokenEmitter` emits `$theme->tokens()` only, and the sole
`Branding::fontPairings()` hits are content-builder's unrelated preset picker.
Another interface complete on the read side with no production caller, the same
shape as `withTheme()` and `content_resolve_media_key` before it.

So `slate-font-heading` follows the tokens() route, not fontPairing(): a
primitive with a default stack, overridden per theme through `Theme::tokens()`,
which is already emitted and already themed. Whether `fontPairing()` should be
wired up or deleted is a separate question this phase should not silently answer.

---

## Three further decisions (accepted)

### `accent-strong`: carry the behaviour through, fix it separately

Two pieces of work that must not share a commit, because one is neutral and one
is visible.

**Inside Phase E — neutral.** Map the `--cb-accent-dark` consumers to
`slate-color-accent-strong`, resolving to exactly what they resolve to today,
*including* the no-op on custom-brand sites where `Branding.php:90` sets
`$accentDark = $ac`. Phase E carries the current, imperfect behaviour through
unchanged. That is what makes it byte-neutral and reviewable under a parity gate.

**Outside Phase E — a labelled behaviour change.** Derive `accent-strong` from
the accent so the hover actually darkens on the sites where it currently does
not, shipped with before/after and the AA-contrast check. This changes the hover
state of **every custom-brand tenant**, so it is the owner's call to ship and it
does not ride inside a migration whose whole claim is that nothing changed.

The temptation is to fix it while already touching the code. Don't: a neutral
migration that also improves one thing cannot be verified by a parity gate,
because the gate would have to be told to ignore the very diff it exists to
catch.

### The two shop leaks are one job

`--cb-accent-dark` (`ShopAPI.php:1219`) and `--cb-cols` (`ShopAPI.php:1098`) are
the same defect: the shop plugin reaching into a `cb` vocabulary it does not own.
ADR-0008's amendment makes resolving the leaks part of Phase E, so they are
resolved **together**, as the leak cleanup — pulled into the shop block locally
as a prop or a derived value, **not** migrated into `--slate-*`. Moving a leak
into the new vocabulary would preserve the coupling under a better name.

### `--cb-scale` is a renamed setting, not a token

It stays a runtime multiplier fed by the `type_scale` site setting. Phase E's
only task is to satisfy step 3's gate without touching behaviour: rename the
emitted custom property `--cb-scale` → `--slate-type-scale` and repoint its
consumers, still fed by the same setting and the same `0.9/1.0/1.12/1.25` map.

Behaviour identical, `--cb-` substring gone. It maps to no design token because
it is not one — it is a preference knob that happens to travel as a custom
property. Recorded explicitly so a later reader does not "finish the job" by
resolving it into `--slate-font-size-*` steps and silently deleting the type-size
preference.


---

## What the goldens do and do not gate

Learned by nearly shipping a change they could not see.

The document goldens compare **source bytes** — rendered HTML plus the inlined
stylesheets. They therefore gate **value** changes completely: rename a consumer
from `var(--cb-ink)` to `var(--slate-color-text)` and the bytes move, so a batch
whose diff is fully explained by the rename is provably value-preserving.

They do **not** gate **selector-matching** changes. Whether a CSS rule applies to
an element is a browser-side computation the goldens never run. Adding a class to
a block root changes about two bytes of HTML; every rule that newly matches
because of it is invisible.

That distinction decided the SBK scoping. Normalising `sb-cta-band` and
`sb-page-hero` to carry `.sb` would have newly applied five rule groups — `.sb`
root padding, `.sb` root colour/font, `.sb h1`–`h4`, `.sb h2` metrics and
`.sb *` — to two blocks that contain headings. The agreed check, "the golden diff
is confined to their class attributes", would have **passed** and shipped the
restyle.

So Phase E scopes the SBK tokens to a union of the real roots
(`SBKThemes::SLATE_SCOPE`) and changes no markup. The union's fragility is
converted into a guarded invariant by `SbkScopeCoverageTest`, which enumerates
every block template's root classes and fails if one is not covered — the
difference between this list and the ones that bit `sanitizeNested()` and
`layoutHasBlock()`.

**The remaining Phase E work is value-only**, so the goldens gate it fully. If a
future step needs to change markup or selectors, it needs a different kind of
evidence than a golden diff.
