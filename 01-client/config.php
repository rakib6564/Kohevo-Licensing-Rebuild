<?php
/**
 * Slate — central configuration.
 *
 * Loaded by every entry point. Defines constants, loads .env (dev) or
 * environment vars (prod), requires all core includes, starts the
 * session, boots the plugin loader.
 *
 * NEVER hardcode credentials in this file. Use .env locally or set
 * environment variables in your hosting panel. See .env.example.
 */

// ── Version + roots ──────────────────────────────────────────
// Guarded: install.php defines these before requiring config.php,
// so we skip the redefine to avoid a "Constant already defined" warning.
if (!defined('SLATE_VERSION')) define('SLATE_VERSION', '1.6.1');
if (!defined('SLATE_ROOT'))    define('SLATE_ROOT', __DIR__);

// ── Error reporting ──────────────────────────────────────────
// Never expose errors to end users. Log only.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

// ── Load .env (dev only — prod uses env vars set by hosting) ─
if (file_exists(SLATE_ROOT . '/.env')) {
    foreach (file(SLATE_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = '') {
        if (isset($_ENV[$key])) return $_ENV[$key];
        $v = getenv($key);
        return ($v !== false) ? $v : $default;
    }
}

// ── App URL + tenant ─────────────────────────────────────────
define('SLATE_URL', rtrim(env('APP_URL', 'https://greenlightinduction.rakibhasaan.com/slate'), '/'));
// The installer persists the database-resolved tenant ID. Before that point,
// fail closed instead of silently selecting tenant 1.
define('TENANT_ID', (int)env('TENANT_ID', 0));

// ── App secret (encryption + HMAC) ───────────────────────────
define('APP_SECRET', env('APP_SECRET', ''));

// ── Cron secret ──────────────────────────────────────────────
define('CRON_SECRET', env('CRON_SECRET', ''));

// ── MCP gateway kill switch ──────────────────────────────────
// Independent of the plugin's active/inactive status in the `plugins`
// table — see plugins/mcp-gateway/README.md. Off by default; an install
// that never sets this env var never exposes the endpoint, full stop.
define('MCP_GATEWAY_ENABLED', env('MCP_GATEWAY_ENABLED', '0') === '1');

// ── Database ─────────────────────────────────────────────────
define('DB_HOST',    env('DB_HOST',    'localhost'));
// Optional — most installs run MySQL on its default port and never set
// this. Exists for setups (e.g. a non-default local dev MySQL/MariaDB
// instance) that listen elsewhere; empty means "let the driver default apply".
define('DB_PORT',    env('DB_PORT',    ''));
define('DB_NAME',    env('DB_NAME',    'slate'));
define('DB_USER',    env('DB_USER',    'slate'));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// ── Core includes ────────────────────────────────────────────

// -- Slate PSR-4 autoloader (Slate\ -> src/) + backward-compat aliases.
//    Phase 1 A1. ADDITIVE: registers autoloading for new Slate\* classes and,
//    as core classes migrate, bridges their old global names via class_alias so
//    existing code and every plugin keep working. Loaded before the legacy
//    require_once chain so both resolve. See docs/03-Standards/platform-foundation.md §4.
require_once SLATE_ROOT . '/src/autoload.php';
require_once SLATE_ROOT . '/src/compat/aliases.php';

// -- PHPMailer loader
$_pm = __DIR__."/vendor/phpmailer/phpmailer/src";
if (is_dir($_pm)) { foreach (["Exception.php","OAuthTokenProvider.php","PHPMailer.php","SMTP.php"] as $_pmf) { if (file_exists($_pm."/".$_pmf)) require_once $_pm."/".$_pmf; } }
unset($_pm, $_pmf);

require_once SLATE_ROOT . '/includes/helpers.php';

// Framing: every page is same-origin only (clickjacking protection for login/admin). The booking widget relaxes this
// for the sites listed in Settings; the Forms embed sets its own header.
if (PHP_SAPI !== 'cli') slate_send_frame_policy(false);
require_once SLATE_ROOT . '/includes/Database.php';
require_once SLATE_ROOT . '/includes/Hook.php';
require_once SLATE_ROOT . '/includes/Auth.php';
require_once SLATE_ROOT . '/includes/I18n.php';
require_once SLATE_ROOT . '/includes/AuditLog.php';
require_once SLATE_ROOT . '/includes/Notifications.php';
require_once SLATE_ROOT . '/includes/Uploads.php';
require_once SLATE_ROOT . '/includes/Media.php';
require_once SLATE_ROOT . '/includes/Mailer.php';
require_once SLATE_ROOT . '/includes/SmtpOAuth.php';
require_once SLATE_ROOT . '/includes/Plugin.php';
require_once SLATE_ROOT . '/includes/PluginLoader.php';
require_once SLATE_ROOT . '/includes/PublicRouter.php';
require_once SLATE_ROOT . '/includes/ui_components.php';

// ── Force HTTPS (Security setting) ───────────────────────────
// Enforce the `force_https` toggle from admin Settings → Security.
// Skipped on CLI (cron) and pre-install (settings table absent).
if (PHP_SAPI !== 'cli') {
    try {
        if (Database::setting('force_https') === '1') {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
            if (!$isHttps && !empty($_SERVER['HTTP_HOST'])) {
                $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)$_SERVER['HTTP_HOST']);
                header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
                exit;
            }
            if ($isHttps) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
    } catch (\Throwable $e) {
        // Not installed yet, or DB unavailable — don't block the request.
    }
}

// ── Session ──────────────────────────────────────────────────
Auth::startSession();

// ── Media library schema (self-installing on existing sites) ─
// schema.sql only runs at fresh install, so provision the core media
// tables here on first use. Skipped on CLI/pre-install; never blocks.
if (PHP_SAPI !== 'cli') {
    try { Media::ensureSchema(); } catch (\Throwable $e) { /* not installed yet */ }
}

// ── Boot plugins ─────────────────────────────────────────────
// Reads the `plugins` table once, requires each active plugin's
// bootstrap, calls boot(). Inactive plugins are never touched.
PluginLoader::boot();

// ── Global License Guard (Phase 6) ───────────────────────────
// The single mandatory choke point for every entry point that reaches this
// line — docs/02-architecture/06-GLOBAL-LICENSE-GUARD.md §3 Option A.
// Placed after Database/Auth/PluginLoader::boot() are all available, since
// the Guard needs all three (readTrustState() reads the database; the
// whitelisted admin/login.php, admin/logout.php, admin/license.php routes
// still need Auth available to themselves gate on).
// Keep the local license copy in step with the Central Server: a throttled
// check-in that runs after the response is sent (includes/license_sync.php).
// Registered before the Guard so a locked install can still sync itself back.
require_once SLATE_ROOT . '/includes/license_sync.php';
slate_license_sync_schedule();

require_once SLATE_ROOT . '/includes/license_guard.php';
slate_license_guard();

// ── Dynamic notifications ────────────────────────────────────
// Surface key events in the topbar bell. Listeners are registered after
// plugins boot so the actions they fire are caught. Kept defensive so a
// notification failure never breaks the underlying event.
Hook::addAction('stripe_webhook_event', function (array $event): void {
    try {
        $type = (string)($event['type'] ?? '');
        if ($type !== 'checkout.session.completed'
            && $type !== 'payment_intent.succeeded') return;
        $obj    = $event['data']['object'] ?? [];
        $amount = (int)($obj['amount_total'] ?? $obj['amount_received'] ?? $obj['amount'] ?? 0);
        $cur    = strtoupper((string)($obj['currency'] ?? 'USD'));
        $src    = (string)(($obj['metadata']['source_plugin'] ?? '') ?: 'payment');
        Notifications::add('Payment received', [
            'body' => trim(slate_format_price_plain($amount, $cur) . ' · ' . $src),
            'icon' => 'card',
            'url'  => defined('SLATE_URL') ? SLATE_URL . '/admin/' : null,
        ]);
    } catch (\Throwable $e) { /* never break the webhook */ }
});

// ── License expiry sweep (Phase 1E C2) ───────────────────────
// Persists the trial/active -> expired transition once a day. Not the only
// place expiry is detected — LicenseService::effectiveStatus() (used by
// EntitlementService on every access check) already reports it lazily in
// the meantime, so entitlement decisions are never stale between cron
// runs; this just keeps the stored `licenses.status` column itself
// truthful without waiting on the next entitlement check to trigger it.
Hook::addAction('daily_cron', function (): void {
    try {
        \Slate\Services\Licensing\LicenseService::sweepExpired();
    } catch (\Throwable $e) {
        slate_log('License expiry sweep failed: ' . $e->getMessage(), 'error');
    }
});
