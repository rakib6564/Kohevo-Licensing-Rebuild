<?php
/**
 * Studio permission levels on an admin page that wrote rows directly:
 *
 * - plugins/studio-builder/admin/templates.php let anyone with
 *   studio-builder.edit create and delete templates (the services require
 *   studio-builder.admin) and unpublish ANY page in the tenant with a raw
 *   UPDATE, unaudited and leaving the compiled page cached.
 *
 * Driven through the real pages in child processes, as a real role with
 * only view+edit, as a view-only role, and as super admin. Everything
 * created here is removed.
 */

declare(strict_types=1);

function sap_probe(string $fixture, string $page, string $arg, int $roleId): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/' . $fixture) . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($arg) . ' ' . escapeshellarg((string) $roleId) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)$/m', $out, $m);
    return [(int) ($m[1] ?? 0), $out];
}

function sap_post(array $fields, int $roleId): array
{
    return sap_probe('admin-page-post-probe.php', 'plugins/studio-builder/admin/templates.php', (string) json_encode($fields), $roleId);
}

function sap_role(array $perms): int
{
    $roleId = (int) Database::insert('roles', [
        'tenant_id' => current_tenant_id(), 'name' => 'Probe SAP', 'slug' => '__probe-sap-' . bin2hex(random_bytes(4)), 'is_system' => 0,
    ]);
    foreach ($perms as $perm) {
        Database::insert('role_permissions', ['role_id' => $roleId, 'perm_key' => $perm, 'granted' => 1]);
    }
    return $roleId;
}

function sap_drop_role(int $roleId): void
{
    Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
    Database::query('DELETE FROM roles WHERE id = ?', [$roleId]);
}

/** A published page with one revision; returns its id. */
function sap_published_page(string $slug, array $settings): int
{
    $tid = current_tenant_id();
    $pageId = (int) Database::insert('studiobuilder_pages', [
        'tenant_id' => $tid, 'uuid' => sprintf('%08x-2222-4000-8000-%012x', random_int(0, 0x7fffffff), random_int(0, 0x7fffffff)),
        'slug' => $slug, 'title' => $slug, 'page_type' => 'header_partial', 'route_mode' => 'standalone', 'status' => 'published',
        'seo_json' => '{}', 'settings_json' => json_encode($settings), 'created_by' => 1, 'updated_by' => 1,
        'created_at' => slate_db_now(), 'updated_at' => slate_db_now(), 'published_at' => slate_db_now(),
    ]);
    $doc = \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::emptyDocument('header_partial', 'default', $slug);
    $revId = (int) Database::insert('studiobuilder_revisions', [
        'tenant_id' => $tid, 'page_id' => $pageId, 'revision_number' => 1, 'revision_kind' => 'manual',
        'schema_version' => \Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema::SCHEMA_VERSION,
        'document_json' => \Slate\Module\StudioBuilder\Document\CanonicalJson::encode($doc), 'summary' => 'test', 'created_by' => 1, 'created_at' => slate_db_now(),
    ]);
    Database::query('UPDATE studiobuilder_pages SET active_draft_revision_id = ?, published_revision_id = ? WHERE id = ? AND tenant_id = ?', [$revId, $revId, $pageId, $tid]);
    return $pageId;
}

function sap_drop_page(int $pageId): void
{
    $tid = current_tenant_id();
    Database::query('DELETE FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [$tid, $pageId]);
    Database::query('UPDATE studiobuilder_pages SET active_draft_revision_id = NULL, published_revision_id = NULL WHERE tenant_id = ? AND id = ?', [$tid, $pageId]);
    Database::query('DELETE FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [$tid, $pageId]);
    Database::query('DELETE FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [$tid, $pageId]);
}

function sap_status(int $pageId): string
{
    return (string) Database::value('SELECT status FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $pageId]);
}

unit('theme builder: an editor without studio-builder.admin / .publish cannot create, delete or unpublish templates', function (): void {
    $editor = sap_role(['studio-builder.view', 'studio-builder.edit']);
    $tag = bin2hex(random_bytes(3));
    $tplPage = sap_published_page("template-sap-{$tag}", ['is_theme_template' => true, 'template_type' => 'header']);
    $tplId = (int) Database::insert('studiobuilder_templates', [
        'tenant_id' => current_tenant_id(), 'uuid' => sprintf('%08x-3333-4000-8000-%012x', random_int(0, 0x7fffffff), random_int(0, 0x7fffffff)),
        'template_key' => "sap-{$tag}", 'template_type' => 'header_preset', 'category' => 'header', 'name' => "SAP {$tag}",
        'schema_version' => '2.0', 'document_json' => '{}', 'is_system' => 0, 'created_by' => 1,
    ]);
    try {
        [, $body] = sap_post(['_action' => 'create_template', 'name' => "SAP Created {$tag}", 'template_type' => 'header'], $editor);
        assert_true(str_contains($body, 'You do not have permission to manage templates'), 'create refused');
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_templates WHERE tenant_id = ? AND name = ?', [current_tenant_id(), "SAP Created {$tag}"]), 'nothing created');

        sap_post(['_action' => 'delete_template', 'template_id' => $tplId], $editor);
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_templates WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $tplId]), 'delete refused');

        sap_post(['_action' => 'unpublish_template', 'page_id' => $tplPage], $editor);
        assert_eq('published', sap_status($tplPage), 'unpublish refused');
    } finally {
        Database::query("DELETE FROM studiobuilder_templates WHERE tenant_id = ? AND (id = ? OR name = ?)", [current_tenant_id(), $tplId, "SAP Created {$tag}"]);
        // If a regression lets the create through, remove its backing page too.
        foreach (Database::all("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND title = ?", [current_tenant_id(), "[Theme] SAP Created {$tag}"]) as $leak) {
            sap_drop_page((int) $leak['id']);
        }
        sap_drop_page($tplPage);
        sap_drop_role($editor);
    }
});

unit('theme builder: unpublish touches only Theme Builder pages, and is audit-logged', function (): void {
    $tag = bin2hex(random_bytes(3));
    $sitePage = sap_published_page("sap-site-{$tag}", []);
    $tplPage  = sap_published_page("template-sap-{$tag}", ['is_theme_template' => true]);
    try {
        [, $body] = sap_post(['_action' => 'unpublish_template', 'page_id' => $sitePage], 1);
        assert_true(str_contains($body, 'Template not found'), 'an ordinary site page is refused');
        assert_eq('published', sap_status($sitePage), 'site page stays published');

        sap_post(['_action' => 'unpublish_template', 'page_id' => $tplPage], 1);
        assert_eq('draft', sap_status($tplPage), 'template page unpublished');
        assert_eq(1, (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'studio.page.unpublished' AND target = ?", [(string) $tplPage]), 'audit entry written');
    } finally {
        Database::query("DELETE FROM audit_log WHERE action = 'studio.page.unpublished' AND target IN (?, ?)", [(string) $sitePage, (string) $tplPage]);
        sap_drop_page($sitePage);
        sap_drop_page($tplPage);
    }
});
