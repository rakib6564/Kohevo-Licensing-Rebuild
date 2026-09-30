# Apache Web Server Configuration Guide (`APACHE-SETUP.md`)

This document details how to deploy the **Central Licensing Server** and **Client Application** on Apache 2.4+ (or LiteSpeed Web Server) and verifies the shipped `.htaccess` protection rules.

---

## 1. Required Apache Modules & Directives

Enable the required Apache modules:

```bash
sudo a2enmod rewrite headers ssl
sudo systemctl reload apache2
```

> [!IMPORTANT]
> **`AllowOverride All` is mandatory** for both the Central and Client `<Directory>` blocks so Apache processes the root `.htaccess` and directory-level `.htaccess` files. Without `AllowOverride All`, rewrite rules (`/licensing/check` and `public.php`) will not run and `.htaccess` access blocks will be ignored!

---

## 2. Central Licensing Server Apache VirtualHost

Example `/etc/apache2/sites-available/kohevo-licensing.conf`:

```apache
<VirtualHost *:80>
    ServerName licensing.yourdomain.com
    Redirect permanent / https://licensing.yourdomain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName licensing.yourdomain.com
    DocumentRoot /var/www/kohevo-licensing

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/licensing.yourdomain.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/licensing.yourdomain.com/privkey.pem

    <Directory /var/www/kohevo-licensing>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Additional server-level defense-in-depth for sensitive paths
    <DirectoryMatch "^/var/www/kohevo-licensing/(\.git|data|db_backups|includes|src|bin|db|tests|audit|docs|Claude)">
        Require all denied
    </DirectoryMatch>

    <FilesMatch "^(\.env|\.installed|composer\.(json|lock)|Makefile|AUDIT\.md|SECURITY\.md|INSTALL\.md)$">
        Require all denied
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/kohevo-licensing-error.log
    CustomLog ${APACHE_LOG_DIR}/kohevo-licensing-access.log combined
</VirtualHost>
```

### Shipped `02-licensing/.htaccess`

The root `.htaccess` included in `kohevo-licensing-central-production.zip`:

```apache
# Slate / Kohevo Central Licensing Server Web Server Security & Routing Configuration

# Disable Directory Browsing
Options -Indexes

# Block direct access to sensitive files and directories
<FilesMatch "^(\.env|\.installed|\.git|composer\.(json|lock)|Makefile|AUDIT\.md|SECURITY\.md|INSTALL\.md)">
    Require all denied
</FilesMatch>

# Block access to internal directories
RedirectMatch 403 ^/(data|db_backups|includes|src|bin|db|tests|audit|docs|Claude)/

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Block hidden files/folders, except /.well-known/ (ACME / Let's Encrypt
    # HTTP-01 certificate renewal)
    RewriteCond %{REQUEST_URI} !/\.well-known/
    RewriteRule "(^|/)\." - [F]

    # Route /licensing/check directly to public.php
    RewriteRule ^licensing/check$ public.php?_path=licensing/check [QSA,L]

    # Serve existing files and directories directly
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Legacy shop storefront routing
    RewriteRule ^shop(/.*)?$ plugins/shop/storefront/router.php [QSA,L]

    # Headless API: /api/v1/... -> api/v1.php, as in the original rules. Routed
    # through public.php instead, a locked install answers API callers with the
    # HTML lock page rather than the JSON LICENSE_INACTIVE error.
    RewriteRule ^api/v1/?$ api/v1.php?_route_path= [QSA,L]
    RewriteRule ^api/v1/(.+)$ api/v1.php?_route_path=$1 [QSA,L]

    # Route all other non-file requests to public.php router
    RewriteRule ^(.*)$ public.php?_path=$1 [QSA,L]
</IfModule>
```

---

## 3. Client Application Apache VirtualHost

Example `/etc/apache2/sites-available/kohevo-client.conf`:

```apache
<VirtualHost *:80>
    ServerName app.clientdomain.com
    Redirect permanent / https://app.clientdomain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName app.clientdomain.com
    DocumentRoot /var/www/kohevo-client

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/app.clientdomain.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/app.clientdomain.com/privkey.pem

    <Directory /var/www/kohevo-client>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Additional server-level defense-in-depth for sensitive paths
    <DirectoryMatch "^/var/www/kohevo-client/(\.git|data|db_backups|includes|src|bin|db|tests|audit|docs|Claude)">
        Require all denied
    </DirectoryMatch>

    <FilesMatch "^(\.env|\.installed|composer\.(json|lock)|Makefile|AUDIT\.md|SECURITY\.md|INSTALL\.md)$">
        Require all denied
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/kohevo-client-error.log
    CustomLog ${APACHE_LOG_DIR}/kohevo-client-access.log combined
</VirtualHost>
```

### Shipped `01-client/.htaccess`

The root `.htaccess` included in `kohevo-client-production.zip`:

```apache
# Slate / Kohevo Client Web Server Security & Routing Configuration

# Disable Directory Browsing
Options -Indexes

# Block direct access to sensitive files and directories
<FilesMatch "^(\.env|\.installed|\.git|composer\.(json|lock)|Makefile|AUDIT\.md|SECURITY\.md|INSTALL\.md)">
    Require all denied
</FilesMatch>

# Block access to internal directories
RedirectMatch 403 ^/(data|db_backups|includes|src|bin|db|tests|audit|docs|Claude)/

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Block hidden files/folders, except /.well-known/ (ACME / Let's Encrypt
    # HTTP-01 certificate renewal)
    RewriteCond %{REQUEST_URI} !/\.well-known/
    RewriteRule "(^|/)\." - [F]

    # Serve existing files and directories directly
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Legacy shop storefront routing
    RewriteRule ^shop(/.*)?$ plugins/shop/storefront/router.php [QSA,L]

    # Headless API: /api/v1/... -> api/v1.php, as in the original rules. Routed
    # through public.php instead, a locked install answers API callers with the
    # HTML lock page rather than the JSON LICENSE_INACTIVE error.
    RewriteRule ^api/v1/?$ api/v1.php?_route_path= [QSA,L]
    RewriteRule ^api/v1/(.+)$ api/v1.php?_route_path=$1 [QSA,L]

    # Route all other non-file requests to public.php router
    RewriteRule ^(.*)$ public.php?_path=$1 [QSA,L]
</IfModule>
```

`/robots.txt` and `/sitemap.xml` reach Kohevo Studio through that last rule (a tenant's generated files). They are only
answered while no real file of that name exists in the document root, so do not ship static copies.

---

## 4. Subdirectory `.htaccess` Defense-in-Depth

In addition to the root `.htaccess`, both packages ship with dedicated `.htaccess` files inside internal directories (`Require all denied` / `Deny from all`):

| Path | Central Package | Client Package | Protection |
| :--- | :---: | :---: | :--- |
| `.htaccess` (root) | Yes | Yes | Blocks `.env`, `.installed`, `.git`, dotfiles, and internal directories; routes clean URLs |
| `data/.htaccess` | Yes | Yes | `Require all denied` / `Deny from all` |
| `db/.htaccess` | Yes | Yes | Denies direct web access to `schema.sql` and `migrations/` |
| `includes/.htaccess` | Yes | Yes | Denies direct web access to PHP includes |
| `src/.htaccess` | Yes | Yes | Denies direct web access to PSR-4 classes |
| `bin/.htaccess` | Yes | Yes | Denies direct web access to CLI utilities |
| `tests/.htaccess` | Yes | Yes | Denies direct web access to test runners |
| `audit/.htaccess` | Yes | Yes | Denies direct web access to audit directory |
| `Claude/.htaccess` | Yes | Yes | Denies direct web access to internal engineering notes |
| `plugins/studio-builder/ui/.htaccess` | n/a | Yes | Denies direct web access to the Studio builder UI sources and dev tooling (`package.json`, `src/`, `tests/`); only `plugins/studio-builder/assets/` is public. Nginx needs the matching `location` rule in `NGINX-SETUP.md` |
| `docs/.htaccess` | Yes | *(covered by root `RedirectMatch 403`)* | Denies direct web access to documentation directory |
| `db_backups/.htaccess` | *(auto-created by `BackupRunner.php` if backups run)* | *(auto-created by `BackupRunner.php` if backups run)* | Covered by root `RedirectMatch 403 ^/(...|db_backups|...)/` and `BackupRunner::localDir()` |

---

## 5. Verification Commands

After enabling the Apache VirtualHost, run these checks against both servers to confirm `403 Forbidden` is returned for all sensitive paths:

```bash
for p in .env .installed .git/config data/slate.log db_backups/ include/ includes/Database.php src/autoload.php bin/migrate db/schema.sql tests/smoke.php audit/findings.md Claude/ docs/; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "https://app.clientdomain.com/$p")
  echo "$p -> HTTP $code"
done
```

Every path above must return `403` (or `404` if non-existent).
