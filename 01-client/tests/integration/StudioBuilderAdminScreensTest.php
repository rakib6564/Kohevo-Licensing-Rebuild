<?php
/**
 * Integration tests for Kohevo Studio (studio-builder) — the admin screens (2026-10-26 builder audit: admin findings).
 *
 * Driven through the real screens in child processes (tests/fixtures/admin-page-post-probe.php), as super admin, on the
 * test database. Each setting a test touches is put back as it was.
 *
 * - Types & fields: the first field a site adds keeps the starter fields every page already shows.
 * - Redirects: an empty address is refused as missing, not normalised to "/".
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Meta\PageMeta;


/** POST to an admin screen as super admin; returns [status, output]. */
function sbasi_post(string $screen, array $fields, string $query = ''): array
{
    $cmd = ($query !== '' ? 'PROBE_QUERY=' . escapeshellarg($query) . ' ' : '')
         . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('plugins/studio-builder/admin/' . $screen) . ' ' . escapeshellarg((string) json_encode($fields)) . ' 1 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)$/m', $out, $m);
    return [(int) ($m[1] ?? 0), $out];
}

/** Runs $fn with the tenant setting $key as given (null: absent), then puts the original back. */
function sbasi_with_setting(string $key, ?string $value, callable $fn): void
{
    $tid = current_tenant_id();
    $before = Database::row('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, $key]);
    try {
        Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, $key]);
        if ($value !== null) {
            Database::setSetting($key, $value, $tid);
        }
        $fn($tid);
    } finally {
        Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, $key]);
        if ($before !== null) {
            Database::setSetting($key, (string) $before['setting_value'], $tid);
        }
    }
}

unit('types & fields: the first field added to a new site keeps the starter fields', function (): void {
    sbasi_with_setting('studio_meta_fields', null, function (int $tid): void {
        $starterKeys = array_column(PageMeta::starterFields(), 'key');
        assert_eq($starterKeys, array_column(PageMeta::allFields($tid), 'key'), 'a new site shows the starters');
        [$status] = sbasi_post('types-fields.php', [
            '_action' => 'create_meta_field', 'post_type' => 'portfolio', 'label' => 'Budget', 'key' => 'budget', 'type' => 'text',
        ]);
        assert_eq(303, $status, 'back to the screen after the change');
        $keys = array_column(PageMeta::allFields($tid), 'key');
        assert_eq(array_merge($starterKeys, ['budget']), $keys, 'the starters are still there, with the new field after them');
        assert_eq($keys, array_column(PageMeta::fieldsFor($tid, 'portfolio'), 'key'), 'and the pages read the same list');
    });
});

unit('redirects: an empty address is refused as missing, and nothing is saved', function (): void {
    sbasi_with_setting('studio_redirects', '[]', function (int $tid): void {
        [$status, $out] = sbasi_post('redirects.php', ['_action' => 'add_redirect', 'from_path' => '', 'to_path' => '/new-page']);
        assert_eq(200, $status);
        assert_true(str_contains($out, 'Enter both the old address and where it should go.'), 'the required message, not the home-page one');
        assert_eq('[]', (string) Database::setting('studio_redirects', $tid), 'no rule was added');

        [$status] = sbasi_post('redirects.php', ['_action' => 'add_redirect', 'from_path' => '/old-sbasi', 'to_path' => '/new-page']);
        assert_eq(303, $status, 'a saved rule redirects back to the list');
        assert_eq(['/old-sbasi'], array_column(json_decode((string) Database::setting('studio_redirects', $tid), true), 'from'));
    });
});
