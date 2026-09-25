# Slate — Full Project Audit

**Date:** 2026-06-01
**Scope:** Entire project — Slate core + all 10 bundled plugins (122 PHP files, ~38k lines, excl. vendor).
**Method:** Static read of every functional area; no code modified.

---

## 1. Executive summary

Slate is a lean, multi-tenant, flat-PHP application shell (PHP 8.4 / MySQL, no build step, PHPMailer the only vendored dependency) with a WordPress-style plugin system. The core ships an admin shell, auth + roles, settings, a customer portal, an audit log, a hook system, and a public router; all business functionality is delivered as uploadable ZIP plugins.

**Overall state: healthy and largely production-ready.** Core and the mature plugins (shop, stripe-payment, booking) are feature-complete. Recent work added two new plugins (`content-builder`, `seo`), upgraded Forms from an early stub to a full-featured form builder (0.1.0 → 0.5.0), and rolled a shared record-editor UI kit out across the booking admin.

**Only one genuine bug found:** `booking/admin/addons.php` loads the editor CSS but not the editor JS. Everything else is cosmetic polish, deliberate scope gaps, or decisions awaiting sign-off.

---

## 2. Plugin manifest table

| Plugin | Slug | Version | Status | Public routes |
|---|---|---|---|---|
| Shop | `shop` | 1.2.7 | ✅ Stable | storefront (via filter) |
| Stripe Payment Gateway | `stripe-payment` | 1.2.0 | ✅ Stable | webhook/return/success/create-intent |
| Shop email notifications | `shop-emails` | 1.0.0 | ✅ Stable | — |
| Media library | `media-library` | 1.0.0 | ✅ Stable | — |
| Flat Rate Shipping (USPS boxes) | `shipping-flat-rate` | 1.0.2 | ✅ Stable | — |
| Flat-rate shipping by weight | `flat-rate-shipping` | 1.0.0 | ✅ Stable | — |
| Booking | `booking` | 0.5.1 | ✅ Beta | `/book` |
| Forms | `forms` | 0.5.0 | ✅ Upgraded | `/forms/<slug>` |
| Content Builder | `content-builder` | 1.3.0 | 🆕 New | `/p/<slug>`, `/<type>/<slug>` |
| SEO | `seo` | 1.0.0 | 🆕 New | — (hooks only) |
| Hello World | `hello-world` | 1.0.0 | Example | (dist-only, not installed) |

> Two shipping plugins both register the `shop_shipping_rate` filter — **activate at most one at a time.**

---

## 3. Slate core

| Area | Files | State | Notes |
|---|---|---|---|
| Bootstrap & config | `config.php`, `.env`, `public.php`, `route.php`, `cron.php` | ✅ | Loads env, boots plugins, force-HTTPS + HSTS, session idle-timeout, Stripe webhook listener. `route.php` is the rewrite trampoline so `/includes/` stays 403'd. |
| Auth & roles | `includes/Auth.php` | ✅ | Admin + customer login, **per-IP** throttling/lockout, timing-attack mitigation, RBAC (core ∪ plugin manifests), Super Admin (id=1) read-only. |
| Admin shell | `admin/`, `admin/partials/` | ✅ | Dashboard, plugin manager (ZIP upload/activate/deactivate/uninstall), users CRUD + reset (self-protection guards), roles editor, settings (Profile/SMTP/Security/Branding/System), audit log, notification bell. |
| Customer portal | `customer/` | ✅ | register → verify → login → logout → forgot/reset → dashboard. |
| Core libs | `includes/` | ✅ | Database (PDO, prepared stmts), Hook, AuditLog, Mailer, I18n (3-layer), Uploads (MIME-checked), Plugin/PluginLoader, PublicRouter, ui_components, helpers (CSRF, AES-256-GCM secrets, safe-redirect). |
| Record-editor UI kit | `includes/record_editor.php` | ✅ | **Promoted to core.** Defines `slate_edit_*` / `slate_editor_*` (hero, card_open/close, day_row, toggle_row, switch, backlink, actionbar, tabs, css, js). Plugins alias to it. |
| UI/UX additions | `breadcrumbs.php`, `a11y_head.php`, `Notifications.php`, `admin/notifications-read.php` | ✅ | All fully wired (header/footer/login/install + topbar bell). |
| DB schema | `db/schema.sql` | ✅ | 12 core tables + lazy `slate_notifications` / `login_attempts`. |

**Core security baseline:** CSRF tokens (timing-safe), bcrypt + auto-rehash, per-IP throttling, AES-256-GCM secret encryption, prepared statements throughout, consistent `e()` escaping, open-redirect guard, MIME-validated uploads, plugin-boot isolation (exceptions caught).

---

## 4. Plugin functionality inventory

### Shop 1.2.7 — ✅ complete
Products with single-axis variants, gallery, dimensions, GTIN; categories; session cart; checkout w/ pluggable payment providers; orders + line items + status lifecycle; auto-created customers; coupons (percent/fixed, min-order, max-uses, expiry); revenue reports + top products; CSV import/export. Storefront: index, category, product (variant picker + gallery), cart, checkout, order (view-token protected against enumeration). ~40-method `ShopAPI`. Shipping via `shop_shipping_rate` filter (free-threshold first, then plugins, then legacy fallback).

### Stripe Payment 1.2.0 — ✅ complete
Generic gateway usable by any plugin. **Hosted Checkout** + **embedded Payment Element**, both behind one `StripePaymentAPI`. Webhook handler w/ HMAC-SHA256 signature verification + replay tolerance; cross-plugin charges ledger w/ refunds; test/live toggle; secret keys encrypted at rest (envelope `enc:v1:`), publishable key plaintext. Unique keys on `(payment_intent_id)` / `(session_id)` prevent double-insert.

### Shop Emails 1.0.0 — ✅ complete
4 templated emails (order-received, payment-confirmed, order-completed, admin new-order), overridable via settings, `{placeholder}` substitution, test send. Synchronous (no queue — fine ~20 orders/day). Customer-supplied fields escaped.

### Media Library 1.0.0 — ✅ complete
Filesystem-first image browser + picker modal (integrates into product/block editors). Scans products/gallery/variants/branding folders; caches dimensions; tracks usage and **refuses deletion of in-use images**.

### Shipping — shipping-flat-rate 1.0.2 (per-box) & flat-rate-shipping 1.0.0 (per-weight) — ✅ complete
Box plugin: admin defines box presets; greedy bin-packing + shrink-fit; USPS defaults; full tenant scoping. Weight plugin: weight-tier brackets; first-match rate; overflow tier (null max). **Both register `shop_shipping_rate` @ priority 10 → run only one.**

### Booking 0.5.1 — ✅ beta, feature-complete for its manifest
Services (categories, add-ons, custom fields, per-staff price/duration overrides, group price tiers, deposits/tax); providers (weekly hours, breaks, date overrides, M:N services); slot engine (race-safe `SELECT … FOR UPDATE`); locations + resources w/ conflict prevention; appointments (recurring, walk-in, capacity); admin month calendar; public `/book` widget (multi-step, fast-book, file upload, self-service cancel/reschedule); email + SMS/WhatsApp (Twilio) reminders & follow-ups via cron; Stripe payments (full/deposit), coupons, gift cards, refunds, invoices; GDPR export/delete. ~50-method `BookingAPI`.
*Nice-to-haves still open:* calendar drag-to-reschedule, Google/iCal sync, multi-timezone slots (provider TZ is currently informational), waitlist.

### Forms 0.5.0 — ✅ upgraded (was 0.1.0)
Public forms (`/forms/<slug>` + iframe embed), submissions inbox, HMAC-signed webhooks w/ **SSRF guard** (DNS-rebinding-safe), admin + submitter email, CSV export, honeypot + per-IP rate limit (5/min). **Newly implemented:** e-signature (draw/type → PNG), **PDF generation** (hand-written writer in `lib/FormsPdf.php`, no FPDF/TCPDF dependency — branded signed-agreement doc), conditional logic (`show_if`, client+server enforced), multi-step wizard, calc/formula fields, file upload. ~23 field types. *Still deferred:* payment field.

### Content Builder 1.3.0 — 🆕 new, complete
WordPress-style block CMS: custom **post types** + **taxonomies/terms**, **navigation menus** (header/footer), **12 block types** (heading, paragraph, image, button, columns, html, post-list, hero, icon-grid, image-grid, cta, testimonial), **theme + branding** (8 palettes, 6 system-font pairings, accent/radius tokens, inlined CSS — zero external requests). Public routing `/p/<slug>` + `/<type>/<slug>` with draft preview. 7 DB tables, seeds a demo landing page. Extensible via `content_register_blocks` / `content_edit_sidebar` / `content_head_tags` hooks. **Block editor is up/down reorder, not drag-drop (intentional, documented). No revisions table (documented as v2).**

### SEO 1.0.0 — 🆕 new, complete (deliberately scoped)
Per-page **meta title / description / canonical / OG / Twitter card / noindex** injected into `<head>` via content-builder hooks; site-wide defaults page; per-page values stored as content-builder post-meta; degrades gracefully if content-builder is inactive. **Out of scope (by design):** sitemap.xml, robots.txt, JSON-LD schema, redirects — no public routes declared.

---

## 5. Booking editor UI migration — detail

The shared kit lives in core (`includes/record_editor.php`); `booking/admin/_editor_ui.php` aliases `booking_edit_*` → `slate_edit_*`.

**9 of 9 editor pages migrated:** providers, services, resources, coupons, fields, giftcards, categories, locations, addons. Splices between edit-view and list-view are clean; no orphaned/duplicate markup.

- **8 pages clean.**
- **`addons.php` — BUG:** calls `booking_editor_css()` (line 93) but never `booking_editor_js()` before the footer (line 191) → toggle-reveal and live avatar/title don't run there. Fix: add `<?php booking_editor_js(); ?>` before the footer include.
- `providers.php` date-overrides block (~354–409) still uses old `.field-row` markup — cosmetic.

Remaining booking pages (settings, index, appointments, appointment, customers, calendar, invoice, new) are list/detail/dashboard/quick-form and legitimately don't need the kit.

---

## 6. Outstanding work

| # | Item | Location | Severity | Status |
|---|---|---|---|---|
| 1 | **`addons.php` missing `booking_editor_js()`** — editor JS doesn't run | `booking/admin/addons.php` | 🔴 Bug (1-line) | ✅ **Fixed** — added JS call (line 191) |
| 2 | content-builder `post-edit.php` "ContentBuilderAPI not found" | `content-builder/admin/post-edit.php:18` | 🟡 Verify | ✅ **Non-issue** — house pattern (all plugin admin pages do this); stale pre-activation hit |
| 3 | "Download PDF" button in Forms admin submission view | `forms/admin/submission.php` | 🟡 Polish | ✅ **Already wired** — actionbar → `?download=pdf` stream (audit false-positive) |
| 4 | Forms `formSettings()` undefined-key `'shape'` warning | `forms/FormsAPI.php:993` | 🟢 Cosmetic | ✅ **Fixed** — coalesce in true-branch |
| 5 | Add **sitemap.xml / robots.txt / JSON-LD** to SEO plugin | `plugins/seo/` | 🟢 Feature gap | ✅ **Done** — seo 1.1.0: routes + JSON-LD + admin robots override |
| 7 | **Repackage `_dist/` ZIPs** | `plugins/_dist/` | 🟢 Pre-distribution | ✅ **Done** — all 11 at current versions; stale dupes removed |
| 6 | Providers date-overrides block → kit markup | `booking/admin/providers.php:354–409` | 🟢 Cosmetic | ⏸️ Deferred — working form, kit has no generic field-row helper; churn > value |
| 8 | Two coexisting contact-form systems — pick one, retire other | core + forms | 🟡 **Decision** | ✅ **Deprecated** — legacy nav hidden + banner; tables/data kept (reversible) |
| 9 | Two shipping plugins both on `shop_shipping_rate` — no guard | shipping plugins | 🟡 **Decision** | ✅ **Done** — shipping-flat-rate 1.0.3 shows a dashboard conflict notice when both active |
| 10 | 2FA setting stored but no TOTP flow | `admin/settings.php` | 🟡 **Decision** | ✅ **Done** — removed the dead `two_factor_admin` store/read |
| 12 | Logout is GET without CSRF token (session-only) | core | 🟢 Minor | ✅ **Fixed** — logout now requires + verifies a CSRF token |
| 11 | Storefront checkout has no coupon field | `shop/storefront/checkout.php` | 🟡 **Decision** | ⏳ Open — needs cart/order total unification first (two code paths); own task |

---

## 7. Notes

- **`.env` holds live credentials** — keep it out of any distributable ZIP.
- **No VCS** in this working tree — any destructive change (dropping legacy contact-form tables, etc.) is irreversible; confirm before proceeding.
- `error_log` noise across the tree is from `/tmp/*.php` debug scripts, a `_verify.php` harness, and dev-time schema-mismatch warnings since resolved by self-healing `ensureSchema()` — not live production errors.
