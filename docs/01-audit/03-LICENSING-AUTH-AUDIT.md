# Phase 0: 03 — Licensing Authentication & Authorization Audit

**Document Status:** Complete Fact-Based Audit  
**Phase:** Phase 0 — Existing System Audit  
**Target Architecture Reference:** `planning.md`, `docs/00-project/REQUIREMENTS.md`, `docs/00-project/DECISIONS.md`  
**Sources Inspected:**
1. `01-client/src/Services/Auth/Auth.php` (Core Auth service)
2. `01-client/src/Tenancy/TenantContext.php` (Tenant context manager)
3. `01-client/admin/partials/header.php` (Admin navigation & authority gate)
4. `01-client/includes/helpers.php` (`current_tenant_id()`, `with_tenant()`)
5. `01-client/includes/error_page.php` (`slate_license_gate()`, `slate_maintenance_gate()`)
6. `01-client/src/Services/Licensing/EntitlementService.php` (Entitlement service)
7. `02-licensing/plugins/licensing/Licensing.php` (Central server admin nav gates)
8. `02-licensing/plugins/licensing/LicensingAPI.php` (Central API check-in authorization)

---

## 1. Executive Summary

The authentication and authorization architecture across the client application and the central licensing platform is strictly an **Identity and Role-Based Access Control (RBAC)** system. 

It currently possesses **no integration with commercial licensing or module entitlements**:
1. **Auth Operates in Isolation from Licensing:** The `Auth` class validates user credentials, maintains sessions, checks roles and permissions, and supports multi-factor authentication. It contains zero knowledge of whether the installation is licensed or whether a requested feature is commercially entitled.
2. **SuperAdmin Bypass Defeats License Boundaries:** In `Auth::can()`, any user with `role_id === 1` or membership in `platform_admins` immediately receives `true` for all permission checks. There is no subsequent check against license state.
3. **Admins Bypass Public License Restrictions:** The only license gate currently executing on requests (`slate_license_gate()` in `error_page.php`) explicitly bypasses any logged-in administrator (`if (Auth::check()) return;`), ensuring that administrators are never locked out even if the installation license is completely revoked, suspended, or expired.
4. **No Route or Middleware Barrier for Unlicensed Installs:** Admin entry points (`admin/*.php`) and API endpoints (`api/v1.php`) execute without querying the license gate. If a user authenticates, they have full access to core and optional features.

---

## 2. Client Authentication Architecture

### 2.1 Admin Authentication (`users`, `admin_sessions`)
- **Primary Implementation:** `Slate\Services\Auth\Auth` (`01-client/src/Services/Auth/Auth.php`).
- **Data Stores:**
  - `users`: Stores administrator credentials (`email`, `password_hash`, `role_id`, `status`). Scoped by `tenant_id`.
  - `admin_sessions`: Tracks active sessions (`session_hash`, `device_label`, `ip_address`, `expires_at`, `revoked_at`).
- **Enforcement Entry Points:**
  - `Auth::require()`: Verifies session validity; if unauthenticated, stores requested URI in session and redirects to `/admin/login.php`.
  - `Auth::check()`: Returns boolean indicating whether an active admin session exists.
  - `Auth::requirePerm(string $perm)`: Verifies authentication and checks `Auth::can($perm)`; emits HTTP 403 if permission is missing.

### 2.2 Customer Authentication (`customers`, `customer_auth_tokens`)
- **Data Stores:**
  - `customers`: Stores end-user credentials for public-facing plugin interactions (e.g., booking clients, members).
  - `customer_auth_tokens`: Single-use, expiring SHA-256 tokens for email verification and password reset.
- **Enforcement Entry Points:**
  - `Auth::requireCustomer()`: Verifies customer session; redirects unauthenticated callers to `/customer/login.php`.
  - `Auth::customer()`: Returns currently authenticated customer row.

---

## 3. Client Authorization & RBAC Architecture

### 3.1 Role & Permission Model (`roles`, `role_permissions`)
- Permissions follow namespaced strings: `<domain>.<action>` (e.g., `forms.view`, `forms.manage`, `booking.view`, `booking.manage`, `settings.edit`, `users.manage`).
- Super Admin (`role_id === 1`) short-circuits all permission checks in `Auth::can()`:
  ```php
  // 01-client/src/Services/Auth/Auth.php lines 216-221
  if (self::isSuperAdmin()) {
      return true;
  }
  ```
- Granular permissions are loaded from `role_permissions` and cached in-memory per request in `Auth::$permCache`.

### 3.2 Platform Administrator Authority (`platform_admins`)
Introduced in migration `0013_platform_admins` as a tenant-independent authority level:
- Backed by table `platform_admins` (`id`, `user_id`, `granted_by`, `granted_at`).
- Evaluated via:
  - `Auth::isPlatformAdmin(?int $userId = null): bool`
  - `Auth::isPlatformSuperAdmin(): bool`
  - `Auth::requirePlatformAdmin(): void`
- **Application in Client:** Used exclusively to gate access to the multi-tenant SaaS management pages:
  - `admin/tenants.php`
  - `admin/plans.php`
  - `admin/licenses.php`
  - `admin/platform-admins.php`
  - `admin/exit-tenant.php`

---

## 4. Multi-Tenant Authorization Coupling

The client application includes deep multi-tenant infrastructure that directly conflicts with the single-installation commercial model:

### 4.1 Tenant Context Resolution (`TenantContext.php`, `helpers.php`)
- `current_tenant_id()` resolves the current tenant:
  1. Checks `$GLOBALS['SLATE_TENANT_OVERRIDE']` (used by CLI and cron).
  2. Checks `$_SESSION['slate_override_tenant']` (used when a Platform Admin impersonates a tenant).
  3. Falls back to constant `TENANT_ID` defined in `.env` / `config.php`.
- **Database Repository Coupling:** Database repository queries automatically inject `WHERE tenant_id = ?` using the resolved tenant context.

### 4.2 Impact on Licensing
- `installation_identity` table contains `tenant_id` with a UNIQUE constraint (`uniq_installation_identity_tenant`).
- `remote_license_cache` table contains `tenant_id` with a UNIQUE constraint (`uniq_remote_license_cache_tenant`).
- `SlateLicenseCacheStore` accepts `tenantId` in its constructor:
  ```php
  // 01-client/src/Services/Licensing/SlateLicenseCacheStore.php line 14
  public function __construct(private int $tenantId) {}
  ```
- `EntitlementService` requires `tenantId` on every public method:
  ```php
  // 01-client/src/Services/Licensing/EntitlementService.php line 21
  public static function canAccess(int $tenantId, string $featureKey): bool
  ```

---

## 5. Interaction Between Auth and Licensing Gate

### 5.1 The Admin Pass-Through Hole
In `01-client/includes/error_page.php`, `slate_license_gate()` contains the following logic:
```php
// Lines 232-233
if (class_exists('Auth') && Auth::check()) return; // admin passes through
```
**Consequence:**
- When an installation's license expires, is revoked, or is suspended, any authenticated admin passes completely unrestricted through the gate.
- This directly violates REQUIREMENTS.md §5 and DECISIONS.md §8, which mandate that without a valid license, the entire application (including Dashboard, Admin / User, and Site Settings) must enter **FULL LOCK**.

### 5.2 The Unconfigured Pass-Through Hole
In `01-client/includes/error_page.php`, `slate_license_gate()` checks:
```php
// Lines 214-216
if ($cached === null) {
    if (!$remoteMode) return; // explicit legacy/unconfigured path
    $restricted = true;
}
```
**Consequence:**
- If remote licensing environment variables are not set in `.env`, `$remoteMode` evaluates to `false`.
- The gate returns immediately without restricting any public access.
- Any installation deployed without licensing configuration remains completely unlocked.

---

## 6. Central Licensing Server Authentication & API Security

On the Central Licensing Server (`02-licensing`):

### 6.1 Administrator Authentication
- Central administrators authenticate using standard Slate admin authentication (`users`, `admin_sessions`).
- Administrative pages (`02-licensing/plugins/licensing/admin/*.php`) require permission `licensing.manage`:
  ```php
  Auth::require();
  Auth::requirePerm('licensing.manage');
  ```

### 6.2 Check-in API Authentication & Transport Security (`public/check.php`, `LicensingAPI.php`)
- **Endpoint:** `POST /licensing/check`
- **Transport Security:**
  - Expects JSON request payload containing `product`, `license_key`, `install_id`, `domain`, `app_version`, `checked_at`.
  - **No Client Authentication Header:** There is no client API key, bearer token, or client-side private key signature on the incoming request.
  - **License Key Verification:** Evaluates `hash('sha256', $licenseKey)` against `licensing_installs.license_key_hash`.
  - **Anti-Enumeration Response:** Returns uniform HTTP 404 `{"error": "invalid_request"}` for invalid product, unknown key, or domain mismatch.
- **Response Authentication & Integrity:**
  - Server signs the canonical JSON payload string using Ed25519 (`sodium_crypto_sign_detached`) with its private key (`licensing.signing_secret_key`).
  - Returns envelope: `{"payload": "<json_string>", "signature": "<base64_sig>"}`.
  - Client verifies using public key (`LICENSE_SERVER_PUBLIC_KEY`).

---

## 7. Findings Classification

| ID | Finding Description | Evidence / Code Location | Classification | Impact | Related Requirement |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **AUTH-01** | SuperAdmin completely bypasses module authorization | `01-client/src/Services/Auth/Auth.php` lines 216-221 | CONFLICTS WITH TARGET | SuperAdmin can use unlicensed optional modules without restriction | REQ §5, DEC §7 |
| **AUTH-02** | Admins bypass public license gate | `01-client/includes/error_page.php` line 232 | CONFLICTS WITH TARGET | Suspended/expired licenses do not lock administrators out of the app | REQ §5, DEC §8 |
| **AUTH-03** | Unconfigured installations bypass license gate | `01-client/includes/error_page.php` lines 214-216 | CONFLICTS WITH TARGET | Removing `.env` licensing variables unlocks the client application | REQ §5, DEC §8 |
| **AUTH-04** | Client admin pages lack license validation | `01-client/admin/index.php` line 9, `admin/settings.php` | MISSING | Any authenticated user reaches dashboard and settings without license check | REQ §5, DEC §8 |
| **AUTH-05** | Auth and Entitlement services are completely decoupled | `01-client/src/Services/Auth/Auth.php`, `EntitlementService.php` | CONFLICTS WITH TARGET | Permissions and entitlements operate as disjointed systems | DEC §7 |
| **AUTH-06** | Check-in API lacks client request signing | `02-licensing/plugins/licensing/LicensingAPI.php` lines 143-151 | MISSING | Central server cannot authenticate the origin of a check-in beyond license key hash | REQ §13 |
| **AUTH-07** | Client exposes platform-level tenant management UI | `01-client/admin/tenants.php`, `admin/plans.php`, `admin/licenses.php` | CONFLICTS WITH TARGET | Platform administration exposed inside client deployment | REQ §11, DEC §3 |
