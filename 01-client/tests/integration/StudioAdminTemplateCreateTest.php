<?php
/**
 * Theme Builder › create template (plugins/studio-builder/admin/templates.php),
 * driven through the real page in a child process (gap report TI-06).
 *
 * The handler used to insert the template, its backing page and revision #1
 * with raw SQL: uuids from mt_rand, a 16-bit key suffix, the name stored
 * unchecked, the starter document never validated (a Section Preset was
 * stored with settings.template_type 'section_preset', which the editor's
 * validator refuses), and exception text — SQL included — shown in the
 * flash. Now it goes through the Studio services in one transaction, like
 * the editor: the template and the page's first draft are canonical,
 * validated documents, and a failure shows a plain message.
 *
 * Everything created here is removed at the end.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/license_signing.php';

use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;

/** Same trusted Studio entitlement seed as StudioTemplateDeleteTest; returns a restore callable. */
function stcre_entitle(): callable
{
    $tid = current_tenant_id();
    $priorCache = Database::all('SELECT * FROM remote_license_cache WHERE tenant_id = ?', [$tid]);
    $priorIdentity = Database::row('SELECT * FROM installation_identity WHERE singleton_id = 1');
    $installationId = $priorIdentity['installation_id'] ?? str_repeat('d', 32);
    if ($priorIdentity === null) {
        Database::insert('installation_identity', ['singleton_id' => 1, 'tenant_id' => $tid, 'installation_id' => $installationId]);
    }
    license_test_seed_cache($tid, [
        'installation_id' => $installationId, 'status' => 'active', 'plan' => 'stcre',
        'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
    ]);
    return static function () use ($tid, $priorCache, $priorIdentity): void {
        Database::query('DELETE FROM remote_license_cache WHERE tenant_id = ?', [$tid]);
        foreach ($priorCache as $row) Database::insert('remote_license_cache', $row);
        if ($priorIdentity === null) Database::query('DELETE FROM installation_identity WHERE singleton_id = 1');
    };
}

function stcre_post(array $fields, int $roleId = 1): array
{
    $env = license_test_env_prefix() . 'LICENSE_SERVER_URL=https://license.test LICENSE_PRODUCT=kohevo LICENSE_KEY=test-key ';
    $cmd = $env . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('plugins/studio-builder/admin/templates.php') . ' ' . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg((string) $roleId) . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    preg_match('/^STATUS (\d+)$/m', $out, $m);
    return [(int) ($m[1] ?? 0), $out];
}

/** The flash message the page rendered, as text (after a success, the short body of the 303 that carries it). */
function stcre_flash(string $body): string
{
    return preg_match('/<div class="alert[^"]*"\s*>\s*(.*?)\s*<\/div>/s', $body, $m) === 1 ? html_entity_decode(trim($m[1]), ENT_QUOTES) : '';
}

/** Template rows whose name starts with the tag, with their backing page (by the `template-<key>` slug). */
function stcre_created(string $tag): array
{
    $tid = current_tenant_id();
    $out = [];
    foreach (Database::all('SELECT * FROM studiobuilder_templates WHERE tenant_id = ? AND name LIKE ?', [$tid, "%{$tag}%"]) as $tpl) {
        $page = Database::row('SELECT * FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ?', [$tid, 'template-' . $tpl['template_key']]);
        $out[] = ['template' => $tpl, 'page' => $page];
    }
    return $out;
}

/** Removes every template, page, revision, dependency and audit row a test created under the tag. */
function stcre_cleanup(string $tag): void
{
    $tid = current_tenant_id();
    $keys = [];
    foreach (Database::all('SELECT id, template_key FROM studiobuilder_templates WHERE tenant_id = ? AND name LIKE ?', [$tid, "%{$tag}%"]) as $tpl) {
        $keys[] = (string) $tpl['template_key'];
        Database::query('DELETE FROM studiobuilder_templates WHERE tenant_id = ? AND id = ?', [$tid, (int) $tpl['id']]);
    }
    foreach (Database::all('SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND title LIKE ?', [$tid, "%{$tag}%"]) as $p) {
        $pid = (int) $p['id'];
        Database::query('DELETE FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [$tid, $pid]);
        Database::query('DELETE FROM studiobuilder_dependencies WHERE tenant_id = ? AND page_id = ?', [$tid, $pid]);
        Database::query('UPDATE studiobuilder_pages SET active_draft_revision_id = NULL, published_revision_id = NULL WHERE tenant_id = ? AND id = ?', [$tid, $pid]);
        Database::query('DELETE FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [$tid, $pid]);
        Database::query('DELETE FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [$tid, $pid]);
        Database::query("DELETE FROM audit_log WHERE action LIKE 'studio.page.%' AND target = ?", [(string) $pid]);
    }
    foreach ($keys as $k) {
        Database::query("DELETE FROM audit_log WHERE action = 'studio.template.saved' AND target = ?", [$k]);
    }
}

unit('theme builder create: every type is created through the Studio services as a canonical, valid template and draft', function (): void {
    $unentitle = stcre_entitle();
    $tag = 'stcre' . bin2hex(random_bytes(3));
    $registry = ModuleBlockDefinitions::studioRegistry();
    try {
        foreach (['header', 'footer', 'single', 'archive', 'not_found', 'section_preset'] as $uiType) {
            [$status, $body] = stcre_post(['_action' => 'create_template', 'name' => "Made {$uiType} {$tag}", 'template_type' => $uiType, 'condition_rule' => 'all_posts']);
            assert_eq(303, $status, "{$uiType}: back to the list (POST/redirect/GET)");
            assert_true(str_contains(stcre_flash($body), 'created as draft'), "{$uiType}: success flash, got: " . stcre_flash($body));
        }
        $made = stcre_created($tag);
        assert_eq(6, count($made), 'one template per type');
        foreach ($made as ['template' => $tpl, 'page' => $page]) {
            $label = (string) $tpl['name'];
            assert_true(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $tpl['uuid']) === 1, "{$label}: a v4 uuid");
            assert_true(preg_match('/-[0-9a-f]{8}$/', (string) $tpl['template_key']) === 1, "{$label}: a 32-bit key suffix, got {$tpl['template_key']}");
            // The stored template document is what the validator produces from it: valid and already normalized.
            $stored = json_decode((string) $tpl['document_json'], true);
            assert_eq($stored, ValidatedDocument::from($stored, $registry)->toArray(), "{$label}: template document is canonical");

            assert_true(is_array($page), "{$label}: backing page at template-<key>");
            $rev = Database::row('SELECT * FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [current_tenant_id(), (int) $page['active_draft_revision_id']]);
            $draft = json_decode((string) $rev['document_json'], true);
            assert_eq($draft, ValidatedDocument::from($draft, $registry)->toArray(), "{$label}: page draft is canonical");
            assert_eq('noindex,nofollow', $draft['seo']['robots'] ?? null, "{$label}: never indexed");
            assert_true((int) Database::value("SELECT COUNT(*) FROM audit_log WHERE action = 'studio.template.saved' AND target = ?", [(string) $tpl['template_key']]) >= 1, "{$label}: audit-logged");
        }
        $byType = array_column(array_map(static fn(array $m): array => [$m['template']['template_type'] . '/' . $m['template']['category'], json_decode((string) $m['template']['document_json'], true)], $made), 1, 0);
        assert_eq('single', $byType['page_template/single']['settings']['template_type'] ?? null, 'a Single template keeps its role');
        assert_eq('singular', $byType['page_template/single']['settings']['conditions']['rules'][0]['condition'] ?? null, 'and its display condition');
        assert_true(!isset($byType['section_preset/section']['settings']['template_type']) && !isset($byType['section_preset/section']['settings']['conditions']), 'a section preset carries no theme role or conditions');
    } finally {
        stcre_cleanup($tag);
        $unentitle();
    }
});

unit('theme builder create: a header can start from a built-in design; an unknown design starts blank', function (): void {
    $unentitle = stcre_entitle();
    $tag = 'stcre' . bin2hex(random_bytes(3));
    $registry = ModuleBlockDefinitions::studioRegistry();
    $types = static function (array $doc): array {
        $out = [];
        $walk = static function (array $blocks) use (&$walk, &$out): void {
            foreach ($blocks as $b) { $out[] = (string) $b['type']; $walk(is_array($b['children'] ?? null) ? $b['children'] : []); }
        };
        foreach ($doc['sections'] as $sec) { $walk($sec['blocks']); }
        return $out;
    };
    try {
        foreach (\Slate\Module\StudioBuilder\Presets\HeaderPresetCatalog::all() as $design) {
            [$status, $body] = stcre_post(['_action' => 'create_template', 'name' => "Design {$design['slug']} {$tag}", 'template_type' => 'header', 'header_design' => $design['key']]);
            assert_eq(303, $status, $design['key']);
            assert_true(str_contains(stcre_flash($body), 'created as draft'), "{$design['key']}: success flash, got: " . stcre_flash($body));
        }
        [$status] = stcre_post(['_action' => 'create_template', 'name' => "Design unknown {$tag}", 'template_type' => 'header', 'header_design' => 'system-section-hero-classic']);
        assert_eq(303, $status, 'created (from the blank header)');
        $made = [];
        foreach (stcre_created($tag) as $m) { $made[(string) $m['template']['name']] = $m; }
        assert_eq(6, count($made), 'five designs and one blank');
        foreach (\Slate\Module\StudioBuilder\Presets\HeaderPresetCatalog::all() as $design) {
            $m = $made["Design {$design['slug']} {$tag}"];
            $rev = Database::row('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [current_tenant_id(), (int) $m['page']['active_draft_revision_id']]);
            $draft = json_decode((string) $rev['document_json'], true);
            assert_eq($draft, ValidatedDocument::from($draft, $registry)->toArray(), "{$design['key']}: the header draft is canonical");
            assert_eq($types($design['document']), $types($draft), "{$design['key']}: the header holds the design's blocks");
            assert_eq('header', $draft['settings']['template_type'] ?? null, "{$design['key']}: still a header");
            assert_true(!empty($draft['settings']['conditions']['rules']), "{$design['key']}: keeps the display condition chosen on the form");
            $catalogueIds = array_column($design['document']['sections'], 'id');
            assert_eq([], array_values(array_intersect($catalogueIds, array_column($draft['sections'], 'id'))), "{$design['key']}: the copy has its own ids");
        }
        $rev = Database::row('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [current_tenant_id(), (int) $made["Design unknown {$tag}"]['page']['active_draft_revision_id']]);
        assert_eq(['core.heading'], $types(json_decode((string) $rev['document_json'], true)), 'a key that is not a header design starts from the blank header');
    } finally {
        stcre_cleanup($tag);
        $unentitle();
    }
});

unit('theme builder: "Edit in canvas" on a built-in header makes a draft header from it and opens the builder; an unknown design changes nothing', function (): void {
    $unentitle = stcre_entitle();
    $tid = current_tenant_id();
    $before = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM studiobuilder_templates WHERE tenant_id = ?', [$tid]);
    $beforePages = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM studiobuilder_pages WHERE tenant_id = ?', [$tid]);
    try {
        $design = \Slate\Module\StudioBuilder\Presets\HeaderPresetCatalog::find('system-header-split');
        [$status] = stcre_post(['_action' => 'use_header_design', 'header_design' => $design['key']]);
        assert_eq(303, $status, 'redirects to the builder (See Other)');
        $rows = Database::all('SELECT * FROM studiobuilder_templates WHERE tenant_id = ? AND id > ?', [$tid, $before]);
        assert_eq(1, count($rows), 'one header template');
        $tpl = $rows[0];
        assert_eq('header_preset', $tpl['template_type']);
        assert_eq($design['name'], $tpl['name'], 'named after the design');
        assert_eq(0, (int) $tpl['is_system'], 'an editable copy, not the built-in design');
        $page = Database::row('SELECT * FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ?', [$tid, 'template-' . $tpl['template_key']]);
        assert_true(is_array($page) && $page['page_type'] === 'header_partial', 'a header page to edit in the canvas');
        assert_true($page['published_revision_id'] === null, 'a draft: not live until it is published');
        $draft = json_decode((string) Database::value('SELECT document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [$tid, (int) $page['active_draft_revision_id']]), true);
        assert_eq(array_column($design['document']['sections'], 'label'), array_column($draft['sections'], 'label'), 'the design\'s sections');
        assert_eq('entire_site', $draft['settings']['conditions']['rules'][0]['condition'] ?? null, 'for the whole site once published');
        $system = Database::value("SELECT COUNT(*) FROM studiobuilder_templates WHERE tenant_id = ? AND template_key = 'system-header-split' AND is_system = 0", [$tid]);
        assert_eq(0, (int) $system, 'the built-in design itself is untouched');

        [$status, $body] = stcre_post(['_action' => 'use_header_design', 'header_design' => 'system-section-hero-classic']);
        assert_eq(200, $status);
        assert_true(str_contains(stcre_flash($body), 'does not exist'), 'an unknown design is refused: ' . stcre_flash($body));
        assert_eq(1, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_templates WHERE tenant_id = ? AND id > ?', [$tid, $before]), 'nothing more was created');
    } finally {
        foreach (Database::all('SELECT id, template_key FROM studiobuilder_templates WHERE tenant_id = ? AND id > ?', [$tid, $before]) as $row) {
            Database::query('DELETE FROM studiobuilder_templates WHERE tenant_id = ? AND id = ?', [$tid, (int) $row['id']]);
            Database::query("DELETE FROM audit_log WHERE action = 'studio.template.saved' AND target = ?", [(string) $row['template_key']]);
        }
        foreach (Database::all('SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND id > ?', [$tid, $beforePages]) as $p) {
            $pid = (int) $p['id'];
            Database::query('DELETE FROM studiobuilder_compilations WHERE tenant_id = ? AND page_id = ?', [$tid, $pid]);
            Database::query('DELETE FROM studiobuilder_dependencies WHERE tenant_id = ? AND page_id = ?', [$tid, $pid]);
            Database::query('UPDATE studiobuilder_pages SET active_draft_revision_id = NULL, published_revision_id = NULL WHERE tenant_id = ? AND id = ?', [$tid, $pid]);
            Database::query('DELETE FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?', [$tid, $pid]);
            Database::query('DELETE FROM studiobuilder_pages WHERE tenant_id = ? AND id = ?', [$tid, $pid]);
            Database::query("DELETE FROM audit_log WHERE action LIKE 'studio.page.%' AND target = ?", [(string) $pid]);
        }
        $unentitle();
    }
});

unit('theme builder create: an over-long or marked-up name is refused with a plain message, and nothing is written', function (): void {
    $unentitle = stcre_entitle();
    $tag = 'stcre' . bin2hex(random_bytes(3));
    try {
        foreach ([$tag . str_repeat('n', 300), "<b>{$tag}</b>"] as $name) {
            [$status, $body] = stcre_post(['_action' => 'create_template', 'name' => $name, 'template_type' => 'header']);
            assert_eq(200, $status);
            assert_true(str_contains(stcre_flash($body), 'A template name is plain text of at most 191 characters'), 'refused, got: ' . stcre_flash($body));
            assert_true(!str_contains($body, 'SQLSTATE') && !str_contains($body, 'Failed to create template'), 'no database text in the flash');
        }
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_templates WHERE tenant_id = ? AND name LIKE ?', [current_tenant_id(), "%{$tag}%"]), 'no template');
        assert_eq(0, (int) Database::value('SELECT COUNT(*) FROM studiobuilder_pages WHERE tenant_id = ? AND title LIKE ?', [current_tenant_id(), "%{$tag}%"]), 'no backing page');
    } finally {
        stcre_cleanup($tag);
        $unentitle();
    }
});
