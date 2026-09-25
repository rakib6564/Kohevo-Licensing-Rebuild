# ADR-0007 — Section/Block content model before a visual Page Builder

**Status:** Accepted   **Date:** 2026-07-27

## Context

There is strong demand for a drag-and-drop visual Page Builder. Today the content
model is a flat JSON array of blocks with **no first-class Section or Template**,
`content-builder`'s `BlockRegistry` is sound but polluted by vertical `rx-*`
blocks, and a parallel `sb-*` block system exists. Building a visual builder on
this foundation would harden the gaps.

## Decision

Define and stabilize the **content model first** — one core Block Registry,
first-class **Sections** and **Templates**, one token vocabulary
([ADR-0008](0008-one-design-token-vocabulary.md)) — and build the visual Page
Builder **afterward** as a *consumer* of that model, not a second renderer.

## Alternatives considered

- **Build the visual builder now.** Rejected: without Sections/Templates it would
  encode a flat model and need rebuilding; two renderers (editor vs runtime) would
  drift.
- **Adopt a third-party builder.** Rejected: doesn't fit the server-rendered,
  no-build, tenant-themed model ([ADR-0003](0003-server-rendered-no-build.md)); a
  heavy external dependency for a core surface.

## Consequences

- **Positive:** the builder and the runtime share one model → what-you-edit-is-
  what-renders; new module blocks appear in the builder automatically; the model is
  useful immediately (v2) even before the visual editor ships.
- **Negative / accepted trade-offs:** users wait longer for drag-and-drop (interim
  editing is form/reorder-based); more up-front modeling work before the visible
  payoff.

## Related

- [ADR-0008](0008-one-design-token-vocabulary.md) · [ADR-0003](0003-server-rendered-no-build.md)
- [05-Rendering/blocks-and-sections.md](../05-Rendering/blocks-and-sections.md) · [05-Rendering/page-builder.md](../05-Rendering/page-builder.md)
