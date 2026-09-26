# Cron Setup Guide (`CRON-SETUP.md`)

This document describes the actual scheduled background jobs (`cron`) implemented in the **Kohevo Client Application** (`01-client`) and **Kohevo Central Licensing Server** (`02-licensing`).

---

## 1. Client Application Cron Jobs

The Client Application uses two cron mechanisms:

### 1.1 Unattended Daily License Check-In (`bin/license-check.php`) — REQUIRED

`bin/license-check.php` is a CLI-only script (`if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`) that synchronizes the installation's signed license state, module entitlements, and expiration window with the Central Licensing Server (`POST /licensing/check`).

- **Command:**
  ```bash
  php bin/license-check.php
  ```
- **Recommended Schedule:** Once daily (matching the Central Server's `next_check_after = 86400` seconds and keeping the 7-day / `604800`-second offline tolerance window fresh).
- **Recommended Crontab Entry (runs daily at 02:00 AM server time):**
  ```cron
  0 2 * * * /usr/bin/php /var/www/kohevo-client/bin/license-check.php >> /var/www/kohevo-client/data/cron-license.log 2>&1
  ```
- **Behavior & Safety Guarantees:**
  - Reads `LICENSE_SERVER_URL`, `LICENSE_SERVER_PUBLIC_KEY`, `LICENSE_PRODUCT`, and `LICENSE_KEY` from `.env` (or environment variables), and `installation_id` from the `installation_identity` table.
  - If remote licensing is not yet configured, it cleanly outputs `Remote licensing is not configured for this install -- skipping.` and exits `0`.
  - On success, it verifies the Ed25519 signature, updates `remote_license_cache`, logs `License check-in succeeded: status=<status>` via `slate_log()`, and outputs `Check-in succeeded -- local cache updated.`.
  - On transient network or server failure, it logs a warning (`License check-in failed: <reason>`) and leaves the last verified signed state untouched in `remote_license_cache` so the 7-day offline availability tolerance remains intact.
  - On authoritative central status transitions (`suspended`, `revoked`, `expired`, or renewed `active`), the signed response updates `remote_license_cache` immediately.

### 1.2 Application Web Cron (`cron.php`) — OPTIONAL / MODULE TASKS

`cron.php` is an HTTP entry point authenticated via `CRON_SECRET` that fires two hook channels:
- `frequent_cron` — fired on every invocation (used by chunked background tasks such as Google Calendar sync and Google Drive backup state machine in `plugins/backups/BackupRunner.php`).
- `daily_cron` — fired once per UTC day (gated by `settings.cron_last_daily`; runs `Slate\Services\Licensing\LicenseService::sweepExpired()` and daily plugin hooks).

- **Recommended Schedule:** Every 5 minutes.
- **Recommended Crontab Entry (using `X-Cron-Key` header so `CRON_SECRET` never appears in URL access logs):**
  ```cron
  */5 * * * * curl -fsS -H 'X-Cron-Key: YOUR_CLIENT_CRON_SECRET' 'https://app.clientdomain.com/cron.php' > /dev/null 2>&1
  ```
- **Fallback Query Parameter (if your hosting panel scheduler cannot send custom headers):**
  ```cron
  */5 * * * * curl -fsS 'https://app.clientdomain.com/cron.php?key=YOUR_CLIENT_CRON_SECRET' > /dev/null 2>&1
  ```
- **Rate Limiting:** `cron.php` enforces built-in rate limits via `login_attempts` (`scope = 'cron'`): maximum 30 attempts per 10 minutes per IP and 200 attempts per hour globally.

### 1.3 Nightly Database Backup (`bin/backup-db.sh`) — RECOMMENDED

- **Command:**
  ```bash
  /var/www/kohevo-client/bin/backup-db.sh /var/www/kohevo-client
  ```
- **Recommended Crontab Entry (nightly at 03:25 AM):**
  ```cron
  25 3 * * * BACKUP_DIR=/var/backups/kohevo-client /var/www/kohevo-client/bin/backup-db.sh /var/www/kohevo-client >> /var/backups/kohevo-client/backup.log 2>&1
  ```

---

## 2. Central Licensing Server Cron Jobs

The Central Licensing Server evaluates license expiration lazily on every check-in (`LicenseService::effectiveStatus()`) and when viewing a license in the admin UI (`LicenseService::syncExpiry()`). In addition, Central provides two scheduled jobs to persist background expiry transitions and database backups:

### 2.1 Central Cron Endpoint (`cron.php`) — DAILY EXPIRY SWEEP

When `cron.php` is called on the Central Licensing Server, the `daily_cron` hook invokes:
- `Licensing::sweepExpiredLicenses()` → `LicenseService::sweepExpired()` (transitions any `active` or `trial` commercial license in `licensing_licenses` whose `expires_at` has passed to `status = 'expired'` and records an `expire` event in `licensing_license_events`).
- `\Slate\Services\Licensing\LicenseService::sweepExpired()`.

- **Recommended Crontab Entry (every 5 minutes or once daily):**
  ```cron
  */5 * * * * curl -fsS -H 'X-Cron-Key: YOUR_CENTRAL_CRON_SECRET' 'https://licensing.yourdomain.com/cron.php' > /dev/null 2>&1
  ```
  *(Or once daily at 01:00 UTC)*:
  ```cron
  0 1 * * * curl -fsS -H 'X-Cron-Key: YOUR_CENTRAL_CRON_SECRET' 'https://licensing.yourdomain.com/cron.php' > /dev/null 2>&1
  ```
- **Expected JSON Output:**
  ```json
  {"ok":true,"fired":["frequent_cron","daily_cron"],"duration_ms":12,"now":"2026-09-27T01:00:00+00:00"}
  ```

### 2.2 Central Nightly Database Backup (`bin/backup-db.sh`) — RECOMMENDED

- **Command:**
  ```bash
  /var/www/kohevo-licensing/bin/backup-db.sh /var/www/kohevo-licensing
  ```
- **Recommended Crontab Entry (nightly at 03:25 AM):**
  ```cron
  25 3 * * * BACKUP_DIR=/var/backups/kohevo-central /var/www/kohevo-licensing/bin/backup-db.sh /var/www/kohevo-licensing >> /var/backups/kohevo-central/backup.log 2>&1
  ```
