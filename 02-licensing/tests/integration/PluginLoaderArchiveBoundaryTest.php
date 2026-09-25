<?php
/**
 * Phase 1D A2 — PluginLoader::boot() left a stale-active plugin reporting
 * isActive()===true forever, and archive/ had no execution/access boundary
 * of its own.
 *
 * PluginLoader::boot() set `self::$activeSlugs[$slug] = true` unconditionally
 * BEFORE loadOne()/boot() ran, and its catch block only ever did
 * `unset(self::$active[$slug])` — never touching `$activeSlugs`. So a plugin
 * whose `plugins` row still says status='active' but whose directory is gone
 * (exactly what "moved to archive/" looks like) fell through loadOne()'s own
 * `!is_dir($dir)` check, returned null without throwing, and left
 * `PluginLoader::isActive($slug)` reporting true anyway — the one thing every
 * other plugin's own activation gate (`if (!PluginLoader::isActive('shop'))
 * { 503 }`, `Membership::boot()`'s multilang-translate check, etc.) trusts to
 * be accurate. A plugin whose boot() threw partway through had the identical
 * gap via the catch block.
 *
 * The fix moves the flag-set to only after loadOne() actually returns a
 * plugin instance, and unsets it alongside $active in the catch block — so
 * a missing directory or a thrown boot() both correctly report inactive.
 *
 * Separately, archive/ (holding archive/plugins/* and archive/tests/*, the
 * disk remnants of anything actually archived) had no .htaccess of its own,
 * unlike every other sensitive directory (bin/, tests/, db/, vendor/) — so
 * an archived plugin's own public/storefront PHP files, if their own
 * isActive() gate were fooled by the bug above, would still have been
 * directly web-reachable. A blanket `Require all denied`, matching the
 * sibling directories' own .htaccess exactly, closes that regardless of
 * what any individual archived file's own logic does.
 */

declare(strict_types=1);

unit('PluginLoader::isActive(): a stale-active row with no directory on disk (the archive scenario) reports inactive, not active', function (): void {
    $slug = '__probe-archived-' . bin2hex(random_bytes(4));

    // Exactly what an archived plugin looks like if its `plugins` row was
    // never flipped to inactive: status='active', directory gone. No
    // plugins/<slug> directory is created here, on purpose.
    Database::insert('plugins', [
        'slug'          => $slug,
        'name'          => 'Probe archived plugin',
        'version'       => '1.0.0',
        'status'        => 'active',
        'manifest_json' => '{}',
    ]);

    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/plugin-isactive-probe.php') . ' '
             . escapeshellarg($slug) . ' 2>&1';
        $out = trim((string) shell_exec($cmd));

        assert_eq('false', $out, 'a plugin with no directory on disk must never report isActive()===true, no matter what its DB row says');
    } finally {
        Database::query('DELETE FROM plugins WHERE slug = ?', [$slug]);
    }
});

unit('PluginLoader::boot(): the fix is present in the shipped file — the active-slug flag is set only after a successful load, and cleared alongside $active on failure', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/src/Kernel/Module/PluginLoader.php');

    $loopStart      = strpos($src, 'self::$activeSlugs = [];');
    $ifPluginPos    = strpos($src, 'if ($plugin) {', $loopStart !== false ? $loopStart : 0);
    $setActivePos   = strpos($src, 'self::$active[$slug] = $plugin;');
    $setSlugPos     = strpos($src, 'self::$activeSlugs[$slug] = true;', $loopStart !== false ? $loopStart : 0);
    $catchPos       = strpos($src, 'catch (\Throwable $e) {', $setSlugPos !== false ? $setSlugPos : 0);
    $unsetActivePos = strpos($src, 'unset(self::$active[$slug]);');
    $unsetSlugPos   = strpos($src, 'unset(self::$activeSlugs[$slug]);');

    assert_true($loopStart !== false, 'the active-slugs loop init must still be present');
    assert_true($ifPluginPos !== false, 'the successful-load branch must still be present');
    assert_true($setActivePos !== false && $setSlugPos !== false, 'both flag-sets must still be present');
    assert_true($catchPos !== false && $unsetActivePos !== false && $unsetSlugPos !== false, 'the catch block must still unset both flags');

    assert_true($ifPluginPos < $setSlugPos, 'activeSlugs must be set INSIDE the successful-load branch, not before loadOne() runs');
    assert_true($setActivePos < $setSlugPos, 'activeSlugs must be set only once $active is already set for this slug');
    assert_true($catchPos > $setSlugPos, 'the catch block must come after the flag-sets it is meant to unwind');
    assert_true($unsetActivePos < $unsetSlugPos, 'the catch block must unset $active before $activeSlugs (matching the set order)');
});

unit('archive/ has its own access boundary, matching bin/, tests/, db/ and vendor/', function (): void {
    $root = dirname(__DIR__, 2);
    $file = $root . '/archive/.htaccess';

    assert_true(is_file($file), 'archive/.htaccess must exist');
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'Require all denied'), 'archive/ must deny all requests, matching its sibling protected directories');
});
