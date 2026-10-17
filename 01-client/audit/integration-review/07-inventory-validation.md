# Settings and widget inventory validation (integrated tree)

Checked on 2026-10-17 on `integration/secrets-and-audit`, using a standard CSV parser (Python `csv`).

## Settings inventory: `01-client/audit/kohevo-settings-widget-inventory.csv`

| Check | Result |
|---|---|
| Rows / columns | 100 rows plus header; 12 columns on every row; LF line endings |
| Each key exists in source (`review-package/csv-verify.py 01-client <out>`) | 99 VERIFIED, 1 NOT_IMPLEMENTED (`stripe_payment_field`, by design), **0 NOT_FOUND** |
| Encryption claims match the code (`inventory-claims-verify.py`) | 12 rows claim encryption; **12 verified**: the key is written in a source file that calls an encryption helper |
| Parity claims match the code | 69 "Identical"; **69 verified** in 02-licensing. Distribution: Identical 69, Client-Only 17, Both Trees 13, n/a 1 |
| History | The original 137 rows: 38 were moved to `kohevo-inventory-removed-rows.csv` and 1 was added (`autotranslate_api_key`), giving 100. The original claim that "66 of 137 rows don't exist in source" was resolved in the audit follow-up (corrected or removed). |

Rows corrected in this pass (8):

| Row | Correction |
|---|---|
| `smtp_oauth_client_secret`, `smtp_oauth_refresh_token` | Now encrypted and fail-closed (`SmtpOAuth::sealSecrets`, #102) |
| `ai_base_url` | Public https only, vetted on save and when used, pinned (#102) |
| `autotranslate_api_key` | Write-only and encrypted (#102) |
| `stripe_payment_field` | Parity "Identical" changed to "n/a" (not implemented in either tree) |
| `LICENSE_SERVER_URL` | Parity changed to "Client-Only": 02-licensing only mentions it in a comment |
| `OFFLINE_TOLERANCE_SECONDS` | Parity changed to "Client-Only"; bounds corrected from 14 days to `7 * 86400` (`CommercialLicenseWindow.php:71`) |
| `ALLOWED_MIMES` | Location corrected to `plugins/media-library/MediaLibrary.php:35`; values corrected to jpeg, png, webp, gif (it isn't `Services/Media/Media.php`, and it doesn't allow audio, video or PDF) |

## Widget and field matrix: `01-client/audit/kohevo-widget-test-matrix.md`

| Check | Result |
|---|---|
| Registered block types in source (renderer `type()` plus plugin `'type' => …`) | 60 |
| Registered types missing from the matrix | **0** (`portfolio.rakib_showcase` was added in this pass; no test references it) |
| Type IDs in rows that aren't registered | 0. Six rows are plugin-SDK rows, keyed by plugin slug, or "not implemented" rows that deliberately name nonexistent classes. |
| Renderer classes named in the matrix that don't exist | **0** (the original 10 phantom classes were removed in the audit follow-up) |
| "Test files" counts | All match a recount (`core.heading` was corrected from 61 to 62) |

Per-row output: `verification/widget-matrix-verification.csv` and `verification/inventory-claims-verification.csv`.

## Limits of this inventory

- It covers the options and blocks named in the CSV and the matrix, cross-checked against static source. It is **not** a proof that every runtime option is listed.
- Plugin settings built dynamically (for example loops over provider lists) and per-block style controls are summarised, not listed one by one. See `review-package/csv-validation.md` § "What a static inventory cannot enumerate".
- A "Test files" count shows that a test references the type ID. It does not show that every control, default or responsive behaviour is covered.
