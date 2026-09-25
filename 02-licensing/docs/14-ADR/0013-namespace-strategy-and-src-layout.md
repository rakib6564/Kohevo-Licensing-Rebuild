# ADR-0013 — Namespace strategy & `src/` layout

**Status:** Accepted   **Date:** 2026-07-27

## Context

Today the codebase has **no namespaces** — ~88k lines of global classes loaded by
~691 `require_once`, coupled via `class_exists('GlobalName')`. Phase 1 introduces
PSR-4 autoloading, which forces a one-time, permanent decision: what are the
top-level namespaces and the `src/` layout? Getting this wrong means renaming
foundational namespaces later — the most disruptive change possible once modules
and (eventually) third parties depend on them. It must be settled **before** the
first `use` statement.

## Decision

Adopt vendor root **`Slate\` → `src/`** (PSR-4) with **ten top-level namespaces
mapped 1:1 to the Architecture Blueprint layers**: `Kernel`, `Support`, `Data`,
`Tenancy`, `Contracts`, `Domain`, `Services`, `Presentation`, `Sdk`, `Module`.
Two disambiguating rules: **Domain = nouns/models, Services = verbs/orchestration**;
**Contracts = interfaces (covered surface), Services = implementations**. The full
map, `src/` tree, and conventions are specified in
[03-Standards/platform-foundation.md](../03-Standards/platform-foundation.md).
Migration is additive behind `class_alias()` shims — nothing renamed/removed until
its replacement is proven ([09-Roadmap/phase1-kernel-substrate.md](../09-Roadmap/phase1-kernel-substrate.md)).

## Alternatives considered

- **`Slate\Core` catch-all + flat structure.** Rejected: "Core" becomes a dumping
  ground with no boundary discipline; a flat tree doesn't express the layer model
  the whole architecture rests on.
- **Framework-imposed structure (Laravel `app/`, Symfony bundles).** Rejected per
  [ADR-0001](0001-flat-php-over-framework.md); we own a layout that matches *our*
  blueprint, not a framework's conventions.
- **Fold `Data`/`Tenancy` under `Kernel`.** Rejected: both are first-class concerns
  with their own hub sections ([11-Database](../11-Database/),
  [multi-tenancy](../01-Architecture/multi-tenancy.md)); top-level makes them
  discoverable and keeps `Kernel` strictly plumbing.
- **Merge `Contracts` into `Sdk`.** Rejected: contracts must be dependency-light and
  referenceable without pulling the SDK base classes; a distinct `Slate\Contracts`
  is the stable, minimal covered surface.
- **Composer-managed autoloader.** Rejected for the *loader mechanism*: no
  `composer install` on shared hosting ([ADR-0001](0001-flat-php-over-framework.md));
  a hand-rolled PSR-4 loader is used. (The PSR-4 *standard* is still followed.)

## Consequences

- **Positive:** the source tree literally is the architecture — a new developer
  navigates by layer; boundaries are greppable (a module importing
  `Slate\Services\*` is a visible violation); the decision is made once and
  documented; BC is total via aliases so migration never breaks the live site.
- **Negative / accepted trade-offs:** ten namespaces is more up-front structure
  than a flat layout; the Domain/Services and Contracts/Services splits require
  discipline to honor; the `class_alias` compat layer must be maintained until a
  future major; migrating ~15 core classes is careful, one-commit-at-a-time work.

## Related

- [ADR-0001](0001-flat-php-over-framework.md) · [ADR-0004](0004-kernel-container-contracts.md) · [ADR-0009](0009-semver-sdk-bc-policy.md)
- [03-Standards/platform-foundation.md](../03-Standards/platform-foundation.md) · [09-Roadmap/phase1-kernel-substrate.md](../09-Roadmap/phase1-kernel-substrate.md)
