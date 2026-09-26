# Production Go-Live Checklist (`GO-LIVE-CHECKLIST.md`)

Complete this step-by-step verification checklist when launching the **Kohevo Central Licensing Server** and each **Kohevo Client Application** in production.

---

## Phase A — Central Licensing Server Go-Live

1. **Infrastructure & Environment**
   - [ ] PHP 8.1+ with `pdo_mysql`, `sodium`, `openssl`, `curl`, `json`, `mbstring` installed and verified (`php -m`).
   - [ ] Dedicated MySQL 8.0+ / MariaDB 10.11+ database (`utf8mb4_unicode_ci`) created.
   - [ ] `kohevo-licensing-central-production.zip` extracted into Central document root.
   - [ ] `.env` created from `.env.example`, populated with production `APP_URL`, 32-byte random `APP_SECRET` and `CRON_SECRET`, and `DB_*` credentials.
   - [ ] `.env` permissions restricted (`chmod 600 .env`); `data/` directory writable by web server (`chmod 750 data`).

2. **Web Server & HTTPS**
   - [ ] Valid HTTPS / TLS certificate installed for Central domain.
   - [ ] Apache (`AllowOverride All` + `mod_rewrite`) or Nginx server block configured (`APACHE-SETUP.md` / `NGINX-SETUP.md`).
   - [ ] Direct HTTP requests to `/.env`, `/.installed`, `/data/`, `/bin/`, `/includes/`, `/src/`, `/db/`, `/tests/`, `/audit/`, `/Claude/`, `/docs/` return `403 Forbidden`.

3. **Database Migration & Key Generation**
   - [ ] Core migrations applied cleanly: `php bin/migrate migrate`.
   - [ ] Licensing plugin activated: `php bin/activate-plugin.php licensing`.
   - [ ] `.installed` lock file present (`chmod 640 .installed`).
   - [ ] Ed25519 signing keypair generated: `php bin/licensing-generate-keys.php`.
   - [ ] Base64 public key recorded for Client distribution.
   - [ ] Central `.env` (`APP_SECRET`) and `settings` (`licensing.signing_public_key`, `licensing.signing_secret_key`) backed up securely.

4. **Administration & First License Issuance**
   - [ ] Primary admin account created (`php bin/reset-admin-password.php`) and granted Platform Administrator status (`/admin/platform-admins.php`).
   - [ ] Product created with slug `kohevo` (`/plugins/licensing/admin/products.php`).
   - [ ] Client record created (`/plugins/licensing/admin/clients.php`).
   - [ ] Commercial Plan created (`/plugins/licensing/admin/plans.php`).
   - [ ] Commercial License issued (`/plugins/licensing/admin/licenses.php`) with desired expiry date and optional modules (`forms`, `membership`, `booking`).
   - [ ] Public check-in endpoint responds over HTTPS:
     `curl -i -X POST https://licensing.yourdomain.com/licensing/check -H 'Content-Type: application/json' -d '{}'` returns `HTTP 400 {"error":"invalid_request"}`.

5. **Cron & Backups**
   - [ ] Central `cron.php` scheduled via crontab with `X-Cron-Key` header (`CRON-SETUP.md`).
   - [ ] Nightly database backup scheduled via `bin/backup-db.sh`.

---

## Phase B — Client Application Go-Live

1. **Infrastructure & Pre-Configuration**
   - [ ] PHP 8.1+ with `pdo_mysql`, `sodium`, `openssl`, `curl`, `json`, `mbstring` installed and verified.
   - [ ] Dedicated MySQL 8.0+ / MariaDB 10.11+ database (`utf8mb4_unicode_ci`) created.
   - [ ] `kohevo-client-production.zip` extracted into Client document root.
   - [ ] Apache (`AllowOverride All` + `mod_rewrite`) or Nginx server block configured with HTTPS.
   - [ ] Direct HTTP requests to `/.env`, `/.installed`, `/data/`, `/bin/`, `/includes/`, `/src/`, `/db/`, `/tests/`, `/audit/`, `/Claude/` return `403 Forbidden`.

2. **5-Step Commercial Installer Verification (`/install.php`)**
   - [ ] **Step 1 (Database Configuration):** Connects to MySQL and writes `.env` (`APP_URL`, `APP_SECRET`, `CRON_SECRET`, `DB_*`).
   - [ ] **Licensing Coordinates Configured:** `LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, and `LICENSE_PRODUCT=kohevo` present in `.env` (or server environment).
   - [ ] **Step 2 (Install Application):** Runs core/licensing schema migrations and provisions unique 32-char hex `INSTALLATION_ID` in `installation_identity` and `.env`.
   - [ ] **Step 3 (License Key → Central Validation & Activate Installation):** Validates license key against Central `/licensing/check`, binds `INSTALLATION_ID` on Central, stores signed envelope in `remote_license_cache`, and persists `LICENSE_KEY` to `.env`.
   - [ ] **Step 4 (Create Admin Account):** Creates initial Super Admin user.
   - [ ] **Step 5 (Finish):** Auto-activates entitled optional modules (`forms`, `membership`, `booking`), writes `.installed`, and redirects to `/admin/login.php?installed=1`.
   - [ ] Remaining core migrations applied: `php bin/migrate migrate`.

3. **Runtime & Entitlement Verification**
   - [ ] Admin login succeeds at `/admin/login.php` and renders **Dashboard** (`/admin/index.php`).
   - [ ] **Admin → License** (`/admin/license.php`) displays `Active` status, Plan name, Expiry date, and exact entitled modules.
   - [ ] Core features (Dashboard, Users/Roles, Site Settings) are accessible.
   - [ ] Entitled optional modules are accessible; unentitled optional modules are hidden in sidebar and return `403` (Admin/API) or `404` (Public).

4. **Cron & Backups**
   - [ ] Manual execution of `php bin/license-check.php` outputs `Check-in succeeded -- local cache updated.`.
   - [ ] Daily cron job scheduled for `php bin/license-check.php` (`0 2 * * *`).
   - [ ] Every-5-minute cron job scheduled for `cron.php` with `X-Cron-Key` header.
   - [ ] Nightly database backup scheduled via `bin/backup-db.sh` and `.env` (`INSTALLATION_ID`, `APP_SECRET`, `LICENSE_KEY`) backed up securely.
