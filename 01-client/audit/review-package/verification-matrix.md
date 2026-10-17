# Verification matrix

**Checked:** 2026-10-17. KOHEVO-STUDIO checkout, branch `audit/settings-secrets-hardening`. Audit commit `e9a1bdf` sits on `origin/main` `ba4c9a5`, plus this pass's follow-up changes, which are uncommitted.

**Status key:**
- **PASS:** the source matches the claim and an executed test catches regressions. A mutation check is noted where one was run.
- **PASS (static):** the claim is backed by a check of the shipped source only.
- **PARTIAL:** the claim is only partly true or only partly tested.
- **FAIL:** the claim is false or the issue is still open.
- **NOT RUN / NOT VERIFIED:** as stated.

Line numbers refer to `01-client`. Every production change below is identical in `02-licensing` unless stated otherwise (see `kohevo-cross-tree-parity.md`).

| ID | Item | Source | Behaviour now | Test evidence | Status |
|---|---|---|---|---|---|
| SEC-1 | Stripe secret key and webhook secret are write-only | `plugins/stripe-payment/admin/settings.php` (POST handler, inputs) | <ul><li>Inputs render empty with a "Configured" badge.</li><li>A blank field keeps the stored value.</li><li>A new secret is encrypted, or the whole save is refused (fail-closed).</li><li>Legacy plaintext is upgraded to an envelope on save.</li></ul> | `StripeWriteOnlySecretTest` cases 1–3 (real POST/GET through the admin page). Mutation-checked: fails without `$keepStored`. | **PASS** |
| SEC-1a | Stripe "Disconnect" clears all keys | same file, `_action=clear_keys` | <ul><li>Blanks the publishable key, secret key and webhook secret in both the `test_*` and `live_*` slots, plus the legacy slots and the verification cache.</li><li>Writes the `stripe.keys_cleared` audit event.</li><li>Leaves countries and toggles untouched.</li><li>The confirmation text ("Clear ALL Stripe keys?") now matches the behaviour.</li><li>CSRF and `stripe.manage_settings` are unchanged.</li></ul> | `StripeWriteOnlySecretTest` case 4. Mutation-checked: fails without the mode-slot clearing. | **PASS** (was FAIL) |
| SEC-2 | Forms CAPTCHA secret is write-only, clearable and encrypted at rest | `plugins/forms/lib/FormsSpamGuard.php` (`resolveStoredSecret`, `captchaSecret`, `verifyCaptcha`), `plugins/forms/admin/edit.php` | <ul><li>A typed secret is stored as an `enc:v1` envelope. With no `APP_SECRET`, the save is refused with a field error and nothing plaintext is stored.</li><li>A blank field keeps the stored value byte-for-byte.</li><li>A legacy plaintext secret is upgraded on save, or left as-is if it cannot be encrypted.</li><li>The clear checkbox removes the secret.</li><li>The runtime decrypts before calling the provider. An undecryptable secret makes `verifyCaptcha()` fail closed and log a warning.</li></ul> | `FormsCaptchaSecretEncryptionTest` (6 tests, both trees; the no-`APP_SECRET` case runs in a child process). `FormsCaptchaSecretSaveTest` (integration: create, blank re-save, GET shows neither plaintext nor envelope, legacy upgrade, clear). Mutation-checked: fails if the editor stores the typed value. | **PASS** (was PARTIAL) |
| SEC-3 | Encryption failure is handled (Google Drive, Booking Google + Twilio, MCP AI key) | `plugins/backups/admin/settings.php`, `plugins/booking/admin/settings.php`, `plugins/mcp-gateway/admin/ai-settings.php` | `slate_encrypt_secret()` throws without `APP_SECRET`. The `try/catch` skips that one secret and flashes an error. The other fields still save, and no plaintext is stored. | `SystemSettingsAuditFixesTest` (static). No test simulates the failure. | **PARTIAL** |
| SEC-4 | A blank secret preserves the existing value | Stripe, Forms (above); Backups, Booking, MCP | Stripe and Forms are new and tested at runtime. Backups, Booking and MCP already behaved this way at base. | Stripe and Forms integration tests; the others are static | **PASS** (Stripe, Forms) / **PASS (static)** (rest) |
| SEC-5 | Archive directory denies HTTP access | `archive/.htaccess` (both trees, committed in `e9a1bdf`) | `Require all denied` (Apache 2.4). Nginx needs the equivalent rule. | `PluginLoaderArchiveBoundaryTest`: FAIL on `origin/main`, PASS now | **PASS** |
| SET-1 | Timezone has one owner | `admin/settings.php` | Only `save_datetime` writes `timezone`. The System tab shows it read-only. | `SystemSettingsAuditFixesTest` (whole branch scanned; fails on base) | **PASS (static)** |
| SET-2 | Permalink allow-list | `admin/settings.php` `save_permalinks` | Allow-list is identical to the radio values offered | `SystemSettingsAuditFixesTest` | **PASS (static)** |
| SET-3 | `start_of_week` stays within 0–6 | `admin/settings.php` `save_datetime` | Values out of range become `1` | `SystemSettingsAuditFixesTest` | **PASS (static)** |
| SET-4 | Backup retention stays within 1–365 | `plugins/backups/admin/settings.php` | Clamped on save; the input declares `min`/`max` | `SystemSettingsAuditFixesTest` | **PASS (static)** |
| SET-5 | Brand font and radius emitted as CSS tokens | `includes/ui_components.php` | Variables are emitted. Web fonts are not loaded. | `SystemSettingsAuditFixesTest` (static) | **PARTIAL** |
| AUD-1 | Coaching settings writes an audit event | `plugins/coaching/admin/settings.php` | `AuditLog::record('coaching.settings_updated')` | `SystemSettingsAuditFixesTest` (static) | **PASS (static)** |
| CLK-1 | Identity token expiry uses the database clock | `src/Services/Identity/IdentityStore.php` | Issue and consume both use the database clock | `IdentityStoreTest`, `ContactSeederTest`, `CustomerAuthParityTest` (both trees, licensing fixtures mirrored): FAIL on base, PASS now | **PASS** |
| T-1 | No production test seam | `src/Module/StudioBuilder/Render/Block/ModuleRenderers/PaymentButtonRenderer.php` | `$stripeReadyOverride` removed; the file is identical to `origin/main`. The real cause of the old failure was a missing `APP_SECRET` once the real `slate_sign()` was loaded; the test now defines it and verifies through `slate_sign()`. | `StudioBuilderPaymentBlocksTest` 8/8 alone and in the full run | **PASS** (was PARTIAL) |
| T-2 | No vacuous tests | `tests/unit/Phase7SlateKohevoIdentityTest.php` (both trees) | The react-site-bridge checks are removed: that plugin was never committed, so they could not run. | 18/18 (was 20, two vacuous) | **PASS** |
| T-3 | `SystemSettingsAuditFixesTest` checks shipped code | both trees | 9 static checks over production files. The Forms check now pins the editor to `FormsSpamGuard::resolveStoredSecret()`. | 9/9 per tree; 0/9 against the original HEAD source | **PASS (static)** |
| T-4 | POST probe arguments | `tests/fixtures/admin-page-post-probe.php` | The unlock flag is at `argv[6]`, because `argv[5]` is the tenant override. New CLI-only `PROBE_QUERY` sets `$_GET` (used to edit an existing form). | Probe-based tests pass | **PASS** |
| T-5 | Platform-admin negative test isolates authority | `tests/integration/PlatformAdminGateTest.php` | The negative upload-form case now runs with the plugin lock open, like the positive cases | 8/8 | **PASS** |
| I18N | New strings translated | `lang/fr.php` (both trees) | All new keys, including `forms_captcha_encryption_failed` | `php scripts/check-i18n.php`: OK | **PASS** |
| INV | Inventory is true to source | `audit/kohevo-settings-widget-inventory.csv` | 100 rows: 99 match source by key and 1 is marked "Not implemented". 38 false rows removed, with reasons. | `review-package/csv-verify.py` (strict) | **PASS** (keys) / **NOT VERIFIED** (descriptive columns) |
| WM | Widget matrix names real classes | `audit/kohevo-widget-test-matrix.md` | Regenerated from source: 47 renderer types plus 12 plugin SDK blocks, each referenced by at least one test | Extracted from code | **PASS** |
| X-1 | Out-of-scope cross-tree line | `02-licensing/admin/settings.php` | Guarded `default_language_studio_hint`, inert in licensing | — | NOTE |

## Open items found during verification (not fixed; see `remaining-risks.md`)

| ID | Finding | Status |
|---|---|---|
| R1 | SMTP OAuth secret and refresh token fall back to plaintext without `APP_SECRET` (`admin/oauth_callback.php:74-75`) | **FAIL** (pre-existing) |
| R2 | Multilang auto-translate API key is stored and read in plaintext | **FAIL** (pre-existing) |
| R3 | `mcp-gateway.ai_base_url` has no host validation (admin-only SSRF surface) | **FAIL** (pre-existing) |
| R4 | 02-licensing Booking and Forms plugins call `ModuleGuard`, which that tree lacks | **FAIL** (pre-existing) |

## Not run

Live Stripe, Twilio and Google calls; Playwright e2e; GitHub Actions CI.
