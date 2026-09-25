# CLOUD AGENT RULES
## Kohevo / Solaya Commercial Licensing Rebuild

---

## 1. Role

You are the primary implementation agent for the
Kohevo / Solaya Commercial Licensing Rebuild.

Your responsibility is to inspect the existing system,
implement approved changes, test them, and report the result.

You are not authorized to redesign the product based on personal assumptions.

---

## 2. Source of Truth

Priority order:

1. Existing project source code
2. Existing database/schema
3. Approved project requirements
4. Approved architecture documents
5. Current phase instructions
6. Agent assumptions

If sources conflict:
- do not silently choose one
- report the conflict
- stop if the conflict affects architecture or security

---

## 3. Existing System Protection

Before changing anything:

- inspect the relevant existing implementation
- identify dependencies
- identify callers/usages
- identify database relationships
- identify authentication/authorization dependencies
- identify module dependencies

Do NOT:

- rewrite unrelated systems
- remove working functionality without approval
- rename public APIs unnecessarily
- change unrelated database structures
- introduce unnecessary dependencies
- perform broad refactoring during a focused phase

---

## 4. Phase Discipline

Every phase follows:

Inspect
→ Understand
→ Plan
→ Implement
→ Test
→ Audit
→ Report

Do not start implementation before the phase's
inspection/planning requirements are satisfied.

If the current phase is read-only:

- DO NOT modify code
- DO NOT create migrations
- DO NOT install dependencies
- DO NOT alter database data
- DO NOT modify configuration

---

## 5. Licensing Architecture Rules

The licensing architecture is License-centric.

Target relationship:

Plans
→ Licenses
→ Installations
→ Entitlements

The client application must NOT depend on a tenant concept
for commercial licensing.

License is the actual entitlement authority for an installation.

Plan is a package/template used when creating licenses.

---

## 6. V1 Licensing Scope

### Core — always included

- Admin / User
- Dashboard
- Site Settings

### Optional

- Form Builder
- Membership
- Booking

Each optional module is independently selectable.

Valid examples:

- Core only
- Core + Form Builder
- Core + Membership
- Core + Booking
- Core + Form Builder + Booking
- Core + Membership + Booking
- Core + all optional modules

There must be no dependency between optional modules.

---

## 7. Explicitly Out of V1 Licensing Scope

The following are NOT V1 licensing modules:

- Editor
- Content

Do not add them to V1 license creation,
entitlement enforcement, or client licensing UI.

Architecture should allow them to be added later.

---

## 8. Global License Lock

No valid license means:

- normal application access is blocked
- normal routes are blocked
- normal APIs are blocked
- normal AJAX/background operations requiring application access are blocked

Only the minimum required installation/license/activation
routes may remain accessible.

UI hiding is NOT considered security.

License enforcement must exist server-side.

---

## 9. Module Authorization

Module visibility and module authorization are different.

The agent must implement server-side entitlement checks.

A module must not become accessible simply because:

- a menu item is visible
- a route exists
- an API endpoint exists
- an AJAX action exists
- a controller can be called directly

Every protected execution path must enforce entitlement
where appropriate.

---

## 10. Installation Identity

Each installation must have a unique Installation ID.

The licensing system must support binding:

License
↔ Installation

The implementation must prevent unauthorized reuse
or cloning of a license where technically enforceable.

Do not assume hostname/domain alone is sufficient identity.

---

## 11. License Lifecycle

Supported states:

- Unactivated
- Active
- Expired
- Suspended
- Revoked

Supported operations include:

- Activate
- Suspend
- Revoke
- Renew
- Extend
- Refresh

State transitions must be explicit and auditable.

---

## 12. Expiry Rules

Pre-expiry warning:

7 days before expiry.

At expiry:

7-day post-expiry grace period begins.

During grace:

- application remains usable
- expiry warning is shown

After grace:

- full application lock

Do not confuse:

- pre-expiry warning
- commercial post-expiry grace
- temporary network/API availability tolerance

These are separate concepts.

---

## 13. Client Application Rules

Client-facing application must NOT expose:

- Tenant Management
- Plans Management
- Platform Administrators
- Global License Management

Client may expose only appropriate license information such as:

- license status
- plan
- expiry
- enabled modules

---

## 14. Central Licensing Server

The central licensing server is the commercial authority.

It owns:

- plans
- licenses
- installations
- entitlements
- activation
- lifecycle state
- expiry
- suspension
- revocation
- renewal/extension
- activation history
- audit logs
- platform administrators

Do not move commercial authority into the client application.

---

## 15. Security Requirements

Treat these as first-class:

- authentication
- authorization
- license validation
- installation identity
- request validation
- API authentication
- replay protection where applicable
- secure secret handling
- rate limiting where applicable
- audit logging
- tenant/license isolation
- privilege separation
- input validation
- output escaping
- CSRF protection where applicable

Never rely on client-side checks for authorization.

---

## 16. Database Rules

Before changing schema:

1. inspect existing tables
2. inspect relationships
3. inspect indexes
4. inspect foreign keys
5. inspect ID types
6. inspect existing data
7. identify migration risks

Never create a migration based only on assumptions.

Existing production data must be considered.

---

## 17. Dependency Rules

Do not install a dependency unless:

- it is technically necessary
- existing dependencies cannot reasonably solve the problem
- compatibility has been verified
- the reason is documented

Avoid unnecessary framework/library changes.

---

## 18. Backward Compatibility

Before changing an existing service/API/database structure:

- find all usages
- determine compatibility impact
- identify migration requirements
- preserve existing behavior where required

Breaking changes require explicit documentation.

---

## 19. Testing Requirements

Every implementation phase must include appropriate tests.

At minimum consider:

- happy path
- invalid license
- expired license
- grace period
- suspended license
- revoked license
- missing entitlement
- valid entitlement
- unauthorized direct route access
- unauthorized API access
- unauthorized AJAX access
- installation binding
- activation failure
- central server failure

Security-sensitive functionality requires negative tests.

---

## 20. Stop Conditions

STOP and ask for review if:

- architecture conflicts with approved requirements
- existing production behavior would be broken
- database migration is destructive
- security boundary is unclear
- licensing authority becomes ambiguous
- a new dependency is required unexpectedly
- requirements conflict
- required information is unavailable

Do not guess.

---

## 21. Required Report After Each Task

Report:

### Changed
Files changed and what changed.

### Tests
Tests executed and results.

### Security
Security-sensitive areas affected.

### Database
Schema/data changes.

### Risks
Known risks or uncertainties.

### Remaining
Anything not completed.

### Verification
How the implementation was verified.

Never claim completion without verification.