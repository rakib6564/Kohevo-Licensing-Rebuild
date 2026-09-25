# DECISIONS
## Kohevo / Solaya Commercial Licensing Rebuild

**Document status:** Architectural decision baseline  
**Purpose:** Record decisions that have been explicitly established for the licensing rebuild.

---

## 1. License-Centric Commercial Model

**Decision:** The licensing architecture is license-centric.

The primary commercial relationship is:

```text
Plan
  ↓
License
  ↓
Installation
  ↓
Entitlements
```

A license represents the actual commercial entitlement for a specific installation.

A plan is a package/template used to define what can be included when a license is created.

---

## 2. Central Licensing Server Is the Commercial Authority

**Decision:** The Central Licensing Server is authoritative for commercial licensing state.

It controls the authoritative state for:

- Plans
- Licenses
- Installations
- Entitlements
- Activation
- Suspension
- Revocation
- Expiry
- Renewal / Extension
- Activation history
- Audit records

The client application may cache or locally represent licensing state where required for operation, but it must not become the commercial authority.

---

## 3. Client Does Not Use Tenant Management as the Commercial Model

**Decision:** The client-facing licensing model does not expose or depend on tenant management.

The client must not expose:

- Tenant Management
- Plans Management
- Platform Administrators
- Global License Management

The client represents a licensed installation of the application.

Platform-level commercial administration remains on the Central Licensing Server.

---

## 4. Core Features Are Automatically Included

**Decision:** The following V1 features are always included in every valid license:

- Admin / User
- Dashboard
- Site Settings

They are not independently selectable license modules.

---

## 5. Optional Modules Are Independent

**Decision:** V1 optional modules are independently selectable:

- Form Builder
- Membership
- Booking

There is no licensing dependency between these modules.

The entitlement model must therefore support any combination of the optional modules.

---

## 6. Editor and Content Are Deferred

**Decision:** `Editor` and `Content` are excluded from V1 licensing scope.

They must not be added to V1 license creation or V1 entitlement enforcement as selectable modules.

The architecture must nevertheless remain extensible so they can be introduced later using the same entitlement mechanism.

---

## 7. Server-Side Enforcement Is Mandatory

**Decision:** Licensing is a server-side authorization boundary.

UI/menu hiding is not considered security.

The system must enforce:

```text
License Gate
    ↓
Application Access

Module Entitlement
    ↓
Module Access
```

Protected routes, controllers, services, APIs, AJAX actions, and applicable background operations must be protected at the appropriate server-side execution layer.

---

## 8. Global License Lock

**Decision:** An installation without a valid license must not have normal application access.

Only the minimum functionality required to install, activate, validate, or recover licensing state may remain accessible.

This lock must be enforced globally rather than separately implemented only on selected pages.

---

## 9. Installation Identity and Binding

**Decision:** Every installation receives a unique Installation ID.

A license is intended to be bound to an installation.

The licensing architecture must support detection and prevention of unauthorized reuse or duplicate activation where technically enforceable.

---

## 10. License Lifecycle Is Explicit

**Decision:** The lifecycle must explicitly represent at least:

```text
Unactivated
Active
Expired
Suspended
Revoked
```

Lifecycle operations include:

```text
Activate
Suspend
Revoke
Renew
Extend
Refresh
```

State transitions should be explicit and auditable rather than inferred only from scattered date or flag checks.

---

## 11. Expiry Warning and Grace Are Separate

**Decision:** Pre-expiry warning and post-expiry grace are separate concepts.

The intended behavior is:

```text
7 days before expiry
        ↓
Warning
        ↓
Expiry
        ↓
7-day commercial grace
        ↓
Full Lock
```

Network/API availability tolerance is also separate from commercial expiry grace.

A temporary licensing-server connectivity problem must not automatically redefine the commercial expiry date.

---

## 12. Kohevo Platform Identity Must Remain Protected

**Decision:** Kohevo remains the underlying platform identity.

Tenant/client branding may customize presentation where supported, but must not compromise:

- Kohevo platform identity
- Licensing authority
- Security boundaries
- Required platform signatures or identity controls

Brand customization is a presentation concern, not a mechanism for bypassing platform-level architecture or security.

---

## 13. Future Modules Must Use the Same Entitlement Architecture

**Decision:** New licensed modules should be added through the existing entitlement architecture rather than creating a separate licensing mechanism for each module.

The architecture must therefore avoid hard-coding V1 optional modules into the fundamental license model in a way that prevents future extension.

---

## 14. Future Commercial Automation

**Decision:** The licensing architecture should be compatible with future automated commercial workflows.

Future capabilities may include:

- Purchase → license creation
- Subscription → renewal
- Payment → license activation
- Automated extension
- Automated suspension
- Automated revocation

These should integrate with the Central Licensing Server rather than requiring a redesign of client licensing enforcement.

---

## 15. Existing System Must Be Inspected Before Modification

**Decision:** No implementation assumptions should be treated as established facts before the actual Solaya client, licensing platform, and database are inspected.

The project follows:

```text
Inspect
→ Understand
→ Plan
→ Implement
→ Test
→ Audit
```

Existing working functionality should be preserved unless an approved requirement explicitly changes it.

---

## 16. Phase-Based Implementation

**Decision:** The project will be implemented in controlled phases defined in `planning.md`.

A phase should not silently expand into unrelated work.

Each phase should have:

- Defined objective
- Scope
- Implementation work
- Tests
- Audit/review
- Completion criteria

---

## 17. Architecture Changes Require Explicit Decisions

**Decision:** Agents may identify better technical approaches, but they must not silently replace an established architectural decision.

If implementation reveals a conflict:

```text
Identify conflict
        ↓
Document evidence
        ↓
Explain impact
        ↓
Propose alternatives if necessary
        ↓
Obtain project decision
        ↓
Implement approved direction
```

---

## 18. No Unnecessary Rebuild of Unrelated Systems

**Decision:** The licensing rebuild should not become a general rewrite of the Solaya application.

Changes should remain focused on:

- Licensing
- Installation
- Activation
- Entitlements
- License enforcement
- Related security boundaries
- Necessary supporting architecture

Unrelated functionality should not be rewritten without an explicit reason and approval.

---

## 19. Documentation Is Part of the Architecture

**Decision:** Important architectural behavior and decisions must be documented.

At minimum:

- Requirements
- Architectural decisions
- Audit findings
- Implementation plans
- Security findings
- Migration risks
- QA results

Documentation should remain synchronized with the implementation as the project progresses.

---

## 20. Decision Precedence

For implementation decisions, use this precedence:

```text
Explicit Project Requirement
        ↓
Approved Architectural Decision
        ↓
Approved Phase Specification
        ↓
Verified Existing-System Constraints
        ↓
Implementation Detail
        ↓
Agent Preference
```

Agent preference must never override an explicit project requirement or approved architectural decision.

---

## 21. Decision Change Policy

If a decision needs to change later, the change must be explicit.

A changed decision should document:

- Previous decision
- New decision
- Reason for change
- Impact
- Migration implications
- Affected phases/documents

Historical decisions should not be silently rewritten in a way that removes the decision history.
