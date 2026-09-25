# Phase 1: 12 — Security Architecture

**Document Status:** Architecture Specification (Documentation Only — No Implementation)
**Phase:** Phase 1 — Target Architecture Specification
**Reference:** `REQUIREMENTS.md` §13, `docs/01-audit/06-LICENSING-SECURITY-AUDIT.md`, `03-LICENSING-AUTH-AUDIT.md`

---

## 1. Verification Against the 12 Required Problems

This section directly answers Phase 0's instruction (`planning.md` §12, restated in the Phase 1 task) to verify the architecture solves each named bypass category. Each row cites the specific mechanism, defined in full in the referenced companion document.

| # | Problem | Phase 0 Finding | Target Mechanism | Where Specified |
| :--- | :--- | :--- | :--- | :--- |
| 1 | Global license bypass | `06-LICENSING-SECURITY-AUDIT.md` 2.1, 2.7, 2.10, 2.12 | Global License Guard invoked from `config.php` (single choke point for every entry point), fail-closed default, admin session no longer bypasses | `06-GLOBAL-LICENSE-GUARD.md` §3, §7 |
| 2 | Admin bypass | `AUTH-01`, `AUTH-02` | `Auth::check()` no longer short-circuits the license gate; Super Admin's RBAC bypass (`Auth::can()`'s `isSuperAdmin()` shortcut) is scoped to *permissions*, not license/entitlement state — the Guard and Module Guard sit outside and before RBAC in the request flow, so no RBAC role can skip them | `01-TARGET-ARCHITECTURE.md` §4, `06` §7 |
| 3 | API/AJAX bypass | 2.3, 2.4 | Same choke point covers `api/v1.php` and every AJAX endpoint, since all are `config.php`-rooted entry points | `06` §3 |
| 4 | Cron/background bypass | 2.11, `08-LICENSING-MIGRATION-RISKS.md` §2.6 | License refresh cron explicitly whitelisted (must always run); plugin `frequent_cron`/`daily_cron` listeners gated per-module by the Module Guard | `06` §5, `07-MODULE-GUARD-ARCHITECTURE.md` §5 |
| 5 | Module entitlement bypass | `MOD-01`–`MOD-05` | `ModuleGuard::require()` called at every admin/public/customer-portal/API/service entry point per module | `07` §2 |
| 6 | Plan/license/install conflation | `DB-02`, `DB-03` | Schema split: `licensing_licenses` / `licensing_installations` / `licensing_license_modules` as distinct tables | `09-CENTRAL-DATABASE-DESIGN.md` §6–§8 |
| 7 | Installer licensing inversion | `INST-01`–`INST-07`, Gap 7 | Reordered installer: License Key → Central Validation → Activate → Admin Account | `05-INSTALLATION-ACTIVATION.md` §1 |
| 8 | Tenant coupling problem | `DB-04`, `AUTH-*` §4, `08-LICENSING-MIGRATION-RISKS.md` §2.2 | Licensing identity keyed exclusively on `installation_id`; `tenant_id` retained internally but never consulted by licensing code | `10-CLIENT-LICENSING-DATABASE-DESIGN.md` §1–§2 |
| 9 | Cache integrity problem | `DB-05`, finding 2.8 | Raw signed payload + signature retained client-side; re-verifiable at read time, not just write time | `10` §3–§4 |
| 10 | Expiry/grace/offline ambiguity | `REQ-16`, `REQ-17`, Gap 6 | Explicit three-window state machine (Warning/Grace/Locked) with a separately-tracked, separately-bounded offline tolerance | `08-EXPIRY-GRACE-OFFLINE.md` |
| 11 | Installation identity problem | `REQ-13` (partially implemented) | 128-bit random Installation ID, generated once, immutable, decoupled from tenant uniqueness for licensing purposes | `05` §4, `10` §2 |
| 12 | License reuse/cloning problem | `REQUIREMENTS.md` §7 | `activation_limit` default `1`, partial binding uniqueness on the *active* Installation per License (generated-column unique key, `09` §7 — replaces an earlier flat `UNIQUE (license_id)` design to allow soft-deactivated history rows), explicit audited admin-only reset path, repeated-attempt visibility via `licensing_license_events`, **plus** `installation_id` bound into the signed payload itself (`11` §2, resolving Finding F-01) so a copied cache row fails client-side verification before ever reaching the network | `03-LICENSE-LIFECYCLE.md` §4–§5, `09` §7, §9, `11` §2, `10` §4 |

None of these twelve mechanisms are new inventions outside the locked requirements — each is a direct architectural consequence of a specific `REQUIREMENTS.md`/`DECISIONS.md` clause, cross-referenced above.

---

## 2. Threat-by-Threat Detail

### 2.1 License Forgery

An attacker attempting to fabricate a valid-looking license state. **Mitigation:** Ed25519 signing (`EXISTING`, preserved, §5 below) means any fabricated payload fails signature verification against the embedded public key. `RECOMMENDED` closure of the one residual gap: read-time re-verification (`10` §4) ensures a forged/tampered row cannot simply be inserted directly into the client's local database and trusted — it must additionally carry a signature that verifies, which requires the private key.

### 2.2 Installation Cloning

Copying a legitimately-activated installation's database (including its `installation_identity` and license state cache rows) onto a second server. This section's original draft described only a *detection* mitigation; it is revised here following the correction of Finding F-01 (`16-PHASE-1-ANTIGRAVITY-REVIEW.md`, `P0 — CRITICAL`), which meaningfully changes what is actually prevented.

**Two distinct cloning vectors, with two distinct mitigations:**

1. **Cloning the whole database, including a fresh check-in capability (the clone can still reach the Central Server with its own network path).** Both the original and the clone submit the *same* `installation_id` on their next check-in. Only one of them "wins" the binding depending on which one already holds it (`EXISTING` `binding_mismatch` rejection logic) — the clone is rejected (or, if it happens to check in first, the *original* is then rejected, surfacing the conflict to whichever operator notices first and contacts support). This remains a **detection-on-next-contact**, not real-time prevention, mechanism — an inherent limit of any pull-based, offline-tolerant licensing scheme (`08` §4 knowingly trades some cloning resistance for legitimate offline operation).
2. **Cloning just the cached signed state (`raw_payload`/`raw_signature` in `remote_license_cache`) onto a *different*, already-provisioned installation, without that installation ever needing to check in on its own.** This is the vector Finding F-01 identified: because every client shares the same public verification key, a copied signed payload verifies successfully on the recipient installation too, purely by signature — nothing about Ed25519 verification alone tells the recipient "this payload wasn't issued to you." Before the F-01 correction, this vector was **not even detection-only** — it was **fully unmitigated**, since the recipient installation never needs to contact the Central Server at all to make use of a copied, still-valid-looking cache row. After the correction (`11-LICENSING-API-CONTRACT.md` §2, `10-CLIENT-LICENSING-DATABASE-DESIGN.md` §4), the signed payload itself carries the *source* installation's `installation_id`, and every verification (write-time and read-time alike) additionally asserts `payload.installation_id === local installation_id`. A copied cache row carries the *original* installation's ID, which will not equal the *clone's* local ID — so this vector is now **prevented outright, offline, at load time**, not merely detected on the clone's next (possibly never-attempted) check-in.

`REQUIRES VERIFICATION` narrowed by this correction: whether stronger real-time prevention is still wanted specifically for vector 1 (whole-database cloning with independent network access) remains open — hardware/environment-fingerprinting binding beyond `installation_id` is not required by locked decisions, and `CLOUD-AGENT-RULES.md` §10 explicitly warns against over-relying on any single environmental signal. Vector 2, the more severe of the two because it required no network access from the attacker at all, is now closed.

### 2.3 License Reuse

Using one license key to activate a second, different installation. **Mitigation:** covered fully in §1 row 12.

### 2.4 Replay Attacks

Covered in `11-LICENSING-API-CONTRACT.md` §10. Current exposure is low-impact (replaying a check-in mostly just re-confirms existing state and refreshes `last_seen_at`) but not zero (masking a decommissioned installation as still-active). `RECOMMENDED` mitigation (`checked_at` tolerance window) flagged there; full resolution tied to the request-signing proposal in §2.5 below.

### 2.5 Request Tampering

An on-path attacker or malicious client modifying a check-in request in transit. **Mitigation:** `EXISTING` — transport is expected to run over HTTPS (`ASSUMPTION`, consistent with `config.php`'s own `force_https` setting and `SLATE_URL` defaulting to an `https://` origin); the request itself carries no additional integrity mechanism beyond TLS today. `RECOMMENDED` (§8/§9 of `11`): a per-installation request-signing secret would let the *server* detect tampering even on a request the attacker fully controls transport for (e.g. a compromised intermediate proxy, or a client-side attacker who can modify outbound HTTP before TLS — a stretch threat model, but the recommendation is low-cost). `REQUIRES VERIFICATION` whether this is prioritized for the security-hardening phase.

### 2.6 Response Tampering

**Mitigation:** `EXISTING`, fully solved already — the response is Ed25519-signed and the client verifies it before trusting it (`RemoteLicenseClient::checkIn()`, `LicenseSignatureVerifier`). No gap here; the only extension is making this verification durable at *read* time as well as *write* time (§2.1, `10` §4), which addresses tampering *after* a legitimate response was received and stored, not tampering of the response itself in transit.

### 2.7 Cache Tampering

Covered fully in §1 row 9 and `10` §3–§4. Direct database write access to `remote_license_cache` is the specific threat Phase 0 identified (finding 2.8) — resolved by read-time signature re-verification.

### 2.8 Direct URL Access

Covered in §1 rows 1–3.

### 2.9 API Access

Covered in §1 row 3.

### 2.10 AJAX

Covered in §1 row 3.

### 2.11 Cron

Covered in §1 row 4.

### 2.12 CLI

Per `06-GLOBAL-LICENSE-GUARD.md` §6: `ASSUMPTION` that `bin/license-check.php` is the only relevant CLI surface (`REQUIRES VERIFICATION` if other CLI scripts exist). Any CLI entry point discovered during implementation must be classified against the Guard's whitelist (`06` §2) before Phase 6 implementation, on the same principle as every other entry point.

### 2.13 Service-Layer Bypass

Covered in §1 row 5, specifically the "service/internal call" row of `07-MODULE-GUARD-ARCHITECTURE.md` §2's table — `RECOMMENDED` defense-in-depth guard calls inside `FormsAPI`/`MembershipAPI`/`BookingAPI` methods themselves, not only at their route-level callers, directly addressing finding 2.5 ("Controller & Service Invocation").

### 2.14 Admin Bypass

Covered in §1 row 2 (duplicate of the twelve required problems' "Admin bypass" — included here for completeness against `REQUIREMENTS.md` §13's own security checklist wording, which lists "admin bypass" as a distinct line item from "global license bypass").

### 2.15 Fail-Open Behavior

Covered fully in `06` §7. The single most consequential fix in this entire architecture: today's system fails open in two independent, compounding ways (unconfigured installs, admin sessions); the target fails closed in both, with a narrow, explicit, auditable whitelist as the only exception (`06` §2).

### 2.16 Secret/Key Protection

- **Central Server's Ed25519 private signing key:** `EXISTING`, already encrypted at rest via `slate_encrypt_secret()` (AES-256-GCM keyed off `APP_SECRET`), the same mechanism already used for Stripe/Twilio/SMTP credentials elsewhere in the codebase — `EXISTING` convention, preserved unchanged. **Residual risk** (`MIG-05`, `08-LICENSING-MIGRATION-RISKS.md` §2.4): if `APP_SECRET` on the Central Server changes (e.g. lost during a server migration, rotated without a re-encryption step), the private signing key becomes permanently unrecoverable, which would break signature generation for **every** license issued by that server. `RECOMMENDED`: document `APP_SECRET` as a Central-Server-critical secret requiring backup/escrow procedures distinct from ordinary application secrets, given the scale of impact if lost — this is an operational recommendation, not a code change.
- **Client's public verification key:** `EXISTING`, correctly stored as plain text (`LICENSE_SERVER_PUBLIC_KEY` in `.env`) — a public key has nothing to protect, this is already correct and requires no change.
- **License keys themselves:** `EXISTING`, `INV-05` — only SHA-256 hashes persisted, raw key shown once at issuance. Preserved unchanged.

### 2.17 Audit Logging

`PARTIALLY IMPLEMENTED` today (`licensing_checkins` exists and is reasonably thorough for phone-home events) → `TARGET` extended with `licensing_license_events` for lifecycle actions (§1 row 9's supporting detail, `09` §9, `03` §6). Client-side: no equivalent audit trail currently exists for local license-state changes (e.g. when the Global License Guard transitions an installation into Locked) — `RECOMMENDED`, not `REQUIRES VERIFICATION`: log Guard state transitions (Active→Warning, Warning→Grace, Grace→Locked, and back) through the client's `EXISTING` `AuditLog`/`slate_log()` facilities, consistent with how other security-relevant events in this codebase are already logged.

---

## 3. Detecting Reuse/Cloning Patterns

Per `REQUIREMENTS.md` §7 ("detect or reject unauthorized activation attempts where technically enforceable"): repeated `binding_mismatch` or `activation_limit` rejections against the same License within a short window are a meaningful signal of a shared/leaked license key, distinct from an isolated, one-off legitimate mistake (e.g. an operator fat-fingering a reinstall before realizing they need to contact support). `RECOMMENDED`: the `licensing_license_events` table (`09` §9) is the natural home for this signal — a future Central admin UI (Phase 3+ implementation, out of scope for this document) could surface "N rejected activation attempts in the last 24h" per license as an operational alert. This document specifies the data model that makes such detection *possible*; it does not specify the alerting mechanism itself, which is an implementation-phase concern.

---

## 4. Rate Limiting

`MISSING` today — Phase 0 found no rate limiting on `POST /licensing/check` (not called out explicitly in the audit, `ASSUMPTION` based on no evidence of any such mechanism in `LicensingAPI.php`, `public/check.php`, or `Licensing.php`). `REQUIREMENTS.md` §13 lists rate limiting "where appropriate." Given the endpoint's anti-enumeration design already limits the *information* an attacker gains from repeated calls (uniform errors), the primary risk rate limiting would address is brute-forcing license keys by volume (each attempt is a SHA-256 hash comparison against a 20-character high-entropy key — brute-forcing this is computationally infeasible at any realistic request rate long before rate limiting would become the binding constraint) or denial-of-service against the Central Server. `RECOMMENDED`: basic IP-based or install-id-based rate limiting on `/licensing/check`, primarily as DoS protection rather than as a meaningfully load-bearing part of the license-forgery threat model. `REQUIRES VERIFICATION` for the security-hardening phase to confirm this is worth the added infrastructure (rate limiting typically requires either a shared cache like Redis or a database-backed counter table, neither of which currently exists in this codebase for this purpose).

---

## 5. Ed25519 — What Is Preserved, Changed, or Requires Verification

| Aspect | Disposition |
| :--- | :--- |
| Algorithm (Ed25519 via `ext-sodium`) | `PRESERVED` — no reason to change; already correctly implemented, already a strong modern choice |
| Key generation (`sodium_crypto_sign_keypair()`) | `PRESERVED` |
| Signing (`sodium_crypto_sign_detached`, raw-string payload) | `PRESERVED` — canonicalization approach (sign the exact JSON string, never re-serialize before verifying) is correct and must not change |
| Verification (`sodium_crypto_sign_verify_detached`) | `PRESERVED` at write time; **`CHANGED`** — extended to also run at read time (`10` §4) |
| Key storage (public: plaintext; private: `slate_encrypt_secret()`) | `PRESERVED` |
| Payload shape | `CHANGED` — additive: `warning_days`, `grace_days`, optionally `api_version` per `11` §12, **and `installation_id`** (`11` §2, `LOCKED`, resolves Finding F-01 — required, not optional; unlike the other additive fields, an old client that does not yet check `installation_id` gains no protection from the field's mere presence, so client-side rollout of the equality check in `10` §4 matters as much as the server-side payload change itself); existing fields unchanged in name or meaning, preserving backward-compatible parsing for any client that has not yet updated |
| Key rotation procedure | `MISSING` today — no documented process exists for rotating the Central Server's signing keypair without breaking every already-activated client's ability to verify future responses (a client only trusts the public key baked into its own `.env` at install time; there is no mechanism today for the Central Server to tell an already-installed client "here is my new public key"). `REQUIRES VERIFICATION` — flagged for the security-hardening phase; out of scope for this rebuild's V1 unless a rotation event is anticipated in the near term. |

---

## 6. Input Validation, Output Escaping, CSRF

`ASSUMPTION`: the existing Slate framework's general-purpose protections (output escaping conventions, CSRF token handling on admin forms) already apply uniformly across the codebase and are not specific to licensing — Phase 0's audit did not flag any licensing-specific gap in these areas beyond what is already covered by the framework's existing admin-form conventions. The new License Key installer step (`05` §1 Step 3) and the new License Locked recovery screen (`06` §2) are ordinary admin-style forms and should follow the same `EXISTING` CSRF/escaping conventions already used by every other admin form in this codebase — no new mechanism is required, only correct application of the existing one during implementation. `REQUIRES VERIFICATION` only in the narrow sense that this document has not independently re-audited the framework's general CSRF implementation (that was outside this rebuild's Phase 0 scope, which focused specifically on licensing).
