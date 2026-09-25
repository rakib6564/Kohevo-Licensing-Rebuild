<?php
/**
 * Probe: what the CORE-1 secret helpers do in each APP_SECRET state.
 *
 * APP_SECRET is a constant, so its three states — never defined, defined
 * empty, defined with a value — cannot be exercised in one process. This
 * script sets up exactly one of them and prints the resulting behaviour as
 * JSON; HelperFailClosedTest runs it three times and asserts on the output.
 *
 * Testing the real constant in a real process matters here. A mock would
 * prove the helpers behave when a fake is wired up; the bug this guards
 * against was that `defined('APP_SECRET')` is TRUE for a constant defined to
 * the empty string, which is a fact about the constant, not about the code
 * around it.
 *
 * Usage: php app-secret-probe.php missing|empty|set
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$mode = $argv[1] ?? '';

switch ($mode) {
    case 'missing':                                    break;  // never define it
    case 'empty':   define('APP_SECRET', '');          break;
    case 'set':     define('APP_SECRET', str_repeat('a', 64)); break;
    default:
        fwrite(STDERR, "usage: app-secret-probe.php missing|empty|set\n");
        exit(2);
}

require dirname(__DIR__, 3) . '/includes/helpers.php';

$out = [
    'mode'            => $mode,
    'constant_defined'=> defined('APP_SECRET'),
    'has_secret'      => slate_has_app_secret(),
    'secret_is_null'  => slate_app_secret() === null,
    'sign'            => slate_sign('probe', 'value'),
    'sign_truncated'  => slate_sign('probe', 'value', 24),
    // Verification must refuse when it cannot verify. The dangerous failure is
    // not "rejects a good token" but "accepts one it could not check".
    'verify_correct'  => slate_sign_equals('probe', 'value', (string) slate_sign('probe', 'value')),
    'verify_empty'    => slate_sign_equals('probe', 'value', ''),
    'verify_garbage'  => slate_sign_equals('probe', 'value', str_repeat('0', 64)),
    // A signature minted for one purpose must not verify as another.
    'verify_crossctx' => slate_sign_equals('other', 'value', (string) slate_sign('probe', 'value')),
];

// slate_encrypt_secret() is the one that throws rather than returning null,
// because there is no safe degraded behaviour for "store this secret".
try {
    slate_encrypt_secret('payload');
    $out['encrypt_threw'] = false;
} catch (\Throwable $e) {
    $out['encrypt_threw'] = true;
}

echo json_encode($out), "\n";
