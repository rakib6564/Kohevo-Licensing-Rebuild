# Phase 13 — Existing Solaya / Legacy Handling

**Status:** Implemented — awaiting Antigravity verification
**Scope rule:** new licensing = new installations. No in-place migration, no fabricated licenses or Installation IDs, no data rewrite, no destructive migration. Legacy code stays, but it cannot grant anything.

`planning.md`'s Phase 13 bullets ("migrate plans / licenses / entitlements") predate locked decision **D12** ("new installations only; no data migration", `15-PHASE-1-DECISIONS.md`) and the Phase 13 brief. D12 governs.

---

## 1. Legacy inventory

### 1.1 Client (`01-client`)

| Surface | Kind | Auth | Guard | Writes | Classification | Phase 13 |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `EntitlementService` legacy authority (`LICENSE_COMPAT_MODE=legacy` + future `LICENSE_COMPAT_UNTIL`) | service | — | n/a | — | **MUST BE BLOCKED** | Grants nothing (§3.1) |
| `licenses`, `platform_plans`, `plan_entitlements`, `tenant_profiles.plan_id` (migrations 0015/0016/0017/0021) | tables | — | — | — | Retained, inert | Untouched. Not in the installer's migration list; created only by `bin/migrate` on upgrade |
| `admin/licenses.php`, `admin/plans.php` | admin pages | `requirePlatformAdmin` + CSRF | yes (not whitelisted) | legacy tables only | SAFE / INTERNAL (inert data) | Unchanged; the data no longer grants |
| `admin/tenants.php`, `admin/platform-admins.php`, `admin/exit-tenant.php` | admin pages | `requirePlatformAdmin` (+ CSRF) | yes | tenants, `platform_admins`, session tenant override | SAFE / INTERNAL (not licensing) | Unchanged |
| `admin/index.php` "Your plan" card (legacy mode) | dashboard | admin | yes | `licenses.last_validated_at` (side effect) | **MUST BE BLOCKED** (presented local data as this install's license) | Removed; the signed-state summary is shown in every mode |
| `admin/index.php` platform overview (tenant / license / plan counts) | dashboard | `isPlatformSuperAdmin()` (true for the installer's `role_id = 1` admin) | yes | — | **MUST BE BLOCKED** (legacy counts; fatal on a fresh install) | Legacy license/plan counts removed; missing `platform_admins` tolerated (§3.1) |
| `slate_license_gate()` (`includes/error_page.php`, called by `index.php`, `public.php`) | public gate | — | runs *after* the Guard | — | SAFE (restrict-only) | Unchanged |
| `daily_cron` → `Slate\Services\Licensing\LicenseService::sweepExpired()` (`config.php`) | cron | `CRON_SECRET` | cron.php whitelisted | `licenses.status` only | SAFE (inert table) | Unchanged |
| `cron.php` | cron | `CRON_SECRET`, rate limit | whitelisted | module listeners gated by `ModuleGuard::allows()` | MUST REMAIN | Unchanged; legacy mode can no longer open a module listener (§3.1) |
| `admin/license.php`, `bin/license-check.php`, `install.php` | recovery / check-in / installer | `settings.edit` + CSRF / CLI / self-protecting | whitelisted / CLI / whitelisted | signed cache, `.env LICENSE_KEY` | MUST REMAIN AVAILABLE FOR RECOVERY | Unchanged |
| `api/v1.php` (booking, mcp), MCP tools | API | bearer / scopes | yes | module data | SAFE | Unchanged; module tools gated by `ModuleGuard::allows()` |
| `remote_license_cache` rows without a signed envelope (pre-0026 shape) | cache | — | — | — | Never trusted | Unchanged (`readTrustState()` → `unsigned`) |

There is no other local or unsigned license cache: no license settings keys, no files in `data/`. `entitlement.<key>.enabled` can only switch a feature off.

### 1.2 Central (`02-licensing`)

| Surface | Kind | Auth | Writes | Classification | Phase 13 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `POST /licensing/check` → `handleLegacyCheckIn()` (keys found only in `licensing_installs`) | public API | license key + binding | bindings, check-ins | SAFE / INTERNAL COMPATIBILITY | Unchanged. Signed and installation-bound; only `trial`/`active` get a payload |
| `admin/installs.php` "Licenses (Legacy)" | admin | `licensing.manage` + CSRF | `licensing_installs` | issue: **MUST BE BLOCKED**; status: restrict-only | Issue refused and form removed; only `expired`/`suspended`/`revoked`/`cancelled` |
| `admin/install.php` | admin | same | key, bindings, expiry, limit, delete | regenerate/reset: **MUST BE BLOCKED**; update: restrict-only | Refused / earlier expiry and lower limit only; delete unchanged |
| `admin/plans.php` legacy `entitlements_json` | admin | same | `licensing_plans` | restrict-only | Keys can be removed, not added; new plans start with none |
| `LicensingAPI::issueInstall()`, `regenerateLicenseKey()`, `resetBindings()` | service | — | legacy tables | NO LONGER USED by any screen | Kept for tests/fixtures |
| `licensing_installs`, `licensing_installation_bindings`, `licensing_checkins`, `licensing_plans.entitlements_json` | tables | — | — | Retained | Untouched |
| `daily_cron` → `LicenseService::sweepExpired()` | cron | — | `licensing_licenses` only | SAFE | Unchanged (no legacy sweep; legacy expiry is lazy at check-in) |
| `uninstall.sql` | plugin uninstall | plugin deactivated first | drops all `licensing_*` tables | Pre-existing | Unchanged |

`LICENSE_COMPAT_MODE` does not exist on the central server.

---

## 2. What existing installations do

| Case | Behaviour | Evidence |
| :--- | :--- | :--- |
| A. Existing install with legacy tables/data | The data is kept and ignored for access. | `Phase13LegacyHandlingTest`, E2E |
| B. No new installation identity | Guard locked (`missing`/`installation_mismatch`). No identity is created by any read path. The License page refuses ("not configured"). | same |
| C. No remote cache (table or row) | Guard locked (a missing table throws inside the Guard → fail closed). `bin/migrate` builds the signed cache table (0022, 0024–0026) empty. | E2E "old install" |
| D. Legacy licensing data | Grants nothing, in any mode. | same |
| E. `LICENSE_COMPAT_MODE=legacy` | Diagnostic label only. Remote mode wins when configured; otherwise no module or capability is granted. | same |
| F–H. Old licensing route / admin page / API / AJAX | Behind the Global Guard like every other entry point. Legacy client pages are reachable only when licensed, and only by platform/super admins. | same |
| I. Old cron job | `cron.php` stays reachable (own secret). Core listeners run. Module listeners run only when `ModuleGuard::allows()`. The legacy sweep touches only the inert `licenses` table. | same |
| J. Legacy module path | Same `ModuleGuard` call sites; no alias or legacy path skips them. | same |

The only way an existing installation operates under the new model is an explicit activation with a key issued by the Central Server. Nothing does this automatically.

---

## 3. Changes

### 3.1 Client

| File | Change |
| :--- | :--- |
| `src/Services/Licensing/EntitlementService.php` | `licensedForFeature()` requires remote mode. `legacyAllows()` (local license status incl. `none` + local plan) removed; `enabledFeaturesFor()` returns `[]` outside remote mode. `authorityMode()` unchanged. |
| `admin/index.php` | Legacy-mode "Your plan" card removed; the signed-state summary shows in every mode. Platform overview no longer counts the legacy `licenses` / `platform_plans` tables, and a missing `platform_admins` table counts as 0. On a fresh install (none of those tables exist) the installer's own admin got a dashboard that fataled halfway: HTTP 200 with a truncated body, 72 fatals per E2E run. Phase 12's check looked only at the status code. |
| `src/Services/Licensing/LicenseStatusPresenter.php` | The "optional modules cannot be enabled" notice also shows in legacy mode. |

**Why:** with `LICENSE_COMPAT_MODE=legacy` and any one of the four remote values missing, the old code granted modules and white_label from the local tables, even when the signed license granted none of them or was suspended. Reproduced against the pre-Phase-13 code:

- real HTTP: membership, which only the legacy plan granted, returned 200 for admin, AJAX, POST, public route and API;
- integration: a suspended installation's module cron listeners returned `ALLOW`.

### 3.2 Central

| File | Change |
| :--- | :--- |
| `plugins/licensing/LegacyLicensePolicy.php` (new) | Pure restrict-only rules: statuses, expiry, activation limit, legacy entitlements. |
| `admin/installs.php` | `issue` refused (nothing written) and its form removed. `set_status` to `trial`/`active` refused and audited (`licensing.install_status_refused`). |
| `admin/install.php` | `regenerate` / `reset_bindings` refused and audited, buttons removed. `update` keeps the label, and only shortens expiry or lowers the activation limit. |
| `admin/plans.php` | Legacy `entitlements_json` may only shrink; a new plan gets `[]`. The module template is unchanged. |
| `Licensing.php`, `LicensingAPI.php`, `InstallationService.php` | Load the policy; stale docblocks corrected. |

**Why:** the legacy screens could issue new legacy keys (signed by the same central key and accepted by any client, including a new installer), and reactivate or extend them. That is a second commercial authority outside Plan → License → Installation → Entitlements.

### 3.3 Not changed

- No migration. No schema, data, identity or cache rewrite.
- `02-licensing/src/Services/Licensing/EntitlementService.php`, the central server's own copy of the client framework, is unchanged. The central server has no license guard, so the copy is not a client security boundary.
- The legacy client admin pages and nav stay as they are (R12 still open). Details in §6.

---

## 4. Tests

| Test | Scope |
| :--- | :--- |
| `01-client/tests/integration/Phase13LegacyHandlingTest.php` (9) | Compat mode legacy / case / missing / malformed / lapsed / bad date. Modern + legacy flag. State matrix: active, trial, suspended, revoked, expired within grace, beyond grace, stale, missing, invalid signature, tampered, unsigned legacy row × {legacy only, modern}. Old install with no identity. Unsigned and older check-ins refused. Tenant A → A / A → B. Child-process entry points with live guards: admin GET (platform admin), admin POST, public, API, MCP, cron gate, for every module, in both modes, plus a positive control. Locked matrix: dashboard, legacy pages, legacy POST, API, public, MCP, cron; License page reachable. Dashboard presentation. |
| `02-licensing/tests/integration/Phase13LegacyAdminTest.php` (7) | Policy (pure). Existing legacy license still checks in; nothing migrated. Issue refused. Reactivation refused while suspend/revoke apply and reach check-in. Re-key / reset refused. Expiry / limit restrict-only and reflected in the signed payload. Legacy entitlements shrink-only. |
| `01-client/tests/e2e/phase13/scenario_legacy.php` (68, real HTTP, run by `phase12/run.sh`) | Fresh-install super-admin dashboard renders completely. Upgrade migration (legacy tables added, identity and signed cache unchanged, replay no-op). Legacy mode on a licensed install. Suspended lock matrix under legacy mode, including AJAX and a secret-authenticated `cron.php`. Pre-rebuild install (identity row, no cache table, no key) locked, installer refuses, License page rejects, nothing fabricated, data intact; `bin/migrate` alone does not license it. Central legacy screens over HTTP. |
| `01-client/tests/run-phase13-legacy.php` | Phase 13 plus the Phase 3/6/7/8 suites it touches. In CI with the central test. |

**Changed expectations** (behaviour intentionally removed):

- `Phase3EntitlementAuthorityTest`: in legacy mode `white_label` is now denied, not granted.
- `run-phase3-relevant.php` no longer includes `EntitlementServiceTest.php` and `PlatformIdentityWhiteLabelTest.php`. Their grant assertions describe the removed legacy authority. They still run, and still fail, in `tests/integration/run.php`, where they have been listed as pre-existing failures since Phase 3.

**Mutation check.** Against the pre-Phase-13 code:

- client test: 7/9 fail;
- central test: 5/7 fail;
- E2E scenario: 9/67 fail.

The tests that still pass lock in behaviour that was already correct.

---

## 5. Regression

Local run, MySQL 26.7 (Homebrew), PHP 8.5.9. MariaDB was **not** run locally. The CI result on MySQL 8.0 and MariaDB 10.11 is in §5.1.

| Suite | Phase 12 baseline (re-measured before any change) | After Phase 13 |
| :--- | :--- | :--- |
| client unit | 564 / 569 | 564 / 569 (same 5) |
| client integration | 495 / 514 | 502 / 523 (+9 Phase 13 tests; same 19 pre-existing, plus the 2 `booking_can_book` time-of-day failures below) |
| client smoke | 21 / 21 | 21 / 21 |
| Phase 3 authority / Phase 3 relevant | 18 / 18, 49 / 49 | 18 / 18, 28 / 28 (two files moved out, §4) |
| Phase 4 / 9 / 10 | 31, 126, 144 (all pass) | same |
| Phase 11 | 172 / 172 | 170 / 172 (the 2 `booking_can_book` failures below) |
| **Phase 13 runner** | — | 92 / 92 |
| FreshInstallMigration / GlobalLicenseGuard / ModuleGuard / McpModuleGuard / Phase9 / CommercialLicenseWindow / RemoteLicenseClientSync | pass | 13, 17, 18, 5, 29, 42, 3: all pass |
| central unit | 503 / 523 | 503 / 523 (same 20) |
| central smoke / Phase 2 / race | 21, 22, 1 binding | same |
| central schema+foundation / cache store / QA2 / Phase 10 / check-in / RLC / signature | pass | 27, 7, 4, 9, 22, 14, 7: all pass |
| **central Phase13LegacyAdminTest** | — | 7 / 7 |
| E2E (`phase12/run.sh`) | 50, 139, 22, 8, 15, 10 | same, plus Phase 13 68 / 68 |
| `php -l` every PHP file | clean | clean |

**`booking_can_book` (2 tests, `BookingCanBookGateTest`) — pre-existing time-of-day failure, not Phase 13.** The test books a 30-minute slot at *now + 3 days* against 00:00–23:59 working hours in local time. Between 23:30 and 24:00 local (+06 here) the slot ends after 23:59. It passed in the baseline (run at 23:12) and failed at 23:35–23:44. It fails identically on untouched `HEAD` in a clean worktree at 23:44. Phase 13 touches no booking code.

---

### 5.1 CI — MySQL 8.0 and MariaDB 10.11

Commit `cfdcb47` (Phase 13), GitHub Actions run **36260339736**, PHP 8.3. Both jobs **success**:
- Licensing & Client Suites (MariaDB 10.11), job 108454821753
- Licensing & Client Suites (MySQL 8.0), job 108454821812

MariaDB was not run locally; this CI run is the MariaDB evidence. The two databases gave identical counts:

| Suite | MySQL 8.0 | MariaDB 10.11 |
| :--- | :--- | :--- |
| **`run-phase13-legacy.php`** (new) | 92 / 92 | 92 / 92 |
| **central `Phase13LegacyAdminTest`** (new) | 7 / 7 | 7 / 7 |
| `run-phase3-relevant.php` (two files moved out, §4) | 28 / 28 | 28 / 28 |
| client smoke / Phase 3 authority / Phase 4 | 21, 18, 31 | 21, 18, 31 |
| FreshInstallMigration / GlobalLicenseGuard / ModuleGuard / McpModuleGuard | 13, 17, 18, 5 | 13, 17, 18, 5 |
| CommercialLicenseWindow / Phase 9 / RemoteLicenseClientSync | 42, 29, 3 | 42, 29, 3 |
| Phase 10 / Phase 11 runners | 144 / 144, 172 / 172 | 144 / 144, 172 / 172 |
| central smoke / RemoteLicenseClient / Phase 2 / schema / schema+foundation | 21, 14, 22, 5, 27 | 21, 14, 22, 5, 27 |
| central cache store / QA round 2 / Phase 10 check-in | 7, 4, 9 | 7, 4, 9 |
| central race | activation_count=1, bindings=1 | activation_count=1, bindings=1 |

The Phase 11 runner is 172/172 in CI. CI ran at 17:49 UTC, outside the local 23:30–24:00 window that trips `booking_can_book` (§5), which is consistent with that failure being time-of-day only.

Not in CI (unchanged from Phase 12): the full client unit/integration runs, the central unit run, and the E2E harness (it needs two HTTP servers). Those were run locally (§5).

## 6. Remaining risks (verified)

| # | Risk | Evidence | Why not changed |
| :--- | :--- | :--- | :--- |
| R1 | Legacy central licenses that already exist (`licensing_installs` rows) still check in, signed and installation-bound, and a new client (installer included) accepts them. Their lifecycle is the legacy one: suspended/revoked/expired get an unsigned 403, so a client keeps its last signed state for up to the 7-day offline tolerance instead of locking at the next check-in. | `LicensingAPI::handleLegacyCheckIn()`; `Phase13LegacyAdminTest`, E2E | Removing the fallback changes Phase 2/4 behaviour that CI tests (`run-phase2-race.php`, `LicensingCheckInTest`) and depends on open items R7/R16. New legacy keys can no longer be issued, re-keyed, reactivated or extended, and under D12 no real central data exists. |
| R2 | The legacy client pages `admin/licenses.php`, `plans.php`, `tenants.php`, `platform-admins.php` are reachable, while licensed, by the installation's own `role_id = 1` admin: `isPlatformSuperAdmin()` equals `isSuperAdmin()`. This contradicts `13-MIGRATION-STRATEGY.md` §2's original assumption and the nav comment in `admin/partials/header.php`. On a fresh install `licenses.php` / `plans.php` error, because their tables are absent. | inventory; E2E | Their data is inert (grants nothing). Hiding or blocking them is open item R12, and blocking would break `LicenseServiceTest` / `PlanServiceTest`, which post to them. |
| R3 | An installation that predates the rebuild stays locked until someone explicitly activates it with a central-issued key. There is no migration path and no messaging for it beyond the lock page. | E2E "old install" | Required by D12 and the Phase 13 brief (no automatic migration). |
| R4 | `uninstall.sql` on the central server drops every `licensing_*` table, legacy included. | `plugins/licensing/uninstall.sql` | Pre-existing plugin uninstall. It needs the plugin deactivated first and is not a migration. |
