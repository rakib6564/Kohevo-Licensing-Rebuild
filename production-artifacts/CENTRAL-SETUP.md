# Central Licensing Server Setup Guide (`CENTRAL-SETUP.md`)

This document provides the exact step-by-step procedure to deploy and initialize the **Kohevo Central Licensing Server** from `kohevo-licensing-central-production.zip`.

---

## 1. Server Requirements

- **Operating System:** Linux (Ubuntu 22.04/24.04 LTS, Debian 12, RHEL/AlmaLinux 9) or compatible POSIX server
- **Web Server:** Apache 2.4+ (with `mod_rewrite` and `mod_headers` enabled, `AllowOverride All`) or Nginx 1.18+ with PHP-FPM
- **HTTPS / TLS:** Valid TLS 1.2 / 1.3 certificate (Let's Encrypt or commercial CA)
- **Disk & Permissions:** Document root readable by web server user (`www-data` / `nginx` / `apache`); `data/` directory writable by web server and CLI user (`0750` or `0755`)
- **System Clock:** Synchronized via NTP (`chronyd` or `systemd-timesyncd`) in UTC so signed `checked_at` and expiry timestamps are accurate

---

## 2. PHP Requirements

- **PHP Version:** PHP 8.1 or newer (tested on PHP 8.3 and PHP 8.5)
- **Required PHP Extensions:**
  - `pdo_mysql` (database connectivity)
  - `sodium` (`ext-sodium` is **mandatory** on Central for Ed25519 key generation and payload signing)
  - `openssl` (AES-256-GCM encryption at rest via `slate_encrypt_secret()`)
  - `curl` (outbound HTTP utilities)
  - `json` (API payload serialization)
  - `mbstring` (multibyte string handling)

Verify PHP and extensions from the CLI:

```bash
php -v
php -m | grep -E '^(pdo_mysql|sodium|openssl|curl|json|mbstring)$'
```

---

## 3. Database Requirements

- **Engine:** MySQL 8.0+ or MariaDB 10.11+ (`InnoDB` storage engine)
- **Character Set / Collation:** `utf8mb4` / `utf8mb4_unicode_ci`
- **Isolation:** Dedicated database and database user exclusively for the Central Licensing Server

---

## 4. Create Database

Log in to MySQL/MariaDB and create the dedicated database and user:

```sql
CREATE DATABASE kohevo_licensing
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'kohevo_licensing_user'@'localhost'
  IDENTIFIED BY 'REPLACE_WITH_STRONG_DB_PASSWORD';

GRANT ALL PRIVILEGES ON kohevo_licensing.*
  TO 'kohevo_licensing_user'@'localhost';

FLUSH PRIVILEGES;
```

---

## 5. Upload Package

Create the target document root, upload `kohevo-licensing-central-production.zip`, and extract it cleanly:

```bash
sudo mkdir -p /var/www/kohevo-licensing
sudo unzip -q kohevo-licensing-central-production.zip -d /var/www/kohevo-licensing
cd /var/www/kohevo-licensing
```

Ensure `data/` is writable by the web server user:

```bash
sudo chown -R www-data:www-data /var/www/kohevo-licensing/data
sudo chmod 750 /var/www/kohevo-licensing/data
```

---

## 6. Configure `.env`

Copy `.env.example` to `.env` and restrict its permissions:

```bash
cp .env.example .env
chmod 600 .env
```

Generate cryptographically strong secrets for `APP_SECRET` and `CRON_SECRET`:

```bash
openssl rand -hex 32   # Use for APP_SECRET
openssl rand -hex 32   # Use for CRON_SECRET
```

Edit `/var/www/kohevo-licensing/.env`:

```ini
APP_URL=https://licensing.yourdomain.com
TENANT_ID=1
APP_SECRET=<64-char-hex-from-openssl-rand>
CRON_SECRET=<64-char-hex-from-openssl-rand>

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=kohevo_licensing
DB_USER=kohevo_licensing_user
DB_PASS=REPLACE_WITH_STRONG_DB_PASSWORD
DB_CHARSET=utf8mb4
```

> [!CAUTION]
> Once signing keys are generated in Step 10, **do not change `APP_SECRET`** without migrating encrypted settings. The Central Ed25519 private signing key (`licensing.signing_secret_key`) is encrypted at rest using AES-256-GCM derived from `APP_SECRET`.

---

## 7. Configure Apache / Nginx

Configure your web server to point to `/var/www/kohevo-licensing`:
- **Apache:** Enable `mod_rewrite` and set `AllowOverride All` on `/var/www/kohevo-licensing` so `.htaccess` enforces sensitive file blocking and routes `POST /licensing/check` to `public.php?_path=licensing/check`. See `APACHE-SETUP.md`.
- **Nginx:** Configure the `location /licensing/check` rule and sensitive path deny block as documented in `NGINX-SETUP.md`.

---

## 8. Configure HTTPS

1. Provision a valid TLS certificate for `licensing.yourdomain.com` (e.g. via `certbot`).
2. Verify that `APP_URL` in `.env` uses `https://`.
3. After logging into the Central Admin UI, you can also enable **Force HTTPS** under **Admin → Settings → Security** (`force_https = 1`), which enforces HTTP-to-HTTPS 301 redirects and emits `Strict-Transport-Security: max-age=31536000; includeSubDomains`.

---

## 9. Run Migration

Apply all core database migrations from the CLI:

```bash
cd /var/www/kohevo-licensing
php bin/migrate migrate
```

Verify migration status:

```bash
php bin/migrate status
```

Next, activate the bundled `licensing` plugin from the CLI (this registers the `licensing` plugin row in `plugins`, runs `plugins/licensing/install.sql` and `plugins/licensing/migrations/*.sql`, and registers the `licensing.manage` permission):

```bash
php bin/activate-plugin.php licensing
```

Mark the installation as complete by creating the `.installed` lock file if you initialized via CLI instead of `/install.php`:

```bash
php -r 'require "config.php"; file_put_contents(".installed", "Installed: " . date("Y-m-d H:i:s") . " | Kohevo " . SLATE_VERSION . "\n"); chmod(".installed", 0640);'
```

---

## 10. Generate Signing Keys

Generate the Central Server's Ed25519 signing keypair using the built-in CLI tool:

```bash
php bin/licensing-generate-keys.php
```

Expected output:

```text
Signing keypair generated and stored (secret key encrypted at rest).

Public key (embed this in every client install):

  <Base64-Ed25519-Public-Key>

The secret key is not shown — it never leaves this server.
```

If you ever need to print the current public key again:

```bash
php -r 'require "config.php"; require_once "plugins/licensing/LicensingAPI.php"; echo LicensingAPI::signingPublicKey() . "\n";'
```

- Copy the printed Base64 public key string — you will set this as `LICENSE_SERVER_PUBLIC_KEY` in every Client Application's `.env`.
- `bin/licensing-generate-keys.php` refuses to overwrite an existing keypair unless `--force` is passed, preventing accidental rotation that would invalidate active client installations.

---

## 11. Protect Private Key

- **Storage Mechanism:** The Ed25519 secret key is never stored in a flat file on disk; `LicensingAPI::storeSigningKeypair()` encrypts the secret key using `slate_encrypt_secret()` (AES-256-GCM keyed by `APP_SECRET`) and stores the ciphertext in the `settings` table under `setting_key = 'licensing.signing_secret_key'`.
- **File Permissions:** Restrict `.env` (which holds `APP_SECRET` and database credentials) so only the service user can read it:
  ```bash
  chmod 600 /var/www/kohevo-licensing/.env
  chmod 640 /var/www/kohevo-licensing/.installed
  ```
- **Never distribute or expose:** Never copy `licensing.signing_secret_key` or Central's `APP_SECRET` to any client server. Client installations only ever receive the public key (`LICENSE_SERVER_PUBLIC_KEY`).

---

## 12. Create Platform Administrator

1. Create the primary Super Admin user (`role_id = 1`, which includes `licensing.manage` permission) using the CLI tool:

```bash
php bin/reset-admin-password.php admin@yourdomain.com 'YourStrongAdminPassword!'
```

2. Grant Platform Administrator status to this account (or via **Admin → Platform Administrators** at `/admin/platform-admins.php`):

```bash
php -r '
require "config.php";
$u = Database::row("SELECT id, email FROM users WHERE email = ?", ["admin@yourdomain.com"]);
if (!$u) { fwrite(STDERR, "User not found\n"); exit(1); }
Auth::grantPlatformAdmin((int)$u["id"], (int)$u["id"]);
echo "Granted Platform Admin to user #{$u["id"]} ({$u["email"]})\n";
'
```

3. Log in at `https://licensing.yourdomain.com/admin/login.php` and verify access to:
   - **Platform Administrators:** `/admin/platform-admins.php`
   - **Licensing Dashboard:** `/plugins/licensing/admin/index.php`

---

## 13. Create Plan

Before creating a Plan, ensure the Product (`kohevo`) and at least one Client exist:

1. **Create Product (`kohevo`):**
   - Navigate to **Licensing → Products** (`/plugins/licensing/admin/products.php?new=1`).
   - Set **Name:** `Kohevo` and **Slug:** `kohevo` (must match `LICENSE_PRODUCT=kohevo` on clients).
   - Click **Save**.
2. **Create Client Record:**
   - Navigate to **Licensing → Clients** (`/plugins/licensing/admin/clients.php?new=1`).
   - Enter the customer's **Name**, **Email**, and optional notes, then click **Save**.
3. **Create Commercial Plan:**
   - Navigate to **Licensing → Plans** (`/plugins/licensing/admin/plans.php?new=1`).
   - Select **Product:** `Kohevo` (`kohevo`).
   - Enter **Name** (e.g. `Professional`) and **Slug** (e.g. `professional`).
   - Check **Active**.
   - Select default **Optional Modules** (`Form Builder`, `Membership`, `Booking`) to pre-fill when issuing licenses under this plan (Core features — Admin/User, Dashboard, Site Settings — are always included automatically).
   - Click **Save**.

---

## 14. Create License

1. Navigate to **Licensing → Licenses** (`/plugins/licensing/admin/licenses.php`).
2. In the **Issue a new license** form:
   - **Client:** Select the customer created in Step 13.
   - **Product:** Select `Kohevo` (`kohevo`).
   - **Plan:** Select the Plan (e.g. `Professional`) or leave optional.
   - **Label:** Enter a descriptive label (e.g. `Acme Corp Production`).
   - **Start Date (`starts_at`):** Optional start date (`YYYY-MM-DD`).
   - **Expiry Date (`expires_at`):** Set the commercial expiration date (`YYYY-MM-DD`).
   - **Warning Days (`warning_days`):** Default `7` (7-day pre-expiry warning banner).
   - **Grace Days (`grace_days`):** Default `7` (7-day post-expiry grace period before full lock).
   - **Activation Limit (`activation_limit`):** `1` (enforces 1 License → 1 Installation binding).
   - **Optional Modules:** Independently check any combination of:
     - `[ ] Form Builder` (`forms`)
     - `[ ] Membership` (`membership`)
     - `[ ] Booking` (`booking`)
3. Submit the form.
4. **Copy the generated License Key immediately.**
   - Central stores only the SHA-256 hash (`license_key_hash`) and short prefix (`license_key_prefix`) in `licensing_licenses`. The plaintext key is displayed **only once** at creation time.

---

## 15. Verify Licensing API

Verify that the public check-in endpoint (`POST /licensing/check`) is reachable over HTTPS and returns a valid HTTP response without exposing internals:

```bash
curl -i -X POST https://licensing.yourdomain.com/licensing/check \
  -H 'Content-Type: application/json' \
  -d '{}'
```

Expected response for an empty/missing payload:
- **HTTP Status:** `400 Bad Request`
- **Body:** `{"error":"invalid_request"}`

Verify that direct web access to internal paths is blocked (`403 Forbidden` or `404 Not Found`):

```bash
curl -I https://licensing.yourdomain.com/.env
curl -I https://licensing.yourdomain.com/data/slate.log
curl -I https://licensing.yourdomain.com/bin/licensing-generate-keys.php
```

---

## 16. Configure Required Cron

Configure the system cron to trigger Central's daily expiry sweep (`LicenseService::sweepExpired()` via `daily_cron` hook in `cron.php`). See `CRON-SETUP.md` for full details.

```cron
*/5 * * * * curl -fsS -H "X-Cron-Key: YOUR_CENTRAL_CRON_SECRET" "https://licensing.yourdomain.com/cron.php" > /dev/null 2>&1
```

---

## 17. Backup / Recovery

### Backing Up Central

1. **Critical Signing Secret & `.env`:**
   - Back up `/var/www/kohevo-licensing/.env` (specifically `APP_SECRET`) to an encrypted offline vault (e.g. 1Password, AWS Secrets Manager, HashiCorp Vault).
   - Back up the `settings` table rows `licensing.signing_public_key` and `licensing.signing_secret_key`.
   - **Warning:** Losing either `APP_SECRET` or `licensing.signing_secret_key` makes it impossible to sign responses verifiable by existing clients' `LICENSE_SERVER_PUBLIC_KEY`.
2. **Nightly Database Backup:**
   - Use the shipped `bin/backup-db.sh` script, which reads DB credentials securely from `.env` via a `0600` temporary `--defaults-extra-file`, streams a consistent `--single-transaction --quick` gzipped dump, verifies the `"Dump completed"` trailer, and prunes dumps older than `BACKUP_KEEP` (default 14 days):
   ```cron
   25 3 * * * BACKUP_DIR=/var/backups/kohevo-central /var/www/kohevo-licensing/bin/backup-db.sh /var/www/kohevo-licensing >> /var/backups/kohevo-central/backup.log 2>&1
   ```

### Restoring Central

1. Restore the `.env` file (preserving the original `APP_SECRET`).
2. Restore the latest gzipped SQL dump into `kohevo_licensing` using `bin/restore-backup.php` or `gunzip < backup.sql.gz | mysql -u kohevo_licensing_user -p kohevo_licensing`.
3. Verify the signing public key matches the key distributed to clients:
   ```bash
   php -r 'require "config.php"; require_once "plugins/licensing/LicensingAPI.php"; echo LicensingAPI::signingPublicKey() . "\n";'
   ```

---

## 18. Security Verification

Before issuing production licenses to customers, verify:
- [ ] `.env` permissions are `0600` and `.env` is unreachable via HTTP (`403` / `404`).
- [ ] `bin/`, `includes/`, `src/`, `db/`, `data/`, `tests/`, `audit/`, `Claude/`, `docs/` return `403` over HTTP.
- [ ] `POST /licensing/check` works over HTTPS with valid TLS.
- [ ] `licensing.signing_public_key` and `licensing.signing_secret_key` are present in `settings` and backed up together with `APP_SECRET`.
- [ ] Only authorized personnel hold Platform Administrator or `licensing.manage` permissions.
