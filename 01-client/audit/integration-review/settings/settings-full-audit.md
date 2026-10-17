# Kohevo settings: full source-driven audit (r11)

Read-only audit of the worktree at `scratchpad/wt-final` (HEAD `71d4e83`). No repository files were changed, nothing was run against a database, and the app was not started.

- Full per-key table: `r11/settings-full-inventory.csv` (482 rows: 251 client keys, 222 licensing keys, plus 9 dynamic-pattern rows).
- Scripts that rebuild it: `r11/scan.py` (scanner), `r11/build.py` (manual expansion of loop-built keys), `r11/gen.py` (per-key rows). Intermediate data: `client.json`, `lic.json`, `merged.json`, `rows.json`.

## 1. Method

**Scope.** Every `*.php` file under `01-client/` and `02-licensing/`, excluding the top-level `vendor/ tests/ node_modules/ audit/ archive/ scratch/ Claude/ docs/ db_backups/` and any nested `vendor/ node_modules/ tests/`. That is 599 PHP files in the client tree and 349 in the licensing tree. `bin/` CLI scripts are included and flagged as CLI owners.

**Accessors found.** All persisted settings go through the `settings` table (`tenant_id, setting_key, setting_value`). The only accessors are `Database::setting()` and `Database::setSetting()` (`src/Data/Database.php:111,122`), plus thin wrappers over them:
- `Plugin::setting()` and `Plugin::setSetting()` add a `<slug>.` prefix (`includes/Plugin.php:78-85`).
- `StudioCodePolicy::setting()` (`src/Module/StudioBuilder/Http/StudioCodePolicy.php:486`) and `EmailTemplate::setting()` (`src/Services/Notifications/EmailTemplate.php:41`) are private `self::` read wrappers.
- `SettingsRepository` (`src/Services/Settings/SettingsRepository.php`) is a proof of concept with no production callers.

The only direct SQL against `settings` outside `Database.php` is in `admin/diag.php:53` (read of `site_name`) and `bin/seed-rakib-portfolio.php:107` (insert, CLI).

**Regexes** (Python, per line; comment lines that start with `*`, `//` or `/*` are skipped):
- Direct literal: `(?:\\?Database|self|static)::(setting|setSetting)\(\s*(['"])([^'"$]+)['"]\s*([,)])`
- Concatenated prefix (flagged as dynamic): `...::(setting|setSetting)\(\s*(['"])([^'"$]*)\2\s*\.`
- Class constant: `...::(setting|setSetting)\(\s*(\\?[A-Za-z_\\]+|self|static)::([A-Z_][A-Z0-9_]*)\s*([,).])`, resolved per class (`self::` means the same file; `Class::` means the file that declares `class Class`).
- Plugin instance: `\$(this|plugin)->(setting|setSetting)\(\s*(['"])([^'"$]+)['"]`, prefixed with the plugin directory slug (`StudioSchemaManager` is mapped to `studio-builder`).
- Wrapper detection: `(?:\$(\w+)\s*=\s*(?:static\s+)?(?:fn|function)|function\s+(\w+))\s*\(\s*(?:string\s+)?\$(\w+)`, where the next 5 lines contain `Database::(setting|setSetting)\(\s*'prefix'\s*\.\s*\$param`. Literal call sites of each wrapper are then expanded. Found: `$g` in the booking, membership and backups settings pages; `$s` in StripePaymentAPI; `$on` in booking `pay-intent.php`; `$sset` in booking `router.php`; and the named function `bookpub_book_setting()`.
- Catch-all: any `(::|->)(setting|setSetting)\(` whose first argument is not a literal is listed as an unresolved dynamic site (49 client, 41 licensing). Each one was read by hand. Those that build keys from a finite set (array loops, `$prefix`, mode slots, `$get` in Mailer, `TenantBranding::KEYS`, capability loops, `$flagKey`, `$settingKey`) were expanded in `build.py` (MANUAL list, 28 expansions). The rest are reported as patterns.
- Completeness check: a second pass (`leftover.py`) re-greps every production line containing `(::|->)(setting|setSetting)\(` and confirms that each one is either a scanner record or a listed dynamic site. Zero lines were left over.

**Counts**

| | client | licensing |
|---|---|---|
| PHP files scanned | 599 | 349 |
| Scanner records (read+write call sites x keys) | 755 (248 W / 507 R) | 674 (204 W / 470 R) |
| By method: direct / closure / plugin-instance / named func / const | 630 / 61 / 27 / 12 / 25 | 568 / 61 / 25 / 12 / 8 |
| Records added by manual loop expansion | 158 | 154 |
| **Distinct concrete keys** | **251** | **222** |
| Dynamic key patterns (not enumerable) | 5 | 4 |

Dynamic patterns: `entitlement.<feature>.enabled` (read only; `EntitlementService.php:65` client, `:114` licensing), `studio_page_meta_<pageId>` (client only; `PageMeta.php:86-133`), `<any key>` via MCP `slate_settings_get/set` (`CoreMcpTools.php:258-276` client, `:246-264` licensing), `<slug>.cap.<cap>` (`PluginLoader.php:234,243`), and `<slug>.<key>` (`Plugin.php:79,84`).

**How the columns were filled.** The *owner* is the canonical admin page that writes the key. `admin/settings.php` comes first. `diag.php`, `repair-settings.php`, `oauth_callback.php` and `plugins.php` rank lower, and every other writer is listed in `notes`. *Readers* are every read site, as file:line. *Default* is the most common literal fallback (`?? 'x'`, `?: 'x'`, or the second argument of a wrapper) at the read sites, or the `repair-settings.php` default. *Validation* and *bounds* were curated by hand for every user-facing write. A heuristic covers system writes (version stamps, API responses). *Permission* and *CSRF* belong to the owning write path. *Classification*: `secret` means a credential, token or key; `sensitive` means PII, identifiers, or code injected into pages; anything else is `normal`. *Used*: `yes` means a reader exists outside the form that writes it; `yes (internal state …)` means system state read by its own module; `display-only` means it is only read back to pre-fill its own form (it has no runtime effect); `no (dead)` means it is never read; `read-only` means there is no production writer.

## 2. Coverage of the existing 100-row inventory

`01-client/audit/kohevo-settings-widget-inventory.csv` has 100 rows. Of these, 41 describe Studio widgets and form fields, and 59 describe settings or config. There is no copy of the file in `02-licensing/audit/`.

- **Covered:** 54 of the 251 client keys (21.5%) and 54 of the 222 licensing keys.
- **Missing:** 197 client keys and 168 licensing keys. In the client tree that is 149 normal, 39 sensitive and 9 secret keys.
- **Secrets missing from the inventory:** `stripe-payment.test_secret_key`, `stripe-payment.live_secret_key`, `stripe-payment.test_webhook_secret`, `stripe-payment.live_webhook_secret` (these mode slots are the values Stripe actually reads first, `StripePaymentAPI.php:79`), `smtp_backup_pass`, `smtp_oauth_access_token`, `backups.google_access_token`, and `smtp_backup_oauth_client_secret` / `smtp_backup_oauth_refresh_token` (read only, never written). The licensing tree also lacks `licensing.signing_secret_key`. All secrets that do have a writer are encrypted at rest (see section 4).
- **Whole groups missing:** all of `booking.*` except 6 keys, all of `landing_*` (17), `business_*` (5), `smtp_backup_*` (6 writable), all of Studio (`studio_*`, 30 keys, client only), all of `membership.*` except 1, Stripe toggles (11 except `method_klarna`), `bookingplus.*`, `booking.cap.*`, the white-label keys (`brand_owner*`, `support_*`, `docs_url`, `privacy_url`, `terms_url`), the media sizes, and `embed_allowed_origins`.

**Inventory rows that are wrong or point at no settings key**
- `site_tagline` and `site_admin_email` are described as "Settings > General", each with a 2026-10-17 correction. **No production code writes either key.** They are only read: `plugins/membership/public/landing.php:73`, `plugins/booking/Booking.php:1010`, and `plugins/booking/public/message.php:192`. Settings > General (`admin/settings.php:80-104`) writes `site_name`, `default_language`, `currency` and `currency_position`.
- `licensing | license_key` says "Database::setting, plaintext". It is actually the `LICENSE_KEY` env var (`includes/license_sync.php:120`, `EntitlementService.php:23`). No settings key exists.
- `forms | sp_captcha_secret` and `sp_captcha_provider` say "Database::setting/form_meta". They are actually stored in the form's `settings_json` (`plugins/forms/admin/edit.php:245-251`). No settings key exists.
- These rows are filed under the wrong owner: `booking | business_hours` and `booking | currency`, `membership | currency`, and `multilang-translate | default_language`. All four are core keys written by `admin/settings.php` (lines 113, 102 and 100). `business_hours` is also display-only (see F-dead).
- `media-library | media_thumb_w` gives the storage as `config`. It is `Database::setting` (`admin/settings.php:178`), and it has no runtime reader.
- Inventory rows for `permalink_structure`, `media_max_upload_mb` and `media_thumb_w` describe settings that have no runtime effect.

## 3. Cross-tree parity

- **Client only (30):** `license_sync.last_attempt` (`includes/license_sync.php:108,114`) and 29 Studio keys (`studio_*`, `studio-builder.applied_version`, `studio-builder.schema_verified`). The licensing tree has no `plugins/studio-builder` or `src/Module/StudioBuilder`. `mcp-gateway.studio_rate_limit_write_per_min` and `mcp-gateway.studio_rate_limit_render_per_min` are read only in the client tree (`McpGatewayAPI.php:439`), and nothing writes them.
- **Licensing only (3):** `licensing.signing_public_key` and `licensing.signing_secret_key` (`plugins/licensing/LicensingAPI.php:127-147`; the secret is `slate_encrypt_secret` at `:136`), and `licensing.schema_verified`.
- **Shared keys, identical writers.** These files are byte-identical in both trees: `admin/settings.php`, `admin/oauth_callback.php`, `admin/repair-settings.php`, `admin/diag.php`, the booking, backups, coaching, membership, mcp-gateway (`ai-settings`), multilang (`api.php`) and stripe settings pages, `BookingPlusAPI.php`, `StripePaymentAPI.php` and `Mailer.php`. Validation, permission and CSRF are therefore the same for every shared key.
- **Shared keys, behaviour differs (Info).**
  - `current_tenant_id()` falls back to `0` in the client tree and to `1` in the licensing tree (`includes/helpers.php:56-58`). A `setSetting` without tenant context lands on tenant 1 in licensing.
  - `admin/plugins.php` differs by 592 lines. The capability writer is at `:343` in client and `:273` in licensing.
  - `CoreMcpTools.php`: client tools carry `classification` / `requires_confirmation` metadata (`settings_set` has `requires_confirmation: true`). The licensing tree has no such metadata.
  - `EntitlementService` reads `entitlement.<f>.enabled` in both trees (client `:65` `!== '0'`, licensing `:114` `=== '0'`). The surrounding remote/legacy logic differs (165 diff lines). Not verified as equivalent.

## 4. Findings

Severity reflects impact and how reachable the problem is. Evidence is from the client tree unless marked. Shared files have the same line numbers in the licensing tree unless noted.

### High

**H1. Studio Content console writes settings with no permission check.** `plugins/studio-builder/admin/content.php:26` calls only `Auth::require()`: there is no `requirePerm`, no `$canEdit` and no `ModuleGuard`. Any signed-in admin-panel user, whatever their role, can overwrite `studio_profile` (`:81`) and `studio_content_portfolio`, `studio_content_service` and `studio_content_testimonial` (`:112,124,136,154,161,168,190,197`). These values feed the public portfolio site (`plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php`). CSRF is checked. Every sibling Studio page requires `studio-builder.view` plus `studio-builder.edit` (for example `seo.php:15-17`). Values are only trimmed, and URL fields (`avatar_url`, `image_url`, `link_url`) are not scheme-checked. Client only.

**H2. MCP `slate_settings_set` and `slate_settings_get` get around per-page validation and the secret guard.** `plugins/mcp-gateway/CoreMcpTools.php:254-276` (licensing `:242-264`).
- The only guard is `looksSensitive()`, which is `/pass|secret/i`.
- With a `mcp-gateway.settings.write` token, a caller can write any other key as a raw string, with none of the admin page's validation or encrypt-first handling. Examples:
  - `smtp_host` sends the stored `smtp_user` / `smtp_pass` to an attacker's host on the next mail.
  - `mcp-gateway.ai_base_url` sends the decrypted AI key as a Bearer token to any public https host (it is re-vetted only for private hosts, `AiProviderClient.php:132`).
  - It can also write `membership.terms_url` (a `javascript:` href) and the unvalidated multilang switcher keys.
  - Token keys that do not match the regex (`booking.twilio_token`, `smtp_oauth_refresh_token`, `mcp-gateway.ai_api_key`, `multilang-translate.autotranslate_api_key`, `backups.google_refresh_token`) can be overwritten in plaintext.
- `settings_get` returns their stored values. These are encrypted envelopes, not plaintext.

The capability is gated by an admin-issued scope (`:178-184`), so exploitation needs a compromised or over-scoped token. Its impact goes beyond what the comment at `:11` promises.

### Medium

**M1. Settings that are saved and shown back but do nothing (labels mislead).** None of these has a runtime reader: grep across all file types finds them only in their own form.
- *Media* (`admin/settings.php:178-185`): `media_thumb_*`, `media_medium_*`, `media_large_*`, `media_webp_convert`, and `media_max_upload_mb`, labelled "Max Upload File Size (MB)" at `:1426`. Uploads use their own constants (for example `Uploads::DEFAULT_MAX_BYTES`).
- *Permalinks* (`:133-135`): the whole Permalinks tab.
- *Debug mode* (`:810`): the hint at `:4190` says "Show detailed error messages".
- *Studio Site & SEO* (`plugins/studio-builder/admin/seo.php:50-56`): 7 `studio_seo_*` keys. The flash says "meta directives saved". `studio_seo_google_verify` and `studio_seo_bing_verify` duplicate the working `studio_code_site_verification` (`code-tracking.php:132`, rendered by `StudioCodePolicy.php:303`).
- *Studio Redirects* (`redirects.php:75`): `studio_redirects` is never applied at request time.
- *Studio AI & MCP* (`ai-mcp.php:49-52`): `studio_ai_model`, `studio_ai_tone`, `studio_ai_temperature`, `studio_ai_max_tokens`.

32 keys in the client tree and 20 in licensing; the full list is in the CSV under `used = display-only`.

**M2. Admin notification address cannot be set, so booking admin emails are silently dropped.**
- `Booking.php:1010` (practitioner nudge cron) and `plugins/booking/public/message.php:190-195` (new client message) send to `booking.notify_admin_email ?: site_admin_email` and return early if both are empty.
- No production code writes either key. The existing inventory lists `site_admin_email` as a General setting.

Both trees are affected.

**M3. A capability toggle has two owners.** `booking.cap.{client_messaging,service_rules,slot_restrictions,custom_reminders}` is written from two places: Admin > Plugins (`admin/plugins.php:343` via `PluginLoader::setCapabilityEnabled`, `src/Kernel/Module/PluginLoader.php:243`, with `plugins.manage`) and Booking settings (`Booking.php:1085` via the `booking_settings_save` hook, with `booking.manage_settings`). These two permissions are different, and nothing in the source indicates which screen is canonical.

**M4. `membership.terms_url` is stored unvalidated and rendered as a link on a customer page.** `plugins/membership/admin/settings.php:32` stores `trim()` only. `plugins/membership/public/views/onboarding.php:99-103` prints `<a href="' . e($termsUrl) . '">`. `e()` does not block `javascript:` URLs. Requires `membership.manage_settings`.

**M5. Multilang switcher settings are saved with no validation.** `plugins/multilang-translate/admin/api.php:176-185` stores 6 raw POST strings (`switcher_enabled`, `style`, `position`, `bg`, `fg`, `accent`). `assets/switcher-render.php:59-60` puts `bg`, `fg` and `accent` into an inline `style=""` attribute with HTML-escaping only, so CSS injection is possible on every page that shows the switcher. `switcher_enabled` is read as truthy (`MultilangTranslate.php:195`), which means the string `"0"` is falsy but `"off"` is truthy. Requires `mlt.manage` and CSRF.

**M6. Keys that are read but have no writer (dead configuration, undocumented).** 24 in the client tree and 22 in licensing:
- `booking.tax_rate` (`BookingAPI.php:1232`).
- `shop.currency` (`StripeAPI.php:49,102`, `public/create-intent.php:67`). Stripe falls back to `usd` and ignores the core `currency` setting unless the cart carries a currency. Not verified end to end.
- `mcp-gateway.rate_limit_per_min` (`McpGatewayAPI.php:394`, default 60).
- `mcp-gateway.studio_rate_limit_{write,render}_per_min` (client `McpGatewayAPI.php:439`, constants at `StudioBuilderMcpHandler.php:32-33`).
- `multilang-translate.autotranslate_provider` and `autotranslate_endpoint` (`AutoTranslate.php:181-182`).
- `brand_text_color`, `brand_canvas_color`, `brand_heading_font` and `brand_body_font` (`src/Services/Content/TenantBranding.php:36-41,49`).
- `site_tagline` and `site_admin_email` (see M2).
- `booking.whatsapp_url` and `booking.nudge_hours` (legacy fallbacks at `BookingPlusAPI.php:253,257`).
- The 8 `smtp_backup_{auth_type,from_email,from_name,oauth_*}` keys (`Mailer.php:101-125`). The backup form has no fields for these, which is by design per `:116-118`.

The only way to set any of these is MCP `settings_set` (H2) or SQL.

### Low

- **L1. The white-label comment is wrong, and four URLs plus a phone number are dead.** The comment at `admin/settings.php:689-690` says the block is read by `help.php` and `login.php`. Only `brand_owner`, `brand_owner_url` and `support_email` are read (`admin/help.php:16`, `admin/login.php:488`). `support_phone`, `support_url`, `docs_url`, `privacy_url`, `terms_url` (`:723-727`) and `business_hours` (`:113`) are display-only. The URL fields use `FILTER_VALIDATE_URL` with no scheme allow-list (`:700-712`).
- **L2. `bookingplus.whatsapp_url` has no server-side URL or scheme check.** `BookingPlusAPI.php:269` relies on `type=url` in the browser. The value flows into booking templates (`Booking.php:898,914,942,956`). The rendering context was not verified.
- **L3. SMTP secret handling is not encrypt-first, unlike the plugins.**
  - `save_smtp` writes host, port, user and from fields, and only then tries to encrypt `smtp_pass`. On failure it redirects with "Settings saved, but…" (`admin/settings.php:215-238`).
  - The Graph branch silently skips `smtp_oauth_client_secret` when encryption is unavailable or throws (`:262-268`).
  - `save_backup_smtp` behaves the same way (`:445-460`).

  Nothing is stored in plaintext. Booking, backups and mcp use `slate_seal_posted_secrets`, which seals before any write (`includes/helpers.php:573`).
- **L4. Partial saves on validation error.**
  - `save_landing` writes 5 text keys (`:740-744`) before the URL check fails (`:748`).
  - `save_branding` writes the accent, button mode, theme, font and radius (`:500-528`) before upload or URL errors set `$flash`.
- **L5. Weakly validated fields.**
  - `date_format` and `time_format` accept free text via "custom", capped at 32 characters (`:146-153,160-161`).
  - `smtp_oauth_email` is not validated in the `save_smtp` Graph branch (`:259-261`), although `oauth_connect` validates it (`:360`).
  - `stripe-payment.allowed_countries` is trim-only (`plugins/stripe-payment/admin/settings.php:148`).
  - `stripe-payment.webhook_secret` has no `whsec_` format check (`:147`).
  - `membership.expiry_reminder_days` is a digits/commas filter only and is display-only (`membership/admin/settings.php:31`).
  - `studio_seo_robots` is free text (`seo.php:46`).
  - The `small-business-kit.*` CLI arguments are unvalidated (`bin/create-construction-site.php:63-67`).
- **L6. A core key is owned by a plugin page.** `embed_allowed_origins` is a core framing allow-list read by `includes/helpers.php` but writable only from Booking settings (`plugins/booking/admin/settings.php:71`, which needs `booking.manage_settings` and an active booking module).
- **L7. Coaching settings are gated by a roster permission.** `plugins/coaching/admin/settings.php:15` requires `coaching.manage_clients`. The plugin declares no settings permission (`plugins/coaching/plugin.json`). `coaching.default_activity_factor` (`:28`) is display-only, and `coaching.program_plan_id` is not checked against existing plans (`:24`).
- **L8. Dead keys written only by a CLI.** `small-business-kit.{header_phone,header_phone_href,header_email,footer_address,footer_about}` are written by `bin/create-construction-site.php:63-67` and read nowhere. No `small-business-kit` plugin exists in either tree. This applies to both trees.

### Info

- **I1. Co-ownership that is intentional.**
  - `smtp_*` and `smtp_oauth_*` are written by both `admin/settings.php` and `admin/oauth_callback.php:82-99` (OAuth completion). The callback is CSRF-protected by state/nonce (`:31-38`) and needs `settings.edit`.
  - `admin/repair-settings.php:81` resets 18 known keys to defaults (`settings.edit` or super admin, CSRF).
  - `admin/diag.php:40` rewrites `site_name` (platform admin, `SLATE_DIAG_ENABLED`, CSRF).
  - `studio_code_custom_css` is written by `code-tracking.php:122` and `StudioApplicationService.php:874`; both require Studio admin and sanitise the input.
- **I2. `brand_sublabel` has no editing UI.** It is read in 5 places but can only be set by the repair reset to `Pro Admin` (`repair-settings.php:41`).
- **I3. A stale comment about two branding keys.** The comment at `admin/settings.php:516-518` says `brand_font_family` and `brand_border_radius` have "no downstream consumer". They are read at `includes/ui_components.php:564,572`.
- **I4. `entitlement.<feature>.enabled` has no writer.** It is a local kill switch (it can only disable a feature) with no production writer.
- **I5. Secrets: all encrypted at rest.** Every secret key with a production writer is encrypted at rest. The encrypting writes are:
  - `slate_encrypt_secret` or `slate_seal_posted_secrets` for `smtp_pass`, `smtp_backup_pass`, `smtp_oauth_client_secret`, `smtp_oauth_access_token`, `smtp_oauth_refresh_token`, `booking.google_client_secret`, `booking.twilio_token`, `backups.google_client_secret`, `backups.google_access_token`, `backups.google_refresh_token`, `mcp-gateway.ai_api_key`, `multilang-translate.autotranslate_api_key` (`AutoTranslate.php:149-170`), and `licensing.signing_secret_key`.
  - Fail-closed `$encSecret` for Stripe `secret_key`, `webhook_secret` and the `test_` / `live_` slots (`stripe-payment/admin/settings.php:221-262`).

  Legacy plaintext values remain readable until they are re-saved: Stripe `keyByMode` and multilang `AutoTranslate.php:163`.

## 5. Not verified

- Output escaping and scheme handling where `studio_profile` / `studio_content_*` URLs, `bookingplus.whatsapp_url` and `brand_owner_url` are rendered. The findings above cover only the storage side.
- Whether `$cart['currency']` is always set, which would make `shop.currency` irrelevant in practice (M6).
- Whether `maintenance_mode` gates every public entry point. It is called from `index.php:13` and `public.php:18`; other entry points were not checked.
- Values that the hook `booking_settings_save` may pass to other listeners beyond `Booking::saveSettings` and `BookingPlusAPI::saveSettings`. Only the in-tree listeners were checked.
- Settings written outside PHP (JS that posts to generic endpoints was covered only through the PHP handlers), and settings rows created by SQL migrations (none found in `db/schema.sql` or `db/migrations`).
- Semantic equivalence of `EntitlementService` and `admin/plugins.php` across the trees (diff only).
- Exploitability of `FILTER_VALIDATE_URL` values with `javascript:` schemes in admin-only pages.
- Default values in the CSV are taken from read-site fallbacks. Keys with several different fallbacks show the most common one.
