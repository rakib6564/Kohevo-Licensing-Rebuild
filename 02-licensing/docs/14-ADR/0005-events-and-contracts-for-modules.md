# ADR-0005 — Events + contracts for inter-module communication

**Status:** Accepted   **Date:** 2026-07-27

## Context

Modules must cooperate (membership needs payments; coaching needs membership;
shop needs shipping) but today they do so by calling each other's concrete global
classes guarded by `class_exists` — even **bidirectionally** (`stripe-payment`'s
public endpoints hard-depend on `ShopAPI`, so the "generic" gateway isn't
consumer-agnostic). Coupling is name-based and unversioned, and table ownership is
convention-only (nothing prevents a future prefix collision). This doesn't scale and
makes modules non-independent.

## Decision

Restrict inter-module communication to **exactly three channels**: (1) **capability
contracts** resolved from the container (synchronous needs), (2) **domain events**
on the bus (reacting to facts), (3) **extension points/filters** (shaping shared
data). **No module may reference another module's class or table.**

## Alternatives considered

- **Direct class calls (status quo).** Rejected: brittle, name-based, unversioned,
  enables cycles.
- **Shared database tables between modules.** Rejected: install-order-dependent
  schema, uninstall hazards, hidden coupling.
- **A message broker.** Rejected as premature per [ADR-0002](0002-modular-monolith-over-microservices.md);
  an in-process event bus suffices, with the queue for async delivery.

## Consequences

- **Positive:** modules are independently installable/removable; no cycles (the
  resolver forbids them); implementations are substitutable; the integration
  surface is discoverable ([06-SDK/event-catalogue.md](../06-SDK/event-catalogue.md)).
- **Negative / accepted trade-offs:** indirection — a flow spans an emit and a
  listen rather than a direct call, which is harder to trace; eventual-consistency
  semantics for event-driven reactions; contracts must be designed and versioned.
  Conformance checks ([12-Testing/architecture-conformance.md](../12-Testing/architecture-conformance.md))
  enforce the rule so it doesn't erode.

## Related

- [ADR-0004](0004-kernel-container-contracts.md) · [ADR-0006](0006-unified-identity-contacts.md) · [ADR-0011](0011-money-value-object.md)
- [01-Architecture/event-system.md](../01-Architecture/event-system.md) · [01-Architecture/plugin-architecture.md](../01-Architecture/plugin-architecture.md)
