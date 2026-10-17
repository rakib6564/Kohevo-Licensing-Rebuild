# Remaining-risk register (final, 2026-10-17)

Every item was confirmed against the source of the final combined tree, `integration/final-check`.

## Closed in this release set

| # | Risk | Fix | Branch | Regression evidence |
|---|---|---|---|---|
| S1 | SMTP OAuth secrets stored in plaintext when encryption was unavailable; a half-configured mailer | `SmtpOAuth::sealSecrets()`: encrypt before any write; on failure nothing is saved | PR #102 | `SmtpOAuthSealSecretsTest`; mutation tests caught |
| S2 | Multilang auto-translate key stored and read in plaintext; no write path | Write-only field; `save_autotranslate_key` (`mlt.manage`, CSRF, audit log); encrypted at rest | PR #102 | `MltAutoTranslateKeyTest`, `SecretHandlingGapsTest` |
| S3 | AI base URL SSRF | `vetBaseUrl()` on save and when used: https only, every resolved address must be public (including CGNAT, NAT64, 6to4, Teredo, multicast); pinned with `CURLOPT_RESOLVE`; no redirects | PR #102 | `AiProviderClientUrlVettingTest` (5 cases per tree); real-DNS probes of shorthand IPv4 forms |
| S4 | Forms CAPTCHA secret stored in plaintext | Encrypted; save blocked if encryption fails; blank keeps; explicit clear | hardening `d368b5c` | `FormsCaptchaSecretEncryptionTest`, `FormsCaptchaSecretSaveTest` |
| S5 | Stripe "Disconnect" left the `test_`/`live_` key slots set | Clears all key slots and webhook secrets in both modes, keeps unrelated settings, writes an audit-log entry | hardening `d368b5c` | `StripeWriteOnlySecretTest` (Disconnect case) |
| S6 | Production test hook in `PaymentButtonRenderer` | Removed | hardening `d368b5c` | `git grep` finds no override |
| S7 | Backups, Booking and AI saved half a form when a secret couldn't be encrypted | `slate_seal_posted_secrets()`: encrypt first, otherwise save nothing | hardening `b8a6fdc` | `SettingsEncryptFirstTest` (fails on the old code, 3/6), `SealPostedSecretsTest` |
| S8 | `APP_SECRET` rotation made every stored secret unreadable, with no recovery | `bin/rekey-secrets.php`:<br>• dry run by default; the old secret comes from an environment variable, never argv<br>• stops if any value is unreadable<br>• one transaction; an envelope-only backup (mode 0600)<br>• symmetric rollback; rerunning is safe | hardening `197fe44`, `17f3a43` | `RekeySecretsCliTest`; removing the stop makes it fail |
| S9 | Central `ModuleGuard` missing: 60 calls in 56 files fataled with a 500 | Class ported; per-tenant plan gating | `fix/licensing-module-guard` | `CentralModuleGuardTest` (real pages: 200 or 403 per plan); making `allows()` return true fails it |
| S10 | No supported way to give a central tenant a plan | `bin/assign-tenant-plan.php` (refuses inactive plans; audit-logged) and the `bin/check-tenant-plans.php` preflight | `fix/licensing-module-guard` | `CentralTenantPlanCliTest` |
| T1 | A silent skip counted as a pass (redirect origin, both unit runs) | Now runs in a child process, including lookalike hosts | hardening `b8a6fdc` | `AuthSecurityBoundaryTest` |
| T2 | Five `APP_SECRET` skip-as-pass guards (Forms CAPTCHA, Stripe) | Now precondition assertions | hardening `b8a6fdc` | n/a |
| T3 | Licensing single-process runner crashed (`licplug_teardown` declared twice); CentralLicensingFoundation failed on its own | One shared support file | hardening `938c4d0` | 22/22 alone; the runner completes |

## Still open

| # | Severity | Risk | Recommendation |
|---|---|---|---|
| R1 | High: **decision** | **Per-plan gating in central needs a plan-assignment path.** No admin screen sets `tenant_profiles.plan_id`, and licences store their own `plan_id`, which entitlement checks don't read. Today the CLI is the only path. | Choose: (a) add a plan selector to the Tenants screen (platform admins only, CSRF, audit log); or (b) make `EntitlementService` fall back to the tenant's current licence plan. Until then, run the preflight and assign plans with the CLI before deploying. |
| R2 | Medium: **decision** | An inactive plan keeps granting its modules to tenants already on it (`PlanService::entitlementsFor()` ignores `is_active`). This matches the architecture doc's "retire a plan from future use without deleting historical reference", and the CLI refuses to assign inactive plans. | Confirm the policy. If inactive should also revoke access, change it in one place and add a test. |
| R3 | Low | PR #102's `SecretHandlingGapsTest` still has 2 `APP_SECRET` skip-as-pass guards. `APP_SECRET` is set locally and in CI, so they run. | Convert them when the hardening PR is merged on top of #102 (a one-line change each). |
| R4 | Medium | CI does not run `tests/unit/run.php` or `tests/integration/run.php` in either tree, so most security tests run only locally. | Add both runners to `ci.yml`. All are green on the final tree, and the licensing single-process runner now completes. |
| R5 | Low | 5 licensing integration tests have out-of-date expectations: LicenseGate 3 (seeds an unsigned cache that Phase 13 no longer trusts), Dashboard 2. | Update the fixtures in a follow-up. |
| R6 | Low | With HTTPS proxy environment variables set, curl sends AI requests through the proxy, which does its own DNS lookup. The Multilang endpoint has no write path and is not vetted. | Document; vet the endpoint if a UI for it is ever added. |
| R7 | Low | Anti-drift: 12 client findings and 1 central (`plugins/licensing/admin/license.php:228`). The same findings appear on `origin/main`. | Fix separately. |
| R8 | Info | AI provider error text (up to 300 characters) is shown in the admin chat (`mcp-gateway.manage` only) and only logged for the public assistant. | Acceptable. |
| R9 | Info | Playwright was not run locally on the combined tree. The sandbox script drops and recreates the `slate_sbx_e2e` database. Playwright did pass in CI on PR #102. | It runs in CI on each PR. |

## Studio Builder product gaps (not release blockers; from the audit and source)

- `portfolio.rakib_showcase` is registered but no test references it.
- The Booking, Forms, Membership and payment widgets are not render-tested by the widget audit tool (`docs/WIDGET-AUDIT.md`).
- Flex-child controls (Order, Grow, Shrink, Basis) were reported earlier as needing a design-control review.
- Brand font tokens change CSS variables, but the font files aren't loaded.
- The widget matrix counts test files that reference each type. It does not prove every control, default, responsive value or sanitization path is covered. Containers, Theme Builder conditions, and template import/export were **not re-audited in this pass**; earlier audit coverage applies.
