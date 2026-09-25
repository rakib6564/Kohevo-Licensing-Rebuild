# Phase 0: 08 — Licensing Migration & Dependency Risks

**Document Status:** Complete Fact-Based Audit  
**Phase:** Phase 0 — Existing System Audit  
**Target Architecture Reference:** `planning.md`, `docs/00-project/REQUIREMENTS.md` (§15), `docs/00-project/DECISIONS.md` (§15, §18), `agents/CLOUD-AGENT-RULES.md`  

---

## 1. Executive Summary

This audit assesses the technical risks, dependencies, backward-compatibility constraints, and data hazards that must be navigated during the upcoming phases of the Kohevo / Solaya Commercial Licensing Rebuild.

The Solaya application is a live, working platform with active customer installations (as demonstrated by the production database dump `u263467780_kohevo_client.sql`). The rebuild must enforce a secure commercial licensing boundary **without disrupting existing application functionality or corrupting production data**.

---

## 2. Identified Migration & Dependency Risks

### 2.1 Database Schema Inconsistency Risk
- **Risk Description:** The live client database dump (`u263467780_kohevo_client.sql`) does not have migrations `0015` through `0022` or `0024` applied. Tables like `remote_license_cache`, `licenses`, and `platform_plans` do not exist on live databases.
- **Evidence:** `u263467780_kohevo_client.sql` lines 180-186 confirm only migrations 0001, 0002, 0011, 0014, and 0023 are in the ledger.
- **Hazard:** If new licensing migrations or code assume `remote_license_cache` already exists with specific columns, existing deployed instances will crash upon upgrading.
- **Mitigation Requirement:** New migrations must either use `CREATE TABLE IF NOT EXISTS` or check table existence safely before altering. Never assume post-install migrations were executed.

### 2.2 The "Tenant Elimination" Systemic Breakdown Risk
- **Risk Description:** DECISIONS.md §3 mandates that "Client Does Not Use Tenant Management as the Commercial Model." However, hundreds of queries across the client application and plugins (`forms`, `membership`, `booking`) hardcode `tenant_id = ?` and rely on `current_tenant_id()`.
- **Evidence:** 
  - `01-client/src/Tenancy/TenantContext.php` manages request-level tenant isolation.
  - Base `Repository` automatically injects `tenant_id` into database queries.
  - Base schema tables (`users`, `customers`, `roles`, `contacts`, `settings`) have `tenant_id` columns.
- **Hazard:** Attempting to globally delete `tenant_id` or remove multi-tenant columns from core database tables during the licensing rebuild would cause widespread breakage across all existing features and plugins.
- **Mitigation Requirement:** De-couple **commercial licensing** from the tenant concept without destroying the underlying single-tenant database schema. The client application should operate with a fixed default tenant (`tenant_id = 1`) internally while completely hiding and isolating tenant concepts from the licensing authority, client licensing UI, and installer.

### 2.3 Central Licensing Server Schema Restructuring Risk
- **Risk Description:** On the Central Server (`02-licensing`), `licensing_installs` conflates licenses and installations, and `licensing_plans` owns entitlements via a JSON column.
- **Evidence:** `02-licensing/plugins/licensing/install.sql` lines 49-93.
- **Hazard:** Transitioning to a strict `Plan -> License -> Installation -> Entitlements` relational model requires modifying the Central Server schema. If existing rows in `licensing_installs` and `licensing_installation_bindings` are migrated carelessly, active client licenses could be invalidated.
- **Mitigation Requirement:** Phase 2 and Phase 3 migrations on the Central Server must map existing `licensing_installs` records cleanly to the new `licenses` and `installations` tables while preserving historical license keys and activation bindings.

### 2.4 Cryptographic Keypair & Signing Migration Risk
- **Risk Description:** The Central Server uses Ed25519 keypairs stored in encrypted settings (`licensing.signing_secret_key` encrypted with `APP_SECRET`). The client verifies using `LICENSE_SERVER_PUBLIC_KEY` in `.env`.
- **Evidence:** `02-licensing/plugins/licensing/LicensingAPI.php` lines 50-83, `01-client/bin/license-check.php` line 36.
- **Hazard:** Changing the signing algorithm or canonical payload structure will break signature verification on all existing client installations. Furthermore, if `APP_SECRET` changes on the Central Server, the private signing key becomes unrecoverable.
- **Mitigation Requirement:** Preserve Ed25519 signing (`sodium_crypto_sign_detached` / `sodium_crypto_sign_verify_detached`). Ensure payload canonicalization is strictly backwards-compatible or versioned in the response envelope.

### 2.5 Fail-Closed Deadlock Risk During Installation
- **Risk Description:** REQUIREMENTS.md mandates a global license lock where an unlicensed installation blocks all routes.
- **Evidence:** `01-client/config.php` loads all core files and routes before any page executes.
- **Hazard:** If the Global License Lock is implemented naively in `config.php`, it could block access to the installer itself (`install.php`), asset files (`assets/*`), or the activation callback endpoint, creating an inescapable deadlock where the software cannot be installed because it is not licensed.
- **Mitigation Requirement:** The Global License Guard must explicitly allow an immutable whitelist of unauthenticated setup/recovery endpoints: `install.php`, static assets (`/assets/*`), and the license activation endpoint.

### 2.6 Background Job Zombie Execution Risk
- **Risk Description:** `cron.php` runs every 5 minutes and triggers plugin background jobs (such as email dispatch).
- **Evidence:** `01-client/cron.php` lines 100-135.
- **Hazard:** If an installation license expires or is revoked, cron sweeps for unlicensed modules might continue running or, conversely, a global lock on `cron.php` might prevent the daily license re-check sweep from executing, preventing recovery after renewal.
- **Mitigation Requirement:** The license check-in sweep must remain executable via CLI/cron even when the license is in a restricted state, while plugin background hooks (`frequent_cron`) must be silenced for unlicensed modules.

---

## 3. Migration Findings Classification

| ID | Finding Description | Evidence / Code Location | Classification | Impact | Related Requirement |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **MIG-01** | Client DB dump has unapplied migration history | `u263467780_kohevo_client.sql` lines 180-186 | EXISTING | High risk of migration failure if scripts assume past migrations ran | REQ §15 |
| **MIG-02** | Deep application dependence on `current_tenant_id()` | `01-client/src/Tenancy/TenantContext.php` | EXISTING | Massive regression hazard if tenant columns are dropped globally | DEC §3, §18 |
| **MIG-03** | Central Server install/license schema conflation | `02-licensing/plugins/licensing/install.sql` | CONFLICTS WITH TARGET | Requires careful data transformation from `licensing_installs` to target model | DEC §1, REQ §8 |
| **MIG-04** | Potential deadlock in global license guard | `01-client/config.php` | REQUIRES VERIFICATION | Global gate could lock out installer or asset loading if not whitelisted | REQ §5, DEC §8 |
| **MIG-05** | Loss of Central Server private key on `APP_SECRET` change | `02-licensing/plugins/licensing/LicensingAPI.php` line 102 | EXISTING | Inability to issue or validate licenses if hosting environment changes | REQ §13 |

---

## 4. Key Recommendations for Phase 1 (Architecture Specification)

1. **Retain Internal Multi-Tenant Core as Implementation Detail:** Maintain `tenant_id = 1` internally for data isolation and repository compatibility, but completely eliminate tenant management from the commercial model, client UI, and installer.
2. **Design Normalized Central Schema:** Separate `licenses`, `installations`, and `license_entitlements` into distinct relational tables on `02-licensing`.
3. **Structure Three-Tier Expiry Engine:** Explicitly model the pre-expiry warning (7 days), commercial post-expiry grace period (7 days), and final full lock. Keep offline cache tolerance strictly separate.
4. **Define Whitelisted Route Surface for Global Lock:** Specify the exact set of routes permitted to bypass the global license gate (`install.php`, static assets, activation endpoint).
5. **Architect Server-Side Module Guards:** Implement an entitlement guard layer that sits between routing and controller/service execution for `forms`, `membership`, and `booking`.
