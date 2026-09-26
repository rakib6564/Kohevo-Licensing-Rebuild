# Production Security Checklist (`SECURITY-CHECKLIST.md`)

Use this checklist to audit both the **Central Licensing Server** and **Client Application** before and immediately after production deployment.

---

## 1. Package & Artifact Security

- [ ] **No Real `.env` Shipped:** Verify neither `kohevo-licensing-central-production.zip` nor `kohevo-client-production.zip` contains a `.env` file (only `.env.example`).
- [ ] **No Private Signing Keys Shipped:** Verify no Ed25519 secret key, `.key`, or `.pem` file exists in either ZIP package.
- [ ] **No Customer SQL Dumps Shipped:** Verify `u263467780_kohevo_client.sql` and `db_backups/` are absent from both ZIP packages.
- [ ] **No Runtime Logs or `.installed` Markers Shipped:** Verify `data/slate.log` and `.installed` are absent from both ZIP packages.
- [ ] **No `.git/` History Shipped:** Verify `.git/` is absent from both ZIP packages.

---

## 2. Central Licensing Server Security

- [ ] **Strong `APP_SECRET` & `CRON_SECRET`:** Generated via `openssl rand -hex 32` (64 hex characters each) and stored in `/var/www/kohevo-licensing/.env` with `chmod 600 .env`.
- [ ] **Ed25519 Signing Keypair Generated:** `php bin/licensing-generate-keys.php` executed once on the live Central server.
- [ ] **Private Key Encrypted at Rest:** `licensing.signing_secret_key` in `settings` is encrypted via `slate_encrypt_secret()` (AES-256-GCM keyed off Central's `APP_SECRET`) and never exposed outside the Central server.
- [ ] **Central Secret Backup:** Central's `APP_SECRET` and the `licensing.signing_public_key` / `licensing.signing_secret_key` settings rows are backed up in a secure vault.
- [ ] **License Keys Hashed at Rest:** Raw commercial license keys are never stored in `licensing_licenses` — only `license_key_hash` (SHA-256) and `license_key_prefix` are persisted.
- [ ] **Strict RBAC & Platform Admin Access:**
  - Only authorized operators have accounts on the Central server.
  - All `/plugins/licensing/admin/*.php` endpoints require authentication (`Auth::require()`) and the `licensing.manage` permission (`Auth::requirePerm('licensing.manage')`).
  - `/admin/platform-admins.php` requires `Auth::requirePlatformAdmin()`.
- [ ] **CSRF & IDOR Enforcement:** All state-changing POST actions on Central verify CSRF tokens (`csrf_verify()`) and validate entity ownership (`license_id` / `product_id` / `installation_id`).
- [ ] **Legacy Licensing Path Locked Down:** `LegacyLicensePolicy` ensures legacy screens (`admin/installs.php`, `admin/install.php`) cannot issue new legacy keys, reactivate revoked/expired legacy rows, or expand legacy entitlements.

---

## 3. Client Application Security

- [ ] **Unique `APP_SECRET` & `CRON_SECRET`:** Each client installation has its own unique 32-byte random `APP_SECRET` and `CRON_SECRET` (`chmod 600 .env` or `0640` during installer).
- [ ] **Public Key Only on Client:** Client `.env` contains `LICENSE_SERVER_PUBLIC_KEY` (Central's Ed25519 public key) — **never** Central's secret key or Central's `APP_SECRET`.
- [ ] **Durable Unique Installation Identity:**
  - `InstallationService::provisionCore()` generates a 32-character lowercase hex `installation_id` stored in `installation_identity` (`singleton_id = 1`) and `.env` (`INSTALLATION_ID`).
  - Never copy `.env` (`INSTALLATION_ID`) or `installation_identity` from one client server to another (1 License = 1 Installation binding).
- [ ] **Signed Cache Verification (`SlateLicenseCacheStore`):**
  - Every cached state row in `remote_license_cache` requires a valid Ed25519 signature (`raw_payload` + `raw_signature`) verified against `LICENSE_SERVER_PUBLIC_KEY`.
  - Direct database tampering of `remote_license_cache` columns (`status`, `entitlements_json`, `expires_at`, `installation_id`) without a matching Ed25519 signature over `raw_payload` fails closed (`readTrustState()` returns untrusted).
  - Monotonic timestamp protection rejects replayed older signed envelopes (`checked_at < current cached checked_at`).
  - Legacy local tables (`licenses`, `platform_plans`, `tenants.plan_id`) grant **zero** runtime entitlements on the Client.
- [ ] **Global License Guard (`includes/license_guard.php`):**
  - Enforced across all web, admin, public, API, AJAX, cron, and MCP entry points via `config.php`.
  - When untrusted, missing, mismatched, suspended, revoked, or expired past the 7-day grace period, all normal routes are locked; only `/admin/login.php`, `/admin/logout.php`, and `/admin/license.php` remain reachable for recovery.
- [ ] **ModuleGuard (`src/Services/Licensing/ModuleGuard.php`):**
  - Enforced across sidebar navigation, admin pages, public routes, REST APIs, and MCP tools for optional modules (`forms`, `membership`, `booking`).
  - Unentitled admin/API/MCP requests return `403`; unentitled public module routes return `404`.
- [ ] **Platform Admin Screens Unreachable on Client:**
  - Client UI exposes only `/admin/license.php` (status, plan, expiry, enabled modules, and recovery check-in) and does not expose platform-level global license/plan/tenant administration.

---

## 4. Web Server & Network Hardening (Both Servers)

- [ ] **HTTPS Forced:** Valid TLS 1.2/1.3 certificates active on both Central and Client domains; HTTP redirects (`301`) to HTTPS.
- [ ] **PHP Error Display Disabled:** `config.php` sets `ini_set('display_errors', '0')` and `ini_set('log_errors', '1')`.
- [ ] **Sensitive Paths Return `403 Forbidden`:**
  - `/.env`
  - `/.installed`
  - `/.git/`
  - `/data/` and `/data/slate.log`
  - `/db_backups/`
  - `/audit/`
  - `/Claude/`
  - `/includes/`
  - `/src/`
  - `/bin/`
  - `/db/`
  - `/tests/`
  - `/docs/`
- [ ] **Cron Authentication via Header:** `cron.php` is invoked using `curl -H 'X-Cron-Key: ...'` rather than passing secrets in URL query strings where practical.
