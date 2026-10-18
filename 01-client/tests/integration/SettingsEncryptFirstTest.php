<?php
/**
 * Settings pages that carry a secret encrypt it BEFORE writing anything: if
 * the secret cannot be encrypted (no APP_SECRET), nothing on the form is
 * saved and the page says so. Before, Backups, Booking and the AI connection
 * saved every other field and only skipped the secret, leaving the form
 * half-applied behind a "saved, but…" message.
 *
 * Each case drives the real admin page through the POST probe in a child
 * process. The "no APP_SECRET" child defines APP_SECRET as '' before
 * config.php runs (a constant cannot be redefined, so config's value is
 * ignored), which is exactly the state of an install without the secret.
 */

declare(strict_types=1);

/**
 * Snapshot every setting under these prefixes (the whole form writes far
 * more than the fields a case asserts: a real POST with unticked boxes turns
 * Booking capabilities and toggles off) and restore them exactly, deleting
 * rows the test created.
 */
function sefs_snapshot(array $prefixes): callable
{
    $tid = current_tenant_id();
    $rows = [];
    foreach ($prefixes as $p) {
        foreach (Database::rows('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key LIKE ?', [$tid, $p . '%']) as $r) {
            $rows[$r['setting_key']] = $r['setting_value'];
        }
    }
    return static function () use ($tid, $prefixes, $rows): void {
        foreach ($prefixes as $p) {
            Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE ?', [$tid, $p . '%']);
        }
        foreach ($rows as $k => $v) {
            Database::setSetting((string) $k, (string) $v, $tid);
        }
    };
}

function sefs_stored(string $key): string
{
    return (string) Database::value('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?', [current_tenant_id(), $key]);
}

/** POST a page in a child process; $withSecret=false runs it with APP_SECRET ''. Returns [status, body]. */
function sefs_post(string $page, array $fields, bool $withSecret): array
{
    $probe = dirname(__DIR__) . '/fixtures/admin-page-post-probe.php';
    $args  = var_export(['probe', $page, (string) json_encode($fields), '1', '0', '', '1'], true);
    $code  = ($withSecret ? '' : "define('APP_SECRET', '');")
           . '$argv = ' . $args . '; $argc = count($argv); $_SERVER["argv"] = $argv;'
           . 'require ' . var_export($probe, true) . ';';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($code) . ' 2>/dev/null');
    if (!preg_match('/^STATUS (\d+)$/m', $out, $m)) {
        throw new RuntimeException('probe produced no STATUS line: ' . substr($out, 0, 300));
    }
    return [(int) $m[1], $out];
}

$sefs_cases = [
    'backups' => [
        'page'   => 'plugins/backups/admin/settings.php',
        'fields' => ['_action' => 'save_settings', 'client_id' => 'cid-after', 'client_secret' => 'gsec-new', 'retention_count' => '30'],
        'plain'  => ['backups.google_client_id' => ['cid-before', 'cid-after'], 'backups.retention_count' => ['7', '30']],
        'secret' => ['backups.google_client_secret', 'gsec-new'],
        'prefixes' => ['backups.'],
    ],
    'booking' => [
        'page'   => 'plugins/booking/admin/settings.php',
        'fields' => ['google_client_id' => 'gcid-after', 'twilio_token' => 'tw-new', 'twilio_sid' => 'AC-after', 'multislot_max' => '9'],
        'plain'  => ['booking.google_client_id' => ['gcid-before', 'gcid-after'], 'booking.twilio_sid' => ['AC-before', 'AC-after']],
        'secret' => ['booking.twilio_token', 'tw-new'],
        'prefixes' => ['booking.', 'embed_allowed_origins'],
    ],
    'ai' => [
        'page'   => 'plugins/mcp-gateway/admin/ai-settings.php',
        'fields' => ['ai_base_url' => '', 'ai_model' => 'model-after', 'ai_api_key' => 'ai-new'],
        'plain'  => ['mcp-gateway.ai_model' => ['model-before', 'model-after']],
        'secret' => ['mcp-gateway.ai_api_key', 'ai-new'],
        'prefixes' => ['mcp-gateway.'],
    ],
];

foreach ($sefs_cases as $name => $c) {
    unit("encrypt-first save ({$name}): with no APP_SECRET nothing on the form is saved and the page says so", function () use ($c): void {
        assert_true(slate_has_app_secret(), 'precondition: APP_SECRET is configured for the parent test process');
        $restore = sefs_snapshot($c['prefixes']);
        try {
            foreach ($c['plain'] as $k => [$before]) Database::setSetting($k, $before);
            $storedSecret = slate_encrypt_secret('existing-secret');
            Database::setSetting($c['secret'][0], $storedSecret);

            [$status, $body] = sefs_post($c['page'], $c['fields'], false);
            assert_eq(200, $status);
            assert_true(str_contains($body, 'Nothing was saved'), 'the page must say nothing was saved');
            foreach ($c['plain'] as $k => [$before]) {
                assert_eq($before, sefs_stored($k), "{$k} must be unchanged");
            }
            assert_eq($storedSecret, sefs_stored($c['secret'][0]), 'the stored secret is untouched');
            assert_false(str_contains(sefs_stored($c['secret'][0]), $c['secret'][1]), 'no plaintext');
        } finally {
            $restore();
        }
    });

    unit("encrypt-first save ({$name}): with APP_SECRET the form saves and the secret is an envelope", function () use ($c): void {
        $restore = sefs_snapshot($c['prefixes']);
        try {
            foreach ($c['plain'] as $k => [$before]) Database::setSetting($k, $before);
            [$status] = sefs_post($c['page'], $c['fields'], true);
            assert_eq(200, $status);
            foreach ($c['plain'] as $k => [, $after]) {
                assert_eq($after, sefs_stored($k), "{$k} must be saved");
            }
            $env = sefs_stored($c['secret'][0]);
            assert_true(str_starts_with($env, 'enc:v1:'), 'secret stored as an envelope');
            assert_eq($c['secret'][1], slate_decrypt_secret($env));
        } finally {
            $restore();
        }
    });
}
