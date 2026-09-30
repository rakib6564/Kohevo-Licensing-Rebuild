<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 8A JSON packages.
 *
 * Dependency-free (no database): the strict package envelope (unknown keys /
 * kinds, tenant_id anywhere, tampering, filesystem paths, limits), the
 * content hash's stability across a browser JSON round trip, the document
 * mapper (id re-minting including nested children, global reference
 * remapping and downgrade, the media downgrade contract), the deterministic
 * report, the HTTP transport rules of the two new actions, and static
 * architecture guards (no network access, no direct table writes, no audit
 * inside the owned transaction, draftKind unchanged, no MCP package tool,
 * import never publishes). Persistence, tenancy, entitlement, permissions,
 * concurrency and audit are covered by the Phase 8 integration suite.
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
    $studioP8UnitStandalone = true;
}

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Application\StudioApplicationService;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Http\StudioApiRequest;
use Slate\Module\StudioBuilder\Http\StudioAuthoringApi;
use Slate\Module\StudioBuilder\Mcp\StudioMcpToolCatalog;
use Slate\Module\StudioBuilder\Package\PackageDocumentMapper;
use Slate\Module\StudioBuilder\Package\StudioImportReport;
use Slate\Module\StudioBuilder\Package\StudioPackageFormat;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

/** @return array<string, mixed> */
function sbu8_block(string $type, array $props, array $children = []): array
{
    return [
        'id' => 'blk_' . bin2hex(random_bytes(12)), 'type' => $type, 'version' => 1, 'props' => $props,
        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
        'bindings' => [], 'children' => $children,
    ];
}

/** @return array<string, mixed> */
function sbu8_document(): array
{
    $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Unit');
    $doc['seo']['og_image_media_id'] = 1;
    $doc['sections'] = [
        [
            'id' => 'sec_aaaaaaaaaaaaaaaaaaaaaaaa', 'label' => 'Main', 'global_ref' => null,
            'layout' => CanonicalDocumentSchema::defaultSectionLayout(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks' => [
                sbu8_block('core.hero', ['heading' => 'Hi', 'media' => ['media_id' => 1, 'alt' => 'x', 'focal_point' => [1.0, 0.5]]]),
                sbu8_block('core.image', ['media' => ['media_id' => 2, 'alt' => 'y']]),
                sbu8_block('core.container', ['gap' => 'md', 'direction' => 'vertical'], [sbu8_block('core.image', ['media' => ['media_id' => 2, 'alt' => 'z']])]),
            ],
        ],
        [
            'id' => 'sec_bbbbbbbbbbbbbbbbbbbbbbbb', 'label' => 'Ref', 'global_ref' => '11111111-1111-4111-8111-111111111111',
            'layout' => CanonicalDocumentSchema::defaultSectionLayout(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks' => [],
        ],
    ];
    return $doc;
}

/** @return array<string, mixed> */
function sbu8_package(array $items = []): array
{
    if ($items === []) {
        $item = ['kind' => 'page', 'key' => 'page', 'title' => 'Unit', 'slug' => 'unit', 'page_type' => 'page', 'route_mode' => 'standalone',
            'document' => sbu8_document(), 'media' => [['key' => 1, 'path' => '/uploads/media/2026/01/a.jpg', 'mime' => 'image/jpeg'], ['key' => 2, 'path' => '/uploads/media/2026/01/b.png', 'mime' => 'image/png']]];
        $item['content_hash'] = StudioPackageFormat::itemHash($item);
        $items = [$item];
    }
    return ['package_format' => 'kohevo-studio-package', 'package_version' => '1.0', 'exported_at' => '2026-09-30T12:00:00Z', 'items' => $items];
}

/** @return list<string> */
function sbu8_codes(array $issues): array
{
    return array_values(array_unique(array_column($issues, 'code')));
}

function sbu8_rehash(array $package): array
{
    foreach ($package['items'] as $i => $item) {
        $package['items'][$i]['content_hash'] = StudioPackageFormat::itemHash($item);
    }
    return $package;
}

// ── 1. Envelope ────────────────────────────────────────────────────────────

unit('phase8a unit: a well-formed package validates; the envelope rejects unknown keys, unknown kinds, unexpected item keys, wrong format/version and oversize packages', function (): void {
    assert_eq([], StudioPackageFormat::validate(sbu8_package()));
    assert_eq(['invalid_package'], sbu8_codes(StudioPackageFormat::validate('nope')));
    assert_eq(['invalid_package'], sbu8_codes(StudioPackageFormat::validate([1, 2])));
    assert_eq(['unknown_package_key'], sbu8_codes(StudioPackageFormat::validate(sbu8_package() + ['secrets' => 'x'])));

    $p = sbu8_package();
    $p['items'][0]['kind'] = 'script';
    assert_true(in_array('unknown_item_type', sbu8_codes(StudioPackageFormat::validate(sbu8_rehash($p))), true));
    $p = sbu8_package();
    $p['items'][0]['page_id'] = 42;
    assert_true(in_array('invalid_package', sbu8_codes(StudioPackageFormat::validate(sbu8_rehash($p))), true), 'a database id is an unexpected item key');
    $p = sbu8_package();
    unset($p['items'][0]['slug']);
    assert_true(in_array('invalid_package', sbu8_codes(StudioPackageFormat::validate(sbu8_rehash($p))), true), 'every item key is required');
    foreach (['package_format' => 'other', 'package_version' => '2.0', 'exported_at' => 'yesterday'] as $k => $v) {
        $p = sbu8_package();
        $p[$k] = $v;
        assert_eq(['invalid_package'], sbu8_codes(StudioPackageFormat::validate($p)), $k);
    }
    $item = sbu8_package()['items'][0];
    $many = [];
    for ($i = 0; $i <= StudioPackageFormat::MAX_ITEMS; $i++) {
        $many[] = array_merge($item, ['key' => 'p' . $i]);
    }
    assert_eq(['invalid_package'], sbu8_codes(StudioPackageFormat::validate(sbu8_package($many))), 'item limit');
    $dup = sbu8_package([$item, $item]);
    assert_true(in_array('invalid_package', sbu8_codes(StudioPackageFormat::validate($dup)), true), 'duplicate item keys');
});

unit('phase8a unit: tenant_id is rejected at ANY depth, and tampering is detected by the content hash (which is not a signature)', function (): void {
    $p = sbu8_package();
    $p['items'][0]['document']['sections'][0]['blocks'][2]['children'][0]['props']['tenant_id'] = 7;
    $issues = StudioPackageFormat::validate(sbu8_rehash($p));
    assert_true(in_array('forbidden_tenant_id', sbu8_codes($issues), true));
    assert_true(in_array('$.items[0].document.sections[0].blocks[2].children[0].props.tenant_id', array_column($issues, 'path'), true), 'the exact path is named');
    $p = sbu8_package() + ['tenant_id' => 1];
    assert_true(in_array('forbidden_tenant_id', sbu8_codes(StudioPackageFormat::validate($p)), true));

    $p = sbu8_package();
    $p['items'][0]['title'] = 'Changed';
    $issues = StudioPackageFormat::validate($p);
    assert_eq(['invalid_package'], sbu8_codes($issues));
    assert_eq('$.items[0].content_hash', $issues[0]['path']);
    // A hash is consistency only: re-hashing a changed item makes it "valid" — content is still validated canonically on import.
    assert_eq([], StudioPackageFormat::validate(sbu8_rehash($p)));
});

unit('phase8a unit: the content hash survives a browser JSON round trip (1.0 becomes 1, key order changes)', function (): void {
    $p = sbu8_package();
    $js = json_decode(json_encode($p), true); // PHP json_encode drops the zero fraction just like JSON.stringify
    $shuffled = $js;
    $shuffled['items'][0] = array_reverse($js['items'][0], true);
    assert_eq([], StudioPackageFormat::validate($shuffled));
    assert_eq(1, $js['items'][0]['document']['sections'][0]['blocks'][0]['props']['media']['focal_point'][0], 'sanity: the float lost its fraction');
});

unit('phase8a unit: media descriptors are exactly {key, path, mime}; filesystem paths and traversal are refused; external URLs are allowed only as (never fetched) references', function (): void {
    foreach (['/etc/passwd', '/var/www/uploads/x.jpg', '/uploads/../config.php', 'C:\\files\\x.jpg', "/uploads/a\x00.jpg"] as $bad) {
        $p = sbu8_package();
        $p['items'][0]['media'][0]['path'] = $bad;
        assert_true(in_array('invalid_package', sbu8_codes(StudioPackageFormat::validate(sbu8_rehash($p))), true), "path {$bad}");
    }
    foreach (['https://cdn.example/a.jpg', ''] as $ok) {
        $p = sbu8_package();
        $p['items'][0]['media'][0]['path'] = $ok;
        assert_eq([], StudioPackageFormat::validate(sbu8_rehash($p)), "path '{$ok}' is a reference, validated later");
    }
    $p = sbu8_package();
    $p['items'][0]['media'][0]['url'] = 'https://x';
    assert_true(in_array('invalid_package', sbu8_codes(StudioPackageFormat::validate(sbu8_rehash($p))), true), 'no extra descriptor keys');
    assert_true(StudioPackageFormat::isExternalReference('https://x/y.png') && StudioPackageFormat::isExternalReference('//x/y.png') && StudioPackageFormat::isExternalReference('data:image/png;base64,AA'));
    assert_false(StudioPackageFormat::isExternalReference('/uploads/media/a.png'));
});

// ── 2. Document mapper ────────────────────────────────────────────────────

unit('phase8a unit: re-minting replaces every section, block and nested child id (even colliding source ids) and keeps everything else', function (): void {
    $doc = sbu8_document();
    $doc['sections'][0]['blocks'][1]['id'] = $doc['sections'][0]['blocks'][0]['id']; // collision in the source
    $out = PackageDocumentMapper::remintIds($doc);
    $old = [$doc['sections'][0]['id'], $doc['sections'][1]['id'], $doc['sections'][0]['blocks'][0]['id'], $doc['sections'][0]['blocks'][2]['id'], $doc['sections'][0]['blocks'][2]['children'][0]['id']];
    $new = [$out['sections'][0]['id'], $out['sections'][1]['id'], $out['sections'][0]['blocks'][0]['id'], $out['sections'][0]['blocks'][1]['id'], $out['sections'][0]['blocks'][2]['id'], $out['sections'][0]['blocks'][2]['children'][0]['id']];
    assert_eq([], array_intersect($old, $new));
    assert_eq(count($new), count(array_unique($new)), 'unique after re-minting');
    foreach ($new as $id) {
        assert_true(preg_match('/^(sec|blk)_[a-z0-9]{24}$/', $id) === 1);
    }
    assert_eq('11111111-1111-4111-8111-111111111111', $out['sections'][1]['global_ref'], 're-minting keeps references (remapping is separate)');
    [$s, $b] = PackageDocumentMapper::deterministicMinters('seed');
    [$s2, $b2] = PackageDocumentMapper::deterministicMinters('seed');
    assert_eq(PackageDocumentMapper::remintIds($doc, $s, $b), PackageDocumentMapper::remintIds($doc, $s2, $b2), 'dry-run minting is reproducible');
});

unit('phase8a unit: media downgrade contract — OG image -> null, optional media -> null, a block with required media is omitted (at any depth); a resolved id is written', function (): void {
    $registry = ModuleBlockDefinitions::studioRegistry();
    $doc = sbu8_document();
    assert_eq([1, 2], PackageDocumentMapper::mediaIds($doc, $registry));

    $none = PackageDocumentMapper::rewriteMedia($doc, $registry, static fn(int $k): ?int => null);
    $d = $none['document'];
    assert_null($d['seo']['og_image_media_id']);
    assert_null($d['sections'][0]['blocks'][0]['props']['media'], 'optional hero media -> null');
    assert_eq(['core.hero', 'core.container'], array_column($d['sections'][0]['blocks'], 'type'), 'the required image is omitted');
    assert_eq([], $d['sections'][0]['blocks'][1]['children'], 'also inside a container');
    assert_eq(['set_null', 'set_null', 'omit_block', 'omit_block'], array_column($none['unresolved'], 'action'));
    assert_true(!str_contains(json_encode($d), 'http'), 'no URL is ever written');

    $some = PackageDocumentMapper::rewriteMedia($doc, $registry, static fn(int $k): ?int => $k === 1 ? 501 : null)['document'];
    assert_eq(501, $some['seo']['og_image_media_id']);
    assert_eq(501, $some['sections'][0]['blocks'][0]['props']['media']['media_id']);
});

unit('phase8a unit: global references are remapped through the explicit map; an unmapped one becomes an empty owned section', function (): void {
    $doc = sbu8_document();
    $mapped = PackageDocumentMapper::rewriteGlobalRefs($doc, static fn(string $r): ?string => '22222222-2222-4222-8222-222222222222');
    assert_eq('22222222-2222-4222-8222-222222222222', $mapped['document']['sections'][1]['global_ref']);
    assert_eq([], $mapped['unresolved']);
    $un = PackageDocumentMapper::rewriteGlobalRefs($doc, static fn(string $r): ?string => null);
    assert_null($un['document']['sections'][1]['global_ref']);
    assert_eq([], $un['document']['sections'][1]['blocks']);
    assert_eq('Ref', $un['document']['sections'][1]['label']);
    assert_eq([['path' => '$.sections[1].global_ref', 'ref' => '11111111-1111-4111-8111-111111111111']], $un['unresolved']);
    assert_eq(1, preg_match(CanonicalDocumentSchema::COMPONENT_REF_PATTERN, PackageDocumentMapper::placeholderUuid('x')), 'placeholder component refs are well formed');
});

unit('phase8a unit: the import report is deterministic, maps validator codes onto the stable vocabulary, and never lets a report with errors commit', function (): void {
    $build = static function (bool $reverse): array {
        $r = new StudioImportReport('create', true, str_repeat('a', 64));
        $r->setItemOrder('b', 1);
        $r->setItemOrder('a', 0);
        $calls = [
            static fn() => $r->warning('unresolved_media', 'b', '$.x', 'w'),
            static fn() => $r->error('route_collision', 'a', '$.slug', 'e'),
            static fn() => $r->validatorErrors('a', '$.items[0].document', [['path' => '$.sections[0].blocks[0].type', 'code' => 'block_module_not_entitled', 'message' => 'm'], ['path' => '$.x', 'code' => 'invalid_layout_width', 'message' => 'm']]),
        ];
        foreach ($reverse ? array_reverse($calls) : $calls as $c) {
            $c();
        }
        return $r->toArray(null);
    };
    $a = $build(false);
    assert_eq($a, $build(true), 'issue order does not depend on discovery order');
    assert_eq(['unentitled_module', 'invalid_document', 'route_collision', 'unresolved_media'], array_column($a['issues'], 'code'), 'by item position, then path');
    assert_eq('invalid_layout_width', $a['issues'][1]['detail']);
    assert_false($a['can_commit']);
    assert_eq(3, $a['summary']['errors_count']);
    assert_eq(1, $a['summary']['unresolved_refs_count']);
    assert_eq([], $a['preview_documents'], 'no previews for an invalid package');
});

// ── 3. HTTP transport rules ───────────────────────────────────────────────

unit('phase8a unit: import_package is a CSRF-guarded POST with strict fields (no tenant, explicit dry_run, replace needs uuid + expected revision); export_package is a GET query', function (): void {
    $api = new StudioAuthoringApi(StudioRuntimeFactory::build()->app);
    $editor = StudioActor::authenticated(7, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
    $post = static fn(array $body, bool $csrf = true): StudioApiRequest => new StudioApiRequest('POST', 'import_package', [], (string) json_encode($body), 'application/json', $csrf, 'same-origin');
    $first = static fn($r): array => $r->payload['error']['details']['errors'][0];

    assert_eq(405, $api->handle(new StudioApiRequest('GET', 'import_package'), $editor)->status);
    assert_eq(405, $api->handle(new StudioApiRequest('POST', 'export_package', [], '{}', 'application/json', true, 'same-origin'), $editor)->status);
    assert_eq(403, $api->handle($post(['package' => sbu8_package(), 'dry_run' => true], false), $editor)->status, 'CSRF');
    $r = $api->handle($post(['package' => sbu8_package(), 'dry_run' => true, 'tenant_id' => 3]), $editor);
    assert_eq(['unknown_field', '$.tenant_id'], [$first($r)['code'], $first($r)['path']], 'the request cannot name a tenant');
    assert_eq('required_field', $first($api->handle($post(['package' => sbu8_package()]), $editor))['code'], 'dry_run is explicit');
    assert_eq('invalid_package', $first($api->handle($post(['package' => [1], 'dry_run' => true]), $editor))['code']);
    assert_eq('$.target_page_id', $first($api->handle($post(['package' => sbu8_package(), 'dry_run' => false, 'mode' => 'replace_draft']), $editor))['path']);
    assert_eq('$.expected_revision_id', $first($api->handle($post(['package' => sbu8_package(), 'dry_run' => false, 'mode' => 'replace_draft', 'target_page_id' => 3]), $editor))['path']);
    assert_eq('$.mode', $first($api->handle($post(['package' => sbu8_package(), 'dry_run' => true, 'expected_revision_id' => 4]), $editor))['path'], 'create mode takes no target');
    assert_eq('$.media_map', $first($api->handle($post(['package' => sbu8_package(), 'dry_run' => true, 'media_map' => ['https://x/a.jpg' => 3]]), $editor))['path'], 'media_map keys are /uploads/ paths');
    assert_eq('$.media_map', $first($api->handle($post(['package' => sbu8_package(), 'dry_run' => true, 'media_map' => ['/uploads/a.jpg' => 'x']]), $editor))['path']);
    assert_eq('$.component_map', $first($api->handle($post(['package' => sbu8_package(), 'dry_run' => true, 'component_map' => ['x' => 'y']]), $editor))['path']);
    assert_eq('$.include_components', $first($api->handle(new StudioApiRequest('GET', 'export_package', ['page' => '1', 'include_components' => 'maybe']), $editor))['path']);
});

unit('phase8a unit: editor saves still accept only autosave/manual — `import` is not a client-selectable kind', function (): void {
    assert_eq(['autosave', 'manual'], StudioApplicationService::EDITOR_REVISION_KINDS);
    assert_eq('import', StudioApplicationService::IMPORT_REVISION_KIND);
    $api = new StudioAuthoringApi(StudioRuntimeFactory::build()->app);
    $editor = StudioActor::authenticated(7, [StudioPermissions::VIEW, StudioPermissions::EDIT]);
    foreach (['save_draft' => ['document' => ['a' => 1]], 'operations' => ['operations' => [['op' => 'update_seo', 'payload' => ['seo' => []]]]]] as $action => $body) {
        $r = $api->handle(new StudioApiRequest('POST', $action, [], (string) json_encode(['page_id' => 1, 'expected_revision_id' => 1, 'revision_kind' => 'import'] + $body), 'application/json', true, 'same-origin'), $editor);
        assert_eq('invalid_revision_kind', $r->payload['error']['details']['errors'][0]['code'], "{$action} cannot write an import revision");
    }
});

// ── 4. Static architecture guards ─────────────────────────────────────────

unit('phase8a architecture: package code makes no network request, writes no table directly, never publishes, and is reached only through the application service', function (): void {
    $root = SLATE_ROOT . '/src/Module/StudioBuilder';
    $files = [...glob($root . '/Package/*.php'), $root . '/Application/StudioPackageService.php'];
    assert_eq(4, count($files));
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
        foreach (['curl_', 'file_get_contents', 'fopen', 'fsockopen', 'stream_socket', 'http_build_query', 'Database::', '->exec(', 'INSERT', 'UPDATE ', 'DELETE ', 'studiobuilder_', 'publishWorkingRevision', '->publish(', 'AuditLog', 'beginTransaction'] as $needle) {
            assert_true(!str_contains($code, $needle), basename($file) . " must not contain {$needle}");
        }
    }
    // Only the application service (and the factory that builds it) constructs or calls the package service.
    $users = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(SLATE_ROOT . '/src')) as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && str_contains((string) file_get_contents($f->getPathname()), 'StudioPackageService')) {
            $users[] = $f->getFilename();
        }
    }
    sort($users);
    assert_eq(['StudioApplicationService.php', 'StudioPackageService.php', 'StudioRuntimeFactory.php'], $users);
    foreach ([SLATE_ROOT . '/plugins/studio-builder/StudioBuilderMcpHandler.php', $root . '/Mcp/StudioMcpAdapter.php', $root . '/Mcp/StudioMcpToolCatalog.php'] as $f) {
        $src = (string) file_get_contents($f);
        assert_true(!str_contains($src, 'importPackage') && !str_contains($src, 'exportPackage'), basename($f) . ' exposes no package tool');
    }
    foreach (StudioMcpToolCatalog::names() as $name) {
        assert_true(!str_contains($name, 'import') && !str_contains($name, 'export') && !str_contains($name, 'package'), 'no MCP import/export tool: ' . $name);
    }
});

unit('phase8a architecture: importPackage audits only after its own transaction commits, and never inside it', function (): void {
    $src = (string) file_get_contents(SLATE_ROOT . '/src/Module/StudioBuilder/Application/StudioApplicationService.php');
    $start = strpos($src, 'public function importPackage(');
    $end = strpos($src, '    // ── Authoring render commands', $start);
    $body = substr($src, $start, $end - $start);
    $tx = substr($body, strpos($body, 'beginTransaction()'), strpos($body, '$pdo->commit()') - strpos($body, 'beginTransaction()'));
    assert_true(!str_contains($tx, 'audit('), 'no audit between beginTransaction and commit');
    assert_true(strpos($body, "'studio.package.imported'") > strpos($body, '$pdo->commit()'), 'the package audit event follows the commit');
    assert_true(str_contains($body, 'if ($dryRun || $report->hasErrors())') && strpos($body, 'if ($dryRun || $report->hasErrors())') < strpos($body, 'beginTransaction()'), 'a dry run returns before any transaction or audit');
    assert_true(!str_contains($body, '->publish(') && !str_contains($body, 'publishWorkingRevision'), 'import never publishes');
});

if (!empty($studioP8UnitStandalone)) {
    exit(unit_summary());
}
