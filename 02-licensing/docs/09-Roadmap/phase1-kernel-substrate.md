# 09 — Phase 1 Scope: Kernel Substrate

**Status:** Implemented — Phase 1 complete (pending merge to `main`) · **Applies to:** current codebase → Slate v1.x
**Prereq:** Phase 0 complete (git + smoke suite). See [refactor-roadmap.md](refactor-roadmap.md).

Phase 1 builds the invisible foundation everything else rides on: **PSR-4
autoload + namespaces**, a **migration framework**, and a **tenant-scoped data
layer**. It is the first phase that restructures real code, so the governing rule
is absolute:

> **Nothing breaks the live site. Every step is additive and backward-compatible;
> nothing is removed until its replacement is proven. The smoke suite runs after
> every step, on a branch, one small commit at a time.**

Plugins are **not** migrated in Phase 1 — they keep working unchanged through
compatibility shims. Phase 1 changes the *core* substrate only.

---

## Goals & non-goals

**Goals**
- A PSR-4 autoloader active alongside today's `require_once` (no big-bang).
- Core `includes/` classes migratable into `Slate\…` namespaces behind global
  `class_alias` shims — so the 691 `require_once`s and every plugin's
  `class_exists('Database')` keep working.
- A migration framework + ledger, coexisting with `ensureSchema()` (which is left
  untouched this phase).
- A tenant-scoped data layer (base `Repository`, `QueryBuilder`, `TenantContext`,
  `Money`) available for new/migrated code — purely additive.

**Non-goals (deferred)**
- Migrating plugins to namespaces (v3 rebuilds).
- Deleting `ensureSchema()` (Phase 1 only adds the alternative).
- Converting all raw `Database::query` call-sites (proof-of-concept only).
- Identity unification (Phase 2), contracts (Phase 3).

---

## Workstream A — PSR-4 autoload + namespaces *(do first; lowest risk)*

> **Design finalized first (ADR-0013).** The permanent namespace map, the complete
> `src/` tree, the autoloading/coding conventions, and the `class_alias` BC policy
> (including the core-class → new-FQCN mapping table) are settled in
> [03-Standards/platform-foundation.md](../03-Standards/platform-foundation.md)
> **before** any code is written — so these foundational decisions are made once.

The foundation the new kernel lives in. Additive: the autoloader only *adds* a way
to load classes; nothing existing changes.

1. **Add a hand-rolled PSR-4 autoloader** (no `composer install` on shared hosting)
   in `config.php`: map `Slate\` → `src/`. Create `src/`. *(Zero existing code
   touched.)*
2. **Prove it**: one trivial `Slate\Kernel\Ping` class + a smoke assertion that it
   autoloads. Commit.
3. **Migrate core classes one at a time**, each with a global alias so nothing
   downstream breaks:
   ```php
   // src/Data/Database.php
   namespace Slate\Data;
   final class Database { /* moved from includes/Database.php */ }
   // config.php (compat bridge, removed only in a later major)
   class_alias(\Slate\Data\Database::class, 'Database');
   ```
   Order: leaf/independent classes first (`Hook`, `I18n`, `AuditLog`), then
   `Database`, then `Auth`/`PluginLoader`. Smoke test + commit after each.
4. **Outcome:** new code is namespaced; core is migrating; `require_once` count
   shrinks; plugins untouched (aliases satisfy `class_exists`).

**Risk:** low per step (alias preserves the old name), but broad. Mitigation:
one class per commit, smoke after each, alias every moved class.

---

## Workstream B — Migration framework *(after A establishes namespaces)*

1. `Slate\Data\Migration` base class (`up`/`down` over a `Schema`/`Table` builder)
   + a `migrations` ledger table + a runner (`bin/migrate`).
2. Express the **core** `db/schema.sql` as the first migrations (`0001_core_init`
   …) recorded in the ledger for fresh installs; on existing installs, mark them
   already-applied (baseline stamp) so nothing re-runs.
3. **Coexist:** `ensureSchema()` in plugins is left exactly as-is. The runner
   governs *core* schema now; plugins adopt migrations when rebuilt.
4. **Outcome:** core schema evolves via ordered, reversible, data-capable
   migrations off the request path. `ensureSchema` untouched.

**Risk:** medium (touches schema tooling). Mitigation: on existing DBs the runner
only *stamps* the baseline as applied — it does not alter existing tables; new
migrations are additive and tested on a copy first.

---

## Workstream C — Tenant-scoped data layer *(parallel with B; needs A)*

1. `Slate\Tenancy\TenantContext` (resolves + holds the current tenant; wraps
   today's `current_tenant_id()`).
2. `Slate\Data\QueryBuilder` (fluent, prepared) + `Slate\Data\Repository` base
   that **auto-injects `tenant_id`** and exposes an audited `crossTenant()`.
3. `Slate\Money\Money` value object (integer minor units + currency).
4. **Proof-of-concept:** reimplement one safe core read (e.g. settings fetch) via a
   repository and assert parity in the smoke suite. Do **not** convert all
   call-sites — raw `Database::` keeps working.
5. **Outcome:** new code can be tenant-safe and `Money`-typed by construction;
   old code is unaffected.

**Risk:** low (additive). Mitigation: parity test against the existing path before
anything relies on it.

---

## Sequencing & branching

```
main ──▶ branch: phase1-kernel-substrate
  A1 autoloader + src/ (+smoke)         → commit
  A2 prove autoload                     → commit
  A3..An migrate core classes w/ alias  → commit each
  C1 TenantContext / QueryBuilder / Repo / Money (+unit tests) → commit
  B1 Migration base + runner + ledger   → commit
  B2 core schema → migrations (baseline stamp) → commit
  C2 repository proof-of-concept + parity test → commit
  ▶ smoke green throughout ▶ review ▶ merge to main
```

Work on the `phase1-kernel-substrate` branch, never directly on `main`. Merge only
when the smoke suite is green and the changes are reviewed.

---

## Definition of done (Phase 1)

- [x] PSR-4 autoloader active; `src/` established; new code namespaced.
- [x] Core `includes/` classes moved to `Slate\…` with global alias shims (11
      classes); plugins still boot unchanged (`class_exists` satisfied — verified
      6/6 active plugins boot, cross-plugin APIs resolve).
- [x] Migration framework + ledger + `bin/migrate`; core schema expressed as
      `0001_core_init`; this install baseline-stamped (no re-run).
- [x] `TenantContext`, `Repository`, `QueryBuilder`, `Money` exist with unit tests;
      one core read (`SettingsRepository`) proven through a repository at parity
      with `Database::setting()`.
- [x] `ensureSchema()` and all plugins untouched and working.
- [x] Test suite green (74 unit / 4 integration / 21 smoke); grown with a
      dependency-free unit tier (Money + tenancy + data-layer) and an integration
      tier (repository parity). `smoke.php` gained the autoload assertions; Money
      and tenancy assertions live in the new unit/integration tiers.
- [x] No secrets committed; `.env` still untracked.

**Known follow-ups (tracked as tech debt, not blockers):** `crossTenant()` audit
sink is inert until the Phase-3 container wires it to `AuditLog`; a true empty-DB
fresh-install migration run is unverified here (shared host denies `CREATE
DATABASE`) though `db/schema.sql` is fully idempotent; MySQL DDL is
non-transactional in the runner.

---

## Guardrails (how we keep it safe)

- **Branch + small commits + smoke after each.** Any red → stop, fix or revert.
- **Alias every moved class** so no global reference or plugin `class_exists`
  breaks.
- **Additive only** — the old path stays until the new one is proven at parity.
- **Grow the smoke suite** with each capability so regressions are caught.
- **Quota watch** — `.git` is ~5MB; keep an eye per the host's EDQUOT history.

---

## Suggested first step

**A1 — add the PSR-4 autoloader + `src/`.** It's the smallest, safest, purely
additive change (an autoloader loads classes that don't exist yet; nothing current
is altered), and it unblocks every subsequent step. One commit, smoke stays green.

---

## Related

- [refactor-roadmap.md](refactor-roadmap.md) · [implementation-roadmap.md](implementation-roadmap.md)
- [../01-Architecture/kernel.md](../01-Architecture/kernel.md) · [../11-Database/migrations.md](../11-Database/migrations.md) · [../11-Database/repository-service-pattern.md](../11-Database/repository-service-pattern.md) · [../01-Architecture/multi-tenancy.md](../01-Architecture/multi-tenancy.md)
