<?php
/**
 * Slate — automatic license sync.
 *
 * The Central Server is the source of truth for a license (status, plan,
 * entitlements, expiry). This installation only holds the last signed copy
 * it fetched (remote_license_cache), so a change made on the Central Server
 * — a renewed or extended expiry, a plan change — reaches the client only
 * when the client next checks in. Before this file the only thing that ever
 * did that was bin/license-check.php run by a cron job, and an install
 * without that cron simply never refreshed: the client kept showing the
 * date it was issued with.
 *
 * Now the client keeps itself current without any scheduler:
 *   - config.php calls slate_license_sync_schedule() on every web request;
 *     when the last verified copy is older than the sync interval (15 min
 *     by default) the check-in runs AFTER the response has been sent, so no
 *     visitor waits for it;
 *   - the License page has a "Check for updates now" button
 *     (slate_license_sync_run(true));
 *   - bin/license-check.php and cron.php keep working exactly as before.
 *
 * Nothing about trust changes: the check-in still goes through
 * RemoteLicenseClient — signature verified, installation id matched, the
 * last trusted state left untouched on ANY failure. This file only decides
 * WHEN to ask and never writes license state itself.
 *
 * Env (all optional):
 *   LICENSE_SYNC_INTERVAL   seconds between automatic checks; default 900,
 *                           clamped to 300..86400.
 */

declare(strict_types=1);

if (!defined('SLATE_LICENSE_SYNC_DEFAULT_INTERVAL')) define('SLATE_LICENSE_SYNC_DEFAULT_INTERVAL', 900);
if (!defined('SLATE_LICENSE_SYNC_MIN_INTERVAL'))     define('SLATE_LICENSE_SYNC_MIN_INTERVAL', 300);
if (!defined('SLATE_LICENSE_SYNC_MAX_INTERVAL'))     define('SLATE_LICENSE_SYNC_MAX_INTERVAL', 86400);
/** After a failed attempt, wait this long before trying again. */
if (!defined('SLATE_LICENSE_SYNC_RETRY_SECONDS'))    define('SLATE_LICENSE_SYNC_RETRY_SECONDS', 300);

if (!function_exists('slate_license_sync_interval')) {
    /** Clamp a raw interval (env value or null) to the supported range. */
    function slate_license_sync_interval(mixed $raw = null): int
    {
        if ($raw === null && function_exists('env')) $raw = env('LICENSE_SYNC_INTERVAL', '');
        $seconds = is_numeric($raw) ? (int) $raw : SLATE_LICENSE_SYNC_DEFAULT_INTERVAL;
        return max(SLATE_LICENSE_SYNC_MIN_INTERVAL, min(SLATE_LICENSE_SYNC_MAX_INTERVAL, $seconds));
    }
}

if (!function_exists('slate_license_sync_is_due')) {
    /**
     * Pure timing decision.
     *
     * @param ?int $lastVerified unix time of the last verified copy (null = never / untrusted)
     * @param ?int $lastAttempt  unix time of the last attempt, successful or not
     */
    function slate_license_sync_is_due(?int $lastVerified, ?int $lastAttempt, int $now, int $interval): bool
    {
        // A recent attempt (even a failed one) holds everyone else off, so a
        // slow or unreachable license server is never hammered by traffic.
        if ($lastAttempt !== null && $now - $lastAttempt < min($interval, SLATE_LICENSE_SYNC_RETRY_SECONDS)) {
            return false;
        }
        if ($lastVerified === null) return true;
        return $now - $lastVerified >= $interval;
    }
}

if (!function_exists('slate_license_sync_configured')) {
    /** True when this install has everything a check-in needs. */
    function slate_license_sync_configured(): bool
    {
        return env('LICENSE_SERVER_URL', '') !== '' && env('LICENSE_SERVER_PUBLIC_KEY', '') !== ''
            && env('LICENSE_PRODUCT', '') !== '' && env('LICENSE_KEY', '') !== '';
    }
}

if (!function_exists('slate_license_sync_run')) {
    /**
     * Run one check-in now (or only if due, when $force is false).
     *
     * status: not_configured | not_due | synced | failed
     * `reason` is the client's coarse split (network | rejected); `failure`
     * is the stable diagnostic category, for logs only.
     *
     * @return array{status:string, ok:bool, reason:?string, failure:?string}
     */
    function slate_license_sync_run(bool $force = false, ?callable $transport = null): array
    {
        $result = static fn(string $status, bool $ok = false, ?string $reason = null, ?string $failure = null): array
            => ['status' => $status, 'ok' => $ok, 'reason' => $reason, 'failure' => $failure];

        if (!slate_license_sync_configured()) return $result('not_configured');

        require_once SLATE_ROOT . '/includes/installer_flow.php';
        require_once SLATE_ROOT . '/plugins/licensing/client/RemoteLicenseClient.php';

        $installId = \Slate\Services\Installation\InstallationService::currentInstallationId();
        if ($installId === null) return $result('not_configured');

        $store = new \Slate\Services\Licensing\SlateLicenseCacheStore((int) TENANT_ID);
        $before = $store->readTrustState();
        $beforeData = $before['trusted'] ? $before['data'] : null;

        if (!$force) {
            $verified = $beforeData !== null ? strtotime((string) ($beforeData['fetched_at'] ?? '') . ' UTC') : null;
            $attempt  = Database::setting('license_sync.last_attempt');
            $attempt  = is_numeric($attempt) ? (int) $attempt : null;
            if (!slate_license_sync_is_due($verified ?: null, $attempt, time(), slate_license_sync_interval())) {
                return $result('not_due');
            }
        }
        Database::setSetting('license_sync.last_attempt', (string) time());

        $client = new RemoteLicenseClient([
            'server_url'  => (string) env('LICENSE_SERVER_URL', ''),
            'public_key'  => (string) env('LICENSE_SERVER_PUBLIC_KEY', ''),
            'product'     => (string) env('LICENSE_PRODUCT', ''),
            'license_key' => (string) env('LICENSE_KEY', ''),
            'install_id'  => $installId,
            'domain'      => installer_domain_from_url(SLATE_URL),
            'app_version' => SLATE_VERSION,
        ], $store, $transport);

        $detail = $client->checkInDetailed();
        if (!$detail['ok']) {
            slate_log('License sync failed: ' . ($client->lastFailure() ?? 'unknown'), 'warning');
            return $result('failed', false, $detail['reason'], $client->lastFailure());
        }

        $after = $store->load();
        if ($after !== null && ($beforeData === null
            || ($beforeData['expires_at'] ?? null) !== ($after['expires_at'] ?? null)
            || ($beforeData['status'] ?? null) !== ($after['status'] ?? null)
            || ($beforeData['plan'] ?? null) !== ($after['plan'] ?? null))) {
            slate_log(sprintf('License updated from the license server: status=%s plan=%s expires_at=%s',
                (string) ($after['status'] ?? ''), (string) ($after['plan'] ?? ''), (string) ($after['expires_at'] ?? '')), 'info');
        }
        return $result('synced', true);
    }
}

if (!function_exists('slate_license_sync_schedule')) {
    /**
     * Called once per web request from config.php, BEFORE the license guard
     * (a locked install must be able to sync its way back to unlocked, and a
     * shutdown function still runs after the guard exits). Costs one
     * function registration; every decision — including the database reads —
     * happens after the response is out.
     */
    function slate_license_sync_schedule(): void
    {
        if (PHP_SAPI === 'cli') return;
        if (defined('SLATE_TESTING') || defined('SLATE_MIGRATING') || env('APP_ENV') === 'testing') return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        if (!slate_license_sync_configured()) return;

        $detach = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request'
            : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
        // Without a way to close the connection early the check-in would make
        // the visitor wait, so it only rides on admin page loads then.
        if ($detach === null && !str_starts_with(slate_license_guard_current_path_safe(), 'admin/')) return;

        register_shutdown_function(static function () use ($detach): void {
            try {
                // Don't hold the session lock while talking to the network.
                if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) session_write_close();
                if ($detach !== null) $detach();
                ignore_user_abort(true);
                @set_time_limit(30);
                slate_license_sync_run(false);
            } catch (\Throwable $e) {
                // Never let a sync problem surface to (or break) a request.
            }
        });
    }
}

if (!function_exists('slate_license_guard_current_path_safe')) {
    /** Lower-cased script path relative to the app root, without needing the guard loaded. */
    function slate_license_guard_current_path_safe(): string
    {
        $path = strtolower(ltrim((string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH), '/'));
        $base = strtolower(trim((string) parse_url(defined('SLATE_URL') ? SLATE_URL : '', PHP_URL_PATH), '/'));
        return ($base !== '' && str_starts_with($path, $base . '/')) ? substr($path, strlen($base) + 1) : $path;
    }
}
