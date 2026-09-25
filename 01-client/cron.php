<?php
/**
 * Slate — cron entry point.
 *
 * Hit by an external scheduler (cPanel cron, GitHub Actions, etc.)
 * to fire periodic actions that plugins listen on.
 *
 * Auth via the CRON_SECRET defined in .env. Set the `X-Cron-Key:
 * <CRON_SECRET>` header (recommended), or pass `?key=<CRON_SECRET>` if
 * your scheduler can't set a custom header. Without a valid key the
 * endpoint returns 403 — no information about whether cron is
 * configured at all.
 *
 * Prefer the header. A secret in the URL ends up in the web server's
 * access log, in curl's own argv (visible to other local users via `ps`
 * for the life of the request), and in shell history if the cron line
 * is ever run by hand. The header form avoids all three.
 *
 * Actions fired (priority-ordered by listener):
 *   - `frequent_cron`  every time this is hit (recommended: every 5m)
 *   - `daily_cron`     once per UTC day (cheap idempotency via the
 *                      `cron_last_daily` setting)
 *
 * Recommended cron line (every 5 minutes):
 *   <every 5 min>  curl -fsS -H 'X-Cron-Key: YOUR_CRON_SECRET' 'https://yoursite/cron.php' > /dev/null
 *
 * Output: a tiny JSON blob with what fired + how long it took.
 * Errors inside individual listeners are caught by Hook::doAction
 * and logged via slate_log — they never crash this endpoint.
 *
 * Rate limiting: reuses login_attempts (scope='cron') — the same table and
 * DB-clock-correct counting pattern Auth's login throttle uses. Two
 * independent ceilings, checked before the key is even evaluated: 30
 * attempts/10min per IP (stops one source from brute-forcing CRON_SECRET),
 * and 200 attempts/hour globally (bounds worst-case Hook::doAction firing
 * rate even under a distributed attempt, or a leaked secret being replayed
 * rapidly). Every attempt is recorded — success included, never cleared —
 * because a correct-but-leaked secret replayed rapidly is exactly as
 * damaging as failed guesses here; unlike login, a success does not prove
 * the caller is legitimate. See cron_rate_limited()/cron_record_attempt().
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

/** REMOTE_ADDR only — never trust X-Forwarded-* (spoofable), matching Auth::clientIp(). */
function cron_client_ip(): string {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * True if either the per-IP or global cron rate limit is currently exceeded.
 * Fails OPEN on any infra error (missing table, DB hiccup) — matching
 * Auth::recordLoginFailure()'s own precedent that throttling must never
 * block on an infrastructure failure. Without a working DB, cron's actual
 * listeners can't do anything useful either, so blocking here on top of
 * that adds a second failure mode with no compensating benefit.
 */
function cron_rate_limited(string $ip): bool {
    try {
        $perIp = (int) Database::value(
            "SELECT COUNT(*) FROM login_attempts
              WHERE scope = 'cron' AND ip = ? AND attempted_at > NOW() - INTERVAL 10 MINUTE",
            [$ip]
        );
        if ($perIp >= 30) return true;

        $global = (int) Database::value(
            "SELECT COUNT(*) FROM login_attempts
              WHERE scope = 'cron' AND attempted_at > NOW() - INTERVAL 1 HOUR"
        );
        return $global >= 200;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Record this cron hit toward both ceilings. Best-effort; never blocks the request. */
function cron_record_attempt(string $ip): void {
    try {
        Database::insert('login_attempts', [
            'tenant_id'  => current_tenant_id(),
            'scope'      => 'cron',
            'ip'         => $ip,
            'identifier' => '',
        ]);
    } catch (\Throwable $e) {
        // best-effort — see cron_rate_limited()'s fail-open note.
    }
}

$cronClientIp = cron_client_ip();
if (cron_rate_limited($cronClientIp)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

// ── Auth ─────────────────────────────────────────────────────
$secret   = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
$provided = (string) ($_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? ''));
$authOk   = $secret !== '' && $provided !== '' && hash_equals($secret, $provided);

cron_record_attempt($cronClientIp);

if (!$authOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

// ── CLI / web duals ──────────────────────────────────────────
$start = microtime(true);
$fired = ['frequent_cron'];

Hook::doAction('frequent_cron');

// Daily cron — fire once per UTC day, gate via the settings table.
$todayUtc = gmdate('Y-m-d');
$lastDaily = (string) Database::setting('cron_last_daily');
if ($lastDaily !== $todayUtc) {
    Hook::doAction('daily_cron');
    Database::setSetting('cron_last_daily', $todayUtc);
    $fired[] = 'daily_cron';
}

$elapsed = (int) ((microtime(true) - $start) * 1000);
echo json_encode([
    'ok'         => true,
    'fired'      => $fired,
    'duration_ms'=> $elapsed,
    'now'        => gmdate('c'),
]);
