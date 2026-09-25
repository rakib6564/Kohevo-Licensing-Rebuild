# Phase 1: 05 — Installation & Activation

**Document Status:** Architecture Specification (Documentation Only — No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Reference:** `REQUIREMENTS.md` §4, §7, `DECISIONS.md` §9, `docs/01-audit/04-LICENSING-INSTALLER-AUDIT.md`, `08-LICENSING-MIGRATION-RISKS.md` §2.5

---

## 1. Target Installer Sequence

```text
Step 1: Database Configuration        — EXISTING, unchanged
        (db_host, db_port, db_name, db_user, db_pass, app_url;
         writes .env, generates APP_SECRET/CRON_SECRET)
        ↓
Step 2: Install Application            — TARGET (narrowed scope)
        Runs CORE migrations only (identity, roles, settings,
        installation_identity). Does NOT create the admin user here
        (moved to Step 6). Does NOT run any optional-module plugin
        install.sql here (moved to Step 7, and no longer operator-chosen
        — see §3).
        Generates the Installation ID (unchanged mechanism:
        bin2hex(random_bytes(16))) and persists it. The Installation ID
        exists locally before any network call — it must, since it is
        the value submitted TO the Central Server, not received FROM it.
        `LOCKED` correction (resolves Finding F-04, see §4): the
        generated Installation ID is persisted BOTH to the
        installation_identity table AND to .env
        (INSTALLATION_ID=<value>) in the same step. If .env already
        carries an INSTALLATION_ID when Step 2 runs (a retry after the
        database was dropped/recreated mid-install), that value is
        reused verbatim rather than generating a new one, and it is
        this reused value that gets written into the freshly-created
        installation_identity row.
        ↓
Step 3: License Key                    — MISSING today, TARGET new step
        Operator enters the license key. Client-side format validation
        only (non-empty, plausible shape) — no local authority to judge
        validity.
        ↓
Step 4: Central Licensing Server Validation — MISSING today, TARGET new
        Installer calls the Central Server synchronously (reusing the
        existing check-in contract — see §2) with:
          { product, license_key, install_id, domain, app_version, checked_at }
        This call MUST succeed (HTTP 200, valid signature, status in
        {trial, active}) before the installer proceeds. Failure keeps
        the operator on Step 3 with an error; nothing is written beyond
        the already-generated Installation ID.
        ↓
Step 5: Activate Installation           — MISSING today, TARGET new
        On success, the installer persists the verified signed state
        (raw payload + signature + verified_at — see 10) into the local
        license state cache. This IS the activation from the client's
        point of view; on the Central Server it is the same event as
        Step 4's first successful check-in (see §2 — Activation is not
        a separate API call).
        ↓
Step 6: Create Admin Account            — TARGET (moved later)
        Only reachable once Step 5 has completed. Same form/fields as
        today's Step 2 (name, email, password).
        ↓
Step 7: Finish                          — TARGET (narrowed scope)
        Auto-activates optional plugins based on the ACTIVATED LICENSE's
        entitlements (see §3) — not an operator checkbox list. Writes
        .installed marker. No manual plugin picker.
        ↓
Step 8: Dashboard                       — EXISTING, unchanged
        Redirect to /admin/login.php?installed=1 (or directly into an
        authenticated session — implementation detail for a later phase)
```

This directly resolves Gap 7 in `07-LICENSING-GAP-ANALYSIS.md` ("Installer Sequence Inversion") and findings `INST-01` through `INST-07`.

---

## 2. Activation Is Not a New API Call

A key design decision: the Central Server does **not** need a distinct `/licensing/activate` endpoint separate from `/licensing/check`. Verified directly in `LicensingAPI::handleCheckIn()` (lines 189-227): if no `licensing_installation_bindings` row exists for the submitted `install_id`, one is created and `activation_count` is incremented — this **already is** activation semantics, triggered by an ordinary check-in call. The only difference between "the installer's Step 4 call" and "next Tuesday's routine daily check-in" is:

- The installer's call is **synchronous and blocking** — the UI waits for it and treats any failure as fatal to the install flow.
- The daily cron call (`01-client/bin/license-check.php`, `EXISTING`) is **best-effort** — per `RemoteLicenseClient`'s own documented contract ("on ANY failure... the cache is left completely untouched"), a failed routine check-in must never brick a previously-working installation.

Both call the same conceptual operation. This is `RECOMMENDED` as the simplest correct design — it reuses `EXISTING`, already-correct server logic (binding creation, activation-limit enforcement, domain binding) rather than building a parallel activation code path that would need to duplicate all of that logic and could drift out of sync with it.

---

## 3. Installer States

| State | Description | Notes |
| :--- | :--- | :--- |
| **Fresh** | No `.env`, no `.installed` marker | `EXISTING` — Step 1 entry condition unchanged |
| **DB Configured** | `.env` exists, no admin user, no license activated | `TARGET` — replaces today's "Step 2 precondition" (today Step 2's precondition is just `.env` existing; target's Step 2 precondition is unchanged, but Step 2 no longer creates the admin) |
| **Core Installed, Unlicensed** | Core schema + Installation ID exist; no verified license state | `TARGET`, new intermediate state — this is exactly the state an interrupted/abandoned installation is left in if the operator closes the browser after Step 2 but before completing Step 4 |
| **Licensed, No Admin** | Verified license state persisted; admin account does not yet exist | `TARGET`, new — brief, normally passed through in one request |
| **Fully Installed** | `.installed` marker present | `EXISTING` mechanism, `TARGET` precondition (marker is now only written after a real activation, not after arbitrary plugin selection) |

---

## 4. Installation ID Generation and Binding

- **Generation:** `bin2hex(random_bytes(16))` → 32 hex characters. `EXISTING`, cryptographically adequate entropy (128 bits), unchanged in the target design.
- **Immutability:** Generated once, at Step 2, and never regenerated for the lifetime of the installation (barring an explicit administrator-approved re-provisioning — see §7). `EXISTING` behavior already supports this (`InstallationService::ensureInstallationIdentity()` is idempotent — re-running finds and returns the existing row rather than generating a new one).
- **Persistence across setup retries — `TARGET` new, `LOCKED` (resolves Finding F-04 of `16-PHASE-1-ANTIGRAVITY-REVIEW.md`, `P1 — HIGH`, "Installer Activation Lockout on Interrupted Provisioning"):**
  `InstallationService::ensureInstallationIdentity()`'s existing idempotency (above) only protects against re-running Step 2 against a database that still has its `installation_identity` row. It does **not** protect against the scenario the review identified: Step 4 succeeds (the Central Server increments `activation_count` to `1` for Installation ID `X`), then Step 5 or 6 fails before `.installed` is written, and the operator — reasonably, given no `.installed` marker exists yet — drops and recreates the database to start over. Step 2 then runs against a truly empty database, `ensureInstallationIdentity()` finds no existing row, and generates a **new**, different Installation ID `Y`. When Step 4 re-runs with `Y`, the Central Server sees a License whose `activation_count` is already `1` (for `X`, which no longer exists locally) and rejects `Y` with `activation_limit` — a permanent deadlock requiring manual support intervention, since the client has no record `X` ever existed to explain the conflict.

  The fix is to make the Installation ID durable **independent of the database**, since the database is exactly what an interrupted-install retry may legitimately wipe: Step 2 persists the generated ID to `.env` (`INSTALLATION_ID=...`) in addition to the `installation_identity` table, and treats `.env`'s copy as authoritative on re-entry — if `INSTALLATION_ID` is already present in `.env`, Step 2 reuses it rather than calling `bin2hex(random_bytes(16))` again, regardless of whether the `installation_identity` table row currently exists. `.env` survives a database drop/recreate (it is a filesystem artifact written once in Step 1 and appended to in Step 2, never touched by a `DROP DATABASE`), so this closes the gap without requiring the installer to detect "was this database just wiped" as a distinct condition — reusing `.env`'s value is safe and correct whether the table row exists (ordinary idempotent re-run) or not (post-wipe retry).
- **Why this is preferred over the review's alternative recommendation (deferring formal activation binding to Step 7):** the review also floated "Step 4 should only validate credentials, while formal activation binding is executed atomically in Step 7." That would require introducing exactly the kind of validate-vs-activate distinction `§2` deliberately avoids (a separate `/licensing/activate` semantics layered on top of `/licensing/check`, which `D7` in `15-PHASE-1-DECISIONS.md` explicitly rejected as unnecessary complexity). The `.env`-persistence fix achieves the same outcome — no permanent lockout from an interrupted install — without touching the "Step 4's call already **is** the activation event" design (§2), so it is adopted as the sole resolution here.
- **Primary identity vs. domain:** Today, `handleCheckIn()` enforces **both** `installation_id` match (via the binding row) **and** `domain_normalized` match (`normalizedInstallDomain()` comparison, `binding_mismatch` rejection on mismatch — verified directly in `LicensingAPI.php` lines 173-179, 217-222) as hard, blocking conditions.
  - `CLOUD-AGENT-RULES.md` §10 states: "Do not assume hostname/domain alone is sufficient identity" — this is a statement that domain **alone** must not be treated as the identity (which the current code already satisfies; domain is never checked without installation_id also matching). It is not a statement that domain-matching must be removed as a *second factor*.
  - **`REQUIRES VERIFICATION`:** whether domain should remain a **hard** block (current behavior — a legitimate hosting migration, staging→production cutover, or domain rename would fail check-in and require an administrator's manual `resetBindings()` intervention) or become a **soft** signal (mismatch is logged and flagged for administrator review, but does not itself block a check-in that otherwise matches on `installation_id`). This document does not resolve this trade-off — it is a security-vs-operability decision that belongs in `15-PHASE-1-DECISIONS.md` for explicit project sign-off, per `DECISIONS.md` §17. Until decided, `RECOMMENDED` default: preserve current hard-block behavior (fail closed is the safer default), since loosening a security check is a bigger risk than a documented recovery procedure (`resetBindings()`) being occasionally needed.

---

## 5. Failure and Retry Behavior

| Failure | Target Behavior |
| :--- | :--- |
| Invalid license key (Step 4) | Installer remains on Step 3. Generic error shown (no anti-enumeration leak — matches `LicensingAPI`'s existing "identical error for invalid product / unknown key / domain mismatch" convention, `EXISTING`, `03-LICENSING-AUTH-AUDIT.md` §6.2). Operator may retry with a corrected key. |
| Central Server unreachable (Step 4) | Installer remains on Step 3 with a distinct "could not reach licensing server, check connectivity and retry" message. This is a network-availability failure, not a commercial rejection — the two must be visibly distinguishable to the operator even though both keep the installer on the same step (this distinction matters operationally, not just architecturally — see `08-EXPIRY-GRACE-OFFLINE.md` for why conflating them elsewhere in the system was a Phase 0 finding, `REQ-17`). |
| Activation limit already reached for this key (Step 4) | Same generic rejection as "invalid key" externally (anti-enumeration) — the Central Server's audit log (`licensing_checkins.failure_code = 'activation_limit'`, `EXISTING`) is where an administrator would actually diagnose this, not the installer UI. |
| Browser/process interrupted between Step 4 success and Step 6 completion, database intact | Installer re-entry at any URL re-derives current state from disk/DB (`.env` presence, Installation ID presence, verified license state presence, admin row presence) and resumes at the correct step — same idempotent-resume principle `InstallationService::provision()` already implements for the DB-config→admin-creation portion today (`EXISTING`, `PARTIALLY IMPLEMENTED` — needs extending to cover the new License Key/Validation/Activation states, which is `MISSING`). |
| Interruption between Step 4 success and `.installed`, **and** the operator drops/recreates the database before retrying | §4's `.env`-persisted `INSTALLATION_ID` is reused at Step 2 instead of a fresh ID being generated, so Step 4's retry re-submits the *same* `install_id` the Central Server already has an activation recorded for, and the check-in succeeds as an ordinary Refresh rather than being rejected as a new, conflicting Activation. `TARGET`, `LOCKED` — resolves Finding F-04. |

---

## 6. Reinstallation Behavior

Two distinct reinstallation scenarios, per `08-LICENSING-MIGRATION-RISKS.md` §2.5 and `CLOUD-AGENT-RULES.md` §20 ("STOP and ask... database migration is destructive"):

1. **`.installed` marker removed, database intact (e.g., accidental marker deletion).** `install.php` would currently re-run against a database that already has data — `InstallationService::provision()` already guards against double-provisioning tenants/admins (`count($tenants) > 1` throws). `RECOMMENDED`: the target installer should, at Step 2, detect an existing `installation_identity` row and **reuse its `installation_id`** rather than the current idempotent-but-identical-outcome behavior already provides (`ensureInstallationIdentity()` already does this correctly — `EXISTING`, no change needed here). This avoids burning a second activation slot against the same License for what is, from the Central Server's perspective, the same installation checking in again.
2. **Genuine reinstall on new infrastructure (server migration, disaster recovery, fresh database).** A new `installation_identity` row is unavoidable — this is a materially different deployment. Because `activation_limit` defaults to `1`, the Central Server will reject this new Installation ID's first check-in (`binding_mismatch` or `activation_limit`, depending on whether the *old* binding still exists) until an administrator explicitly calls `resetBindings()` (`EXISTING` server-side operation) against the License. This is `TARGET`-intended behavior, not a bug to fix: it enforces "1 License → 1 Installation" exactly as `REQUIREMENTS.md` §7 requires, while still leaving a documented, audited, human-approved recovery path. The installer itself should surface a clear message distinguishing "this key is invalid" from "this key is already bound elsewhere — contact your provider to transfer it" — the latter requires the Central Server to return a distinguishable-to-the-admin (but still anti-enumeration-safe to the untrusted caller) failure code, which it already does internally (`failure_code = 'binding_mismatch'` vs `'activation_limit'`, `EXISTING`).

---

## 7. Duplicate Activation Behavior

Already correctly enforced server-side today and preserved unchanged in the target design (§4, §6 above): a second, different Installation ID attempting to bind against a License whose `activation_count >= activation_limit` is rejected (`EXISTING`, `LicensingAPI.php` lines 200-207). The target architecture's only addition is making this an explicitly named, audited event (`licensing_license_events`, per `03-LICENSE-LIFECYCLE.md` §6) rather than only a `licensing_checkins` log row with a `failure_code`, so that repeated duplicate-activation attempts against the same License are visible to a Central administrator as a potential license-sharing/cloning signal (`12-SECURITY-ARCHITECTURE.md` §3, addressing `REQUIREMENTS.md` §7's "detect... unauthorized activation attempts where technically enforceable").

---

## 8. Admin Creation Timing

Moved from Step 2 (today) to Step 6 (target), strictly after a verified license activation (§1). This directly resolves `INST-04` ("Admin account created before license validation") and satisfies `REQUIREMENTS.md` §4's explicit ordering. `REQUIRES VERIFICATION` (operational, not architectural): whether an operator who successfully activates a license but then abandons the install before creating an admin account should be able to resume *indefinitely*, or whether the Central Server should treat "activated but no admin created within N days" as worth surfacing to the Client (`licensing_clients`) for support follow-up. This document does not require a specific answer — it is a customer-success process question, not a licensing-architecture requirement, and is noted here only so it is not silently forgotten.

---

## 9. Whitelisted Installer/Recovery Surface

Per `08-LICENSING-MIGRATION-RISKS.md` §2.5 ("Fail-Closed Deadlock Risk"), the Global License Guard (`06-GLOBAL-LICENSE-GUARD.md`) must never block:

- `install.php` and its POST handlers, for as long as the installer state machine (§3) has not reached "Fully Installed"
- Static assets (`/assets/*`)
- The activation/check-in endpoint itself (client-side, this is an outbound call the client makes, not an inbound route to protect — but the equivalent inbound concept, a future "re-enter license key" recovery screen reachable from the Full Lock state, must be whitelisted)
- Once `.installed` exists, `install.php` must NOT be reachable again in a way that lets an attacker overwrite `.env` with arbitrary database credentials — this is an `EXISTING` protection today (`install.php` halts with a warning if `.installed` exists) that must be preserved, not loosened, when the Guard is added (`INST` finding in `04-LICENSING-INSTALLER-AUDIT.md` §4.3 already flags the narrower risk that a permissions issue deleting `.installed` re-opens Step 1 — this is a filesystem/ops hardening concern outside this document's scope, noted here for completeness).

Full whitelist specification is in `06-GLOBAL-LICENSE-GUARD.md` §3.
