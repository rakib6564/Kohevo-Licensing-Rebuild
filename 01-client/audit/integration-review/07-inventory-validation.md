# Settings and widget inventory validation (integrated tree)

Checked on 2026-10-17 against the **combined tree** `integration/final-check` (PR #102 + ModuleGuard + this branch), and re-run unchanged on `integration/release-check` `99ba13e` with identical results (99 VERIFIED, 1 NOT_IMPLEMENTED; 60/60 types; 12/12 encryption and 69/69 parity claims), using a standard CSV parser (Python `csv`). The inventory describes that combined release. On this branch alone, `autotranslate_api_key`'s encryption claim doesn't hold until PR #102 is merged.

## Settings inventory: `01-client/audit/kohevo-settings-widget-inventory.csv`

| Check | Result |
|---|---|
| Rows / columns | 100 rows plus header; 12 columns on every row; LF line endings |
| Each key exists in source (`review-package/csv-verify.py 01-client <out>`) | 99 VERIFIED, 1 NOT_IMPLEMENTED (`stripe_payment_field`, by design), **0 NOT_FOUND** |
| Encryption claims match the code (`inventory-claims-verify.py . <out>` on the combined tree) | 12 rows claim encryption; **12 verified**: the key is written in a source file that calls an encryption helper (`slate_encrypt_secret`, `slate_seal_posted_secrets`, `sealSecrets`, `resolveStored…`) |
| Parity claims match the code | 69 "Identical"; **69 verified** in 02-licensing. Distribution: Identical 69, Client-Only 17, Both Trees 13, n/a 1 |
| History | The original 137 rows: 38 were moved to `kohevo-inventory-removed-rows.csv` and 1 was added (`autotranslate_api_key`), giving 100. The original claim that "66 of 137 rows don't exist in source" was resolved in the audit follow-up (corrected or removed). |

Rows corrected in this pass (12):

| Row | Correction |
|---|---|
| `smtp_oauth_client_secret`, `smtp_oauth_refresh_token` | Now encrypted and fail-closed (`SmtpOAuth::sealSecrets`, #102) |
| `ai_base_url` | Public https only, vetted on save and when used, pinned (#102) |
| `autotranslate_api_key` | Write-only and encrypted (#102) |
| `stripe_payment_field` | Parity "Identical" changed to "n/a" (not implemented in either tree) |
| `LICENSE_SERVER_URL` | Parity changed to "Client-Only": 02-licensing only mentions it in a comment |
| `OFFLINE_TOLERANCE_SECONDS` | Parity changed to "Client-Only"; bounds corrected from 14 days to `7 * 86400` (`CommercialLicenseWindow.php:71`) |
| `ALLOWED_MIMES` | Location corrected to `plugins/media-library/MediaLibrary.php:35`; values corrected to jpeg, png, webp, gif (it isn't `Services/Media/Media.php`, and it doesn't allow audio, video or PDF) |
| `backups.google_client_secret`, `booking.google_client_secret`, `booking.twilio_token`, `mcp-gateway.ai_api_key` | Notes no longer say "save skips the secret" or "try/catch". Now: encrypt first, and nothing is saved if encryption fails (`b8a6fdc`) |

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

## Limits of this check (found by the full settings audit)

The 100-row inventory passes the checks above, which confirm each row's key, location and claims exist in source. They do not check that a key has a writer or that it covers every key. The full scan in `settings/settings-full-inventory.csv` found:

- the inventory covers 54 of 251 client keys and 54 of 222 central keys;
- `site_tagline` and `site_admin_email` are listed under Settings › General, but nothing writes them;
- `license_key` is the `LICENSE_KEY` environment variable, not a database setting;
- the Forms CAPTCHA secret and provider live in each form's `settings_json`, not the settings table;
- `business_hours`, `currency` and `default_language` are filed under plugins but are core keys written by `admin/settings.php`.

Use `settings/settings-full-inventory.csv` as the complete inventory; the 100-row file remains as the reviewed subset.

## Mechanical check of the full inventory (`settings/settings-full-inventory.csv`, 482 rows)

**What was checked.** `settings/tools/verify-full-inventory.py` checks every row against the tree the audit read (`71d4e83`):
- the owner file exists, and the key appears within 6 lines of the cited line;
- each reader `file:line` exists and mentions the key there.

The full output is in `settings/full-inventory-verification.txt`.

**Owner citations:**

| Result | Rows |
|---|---|
| Key at the cited line | 336 |
| Key in the file, but the line moved | 33 |
| Key built in code from a prefix, with its name at the cited line | 25 |
| Key built in code, with its name elsewhere in the file | 8 |
| Dynamic key, not checkable | 6 |
| No owner recorded (keys that are read but never written) | 46 |
| Not found by text search | 28 |

**Reader citations:**

| Result | Count |
|---|---|
| At the cited line | 779 |
| Line moved | 157 |
| Built in code | 107 |
| Not found by text search | 84 |

**The rows not found by text search** were checked by hand, one per pattern. All are keys built at run time, so the citations are right:
- SMTP keys read through `'smtp_' . $prefix . $k` (`Mailer.php:100-101`);
- Stripe slots through `'stripe-payment.' . $prefix . '_' . $name` (`StripePaymentAPI.php:79`);
- the Multilang key through `MLT_AutoTranslate::API_KEY_SETTING`;
- MCP rate limits through a variable `$settingKey`;
- `booking.cap.*` through `PluginLoader::setCapabilityEnabled()`.

**Other checks:**
- **CSV shape:** parses with Python `csv`; all 482 rows have 14 fields.
- **Classification:** 342 normal, 90 sensitive, 41 secret, 9 mixed.
- **Disposition:** 307 used; 58 internal state; 52 display-only; 46 read but never written; 10 dead; 9 dynamic.
