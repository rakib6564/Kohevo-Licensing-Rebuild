# Remaining-risk register (2026-10-17, release-check)

Every item was checked against the combined tree `integration/release-check` (local, test only). The full list of individual findings, including the new Studio Builder and settings audit, is in `12-findings-register.md`. This file covers only what decides the release.

## Closed in this release set

| # | Risk | Fix | Branch | Regression evidence |
|---|---|---|---|---|
| S1 | SMTP OAuth secrets stored in plaintext when encryption was unavailable; a half-configured mailer | `SmtpOAuth::sealSecrets()`: encrypt before any write; on failure nothing is saved | PR #102 | `SmtpOAuthSealSecretsTest`; mutation caught |
| S2 | Multilang auto-translate key stored and read in plaintext | Write-only field, encrypted at rest, audit-logged | PR #102 | `MltAutoTranslateKeyTest`, `SecretHandlingGapsTest` |
| S3 | AI base URL SSRF | `vetBaseUrl()` on save and use: https only, every resolved address public, pinned with `CURLOPT_RESOLVE`, no redirects | PR #102 | `AiProviderClientUrlVettingTest`; mutation caught |
| S4 | Forms CAPTCHA secret in plaintext | Encrypted; blank keeps; explicit clear | PR 3 `d368b5c` | `FormsCaptchaSecret*Test` |
| S5 | Stripe Disconnect left the `test_`/`live_` slots set | Clears every slot in both modes; audit-logged | PR 3 `d368b5c` | `StripeWriteOnlySecretTest`; mutation caught |
| S6 | Production test hook in `PaymentButtonRenderer` | Removed | PR 3 `d368b5c` | `git grep` |
| S7 | Backups, Booking and AI saved half a form when a secret couldn't be encrypted | `slate_seal_posted_secrets()`: encrypt first, otherwise save nothing | PR 3 `b8a6fdc` | `SettingsEncryptFirstTest`, `SealPostedSecretsTest`; mutation caught |
| S8 | `APP_SECRET` rotation made every secret unreadable | `bin/rekey-secrets.php`: dry run by default; old secret from the environment only; stops on any unreadable value; refuses non-InnoDB tables; ciphertext-only backup (0600); one transaction with guarded writes; `--restore` that refuses under the wrong secret; reverse run | PR 3 `197fe44`, `346b48b`, `c56c7be` | `RekeySecretsCliTest` 7 cases (forced mid-run failure via trigger, MyISAM refusal, corrupt ciphertext, restore, reverse run); 4 mutations caught |
| S9 | Central `ModuleGuard` missing: 60 calls in 56 files fataled | Class ported; per-plan gating | PR 2 `08f3b99` | `CentralModuleGuardTest`; mutation caught |
| S10 | No supported way to give a central tenant a plan (was **R1**) | Tenants › **Plan** selector: platform admins only, CSRF, audit log `tenant.plan_assigned`, inactive plans refused, "would be refused a module" warning on the list; plus `bin/assign-tenant-plan.php` and `bin/check-tenant-plans.php` on the same service | PR 2 `188808b`, `2866452` | `TenantPlanAdminTest` 5 cases, `CentralTenantPlanCliTest`; 2 mutations caught |
| S11 | `APP_ENV=testing` switched off the licence guard and ModuleGuard on **web** requests | Honoured only on CLI | client PR 3 `8db616f`, central PR 2 `d77b076` | built-in web server probes in `AppEnvTestingBypassTest` (2 cases) and `CentralModuleGuardTest`; 3 mutations caught |
| T1–T3 | Silent skips; licensing single-process crash | See `04-test-results.md` | PR 3 | — |
| T4 | 3 `LicenseGateTest` failures (fixtures predated the installation-identity trust rule) | Fixtures seed trusted rows; new case pins that untrusted rows restrict | PR 3 `5462c1e` | 9/9 |
| T5 | Two test-order dependencies (fatal or unique-key failure in any non-alphabetical order) | `19c9a55`, `444c531` | PR 3 | seeded shuffled runs pass in both trees |

## Still open: decisions for the owner

| # | Severity | Item | Recommendation |
|---|---|---|---|
| D1 | High: **decision** | Gate central Forms, Booking and Membership per plan (implemented) or exempt the central tree | Approve per-plan gating. Before deploying PR 2, run `bin/check-tenant-plans.php` and assign plans, starting with the operator's tenant (`11-runbooks.md` §1). Nothing is assigned automatically. |
| R2 | Medium: **decision** | A retired (inactive) plan keeps granting its modules to tenants already on it; new assignments are refused. Re-saving a tenant's current retired plan is a no-op | Confirm. It matches the architecture doc ("retire from future use"). If retiring should also revoke, it is one change in `PlanService::entitlementsFor()` plus a test. |
| R5 | Medium: **decision** | `0d48565` ("Fix Date & Time formatting mismatch…") removed the central dashboard's "Platform overview" and "Your plan" sections. That looks accidental and is the cause of the 2 remaining `DashboardMetricsTest` failures | Restore the sections (preferred) or retire the test. Not changed here. |

## Found in this pass and fixed on separate branches (N1–N5)

These already existed on `origin/main`; they are not regressions from PRs #102, 2 or 3. Each fix is its own local branch based on `origin/main`, with a test that fails without it (descriptions in `pr-high-fixes.md`). None is pushed.

| # | Severity | Item | Branch, commit | Evidence |
|---|---|---|---|---|
| N1 | High | Stored XSS in `theme.post_content` "Fallback Content" (W-F1) | `fix/studio-post-content-xss` `c19c9d5` | Sprint6 case, 6 payloads; mutation caught |
| N2 | High | MCP `settings.set` wrote any key not matching `pass\|secret` (S-11) | `fix/mcp-settings-allowlist` `3862f1c` | `McpSettingsAllowlistTest` 5 per tree; 4 mutations caught |
| N3 | High | Template delete hard-deleted the wrong page and its revisions (TB-02) | `fix/studio-template-delete` `885d23e` | `StudioTemplateDeleteTest` with a decoy page; 2 mutations caught |
| N4 | High | Draft Theme Builder conditions went live without publishing (TB-01) | `fix/studio-theme-published-conditions` `72fd527` | Sprint6 case, three draft scenarios; mutation caught |
| N5 | High | `admin/templates.php` and the Studio Content page skipped Studio permission levels (TB-03, ST-01) | `fix/studio-admin-permissions` `4aa8550` | `StudioAdminPermissionsTest` with real roles; 3 mutations caught |

**Behaviour changes to tell admins about (N2, N3, N5):**
- MCP clients can no longer change plugin, mail or URL settings.
- Deleting a theme template needs `studio-builder.admin`, and archives its page instead of erasing it.
- Theme Builder publish and unpublish need `studio-builder.publish`.
- The Studio Content page needs `studio-builder.edit`.

## Still open: lower

| # | Severity | Item |
|---|---|---|
| R3 | Low | PR #102's `SecretHandlingGapsTest` has 2 skip-as-pass guards (not taken; `APP_SECRET` is set locally and in CI). |
| R4 | Medium | CI doesn't run either tree's `tests/unit/run.php` or `tests/integration/run.php`; most security tests run only locally. Both are now order-independent and green apart from R5, so they can be added. **Client and licensing integration suites must not run at the same time** against the same MySQL: client Phase 10 tests reset the licensing test tables. |
| R6 | Low | AI requests honour HTTPS proxy environment variables (the proxy resolves DNS). |
| R7 | Low | Anti-drift: 12 client + 1 central findings, identical to `origin/main`. |
| R9 | Info | Studio Playwright e2e not run locally (its sandbox drops `slate_sbx_e2e`); runs in CI on push. |
| — | Medium/Low | Studio Builder product gaps (inert controls, duplicate controls, responsive gaps, dead branding keys, settings saved but unused): `12-findings-register.md`. |
