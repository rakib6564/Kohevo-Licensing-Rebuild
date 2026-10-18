# Remaining risks and open items

> **Current status, 2026-10-17.** The text below this block is **historical**: it was written for `audit/settings-secrets-hardening` alone. The current state has been tested as one combined tree, on the local test-only branch `integration/final-check`, which merges three branches onto `origin/main` `ba4c9a5`:
>
> | Branch | Head | What it holds |
> |---|---|---|
> | PR #102 `fix/secret-handling-gaps` | `6fed518` | SMTP OAuth secrets, Multilang translate key, AI base URL (SSRF) |
> | `fix/licensing-module-guard` | `188808b` | Central `ModuleGuard`, per-tenant plan enforcement, plan CLIs |
> | `audit/settings-hardening-next` | this branch | The audit work, encrypt-first saves, `APP_SECRET` rotation tool, test hardening |
>
> Test results and the risk register are in `01-client/audit/integration-review/` (start with `README.md` and `10-final-release-readiness.md`).
>
> **Status of R1–R8:**
> - **R1–R3 fixed** by PR #102.
> - **R4 fixed** on `fix/licensing-module-guard`: 60 calls in 56 central files, per-plan gating, plan tooling.
> - **R5 fixed** on this branch: `bin/rekey-secrets.php` re-encrypts stored secrets after an `APP_SECRET` rotation.
> - **R6 fixed** on this branch: Backups, Booking and AI now encrypt every secret first and save nothing on failure.
> - **R7 and R8 remain open.**
>
> The current register is `integration-review/05-remaining-risks.md`.

**Date:** 2026-10-17. **Checkout:** `~/Downloads/Fahim/KOHEVO-STUDIO`, branch `audit/settings-secrets-hardening`. Audit commit `e9a1bdf` sits on `origin/main` `ba4c9a5`, re-fetched today with no divergence; this pass's follow-up changes are uncommitted on top of it. Nothing has been pushed, merged or deployed.

## Fixed in this pass (were open)

| Item | Fix | Evidence |
|---|---|---|
| Forms CAPTCHA secret stored in plaintext | Encrypted at rest (`enc:v1`) via `FormsSpamGuard::resolveStoredSecret()`. A save is refused when encryption is unavailable. A blank field keeps the stored value. Legacy plaintext is upgraded on save. Explicit clear supported. The runtime decrypts in `captchaSecret()`, and an undecryptable secret fails closed in `verifyCaptcha()`. Both trees. | `FormsCaptchaSecretEncryptionTest` (6 unit tests ×2 trees: new write, blank keep, legacy upgrade, clear, decrypt failure, no `APP_SECRET` in a child process); `FormsCaptchaSecretSaveTest` (integration, real editor POST/GET; fails if the editor stores plaintext) |
| Stripe "Disconnect" left `test_*`/`live_*` keys in place | `clear_keys` now blanks the publishable key, secret key and webhook secret in both modes plus the legacy slots, and writes the `stripe.keys_cleared` audit event. Countries and toggles are untouched. The confirmation text already said "Clear ALL Stripe keys". CSRF and `stripe.manage_settings` are unchanged. | `StripeWriteOnlySecretTest` Disconnect case; fails 3/4 when the mode-slot clearing is removed |
| Production test seam `PaymentButtonRenderer::$stripeReadyOverride` | Removed; the renderer is identical to `origin/main`. Root cause of the old order-dependent failure: `APP_SECRET` was undefined once the real `includes/helpers.php` was loaded. The test now defines it and signs and verifies through `slate_sign()`. | `StudioBuilderPaymentBlocksTest` 8/8 alone and in the full unit run |
| Vacuous react-site-bridge tests | Removed: that plugin has never been committed to this repository. They are no longer counted as passes. | `Phase7SlateKohevoIdentityTest` 18/18 (was 20, two of them vacuous) |
| Negative upload-form test ran with the plugin lock closed | Now runs with the lock open, so only platform authority differs | `PlatformAdminGateTest` 8/8 |
| Licensing clock-skew test fixtures | The client fixture fix was mirrored to `02-licensing/tests/integration/{ContactSeeder,CustomerAuthParity}Test.php` | 3 licensing failures fixed |

## Still open

| # | Risk | Where | Status / next step |
|---|---|---|---|
| R1 | **The SMTP OAuth client secret and refresh token are stored in plaintext when encryption is unavailable** (`$enc ? slate_encrypt_secret(...) : ...`). | `admin/oauth_callback.php:74-75` (both trees) | Open, pre-existing. Make it fail closed like Stripe: refuse to complete the connection without `APP_SECRET`. |
| R2 | **The Multilang auto-translate API key is stored and read in plaintext.** | `plugins/multilang-translate/includes/AutoTranslate.php:143` | Open, pre-existing. Encrypt on save and decrypt on read, as `mcp-gateway.ai_api_key` does. |
| R3 | **`mcp-gateway.ai_base_url` has no scheme/host validation**, so an admin can point the AI client at internal hosts (an SSRF surface limited to admins). The earlier inventory falsely claimed strict SSRF validation. | `plugins/mcp-gateway/admin/ai-settings.php:27-33`, `AiProviderClient.php:58` | Open, pre-existing. Require https and reject private/loopback hosts. |
| R4 | **`ModuleGuard` is missing from the 02-licensing tree**, while its Booking and Forms plugins call it. Those paths fatal there. | `02-licensing/plugins/booking/BookingApiHandler.php:19`, `BookingAPI.php:2368`, `plugins/forms/public/router.php:19`, … | Open, pre-existing (also on `origin/main`). Decide whether those plugins should run in the licensing tree at all, then port the class or remove the plugins. |
| R5 | Rotating `APP_SECRET` makes every stored secret unreadable. A blank re-save keeps the unreadable envelope. Forms CAPTCHA now fails closed (it rejects submissions and logs a warning) until the secret is re-entered. | `includes/helpers.php` | By design; no re-encryption tool exists. |
| R6 | Backups, Booking and MCP skip only the secret that fails to encrypt and save the rest of the form. Stripe and Forms refuse the whole save. | settings pages | Accepted inconsistency; no plaintext is stored either way. |
| R7 | Brand fonts change CSS variables, but the font files are not loaded. | `includes/ui_components.php` | Cosmetic |
| R8 | The licensing integration suite cannot run as one process: `licplug_teardown()` and `licplug_ensure_schema()` are defined only in other test files, so files depend on run order. | `02-licensing/tests/integration/*` | Open, pre-existing. Move the shared helpers into a support file. |

## Test caveats that remain

- `SystemSettingsAuditFixesTest` is a static check of the shipped source. The behaviour of the Stripe and Forms secret paths is covered end to end; the other settings fixes (permalinks, start of week, retention, timezone, brand tokens, coaching audit) have static checks only.
- No test simulates an encryption failure on the Backups, Booking or MCP pages.
- The entitlement, white-label and dashboard client tests seed the signed licence cache they then assert against. That is correct under Phase 13, but the test names are stale.

## Inventory

The CSV has 100 rows: 99 match source by key and 1 is marked "Not implemented". 38 rows that described non-existent settings were moved to `kohevo-inventory-removed-rows.csv` with the reason for each. Only the key and secret-handling columns were verified row by row. The other descriptive columns are NOT VERIFIED wholesale; one spot check (`ai_base_url`) found and corrected a false claim.

## Not run

- Live Stripe, Twilio and Google calls
- Playwright e2e (`npm run test:e2e`)
- GitHub Actions CI. Nothing has been pushed; CI uses PHP 8.3, while local runs used 8.5.9.

## Rollback

There are no schema changes or migrations. To roll back, revert the commit(s). Stripe and Forms secrets written by this code are standard `enc:v1` envelopes. After a rollback, `origin/main` code reads Stripe envelopes correctly, but it does **not** decrypt Forms CAPTCHA secrets: it would send the envelope to the provider, and those forms would fail CAPTCHA until each secret is re-entered. Re-enter the CAPTCHA secrets after any rollback.
