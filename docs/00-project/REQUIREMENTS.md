# REQUIREMENTS
## Kohevo / Solaya Commercial Licensing Rebuild

**Document status:** Project foundation  
**Scope:** Commercial licensing system and client licensing integration  
**Source of truth:** Approved project requirements

---

## 1. Purpose

The project will rebuild the commercial licensing architecture for the Solaya application and its central Kohevo licensing platform.

The licensing system must provide a secure, centrally controlled way to create, activate, manage, validate, suspend, revoke, renew, and expire application licenses.

The client application must enforce the license and its enabled modules at the server side.

---

## 2. Product Scope

The target system consists of two related applications:

1. **Client Application**
   - The Solaya application installed on a customer's server.
   - Runs the licensed application.
   - Communicates with the Central Licensing Server for licensing operations.

2. **Central Licensing Server**
   - The commercial authority for licensing.
   - Manages plans, licenses, installations, entitlements, lifecycle state, activation history, and audit records.

The client application must not become the commercial authority.

---

## 3. V1 Licensing Scope

### 3.1 Core Features

Every valid V1 license automatically includes:

- Admin / User
- Dashboard
- Site Settings

These are not optional license modules.

### 3.2 Optional Features

The following modules may be independently selected when creating a license:

- Form Builder
- Membership
- Booking

Each optional module is independent.

No optional module may require another optional module for licensing purposes.

### 3.3 Valid Optional Combinations

The licensing architecture must support all combinations, including:

- No optional module
- Form Builder only
- Membership only
- Booking only
- Form Builder + Membership
- Form Builder + Booking
- Membership + Booking
- Form Builder + Membership + Booking

### 3.4 Explicit V1 Exclusions

The following are outside V1 licensing scope:

- Editor
- Content

They may be introduced as licensed modules in a future version without requiring a redesign of the licensing architecture.

---

## 4. Client Installation Flow

The intended client installation flow is:

```text
Database Configuration
        ↓
Install Application
        ↓
License Key
        ↓
Central Licensing Server Validation
        ↓
Activate Installation
        ↓
Create Admin Account
        ↓
Finish
        ↓
Dashboard
```

The exact implementation details may be refined during architecture design, but the final experience must preserve this logical sequence.

---

## 5. License Requirement

A valid license is required for normal application use.

If the client does not have a valid license:

- normal application access must be blocked
- normal pages must be blocked
- normal URLs/routes must be blocked
- normal APIs must be blocked
- normal AJAX operations requiring application access must be blocked
- applicable background operations must also be prevented

Only the minimum routes/services required for installation, licensing, activation, and recovery of the licensing state may remain accessible.

The license gate must be enforced server-side.

Hiding menus or UI elements is not sufficient.

---

## 6. License as the Actual Entitlement

The license is the actual commercial entitlement for a specific installation.

A plan is a package/template used when creating licenses.

The final access decision must be based on the active license and its entitlements.

The client must not treat a plan definition alone as authorization to use functionality.

---

## 7. Installation Identity

Each installed client application must have a unique Installation ID.

The licensing system must support binding a license to an installation.

The architecture must be designed to prevent unauthorized reuse of a license across installations and to detect or reject unauthorized activation attempts where technically enforceable.

---

## 8. Central Licensing Server Responsibilities

The Central Licensing Server must be responsible for:

- Plan management
- License creation
- License management
- Installation records
- Entitlement management
- License activation
- License validation
- License status
- License expiry
- License suspension
- License revocation
- License renewal
- License extension
- License refresh
- Activation history
- Audit logging
- Platform administrator access

The client application must not expose or control these global commercial management functions.

---

## 9. License Lifecycle

The licensing system must support at least these states:

- Unactivated
- Active
- Expired
- Suspended
- Revoked

The licensing system must support operations including:

- Activate
- Suspend
- Revoke
- Renew
- Extend
- Refresh

State transitions must be explicit and auditable.

---

## 10. Expiry and Grace Period

Expiry handling consists of separate concepts.

### 10.1 Pre-Expiry Warning

Starting 7 days before the license expiry date, the client should show an expiry warning while normal use continues.

### 10.2 Commercial Post-Expiry Grace

When the license reaches its expiry date, a 7-day post-expiry grace period begins.

During this grace period:

- the application remains usable
- expiry warnings remain visible

After the 7-day post-expiry grace period:

- the application enters FULL LOCK

### 10.3 Network/API Availability Tolerance

Temporary inability to contact the Central Licensing Server is a separate concern from commercial expiry grace.

The implementation must not incorrectly treat network/API availability tolerance as an extension of the commercial license expiry period.

---

## 11. Client-Facing Licensing UI

The client may display simple licensing information such as:

- License status
- Plan
- Expiry date
- Enabled modules

The client-facing application must not expose:

- Tenant Management
- Plans Management
- Platform Administrators
- Global License Management

The client-facing licensing experience should remain focused on the installed application's license.

---

## 12. Platform Identity

Kohevo remains the underlying platform identity.

Tenant/client branding may exist where supported by the product, but tenant customization must not remove or compromise required Kohevo platform identity, licensing authority, or security boundaries.

---

## 13. Security Requirements

Licensing enforcement must be treated as a security boundary.

The implementation must consider, as applicable:

- Authentication
- Authorization
- Server-side license validation
- Server-side module entitlement enforcement
- Installation identity
- Secure communication with the Central Licensing Server
- Request validation
- Secret/key protection
- Replay protection where required
- Rate limiting where appropriate
- Audit logging
- Input validation
- CSRF protection where applicable
- Error handling without sensitive information leakage

Client-side checks alone must never be considered sufficient authorization.

---

## 14. Future Extensibility

The architecture must allow future licensed modules to be introduced without redesigning the core licensing model.

In particular, future modules such as:

- Editor
- Content
- Other future application modules

must be able to become license entitlements through the same general entitlement architecture.

The system should also support future automated commercial workflows such as:

- Online purchases
- Automated license creation
- Automated renewal
- Subscription lifecycle integration

These future capabilities must not require replacing the client licensing architecture.

---

## 15. Backward Compatibility and Existing System

The existing Solaya application and existing licensing-related implementation must be inspected before implementation changes are made.

The rebuild must protect existing working functionality unless an approved requirement explicitly changes it.

No assumption about the existing implementation should be treated as fact until verified from the actual project source and database.

---

## 16. Requirements Governance

These requirements are project-level requirements.

Agents must not silently:

- remove requirements
- weaken security requirements
- change licensing scope
- introduce optional-module dependencies
- expose platform-level management in the client
- replace central licensing authority with client-side authority

If an implementation conflict is discovered, the conflict must be reported and resolved through an explicit project decision.
