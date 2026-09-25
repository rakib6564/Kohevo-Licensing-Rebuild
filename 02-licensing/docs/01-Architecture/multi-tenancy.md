# 01 — Multi-Tenancy

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How Slate isolates many tenants on one install — across data, config, theme,
assets, and routing — and how the *storage strategy* behind that isolation can
change from shared-hosting to enterprise without touching a single module.

> **Problem being solved.** Multi-tenancy is structurally present today
> (`tenant_id` on every table) but **enforced by hand**: every query must
> remember `AND tenant_id = ?`. Per [AUDIT-BRIEFING](../AUDIT-BRIEFING.md),
> some queries scope correctly and some don't — one forgotten predicate is a
> cross-tenant data leak. This document makes scoping **structural**: you opt
> *out* loudly, you never opt *in* silently. Realizes invariant #2 and ADR-0012.

---

## 1. The tenant context

A **Tenant** is an isolated customer of an install. Exactly one tenant is
"current" for the duration of a request, established early in the
[lifecycle](request-lifecycle.md) (step 3, before auth) and injected into every
service via the container.

**Resolution order** (first match wins):
1. **CLI/cron override** — an explicit `--tenant` / global for jobs.
2. **Host match** — `example.com` → tenant by primary domain.
3. **Subdomain** — `acme.slate.app` → tenant `acme`.
4. **Sub-path** — `/t/acme/…` (and the install base path `/slate/`).
5. **Fallback** — the default tenant (id 1) for single-tenant installs.

```php
interface TenantContext {
    public function id(): int;
    public function tenant(): Tenant;
    public function runAs(int $tenantId, callable $fn): mixed; // scoped switch (super-admin tooling, cron)
}
```

Nothing downstream reads `$_SERVER` or a constant to find the tenant — they ask
the injected `TenantContext`. This is the single source of truth.

---

## 2. Automatic data scoping

Scoping lives in the **base repository** ([11-Database](../11-Database/repository-service-pattern.md)),
not in each query. Every read and write a repository issues is stamped with the
current `tenant_id` automatically.

```php
abstract class Repository {
    // every query gains "AND tenant_id = :tid"; every insert sets tenant_id
    protected function scoped(QueryBuilder $q): QueryBuilder { /* injects tenant predicate */ }

    // the ONLY way to cross tenants — explicit, greppable, and audited
    public function crossTenant(callable $fn): mixed { /* logs an audit entry, lifts the scope */ }
}
```

- **Default is safe.** A module author who writes `orders->all()` gets only the
  current tenant's orders. They cannot forget the predicate because they never
  write it.
- **Opting out is loud.** Cross-tenant access (platform admin dashboards,
  billing rollups) must call `crossTenant()`, which writes an audit-log entry.
  Grep for `crossTenant` and you have the complete list of every place isolation
  is deliberately lifted — impossible today.
- **Conformance-testable.** [12-Testing](../12-Testing/architecture-conformance.md)
  asserts no repository issues an unscoped query outside a `crossTenant` block.

---

## 3. What else is tenant-scoped

| Concern | How it's scoped |
|---|---|
| Settings | `settings` keyed by `(tenant_id, key)`; the Settings service auto-scopes |
| Theme | each tenant selects a Theme + token overrides ([05-Rendering](../05-Rendering/theme-and-template-engine.md)) |
| Media / assets | uploads land under a per-tenant path; the Media service scopes queries |
| Cache | every cache key is prefixed with `tenant_id` so tenants never share entries |
| Identity | a Contact belongs to one tenant; the same email in two tenants = two contacts |
| Routing | the resolved tenant selects which site/pages/modules answer the request |
| Audit / logs | every entry carries `tenant_id` |

---

## 4. Storage strategy is swappable (ADR-0012)

The *logical* model (a `tenant_id` on every row, one current tenant) is fixed.
The *physical* storage behind it is a driver, chosen per deployment, behind one
interface — so the same modules run unchanged on $4 shared hosting and on an
enterprise cluster.

| Strategy | When | Isolation | Notes |
|---|---|---|---|
| **Shared DB + `tenant_id`** (default) | shared hosting, most installs | logical (row-level) | one schema; the base-repository scope is the isolation boundary |
| **Schema-per-tenant** | larger installs, noisy-neighbor concerns | strong (separate schemas) | the driver rewrites the target schema; modules unchanged |
| **Database-per-tenant** | enterprise, compliance, data residency | strongest (separate DBs/hosts) | the driver selects a connection per tenant; enables per-tenant backup/restore/move |

Because scoping is centralized (§2), moving from shared-DB to DB-per-tenant is a
**driver swap**, not a module rewrite. Modules keep calling
`orders->all()`; the tenancy driver decides which schema/connection that lands
on.

---

## 5. Tenant lifecycle

- **Provision** — create the tenant row, seed default settings/roles/theme,
  (in the DB-per-tenant driver) create the schema/DB and run migrations.
- **Suspend** — flip status; requests resolve to a branded "suspended" response;
  data retained.
- **Export / move** — per-tenant export (trivial under DB-per-tenant; a scoped
  dump under shared-DB). Feeds GDPR and self-serve migration (v4/v5).
- **Delete** — hard-delete all rows for the tenant (or drop the schema/DB),
  cascade through the profile-provider registry ([02-Domain](../02-Domain/identity-contacts.md)).

Self-serve provisioning at scale is a v4/v5 concern ([09-Roadmap](../09-Roadmap/implementation-roadmap.md));
the abstraction that makes it cheap is defined here, now.

---

## Related

- [request-lifecycle.md](request-lifecycle.md) (tenant-resolve step) · [service-layer.md](service-layer.md)
- [11-Database/repository-service-pattern.md](../11-Database/repository-service-pattern.md) · [02-Domain/identity-contacts.md](../02-Domain/identity-contacts.md)
- [13-Operations/shared-hosting-compatibility.md](../13-Operations/shared-hosting-compatibility.md) · [ADR-0012](../14-ADR/)
