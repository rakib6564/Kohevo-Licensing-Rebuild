<?php
/**
 * Forms CAPTCHA secret through the real form editor (plugins/forms/admin/edit.php).
 *
 * Drives the editor's POST handler and GET render in child processes and
 * reads forms_definitions.settings_json back:
 *   - a typed secret is stored as an enc:v1 envelope, never plaintext
 *   - a blank field keeps the stored envelope byte-for-byte
 *   - a legacy plaintext secret is upgraded to an envelope on the next save
 *   - the "Clear saved secret" checkbox removes it
 *   - the editor page never renders the secret
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/forms/FormsAPI.php';

const FCSS_DSL = "text|full_name|Your name|required";

/** POST the editor. $id = 0 creates a form. Returns [status, body]. */
function fcss_post(int $id, array $fields): array
{
    $env = $id > 0 ? 'PROBE_QUERY=' . escapeshellarg('id=' . $id) . ' ' : '';
    $cmd = $env . escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('plugins/forms/admin/edit.php') . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe produced no STATUS line: ' . substr($out, 0, 400));
    }
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

function fcss_get(int $id): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg('plugins/forms/admin/edit.php') . ' '
         . escapeshellarg('id=' . $id) . ' '
         . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe produced no STATUS line: ' . substr($out, 0, 400));
    }
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

function fcss_fields(string $title, array $extra = []): array
{
    return array_merge([
        'title' => $title, 'fields_dsl' => FCSS_DSL, 'status' => 'draft',
        'sp_captcha_provider' => 'turnstile', 'sp_captcha_site_key' => '0xSITEKEY',
        'sp_captcha_secret' => '',
    ], $extra);
}

function fcss_stored_secret(int $id): string
{
    $json = (string) Database::value('SELECT settings_json FROM forms_definitions WHERE id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
    $set = json_decode($json, true);
    return (string) ($set['spam']['captcha_secret'] ?? '');
}

function fcss_cleanup(string $title): void
{
    $tid = current_tenant_id();
    foreach (Database::rows('SELECT id FROM forms_definitions WHERE tenant_id = ? AND title = ?', [$tid, $title]) as $r) {
        Database::query('DELETE FROM forms_webhooks WHERE form_id = ? AND tenant_id = ?', [(int) $r['id'], $tid]);
        Database::query('DELETE FROM forms_definitions WHERE id = ? AND tenant_id = ?', [(int) $r['id'], $tid]);
    }
}

unit('forms editor: the CAPTCHA secret is encrypted on save, kept when blank, upgraded from plaintext, cleared on request, and never rendered', function (): void {
    if (!slate_has_app_secret()) { assert_true(true, 'skipped: APP_SECRET not configured in the test environment'); return; }
    FormsAPI::ensureSchema();
    $title  = '__probe-fcss-' . bin2hex(random_bytes(5));
    $secret = '0xCAPTCHA' . bin2hex(random_bytes(10));
    $tid    = current_tenant_id();
    try {
        // 1. Create with a typed secret.
        [$status] = fcss_post(0, fcss_fields($title, ['sp_captcha_secret' => $secret]));
        assert_true(in_array($status, [200, 302, 303], true), "create must succeed, got {$status}");
        $id = (int) Database::value('SELECT id FROM forms_definitions WHERE tenant_id = ? AND title = ?', [$tid, $title]);
        assert_true($id > 0, 'the form row must exist');
        $stored = fcss_stored_secret($id);
        assert_true(str_starts_with($stored, 'enc:v1:'), 'a typed secret is stored as an envelope');
        assert_false(str_contains($stored, $secret), 'plaintext must not be stored');
        assert_eq($secret, slate_decrypt_secret($stored));

        // 2. Re-save with the field blank: envelope kept byte-for-byte.
        [$status] = fcss_post($id, fcss_fields($title));
        assert_true(in_array($status, [200, 302, 303], true), "re-save must succeed, got {$status}");
        assert_eq($stored, fcss_stored_secret($id), 'a blank field keeps the stored envelope unchanged');

        // 3. The editor page never renders the secret.
        [$gstatus, $body] = fcss_get($id);
        assert_eq(200, $gstatus);
        assert_false(str_contains($body, $secret), 'the plaintext secret must not appear in the editor');
        assert_false(str_contains($body, $stored), 'the stored envelope must not appear in the editor either');
        assert_true(str_contains($body, 'name="sp_captcha_clear_secret"'), 'a configured secret offers the clear checkbox');

        // 4. A legacy plaintext value is upgraded on the next save.
        $set = json_decode((string) Database::value('SELECT settings_json FROM forms_definitions WHERE id = ?', [$id]), true);
        $set['spam']['captcha_secret'] = 'legacy-' . $secret;
        Database::update('forms_definitions', ['settings_json' => json_encode($set)], 'id = ? AND tenant_id = ?', [$id, $tid]);
        fcss_post($id, fcss_fields($title));
        $upgraded = fcss_stored_secret($id);
        assert_true(str_starts_with($upgraded, 'enc:v1:'), 'legacy plaintext is re-encrypted on save');
        assert_eq('legacy-' . $secret, slate_decrypt_secret($upgraded));

        // 5. The clear checkbox removes it.
        fcss_post($id, fcss_fields($title, ['sp_captcha_clear_secret' => '1']));
        assert_eq('', fcss_stored_secret($id), 'the clear checkbox removes the secret');
    } finally {
        fcss_cleanup($title);
    }
});
