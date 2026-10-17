<?php
/**
 * Static regression checks over the shipped files (they read the production
 * source; they do not execute the admin pages). The end-to-end Stripe save
 * path is covered by tests/integration/StripeWriteOnlySecretTest.php.
 *
 * Regression and verification unit tests for the Complete System Audit fixes:
 * 1. Secret handling: write-only inputs and preservation of existing secrets when left blank.
 * 2. Permalinks structure allow-list validation.
 * 3. Date & Time bounds for start_of_week (0..6).
 * 4. Timezone canonical ownership (Date & Time tab vs System tab).
 * 5. Backups retention bounds (1..365).
 * 6. Coaching settings audit logging.
 * 7. Fail-closed encryption for sensitive secrets.
 * 8. Branding tokens: font family and corner radius custom properties emission.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}

require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

/** The body of one `elseif ($action === '<name>')` branch in admin/settings.php. */
function ssaf_settings_branch(string $action): string
{
    $src = (string) file_get_contents(SLATE_ROOT . '/admin/settings.php');
    $pos = strpos($src, "\$action === '{$action}'");
    assert_true($pos !== false, "settings.php must still handle {$action}");
    $next = strpos($src, "elseif (\$action === '", $pos + 10);
    return substr($src, $pos, ($next !== false ? $next : $pos + 4000) - $pos);
}

unit('audit fixes: the permalink allow-list in save_permalinks matches the choices the form offers', function (): void {
    $branch = ssaf_settings_branch('save_permalinks');
    assert_true((bool) preg_match('/\$allowedStructs\s*=\s*\[(.*?)\];/s', $branch, $m), 'save_permalinks must declare $allowedStructs');
    preg_match_all("/'([^']+)'/", $m[1], $allowed);
    assert_true(str_contains($branch, 'in_array($struct, $allowedStructs, true)'), 'the posted structure must be checked strictly against the allow-list');
    assert_true(str_contains($branch, "\$struct = '/p/%postname%';"), 'an unknown structure must fall back to the default');

    // Every radio the Permalinks tab renders must be accepted, and nothing else.
    $src = (string) file_get_contents(SLATE_ROOT . '/admin/settings.php');
    preg_match_all('/name="permalink_structure" value="([^"]+)"/', $src, $radios);
    $offered = $radios[1];
    sort($offered);
    $accepted = $allowed[1];
    sort($accepted);
    assert_true($offered !== [], 'the Permalinks tab must render structure choices');
    assert_eq($offered, $accepted, 'allow-list and form choices must be the same set');
});

unit('audit fixes: save_datetime bounds start_of_week to 0..6 before saving it', function (): void {
    $branch = ssaf_settings_branch('save_datetime');
    $guard = strpos($branch, 'if ($startOfWeek < 0 || $startOfWeek > 6)');
    $write = strpos($branch, "Database::setSetting('start_of_week'");
    assert_true($guard !== false, 'save_datetime must range-check start_of_week');
    assert_true($write !== false && $guard < $write, 'the range check must run before the value is written');
});

unit('audit fixes: backups retention_count is clamped to 1..365 on save and in the form', function (): void {
    $src = (string) file_get_contents(SLATE_ROOT . '/plugins/backups/admin/settings.php');
    assert_true(str_contains($src, "Database::setSetting('backups.retention_count', (string) max(1, min(365, (int)(\$_POST['retention_count'] ?? 14))));"), 'the save path must clamp retention_count to 1..365');
    assert_true((bool) preg_match('/name="retention_count"[^>]*min="1"[^>]*max="365"/', $src), 'the input must declare the same bounds');
});

unit('audit fixes: forms editor routes the captcha secret through FormsSpamGuard and never echoes it', function (): void {
    // Behaviour (encrypt / keep / upgrade / clear / no APP_SECRET) is covered by
    // FormsCaptchaSecretEncryptionTest (unit) and FormsCaptchaSecretSaveTest
    // (integration); this pins the editor's wiring to that code.
    $src = (string) file_get_contents(SLATE_ROOT . '/plugins/forms/admin/edit.php');
    assert_true(str_contains($src, "FormsSpamGuard::resolveStoredSecret(\n            (string)(\$fset['spam']['captcha_secret'] ?? ''),\n            (string)(\$_POST['sp_captcha_secret'] ?? ''),\n            !empty(\$_POST['sp_captcha_clear_secret'])\n        );"), 'the editor must resolve the secret via FormsSpamGuard::resolveStoredSecret(stored, posted, clear)');
    assert_true(str_contains($src, "'captcha_secret'   => \$savedCaptchaSecret,"), 'the saved secret must be the resolved value, not raw $_POST');
    assert_true(str_contains($src, "\$fieldErrors['sp_captcha_secret']"), 'an encryption failure must block the save');
    assert_true((bool) preg_match('/name="sp_captcha_secret"[^>]*\n\s*value=""/', $src), 'the secret input must render empty');
    assert_false(str_contains($src, "value=\"<?= e(\$sp['captcha_secret']) ?>\""), 'the stored secret must never be echoed into the page');
    $guard = (string) file_get_contents(SLATE_ROOT . '/plugins/forms/lib/FormsSpamGuard.php');
    assert_true(str_contains($guard, "'secret'   => \$secret,"), 'the provider call must use the decrypted secret');
});

unit('audit fixes: Stripe secret and webhook inputs never echo the stored secret', function (): void {
    $src = (string) file_get_contents(SLATE_ROOT . '/plugins/stripe-payment/admin/settings.php');
    foreach (['secret_key', 'webhook_secret'] as $name) {
        assert_true((bool) preg_match('/name="' . $name . '"\s*\n\s*value=""/', $src), "{$name} input must render empty");
    }
    assert_false(str_contains($src, 'value="<?= e($secretKey) ?>"'), 'secret key must not be echoed');
    assert_false(str_contains($src, 'value="<?= e($webhookSecret) ?>"'), 'webhook secret must not be echoed');
});

unit('audit fixes: coaching settings updates record AuditLog', function (): void {
    $src = file_get_contents(SLATE_ROOT . '/plugins/coaching/admin/settings.php');
    assert_true(str_contains($src, "AuditLog::record('coaching.settings_updated')"), 'coaching settings records audit log');
});

unit('audit fixes: system settings does not overwrite timezone', function (): void {
    $branch = ssaf_settings_branch('save_system');
    assert_false(str_contains($branch, "Database::setSetting('timezone'"), 'save_system must not write timezone');
    assert_true(str_contains(ssaf_settings_branch('save_datetime'), "Database::setSetting('timezone'"), 'save_datetime remains the one place timezone is written');
});

unit('audit fixes: secret settings encrypt fail-closed (Stripe catches itself; Backups, Booking and AI seal every secret first)', function (): void {
    $stripe = file_get_contents(SLATE_ROOT . '/plugins/stripe-payment/admin/settings.php');
    assert_true(str_contains($stripe, 'catch (\Throwable') || str_contains($stripe, 'catch (Throwable'), 'stripe settings must handle encryption failure via try/catch');

    // The shared step encrypts every posted secret and returns null on any failure.
    $helpers = file_get_contents(SLATE_ROOT . '/includes/helpers.php');
    $start = strpos($helpers, 'function slate_seal_posted_secrets(');
    assert_true($start !== false, 'slate_seal_posted_secrets() exists');
    $body = substr($helpers, $start, 1200);
    assert_true(str_contains($body, 'slate_encrypt_secret(') && str_contains($body, 'catch (\Throwable') && str_contains($body, 'return null;'), 'the seal step catches encryption failure and returns null');

    // Each page seals first, refuses the save when sealing fails, and never calls the throwing encryptor directly.
    foreach (['plugins/backups/admin/settings.php', 'plugins/booking/admin/settings.php', 'plugins/mcp-gateway/admin/ai-settings.php'] as $f) {
        $content = file_get_contents(SLATE_ROOT . '/' . $f);
        assert_true(str_contains($content, 'slate_seal_posted_secrets('), "$f must seal its secrets before saving");
        assert_true(str_contains($content, "__('settings_not_saved_encryption'"), "$f must refuse the whole save when sealing fails");
        assert_false(str_contains($content, 'slate_encrypt_secret('), "$f must not call slate_encrypt_secret() directly (uncaught, or a half-saved form)");
    }
});

unit('audit fixes: branding CSS emitter wires font-family and border-radius tokens', function (): void {
    $ui = file_get_contents(SLATE_ROOT . '/includes/ui_components.php');
    assert_true(str_contains($ui, "Database::setting('brand_font_family')"), 'ui_components reads brand_font_family');
    assert_true(str_contains($ui, "Database::setting('brand_border_radius')"), 'ui_components reads brand_border_radius');
    assert_true(str_contains($ui, '--font-sans:'), 'ui_components emits --font-sans variable');
    assert_true(str_contains($ui, '--radius-sm:'), 'ui_components emits --radius-sm variable');
});
