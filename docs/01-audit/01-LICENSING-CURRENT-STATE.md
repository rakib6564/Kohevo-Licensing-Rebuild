# Phase 0: 01 — Licensing Current State Audit

**Document Status:** Complete Fact-Based Audit  
**Phase:** Phase 0 — Existing System Audit  
**Target Architecture Reference:** `planning.md`, `docs/00-project/REQUIREMENTS.md`, `docs/00-project/DECISIONS.md`, `agents/CLOUD-AGENT-RULES.md`  
**Inspected Directories:** `01-client/`, `02-licensing/`, `00-original/`

---

## 1. Executive Summary

This document establishes the verified, factual baseline of the existing codebase prior to any architectural redesign or code modification.

The codebase originates from **Slate / Solaya**, a PHP-based modular monolith designed with a single-server, multi-tenant SaaS architecture. Over previous iterations, two parallel and competing licensing paradigms were introduced:
1. **A Local Multi-Tenant Licensing Subsystem** located in `01-client/src/Services/Licensing/` (`LicenseService`, `PlanService`) and exposed via `admin/licenses.php`, `admin/plans.php`, and `admin/tenants.php`. This model treats the installation as a multi-tenant host managing its own tenants and issuing internal licenses.
2. **A Partial Remote Licensing Subsystem** consisting of:
   - A dedicated server instance (`02-licensing/` running the `licensing` plugin), which exposes a POST endpoint `/licensing/check`, verifies SHA-256 license hashes, checks normalized domain and installation bindings, and returns an Ed25519-signed JSON envelope.
   - A client check-in script (`01-client/bin/license-check.php`) utilizing `RemoteLicenseClient` to verify the Ed25519 signature and cache response payloads into the `remote_license_cache` table.
   - A public-only gate (`slate_license_gate()` in `01-client/includes/error_page.php`) called exclusively by `index.php` and `public.php`.

### Fundamental Current-State Realities
- **No Global License Lock:** Unlicensed installations are accessible. An administrator can log in, view the dashboard, configure settings, and use all administrative features without a valid license.
- **No Server-Side Module Entitlement Enforcement:** None of the optional V1 modules (`forms`, `membership`, `booking`) check `EntitlementService` or license status in their routes, controllers, services, APIs, or customer portals. Access is restricted solely by database plugin status (`plugins.status = 'active'`) and RBAC permissions (`Auth::can(...)`).
- **Conflation of Plan and Entitlements:** On the Central Server (`02-licensing`), entitlements exist only as a JSON array (`entitlements_json`) on the `licensing_plans` table. Licenses (`licensing_installs`) cannot have custom or independently toggled module entitlements.
- **Conflation of License and Installation:** On the Central Server, the table `licensing_installs` represents both the commercial license (holding the key hash, status, expiry) and the installation record.
- **Installer Omits License Validation:** The client installer (`01-client/install.php`) has no license key step, does not communicate with the Central Server, creates the admin user immediately in Step 2, and allows arbitrary plugin selection in Step 3.
- **Client Exposes Platform Administration:** The client admin shell (`01-client/admin/`) contains active management pages for Tenants (`tenants.php`), Plans (`plans.php`), Platform Admins (`platform-admins.php`), and Licenses (`licenses.php`).

---

## 2. Project & Application Structure

### 2.1 Workspace Layout
The repository contains three primary directories:
- `00-original/`: Reference packages containing:
  - `kohevo-client-final.zip`: The reference client release archive.
  - `kohevo-license-server-final.zip`: The reference licensing server archive.
  - `u263467780_kohevo_client.sql`: A live production database dump from an actual deployed client install.
- `01-client/`: The active client application directory (Solaya).
- `02-licensing/`: The active Central Licensing Server application directory.
- `docs/`: Master documentation and phase specifications.

### 2.2 Framework & Runtime Environment
- **Language / Runtime:** PHP 8.2+ (strictly typed with `declare(strict_types=1);` in newer classes).
- **Architecture:** Flat PHP modular monolith ("Slate Framework"). Direct PHP entry points (e.g., `admin/*.php`, `cron.php`, `install.php`) combined with front controllers (`index.php`, `public.php` using `PublicRouter`, and `api/v1.php` using `ApiRouter`).
- **Database Access:** Direct PDO wrapper (`includes/Database.php` providing static methods: `Database::row()`, `Database::rows()`, `Database::value()`, `Database::insert()`, `Database::update()`, `Database::delete()`).
- **Autoloading:** PSR-4 autoloader (`src/autoload.php`) mapping `Slate\` to `src/`, accompanied by legacy class aliases (`src/compat/aliases.php`) bridging static facades (`Auth`, `Database`, `Hook`, `PluginLoader`, `AuditLog`, `I18n`).
- **Hook System:** WordPress-inspired action and filter bus (`includes/Hook.php` providing `Hook::addAction()`, `Hook::doAction()`, `Hook::addFilter()`, `Hook::applyFilters()`).
- **Plugin System:** Object-oriented lifecycle extensions (`includes/Plugin.php`, `includes/PluginLoader.php`) discovering and booting plugins located in `plugins/<slug>/`.

---

## 3. Bootstrap & Request Execution Flow

Every request executes the following bootstrap sequence:

```text
Request to /path
      ↓
[Apache Rewrite / Direct File Access]
      ↓
Loads config.php
      ↓
1. Loads .env (if present)
2. Defines constants (SLATE_URL, TENANT_ID, APP_SECRET, CRON_SECRET)
3. Registers autoloader (src/autoload.php) & aliases (src/compat/aliases.php)
4. Requires core includes (Database, Hook, Auth, I18n, AuditLog, PluginLoader, etc.)
5. Starts session (Auth::startSession())
6. Boots active plugins (PluginLoader::boot())
7. Registers cron actions
      ↓
[Entry Point Execution]
```

### Request Flow to Protected Functionality
Depending on the requested URL, the request takes one of four paths:

1. **Admin Pages (`admin/*.php`):**
   - Direct execution: `require_once dirname(__DIR__) . '/config.php';`
   - Authentication check: `Auth::require();`
   - Authorization check: `Auth::requirePerm('some.perm');` or `Auth::requirePlatformAdmin();`
   - **Licensing Gate:** NONE. Neither `slate_license_gate()` nor any entitlement check is invoked. Normal admin pages execute completely unrestricted.

2. **Public Router (`public.php` via Apache Rewrite):**
   - Bootstrap: `require_once __DIR__ . '/config.php';`
   - Maintenance Gate: `slate_maintenance_gate();`
   - Remote License Gate: `slate_license_gate();`
     - Evaluates `SlateLicenseCacheStore(current_tenant_id())->load()`
     - If remote licensing is unconfigured (`!EntitlementService::remoteConfigured()`), it immediately returns and passes through.
     - If user is logged in as admin (`Auth::check()`), it immediately returns and passes through.
     - If cache is missing in remote mode or status is not `trial`/`active` or `fetched_at` is older than 7 days, renders `403 License inactive` via `slate_render_error(403, ...)`.
   - Dispatch: `PublicRouter::dispatch($path);` dispatches to plugin route handlers (e.g., `forms`, `book`, `member`, `api/v1`).

3. **Public Landing Page (`index.php`):**
   - Bootstrap: `require_once __DIR__ . '/config.php';`
   - Invokes `slate_maintenance_gate()` and `slate_license_gate()`.
   - Renders landing page via `includes/landing.php`.

4. **API Gateway (`api/v1.php` / `src/Kernel/Http/ApiRouter.php`):**
   - Bootstrap: `require_once dirname(__DIR__) . '/config.php';`
   - Invokes `ApiRouter::handle()`.
   - Checks `api_v1_routes` filter and dispatches to registered callable.
   - **Licensing Gate:** NONE. Does not check license status or module entitlements.

5. **Customer Portal (`customer/portal_router.php`):**
   - Direct or routed execution.
   - Invokes `Auth::requireCustomer();`.
   - Dispatches to `plugins/membership/public/router.php` or booking flows.
   - **Licensing Gate:** NONE.

6. **CLI Cron (`cron.php`):**
   - Bootstrap: `require_once __DIR__ . '/config.php';`
   - Verifies `CRON_SECRET`.
   - Fires `frequent_cron` and `daily_cron`.
   - **Licensing Gate:** NONE. Scheduled background tasks run regardless of license state.

---

## 4. Existing Licensing Components Inventory

| Component | Path / Location | Intended Responsibility | Actual Current Behavior | Status Classification |
| :--- | :--- | :--- | :--- | :--- |
| **LicenseService** | `01-client/src/Services/Licensing/LicenseService.php` | Local SaaS license manager | Manages local `licenses` table for tenants on client DB; generates keys, records hashes, tracks local lifecycle. | CONFLICTS WITH TARGET |
| **PlanService** | `01-client/src/Services/Licensing/PlanService.php` | Local SaaS plan manager | Manages local `platform_plans` and `plan_entitlements` tables on client DB. | CONFLICTS WITH TARGET |
| **EntitlementService** | `01-client/src/Services/Licensing/EntitlementService.php` | Entitlement decision gate | Evaluates remote cache vs local plans; completely unwired to application modules. | PARTIALLY IMPLEMENTED |
| **SlateLicenseCacheStore** | `01-client/src/Services/Licensing/SlateLicenseCacheStore.php` | Local cache persistence | Saves and loads verified remote status to/from `remote_license_cache` table. Unverified at load time. | PARTIALLY IMPLEMENTED |
| **RemoteLicenseClient** | `01-client/plugins/licensing/client/RemoteLicenseClient.php` | Central phone-home client | Sends check-in payload to Central Server; verifies Ed25519 signature; stores status. Zero framework coupling. | EXISTING |
| **LicenseSignatureVerifier** | `01-client/plugins/licensing/client/LicenseSignatureVerifier.php` | Ed25519 verifier | Verifies cryptographic signature using `sodium_crypto_sign_verify_detached`. | EXISTING |
| **LicenseCacheStoreInterface** | `01-client/plugins/licensing/client/LicenseCacheStoreInterface.php` | Cache storage seam | Interface defining `load(): ?array` and `save(array $status): void`. | EXISTING |
| **Installation Identity** | `01-client/src/Services/Installation/InstallationService.php` | Durable instance identity | Generates 32-char hex identity stored in `installation_identity` table (`singleton_id=1`). | PARTIALLY IMPLEMENTED |
| **Client Check-in CLI** | `01-client/bin/license-check.php` | Daily cron check-in script | Reads `.env`, loads installation ID, executes `RemoteLicenseClient->checkIn()`. | EXISTING |
| **Public License Gate** | `01-client/includes/error_page.php` (`slate_license_gate`) | Request blocking | Enforces remote cache restriction on public visitors only. Bypassed by admins and unconfigured installs. | CONFLICTS WITH TARGET |
| **Central Licensing Plugin** | `02-licensing/plugins/licensing/Licensing.php` | Central server bootstrap | Registers `/licensing/check` public route and admin navigation for central server. | EXISTING |
| **Central LicensingAPI** | `02-licensing/plugins/licensing/LicensingAPI.php` | Central server core engine | Validates check-in, verifies domain and binding, signs responses, issues keys. Conflates installs and licenses. | PARTIALLY IMPLEMENTED |
| **Central Check-in Endpoint** | `02-licensing/plugins/licensing/public/check.php` | HTTP check-in receiver | Receives JSON POST, forwards to `LicensingAPI::handleCheckIn()`, emits signed JSON envelope. | EXISTING |
| **Central Admin Installs** | `02-licensing/plugins/licensing/admin/installs.php` | Central license issuance | Lists and issues new licenses/installs. Missing optional module selection. | PARTIALLY IMPLEMENTED |
| **Central Admin Plans** | `02-licensing/plugins/licensing/admin/plans.php` | Central plan management | CRUD for plans with raw textarea for `entitlements_json`. | PARTIALLY IMPLEMENTED |
| **Central Admin Products** | `02-licensing/plugins/licensing/admin/products.php` | Central product catalogue | CRUD for `licensing_products` (e.g., "kohevo"). | EXISTING |
| **Central Admin Clients** | `02-licensing/plugins/licensing/admin/clients.php` | Licensee client catalogue | CRUD for commercial client entities (`licensing_clients`). | EXISTING |

---

## 5. Summary of Findings

1. **The codebase contains two conflicting licensing models:** The client codebase (`01-client`) contains a legacy multi-tenant SaaS licensing engine (`LicenseService`, `PlanService`, `admin/licenses.php`, `admin/plans.php`, `admin/tenants.php`) alongside a partial remote licensing consumer (`RemoteLicenseClient`, `remote_license_cache`).
2. **Global license gate is completely missing from the admin area:** Administrative routes (`admin/*`) do not check license state. An unlicensed or expired installation allows full administrative access.
3. **Module entitlement enforcement is completely missing:** No routes, controllers, or APIs for `forms`, `membership`, or `booking` enforce entitlements. If the plugin is marked active in the database, it is completely accessible.
4. **Central licensing platform lacks independent entitlement assignment:** On `02-licensing`, entitlements are hardcoded to plans via `entitlements_json`. When issuing a license in `installs.php`, an administrator cannot select or customize optional modules.
5. **Installer completely bypasses licensing:** `01-client/install.php` configures the database, creates the admin user, and installs plugins without ever requesting or validating a license key.
6. **Commercial grace period and network availability tolerance are confused:** In `slate_license_gate()`, a 7-day threshold is applied to `fetched_at` (check-in staleness) while expired licenses are blocked immediately with zero commercial grace period.
7. **Identity models remain tied to multi-tenancy:** Installation identity and license cache tables in the client enforce a `tenant_id` foreign key/unique constraint, conflicting with the required single-installation commercial architecture.
