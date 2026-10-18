<?php
/**
 * Forms CAPTCHA secret: encrypted at rest, write-only, explicitly clearable.
 *
 * Exercises the real FormsSpamGuard::resolveStoredSecret() (what the form
 * editor stores) and FormsSpamGuard::captchaSecret()/verifyCaptcha() (what
 * the public form sends to the provider). The "no APP_SECRET" case runs in a
 * child process, because APP_SECRET is a constant and cannot be undefined.
 */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/includes/helpers.php';
require_once SLATE_ROOT . '/plugins/forms/lib/FormsSpamGuard.php';
if (!defined('APP_SECRET')) {
    define('APP_SECRET', 'unit-test-app-secret-forms-captcha');
}
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

/** An enc:v1 envelope made with a key other than APP_SECRET (what a rotated secret leaves behind). */
function fcse_foreign_envelope(string $plain): string
{
    $key = hash('sha256', 'some-other-app-secret', true);
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:v1:' . base64_encode($iv . $tag . $ct);
}

unit('forms captcha: a newly typed secret is stored as an envelope that decrypts to it', function (): void {
    $r = FormsSpamGuard::resolveStoredSecret('', '  0xNEWSECRET  ', false);
    assert_null($r['error']);
    assert_true(str_starts_with($r['secret'], 'enc:v1:'), 'stored value must be an encryption envelope');
    assert_false(str_contains($r['secret'], '0xNEWSECRET'), 'plaintext must not appear in the stored value');
    assert_eq('0xNEWSECRET', FormsSpamGuard::captchaSecret(['captcha_secret' => $r['secret']]), 'runtime read returns the trimmed secret');
});

unit('forms captcha: a blank field keeps the stored envelope byte-for-byte', function (): void {
    $env = slate_encrypt_secret('0xKEEPME');
    $r = FormsSpamGuard::resolveStoredSecret($env, '', false);
    assert_null($r['error']);
    assert_eq($env, $r['secret']);
    $r = FormsSpamGuard::resolveStoredSecret($env, "   \t", false);
    assert_eq($env, $r['secret'], 'whitespace-only input counts as blank');
});

unit('forms captcha: a legacy plaintext secret is upgraded to an envelope on save and still works', function (): void {
    $r = FormsSpamGuard::resolveStoredSecret('0xLEGACYPLAIN', '', false);
    assert_null($r['error']);
    assert_true(str_starts_with($r['secret'], 'enc:v1:'), 'legacy plaintext must be re-encrypted on save');
    assert_eq('0xLEGACYPLAIN', FormsSpamGuard::captchaSecret(['captcha_secret' => $r['secret']]));
    // Not yet re-saved: the runtime still reads legacy plaintext unchanged.
    assert_eq('0xLEGACYPLAIN', FormsSpamGuard::captchaSecret(['captcha_secret' => '0xLEGACYPLAIN']));
});

unit('forms captcha: the clear checkbox removes the secret and wins over a typed value', function (): void {
    $env = slate_encrypt_secret('0xOLD');
    assert_eq(['secret' => '', 'error' => null], FormsSpamGuard::resolveStoredSecret($env, '', true));
    assert_eq(['secret' => '', 'error' => null], FormsSpamGuard::resolveStoredSecret($env, '0xTYPED', true));
    assert_false(FormsSpamGuard::captchaEnabled(['captcha_provider' => 'turnstile', 'captcha_site_key' => 'k', 'captcha_secret' => '']), 'a cleared secret disables CAPTCHA');
});

unit('forms captcha: an envelope that cannot be decrypted is reported as unusable, never passed through', function (): void {
    $foreign = fcse_foreign_envelope('0xROTATED');
    assert_null(FormsSpamGuard::captchaSecret(['captcha_secret' => $foreign]));
    // verifyCaptcha fails closed before any provider call is made.
    $spam = FormsSpamGuard::normalize([
        'captcha_provider' => 'turnstile', 'captcha_site_key' => 'site', 'captcha_secret' => $foreign,
    ]);
    assert_true(FormsSpamGuard::captchaEnabled($spam), 'precondition: CAPTCHA is configured');
    assert_false(FormsSpamGuard::verifyCaptcha($spam, ['cf-turnstile-response' => 'tok'], '127.0.0.1'), 'undecryptable secret must reject the submission');
});

unit('forms captcha: with no APP_SECRET a new secret is refused, nothing plaintext is stored, and a stored value is kept', function (): void {
    $code = 'require ' . var_export(SLATE_ROOT . '/includes/helpers.php', true) . ';'
          . 'require ' . var_export(SLATE_ROOT . '/plugins/forms/lib/FormsSpamGuard.php', true) . ';'
          . 'echo json_encode(['
          . '"defined" => defined("APP_SECRET"),'
          . '"typed" => FormsSpamGuard::resolveStoredSecret("enc:v1:STOREDENVELOPE", "0xPLAINNEW", false),'
          . '"fresh" => FormsSpamGuard::resolveStoredSecret("", "0xPLAINNEW", false),'
          . '"legacy" => FormsSpamGuard::resolveStoredSecret("0xLEGACY", "", false),'
          . ']);';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    $res = json_decode($out, true);
    assert_true(is_array($res), 'child process output: ' . $out);
    assert_false($res['defined'], 'precondition: the child has no APP_SECRET');
    assert_eq('encryption_unavailable', $res['typed']['error']);
    assert_eq('enc:v1:STOREDENVELOPE', $res['typed']['secret'], 'the existing stored value is kept when a new one cannot be encrypted');
    assert_eq('encryption_unavailable', $res['fresh']['error']);
    assert_eq('', $res['fresh']['secret'], 'a new secret is never stored in plaintext');
    assert_false(str_contains($out, '0xPLAINNEW'), 'the typed plaintext must not come back in any stored value');
    assert_eq(['secret' => '0xLEGACY', 'error' => null], $res['legacy'], 'a legacy value is left as-is (not lost) when it cannot be upgraded');
});
