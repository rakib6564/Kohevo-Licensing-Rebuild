# Phase 1: 11 — Licensing API Contract

**Document Status:** Architecture Specification (Documentation Only — No Implementation)
**Phase:** Phase 1 — Target Architecture Specification
**Reference:** `REQUIREMENTS.md` §13, `docs/01-audit/03-LICENSING-AUTH-AUDIT.md` §6, `06-LICENSING-SECURITY-AUDIT.md`

> Conceptual contract only. No endpoint is implemented, no route is registered, and no code is written from this document.

---

## 1. Existing Transport, Preserved

| Aspect | Design |
| :--- | :--- |
| Endpoint | `POST /licensing/check` — `EXISTING`, unchanged path. Serves both "Activation" (first check-in) and "Refresh" (routine check-in) per `05-INSTALLATION-ACTIVATION.md` §2 — no separate `/licensing/activate` endpoint is introduced. |
| Request encoding | JSON body — `EXISTING`, unchanged |
| Request fields | `product`, `license_key`, `install_id`, `domain`, `app_version`, `checked_at` — `EXISTING`, unchanged |
| Response envelope | `{"payload": "<json string>", "signature": "<base64>"}` — `EXISTING`, unchanged. `payload` is signed as a raw string, not re-serialized after decoding, specifically to avoid canonicalization mismatches — this reasoning is already documented in `LicensingAPI.php`'s own comments and is preserved as-is. |
| Signature scheme | Ed25519, `sodium_crypto_sign_detached` / `sodium_crypto_sign_verify_detached` | `EXISTING`, preserved — see `12-SECURITY-ARCHITECTURE.md` §5 |
| Anti-enumeration | Identical HTTP status + body for "invalid product," "unknown key," and "domain mismatch" | `EXISTING`, preserved |
| Response status for a **validly bound** installation | `HTTP 200` with a signed envelope for **every** commercial `status` value (`active`, `expired`, `suspended`, `revoked`) — `TARGET` correction, `LOCKED`. **Not** `EXISTING`/preserved for this specific case — see §7 and §13; this corrects Finding F-02 of `16-PHASE-1-ANTIGRAVITY-REVIEW.md`. |

## 2. Payload Additions — `TARGET`, `installation_id` is `LOCKED`

Per `08-EXPIRY-GRACE-OFFLINE.md` §3, the response payload gains fields beyond today's `status`, `plan`, `entitlements`, `expires_at`, `checked_at`, `next_check_after`:

```json
{
  "installation_id": "a1b2c3d4e5f60718293a4b5c6d7e8f90",
  "status": "active",
  "plan": "professional",
  "entitlements": ["forms", "booking"],
  "expires_at": "2027-09-20T00:00:00Z",
  "warning_days": 7,
  "grace_days": 7,
  "checked_at": "2026-09-25T10:00:00Z",
  "next_check_after": 86400
}
```

`entitlements` now reflects `licensing_license_modules` (per-license, `09-CENTRAL-DATABASE-DESIGN.md` §8) rather than `licensing_plans.entitlements_json` (today's source, `DB-03`) — this is a data-source change, not a shape change; the client-side parsing of this field is unaffected.

**`installation_id` — `LOCKED`, project-owner decision, not optional or `RECOMMENDED`.** This closes Finding F-01 of `16-PHASE-1-ANTIGRAVITY-REVIEW.md` (`P0 — CRITICAL`, offline license cloning). Without it, the signed envelope is not bound to any specific installation: because the Central Server's public verification key is identical across every client, an attacker who extracts `raw_payload`/`raw_signature` from one licensed installation's database can transplant both values verbatim into any other installation's cache, and `sodium_crypto_sign_verify_detached()` will succeed there too — the signature proves the payload came from the Central Server, but says nothing about *which* installation it was issued to. Binding requires two changes, both mandatory:

1. **Server (Central):** `LicensingAPI::handleCheckIn()`'s `$payload` array, generated and signed for every check-in (activation and refresh alike), MUST include the requesting Installation's own `installation_id` value — read from the resolved `licensing_installations` row (`09-CENTRAL-DATABASE-DESIGN.md` §7), never echoed back unvalidated from the request body.
2. **Client:** on every verification — write-time (`RemoteLicenseClient::checkIn()`) and read-time (`10-CLIENT-LICENSING-DATABASE-DESIGN.md` §4) alike — the client MUST assert, as a condition of trusting the payload at all, that:
   ```php
   $decodedPayload['installation_id'] === $localInstallationId // from installation_identity, 10 §2
   ```
   A signature that verifies cryptographically but whose `installation_id` does not match the local `installation_identity.installation_id` MUST be treated exactly as if signature verification itself had failed — i.e., the payload is untrusted and the Global License Guard's fail-closed behavior (`06` §7) applies. This is what actually closes the cloning vector: a copied `raw_payload`/`raw_signature` pair still carries the *original* installation's `installation_id`, so the equality check fails on the clone the moment it is loaded, without needing to wait for — or reach — the Central Server at all. See `10` §4 and `12-SECURITY-ARCHITECTURE.md` §2.2 for the resulting change to the cloning threat model (detection-only → prevented at read time).

## 3. Validation

- **Request shape:** all five fields (`product`, `license_key`, `install_id`, `domain`, `app_version`) required and non-empty except `app_version`; `EXISTING`, unchanged (`400 invalid_request` on missing/malformed fields).
- **`install_id`:** `EXISTING` — lower-cased, trimmed; `TARGET`: format-validate as exactly 32 lowercase hex characters (matching `bin2hex(random_bytes(16))`'s output shape) before any database lookup, rejecting malformed values early. `RECOMMENDED` hardening, not currently verified as present (Phase 0 did not report explicit format validation beyond `strtolower(trim(...))`).
- **`domain`:** `EXISTING` — normalized via `LicensingAPI::normalizeDomain()` (scheme-agnostic, lowercase host, default-port-stripped, path/query/fragment rejected). Preserved unchanged.

## 4. Activation

Covered in full in `05-INSTALLATION-ACTIVATION.md` §2. Contract-level summary: the **first** successful `POST /licensing/check` for a given (`product`, `license_key`, `install_id`) triple that has no existing binding **is** the activation event, subject to `activation_limit`. No distinct request/response shape from a routine check-in.

## 5. Check-in (Refresh)

Every subsequent call from an already-bound Installation. `EXISTING` behavior preserved: updates `last_seen_at`/`last_seen_ip` on the binding, logs a `licensing_checkins` row, returns a freshly-signed current-state envelope. This is also how a Suspend/Revoke/Renew/Extend performed by an administrator on the Central Server actually reaches the client — the client only learns of a lifecycle change on its next check-in, there is no push mechanism. `ASSUMPTION`: this pull-only model is acceptable and matches `next_check_after` (`EXISTING`, currently hardcoded to `86400` = 24h) governing how promptly a client notices a change; `REQUIRES VERIFICATION` if faster propagation (e.g., for an urgent Revoke) is a business requirement — nothing in `REQUIREMENTS.md`/`DECISIONS.md` mandates push/real-time propagation, so this document does not add one.

## 6. Renewal/Extension Propagation

No new endpoint — propagation happens exactly like any other status change, via the next check-in reading the License's current `expires_at`/`status` (§5). The **administrative** side (a Central admin calling Renew/Extend, per `03-LICENSE-LIFECYCLE.md` §3) is a Central Server-internal operation (future admin UI, Phase 3 implementation) with no client-facing API surface of its own — the client is a passive consumer of whatever state the next check-in reports, consistent with `DECISIONS.md` §2 (Central Server is the sole commercial authority; the client never initiates or negotiates a lifecycle change).

## 7. Suspension/Revocation Propagation

Same mechanism as §6 — the next check-in's `status` field reflects `suspended`/`revoked`, and the client's Global License Guard (`06`) responds to that status the same way it responds to `expired` past grace: FULL LOCK. No distinct wire-level signal is needed for Suspend/Revoke vs. Expire — the Guard's decision is always "what does the current verified `status` + `expires_at` imply," uniformly.

**Correction — `LOCKED`, resolves Finding F-02 of `16-PHASE-1-ANTIGRAVITY-REVIEW.md` (`P1 — HIGH`):** for this propagation to work at all, the Central Server MUST actually deliver a signed payload carrying `status: "expired"` / `"suspended"` / `"revoked"`, not an HTTP error. Today's `LicensingAPI.php` (line ~182) short-circuits to `HTTP 403 {"error": "invalid_request"}` with **no payload and no signature** the instant `effectiveInstallStatus()` returns anything other than `trial`/`active` — which directly contradicts `08-EXPIRY-GRACE-OFFLINE.md` §2–§3 (Warning/Grace/Locked are derived from a *signed* `status`/`expires_at` the client receives) and this section's own premise. An expired/suspended/revoked license can never enter commercial Grace, and the client cannot distinguish "authoritatively suspended" from "transient network failure," if the server never sends a payload to distinguish them.

**Target behavior, unconditional for any request that resolves to an existing, valid `(license_key, install_id, domain)` binding** (i.e., the credentials are correct and the binding matches — see §13 for what still remains an error):

- Return `HTTP 200` with the normal signed envelope (§1, §2), where `payload.status` is the License's *actual* current commercial state — `active`, `expired`, `suspended`, or `revoked` — computed the same lazily-derived way `effectiveInstallStatus()` already computes it today, just no longer discarded in favor of a 403.
- `warning_days`/`grace_days` (§2) are included regardless of `status`, so a client receiving `status: "expired"` has everything it needs to compute Warning vs. Grace vs. Locked locally per `08` §2–§3, and a client receiving `status: "suspended"`/`"revoked"` has an authoritative signed basis for immediate FULL LOCK rather than treating the rejection as a possibly-transient failure that leaves the stale cached state in place.
- `HTTP 400`/`403`/`404` are reserved exclusively for what §13 already calls out as separate `failure_code` categories: malformed/missing request fields, an unknown/invalid `license_key`, or a `binding_mismatch` (an `install_id`/`domain` that does not match this License's bound Installation at all) — none of which describe "a real, correctly-identified installation whose license has lapsed."
- This also resolves the ambiguity `05-INSTALLATION-ACTIVATION.md` §5 already treats as important to keep distinguishable in the installer UI ("could not reach licensing server" vs. a commercial rejection): after this fix, the *only* time the client legitimately sees an HTTP error from a reachable Central Server is a genuine credential/binding problem, never a lapsed-but-real license — making "HTTP error" and "commercial rejection short of a real binding" synonymous again, which is what the installer's existing failure-message design in `05` §5 already assumes.

## 8. Authentication

**Current model, preserved:** the license key itself (hashed and compared server-side) is the sole bearer credential (`EXISTING`, verified — `AUTH-06`: "no client API key, bearer token, or client-side private key signature on the incoming request"). This is `PARTIALLY IMPLEMENTED` by design, not accidentally weak: a check-in request that gets the license key wrong is rejected outright before any binding logic runs, and a request with a *correct* key but a *mismatched* `install_id`/`domain` is separately rejected by the binding check — so the license key alone is not actually sufficient to impersonate a specific bound installation, only to *attempt* a check-in.

**`RECOMMENDED` enhancement (not required by locked decisions, flagged for consideration):** issue a secondary, per-installation shared secret at activation time (distinct from the license key, which the license key itself already partially serves as a stand-in for) to use as an HMAC key for request signing, closing the "any caller who obtains a valid license key can attempt binding-mismatch probes against it" residual exposure. This is discussed further in `12-SECURITY-ARCHITECTURE.md` §2 as a defense-in-depth item, not a blocking requirement — `REQUIREMENTS.md` §13 lists "Authentication," "Request validation," and "Secure communication" as considerations "as applicable," not as an unconditional mandate for a specific mechanism.

## 9. Request Signing / Response Signing

- **Response signing:** `EXISTING`, Ed25519, preserved (§1).
- **Request signing:** `MISSING` today — the request itself is unsigned; only the response is. `RECOMMENDED` per §8 above, tied to the same shared-secret proposal. `REQUIRES VERIFICATION` whether this is worth the added complexity (key provisioning, rotation) given the existing binding-mismatch check already provides meaningful protection without it.

## 10. Replay Protection

`MISSING` today — `checked_at` is included in the request but is not verified against a tolerance window or nonce-tracked server-side (Phase 0 did not find any replay-window check in `handleCheckIn()`, and direct inspection of `LicensingAPI.php` confirms `checked_at` is never read back out of `$input` at all — it is accepted but unused). Because the check-in is largely idempotent (repeating the same request just re-confirms the same state and updates `last_seen_at`), a naive replay is low-impact — but a captured request replayed by an attacker *without* the legitimate installation's knowledge could be used to keep a `last_checkin_at`/`last_seen_at` artificially fresh, potentially masking that the real installation has gone offline or been decommissioned. `RECOMMENDED`: bind `checked_at` to a server-side tolerance window (e.g. reject if more than a few minutes from server time) as a low-cost mitigation; full nonce-based replay prevention is a larger addition and should be weighed against §8/§9's request-signing proposal, since meaningful replay protection is difficult to achieve without request signing to begin with (an unsigned `checked_at` can simply be rewritten by a replaying attacker to look fresh). `REQUIRES VERIFICATION` — flagged for the security-hardening phase, not resolved here.

## 11. Idempotency

`EXISTING`, effectively already idempotent: submitting the same check-in twice produces the same resulting state (binding already exists → update `last_seen_at` → log a checkin row → return current signed state). No explicit idempotency key is required given this natural idempotency. `ASSUMPTION`: this remains true after the schema split (§ in `09`), since the same logical operation (find-or-create binding, log, respond) is preserved, only across renamed/split tables.

## 12. Versioning

`MISSING` today — the payload has no version field, and a future incompatible payload shape change would have no way to signal itself to older clients. `RECOMMENDED`: add an `api_version` (or `payload_version`) integer/string field to the signed payload so a future Central Server change can be detected by clients that have not yet updated their parsing logic, and can choose to ignore fields they don't recognize (already implicitly safe, since JSON parsing naturally ignores unknown fields) or refuse to proceed on a major version bump they don't understand. Not required by any locked decision; `RECOMMENDED` as low-cost future-proofing consistent with `REQUIREMENTS.md` §14's general extensibility intent.

## 13. Error Handling

`EXISTING` pattern, preserved **but narrowed in scope** by the §7 correction (F-02): uniform `{"error": "<code>"}` body with an HTTP status (`400`/`403`/`404`/`500`), anti-enumeration-safe codes (`invalid_request`, `server_error`) returned to the caller, while a more specific `failure_code` (`binding_mismatch`, `activation_limit`) is recorded server-side in `licensing_checkins` for administrator diagnosis only — never echoed back to the caller. This pattern still applies unchanged to:

- Malformed/missing request fields (§3)
- An unknown or invalid `license_key`
- `binding_mismatch` — an `install_id`/`domain` that does not match this License's existing bound Installation
- `activation_limit` — a *new*, previously-unbound `install_id` attempting to activate a License that already has an active binding
- Server-side failures (`server_error`, `500`)

**No longer** in this category, per §7's correction: `inactive` (a request from an already-bound Installation whose License is `expired`/`suspended`/`revoked`) is **not** an error response — it is a normal `HTTP 200` signed payload carrying that `status` value, per §7. The `failure_code = 'inactive'` value some earlier drafts of this document associated with an error path no longer applies to a bound installation's routine check-in; it is retained, if at all, only for the distinct case of a *first-activation attempt* against a License that is `suspended`/`revoked` before any Installation has ever bound to it (`03-LICENSE-LIFECYCLE.md` §5) — there is genuinely no signed "current state" to return in that case, since no Installation binding exists yet to sign a payload *for*.

The only other addition (§9 of `09-CENTRAL-DATABASE-DESIGN.md`) is that lifecycle-significant rejections (repeated `activation_limit`/`binding_mismatch` attempts) also surface through the new `licensing_license_events` audit trail for pattern detection (`12-SECURITY-ARCHITECTURE.md` §3), not through any change to what the caller receives.
