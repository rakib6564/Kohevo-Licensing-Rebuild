# ADR-0009 — Semver'd SDK with a backward-compatibility policy

**Status:** Accepted   **Date:** 2026-07-27

## Context

Slate intends to support first-party and eventually third-party modules for
10–15 years (a v5 marketplace). Without a defined, stable surface and a
compatibility promise, every internal refactor risks breaking modules, and no
external developer can safely build on the platform.

## Decision

Define a **stable, semantically-versioned SDK** as the *only* surface modules
depend on (base classes, capability interfaces, the manifest schema, the event
catalogue, the documented HTTP API). Adopt a **backward-compatibility policy**:
breaking changes are gated to MAJOR versions and preceded by a deprecation window;
everything not in the SDK is internal and may change freely.
([03-Standards/versioning-and-compatibility.md](../03-Standards/versioning-and-compatibility.md).)

## Alternatives considered

- **No formal SDK boundary.** Rejected: modules would couple to internals, making
  every refactor a breaking change and an ecosystem impossible.
- **Freeze internals for stability.** Rejected: kills the ability to improve the
  platform; the point of the SDK boundary is to keep internals *free* to change.

## Consequences

- **Positive:** the platform can be rebuilt internally many times without breaking
  modules; third parties get a dependable contract (precondition for the
  marketplace); deprecations are predictable.
- **Negative / accepted trade-offs:** discipline to keep the public surface small
  and to honor deprecation windows even when inconvenient; a public-API snapshot
  must be maintained and checked in CI ([12-Testing/architecture-conformance.md](../12-Testing/architecture-conformance.md));
  some desirable breaking changes must wait for a MAJOR.

## Related

- [ADR-0004](0004-kernel-container-contracts.md) · [ADR-0005](0005-events-and-contracts-for-modules.md)
- [03-Standards/versioning-and-compatibility.md](../03-Standards/versioning-and-compatibility.md) · [06-SDK](../06-SDK/)
