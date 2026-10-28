<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the first fixes from the 2026-10-26 builder audit.
 *
 * Autoloader only. An SVG upload cannot carry script through a DOCTYPE entity or a namespaced href; the portfolio
 * pages button runs as the signed-in user, needs publish and never overwrites a page; the media screen searches the
 * columns media_files really has and deletes through Media::delete; the card image picker posts the CSRF field the
 * check reads.
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

use Slate\Services\Media\Media;
use Slate\Services\Media\Uploads;

/** Sanitize one SVG in a temp file; returns [accepted, the file afterwards]. */
function sb_audit_svg(string $svg): array
{
    $file = tempnam(sys_get_temp_dir(), 'sbsvg');
    file_put_contents($file, $svg);
    $ok = Media::sanitizeSvgFile($file);
    $out = (string) file_get_contents($file);
    @unlink($file);
    return [$ok, $out];
}

unit('svg upload: a DOCTYPE or ENTITY declaration is refused (an entity can expand into a script in the browser)', function (): void {
    [$ok] = sb_audit_svg('<!DOCTYPE svg [<!ENTITY x "&#60;script&#62;alert(1)&#60;/script&#62;">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>');
    assert_false($ok, 'an internal entity is refused');
    [$ok] = sb_audit_svg('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>');
    assert_false($ok, 'any DOCTYPE is refused');
});

unit('svg upload: a javascript: link is stripped whatever prefix names the XLink namespace', function (): void {
    [$ok, $out] = sb_audit_svg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:x="http://www.w3.org/1999/xlink"><a x:href="javascript:alert(1)"><text>hi</text></a><a href=" java&#x09;script:alert(2)"><text>b</text></a></svg>');
    assert_true($ok);
    assert_false(stripos($out, 'javascript') !== false, 'no javascript: link survives: ' . $out);
    assert_true(str_contains($out, '<text>hi</text>'), 'the content stays');
});

unit('svg upload: processing instructions and event handlers are dropped; a clean SVG keeps its links', function (): void {
    [$ok, $out] = sb_audit_svg('<?xml-stylesheet href="evil.css"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>');
    assert_true($ok);
    assert_false(str_contains($out, 'xml-stylesheet'), 'the stylesheet instruction is gone');
    assert_false(str_contains($out, 'onload'), 'the handler is gone');
    [$ok, $out] = sb_audit_svg('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="https://example.com"><rect/></a></svg>');
    assert_true($ok);
    assert_true(str_contains($out, 'xlink:href="https://example.com"'), 'a safe link stays');
});

unit('svg upload: upload folders serve SVGs with a sandboxed, script-free CSP', function (): void {
    $rules = Uploads::svgSandboxRules();
    assert_true(str_starts_with($rules, Uploads::SVG_SANDBOX_MARKER));
    assert_true(str_contains($rules, "default-src 'none'") && str_contains($rules, 'sandbox'), $rules);
    assert_true(str_contains($rules, '<IfModule mod_headers.c>'), 'harmless where mod_headers is missing');
});

unit('media screen: search uses the columns media_files has, filters use its kinds, delete goes through Media::delete', function (): void {
    $admin = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/media.php');
    $schema = (string) file_get_contents(SLATE_ROOT . '/db/schema.sql');
    assert_true(str_contains($admin, '(path LIKE ? OR original_name LIKE ?)'));
    assert_true(str_contains($schema, '`original_name` VARCHAR(255)'), 'the column exists');
    assert_false(str_contains($admin, 'original_filename'), 'that column never existed');
    assert_true(str_contains($schema, "`kind`          ENUM('image','document','other')"));
    assert_true(str_contains($admin, "in_array(\$kindFilter, ['image', 'document', 'other'], true)"));
    assert_true(str_contains($admin, '$res = Media::delete($mediaId);'), 'in-use files are refused and the file leaves the disk');
    assert_false(str_contains($admin, "Database::delete('media_files'"), 'no raw row delete');
});

unit('media usage: Studio adds its page counts to the media library map and leaves other entries alone', function (): void {
    $map = \Slate\Module\StudioBuilder\Dependency\StudioMediaUsage::merge(
        ['/uploads/shop/a.png' => 2, '/uploads/media/b.png' => 1],
        [['path' => '/uploads/media/b.png', 'c' => '3'], ['path' => '/uploads/media/c.png', 'c' => 1], ['path' => '', 'c' => 5], ['path' => '/x.png', 'c' => 0]]
    );
    assert_eq(['/uploads/shop/a.png' => 2, '/uploads/media/b.png' => 4, '/uploads/media/c.png' => 1], $map);
    $sql = \Slate\Module\StudioBuilder\Dependency\StudioMediaUsage::SQL;
    assert_true(str_contains($sql, "p.status <> 'archived'"), 'an archived page holds no file');
    assert_true(str_contains($sql, 'p.active_draft_revision_id = d.revision_id OR p.published_revision_id = d.revision_id'), 'only the current draft and live revision count');
    assert_true(str_contains($sql, 'f.tenant_id = d.tenant_id') && str_contains($sql, 'WHERE d.tenant_id = ?'), 'one tenant only');
    $plugin = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/StudioBuilder.php');
    assert_true(str_contains($plugin, "\\Hook::removeFilter('media_usage', \$mediaUsage);"), 'registered once even when booted twice');
});
