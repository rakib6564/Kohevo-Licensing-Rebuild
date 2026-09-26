# Nginx Web Server Configuration Guide (`NGINX-SETUP.md`)

Nginx does not process `.htaccess` files. When deploying the **Central Licensing Server** or **Client Application** on Nginx 1.18+, you must include the explicit `location` security blocks and rewrite rules shown below in your Nginx server configuration.

---

## 1. Central Licensing Server Nginx Configuration

Create `/etc/nginx/sites-available/kohevo-licensing.conf`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name licensing.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name licensing.yourdomain.com;

    root /var/www/kohevo-licensing;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/licensing.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/licensing.yourdomain.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    disable_symlinks off;
    autoindex off;

    # 1. Block all dotfiles (.env, .installed, .git, .htaccess, etc.) except .well-known
    location ~ /\.(?!well-known).* {
        deny all;
        return 403;
    }

    # 2. Block sensitive root files and internal directories
    location ~ ^/(\.env|\.installed|\.git|data|db_backups|includes|src|bin|db|tests|audit|docs|Claude|uploads/_plugin_staging|composer\.(json|lock)|Makefile|AUDIT\.md|SECURITY\.md|INSTALL\.md|dev-server\.php)(/|$) {
        deny all;
        return 403;
    }

    # 3. Route public license check-in endpoint to public.php
    location = /licensing/check {
        try_files $uri /public.php?_path=licensing/check&$args;
    }

    # 4. Serve existing files or route clean URLs to public.php
    location / {
        try_files $uri $uri/ /public.php?_path=$uri&$args;
    }

    # 5. Pass PHP scripts to PHP-FPM
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## 2. Client Application Nginx Configuration

Create `/etc/nginx/sites-available/kohevo-client.conf`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name app.clientdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name app.clientdomain.com;

    root /var/www/kohevo-client;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/app.clientdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.clientdomain.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    autoindex off;

    # 1. Block all dotfiles (.env, .installed, .git, .htaccess, etc.) except .well-known
    location ~ /\.(?!well-known).* {
        deny all;
        return 403;
    }

    # 2. Block sensitive root files and internal directories
    location ~ ^/(\.env|\.installed|\.git|data|db_backups|includes|src|bin|db|tests|audit|docs|Claude|uploads/_plugin_staging|composer\.(json|lock)|Makefile|AUDIT\.md|SECURITY\.md|INSTALL\.md|dev-server\.php)(/|$) {
        deny all;
        return 403;
    }

    # 3. Serve existing files or route public module URLs (/book, /forms/*, /membership/*) to public.php
    location / {
        try_files $uri $uri/ /public.php?_path=$uri&$args;
    }

    # 4. Pass PHP scripts to PHP-FPM
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

> [!NOTE]
> Adjust `fastcgi_pass unix:/run/php/php8.3-fpm.sock;` to match the PHP-FPM socket path on your server (e.g. `php8.1-fpm.sock`, `php8.2-fpm.sock`, `php8.3-fpm.sock`, or `127.0.0.1:9000`).

---

## 3. Protected Paths Summary

Both Nginx configurations above explicitly deny (`403 Forbidden`) requests to:
- `.env`
- `.installed`
- `.git/`
- `data/` (including `data/slate.log` and cron logs)
- `db_backups/`
- `audit/`
- `Claude/`
- `includes/`
- `src/`
- `bin/`
- `db/`
- `tests/`
- `docs/`
- `dev-server.php`, `Makefile`, `composer.json`, `AUDIT.md`, `SECURITY.md`, `INSTALL.md`

---

## 4. Reload & Verify Nginx

Test the configuration syntax and reload Nginx:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Verify with `curl` that all sensitive paths return `403` and that `POST /licensing/check` on Central routes to `public.php`:

```bash
curl -I https://licensing.yourdomain.com/.env
curl -I https://licensing.yourdomain.com/data/slate.log
curl -I https://licensing.yourdomain.com/bin/licensing-generate-keys.php
curl -i -X POST https://licensing.yourdomain.com/licensing/check -H 'Content-Type: application/json' -d '{}'
```
