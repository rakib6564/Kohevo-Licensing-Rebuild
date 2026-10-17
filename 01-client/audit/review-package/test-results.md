# Test results

**Run date:** 2026-10-17
**Environment:** macOS arm64, PHP 8.5.9 CLI, Node v24.18.0, local MySQL. Test databases: `root_slate_t9` (client) and `root_slate_t20` (licensing), taken from each tree's `.env`.

| Tree | What it is |
|---|---|
| **Final** | `~/Downloads/Fahim/KOHEVO-STUDIO`, branch `audit/settings-secrets-hardening`: audit commit `e9a1bdf` plus this pass's uncommitted follow-up changes |
| **Base** | Clean `git archive ba4c9a5` (= `origin/main`, re-fetched today) in a scratch directory, with the same `.env`, `vendor/` and `node_modules/`. The user's worktree was not touched for this. |

Runners (copied to `test-logs/`):
- `run-suites.sh <root> <logdir>`: every suite, in sequence, from the repository root. The UI suite runs from `01-client/plugins/studio-builder/ui`.
- `run-lic-perfile.sh`: each licensing integration file in its own PHP process.
- `run-lic-ci.sh`: the exact 02-licensing commands from `.github/workflows/ci.yml`.

Raw logs: `test-logs/final/`, `test-logs/base/`. They contain local absolute paths, but no credentials.

## Results

| Suite | Command | Base (`origin/main`) | **Final** | Final exit |
|---|---|---|---|---|
| Client unit | `php 01-client/tests/unit/run.php` | 977 / 983 (6 fail) | **996 / 996** | 0 |
| Licensing unit | `php 02-licensing/tests/unit/run.php` | 426 / 430 (4 fail) | **443 / 443** | 0 |
| Client smoke | `php 01-client/tests/smoke.php` | 21 / 21 | **21 / 21** | 0 |
| Licensing smoke | `php 02-licensing/tests/smoke.php` | 21 / 21 | **21 / 21** | 0 |
| Client integration | `php 01-client/tests/integration/run.php` | 608 / 631 (23 fail) | **636 / 636** | 0 |
| Licensing integration, documented single-process runner | `php 02-licensing/tests/integration/run.php` | fatal after 125 ok / 53 FAIL | **fatal after 126 ok / 52 FAIL** (`Cannot redeclare function licplug_teardown()`) | 255 |
| Licensing integration, one process per file (70 files) | `test-logs/run-lic-perfile.sh` | 349 / 403 (54 fail) | **354 / 403 (49 fail)** | 1 |
| Licensing CI command set (11 commands) | `test-logs/run-lic-ci.sh` | all exit 0 | **all exit 0**: smoke 21/21, RemoteLicenseClient 14/14, LicensingSignature 12/12, phase2-licensing 22/22, PluginSchema 5/5, Schema+CentralLicensingFoundation 27/27, SlateLicenseCacheStore 7/7, QaFixRound2 4/4, Phase10CheckInSync 9/9, Phase13LegacyAdmin 7/7, phase2-race exit 0 | 0 |
| Studio Builder unit (subset of client unit) | `php 01-client/tests/run-studio-builder.php unit` | 499 / 499 | **499 / 499** | 0 |
| Studio Builder integration (subset of client integration) | `php 01-client/tests/run-studio-builder.php integration` | 137 / 137 | **137 / 137** | 0 |
| Studio Builder UI | `npm test` | 368 / 368 | **368 / 368**; 0 skipped, 0 todo, 0 cancelled | 0 |
| Settings regression (subset of unit), each tree | `SystemSettingsAuditFixesTest.php` | n/a (file is new) | **9 / 9** and **9 / 9** | 0 |
| Forms CAPTCHA unit (subset of unit), each tree | `FormsCaptchaSecretEncryptionTest.php` | n/a | **6 / 6** and **6 / 6** | 0 |
| Translation check | `php scripts/check-i18n.php` | OK | **OK** | 0 |
| PHP syntax | `php -l` on all 48 changed or new PHP files | — | **no errors** | 0 |
| Whitespace | `git diff --check origin/main` | — | **clean** | 0 |

The UI count is 368, not the 371 reported before the rebase. The 3 extra UI tests came from the Field value dropdown commit, which is no longer under this branch. Base shows the same 368.

### Count reconciliation

- Client unit: 983 on base − 2 removed react-site-bridge tests + 9 settings tests + 6 Forms tests = **996**.
- Licensing unit: 430 − 2 + 9 + 6 = **443**.
- Client integration: 631 + 3 Stripe (`StripeWriteOnlySecretTest` cases 1–3) + 1 Stripe Disconnect + 1 Forms editor = **636**.
- The Studio-runner rows and the per-suite regression rows are subsets of the unit and integration counts and are **not** added again below.

### Totals (each test counted once)

| | Discovered | Executed | Passed | Failed | Skipped | Not run |
|---|---|---|---|---|---|---|
| Client unit + licensing unit + both smoke + client integration + UI | 2,485 | 2,485 | **2,485** | 0 | 0 | 0 |
| Licensing integration (per file) | 403 | 403 | 354 | 49 | 0 | 0 |
| **All** | **2,888** | **2,888** | **2,839** | **49** | **0** | **0** |

The PHP harness has no skip state. No test in the final tree returns early to fake a pass: the two vacuous react-site-bridge tests were removed. The tests that skip without `APP_SECRET` (`StripeWriteOnlySecretTest`, `FormsCaptchaSecretSaveTest`) ran fully here, because `APP_SECRET` is configured.

## Disposition of the 49 licensing integration failures (per-file run)

None of the 49 is introduced by this branch: every failing test in the final tree also fails on `origin/main`. The 5 that went from failing to passing are listed at the end of this section.

| Count | File(s) | Category | Root cause (evidence) |
|---|---|---|---|
| 22 | `CentralLicensingFoundationTest` | Harness: passes in the supported method | `Call to undefined function licplug_ensure_schema()`. The helper lives in `LicensingPluginSchemaTest.php`. CI runs the two files together, and that exact command passes 27/27 on both trees. Running the file on its own is not a supported invocation. The same helper split makes the single-process `run.php` fatal on a redeclare. |
| 20 | `BookingAdminCapabilityGuardTest` (7), `BookingAdminTenantLinkageTest` (3), `BookingApiAuthorizationTest` (8), `BookingMessagePublicGuardTest` (2) | Base production defect, unresolved | `Class "ModuleGuard" not found`. The licensing copies of the booking pages and handlers call `ModuleGuard`, but that class exists only in `01-client/src/Services/Licensing/ModuleGuard.php`. Reproduced directly: `php tests/fixtures/admin-page-probe.php plugins/booking/admin/messages.php` → fatal at `messages.php:16`. Remaining risk R4. |
| 1 | `BookingTwilioTokenEncryptionTest` | Base production defect, unresolved | Same `ModuleGuard` fatal: `plugins/booking/admin/settings.php` uses it, so the probe save never reaches the handler. |
| 1 | `FormsPdfTokenLiveTest` | Base production defect, unresolved | `Class "ModuleGuard" not found` at `02-licensing/plugins/forms/public/router.php:19` |
| 3 | `LicenseGateTest` | Base stale test, unresolved | The test seeds an unsigned licence-cache row. Since Phase 13 only signed cache state counts, so the gate returns 403. This is the same class of retired expectation that the audit updated in the client tests; the licensing copy was not updated. |
| 2 | `DashboardMetricsTest` | Base stale test and harness, unresolved | The licensing probe prints `session_id(): Session ID cannot be changed` and `Undefined array key "name"` (`02-licensing/admin/index.php:210`) before `STATUS`. It also uses pre-Phase-13 expectations. The client copy was fixed by the audit; the licensing copy was not. |

**From failing on base to passing in the final tree (5):**
- `IdentityStoreTest` token test (the `IdentityStore.php` fix)
- `PluginLoaderArchiveBoundaryTest` (the `archive/.htaccess` sentinel)
- `ContactSeederTest` (1) and `CustomerAuthParityTest` (2): the client clock-skew fixture fix, mirrored to licensing in this pass

**The licensing integration suite does not pass.** It has 49 per-file failures, and its documented single-process runner fatals. Every failing test also fails on base, but none of them is resolved here.

## Base failures in the client suites, all passing in the final tree

- **Client integration, 23 on base:** ContactSeeder, CustomerAuthParity ×2, IdentityStore token, dashboard plan, EntitlementService ×6, platform-admin upload UI ×2, white_label ×5, plugin activation ×4, archive sentinel. Root causes and fixes are in `verification-matrix.md` (CLK-1, SEC-5) and in the per-file test diffs.
- **Client unit, 6 on base:**
  - Phase 3 remote mapping: the payload lacked `installation_id`.
  - Three react-site-bridge checks: those tests are now **removed**, because the plugin was never committed.
  - Session-lock timing.
  - Payment-blocks render: fixed by defining `APP_SECRET` and signing through `slate_sign()`. There is no production seam.
- **Licensing unit, 4 on base:** the 3 react-site-bridge checks (removed) and session-lock timing.

## NOT RUN

| What | Why |
|---|---|
| Live Stripe API (`stripe_verify_secret`), Stripe webhooks, Twilio SMS, Google OAuth and Calendar/Drive | No live credentials or network calls in this environment. These flows are **not** tested. |
| Playwright e2e (`npm run test:e2e`) | Needs a running site and a browser |
| GitHub Actions CI | Nothing pushed. CI uses PHP 8.3; the local runs used 8.5.9. |
| Encryption-failure path on the Backups, Booking and MCP settings pages | No test exists |
