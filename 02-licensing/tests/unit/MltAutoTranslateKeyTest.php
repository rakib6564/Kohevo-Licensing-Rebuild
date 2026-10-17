<?php
/**
 * Multilang auto-translate API key: encrypted at rest, write-only, clearable.
 * Exercises the real MLT_AutoTranslate::resolveStoredApiKey(); the "no
 * APP_SECRET" case runs in a child process (APP_SECRET is a constant).
 */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/includes/helpers.php';
require_once SLATE_ROOT . '/plugins/multilang-translate/includes/AutoTranslate.php';
if (!defined('APP_SECRET')) {
    define('APP_SECRET', 'unit-test-app-secret-mlt-key');
}
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

unit('mlt api key: a typed key is stored as an envelope that decrypts to it', function (): void {
    $r = MLT_AutoTranslate::resolveStoredApiKey('', '  lt-key-123  ', false);
    assert_null($r['error']);
    assert_true(str_starts_with($r['key'], 'enc:v1:'));
    assert_false(str_contains($r['key'], 'lt-key-123'));
    assert_eq('lt-key-123', slate_decrypt_secret($r['key']));
});

unit('mlt api key: blank keeps the stored envelope; legacy plaintext is upgraded; clear removes it', function (): void {
    $env = slate_encrypt_secret('lt-old');
    assert_eq(['key' => $env, 'error' => null], MLT_AutoTranslate::resolveStoredApiKey($env, '   ', false));
    $up = MLT_AutoTranslate::resolveStoredApiKey('lt-legacy-plain', '', false);
    assert_true(str_starts_with($up['key'], 'enc:v1:'), 'legacy plaintext is re-encrypted on save');
    assert_eq('lt-legacy-plain', slate_decrypt_secret($up['key']));
    assert_eq(['key' => '', 'error' => null], MLT_AutoTranslate::resolveStoredApiKey($env, 'typed', true), 'clear wins over a typed value');
});

unit('mlt api key: with no APP_SECRET a new key is refused and nothing plaintext is stored', function (): void {
    $code = 'require ' . var_export(SLATE_ROOT . '/includes/helpers.php', true) . ';'
          . 'require ' . var_export(SLATE_ROOT . '/plugins/multilang-translate/includes/AutoTranslate.php', true) . ';'
          . 'echo json_encode(["defined" => defined("APP_SECRET"),'
          . '"typed" => MLT_AutoTranslate::resolveStoredApiKey("enc:v1:STORED", "lt-PLAINNEW", false),'
          . '"fresh" => MLT_AutoTranslate::resolveStoredApiKey("", "lt-PLAINNEW", false),'
          . '"legacy" => MLT_AutoTranslate::resolveStoredApiKey("lt-legacy", "", false)]);';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    $res = json_decode($out, true);
    assert_true(is_array($res), 'child process output: ' . $out);
    assert_false($res['defined'], 'precondition: the child has no APP_SECRET');
    assert_eq(['key' => 'enc:v1:STORED', 'error' => 'encryption_unavailable'], $res['typed'], 'the stored key is kept when a new one cannot be encrypted');
    assert_eq(['key' => '', 'error' => 'encryption_unavailable'], $res['fresh'], 'a new key is never stored in plaintext');
    assert_false(str_contains($out, 'lt-PLAINNEW'), 'the typed plaintext must not come back as a storable value');
    assert_eq(['key' => 'lt-legacy', 'error' => null], $res['legacy'], 'a legacy key is left as-is (not lost) when it cannot be upgraded');
});
