# Phase 1: 08 — Expiry, Grace & Offline Tolerance

**Document Status:** Architecture Specification (Documentation Only — No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Reference:** `REQUIREMENTS.md` §10, `DECISIONS.md` §11, `docs/01-audit/06-LICENSING-SECURITY-AUDIT.md` §2.12, `07-LICENSING-GAP-ANALYSIS.md` Gap 6

---

## 1. Three Separate Concepts

`REQUIREMENTS.md` §10.3 and `DECISIONS.md` §11 both explicitly warn against conflating these. Phase 0 confirmed the current system already conflates two of them (`REQ-17`, Gap 6):

```text
1. Pre-Expiry Warning     — commercial, time-based, driven by expires_at
2. Post-Expiry Grace      — commercial, time-based, driven by expires_at
3. Offline/Network         — operational, driven by fetched_at (cache
   Tolerance                  staleness), NOT a commercial concept at all
```

The current bug, restated precisely: `slate_license_gate()` applies its one and only 7-day threshold to `fetched_at` (cache staleness — concept 3) while `expires_at` (concept 1/2's actual driver) blocks with **zero** grace the instant it passes (`error_page.php` lines 221-229, verified). This means today's system has accidentally implemented *only* concept 3, and has implemented it as if it were concept 2, while concept 1 (pre-expiry warning) does not exist at all (`REQ-15`, "MISSING").

---

## 2. Target State Machine

```text
                    expires_at            expires_at + grace_days
                        │                         │
   ──────────┬──────────┼─────────────────────────┼──────────►  time
   Active     Warning    │        Grace             │   Locked
   (silent)  (banner,    │    (banner, full          │  (FULL LOCK)
              full        │     access)              │
              access)     │                          │
             ▲                                       ▲
    expires_at − warning_days              expires_at + grace_days
```

- **Active (silent):** more than `warning_days` before `expires_at`. No banner. Full access. `EXISTING` concept (this is simply "not near expiry"), no change needed.
- **Warning:** within `warning_days` of `expires_at` but not yet past it. Full access continues (`REQUIREMENTS.md` §10.1: "the client should show an expiry warning while normal use continues"). `MISSING` today — no warning exists in the current implementation at all.
- **Grace:** past `expires_at` but within `grace_days` of it. Full access continues, warning remains visible (`REQUIREMENTS.md` §10.2). `CONFLICTS WITH TARGET` today — the current system has *no* grace; `expires_at` passing blocks immediately for public visitors (and is masked entirely for admins by the unrelated admin-bypass bug, `AUTH-02`).
- **Locked:** past `expires_at + grace_days`. FULL LOCK, enforced by the Global License Guard (`06`) with only the whitelist surface (`06` §2) reachable. `MISSING` today.

`warning_days = 7` and `grace_days = 7` are both explicitly locked values from `REQUIREMENTS.md` §10.1/§10.2 and `DECISIONS.md` §11 — not open questions.

**Why this is not modeled as four separate License `status` values:** Active/Warning/Grace/Locked are all *derived* from `status = active` (or `expired`) plus `expires_at` plus the current time — they are presentation/enforcement states, not commercial states. `03-LICENSE-LIFECYCLE.md` §1 deliberately keeps the database `status` column at five/six values (Unactivated/Active/Expired/Suspended/Revoked[/Cancelled]) and computes Warning/Grace/Locked at read time, for the same reason `INV-09` requires status to be lazily derivable rather than dependent on a sweep job having already run: a grace-window boundary that depended on a cron job to "notice" the transition would introduce exactly the kind of staleness bug this whole document exists to prevent.

---

## 3. Where Warning/Grace/Locked Are Computed

Both the Central Server (for its own admin UI / API response) and the Client (for enforcement) need this computation, and per `DECISIONS.md` §2 the Central Server is authoritative. Target: the Central Server computes and includes the *authoritative* windowing in its signed check-in response payload — not just raw `status`/`expires_at`, but an already-classified summary — so the client does not need to independently reimplement the boundary logic (avoiding drift between a future server-side rule change and client code that would otherwise need a synchronized release). `RECOMMENDED` payload additions to the existing envelope (`LicensingAPI::handleCheckIn()`'s `$payload` array, currently `status`, `plan`, `entitlements`, `expires_at`, `checked_at`, `next_check_after`):

```text
warning_days   — currently-configured pre-expiry warning window (default 7)
grace_days     — currently-configured post-expiry grace window (default 7)
```

The client then derives Warning/Grace/Locked locally by comparing `expires_at` (already in the payload) against local time, using the server-supplied window lengths — this keeps the *lengths* centrally controlled (in case a future business decision changes them per-plan or globally) while keeping the *comparison* local (so the client does not need network connectivity to determine which of the four states it is currently in — this is exactly what concept 3, offline tolerance, exists to support, see §4).

**Precondition this entire section depends on — `LOCKED` correction (resolves Finding F-02, `16-PHASE-1-ANTIGRAVITY-REVIEW.md`):** the client can only derive Warning/Grace/Locked "locally" as described above if the Central Server actually *sends* a signed payload with `status`/`expires_at`/`warning_days`/`grace_days` for a bound Installation whose License is `expired`, `suspended`, or `revoked` — not an HTTP error in place of a payload. An earlier draft of `11-LICENSING-API-CONTRACT.md` implicitly assumed this without stating it as a requirement, while the then-current codebase actually returned `HTTP 403` with no payload at all for exactly this case (`LicensingAPI.php`, verified). `11-LICENSING-API-CONTRACT.md` §7 now states this explicitly as a `LOCKED` requirement: `HTTP 200` with a signed envelope for every commercial state, for any request that resolves to a valid, bound `(license_key, install_id, domain)` triple. Without that fix, a License moving to Expired/Suspended/Revoked would never actually reach this section's Grace/Locked derivation at all — the client would simply see a failed check-in and, per §4 below, correctly but *for the wrong reason* fall back to offline-tolerance handling instead of authoritative state.

---

## 4. Network/API Availability Tolerance

This is `REQUIREMENTS.md` §10.3's third, separate concept, and is the one dimension of this document explicitly **not** given a locked numeric value by the requirements — `REQUIREMENTS.md` says only that it "is a separate concern" and "must not incorrectly treat network/API availability tolerance as an extension of the commercial license expiry period." No specific duration is mandated.

**Target design:** the client trusts its last-verified, signed license state (`10-CLIENT-LICENSING-DATABASE-DESIGN.md` §3) for a bounded period after `fetched_at`/`remote_checked_at`, independent of where that state currently sits in the Warning/Grace/Locked timeline. If the Central Server cannot be reached, the client continues enforcing whatever state its last verified snapshot implies (including, correctly, enforcing Locked if the snapshot already showed Locked) — it does not fall back to "assume licensed" (that would be exactly today's fail-open bug, finding 2.12) nor to "assume locked the moment connectivity drops" (that would make ordinary transient network blips take down paying customers' production sites, which is disproportionate and not what any part of the requirements asks for).

**Numeric value — `REQUIRES VERIFICATION`:** `RECOMMENDED` starting point: retain the existing 7-day figure that today's code already uses for this exact purpose (`REMOTE_GRACE_SECONDS = 7 * 86400` in `EntitlementService`, and the same `7 * 86400` in `slate_license_gate()`) — reusing an already-implemented, already-reasonable value avoids inventing a new number with no grounding, while formally renaming it (in documentation and in a future implementation) from "grace" to "offline tolerance" so it is never confused with the commercial `grace_days` from §2, even though both currently happen to be 7 days. Making offline tolerance a *separately configurable* value (rather than hardcoded) is `RECOMMENDED` so a future decision to change one does not silently change the other purely because they shared a constant. This choice — reuse 7 days vs. pick a different number — should be confirmed explicitly in `15-PHASE-1-DECISIONS.md` before implementation, since it is a judgment call this document is making on the project's behalf, not a value handed down by requirements.

**Interaction with Warning/Grace/Locked:** offline tolerance is a ceiling on *trusting the last snapshot at all*, not a fifth timeline state. If the client cannot reach the Central Server and the last verified snapshot is older than the offline tolerance window, the client must stop trusting it and fail closed (Locked) — this is the fail-closed default from `06-GLOBAL-LICENSE-GUARD.md` §7, applied specifically to the "we haven't heard from the server in a long time" case. Within the tolerance window, the client keeps enforcing whatever Warning/Grace/Locked state the snapshot's own `expires_at` math implies.

---

## 5. Stale Cache Behavior

| Scenario | Behavior |
| :--- | :--- |
| Snapshot fresh (within offline tolerance), license currently Active/Warning | Full access, per §2 |
| Snapshot fresh, license currently Grace | Full access + warning banner, per §2 |
| Snapshot fresh, license currently Locked (server already told us so) | FULL LOCK — this is correct enforcement of an already-known state, not an offline-tolerance scenario at all |
| Snapshot stale (past offline tolerance), server unreachable | FULL LOCK (fail closed, §4) — regardless of what the stale snapshot's own expiry math would have said, since the client can no longer be confident the snapshot reflects reality (a Suspend or Revoke that happened server-side after the snapshot was taken would not yet be reflected) |
| Snapshot stale, server reachable again | Client performs a fresh check-in on next opportunity (page load background trigger, or the whitelisted cron in `06` §5) and replaces the snapshot; enforcement immediately reflects the new, current state |

This table directly resolves `REQ-16`/`REQ-17` and Gap 6 in `07-LICENSING-GAP-ANALYSIS.md`.

---

## 6. Central Server Unavailable — Distinguishing From Commercial Lock

Per `05-INSTALLATION-ACTIVATION.md` §5, the installer already needs to distinguish "your license key is invalid" from "we could not reach the licensing server" as separate user-facing messages. The same distinction must hold at runtime for the recovery screen (`06` §2, §9): if the Global License Guard is currently in Locked state, the recovery screen must indicate *why* — commercial expiry/grace exhausted vs. suspended/revoked vs. "we have not been able to verify your license recently, please check connectivity" (the offline-tolerance-exceeded case) — since the remediation differs (renew vs. contact provider vs. fix network/DNS/firewall). This is `RECOMMENDED` UX guidance flowing from the architecture, not itself a new architectural mechanism — the underlying state needed to distinguish these cases (last verified `status`, `expires_at`, `fetched_at`) is already part of the cached snapshot described in `10`.

---

## 7. Clock Manipulation Considerations

`REQUIREMENTS.md` §13 lists general security requirements including request validation and secure handling, and `planning.md` Phase 11 explicitly lists "clock manipulation considerations" as an audit scope item. This document flags the risk without prescribing a specific mitigation mechanism (that belongs in a later security-hardening phase's implementation, not this architecture document):

- **Risk:** the client enforces Warning/Grace/Locked by comparing the *local server's* clock against `expires_at`/`fetched_at`. If an operator (or an attacker with server access) rolls the local system clock backward, an already-Locked installation could appear to be back in Active/Warning, and offline tolerance could be indefinitely extended by repeatedly rewinding the clock rather than ever successfully reconnecting.
- **Partial existing mitigation:** because the license state itself (`status`, `expires_at`) only ever changes via a *signed* response from the Central Server, an attacker rolling the local clock cannot fabricate a *different* commercial state — only distort how long the *existing, genuinely-signed* state is treated as valid/fresh. This bounds the damage (they cannot forge "active" out of nothing) but does not eliminate it (they can extend a real but stale "active" snapshot's apparent freshness).
- **`RECOMMENDED`, `REQUIRES VERIFICATION`:** a future implementation phase should consider binding freshness comparisons to a monotonic clock source where the runtime provides one, and/or having the signed payload's own `checked_at` timestamp cross-checked against the local clock at the moment it is *received* (detecting large discrepancies then, when a live network response can be compared against local time, rather than only at later read time when there is nothing to compare against). This document does not mandate a specific implementation, since PHP's portable options for monotonic/tamper-resistant time are limited and any specific mechanism should be evaluated during Phase 11 (Security Hardening) with the actual hosting environment constraints in view.
