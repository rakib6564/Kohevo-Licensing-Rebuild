# Phase 1: 03 — License Lifecycle

**Document Status:** Architecture Specification (Documentation Only — No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Scope:** `02-licensing` — License state machine
**Reference:** `REQUIREMENTS.md` §9, `DECISIONS.md` §10, `docs/01-audit/02-LICENSING-DATABASE-AUDIT.md` §4.4

---

## 1. States

| State | Meaning | Current Schema Equivalent |
| :--- | :--- | :--- |
| **Unactivated** | License has been issued but has never had a successful Installation binding. | Today's `trial` default status is the closest analog, but nothing distinguishes "never bound" from "bound and currently in a trial commercial tier" — these are different concepts conflated by reusing one enum value. `MISSING` as a distinct state; `TARGET` adds it explicitly. |
| **Active** | License is bound (or bindable) and within its commercial validity window (before expiry, or within pre-expiry warning — see `08`). | `active` — `EXISTING` value, semantics preserved |
| **Expired** | `expires_at` has passed. Distinct from grace/lock — see `08` for how Active/Grace/Locked are derived from Expired + elapsed time, not modeled as separate top-level states. | `expired` — `EXISTING` value, but today's lazy computation (`effectiveInstallStatus()`) treats "expired" as immediately blocking with no grace; target reinterprets "expired" as the trigger for the grace window, not for an immediate lock. See `08`. |
| **Suspended** | Administrator-initiated, reversible hold. `TARGET` correction (resolves Finding F-02, `16-PHASE-1-ANTIGRAVITY-REVIEW.md`): a *bound* Installation's routine check-in still succeeds at the transport level (`HTTP 200`) and receives a signed payload carrying `status: "suspended"` — see `11-LICENSING-API-CONTRACT.md` §7 — which is how the client's Global License Guard actually learns to enforce FULL LOCK regardless of grace/warning state; the check-in is not itself rejected. A *first-time activation attempt* against a License that is already Suspended (no existing binding) is a different case and is rejected — see §5. | `suspended` — `EXISTING` value |
| **Revoked** | Administrator-initiated, terminal. Semantically distinct from Suspended: intended to communicate "this license will not be reinstated," though the schema does not need to *prevent* an administrator from technically reversing it — that is a policy/UI decision, not a data-model one. Same `TARGET` correction as Suspended above applies: a bound Installation's check-in still succeeds and returns a signed `status: "revoked"` payload, rather than an HTTP error. | `revoked` — `EXISTING` value |

**`cancelled`** exists in the current enum (`licensing_installs.status`) but has no requirement basis in `REQUIREMENTS.md` or `DECISIONS.md`. `ASSUMPTION`: it is retained in the target enum as a superset-compatible value (administrative "this was never actually purchased / issued in error" marker, distinct from Revoked which implies a license that *was* valid and is being terminated) but is out of scope for this rebuild's required behavior. `REQUIRES VERIFICATION` — confirm with the project owner whether `cancelled` should be a first-class Phase 1 state or dropped.

`trial` also exists in the current enum and is used throughout `EntitlementService::LICENSED_STATUSES = ['trial', 'active']` and `slate_license_gate()`'s `$licensedStatuses`. `REQUIREMENTS.md` §9 does not list "Trial" as a required V1 state. `ASSUMPTION`: `trial` is retained as an allowed value of **Active** (a trial is commercially active, just time-boxed and perhaps plan-flagged), not a separate top-level state in this document's model, to avoid contradicting `EXISTING` code that already treats it as license-valid. `REQUIRES VERIFICATION` — confirm trial licensing is in scope for V1 at all, since it is not mentioned in the locked requirements.

---

## 2. State Diagram

```text
                    ┌───────────────┐
   License issued → │  Unactivated  │
                    └───────┬───────┘
                            │ first successful Installation binding
                            │ (Activate)
                            ▼
                    ┌───────────────┐   Suspend    ┌───────────────┐
                    │    Active     │─────────────►│   Suspended   │
                    │ (incl. Warning│◄─────────────│               │
                    │  sub-state,   │   Unsuspend  └───────┬───────┘
                    │  see 08)      │                       │ Revoke
                    └───────┬───────┘                       ▼
                            │ expires_at reached      ┌───────────────┐
                            ▼                         │    Revoked    │
                    ┌───────────────┐                 │  (terminal)   │
                    │    Expired    │                 └───────────────┘
                    │ (Grace window,│
                    │   see 08)     │
                    └───────┬───────┘
                 Renew/Extend│  │ grace window elapses
                            │  ▼
                            │ ┌───────────────┐
                            └►│ Locked (still  │
                              │ "Expired" data-│
                              │ level; FULL    │
                              │ LOCK enforced) │
                              └───────────────┘
                                     │ Renew/Extend
                                     ▼
                                  Active
```

"Locked" is an *enforcement* state derived from `Expired` + elapsed grace time, not a separate `status` enum value — see `08-EXPIRY-GRACE-OFFLINE.md` §2 for why this is deliberately not a sixth database state. Revoked and (administratively) Suspended may also result in FULL LOCK at the enforcement layer, but for a different reason (explicit administrator action, not time elapsing).

---

## 3. Operations

| Operation | Preconditions (Valid From) | Effect | Status |
| :--- | :--- | :--- | :--- |
| **Activate** | Unactivated (or Active with `activation_count < activation_limit`, for a second concurrent installation if `activation_limit > 1` — not the V1 default) | Creates the Installation binding; transitions Unactivated → Active if this is the first binding | `EXISTING` mechanism (implicit in `handleCheckIn()`), `TARGET` as an explicit, named, audited operation |
| **Suspend** | Active, Expired | → Suspended | `PARTIALLY IMPLEMENTED` — today only a raw status edit via `admin/installs.php`, no dedicated operation or audit reason |
| **Revoke** | Any non-terminal state | → Revoked (terminal) | `PARTIALLY IMPLEMENTED` — same as Suspend |
| **Renew** | Expired (including within or past grace) | → Active, sets a new `expires_at` for a new commercial period | `MISSING` as a distinct operation — today an administrator would just edit `expires_at` directly with no semantic difference from Extend |
| **Extend** | Active, Expired | → Active (if was Expired) or remains Active, pushes `expires_at` forward without necessarily representing a full new commercial period (e.g., a goodwill grace extension) | `MISSING` — see note below on Renew vs Extend distinction |
| **Refresh** | Active, Expired, Suspended, Revoked | No state transition. Re-validates and re-signs the *current* state for the Installation, whatever it is — including `expired`/`suspended`/`revoked` (`TARGET` correction, resolves Finding F-02: an earlier draft of this document implicitly assumed Revoked was outside Refresh's scope because the current code rejects it with an HTTP error instead of a signed payload; see `11-LICENSING-API-CONTRACT.md` §7) | `PARTIALLY IMPLEMENTED` today (`handleCheckIn()` already re-signs current state for Active/Expired-as-error cases; `TARGET` extends this to Suspended/Revoked returning a signed payload rather than an error, per `11` §7) |

**Renew vs. Extend — `REQUIRES VERIFICATION`:** `REQUIREMENTS.md` §9 and `DECISIONS.md` §10 both list Renew and Extend as distinct operations but do not define the semantic difference. This document's `ASSUMPTION`: **Renew** = issue a new commercial period (e.g., the next year's subscription term, typically resetting `starts_at`), **Extend** = push `expires_at` further out without treating it as a new period (e.g., a 14-day goodwill extension, or extending mid-term). Both produce the same schema effect (`expires_at` moves forward, status returns to Active) but should be recorded with different `event_type` values in the audit trail (`09-CENTRAL-DATABASE-DESIGN.md` §5) so the distinction is preserved for reporting even though it has no distinct enforcement behavior. This split should be confirmed with the project owner before Phase 3 implementation.

---

## 4. Re-activation Rules

"Re-activation" covers two different scenarios that must not be conflated:

1. **The same Installation checking in again** (routine, daily). This is not re-activation — it is **Refresh**. The binding already exists; `handleCheckIn()` already correctly treats this as an update to `last_seen_at`/`last_seen_ip`, not a new binding (`LicensingAPI.php` lines 216-227, verified).
2. **A different Installation attempting to bind to a License that already has an active binding.** Given the locked "1 License → 1 Installation" rule (`REQUIREMENTS.md` §7) and target default `activation_limit = 1`, this must be rejected (`INVALID` transition — see §5) unless an administrator has explicitly reset the existing binding first (`LicensingAPI::resetBindings()`, `EXISTING`, described in `05-INSTALLATION-ACTIVATION.md` §5). This is the intended path for legitimate re-installs (server migration, disaster recovery) and must always be an explicit, audited, administrator-initiated action — never automatic.

---

## 5. Invalid Transitions

| Attempted Transition | Why Invalid | Current Behavior |
| :--- | :--- | :--- |
| Unactivated → Suspended/Revoked without ever activating | Not explicitly forbidden by requirements, but has no defined meaning — a license that was never used being "suspended" is indistinguishable from being "revoked" in effect | `ASSUMPTION`: permit Revoke (an administrator may cancel an unissued/unused license outright) but treat Suspend on Unactivated as equivalent to Revoke for enforcement purposes since there is nothing to "pause." `REQUIRES VERIFICATION`. |
| Revoked → any other state | Revoked is terminal per this document's model | `EXISTING` schema does not technically prevent editing `status` back to `active` via the admin UI; `TARGET` — the future Central admin UI (Phase 3 implementation) should require an explicit "re-issue as a new license" flow instead of silently un-revoking, but this document does not mandate a hard database constraint against it, since Phase 0 found no evidence such a constraint was ever required. `RECOMMENDED`, not `REQUIRES VERIFICATION`. |
| Second Installation binding while `activation_count >= activation_limit` | Violates "1 License → 1 Installation" | `EXISTING` — already correctly rejected today (`activation_limit` check in `handleCheckIn()`, HTTP 403 `invalid_request`) |
| Check-in from an Installation ID that does not match the License's existing binding | License reuse / cloning attempt | `EXISTING` — already correctly rejected today (`binding_mismatch` failure code, HTTP 404 `invalid_request`) |
| **First-time** Activation of a License that is Suspended or Revoked (no existing Installation binding yet) | A suspended/revoked license must not be usable, and there is no existing bound Installation to sign a current-state payload *for* | `EXISTING` mechanism, `TARGET` scope-narrowed by the F-02 correction — rejected with an HTTP error (`inactive`/anti-enumeration-safe `invalid_request`, per `11-LICENSING-API-CONTRACT.md` §13), same as today. This is distinct from a Suspend/Revoke applied to a License that **already has** a bound Installation — see the Suspended/Revoked rows in §1 above, where the now-bound Installation's *subsequent* check-ins succeed with a signed `suspended`/`revoked` payload rather than being rejected. |

---

## 6. Auditability Requirement

`INV-08` (`02-CENTRAL-LICENSING-DOMAIN.md` §2) requires every transition to be attributable and timestamped. Today, `licensing_checkins` records *check-in attempts* (including rejections with a `failure_code`) but does not record *administrator-initiated* lifecycle changes (a status edit via `admin/installs.php` leaves no trace of who changed it or why — verified: `admin/installs.php` performs a direct `Database::update()` with no accompanying audit insert). Target: a `licensing_license_events` table (see `09-CENTRAL-DATABASE-DESIGN.md` §5) records every Activate/Suspend/Revoke/Renew/Extend with `actor` (admin user ID, or `'system'` for the implicit first-activation trigger), `reason` (free text, required for Suspend/Revoke), and `metadata_json`. This is `MISSING` today and is a required addition, not merely `RECOMMENDED`, per `DECISIONS.md` §10 ("State transitions must be explicit and auditable").
