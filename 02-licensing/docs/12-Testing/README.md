# 12 — Testing Strategy

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The safety net that makes the whole blueprint survivable. Realizes the
*process-protects-architecture* principle ([00-Vision](../00-Vision/)): a perfect
design with nothing to catch regressions erodes back into coupling within a year.

> **Starting point** ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)): **no tests, no CI,
> no version control.** [Phase 0 of the refactor roadmap](../09-Roadmap/refactor-roadmap.md)
> establishes all three before any structural change. This document defines what
> "tested" means going forward.

---

## Section contents

- **[architecture-conformance.md](architecture-conformance.md)** — turning the
  §7 invariants into automated gates (the anti-erosion mechanism).

---

## 1. The pyramid (adapted to flat PHP)

| Layer | Covers | Speed | Notes |
|---|---|---|---|
| **Unit** | services, value objects (`Money`), policies, block schemas | fast | fake repositories; no DB |
| **Integration** | repositories + migrations against a real test DB | medium | per-tenant test schema |
| **HTTP/functional** | routes → controller → service → response | medium | boots the kernel; asserts status + envelope |
| **Smoke** | install, login, a booking, a payment happy-path | slow | the Phase-0 minimum |

Bias toward **unit + integration**; keep a thin, high-value HTTP/smoke layer.

## 2. What to test first (priority)

The invariants and the money paths — where bugs are most expensive:

1. **Money** — no float ever; conversions correct; totals reconcile.
2. **Tenancy** — no query escapes tenant scope; cross-tenant access is refused.
3. **Auth/authz** — the policy engine denies correctly; self-protection guards.
4. **Payments** — intent→reconcile→event; refunds; double-webhook idempotency.
5. **Migrations** — up/down round-trip; ordering; ownership.

## 3. Tooling (shared-hosting-friendly)

- **PHPUnit** (or a lightweight equivalent) that runs with `composer`/PHP alone —
  **no Node, no build**. Tests must run on a shared-hosting-like environment.
- **Fixtures/factories** for contacts, tenants, orders — a factory per core
  entity; modules ship factories for their own.
- **A dedicated test database** (and, for tenancy tests, multiple tenant rows /
  schemas) reset between runs.

## 4. CI (the gate)

- A simple runner executes the suite **on every push/PR**; red = no merge.
- CI also runs the [architecture-conformance](architecture-conformance.md) checks
  and a lint/static pass ([03-Standards](../03-Standards/)).
- Kept lightweight enough to run without heavy infrastructure — matching the
  no-build ethos (a container or a plain PHP runner both work).

## 5. Module definition of done

Every module ships tests for its **money, auth, and tenancy** paths and passes
conformance before merge ([03-Standards/module-development-standards.md](../03-Standards/module-development-standards.md),
[15-Contributing](../15-Contributing/)).

---

## Related

- [architecture-conformance.md](architecture-conformance.md) · [03-Standards](../03-Standards/) · [09-Roadmap Phase 0](../09-Roadmap/refactor-roadmap.md) · [15-Contributing](../15-Contributing/)
