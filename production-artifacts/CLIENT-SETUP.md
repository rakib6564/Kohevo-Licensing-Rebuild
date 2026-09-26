# Client Application Setup Guide (`CLIENT-SETUP.md`)

This document describes the complete end-to-end deployment and installation flow for the **Kohevo Client Application** (`kohevo-client-production.zip`).

---

## Installation Flow Overview

The Client Application enforces a strict, resumable 5-step installation sequence (`install.php` + `includes/installer_flow.php`):

```
Database Configuration (Step 1)
  → Install Application (Step 2: Core Schema + Unique Installation ID)
  → License Key (Step 3: Central Licensing Server Validation & Activate Installation)
  → Create Admin Account (Step 4)
  → Finish (Step 5: Auto-Activate Entitled Modules & Write .installed)
  → Dashboard (/admin/)
```

> [!IMPORTANT]
> Normal application access is completely locked until a valid license is validated by the Central Licensing Server and bound to this installation's unique `INSTALLATION_ID`. Attempting to skip ahead via URL parameters (`?step=4`) or forged POST requests is rejected server-side by `installer_resolve_step()`.

---

## 1. Requirements

- **PHP:** PHP 8.1+ (tested on PHP 8.3 and PHP 8.5)
- **Required PHP Extensions:**
  - `pdo_mysql`
  - `sodium` (`ext-sodium` for Ed25519 signature verification)
  - `curl` (required by `RemoteLicenseClient` to communicate with the Central Licensing Server over HTTPS)
  - `json`
  - `mbstring`
  - `openssl`
- **Database:** MySQL 8.0+ or MariaDB 10.11+ (`utf8mb4_unicode_ci`)
- **Web Server:** Apache 2.4+ (`mod_rewrite` + `AllowOverride All`) or Nginx 1.18+ with PHP-FPM
- **Outbound Network Access:** Outbound HTTPS (`TCP 443`) from the Client server to `LICENSE_SERVER_URL` (`POST /licensing/check`)

---

## 2. Database Creation

Create a dedicated MySQL/MariaDB database and user for the client installation:

```sql
CREATE DATABASE kohevo_client
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'kohevo_client_user'@'localhost'
  IDENTIFIED BY 'REPLACE_WITH_STRONG_DB_PASSWORD';

GRANT ALL PRIVILEGES ON kohevo_client.*
  TO 'kohevo_client_user'@'localhost';

FLUSH PRIVILEGES;
```

---

## 3. Package Extraction

Create the client document root, upload `kohevo-client-production.zip`, and extract it:

```bash
sudo mkdir -p /var/www/kohevo-client
sudo unzip -q kohevo-client-production.zip -d /var/www/kohevo-client
cd /var/www/kohevo-client
```

Set ownership so the web server can write `.env` and `.installed` during the installation wizard, as well as `data/` and `uploads/`:

```bash
sudo chown -R www-data:www-data /var/www/kohevo-client
sudo chmod 750 /var/www/kohevo-client/data
```

---

## 4. `.env` Configuration

The Client Application requires Central Licensing Server connection settings (`LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, `LICENSE_PRODUCT`).

You can provide these in either of two ways:
1. **Hosting Panel / Web Server Environment Variables (Recommended for managed distributions):** Set `LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, and `LICENSE_PRODUCT=kohevo` in your PHP-FPM pool or Apache vhost `SetEnv`, then let **Installer Step 1** create `.env` with database and secret settings.
2. **File-Based `.env` Configuration:** Copy `.env.example` to `.env` after Step 1 (or before Step 3) and ensure the licensing coordinates are present.

> [!NOTE]
> If `.env` does not exist yet when you first open `/install.php`, **Step 1** of the web installer creates `.env` with `APP_URL`, `APP_SECRET`, `CRON_SECRET`, and `DB_*`. Before submitting **Step 3** (License Key), ensure `LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, and `LICENSE_PRODUCT` are present in `.env` (or set in the server environment).

---

## 5. `LICENSE_SERVER_URL`

Set `LICENSE_SERVER_URL` to the base HTTPS URL of your Central Licensing Server (without a trailing slash):

```ini
LICENSE_SERVER_URL=https://licensing.yourdomain.com
```

`RemoteLicenseClient` sends signed check-in requests to `https://licensing.yourdomain.com/licensing/check`.

---

## 6. `LICENSE_SERVER_PUBLIC_KEY`

Set `LICENSE_SERVER_PUBLIC_KEY` to the Base64-encoded Ed25519 public key generated on the Central Licensing Server (`php bin/licensing-generate-keys.php`):

```ini
LICENSE_SERVER_PUBLIC_KEY=<Base64-Ed25519-Public-Key>
```

`LicenseSignatureVerifier` uses this public key to verify the detached Ed25519 signature on every licensing response before it is trusted or written to `remote_license_cache`.

---

## 7. `LICENSE_PRODUCT`

Set `LICENSE_PRODUCT` to the product slug registered on the Central Licensing Server:

```ini
LICENSE_PRODUCT=kohevo
```

---

## 8. `LICENSE_KEY`

- During fresh web installation, you enter the customer's license key in **Step 3** of `/install.php`.
- Once validated, `install.php` (and the recovery form in `/admin/license.php`) automatically persists `LICENSE_KEY=<verified-key>` into `.env` so the daily cron job (`php bin/license-check.php`) can perform unattended background revalidations.
- You may also pre-set `LICENSE_KEY` in `.env` from `.env.example`.

---

## 9. Web-Server Configuration

- **Apache:** Ensure `mod_rewrite` is enabled and `AllowOverride All` is configured for `/var/www/kohevo-client`. The shipped `.htaccess` blocks direct access to `.env`, `.installed`, `.git`, `data/`, `db_backups/`, `includes/`, `src/`, `bin/`, `db/`, `tests/`, `audit/`, `docs/`, `Claude/`, and routes public URLs to `public.php`. See `APACHE-SETUP.md`.
- **Nginx:** Configure the server block and `location` deny rules documented in `NGINX-SETUP.md`.

---

## 10. HTTPS

Configure a valid TLS certificate for the client domain (e.g. `https://app.clientdomain.com`). Ensure `APP_URL` uses `https://`.

---

## 11. Installer (`https://app.clientdomain.com/install.php`)

Open `https://app.clientdomain.com/install.php` in your browser:

### Step 1 — Database Configuration (`?step=1`)
- Enter:
  - **Application URL:** `https://app.clientdomain.com` (no trailing slash)
  - **Database host:** `127.0.0.1` (or `localhost`)
  - **Port:** `3306` (optional)
  - **Database name:** `kohevo_client`
  - **Database user:** `kohevo_client_user`
  - **Database password:** Your database password
- Click **Connect & continue →**.
- The installer verifies the PDO connection, generates 32-byte random `APP_SECRET` and `CRON_SECRET`, writes `.env` (`chmod 0640`), and advances to Step 2.
- If you are not injecting `LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, and `LICENSE_PRODUCT` via server environment variables, append them to `/var/www/kohevo-client/.env` now before Step 3:
  ```ini
  LICENSE_SERVER_URL=https://licensing.yourdomain.com
  LICENSE_SERVER_PUBLIC_KEY=<Base64-Ed25519-Public-Key>
  LICENSE_PRODUCT=kohevo
  ```

### Step 2 — Install Application (`?step=2`)
- Click **Install →**.
- The installer runs the core and licensing migrations (`0001_core_init`, `0002_identity_core`, `0011_login_attempts`, `0014_tenant_profiles`, `0023_installation_identity`, `0022_remote_license_cache`, `0024_remote_license_metadata`, `0025_remote_license_cache_installation_id`, `0026_remote_license_cache_signed_payload`).
- `InstallationService::provisionCore()` creates the default tenant, roles, and a cryptographically random 32-character lowercase hex `installation_id` in `installation_identity`, and persists both `TENANT_ID` and `INSTALLATION_ID` to `.env` (ensuring a database wipe-and-retry reuses the exact same `INSTALLATION_ID` without consuming a second activation slot).
- No admin user is created yet.

---

## 12. Activation (`?step=3` — License Key, Central Validation & Activate Installation)

- Enter the commercial **License Key** issued by the Central Licensing Server and click **Validate & activate →**.
- Behind the scenes:
  1. `RemoteLicenseClient::checkInDetailed()` sends a `POST /licensing/check` request to `LICENSE_SERVER_URL` with `product`, `license_key`, `install_id`, `domain`, and `app_version`.
  2. The Central Licensing Server validates the license key, checks that the license is activatable (`unactivated`, `trial`, or `active` and not bound to a different installation), binds the license to this `install_id` in `licensing_installations`, transitions `unactivated -> active`, and returns a signed envelope (`payload` + Ed25519 `signature`).
  3. The Client verifies the Ed25519 signature against `LICENSE_SERVER_PUBLIC_KEY`, verifies that the signed `installation_id` matches its own `installation_identity`, and stores the signed envelope in `remote_license_cache`.
  4. The verified `LICENSE_KEY` is persisted to `.env` (`0640`) for unattended daily cron check-ins.
- If the license is invalid, expired, suspended, revoked, or already bound to another installation, activation is rejected and the installer remains on Step 3.

---

## 13. Admin Creation (`?step=4`)

- Once the license is active, Step 4 (**Create your admin account**) unlocks.
- Enter:
  - **Your name**
  - **Email**
  - **Password** (minimum 8 characters)
- Click **Create account →**.
- `InstallationService::createAdminAccount()` creates the initial Super Admin user (`role_id = 1`).

---

## 14. Finish (`?step=5`)

- Step 5 displays your active license confirmation and automatically activates only the optional modules (`forms`, `membership`, `booking`) granted in your signed license entitlements (`PluginLoader::installFromDisk()`).
- Click **Finish →**.
- The installer writes the `.installed` lock file (`chmod 0640`) and redirects to `/admin/login.php?installed=1`.
- Apply any remaining core migrations via CLI so all schema tables are up to date:
  ```bash
  php bin/migrate migrate
  ```

---

## 15. Dashboard (`/admin/`)

- Log in at `https://app.clientdomain.com/admin/login.php` with the administrator account created in Step 4.
- You are taken to the **Dashboard** (`/admin/index.php`).
- Navigate to **Admin → License** (`/admin/license.php`) to view:
  - **Status** (e.g. `Active`)
  - **Plan**
  - **Expiry Date**
  - **Enabled Modules** (Core features + licensed optional modules)
- Unentitled optional modules are hidden from navigation and strictly blocked (`403` in Admin/API/MCP, `404` on public routes) by `ModuleGuard`.

---

## 16. Daily License-Check Cron

Configure a daily cron job to run `php bin/license-check.php` (see `CRON-SETUP.md`):

```cron
0 2 * * * /usr/bin/php /var/www/kohevo-client/bin/license-check.php >> /var/www/kohevo-client/data/cron-license.log 2>&1
```

Test it manually right after installation:

```bash
cd /var/www/kohevo-client
php bin/license-check.php
# Expected output: Check-in succeeded -- local cache updated.
```

Also schedule the web/application cron (`cron.php`) every 5 minutes if using background tasks (email reminders, Google Drive backups, etc.):

```cron
*/5 * * * * curl -fsS -H "X-Cron-Key: YOUR_CLIENT_CRON_SECRET" "https://app.clientdomain.com/cron.php" > /dev/null 2>&1
```

---

## 17. Backup / Recovery

### Backing Up the Client Installation

1. **Preserve `.env` and `INSTALLATION_ID`:**
   - Back up `/var/www/kohevo-client/.env`.
   - **Critical:** `INSTALLATION_ID` in `.env` (and in the `installation_identity` table) uniquely identifies this server to the Central Licensing Server. Preserve it across restores or server migrations so the license remains bound to this installation.
2. **Nightly Database Backup:**
   ```cron
   25 3 * * * BACKUP_DIR=/var/backups/kohevo-client /var/www/kohevo-client/bin/backup-db.sh /var/www/kohevo-client >> /var/backups/kohevo-client/backup.log 2>&1
   ```

### License Lock Recovery Flow

If a license expires past the 7-day grace period, is suspended, or the local signed cache becomes stale (e.g. after >7 days of network isolation):
1. The Global License Guard (`includes/license_guard.php`) locks the application and redirects authenticated admins to `/admin/license.php`.
2. Only `/admin/login.php`, `/admin/logout.php`, and `/admin/license.php` remain accessible.
3. Once the license is renewed/unsuspended on Central (or a new license key is issued for this installation), an admin with `settings.edit` permission can log in, visit `/admin/license.php`, and click **Re-check license** (or enter the new license key) to immediately synchronize the signed cache and unlock the application.

---

## 18. Troubleshooting

| Symptom | Cause | Resolution |
| :--- | :--- | :--- |
| **"Licensing is not configured for this build. Contact your provider."** on Installer Step 3 | `LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, or `LICENSE_PRODUCT` is missing from `.env` / environment. | Add `LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, and `LICENSE_PRODUCT=kohevo` to `.env` and reload Step 3. |
| **"Could not reach the licensing server. Check your connection and try again."** | DNS failure, firewall blocking outbound HTTPS (`443`), or wrong `LICENSE_SERVER_URL`. | Test from client server CLI: `curl -i -X POST "$LICENSE_SERVER_URL/licensing/check"`. Verify SSL certificate and `/licensing/check` rewrite rule on Central. |
| **"This license key could not be validated. Double-check the key and try again."** | Invalid license key, wrong `LICENSE_PRODUCT`, or mismatched `LICENSE_SERVER_PUBLIC_KEY` (`signature_invalid`), or license already bound to a different `installation_id` (`installation_mismatch`). | Check `data/slate.log` for the failure category (`http_status`, `signature_invalid`, `installation_mismatch`). Verify public key matches Central's `LicensingAPI::signingPublicKey()`. |
| **"This license is not currently active. Please contact your provider."** | License status on Central is `expired`, `suspended`, or `revoked`. | Check the license status in Central Admin (`/plugins/licensing/admin/licenses.php`). |
| **`bin/license-check.php` prints `Remote licensing is not configured for this install -- skipping.`** | One of `LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, `LICENSE_PRODUCT`, or `LICENSE_KEY` is empty in `.env`. | Ensure all four variables are defined in `.env`. |
