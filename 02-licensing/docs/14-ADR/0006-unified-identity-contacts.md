# ADR-0006 — Unified Identity/Contacts model

**Status:** Accepted   **Date:** 2026-07-27

## Context

A person is currently modeled up to **five ways** — `customers`,
`booking_customers`, `shop_customers`, `restaurant_customers`,
`clientdesk_clients` — keyed loosely by email with no enforced foreign key. Cross-
module identity resolution is impossible; a real CRM cannot be built on this. This
is the platform's single largest data-architecture debt.

## Decision

Establish **one core `contacts` model** (person OR organization) with an
`identities` auth linkage. Every module attaches a **profile** keyed by
`contact_id` (a booking profile, a shop-customer profile, a CRM lead) — **one
identity, many profiles.** A module never creates a person table and never copies
name/email/phone.

## Alternatives considered

- **Leave per-module customer tables, reconcile by email.** Rejected: email is not
  a stable key, no referential integrity, duplicate/merge chaos, no single view of
  a person.
- **A master-data sync between tables.** Rejected: accidental complexity to keep
  five copies consistent; still no single source of truth.

## Consequences

- **Positive:** one source of truth for a person; a CRM becomes a straightforward
  module; cross-module reporting, dedup/merge, and GDPR export/erase-by-person all
  become possible; profiles keep module-specific data cleanly separated.
- **Negative / accepted trade-offs:** a **data migration** consolidating five
  tables with a dual-write window and match precedence — the hardest single piece
  of the refactor, and irreversible without the [Phase-0](../09-Roadmap/refactor-roadmap.md)
  safety net; module code must be reworked to read the Contact rather than a local
  copy.

## Related

- [ADR-0005](0005-events-and-contracts-for-modules.md) · [ADR-0010](0010-migrations-over-ensureschema.md)
- [02-Domain/identity-contacts.md](../02-Domain/identity-contacts.md) · [09-Roadmap Phase 2](../09-Roadmap/refactor-roadmap.md)
