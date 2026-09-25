# Phase 1: 04 — Entitlement Architecture

**Document Status:** Architecture Specification (Documentation Only — No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Reference:** `REQUIREMENTS.md` §3, `DECISIONS.md` §4, §5, §6, §13, `docs/01-audit/05-LICENSING-MODULE-AUDIT.md`

---

## 1. Model

An entitlement is a grant of one **module key** to one **License**. Two categories:

```text
Core (implicit, always granted, not individually toggleable)
  - admin-user
  - dashboard
  - site-settings

Optional (explicit, independently selectable, no interdependency)
  - forms
  - membership
  - booking

Future (excluded from V1, same mechanism when added)
  - editor
  - content
  - <whatever comes next>
```

**Module key naming — `ASSUMPTION`:** this document uses `forms`, `membership`, `booking` because these already exist as the client's plugin slugs (`01-client/plugins/forms/plugin.json`, `.../membership/plugin.json`, `.../booking/plugin.json`, verified — `plugins.status = 'active'` is keyed by these exact slugs today). Reusing the plugin slug as the entitlement/module key means the Module Guard (`07`) can map one-to-one from "which plugin is this route/controller/API in" to "which entitlement does it need" with no separate lookup table. Core module keys (`admin-user`, `dashboard`, `site-settings`) do not correspond to existing plugin slugs since Core is not implemented as a plugin (`MOD-01` — it is built directly into the application shell); these three key names are proposed here and should be confirmed as part of `15-PHASE-1-DECISIONS.md` before Phase 2 implementation.

---

## 2. Core Entitlements

Core modules are **not** rows in the per-license entitlement table. They are implicit consequences of the License itself being in an enforceable-access state (Active-within-warning, or Active-within-grace — see `08`). This is a deliberate design choice, not an oversight:

- `REQUIREMENTS.md` §3.1: "These are not optional license modules."
- `DECISIONS.md` §4: "They are not independently selectable license modules."

If Core were modeled as three rows in `licensing_license_modules` that merely happen to always be inserted, nothing in the schema would prevent a future bug (or a compromised admin session) from deleting one of those three rows and disabling Dashboard access on an otherwise valid license — which would violate the requirement. Modeling Core as "true whenever the license is valid, full stop, no row lookup" removes that failure mode entirely. `INV-06` (`02-CENTRAL-LICENSING-DOMAIN.md` §2) codifies this.

**Enforcement implication for the client:** the Global License Guard (`06`) alone is sufficient to protect Core routes — Core routes need no separate Module Guard call, only the Guard's basic "is there a currently valid license" check. This matches `REQUIREMENTS.md` §5's ordering: license gate first (protects Core + everything else), module entitlement second (protects Optional only).

---

## 3. Optional Entitlements

Each optional module is an independent `(license_id, module_key)` row. Per `REQUIREMENTS.md` §3.3 and `DECISIONS.md` §5, all eight combinations (∅, and each of the seven non-empty subsets of {forms, membership, booking}) must be representable and valid. A flat join table with one row per granted module trivially satisfies this — there is no combinatorial enum, no bitmask, and no ordering dependency between rows.

Phase 0 already confirmed there is no *functional* coupling between these three modules in the application code (`MOD-06`: "no functional dependencies between Form Builder, Membership, and Booking... each functions completely independently"). The entitlement schema preserves this by construction: `INV-07` prohibits any prerequisite/ordering column on `licensing_license_modules`.

---

## 4. Plan Template vs. Actual License Entitlement

```text
Plan (template)                          License (actual entitlement)
──────────────────                       ─────────────────────────────
licensing_plan_modules                   licensing_license_modules
  - default suggested module set           - the modules THIS license
    shown when an admin starts               actually grants, copied
    creating a new license                   from the plan at issuance
                                              time (or hand-picked) and
  READ ONLY at license-creation time.         then fully independent
  NEVER read at validation/check-in time.     of the plan afterward.
```

This is the direct fix for `DB-03` / Gap 1 (`07-LICENSING-GAP-ANALYSIS.md`): today, because entitlements live *only* on the plan (`licensing_plans.entitlements_json`), every license issued against, say, a "Professional" plan gets the exact same module set, and an administrator cannot grant one Professional customer `booking` only while granting another Professional customer `booking + membership`. In the target model, the Plan is consulted exactly once — as a convenience default at the moment `admin/installs.php`'s (future, Phase 3) successor screen renders the "create license" form — and never again. Changing a Plan's template later has **zero** effect on already-issued licenses, which is the correct behavior for a commercial entitlement system (a plan price/feature-set change should not silently alter what existing paying customers already have).

`REQUIREMENTS.md` §6 states this directly: "The client must not treat a plan definition alone as authorization to use functionality." The License, not the Plan, is what `EntitlementService`-equivalent logic (client-side) and `handleCheckIn()` (server-side) must read.

---

## 5. Future Module Extensibility

Adding a new licensable module (e.g. `editor`) in a future phase requires, by this architecture:

1. A new allowed value for `module_key` (no schema change — the column is a plain string/slug, not an enum, specifically so this step requires no migration; see `09-CENTRAL-DATABASE-DESIGN.md` §4 for the exact column type recommendation).
2. A new Module Guard call at `editor`'s entry points on the client, exactly like the three V1 guards (`07-MODULE-GUARD-ARCHITECTURE.md`).
3. A checkbox in the (future) license-creation UI.

No change to: the License entity, the Installation entity, the Global License Guard, the check-in API contract, or any other V1 optional module's code. This satisfies `REQUIREMENTS.md` §14 and `DECISIONS.md` §13 directly. `Editor` and `Content` remain explicitly excluded from V1 entitlement enforcement per `DECISIONS.md` §6 — this document does not add them, it only confirms the mechanism they will use later does not require redesign.

---

## 6. No Dependency Between Form Builder, Membership, and Booking

Restated for completeness against `REQUIREMENTS.md` §3.2: the target schema and guard design contain no mechanism — no foreign key, no ordering column, no "requires" field — by which granting or checking one optional module's entitlement could ever consult another's. `INV-07` is the formal statement of this; §3 above is the concrete schema reasoning. Phase 0's module audit (`05-LICENSING-MODULE-AUDIT.md` §3) already verified the *application* has no such coupling; this section's job is only to confirm the *licensing* layer being added does not introduce one.
