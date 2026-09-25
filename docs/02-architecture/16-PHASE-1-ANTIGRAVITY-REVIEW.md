# Phase 1: 16 — Independent Architecture Review (Antigravity)

**Document Status:** Independent Architecture Review & Security Audit  
**Phase:** Phase 1 — Target Architecture Specification Review  
**Auditor / Reviewer:** Antigravity (Independent Verification Agent)  
**Governing Documents:** `agents/ANTIGRAVITY-RULES.md`, `planning.md`, `docs/00-project/REQUIREMENTS.md`, `docs/00-project/DECISIONS.md`, `docs/01-audit/*`  
**Documents Reviewed:** `docs/02-architecture/01-TARGET-ARCHITECTURE.md` through `15-PHASE-1-DECISIONS.md`  

---

## 1. Executive Summary

Antigravity has conducted a comprehensive, adversarial, and independent review of the 15 Phase 1 architecture documents prepared for the Kohevo / Solaya Commercial Licensing Rebuild.

### Overall Assessment
The Phase 1 architecture represents a substantial leap forward compared to the legacy implementation audited in Phase 0. The target design successfully conceives a sound structural foundation:
- It eliminates the legacy Plan/License conflation by splitting `licensing_installs` into `licensing_licenses`, `licensing_installations`, and per-license `licensing_license_modules`.
- It formally decouples commercial licensing identity from internal application `tenant_id`, establishing an immutable 128-bit `installation_id`.
- It reorders the installer sequence so that license validation occurs before administrative account provisioning.
- It defines explicit Global License Guard and Module Guard boundaries, rejecting UI hiding in favor of server-side enforcement.
- It replaces an ambiguous single-grace concept with a three-window timeline (Warning, Grace, Locked) and separates commercial grace from offline network tolerance.

However, our adversarial review identified **critical architectural gaps, severe security vulnerabilities, and direct cross-document contradictions** that must be resolved before implementation commences:
1. **Critical Security Vulnerability (P0):** The signed check-in payload specification (`11-LICENSING-API-CONTRACT.md` §2) **omits `installation_id`** from the cryptographically signed envelope. Because Ed25519 signs only the payload string, an attacker can extract a valid signed payload from an active installation and transplant it into any other installation's `remote_license_cache`, bypassing signature verification and enabling offline license cloning across arbitrary servers.
2. **Architectural Contradiction (P1):** `11-LICENSING-API-CONTRACT.md` claims to preserve existing `LicensingAPI.php` transport and status codes, but existing code returns an HTTP 403 error for expired, suspended, or inactive licenses without returning a signed payload. This directly contradicts `08-EXPIRY-GRACE-OFFLINE.md` and `11` §7, which require the Central Server to return a signed payload indicating `status: "expired"`, `warning_days`, and `grace_days` so the client can enter commercial grace or display authoritative locked screens.
3. **Audit Trail Destruction Risk (P1):** `09-CENTRAL-DATABASE-DESIGN.md` §7 imposes a strict `UNIQUE KEY (license_id)` on `licensing_installations` and prescribes hard-deleting the installation record during administrative reset. This permanently destroys the installation history and audit trail mandated by `REQUIREMENTS.md` §8.
4. **Installer Deadlock on Aborted Setup (P1):** Activating the license at Step 4 consumes the single activation limit (`activation_limit = 1`). If the installer is interrupted before completion and restarted on a wiped database, the Central Server rejects the retry as `activation_limit` exceeded, permanently locking out legitimate operators.
5. **Global Choke Point Test/CLI Blindspot (P2):** Placing the Global Guard in `config.php` without explicit bypass guards for migrations, test runners, and CLI maintenance will deadlock automated testing and schema deployment.

### Final Phase 1 Verdict
**APPROVED WITH CONDITIONS.**  
Phase 1 architecture is fundamentally sound in its domain model and separation of concerns, but **Phase 2 kickoff is conditioned upon formal acceptance of the remediation requirements** specified in this review.

---

## 2. Verification of Phase 0 Problem Resolution (All 12 Problems)

We systematically traced how the Phase 1 architecture addresses each of the 12 problems identified during the Phase 0 audit:

| # | Problem | Audit Ref | Target Architecture Resolution | Evaluation & Status |
| :--- | :--- | :--- | :--- | :--- |
| **1** | **Global License Bypass** | `06` Finding 2.1, 2.10, 2.12 | Enforced at a single choke point in `config.php` via `slate_license_guard()` before application dispatch. Defaults fail-closed if unconfigured. | **VERIFIED.** Single choke point covers all entry points; removes unconfigured fail-open vulnerability. |
| **2** | **Admin Bypass** | `AUTH-01`, `AUTH-02` | `Auth::check()` and `isSuperAdmin()` bypasses removed from license gate. Admins are subject to global lock; only dedicated recovery screen and login are whitelisted. | **VERIFIED.** Properly separates RBAC permission checks from commercial entitlement gates. |
| **3** | **API / AJAX Bypass** | `06` Finding 2.3, 2.4 | All API (`api/v1.php`) and AJAX scripts load `config.php` and are gated by the Global License Guard before execution. | **VERIFIED.** Choke point architecture eliminates per-route omissions. |
| **4** | **Cron / Background Bypass** | `06` Finding 2.11, `08` §2.6 | Whitelists license refresh check-in cron; gates module-specific cron jobs (`frequent_cron`, `daily_cron`) via Module Guard. | **VERIFIED.** Prevents zombie background execution while ensuring lock can be lifted automatically. |
| **5** | **Module Entitlement Bypass** | `MOD-01`–`MOD-05` | Introduces `ModuleGuard::require($module)` at admin controllers, public routers, portal routers, API dispatch, and service methods. | **VERIFIED.** Enforces server-side authorization at every entry point. UI hiding is strictly presentational. |
| **6** | **Plan / License / Install Conflation** | `DB-02`, `DB-03` | Schema split: `licensing_licenses` (commercial authority), `licensing_installations` (deployment binding), `licensing_license_modules` (per-license entitlements). | **VERIFIED.** Plan is reduced to a creation template; entitlements are granted per-license. |
| **7** | **Installer Licensing Inversion** | `INST-01`–`INST-07`, Gap 7 | Inverts installer sequence: DB initialization → License Key verification & Central Activation → Admin Account Creation. | **VERIFIED.** Eliminates the "installed but unlicensed" zombie state. |
| **8** | **Tenant Coupling Problem** | `DB-04`, `AUTH-*` §4 | Decouples licensing identity from internal application `tenant_id`. Licensing keys exclusively on `installation_id`. | **VERIFIED.** Preserves internal tenant architecture for RBAC while isolating commercial layer. |
| **9** | **Cache Integrity Problem** | `DB-05`, Finding 2.8 | Retains verbatim signed payload (`raw_payload`) and base64 signature (`raw_signature`); verifies signature at read-time via Ed25519. | **PARTIALLY VERIFIED / CONDITIONAL.** Mechanism is sound, but compromised by omitting `installation_id` from payload (see Finding F-01). |
| **10** | **Expiry / Grace / Offline Ambiguity** | `REQ-16`, `REQ-17`, Gap 6 | Explicit state machine: Pre-expiry Warning (7d) → Post-expiry Grace (7d) → Full Lock. Separately bounds network tolerance from commercial grace. | **VERIFIED.** Correctly resolves the conflation between cache staleness and commercial validity. |
| **11** | **Installation Identity Problem** | `REQ-13` | Singleton 128-bit hex UUID in `installation_identity` generated at install time, immutable across reboots. | **VERIFIED.** Provides a deterministic, tamper-resistant installation anchor. |
| **12** | **License Reuse / Cloning Problem** | `REQUIREMENTS.md` §7 | 1:1 binding enforcement, `activation_limit`, `binding_mismatch` detection, and audit trail in `licensing_license_events`. | **PARTIALLY VERIFIED / CONDITIONAL.** Strong at check-in time, but vulnerable during offline window due to F-01. |

---

## 3. Critical Findings (P0 / P1)

### Finding F-01: Cryptographic Payload Omits `installation_id` (Offline License Cloning)
- **Finding ID:** F-01
- **Severity:** `P0 — CRITICAL`
- **Category:** `RISK / SECURITY VULNERABILITY`
- **Target Document:** `11-LICENSING-API-CONTRACT.md` §2, `10-CLIENT-LICENSING-DATABASE-DESIGN.md` §3
- **Problem Description:**  
  The check-in response payload signed by the Central Server is defined as:
  ```json
  {
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
  The payload contains **no reference to `installation_id` or `domain`**. In `10-CLIENT-LICENSING-DATABASE-DESIGN.md` §3, `installation_id` is merely an unverified column in the local database table.
- **Architectural Impact:**  
  Because the Central Server's public signing key is identical across all client installations, an attacker with direct access to any licensed site's database can copy `raw_payload` and `raw_signature` into an arbitrary number of unauthorized installations. On the victim installations, `sodium_crypto_sign_verify_detached($raw_signature, $raw_payload, $pubKey)` will **succeed completely**. The attacker can operate unauthorized installations offline for the entire duration of the offline tolerance window (e.g., 7 days) repeatedly, or permanently if outbound phone-home traffic is blocked.
- **Recommendation:**  
  1. `installation_id` MUST be included in the JSON `$payload` array generated by `LicensingAPI.php` *prior* to JSON encoding and Ed25519 signing.
  2. On the client, read-time signature verification MUST assert:
     `$decodedPayload['installation_id'] === $localInstallationId`.
- **Blocks Phase 2:** **YES.** The API response contract and Central Server payload generator implemented in Phase 2 must include `installation_id`.

---

### Finding F-02: API Contract Contradiction on Inactive/Grace License Status Propagation
- **Finding ID:** F-02
- **Severity:** `P1 — HIGH`
- **Category:** `CONTRADICTION`
- **Target Document:** `11-LICENSING-API-CONTRACT.md` §1, §7 vs `08-EXPIRY-GRACE-OFFLINE.md` §2, §3 vs `LicensingAPI.php` line 182
- **Problem Description:**  
  `11-LICENSING-API-CONTRACT.md` §1 states that existing transport and error responses are preserved. In the audited codebase (`LicensingAPI.php` line 182), if `effectiveInstallStatus()` returns anything other than `'trial'` or `'active'`, the API immediately terminates and returns:
  `HTTP 403 {"error": "invalid_request"}`
  No payload or signature is returned.  
  However, `08-EXPIRY-GRACE-OFFLINE.md` §3 and `11` §7 state that the Central Server returns a signed payload containing `status: "expired"` (with `grace_days`), `status: "suspended"`, or `status: "revoked"`, which the client verifies and caches.
- **Architectural Impact:**  
  If the Central Server returns HTTP 403 on expired or suspended licenses:
  1. An expired license can **never enter commercial grace** via check-in, because the client receives an HTTP error rather than a signed payload with `status: "expired"` and `grace_days: 7`.
  2. The client cannot distinguish between a transient network failure, an unauthenticated request, and an authoritative suspension. Under offline tolerance rules, a suspended installation might remain active for 7 days because the 403 error is treated as a failed check-in attempt rather than an authoritative revocation.
- **Recommendation:**  
  Refactor Central Server response semantics:
  - For bound installations with valid credentials, `POST /licensing/check` MUST return **HTTP 200 with a signed payload** reflecting the authoritative commercial state (`active`, `expired`, `suspended`, `revoked`).
  - Reserve HTTP 400/403/404 exclusively for invalid request envelopes, unknown license keys, or unresolvable binding mismatches.
- **Blocks Phase 2:** **YES.** Phase 2 implements `LicensingAPI::handleCheckIn()`; its response status contract must be aligned immediately.

---

### Finding F-03: Hard-Deletion on Reset Destroys Activation Audit Trail
- **Finding ID:** F-03
- **Severity:** `P1 — HIGH`
- **Category:** `CONTRADICTION / DATA LOSS RISK`
- **Target Document:** `09-CENTRAL-DATABASE-DESIGN.md` §7, `15-PHASE-1-DECISIONS.md` R13 vs `REQUIREMENTS.md` §8
- **Problem Description:**  
  `09-CENTRAL-DATABASE-DESIGN.md` §7 specifies a `UNIQUE KEY uniq_installation_license (license_id)` on `licensing_installations`. To allow re-activation on a new server after an administrative reset, Cloud Agent proposes hard-deleting the existing `licensing_installations` record (`DELETE FROM licensing_installations WHERE license_id = ?`).
- **Architectural Impact:**  
  Hard-deleting installation records directly violates `REQUIREMENTS.md` §8 ("Central Licensing Server responsibilities include: Installation records, Activation history, Audit logging"). When an installation is decommissioned or reset, all historical telemetry—including initial activation timestamp, bound domain, installed version, and last check-in IP—is permanently destroyed.
- **Recommendation:**  
  1. Add `status ENUM('active', 'revoked', 'superseded') NOT NULL DEFAULT 'active'` and `deleted_at DATETIME NULL` to `licensing_installations`.
  2. Implement soft-deactivation on reset (`UPDATE licensing_installations SET status = 'superseded', deleted_at = NOW() WHERE license_id = ?`).
  3. Change the unique constraint from `UNIQUE (license_id)` to a partial index, application-level concurrency lock, or composite key supporting historical tracking while preventing multiple *active* bindings.
- **Blocks Phase 2:** **YES.** Directly impacts the schema DDL written in Phase 2 migration `0025`.

---

### Finding F-04: Installer Activation Lockout on Interrupted Provisioning
- **Finding ID:** F-04
- **Severity:** `P1 — HIGH`
- **Category:** `RISK / IMPLEMENTATION DEADLOCK`
- **Target Document:** `05-INSTALLATION-ACTIVATION.md` §1, §8, `15-PHASE-1-DECISIONS.md` R15
- **Problem Description:**  
  In `05-INSTALLATION-ACTIVATION.md`, Step 4 sends `POST /licensing/check` to activate the license. If Step 5 (Admin User Creation) or Step 6 fails (e.g., server crash, database constraint failure, browser closure), the installer is aborted before `.installed` is written.  
  If the operator drops/re-creates the database to start fresh, Step 2 generates a **new** `installation_id`. When Step 4 runs again, the Central Server sees a new `installation_id` for a license whose `activation_count` is already 1, returning `403 invalid_request` (`activation_limit`).
- **Architectural Impact:**  
  A single network hiccup or configuration typo during setup results in a permanent installation deadlock, requiring manual support intervention to reset the license binding before installation can proceed.
- **Recommendation:**  
  1. Step 2 must be idempotent: store the generated `installation_id` in `.env` or session so that installer retries reuse the existing `installation_id`.
  2. Alternatively, Step 4 should only *validate* credentials, while formal activation binding is executed atomically in Step 7 (Finalize) alongside `.installed` marker creation.
- **Blocks Phase 2:** **NO.** This is client-side installer architecture implemented in Phase 4.

---

## 4. Non-Critical Findings (P2 / P3)

### Finding F-05: Global Guard Choke Point Lacks Test & Migration Bypasses
- **Severity:** `P2 — MEDIUM` | **Category:** `UNSAFE ASSUMPTION` | **Target:** `06-GLOBAL-LICENSE-GUARD.md` §3
- **Problem:** Option A embeds `slate_license_guard()` in `config.php`. Automated test suites (PHPUnit), database migration runners (`db/migrate.php`), and CLI scripts all bootstrap via `config.php`. Under a fail-closed model with an empty test database, migrations and tests will crash immediately upon calling the guard.
- **Recommendation:** `slate_license_guard()` must include explicit execution-context bypasses:
  ```php
  if (defined('SLATE_MIGRATING') || defined('SLATE_TESTING') || env('APP_ENV') === 'testing') return;
  ```
- **Blocks Phase 2:** No (Client Phase 6).

### Finding F-06: In-Memory Signature Verification Redundancy
- **Severity:** `P2 — MEDIUM` | **Category:** `MISSING / PERFORMANCE` | **Target:** `10-CLIENT-LICENSING-DATABASE-DESIGN.md` §4, `07-MODULE-GUARD-ARCHITECTURE.md` §2
- **Problem:** If every `ModuleGuard::require()` call independently queries `remote_license_cache` and performs Ed25519 signature verification, a page executing multiple service calls will incur redundant database queries and CPU overhead.
- **Recommendation:** Introduce a request-scoped in-memory cache inside `LicenseManager` / `SlateLicenseCacheStore` that retains the verified payload for the duration of the PHP process.
- **Blocks Phase 2:** No (Client Phase 5).

### Finding F-07: Lock Screen Admin Session Invalidation / Logout Missing from Whitelist
- **Severity:** `P3 — LOW` | **Category:** `MISSING` | **Target:** `06-GLOBAL-LICENSE-GUARD.md` §2
- **Problem:** `admin/logout.php` is omitted from the whitelist. If an admin is logged in with an unprivileged account when the system locks, they cannot log out to authenticate as Super Admin.
- **Recommendation:** Add `admin/logout.php` to the Global License Guard whitelist.
- **Blocks Phase 2:** No (Client Phase 6).

### Finding F-08: Timezone Ambiguity in Expiry Comparisons
- **Severity:** `P3 — LOW` | **Category:** `CLARIFICATION` | **Target:** `08-EXPIRY-GRACE-OFFLINE.md` §2
- **Problem:** Architecture does not explicitly enforce UTC for datetime comparisons, risking subtle window calculation bugs across servers running in local timezones.
- **Recommendation:** Mandate UTC timestamps (`gmdate('Y-m-d H:i:s')` / ISO-8601 UTC) across all Central and Client timestamp arithmetic.
- **Blocks Phase 2:** No.

---

## 5. Analysis of the 5 "Blocking" Decisions Identified by Cloud Agent

Cloud Agent flagged 5 decisions in `15-PHASE-1-DECISIONS.md` §5 as blocking Phase 2. Antigravity conducted an independent verification of each:

```
┌─────────────────────────────────────────────────────────────┬──────────────┬──────────────────────────────────────────────────────────────┐
│ Decision Item                                               │ Cloud Agent  │ Antigravity Determination & Rationale                        │
├─────────────────────────────────────────────────────────────┼──────────────┼──────────────────────────────────────────────────────────────┤
│ 1. Central Database Foreign Key Strategy (R1)               │ BLOCKING     │ CONFIRMED BLOCKING. Affects Phase 2 migration DDL directly.  │
│ 2. Domain-Binding Strength: Hard vs Soft (R2)               │ BLOCKING     │ NON-BLOCKING for Phase 2 kickoff. Schema accommodates both.   │
│ 3. Offline Tolerance Duration (R3)                          │ BLOCKING     │ NON-BLOCKING for Phase 2. Enforced on Client (Phase 5/6).    │
│ 4. Central Server Fresh vs Upgrade Status (R7)              │ BLOCKING     │ NON-BLOCKING. Additive migration 0025 works on both.         │
│ 5. Core Entitlement Key Naming (R14)                        │ BLOCKING     │ REJECTED / NON-BLOCKING. Core is implicit; not stored in DB. │
└─────────────────────────────────────────────────────────────┴──────────────┴──────────────────────────────────────────────────────────────┘
```

### Detailed Breakdown:
1. **Central Database Foreign Key Strategy (R1):**
   - *Cloud Agent Position:* Flagged as blocking due to tension between existing plugin no-FK convention and data integrity.
   - *Antigravity Analysis:* **BLOCKING.** Phase 2 begins with writing Central migration DDL. The decision cannot be deferred. We recommend enforcing InnoDB Foreign Keys (`ON DELETE CASCADE` for modules and events; `ON DELETE RESTRICT` for active installations). Commercial licensing demands referential integrity; application-level discipline alone produced the exact orphan risks found in Phase 0.
2. **Domain-Binding Strength (R2):**
   - *Cloud Agent Position:* Flagged as blocking.
   - *Antigravity Analysis:* **NON-BLOCKING for Phase 2 Schema.** The table `licensing_installations` stores `domain` and `domain_normalized` regardless of whether mismatch enforcement is hard or soft. We recommend **Hard Block with Central Support Reset** as the baseline security default, but this is an API logic rule, not a blocker to schema creation.
3. **Offline Tolerance Duration (R3):**
   - *Cloud Agent Position:* Flagged as blocking.
   - *Antigravity Analysis:* **NON-BLOCKING for Phase 2.** Offline tolerance is evaluated and enforced strictly on the client. Central Server check-in responses only pass `warning_days` and `grace_days`. A default of 7 days (`604800` seconds) can be locked without obstructing Central Server development.
4. **Central Server Fresh vs Upgrade Status (R7):**
   - *Cloud Agent Position:* Flagged as blocking.
   - *Antigravity Analysis:* **NON-BLOCKING.** The locked project requirement states: *"There is NO real/live customer licensing data that must be preserved."* Writing migration `0025_commercial_licensing_rebuild.sql` with `CREATE TABLE IF NOT EXISTS` ensures seamless execution on both brand-new database instances and existing dev environments.
5. **Core Entitlement Key Naming (R14):**
   - *Cloud Agent Position:* Flagged as blocking.
   - *Antigravity Analysis:* **REJECTED AS BLOCKING.** Cloud Agent's own document (`04` §2) proves that Core features are implicit and **never stored as rows in `licensing_license_modules` or checked via ModuleGuard**. Because these keys never touch the database or wire payloads, their naming has zero impact on Phase 2 DDL or API design.

---

## 6. Analysis of All 16 Open Items in Decision Register (`15-PHASE-1-DECISIONS.md`)

We evaluated all 16 open items from `15-PHASE-1-DECISIONS.md` §2:

| Ref | Item Description | Nature | Antigravity Resolution / Guidance | Blocks Phase 2? |
| :--- | :--- | :--- | :--- | :--- |
| **R1** | FK Constraints on new Central tables | Architectural | **Adopt Foreign Keys.** Use InnoDB FKs with cascading deletes for child modules/events and restrict for installations. | **YES** |
| **R2** | Domain binding strength (Hard vs Soft) | Security / UX | **Adopt Hard Block with Admin Reset Tool.** Matches existing security model and prevents basic multi-site reuse. | **NO** |
| **R3** | Offline tolerance duration | Policy / Config | **Adopt 7 Days (configurable).** Keeps existing `REMOTE_GRACE_SECONDS` baseline; decouple from commercial grace. | **NO** |
| **R4** | Renew vs Extend semantics | Audit / Domain | **Adopt Semantic Split.** Renew advances billing period; Extend adjusts `expires_at`. Record in event log. | **NO** |
| **R5** | `trial` status scope | Backward Compat | **Retain in Enum.** Maintain compatibility with existing code; inactive for fresh production unless configured. | **NO** |
| **R6** | `cancelled` status scope | Domain Model | **Retain in Enum.** Terminal state for customer churn. Non-blocking. | **NO** |
| **R7** | Fresh vs Upgrade Central deployment | Deployment | **Use Additive Migration (0025).** Compatible with both clean and upgraded servers. | **NO** |
| **R8** | Per-installation HMAC request signing | Security Hardening | **Defer to Phase 11.** Not required for V1 baseline. Existing binding check provides sufficient barrier. | **NO** |
| **R9** | Timestamp tolerance / Replay protection | Security Hardening | **Implement ±5min Tolerance in Phase 2.** Validate `checked_at` against server clock during check-in. Low cost. | **NO** |
| **R10** | Rate limiting on `/licensing/check` | Infrastructure | **Defer to Phase 11.** Brute-force risk is low due to 20-char key entropy and uniform anti-enumeration errors. | **NO** |
| **R11** | Ed25519 signing key rotation procedure | Operational | **Defer to Post-V1.** Document procedure; not required for initial build. | **NO** |
| **R12** | Nav-hiding of deprecated platform pages | Presentational | **Defer to Phase 6.** RBAC gating is already secure; nav-hiding is cosmetic cleanup. | **NO** |
| **R13** | Preserve installation history on reset | Data Integrity | **Preserve History via Soft-Deactivation.** Supersedes hard-delete to satisfy audit requirements. | **YES** |
| **R14** | Core entitlement key names | Naming | **Non-blocking.** Core is implicit; keys are never stored or transmitted. | **NO** |
| **R15** | Abandoned install recovery process | Client Installer | **Resolve in Phase 4.** Idempotent installer identity persistence. | **NO** |
| **R16** | Split `install.sql` vs migration | Deployment | **Use Migration 0025.** Leave `install.sql` intact; apply new schema via standard migration pipeline. | **NO** |

---

## 7. Contradiction Analysis

We identified three direct contradictions across the Phase 1 documents:

1. **`11-LICENSING-API-CONTRACT.md` vs `08-EXPIRY-GRACE-OFFLINE.md`:**  
   - `11` asserts preservation of existing `LicensingAPI.php` behavior where expired/inactive licenses return `403 invalid_request`.  
   - `08` requires the Central Server to return a signed payload indicating `status: "expired"` with `grace_days: 7`.  
   - *Resolution:* `LicensingAPI` must return HTTP 200 with signed state for validly bound keys regardless of expiry.
2. **`09-CENTRAL-DATABASE-DESIGN.md` §7 vs `REQUIREMENTS.md` §8:**  
   - `09` specifies `UNIQUE (license_id)` on `licensing_installations` and prescribes hard-deleting the row on reset.  
   - `REQUIREMENTS.md` §8 mandates historical tracking of all installation records and activation history.  
   - *Resolution:* Add `status` and `deleted_at` columns to `licensing_installations`.
3. **`11-LICENSING-API-CONTRACT.md` §2 vs `10-CLIENT-LICENSING-DATABASE-DESIGN.md` §3:**  
   - `10` assumes `installation_id` in the local cache protects against cloning via cryptographic verification.  
   - `11` omits `installation_id` from the signed payload, rendering the cryptographic check incapable of detecting cloned rows.  
   - *Resolution:* Include `installation_id` in the signed envelope.

---

## 8. Missing Architecture Elements

The following structural components are missing from Phase 1 and must be accounted for during implementation:
1. **CLI License Management Tooling:** While `bin/license-check.php` handles automated cron checks, there is no CLI tool for an operator to manually activate, diagnose, or recover a license (e.g. `bin/license-manage.php --status` or `--activate <key>`).
2. **Clock Skew Detection:** Local client time can drift or be intentionally manipulated. The client should check if `fetched_at` or `remote_checked_at` is in the future relative to the client clock by more than 1 hour and flag a clock-tampering alert.
3. **Central Admin Management APIs:** While check-in endpoints are defined, administrative endpoints for creating plans, issuing licenses, toggling modules, and resetting bindings are left unspecified, deferred to Phase 2/3.

---

## 9. Edge Case Analysis

| Edge Case Scenario | Architecture Status | Verdict & Mitigation |
| :--- | :--- | :--- |
| **Site Migration / Domain Change** | Mismatch returns 404 binding mismatch; site enters lock. | **Acceptable.** Operator must request Central Server binding reset or support must update domain. |
| **Reverse Proxy / SSL Offloading** | Domain headers (`X-Forwarded-Host`) may mismatch `SLATE_URL`. | **Risk.** Client must consistently derive domain from normalized `SLATE_URL` host, not dynamic request headers. |
| **Central Server Extended Outage** | Handled by offline tolerance window. After window, fails closed. | **Correct.** Graceful tolerance followed by deterministic fail-closed security. |
| **Clock Rollback by Operator** | Client compares local time against signed `expires_at`. | **Partially Mitigated.** Signed state prevents forging "active", but clock rewinding can prolong grace. |
| **Concurrent First Activation** | Multiple nodes activate same key simultaneously. | **Handled.** `PDO::beginTransaction()` and `FOR UPDATE` in `LicensingAPI` prevents race condition. |

---

## 10. Security Architecture Review

- **Cryptographic Boundary:** The selection of Ed25519 (`sodium_crypto_sign_detached` / `sodium_crypto_sign_verify_detached`) is cryptographically optimal and modern. Storing private signing keys encrypted via AES-256-GCM (`slate_encrypt_secret()`) is verified as consistent with existing platform conventions.
- **Fail-Closed Stance:** The architecture successfully reverses the legacy fail-open vulnerabilities (`AUTH-02`, Finding 2.12). Unconfigured installations default to fully locked.
- **Tamper Evidence:** Read-time signature re-verification closes the database tampering gap (`DB-05`), provided Finding F-01 is implemented.

---

## 11. Final Verdict on Phase 1 & Guidance for Phase 2 Kickoff

### Verdict
**APPROVED WITH CONDITIONS.**

Phase 1 provides a cohesive, high-quality architectural roadmap that resolves the legacy design flaws. It is approved to proceed to Phase 2 under the following binding conditions:

### Binding Conditions for Phase 2 Kickoff:
1. **Payload Specification Fix:** In `11-LICENSING-API-CONTRACT.md` and Phase 2 implementation, `installation_id` MUST be added to the signed JSON payload.
2. **Check-in Response Status Fix:** `LicensingAPI::handleCheckIn()` in Phase 2 MUST return HTTP 200 with signed payloads for `expired`, `suspended`, and `revoked` licenses, reserving 4xx errors for credentials/binding rejections.
3. **Database Schema Enhancements:**
   - Migration `0025` MUST implement InnoDB Foreign Keys (`ON DELETE CASCADE` for modules and events).
   - Migration `0025` MUST add `status` and `deleted_at` to `licensing_installations` to support soft-deactivation and preserve audit history.
4. **Migration Sequence:** Number the Central Server migration cleanly as `0025_commercial_licensing_rebuild.sql` in `02-licensing/plugins/licensing/migrations/`.

With these adjustments formally incorporated, the architecture is ready for Phase 2 implementation.
