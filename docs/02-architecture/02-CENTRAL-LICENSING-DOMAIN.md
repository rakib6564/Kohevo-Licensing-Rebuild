# Phase 1: 02 — Central Licensing Domain

**Document Status:** Architecture Specification (Documentation Only — No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Scope:** `02-licensing` — the Central Licensing Server's domain model
**Reference:** `docs/01-audit/01-LICENSING-CURRENT-STATE.md`, `02-LICENSING-DATABASE-AUDIT.md`

---

## 1. Domain Entities

### 1.1 Product — `EXISTING`, unchanged

One row per product this server issues licenses for (e.g. `kohevo`). Table `licensing_products` (`id`, `slug`, `name`, `created_at`) is already correctly scoped and requires no structural change. Every other entity below is scoped to a product.

### 1.2 Client — `EXISTING`, unchanged

The commercial customer who owns one or more licenses. Table `licensing_clients` (`id`, `name`, `email`, `notes`, `created_at`) is already product-agnostic by design (a client can hold licenses across products) and requires no structural change.

**Naming note:** "Client" here means *the commercial customer entity on the Central Server* — it is unrelated to, and must not be confused with, "the client application" (`01-client`, the Solaya installation) used everywhere else in this document set. Both usages are inherited from the existing codebase's own vocabulary; this document does not rename either.

### 1.3 Plan — `TARGET` (restructured)

A **package/template** used only when *creating* a license (`DECISIONS.md` §1). Today (`licensing_plans.entitlements_json`) a plan is also the sole place entitlements are defined, which forces every license issued against a plan to receive identical, non-customizable modules (`DB-03`). Target: the Plan retains `product_id`, `slug`, `name`, and gains an optional `description`; its module set moves to a `licensing_plan_modules` template table used strictly as **pre-fill defaults** when an administrator starts creating a new license — never read at validation time. See `09-CENTRAL-DATABASE-DESIGN.md` §2.

### 1.4 License — `TARGET` (split out of `licensing_installs`)

The **actual commercial entitlement** for a specific installation (`DECISIONS.md` §1, `REQUIREMENTS.md` §6). Owns:

- `license_key_hash` (SHA-256 of the raw key — the raw key is never persisted, matching the existing `LicensingAPI::issueInstall()` convention)
- `status` (see `03-LICENSE-LIFECYCLE.md`)
- `issued_at`, `starts_at`, `expires_at`
- `activation_limit` (target default: `1`, per `REQUIREMENTS.md` §7 "1 License → 1 Installation")
- Its own entitlement set (`licensing_license_modules` — see `04-ENTITLEMENT-ARCHITECTURE.md`)
- A reference to the Plan it was created from (`plan_id`, nullable, historical/informational only after creation)
- A reference to its Client and Product

This is currently conflated into `licensing_installs`, which mixes license fields (`license_key_hash`, `status`, `expires_at`, `plan_id`) with installation fields (`domain`, `activation_count`, `installed_version`, `last_checkin_at`) in one table (`DB-02`). The target splits these so a License can exist, be issued, and even be suspended/revoked *before* any Installation has ever activated it — which the current schema cannot cleanly represent (an "unactivated" install row today already has a domain and activation counters that only make sense post-activation).

### 1.5 Installation — `TARGET` (split out of `licensing_installs` / `licensing_installation_bindings`)

The specific bound client deployment. Owns:

- `installation_id` (the 32-char identity generated client-side and submitted on first check-in)
- `license_id` (1:1-*currently-active* — see §3 below and `09-CENTRAL-DATABASE-DESIGN.md` §7 for how the schema now permits a *history* of Installations per License while still enforcing exactly one active binding at a time)
- `domain_normalized` (kept as metadata/fraud-signal — see `05-INSTALLATION-ACTIVATION.md` §4 for the enforcement-strength decision)
- `installed_version`, `last_checkin_at`, `last_checkin_ip`
- `status` (`active` / `suspended` / `revoked` / `superseded` at the *binding* level, independent of the License's own status — this lets an administrator unbind/reset an installation without changing the license's commercial status; mirrors the existing `licensing_installation_bindings.status` column, extended with `superseded` to support soft-deactivation on reset — `TARGET`, `LOCKED`, resolves Finding F-03/`R13`: a reset now flips the old binding to `superseded` with `deleted_at` set, rather than hard-deleting it, preserving the activation/installation history `REQUIREMENTS.md` §8 requires)

### 1.6 Entitlement — `TARGET` (moved from Plan to License)

A `(license_id, module_key)` grant. Core modules (`admin-user`, `dashboard`, `site-settings` — see `04-ENTITLEMENT-ARCHITECTURE.md` §2 for exact key naming) are implicit on every license and are **not** rows in this table; optional modules (`forms`, `membership`, `booking`) are explicit rows. This directly resolves `DB-03` and Gap 1 in `07-LICENSING-GAP-ANALYSIS.md`: an administrator issuing a license can now select any combination of the three optional modules independently of which plan was used as a starting template.

### 1.7 Activation — `TARGET` (explicit concept, was implicit)

The event where an Installation first successfully binds to a License. Today this is an implicit side effect of the first successful `handleCheckIn()` call (a `licensing_installation_bindings` row is created if none exists — `LicensingAPI.php` lines 200-214, verified directly). Target: this remains the mechanical trigger (see `05-INSTALLATION-ACTIVATION.md`), but the event is now also recorded as a first-class row in the License Lifecycle's audit trail (`03-LICENSE-LIFECYCLE.md` §5), not only as an `licensing_installation_bindings` insert and a `licensing_checkins` log line.

### 1.8 Lifecycle — `TARGET`

See `03-LICENSE-LIFECYCLE.md` for the full state machine. Today the Central Server stores a `status` enum column (`trial`, `active`, `expired`, `suspended`, `revoked`, `cancelled`) but has no explicit transition functions — status is read and written ad hoc by `admin/installs.php` and lazily recomputed at check-in time (`effectiveInstallStatus()`). Target: transitions are explicit operations with recorded actor/reason/timestamp (`DECISIONS.md` §10).

### 1.9 Renewal / Suspension / Revocation — `TARGET`

Lifecycle operations, detailed in `03`. Today only `status` can be edited via the admin UI (`admin/installs.php`, per `01-LICENSING-CURRENT-STATE.md` inventory, `PARTIALLY IMPLEMENTED`); there is no dedicated "renew" or "extend" operation distinct from directly editing `expires_at`, and no audit record of who changed it or why.

### 1.10 Platform Administrator — `EXISTING` mechanism, `TARGET` scope statement

Central administrators authenticate via the Central Server's own Slate `users`/`admin_sessions` tables and are authorized via the `licensing.manage` RBAC permission (`03-LICENSING-AUTH-AUDIT.md` §6.1, verified: `Auth::require(); Auth::requirePerm('licensing.manage');`). This is **not** the same mechanism as the `platform_admins` table that exists on the *client* codebase (`01-client/src/...`, gating `admin/tenants.php` etc.) — that is a different, client-side, multi-tenant-SaaS concept that the target architecture explicitly excludes from the client (`REQUIREMENTS.md` §11, `DECISIONS.md` §3). "Platform administrator" in the target model means: a user with sufficient RBAC permission on the Central Server itself. No new authentication mechanism is required; this is `EXISTING` and sufficient.

---

## 2. Domain Rules and Invariants

| ID | Invariant | Status | Enforcement Point |
| :--- | :--- | :--- | :--- |
| **INV-01** | A License belongs to exactly one Client and one Product. | `EXISTING` (already true of `licensing_installs.client_id`/`product_id`) | Schema (NOT NULL columns) |
| **INV-02** | A License's entitlement set is independent of its originating Plan once issued. | `TARGET` | `licensing_license_modules` is populated at issuance time and never re-read from the Plan afterward |
| **INV-03** | A License binds to at most `activation_limit` **currently-active** Installations (target default `1`) — a superseded (soft-deactivated) prior Installation does not count against this limit. | `EXISTING` mechanism (`activation_count >= activation_limit` check in `LicensingAPI::handleCheckIn()`), `TARGET` default value and `TARGET` uniqueness mechanism (`uniq_installation_active_license` generated-column unique key, `09` §7, resolving Finding F-03/`R13`) | `licensing_installations` partial-uniqueness (via generated column) + activation-limit check, see `05`, `09` §7 |
| **INV-04** | An Installation binds to exactly one License at a time. | `EXISTING` (a `licensing_installation_bindings.installation_id` unique key already enforces this) | Schema unique key |
| **INV-05** | The raw license key is never stored — only its SHA-256 hash. | `EXISTING`, verified (`LicensingAPI::issueInstall()`, `generateLicenseKey()`) | Application code; preserved unchanged |
| **INV-06** | Core entitlements cannot be individually disabled on a License. | `TARGET`, new | Application code — core keys are never represented as toggleable rows; see `04` §2 |
| **INV-07** | No functional dependency may exist between optional entitlements. | `EXISTING` fact about the application (`MOD-06` — forms/membership/booking are already independent plugins with no cross-dependency), `TARGET` as a schema-level guarantee (each `licensing_license_modules` row is independent, no ordering/prerequisite column) | Schema + Central license-creation UI (future phase) |
| **INV-08** | Every lifecycle state transition is attributable and timestamped. | `MISSING` today, `TARGET` | `licensing_license_events`, see `03` §5, `09` §5 |
| **INV-09** | A License's status is always independently derivable from stored state plus current time — never solely from a value that must be proactively swept. | `PARTIALLY IMPLEMENTED` today (`effectiveInstallStatus()` already does lazy expiry computation at check-in time, and the client mirrors this pattern in `LicenseService::effectiveStatus()`, per `config.php`'s `daily_cron` sweep comment), `TARGET` — same lazy-computation principle applied consistently everywhere status is read, including the warning/grace boundary in `08` | Application code |

---

## 3. Why License and Installation Are Separate Entities, Not a Discriminator Column

An alternative considered and rejected: keep one table with a `type` discriminator or simply keep `licensing_installs` as-is and bolt entitlements onto it. Reasons for a full split instead:

1. **A License can exist before any Installation.** `REQUIREMENTS.md` §4 requires the Central Server to *validate a license key* before an Installation is activated — meaning the License row must be creatable, and independently valid/invalid/suspended, without an installation yet existing. The current schema's `domain` column is `NOT NULL`, so today a row cannot represent "issued, not yet installed anywhere" cleanly (Phase 0 finding, `DB-02` follow-on: an unactivated install still requires a placeholder domain value in practice).
2. **A License's lifecycle (suspend/revoke/renew) is a commercial action; an Installation's binding state (active/suspended/revoked-at-the-binding-level, reset) is an operational action.** An administrator resetting bindings (`LicensingAPI::resetBindings()`, `EXISTING`) so a client can reinstall on new hardware must not be recorded as if the License itself were suspended — these are different audit events with different meaning today conflated in one status field.
3. **1-License-to-many-history, preserved by design (not merely a future nice-to-have).** `REQUIREMENTS.md` §7 locks "1 License → 1 Installation" as the *active* binding rule, but an administrator resetting bindings and re-activating on a new server means one License can have a *sequence* of Installations over its lifetime (only one active at a time). Splitting the tables makes this historical relationship representable; a conflated table cannot express "this license was previously bound to installation A, is now bound to installation B" without overloading columns. `TARGET`, `LOCKED` (resolves Finding F-03/`R13`, `16-PHASE-1-ANTIGRAVITY-REVIEW.md`): this document's original draft treated preserving that history across a reset as an open, deferrable question; it is now the Phase 1 baseline — a reset soft-deactivates (`status = 'superseded'`, `deleted_at` set) rather than hard-deleting, so the full sequence remains permanently queryable. See `09-CENTRAL-DATABASE-DESIGN.md` §7.

4. **Referential integrity is now database-enforced, not solely application-code discipline.** `TARGET`, `LOCKED` (resolves `R1`): `licensing_installations.license_id`, `licensing_license_modules.license_id`, and `licensing_license_events.license_id` all carry `FOREIGN KEY` constraints against `licensing_licenses.id` — see `09-CENTRAL-DATABASE-DESIGN.md` §12 for the exact `ON DELETE` semantics per table. This was Phase 1's original open item `R1`; it is resolved as part of this correction pass, not left for Phase 2 to decide.

This decision, its alternatives, and its impact are recorded formally in `15-PHASE-1-DECISIONS.md`.
