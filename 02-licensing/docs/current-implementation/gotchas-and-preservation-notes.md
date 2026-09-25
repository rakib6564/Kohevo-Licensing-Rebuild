# Current Implementation — Gotchas & Preservation Notes

**Status:** Living reference · **Describes:** today's code.

The **tacit knowledge** — behaviors, hazards, and decisions real in the running
system but not obvious from any single file. Losing these is how a "safe refactor"
breaks production. The formal "must-not-change" list is in
[load-bearing-behaviors.md](load-bearing-behaviors.md).

---

## 1. Deployment & environment

- **Served under a `/slate/` sub-path, not the domain root.** Routes, asset URLs,
  cookie paths, redirects must be base-path aware. **Smoke-test with the `/slate/`
  prefix.**
- **CloudLinux/cPanel with a hard disk quota.** On `EDQUOT`, **all Bash breaks**.
  Clear `~/.npm` / `~/.claude` caches.
- **`.env` holds live credentials** (DB, `APP_SECRET`, `CRON_SECRET`); git-ignored;
  never ship in a ZIP.
- **Asset cache-busting by mtime behind a 7-day Cloudflare cache** (`?v=<mtime>`).
  After editing plugin CSS/JS, **test the `?v=` URL**.
- Stray root debug scripts (`_*.php`) + `admin/diag.php`, `opcache-reset.php`,
  `repair-settings.php` are dev aids — production-hygiene liabilities.

## 2. Bootstrap & runtime cost

- **`config.php` is the single bootstrap**, required directly by every admin page —
  **no front controller**.
- **Every request** runs a `plugins` SELECT + per-plugin `plugin.json` read +
  `version_compare` + 1–2 `settings` reads. **No boot cache.** `MAX_BOOT_MS=250`
  logs, doesn't prevent. #1 steady-state cost.
- **`force_https`+HSTS and session idle-timeout enforced in `config.php`** (skipped
  on CLI/pre-install). Dead "Require 2FA" toggle removed.
- Post-boot, `config.php` bridges `stripe_webhook_event` → `Notifications::add`.

## 3. Plugins, hooks & tenancy — sharp edges

- **`works_better_with` is advisory only**, never enforced. No hard inter-plugin
  dependency/version system. `requires_core` is the only enforced constraint.
- **Table ownership is convention, not enforced.** *Verified:* each plugin owns its
  own prefix (`booking`→`booking_*`, `booking-plus`→`bookingplus_*`,
  `restaurant`→`restaurant_*`); none creates, alters, drops, or directly queries
  another's tables (`booking-plus` reaches booking only via `BookingAPI`). **But
  nothing prevents a future prefix collision** — two plugins claiming the same table
  would be undetected. (Note: an earlier assessment wrongly claimed these plugins
  *share* `booking_*` tables; the `install.sql`/`uninstall.sql` do not — corrected
  here.)
- **`activate()` permission loop is a dead no-op** (`PluginLoader.php` ~:422–433).
  Perms surface via `Auth::knownPermissions()` reading `manifest_json`. Don't "fix"
  the loop.
- **Tenant scoping is manual and inconsistent** — some queries omit
  `AND tenant_id = ?`. Any new query MUST add it explicitly.
- `current_tenant_id()`: CLI/cron override → super-admin tenant-switch →
  `TENANT_ID` (default 1).
- **Two settings accessors:** `Plugin::setting()` auto-prefixes the slug;
  `Database::setting()` takes the raw key (most code uses raw + hand-written
  prefix).
- **Settings-prefix drift:** `shop-emails` uses `shop_email.`; `seo` uses its own
  `seo_settings` table.
- **Schema self-heal:** 7 plugins define `ensureSchema()`; steady state skips via
  `applied_version`/`schema_verified` settings. **`membership` runs `ensureSchema()`
  unconditionally on its dashboard widget.**
- **Blocks register twice** (direct + `content_register_blocks` fallback) for
  order-independence. Preserve both.

## 4. Money, payments & identity

- **Money is two ways:** `shop` = `DECIMAL(12,2)`; everyone else = integer
  `*_cents`. Shop↔Stripe = float→cents conversion (rounding risk).
- **Stripe centralized but coupled:** stripe-payment's public endpoints depend on
  `ShopAPI`/`isActive('shop')`.
- **Person modeled 5 ways:** `customers` + `booking_customers` + `shop_customers` +
  `restaurant_customers` + `clientdesk_clients` (email-keyed, no FK). membership +
  coaching reuse `customer_id`.
- **`email_verified` is NOT authorization** — unverified users can log in.
- **Payment hardening to preserve:** amount reconciled vs rebuilt order total
  (mismatch → `on-hold`); charges ledger unique keys on
  `(tenant_id,payment_intent_id)`/`(tenant_id,session_id)`; `return.php` bound to
  `shop_sid`; booking `/book/done` verifies `metadata.booking_appt_id`; webhook
  rejects too-old AND future timestamps.
- **Shop order numbers from auto-increment id**, not `COUNT(*)+1`; order pages
  view-token-protected.

## 5. Presentation & UI

- **Never put `backdrop-filter` on `.app-panel`** — traps fixed modals.
- **Scrollbars hidden app-wide** — content wraps, never h-scrolls. `index.php` is
  the responsive gold standard; shared editor kit is `includes/record_editor.php`
  (`.pv-*`).
- **`slate_brand_accent_emit()` must run AFTER `slate_ui_emit_css()`** or accent
  reverts to default blue.
- **6 subsystems each render their own HTML doc** / token vocabulary (admin
  `--accent/--glass`, landing, content-builder `--cb-*`, SBK `--sb-*`, shop
  storefront, booking). No shared render layer.
- **content-builder is the block spine:** `BlockRegistry::register($type,$def)`
  (`fields[]` + `tpl` path OR `render` callable). Caveats: `rx-*` restaurant blocks
  in core registry; SBK `sb-*` parallel system; `full_html` bypasses theme; legacy
  `public/render.php` duplicates `router.php`; "layout" is a flat JSON block array
  (no Section/Template object).
- **`media-library` is a REQUIRED shim** (media promoted to core
  `includes/Media.php`). Not optional.

## 6. Security controls (don't regress)

- bcrypt+auto-rehash; per-IP throttling/lockout both flows (lazy `login_attempts`);
  dummy `password_verify` on no-such-user; logout requires CSRF token.
- CSRF (timing-safe); open-redirect guard (`slate_safe_redirect_target()`);
  prepared statements throughout.
- AES-256-GCM secrets at rest (`enc:v1:`); Stripe publishable key plaintext by
  design.
- Uploads MIME-checked; PHP-off `.htaccess`; `Require all denied` on
  data/db/docs/includes; SVG upload disabled.
- Forms webhook SSRF guard (private-IP refusal + IP pinning); public submits
  rate-limited + honeypot.
- CSV formula-injection neutralized.
- Concurrency: atomic gift-card debit, atomic coupon `max_uses`, slot engine
  `SELECT … FOR UPDATE`.
- `route.php` trampoline keeps `/includes/` 403 under subdir deploy.

## 7. Services & runtime

- **I18n 3-layer:** `lang/en.php` → DB `lang_overrides` → runtime (core + plugin
  strings).
- **Cron:** `cron.php` gated by `CRON_SECRET`, fires `frequent_cron`+`daily_cron`.
  No persistent worker.
- **Public routing:** `public_routes` filter → `PublicRouter` → `public.php`.
- **Media:** `media_files`+`media_usage`; `ensureSchema()` each non-CLI boot;
  refuses deletion of in-use; derived refs via `media_usage` filter.
- **`sitehub` is NOT a site builder** — remote control plane for external WordPress
  fleets (PortKit HTTPS API).

## 8. Naming & structural traps

- **Table prefix ≠ slug:** contentbuilder_, medialibrary_, flatrateshipping_,
  stripepayment_, surveypipeline_.
- **Two contact-form systems** (legacy core + Forms); removing legacy drops tables.
- **Two shipping plugins on `shop_shipping_rate`** — run at most one.
- **No namespaces/autoloader:** ~691 `require_once`, global classes,
  `class_exists('*API')` coupling.

## 9. Process reality

- Git exists now (Phase 0, `main`); previously none. Smoke suite
  (`tests/smoke.php`, 19/19); no broader suite, no CI.

---

## Top hazards if forgotten

(1) `/slate/` sub-path; (2) manual tenant scoping; (3) unenforced table-prefix
ownership (no current collision); (4) money DECIMAL-vs-cents; (5) `backdrop-filter`
on `.app-panel`; (6)
`slate_brand_accent_emit()` ordering; (7) payment-completion hardening; (8)
`media-library` required shim; (9) dead `activate()` perm loop; (10)
`email_verified` ≠ auth. Full contract: [load-bearing-behaviors.md](load-bearing-behaviors.md).
