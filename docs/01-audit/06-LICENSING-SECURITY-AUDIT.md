# Phase 0: 06 — Licensing Security & Bypass Audit

**Document Status:** Complete Fact-Based Audit  
**Phase:** Phase 0 — Existing System Audit  
**Target Architecture Reference:** `planning.md`, `docs/00-project/REQUIREMENTS.md` (§13), `docs/00-project/DECISIONS.md` (§7, §8)  
**Environment Inspected:** Local / project workspace (`01-client/`, `02-licensing/`, `00-original/`)

---

## 1. Executive Summary

A rigorous security review of the existing codebase confirms that **licensing enforcement does not currently function as an effective security boundary**.

In its current state, an unlicensed installation or an installation with a suspended, revoked, or expired license can be operated with minimal or no restriction. The system exhibits critical vulnerabilities across direct routing, API access, administrative sessions, local database manipulation, and background operations.

Every major attack vector identified in the audit requirements was tested against the verified source code. The findings prove that current license enforcement relies heavily on presentational checks and UI hiding, while core and optional modules completely lack server-side entitlement enforcement.

---

## 2. Comprehensive Attack Vector & Bypass Analysis

### 2.1 Direct URL Access
- **Vulnerability:** Unlicensed application access via direct URLs.
- **Evidence:** `01-client/admin/index.php` (line 9), `01-client/admin/users.php` (line 8), `01-client/admin/settings.php` (line 9).
- **Mechanics:** Administrative PHP scripts directly execute `Auth::require()`. They do not invoke `slate_license_gate()` or any license validation.
- **Result:** An authenticated administrator can access the Dashboard, User Management, Site Settings, and all Core tools on an unlicensed installation simply by navigating directly to `/admin/index.php`.
- **Classification:** CONFLICTS WITH TARGET.

### 2.2 Direct Route Access (Optional Modules)
- **Vulnerability:** Unlicensed access to optional modules (`forms`, `membership`, `booking`).
- **Evidence:** 
  - `01-client/plugins/forms/admin/index.php` lines 9-10 (`Auth::require()`, `Auth::requirePerm('forms.view')`).
  - `01-client/plugins/membership/admin/index.php` lines 11-14 (`Auth::require()`, `Auth::requirePerm('membership.view')`).
  - `01-client/plugins/booking/admin/appointments.php` lines 8-9 (`Auth::require()`, `Auth::requirePerm('booking.view')`).
- **Mechanics:** Optional module administrative scripts check only RBAC permissions. They contain zero calls to `EntitlementService::canAccess()`.
- **Result:** Any user with the corresponding RBAC permission can access, create, edit, and delete records in `forms`, `membership`, and `booking` even if the active license does not entitle those modules.
- **Classification:** CONFLICTS WITH TARGET.

### 2.3 Direct API Calls (`/api/v1/`)
- **Vulnerability:** Bypassing license and entitlement gates via the Headless REST API.
- **Evidence:** `01-client/api/v1.php` line 14, `01-client/src/Kernel/Http/ApiRouter.php` lines 57-74.
- **Mechanics:** `ApiRouter` resolves authentication via Bearer token (`api_v1_authenticate` hook) and dispatches directly to module handlers (`api_v1_routes`). It does not verify whether the client is licensed or whether the requested module is entitled.
- **Result:** External API clients, mobile apps, or headless frontends can perform operations (such as booking appointments or fetching resources) on unlicensed or unentitled installations.
- **Classification:** CONFLICTS WITH TARGET.

### 2.4 Direct AJAX Endpoints
- **Vulnerability:** Executing actions via standalone AJAX endpoints.
- **Evidence:** `01-client/admin/notifications-poll.php` line 15, `01-client/admin/notifications-read.php`, `01-client/admin/editor-preview.php`.
- **Mechanics:** AJAX endpoints require only `Auth::require()`. They do not enforce licensing gates.
- **Result:** Unlicensed installations continue servicing polling and administrative AJAX actions.
- **Classification:** CONFLICTS WITH TARGET.

### 2.5 Controller & Service Invocation
- **Vulnerability:** Internal service methods execute without entitlement authorization.
- **Evidence:** `FormsAPI::submit()`, `BookingAPI::createAppointment()`, `MembershipAPI::createSubscription()`.
- **Mechanics:** Service layer methods operate purely on database tables (`Database::insert()`, `Database::update()`). None of the service methods verify `EntitlementService::canAccess()`.
- **Result:** Any internal code path, hook listener, or background task invoking these services executes without license validation.
- **Classification:** CONFLICTS WITH TARGET.

### 2.6 Manipulated Request Parameters
- **Vulnerability:** Switching authority mode via environment variables or query parameters.
- **Evidence:** `01-client/src/Services/Licensing/EntitlementService.php` lines 81-87 (`legacyCompatibilityEnabled()`).
- **Mechanics:** If `env('LICENSE_COMPAT_MODE') === 'legacy'` and `LICENSE_COMPAT_UNTIL` is in the future, `EntitlementService` drops remote validation and falls back to local database tables (`platform_plans`, `tenant_profiles`).
- **Result:** An operator can bypass remote licensing authority simply by configuring legacy compatibility flags in `.env`.
- **Classification:** CONFLICTS WITH TARGET.

### 2.7 Modified Cookies & Session State (Admin Pass-Through)
- **Vulnerability:** Complete license bypass for authenticated sessions.
- **Evidence:** `01-client/includes/error_page.php` lines 232-233.
  ```php
  if (class_exists('Auth') && Auth::check()) return; // admin passes through
  ```
- **Mechanics:** In `slate_license_gate()`, if `Auth::check()` returns true, the function immediately returns.
- **Result:** Even when `remote_license_cache` indicates a license is revoked, suspended, or expired, an active admin session bypasses the gate completely on every public route and root landing page.
- **Classification:** CONFLICTS WITH TARGET.

### 2.8 Modified Local Database Values
- **Vulnerability:** Tampering with local cached license state.
- **Evidence:** `01-client/src/Services/Licensing/SlateLicenseCacheStore.php` lines 16-29.
  ```php
  public function load(): ?array {
      $row = \Database::row('SELECT * FROM remote_license_cache WHERE tenant_id = ?', [$this->tenantId]);
      if (!$row) return null;
      return [
          'status' => (string) $row['status'],
          'plan' => $row['plan'],
          'entitlements' => json_decode((string) $row['entitlements'], true) ?: [],
          'expires_at' => $row['expires_at'],
          // ...
      ];
  }
  ```
- **Mechanics:** While `RemoteLicenseClient` verifies the Ed25519 signature upon receipt from the Central Server, it stores unpacked columns in `remote_license_cache`. `SlateLicenseCacheStore::load()` simply reads these columns. It does not verify an Ed25519 signature or HMAC at read time.
- **Result:** Any user with SQL access (or via database export/import) can insert or update a row in `remote_license_cache` setting `status = 'active'`, `expires_at = '2099-12-31'`, and `entitlements = '["forms","membership","booking"]'`, permanently bypassing licensing without contacting the central server.
- **Classification:** CONFLICTS WITH TARGET.

### 2.9 Client-Side JavaScript & Disabled UI / Menu Items
- **Vulnerability:** Security through UI obscurity.
- **Evidence:** `01-client/admin/partials/header.php` lines 253-290, `01-client/plugins/forms/Forms.php` lines 64-100.
- **Mechanics:** Navigation items are conditionally rendered based on RBAC permissions. If a permission is missing or an entitlement is displayed as disabled on the dashboard, the link is omitted from the DOM.
- **Result:** Hiding links does not prevent direct HTTP requests to the target `.php` files.
- **Classification:** CONFLICTS WITH TARGET.

### 2.10 Missing Global Middleware / Central Gate
- **Vulnerability:** Absence of an application-wide enforcement barrier.
- **Evidence:** `config.php` does not invoke `slate_license_gate()`. Only `index.php` and `public.php` invoke it.
- **Mechanics:** The application relies on direct script routing rather than a central front-controller pipeline with mandatory middleware.
- **Result:** Direct requests to `admin/*.php`, `customer/*.php`, `api/v1.php`, and `cron.php` execute without ever passing through a license gate.
- **Classification:** MISSING.

### 2.11 Background Operations (`cron.php`)
- **Vulnerability:** Scheduled tasks execute regardless of license status.
- **Evidence:** `01-client/cron.php` lines 100-135.
- **Mechanics:** `cron.php` validates `CRON_SECRET` and fires `frequent_cron` and `daily_cron` without checking license validity.
- **Result:** Background jobs (such as automated email reminders in `plugins/booking`) continue executing indefinitely on expired or suspended installations.
- **Classification:** CONFLICTS WITH TARGET.

### 2.12 Fail-Open Configuration Vulnerability
- **Vulnerability:** Unconfigured installations are treated as unrestricted.
- **Evidence:** `01-client/includes/error_page.php` lines 214-216:
  ```php
  if ($cached === null) {
      if (!$remoteMode) return; // explicit legacy/unconfigured path
      $restricted = true;
  }
  ```
- **Mechanics:** If `LICENSE_SERVER_URL` or `LICENSE_KEY` is omitted from `.env`, `remoteConfigured()` returns false.
- **Result:** An installer or operator can simply omit or remove licensing environment variables to run the application in an unconfigured, unrestricted state.
- **Classification:** CONFLICTS WITH TARGET.

---

## 3. Summary Security Findings Matrix

| Attack Vector | Vulnerability Description | Exploitability | Impact | Classification |
| :--- | :--- | :--- | :--- | :--- |
| **Direct URL** | Accessing `admin/index.php`, `users.php`, `settings.php` without license | Trivial (Standard Browser) | Complete administrative access on unlicensed install | CONFLICTS WITH TARGET |
| **Direct Route** | Accessing `plugins/forms/admin/`, `plugins/booking/admin/` without entitlement | Trivial (Direct Navigation) | Unauthorized use of premium modules | CONFLICTS WITH TARGET |
| **Direct API** | Calling `/api/v1/booking` without license or entitlement | Trivial (HTTP Client / curl) | Headless API access completely ungated | CONFLICTS WITH TARGET |
| **Direct AJAX** | Polling notifications or executing preview actions | Trivial (HTTP Client) | Unlicensed operations continue | CONFLICTS WITH TARGET |
| **Service Call** | Calling `BookingAPI` or `FormsAPI` directly in PHP | Requires Code Execution | Services lack internal entitlement authorization | CONFLICTS WITH TARGET |
| **Request Params** | Setting legacy compatibility environment parameters | Trivial (via `.env` edit) | Drops remote licensing enforcement | CONFLICTS WITH TARGET |
| **Session State** | Active admin session bypasses public license gate | Trivial (Log in as admin) | Public gate rendered completely ineffective for admins | CONFLICTS WITH TARGET |
| **Database Tampering** | Modifying `remote_license_cache` row directly in MySQL | Requires DB Access | Local cache forgery with no read-time signature check | CONFLICTS WITH TARGET |
| **UI Obscurity** | Bypassing hidden menus by typing direct URL | Trivial (Direct Navigation) | UI hiding fails as a security boundary | CONFLICTS WITH TARGET |
| **Missing Gate** | No global gate in `config.php` | Architecture Flaw | Direct script entry points execute ungated | MISSING |
| **Background Ops** | `cron.php` runs scheduled jobs without license check | Automatic (Scheduled Cron) | Unlicensed background tasks execute | CONFLICTS WITH TARGET |
| **Fail-Open Config**| Deleting `.env` licensing variables removes license gate | Trivial (Config Change) | Complete product unlock without license | CONFLICTS WITH TARGET |
