# Remaining risks and open items

**Date:** 2026-10-17. **Checkout:** `~/Downloads/Fahim/KOHEVO-STUDIO`, branch `feat/field-value-dropdown`, HEAD `1ae3847`. Nothing has been committed, pushed or deployed.

This replaces the earlier risk list. That list claimed test coverage that does not exist (for example "`StripeWebhookRaceTest.php` passing 9/9 assertions" as evidence of live webhook handling) and was silent on the items below.

## Release blockers for this change set

1. **Not committed. The archive sentinel is untracked.** `01-client/archive/.htaccess` and `02-licensing/archive/.htaccess` have never been in git. On any fresh checkout `PluginLoaderArchiveBoundaryTest` fails and the directory is unprotected until these files are committed.
2. **The branch is wrong for this work.** The changes sit uncommitted on `feat/field-value-dropdown`, a feature branch that already carries an unrelated pushed commit (`1ae3847`, Field value dropdown). Commit them on a dedicated branch instead (see `diff-summary.md` → "Commit plan").
3. **The 02-licensing integration suite is not runnable as one process.** `php 02-licensing/tests/integration/run.php` fatals with `Cannot redeclare function licplug_teardown()` (`DynamicModuleSystemTest.php` and `LicensingPluginSchemaTest.php`). This happens identically at HEAD, so this change set did not cause it. As a result the earlier report's totals never included that suite. CI runs selected licensing integration files one at a time. The per-file results are in `test-results.md`.

## Security gaps remaining

| # | Risk | Where | Status |
|---|---|---|---|
| R1 | **The Forms CAPTCHA secret is stored in plaintext** inside each form's settings JSON. The UI is now write-only, but anyone with read access to the database still sees the secret. | `plugins/forms/lib/FormsSpamGuard.php:94`, `:236` | Open. Next step: encrypt it in `admin/edit.php` with `slate_encrypt_secret()` and decrypt it where `FormsSpamGuard` builds the verification request. `slate_decrypt_secret()` passes plaintext through unchanged, so no migration is needed. |
| R2 | **Stripe "Clear keys" leaves the mode-prefixed keys in place.** `clear_keys` blanks only `stripe-payment.{secret_key,…}`. `keyByMode()` reads `test_*`/`live_*` first, so the integration remains configured after a "clear". | `plugins/stripe-payment/admin/settings.php:118-124` | Open (this existed at HEAD). Next step: also blank `test_*` and `live_*` for `publishable_key`, `secret_key` and `webhook_secret`, and add a probe test. |
| R3 | **Partial saves on encryption failure.** Backups, Booking and MCP skip only the secret that failed to encrypt. The other fields on the form are still saved, and the user sees an error flash. Stripe refuses the whole save. Neither path stores plaintext, but the two behave inconsistently. | the three settings pages | Accepted, documented. |
| R4 | **`APP_SECRET` rotation makes every stored secret unreadable.** `slate_decrypt_secret()` returns `null` when the key changes. Stripe, Booking, Google Drive, Twilio, MCP and SMTP OAuth then behave as unconfigured. A blank re-save keeps the unreadable envelope, so the admin must re-enter each secret. There is no re-encryption tool. | `includes/helpers.php:545-556` | Open (by design). Next step: add a CLI that takes the old and new secret and re-wraps every `enc:v1:` value. |
| R5 | **A test seam was added to production code.** `PaymentButtonRenderer::$stripeReadyOverride` is a public static mutable flag. Nothing in production sets it, but `StudioBuilderPaymentBlocksTest` no longer exercises the real `StripePaymentAPI::isConfigured()` check. | `src/Module/StudioBuilder/Render/Block/ModuleRenderers/PaymentButtonRenderer.php:22,64` | Reviewer decision. |
| R6 | **Probe fixtures can bypass the plugin lock.** `PROBE_UNLOCK_PLUGINS`/`argv[6]` sets `plugins_unlocked_until` in a test session. This is CLI-only (`PHP_SAPI !== 'cli'` → 404) and test-only. | `tests/fixtures/admin-page{,-post}-probe.php` | Acceptable. |
| R7 | **Brand fonts are not loaded.** Choosing Plus Jakarta, Outfit, Playfair or Roboto changes `--font-sans` and `--font-display`, but no font file is loaded, so the browser falls back to the system stack. | `includes/ui_components.php` | Cosmetic, open. |

## Test-quality caveats

The tests now pass, but these caveats limit what that proves. Details are in `verification-matrix.md`.

- `Phase7SlateKohevoIdentityTest` (both trees): the react-site-bridge assertions now pass vacuously. That plugin was never tracked.
- The entitlement, white-label and dashboard tests seed the exact signed entitlements they then assert. That is correct under Phase 13, but the test names are stale.
- `SystemSettingsAuditFixesTest` is a static source check: it reads the shipped files and does not execute the pages. Each of its 9 tests fails against the HEAD source. Only the Stripe write-only path has an end-to-end test (`StripeWriteOnlySecretTest`).
- No test simulates an encryption failure (missing `APP_SECRET`) on any settings page.

## Inventory and report accuracy

- 66 of the 137 inventory rows name keys that do not exist in the source (`csv-validation.md`).
- `kohevo-widget-test-matrix.md` cites 10 classes that do not exist.
- The earlier "2,473 / 25 resolved" totals were wrong (see `test-results.md`).

Verification notes were added at the top of each affected document. The documents were not rewritten.

## Not run or not verifiable here

- **NOT RUN:** live Stripe API verification (`stripe_verify_secret`), webhook delivery from Stripe, Twilio SMS, the Google OAuth consent flow, and Playwright e2e (`npm run test:e2e`).
- **NOT RUN:** CI on GitHub. Nothing has been pushed. Local PHP is 8.5.9, while CI uses 8.3.
- **Environment:** the client integration suite needs `01-client/vendor/` (Composer, untracked). Without it the SMTP OAuth test fails. This is a local-setup issue, not a code change.

## Rollback

Every change is confined to PHP pages, test files and docs. There are no schema changes or migrations.

- To roll back after merge, revert the commit.
- Stripe secrets written by the new code are standard `enc:v1:` envelopes, which HEAD code also reads, so rolling back does not strand any stored credential.
- Values the old System tab had written to `timezone` remain valid.
