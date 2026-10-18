<?php
/**
 * MCP slate_settings_set may change only the general settings it lists,
 * each validated like admin/settings.php; slate_settings_get hides
 * credentials and encrypted values. Before this, the tool refused only
 * keys matching pass|secret, so a token with the settings.write scope
 * could repoint smtp_host or mcp-gateway.ai_base_url (sending the stored
 * credentials to another server) or overwrite *_token / *_api_key keys
 * with plaintext.
 *
 * Every setting touched here is snapshotted and restored.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/mcp-gateway/CoreMcpTools.php';

function msa_call(string $tool, array $args, array $scopes): mixed
{
    return \CoreMcpTools::callTool(null, $tool, $args, ['scopes' => $scopes]);
}

function msa_snapshot(array $keys): callable
{
    $prior = [];
    foreach ($keys as $k) {
        $prior[$k] = Database::row('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?', [current_tenant_id(), $k]);
    }
    return static function () use ($prior): void {
        foreach ($prior as $k => $row) {
            if ($row === null) {
                Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [current_tenant_id(), $k]);
            } else {
                Database::setSetting($k, (string) $row['setting_value']);
            }
        }
    };
}

const MSA_W = ['mcp-gateway.settings.write'];
const MSA_R = ['mcp-gateway.settings.read'];

unit('MCP settings.set refuses server, URL, credential, security and plugin keys and leaves them unchanged', function (): void {
    $keys = ['smtp_host', 'smtp_port', 'mcp-gateway.ai_base_url', 'smtp_oauth_access_token', 'backups.google_access_token',
             'multilang.autotranslate_api_key', 'force_https', 'session_timeout_minutes', 'booking.cap.admin.manage', 'license_sync.last_attempt'];
    $restore = msa_snapshot($keys);
    try {
        foreach ($keys as $k) {
            $before = Database::setting($k);
            $refused = false;
            try { msa_call('slate_settings_set', ['key' => $k, 'value' => 'evil.example'], MSA_W); }
            catch (\InvalidArgumentException $e) { $refused = str_contains($e->getMessage(), "can't be changed through MCP"); }
            assert_true($refused, "{$k} is refused with a pointer to the admin UI");
            assert_eq($before, Database::setting($k), "{$k} unchanged");
        }
    } finally {
        $restore();
    }
});

unit('MCP settings.set accepts the listed general settings and validates them like the admin page', function (): void {
    $keys = ['site_name', 'business_email', 'currency', 'currency_position', 'default_language', 'timezone', 'date_format', 'start_of_week', 'business_address'];
    $restore = msa_snapshot($keys);
    try {
        msa_call('slate_settings_set', ['key' => 'site_name', 'value' => "  My <b>Site</b>\n "], MSA_W);
        assert_eq('My Site', Database::setting('site_name'), 'tags and extra whitespace stripped');
        msa_call('slate_settings_set', ['key' => 'currency', 'value' => 'eur'], MSA_W);
        assert_eq('EUR', Database::setting('currency'));
        msa_call('slate_settings_set', ['key' => 'timezone', 'value' => 'Europe/Brussels'], MSA_W);
        assert_eq('Europe/Brussels', Database::setting('timezone'));
        msa_call('slate_settings_set', ['key' => 'business_address', 'value' => str_repeat('a', 600)], MSA_W);
        assert_eq(500, mb_strlen((string) Database::setting('business_address')), 'length capped as on the page');

        $invalid = [
            ['site_name', '   '], ['business_email', 'not-an-email'], ['currency', 'euro€'], ['currency_position', 'middle'],
            ['default_language', 'english'], ['timezone', 'Mars/Olympus'], ['date_format', '<script>'], ['start_of_week', '7'],
        ];
        foreach ($invalid as [$k, $v]) {
            $before = Database::setting($k);
            $threw = false;
            try { msa_call('slate_settings_set', ['key' => $k, 'value' => $v], MSA_W); } catch (\InvalidArgumentException $e) { $threw = true; }
            assert_true($threw, "{$k}={$v} rejected");
            assert_eq($before, Database::setting($k), "{$k} unchanged after a rejected value");
        }
    } finally {
        $restore();
    }
});

unit('MCP settings.get hides credentials by key name and any encrypted value', function (): void {
    $keys = ['smtp_oauth_access_token', 'mcp-gateway.ai_api_key', 'msa_probe_plain', 'msa_probe_enc'];
    $restore = msa_snapshot($keys);
    try {
        Database::setSetting('smtp_oauth_access_token', 'tok-123');
        Database::setSetting('mcp-gateway.ai_api_key', 'key-123');
        Database::setSetting('msa_probe_plain', 'visible');
        Database::setSetting('msa_probe_enc', 'enc:v1:abcdef');
        $out = msa_call('slate_settings_get', ['keys' => $keys], MSA_R)['settings'];
        assert_eq('[hidden]', $out['smtp_oauth_access_token']);
        assert_eq('[hidden]', $out['mcp-gateway.ai_api_key']);
        assert_eq('[hidden]', $out['msa_probe_enc'], 'an encrypted envelope is never returned, whatever the key');
        assert_eq('visible', $out['msa_probe_plain']);
    } finally {
        $restore();
    }
});

unit('MCP settings.set still requires the settings.write scope', function (): void {
    $threw = false;
    try { msa_call('slate_settings_set', ['key' => 'site_name', 'value' => 'x'], MSA_R); } catch (\Throwable $e) { $threw = true; }
    assert_true($threw, 'read scope cannot write');
});

unit('MCP audit log redacts the value of a settings write whose key names a credential', function (): void {
    require_once dirname(__DIR__, 2) . '/plugins/mcp-gateway/McpGatewayAPI.php';
    $redact = new \ReflectionMethod(\McpGatewayAPI::class, 'redact');
    foreach (['smtp_oauth_access_token', 'mcp-gateway.ai_api_key', 'smtp_pass', 'stripe-payment.live_webhook_secret'] as $k) {
        $out = $redact->invoke(null, ['key' => $k, 'value' => 'plain-credential']);
        assert_eq('[redacted]', $out['value'], "{$k}: value redacted in the audit log");
        assert_eq($k, $out['key'], 'the key itself is kept');
    }
    assert_eq('My Site', $redact->invoke(null, ['key' => 'site_name', 'value' => 'My Site'])['value'], 'ordinary values are logged');
});
