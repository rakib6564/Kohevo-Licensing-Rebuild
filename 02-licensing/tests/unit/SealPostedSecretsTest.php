<?php
/**
 * slate_seal_posted_secrets(): the encrypt-everything-first step every
 * settings form with a secret uses. Filled-in fields come back as enc:v1
 * envelopes keyed by setting key; blank fields are left out (keep the stored
 * secret); if any value cannot be encrypted the whole call returns null so
 * the caller saves nothing. The no-APP_SECRET case runs in a child process.
 */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/includes/helpers.php';
if (!defined('APP_SECRET')) {
    define('APP_SECRET', 'unit-test-app-secret-seal');
}
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

unit('seal posted secrets: filled fields become envelopes under their setting keys; blank fields are left out', function (): void {
    $r = slate_seal_posted_secrets(['a' => 'plugin.a_secret', 'b' => 'plugin.b_token', 'c' => 'plugin.c_key'], ['a' => '  s3cret ', 'b' => '', 'c' => '   ']);
    assert_true(is_array($r));
    assert_eq(['plugin.a_secret'], array_keys($r), 'only the filled-in field is returned');
    assert_true(str_starts_with($r['plugin.a_secret'], 'enc:v1:'));
    assert_eq('s3cret', slate_decrypt_secret($r['plugin.a_secret']), 'trimmed value round-trips');
    assert_eq([], slate_seal_posted_secrets(['a' => 'k'], []), 'nothing posted is not a failure');
});

unit('seal posted secrets: with no APP_SECRET any filled field makes the whole call fail (null), so nothing is saved', function (): void {
    $code = 'require ' . var_export(SLATE_ROOT . '/includes/helpers.php', true) . ';'
          . 'echo json_encode(["defined" => defined("APP_SECRET"),'
          . '"filled" => slate_seal_posted_secrets(["a" => "k1", "b" => "k2"], ["a" => "PLAIN-A", "b" => "PLAIN-B"]),'
          . '"blank" => slate_seal_posted_secrets(["a" => "k1"], ["a" => ""])]);';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    $res = json_decode($out, true);
    assert_true(is_array($res), 'child output: ' . $out);
    assert_false($res['defined'], 'precondition: the child has no APP_SECRET');
    assert_null($res['filled'], 'a secret that cannot be encrypted fails the whole form');
    assert_false(str_contains($out, 'PLAIN-'), 'no plaintext comes back as a storable value');
    assert_eq([], $res['blank'], 'blank fields need no encryption, so they do not fail');
});
