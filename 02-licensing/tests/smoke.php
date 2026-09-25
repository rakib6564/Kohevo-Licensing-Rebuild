<?php
/**
 * Slate — minimal smoke test (Phase 0 safety net).
 *
 * Dependency-free (no PHPUnit / no composer install) so it runs on
 * shared hosting exactly as production does. READ-ONLY: it never
 * mutates data — it boots the app, checks core wiring, DB connectivity,
 * and the presence of core schema, then reports PASS/FAIL.
 *
 * Usage:  php tests/smoke.php
 * Exit:   0 = all passed, 1 = one or more failures.
 *
 * This is the seed of the test strategy in docs/12-Testing/. Grow it:
 * add money, tenancy, auth, and payment assertions as those layers land.
 */

declare(strict_types=1);

// Never over HTTP. These runners touch the database — the integration suite
// creates and deletes rows — and there is no reason for a web request to
// reach them. .htaccess denies the directory too; this is the backstop for
// when server config does not.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$PASS = 0; $FAIL = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "ok   - $name\n"; }
    else     { $FAIL++; echo "FAIL - $name" . ($detail ? "  ($detail)" : '') . "\n"; }
}

echo "# Slate smoke test\n";

// ── Boot the application exactly as a request would ──────────
try {
    require __DIR__ . '/../config.php';
require __DIR__ . '/guard.php';
slate_require_test_database();
    check('config.php boots without fatal', true);
} catch (\Throwable $e) {
    check('config.php boots without fatal', false, $e->getMessage());
    echo "1..".($PASS+$FAIL)."\n# aborted: cannot boot\n";
    exit(1);
}

// ── Core classes are wired ───────────────────────────────────
foreach (['Database','Auth','Hook','PluginLoader','I18n','AuditLog'] as $cls) {
    check("core class exists: $cls", class_exists($cls));
}

// ── Constants / config present ───────────────────────────────
check('SLATE_VERSION defined', defined('SLATE_VERSION'));
check('SLATE_URL defined',     defined('SLATE_URL'));

// ── PSR-4 autoloader wired into bootstrap (Phase 1 A1.2) ─────
check('Slate PSR-4 autoloader registered via config.php', defined('SLATE_AUTOLOAD_REGISTERED'));
check('Slate\\Kernel\\Ping autoloads through bootstrap',   class_exists(\Slate\Kernel\Ping::class));

// ── Database connectivity (read-only) ────────────────────────
try {
    $one = Database::query('SELECT 1 AS n')->fetch();
    check('DB connectivity (SELECT 1)', ($one['n'] ?? null) == 1);
} catch (\Throwable $e) {
    check('DB connectivity (SELECT 1)', false, $e->getMessage());
}

// ── Core schema present ──────────────────────────────────────
$coreTables = ['tenants','roles','role_permissions','users','customers','settings','plugins','audit_log'];
foreach ($coreTables as $t) {
    try {
        Database::query("SELECT 1 FROM `$t` LIMIT 1");
        check("core table present: $t", true);
    } catch (\Throwable $e) {
        check("core table present: $t", false, 'missing or unreadable');
    }
}

// ── Default tenant seeded ────────────────────────────────────
try {
    $row = Database::query('SELECT COUNT(*) AS c FROM tenants')->fetch();
    check('at least one tenant row', (int)($row['c'] ?? 0) >= 1);
} catch (\Throwable $e) {
    check('at least one tenant row', false, $e->getMessage());
}

// ── Summary (TAP-style) ──────────────────────────────────────
$total = $PASS + $FAIL;
echo "1..$total\n";
echo "# passed $PASS / $total\n";
exit($FAIL === 0 ? 0 : 1);
