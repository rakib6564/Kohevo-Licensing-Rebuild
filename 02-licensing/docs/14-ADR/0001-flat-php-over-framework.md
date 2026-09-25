# ADR-0001 — Flat PHP over a full framework

**Status:** Accepted   **Date:** 2026-07-27

## Context

Slate must be deployable by upload to commodity shared cPanel hosting under a
sub-path, by users without root or a build toolchain. It already runs as flat PHP
8.x + MySQL with a hook system and no build step. The temptation, when
professionalizing the architecture, is to adopt a full framework (Laravel,
Symfony) for its container, ORM, and conventions.

## Decision

Build Slate as **flat, framework-free PHP** with a small purpose-built kernel
(container, module manager, event bus, lifecycle — [ADR-0004](0004-kernel-container-contracts.md)),
borrowing framework *patterns* (DI, PSR-4, migrations) without adopting a
framework *runtime*.

## Alternatives considered

- **Adopt Laravel/Symfony wholesale.** Rejected: assumes `composer install`, a
  build/artisan step, and resource headroom that shared hosting doesn't
  guarantee; trades the "runs anywhere by upload" promise for framework ceremony;
  a heavy migration of ~88k lines with little payoff over a focused kernel.
- **Stay ad-hoc (status quo).** Rejected: global classes + `class_exists`
  coupling don't scale to 50 modules ([ADR-0004](0004-kernel-container-contracts.md),
  [ADR-0005](0005-events-and-contracts-for-modules.md)).

## Consequences

- **Positive:** preserves upload-and-run deployability; minimal memory/footprint;
  full control of the kernel surface; no framework upgrade treadmill; the team
  owns every abstraction.
- **Negative / accepted trade-offs:** we build and maintain kernel machinery a
  framework would give free (container, router, migration runner); we forgo the
  framework's ecosystem and hiring familiarity; we must be disciplined to keep the
  kernel *small* rather than reinventing a framework badly.

## Related

- [ADR-0002](0002-modular-monolith-over-microservices.md) · [ADR-0003](0003-server-rendered-no-build.md) · [ADR-0004](0004-kernel-container-contracts.md)
- [00-Vision](../00-Vision/) · [01-Architecture](../01-Architecture/)
