<?php
/**
 * Theme Builder › delete template (plugins/studio-builder/admin/templates.php),
 * driven through the real page in a child process.
 *
 * The handler used to find the backing page with
 * settings_json LIKE '%"template_id":<id>%', which also matches 50, 512, ...
 * for template 5, and then hard-deleted that page with its compilations and
 * revisions. It also ran for an unknown or system template id. Now: only
 * the exact backing page is touched, it is archived (revisions kept), and
 * the template goes through the Studio service (admin permission, audit).
 *
 * Everything created here is removed at the end.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

/**
 * The Studio services refuse an install without a trusted Studio
 * entitlement, like production. Seed one signed licence snapshot for this
 * tenant (and the matching installation identity), and return a restore
 * callable that puts both back exactly.
 */
function stdel_entitle(): callable
{
    $tid = current_tenant_id();
    $priorCache = Database::all('SELECT * FROM remote_license_cache WHERE tenant_id = ?', [$tid]);
    $priorIdentity = Database::row('SELECT * FROM installation_identity WHERE singleton_id = 1');
    $installationId = $priorIdentity['installation_id'] ?? str_repeat('d', 32);
    if ($priorIdentity === null) {
        Database::insert('installation_identity', ['singleton_id' => 1, 'tenant_id' => $tid, 'installation_id' => $installationId]);
    }
    license_test_seed_cache($tid, [
        'installation_id' => $installationId, 'status' => 'active', 'plan' => 'stdel',
        'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
    ]);
    return static function () use ($tid, $priorCache, $priorIdentity): void {
        Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [$tid]);
        foreach ($priorCache as $row) Database::insert('remote_license_cache', $row);
        if ($priorIdentity === null) Database::query('DELETE FROM installation_identity WHERE singleton_id = 1');
    };
}

function stdel_post(array $fields, int $roleId = 1): array
{
    $env = license_test_env_prefix() . 'LICENSE_SERVER_URL=https://license.test LICENSE_PRODUCT=kohevo LICENSE_KEY=test-key ';
    $cmd = $env . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('plugins/studio-builder/admin/templates.php') . ' ' . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)$/m', $out, $m);
    return [(int) ($m[1] ?? 0), $out];
}

/** A page plus one revision, shaped like the ones templates.php creates; returns [pageId, revisionId]. */
function stdel_page(string $slug, array $settings): array
{
    $tid = current_tenant_id();
    $uuid = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0x7fffffff), random_int(0, 0x7fffffff));
    $pageId = (int) Database::insert('studiobuilder_pages', [
        'tenant_id' => $tid, 'uuid' => $uuid, 'slug' => $slug, 'title' => '[Theme] ' . $slug,
        'page_type' => 'header_partial', 'route_mode' => 'standalone', 'status' => 'draft',
        'seo_json' => '{}', 'settings_json' => json_encode($settings),
        'created_by' => 1, 'updated_by' => 1, 'created_at' => slate_db_now(), 'updated_at' => slate_db_now(),
    ]);
    $doc = \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::emptyDocument('header_partial', 'default', $slug);
    $revId = (int) Database::insert('studiobuilder_revisions', [
        'tenant_id' => $tid, 'page_id' => $pageId, 'revision_number' => 1, 'revision_kind' => 'manual',
        'schema_version' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::SCHEMA_VERSION,
        'document_json' => \Slate\Module\StudioBuilder\Document\CanonicalJson::encode($doc),
        'summary' => 'test', 'created_by' => 1, 'created_at' => slate_db_now(),
    ]);
    Database::query('UPDATE studiobuilder_pages SET active_draft_revision_id = ? WHERE id = ? AND tenant_id = ?', [$revId, $pageId, $tid]);
    return [$pageId, $revId];
}

function stdel_template(string $key, int $isSystem = 0): int
{
    $doc = \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::emptyDocument('header_partial', 'default', $key);
    return (int) Database::insert('studiobuilder_templates', [
        'tenant_id' => current_tenant_id(), 'uuid' => sprintf('%08x-1111-4000-8000-%012x', random_int(0, 0x7fffffff), random_int(0, 0x7fffffff)),
        'template_key' => $key, 'template_type' => 'header_preset', 'category' => 'header', 'name' => $key,
        'schema_version' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::SCHEMA_VERSION,
        'document_json' => \Slate\Module\StudioBuilder\Document\CanonicalJson::encode($doc),
        'is_system' => $isSystem, 'created_by' => 1,
    ]);
}

function stdel_cleanup(array $pageIds, array $templateIds): void
{
    $tid = current_tenant_id();
    foreach ($pageIds as $p) {
        Database::query('DELETE FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [$tid, $p]);
        Database::query('UPDATE studiobuilder_pages SET active_draft_revision_id = NULL, published_revision_id = NULL WHERE tenant_id = ? AND id = ?', [$tid, $p]);
        Database::query('DELETE FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [$tid, $p]);
        Database::query('DELETE FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [$tid, $p]);
    }
    foreach ($templateIds as $t) {
        Database::query('DELETE FROM studiobuilder_templates WHERE tenant_id = ? AND id = ?', [$tid, $t]);
    }
}

unit('theme builder delete: only the exact backing page is affected, it is archived not erased, and its revisions stay', function (): void {
    $unentitle = stdel_entitle();
    $tag = bin2hex(random_bytes(3));
    $tplId = stdel_template("stdel-{$tag}");
    // The decoy's template_id starts with the doomed id's digits (5 -> 57), which the old LIKE matched.
    [$backing, $backingRev] = stdel_page("template-stdel-{$tag}", ['is_theme_template' => true, 'template_id' => $tplId, 'template_type' => 'header']);
    [$decoy, $decoyRev]     = stdel_page("stdel-decoy-{$tag}", ['is_theme_template' => true, 'template_id' => (int) ($tplId . '7'), 'template_type' => 'header']);
    try {
        [$status, $body] = stdel_post(['_action' => 'delete_template', 'template_id' => $tplId]);
        assert_eq(303, $status, 'back to the list (POST/redirect/GET)');
        assert_true(str_contains($body, 'Template deleted successfully'), 'success flash: ' . substr(strip_tags($body), 0, 200));
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_templates WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $tplId]), 'template removed');

        assert_eq('archived', (string) Database::value('SELECT status FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $backing]), 'backing page archived');
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $backingRev]), 'backing page revision kept');

        assert_eq('draft', (string) Database::value('SELECT status FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $decoy]), 'decoy page untouched');
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $decoyRev]), 'decoy revision untouched');
        assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'studio.template.deleted' AND target = ?", ["stdel-{$tag}"]) >= 1, 'deletion audit-logged');
    } finally {
        stdel_cleanup([$backing, $decoy], [$tplId]);
        $unentitle();
    }
});

unit('theme builder delete: an unknown or system template id changes nothing', function (): void {
    $unentitle = stdel_entitle();
    $tag = bin2hex(random_bytes(3));
    $sysId = stdel_template("stdel-sys-{$tag}", 1);
    [$sysPage, $sysRev] = stdel_page("template-stdel-sys-{$tag}", ['is_theme_template' => true, 'template_id' => $sysId]);
    try {
        foreach ([$sysId, 999999999] as $id) {
            [, $body] = stdel_post(['_action' => 'delete_template', 'template_id' => $id]);
            assert_true(str_contains($body, 'Template not found'), "{$id}: refused");
        }
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_templates WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $sysId]), 'system template kept');
        assert_eq('draft', (string) Database::value('SELECT status FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $sysPage]), 'its page untouched');
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $sysRev]));
    } finally {
        stdel_cleanup([$sysPage], [$sysId]);
        $unentitle();
    }
});
