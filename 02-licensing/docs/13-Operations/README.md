# 13 — Operations

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How Slate is deployed, kept fast, and kept observable — while honoring the
*no-build-step* and *optionality* principles ([00-Vision](../00-Vision/)): it runs
on $4 shared hosting by default and scales to a VPS/cluster for enterprise
tenants through **driver swaps**, not rewrites.

---

## Section contents

- **[deployment.md](deployment.md)** — upload-and-run, config, upgrades, backups.
- **[shared-hosting-compatibility.md](shared-hosting-compatibility.md)** — the
  driver matrix that makes "deploy anywhere / scale to enterprise" coexist.
- **[performance-and-caching.md](performance-and-caching.md)** — the caching tiers
  and performance budgets.
- **[logging-and-auditing.md](logging-and-auditing.md)** — diagnostics vs the
  business audit trail.

---

## 1. Operating principles

- **No server build.** Deploy by upload; assets compose at runtime and cache
  ([05-Rendering/assets.md](../05-Rendering/assets.md)). Nothing to compile.
- **Everything scale-sensitive is a driver.** Cache, queue, tenancy storage, and
  search each have a shared-hosting default and a heavier optional driver behind
  one interface (ADR-0012). The deployment target changes; modules don't.
- **Fail safe, observe everything.** Errors never leak
  ([10-Security/error-handling.md](../10-Security/error-handling.md)); every
  request is correlation-id'd; sensitive actions are audited.

## 2. The two postures

| Posture | Cache | Queue | Tenancy | Search |
|---|---|---|---|---|
| **Shared hosting** (default) | file / APCu | DB table + cron | shared DB + `tenant_id` | MySQL FULLTEXT |
| **VPS / enterprise** (optional) | Redis | Redis/worker | schema-/DB-per-tenant | external engine |

Both run the identical module code. Moving between them is configuration, not
migration of application logic.

---

## Related

- [deployment.md](deployment.md) · [shared-hosting-compatibility.md](shared-hosting-compatibility.md) · [performance-and-caching.md](performance-and-caching.md) · [logging-and-auditing.md](logging-and-auditing.md)
- [01-Architecture/multi-tenancy.md](../01-Architecture/multi-tenancy.md) · [14-ADR](../14-ADR/) (0012)
- [running-tests-safely.md](running-tests-safely.md) — provisioning an isolated test database, the guard that refuses production, and the `SLATE_ALLOW_LIVE_DB` opt-in.
