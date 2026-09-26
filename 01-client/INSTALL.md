# Kohevo Licensing Rebuild — Client Application Production Deployment Guide

This guide describes how to deploy, configure, and operate the Kohevo Client Application (`01-client`).

---

## 1. System Requirements

- **PHP**: PHP 8.1+ (tested on PHP 8.3, 8.5)
- **Extensions**: `pdo_mysql`, `sodium` (or `paragonie/sodium_compat`), `curl`, `json`, `mbstring`, `openssl`
- **Database**: MySQL 8.0+ or MariaDB 10.11+
- **Web Server**: Apache 2.4+ (with `mod_rewrite` enabled) or Nginx 1.18+

---

## 2. Environment Configuration (`.env`)

Copy `.env.example` to `.env` in the root of `01-client/`:

```bash
cp .env.example .env
```

Set appropriate production parameters in `.env`:

```ini
APP_URL=https://app.yourdomain.com
TENANT_ID=1
APP_SECRET=use-a-strong-random-32-byte-secret
CRON_SECRET=use-a-strong-random-cron-secret

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=kohevo_client
DB_USER=kohevo_user
DB_PASS=your-secure-db-password
DB_CHARSET=utf8mb4

# Central Licensing Server Configuration
LICENSE_SERVER_URL=https://licensing.yourdomain.com
LICENSE_SERVER_PUBLIC_KEY=<Base64 Ed25519 Public Key from Central Licensing Server>
LICENSE_PRODUCT=kohevo
LICENSE_KEY=KOH-XXXX-XXXX-XXXX-XXXX
```

> [!IMPORTANT]
> Never commit `.env` or `.installed` to source control. Ensure permissions are restricted (`chmod 600 .env`).

---

## 3. Database Migration & Initialization

Run database migrations via the CLI tool:

```bash
php bin/migrate migrate
```

For fresh installations, complete the installation wizard at `/install.php` or activate via CLI. The installer will bind your `LICENSE_KEY` with the Central Licensing Server, initialize the database schema, create the administrator account, and generate the `.installed` file.

---

## 4. Web Server Configuration

### Apache (`mod_rewrite`)

Ensure `AllowOverride All` is set in Apache configuration for your document root so the shipped `.htaccess` file is processed.

The shipped `.htaccess` automatically:
1. Blocks direct access to `.env`, `.installed`, `.git`, `data/`, `db_backups/`, `includes/`, `src/`, `bin/`, `tests/`, `audit/`, `docs/`.
2. Serves static files directly.
3. Routes public module URLs (`/book`, `/forms/*`, `/membership/*`) to `public.php`.

### Nginx

Example Nginx server block:

```nginx
server {
    listen 443 ssl http2;
    server_name app.yourdomain.com;
    root /var/www/kohevo-client;
    index index.php;

    # Block direct access to sensitive files and internal directories
    location ~ ^/(\.env|\.installed|\.git|data|db_backups|includes|src|bin|db|tests|audit|docs|Claude) {
        deny all;
        return 403;
    }

    # Static assets
    location / {
        try_files $uri $uri/ /public.php?_path=$uri&$args;
    }

    # PHP-FPM dispatch
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## 5. Automated License Check-In (Cron)

Set up a daily cron job to run `bin/license-check.php` unattended. This synchronizes signed license status, entitlements, and renewal windows from the Central Licensing Server.

Example crontab entry (runs daily at 02:00 AM):

```cron
0 2 * * * /usr/bin/php /var/www/kohevo-client/bin/license-check.php >> /var/www/kohevo-client/data/cron-license.log 2>&1
```

The script cleanly no-ops if remote licensing is not yet configured, and fails safe (preserving previous verified state) during temporary network outages.

---

## 6. Backup & Disaster Recovery

- **Database Backup**: Regularly dump the MySQL database (`mysqldump -u kohevo_user -p kohevo_client > backup.sql`).
- **Secrets Backup**: Safely backup `.env` and `APP_SECRET`.
- **Database Restore**: If restoring the database, run `php bin/license-check.php` to refresh the signed license cache.
