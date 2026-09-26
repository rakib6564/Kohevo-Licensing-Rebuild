# PHASE 12 — FULL TESTING & PRODUCTION READINESS REPORT

## Repository Identity

| | |
| :--- | :--- |
| Path | `/Users/rakibhasan/Downloads/Fahim/Kohevo-Licensing-Rebuild` (= `git rev-parse --show-toplevel`) |
| Branch | `main` |
| HEAD at start | `711b492cd6b76cac70c5c033c57044384cebf909` — `feat(licensing): implement Phase 11 security hardening` |
| `git status` at start | clean |
| Phase 12 changes | uncommitted working tree (listed under **New Defects** and **Tests added**) |

## Environment

| | |
| :--- | :--- |
| OS | macOS 26.6.2 (25G83), arm64 |
| PHP | 8.5.9 CLI (NTS) — sodium, pdo_mysql, curl |
| MySQL | 26.7.0 (Homebrew), 127.0.0.1 — **executed locally** |
| MariaDB | **not available locally — not executed locally.** CI (GitHub Actions) ran the licensing suites on **MariaDB 10.11** and **MySQL 8.0**, PHP 8.3, for HEAD `711b492` (run 36253064933: both jobs success). The Phase 12 fixes below have **not** yet run in CI. |
| Servers | `php -S` (built-in server) with each app's `dev-server.php` router; no Apache/nginx available |

---

## Fresh Installation — **PASS** (after D1/D3 fixes)

Evidence: `01-client/tests/e2e/phase12/scenario_install.php` — **50/50** over real HTTP against fresh `p12_client` / `p12_central` databases, a real central keypair from `bin/licensing-generate-keys.php`, and licenses issued through `LicenseService`.

- Step order DB config → install → identity → license → central validation → activation → admin → finish → dashboard verified; skip-ahead GETs are redirected, forged later-step POSTs create nothing.
- Installation ID: 32 lowercase hex, identical in DB and `.env` (D18), exactly one row, reused after the DB is dropped mid-install.
- Admin cannot be created before activation (forged step-4 POST at steps 2 and 3); replayed step-4 POST creates no second admin.
- `.installed` absent until Finish; written at Finish; installer then reports "already installed".
- Rejected at step 3 with no admin and no binding: invalid key, malformed key, suspended license (bound elsewhere), active license already bound to another installation, revoked license, central unreachable (distinct "could not reach" message).
- Valid key: central binds the license to this installation id; one signed installation-bound cache row; entitled plugins (forms, booking) activated at Finish, unentitled (membership) not; admin login and dashboard render.
- Unattended check-in (`bin/license-check.php`) succeeds right after install (**D3**).
- Expired / suspended / revoked cannot continue: see **Installer Recovery** (reinstall) and D1/D2.

## Installer Recovery — **PASS** (after D1 fix)

- DB lost after step 2 → back to step 2, same installation id (`scenario_install`).
- New browser session after activation resumes at step 4; resubmitted activation creates no second binding.
- Central unavailable during activation → stays on step 3, retry works.
- Bound installation reinstalling (DB wiped, `.env` kept) while its license is **suspended / revoked / expired beyond grace**: stays on step 3, no admin, cannot finish — `scenario_reinstall.php` **15/15** (was 6/15 before D1).
- Not simulated: a half-applied migration set (the installer's migration list is ledger-recorded and replay-safe; `FreshInstallMigrationTest` 13/13), a plugin failing to install at Finish (the `finish_anyway` path).

## License Lifecycle — **PASS**

`scenario_central_authz.php` **22/22** + `scenario_runtime.php`:
Unactivated → Active (activation), Suspend, Unsuspend, Expire (lazy), Renew, Extend, Revoke; revoked is terminal (unsuspend/renew/extend refused); event history intact (`create, activate, suspend, activate[unsuspend], expire, renew, extend, revoke`, actor and reason recorded). Unsuspend is recorded as `activate`, consistent with the 09 §5 event vocabulary (no `unsuspend` type). First activation of a license issued with a past expiry is now stored and signed `expired` (**D2**), renewable afterwards.

## Central ↔ Client Sync — **PASS**

`scenario_runtime.php` (real central → real `bin/license-check.php`): suspended, unsuspended, expiring soon, expired-in-grace, expired-beyond-grace, renewed, extended, revoked all reach the client as signed state; module grants/removals propagate. Central down → check-in fails and the last trusted state is kept (app stays usable within tolerance). Signature/payload/installation-id/stale/replay/concurrency cases: `Phase10SynchronizationTest` (client) and `Phase10CheckInSyncTest` (central), all passing; `cancelled` is covered unit-level only (`CommercialLicenseWindowTest`), not end-to-end.

## Global License Lock — **PASS**

Lock matrix (`scenario_runtime.php`, per locked state: suspended, expired beyond grace, each tampered column, revoked): admin dashboard, public site, direct plugin admin PHP, API (403 JSON `LICENSE_INACTIVE`), AJAX (403 JSON `license_inactive`), platform-admin page for the super admin — all locked; whitelisted License page and login reachable; `cron.php` answers its own secret check (not the lock page). Open states (unsuspended, renewed, extended, central-down-but-recent) unlocked. Plus `GlobalLicenseGuardTest`, `Phase9ExpiryGraceTest`, Phase 11 subdirectory tests (in the runners below).

## Expiry / Grace / Offline — **PASS**

Exact-second boundaries in `CommercialLicenseWindowTest` (42/42): 8d, 7d+1s, exactly 7d, 6d before; −1s, exact expiry, +1s; +6d 23:59:59 (allowed); exactly +7d and +7d+1s (locked). Offline: fresh, 6d, **exactly 7d (trusted)**, 7d+1s (stale) — the exactly-7d case was added in this phase. End to end: expiring-soon warning (3 days), grace warning (expired 2 days ago, usable), beyond grace locked, renewal unlocks.

## Optional Module Matrix — **PASS**

All 8 combinations (none, F, M, B, F+M, F+B, M+B, F+M+B) for admin, public, Booking API and cron: `ModuleGuardTest` (in the Phase 10/11 runners). MCP: `McpModuleGuardTest` (none, single-module, isolation, all). Booking payment / customer portal / Google Calendar / prereq / message / content blocks for all modules: `Phase11SecurityHardeningTest`. End to end with real sync: forms+booking entitled, membership not.

| Combination | Covered by |
| :--- | :--- |
| none | ModuleGuardTest, McpModuleGuardTest, Phase11 V2 |
| forms | ModuleGuardTest, Phase11 V1/V2/V3 |
| membership | ModuleGuardTest |
| booking | ModuleGuardTest (admin + API) |
| forms + membership | ModuleGuardTest |
| forms + booking | ModuleGuardTest; E2E install/runtime |
| membership + booking | ModuleGuardTest |
| forms + membership + booking | ModuleGuardTest, McpModuleGuardTest, Phase11 V2; E2E runtime |

Gaps (not failures): no Forms REST API surface exists; Membership customer portal and Stripe handlers are covered at the gate (`ModuleGuard::allows`) level, not by a live Stripe event.

## Module Downgrade / Upgrade — **PASS**

`scenario_runtime.php`: for **booking**, **forms** and **membership** — entitled and working → entitlement removed on central while the plugin stays installed and active → real check-in → every tested surface blocked (booking: admin 403, `/book` 404, pay-intent 404, API 403; forms: admin 403; membership: admin 403), other modules and Core unaffected → entitlement restored → surfaces work again.

## Security

| Area | Result | Evidence |
| :--- | :--- | :--- |
| Authentication | PASS | central anonymous → login redirect; client admin pages require login |
| Authorization | PASS | central `licensing.manage` enforced on every plugin page and POST (Manager role 403, POSTs no effect) |
| IDOR | PASS | another license's installation cannot be revoked via this license's page; unknown id 404 |
| CSRF | PASS | forged token refused on central lifecycle POST and client installer step 3 |
| Tenant isolation | PASS (inspection + existing tests; no two-tenant E2E) | client `current_tenant_id()` has no request/Host input; cache rows are per tenant **and** signed for one installation; `Phase4SurfaceSecurityTest`; central is single-tenant by design (not enforced — see readiness) |
| Cache integrity | PASS | each of status, plan, entitlements, expires_at, installation_id, raw_signature, raw_payload, fetched_at tampered at runtime → locked; real check-in restores |
| Installation binding | PASS | cross-installation rows untrusted (Phase 10/11 tests); 1 license → 1 installation under 12-way concurrency |
| Replay | PASS (bounded, as designed) | older payload refused; replay into empty cache bounded by 7-day tolerance (Phase 11 tests). Clock rollback remains a documented, deferred limitation (Phase 11 §4 A) |
| Path bypass / subdirectory | PASS | Phase 11 E tests; whitelisted pages exact-match only |
| Cron | PASS | secret check timing-safe; cron reachable while locked; module cron listeners gated |
| MCP | PASS | `McpModuleGuardTest`, Phase 11 V3 |
| Information disclosure | PASS for licensing paths | uniform `invalid_request`/`server_error`; no key/signature logging; 300 unknown-key requests write no rows or log lines. Pre-install only: client/central installer step 1 echoes the PDO connection error |

## Database

| | Result |
| :--- | :--- |
| Fresh migration | PASS — central `bin/migrate` on an empty DB (24 applied); client installer's curated core set on an empty DB |
| Upgrade migration | PASS — client `bin/migrate` applied the remaining product migrations on top of an installed DB; Phase 10 migration 0026 upgrade/replay test passing |
| Replay | PASS — second `bin/migrate migrate` on both: "Nothing to migrate" |
| Foreign keys | PASS — `licensing_installations → licenses` RESTRICT; `license_modules`, `license_events → licenses` CASCADE |
| Indexes / constraints | PASS — unique `license_key_hash`, `installation_id`, active-license-per-installation (generated column), `(license_id, module_key)`; client unique cache row per tenant, unique installation identity |
| Race conditions | PASS — 12 installations racing one license: exactly one binding and one `activate` event; `run-phase2-race.php` |

## Legacy Compatibility — **PASS** (no bypass found)

No legacy path unlocks the Global Guard (it always requires a signed, installation-bound, fresh cache row): `slate_license_gate()` runs after the Guard; legacy `licenses` / `tenant_profiles` / plan tables are read only in `LICENSE_COMPAT_MODE=legacy`; central legacy `licensing_installs` check-in signs `installation_id` after binding checks; legacy admin pages require `licensing.manage` + CSRF. Residual items are listed under readiness (all need `.env` control).

## CI — **PASS** (for HEAD `711b492`)

`.github/workflows/ci.yml`: MySQL 8.0 and MariaDB 10.11, PHP 8.3, lint; runs Phase 3/4 runners, FreshInstallMigration, GlobalLicenseGuard, ModuleGuard, MCP ModuleGuard, CommercialLicenseWindow, Phase 9, RemoteLicenseClientSync, Phase 10 runner, **Phase 11 runner**, and the central smoke / schema / foundation / cache / QA / Phase 10 / race suites. Run 36253064933: both jobs success. Not in CI: full client unit/integration runs, central unit run, the E2E harness (needs two HTTP servers). No CI change needed: the Phase 12 tests live in files CI already runs.

## Full Test Results (local, MySQL 26.7, after the Phase 12 fixes)

| Command | Result |
| :--- | :--- |
| `php 01-client/tests/unit/run.php` | 564 / 569 (5 pre-existing) |
| `php 01-client/tests/integration/run.php` | 495 / 514 (19 pre-existing) |
| `php 01-client/tests/smoke.php` | 21 / 21 |
| `php 01-client/tests/run-phase3-authority.php` | 18 / 18 |
| `php 01-client/tests/run-phase3-relevant.php` | 49 / 49 |
| `php 01-client/tests/run-phase4-focused.php` | 31 / 31 |
| `php 01-client/tests/run-phase9-licensing.php` | 126 / 126 |
| `php 01-client/tests/run-phase10-licensing.php` | 144 / 144 |
| `php 01-client/tests/run-phase11-security.php` | 172 / 172 |
| CommercialLicenseWindowTest (unit) | 42 / 42 |
| FreshInstallMigrationTest | 13 / 13 |
| `php 02-licensing/tests/unit/run.php` | 503 / 523 (20 pre-existing) |
| `php 02-licensing/tests/integration/run.php` | aborts (pre-existing: `BookingAdminTenantLinkageTest`, no booking tables) |
| `php 02-licensing/tests/smoke.php` | 21 / 21 |
| `php 02-licensing/tests/run-phase2-licensing.php` | 22 / 22 |
| LicensingPluginSchemaTest + CentralLicensingFoundationTest | 27 / 27 |
| SlateLicenseCacheStoreTest / LicensingQaFixRound2Test | 7 / 7, 4 / 4 |
| Phase10CheckInSyncTest | 9 / 9 |
| LicensingCheckInTest | 22 / 22 |
| RemoteLicenseClientTest / LicensingSignatureTest | 14 / 14, 7 / 7 |
| `php 02-licensing/tests/run-phase2-race.php` | concurrent_results=2, activation_count=1, bindings=1 |
| `01-client/tests/e2e/phase12/run.sh` | install 50/50, runtime 139/139, central authz 22/22, expired activation 8/8, reinstall 15/15, stress 10/10 |
| `php -l` on every PHP file | clean |

The failure set before and after the Phase 12 changes is **identical** (51 lines, diffed).

## Known Pre-existing Failures

- Client unit (5): Phase 3 remote client exact mapping; 3 `archive/plugins/react-site-bridge` identity-string tests; files session handler lock timing.
- Client integration (19): legacy `EntitlementServiceTest` (6), white_label (5), dashboard plan section (2), ContactSeeder / identity tokens (4), `archive/.htaccess`, `SmtpOAuth` class.
- Central unit (20): tests reference plugins not present in the `02-licensing` checkout (booking, forms, backups, mcp-gateway, multilang-translate).
- Central integration runner: aborts in `BookingAdminTenantLinkageTest` (no booking tables in the central test DB); not run by CI.
- `CentralLicensingFoundationTest` alone: 0/22 without `LicensingPluginSchemaTest` loaded first (defines `licplug_ensure_schema`) — test-order dependency, passes as CI runs it.

None is new; `02-licensing` production code was unchanged between `e63b2f3` and `711b492`.

## New Defects

| ID | Severity | Reproduction | Impact | Files | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **D1** | P1 | Install, then suspend/revoke/expire the license on central; wipe the client DB and `.installed` (keep `.env`); run the installer with the same key. Central returns a signed `suspended`/`revoked`/`expired` state for the bound installation (11 §7) and the installer advanced to admin creation and Finish. | Violates 05 §1 Step 4 ("status in {trial, active}"): an admin is created and the install completes into a locked app. Not a commercial bypass (the Guard still locks). | `01-client/includes/installer_flow.php` (new `installer_license_usable()`, used by `installer_resolve_step()`), `01-client/install.php` (step 3) | **Fixed**; `scenario_reinstall` 15/15; regression test in `InstallerLicenseActivationFlowTest` (fails on old code) |
| **D2** | P2 | Issue a license with `expires_at` in the past; first check-in activates it. | Stored and **signed** `status: active` with a past expiry (lazy expiry sync skipped Unactivated), contradicting 11 §7; the installer then continued. Client-side windowing still used `expires_at`. | `02-licensing/plugins/licensing/LicensingAPI.php` (post-activation re-read via `LicenseService::syncExpiry()`) | **Fixed**; now `expired` + `expire` event, renewable; regression test in `Phase10CheckInSyncTest` (fails on old code) |
| **D3** | P1 | Fresh install, then run `bin/license-check.php`: "Remote licensing is not configured — skipping". | The installer never persisted `LICENSE_KEY`, so the unattended check-in (06 §5.1) could not run: every new install would lock 7 days later unless an admin re-entered the key on the License page. | `01-client/install.php` (step 3 persists the verified key, as `admin/license.php` already does) | **Fixed**; E2E check-in after install succeeds; regression test in `InstallerLicenseActivationFlowTest` |

Tests added: `InstallerLicenseActivationFlowTest` (+2), `Phase10CheckInSyncTest` (+1), `CommercialLicenseWindowTest` (+1, offline boundaries), E2E harness `01-client/tests/e2e/phase12/`.

## Production Readiness — remaining requirements

Not code defects in the licensing architecture; each must be closed before production (Phase 14 scope unless stated):

1. **Web-server configuration is not in the repository.** Neither app ships a root `.htaccess` (or nginx equivalent); `README.md`/`INSTALL.md` say one exists. The original (`00-original/*.zip`) is deployment-specific (`RewriteBase /solaya/`). Without it, Apache does not route `api/v1/*` or `public.php` paths (including central `/licensing/check`), and `.env`, `.installed`, `data/*.log`, `db_backups/` are downloadable. **Deployment blocker.**
2. **Schedule the license check-in.** Nothing in the app schedules `bin/license-check.php`; a crontab entry (daily is sufficient) is required, or every installation locks after 7 days offline tolerance. Not documented in `INSTALL.md`.
3. **Licensing configuration delivery.** `LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, `LICENSE_PRODUCT` must be provided by the build/hosting environment (installer step 1 writes `.env` without them); no `.env.example` exists in either app.
4. **Central `APP_SECRET` backup/escrow** — losing it makes the signing key unrecoverable (12 §2.16).
5. **Tracked production data:** `00-original/u263467780_kohevo_client.sql` contains a real admin email, bcrypt hash, session hashes and IPs. Removal from history needs an owner decision.
6. **`APP_URL` default** in both `config.php` is a third-party domain; a missing `APP_URL` silently points links and the license domain there.
7. **Central single-tenant assumption not enforced** — licensing pages trust tenant-scoped `licensing.manage`.
8. Lower-severity observations (no fix made): concurrent first check-ins from the *same* installation — losers get 403, a retry succeeds; client admin requests run ~440 logical queries, dominated by core session re-validation (88×) and settings reads (118×) — licensing adds 3 cache reads at ~0.22 ms each; central `slate.log` has no rotation and legacy `licensing_checkins` no pruning; installer step 1 has no CSRF and writes raw values to `.env` (pre-install only); `LICENSE_COMPAT_MODE=legacy` and the unsigned product field are `.env`-controlled trust (the operator who controls `.env` already controls the public key); `admin/exit-tenant.php` is not whitelisted while locked (logout recovers).
9. Deferred by decision (Phase 11): clock rollback, server replay window, rate limiting (infrastructure), key rotation.

## Final Verdict

All critical flows and security boundaries pass end to end after the three in-phase fixes (D1–D3), with regression coverage and an unchanged pre-existing failure set. The remaining items are production deployment and data-hygiene requirements, not blockers for the Phase 13 migration; the system is **not** production-ready until items 1–3 above are closed. The Phase 12 changes still need CI and independent verification.

**PHASE 12 VERIFIED — READY FOR PHASE 13**
