# Inventory CSV validation

**File:** `01-client/audit/kohevo-settings-widget-inventory.csv` (not modified by this verification)
**Checked:** 2026-10-17 on branch `feat/field-value-dropdown`, HEAD `1ae3847`
**Parser:** the Python 3 standard-library `csv` module

This file replaces an earlier version. That version gave the wrong domain breakdown and the wrong disposition values, and it called the inventory "exhaustive".

## Result

| Check | Result | Status |
|---|---|---|
| Header | 12 columns, exactly `Domain, Component, Setting_Or_Widget, Type, Storage_Key, Input_Validation, Bounds_Enforced, Secret_Handling, Owner_Tab, Parity_Status, Disposition, Notes` | PASS |
| Data rows | 137 (138 physical lines including the header). This matches the reported 137. | PASS |
| Row width | Every row has 12 fields. 0 malformed. | PASS |
| Quoting | The file parses cleanly. No quoting fix was needed, so the file was left unchanged. | PASS |
| Encoding | UTF-8 without BOM. Originally CRLF line endings; normalized to LF in this pass so `git diff --check` passes. A parse before and after gave identical rows. | PASS |
| Duplicates | 0 duplicate `(Domain, Component, Setting_Or_Widget)` keys. 0 identical rows. | PASS |
| Blank rows | 0 | PASS |
| Mandatory fields | No column has an empty cell. | PASS |
| **Content traced to source** | **60 VERIFIED, 11 PARTIAL, 66 NOT_FOUND** (details below) | **FAIL** |
| Exhaustiveness | Not demonstrated; see "Coverage limits" | NOT VERIFIED |

## Actual distribution

The earlier report listed "Core Admin 22 / Platform Admin 16 / Plugins 71 / Studio 15+2 / Forms 11". The file actually contains:

| Domain | Rows | VERIFIED | PARTIAL | NOT_FOUND |
|---|---|---|---|---|
| `Plugin` | 76 | 21 | 6 | 49 |
| `Core: System` | 33 | 15 | 4 | 14 |
| `Widget: Studio` | 17 | 14 | 1 | 2 |
| `Field: Forms` | 11 | 10 | 0 | 1 |
| **Total** | **137** | **60** | **11** | **66** |

`Disposition` values present: `Keep/Core` (132), `Keep/Advanced` (4), `Deprecate/Docs Corrected` (1). The earlier report's vocabulary ("Retained, Bounded, Hardened, Canonicalized, Deprecated") does not appear in the file.

## Row-to-source trace method

Rerun with: `python3 -I 01-client/audit/review-package/csv-verify.py 01-client 01-client/audit/review-package/csv-row-verification.csv`

The script searches every `.php`, `.js`, `.mjs` and `.json` file under `01-client/{admin,includes,src,plugins,api,public}`. It skips `tests`, `lang`, `vendor` and `node_modules`.

- **VERIFIED**: the key appears as a quoted literal (`'key'` or `"key"`). Plugin rows also match `'<plugin>.key'`.
- **PARTIAL**: the key appears only as a case-insensitive substring of other code. Treat the row as approximate.
- **NOT_FOUND**: the key does not appear anywhere in the shipped source.

Per-row output: `csv-row-verification.csv`.

### NOT_FOUND rows (66)

The source does not store any of these settings under the key the row gives:

- **Core:** `default_role`, `users_can_register`, `membership_requires_approval`, `brand_primary_color`, `brand_secondary_color`, `brand_logo_url`, `brand_favicon_url`, `session_idle_timeout`, `mfa_enforced`, `login_lockout_attempts`, `login_lockout_duration`, `mail_driver`, `smtp_username`, `smtp_oauth_tokens`
- **backups:** `schedule_frequency`, `schedule_time`, `gdrive_client_id`, `gdrive_client_secret`, `gdrive_refresh_token`, `include_database`, `include_uploads`. The real keys include `backups.google_client_id` and `backups.google_client_secret`.
- **booking:** `slot_interval_minutes`, `buffer_minutes`, `max_future_days`, `deposit_mode`, `deposit_amount`, `google_calendar_enabled`, `google_tokens`, `twilio_from`
- **coaching:** `gated_plans`, `macro_protein_pct`, `macro_carb_pct`, `macro_fat_pct`, `client_diary_visibility`, `audit_logging`
- **forms:** `sp_captcha_sitekey` (the real name is `sp_captcha_site_key`), `sp_honeypot_enabled`, `sp_rate_limit_rpm`, `notification_emails`, `stripe_payment_field`
- **licensing:** `central_api_url`
- **mcp-gateway:** `mcp_enabled`, `ai_provider`, `allowed_tools`
- **media-library:** `allowed_mime_types`, `max_upload_size_mb`, `track_usage`, `image_variants`
- **membership:** `grace_period_days`, `stripe_sync_enabled`, `qr_attendance_enabled`, `wallet_ledger_enabled`
- **multilang-translate:** `active_languages`, `crawler_protection`, `url_prefix_mode`
- **stripe-payment:** `stripe_mode` (the real key is `stripe-payment.mode`)
- **studio-builder:** `site_title`, `custom_head_code`, `custom_footer_code`, `tracking_head_code`, `tracking_body_code`, `global_css`, `theme_preset`
- **Widgets/fields:** `membership-pricing`, `testimonial-slider`, `checkbox_group`

Some of these rows misname a real setting, as in the Google Drive and site-key cases. Others describe features this codebase does not have, such as an MFA-enforcement toggle or Studio custom head code. The rows were not deleted, because this pass was instructed to keep meaningful content. **Read this inventory only together with `csv-row-verification.csv`.**

### Spot checks against source (VERIFIED rows)

| Row | Source evidence |
|---|---|
| `Core: System / timezone` | In `admin/settings.php`, `save_datetime` validates against `DateTimeZone::listIdentifiers()` and is now the only writer. |
| `Core: System / permalink_structure` | In `admin/settings.php`, `save_permalinks` has an allow-list identical to the three radio values (checked by `SystemSettingsAuditFixesTest`). |
| `Core: System / start_of_week` | In `admin/settings.php`, `save_datetime` resets values outside 0..6 to 1 before writing. |
| `Plugin / backups / retention_count` | `plugins/backups/admin/settings.php` clamps with `max(1, min(365, …))`, and the input declares `min="1" max="365"`. |
| `Plugin / stripe-payment / secret_key`, `webhook_secret` | Stored as encrypted `enc:v1:` envelopes; the form is write-only. Covered end to end by `StripeWriteOnlySecretTest`. |
| `Plugin / mcp-gateway / ai_api_key` | Encrypted, with an explicit "clear" checkbox. The save is skipped if encryption throws. |

## Coverage limits: what a static inventory cannot enumerate

- **Tenant-defined data.** Studio page custom fields (29 meta field types, plus field sets per content type), form schemas and membership plans are tenant data, not platform settings.
- **Runtime-registered modules and blocks.** Plugins installed from disk and Widget SDK blocks register at runtime. `studio-freelancer-portfolio` and `studio-testimonial-widget` are examples that exist as plugin directories. The block list depends on which plugins are active.
- **Hook-contributed settings.** `Hook::doAction('booking_settings_save', $_POST)` lets other plugins add booking settings cards, and their keys cannot be known statically.
- **Settings that exist only in 02-licensing.** The inventory was built from `01-client`. Settings unique to the licensing tree, such as central licensing keypair storage, are not listed.

**Conclusion:** the CSV is structurally valid, but only about 44% of its rows can be traced verbatim to the source, and it is not exhaustive.
