# Kohevo Licensing Rebuild — Central Licensing Server Production Deployment Guide

This guide describes how to deploy, configure, and operate the Kohevo Central Licensing Server (`02-licensing`).

---

## 1. System Requirements

- **PHP**: PHP 8.2+ (CI runs PHP 8.3; also verified on 8.5). The code uses `readonly class`, so 8.1 cannot run it.
- **Extensions**: `pdo_mysql`, `mbstring`, `curl`, `json`, `openssl`, `sodium`
  (`mbstring` is used throughout; `sodium` does the Ed25519 licence verification — without it the check degrades to a
  keyed-hash comparison that is not a real signature check, so treat it as required in production)
- **Database**: MySQL 8.0+ or MariaDB 10.11+
- **Web Server**: Apache 2.4+ (with `mod_rewrite` enabled) or Nginx 1.18+

---

## 2. Environment Configuration (`.env`)

Copy `.env.example` to `.env` in the root of `02-licensing/`:

```bash
cp .env.example .env
```

Set appropriate production parameters in `.env`:

```ini
APP_URL=https://licensing.yourdomain.com
TENANT_ID=1
APP_SECRET=use-a-strong-random-32-byte-secret
CRON_SECRET=use-a-strong-random-cron-secret

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=kohevo_licensing
DB_USER=kohevo_licensing_user
DB_PASS=your-secure-db-password
DB_CHARSET=utf8mb4
```

> [!IMPORTANT]
> Never commit `.env` or `.installed` to source control. Ensure permissions are restricted (`chmod 600 .env`).

---

## 3. Database Migration & Key Generation

1. Run database migrations via the CLI tool:

```bash
php bin/migrate migrate
```

2. Generate the central Ed25519 signing keypair:

```bash
php bin/licensing-generate-keys.php
```

3. Export the public key for client applications:

```bash
php -r 'require "config.php"; echo LicensingAPI::signingPublicKey() . "\n";'
```

Copy the generated Ed25519 public key string into the client application's `LICENSE_SERVER_PUBLIC_KEY` environment variable.

---

## 4. Web Server Configuration

### Apache (`mod_rewrite`)

Ensure `AllowOverride All` is set in Apache configuration for your document root so the shipped `.htaccess` file is processed.

The shipped `.htaccess` automatically:
1. Directs `/licensing/check` POST requests to `public.php`.
2. Blocks direct access to `.env`, `.installed`, `.git`, `data/`, `db_backups/`, `includes/`, `src/`, `bin/`, `tests/`, `audit/`, `docs/`.
3. Serves static files and admin interfaces directly.

### Nginx

Example Nginx server block:

```nginx
server {
    listen 443 ssl http2;
    server_name licensing.yourdomain.com;
    root /var/www/kohevo-licensing;
    index index.php;

    # Block direct access to sensitive files and internal directories
    location ~ ^/(\.env|\.installed|\.git|data|db_backups|includes|src|bin|db|tests|audit|docs|Claude)(/|$) {
        deny all;
        return 403;
    }

    # Public check-in route dispatch
    location /licensing/check {
        try_files $uri /public.php?_path=licensing/check&$args;
    }

    # Static assets and admin routes
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

## 5. Central Maintenance & Expiry Sweeps

The central licensing server performs lazy status checks during check-in. Optional daily background sweeps can also be scheduled via cron or `cron.php`:

```cron
0 3 * * * curl -fsS -H 'X-Cron-Key: YOUR_CRON_SECRET' 'https://licensing.example.com/cron.php' >> /var/www/kohevo-licensing/data/cron-sweep.log 2>&1
```

---

## 6. Backup & Disaster Recovery

- **Central Key Backup**: Securely back up `licensing.signing_public_key` and `licensing.signing_secret_key` stored in the `settings` table, as well as `APP_SECRET`. Loss of the signing secret key invalidates all signed state cache signatures across all clients.
- **Database Backup**: Dump the MySQL database regularly (`mysqldump -u kohevo_licensing_user -p kohevo_licensing > central_backup.sql`).
