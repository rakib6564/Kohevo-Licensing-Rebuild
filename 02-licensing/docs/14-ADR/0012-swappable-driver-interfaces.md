# ADR-0012 — Swappable driver interfaces for shared-hosting ↔ enterprise

**Status:** Accepted   **Date:** 2026-07-27

## Context

Slate must run on commodity shared hosting (PHP + MySQL + cron) *and* scale to
VPS/enterprise for large tenants — from **one codebase**. If the architecture
hard-assumed Redis, a worker daemon, an external search engine, or a particular
tenancy layout, it would break the "deploy anywhere" promise; if it hard-assumed
shared hosting, it couldn't scale.

## Decision

Define every scale-sensitive concern as an **interface with two (or more) drivers**:
a **shared-hosting default** and an **optional heavier driver**, chosen by
configuration. Applies to **cache** (file/APCu→Redis), **queue** (DB+cron→worker),
**tenancy storage** (shared-DB→schema-/DB-per-tenant), **search**
(MySQL FULLTEXT→external), sessions, and mail. Modules code to the interface and
never know which driver is active.

## Alternatives considered

- **Target shared hosting only.** Rejected: caps the platform's ceiling; enterprise
  tenants (data residency, volume) couldn't be served.
- **Target VPS/containers only.** Rejected: breaks upload-and-run and excludes the
  large shared-hosting market that is Slate's base.
- **Fork per posture.** Rejected: two codebases to maintain — the exact thing this
  avoids.

## Consequences

- **Positive:** one codebase runs from $4 hosting to an enterprise cluster; moving
  up is a config/driver change, not a rewrite; modules are insulated from
  infrastructure; graceful degradation when an optional facility is absent.
- **Negative / accepted trade-offs:** every such concern must be designed as an
  interface up front (more abstraction than a single hard choice); the default
  drivers must be genuinely production-grade, not toys; more drivers to test
  (the interface contract is the test boundary).

## Related

- [ADR-0002](0002-modular-monolith-over-microservices.md) · [ADR-0001](0001-flat-php-over-framework.md)
- [13-Operations/shared-hosting-compatibility.md](../13-Operations/shared-hosting-compatibility.md) · [01-Architecture/multi-tenancy.md](../01-Architecture/multi-tenancy.md)
