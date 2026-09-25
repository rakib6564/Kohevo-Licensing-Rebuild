# ADR-0002 — Modular monolith over microservices

**Status:** Accepted   **Date:** 2026-07-27

## Context

Slate hosts many verticals (booking, shop, membership, CRM, LMS…) that must be
independently developed and deployed-as-modules, yet share identity, payments,
and rendering. A common instinct for "scalable platform" is microservices.

## Decision

Build a **modular monolith**: one deployable, internally partitioned by
**contracts** (capability interfaces + events — [ADR-0005](0005-events-and-contracts-for-modules.md)).
Design explicit seams (events, service interfaces, an HTTP API) so a piece *can*
be extracted to a service later, but do not distribute prematurely.

## Alternatives considered

- **Microservices from the start.** Rejected: network boundaries, distributed
  transactions, and per-service ops are enormous cost for a platform that must run
  on shared hosting; identity/payments/rendering are deeply shared and would
  become chatty cross-service calls; operational complexity no small team should
  take on before scale demands it.
- **Big-ball-of-mud monolith (status quo).** Rejected: no internal boundaries →
  the coupling problems the audit found.

## Consequences

- **Positive:** one process to deploy (upload-and-run); in-process calls are cheap
  and transactional; shared concerns stay shared; the seams still enable later
  extraction if a component genuinely needs independent scaling.
- **Negative / accepted trade-offs:** the whole app scales as a unit (mitigated by
  the [driver strategy](0012-swappable-driver-interfaces.md) and caching); a
  runaway module can affect the process (mitigated by fault isolation —
  [10-Security/error-handling.md](../10-Security/error-handling.md)); discipline is
  required to keep module boundaries real without a network forcing them.

## Related

- [ADR-0001](0001-flat-php-over-framework.md) · [ADR-0005](0005-events-and-contracts-for-modules.md) · [ADR-0012](0012-swappable-driver-interfaces.md)
- [01-Architecture](../01-Architecture/)
