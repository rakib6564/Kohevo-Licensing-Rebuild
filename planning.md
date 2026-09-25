# Kohevo Licensing Rebuild — Master Planning

## 1. Project Goal

Rebuild the Solaya/Kohevo commercial licensing architecture so that:

- The client application is installed on the client's own server.
- A valid license is required before the application can be used.
- License validation is performed through the Central Licensing Server.
- Every valid license automatically receives the V1 Core features.
- Optional modules can be independently selected when a license is created.
- Direct URL/API access cannot bypass licensing or module restrictions.
- License expiry includes advance warning and a post-expiry grace period.
- The architecture can later support additional modules and future commercial automation without requiring a licensing rewrite.

---

# 2. V1 Feature Scope

## Core Features

Every valid license automatically includes:

- Admin / User
- Dashboard
- Site Settings

These are not individually selectable during license creation.

## Optional Features

The following modules are independently selectable per license:

- Form Builder
- Membership
- Booking

Any combination is valid:

- None
- Form Builder only
- Membership only
- Booking only
- Form Builder + Membership
- Form Builder + Booking
- Membership + Booking
- Form Builder + Membership + Booking

There are no dependencies between these optional modules.

## Future Features — Out of V1 Scope

These exist or are under development but are NOT part of the current licensing scope:

- Editor
- Content

The licensing architecture should remain extensible so these can be added later.

---

# 3. Client Installation Flow

The final installation flow is:

Database Configuration
→ Install Application
→ License Key
→ Central Licensing Server Validation
→ Activate Installation
→ Create Admin Account
→ Finish
→ Dashboard

A client must not be able to use the normal application before a valid license is activated.

---

# 4. License Gate

Without a valid license, the normal application must be completely locked.

Blocked without a valid license:

- Dashboard
- Admin / User
- Site Settings
- Form Builder
- Membership
- Booking

Direct URL access must also be blocked.

Hiding menu items is not sufficient.

The license gate must eventually be enforced server-side at the appropriate request, route, service, and API boundaries.

Only the minimum licensing/activation/installation routes required to activate the product should remain accessible.

---

# 5. Module Entitlement

After a valid license is activated, module access is determined by that license's entitlements.

Example:

License:
- Admin / User: Core
- Dashboard: Core
- Site Settings: Core
- Booking: Enabled
- Membership: Disabled
- Form Builder: Disabled

Result:

- Dashboard → Allowed
- Admin / User → Allowed
- Site Settings → Allowed
- Booking → Allowed
- Membership → Blocked
- Form Builder → Blocked

The restriction must work at both UI and server-side levels.

Direct URLs and APIs for unlicensed modules must also be blocked.

---

# 6. Central Licensing Platform

The Central Licensing Server is the commercial licensing authority.

It will eventually manage:

- Plans
- Licenses
- Installations
- Entitlements
- License activation
- License status
- License expiry
- Suspension
- Revocation
- Renewal
- Activation history
- Audit logs
- Platform administrators

Conceptual relationship:

Plan
→ License
→ Installation
→ Entitlements

The client application communicates with the central licensing system through a secure API.

---

# 7. License Creation

When creating a license, the administrator should be able to define:

- Plan
- Duration
- Start date
- Expiry date
- Optional modules

Core features are automatically included.

Example:

Core:
- Admin / User
- Dashboard
- Site Settings

Optional:
- [ ] Form Builder
- [ ] Membership
- [ ] Booking

The selected optional modules become the entitlements of that specific license.

---

# 8. Installation Identity

Each installed client application must have a unique Installation Identity.

Conceptually:

License
→ Installation ID
→ Specific Client Server

The licensing system should be able to associate an active license with its authorized installation.

The architecture should consider:

- Activation records
- Installation binding
- Duplicate activation
- Unauthorized reuse
- Installation cloning

---

# 9. License Lifecycle

Expected license states should support at minimum:

- Unactivated
- Active
- Expired
- Suspended
- Revoked

Lifecycle operations should eventually support:

- Activate
- Suspend
- Revoke
- Renew
- Extend
- Refresh / Revalidate

---

# 10. Expiry and Grace Period

The final expiry behavior is:

### 7 Days Before Expiry

Show a warning.

The client can continue normal use.

### Expiry Date

The license becomes expired and a 7-day post-expiry grace period begins.

The client can continue using the application during the grace period, with clear warning/status messaging.

### After 7-Day Grace Period

The entire application becomes locked.

Only the minimum licensing-related functionality remains accessible.

Important:

The following are separate concepts:

- Pre-expiry warning period
- Post-expiry commercial grace period
- Temporary network/API availability tolerance

---

# 11. Client License UI

The client application should expose only relevant license information.

Example:

License
- Status: Active
- Plan: Professional
- Expires: 20 Sep 2027
- Enabled Modules:
  - Form Builder
  - Booking

The client-facing product should NOT expose the platform's global management interfaces.

Do not expose in the client UI:

- Tenant Management
- Global Plans Management
- Platform Administrators
- Global License Management

---

# 12. Master Implementation Roadmap

## PHASE 0 — Existing System Audit

Goal: Understand the current system without modifying it.

Tasks:

- Repository structure audit
- Database/schema audit
- Current License system audit
- Current Plan system audit
- Current Tenant dependency audit
- Authentication/authorization audit
- Installer audit
- Form Builder audit
- Membership audit
- Booking audit
- Existing entitlement checks audit
- Existing security/bypass audit
- Existing test audit
- Migration/dependency risk audit

Deliverables:

- Current State Audit
- Database Audit
- Authentication Audit
- Installer Audit
- Module Audit
- Security Audit
- Gap Analysis
- Migration Risks

Rules:

- No application code changes
- No migrations
- No dependency installation
- No destructive operations

STOP → Human Review

---

## PHASE 1 — Final Architecture Specification

Goal: Lock the target architecture before implementation.

Define:

- Installation Identity
- License Identity
- Plan
- Entitlement
- License Status
- License Lifecycle
- Central Licensing Server
- Client Licensing Client
- Validation API
- Global License Guard
- Module Guards
- Expiry/Grace system
- Activation flow
- Renewal flow
- Suspension/Revoke flow

Deliverables:

- Architecture Specification
- Data Model Specification
- Module/Entitlement Specification
- License Lifecycle Specification
- Security Boundary Specification
- API Contract Specification

STOP → Human Review

---

## PHASE 2 — Central Licensing Platform Foundation

Goal: Build the central licensing authority.

Tasks:

- Central project foundation
- Authentication
- Platform Administrators
- Plans
- Licenses
- Installations
- Entitlements
- License status
- Activation records
- Audit logs

STOP → Test + Review

---

## PHASE 3 — Plan & License Management

Build the central administrative workflows:

- Create Plan
- Edit Plan
- Create License
- Edit License
- Activate
- Suspend
- Revoke
- Renew
- Extend

License creation must support independent optional-module selection.

STOP → Test + Review

---

## PHASE 4 — License Key & Installation Identity

Implement:

- Secure license key
- Installation ID
- License-to-installation binding
- Activation records
- Activation history
- Duplicate activation protection

Target:

License
→ Installation ID
→ Specific Client Server

STOP → Security Review

---

## PHASE 5 — Client Installer Rebuild

Implement the final installer sequence:

1. Database Configuration
2. Install Application
3. License Key
4. Central Validation
5. Activate Installation
6. Create Admin Account
7. Finish
8. Dashboard

Invalid license or failed activation must prevent normal application access.

STOP → Fresh Installation Test

---

## PHASE 6 — Global License Lock

Implement the application-wide license gate.

Conceptual flow:

Request
→ Authentication
→ License Check
→ Valid?
→ No: License Activation
→ Yes: Continue

The gate must protect:

- Dashboard
- Admin / User
- Site Settings
- Form Builder
- Membership
- Booking

Direct URL and API bypasses must not work.

STOP → Security Test

---

## PHASE 7 — Module Entitlement System

Implement independent module entitlements.

Example:

Core:
- Admin / User ✓
- Dashboard ✓
- Site Settings ✓

Optional:
- Form Builder ✗
- Membership ✗
- Booking ✓

Enforce entitlement at:

- Sidebar/menu
- Routes
- Controllers
- Services
- APIs
- AJAX/endpoints
- Relevant background operations

STOP → Module Security Test

---

## PHASE 8 — Client License UI

Build the client-facing license experience.

Include:

- License status
- Plan
- Expiry
- Enabled modules
- Warning states

Do not expose platform-level tenant/plan/license administration.

STOP → UI Review

---

## PHASE 9 — Expiry & Grace Period

Implement:

- 7-day pre-expiry warning
- Expiry transition
- 7-day post-expiry grace period
- Grace-period warnings
- Full lock after grace period
- Renewal/extension recovery

STOP → Time/Expiry Test

---

## PHASE 10 — Central ↔ Client Synchronization

Implement secure communication between client and central licensing server.

Support:

- License validation
- Refresh/revalidation
- License changes
- Suspension
- Revocation
- Renewal
- Expiry
- Network failure handling
- Secure local state/cache where required

Network availability tolerance must remain conceptually separate from the commercial expiry grace period.

STOP → Security Review

---

## PHASE 11 — Security Hardening

Perform a full security review covering:

- Direct URL bypass
- API bypass
- Local database manipulation
- Fake license status
- Fake entitlements
- License key exposure
- Installation cloning
- Replay attacks
- API authentication
- API authorization
- Request integrity/signing
- Rate limiting
- Secret handling
- Audit logging
- Fail-open/fail-closed behavior

Use Antigravity as an independent review/security pass where practical.

STOP → Security Approval

---

## PHASE 12 — Testing

### License Tests

- No license
- Invalid license
- Valid license
- Suspended license
- Revoked license
- Expired license
- Grace period
- Grace expired
- Renewed license

### Module Tests

- Core only
- Form Builder only
- Membership only
- Booking only
- Form Builder + Membership
- Form Builder + Booking
- Membership + Booking
- All optional modules

### Security Tests

- Direct URL
- Direct API
- Unauthorized installation
- Duplicate activation
- Tampered local state

### Installer Tests

- Fresh installation
- Invalid database
- Invalid license
- Valid license
- Activation failure
- Reinstallation

---

## PHASE 13 — Existing Solaya Migration

After the new architecture has been verified:

- Migrate existing licensing data where appropriate
- Refactor/remove tenant dependencies where required
- Migrate plans
- Migrate licenses
- Migrate entitlements
- Clean up old licensing routes
- Deprecate obsolete licensing paths
- Verify data integrity
- Preserve existing working functionality

Editor and Content remain outside V1.

STOP → Migration Review

---

## PHASE 14 — Final Production QA

Run the complete end-to-end lifecycle:

Central Licensing
→ Create License
→ Client Installer
→ License Activation
→ Dashboard
→ Core Access
→ Optional Module Access
→ Expiry Warning
→ Grace Period
→ Full Lock
→ Renewal
→ Unlock

Also verify:

- Performance
- Security
- Database integrity
- Error handling
- Logging
- Deployment
- Backup/rollback
- Documentation
- Production readiness

Final outcome:

A production-ready licensed client application controlled by a central licensing authority.

---

# 13. Agent Execution Strategy

Do NOT give the entire roadmap to an agent as one implementation task.

Use:

Phase
→ Inspect
→ Plan
→ Implement
→ Test
→ Audit
→ Human Review
→ Next Phase

Primary implementation agent:

- Cloud Agent

Independent review/testing:

- Antigravity

Do not allow both agents to modify the same implementation simultaneously.

---

# 14. Final Target Architecture

Central Licensing Platform:

Plans
Licenses
Installations
Entitlements
Administrators
Activation
Audit

↓

Secure Licensing API

↓

Client Server / Solaya

Installation ID
↓
License
↓
Entitlements
↓
Global License Guard
↓
Module Guards
↓
Application

V1 Core:

- Admin / User
- Dashboard
- Site Settings

V1 Optional:

- Form Builder
- Membership
- Booking

Future:

- Editor
- Content
- Additional modules

---

# 15. Final Product Experience

Client:

Install
→ Database
→ License Key
→ Central Validation
→ Activation
→ Admin Account
→ Dashboard

Without license:

FULL LOCK

With valid license:

CORE ACCESS

With optional entitlement:

CORRESPONDING MODULE ACCESS

7 days before expiry:

WARNING

After expiry:

7-day grace

After grace:

FULL LOCK

After renewal:

ACCESS RESTORED

This is the target state of the licensing rebuild.
