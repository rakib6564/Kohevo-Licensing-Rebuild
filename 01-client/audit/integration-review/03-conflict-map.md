# Branch overlap and conflict map

The comparison covers three sides:

- the audit work: `e9a1bdf` plus its uncommitted follow-up, taken as snapshot `8788f2c`; 88 paths against `origin/main`;
- `fix/secret-handling-gaps` at `6fed518`; 28 paths;
- `fix/licensing-module-guard` at `08f3b99`; 3 paths.

I ran a trial merge with `git merge-tree --write-tree 8788f2c 6fed518`, then did the real merges on the `integration/secrets-and-audit` branch.

## Overlapping paths

| Path (both trees) | Audit change | Secret-handling change | Git result | Resolution in integration | Semantic check |
|---|---|---|---|---|---|
| `lang/fr.php` | Adds 11 keys in the client tree (12 in the licensing tree): `forms_captcha_*`, `encryption_failed`, `stripe_*`, `manage_in_datetime`, `timezone_system_hint`, and in the licensing tree also `default_language_studio_hint`. It also adds `leave_blank_to_keep` and `clear_saved_secret`. | Adds 6 keys, including the same `leave_blank_to_keep` and `clear_saved_secret` with identical text. | **CONFLICT**, appended at the same end-of-array spot | Kept both blocks by hand. The two shared keys appear once, from the secret-handling block. No key was dropped. | `php -l` is clean. `check-i18n`: client 0 missing, licensing 0 missing. |
| `plugins/mcp-gateway/admin/ai-settings.php` | Wraps the AI key's `slate_encrypt_secret()` in try/catch and shows `encryption_failed`. | Vets the URL; `if ($flash === null)` guards every write. | Auto-merged | none needed | Checked by reading the merged file. The URL vetting runs first; if it refuses, nothing is written. If it passes, the URL and model are written and key encryption is caught. Remaining issue: if only the key fails to encrypt, the URL and model are still saved (see R5). |
| `01-client/tests/fixtures/admin-page-post-probe.php` | Moves the unlock flag to `argv[6]`, sets `$_GET` from `PROBE_QUERY`. | Sets `$_REQUEST = array_merge($_GET, $_POST)` after `$_POST`. | Auto-merged | none needed | `$_REQUEST` now follows the `PROBE_QUERY` `$_GET`. All client integration tests pass, 641/641. |

## Shared code without textual overlap

| Area | Note |
|---|---|
| `includes/helpers.php` (`slate_encrypt_secret`, `slate_decrypt_secret`) | Neither branch changes it. Both use the same `enc:v1:` contract and the same "throws when `APP_SECRET` is missing" behaviour. |
| Secret-handling tests | The two sets of test files don't overlap: the audit adds Forms, Stripe and SystemSettings tests; the fix adds SMTP, MLT, AI and SecretHandlingGaps tests. They share `admin-page-post-probe.php`; see above. |
| `SystemSettingsAuditFixesTest` (static checks on the source) | It doesn't inspect `ai-settings.php`, `oauth_callback.php`, `SmtpOAuth.php` or `AutoTranslate.php`, so it passes after integration. |
| Audit reports and review package | Only the audit side has them. On the integration branch, four inventory rows (`smtp_oauth_client_secret`, `smtp_oauth_refresh_token`, `ai_base_url`, `autotranslate_api_key`) were updated from "open risk" to "fixed". The main checkout's CSV is unchanged and stays accurate for the audit branch alone. |
| `fix/licensing-module-guard` | It touches only `02-licensing/src/Services/Licensing/ModuleGuard.php` (new), `02-licensing/src/compat/aliases.php` and a new test. There is no overlap with either other branch. |

## Expected conflicts per merge order

- If `fix/secret-handling-gaps` lands first: the audit branch conflicts only in the two `lang/fr.php` files.
- If the audit branch lands first: the same conflicts, seen from the other side.
- `fix/licensing-module-guard` merges cleanly in any order.

## Final merge (2026-10-17): `integration/final-check`

These merges are in order onto `origin/main` `ba4c9a5`:
1. PR #102 at `6fed518`.
2. `audit/settings-hardening-next` at `197fe44`, then again at `240caef` and `17f3a43`.
3. `fix/licensing-module-guard` at `188808b`.

| Path (both trees) | PR #102 intent | Hardening intent | Resolution |
|---|---|---|---|
| `plugins/mcp-gateway/admin/ai-settings.php` | Refuse the whole save if the base URL fails `vetBaseUrl()`; `if ($flash === null)` guards the writes | Encrypt the new key first with `slate_seal_posted_secrets()`; refuse the whole save if that fails | One chain: CSRF → URL vetting → seal the key → write everything, or nothing. Identical in both trees. Tested by `SecretHandlingGapsTest` (refused URL writes nothing) and `SettingsEncryptFirstTest` (no `APP_SECRET`: nothing written; with it: saved and encrypted). |
| `lang/fr.php` | 6 keys | the audit keys plus `settings_not_saved_encryption` | Kept both sets; the shared keys `leave_blank_to_keep` and `clear_saved_secret` appear once (identical text). The translation gate passes. |
| `02-licensing/tests/support/` | none | `licensing-plugin.php` | No conflict: the ModuleGuard branch adds `tenant-plan.php` to the same folder. |
