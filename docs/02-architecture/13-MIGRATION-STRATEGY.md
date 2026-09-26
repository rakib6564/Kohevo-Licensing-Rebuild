# Phase 1: 13 — Migration Strategy

**Document Status:** Architecture Specification (Documentation Only — No Migrations, No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Reference:** Locked project decision (this Phase's brief): "There is NO real/live customer licensing data that must be preserved" and "The new licensing architecture is ONLY for NEW Solaya installations," `docs/01-audit/08-LICENSING-MIGRATION-RISKS.md`

---

## 1. Scope Statement

This rebuild targets **new installations only**. Existing/already-deployed Solaya installations are explicitly **not** migrated to the new licensing architecture as part of this project. This is a locked project decision provided directly for this phase, not an inference — restated here so every other document in this set can rely on it without re-deriving it.

This scoping is corroborated, not merely asserted, by Phase 0 evidence: the one available production database dump (`u263467780_kohevo_client.sql`) has never even reached a state where the current (pre-rebuild) remote-licensing tables exist (`02-LICENSING-DATABASE-AUDIT.md` §2 — migrations `0015`–`0022`, `0024` were never applied). There is, in the one verified real-world sample, no live remote-licensing data of any kind to migrate even if migration were in scope.

---

## 2. What Happens to Legacy Licensing Code

Per this Phase's explicit constraint ("Do NOT delete or rename existing licensing code") and `DECISIONS.md` §18 ("no unnecessary rebuild of unrelated systems"), this document does not direct any deletion. It specifies the **target disposition** for a future implementation phase:

| Component | Disposition | Rationale |
| :--- | :--- | :--- |
| `01-client/src/Services/Licensing/LicenseService.php`, `PlanService.php` | **Isolated, not wired into new installs.** Remains in the codebase, callable, untouched. New installer (`05`) never invokes it. | Removing it outright is a larger, riskier change than simply not using it, and nothing in the locked requirements demands deletion — only that it not be the commercial authority for new installs (`DECISIONS.md` §2, already satisfied by not wiring it in) |
| `01-client/admin/tenants.php`, `plans.php`, `licenses.php`, `platform-admins.php` | **Deprecated for new installs.** These pages are gated by `Auth::requirePlatformAdmin()` (`EXISTING`), a role that a fresh target-architecture installation's installer should simply never grant to anyone (since the installer no longer creates a multi-tenant SaaS context at all) — `RECOMMENDED`: these pages become unreachable in practice on a new install without any code change, purely because no user ever holds `platform_admins` membership on such an install. `REQUIRES VERIFICATION`: whether these files should be actively hidden from the admin nav (`admin_nav_items` hook) on a new-architecture install, or left exactly as-is and rely on the RBAC gate alone — this document recommends the latter as sufficient for V1 given `REQUIREMENTS.md` §11's requirement is about what the client *exposes*, and an unreachable page gated by a role nobody holds is not "exposed" in any meaningful sense, though a defense-in-depth nav-hiding pass is a reasonable low-cost addition for a later phase. |
| `01-client/db/migrations/0015`–`0017`, `0021`, `0022` (`platform_plans`, `plan_entitlements`, `licenses`, `remote_license_cache`'s *original* shape) | **Remain in migration history, unchanged.** New installer's migration list (`05` §1 Step 2) simply does not include `0015`–`0017`, `0021` for new installs. `0022`/`0024` (`remote_license_cache`) **are** still needed (hardened per `10` §3), just evolved rather than replaced. | Editing already-shipped migration files is itself a backward-compatibility risk for any environment that already ran them (even in dev/staging) — a new migration (numbered after `0024`, see `09` §13) adding the hardening columns is the correct approach, not editing history |
| `02-licensing/plugins/licensing/admin/installs.php`, `plans.php` (old Central admin UI, reads/writes `licensing_installs`/`licensing_plans.entitlements_json`) | **Deprecated, superseded by a future admin UI built against the new schema (`09`).** Not deleted this phase; a future implementation phase (Phase 3 per `planning.md`) builds the replacement screens. | Same reasoning — UI rebuild is implementation work, explicitly out of scope for Phase 1 |
| `02-licensing`'s `licensing_installs`, `licensing_installation_bindings` tables | **Remain, unchanged, alongside the new tables.** No data migration between old and new Central schema is performed, since (per §1) there is no live data requiring preservation. | Directly follows from §1's scope statement |

---

## 3. Clean-Install Expectations

A fresh installation under the target architecture:

- **Client:** runs only the Core migration set (`05` §1) — never provisions `licenses`, `platform_plans`, `plan_entitlements` at all. Provisions the hardened `remote_license_cache` (§2 of `10`) via its new migration.
- **Central Server:** a fresh `02-licensing` deployment provisions **both** the legacy tables (`licensing_installs` etc., via `install.sql`'s existing `CREATE TABLE IF NOT EXISTS`, which cannot be selectively skipped without editing that file — `REQUIRES VERIFICATION`/`RECOMMENDED` for implementation: consider whether `install.sql` should be split so a *fresh* Central Server deployment provisions only the new schema, while an *upgrading* existing Central Server deployment keeps the legacy tables present for continuity) **and** the new schema (`09`). Since `REQUIREMENTS.md` explicitly scopes this rebuild to new *client* installations, and does not state whether the Central Server itself is being freshly stood up or is an existing deployment being upgraded, this document treats both as possible and does not assume the Central Server side gets a "clean slate" the way client installs do.

**`REQUIRES VERIFICATION`:** whether the Central Server instance this project will actually use is itself a fresh deployment or an upgrade of the currently-audited one. This materially affects whether `licensing_installs`/`licensing_installation_bindings` ever need to coexist with real (even if test) data in the new tables. Not resolved by this document; carried to `15-PHASE-1-DECISIONS.md`.

---

## 4. Avoiding Unnecessary Compatibility Complexity

Because there is no live data to preserve (§1) and no existing consumer of the new schema to keep in sync with the old one (nothing outside this rebuild reads `licensing_licenses`/`licensing_installations` yet, since they don't exist yet), this document explicitly recommends **against** building any dual-write, dual-read, or data-sync mechanism between the old and new Central schemas. Such a mechanism would only be justified if live traffic needed to keep working against both schemas simultaneously during a transition window — which is not the case here. `RECOMMENDED`, directly following `DECISIONS.md` §18's "no unnecessary rebuild" and this Phase's own instruction to "avoid unnecessary compatibility complexity."

---

## 5. Summary

| Question | Answer |
| :--- | :--- |
| Are existing production Solaya installations migrated? | **No** — explicitly out of scope (locked decision) |
| Is legacy client licensing code removed? | **No**, not this phase — isolated/deprecated, disposition documented in §2 for a future phase to execute |
| Is legacy Central Server schema removed? | **No**, not this phase — coexists alongside new schema, no data migration between them |
| Does a fresh client install provision legacy licensing tables? | **No** — new installer's migration list excludes them (§3) |
| Is dual-write/sync between old and new Central schema required? | **No** — explicitly recommended against (§4) |

---

## 6. Phase 13 Implementation Status

Phase 13 applied this document without a data migration. Detail, inventory and tests: `docs/03-implementation/PHASE-13-LEGACY-HANDLING.md`.

| Question | Phase 13 outcome |
| :--- | :--- |
| Are existing installations migrated, re-keyed, or given an Installation ID? | **No.** Nothing is created or rewritten automatically. An existing installation that receives this code is locked by the Global License Guard until it holds signed, installation-bound state. That state comes only from an explicit activation with a central-issued key: the installer for new installations, or the License page for an installation that already has an identity. |
| Is legacy client licensing data used for access? | **No.** `licenses`, `platform_plans`, `plan_entitlements` and `tenant_profiles.plan_id` stay in the database, untouched, and are never read for an access decision. `LICENSE_COMPAT_MODE=legacy` still exists as a diagnostic label but grants nothing. |
| Legacy client admin pages (`admin/licenses.php`, `admin/plans.php`, `admin/tenants.php`, `admin/platform-admins.php`) | Unchanged: still behind the Global Guard, `requirePlatformAdmin()` and CSRF. Their data no longer grants anything. **Correction to §2:** these pages are *not* unreachable on a new install. `Auth::isPlatformSuperAdmin()` equals `isSuperAdmin()`, which is true for `role_id = 1`, and the installer's first admin has that role. R12 (nav hiding) stays open. |
| Legacy Central tables and the legacy check-in path | Kept, unchanged. Existing `licensing_installs` keys still check in (signed, binding-checked). The legacy **admin** is restrict-only: it can no longer issue, reactivate, re-key, reset bindings, extend expiry, raise activation limits, or add legacy plan entitlements (`LegacyLicensePolicy`). |
| Migrations | None added. History untouched. Legacy tables are created only by `bin/migrate` on an upgrade, never by the installer. |
