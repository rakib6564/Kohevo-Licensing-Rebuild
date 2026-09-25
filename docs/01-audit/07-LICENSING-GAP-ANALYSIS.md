# Phase 0: 07 — Licensing Gap Analysis

**Document Status:** Complete Fact-Based Audit  
**Phase:** Phase 0 — Existing System Audit  
**Target Architecture Reference:** `planning.md`, `docs/00-project/REQUIREMENTS.md`, `docs/00-project/DECISIONS.md`  

---

## 1. Executive Summary

This Gap Analysis provides a comprehensive, requirement-by-requirement comparison between the **Actual Current System** (verified in `01-client/`, `02-licensing/`, and `00-original/`) and the **Approved Target Requirements** established in `REQUIREMENTS.md` and `DECISIONS.md`.

The analysis highlights that while foundational cryptographic components exist (Ed25519 signing via `ext-sodium`, domain normalization, check-in transport), the current architecture fundamentally conflicts with the target commercial model. The system currently functions as a multi-tenant SaaS application hosting its own internal licensing engine, rather than an isolated client installation governed by an external Central Licensing Authority.

---

## 2. Requirements Compliance Checklist

| Ref | Approved Requirement / Decision | Current System State | Classification | Evidence / Source Reference |
| :--- | :--- | :--- | :--- | :--- |
| **REQ-01** | Client application installed on client's own server | Client application is self-hosted flat PHP monolith | EXISTING | `01-client/` |
| **REQ-02** | Central Licensing Server is authoritative for licensing | Central server exists (`02-licensing/`), but client also contains local `LicenseService` / `PlanService` | CONFLICTS WITH TARGET | `01-client/src/Services/Licensing/LicenseService.php` |
| **REQ-03** | Valid license required for normal application use | Admin area, settings, and APIs are accessible without license | CONFLICTS WITH TARGET | `01-client/admin/index.php`, `users.php` |
| **REQ-04** | Core features automatically included in every valid license | Core features exist, but are completely ungated by license | CONFLICTS WITH TARGET | `01-client/admin/index.php`, `settings.php` |
| **REQ-05** | Optional modules independently selectable per license | Entitlements exist only on `licensing_plans.entitlements_json`; cannot select modules per license | CONFLICTS WITH TARGET | `02-licensing/plugins/licensing/admin/installs.php` |
| **REQ-06** | All combinations of optional modules supported | Modules are decoupled, but licensing server cannot issue custom combinations | PARTIALLY IMPLEMENTED | `02-licensing/plugins/licensing/LicensingAPI.php` line 250 |
| **REQ-07** | Editor and Content excluded from V1 licensing | Both exist as core capabilities; neither is licensed | EXISTING | `01-client/admin/editor.php`, `posts.php` |
| **REQ-08** | Installer: Database Configuration | Step 1 configures database and writes `.env` | EXISTING | `01-client/install.php` lines 76-121 |
| **REQ-09** | Installer: License Key validation & activation | No license key input or central activation during installation | MISSING | `01-client/install.php` |
| **REQ-10** | Installer: Admin creation after license activation | Admin created in Step 2 before license verification | CONFLICTS WITH TARGET | `01-client/install.php` lines 124-179 |
| **REQ-11** | Server-side Global License Lock | Public gate restricts visitors only; admins pass through; admin area ungated | CONFLICTS WITH TARGET | `01-client/includes/error_page.php` line 232 |
| **REQ-12** | Server-side Module Entitlement Enforcement | No checks in `forms`, `membership`, or `booking` controllers/routes/APIs | MISSING | `01-client/plugins/forms/Forms.php`, `Booking.php` |
| **REQ-13** | Unique Installation Identity bound to License | 32-char hex generated locally, but bound to `tenant_id` | PARTIALLY IMPLEMENTED | `01-client/src/Services/Installation/InstallationService.php` |
| **REQ-14** | License Lifecycle: Explicit states (Unactivated, Active, Expired, Suspended, Revoked) | Central server lacks explicit transition methods; client local service has them | CONFLICTS WITH TARGET | `02-licensing/plugins/licensing/LicensingAPI.php` |
| **REQ-15** | 7-day pre-expiry warning | No pre-expiry warning implemented | MISSING | `01-client/includes/error_page.php` |
| **REQ-16** | 7-day post-expiry commercial grace period | Expired licenses blocked immediately with 0 grace; 7-day cache staleness confused with grace | CONFLICTS WITH TARGET | `01-client/includes/error_page.php` line 227 |
| **REQ-17** | Network availability tolerance separate from grace | Hardcoded 7-day threshold on `fetched_at` treated as grace | CONFLICTS WITH TARGET | `01-client/includes/error_page.php` lines 226-228 |
| **REQ-18** | Client UI: Clean license display | Dashboard displays stats, but client admin shell contains full platform tenant/plan manager | CONFLICTS WITH TARGET | `01-client/admin/partials/header.php` lines 253-290 |
| **REQ-19** | Client UI: Never expose tenant/plan/global license admin | Client exposes `tenants.php`, `plans.php`, `licenses.php`, `platform-admins.php` | CONFLICTS WITH TARGET | `01-client/admin/` |
| **REQ-20** | Kohevo Platform Identity protected | Platform signature enforced across surfaces (`PlatformSignature.php`) | EXISTING | `01-client/includes/error_page.php` lines 53-58 |
| **REQ-21** | Cryptographic verification of licensing responses | Central server signs with Ed25519; client verifies | EXISTING | `01-client/plugins/licensing/client/LicenseSignatureVerifier.php` |
| **REQ-22** | Tamper-resistant local cache | Client cache table stores unverified plain columns | CONFLICTS WITH TARGET | `01-client/src/Services/Licensing/SlateLicenseCacheStore.php` |

---

## 3. Major Architectural Gaps Detailed

### Gap 1: License-Centric Model vs. Plan/Install Conflation
- **Requirement:** A license represents the actual commercial entitlement for an installation. A plan is merely a template. Optional modules must be selectable per license.
- **Current System:** On `02-licensing`, the table `licensing_installs` represents both the license and the install. Entitlements exist solely as a JSON column on `licensing_plans`.
- **Gap:** When issuing a license in `admin/installs.php`, the operator cannot select which optional modules are granted. All installs on a plan receive identical entitlements.

### Gap 2: Dual Licensing Authority (Central vs. Local)
- **Requirement:** The Central Licensing Server is the sole commercial authority. The client must not possess or expose licensing authority.
- **Current System:** `01-client` retains a complete local multi-tenant licensing engine (`LicenseService`, `PlanService`, `licenses`, `platform_plans`, `admin/licenses.php`).
- **Gap:** Two competing engines exist in the same codebase. The client application contains dead-weight and confusing legacy multi-tenant code.

### Gap 3: Installation Identity & Multi-Tenancy Coupling
- **Requirement:** The client application is an isolated single installation with a unique Installation ID.
- **Current System:** `installation_identity` table mandates a `tenant_id` column. `SlateLicenseCacheStore` and `EntitlementService` require `tenantId` parameters.
- **Gap:** Client licensing logic is hard-coded to multi-tenancy conventions that must be untangled.

### Gap 4: Absence of Global License Lock
- **Requirement:** An installation without a valid license must be completely locked across all routes, admin screens, APIs, and background jobs.
- **Current System:** Only `index.php` and `public.php` invoke `slate_license_gate()`. Admin pages (`admin/*.php`), customer portals (`customer/*.php`), APIs (`api/v1.php`), and cron jobs (`cron.php`) run completely ungated. Furthermore, `slate_license_gate()` bypasses authenticated administrators.
- **Gap:** Complete absence of a global, server-side license barrier.

### Gap 5: Absence of Module Entitlement Enforcement
- **Requirement:** Access to `forms`, `membership`, and `booking` must be verified server-side against the license's entitlements.
- **Current System:** Zero entitlement checks exist inside `plugins/forms/`, `plugins/membership/`, or `plugins/booking/`.
- **Gap:** Any active plugin is completely usable by any administrator.

### Gap 6: Expiry, Grace Period, and Cache Staleness Confusion
- **Requirement:**
  - 7 days before expiry: Warning displayed, full functionality active.
  - Expiry date: 7-day post-expiry commercial grace period begins; full functionality active with warnings.
  - After 7-day grace: FULL LOCK.
  - Network tolerance: Distinct offline tolerance without redefining commercial expiry.
- **Current System:**
  - No pre-expiry warning.
  - License is marked `expired` the second `expires_at` passes.
  - Expired status immediately blocks public visitors (0-day grace period).
  - A 7-day threshold is placed on `fetched_at` (cache age), confusing network offline tolerance with commercial expiry grace.
- **Gap:** Complete mismatch with the specified three-tier expiry lifecycle.

### Gap 7: Installer Sequence Inversion
- **Requirement:** DB Config → Install Application → License Key → Central Validation → Activate Installation → Create Admin Account → Finish → Dashboard.
- **Current System:** DB Config → Core Migrations + Admin Creation + Local Identity → Arbitrary Plugin Activation → Finish.
- **Gap:** Missing license key entry, missing remote validation, missing remote activation, premature admin creation, and unentitled module activation.
