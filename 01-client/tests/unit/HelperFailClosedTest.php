<?php
/**
 * CORE-1 acceptance: every helper fails closed.
 *
 * The helpers exist because a whole class of bug came from code reaching
 * around a correct implementation. That only holds if the helpers themselves
 * are safe when their input is missing — otherwise routing every call site
 * through them just centralises the failure.
 *
 * "Fails closed" here means something specific: when the helper cannot do its
 * job, the caller ends up REFUSING, never proceeding with a weaker guarantee.
 * The bug that motivated this was the opposite — `defined('APP_SECRET')` is
 * true for a constant defined to '', so three call sites believed they had a
 * key and signed with the empty string, producing tokens that looked valid
 * and were reproducible by anyone.
 */

declare(strict_types=1);

/** Run the probe in one APP_SECRET state and decode its report. */
function core1_probe(string $mode): array
{
    $script = __DIR__ . '/fixtures/app-secret-probe.php';
    $cmd    = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($mode);
    $raw    = shell_exec($cmd . ' 2>/dev/null');
    $json   = json_decode((string) $raw, true);
    if (!is_array($json)) {
        throw new RuntimeException("probe '$mode' produced no JSON: " . var_export($raw, true));
    }
    return $json;
}

// ── APP_SECRET absent entirely ───────────────────────────────
unit('slate_app_secret(): with APP_SECRET undefined, nothing can be signed or verified', function (): void {
    $p = core1_probe('missing');

    assert_false($p['constant_defined'], 'probe should not define the constant');
    assert_false($p['has_secret'],       'slate_has_app_secret() must be false');
    assert_true($p['secret_is_null'],    'slate_app_secret() must return null');

    assert_eq(null, $p['sign'],           'slate_sign() must return null, never a signature');
    assert_eq(null, $p['sign_truncated'], 'the truncated form must also return null');

    // The critical one: with no key, verification must reject EVERYTHING —
    // including the value the signer itself just produced. If null round-trips
    // to a passing comparison, an attacker needs no key either.
    assert_false($p['verify_correct'], 'verification must reject even a self-produced token');
    assert_false($p['verify_empty'],   'verification must reject an empty candidate');
    assert_false($p['verify_garbage'], 'verification must reject a garbage candidate');

    assert_true($p['encrypt_threw'], 'slate_encrypt_secret() must throw rather than store plaintext');
});

// ── APP_SECRET defined but empty — the actual production bug ─
unit('slate_app_secret(): APP_SECRET="" behaves as no secret, not as a usable key', function (): void {
    $p = core1_probe('empty');

    // The whole point. config.php always defines APP_SECRET, so `defined()`
    // is true here — and every helper must still treat it as absent.
    assert_true($p['constant_defined'], 'config.php-style: the constant IS defined');
    assert_false($p['has_secret'],      'an empty secret is not a secret');
    assert_true($p['secret_is_null'],   'slate_app_secret() must return null for ""');

    assert_eq(null, $p['sign'],           'must not sign under the empty key');
    assert_eq(null, $p['sign_truncated'], 'must not sign under the empty key (truncated)');

    assert_false($p['verify_correct'], 'must not verify anything under the empty key');
    assert_false($p['verify_empty'],   'must reject an empty candidate');
    assert_false($p['verify_garbage'], 'must reject a garbage candidate');

    assert_true($p['encrypt_threw'], 'slate_encrypt_secret() must throw on an empty secret');
});

// ── APP_SECRET properly configured ───────────────────────────
unit('slate_app_secret(): with a real secret, signing works and stays context-bound', function (): void {
    $p = core1_probe('set');

    assert_true($p['has_secret'],      'a configured secret is usable');
    assert_false($p['secret_is_null'], 'slate_app_secret() must return the value');

    assert_true(is_string($p['sign']) && strlen($p['sign']) === 64, 'sha256 hex is 64 chars');
    assert_true(is_string($p['sign_truncated']) && strlen($p['sign_truncated']) === 24, 'truncation is honoured');
    assert_true(str_starts_with($p['sign'], $p['sign_truncated']), 'truncation is a prefix of the full signature');

    assert_true($p['verify_correct'],  'a correct token verifies');
    assert_false($p['verify_empty'],   'an empty candidate never verifies');
    assert_false($p['verify_garbage'], 'a garbage candidate never verifies');

    // Context namespacing: a forms-pdf token must not pass as a shop-csrf one.
    assert_false($p['verify_crossctx'], 'a signature must not verify under a different context');

    assert_false($p['encrypt_threw'], 'encryption succeeds once a secret exists');
});

// ── Tenant qualification ─────────────────────────────────────
unit('slate_tenant_clause(): always emits a real predicate, aliased or not', function (): void {
    require_once dirname(__DIR__, 2) . '/includes/helpers.php';

    // There is no input that makes this return an empty string. A helper that
    // could yield '' would let a caller build "... WHERE " . $clause and
    // produce an unscoped query while looking scoped at the call site.
    foreach ([null, '', 'a', 'appt'] as $alias) {
        $clause = slate_tenant_clause($alias);
        assert_true(str_contains($clause, 'tenant_id'), 'clause always names tenant_id');
        assert_true(str_contains($clause, '?'),         'clause always leaves a bound placeholder');
        assert_true(trim($clause) !== '',               'clause is never empty');
    }

    assert_eq('tenant_id = ?',   slate_tenant_clause(),      'unaliased form');
    assert_eq('tenant_id = ?',   slate_tenant_clause(''),    'empty alias behaves as unaliased');
    assert_eq('a.tenant_id = ?', slate_tenant_clause('a'),   'aliased form qualifies the column');
});

unit('slate_tenant_id(): returns a usable tenant and tracks the override', function (): void {
    require_once dirname(__DIR__, 2) . '/includes/helpers.php';
    if (!defined('TENANT_ID')) define('TENANT_ID', 1);

    assert_true(is_int(slate_tenant_id()), 'always an int — it is bound into a query');
    assert_eq(current_tenant_id(), slate_tenant_id(), 'agrees with current_tenant_id() today');

    // with_tenant() is how cron scopes a sweep. If slate_tenant_id() ignored
    // it, a scoped query inside the callback would silently read the wrong
    // tenant — the exact failure the helper exists to prevent.
    $seen = with_tenant(4242, static fn (): int => slate_tenant_id());
    assert_eq(4242, $seen, 'honours with_tenant()');
    assert_eq(current_tenant_id(), slate_tenant_id(), 'override is unwound afterwards');
});

// ── Clock ────────────────────────────────────────────────────
unit('slate_db_time(): derives from the database clock, not PHP time()', function (): void {
    // Subprocess, for the same reason as the secret probe: helpers.php guards
    // its definitions with function_exists, so a stub only wins if it is
    // declared before the file loads — and by now another test has loaded it.
    $script = __DIR__ . '/fixtures/db-clock-probe.php';
    $raw    = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>/dev/null');
    $p      = json_decode((string) $raw, true);

    assert_true(is_array($p), 'clock probe produced JSON: ' . var_export($raw, true));

    assert_eq($p['expected'], $p['db_time'], 'slate_db_time() reflects slate_db_now()');
    assert_true($p['used_db_clock'],         'slate_db_time() used the database clock');

    // The bug this prevents is a PHP timestamp compared against a column MySQL
    // wrote. If slate_db_time() ever quietly fell back to time(), these two
    // would converge and the helper would be indistinguishable from the bug.
    assert_true(abs((int) $p['db_time'] - (int) $p['php_time']) > 60,
        'and is demonstrably not PHP time()');
});

unit('slate_db_now() is not cached — a stale "now" is the bug it exists to prevent', function (): void {
    // Static-source check, because the failure is invisible in a short process
    // and only shows up in a long one: a cron sweep stamping every row with
    // the process start time, or the integration suite drifting ~58s from
    // NOW() and failing DbClockHelperTest. Caching was added once for speed
    // and reverted for exactly this; the test is here so it is not re-added.
    $src = file_get_contents(dirname(__DIR__, 2) . '/includes/helpers.php');
    $fn  = strstr($src, 'function slate_db_now(');
    $fn  = substr($fn, 0, strpos($fn, "\n    }") ?: strlen($fn));

    assert_false(str_contains($fn, 'static '), 'slate_db_now() must hold no static state');
    assert_true(str_contains($fn, 'SELECT NOW()'), 'it must ask the database every call');
});
