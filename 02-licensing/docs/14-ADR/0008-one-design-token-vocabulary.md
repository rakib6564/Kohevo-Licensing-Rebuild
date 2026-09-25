# ADR-0008 — One design-token vocabulary for admin + public

**Status:** Accepted   **Date:** 2026-07-27

## Context

The codebase currently has **five disjoint token/theming vocabularies**
(`--accent`/`--glass`, `--cb-*`, `--sb-*`, the landing page's, the storefront's)
and **three parallel admin component kits**. Accent color alone is defined in 5+
places. This makes consistent theming impossible and every UI change a
multi-place edit.

## Decision

Adopt **one design-token vocabulary** (`--slate-*`) consumed by **both admin and
public**, and **one component library** built on it. A Theme supplies token
*values* (per tenant); Components consume tokens; Blocks compose Components. One
source of truth per visual concept.

## Alternatives considered

- **Separate admin and public design systems.** Rejected: duplicates the token +
  component work and guarantees drift; a tenant's public theme couldn't inform
  admin accents.
- **Keep per-subsystem tokens, add a mapping layer.** Rejected: a mapping between
  five vocabularies is more complexity than unifying them.

## Consequences

- **Positive:** change a token once, everything restyles; per-tenant theming is
  trivial and contrast-validated ([04-Design-System/accessibility.md](../04-Design-System/accessibility.md));
  admin and public feel like one product; accessibility is solved at the primitive
  layer.
- **Negative / accepted trade-offs:** a migration consolidating five vocabularies
  and three component kits (churn across many templates); a period where old and
  new coexist; naming discipline required to keep the vocabulary coherent.

## Amendment — Phase E scope

**Status:** Accepted   **Date:** 2026-08-28

This ADR's decision is one vocabulary for admin **and** public, collapsing five
disjoint sets. [ADR-0013 §6](0013-content-document-and-content-api.md) scopes
Phase E to two of them — the `--cb-*` and `--sb-*` block families. Those are not
the same goal, and two accepted ADRs pointing at different targets is the drift
this amendment exists to stop.

**The decision here is unchanged. The phasing is recorded.**

- **Phase E** unifies the content engine's vocabularies: `--cb-*` and `--sb-*`
  collapse into `--slate-*`, and the alias layer is deleted. Its boundary is the
  content-engine token graph *including its leaks* — `shop/ShopAPI.php` declares
  `--cb-cols`, and `sb.css` consumes `--cb-*`, so the two families are already
  coupled across plugin boundaries and cannot migrate independently. Cleaning
  those leaks is part of Phase E, not separate from it.
- **The remaining three vocabularies** — the admin's `--accent`/`--glass` set,
  the landing page's, and the storefront's — stay out of scope. They are separate
  surfaces and a materially larger effort. This ADR's full goal is deferred, not
  abandoned.

**Phase E is extend-then-migrate, not a rename.** The
[token inventory](../04-Design-System/phase-e-token-inventory.md) found that
`--slate-*` cannot yet express what `--sb-*` does: eight concepts have no target
at all, and font weights exist as two tokens against seven. A find-and-replace
would silently drop typographic control all five shipped themes already use. So
the order is: extend `--slate-*` to cover the gap, resolve the leaks, migrate
`--cb-*`, migrate `--sb-*`, delete the alias layer.

A method note for whoever does the work: the `--sb-*` colour tokens are PHP array
keys in `Themes.php`, not CSS declarations. Tooling that greps CSS finds nine of
thirty-two and will report the migration a third done when it has barely started.

## Related

- [ADR-0003](0003-server-rendered-no-build.md) · [ADR-0007](0007-section-block-before-page-builder.md)
- [04-Design-System](../04-Design-System/) · [05-Rendering/theme-and-template-engine.md](../05-Rendering/theme-and-template-engine.md)
