# ADR-0004 — Kernel + service container + capability contracts

**Status:** Accepted   **Date:** 2026-07-27

## Context

Today modules are loaded by a `PluginLoader` and expose global classes
(`BookingAPI`, `ShopAPI`) that others reach via `class_exists()`. There is no
dependency injection, no service resolution, and no way to substitute an
implementation. This makes testing hard and coupling brittle.

## Decision

Introduce a small **kernel** with a **service container** (PSR-11-style, lazy,
constructor-injection/factory closures) and a **module manager**. Services are
registered as **capability contracts** (interfaces) and resolved from the
container; nothing constructs a cross-boundary dependency with `new`.

## Alternatives considered

- **Keep global classes + `class_exists`.** Rejected: name collisions in a flat
  namespace, no versioning, no substitution, untestable.
- **A heavy framework container.** Rejected per [ADR-0001](0001-flat-php-over-framework.md).
- **Service locator only (no DI).** Rejected: hides dependencies and keeps testing
  painful; the container supports constructor injection so dependencies are
  explicit.

## Consequences

- **Positive:** implementations are swappable (real vs fake for tests, Stripe vs
  PayPal for payments); dependencies are explicit; the boot cache can compile
  wiring; no global-name collisions.
- **Negative / accepted trade-offs:** a learning curve vs "just call the class";
  the container is machinery we own and must keep minimal; over-abstraction is a
  risk we manage by only exposing contracts that need substitution.

## Related

- [ADR-0001](0001-flat-php-over-framework.md) · [ADR-0005](0005-events-and-contracts-for-modules.md)
- [01-Architecture/kernel.md](../01-Architecture/kernel.md) · [01-Architecture/service-layer.md](../01-Architecture/service-layer.md)
