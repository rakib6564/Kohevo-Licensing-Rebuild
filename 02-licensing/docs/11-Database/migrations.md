# 11 — Migration Framework

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

Versioned, ordered, reversible schema evolution. Replaces the `ensureSchema()`
self-heal that runs on the request path today. Realizes invariant #10 and
ADR-0010.

> **Problem being solved.** Today ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) schema
> evolves via per-plugin `ensureSchema()` that reconciles columns on boot —
> because `CREATE TABLE IF NOT EXISTS` can't add columns to an existing table.
> It can't rename or transform data, it runs on the hot path, and its ordering is
> implicit. Migrations fix all four.

---

## 1. What a migration is

```php
// modules/shop/migrations/0007_orders_money_to_minor_units.php
final class OrdersMoneyToMinorUnits extends Migration {
    public function up(Schema $s): void {
        $s->table('shop_orders', function (Table $t) {
            $t->bigInt('total_minor')->after('total');       // add
        });
        $s->raw('UPDATE shop_orders SET total_minor = ROUND(total * 100)'); // data transform
        $s->table('shop_orders', fn(Table $t) => $t->drop('total'));        // remove old
    }
    public function down(Schema $s): void { /* true inverse */ }
}
```

- **Versioned & ordered.** Numeric prefix per module; the runner applies pending
  migrations in order and records them in a `migrations` ledger (per tenant where
  the tenancy driver requires it).
- **Reversible.** `down()` is a real inverse (enables rollback in dev/staging).
- **Data-capable.** Can transform/backfill — exactly what `ensureSchema` cannot
  (e.g. the DECIMAL→minor-units and the 5-table identity consolidation).
- **Off the hot path.** Migrations run at activate/upgrade/deploy, never per
  request.

## 2. The runner

```
on activate/upgrade/deploy:
  for each module in dependency order:
    apply pending migrations (record in ledger)   # idempotent, transactional per step
```

- **Idempotent** — already-applied migrations are skipped via the ledger.
- **Ownership-scoped** — a module's migrations touch only its own tables
  (invariant #1); attempts to alter another module's or core tables are rejected.
- **Dependency-ordered** — a module that `requires` another runs after it
  ([01-Architecture/plugin-architecture.md](../01-Architecture/plugin-architecture.md)).

## 3. Production policy

- **Forward-only in production**, authored reversible for lower environments.
- A schema change ships **with the code MINOR that needs it** and never assumes an
  out-of-order apply ([03-Standards/versioning-and-compatibility.md](../03-Standards/versioning-and-compatibility.md)).
- **Backward-compatible windows** for online changes: add column → backfill →
  switch reads → drop old, across releases, so a deploy never requires downtime or
  a big-bang alter.

## 4. Migrating from `ensureSchema`

The refactor ([09-Roadmap Phase 1](../09-Roadmap/refactor-roadmap.md)) captures
each plugin's current reconciled schema as an initial `0001_init` migration, then
future changes are migrations. The self-heal is deleted once the ledger is the
source of truth — no more per-request `INFORMATION_SCHEMA` probing.

## 5. Multi-tenant considerations

Under shared-DB, migrations run once. Under schema-/DB-per-tenant (ADR-0012), the
runner applies each migration per tenant schema/DB; the ledger is per tenant. The
migration code is identical — the tenancy driver decides the fan-out.

---

## Related

- [README.md](README.md) · [repository-service-pattern.md](repository-service-pattern.md) · [schema-conventions.md](schema-conventions.md)
- [01-Architecture/plugin-architecture.md](../01-Architecture/plugin-architecture.md) · [09-Roadmap](../09-Roadmap/) · [ADR-0010](../14-ADR/)
