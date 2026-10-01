# Kohevo — Client Platform & Central Licensing Server

[![CI](https://github.com/rakib6564/Kohevo-Licensing-Rebuild/actions/workflows/ci.yml/badge.svg)](https://github.com/rakib6564/Kohevo-Licensing-Rebuild/actions/workflows/ci.yml)
![Version](https://img.shields.io/badge/version-1.6.2-blue)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)
![Languages](https://img.shields.io/badge/languages-EN%20%7C%20FR-informational)

**Kohevo** is a white-label practice-management platform — website, clients, bookings,
memberships, forms and payments in one branded environment — sold to businesses as a
**licensed product**. This repository holds both halves of that system:

| | Folder | What it is | Deployed to |
|---|---|---|---|
| **Client** | [`01-client/`](01-client/) | The application a customer installs on their own hosting: admin, customer portal, public booking widget, forms, memberships, coaching, Stripe payments. | One install per customer (e.g. `app.customer.com`) |
| **Central** | [`02-licensing/`](02-licensing/) | The licensing authority: products, plans, clients, licenses, module entitlements, Ed25519 signing and the check-in API every client phones home to. | One install, run by the vendor (e.g. `licensing.vendor.com`) |

> *"Slate" is the internal engineering codename of the underlying PHP shell; it survives in
> class names and constants (`SLATE_VERSION`, `slate_*()` helpers). Product-facing text says Kohevo.*

---

## How licensing works

```
        Central Licensing Server (02-licensing)                Client install (01-client)
   ┌─────────────────────────────────────────────┐        ┌────────────────────────────────┐
   │ Products · Plans · Clients · Licenses       │        │ 5-step installer               │
   │ Module entitlements (Forms, Booking, …)     │◄───────│  activates the license key     │
   │ Ed25519 keypair (secret encrypted at rest)  │  HTTPS │                                │
   │                                             │        │ Periodic check-in (cron)       │
   │   POST /licensing/check  ── signed reply ──►│───────►│  verifies the Ed25519 signature│
   └─────────────────────────────────────────────┘        │  caches it (remote_license_    │
                                                          │  cache)                        │
                                                          │ Global License Guard + Module  │
                                                          │ Guard gate every route         │
                                                          │ 7-day pre-expiry warning,      │
                                                          │ 7-day post-expiry grace        │
                                                          └────────────────────────────────┘
```

- Every valid license includes the **core**: admin/users, dashboard, site settings.
- **Modules** (Forms, Membership, Booking, Coaching, MCP Gateway, …) are entitlements chosen per license.
- Direct URL/API access can not bypass the guards; the public site stays open during the grace period.
- The two apps must run on **separate domains, document roots and databases**.

Design documents: [`docs/02-architecture/`](docs/02-architecture/).

---

## What the client application includes

| Plugin | Purpose |
|---|---|
| **Booking** | Services, providers, availability, conflict handling, payments, reminders, self-service cancel/reschedule, and an **embeddable widget** ([guide](docs/05-guides/EMBEDDING.md)) |
| **Membership** | Plans, members, profile completion (switchable), wallet ledger, the membership gate wired into Booking |
| **Forms** | Public forms, multi-step questionnaires with conditional logic, branded submission emails, iframe embedding |
| **Coaching** | The 3-month Body & Soul programme: tracking, goals, chat, recipes, summaries |
| **Stripe Payment** | Checkout, refunds and invoices for every plugin |
| **Multilang Translate** | Language registry, visual string translation, language switcher |
| **Media Library**, **Backups**, **MCP Gateway** | Media picker; scheduled DB + files backup to Google Drive; audited, scoped AI-agent admin access |

All transactional email — booking, membership, sign-in/verification/password reset, SMTP test — uses **one**
branded template ([guide](docs/05-guides/EMAIL-TEMPLATES.md)).

## Languages

The UI ships in **English and French**. Every string the code renders has a French entry
(3,358 keys in the client, 3,535 in central — verified against the source). French dates default to
day-first. See [`docs/05-guides/I18N.md`](docs/05-guides/I18N.md) for how to enable French on an install, add a
language, and keep the dictionaries complete.

---

## Repository layout

```
.
├── 01-client/            Client application (PHP, no build step)
├── 02-licensing/         Central licensing server (same shell + the `licensing` plugin)
├── docs/                 Architecture, audits, guides, operations, release notes
├── production-artifacts/ Step-by-step production deployment guides (Apache, Nginx, cron, env, go-live)
├── scripts/              Repository tooling (release build)
├── agents/               Working rules for AI coding agents used on this project
├── 00-original/          Read-only archive of the pre-rebuild code (reference only)
├── .github/              CI workflow, PR and issue templates
├── VERSION               Single source of truth for the release number
├── CHANGELOG.md          What changed, per release
└── SECURITY.md           How to report a vulnerability
```

Each app is a self-contained tree: `admin/` `customer/` `api/` `includes/` `src/` `plugins/` `lang/` `db/`
(schema + migrations) `bin/` (CLI tools) `templates/` `tests/`.

---

## Getting started (local development)

**Requirements:** PHP 8.2+ (the code uses `readonly class`; CI runs 8.3), MySQL 8.0+ or MariaDB 10.11+, and the extensions
`pdo_mysql`, `mbstring`, `curl`, `json`, `openssl` and `sodium` (Ed25519 licence signing and verification — required, with no fallback:
without it the installers refuse to run, Central issues no licences and a client trusts none);
Kohevo Studio's optional HTML/CSS import also needs `dom` and `libxml`.
No Composer/Node build is needed to run the apps; PHPMailer is added at packaging time.

```bash
cd 01-client                      # or 02-licensing
cp .env.example .env              # set APP_URL, APP_SECRET, CRON_SECRET and the DB_* values
php bin/migrate migrate           # create / update the schema
php -S 127.0.0.1:8000 dev-server.php
```

Then open `http://127.0.0.1:8000/install.php`. On the central server, generate the signing keypair once with
`php bin/licensing-generate-keys.php` and copy the printed **public** key into each client's
`LICENSE_SERVER_PUBLIC_KEY`.

> **Never commit `.env`.** It is git-ignored; the packages ship without one.

### Tests

Both apps use a dependency-free harness (plain PHP, runs on shared hosting).

```bash
cd 01-client && make ci           # lint + secret scan + unit/integration/smoke
```

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) lints every PHP file, provisions MySQL 8.0 **and**
MariaDB 10.11, runs migrations, and executes the client and central suites on each push and pull request to `main`.

---

## Releasing and deploying

```bash
scripts/build-release.sh          # builds KOHEVO-CLIENT-V<x.y>-DEPLOYMENT-READY.zip and the CENTRAL one from main
```

Packages are built from a git ref (never the working tree), exclude tests and dev-only files, ship **no `.env`**, and
are git-ignored. Full procedure, versioning rules and shared-hosting notes:
[`docs/06-operations/RELEASING.md`](docs/06-operations/RELEASING.md) and
[`docs/06-operations/SHARED-HOSTING.md`](docs/06-operations/SHARED-HOSTING.md).
Server setup guides: [`production-artifacts/`](production-artifacts/) and each app's `INSTALL.md`.

## Documentation

Start at the [documentation index](docs/README.md). Highlights: [architecture](docs/02-architecture/),
[iframe embedding](docs/05-guides/EMBEDDING.md), [French / i18n](docs/05-guides/I18N.md),
[email templates](docs/05-guides/EMAIL-TEMPLATES.md), [changelog](CHANGELOG.md).

## Security

Please report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Credentials come only from the
environment / database settings; nothing secret belongs in this repository.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

This repository does not currently carry a licence file, so **all rights are reserved** by the owner.
Add a `LICENSE` file to change that.
