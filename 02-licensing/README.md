# Kohevo Central Licensing Server

The vendor-side half of Kohevo's commercial licensing. One install of this app issues and tracks licenses, and
every customer install ([`01-client`](../01-client/)) phones home to it once a day.

> Overview of the whole system: [root README](../README.md). Design: [`docs/02-architecture/`](../docs/02-architecture/).
> Installing it: [INSTALL.md](INSTALL.md). Version: see [`VERSION`](../VERSION).

## What it does

| Area | Where |
|---|---|
| **Products, plans, clients, licenses, installations** — the admin screens | `plugins/licensing/admin/` (`products`, `plans`, `clients`, `licenses`, `installs`) |
| **Module entitlements** — which optional modules (Forms, Membership, Booking, …) a license unlocks; core is always included | `plugins/licensing/ModuleCatalog.php`, `PlanService.php`, `LicenseService.php` |
| **Installation binding** — one license ↔ one installation identity | `plugins/licensing/InstallationService.php` |
| **Check-in API** — `POST /licensing/check` returns a signed payload | `plugins/licensing/LicensingAPI.php`, route registered in `Licensing.php` |
| **Signing** — Ed25519; the secret key is encrypted at rest with `APP_SECRET`, only the public key is distributed | `bin/licensing-generate-keys.php` |
| **Lifecycle & audit** — `trial`, `active`, `suspended`, `revoked`, `expired`, with an event history | `licensing_license_events`, `audit_log` |
| **Legacy installs** — existing pre-licensing Solaya sites are handled by an explicit policy | `plugins/licensing/LegacyLicensePolicy.php` |

Expiry is evaluated lazily on every check-in and when a license is opened in the admin; a daily job
(`cron.php` → `LicenseService::sweepExpired()`) persists the transitions.

### The check-in contract

```
POST /licensing/check            {"product", "license_key", "install_id", "domain", "app_version", "checked_at"}
→ 200 {"payload": "<signed JSON string>", "signature": "<base64 Ed25519>"}
```

The payload carries `installation_id`, `status`, `plan`, `entitlements`, `expires_at`, `warning_days`, `grace_days`
and `next_check_after`. Clients verify the signature **and** that `installation_id` is their own, so a payload copied
from another install is rejected. Unknown product, unknown key and domain mismatch are indistinguishable to the
caller (anti-enumeration). Full contract:
[`docs/02-architecture/11-LICENSING-API-CONTRACT.md`](../docs/02-architecture/11-LICENSING-API-CONTRACT.md).

## First run

```bash
cp .env.example .env                      # APP_URL, APP_SECRET, CRON_SECRET, DB_*
php bin/migrate migrate                   # schema
php bin/licensing-generate-keys.php       # once; refuses to overwrite an existing keypair
php -r 'require "config.php"; echo LicensingAPI::signingPublicKey(), "\n";'
```

Copy the printed **public** key into each client's `LICENSE_SERVER_PUBLIC_KEY`. Rotating the keypair invalidates
every signature already trusted in the field — redeploy the new public key everywhere first.

Then create a **real** administrator: `php bin/reset-admin-password.php <your-real-email> <strong-password>` creates
that Super Admin (or resets the password if it exists). Use your own address — never a placeholder from documentation.

## Also in this tree

This app is built from the same PHP shell as the client, so it carries the shell's admin, users/roles, settings and
the shared plugins (`booking`, `forms`, `membership`, `stripe-payment`, `multilang-translate`, …). They are kept
identical to the client's copies (see [CONTRIBUTING](../CONTRIBUTING.md)); the only plugin unique to this tree is
`licensing`. The UI is available in English and French ([guide](../docs/05-guides/I18N.md)).

## Develop and test

```bash
make lint                 # PHP syntax
make test-unit            # no database needed
make ci                   # lint + secret scan + full suite (needs a test database)
php tests/run-phase2-licensing.php
```

Tests refuse to run against a non-test database (`slate_require_test_database()`).

## Operations

- Cron and backups: [`production-artifacts/CRON-SETUP.md`](../production-artifacts/CRON-SETUP.md)
- Hosting notes: [`docs/06-operations/SHARED-HOSTING.md`](../docs/06-operations/SHARED-HOSTING.md)
- Releasing: [`docs/06-operations/RELEASING.md`](../docs/06-operations/RELEASING.md)
- Security notes and secrets handling: [SECURITY.md](SECURITY.md)
