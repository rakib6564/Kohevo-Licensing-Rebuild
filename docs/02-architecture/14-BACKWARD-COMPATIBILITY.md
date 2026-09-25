# Phase 1: 14 — Backward Compatibility

**Document Status:** Architecture Specification (Documentation Only — No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Reference:** `REQUIREMENTS.md` §15, `DECISIONS.md` §15, §18, `docs/01-audit/08-LICENSING-MIGRATION-RISKS.md`

> This document states compatibility requirements grounded directly in verified Phase 0 findings. It does not invent requirements — per `DECISIONS.md` §15's governing principle, every constraint below traces to something Phase 0 actually observed in the source or database.

---

## 1. Existing Application Internals

| Internal | Requirement | Evidence |
| :--- | :--- | :--- |
| PSR-4 autoloader (`src/autoload.php`) + legacy aliases (`src/compat/aliases.php`) | Unchanged. All new licensing classes (`ModuleGuard`, hardened cache reader, etc.) follow the existing `Slate\` namespace convention already used by `Slate\Services\Licensing\*`. | `01-LICENSING-CURRENT-STATE.md` §2.2, verified directly against `config.php` |
| Hook system (`Hook::addAction`/`doAction`, `Hook::addFilter`/`applyFilters`) | Unchanged. Module Guards integrate via existing hook points (`admin_nav_items`, `public_routes`, `api_v1_routes`, `frequent_cron`) rather than a new event system. | `01-LICENSING-CURRENT-STATE.md` §2.2 |
| Plugin lifecycle (`Plugin`, `PluginLoader`) | Unchanged. `PluginLoader::isActive($slug)` remains the existing plugin-active check; `EntitlementService::canAccess()` already correctly layers on top of it (`!PluginLoader::isActive($featureKey)) return false` before consulting license state) — this two-layer check (plugin active AND entitled) is preserved as-is, not replaced. | `EntitlementService.php`, verified directly |
| `Database` facade (`row()`, `rows()`, `value()`, `insert()`, `update()`, `delete()`) | Unchanged. All new tables/queries use the same static facade convention as every existing table. | `01-LICENSING-CURRENT-STATE.md` §2.2 |
| Migration runner | Unchanged mechanism; new migrations are additive, numbered after the existing sequence's highest number in each respective codebase (`0024` client-side, `0024` central-side — independent sequences, `09-CENTRAL-DATABASE-DESIGN.md` §13) | Verified directly against both `db/migrations/` directories |

## 2. `tenant_id` Dependencies

**Requirement:** No change to `tenant_id`'s presence, meaning, or behavior anywhere outside the licensing/installation-identity subsystem. Per `08-LICENSING-MIGRATION-RISKS.md` §2.2, `tenant_id` is load-bearing across the base `Repository` layer and every core/plugin table (`users`, `customers`, `roles`, `contacts`, `settings`, and all of Forms/Membership/Booking's own tables). This architecture touches **only**:

- `installation_identity.tenant_id` — column retained, only its *licensing relevance* changes (`10-CLIENT-LICENSING-DATABASE-DESIGN.md` §2)
- `remote_license_cache.tenant_id` — same treatment (`10` §3)
- `EntitlementService`'s `tenantId` parameter — remains present in its method signatures (`canAccess(int $tenantId, ...)`) since removing it would require touching every one of its call sites; `ASSUMPTION`/`RECOMMENDED`: new Module Guard callers simply pass the resolved `current_tenant_id()` through unchanged, exactly as `EntitlementService`'s existing (if currently unused-for-enforcement) callers already do — no signature change to this method is required by this architecture

Everything else that reads or writes `tenant_id` — RBAC, contacts, settings, every plugin's own data — is explicitly out of scope and must not be touched, per `DECISIONS.md` §18.

## 3. Existing Modules

Forms, Membership, and Booking's **internal business logic and data tables are unchanged**. Verified in Phase 0 (`05-LICENSING-MODULE-AUDIT.md` §3): each module's admin controllers, public routes, customer portal integration, and database tables (`membership_plans`, `membership_subscriptions`, `membership_profiles`, etc.) are fully independent of licensing today and remain so — this architecture adds a `ModuleGuard::require()` call at each module's entry points (`07-MODULE-GUARD-ARCHITECTURE.md` §2) and nothing else. No route is renamed, no table is altered, no existing permission (`forms.view`, `booking.manage`, etc.) is changed in meaning.

## 4. Existing Authentication

`Auth` (`01-client/src/Services/Auth/Auth.php`) is **unchanged**: `Auth::require()`, `Auth::check()`, `Auth::can()`, `Auth::requirePerm()`, `Auth::requirePlatformAdmin()`, session handling, MFA — none of this is modified. The Global License Guard and Module Guards are **additional, independent layers**, not replacements or modifications to Auth (`01-TARGET-ARCHITECTURE.md` §4's three-box flow explicitly keeps Auth/RBAC as its own unchanged middle box). This directly satisfies `REQUIREMENTS.md` §15's instruction to protect existing working functionality.

## 5. Existing Admin Structure

The direct-PHP-script convention (`admin/*.php`, each beginning with `Auth::require()`) is preserved. The Guard is added either as a single `config.php`-level call (`06` §3, Option A — `RECOMMENDED`) that every existing script automatically inherits with zero per-file changes, or, if Option A is not adopted, as an additional single line per admin script mirroring the existing `Auth::require()` idiom. Either way, no existing admin script's structure, arguments, or output format changes.

## 6. Existing Database Conventions

| Convention | Preserved As |
| :--- | :--- |
| No foreign keys on plugin tables | **Open question**, not a blanket "preserved" — `09-CENTRAL-DATABASE-DESIGN.md` §12 explicitly flags this as `REQUIRES VERIFICATION` for the new Central tables given their higher commercial-integrity stakes, rather than silently preserving or silently breaking the convention |
| `ENGINE=InnoDB`, `utf8mb4_unicode_ci` | Preserved, all new tables |
| `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` (+ `ON UPDATE` where mutable) | Preserved, all new tables |
| Secrets hashed (license keys) or encrypted at rest (`slate_encrypt_secret()`) | Preserved, unchanged mechanism |
| `Database::insert/update/row/rows/value` static facade, no ORM | Preserved |
| `CREATE TABLE IF NOT EXISTS` self-healing schema application (`ensureSchema()`) | Preserved, `EXISTING` pattern used for new tables too |

## 7. What This Document Does Not Cover

Per `DECISIONS.md` §15's "no assumption... treated as fact until verified," this document does not assert compatibility guarantees for anything Phase 0 did not actually inspect. Notably out of scope: Content/Editor internals (explicitly excluded from V1 licensing per `DECISIONS.md` §6, and not separately re-audited here), Stripe payment integration internals beyond the one webhook-guard note in `07-MODULE-GUARD-ARCHITECTURE.md` §2, and any environment/hosting-level configuration not already surfaced by the Phase 0 audits.
