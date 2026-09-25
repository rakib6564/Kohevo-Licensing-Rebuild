# 11 — Database & Data Layer

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How Slate persists data: a lightweight, predictable data layer over PDO/MySQL
that makes the dangerous things (tenant scoping, money, schema change) safe by
default. Realizes the *boring-where-it-counts* principle ([00-Vision](../00-Vision/))
and invariants #2, #3, #4, #10.

> **Problem being solved.** Today ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) it's
> raw SQL everywhere with **manual** `AND tenant_id = ?` (a proven leak surface),
> `ensureSchema()` self-heal instead of migrations, money as `DECIMAL` in shop vs
> integer cents elsewhere, and table prefixes that don't match slugs. The data
> layer closes each of these.

---

## Section contents

- **[repository-service-pattern.md](repository-service-pattern.md)** — repositories,
  automatic tenant scoping, services, thin controllers.
- **[migrations.md](migrations.md)** — the versioned migration framework.
- **[schema-conventions.md](schema-conventions.md)** — naming, `Money`, indexes,
  the core table catalogue.

---

## 1. Not a heavy ORM (ADR-0010 context)

Slate uses a **lightweight ActiveRecord/Repository + fluent query builder** over
PDO — not Doctrine/Eloquent-scale mapping. Why:

- **Shared hosting, no build step** — nothing to compile, minimal memory.
- **Predictability** — SQL stays close and inspectable; no hidden N+1 magic.
- **Just enough abstraction** to enforce the invariants (tenant scope, `Money`)
  centrally, which is the whole point.

```php
$orders = $repo->where('status','paid')->orderBy('created_at','desc')->limit(20)->all();
// → prepared statement, tenant predicate auto-injected, rows hydrated to Order entities
```

## 2. What the layer guarantees

| Guarantee | Mechanism | Invariant |
|---|---|---|
| No cross-tenant leak | base repository injects `tenant_id` | #2 |
| Money is never a float | `Money` value object + `money()` column type | #3 |
| One person, many profiles | Identity service owns `contacts`; modules key by `contact_id` | #4 |
| Safe schema evolution | migrations, not `ensureSchema` | #10 |
| Prepared statements throughout | query builder + PDO (emulation off) | security |

## 3. Connections & tenancy

- One PDO connection per request in the shared-DB default; the
  [tenancy driver](../01-Architecture/multi-tenancy.md) may select a
  schema/connection per tenant at scale (ADR-0012) **without** changing
  repositories.
- READ COMMITTED isolation; row-locking (`SELECT … FOR UPDATE`) for the
  race-sensitive paths (slot booking, gift-card debit, coupon redemption).

---

## Related

- [01-Architecture/multi-tenancy.md](../01-Architecture/multi-tenancy.md) · [02-Domain/identity-contacts.md](../02-Domain/identity-contacts.md)
- [07-API/payments.md](../07-API/payments.md) · [14-ADR](../14-ADR/) (0010, 0011, 0012)
