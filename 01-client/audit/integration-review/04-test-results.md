# Test results (final, 2026-10-17)

## Environment

- macOS, PHP 8.5.9, Node 24.18.0, local MySQL.
- Test databases: `root_slate_t9` for the client and `root_slate_t20` for licensing; the guard script confirms both are test databases.
- `APP_SECRET` is set in both test `.env` files.
- **Base** is `origin/main` `ba4c9a5`, run in a detached worktree. `main` has not moved since, so those base runs are still current.
- **Final** is `integration/final-check`, which merges onto `ba4c9a5`:
  - PR #102 at `6fed518`;
  - `audit/settings-hardening-next` at `17f3a43`;
  - `fix/licensing-module-guard` at `fd592d3`.
- The final tree's code is `ed90282`; the documentation commit merged afterwards changes no code.

The full set ran on `8a09f58` (run r8). Two later sets of fixes touch only `02-licensing` tests, the rekey CLI and its test. The suites they affect were rerun on `a747ed6` (r9) and `ed90282` (r10). Each row says which run it comes from. Logs are in `test-logs/final/`.

## Results

| Suite | Command (run from the repository root unless noted) | Run | Base `ba4c9a5` | Final | Status |
|---|---|---|---|---|---|
| Client unit | `php 01-client/tests/unit/run.php` | r8 | 977/983 (+1 silent skip counted as a pass) | **1008/1008**, 0 skipped | PASS |
| Licensing unit | `php 02-licensing/tests/unit/run.php` | r9 | 426/430 (+1 silent skip) | **455/455**, 0 skipped | PASS |
| Client smoke | `php 01-client/tests/smoke.php` | r8 | 21/21 | 21/21 | PASS |
| Licensing smoke | `php 02-licensing/tests/smoke.php` | r8 | 21/21 | 21/21 | PASS |
| Client integration | `php 01-client/tests/integration/run.php` | r9 | 608/631 | **651/651** | PASS |
| Licensing integration, single process (the full runner) | `php 02-licensing/tests/integration/run.php` | r10 | **crashed** (`Cannot redeclare licplug_teardown()`); no summary | **402/407**, no fatal | BASELINE FAILURE (5) |
| Licensing integration, one process per file (supplementary) | `run-lic-per-file.sh` | r10 | 349/403 (54 failing) | **407/412** (5 failing) | BASELINE FAILURE (5) |
| Licensing CI subset (the exact `ci.yml` commands) | `run-lic-ci.sh` | r10 | 11/11 | 11/11 | PASS |
| Studio Builder unit subset | `php 01-client/tests/run-studio-builder.php unit` | r8 | 499/499 | 499/499 | PASS |
| Studio Builder integration subset | `… integration` | r8 | 137/137 | 137/137 | PASS |
| Studio Builder UI | `npm test` (in `01-client/plugins/studio-builder/ui`) | r8 | 368/368 | 368 pass, 0 fail, 0 skipped | PASS |
| Translation | `php scripts/check-i18n.php` | r8, r9 | OK | OK: 0 missing, 0 en→fr gaps, both trees | PASS |
| PHP syntax | `php -l` on every changed `.php` file | r8 | n/a | 0 errors | PASS |
| JS syntax | `node --check` on changed JS | r8 | n/a | 2/2 ok | PASS |
| Anti-drift | `php bin/anti-drift.php` in each tree | r8 | 12 client + 1 central findings | the same 12 + 1 | BASELINE |
| Mutation tests | 12 targeted mutations (`test-logs/final/mutations-final.txt`) | after r10 | n/a | **12/12 caught**; tree restored | PASS |
| CI on PR #102 head `6fed518` | GitHub Actions run 39021925057 | n/a | n/a | MySQL 8.0 ✓, MariaDB 10.11 ✓, Playwright ✓ | PASS (branch alone) |
| Playwright on the combined tree | `npm run test:e2e` | n/a | n/a | not run: the sandbox script drops and recreates the `slate_sbx_e2e` database. The combined tree changes no Studio Builder code. | NOT RUN |
| CI on PR 2 and PR 3 heads | n/a | n/a | n/a | not pushed | NOT RUN |
| Live providers (Stripe, Twilio, Google, Microsoft, AI, translation) | n/a | n/a | n/a | n/a | NOT RUN |

Unit totals grew because of new tests. Client: +SealPostedSecrets, +ReencryptEnvelope, plus the audit tests already counted. Licensing: the same, plus those already counted.

The client integration total grew by `SettingsEncryptFirstTest` (6) and `RekeySecretsCliTest` (4). The licensing totals grew by `CentralModuleGuardTest` (5) and `CentralTenantPlanCliTest` (2).

The single-process and per-file licensing totals differ (407 vs 412). The per-file runner also counts some files in isolation differently, so the two aren't added together.

## The 5 remaining licensing failures (all present on base, same names)

| File | Base | Final | Cause |
|---|---|---|---|
| LicenseGateTest | 5/8 | 5/8 | The test seeds an unsigned licence cache, which Phase 13 deliberately no longer trusts. The expectations are out of date, not a product regression. |
| DashboardMetricsTest | 1/3 | 1/3 | Out-of-date "platform overview" expectations and harness warnings. |

Fixed compared with base:
- 21 central Booking/Forms tests (ModuleGuard port plus an entitled test tenant);
- 22 CentralLicensingFoundation tests (the shared helper);
- the single-process crash;
- all 6 client unit, 4 licensing unit and 23 client integration base failures (audit work).

## Silent skips

| Item | Status in the final tree |
|---|---|
| Redirect-origin case in both unit runs | Used to be skipped and counted as a pass. It now runs. |
| `APP_SECRET` guards in the Forms CAPTCHA and Stripe tests | Now preconditions. |
| PR #102's `SecretHandlingGapsTest` (2 guards) | Still skip-as-pass, but not taken, because `APP_SECRET` is set (R3). |
| 8 conditional skips that predate this work (membership inactive, backups row missing, node missing) | Not taken in this environment. |

## Test-isolation problems found and fixed during this pass

These are recorded here because they first showed up as failures.

1. **My `SettingsEncryptFirstTest` turned Booking capabilities off.** It restored only the keys it asserted, which caused 5 Booking failures in the first final run. Fixed in `240caef`, which restores whole prefixes; the settings fingerprint is now identical before and after. I removed the 27 polluted test-database rows after saving a JSON backup of them.
2. **The shared test tenant got Enterprise for the whole process**, and Enterprise carries `white_label`. That caused 15 platform-signature failures in single-process runs. Fixed in `fd592d3` by switching to Professional.
3. **My static-check update** (`9ac61ab`). The audit check required a `try/catch` in each settings page, so it failed once encryption moved into the shared seal step. It now checks the stronger rule.
