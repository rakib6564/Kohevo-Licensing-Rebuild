# Kohevo Commercial Licensing Rebuild — Production Deployment Guide

This master deployment guide provides the end-to-end architecture overview, package inventory, and deployment sequence for bringing the **Kohevo Central Licensing Server** and **Kohevo Client Application** live in production.

---

## 1. Production Packages Overview

Two standalone, production-ready deployment packages are provided in `production-artifacts/`:

| Package File | Source | Target Role | Description |
| :--- | :--- | :--- | :--- |
| `kohevo-licensing-central-production.zip` | `02-licensing/` | **Central Licensing Server** | Commercial licensing authority managing products, plans, clients, licenses, Ed25519 cryptographic signing, installation bindings, lifecycle transitions, and the `POST /licensing/check` validation API. |
| `kohevo-client-production.zip` | `01-client/` | **Client Application** | Customer-installed Kohevo application enforcing the 5-step commercial installer flow, Ed25519 signature verification, `remote_license_cache`, Global License Guard, ModuleGuard (Forms, Membership, Booking), 7-day pre-expiry warning, and 7-day post-expiry grace period. |

> [!IMPORTANT]
> The Central Licensing Server and Client Application must be deployed on separate domains/subdomains, separate document roots, and separate MySQL/MariaDB databases. Never deploy both packages into the same directory or database.

---

## 2. Target Production Architecture

```
Central Licensing Server (licensing.yourdomain.com)
  ├── Products, Plans & Optional Module Templates
  ├── Commercial Licenses & Module Entitlements (licensing_licenses, licensing_license_modules)
  ├── Installation Identity Binding (licensing_installations, 1 License -> 1 Installation)
  ├── Lifecycle & Audit History (licensing_license_events, audit_log)
  ├── Ed25519 Signing Keypair (secret key encrypted at rest via APP_SECRET)
  └── Public Check-In Endpoint: POST /licensing/check
              │
              ▼  HTTPS (Signed JSON Envelope: payload + Ed25519 signature)
Client Application (app.clientdomain.com)
  ├── Unique Installation Identity (installation_identity + .env INSTALLATION_ID)
  ├── RemoteLicenseClient + LicenseSignatureVerifier (Ed25519 public key verification)
  ├── Signed Local Cache (remote_license_cache)
  ├── Global License Guard (includes/license_guard.php)
  ├── ModuleGuard & EntitlementService (Core + optional Forms, Membership, Booking)
  ├── CommercialLicenseWindow (7-day warning, 7-day post-expiry grace, full lock)
  └── Unattended Daily Sync Cron (php bin/license-check.php)
```

### V1 Feature & Entitlement Model

- **Core Features (automatically included with every active license):**
  - Admin / User (`admin/users.php`, `admin/roles.php`, `admin/sessions.php`)
  - Dashboard (`admin/index.php`)
  - Site Settings (`admin/settings.php`)
- **Optional Modules (independently selectable per license):**
  - `forms` — Form Builder
  - `membership` — Membership
  - `booking` — Booking
- **Out of V1 Commercial Scope:**
  - Editor / Content remain outside V1 commercial licensing scope.

---

## 3. Master Deployment Sequence

Deploy the **Central Licensing Server** first, generate its Ed25519 signing keypair, and embed the resulting public key into each **Client Application** deployment.

1. **Deploy Central Licensing Server** (`CENTRAL-SETUP.md`)
   - Provision server, PHP 8.2+, and dedicated MySQL 8.0+ / MariaDB 10.11+ database.
   - Extract `kohevo-licensing-central-production.zip` into the Central document root.
   - Configure `.env` from `.env.example` (`ENV-SETUP.md`).
   - Configure Apache (`APACHE-SETUP.md`) or Nginx (`NGINX-SETUP.md`) with HTTPS.
   - Run database migrations (`php bin/migrate migrate`) and activate the `licensing` plugin (`php bin/activate-plugin.php licensing`).
   - Generate the Ed25519 signing keypair (`php bin/licensing-generate-keys.php`) and record the Base64 public key.
   - Create the Platform Administrator account, Product (`kohevo`), Client, Plan, and Commercial License.
   - Configure Central cron (`CRON-SETUP.md`).

2. **Deploy Client Application** (`CLIENT-SETUP.md`)
   - Provision client server, PHP 8.2+, and dedicated MySQL 8.0+ / MariaDB 10.11+ database.
   - Extract `kohevo-client-production.zip` into the Client document root.
   - Configure Apache (`APACHE-SETUP.md`) or Nginx (`NGINX-SETUP.md`) with HTTPS.
   - Pre-configure licensing environment variables (`LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, `LICENSE_PRODUCT=kohevo`) in `.env` or hosting environment variables (`ENV-SETUP.md`).
   - Run the web installer at `https://<client-domain>/install.php`:
     - **Step 1:** Database Configuration
     - **Step 2:** Install Application (provisions core schema and unique 32-char hex `INSTALLATION_ID`)
     - **Step 3:** License Key → Central Licensing Server Validation & Installation Activation
     - **Step 4:** Create Admin Account
     - **Step 5:** Finish (auto-activates entitled modules and writes `.installed`) → Dashboard
   - Configure the daily unattended license check-in cron (`php bin/license-check.php`, see `CRON-SETUP.md`).

3. **Run Security & Go-Live Verification**
   - Complete `SECURITY-CHECKLIST.md` and `GO-LIVE-CHECKLIST.md` before handing over the installation.

---

## 4. Documentation Index

| Document | Purpose |
| :--- | :--- |
| `PRODUCTION-DEPLOYMENT.md` | Master deployment architecture and end-to-end sequence (this file). |
| `CENTRAL-SETUP.md` | Step-by-step setup guide for the Central Licensing Server (`02-licensing`). |
| `CLIENT-SETUP.md` | Step-by-step setup guide for the Client Application (`01-client`). |
| `ENV-SETUP.md` | Complete reference for Central and Client `.env` variables and signing keys. |
| `CRON-SETUP.md` | Cron configuration for Client daily license check-in and Central maintenance. |
| `APACHE-SETUP.md` | Apache virtual host, `AllowOverride All`, and `.htaccess` security rules. |
| `NGINX-SETUP.md` | Nginx server blocks, routing (`public.php` and `/licensing/check`), and path blocking. |
| `SECURITY-CHECKLIST.md` | Pre-production security verification checklist. |
| `GO-LIVE-CHECKLIST.md` | Final operational sign-off checklist for Central and Client deployments. |
| `CENTRAL-PACKAGE-MANIFEST.md` | File manifest, component inventory, and exclusions for `kohevo-licensing-central-production.zip`. |
| `CLIENT-PACKAGE-MANIFEST.md` | File manifest, component inventory, and exclusions for `kohevo-client-production.zip`. |
