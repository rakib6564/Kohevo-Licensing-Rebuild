# Phase 1: 10 — Client Licensing Database Design

**Document Status:** Architecture Specification (Documentation Only — No Migrations)
**Phase:** Phase 1 — Target Architecture Specification
**Scope:** `01-client` target licensing-related schema
**Reference:** `docs/01-audit/02-LICENSING-DATABASE-AUDIT.md` §3, `08-LICENSING-MIGRATION-RISKS.md` §2.2

> This is a conceptual data-model specification. **No migrations are created in this phase.**

---

## 1. The Tenant Coupling Problem, Restated

`DECISIONS.md` §3 locks: the client does not use tenant management as the commercial model. `08-LICENSING-MIGRATION-RISKS.md` §2.2 already establishes why this **cannot** mean globally removing `tenant_id` from the client database: the base schema (`users`, `customers`, `roles`, `contacts`, `settings`, and every plugin table) is tenant-scoped by construction, the base `Repository` layer automatically injects `tenant_id` into queries, and `TenantContext`/`current_tenant_id()` are load-bearing throughout the entire application, not just the licensing subsystem. Ripping this out would be exactly the "general rewrite of the Solaya application" `DECISIONS.md` §18 prohibits.

**Target resolution, restated precisely from the Migration Risk's own recommendation:** the client continues to operate internally as a single-tenant deployment with a fixed, resolved tenant row (today's pattern already supports this — `InstallationService::provision()` already asserts "This installation contains more than one tenant; fresh installation cannot continue safely" and resolves exactly one tenant). What changes is **not the schema** but **which identity the licensing/commercial layer is keyed on**: `installation_id`, never `tenant_id`. Tenant remains a real, populated, necessary internal concept for RBAC/data-scoping; it simply stops being a *licensing* concept.

---

## 2. `installation_identity` — `EXISTING` table, `TARGET` semantic change only

| Column | Type | Target Treatment |
| :--- | :--- | :--- |
| `singleton_id` | `TINYINT UNSIGNED DEFAULT 1`, PK | `EXISTING`, unchanged — already correctly models "exactly one row" |
| `tenant_id` | `INT UNSIGNED NOT NULL` | `EXISTING` column, **retained** — `ASSUMPTION`/`RECOMMENDED`: keep the column (removing it would touch a foreign key/uniqueness relationship for no licensing benefit, per §1) but stop treating its uniqueness constraint as licensing-relevant. It exists for internal repository consistency, not identity. |
| `installation_id` | `CHAR(32) NOT NULL` | `EXISTING`, unchanged — this is now unambiguously *the* licensing identity |
| `created_at` / `updated_at` | `DATETIME` | `EXISTING`, unchanged |

**Keys:** `EXISTING` — `PRIMARY KEY (singleton_id)`, `UNIQUE KEY uniq_installation_identity_tenant (tenant_id)`, `UNIQUE KEY uniq_installation_identity_value (installation_id)`. No key changes are required: because a fresh installation already only ever has exactly one tenant row (`singleton_id = 1` enforced by the installer, verified in production data — `02-LICENSING-DATABASE-AUDIT.md` §2.3 shows exactly one live row with `tenant_id = 1`), the `tenant_id` unique constraint is not *incorrect*, merely *irrelevant to licensing* — dropping it would be a schema change justified only by tidiness, not by any functional requirement. `RECOMMENDED`: leave the column and its constraints exactly as they are; the fix is entirely in which column new licensing code reads (`installation_id`, never `tenant_id`), not in the table shape.

---

## 3. License State Cache — `remote_license_cache` → `TARGET` hardened

Today's table (`EXISTING`, `remote_license_cache`) stores unpacked, individually-updatable columns (`status`, `plan`, `entitlements`, `expires_at`, `fetched_at`, `signature_valid`) with no raw payload or signature retained — meaning `SlateLicenseCacheStore::load()` trusts whatever is currently in these columns with no way to detect if a row was altered directly in the database after being written (`DB-05`, finding 2.8 in `06-LICENSING-SECURITY-AUDIT.md`).

**Target columns** (`RECOMMENDED` additions to the existing table — name retained as `remote_license_cache` for continuity, or renamed; naming is an implementation detail, not addressed further here):

| Column | Type | Purpose |
| :--- | :--- | :--- |
| `tenant_id` | `INT UNSIGNED NOT NULL` | `EXISTING`, retained for the same reason as §2 — internal row-scoping, not the licensing key |
| `status`, `plan`, `entitlements`, `expires_at`, `fetched_at` | as today | `EXISTING`, unchanged — these remain the *convenient, pre-parsed* view used by fast-path reads (e.g. dashboard presentational stats) |
| `raw_payload` | `TEXT NOT NULL` | `MISSING` today, `TARGET` new — the exact JSON string that was signed, verbatim, as received in the check-in envelope's `payload` field |
| `raw_signature` | `VARCHAR(255) NOT NULL` | `MISSING` today, `TARGET` new — the base64 Ed25519 signature that accompanied `raw_payload` |
| `verified_at` | `DATETIME NOT NULL` | `MISSING` today, `TARGET` new — when the signature was last actually verified against `raw_payload` (distinct from `fetched_at`, which only records when the row was written) |
| `installation_id` | `CHAR(32) NULL` | `TARGET`, `LOCKED` — no longer merely defense-in-depth (an earlier draft marked this `RECOMMENDED`). Since `installation_id` is now part of the *signed* payload itself (`11-LICENSING-API-CONTRACT.md` §2, resolving Finding F-01), this column is populated from `decodedPayload['installation_id']` at write time and is the value §4's mandatory equality check compares against `installation_identity.installation_id` on every load — it is a required input to trust verification, not an optional cross-check |
| `signature_valid` | `TINYINT(1) DEFAULT 1` | `EXISTING` column — `TARGET` semantic change: this should be *recomputed*, not merely stored, once `raw_payload`/`raw_signature` exist (see §4) |
| `remote_checked_at`, `next_check_after` | as today (added in migration 0024) | `EXISTING`, unchanged |
| `created_at` / `updated_at` | `DATETIME` | `EXISTING`, unchanged |

**Why this resolves `DB-05`:** with the raw signed payload and its signature retained, the client's read path can (a) at minimum, be independently audited/forensically checked after the fact by comparing stored `raw_payload`/`raw_signature` against the embedded `LICENSE_SERVER_PUBLIC_KEY`, and (b) optionally, re-verify the signature at read time rather than only at write time — turning "trust whatever is in the unpacked columns" into "trust only a payload whose signature still verifies right now." Option (b) is `RECOMMENDED` as the actual enforcement mechanism (not merely an audit capability) — see §4.

---

## 4. Read-Time vs. Write-Time Verification

Today, `RemoteLicenseClient::checkIn()` already verifies the Ed25519 signature at **write time** (before calling `$this->store->save(...)`) — this is `EXISTING` and correct as far as it goes. The gap is that `SlateLicenseCacheStore::load()` performs **no** verification at **read time** — it is a plain `SELECT * FROM remote_license_cache WHERE tenant_id = ?` with no cryptographic check at all (`DB-05`, verified directly against the class).

**Target:** with `raw_payload`/`raw_signature` retained (§3), the read path (`EntitlementService`'s consumers, and the new Global/Module Guards) can re-verify `sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)` on every load, or on a throttled cadence (e.g. once per request is likely acceptable given `sodium_crypto_sign_verify_detached` is a fast operation — no caching of the *verification result* itself is architecturally required, only caching of the *fetched state*, which is what this table already is). This closes the tampering vector completely: an attacker with direct database write access could still overwrite `raw_payload`/`raw_signature` together with consistent unpacked columns, but could not do so **and have it still verify** without possessing the Central Server's private signing key — which is the correct, intended security boundary (the same boundary the write-time check already relies on; this simply extends it to also cover post-write tampering, not just in-transit tampering).

`RECOMMENDED`, not `REQUIRES VERIFICATION` — this is a direct, mechanical closure of a clearly-identified gap with no product trade-off attached.

**`installation_id` binding check — `TARGET` new, `LOCKED` (resolves Finding F-01 of `16-PHASE-1-ANTIGRAVITY-REVIEW.md`, `P0 — CRITICAL`):** signature verification alone (above) is **necessary but not sufficient**. A signature proves a payload was genuinely produced by the Central Server; it does not by itself prove that payload was produced *for this installation*. Because every client installation shares the same embedded public key, a payload signed for installation `X` verifies successfully when checked against the public key on installation `Y` too — `sodium_crypto_sign_verify_detached()` has no notion of "this signature is scoped to a specific recipient." A copy of `raw_payload`/`raw_signature` lifted from installation `X`'s database and inserted into installation `Y`'s `remote_license_cache` row would therefore pass signature verification cleanly, even though `Y` was never actually licensed.

Both write-time (`RemoteLicenseClient::checkIn()`) and read-time (this section) verification MUST therefore perform a second, mandatory check immediately after signature verification succeeds, before the payload is trusted for any enforcement decision:

```php
if ($decodedPayload['installation_id'] !== $localInstallationId) {
    // Treat exactly as a signature-verification failure — untrusted, fail closed (06 §7)
}
```

`$localInstallationId` is read from `installation_identity.installation_id` (§2) — the one value that is generated locally and never transmitted *to* the client by the Central Server, so it cannot itself be part of what an attacker copies from another installation's payload and have it match. This is what actually converts installation cloning from a "detected on next check-in, if the Central Server happens to notice a conflicting `install_id`" problem (the pre-fix threat model) into a "rejected locally, offline, at the moment the clone attempts to load its copied cache" problem — see `12-SECURITY-ARCHITECTURE.md` §2.2 for the full before/after threat-model comparison.

---

## 5. Legacy Local Licensing Tables — Schema Disposition

`licenses`, `platform_plans`, `plan_entitlements` (migrations `0015`–`0017`, `0021`, `EXISTING`, `CONFLICTS WITH TARGET` per Phase 0) are **not modified or removed by this document** — per this Phase's constraints ("Do NOT delete or rename existing licensing code") and per `13-MIGRATION-STRATEGY.md`, their disposition is: remain present in the codebase's migration history unchanged, but **a NEW installation's installer no longer runs these specific migrations at all** (they are simply excluded from the target installer's migration list in `05-INSTALLATION-ACTIVATION.md` §1 Step 2 — "CORE migrations only"). This means a fresh target-architecture installation's database never even provisions these tables, which is a stronger and simpler resolution than provisioning-then-ignoring them. See `13-MIGRATION-STRATEGY.md` §3 and `14-BACKWARD-COMPATIBILITY.md` §2 for the full disposition and its backward-compatibility implications for already-existing installations (which are explicitly out of scope for migration per the locked project decision that this architecture targets NEW installations only).

---

## 6. Production Data Reality Check

Per `02-LICENSING-DATABASE-AUDIT.md` §2, the one available production dump has applied only migrations `0001`, `0002`, `0011`, `0014`, `0023` — meaning `remote_license_cache`, `licenses`, `platform_plans`, and `plan_entitlements` **do not exist** on that live database at all. This is strong corroborating evidence (not proof, since it is a single sample) that the "new architecture, new installations only" scoping decision this document builds on is the operationally correct one: even the one available real-world install never reached a state where the legacy licensing tables were relevant, so excluding them from new installs' migration set carries negligible regression risk to anything currently deployed. `ASSUMPTION`, grounded directly in Phase 0 evidence, not invented for this document.

---

## 7. Summary Table Against Target Model

| Target Concept | Client Table | Status |
| :--- | :--- | :--- |
| Installation Identity | `installation_identity` | `EXISTING` schema, `TARGET` semantic-only change (§2) |
| Verified License State (cache) | `remote_license_cache` (hardened) | `TARGET` new columns (§3) |
| License / Plan (commercial authority) | *(none on client — lives only on Central Server)* | `TARGET` — the client never stores an authoritative License or Plan row, only a verified cache of the Central Server's answer, consistent with `DECISIONS.md` §2 |
| Legacy local License/Plan engine | `licenses`, `platform_plans`, `plan_entitlements` | `EXISTING`, unchanged this phase, excluded from new-install provisioning going forward (§5) |
