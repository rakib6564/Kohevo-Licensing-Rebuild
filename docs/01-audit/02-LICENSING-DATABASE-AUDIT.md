# Phase 0: 02 — Licensing Database Audit

**Document Status:** Complete Fact-Based Audit  
**Phase:** Phase 0 — Existing System Audit  
**Target Architecture Reference:** `planning.md`, `docs/00-project/REQUIREMENTS.md`, `docs/00-project/DECISIONS.md`  
**Sources Inspected:**
1. `00-original/u263467780_kohevo_client.sql` (Live client database dump)
2. `01-client/db/schema.sql` (Base installation schema)
3. `01-client/db/migrations/` (Client migration scripts 0001 to 0024)
4. `02-licensing/plugins/licensing/install.sql` (Central server schema)
5. `02-licensing/plugins/licensing/migrations/0024_installation_binding.sql` (Central server migration)

---

## 1. Executive Summary

A comprehensive audit of the database state across the client application, the central licensing server, and the provided production client database dump reveals major structural discrepancies, architectural conflicts, and migration hazards:

1. **Production Client Database is Missing Post-Install Migrations:** The provided production dump (`u263467780_kohevo_client.sql`) contains records for only 5 applied migrations (`0001_core_init`, `0002_identity_core`, `0011_login_attempts`, `0014_tenant_profiles`, `0023_installation_identity`). Tables created by migrations `0013`, `0015`, `0016`, `0017`, `0021`, `0022`, and `0024` (`platform_admins`, `platform_plans`, `plan_entitlements`, `licenses`, `remote_license_cache`) **do not exist** in this production database.
2. **Structural Conflation on Central Server:** On the Central Licensing Server (`02-licensing`), the table `licensing_installs` conflates a commercial license with an installation deployment. Furthermore, entitlements are stored as a JSON string (`entitlements_json`) directly on `licensing_plans`, precluding per-license optional module selection.
3. **Tenant Coupling on Single-Install Client Tables:** Client-side licensing and installation tables (`installation_identity`, `remote_license_cache`, and legacy `licenses`) enforce unique constraints and foreign keys against `tenant_id`, reflecting multi-tenant heritage rather than an isolated installation model.
4. **Referential Integrity Omissions:** The Central Licensing Server does not enforce foreign keys on `licensing_installs.client_id`, `licensing_installs.plan_id`, or `licensing_checkins.install_id`. Deletion of a client or plan can leave orphaned install records.

---

## 2. Production Client Database Dump Audit (`u263467780_kohevo_client.sql`)

### 2.1 Table Inventory
The production database dump contains exactly 26 tables:
- `admin_sessions`
- `audit_log`
- `contacts`
- `contact_emails`
- `contact_forms`
- `contact_form_submissions`
- `contact_phones`
- `customers`
- `customer_auth_tokens`
- `identities`
- `identity_tokens`
- `installation_identity`
- `lang_overrides`
- `login_attempts`
- `media_files`
- `media_usage`
- `migrations`
- `plugins`
- `roles`
- `role_permissions`
- `settings`
- `slate_notifications`
- `tenants`
- `tenant_profiles`
- `users`
- `user_mfa_factors`
- `user_mfa_recovery_codes`

### 2.2 Applied Migrations Ledger
The `migrations` table contains exactly 5 records:
```sql
INSERT INTO `migrations` (`id`, `migration`, `batch`, `applied_at`) VALUES
(1, '0001_core_init', 1, '2026-09-24 23:01:42'),
(2, '0002_identity_core', 1, '2026-09-24 23:01:43'),
(3, '0011_login_attempts', 1, '2026-09-24 23:01:43'),
(4, '0014_tenant_profiles', 1, '2026-09-24 23:01:43'),
(5, '0023_installation_identity', 1, '2026-09-24 23:01:43');
```

### 2.3 Detailed Table Structure: `installation_identity`
- **Columns:**
  - `singleton_id`: `tinyint(3) unsigned NOT NULL DEFAULT 1`
  - `tenant_id`: `int(10) unsigned NOT NULL`
  - `installation_id`: `char(32) COLLATE utf8mb4_unicode_ci NOT NULL`
  - `created_at`: `datetime NOT NULL DEFAULT current_timestamp()`
  - `updated_at`: `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- **Keys & Constraints:**
  - Primary Key: `(`singleton_id`)`
  - Unique Key: `uniq_installation_identity_tenant` (`tenant_id`)
  - Unique Key: `uniq_installation_identity_value` (`installation_id`)
- **Live Data:**
  - `singleton_id`: `1`
  - `tenant_id`: `1`
  - `installation_id`: `'dd67d8e7604e17f4af4727f8df7596f0'`
  - `created_at`: `'2026-09-24 23:01:43'`

### 2.4 Critical Finding: Absent Tables in Production Dump
The following tables defined in migration files are **completely absent** from the production client database dump:
- `remote_license_cache` (defined in `0022_remote_license_cache.php`, updated in `0024`)
- `licenses` (defined in `0017_licenses.php`)
- `platform_plans` (defined in `0015_platform_plans.php`)
- `plan_entitlements` (defined in `0015_platform_plans.php`, updated in `0021`)
- `platform_admins` (defined in `0013_platform_admins.php`)

**Impact:** Any code in `01-client` attempting to query `remote_license_cache`, `licenses`, or `platform_plans` will fail with a PDO table-not-found exception on this database unless migrations are applied or tables are created.

---

## 3. Client Schema & Migration Specifications (`01-client/`)

### 3.1 `remote_license_cache` (Migration 0022 + 0024)
Intended to store the verified state received from the Central Licensing Server.
- **Table Name:** `remote_license_cache`
- **Engine:** InnoDB, `utf8mb4_unicode_ci`
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `tenant_id`: `INT UNSIGNED NOT NULL` (Unique Key: `uniq_remote_license_cache_tenant`)
  - `status`: `VARCHAR(32) NOT NULL` (Plain string representing remote status: `trial`, `active`, `expired`, `suspended`, `revoked`)
  - `plan`: `VARCHAR(64) NULL`
  - `entitlements`: `JSON NULL`
  - `expires_at`: `DATETIME NULL`
  - `fetched_at`: `DATETIME NOT NULL`
  - `signature_valid`: `TINYINT(1) NOT NULL DEFAULT 1`
  - `remote_checked_at`: `DATETIME NULL` (Added in 0024)
  - `next_check_after`: `INT NULL` (Added in 0024, recommended polling interval in seconds)
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- **Analysis:**
  - Couplings: Bound to `tenant_id`.
  - Integrity: The raw signature and raw payload string are NOT stored. `signature_valid` is a static flag that is never updated or re-verified by `SlateLicenseCacheStore::load()`.

### 3.2 Legacy Local Licensing Tables (Migrations 0015, 0016, 0017, 0021)

#### Table: `licenses` (Migration 0017)
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `tenant_id`: `INT UNSIGNED NOT NULL` (Index: `idx_license_tenant`)
  - `license_key_hash`: `CHAR(64) NOT NULL` (Unique Key: `uniq_license_key_hash`)
  - `plan_id`: `INT UNSIGNED NULL`
  - `status`: `ENUM('trial','active','expired','suspended','revoked','cancelled') NOT NULL DEFAULT 'trial'`
  - `issued_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `starts_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `expires_at`: `DATETIME NULL`
  - `activation_limit`: `INT UNSIGNED NOT NULL DEFAULT 1`
  - `activation_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
  - `metadata`: `JSON NULL`
  - `last_validated_at`: `DATETIME NULL`
  - `revoke_reason`: `VARCHAR(255) NULL`
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- **Status:** CONFLICTS WITH TARGET. Exists on the client database to support local multi-tenant SaaS licensing.

#### Table: `platform_plans` (Migration 0015)
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `slug`: `VARCHAR(64) NOT NULL` (Unique Key: `uniq_plan_slug`)
  - `name`: `VARCHAR(120) NOT NULL`
  - `description`: `TEXT NULL`
  - `is_active`: `TINYINT(1) NOT NULL DEFAULT 1`
  - `sort_order`: `INT NOT NULL DEFAULT 0`
  - `limits`: `JSON NULL`
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

#### Table: `plan_entitlements` (Migration 0015, 0021)
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `plan_id`: `INT UNSIGNED NOT NULL`
  - `feature_key`: `VARCHAR(64) NOT NULL`
  - `enabled`: `TINYINT(1) NOT NULL DEFAULT 1`
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- **Keys:**
  - Unique Key: `uniq_plan_feature` (`plan_id`, `feature_key`)
  - Index: `idx_feature_key` (`feature_key`)

---

## 4. Central Licensing Server Schema Audit (`02-licensing/`)

The Central Licensing Server schema is defined in `02-licensing/plugins/licensing/install.sql` and `migrations/0024_installation_binding.sql`.

### 4.1 Table: `licensing_products`
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `slug`: `VARCHAR(64) NOT NULL UNIQUE KEY uniq_product_slug`
  - `name`: `VARCHAR(160) NOT NULL`
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### 4.2 Table: `licensing_clients`
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `name`: `VARCHAR(160) NOT NULL`
  - `email`: `VARCHAR(190) NULL KEY idx_client_email`
  - `notes`: `TEXT NULL`
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

### 4.3 Table: `licensing_plans`
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `product_id`: `INT UNSIGNED NOT NULL KEY idx_plan_product`
  - `slug`: `VARCHAR(64) NOT NULL`
  - `name`: `VARCHAR(160) NOT NULL`
  - `entitlements_json`: `LONGTEXT NOT NULL`
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- **Keys:**
  - Unique Key: `uniq_plan_product_slug` (`product_id`, `slug`)
- **Architectural Finding:** Entitlements are serialized as a flat JSON array of strings directly in `entitlements_json`. There is no relation to individual licenses.

### 4.4 Table: `licensing_installs` (Conflated License/Install Table)
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `client_id`: `INT UNSIGNED NOT NULL KEY idx_install_client`
  - `product_id`: `INT UNSIGNED NOT NULL`
  - `plan_id`: `INT UNSIGNED NULL`
  - `label`: `VARCHAR(190) NOT NULL DEFAULT ''`
  - `domain`: `VARCHAR(190) NOT NULL`
  - `domain_normalized`: `VARCHAR(190) NULL` (Added in 0024)
  - `license_key_hash`: `CHAR(64) NOT NULL UNIQUE KEY uniq_install_key_hash`
  - `status`: `ENUM('trial','active','expired','suspended','revoked','cancelled') NOT NULL DEFAULT 'trial' KEY idx_install_status`
  - `issued_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `starts_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `expires_at`: `DATETIME NULL`
  - `activation_limit`: `INT UNSIGNED NOT NULL DEFAULT 1`
  - `activation_count`: `INT UNSIGNED NOT NULL DEFAULT 0`
  - `installed_version`: `VARCHAR(40) NULL`
  - `last_checkin_at`: `DATETIME NULL`
  - `last_checkin_ip`: `VARCHAR(45) NULL`
  - `revoke_reason`: `VARCHAR(255) NULL`
  - `metadata_json`: `LONGTEXT NULL`
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- **Keys:**
  - Unique Key: `uniq_install_product_domain` (`product_id`, `domain`)
  - Key: `idx_install_domain_normalized` (`product_id`, `domain_normalized`)
- **Architectural Finding:** This single table attempts to be both a commercial license (key hash, status, plan, expiry) and an installation record (domain, activation count, installed version).

### 4.5 Table: `licensing_installation_bindings`
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `install_id`: `INT UNSIGNED NOT NULL KEY idx_binding_install`
  - `installation_id`: `CHAR(32) NOT NULL UNIQUE KEY uniq_binding_identity`
  - `domain_normalized`: `VARCHAR(190) NOT NULL`
  - `first_registered_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `last_seen_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `last_seen_ip`: `VARCHAR(45) NULL`
  - `status`: `ENUM('active','suspended','revoked','cancelled') NOT NULL DEFAULT 'active'`
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `updated_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- **Keys & Constraints:**
  - Unique Key: `uniq_binding_install_identity` (`install_id`, `installation_id`)
  - Foreign Key: `fk_binding_install` FOREIGN KEY (`install_id`) REFERENCES `licensing_installs`(`id`) ON DELETE CASCADE

### 4.6 Table: `licensing_checkins`
- **Columns:**
  - `id`: `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
  - `install_id`: `INT UNSIGNED NOT NULL`
  - `checked_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
  - `ip`: `VARCHAR(45) NULL`
  - `reported_domain`: `VARCHAR(190) NULL`
  - `reported_version`: `VARCHAR(40) NULL`
  - `response_status`: `VARCHAR(20) NOT NULL`
  - `failure_code`: `VARCHAR(64) NULL` (Added in 0024)
  - `created_at`: `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- **Keys:**
  - Key: `idx_checkin_install_time` (`install_id`, `checked_at`)
  - Key: `idx_checkin_failure` (`failure_code`)

---

## 5. Comparative Schema & Relationship Analysis

### 5.1 Comparison Against Target Conceptual Model
Target Model:
```text
Plan
  ↓
License
  ↓
Installation
  ↓
Entitlements
```

| Entity | Target Model | Current Client Schema | Current Central Server Schema | Classification |
| :--- | :--- | :--- | :--- | :--- |
| **Plan** | Package/template defining modules | `platform_plans` (local to client) | `licensing_plans` (owns `entitlements_json`) | CONFLICTS WITH TARGET |
| **License** | Commercial authority entity with status, dates, entitlements | `licenses` (local to client, tenant-scoped) | Conflated in `licensing_installs` | CONFLICTS WITH TARGET |
| **Installation** | Client deployment bound to License via Installation ID | `installation_identity` (`singleton_id=1`, `tenant_id`) | `licensing_installation_bindings` | PARTIALLY IMPLEMENTED |
| **Entitlements** | Specific modules granted to a specific License | `plan_entitlements` (tied to local plan) | `licensing_plans.entitlements_json` (tied to plan, not license) | CONFLICTS WITH TARGET |

---

## 6. Detailed Findings Classification

| ID | Finding Description | Evidence / Code Location | Classification | Impact | Related Requirement |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **DB-01** | Production client dump missing 7 migrations | `u263467780_kohevo_client.sql` lines 180-186 | EXISTING | `remote_license_cache`, `licenses`, `platform_plans` do not exist on live client DB | REQ §15 |
| **DB-02** | Central server conflates License with Installation | `02-licensing/plugins/licensing/install.sql` line 67 | CONFLICTS WITH TARGET | Precludes 1-to-many or clean lifecycle separation between license and installation | DEC §1, REQ §7 |
| **DB-03** | Entitlements stored on Plan, not on License | `02-licensing/plugins/licensing/install.sql` line 54 | CONFLICTS WITH TARGET | Administrator cannot issue a license with arbitrary optional module combinations | REQ §3.2, §3.3, §6 |
| **DB-04** | Client tables hardcoded to `tenant_id` | `01-client/db/migrations/0023_installation_identity.php` line 26 | CONFLICTS WITH TARGET | Prevents clean single-install client model without tenant dependencies | DEC §3, REQ §7 |
| **DB-05** | Unstored/Unverified signature in client cache | `01-client/src/Services/Licensing/SlateLicenseCacheStore.php` lines 32-49 | CONFLICTS WITH TARGET | Cache can be modified in database without signature detection | REQ §13 |
| **DB-06** | Missing foreign key constraints on central server | `02-licensing/plugins/licensing/install.sql` lines 61-93 | PARTIALLY IMPLEMENTED | Risk of orphaned install and checkin records upon client/plan deletion | REQ §8 |
| **DB-07** | Inconsistent ID types | `installation_identity` uses `char(32)`, `licenses.id` uses `int unsigned`, `contacts.id` uses `bigint` | EXISTING | Minor type variance; requires consistent typing in target schema | REQ §7 |
