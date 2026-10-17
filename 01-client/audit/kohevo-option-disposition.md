# Kohevo option disposition

> **Regenerated 2026-10-17 from the corrected inventory.** The earlier version was built on an inventory in which 66 of 137 rows named keys that do not exist in the source. Each row below now traces to a real key, block type or field type (see `review-package/csv-row-verification.csv`), and the rows that described non-existent settings are listed at the end with the reason they were removed.

## Categories

- **Implemented**: the key, block or field exists in source; `csv-verify.py` matched it exactly.
- **Corrected**: implemented, but the earlier inventory used the wrong name. The old name is kept in the row's Notes.
- **Not implemented**: claimed earlier, deferred and documented as such.
- **Removed**: the earlier inventory described a setting that does not exist.
- **Open risk**: implemented, but this verification found a security gap (see `review-package/remaining-risks.md`).

## Summary

| Category | Rows |
|---|---|
| Implemented, as listed | 45 |
| Implemented, name corrected | 53 |
| Not implemented (documented deferral) | 1 |
| Removed (did not exist) | 38 |
| Of the implemented rows, flagged as open risk | 4 |

## Inventory by domain and disposition

| Domain | Disposition | Rows |
|---|---|---|
| Core: System | Keep/Core | 27 |
| Field: Forms | Keep/Core | 11 |
| Plugin | Keep/Core | 45 |
| Plugin | Not implemented | 1 |
| Widget: Studio | Keep/Core | 16 |

## Hardened in this change set

| Option | Disposition |
|---|---|
| `stripe-payment.secret_key`, `webhook_secret` | Write-only; encrypted; save refused if encryption fails; legacy plaintext upgraded on save; Disconnect clears both modes |
| `forms` `sp_captcha_secret` | Write-only; **encrypted at rest** (enc:v1); save refused if encryption fails; legacy plaintext upgraded on save; explicit clear |
| `backups.google_client_secret`, `booking.google_client_secret`, `booking.twilio_token`, `mcp-gateway.ai_api_key` | Encryption failure no longer 500s; secret is skipped with an error, never stored in plaintext |
| `permalink_structure` | Allow-list equal to the form's three choices |
| `start_of_week` | 0..6, otherwise 1 |
| `backups.retention_count` | 1..365 |
| `timezone` | Written only by Date & Time |
| `brand_font_family`, `brand_border_radius` | Now emitted as CSS variables |
| `coaching.settings_updated` | Audit event on save |

## Open risks on implemented options

| Option | Finding |
|---|---|
| `Email` `smtp_oauth_client_secret` | admin/oauth_callback.php stores it in plaintext when encryption is unavailable ($enc ? slate_encrypt_secret(...) : ...) |
| `Email` `smtp_oauth_refresh_token` | OAuth refresh token (with smtp_oauth_access_token / smtp_oauth_expires); stored encrypted |
| `mcp-gateway` `ai_base_url` | OpenAI-compatible endpoint used by AiProviderClient; no scheme/host validation, so an admin can point it at any host including internal ones |
| `multilang-translate` `autotranslate_api_key` | Read raw by includes/AutoTranslate.php:143 (no decryption), so it is stored in plaintext |

## Corrected names

| Component | Real key | Was |
|---|---|---|
| General | `site_tagline` | `tagline` |
| General | `APP_URL` | `site_url` |
| General | `site_admin_email` | `admin_email` |
| Branding | `brand_logo_path` | `brand_logo_url` |
| Branding | `brand_favicon_path` | `brand_favicon_url` |
| Security | `session_timeout_minutes` | `session_idle_timeout` |
| Security | `max_login_attempts` | `login_lockout_attempts` |
| Security | `lockout_minutes` | `login_lockout_duration` |
| Email | `smtp_auth_type` | `mail_driver` |
| Email | `smtp_user` | `smtp_username` |
| Email | `smtp_pass` | `smtp_password` |
| Email | `smtp_oauth_refresh_token` | `smtp_oauth_tokens] | admin/oauth_callback.php stores it in plaintext when encryption is unavailable ($enc ? slate_encrypt_secret(...) : ...) [verified 2026-10-17; open risk` |
| backups | `google_client_id` | `gdrive_client_id` |
| backups | `google_client_secret` | `gdrive_client_secret` |
| backups | `google_refresh_token` | `gdrive_refresh_token` |
| booking | `buffer_min` | `buffer_minutes` |
| booking | `google_enabled` | `google_calendar_enabled` |
| booking | `twilio_sms_from` | `twilio_from` |
| coaching | `program_plan_id` | `gated_plans` |
| coaching | `coaching.settings_updated` | `audit_logging` |
| forms | `sp_captcha_site_key` | `sp_captcha_sitekey` |
| forms | `sp_rate_limit` | `sp_rate_limit_rpm` |
| forms | `notify_email` | `notification_emails` |
| forms | `webhook_urls` | `webhook_url` |
| licensing | `remote_license_cache` | `license_cache` |
| licensing | `LICENSE_SERVER_URL` | `central_api_url` |
| licensing | `OFFLINE_TOLERANCE_SECONDS` | `offline_tolerance_seconds` |
| mcp-gateway | `ai_base_url` | `(text corrected)` |
| media-library | `ALLOWED_MIMES` | `allowed_mime_types` |
| media-library | `media_max_upload_mb` | `max_upload_size_mb` |
| media-library | `media_usage` | `track_usage` |
| media-library | `media_thumb_w` | `image_variants` |
| membership | `enable_attendance` | `qr_attendance_enabled` |
| stripe-payment | `mode` | `stripe_mode` |
| stripe-payment | `method_klarna` | `payment_methods` |
| studio-builder | `studiobuilder_tokens` | `theme_preset` |
| Core | `core.heading` | `header` |
| Core | `core.text` | `paragraph` |
| Core | `core.button` | `button` |
| Core | `core.image` | `image` |
| Core | `layout.grid` | `columns` |
| Core | `core.card` | `card` |
| Core | `core.hero` | `hero` |
| Core | `core.divider` | `divider` |
| Core | `core.spacer` | `spacer` |
| Core | `core.video` | `video` |
| Core | `forms.embed` | `form` |
| Core | `booking.embed` | `booking-calendar` |
| Core | `membership.plans` | `membership-pricing` |
| Core | `payment.button` | `payment-button` |
| Plugin | `portfolio.project_card` | `freelancer-portfolio` |
| Plugin | `acme.testimonial` | `testimonial-slider` |
| Built-in | `checkboxes` | `checkbox_group` |

## Removed rows (setting does not exist)

| Component | Earlier name | Reason |
|---|---|---|
| General | `default_role` | No user self-registration role setting exists |
| General | `users_can_register` | No self-registration toggle exists |
| General | `membership_requires_approval` | No registration-approval setting exists |
| Branding | `brand_primary_color` | Only brand_accent_color exists; there is no primary-colour setting |
| Branding | `brand_secondary_color` | Only brand_accent_color exists; there is no secondary-colour setting |
| Security | `mfa_enforced` | MFA exists per user (src/Services/Auth/Mfa.php) but there is no platform-wide enforcement setting |
| backups | `schedule_frequency` | Backups are cron-driven; there is no schedule setting |
| backups | `schedule_time` | Backups are cron-driven; there is no schedule-time setting |
| backups | `destination` | Google Drive is the only destination; there is no destination setting |
| backups | `include_database` | Backup contents are not configurable |
| backups | `include_uploads` | Backup contents are not configurable |
| booking | `slot_interval_minutes` | No global slot-interval setting exists |
| booking | `min_notice_hours` | No booking minimum-notice setting; the real keys are cancel_min_notice_hours and reschedule_min_notice_hours (different meaning) |
| booking | `max_future_days` | No max-future-days setting exists |
| booking | `deposit_mode` | No deposit setting exists |
| booking | `deposit_amount` | No deposit setting exists |
| booking | `google_tokens` | Not a setting; Google OAuth tokens were not verified as a settings key |
| coaching | `macro_protein_pct` | No macro-percentage setting exists |
| coaching | `macro_carb_pct` | No macro-percentage setting exists |
| coaching | `macro_fat_pct` | No macro-percentage setting exists |
| coaching | `client_diary_visibility` | No diary-visibility setting exists |
| forms | `sp_honeypot_enabled` | The honeypot is always on; there is no toggle |
| mcp-gateway | `mcp_enabled` | No MCP master switch; plugin activation is the switch |
| mcp-gateway | `allowed_tools` | Tool access is governed by MCP token scopes, not a setting |
| membership | `grace_period_days` | No grace-period setting exists |
| membership | `stripe_sync_enabled` | No Stripe-sync toggle exists |
| membership | `wallet_ledger_enabled` | No wallet/ledger toggle exists |
| multilang-translate | `active_languages` | Language configuration was not verified as a settings key |
| multilang-translate | `crawler_protection` | No crawler-protection setting exists |
| multilang-translate | `url_prefix_mode` | No URL-prefix-mode setting exists |
| studio-builder | `site_title` | No Studio site-title setting exists (core site_name is used) |
| studio-builder | `custom_head_code` | No custom head-code setting exists |
| studio-builder | `custom_footer_code` | No custom footer-code setting exists |
| studio-builder | `tracking_head_code` | No tracking head-code setting exists |
| studio-builder | `tracking_body_code` | No tracking body-code setting exists |
| studio-builder | `global_css` | No global CSS setting exists |
| Core | `coaching-feed` | No coaching feed block exists |
| mcp-gateway | `ai_provider` | No provider setting exists; the client is configured by ai_base_url + ai_model (already listed) |

## Not verified

Only the key/type columns and the secret-handling column were checked against source row by row. The `Input_Validation`, `Bounds_Enforced` and free-text `Notes` columns of rows that were not touched were spot-checked only, and one of those spot checks (`ai_base_url`) turned out to be false and was corrected. Treat those columns as NOT VERIFIED.
