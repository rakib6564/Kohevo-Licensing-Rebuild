<?php
/**
 * Unit tests for the front-end admin bar: what an admin sees, and above all what
 * a cached public page must never carry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Services/Admin/FrontAdminBar.php';
require_once __DIR__ . '/../../src/Services/Auth/AdminHint.php';

use Slate\Module\StudioBuilder\Runtime\StudioHttpResponder;
use Slate\Services\Admin\FrontAdminBar;
use Slate\Services\Auth\AdminHint;

$frontBarView = static fn (array $over = []): array => $over + [
    'items' => [], 'wordmark' => '/w.svg', 'dashboard' => '/admin/', 'home' => '/', 'site' => 'Site',
    'user' => 'Sam', 'profile' => '', 'logout' => '/admin/logout.php?csrf=t',
];

unit('FrontAdminBar::localPath: strips the folder a site lives under, and only that', function () {
    assert_eq('about', FrontAdminBar::localPath('/fightxlife/about', 'https://x.test/fightxlife'));
    assert_eq('', FrontAdminBar::localPath('/fightxlife/', 'https://x.test/fightxlife'));
    assert_eq('', FrontAdminBar::localPath('/fightxlife', 'https://x.test/fightxlife/'));
    assert_eq('fightxlife-news', FrontAdminBar::localPath('/fightxlife-news', 'https://x.test/fightxlife'));
    assert_eq('blog/post', FrontAdminBar::localPath('/blog/post/', 'https://x.test'));
    assert_eq('', FrontAdminBar::localPath('/', 'https://x.test'));
});

unit('FrontAdminBar::visibleItems: hides what the admin may not use, drops malformed items, keeps the order', function () {
    $items = [
        ['id' => 'b', 'label' => 'B', 'href' => '/b', 'perm' => 'yes', 'order' => 20],
        ['id' => 'c', 'label' => 'C', 'href' => '/c', 'perm' => 'no', 'order' => 5],
        ['id' => 'a', 'label' => 'A', 'href' => '/a', 'order' => 10],
        ['id' => 'nolabel', 'href' => '/x'],
        'junk',
    ];
    $seen = FrontAdminBar::visibleItems($items, static fn (string $perm): bool => $perm === 'yes');
    assert_eq(['a', 'b'], array_column($seen, 'id'));
});

unit('FrontAdminBar::render: escapes every value and puts counts in the link', function () use ($frontBarView) {
    $html = FrontAdminBar::render($frontBarView([
        'user' => '<b>Sam</b>', 'site' => '"><script>x</script>',
        'items' => [['label' => 'Forms<i>', 'href' => '/f?a=1&b="2"', 'menu' => 'bar', 'count' => 150]],
    ]));
    assert_false(str_contains($html, '<script>'));
    assert_false(str_contains($html, '<b>Sam'));
    assert_false(str_contains($html, 'Forms<i>'));
    assert_true(str_contains($html, '/f?a=1&amp;b=&quot;2&quot;'));
    assert_true(str_contains($html, '99+'));
});

unit('FrontAdminBar::render: New only appears with something under it; Edit stays on the bar on a phone', function () use ($frontBarView) {
    assert_false(str_contains(FrontAdminBar::render($frontBarView()), 'id="kb-new"'));
    $html = FrontAdminBar::render($frontBarView(['items' => [
        ['label' => 'Page', 'href' => '/n', 'menu' => 'new'],
        ['label' => 'Edit', 'href' => '/e', 'menu' => 'bar', 'primary' => true],
    ]]));
    assert_true(str_contains($html, 'id="kb-new"'));
    assert_true(strpos($html, 'class="primary"') < strpos($html, 'id="kb-links"'));
});

unit('FrontAdminBar::render: no Kohevo mark when a white-labelled site passes none', function () use ($frontBarView) {
    $html = FrontAdminBar::render($frontBarView(['wordmark' => '']));
    assert_false(str_contains($html, '<img'));
    assert_false(str_contains($html, 'Kohevo'));
});

unit('FrontAdminBar: the Edit menu lists only what the admin may open, tagged, divided by group', function () use ($frontBarView) {
    $items = FrontAdminBar::visibleItems([
        ['label' => 'Edit', 'href' => '/e', 'perm' => 'yes', 'primary' => true, 'children' => [
            ['label' => 'Main <h>', 'href' => '/h', 'perm' => 'yes', 'tag' => 'Header', 'group' => 'page'],
            ['label' => 'Secret', 'href' => '/s', 'perm' => 'no', 'tag' => 'Website', 'group' => 'site'],
            ['label' => 'Menus', 'href' => '/m', 'perm' => 'yes', 'tag' => 'Website', 'group' => 'site'],
        ]],
    ], static fn (string $perm): bool => $perm === 'yes');
    $html = FrontAdminBar::render($frontBarView(['items' => $items]));
    assert_true(str_contains($html, 'id="kb-edit"'));
    assert_true(str_contains($html, 'Main &lt;h&gt;'));
    assert_false(str_contains($html, 'Secret'));
    assert_eq(1, substr_count($html, 'class="sep"'));
    assert_true(strpos($html, 'class="primary"') < strpos($html, 'id="kb-links"'));
});

unit('Page parts are worked out for the signed-in author only, never while rendering a public page', function () {
    $root = __DIR__ . '/../../';
    assert_false(str_contains((string) file_get_contents($root . 'src/Module/StudioBuilder/Runtime/StudioPublicRuntime.php'), 'PublishedPageParts'));
    assert_false(str_contains((string) file_get_contents($root . 'src/Module/StudioBuilder/Render/PageDocumentAssembler.php'), 'pageParts'));
    assert_true(str_contains((string) file_get_contents($root . 'plugins/studio-builder/StudioBuilder.php'), "pageParts->of("));
});

unit('AdminHint: readable by script, carries no identity, ends with the browser session, stays on the site path', function () {
    $options = AdminHint::cookieOptions();
    assert_false($options['httponly']);
    assert_eq(0, $options['expires']);
    assert_eq('Lax', $options['samesite']);
    assert_eq('/fightxlife', AdminHint::sitePath('https://x.test/fightxlife/'));
    assert_eq('/', AdminHint::sitePath('https://x.test'));
});

unit('FrontAdminBar::scriptTag: nothing without the hint, nothing in an embed', function () {
    $cookie = $_COOKIE;
    $get = $_GET;
    $_COOKIE = [];
    assert_eq('', FrontAdminBar::scriptTag());
    $_COOKIE[AdminHint::COOKIE] = '1';
    $_GET['embed'] = '1';
    assert_eq('', FrontAdminBar::scriptTag());
    $_COOKIE = $cookie;
    $_GET = $get;
});

unit('Cached Studio pages: the document assembler knows nothing of the bar or its cookie', function () {
    $src = file_get_contents(__DIR__ . '/../../src/Module/StudioBuilder/Render/PageDocumentAssembler.php');
    assert_false(str_contains($src, 'AdminHint'));
    assert_false(str_contains($src, 'FrontAdminBar'));
    assert_false(str_contains($src, 'admin_hint'));
    $responder = file_get_contents(__DIR__ . '/../../src/Module/StudioBuilder/Runtime/StudioHttpResponder.php');
    assert_false(str_contains($responder, 'AdminHint'));
});

unit('Studio runtime: only asks on a public page with the hint, and never holds admin data', function () {
    $src = file_get_contents(__DIR__ . '/../../plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($src, "classList.contains('sb-body--public')"));
    assert_true(str_contains($src, 'kohevo_admin_hint=1'));
    assert_false(str_contains($src, 'front-bar.php'));
});

unit('admin/front-bar.php: never stored, and the session decides, before anything is built', function () {
    $src = file_get_contents(__DIR__ . '/../../admin/front-bar.php');
    assert_true(str_contains($src, "Cache-Control: private, no-store"));
    assert_true(str_contains($src, "Vary: Cookie"));
    $check = strpos($src, 'if (!Auth::check())');
    assert_true($check !== false && $check < strpos($src, 'FrontAdminBar::forCurrentUser'));
    assert_true($check < strpos($src, 'json_encode'));
});

unit('The hint cookie is written on sign-in and removed on sign-out, never on the public path', function () {
    $auth = file_get_contents(__DIR__ . '/../../src/Services/Auth/Auth.php');
    assert_eq(2, substr_count($auth, 'AdminHint::set()'));
    assert_eq(1, substr_count($auth, 'AdminHint::clear()'));
    assert_true(str_contains(file_get_contents(__DIR__ . '/../../assets/js/front-admin-bar.js'), 'sb-body--(preview|editor)'));
});

unit('A hint cookie on a public response would still be kept, so nothing on that path may set one', function () {
    $lines = ['Set-Cookie: ' . AdminHint::COOKIE . '=1; path=/'];
    assert_eq($lines, StudioHttpResponder::keptCookieLines($lines, 'SLATE_SID'));
});
