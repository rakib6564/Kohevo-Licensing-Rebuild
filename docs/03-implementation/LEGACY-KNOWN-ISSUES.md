# Legacy known issues (client, pre-rebuild)

> **Historical.** Moved verbatim from `01-client/README.md`. It was written before the licensing rebuild and refers to
> plugins (shop, shipping) and behaviours that are no longer part of this build. Do not treat it as current status —
> see [`CHANGELOG.md`](../../CHANGELOG.md) and the CI results.

## Known issues & remaining work (as of the original hand-off)

**Security hardening pass (latest):**
- **Login throttling is now enforced.** The `max_login_attempts` /
  `lockout_minutes` Security settings were previously stored but never
  read; `Auth` now counts failed attempts per client IP and locks out
  both admin and customer logins (defaults: 10 attempts / 15 min). The
  no-such-user path also runs a dummy `password_verify` to flatten the
  account-enumeration timing oracle.
- **Force-HTTPS and session idle-timeout are now enforced** (they were
  inert settings). `config.php` 301-redirects to HTTPS (and sets HSTS)
  when `force_https` is on; idle sessions past `session_timeout_minutes`
  are dropped. The non-functional "Require 2FA" toggle was removed.
- **Stripe secret + webhook keys are encrypted at rest** (AES-256-GCM)
  via `slate_encrypt_secret()`; legacy plaintext keys are read
  transparently and re-encrypted on next save. (Publishable key stays
  plaintext — it's public.)
- **Stripe completion paths hardened:** the embedded checkout and the
  bank-redirect `return.php` now reconcile the captured amount against
  the rebuilt order total (parking mismatches on-hold), `return.php` is
  bound to the buyer's `shop_sid`, and the charges ledger has unique
  keys on `(tenant_id, payment_intent_id)` / `(tenant_id, session_id)`
  so concurrent webhooks can't double-insert.
- **Booking payment confirmation** (`/book/done`) now verifies the
  Stripe session's `metadata.booking_appt_id` matches the appointment
  before marking it paid (was spoofable with any paid session id).
- **Forms webhooks gained an SSRF guard** — non-http(s) schemes and
  private/loopback/link-local/reserved IPs are refused, and curl is
  pinned to the vetted IP. Public form submissions are now rate-limited
  per IP (≤5/min).
- **Tenant isolation:** shop variant create/edit/delete now verify the
  parent product belongs to the tenant; the `shipping-flat-rate` plugin
  gained a `tenant_id` column + per-tenant scoping on all queries.
- **Open-redirect fixed** on both login pages (`next=//evil.com` is now
  rejected via `slate_safe_redirect_target()`).
- **Booking uploads** (`uploads/booking/`) now get a PHP-off `.htaccess`
  and a real MIME check. `data/`, `db/`, `docs/`, `includes/` each got a
  `Require all denied` `.htaccess` (the root rules don't match under a
  `/subdir/` deployment). SVG logo upload disabled (script-carrying).
- **Concurrency:** booking gift-card debit is now an atomic conditional
  UPDATE and coupon redemption respects `max_uses` atomically.
- **CSV exports** (shop products, form submissions) neutralise formula
  injection; the `migrate-images` CLI tool blocks SSRF to private IPs.
- **Booking schema self-heal:** `gift_applied_cents` and the other 0.5.1
  payment columns are now reconciled even when the version was stamped
  early (`schemaIsCurrent()` checks them; a one-time pass adds them).

**Still open (deliberately deferred):**
- **Customer email verification is not a login gate.** An unverified
  customer can still log in (the dashboard shows a soft banner). Left as
  intentional UX to avoid locking out existing accounts — don't treat
  `email_verified` as an authorization signal in plugins.
- **Storefront checkout has no coupon field.** `ShopAPI::createOrder()`
  supports coupons but the public checkout never collects one, and cart
  vs. order totals are computed by two paths — unify before relying on
  storefront discounts.
- Logout is a GET with no CSRF token (low-impact session-only).

**Recently fixed (prior audit):**
- Flat-rate-shipping triggered a PHP 8.4 implicit-nullable deprecation
  on every request — now `?array $context`.
- Shop coupons editor crashed when creating a new coupon
  (`$editing['expires_at']` on null) — now null-safe.
- Forms threw "Undefined array key 'status'" because
  `CREATE TABLE IF NOT EXISTS` never adds columns to a pre-existing
  table — `FormsAPI::ensureSchema()` now reconciles missing columns.
- **Forms public submissions fatal-crashed** (`Unknown column
  'data_json'`): `ensureSchema()` only reconciled `forms_definitions`,
  not `forms_submissions`. Both tables now reconcile, plus a one-time
  legacy `data` → `data_json` backfill so old submissions still render.
- **Stripe webhook now rejects future-dated timestamps** via
  `abs(time() - $t) > tolerance` (was only rejecting too-old).
- **Shop/Stripe paid amount is now reconciled** against the rebuilt
  order total at webhook/return time. A mismatch (cart drifted between
  intent creation and completion) parks the order on `on-hold` for
  review — logged + audited — instead of silently fulfilling a wrong
  total. Matching charges advance to `processing` as before.
- **Shop order numbers** now derive from the row's auto-increment id
  (collision-safe, never reused after a delete) instead of `COUNT(*)+1`.
- **Booking** gained an admin month **calendar** (`admin/calendar.php`)
  and its READMEs were rewritten to the actual 0.5.1 feature set.

**Open / known:**
- **Two contact-form systems coexist:** the legacy core Contact Forms
  (`admin/contact_forms.php` + `contact_forms` tables) and the newer
  Forms plugin. Pick one; retire the other. *Deferred — removing the
  legacy system drops its tables (data loss) and there's no VCS here,
  so it needs an explicit go-ahead.*
- **Forms** (0.1.0) is early — the field editor is line-syntax, not the
  planned drag-and-drop builder. *Deferred (large feature).*
- **Booking** (0.5.1) is feature-complete for its manifest; remaining
  nice-to-haves: calendar drag-to-reschedule, Google/iCal sync,
  multi-timezone slot computation, waitlist, and membership plans.
- **`.env` contains live credentials** — keep it out of any
  distributable ZIP. (Other stray build/backup/log artifacts have been
  removed from the working tree.)
