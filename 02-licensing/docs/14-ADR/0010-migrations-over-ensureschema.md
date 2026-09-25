# ADR-0010 — Migration framework over `ensureSchema()` self-heal

**Status:** Accepted   **Date:** 2026-07-27

## Context

Schema evolves today via per-plugin `ensureSchema()` that reconciles columns on
boot (because `CREATE TABLE IF NOT EXISTS` can't add columns to existing tables).
This runs on the request path, can't rename or transform data, has implicit
ordering, and probes `INFORMATION_SCHEMA` at runtime.

## Decision

Adopt a **versioned migration framework**: ordered, reversible, data-capable
migration files per module, applied by a runner at activate/upgrade/deploy and
recorded in a ledger. Retire `ensureSchema()`.

## Alternatives considered

- **Keep `ensureSchema` self-heal.** Rejected: cannot do renames/backfills (needed
  for the DECIMAL→minor-units and identity consolidations), runs on the hot path,
  implicit ordering.
- **Manual SQL patch files run by hand.** Rejected: error-prone, no ledger, no
  ordering guarantees, no per-tenant fan-out.

## Consequences

- **Positive:** true schema evolution (rename/transform/backfill); off the request
  path; explicit ordering with dependency awareness; per-tenant application under
  heavier tenancy drivers; a ledger that makes state auditable.
- **Negative / accepted trade-offs:** authors must write migrations (and reversible
  `down`s); an initial pass to capture each module's current schema as `0001_init`;
  production is forward-only, so a bad migration needs a compensating one, not an
  in-place edit.

## Related

- [ADR-0006](0006-unified-identity-contacts.md) · [ADR-0011](0011-money-value-object.md)
- [11-Database/migrations.md](../11-Database/migrations.md) · [09-Roadmap Phase 1](../09-Roadmap/refactor-roadmap.md)
