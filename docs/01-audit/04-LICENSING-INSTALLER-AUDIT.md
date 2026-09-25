# Phase 0: 04 — Licensing Installer Audit

**Document Status:** Complete Fact-Based Audit  
**Phase:** Phase 0 — Existing System Audit  
**Target Architecture Reference:** `planning.md`, `docs/00-project/REQUIREMENTS.md` (§4), `docs/00-project/DECISIONS.md`  
**Sources Inspected:**
1. `01-client/install.php` (Installer script)
2. `01-client/src/Services/Installation/InstallationService.php` (Provisioning service)
3. `01-client/db/migrations/0023_installation_identity.php` (Installation identity migration)
4. `00-original/u263467780_kohevo_client.sql` (Production database dump from live installer execution)

---

## 1. Executive Summary

An exhaustive trace of `01-client/install.php` reveals that the existing installer flow **completely bypasses licensing**.

The current installation process consists of three steps:
1. **Step 1:** Collects MySQL database credentials and App URL, tests connection, and writes the `.env` file.
2. **Step 2:** Runs a hardcoded subset of 5 migrations, provisions the first administrator user, creates an `installation_identity` row, and writes `TENANT_ID` into `.env`.
3. **Step 3:** Discovers all plugins residing on disk (`PluginLoader::discoverOnDisk()`), allows the operator to select and activate any plugins arbitrarily, writes the `.installed` marker file, and redirects to `admin/login.php`.

### Fundamental Conflicts with Target Requirements
- **No License Key Input:** There is no field, step, or prompt to enter a commercial license key.
- **No Central Server Communication:** The installer makes zero network requests to the Central Licensing Server.
- **No Installation Activation:** The installation identity is generated locally via `bin2hex(random_bytes(16))` and stored in the database, but is never submitted to or activated by the Central Server.
- **Admin Creation Precedes Licensing:** The administrator account is created in Step 2, prior to any license verification.
- **Uncontrolled Plugin Activation:** Step 3 allows the user to activate any optional plugins (`forms`, `membership`, `booking`) found on disk without checking license entitlement.

---

## 2. End-to-End Trace of Current Installer Execution

```text
Request: GET /install.php
     ↓
Check if .installed marker exists
     ├─ Yes → Display "Kohevo is already installed" and exit
     └─ No  → Proceed
     ↓
Step 1: Database Configuration (GET /install.php?step=1)
     ├─ Form inputs: db_host, db_port, db_name, db_user, db_pass, app_url
     ├─ Submission: POST step=1
     ├─ Connects via PDO: new PDO("mysql:host=...;dbname=...")
     ├─ Generates secrets: APP_SECRET = random_bytes(32), CRON_SECRET = random_bytes(32)
     ├─ Writes file: .env (chmod 0640)
     └─ Redirects: GET /install.php?step=2
     ↓
Step 2: Admin Account & Schema (GET /install.php?step=2)
     ├─ Pre-condition: SLATE_ROOT/.env must exist (otherwise redirects to ?step=1)
     ├─ Form inputs: name, email, password (min 8 chars)
     ├─ Submission: POST step=2
     ├─ Runs selected migrations via MigrationRunner:
     │    - 0001_core_init
     │    - 0002_identity_core
     │    - 0011_login_attempts
     │    - 0014_tenant_profiles
     │    - 0023_installation_identity
     ├─ Invokes InstallationService::provision($name, $email, $passwordHash):
     │    - Resolves/creates default tenant row in `tenants` table
     │    - Creates profile in `tenant_profiles` table
     │    - Ensures Super Admin role (id=1, slug='super-admin')
     │    - Generates 32-char hex installation ID and inserts into `installation_identity`
     │    - Inserts admin record into `users` table
     ├─ Updates .env: Appends or replaces `TENANT_ID=<resolved_id>`
     └─ Redirects: GET /install.php?step=3
     ↓
Step 3: Plugin Selection & Finalization (GET /install.php?step=3)
     ├─ Pre-condition: `users` table must contain at least 1 record
     ├─ Discovers plugins on disk: PluginLoader::discoverOnDisk()
     ├─ Form inputs: Checkbox list of all discovered plugins on disk
     ├─ Submission: POST step=3 (_action='apply' or 'skip')
     ├─ For each selected plugin: PluginLoader::installFromDisk($slug)
     │    - Executes plugin `install.sql` (if present)
     │    - Adds record to `plugins` table (`status = 'active'`)
     ├─ Writes marker file: SLATE_ROOT/.installed ("Installed: YYYY-MM-DD HH:MM:SS | Kohevo 1.0.0")
     ├─ Sets permissions: chmod .installed 0640
     └─ Redirects: GET /admin/login.php?installed=1
```

---

## 3. Comparison: Actual vs. Target Installation Flow

| Sequence Step | Target Requirement (REQUIREMENTS.md §4) | Actual Existing Implementation (`install.php`) | Status Classification |
| :--- | :--- | :--- | :--- |
| **1** | Database Configuration | Step 1: Tests DB connection, writes `.env` with DB credentials and secrets. | EXISTING |
| **2** | Install Application | Step 2: Runs core migrations (0001, 0002, 0011, 0014, 0023). | PARTIALLY IMPLEMENTED |
| **3** | License Key | **MISSING:** No UI or handler for license key input. | MISSING |
| **4** | Central Server Validation | **MISSING:** No API request made to Central Server. | MISSING |
| **5** | Activate Installation | **MISSING:** Installation identity is created locally but never registered or bound with Central Server. | MISSING |
| **6** | Create Admin Account | Step 2: Admin account is created **before** licensing validation. | CONFLICTS WITH TARGET |
| **7** | Finish (Lock/Marker) | Step 3: Writes `.installed` marker after arbitrary plugin selection. | PARTIALLY IMPLEMENTED |
| **8** | Dashboard Redirect | Redirects to `/admin/login.php?installed=1`. | EXISTING |

---

## 4. Detailed Component Inspections

### 4.1 `InstallationService::provision()` (`01-client/src/Services/Installation/InstallationService.php`)
- **Transaction Safety:** Wraps provisioning in a database transaction (`beginTransaction()` / `commit()` / `rollBack()`).
- **Idempotency:** Re-running after a partial POST checks for existing records in `tenants`, `tenant_profiles`, `roles`, `installation_identity`, and `users`.
- **Installation Identity Generation:**
  ```php
  // Lines 123-140
  private static function ensureInstallationIdentity(int $tenantId): string
  {
      $row = \Database::row(
          'SELECT installation_id FROM installation_identity WHERE singleton_id = 1 OR tenant_id = ? LIMIT 1',
          [$tenantId]
      );
      if ($row !== null && (string) $row['installation_id'] !== '') {
          return (string) $row['installation_id'];
      }

      $installationId = bin2hex(random_bytes(16));
      \Database::insert('installation_identity', [
          'singleton_id'    => 1,
          'tenant_id'       => $tenantId,
          'installation_id' => $installationId,
      ]);
      return $installationId;
  }
  ```
- **Finding:** The installation ID is generated locally using `bin2hex(random_bytes(16))` (a 32-character hexadecimal string). It is stored with `singleton_id = 1` and `tenant_id`. It is never transmitted to the licensing server during installation.

### 4.2 Step 3 Plugin Picker Vulnerability
In Step 3, the installer executes:
```php
// Lines 198-212
$selected = ($action === 'skip') ? [] : array_values(array_intersect(
    array_map('strval', (array)($_POST['plugins'] ?? [])),
    array_keys($discovered)
));

foreach ($selected as $slug) {
    $res = PluginLoader::installFromDisk($slug);
    // ...
}
```
**Finding:** Any plugin present on the filesystem (e.g., `forms`, `membership`, `booking`, `coaching`, `stripe-payment`) can be checked and activated. There is zero verification against license entitlements.

### 4.3 Lock File & Re-Installation Security
- **Lock Mechanism:** Presence of `.installed` file in `SLATE_ROOT`.
- **Re-Installation Guard:** If `.installed` exists, `install.php` halts execution and outputs a warning message.
- **Vulnerability:** If `.installed` is deleted or unreadable due to permissions, `install.php` runs again. Because Step 2 checks `count($tenants) > 1`, a partially provisioned database throws an exception, but Step 1 can overwrite `.env` with arbitrary database credentials if an attacker can reach `install.php`.

---

## 5. Findings Classification

| ID | Finding Description | Evidence / Code Location | Classification | Impact | Related Requirement |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **INST-01** | Missing license key collection step | `01-client/install.php` lines 43-74 | MISSING | Operator never enters license key during setup | REQ §4, DEC §8 |
| **INST-02** | Missing Central Licensing Server validation | `01-client/install.php` lines 124-179 | MISSING | Installation proceeds without central license validation | REQ §4, DEC §2 |
| **INST-03** | Missing remote installation activation | `01-client/src/Services/Installation/InstallationService.php` lines 123-140 | MISSING | Central server has no record of the new installation | REQ §4, §7, DEC §9 |
| **INST-04** | Admin account created before license validation | `01-client/install.php` lines 151-155 | CONFLICTS WITH TARGET | Admin account exists even if software is completely unlicensed | REQ §4 |
| **INST-05** | Unentitled plugin selection in Step 3 | `01-client/install.php` lines 198-212 | CONFLICTS WITH TARGET | Operator can enable all optional modules without commercial entitlement | REQ §3.2, §5 |
| **INST-06** | Installer persists `TENANT_ID` to `.env` | `01-client/install.php` lines 160-170 | CONFLICTS WITH TARGET | Embeds multi-tenant architecture into client configuration | DEC §3 |
| **INST-07** | Partial migration runner skips licensing tables | `01-client/install.php` lines 143-149 | EXISTING | `remote_license_cache` table is never created by the installer | REQ §15 |
