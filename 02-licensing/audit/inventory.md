# Slate platform — factual inventory

Tree: `claude/slate-platform-audit-c95b8f` @ c3a90ba — this is `develop` **plus** the Phase C/D content-spine commits (63 files, ~4,200 insertions, touching `content-builder`, `forms`, `src/Presentation`, `tests/`). Findings that depend on those files are marked in `findings.md`.

~110,000 lines of PHP: 21 plugins, plus `admin/` (10,290), `src/` (10,447), `includes/` (5,333), `customer/` (1,224), `db/` (867).

Audited in depth: `booking`, `booking-plus`, `membership`, `coaching`, `content-builder`, `forms`, `react-site-bridge`, `restaurant`, `stripe-payment`, `shop`, `studio`, plus core primitives. Surveyed only: `clientdesk`, `timeclock`, `small-business-kit`, `sitehub`, `seo`, `slate-mcp`, `media-library`, `shop-emails`, and the two shipping plugins.

---

## 1. Core primitives

| primitive | location | notes |
|---|---|---|
| DB wrapper | `src/Data/Database.php` (alias `Database`) | prepared statements only; `::setting()`/`::setSetting()` are tenant-scoped |
| Tenant resolution | `includes/helpers.php:19-31` `current_tenant_id()` | override → super-admin session → `TENANT_ID` constant |
| CSRF | `includes/helpers.php:54-76` | session-based |
| Double-submit | `includes/helpers.php:85-128` | one-time tokens |
| Authorization | `src/Services/Auth/Auth.php` | `require()`, `requirePerm()`, `can()`, `requireCustomer()` |
| URL safety | `includes/helpers.php:173-190` `slate_safe_url()` | scheme allowlist |
| DB clock | `includes/helpers.php:193-213` `slate_db_now()` | exists because PHP≠MySQL timezone here |
| Secret encryption | `includes/helpers.php:216-242` | AES-256-GCM, `enc:v1:` envelope, keyed on `APP_SECRET` |
| Public routing | `src/Kernel/Http/PublicRouter.php` | sets `$_GET['_route_path']`; 404s at `:119` |
| Identity spine | `db/migrations/0002_identity_core.php` | `contacts`, `contact_emails`, `contact_phones`, `identities`, `identity_tokens` |

### The tenancy model — important context for every scoping finding

There is **no host-based tenant resolution anywhere in the platform**. `current_tenant_id()` (`includes/helpers.php:19-31`) and `TenantContext::id()` (`src/Tenancy/TenantContext.php:45-54`) both resolve to the `TENANT_ID` constant from `.env` (`config.php:46`) unless a super-admin is impersonating via `$_SESSION['slate_override_tenant']` or a CLI job used `with_tenant()`. The `tenants` table (`db/schema.sql`) has no domain or host column.

**One deployment therefore serves exactly one tenant.** The `tenant_id` columns are the isolation model for a *shared database* across multiple deployments. This materially bounds the tenant-scoping findings below: they are real holes in that isolation model, exploitable where two deployments share one database, and inert on a single-tenant install.

---

## 2. Person tables — six independent models

| table | source | key person columns | linked to core? |
|---|---|---|---|
| `customers` | `db/schema.sql` | email, name, phone | — (is the core record) |
| `contacts` + `contact_emails` + `contact_phones` + `identities` | `db/migrations/0002_identity_core.php` | display_name, primary_email, primary_phone | canonical spine |
| `booking_customers` | `plugins/booking/install.sql` | email, name, phone, booking_count | nullable `customer_id` |
| `shop_customers` | `plugins/shop/install.sql` | email, name, address | no FK |
| `restaurant_customers` | `plugins/restaurant/install.sql` | name, email, phone | no FK |
| `forms_contacts` | created at runtime in `plugins/forms/FormsAPI.php` | email, name, phone | no FK |
| `clientdesk_clients` | `plugins/clientdesk/install.sql` | email | no FK (surveyed only) |
| `timeclock_employees` | `plugins/timeclock/install.sql` | — (surveyed only) | — |

**No foreign keys exist between any of these.** `booking_customers.customer_id` is the only link and it is nullable.

**Only `plugins/studio` uses the identity spine** (`StudioAPI.php:344`, `:361`, `:380`, `:389`, `:528`, `:531`, `:565`, `:597`, `:619`, `:653`, `:792`, `:839`; `admin/families.php`, `admin/classes.php`).

**Membership and coaching duplicate neither** — both key off core `customers.id` and say so in their install headers. What they duplicate is *profile attributes*:

| attribute | membership | coaching |
|---|---|---|
| DOB | `membership_profiles.dob DATE` | `coaching_profile.dob DATE` |
| gender | `ENUM(...) NOT NULL DEFAULT 'undisclosed'` | `ENUM(...) NULL` |
| allergies | `allergies TEXT` | `intolerances JSON` |
| medical | `medical_notes TEXT` | `pathologies` / `ongoing_care` / `alternative_medicine` / `personal_issues` |
| emergency contact | 3 VARCHAR columns | `therapist_contact JSON` |

---

## 3. Settings surfaces

Keys are flat strings in the tenant-scoped `settings` table, conventionally `<plugin>.<key>`.

**One key is written by more than one screen:** `booking.reminder_leads` — by `plugins/booking/admin/settings.php:30`, by `plugins/booking-plus/admin/settings.php:28`, and by `plugins/booking-plus/BookingPlus.php:59` on every boot (`BookingPlus.php:24`). The boot seed runs last and wins. See finding H-4.

Every other plugin audited writes its own namespace exclusively. Cross-plugin **reads** are common and deliberate (`brand_accent_color`, `brand_logo_path`, `site_name`, `stripe-payment.*`).

`APP_SECRET` is consumed as an HMAC key at four sites and as an encryption key at two. See finding C-1.

---

## 4. Message / notification composition

| plugin | sites |
|---|---|
| booking | `BookingAPI.php:1416` confirmation, `:1451` reminder, `:1471` follow-up, `:1481` staff, `:1498` cancellation, `:1510` reschedule; SMS/WhatsApp via Twilio `:1550-1602`; cron `Booking.php:315` |
| booking-plus | therapist notify (`public/message.php`), nudge cron (`BookingPlus.php:380`), per-service reminder templates (`BookingPlus.php:295-330`) |
| membership | `MembershipAPI.php:678` purchase, `:699` cancel; expiry reminders guarded by `reminder_7d_sent`/`reminder_3d_sent` |
| coaching | in-app only (`coaching_thread` / `coaching_message`) |
| forms | admin notification, autoresponder, webhooks (`FormsAPI.php:2450+`), dispatch at `public/router.php:577` |
| shop | via `shop-emails/ShopEmails.php` |
| restaurant | own confirmations |
| core | `includes/Mailer.php` (PHPMailer + SMTP settings), `includes/Notifications.php` (topbar bell) |

---

## 5. Duplicated helpers

| job | implementations |
|---|---|
| **CSRF** | core session-based (`helpers.php:54-76`) — used by admin, restaurant storefront, forms, booking; **shop storefront HMAC** (`shop/storefront/includes/layout.php:127-139`) |
| **Currency formatting** | booking per-file closures (`admin/invoice.php:46` and others); `MembershipAPI::money():142`; shop's own |
| **Currency modelling** | per-service (`booking_services.currency`); per-order-only (`shop_orders.currency`, no product column); none at all (restaurant) |
| **Slug generation** | `ContentBuilderAPI::slugify()`; `FormsAPI::slugify():2311`; `BookingAPI::slugify():1602` |
| **Template placeholders** | `BookingAPI::renderTemplate():1535`; `BookingPlusAPI::renderTemplate()` (`:152`, documented as extending it) |
| **Media URL building** | `ContentBuilderAPI::mediaUrl():38`; core `Slate\Services\Media\Media`; `MediaKeyResolver` (new); RSB's own |
| **Person storage** | six tables, section 2 |
| **Manage-token lookup** | five copies — `booking/admin/invoice.php:20`, `booking/public/router.php:1127`, `:1238`, `booking/public/pay-intent.php:65`, `booking-plus/public/message.php:37` (the diverged one) |
| **Site lookup by id** | three copies — `ReactSiteBridgeAPI.php:48`, `:53` (the diverged one), `:565` |

Genuinely *not* duplicated, checked and cleared: the two shipping plugins are deliberate alternatives with an abstention contract and a conflict warning (`ShopAPI::calculateShipping:562-579`, `ShippingFlatRate.php:86-130`); `StripeAPI::verifyWebhookSignature()` delegates to `StripePaymentAPI` rather than copying; booking-plus reuses `BookingAPI::effectiveIntervals()` rather than reimplementing slot maths.

---

## 6. Authorization coverage

**Every admin screen in all ten audited plugins calls `Auth::require()` + `Auth::requirePerm()`.** 21 booking/booking-plus, 13 membership/coaching, 19 content-builder/forms/RSB, and all restaurant/stripe/shop screens. No screen relies on nav visibility alone. This contradicts a browser-level assumption and is recorded so it is not re-investigated.

**CSRF** is verified on every state-changing admin POST across all audited plugins, and on the public POST paths in booking (`router.php:60`), booking-plus (`message.php:53`), membership (`router.php:41`), forms (`router.php:114`) and restaurant (4 sites). The one omission, `booking/public/pay-intent.php`, is not CSRF-relevant: POST-only, gated by a 32-hex unguessable token, no session-authorised action.

---

## 7. Migrations and uninstall

- Core migrations (`db/migrations/*.php`) implement `up()`/`down()` — e.g. `0002_identity_core.php:97-105`.
- **Plugin migrations are plain forward-only `.sql`** with no rollback counterpart: `booking/migrations/{0.2.0,0.4.0,0.5.0,0.5.1}.sql`, `shop/migrations/1.1.0.sql`, `clientdesk/migrations/{2.0.0,2.1.0}.sql`, `timeclock/migrations/1.0.0.sql`.
- Uninstall completeness: **booking drops 5 of 16 tables** (finding M-5). booking-plus, membership, coaching, forms, content-builder, RSB, restaurant, shop all drop their full set.
- No uninstall script removes uploaded **files**, which outlive every table drop.

---

## 8. Dead weight and stubs

- **`multilang-translate` does not exist.** The brief lists it as running on the live tenant; there is no such directory and no `multilang` reference anywhere in the tree.
- **Zoom `api` mode** is selectable and persisted but has no implementation (`booking-plus/admin/service.php:238`, `BookingPlusAPI.php:86`, `BookingPlus.php:314-317`). Finding M-6.
- **Stripe Terminal is NOT a stub** — checked; `StripeTerminalAPI.php` is a real REST implementation backed by `restaurant_readers`.
- Debug/scratch files at repo root, apparently unreferenced: `_append.php`, `_auditcheck.php`, `_fsc.php`, `_inspect_subs.php`, `_media_list.php`, `_short.php`, `_themetest.php`.
- "Phase 1.5" markers: `booking-plus/admin/service.php:164`, `:238`; `booking-plus/admin/services.php:76`. Notably few TODO/FIXME markers overall — the codebase carries its debt in docblocks rather than tags.
