# Kohevo — Nutrition Booking & Management

**Kohevo** is a white-label modular platform — website, clients, bookings,
sales and business management in a single, personalized environment.
("Slate" is this repository's internal engineering codename; product/
brand references outside the codebase should say Kohevo.)

> This is the **client application** of the Kohevo repository. System overview, licensing flow, releasing and guides:
> [root README](../README.md) · [documentation index](../docs/README.md) · [changelog](../CHANGELOG.md).

This build configures Kohevo as a nutrition practice management
application — client bookings, coaching programs, food and hydration
tracking, memberships and payments.

The spec defines a **strict two-level model**: a one-time nutrition
assessment never grants access to the programme app.

### Level 1 — Browser access

For every prospect and client before a programme. Booking, payments,
reminders, preparation and follow-up, with no download.
Plugins: `booking` (with its `service_rules` and `client_messaging`
capabilities), and `membership` controlling access.

### Level 2 — Downloadable app

For active clients of the 3-month Body & Soul programme. Daily tracking,
goals, chat, motivation, analytics and the final summary.
Plugin: `coaching`, on top of `membership` + `booking`.

### Appointment types

| Type | Duration | Payment |
|---|---|---|
| Discovery Call | 20 min | Free |
| Nutrition Assessment | 1 h | Full |
| Emotional Serenity Hypnosis | 2 h | Full |
| Spiritual Regressive Hypnosis (HSR) | 3 h 30 – 4 h | First hour at booking, balance on the day |
| Body & Soul follow-up | 30 / 45 min | Monthly |

HSR is gated: a configurable minimum advance delay (default 21 days) and
a mandatory Discovery Call beforehand.

---

## The platform underneath

A lean, multi-tenant PHP application shell with a WordPress-style
plugin system. The core ships an admin shell, auth + roles, settings,
a customer portal, an audit log, a hook system, and a public router.
Everything else — bookings, coaching, memberships, forms, payments — is
a plugin you upload as a ZIP through the admin.

- **Version:** see [`VERSION`](../VERSION) (`SLATE_VERSION` in `config.php`)
- **Runs on:** PHP 8.2+ (CI: PHP 8.3; also run on 8.4/8.5), MySQL 8.0+ / MariaDB 10.11+
- **No build step.** Vanilla PHP, vanilla CSS/JS. PHPMailer is the
  only Composer dependency (vendored).

---

## What's in this package

### Core

- ✅ Two-step `install.php` wizard (DB creds → admin account)
- ✅ Premium admin shell — dark sidebar with section labels, light
  canvas, blue accent, mobile bottom tab bar
- ✅ Admin login + dashboard
- ✅ **Card-row data lists** instead of HTML tables everywhere
- ✅ **Full plugin manager** — ZIP upload, activate, deactivate,
  uninstall, with cross-filesystem safe staging; `bin/package-plugin.php`
  CLI packager
- ✅ **Users CRUD** + password reset, with self-protection guards
  (can't demote/suspend/delete yourself or the last Super Admin)
- ✅ **Roles editor** — grouped permission cards; permissions are the
  union of the core list and active-plugin manifests; Super Admin
  (id=1) is read-only
- ✅ **Settings** (tabbed) — General, Branding (accent + logo upload),
  Business details, Email/SMTP (encrypted password + test send),
  Security (session timeout, login throttling, force-HTTPS), System
  (maintenance mode, timezone, version info)
- ✅ **Customer portal** — register, email verification, login,
  logout, forgot/reset password, resend verification, dashboard
  (`customer/`)
- ✅ **Public router** (`PublicRouter`) — plugins register URL
  prefixes (e.g. `/forms/…`, `/shop/…`, `/book`) served through
  `public.php`
- ✅ **Cron entry point** (`cron.php`, secret-protected) for plugin
  scheduled tasks (reminder emails, webhook retries, etc.)
- ✅ Hook system, AuditLog, Mailer (PHPMailer/SMTP), I18n with DB
  language overrides, Uploads helper, encrypted secrets
- ✅ Legacy **core Contact Forms** (`admin/contact_forms.php`) — a
  basic builder kept from the original Stage 1. Superseded by the
  Forms plugin below; see *Known issues*.

### Bundled plugins (`plugins/`)

Nine plugins ship in this build. Scope comes from *Solaya — Capacités &
Parcours / Capabilities & Journeys*, which names the application as
Booking, Booking+, Membership and Coaching. Booking has since absorbed
Booking+ outright — its capabilities are the four flags on the Booking
row below — so the spec's four features are delivered by three plugins,
with the rest as the dependencies they need.

| Plugin | Slug | Version | Role | What it covers |
|---|---|---|---|---|
| **Booking** | `booking` | 0.9.0 | Level 1 · Appointments & practitioner logic | Appointment types with duration, buffers, price and categories; availability with breaks and per-provider date overrides; conflict handling; embeddable `/book` widget with self-service cancel & reschedule; Stripe free / full / deposit / on-site; coupons, gift cards, tax, refunds, invoices; email + SMS/WhatsApp reminders. Four capability flags carry the absorbed Booking+ behaviour: `service_rules` (HSR minimum-advance days, Discovery-Call prerequisite, prep page, WhatsApp, auto-response), `client_messaging` (the client "human message" door and its 8-hour practitioner nudge), `slot_restrictions` (slots reserved per type) and `custom_reminders` (the 8-day / day-before / 10-minute cadence) |
| **Membership** | `membership` | 0.7.1 | Access control | Fixed-term plans with FR+EN names, pricing, duration and grace period; plan types (membership / insurance / course); member profiles with medical, allergies, emergency contact and consent, plus QR member card; wallet and transaction ledger; the active-membership gate wired into Booking that keeps a one-time assessment from ever unlocking the programme app |
| **Coaching** | `coaching` | 0.6.0 | Level 2 · Body & Soul app | Full profile with auto BMI/BMR, medical section and intolerances; 3-level goal tracker with "exceeded" and extra actions; food diary (meals, foods, photos, quantities); emotions with hunger/satiety context; hydration and physical activity; charts including the emotion↔food correlation; 1:1 chat with scheduled and immediate messages; meal structure, shopping list, two-way recipes and template library; challenges and end-of-programme summary; 1-year reactivation then automatic data deletion |
| **Stripe Payment Gateway** | `stripe-payment` | 1.2.1 | Payments engine | Backs every payment mode the spec requires — free, full, deposit and the HSR staged first-hour-then-balance flow — plus refunds and invoices |
| **Forms** | `forms` | 0.7.5 | Questionnaires | Backs the HSR preparation questionnaire (§5.2) and the interactive 7-day food-diary document sent before a Nutrition Assessment (§5.3) |
| **Slate Multi Language Visual Translation** | `multilang-translate` | 1.3.0 | Bilingual UI | Closes the §6 gap "Bilingual UI coverage — verify all Booking/Coaching strings." Harvests every rendered string for FR/EN translation |
| **Media library (compatibility shim)** | `media-library` | 1.1.0 | Media picker | Retained on purpose: core `Media::enqueuePicker()` serves its picker CSS/JS, and coaching's recipe and template library uses it to attach images |
| **Backups** | `backups` | 1.0.0 | Operations | Automated daily backup of the database and uploaded files synced to Google Drive, with retention pruning and a manual "run now" |
| **MCP Gateway** | `mcp-gateway` | 0.1.0 | AI administration | Scoped, revocable, audited MCP access for approved AI agents to manage admin tasks; never exposes password changes or user/role deletion |

Other Slate plugins (shop, CMS, SEO, timeclock, restaurant, studio, clientdesk …) are not part of this build.
Versions are read from each `plugins/<slug>/plugin.json`.

---

## Design language

Slate uses a dark-sidebar / light-canvas theme. Off-white canvas
(`#F5F4F1`), white cards on 1px hairline borders (no halo shadow),
near-black sidebar (`#0E1117`), blue accent (`#2563EB`) for primary
actions and active states. Typography is DM Sans (body), Syne
(display), DM Mono (code), from Google Fonts. 12px card radius, 44px
minimum tap targets on mobile, backdrop-blurred topbar.

Sidebar nav items are organised into uppercase section labels
(OVERVIEW, CONTENT, SYSTEM by default) via a `group` field; plugins
register their own groups (e.g. SHOP).

**Data lists use the card-row pattern, not HTML tables.** Each row is
a card (avatar + title + meta + status badge); tapping expands a
labeled key/value grid with optional actions. One row open at a time.
No horizontal scrollbars. Plugins get this free via `slate_data_row()`.

Detail pages use the right-rail layout — `slate_page_layout('with-aside')`
opens a CSS grid with a 320px aside hosting `.aside-card` blocks
(`.kv-list`, `.audit-trail`). All tokens and component classes are
documented in [`PLUGIN-API.md`](../02-licensing/docs/PLUGIN-API.md) §15 — reuse them rather than adding
parallel CSS.

## Responsive layout

- **Desktop (≥768px):** sidebar left, topbar across, main content, no
  tab bar.
- **Mobile (<768px):** topbar across, full-width content, bottom tab
  bar pinned to the viewport. First 4 nav items in the bar; the rest
  in a slide-up "More" sheet.

Vanilla CSS Grid + a single `@media (min-width: 768px)` breakpoint.

---

## File structure

```
slate/
├── install.php            Two-step install wizard
├── config.php             Bootstrap (loads .env, core includes, boots plugins)
├── public.php / route.php Public-router entry points
├── cron.php               Secret-protected cron runner
├── .env.example           Copy to .env (install.php does this)
├── .htaccess              Apache rewrites + hardening
│
├── admin/                 Admin interface (dashboard, login, plugins,
│                          users, roles, settings, audit, contact_forms…)
│   └── partials/          header.php (shell) + footer.php
├── customer/              Customer portal (register/verify/login/reset)
├── includes/              Core classes: Auth, Database, Hook, AuditLog,
│                          Mailer, I18n, Uploads, Plugin, PluginLoader,
│                          PublicRouter, ui_components, helpers…
├── db/
│   ├── schema.sql         12 core tables
│   └── migrations/        phase1.sql
├── lang/                  en.php + fr.php — core translations (DB can override)
├── templates/             Email/page templates
├── uploads/               File uploads (created at runtime)
├── bin/                   migrate, license-check, package-plugin, seed scripts, backup/restore
├── vendor/                PHPMailer — added when the package is built
└── plugins/               The 9 bundled plugins (see table above), each with its own lang/ pack
```

Plugin-building docs live in [`../02-licensing/docs/`](../02-licensing/docs/).

Core tables (`db/schema.sql`): `tenants`, `roles`, `role_permissions`,
`users`, `customers`, `customer_auth_tokens`, `settings`, `plugins`,
`audit_log`, `contact_forms`, `contact_form_submissions`,
`lang_overrides`. Each plugin creates its own tables on activation
(and self-heals via an `ensureSchema()` pattern).

---

## Deploying

1. Upload the contents to your web root.
2. Visit `/install.php`. Step 1: DB credentials + app URL. Step 2:
   admin name, email, password (8+ chars).
3. Log in. Go to **Plugins** to upload/activate plugins.
4. Round-trip test: download the example plugin from the Plugins page,
   re-upload it, activate it, and watch "Hello World" appear in the
   sidebar.

See `INSTALL.md` for the full walkthrough and [`CLIENT-ONBOARDING.md`](../02-licensing/docs/CLIENT-ONBOARDING.md)
for handing a finished site to a client.

## Packaging a plugin

```bash
php bin/package-plugin.php plugins/my-plugin           # → plugins/my-plugin-vX.Y.Z.zip
php bin/package-plugin.php plugins/my-plugin --dist     # → write into plugins/_dist/
```

The packager validates the manifest + SQL before zipping, so a ZIP
that passes is guaranteed to pass installation. Start from
[`BUILDING-PLUGINS.md`](../02-licensing/docs/BUILDING-PLUGINS.md).

## Requirements

- PHP **8.2+** (CI runs **8.3**; also run on 8.4/8.5)
- MySQL 8.0+ / MariaDB 10.11+ (the versions CI verifies; the Forms schema
  self-heal uses `information_schema`)
- PHP extensions: `pdo_mysql`, `mbstring`, `zip`, `openssl`, `fileinfo`,
  `curl` (Stripe/webhooks), `gd` (image dimensions)
- Apache with `mod_rewrite`, `mod_headers`, `mod_deflate` (the public
  router relies on rewrites); nginx needs equivalent config.

## Browser support

Modern CSS (Grid, custom properties, backdrop-filter). Current Chrome,
Safari, Firefox, Edge. iOS Safari 15+ and Android Chrome 90+ are
first-class. IE11 is not supported.

---

## Known issues

The security-hardening notes and open items that used to be listed here predate the licensing rebuild and mention
plugins that are no longer part of this build; they are kept for reference in
[`docs/03-implementation/LEGACY-KNOWN-ISSUES.md`](../docs/03-implementation/LEGACY-KNOWN-ISSUES.md). Current
status: [CHANGELOG](../CHANGELOG.md), [SECURITY.md](SECURITY.md) and the CI results.

## License

See the [root README](../README.md#license).
