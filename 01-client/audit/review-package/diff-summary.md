# Diff summary and commit plan

**Base:** `origin/main` `ba4c9a5` (fetched 2026-10-17; the branch is 0 behind and 1 ahead).
**Branch:** `audit/settings-secrets-hardening`
- Committed: `e9a1bdf`, the first audit change set.
- Uncommitted: the follow-up changes from this verification pass.

**Patches:**
- `audit-changes.patch`: the whole branch (committed + uncommitted + new files) against `origin/main`. It applies cleanly to `ba4c9a5`.
- `followup-changes.patch`: only the uncommitted follow-up. It applies cleanly to `e9a1bdf`.

The Field value dropdown commit (`1ae3847`) is **not** included. No path overlaps with it; it is referred to only by name in earlier audit text.

## Production changes on the branch (both trees unless noted)

| File | Change | Where |
|---|---|---|
| `plugins/stripe-payment/admin/settings.php` | <ul><li>Secret key and webhook secret are write-only.</li><li>Encryption fails closed: the save is refused.</li><li>Legacy plaintext is upgraded on save.</li><li>**Disconnect clears the test, live and legacy keys and writes an audit event.**</li></ul> | e9a1bdf + **follow-up** |
| `plugins/forms/lib/FormsSpamGuard.php` | **New** `resolveStoredSecret()` and `captchaSecret()`. `verifyCaptcha()` decrypts the secret and fails closed when it cannot. | **follow-up** |
| `plugins/forms/admin/edit.php` | <ul><li>CAPTCHA secret is write-only, with a clear checkbox.</li><li>**Stored encrypted.**</li><li>Encryption failure blocks the save with a field error.</li></ul> | e9a1bdf + **follow-up** |
| `plugins/backups/admin/settings.php`, `plugins/booking/admin/settings.php`, `plugins/mcp-gateway/admin/ai-settings.php` | Encryption failure is caught and the secret is skipped. Retention is limited to 1..365. | e9a1bdf |
| `admin/settings.php` | Permalink allow-list, `start_of_week` 0..6, timezone owned by Date & Time only. The licensing tree also gets the inert Studio hint. | e9a1bdf |
| `includes/ui_components.php` | Brand font and radius CSS variables | e9a1bdf |
| `plugins/coaching/admin/settings.php` | Audit event on save | e9a1bdf |
| `src/Services/Identity/IdentityStore.php` | Token expiry stamped on the database clock | e9a1bdf |
| `admin/partials/header.php` | Name fallback | e9a1bdf |
| `archive/.htaccess` (new) | `Require all denied` | e9a1bdf |
| `lang/fr.php` | French strings for all new UI text | e9a1bdf + **follow-up** |
| `src/.../PaymentButtonRenderer.php` (client) | The audit's `$stripeReadyOverride` was **removed** in the follow-up. The net diff against `origin/main` is empty. | — |

## Test changes

- **New:**
  - `SystemSettingsAuditFixesTest` (both trees, static, 9 tests)
  - `StripeWriteOnlySecretTest` (client integration, 4 tests)
  - `FormsCaptchaSecretEncryptionTest` (both trees, 6 tests)
  - `FormsCaptchaSecretSaveTest` (client integration, 1 test)
- **Follow-up corrections:**
  - Payment-blocks test: no seam; signs and verifies through `slate_sign()`.
  - react-site-bridge tests removed (both trees).
  - `PlatformAdminGateTest` negative case runs with the plugin lock open.
  - `PluginActivationAuthorizationTest` docs and skip text updated.
  - POST probe gains `PROBE_QUERY`.
  - Licensing `ContactSeederTest` and `CustomerAuthParityTest` get the client clock fixture.

Per-file classification is in `changed-files.txt`, and per-claim status in `verification-matrix.md`.

## Follow-up commit plan (prepared, NOT executed)

```bash
cd ~/Downloads/Fahim/KOHEVO-STUDIO
git add -A -- 01-client 02-licensing \
  ':!01-client/vendor' ':!02-licensing/vendor' ':!01-client/composer.lock' ':!02-licensing/composer.lock'
git diff --cached --check
git diff --cached --stat
git commit -F 01-client/audit/review-package/commit-message.txt
```

`prototype-*/` sits outside `01-client` and `02-licensing`, so the pathspec never touches it. `*.log` and `*.zip` stay out because they are gitignored.

**Before pushing:**
- Re-run `git fetch origin && git rev-list --left-right --count origin/main...HEAD`.
- If `origin/main` has moved, decide between rebasing and merging before opening the PR. Do not force-push a branch that someone else has fetched.

**Push and PR (requires your go-ahead):**
```bash
git push -u origin audit/settings-secrets-hardening
gh pr create --base main --head audit/settings-secrets-hardening --title "fix(settings): write-only, encrypted secrets and bounded settings" --body-file 01-client/audit/review-package/pr-description.md
```
