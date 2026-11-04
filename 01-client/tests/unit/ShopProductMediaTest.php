<?php
/**
 * Shop product images from the Media library: the product form's image manager, the picker's several-at-once mode,
 * and the picker's text escaping. Source checks (the repo's usual style for admin screens) plus the script run in node
 * when it is installed.
 */

declare(strict_types=1);

$__root = dirname(__DIR__, 2);

unit('media picker: file names are escaped as text (a < can no longer open a tag; an apostrophe stays an apostrophe)', function () use ($__root): void {
    $js = (string) file_get_contents($__root . '/plugins/media-library/assets/js/picker.js');
    preg_match('/function escText\(s\) \{(.*?)\n    \}/s', $js, $m);
    assert_true(isset($m[1]), 'escText found');
    assert_true(str_contains($m[1], ".replace(/</g, '&lt;')"), 'escapes <');
    assert_true(!str_contains($m[1], ".replace(/'/g, '&lt;')"), 'no longer turns an apostrophe into &lt;');
    $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
    if ($node !== '') {
        $code = 'var s=' . json_encode($m[0]) . '; eval(s); process.stdout.write(escText("<img src=x onerror=alert(1)> Chef\'s & co"));';
        $out = (string) shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($code) . ' 2>&1');
        assert_eq("&lt;img src=x onerror=alert(1)&gt; Chef's &amp; co", $out, 'run in node');
    }
});

unit('media picker: several at once keeps the order chosen and gives each item its own alt text', function () use ($__root): void {
    $js = (string) file_get_contents($__root . '/plugins/media-library/assets/js/picker.js');
    assert_true(str_contains($js, "picked.slice().forEach(commitPick)"), 'every chosen item is handed back, in order');
    assert_true(str_contains($js, "classList.contains('is-active') && item === selectedItem"), 'the sidebar\'s edits apply only to the item shown there');
    assert_true(str_contains($js, "mode: opts.multiple ? 'append' : 'single'"), 'SlateMedia.open({multiple:true}) is the several-at-once mode');
});

unit('shop product form: the image manager draws from the one saved field, and works without the Media library', function () use ($__root): void {
    $php = (string) file_get_contents($__root . '/plugins/shop/admin/products.php');
    assert_true(str_contains($php, 'data-shop-media'), 'image manager root');
    assert_true(preg_match('/<textarea id="images" name="images"/', $php) === 1, 'the addresses field is still the saved one');
    assert_true(str_contains($php, "PluginLoader::isActive('media-library')"), 'the picker loads only with the Media library on');
    assert_true(str_contains($php, 'data-shop-variant-image'), 'variant image fields get a picker');
    assert_true(str_contains($php, "'avatar_html'"), 'the product list shows the product image');
    $js = $__root . '/plugins/shop/assets/admin/product-media.js';
    assert_true(is_file($js) && is_file($__root . '/plugins/shop/assets/admin/product-media.css'), 'assets exist');
    $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
    if ($node !== '') {
        exec(escapeshellarg($node) . ' --check ' . escapeshellarg($js) . ' 2>&1', $o, $rc);
        assert_eq(0, $rc, 'product-media.js parses');
    }
});

unit('checkout quotes: an answer keeps what was typed while it was on its way, and a quote asked for meanwhile is sent after', function () use ($__root): void {
    $js = (string) file_get_contents($__root . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($js, 'function keepTyped(slot, typed, activeName)'), 'typed values are put back into the new form');
    assert_true(str_contains($js, "if (now[k] !== sent[k]) typed[k] = now[k];"), 'only what changed after sending');
    assert_true(str_contains($js, "/^(hidden|submit|button|reset|file|image)$/i"), 'hidden fields (tokens, stamps) stay the server\'s');
    assert_true(str_contains($js, "hasAttribute('data-sb-requote-button')) form.__sbRequoteAgain = true;"), 'only a quote is queued, never placing the order');
    $php = (string) file_get_contents($__root . '/plugins/shop/public/_shop.php');
    assert_true(str_contains($php, "if ((\$errors['shipping_rate'] ?? '') !== \$msg)"), 'the rates message is said once');
});
