# Central Licensing Server Package Manifest (`CENTRAL-PACKAGE-MANIFEST.md`)

- **Package Filename:** `kohevo-licensing-central-production.zip`
- **Source Directory:** `02-licensing/`
- **Package Size:** `2,094,340` bytes (~2.0 MB)
- **Total Files Included:** `616` files

---

## 1. Core & Licensing Components Included

| Component Category | Key Paths in Package | Purpose |
| :--- | :--- | :--- |
| **Configuration & Bootstrap** | `config.php`, `.env.example`, `index.php`, `public.php`, `route.php`, `cron.php`, `install.php` | Central bootstrap, environment loader, HTTPS enforcement, session init, plugin loader, public router, and daily cron entry point. |
| **Web Server Security** | `.htaccess`, `data/.htaccess`, `db/.htaccess`, `includes/.htaccess`, `src/.htaccess`, `bin/.htaccess` | Apache `mod_rewrite` routing (`POST /licensing/check` -> `public.php`) and `Require all denied` / `403` protection for sensitive directories and files. |
| **Core Migrations & Schema** | `db/schema.sql`, `db/migrations/0001_core_init.php` … `0024_remote_license_cache_installation_id.php`, `bin/migrate` | Core database schema and idempotent CLI migration runner (`php bin/migrate migrate`). |
| **Central Licensing Plugin** | `plugins/licensing/Licensing.php`, `plugins/licensing/plugin.json`, `plugins/licensing/install.sql`, `plugins/licensing/uninstall.sql`, `plugins/licensing/migrations/0024_installation_binding.sql`, `plugins/licensing/migrations/0025_commercial_licensing_rebuild.sql` | Central licensing authority plugin bootstrap, schema self-heal, public route registration (`/licensing/check`), daily expiry sweep hook, and central dashboard module catalog widget. |
| **All Platform & Commercial Modules** | `plugins/forms/`, `plugins/membership/`, `plugins/booking/`, `plugins/stripe-payment/`, `plugins/multilang-translate/`, `plugins/media-library/`, `plugins/backups/`, `plugins/mcp-gateway/`, `plugins/coaching/` | Complete modular capabilities bundled directly inside the central installation: Forms, Membership, Booking, Stripe Payments, Visual Translations, Media Library, Backups, MCP AI Gateway, and Coaching. |
| **Plan Management** | `plugins/licensing/PlanService.php`, `plugins/licensing/admin/plans.php` | Commercial plan CRUD and default optional-module template assignment (`licensing_plans`, `licensing_plan_modules`). |
| **License & Lifecycle Management** | `plugins/licensing/LicenseService.php`, `plugins/licensing/admin/licenses.php`, `plugins/licensing/admin/license.php` | License issuance (SHA-256 hashed keys), independent optional-module entitlements (`forms`, `membership`, `booking`), and lifecycle state transitions (`unactivated`, `trial`, `active`, `expired`, `suspended`, `revoked`, `cancelled`; `suspend`, `unsuspend`, `revoke`, `renew`, `extend`, `sweepExpired`). |
| **Installation Binding Management** | `plugins/licensing/InstallationService.php`, `plugins/licensing/admin/license.php` | 1 License → 1 Installation identity binding (`licensing_installations`), activation validation, installation revocation, and admin installation reset. |
| **Ed25519 Signing & Check-In API** | `plugins/licensing/LicensingAPI.php`, `plugins/licensing/public/check.php`, `bin/licensing-generate-keys.php` | Ed25519 keypair generation, AES-256-GCM encrypted secret key storage in `settings`, canonical JSON payload signing, and `POST /licensing/check` handler. |
| **Legacy Policy Enforcement** | `plugins/licensing/LegacyLicensePolicy.php`, `plugins/licensing/admin/installs.php`, `plugins/licensing/admin/install.php` | Phase 13 restriction policy preventing new key issuance, reactivation, or entitlement expansion on legacy `licensing_installs` records. |
| **Platform Administration & RBAC** | `admin/platform-admins.php`, `admin/users.php`, `admin/roles.php`, `includes/Auth.php`, `src/Services/Auth/` | Platform administrator management (`platform_admins`), role permissions (`licensing.manage`), MFA, and session security. |
| **Audit & Event History** | `includes/AuditLog.php`, `src/Services/Audit/AuditLog.php`, `admin/audit.php`, `plugins/licensing/LicenseService.php` (`licensing_license_events`) | Immutable audit logging (`audit_log`) and per-license lifecycle event ledger (`licensing_license_events`). |
| **CLI Utilities** | `bin/migrate`, `bin/licensing-generate-keys.php`, `bin/activate-plugin.php`, `bin/deactivate-plugin.php`, `bin/reset-admin-password.php`, `bin/backup-db.sh`, `bin/restore-backup.php`, `bin/diagnose-login.php` | Production CLI management, keypair generation, plugin activation, admin password initialization, and database backup/restore. |

---

## 2. Files & Directories Intentionally Excluded

The following files and directories from the repository were strictly excluded from `kohevo-licensing-central-production.zip`:

| Excluded Path / Pattern | Reason for Exclusion |
| :--- | :--- |
| `.git/`, `.github/`, `.gitignore` | Version control metadata and CI workflows must never be deployed to production web roots. |
| `02-licensing/.env` | Local development environment configuration and database settings. |
| `02-licensing/.installed` | Installation completion marker (generated during target server setup). |
| `02-licensing/data/slate.log` (`data/*.log`, `*.log`) | Local development and test-generated runtime logs (`data/.htaccess` and `data/.gitkeep` are preserved). |
| `02-licensing/db_backups/` | Local database backup staging directory. |
| `00-original/` (including `u263467780_kohevo_client.sql` and reference ZIPs) | Reference customer SQL dump and historical archives outside `02-licensing/`. |
| `.DS_Store`, `.claude/`, `.idea/`, `.vscode/`, `*.tmp`, `*.bak`, `*.swp` | macOS Finder metadata, IDE/agent files, and temporary artifacts. |
| `*.key`, `*.pem`, `*.sqlite*`, `*.db` | Private keys and local database files (Central Ed25519 keys are generated on the live server via `php bin/licensing-generate-keys.php`). |
| `tests/`, `Claude/`, `audit/`, `docs/` | Lean build (2026-09-27): test suites, agent notes, audit notes and developer docs are not needed at runtime. The full real-HTTP E2E suite passed against this exact package. |
| `dev-server.php`, `Makefile`, `composer.json` | Local development tooling (PHP built-in server router, make targets); Apache/LiteSpeed use `.htaccess`. |
| `README.md`, `README-PATCH.md`, `AUDIT.md`, `CONTRIBUTING.md`, `SECURITY.md`, `SKILL_studio_plugin*.md`, `STUDIO_BUILD_BRIEF.md` | Developer documentation. `INSTALL.md` and `.env.example` are kept. |
| `bin/anti-drift*`, `bin/clean-demo.php`, `bin/cleanup-test-pages.php`, `bin/create-construction-site.php`, `bin/create-react-site.php`, `bin/deploy.sh`, `bin/mship-views.php`, `bin/package-plugin.php`, `bin/seed-demo.php`, `bin/seed-solaya.php` | Demo seeding and developer scripts. Operational scripts (`migrate`, backups, restore, plugin activation, admin password reset, login diagnosis, license check / key generation) are kept. |
