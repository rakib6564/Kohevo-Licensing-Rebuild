# Current Implementation — Load-Bearing Behaviors

**Status:** Living reference · **Describes:** observable behavior that MUST be
preserved through the refactor.

This is the **preservation contract**. Where [gotchas-and-preservation-notes.md](gotchas-and-preservation-notes.md)
captures *things to know*, this captures *behaviors that must not change* — even
when the implementation behind them is rewritten. Each item is an **observable
guarantee**: internals may move (Phase 1–4), but the guarantee holds at every
commit. Every Phase-1+ change is validated against this list plus the smoke suite.

> **Rule:** a refactor MAY change *how* a behavior is implemented; it MUST NOT
> change *whether* the behavior holds. If a change would break one of these,
> it needs an explicit decision (ADR) and a migration, not a silent break.

---

## 1. What currently works and must not break

### Install & bootstrap
- A fresh upload to a `/slate/` sub-path installs via the two-step wizard and comes
  up working. The base-path deployment MUST keep working.
- Every existing entry point (`admin/*.php`, `customer/*.php`, `public.php`,
  `cron.php`, `install.php`) MUST continue to resolve and respond during migration
  (the front controller is introduced *alongside*, not as a replacement, until
  parity).

### Admin
- Admin login → dashboard → Plugins / Users / Roles / Settings / Audit / Media /
  Notifications all function. Self-protection guards hold: **you cannot
  demote/suspend/delete yourself or the last Super Admin; Super Admin (id 1) is
  read-only.**
- Plugin manager: ZIP upload → install → activate → deactivate → uninstall, with
  cross-filesystem-safe staging. Deactivate never destroys data.

### Customer portal
- Register → email verify → login → dashboard → forgot/reset all work.
  **Unverified customers can still log in** (soft banner) — this is intentional and
  MUST be preserved (do not turn `email_verified` into a login gate without an ADR).

### Storefront / booking / forms (public)
- Shop storefront (index/category/product/cart/checkout/order) works; order pages
  remain enumeration-protected by view-token.
- Booking `/book` widget: multi-step, fast-book, custom fields, file upload,
  self-service cancel/reschedule.
- Forms `/forms/<slug>` + iframe embed; submissions inbox; e-signature; PDF export.

### Payments
- Stripe checkout (hosted + embedded) completes and reconciles. **All existing
  payment-safety behaviors hold** (§4).

## 2. Existing compatibility expectations

These are relied on by live data and by installed plugins; breaking them silently
corrupts state or fatals a plugin.

- **Global class names resolve.** `Database`, `Auth`, `Hook`, `PluginLoader`,
  `AuditLog`, `I18n`, `Media`, `Notifications`, and every plugin `*API`
  (`ShopAPI`, `BookingAPI`, …) MUST remain callable by their current global names
  (via `class_alias` once migrated). Every `class_exists('XAPI')` check MUST keep
  returning the same answer.
- **The `plugins` registry is the source of truth** for what boots; its shape
  (`slug`, `version`, `status`, `manifest_json`) MUST stay readable.
- **Settings keys keep their current strings** — including the drifted ones
  (`shop_email.*`, `seo_settings` table). A rename is a data migration, not an
  edit.
- **Existing DB columns and table names persist** until a migration moves them with
  a backfill. In particular the five person tables, the `booking_*` set, and shop's
  `DECIMAL` money columns stay until Phase 2/3 migrations replace them.
- **`current_tenant_id()` resolution order** (CLI override → super-admin switch →
  `TENANT_ID`) MUST be preserved so scoping and tenant-switch tooling keep working.
- **Hook names and their payloads** (the 43 current hooks — see
  [runtime-catalogues.md](runtime-catalogues.md)) MUST keep firing with the same
  arguments so plugin listeners keep working.

## 3. Important plugin interactions (must keep functioning)

- **Shipping via `shop_shipping_rate`**: Shop consumes the first shipping plugin's
  rate; the free-shipping-threshold path runs first. Exactly one shipping plugin
  active. This contract MUST hold.
- **Payments via provider guard**: consumers call `StripePaymentAPI` behind
  `class_exists` + `isActive('stripe-payment')` and degrade gracefully when absent.
  Graceful degradation MUST be preserved (a missing dependency degrades, never
  fatals).
- **Blocks via `content_register_blocks`**: Shop and others register storefront
  blocks into content-builder's `BlockRegistry`; order-independent registration
  MUST keep working.
- **Media picker**: the shop product editor (and others) use the core media picker
  via the `media-library` shim. The shim MUST stay active/available.
- **Membership ↔ Booking**: membership integrates booking via the `booking_can_book`
  filter and reads booking data; this cross-module path MUST keep working.
- **SEO ↔ content-builder**: SEO reads/writes content-builder post-meta and injects
  `<head>` via `content_head_tags`; degrades if content-builder is inactive.
- **Stripe → notifications bridge**: `stripe_webhook_event` surfaces a topbar
  notification. Preserve.

## 4. Payment & data-safety invariants (never weaken)

- Captured amount is reconciled against the rebuilt order total before fulfillment;
  a mismatch parks the order `on-hold` (never silently fulfills a wrong total).
- Charges ledger unique keys prevent double-insert on concurrent webhooks.
- `return.php` is bound to the buyer's `shop_sid`; booking `/book/done` verifies
  `metadata.booking_appt_id`.
- Stripe webhook rejects both too-old and future-dated timestamps.
- Gift-card debit and coupon `max_uses` are atomic; the slot engine uses row locks.
- Shop order numbers derive from the auto-increment id (never reused/collision).

## 5. Security guarantees (never regress)

- Per-IP login throttling; dummy `password_verify` on unknown user; CSRF on
  mutations incl. logout; open-redirect guard; prepared statements everywhere.
- Secrets encrypted at rest (AES-256-GCM); upload MIME checks + PHP-off dirs; SVG
  upload disabled; SSRF guard on outbound webhooks; CSV formula-injection
  neutralized; `force_https`+HSTS when enabled.
  (Full detail: [security-as-built.md](security-as-built.md).)

## 6. UX guarantees (visual/interaction contracts)

- No horizontal scrollbars anywhere; content wraps.
- Data lists use the card-row pattern; one row expanded at a time.
- Fixed modals escape their panel (never trap `position:fixed` under a
  `backdrop-filter` panel).
- Tenant branding accent renders (not default blue) on every surface.
- All error pages (403/404/500) are branded via `includes/error_page.php`.

---

## How this list is used

- **Every Phase-1+ commit** is checked against these guarantees + the smoke suite
  before merge.
- As the smoke/test suite grows ([12-Testing](../12-Testing/)), items here become
  **automated assertions** (money reconciliation, tenant isolation, alias
  resolution, login throttling) so preservation is enforced, not just documented.
- If a guarantee must genuinely change, it goes through an **ADR + migration** —
  never a silent break.
