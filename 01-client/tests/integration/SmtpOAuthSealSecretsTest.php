<?php
/**
 * SmtpOAuth::sealSecrets() — the one place OAuth secrets are encrypted before
 * they are stored (admin/oauth_callback.php and the access-token refresh).
 *
 * Contract: every non-empty value comes back as an enc:v1 envelope that
 * decrypts to the original; empty values stay empty; if ANY value cannot be
 * encrypted the whole call returns null so the caller writes nothing. The
 * "no APP_SECRET" case runs in a child process, because APP_SECRET is a
 * constant in this one.
 */

declare(strict_types=1);

unit('SmtpOAuth::sealSecrets(): every non-empty secret becomes an envelope that decrypts to the original; empty stays empty', function (): void {
    assert_true(class_exists('SmtpOAuth', false), 'precondition: SmtpOAuth is loaded (needs PHPMailer from vendor/)');
    assert_true(slate_has_app_secret(), 'precondition: APP_SECRET is configured for the integration suite');
    $plain = ['client_secret' => 'cs-' . bin2hex(random_bytes(8)), 'refresh_token' => 'rt-' . bin2hex(random_bytes(8)), 'access_token' => ''];
    $sealed = SmtpOAuth::sealSecrets($plain);
    assert_true(is_array($sealed), 'sealing must succeed with APP_SECRET');
    assert_eq(array_keys($plain), array_keys($sealed), 'keys are preserved');
    foreach (['client_secret', 'refresh_token'] as $k) {
        assert_true(str_starts_with($sealed[$k], 'enc:v1:'), "{$k} must be an envelope");
        assert_false(str_contains($sealed[$k], $plain[$k]), "{$k} plaintext must not appear in the envelope");
        assert_eq($plain[$k], slate_decrypt_secret($sealed[$k]), "{$k} must decrypt to the original");
    }
    assert_eq('', $sealed['access_token'], 'an empty value stays empty');
});

unit('SmtpOAuth::sealSecrets(): with no APP_SECRET it returns null, so nothing (and nothing plaintext) is stored', function (): void {
    $root = dirname(__DIR__, 2);
    $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
          . 'require ' . var_export($root . '/includes/helpers.php', true) . ';'
          . 'require ' . var_export($root . '/includes/SmtpOAuth.php', true) . ';'
          . 'echo json_encode(["defined" => defined("APP_SECRET"), "class" => class_exists("SmtpOAuth", false),'
          . '"sealed" => SmtpOAuth::sealSecrets(["client_secret" => "cs-PLAIN", "refresh_token" => "rt-PLAIN"]),'
          . '"empty_only" => SmtpOAuth::sealSecrets(["access_token" => ""])]);';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    $res = json_decode($out, true);
    assert_true(is_array($res), 'child process output: ' . $out);
    assert_false($res['defined'], 'precondition: the child has no APP_SECRET');
    assert_true($res['class'], 'precondition: SmtpOAuth loaded in the child');
    assert_null($res['sealed'], 'sealing must fail as a whole without APP_SECRET');
    assert_false(str_contains($out, 'cs-PLAIN') || str_contains($out, 'rt-PLAIN'), 'no plaintext secret may come back as a storable value');
    assert_eq(['access_token' => ''], $res['empty_only'], 'nothing to encrypt is not a failure');
});
