<?php
/**
 * Slate — shared helpers.
 *
 * CSRF, escaping, tenant resolution, secret encryption, version compare.
 * Everything here is small, stateless, and used by the shell and by
 * every plugin. Wrapped in function_exists guards so test bootstraps
 * can stub them without fataling.
 */

// ── HTML escape ───────────────────────────────────────────────
if (!function_exists('e')) {
    function e($s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// ── Current tenant resolution ─────────────────────────────────
if (!function_exists('current_tenant_id')) {
    function current_tenant_id(): int {
        // CLI / cron override
        if (!empty($GLOBALS['SLATE_TENANT_OVERRIDE'])) {
            return (int)$GLOBALS['SLATE_TENANT_OVERRIDE'];
        }
        // Platform admin acting as a tenant — crossing the tenant boundary
        // itself is genuinely platform-level, gated by the dedicated concept
        // rather than the generic isSuperAdmin() bypass. See Phase 0E/0G
        // RBAC audit. (Nothing currently writes slate_override_tenant — this
        // branch is a prepared, unwired mechanism.)
        //
        // Phase 0H: Auth::check() validates the session via SessionRepository,
        // whose query is tenant-scoped through a fresh TenantContext — i.e.
        // through THIS function. Calling it here unguarded recurses forever:
        // current_tenant_id() -> Auth::check() -> SessionRepository ->
        // TenantContext::id() -> current_tenant_id() -> ... (this branch is
        // still true, nothing has changed $_SESSION). Fix: pin the nested
        // resolution to the session's OWN home tenant — recorded at login in
        // $_SESSION['slate_user']['tenant_id'], never the tenant being
        // requested via the override — for the duration of the check, via
        // TenantContext::runAs(). runAs() sets the CLI-override global that
        // the very first branch above returns from immediately, so the
        // nested call never reaches this branch again. If the session has no
        // usable home tenant, the override is denied (fail closed) rather
        // than risking the same recursion.
        if (!empty($_SESSION['slate_override_tenant']) && class_exists('Auth')) {
            $homeTenant = (int)($_SESSION['slate_user']['tenant_id'] ?? 0);
            if ($homeTenant > 0) {
                $authorized = (new \Slate\Tenancy\TenantContext())->runAs($homeTenant, static function (): bool {
                    return Auth::check() && Auth::isPlatformSuperAdmin();
                });
                if ($authorized) {
                    return (int)$_SESSION['slate_override_tenant'];
                }
            }
        }
        // Fallback: tenant 1 (default single-tenant install)
        return defined('TENANT_ID') ? TENANT_ID : 1;
    }
}

if (!function_exists('with_tenant')) {
    /**
     * Run a callback as a specific tenant. Used by cron sweeps.
     */
    function with_tenant(int $tenantId, callable $fn) {
        $previous = $GLOBALS['SLATE_TENANT_OVERRIDE'] ?? null;
        $GLOBALS['SLATE_TENANT_OVERRIDE'] = $tenantId;
        try {
            return $fn();
        } finally {
            if ($previous === null) {
                unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
            } else {
                $GLOBALS['SLATE_TENANT_OVERRIDE'] = $previous;
            }
        }
    }
}

// ── Tenant qualification (CORE-1) ─────────────────────────────
// current_tenant_id() answers "who am I". These answer "how do I scope a
// query", which is a different question with a different failure mode: get
// the first wrong and one request misbehaves, get the second wrong and one
// tenant reads another tenant's rows.
//
// Today the two are the same value, because tenancy resolves from the
// TENANT_ID constant and one deployment serves one tenant. That changes with
// multi-tenant single-install, where the tenant comes from the request. When
// it does, THIS is the seam that changes — not two hundred call sites — which
// is the whole point of routing through it now, while the answer is boring.
//
// The CI guard (bin/anti-drift.php, rule TENANT) fails the build when a
// query touches a tenant-scoped table without naming tenant_id.
if (!function_exists('slate_tenant_id')) {
    /**
     * The tenant a query must be scoped to.
     *
     * Use this — not current_tenant_id() — for the value you bind into a
     * tenant_id placeholder. They agree today and are documented to diverge.
     */
    function slate_tenant_id(): int {
        return current_tenant_id();
    }
}

if (!function_exists('slate_tenant_clause')) {
    /**
     * The SQL fragment that scopes a query, optionally table-aliased.
     *
     *   slate_tenant_clause()     → "tenant_id = ?"
     *   slate_tenant_clause('a')  → "a.tenant_id = ?"
     *
     * Bind slate_tenant_id() into the placeholder it creates. Building the
     * fragment here means the day tenancy gains a second dimension (a site id,
     * an environment) every query that used this picks it up.
     */
    function slate_tenant_clause(?string $alias = null): string {
        $prefix = ($alias !== null && $alias !== '') ? $alias . '.' : '';
        return $prefix . 'tenant_id = ?';
    }
}

// ── APP_SECRET access (CORE-1) ────────────────────────────────
// APP_SECRET is ALWAYS defined — config.php:49 defines it to '' when unset —
// so `defined('APP_SECRET') ? APP_SECRET : $fallback` never takes the
// fallback and silently signs with the empty string. Three call sites had
// exactly that shape, including the shop storefront's CSRF token, whose
// docblock claimed it was "unguessable without APP_SECRET".
//
// These two are the only sanctioned way to reach the secret. The CI guard
// (rule SECRET) fails the build on a raw defined('APP_SECRET').
if (!function_exists('slate_has_app_secret')) {
    /** True when a usable signing/encryption key is configured. */
    function slate_has_app_secret(): bool {
        return defined('APP_SECRET') && APP_SECRET !== '';
    }
}

if (!function_exists('slate_app_secret')) {
    /**
     * The application secret, or null when unconfigured.
     *
     * Returns null rather than throwing so a caller can degrade deliberately
     * (skip signing a webhook, refuse to mint a token) instead of fataling a
     * page. What a caller must NOT do is fall back to a constant string: that
     * is a known key, which is the same as no key. Check the return value.
     */
    function slate_app_secret(): ?string {
        return slate_has_app_secret() ? (string) APP_SECRET : null;
    }
}

if (!function_exists('slate_sign')) {
    /**
     * HMAC-SHA256 a value with the application secret, or null when no secret
     * is configured. $context namespaces the signature so a token minted for
     * one purpose cannot be replayed as another.
     *
     * Returns null — never a signature under a guessable key. A caller that
     * gets null must fail closed.
     */
    function slate_sign(string $context, string $value, ?int $length = null): ?string {
        $secret = slate_app_secret();
        if ($secret === null) return null;
        $sig = hash_hmac('sha256', $context . '|' . $value, $secret);
        return $length !== null && $length > 0 ? substr($sig, 0, $length) : $sig;
    }
}

if (!function_exists('slate_sign_equals')) {
    /**
     * Constant-time check of a signature produced by slate_sign().
     * False when no secret is configured — an unverifiable token is not a
     * valid one.
     */
    function slate_sign_equals(string $context, string $value, string $candidate, ?int $length = null): bool {
        $expected = slate_sign($context, $value, $length);
        if ($expected === null || $candidate === '') return false;
        return hash_equals($expected, $candidate);
    }
}

// ── Public entry point gate (SEC-9) ─────────────────────────────
// .htaccess deliberately allows plugins/*/public/ and plugins/*/storefront/
// to be fetched directly by URL (needed for legitimate assets), which means
// a plugin's PHP files are reachable that way REGARDLESS of whether the
// plugin is active. Deactivating a plugin removes its nav, its hooks, and
// its `public_routes` filter registration — none of which stop a direct
// fetch of the file itself. Session 11's security audit found this open on
// every plugin except stripe-payment and shop.
//
// This is the one required prologue: call it as the very first statement
// (immediately after requiring config.php) of every plugin public/storefront
// entry point. A file that skips it is caught by bin/anti-drift.php's ENTRY
// rule rather than relying on someone noticing in review.
if (!function_exists('slate_public_entry')) {
    /**
     * Refuse the request with a clean 503 unless $slug is an active plugin.
     * Call this — not a local `PluginLoader::isActive()` check — so every
     * entry point fails the same way and the anti-drift guard can verify
     * every entry point calls it.
     */
    function slate_public_entry(string $slug): void {
        if (!class_exists('PluginLoader') || !PluginLoader::isActive($slug)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo "This page is not currently available.";
            exit;
        }
    }
}

// ── CSRF ──────────────────────────────────────────────────────
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['slate_csrf'])) {
            $_SESSION['slate_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['slate_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token = null): bool {
        $token  = $token ?? ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $stored = $_SESSION['slate_csrf'] ?? '';
        if ($stored === '' || $token === '') return false;
        return hash_equals($stored, $token);
    }
}

// ── One-time submission token (anti double-submit) ────────────
// Stops a single user action from writing twice. Each rendered form
// embeds a fresh single-use token via submit_token_field(); the first
// POST consumes it with submit_token_consume(), so any replay (page
// refresh, back-button re-POST, or a fast double-click) finds the token
// already gone and is rejected. Independent of the CSRF token, which is
// per-session and reusable (and so cannot detect a replay on its own).
if (!function_exists('submit_token')) {
    function submit_token(): string {
        if (empty($_SESSION['slate_submit_tokens']) || !is_array($_SESSION['slate_submit_tokens'])) {
            $_SESSION['slate_submit_tokens'] = [];
        }
        $now = time();
        // Prune expired (2h TTL) and cap to the 50 most recent so a long
        // session with many rendered forms can't grow the session record
        // without bound.
        foreach ($_SESSION['slate_submit_tokens'] as $t => $exp) {
            if ((int)$exp < $now) unset($_SESSION['slate_submit_tokens'][$t]);
        }
        if (count($_SESSION['slate_submit_tokens']) > 50) {
            $_SESSION['slate_submit_tokens'] =
                array_slice($_SESSION['slate_submit_tokens'], -50, null, true);
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['slate_submit_tokens'][$token] = $now + 7200;
        return $token;
    }
}

if (!function_exists('submit_token_field')) {
    function submit_token_field(): string {
        return '<input type="hidden" name="_stoken" value="' . e(submit_token()) . '">';
    }
}

if (!function_exists('submit_token_consume')) {
    /**
     * Validate-and-consume the submission token on the current request.
     * Returns true exactly once per issued token; false for a missing,
     * unknown, expired, or already-used token — i.e. a duplicate submit.
     */
    function submit_token_consume(?string $token = null): bool {
        $token = $token ?? ($_POST['_stoken'] ?? '');
        if (!is_string($token) || $token === '') return false;
        $store = $_SESSION['slate_submit_tokens'] ?? [];
        if (!is_array($store) || !isset($store[$token])) return false;
        $exp = (int)$store[$token];
        unset($_SESSION['slate_submit_tokens'][$token]); // consume either way
        return $exp >= time();
    }
}

// ── Safe post-login redirect target ───────────────────────────
if (!function_exists('slate_safe_redirect_target')) {
    /**
     * Sanitise a user-supplied `next=` redirect. Only same-origin
     * destinations are allowed; anything else falls back. This blocks
     * open redirects, including protocol-relative URLs ("//evil.com",
     * "/\evil.com") that browsers treat as off-site.
     */
    function slate_safe_redirect_target($next, string $fallback): string {
        $next = (string)($next ?? '');
        if ($next === '' || preg_match('/[\x00-\x1F\x7F]/', $next)) {
            return $fallback;
        }
        // An absolute URL to our own origin is fine.
        if (defined('SLATE_URL') && ($next === SLATE_URL || str_starts_with($next, SLATE_URL . '/'))) {
            return $next;
        }
        // Otherwise accept only a same-origin absolute path: a single
        // leading slash that is NOT followed by another slash or a
        // backslash (which would make it protocol-relative / off-site).
        if (str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_starts_with($next, '/\\')) {
            return $next;
        }
        return $fallback;
    }
}

// ── URL safety ───────────────────────────────────────────────
if (!function_exists('slate_safe_url')) {
    /**
     * Reduce a user-supplied URL to something safe to put in href/src.
     *
     * e() escapes HTML; it does nothing about the scheme. `javascript:alert(1)`
     * survives htmlspecialchars untouched and runs on click, so any URL that
     * came from an editor has to pass through here BEFORE e().
     *
     * Anything without a scheme (/about, ../x, #anchor, ?page=2) is relative and
     * safe. Anything with a scheme must be one we allow. Protocol-relative
     * (//host/path) inherits the page scheme and is allowed.
     *
     * Returns $fallback when the URL is unusable, so a hostile value becomes an
     * inert link rather than a broken page.
     */
    function slate_safe_url(?string $url, string $fallback = '#'): string {
        $url = trim((string)$url);
        if ($url === '') return $fallback;

        // Browsers ignore control characters and whitespace while parsing a
        // scheme, so "jav\tascript:" and "java\0script:" both execute. Strip them
        // for the DECISION only — the original string is what gets returned.
        $probe = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';

        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $probe, $m)) {
            $scheme = strtolower($m[1]);
            if (!in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
                return $fallback;
            }
        }

        return $url;
    }
}

// ── Database clock ───────────────────────────────────────────
if (!function_exists('slate_db_now')) {
    /**
     * "Now", as the database sees it, as a Y-m-d H:i:s string.
     *
     * Use this instead of date('Y-m-d H:i:s') for any value that will later be
     * compared against NOW(), CURDATE(), or a column MySQL wrote itself
     * (anything with DEFAULT CURRENT_TIMESTAMP). PHP and MySQL do not
     * necessarily share a timezone — on this host PHP runs UTC and MySQL runs
     * SYSTEM, a four-hour gap — and mixing the two clocks produces comparisons
     * that are silently wrong by that offset. It disabled the login throttle
     * outright and handed every membership four hours of unpaid access before
     * anyone noticed.
     *
     * A timestamp that is only ever displayed, never compared, does not need
     * this. A timestamp that gates access, expiry, or reporting does.
     */
    function slate_db_now(): string {
        // DO NOT CACHE THIS. It was cached once, on the reasoning that every
        // caller in a request wants the same instant and the correct helper
        // should not cost more than the wrong one. That is true of a web
        // request measured in milliseconds and false of everything else:
        //
        //   - the reminder cron (Booking::sendReminders) sweeps for minutes,
        //     and would have stamped every row with the process start time
        //   - the integration suite runs 26 files in one process, so a cached
        //     value drifted ~58s from NOW() and DbClockHelperTest failed on
        //     its own invariant — which is exactly what that test is for
        //
        // A helper whose entire purpose is "agree with the database clock"
        // cannot hold a value that stops agreeing. One round trip is cheap;
        // this is not a place to save it.
        return (string) Database::value('SELECT NOW()');
    }
}

if (!function_exists('slate_db_time')) {
    /**
     * "Now" on the database clock as a Unix timestamp (CORE-1).
     *
     * The integer counterpart to slate_db_now(), for PHP-side arithmetic that
     * will be compared against a stored value: deltas, expiry windows, "is
     * this in the past". Using time() for that is the same bug in a different
     * shape — PHP's clock, measured against a column MySQL wrote.
     *
     * The CI guard (rule CLOCK) fails the build on a PHP date()/time() value
     * written into a datetime column.
     */
    function slate_db_time(): int {
        $ts = strtotime(slate_db_now());
        return $ts !== false ? $ts : time();
    }
}

// ── Global currency default ─────────────────────────────────────
// The single tenant-wide default currency (Settings → General). Plugins
// (membership, booking, ...) read this instead of keeping their own
// duplicate "default currency" setting, so there's one place to change it.
if (!function_exists('slate_default_currency')) {
    function slate_default_currency(): string {
        $c = strtoupper(trim((string) Database::setting('currency')));
        return preg_match('/^[A-Z]{3,8}$/', $c) ? $c : 'USD';
    }
}

// ── Global currency position (Settings → General) ────────────────
// Whether the symbol/code renders before ("$50.00") or after ("50.00 $")
// the amount. Single tenant-wide switch — plugins must not keep their own.
if (!function_exists('slate_currency_position')) {
    function slate_currency_position(): string {
        $p = strtolower(trim((string) Database::setting('currency_position')));
        return $p === 'after' ? 'after' : 'before';
    }
}

// ── ISO 4217 code → display symbol ────────────────────────────────
if (!function_exists('slate_currency_symbol')) {
    function slate_currency_symbol(?string $code = null): string {
        $code = strtoupper(trim((string) ($code ?? slate_default_currency())));
        static $symbols = [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CHF' => 'CHF', 'CAD' => '$',
            'AUD' => '$', 'NZD' => '$', 'JPY' => '¥', 'CNY' => '¥', 'INR' => '₹',
            'BRL' => 'R$', 'MXN' => '$', 'ZAR' => 'R', 'SEK' => 'kr', 'NOK' => 'kr',
            'DKK' => 'kr', 'PLN' => 'zł', 'CZK' => 'Kč', 'RON' => 'lei', 'TRY' => '₺',
            'AED' => 'د.إ', 'SAR' => '﷼', 'SGD' => '$', 'HKD' => '$', 'KRW' => '₩',
        ];
        return $symbols[$code] ?? $code;
    }
}

// ── The single formatter every plugin should call to render a price ──
// Combines the global currency symbol and the global before/after position
// so every screen (booking, membership, gift cards, stripe, emails, chat)
// renders amounts identically. $cents is an integer amount in the minor unit.
// Returns HTML-safe markup (symbol escaped, "&nbsp;" entity) for direct echo.
if (!function_exists('slate_format_price')) {
    function slate_format_price(int $cents, ?string $currencyCode = null): string {
        $amount = number_format($cents / 100, 2);
        $symbol = e(slate_currency_symbol($currencyCode));
        return slate_currency_position() === 'after'
            ? $amount . '&nbsp;' . $symbol
            : $symbol . $amount;
    }
}

// Plain-text counterpart for SMS, email subjects, JSON, PDFs — a real
// U+00A0 non-breaking space instead of the HTML entity, symbol unescaped.
if (!function_exists('slate_format_price_plain')) {
    function slate_format_price_plain(int $cents, ?string $currencyCode = null): string {
        $amount = number_format($cents / 100, 2);
        $symbol = slate_currency_symbol($currencyCode);
        return slate_currency_position() === 'after'
            ? $amount . "\u{00A0}" . $symbol
            : $symbol . $amount;
    }
}

// ── Secret encryption (AES-256-GCM, envelope format 'enc:v1:') ──
if (!function_exists('slate_encrypt_secret')) {
    function slate_encrypt_secret(string $plaintext): string {
        $secret = slate_app_secret();
        if ($secret === null) {
            throw new RuntimeException('APP_SECRET is not configured.');
        }
        $key = hash('sha256', $secret, true);
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'enc:v1:' . base64_encode($iv . $tag . $ct);
    }
}

if (!function_exists('slate_decrypt_secret')) {
    function slate_decrypt_secret(string $envelope): ?string {
        if (!str_starts_with($envelope, 'enc:v1:')) return $envelope;
        if (!slate_has_app_secret()) return null;
        $raw = base64_decode(substr($envelope, 7), true);
        if ($raw === false || strlen($raw) < 28) return null;
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $key = hash('sha256', (string) slate_app_secret(), true);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }
}

// ── Semver comparison ─────────────────────────────────────────
if (!function_exists('slate_semver_satisfies')) {
    /**
     * Check whether $version satisfies a simple semver range.
     * Supports: ">=1.2.3", ">1.0", "=1.0", "<2.0", "<=1.5", "1.0" (exact)
     * Plugin manifests use this to declare 'requires_core'.
     */
    function slate_semver_satisfies(string $version, string $range): bool {
        $range = trim($range);
        if ($range === '') return true;

        // Parse leading operator
        $op = '=';
        if (preg_match('/^(>=|<=|>|<|=)?\s*(.+)$/', $range, $m)) {
            if ($m[1] !== '') $op = $m[1];
            $bound = trim($m[2]);
        } else {
            return false;
        }

        $cmp = version_compare($version, $bound);
        return match ($op) {
            '>=' => $cmp >= 0,
            '<=' => $cmp <= 0,
            '>'  => $cmp >  0,
            '<'  => $cmp <  0,
            '='  => $cmp === 0,
            default => false,
        };
    }
}

// ── JSON helpers ──────────────────────────────────────────────
if (!function_exists('json_response')) {
    function json_response($payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('json_error')) {
    function json_error(string $message, int $status = 400): void {
        json_response(['ok' => false, 'error' => $message], $status);
    }
}

// ── i18n shim ─────────────────────────────────────────────────
// Bare __() so plugin code can be written before I18n is loaded.
// Real implementation lives in includes/I18n.php and is loaded by
// config.php. The shim falls back to the inline English.
if (!function_exists('__')) {
    function __(string $key, string $fallback = ''): string {
        if (class_exists('I18n')) {
            return I18n::translate($key, $fallback);
        }
        return $fallback !== '' ? $fallback : $key;
    }
}

if (!function_exists('__js')) {
    function __js(array $keys): array {
        $out = [];
        foreach ($keys as $key => $fallback) {
            $out[is_int($key) ? $fallback : $key] = __(is_int($key) ? $fallback : $key, $fallback);
        }
        return $out;
    }
}

// ── Logging ───────────────────────────────────────────────────
if (!function_exists('slate_log')) {
    /**
     * Append a line to the Slate log file.
     * Used by PluginLoader for plugin errors, by audit failures,
     * by anything that needs a "this happened" trail visible to
     * the operator without polluting PHP error logs.
     */
    function slate_log(string $message, string $level = 'info'): void {
        $logFile = (defined('SLATE_ROOT') ? SLATE_ROOT : __DIR__ . '/..') . '/data/slate.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        // anti-drift-ignore: CLOCK — a log-file line, never a DB value, and
        // must not need a database connection to write.
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

// ── Time display formatting ──────────────────────────────────────
// admin/settings.php has always let a site owner choose a "Time Format"
// (12-hour "2:30 pm" vs 24-hour "14:30", or a custom PHP date() format) —
// but until now nothing in the codebase actually read the setting back;
// every plugin hardcoded date('H:i', ...) instead, so the control did
// nothing. These are the display helpers plugins should call instead —
// storage/machine-readable timestamps (SQL 'Y-m-d H:i:s' values, slot
// keys used in booking logic, datetime-local form defaults) must keep
// using date()/strtotime() directly; only what a person actually reads
// on screen or in an email/SMS should go through slate_time_format().
if (!function_exists('slate_time_format')) {
    /** The site's configured time-of-day display format (PHP date() format string). */
    function slate_time_format(): string {
        $fmt = trim((string) Database::setting('time_format'));
        return $fmt !== '' ? $fmt : 'g:i a';
    }
}

if (!function_exists('slate_format_time')) {
    /**
     * Format a Unix timestamp (int) or a parseable date/time string as
     * just the time-of-day, using the site's configured time format.
     * Returns '' for an unparsable/empty input.
     */
    function slate_format_time($when): string {
        $ts = is_int($when) ? $when : strtotime((string) $when);
        if (!$ts) return '';
        return date(slate_time_format(), $ts);
    }
}

if (!function_exists('slate_format_datetime')) {
    /**
     * Format a Unix timestamp (int) or a parseable date/time string as
     * "<date>, <time>", with the time portion honouring the site's
     * configured time format. $dateFmt controls only the date part
     * (default matches the long form used across Booking's admin views
     * and emails); $sep is the separator between date and time.
     */
    function slate_format_datetime($when, string $dateFmt = 'l, j F Y', string $sep = ', '): string {
        $ts = is_int($when) ? $when : strtotime((string) $when);
        if (!$ts) return '';
        return I18n::localDate($dateFmt, $ts) . $sep . date(slate_time_format(), $ts);
    }
}

// ── plugin_url ──────────────────────────────────────────────────
// Build a URL into a plugin's directory. Equivalent to $this->url()
// from inside a Plugin class, but callable from any context (admin
// pages, other plugins, partials) without needing a Plugin instance.
//
//   plugin_url('shop')                       → /plugins/shop
//   plugin_url('shop', 'admin/orders.php')   → /plugins/shop/admin/orders.php
if (!function_exists('plugin_url')) {
    function plugin_url(string $slug, string $relPath = ''): string {
        $base = SLATE_URL . '/plugins/' . $slug;
        return $relPath === '' ? $base : $base . '/' . ltrim($relPath, '/');
    }
}

// ── plugin_dir ──────────────────────────────────────────────────
// Filesystem counterpart to plugin_url(). Useful when one plugin
// needs to read another's static asset (rare; usually use the
// target plugin's API class instead).
if (!function_exists('plugin_dir')) {
    function plugin_dir(string $slug, string $relPath = ''): string {
        $base = SLATE_ROOT . '/plugins/' . $slug;
        return $relPath === '' ? $base : $base . '/' . ltrim($relPath, '/');
    }
}

// ── Avatar photo overlay ──────────────────────────────────────────
// Gravatar URL builder + initials/photo overlay markup were previously
// reimplemented independently in plugins/forms/FormsAPI.php,
// plugins/forms/admin/submissions.php, includes/portal_shell.php, and
// admin/partials/header.php -- and every OTHER plugin's customer/contact
// list (Booking's dashboard + customer list, Membership's members list,
// Coaching's client list) just never looked up a photo at all, always
// showing bare initials even for a person who has a Gravatar or an
// uploaded photo. One shared implementation so any list can opt in.
if (!function_exists('slate_gravatar_url')) {
    function slate_gravatar_url(string $email, int $size = 40): string {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) return '';
        return 'https://www.gravatar.com/avatar/' . md5($email) . '?s=' . ($size * 2) . '&d=404';
    }
}

// The single builder every list should call for slate_dlist_row()'s /
// slate_data_row()'s 'avatar_html' param: initials text with a photo
// <img> overlay layered on top when one is available. An uploaded photo
// path (e.g. Membership's own avatar_path) takes priority over Gravatar;
// with neither, this degrades to plain initials. The overlay uses
// Gravatar's d=404 + onerror="this.remove()" so an email with no
// registered Gravatar silently falls back to the initials underneath,
// never a broken-image icon.
if (!function_exists('slate_avatar_overlay_html')) {
    function slate_avatar_overlay_html(string $initials, string $email = '', ?string $photoPath = null, int $size = 40): string {
        $imgSrc = '';
        if ($photoPath !== null && $photoPath !== '') {
            $imgSrc = SLATE_URL . '/' . ltrim($photoPath, '/');
        } elseif ($email !== '') {
            $imgSrc = slate_gravatar_url($email, $size);
        }
        if ($imgSrc === '') return e($initials);
        return e($initials) . '<img src="' . e($imgSrc) . '" alt="" loading="lazy" onerror="this.remove()">';
    }
}

// ── Dynamic (light/dark) brand logo ──────────────────────────────
if (!function_exists('slate_logo_urls')) {
    /**
     * The tenant's logo, in both variants. `dark` is meant for the app's
     * fixed-dark surfaces (login hero panel, public landing page, error
     * pages) and for email, which genuinely follows the recipient's own
     * client. It is NOT swapped by the visitor's OS/browser color-scheme
     * preference anywhere else — none of this app's admin/portal chrome
     * has dark-mode CSS today, so a page with a fixed light background
     * always wants `light`, and a page with a fixed dark background always
     * wants `dark` (falling back to `light` when no dark variant is set).
     * Callers pick whichever matches their own known background.
     *
     * @return array{light:string,dark:string} Resolved absolute URLs, or '' when unset.
     */
    function slate_logo_urls(): array {
        $light = trim((string) Database::setting('brand_logo_path'));
        $dark  = trim((string) Database::setting('brand_logo_dark_path'));
        return [
            'light' => $light !== '' ? SLATE_URL . '/' . ltrim($light, '/') : '',
            'dark'  => $dark  !== '' ? SLATE_URL . '/' . ltrim($dark, '/')  : '',
        ];
    }
}

// ── Favicon (tenant-uploaded, falling back to the platform default) ──────
if (!function_exists('slate_default_favicon_url')) {
    /** The platform's own mark, used wherever no tenant branding applies. */
    function slate_default_favicon_url(): string {
        return rtrim((string) SLATE_URL, '/') . '/assets/img/kohevo-favicon.ico';
    }
}
if (!function_exists('slate_favicon_url')) {
    /**
     * Matches the `Database::setting('site_name') ?: 'Kohevo'` fallback
     * pattern used everywhere else: an unbranded tenant gets the platform's
     * own mark instead of no favicon at all.
     */
    function slate_favicon_url(): string {
        $path = trim((string) Database::setting('brand_favicon_path'));
        return $path !== '' ? SLATE_URL . '/' . ltrim($path, '/') : slate_default_favicon_url();
    }
}
