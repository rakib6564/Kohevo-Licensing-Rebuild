# Client Application Package Manifest (`CLIENT-PACKAGE-MANIFEST.md`)

- **Package Filename:** `kohevo-client-production.zip`
- **Source Directory:** `01-client/`
- **Package Size:** `2,817,590` bytes (~2.7 MB)
- **Total Files Included:** `789` files

---

## 1. Core, Licensing & Module Components Included

| Component Category | Key Paths in Package | Purpose |
| :--- | :--- | :--- |
| **Installer & Activation Wizard** | `install.php`, `includes/installer_flow.php` | 5-step commercial installation wizard: (1) Database Configuration, (2) Install Application & Installation ID, (3) License Key Validation & Central Activation, (4) Create Admin Account, (5) Finish & Auto-Activate Entitled Modules. |
| **Configuration & Bootstrap** | `config.php`, `.env.example`, `index.php`, `public.php`, `route.php`, `cron.php` | Application bootstrap, environment loader, HTTPS enforcement, plugin loader, Global License Guard invocation, and cron dispatcher. |
| **Web Server Security** | `.htaccess`, `data/.htaccess`, `db/.htaccess`, `includes/.htaccess`, `src/.htaccess`, `bin/.htaccess`, `tests/.htaccess`, `audit/.htaccess`, `Claude/.htaccess` | Apache `mod_rewrite` routing and `Require all denied` / `403` protection for `.env`, `.installed`, `.git`, `data/`, `db_backups/`, `includes/`, `src/`, `bin/`, `db/`, `tests/`, `audit/`, `docs/`, `Claude/`. |
| **Core & Licensing Migrations** | `db/schema.sql`, `db/migrations/0001_core_init.php` … `0026_remote_license_cache_signed_payload.php`, `bin/migrate` | Core schema and commercial licensing migrations (`0022_remote_license_cache`, `0023_installation_identity`, `0024_remote_license_metadata`, `0025_remote_license_cache_installation_id`, `0026_remote_license_cache_signed_payload`). |
| **Installation Identity** | `src/Services/Installation/InstallationService.php`, `db/migrations/0023_installation_identity.php` | Generates and persists the unique 32-character lowercase hex `installation_id` in `installation_identity` (`singleton_id = 1`) and `.env` (`INSTALLATION_ID`). |
| **Remote Licensing Client & Verifier** | `plugins/licensing/client/RemoteLicenseClient.php`, `plugins/licensing/client/LicenseSignatureVerifier.php`, `plugins/licensing/client/LicenseCacheStoreInterface.php` | Communicates with Central `POST /licensing/check`, verifies detached Ed25519 signatures using `LICENSE_SERVER_PUBLIC_KEY`, enforces installation ID matching, and rejects stale/replayed responses. |
| **Signed Local License Cache** | `src/Services/Licensing/SlateLicenseCacheStore.php` | Reads and writes signed license envelopes in `remote_license_cache`, verifying Ed25519 signature integrity (`raw_payload` + `raw_signature`), `installation_id` binding, and 7-day (`604800`s) offline availability tolerance on every read (`readTrustState()`). |
| **Global License Guard** | `includes/license_guard.php`, `config.php` | Single mandatory choke point locking Dashboard, Admin/User, Site Settings, Forms, Membership, Booking, APIs, Cron, and MCP when no valid/grace-eligible signed license state is present; permits only `/admin/login.php`, `/admin/logout.php`, and `/admin/license.php` for recovery. |
| **ModuleGuard & Entitlements** | `src/Services/Licensing/ModuleGuard.php`, `src/Services/Licensing/EntitlementService.php` | Enforces V1 Core features (`admin`, `dashboard`, `settings`) and optional module entitlements (`forms`, `membership`, `booking`) across UI navigation, admin controllers, public routes, REST APIs, and MCP tools. |
| **Expiry, Warning & Grace Handling** | `src/Services/Licensing/CommercialLicenseWindow.php`, `src/Services/Licensing/LicenseStatusPresenter.php`, `admin/partials/header.php` | Implements the 7-day pre-expiry warning banner, expiry transition, 7-day post-expiry commercial grace period with warning banner, and post-grace full application lock. |
| **Client License UI & Recovery Flow** | `admin/license.php` | Displays commercial license status, plan, expiry date, and enabled modules; provides CSRF-protected and `settings.edit`-gated **Re-check license** and license key update recovery actions. |
| **Optional Module: Form Builder (`forms`)** | `plugins/forms/Forms.php`, `plugins/forms/FormsAPI.php`, `plugins/forms/FormsMcpHandler.php`, `plugins/forms/admin/`, `plugins/forms/public/` | Form Builder module gated by `ModuleGuard` (`forms` entitlement). |
| **Optional Module: Membership (`membership`)** | `plugins/membership/Membership.php`, `plugins/membership/MembershipAPI.php`, `plugins/membership/admin/`, `plugins/membership/public/` | Membership module gated by `ModuleGuard` (`membership` entitlement). |
| **Optional Module: Booking (`booking`)** | `plugins/booking/Booking.php`, `plugins/booking/BookingAPI.php`, `plugins/booking/BookingMcpHandler.php`, `plugins/booking/admin/`, `plugins/booking/public/` | Booking module gated by `ModuleGuard` (`booking` entitlement). |
| **Supporting Plugins** | `plugins/backups/`, `plugins/coaching/`, `plugins/mcp-gateway/`, `plugins/media-library/`, `plugins/multilang-translate/`, `plugins/stripe-payment/` | Bundled platform plugins (with `mcp-gateway` enforcing `ModuleGuard` per tool scope). |
| **Daily License Check Cron & CLI** | `bin/license-check.php`, `bin/migrate`, `bin/activate-plugin.php`, `bin/deactivate-plugin.php`, `bin/reset-admin-password.php`, `bin/backup-db.sh`, `bin/restore-backup.php` | Unattended daily license check-in CLI script (`php bin/license-check.php`) and operational CLI utilities. |
| **Verification & Test Suites** | `tests/smoke.php`, `tests/unit/run.php`, `tests/run-phase9-licensing.php`, `tests/run-phase10-licensing.php`, `tests/run-phase11-security.php`, `tests/run-phase13-legacy.php`, `tests/e2e/phase12/` | CLI-guarded (`PHP_SAPI !== 'cli'`) unit, integration, security, and E2E verification suites. |

---

## 2. Files & Directories Intentionally Excluded

The following files and directories from the repository were strictly excluded from `kohevo-client-production.zip`:

| Excluded Path / Pattern | Reason for Exclusion |
| :--- | :--- |
| `.git/`, `.github/`, `.gitignore` | Version control metadata and CI workflows must never be deployed to production web roots. |
| `01-client/.env` | Local development environment configuration and database settings. |
| `01-client/.installed` | Installation completion marker (must be absent so fresh installations start at `/install.php` Step 1). |
| `01-client/data/slate.log` (`data/*.log`, `*.log`) | Local development and test-generated runtime logs (`data/.htaccess` and `data/.gitkeep` are preserved). |
| `01-client/db_backups/` | Local database backup staging directory (`BackupRunner.php` creates `db_backups/` with `.htaccess` on demand if backups are enabled). |
| `00-original/` (including `u263467780_kohevo_client.sql` and reference ZIPs) | Reference customer SQL dump and historical archives outside `01-client/`. |
| `01-client/.DS_Store`, `.claude/`, `.idea/`, `.vscode/`, `*.tmp`, `*.bak`, `*.swp` | macOS Finder metadata, IDE/agent files, and temporary artifacts. |
| `*.key`, `*.pem`, `*.sqlite*`, `*.db` | Private keys and local database files (Client never holds private signing keys). |
