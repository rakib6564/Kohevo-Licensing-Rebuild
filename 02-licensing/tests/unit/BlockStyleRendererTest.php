<?php
/**
 * Unit tests for BlockStyleRenderer — the live replacement for the archived
 * \Renderer::applyStyle() that PageRenderer used to defer to (see its own
 * docblock and PageRenderer::renderBlock()). Every DocumentValidator
 * STYLE_KEYS property, the responsive.{tablet,mobile} override, and the two
 * places this class re-checks a value DocumentValidator already validated
 * (bgImage scheme, CSS-breaking characters) are covered here.
 */

declare(strict_types=1);

use Slate\Presentation\Rendering\BlockStyleRenderer;

$html = '<p>Body</p>';

unit('an empty style returns the html completely unchanged', function () use ($html) {
    $r = new BlockStyleRenderer();
    assert_eq($html, $r->apply($html, []));
});

unit('bgColor + bgOpacity become an rgba() background-color', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['bgColor' => '#22aa66', 'bgOpacity' => 50]);
    assert_true(str_contains($out, 'background-color:rgba(34,170,102,0.5)'), $out);
});

unit('a 3-digit hex bgColor expands correctly', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['bgColor' => '#fff']);
    assert_true(str_contains($out, 'background-color:rgba(255,255,255,1)'), $out);
});

unit('bgImage + bgOverlay become a layered background-image', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['bgImage' => '/uploads/x.jpg', 'bgOverlay' => 'rgba(0,0,0,0.5)']);
    // The style="" attribute value is HTML-escaped as a whole, so the CSS's own
    // single quotes come out as &#039; here (correct — browsers decode it back
    // to ' when parsing the attribute); check content, not literal quote chars.
    assert_true(str_contains($out, 'linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.5))'), $out);
    assert_true(str_contains($out, '/uploads/x.jpg'), $out);
    assert_true(str_contains($out, 'background-size:cover'), $out);
});

unit('a javascript: bgImage is neutralized, not rendered as a background at all', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['bgImage' => 'javascript:alert(1)']);
    assert_false(str_contains($out, 'javascript:'), $out);
    assert_false(str_contains($out, 'background-image'), 'an unsafe URL must not produce any background-image at all: ' . $out);
});

unit('textColor, textAlign, spacing, borderRadius, and maxWidth all become real CSS', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, [
        'textColor' => '#111827', 'textAlign' => 'center',
        'paddingTop' => 10, 'paddingBottom' => 20, 'paddingLeft' => 0, 'paddingRight' => 5,
        'marginTop' => 8, 'marginBottom' => 12, 'borderRadius' => 4, 'maxWidth' => '600px',
    ]);
    foreach ([
        'color:#111827', 'text-align:center', 'padding-top:10px', 'padding-bottom:20px',
        'padding-left:0px', 'padding-right:5px', 'margin-top:8px', 'margin-bottom:12px',
        'border-radius:4px', 'max-width:600px',
    ] as $decl) {
        assert_true(str_contains($out, $decl), "missing '$decl' in: $out");
    }
});

unit('a bare numeric maxWidth is given a px unit', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['maxWidth' => '900']);
    assert_true(str_contains($out, 'max-width:900px'), $out);
});

unit('hideDesktop/hideTablet/hideMobile and customClass become real classes', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['hideDesktop' => true, 'hideTablet' => true, 'hideMobile' => true, 'customClass' => 'my-class']);
    foreach (['ve-hide-desktop', 've-hide-tablet', 've-hide-mobile', 'my-class', 'cb-block-wrapper'] as $cls) {
        assert_true(str_contains($out, $cls), "missing class '$cls' in: $out");
    }
});

unit('a style with only base properties needs no id or <style> block', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['bgColor' => '#000000']);
    assert_false(str_contains($out, '<style>'), 'no responsive override means no extra <style> tag: ' . $out);
    assert_false(str_contains($out, ' id="'), $out);
});

unit('a responsive.tablet/mobile override gets its own id and scoped @media rules', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, [
        'bgColor' => '#22aa66',
        'responsive' => [
            'tablet' => ['textAlign' => 'left'],
            'mobile' => ['textAlign' => 'center'],
        ],
    ]);
    assert_true((bool) preg_match('/id="(cb-blk-[a-z0-9-]+)"/', $out, $m), 'a responsive override must get a stable id: ' . $out);
    $id = $m[1];
    assert_true(str_contains($out, '<style>@media (max-width:1024px){#' . $id . '{text-align:left;}}'), $out);
    assert_true(str_contains($out, '@media (max-width:640px){#' . $id . '{text-align:center;}}'), $out);
    // The base bgColor is still a plain inline style, unaffected by the responsive block.
    assert_true(str_contains($out, 'background-color:rgba(34,170,102,1)'), $out);
});

unit('two blocks rendered by the same instance get different responsive-override ids', function () use ($html) {
    $r = new BlockStyleRenderer();
    $style = ['responsive' => ['mobile' => ['textAlign' => 'center']]];
    $out1 = $r->apply('<p>One</p>', $style);
    $out2 = $r->apply('<p>Two</p>', $style);
    preg_match('/id="(cb-blk-[a-z0-9-]+)"/', $out1, $m1);
    preg_match('/id="(cb-blk-[a-z0-9-]+)"/', $out2, $m2);
    assert_true($m1[1] !== $m2[1], 'each block instance needs a distinct id or their @media rules would collide: ' . $out1 . ' | ' . $out2);
});

unit('cssId becomes the wrapper\'s real id attribute', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['cssId' => 'my-anchor']);
    assert_true(str_contains($out, ' id="my-anchor"'), $out);
});

unit('zIndex implies position:relative (a bare z-index is a no-op otherwise)', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['zIndex' => 5]);
    assert_true(str_contains($out, 'position:relative;z-index:5;'), $out);
});

unit('a negative zIndex is preserved', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['zIndex' => -1]);
    assert_true(str_contains($out, 'z-index:-1;'), $out);
});

unit('a user-set cssId is reused as the id for a responsive override, instead of generating a second one', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['cssId' => 'hero-banner', 'responsive' => ['mobile' => ['textAlign' => 'center']]]);
    assert_true(substr_count($out, 'id="hero-banner"') === 1, 'exactly one id, matching the user\'s own choice: ' . $out);
    assert_true(str_contains($out, '@media (max-width:640px){#hero-banner{text-align:center;}}'), $out);
});

unit('a CSS-breaking character in an already-validated value is stripped, not trusted blindly', function () use ($html) {
    $r = new BlockStyleRenderer();
    $out = $r->apply($html, ['textColor' => '#111827;}body{display:none', 'maxWidth' => '600px}malicious{color:red']);
    assert_false(str_contains($out, '}body{display:none'), $out);
    assert_false(str_contains($out, '}malicious{color:red'), $out);
});
