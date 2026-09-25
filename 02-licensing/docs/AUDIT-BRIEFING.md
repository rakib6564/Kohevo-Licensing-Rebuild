# Slate — Architecture Audit Briefing

**Prepared:** 2026-07-27
**For:** External architecture / security / performance / SEO audit
**Snapshot method:** Live read of the working tree + plugin registry (not the older `AUDIT.md`, which predates 9 of the current plugins).

> Companion docs already in the repo: `README.md` (overview), `AUDIT.md`
> (2026-06-01 internal audit — still accurate for core & the mature
> plugins, stale on the plugin roster), `INSTALL.md`, and everything under
> `docs/` (`PLUGIN-API.md`, `BUILDING-PLUGINS.md`, `CLIENT-ONBOARDING.md`).

---

## 1. What Slate is

A lean, **multi-tenant, flat-PHP application shell** with a WordPress-style
plugin system. The **core** ships an admin shell, auth + RBAC, settings, a
customer portal, an audit log, a hook system, a media library, and a public
router. **All business functionality is delivered as uploadable ZIP plugins.**

- **No build step.** Vanilla PHP, vanilla CSS/JS.
- **One vendored dependency:** PHPMailer (Composer, committed under `vendor/`).
- Core version **1.0.0**; installed 2026-05-28.

---

## 2. Technology stack

| Layer | Choice |
|---|---|
| Language | **PHP 8.4.23** (CLI confirmed; code targets 8.1+) |
| DB | **MySQL / MariaDB**, InnoDB, `utf8mb4_unicode_ci`, PDO + prepared statements |
| Web server | **Apache** + `mod_rewrite` / `mod_headers` / `mod_deflate` (`.htaccess` in repo) |
| Hosting | CloudLinux cPanel (EA-PHP), served under a **`/slate/` subpath**, not domain root |
| Front end | Server-rendered PHP; vanilla CSS Grid + custom properties; no framework, no bundler |
| Mail | PHPMailer over SMTP (settings-table driven; SMTP OAuth support in `SmtpOAuth.php`) |
| Payments | Stripe (via the `stripe-payment` plugin), Twilio SMS/WhatsApp (booking) |
| Required PHP ext | `pdo_mysql`, `mbstring`, `zip`, `openssl`, `fileinfo`, `curl`, `gd` |

**Size:** ~314 PHP files, ~88k lines (excluding `vendor/`).

**Config / secrets:** `.env` (dev) or hosting env vars → `config.php`.
Keys: `APP_URL`, `TENANT_ID`, `APP_SECRET` (AES-256-GCM + HMAC), `CRON_SECRET`,
`DB_*`. **`.env` holds live credentials — must never ship in a distributable ZIP.**

---

## 3. Folder structure

```
slate/
├── install.php          Two-step install wizard (DB creds → admin account)
├── config.php           Bootstrap: loads .env, requires core includes, boots plugins
├── public.php / route.php  Public-router entry points (rewrite trampoline)
├── cron.php             Secret-protected cron runner (plugin scheduled tasks)
├── index.php            Landing / root
├── .htaccess            Apache rewrites + hardening
│
├── admin/               Admin UI: dashboard, login, plugins, users, roles,
│   │                    settings, audit, media, notifications, diag, help
│   └── partials/        header.php (shell) + footer.php
├── customer/            Customer portal (register/verify/login/reset/dashboard)
├── includes/            Core classes (see §5)
├── db/
│   ├── schema.sql       14 core tables (see §4)
│   └── migrations/
├── docs/                PLUGIN-API.md, BUILDING-PLUGINS.md, CLIENT-ONBOARDING.md, plans
├── lang/                Base translations (en) — DB can override
├── templates/           Email / page templates
├── uploads/             Branding, shop, media, plugin staging (per-dir PHP-off .htaccess)
├── bin/                 package-plugin.php, seed-demo.php, clean-demo.php
├── vendor/              PHPMailer only
└── plugins/             19 plugins + _dist/ (upload-ready ZIPs)
```

> **Audit note — stray root files:** several `_*.php` debug/inspection
> scripts (`_append.php`, `_short.php`, `_media_list.php`, `_auditcheck.php`,
> etc.), `admin/diag.php`, `admin/opcache-reset.php`, `admin/repair-settings.php`,
> and a large `error_log` live in the tree. Worth flagging for
> production hygiene (remove or access-gate before shipping).

---

## 4. Database schema

**Core = 14 tables** (`db/schema.sql`, idempotent `CREATE TABLE IF NOT EXISTS`).
Every domain table carries a `tenant_id` (default 1) for multi-tenant scoping.

| Table | Purpose |
|---|---|
| `tenants` | Tenant registry (single-tenant installs still have row id=1) |
| `roles` | Named roles; Super Admin = id 1 (all perms via short-circuit) |
| `role_permissions` | `<domain>.<action>` keys; core ∪ plugin-declared |
| `users` | Admin/staff logins (FK → roles) |
| `customers` | Portal end-users (booking/shop/etc.); `email_verified` flag |
| `customer_auth_tokens` | Single-use hashed verify/reset tokens (SHA-256) |
| `settings` | Tenant-scoped key/value; plugins use `<slug>.` prefix |
| `plugins` | Install registry + `manifest_json`; PluginLoader reads every request |
| `audit_log` | Actor/action/target/meta/ip trail |
| `contact_forms` / `contact_form_submissions` | **Legacy** core forms (superseded by Forms plugin — see §9) |
| `lang_overrides` | Admin-editable per-locale string overrides |
| `media_files` / `media_usage` | Core media library + reference tracking (blocks deletion of in-use files) |

Two tables self-install lazily outside `schema.sql`: `slate_notifications`
and `login_attempts` (throttling). `media_files`/`media_usage` self-heal via
`Media::ensureSchema()` on boot.

**Per-plugin tables** (created at activation, self-healed via an
`ensureSchema()`/column-reconcile pattern — because `CREATE TABLE IF NOT
EXISTS` never adds columns to a pre-existing table):

| Plugin | Tables | Plugin | Tables |
|---|---|---|---|
| coaching | 16 | booking | 14 |
| clientdesk | 14 | restaurant | 13 |
| content-builder | 7 | forms / membership / shop / timeclock | 5 each |
| booking-plus / sitehub / survey-pipeline | 3 each | stripe-payment | 2 |
| seo / shop / shipping / media-library | 1 each | shop-emails / small-business-kit | 0 (hooks only) |

**No ERD file exists** — schema is authoritative in `db/schema.sql` + each
`plugins/<slug>/install.sql`. An auditor wanting an ERD should generate it
from those files.

---

## 5. Core system overview

### Bootstrap (`config.php`)
Loads `.env` → defines constants → requires all `includes/` classes →
enforces **force-HTTPS + HSTS** (Security setting) → starts session →
`Media::ensureSchema()` → **`PluginLoader::boot()`** → registers the
Stripe-webhook → notifications bridge. Errors are logged, never displayed.

### Routing
- **Admin:** direct PHP files under `admin/` (`admin/plugins.php`, etc.).
- **Public:** `PublicRouter` — plugins register URL prefixes (`/book`,
  `/forms/<slug>`, `/shop`, content-builder's `/p/<slug>` and `/<type>/<slug>`).
  Served through `public.php`; `route.php` is a rewrite trampoline so
  `/includes/` stays 403'd even on subdir deployments.
- **Cron:** `cron.php`, gated by `CRON_SECRET`, dispatches plugin scheduled
  tasks (reminder emails, webhook retries, follow-ups).

### Plugin system (`includes/Plugin.php`, `PluginLoader.php`)
- Each plugin = a folder with `plugin.json` (manifest: slug, version,
  permissions, nav, routes), a bootstrap PHP with a `boot()`, `install.sql`,
  and its own `admin/` + `storefront/`/`public/` pages.
- Lifecycle: **ZIP upload → install → activate → deactivate → uninstall**,
  all from `admin/plugins.php`, with cross-filesystem-safe staging.
- `bin/package-plugin.php` validates manifest + SQL before zipping (a ZIP
  that passes is guaranteed to install). Pre-built ZIPs in `plugins/_dist/`.
- **Hook system** (`Hook.php`): WordPress-style `addAction`/`addFilter`.
  Plugins extend the shell (nav groups, dashboard widgets, shipping rates,
  content blocks, SEO head tags) purely through hooks. Plugin boot is
  exception-isolated — one plugin crashing doesn't take down the shell.

### Auth & permissions (`includes/Auth.php`)
- Separate admin (`users`) and customer (`customers`) login flows.
- **Per-IP login throttling / lockout** (default 10 attempts / 15 min),
  enforced for both flows; dummy `password_verify` on the no-such-user path
  to flatten the enumeration timing oracle.
- **RBAC:** permission = union of core keys + active-plugin manifest keys.
  Super Admin (role id 1 / user id 1) short-circuits to all permissions and
  is read-only in the roles editor. Self-protection guards (can't
  demote/suspend/delete yourself or the last Super Admin).
- bcrypt with auto-rehash; CSRF tokens (timing-safe compare); AES-256-GCM
  encryption for secrets at rest via `slate_encrypt_secret()`; open-redirect
  guard (`slate_safe_redirect_target()`); MIME-validated uploads.

### Core libraries (`includes/`)
`Auth`, `Database` (PDO), `Hook`, `AuditLog`, `Mailer` (+`SmtpOAuth`),
`I18n` (3-layer: file → DB overrides → runtime), `Uploads`, `Media`,
`Notifications`, `Plugin`/`PluginLoader`, `PublicRouter`, `ui_components`,
`record_editor` (shared admin editor UI kit), `helpers` (CSRF, encryption,
safe-redirect, escaping), `landing`, `error_page`, `breadcrumbs`, `a11y_head`.

---

## 6. Plugin roster (current — live registry)

19 plugins installed. **8 active**, 11 inactive. Note this is far beyond the
10 plugins in the June `AUDIT.md`.

### Active
| Plugin | Ver | What it does |
|---|---|---|
| `booking` | 0.5.1 | Full appointments: services/add-ons/custom fields, providers w/ hours & overrides, capacity/recurring/walk-in, locations+resources, admin calendar, `/book` widget, email+SMS/WhatsApp reminders, Stripe pay, coupons/gift cards, GDPR export |
| `booking-plus` | 0.2.0 | Booking add-on layer (see `docs/bookingplus-phase1.md`) |
| `coaching` | 0.6.0 | Coaching program (16 tables — largest plugin; `docs/coaching-phase2.md`) |
| `content-builder` | 1.6.1 | WP-style block CMS: post types, taxonomies, menus, 12 block types, theme/branding, `/p/<slug>` routing |
| `forms` | 0.7.5 | Form builder: ~23 field types, e-signature, PDF gen, conditional logic, multi-step, webhooks w/ SSRF guard, rate-limited |
| `media-library` | 1.1.0 | **Compatibility shim** — media is now core (`includes/Media.php`); this remains a required adopter |
| `membership` | 0.7.1 | Fixed-term membership billing on core customers; integrates booking + Stripe via a MembershipAPI facade |
| `small-business-kit` | 0.1.0 | Hooks-only kit (0 tables) |

### Inactive (installed, available)
`clientdesk` 2.1.2 · `shop` 1.2.7 · `stripe-payment` 1.2.0 · `shop-emails` 1.0.0 ·
`shipping-flat-rate` 1.0.2 · `flat-rate-shipping` 1.0.0 · `seo` 1.1.0 ·
`restaurant` 0.2.0 (planned SpotOn-style, single-location) · `sitehub` 1.0.0 ·
`survey-pipeline` 1.0.0 · `timeclock` 1.2.0

> **Conflict to know:** `shipping-flat-rate` (per-box) and `flat-rate-shipping`
> (per-weight) both register `shop_shipping_rate` — activate at most one.

---

## 7. Installation & setup

1. Upload contents to web root (here: the `/slate/` subpath).
2. Visit `/install.php`. **Step 1:** DB creds + app URL. **Step 2:** admin
   name/email/password (8+ chars). Writes `.env`, runs `schema.sql`, drops
   `.installed`.
3. Log in at `admin/`, go to **Plugins** to upload/activate.
4. Round-trip smoke test: download the example plugin, re-upload, activate.

Full walkthrough in `INSTALL.md`; client-handoff steps in
`docs/CLIENT-ONBOARDING.md`. **Always smoke-test with the `/slate/` prefix.**

---

## 8. Admin & user workflows

- **Admin (`/admin/`):** login → dashboard (KPI widgets plugins inject via
  `admin_dashboard_widgets`) → manage Plugins, Users, Roles, Settings
  (General/Branding/Business/SMTP/Security/System tabs), Audit log, Media,
  Notifications bell. UI is **glassmorphism** (`--glass-*` tokens); data lists
  use the **card-row pattern**, not HTML tables; detail pages use a right-rail
  layout. Shared editor kit in `includes/record_editor.php`.
- **Customer (`/customer/`):** register → email verify → login → dashboard →
  forgot/reset. Note: **email verification is not a login gate** (soft banner
  only) — do not treat `email_verified` as an authorization signal.
- **Public:** branded landing (`includes/landing.php`), plugin storefronts /
  booking widget / forms, all error pages branded via `includes/error_page.php`.

---

## 9. Known limitations & areas to focus the audit

**Deliberately deferred (documented decisions):**
- **Two contact-form systems coexist** — legacy core (`admin/contact_forms.php`
  + `contact_forms` tables, nav hidden/deprecated) and the Forms plugin.
  Retiring the legacy one drops its tables (data loss) and **there is no VCS
  in this tree**, so it needs explicit sign-off.
- **Customer email verification is not an auth gate** (intentional UX).
- **Storefront checkout has no coupon field** — `ShopAPI::createOrder()`
  supports coupons, but cart vs. order totals are computed by two code paths;
  unify before relying on storefront discounts.
- **Forms** field editor beyond drag-drop, **Booking** nice-to-haves
  (calendar drag-reschedule, Google/iCal sync, multi-timezone slots, waitlist).

**Good places to point the auditors:**
- **Security:** Stripe completion/reconciliation paths, webhook replay
  handling, Forms webhook SSRF guard, tenant-isolation on the newer plugins
  (`coaching`, `clientdesk`, `restaurant`, `membership` — these post-date the
  June audit and haven't had the same scrutiny), upload MIME handling, the
  stray `_*.php`/`diag.php`/`opcache-reset.php` debug scripts.
- **Performance:** `PluginLoader` reads the registry every request; each
  active plugin's `ensureSchema()` runs on boot; synchronous email sends (no
  queue); no caching layer / opcache assumptions; media library scans.
- **Scalability:** multi-tenant is structural (every table scoped) but
  untested at scale — single DB, no read replicas, session-based cart.
- **SEO:** `seo` plugin (1.1.0, currently **inactive**) covers meta/OG/Twitter/
  canonical/noindex + sitemap.xml/robots.txt/JSON-LD; content-builder inlines
  CSS (zero external requests). SSR means content is crawlable. Worth checking:
  it's inactive today, so the live site has no managed meta.
- **Code quality:** ~88k lines, no automated tests, no VCS in the working
  tree, self-healing schema pattern (resilient but implicit), heavy reliance
  on hooks and conventions documented in `docs/PLUGIN-API.md`.

**Operational cautions for the auditor:**
- **No version control in this tree** — any destructive change is irreversible.
- `error_log` noise is mostly dev-time schema-mismatch warnings since resolved
  by self-healing, plus `/tmp` debug scripts — not live production errors.
- Plugin CSS/JS sit behind a 7-day Cloudflare cache; `PluginLoader` cache-busts
  by mtime (`?v=`), so test the `?v=` URL, not the bare one.
