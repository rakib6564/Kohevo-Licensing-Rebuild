# Test results (release-check, 2026-10-17)

## Environment

- macOS, PHP 8.5.9, Node 24.18.0, local MySQL.
- Test databases: `root_slate_t9` (client) and `root_slate_t20` (licensing). The guard script confirms both are test databases. No user database was reset or recreated.
- `APP_SECRET` is set in both test `.env` files. Its value is not recorded here.
- **Base:** `origin/main` `ba4c9a5`, run in a detached worktree. `main` has not moved, so the base runs are current.
- **Release tree:** `integration/release-check` `99ba13e3caed50524fcd59ffcfc9d6bf3f49c2e3`. It is `ba4c9a5` with these merged in:
  - PR #102 `6fed518`;
  - `audit/settings-hardening-next` `49c1fd4`;
  - `fix/licensing-module-guard` `d77b076`.
- Later merges into this tree change documentation only (`git diff 99ba13e <head> -- ':!01-client/audit'` is empty).
- **Runner:** `test-logs/release/scripts/run-rel-final.sh` (run r15). Every suite runs **one at a time**.
- Logs: `test-logs/release/`.

## Results

| Suite | Command (from the repository root) | Base `ba4c9a5` | Release `99ba13e` | Status |
|---|---|---|---|---|
| Client unit | `php 01-client/tests/unit/run.php` | 977/983 (+1 silent skip counted as a pass) | **1008/1008**, 0 skipped | PASS |
| Licensing unit | `php 02-licensing/tests/unit/run.php` | 426/430 (+1 silent skip) | **455/455**, 0 skipped | PASS |
| Client smoke | `php 01-client/tests/smoke.php` | 21/21 | 21/21 | PASS |
| Licensing smoke | `php 02-licensing/tests/smoke.php` | 21/21 | 21/21 | PASS |
| Client integration | `php 01-client/tests/integration/run.php` | 608/631 | **656/656** | PASS |
| Client integration, shuffled order | `run-shuffled.php 01-client <seed>`, seeds 20261017 and 7 | not run | **656/656** both seeds | PASS |
| Licensing integration, single process | `php 02-licensing/tests/integration/run.php` | **crashed** (`Cannot redeclare licplug_teardown()`), no summary | **412/414** | 2 FAIL (R5, decision) |
| Licensing integration, shuffled order | seeds 20261017 and 7 | not run (would crash) | **412/414** both seeds, same 2 | 2 FAIL (R5) |
| Licensing integration, one process per file | `run-lic-perfile.sh` | 349/403 (54 failing) | **412/414** | 2 FAIL (R5) |
| Licensing CI subset (the `ci.yml` commands) | `run-lic-ci.sh` | 11/11 files | 11/11 files, 128/128 cases | PASS |
| Studio Builder unit | `php 01-client/tests/run-studio-builder.php unit` | 499/499 | 499/499 | PASS |
| Studio Builder integration | `… integration` | 137/137 | 137/137 | PASS |
| Studio Builder UI | `npm test` in `01-client/plugins/studio-builder/ui` | 368/368 | 368 pass, 0 fail, 0 skipped | PASS |
| Translation | `php scripts/check-i18n.php` | OK | OK: 0 missing, 0 en→fr gaps, both trees | PASS |
| PHP syntax | `php -l` on every `.php` changed against `origin/main` | n/a | 0 errors | PASS |
| JS syntax | `node --check` on changed JS | n/a | 2/2 ok | PASS |
| Anti-drift | `php bin/anti-drift.php` in each tree | 12 client + 1 central | the same 12 + 1, all in files these PRs don't change | BASELINE |
| Mutation tests | `scripts/mutate-rel.py` (15 mutations, each file restored) | n/a | **15/15 caught** (`mutations-rel.txt`) | PASS |
| Inventory verifiers | `csv-verify.py`, `inventory-claims-verify.py` | n/a | 99 VERIFIED + 1 NOT_IMPLEMENTED; 60/60 types; 12/12 encryption, 69/69 parity claims | PASS |
| CI on PR #102 head `6fed518` | GitHub Actions | n/a | MySQL 8.0 ✓, MariaDB 10.11 ✓, Playwright ✓ | PASS (branch alone) |
| Playwright on the release tree | `npm run test:e2e` | n/a | not run: its sandbox script drops and recreates `slate_sbx_e2e`. The PRs change no Studio Builder code | NOT RUN |
| CI on PR 2 and PR 3 heads | n/a | n/a | not pushed | NOT RUN |
| Live providers (Stripe, Twilio, Google, Microsoft, AI, translation) | n/a | n/a | not called by any test; sandbox plan in `11-runbooks.md` §4 | NOT RUN |

**Why the totals changed:**
- **Client integration:** `SettingsEncryptFirstTest` (+6), `RekeySecretsCliTest` (+7) and `AppEnvTestingBypassTest` (+2), among others.
- **Licensing:** `CentralModuleGuardTest` (6), `CentralTenantPlanCliTest` (2), `TenantPlanAdminTest` (5) and a new `LicenseGateTest` case.
- **Per-file licensing:** the total now equals the single-process total. `TenantSwitchAuditTest` no longer re-runs `TenantOverrideRecursionTest`'s 5 cases inside itself (`19c9a55`).

## The 2 remaining licensing failures

| File | Base | Release | Cause |
|---|---|---|---|
| `DashboardMetricsTest` | 1/3 | 1/3 | `0d48565` ("Fix Date & Time formatting mismatch…") removed the "Platform overview" and "Your plan" sections from `02-licensing/admin/index.php`. This looks accidental. Restore or retire: decision R5. |

`LicenseGateTest` is fixed (5/8 → 9/9, `5462c1e`). Its 3 failures were fixtures that predated the installation-identity trust rule. The client copy had already been updated.

## Order independence

Seeded shuffled runs found two test-order bugs that predate this work. Both are fixed in both trees:

1. `TenantSwitchAuditTest` loaded `TenantOverrideRecursionTest.php` with `require_once`, and the runner then loaded the same file again with `require`. Whenever the first file ran first, the second load redeclared a function and the whole run died with a fatal error (`19c9a55`).
2. `MfaRepositoryTest` left behind the admin session that `Auth::completeMfa()` registers. A later test that registered the same session id hit the unique key (`444c531`). `scripts/find-session-leak.php` found the file by replaying the order.

**Run one suite at a time.** Running the client and licensing integration suites at the same time produced false failures: client Phase 10 tests reset the licensing test tables. That run is kept in `concurrent-run-not-counted/` and isn't counted. It is also why R4 (adding these runners to CI) needs separate jobs or databases.

## Silent skips

| Item | Status |
|---|---|
| Redirect-origin case in both unit runs | Used to be skipped and counted as a pass. It now runs. |
| `APP_SECRET` guards in the Forms CAPTCHA and Stripe tests | Now preconditions. |
| PR #102's `SecretHandlingGapsTest` (2 guards) | Still skip-as-pass, but not taken, because `APP_SECRET` is set (R3). |
| 8 conditional skips that predate this work | Not taken in this environment. |

## Test-isolation problems found and fixed in this work

1. `SettingsEncryptFirstTest` left `booking.cap.*` changed (`240caef`). The 27 polluted test-database rows were backed up to JSON and then removed.
2. The shared test tenant had Enterprise, which carries `white_label` (`fd592d3`).
3. The static check required a `try/catch` in each settings page (`9ac61ab`).
4. The two order dependencies above.

## High fixes (N1–N5) on the combined tree

**Tree:** `integration/release-check-highs` (local, test only, never pushed). It is `integration/release-check` plus the five fix branches (`c19c9d5`, `3862f1c`, `885d23e`, `72fd527`, `4aa8550`), plus `234b4d6`.

The only merge conflict was in the Sprint6 unit test, where two new cases were both appended at the end. Both were kept. `templates.php` (N3 and N5) merged automatically.

**Logs:** `test-logs/release-highs/`. Each suite ran one at a time.

| Suite | Release `99ba13e` | Combined `762d0c7` (r17) | After `234b4d6` (`5e4963c`) | Status |
|---|---|---|---|---|
| Client unit | 1008/1008 | **1010/1010** | (unchanged) | PASS |
| Licensing unit | 455/455 | 455/455 | (unchanged) | PASS |
| Smoke, both trees | 21/21, 21/21 | 21/21, 21/21 | (unchanged) | PASS |
| Client integration | 656/656 | **666/666** | **666/666** | PASS |
| Client integration, shuffled 20261017 / 7 | 656 / 656 | 663/666 / 666 | **666 / 666** | PASS after `234b4d6` |
| Licensing integration (single, per-file, both shuffles) | 412/414 | **417/419** | (unchanged) | 2 FAIL = R5 (Dashboard) |
| Licensing CI subset | 128/128 | 128/128 | (unchanged) | PASS |
| Studio Builder unit / integration | 499 / 137 | **501** / 137 | (unchanged) | PASS |
| Studio Builder UI | 368, 0 skipped | 368 pass, 0 fail, 0 skipped (re-run, see note) | (unchanged) | PASS |
| Translation, PHP/JS syntax, anti-drift | OK, 0, 2/2, 12+1 | OK, 0, 2/2, the same 12+1 | (unchanged) | PASS / BASELINE |
| Mutations, release set | 15/15 | — | **15/15** | PASS |
| Mutations, N1–N5 (`scripts/mutate-highs.py`) | — | — | **11/11** | PASS |

**Notes:**
- **Shuffled client run (seed 20261017):** the new test files changed the order, which exposed a dependency already on base. `Phase10ZAutoRefreshTest` borrowed helpers from `Phase10SynchronizationTest` and relied on loading after it. Fixed in `234b4d6` (`tests/support/phase10.php`). Both files now pass alone and in either order (22/22).
- **Scan for similar dependencies:** a scan of every integration file in both trees for helpers defined in another test file found no other real case; the other 14 matches were mentions in comments.
- **Studio UI:** the first UI run in the new worktree reported 22 skipped. `node_modules` was missing there, so the esbuild-based tests skip themselves; this is an environment gap, not a code change. With the dependencies linked, the suite passes 368/368 with 0 skipped. That re-run's log replaces the first one.
- **Database state:** after the mutation runs the database was checked. No rows from these tests remain, and `smtp_host` is unchanged.

## Earlier runs

Runs r8–r10 on the superseded `integration/final-check` are in `test-logs/final/`.
