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
> When this PR is opened after #102 has merged, replace the "Remaining risks" items for SMTP OAuth, the Multilang key and the AI base URL with "fixed in #102", and refresh the test numbers from `integration-review/04-test-results.md`.

## Purpose and scope

This PR hardens how the admin settings pages handle secrets and bounded values, in both `01-client` and `02-licensing`. It also brings stale tests in line with the current architecture. The base is `origin/main` (`ba4c9a5`). It does not include the unrelated Field value dropdown work.

The first audit pass (`e9a1bdf`) was independently verified. This PR adds a follow-up that:
- fixes the three remaining issues that verification found;
- removes test-only hooks from production code and tests that could never run;
- corrects the audit inventory against the source.

## Security and reliability changes

**Stripe**
- The secret key and webhook signing secret are write-only.
- New secrets are encrypted at rest; if encryption is unavailable, the save is refused instead of silently storing plaintext.
- Legacy plaintext keys are upgraded on save.
- **"Disconnect" now clears the test, live and legacy key slots** and records `stripe.keys_cleared`. Before, the test and live keys survived "Clear ALL Stripe keys".

**Forms CAPTCHA secret**
- **Now encrypted at rest** (`enc:v1`) and write-only.
- A blank field keeps the stored value, and an explicit checkbox clears it.
- If encryption is unavailable, the save is refused.
- The public form decrypts the secret before verifying, and fails closed if it can't.

**Google Drive, Booking (Google, Twilio) and MCP AI key**
- An encryption failure no longer causes a 500: the secret is skipped and an error is shown.
- Plaintext is never stored.

**Identity tokens**
- Expiry is now stamped on the database clock, matching `consumeToken()`. Before, a PHP/MySQL timezone gap expired tokens immediately.

**Archive directory**
- `archive/.htaccess` denies direct HTTP access in both trees.

**Settings**
- Permalink allow-list.
- `start_of_week` limited to 0–6; backup retention limited to 1–365.
- Timezone is written only by the Date & Time tab.
- Brand font and radius are emitted as CSS variables.
- Coaching settings saves are audit-logged.

**No production test hooks**
- The `PaymentButtonRenderer::$stripeReadyOverride` seam is removed. The renderer is unchanged from `main`.

## Test results (local, PHP 8.5.9 + MySQL; base = clean copy of `origin/main`)

| Suite | `origin/main` | This branch |
|---|---|---|
| Client unit | 977/983 | **996/996** |
| Licensing unit | 426/430 | **443/443** |
| Smoke, both trees | 21/21, 21/21 | **21/21, 21/21** |
| Client integration | 608/631 | **636/636** |
| Studio Builder UI | 368/368 | **368/368** |
| Licensing CI command set (`ci.yml`) | all pass | **all pass** |
| Licensing integration, file by file | 349/403 | **354/403** |
| `scripts/check-i18n.php`, `php -l`, `git diff --check` | — | pass |

Each test counted once: **2,839 passed, 49 failed, 0 skipped** out of 2,888.

New tests:
- `SystemSettingsAuditFixesTest` (both trees): checks the shipped source.
- `FormsCaptchaSecretEncryptionTest` (both trees, 6 tests), including a child process with no `APP_SECRET`.
- `FormsCaptchaSecretSaveTest`: drives the real form editor.
- `StripeWriteOnlySecretTest`: 4 tests through the real admin page, including Disconnect.

The key new tests were mutation-checked: each one fails when its fix is removed.

## Known failures and suites not run

- **Licensing integration still fails: 49 per-file failures, and the documented single-process runner fatals.** Every failing test also fails on `origin/main`; this PR introduces none and fixes 5. The remaining 49:
  - **22 (`CentralLicensingFoundationTest`):** fail only when the file runs alone. It depends on a helper in another file, and CI's combined command passes 27/27.
  - **22 (Booking and Forms):** the licensing tree calls a `ModuleGuard` class that only exists in `01-client`.
  - **5 (`LicenseGate`, `Dashboard`):** stale pre-Phase-13 tests in the licensing tree.
- **Not run:** live Stripe, Twilio and Google calls; Playwright e2e; GitHub Actions CI.

## Migration and compatibility

No schema changes or migrations.
- Existing encrypted secrets keep working unchanged.
- Legacy plaintext Stripe and Forms CAPTCHA secrets are re-encrypted on the next save of that page. This needs `APP_SECRET`; without it they are left exactly as they are.
- Rotating `APP_SECRET` makes stored secrets unreadable, as before. Forms CAPTCHA then rejects submissions and logs a warning until the secret is re-entered.

## Remaining risks (open, found during verification; details in `01-client/audit/review-package/remaining-risks.md`)

- SMTP OAuth client secret and refresh token fall back to plaintext when `APP_SECRET` is missing (`admin/oauth_callback.php`).
- The Multilang auto-translate API key is stored in plaintext.
- `mcp-gateway.ai_base_url` has no host validation, an SSRF surface open to admins only.
- The licensing tree's Booking and Forms plugins call the missing `ModuleGuard`.

## Rollback

Revert the commits. Stripe envelopes stay readable by `main`. **Forms CAPTCHA secrets saved by this branch are envelopes that `main` does not decrypt**: after a rollback, those forms fail CAPTCHA until each secret is re-entered.

## Audit evidence

- Review package: `01-client/audit/review-package/`. It holds the test results, verification matrix, CSV validation, remaining risks, the patches against `origin/main` and `e9a1bdf`, and test logs.
- Local bundle (gitignored, not committed): `01-client/audit/Kohevo_Complete_System_Plugin_Widget_Audit_Final_Bundle.zip`. It was rebuilt on 2026-10-17 from the integrated tree. An archive can't contain its own checksum, so the file count, size and SHA-256 are recorded in the release report delivered with it. The earlier build (56 files, SHA-256 `7d471a15…eaf3`) is superseded.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
