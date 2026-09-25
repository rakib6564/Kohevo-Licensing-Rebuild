# Phase 1: 01 — Target Architecture

**Document Status:** Architecture Specification (Documentation Only — No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Inputs:** `planning.md`, `docs/00-project/REQUIREMENTS.md`, `docs/00-project/DECISIONS.md`, `docs/01-audit/01` through `08`, direct source inspection of `01-client/` and `02-licensing/`
**Classification Legend:** `EXISTING` (verified present today) · `TARGET` (the architecture this document specifies) · `MISSING` (required, not present today) · `RECOMMENDED` (not explicitly required, proposed as good practice) · `ASSUMPTION` (not verified, used for planning) · `REQUIRES VERIFICATION` (open question for human decision)

---

## 1. Purpose

This document is the top-level target architecture for the Kohevo / Solaya commercial licensing rebuild. It does not introduce new product decisions — every structural choice below traces to `REQUIREMENTS.md`, `DECISIONS.md`, or a Phase 0 finding. Where a Phase 0 finding exposes a gap that the locked decisions do not fully resolve, this document proposes a specific closure and flags it `RECOMMENDED` or `REQUIRES VERIFICATION` rather than inventing new product scope.

Companion documents (`02` through `15` in this directory) expand each area referenced here.

---

## 2. Two Applications, One Commercial Authority

```text
┌───────────────────────────────┐        ┌───────────────────────────────┐
│   CENTRAL LICENSING SERVER    │        │        CLIENT APPLICATION      │
│         (02-licensing)         │        │           (01-client)          │
│                                 │        │                                 │
│  Commercial authority for:     │◄──────►│  Runs the licensed application │
│   - Products                   │  HTTPS │  Enforces the license and its  │
│   - Clients                    │  API   │  entitlements server-side      │
│   - Plans                      │        │                                 │
│   - Licenses                   │        │  NEVER the commercial          │
│   - Installations              │        │  authority — only a verified   │
│   - Entitlements               │        │  consumer of Central state     │
│   - Lifecycle / Activation     │        │                                 │
│   - Audit                      │        │                                 │
└───────────────────────────────┘        └───────────────────────────────┘
```

This is `EXISTING` as a two-codebase split (`01-client`, `02-licensing` are already separate deployable Slate installs) and `TARGET` as a clean authority boundary. Today the client also runs a second, competing local licensing engine (`01-client/src/Services/Licensing/LicenseService.php`, `PlanService.php`) that independently issues and tracks licenses on the client's own database (Phase 0 finding, `01-LICENSING-CURRENT-STATE.md` §4). The target architecture treats that local engine as **not authoritative** for the license-centric commercial model — see `13-MIGRATION-STRATEGY.md` and `14-BACKWARD-COMPATIBILITY.md` for its disposition.

Both applications are already instances of the same underlying **Slate framework** (PHP 8.2+, flat modular monolith, PSR-4 autoload, Hook bus, Plugin lifecycle, direct PDO `Database` facade). `02-licensing` is itself a single-tenant Slate install running the `licensing` plugin — it is not a separate technology stack. This is `EXISTING` and the target architecture preserves it: no new framework, no new runtime, no new primary datastore.

---

## 3. Component Boundaries

### 3.1 Central Licensing Server (`02-licensing`)

| Component | Responsibility | Status |
| :--- | :--- | :--- |
| `licensing_products`, `licensing_clients` | Product catalogue, commercial customer catalogue | `EXISTING` — unchanged |
| **Plan domain** | Package/template used only when *creating* a license; not itself an authorization source | `TARGET` — restructured, see `04` |
| **License domain** | The commercial entitlement instance: status, dates, key, per-license module grants | `TARGET` — split out of `licensing_installs`, see `02`, `03` |
| **Installation domain** | The bound deployment: installation ID, activation state, check-in history | `TARGET` — split out of `licensing_installs` / `licensing_installation_bindings`, see `05` |
| **Entitlement domain** | Core (implicit) + optional (explicit, independent) module grants per license | `TARGET`, see `04` |
| **Validation/Activation API** | `POST /licensing/check` (recurring) and activation (first check-in) | `EXISTING` mechanism, `TARGET` contract refinement, see `11` |
| **Signing** | Ed25519 response signing (`sodium_crypto_sign_detached`) | `EXISTING` — preserved as-is, see `12` |
| **Platform administration** | Central-only RBAC (`licensing.manage` permission on the central Slate install's own `users`/`roles`) | `EXISTING` mechanism, `TARGET` scope (client must never expose this), see `02`, `12` |
| **Audit** | `licensing_checkins` (phone-home log, existing) + new license lifecycle audit trail | `EXISTING` (checkins) + `MISSING` (lifecycle audit), see `03`, `09` |

### 3.2 Client Application (`01-client`)

| Component | Responsibility | Status |
| :--- | :--- | :--- |
| **Installation Identity** | One immutable Installation ID per deployment | `EXISTING` mechanism (`installation_identity` table), `TARGET` decoupling from tenant uniqueness, see `05`, `10` |
| **License Client** | Signed check-in transport + local cache | `EXISTING` (`RemoteLicenseClient`, `LicenseSignatureVerifier`) — reusable largely as-is, see `06`, `08` |
| **License State Cache** | Tamper-evident local copy of the last verified signed state | `EXISTING` mechanism, `TARGET` hardening (store raw payload + signature), see `08`, `10` |
| **Global License Guard** | Server-side barrier in front of the whole application | `MISSING` today (only public routes are gated, and even those admins bypass) — this document's central deliverable, see `06` |
| **Module Guards** | Independent server-side entitlement checks for Form Builder, Membership, Booking | `MISSING` today (zero entitlement checks in any optional module), see `07` |
| **Installer** | DB Config → Install → License Key → Central Validation → Activate → Admin → Finish → Dashboard | `PARTIALLY IMPLEMENTED` today (stops after DB config + migrations + arbitrary plugin picker, no licensing step at all), see `05` |
| **Client License UI** | Status/plan/expiry/enabled-modules display only | `PARTIALLY IMPLEMENTED` today (dashboard shows presentational stats only, does not gate), `TARGET` unchanged presentational role but now backed by real enforcement |

---

## 4. Request / Validation Flow (Target)

```text
Any request into the client application
        │
        ▼
┌───────────────────────────────────────────┐
│ Global License Guard (see 06)              │
│  - whitelist check (install/assets/        │
│    activation/recovery routes pass through)│
│  - else: require a verified, non-expired,  │
│    non-suspended, non-revoked license      │
│    state (accounting for warning/grace,    │
│    see 08) — fail CLOSED                   │
└───────────────────────────────────────────┘
        │ passes (licensed, or on whitelist)
        ▼
┌───────────────────────────────────────────┐
│ Auth / RBAC (Auth::require, Auth::can)     │
│  — UNCHANGED, existing identity layer      │
└───────────────────────────────────────────┘
        │ passes
        ▼
┌───────────────────────────────────────────┐
│ Module Guard (see 07) — only for routes    │
│ belonging to an optional module            │
│  - require that module's entitlement to be │
│    present on the CURRENT license state    │
└───────────────────────────────────────────┘
        │ passes
        ▼
   Controller / Service / API handler executes
```

Core features (Admin/User, Dashboard, Site Settings) pass the Global License Guard and Auth/RBAC layers but never hit a Module Guard — they are implicitly granted by any valid license (`REQUIREMENTS.md` §3.1, `DECISIONS.md` §4). Optional features additionally require their specific module entitlement. This ordering — license validity gates everything first, entitlement gates specific modules second — is `TARGET` and directly resolves Phase 0 findings `AUTH-01` through `AUTH-05` and `MOD-01` through `MOD-05`.

The License Client's own recurring check-in (background refresh) and the Global License Guard's read of the local cache are two different concerns run on two different cadences — see `06` and `08`.

---

## 5. License → Installation → Entitlement Relationship

```text
Plan (template)
   │  used only at license-creation time to pre-fill defaults
   ▼
License (the commercial entitlement instance — DECISIONS.md §1)
   │  1:1 binding (REQUIREMENTS.md §7; "1 License → 1 Installation")
   ▼
Installation (the specific bound client deployment)
   │  entitlements are read FROM the license, not the plan
   ▼
Entitlements (Core: implicit/always · Optional: forms | membership | booking,
              independently selectable, no interdependency — REQUIREMENTS.md §3)
```

This is the single conceptual model referenced throughout every companion document. The current system violates it in two ways that this architecture corrects:

1. **Plan/License/Installation conflation** (`DB-02`, `DB-03`, Gap 1 in `07-LICENSING-GAP-ANALYSIS.md`): today `licensing_installs` is simultaneously the license and the installation, and entitlements live only on the Plan (`entitlements_json`), so every installation on a plan gets identical, non-customizable entitlements. Target: three distinct relational entities — License, Installation, and a per-license entitlement set — detailed in `02`, `04`, `09`.
2. **License ≠ authorization source today**: the client's `EntitlementService` does correctly read from the verified remote cache, but nothing calls it outside the dashboard's presentational stat cards (`MOD-01`). Target: every protected execution path reads the current entitlement set via the Global License Guard / Module Guard, never the Plan directly (`REQUIREMENTS.md` §6).

---

## 6. Global Licensing Boundary

The boundary is a **security boundary**, not a UI concern (`DECISIONS.md` §7, `REQUIREMENTS.md` §13). Concretely, this means:

- The decision "is this installation licensed, and for which modules" must be re-derivable from server-side state alone, on every request that needs it, independent of what the browser sends.
- No authenticated session, RBAC role (including Super Admin — `AUTH-01`), or client-supplied parameter may substitute for a valid license/entitlement check (`AUTH-01`, `AUTH-02`, finding 2.6 "Manipulated Request Parameters" in `06-LICENSING-SECURITY-AUDIT.md`).
- The boundary must be reachable from every execution surface the codebase actually has: direct admin scripts, the public router, the API router, AJAX endpoints, the customer portal, and cron — not only `index.php`/`public.php` as today (`MISSING` finding 2.10, "Missing Global Middleware / Central Gate").

Full design in `06-GLOBAL-LICENSE-GUARD.md` and `07-MODULE-GUARD-ARCHITECTURE.md`.

---

## 7. Future Extensibility

Two extensibility requirements are structural, not aspirational, and are reflected directly in the schema and guard design (not deferred to "later refactor"):

1. **New licensed modules (Editor, Content, others) without redesign** (`REQUIREMENTS.md` §14, `DECISIONS.md` §13, §6): the entitlement model is a generic `(license, module_key)` grant set, not a fixed enum of three columns. Adding `editor` or `content` as a fourth/fifth module key requires a new row type in the same table, a new Module Guard call at the relevant entry points, and a Plan/License-creation UI update — it does not require changing the License, Installation, or Guard architecture itself. See `04-ENTITLEMENT-ARCHITECTURE.md` §5.
2. **Future commercial automation** (`REQUIREMENTS.md` §14, `DECISIONS.md` §14): purchase → license creation, subscription → renewal, payment → activation. Because the License Lifecycle (`03`) already models explicit states and auditable transitions driven by a defined set of operations (Activate/Suspend/Revoke/Renew/Extend/Refresh), an automated caller (a future billing webhook) is simply another caller of the same lifecycle operations — it does not require a parallel lifecycle model.

---

## 8. What This Document Does Not Decide

Per `DECISIONS.md` §17 and §20, the following are flagged here as open items requiring an explicit project decision before Phase 2 implementation, and are carried into `15-PHASE-1-DECISIONS.md` with full detail:

- Whether the new Central schema should add foreign-key constraints (current plugin convention explicitly omits them — see `09-CENTRAL-DATABASE-DESIGN.md` §6).
- Whether domain-normalized binding remains a **hard** enforcement factor alongside Installation ID, or becomes a soft/informational signal (current behavior is a hard match — see `05-INSTALLATION-ACTIVATION.md` §4, `12-SECURITY-ARCHITECTURE.md` §3).
- The exact numeric value of the offline/network availability tolerance window, which `REQUIREMENTS.md` §10.3 requires to exist and be distinct from commercial grace but does not specify a number for (see `08-EXPIRY-GRACE-OFFLINE.md` §4).

---

## 9. Document Map

| Doc | Scope |
| :--- | :--- |
| `02-CENTRAL-LICENSING-DOMAIN.md` | Central entity model and domain rules |
| `03-LICENSE-LIFECYCLE.md` | States, transitions, invariants |
| `04-ENTITLEMENT-ARCHITECTURE.md` | Core vs optional entitlements, extensibility |
| `05-INSTALLATION-ACTIVATION.md` | Installer flow, activation, binding, re-install |
| `06-GLOBAL-LICENSE-GUARD.md` | Application-wide lock design |
| `07-MODULE-GUARD-ARCHITECTURE.md` | Per-module server-side guards |
| `08-EXPIRY-GRACE-OFFLINE.md` | Warning/grace/lock/offline-tolerance state machine |
| `09-CENTRAL-DATABASE-DESIGN.md` | Target Central schema |
| `10-CLIENT-LICENSING-DATABASE-DESIGN.md` | Target Client schema, tenant coexistence |
| `11-LICENSING-API-CONTRACT.md` | Conceptual API contract |
| `12-SECURITY-ARCHITECTURE.md` | Threat-by-threat mitigation mapping |
| `13-MIGRATION-STRATEGY.md` | New-install-only scope, legacy code disposition |
| `14-BACKWARD-COMPATIBILITY.md` | What must not change |
| `15-PHASE-1-DECISIONS.md` | Decision register |
