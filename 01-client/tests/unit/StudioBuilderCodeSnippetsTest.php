<?php
/**
 * Unit tests for head/footer code snippets and search-engine verification tokens (StudioCodePolicy):
 * what a snippet may contain, what is refused and why, and that nothing is emitted outside the public site.
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
    $studioSnipUnitStandalone = true;
}

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Render\RenderMode;

unit('verification: a bare token and a pasted meta tag both give the token; junk is refused; empty is nothing', function (): void {
    $token = 'AbC123_-xyzXYZ0123456789';
    assert_eq($token, StudioCodePolicy::verificationToken($token));
    assert_eq($token, StudioCodePolicy::verificationToken("  {$token}\n"));
    assert_eq($token, StudioCodePolicy::verificationToken('<meta name="google-site-verification" content="' . $token . '" />'));
    assert_eq($token, StudioCodePolicy::verificationToken("<meta content='{$token}' name='msvalidate.01'>"));
    assert_eq('', StudioCodePolicy::verificationToken(''));
    assert_eq('', StudioCodePolicy::verificationToken("  \n"));
    foreach (['short', 'has space in it', '"><script>alert(1)</script>', 'abc"def12345', str_repeat('a', 129), '<meta name="x" content="">'] as $bad) {
        assert_null(StudioCodePolicy::verificationToken($bad), "refused: {$bad}");
    }
});

unit('snippets: ordinary vendor tags are accepted in the head', function (): void {
    foreach ([
        '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC1234"></script>',
        "<script>window.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\nif (1 < 2 && a<b) { gtag('js', new Date()); }</script>",
        '<meta name="google-site-verification" content="abcdefgh12345678">',
        '<link rel="preconnect" href="https://fonts.gstatic.com">',
        '<style>body{color:red}</style>',
        '<!-- Meta Pixel --><script>fbq("init","1");</script><noscript><meta name="x" content="y"></noscript>',
        '',
    ] as $ok) {
        assert_null(StudioCodePolicy::snippetProblem($ok, 'head'), 'accepted: ' . substr($ok, 0, 50));
    }
});

unit('snippets: the footer also takes tracking pixels and the GTM noscript iframe', function (): void {
    $gtm = '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC1234" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
    assert_null(StudioCodePolicy::snippetProblem($gtm, 'footer'));
    assert_null(StudioCodePolicy::snippetProblem('<img src="https://px.example/p.gif" width="1" height="1" alt="">', 'footer'));
    assert_eq('tag:iframe', StudioCodePolicy::snippetProblem($gtm, 'head'), 'an iframe has no place in the head');
    assert_eq('tag:img', StudioCodePolicy::snippetProblem('<img src="x">', 'head'));
});

unit('snippets: anything that could reshape the document is refused with a reason', function (): void {
    $cases = [
        '</head><body>' => 'tag:head',
        '<body onload="x()">' => 'tag:body',
        '<html lang="en">' => 'tag:html',
        '<base href="https://evil.test/">' => 'tag:base',
        '<form action="/x"><input></form>' => 'tag:form',
        '<div>hello</div>' => 'tag:div',
        '<object data="x"></object>' => 'tag:object',
        '<a href="x">x</a>' => 'tag:a',
        '<!doctype html>' => 'tag:<!',
        '<?php echo 1; ?>' => 'tag:<?',
        '<script>alert(1)' => 'unclosed:script',
        '<style>a{}' => 'unclosed:style',
        '<noscript><meta name="a" content="b">' => 'unclosed:noscript',
        '</script>' => 'stray_close:script',
        '<script src="x"' => 'unclosed:script',
        '<!-- never ends <script></script>' => 'unclosed:comment',
        "<script>1</script>\x00" => 'control_characters',
    ];
    foreach ($cases as $html => $reason) {
        assert_eq($reason, StudioCodePolicy::snippetProblem($html, 'footer'), 'refused: ' . json_encode($html));
    }
});

unit('snippets: markup hidden in script text, quotes or comments is not mistaken for a tag', function (): void {
    assert_null(StudioCodePolicy::snippetProblem('<script>var s = "<div>"; var c = "</body>"; if (a<b) {}</script>', 'head'), 'raw script text is not scanned');
    assert_null(StudioCodePolicy::snippetProblem('<meta name="a" content="1 > 2 <div>">', 'head'), 'quoted attribute values may contain > and <');
    assert_null(StudioCodePolicy::snippetProblem('<!-- <div> --><meta name="a" content="b">', 'head'), 'comments are skipped');
    assert_eq('tag:div', StudioCodePolicy::snippetProblem('<script>1</script><div>', 'head'), 'a tag after a script is still checked');
    assert_true(StudioCodePolicy::snippetProblem('<scr<script>ipt>', 'footer') !== null, 'a split tag name is not a way in');
});

unit('snippets: size is capped; prepareSnippet trims and throws the reason', function (): void {
    $big = '<script>' . str_repeat('a', StudioCodePolicy::MAX_SNIPPET_BYTES) . '</script>';
    assert_eq('too_large', StudioCodePolicy::snippetProblem($big, 'head'));
    assert_eq('<meta name="a" content="b">', StudioCodePolicy::prepareSnippet("\n  <meta name=\"a\" content=\"b\">  \n", 'head'));
    assert_throws(\InvalidArgumentException::class, static fn () => StudioCodePolicy::prepareSnippet('<div>', 'head'));
    try {
        StudioCodePolicy::prepareSnippet('<base href="x">', 'footer');
        assert_true(false, 'must throw');
    } catch (\InvalidArgumentException $e) {
        assert_eq('tag:base', $e->getMessage());
    }
});

unit('snippets: nothing is emitted in the editor or preview, and an unset tenant emits nothing', function (): void {
    foreach ([RenderMode::Editor, RenderMode::Preview] as $mode) {
        assert_eq('', StudioCodePolicy::headMarkup(1, $mode));
        assert_eq('', StudioCodePolicy::bodyMarkup(1, $mode));
        assert_eq('', StudioCodePolicy::verificationMarkup(1, $mode));
    }
    assert_eq('', StudioCodePolicy::verificationMarkup(1, RenderMode::Public), 'no settings table in a unit run -> nothing');
    assert_eq('', StudioCodePolicy::bodyMarkup(1, RenderMode::Public));
});

unit('snippets: the new settings are separate from the legacy ones and the services are fixed', function (): void {
    assert_true(StudioCodePolicy::SETTING_HEAD_SNIPPET !== 'studio_code_head');
    assert_true(StudioCodePolicy::SETTING_FOOTER_SNIPPET !== 'studio_code_footer');
    assert_eq(['google', 'bing', 'facebook', 'pinterest'], array_keys(StudioCodePolicy::VERIFICATION_SERVICES));
    assert_eq('google-site-verification', StudioCodePolicy::VERIFICATION_SERVICES['google']);
});

if (!empty($studioSnipUnitStandalone)) {
    exit(unit_summary());
}
