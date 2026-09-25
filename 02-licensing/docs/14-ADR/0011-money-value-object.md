# ADR-0011 — `Money` value object (integer minor units) platform-wide

**Status:** Accepted   **Date:** 2026-07-27

## Context

Money is represented inconsistently: `shop` stores `DECIMAL(12,2)` while booking,
membership, restaurant, and the Stripe gateway use integer **minor units**
(`*_cents`). Every shop↔gateway handoff is a float→cents conversion — a rounding-
risk boundary — and there is no single, safe money type in the code.

## Decision

Standardize on a **`Money` value object = integer minor units + currency**, used
end to end (schema columns, application code, events, API payloads). Floats and
`DECIMAL` for money are prohibited. Shop's `DECIMAL` columns migrate to minor
units.

## Alternatives considered

- **Standardize on DECIMAL.** Rejected: floating/decimal arithmetic invites
  rounding bugs and doesn't match payment providers, which are cents-based.
- **Leave the mix, convert at boundaries.** Rejected: every boundary is a bug
  surface; there is no canonical type to reason about or test.

## Consequences

- **Positive:** no rounding drift; one type to test and reason about; matches
  payment providers exactly (no conversion at the gateway); currency travels with
  the amount, enabling multi-currency later.
- **Negative / accepted trade-offs:** a **data migration** of shop's money columns
  (and any report/query assuming decimals); developers must construct `Money`
  rather than use bare numbers; API money is an object `{amount,currency}`, not a
  scalar (a deliberate, documented shape — [07-API/versioning-and-errors.md](../07-API/versioning-and-errors.md)).

## Related

- [ADR-0010](0010-migrations-over-ensureschema.md) · [ADR-0005](0005-events-and-contracts-for-modules.md)
- [11-Database/schema-conventions.md](../11-Database/schema-conventions.md) · [07-API/payments.md](../07-API/payments.md)
