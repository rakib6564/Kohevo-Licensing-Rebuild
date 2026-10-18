<?php
/**
 * Secret-handling gaps closed after the settings audit, driven through the
 * real admin pages (child-process probes) and the real runtime code:
 *   - mcp-gateway AI base URL must be a public https address: a private one
 *     is refused on save (nothing on the form is written) and refused again
 *     at call time, before any request is made;
 *   - multilang auto-translate API key is write-only and encrypted at rest:
 *     saved as an envelope, kept when blank, cleared on request, decrypted
 *     for the provider, legacy plaintext still readable, never rendered.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/mcp-gateway/AiProviderClient.php';
require_once dirname(__DIR__, 2) . '/plugins/multilang-translate/includes/AutoTranslate.php';

function shg_snapshot(string $prefix): callable
{
    $tid = current_tenant_id();
    $rows = Database::rows('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key LIKE ?', [$tid, $prefix . '%']);
    return static function () use ($tid, $prefix, $rows): void {
        Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE ?', [$tid, $prefix . '%']);
        foreach ($rows as $row) {
            Database::setSetting($row['setting_key'], $row['setting_value'], $tid);
        }
    };
}

function shg_stored(string $key): string
{
    return (string) Database::value('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?', [current_tenant_id(), $key]);
}

/** Run a page in a child process. $fields = null means GET. Returns [status, body]. */
function shg_probe(string $page, ?array $fields): array
{
    $fixture = $fields === null ? 'admin-page-probe.php' : 'admin-page-post-probe.php';
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture) . ' '
         . escapeshellarg($page) . ' '
         . escapeshellarg($fields === null ? '' : (string) json_encode($fields)) . ' '
         . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe produced no STATUS line: ' . substr($out, 0, 400));
    }
    return [(int) $m[1], substr($out, strlen($m[0]))];
}

unit('AI connection: a private or non-https base URL is refused on save and nothing on the form is written', function (): void {
    $restore = shg_snapshot('mcp-gateway.ai_');
    try {
        Database::setSetting('mcp-gateway.ai_base_url', 'https://1.1.1.1/v1');
        Database::setSetting('mcp-gateway.ai_model', 'model-before');
        foreach (['https://169.254.169.254/latest', 'http://1.1.1.1/v1', 'https://127.0.0.1:8080/v1', 'https://10.0.0.2/v1'] as $bad) {
            [$status, $body] = shg_probe('plugins/mcp-gateway/admin/ai-settings.php', ['ai_base_url' => $bad, 'ai_model' => 'model-after', 'ai_api_key' => '']);
            assert_eq(200, $status);
            assert_true(str_contains($body, 'Settings not saved'), "{$bad}: the page must say the save was refused");
            assert_eq('https://1.1.1.1/v1', shg_stored('mcp-gateway.ai_base_url'), "{$bad}: the stored URL must be unchanged");
            assert_eq('model-before', shg_stored('mcp-gateway.ai_model'), "{$bad}: other fields must not be written either");
        }
        [$status, $body] = shg_probe('plugins/mcp-gateway/admin/ai-settings.php', ['ai_base_url' => 'https://8.8.8.8/v1', 'ai_model' => 'model-after', 'ai_api_key' => '']);
        assert_eq(200, $status);
        assert_eq('https://8.8.8.8/v1', shg_stored('mcp-gateway.ai_base_url'), 'a public https address saves');
        assert_eq('model-after', shg_stored('mcp-gateway.ai_model'));
    } finally {
        $restore();
    }
});

unit('AI connection: a private base URL already in the database is refused at call time, before any request', function (): void {
    if (!slate_has_app_secret()) { assert_true(true, 'skipped: APP_SECRET not configured in the test environment'); return; }
    $restore = shg_snapshot('mcp-gateway.ai_');
    try {
        Database::setSetting('mcp-gateway.ai_base_url', 'https://169.254.169.254/latest');
        Database::setSetting('mcp-gateway.ai_model', 'm');
        Database::setSetting('mcp-gateway.ai_api_key', slate_encrypt_secret('k'));
        $msg = '';
        $t0 = microtime(true);
        try { AiProviderClient::chat([['role' => 'user', 'content' => 'hi']]); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
        assert_true(str_contains($msg, 'AI provider URL is not allowed (private_address)'), 'chat() must refuse the stored internal URL: ' . $msg);
        assert_true(microtime(true) - $t0 < 1.0, 'refusal happens before any network request');
    } finally {
        $restore();
    }
});

unit('Multilang: the auto-translate API key is saved encrypted, kept when blank, cleared on request, and never rendered', function (): void {
    if (!slate_has_app_secret()) { assert_true(true, 'skipped: APP_SECRET not configured in the test environment'); return; }
    $restore = shg_snapshot(MLT_AutoTranslate::API_KEY_SETTING);
    $key = 'lt-' . bin2hex(random_bytes(10));
    $api = 'plugins/multilang-translate/admin/api.php';
    try {
        Database::setSetting(MLT_AutoTranslate::API_KEY_SETTING, '');
        [$status, $body] = shg_probe($api, ['action' => 'save_autotranslate_key', 'api_key' => $key, 'clear' => '0']);
        assert_eq(200, $status, 'save must succeed: ' . $body);
        assert_false(str_contains($body, $key), 'the response must not echo the key');
        $stored = shg_stored(MLT_AutoTranslate::API_KEY_SETTING);
        assert_true(str_starts_with($stored, 'enc:v1:'), 'stored as an envelope');
        assert_false(str_contains($stored, $key));
        assert_eq($key, MLT_AutoTranslate::apiKey(), 'the provider receives the decrypted key');

        shg_probe($api, ['action' => 'save_autotranslate_key', 'api_key' => '', 'clear' => '0']);
        assert_eq($stored, shg_stored(MLT_AutoTranslate::API_KEY_SETTING), 'a blank field keeps the stored envelope');

        [$gstatus, $page] = shg_probe('plugins/multilang-translate/admin/index.php', null);
        assert_eq(200, $gstatus);
        assert_false(str_contains($page, $key) || str_contains($page, $stored), 'the settings page renders neither the key nor its envelope');
        assert_true(str_contains($page, 'id="mlt-cz-at-clear"'), 'a configured key offers the clear checkbox');

        shg_probe($api, ['action' => 'save_autotranslate_key', 'api_key' => '', 'clear' => '1']);
        assert_eq('', shg_stored(MLT_AutoTranslate::API_KEY_SETTING), 'clear removes the key');
        assert_null(MLT_AutoTranslate::apiKey());

        Database::setSetting(MLT_AutoTranslate::API_KEY_SETTING, 'legacy-plain-key');
        assert_eq('legacy-plain-key', MLT_AutoTranslate::apiKey(), 'a legacy plaintext key keeps working until re-saved');
    } finally {
        $restore();
    }
});
