<?php
/**
 * Unit tests for LegacyBlockBridge's rx-* widgets and the icon-grid/image-grid
 * upgrade — all previously either a generic heading+content placeholder or a
 * hardcoded, non-editable 3-card renderer (see the file's own docblocks for
 * the exact prior behavior each of these replaces). Pure: registration and
 * rendering have no DB dependency, so this exercises the real render closures
 * directly through PageRenderer, matching PageRendererTest's fixture pattern.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/helpers.php';

use Slate\Presentation\RenderContext;
use Slate\Presentation\Rendering\InMemoryBlockRegistry;
use Slate\Presentation\Rendering\LegacyBlockBridge;
use Slate\Presentation\Rendering\PageRenderer;

function _lbbFixture(): PageRenderer
{
    $reg = new InMemoryBlockRegistry();
    LegacyBlockBridge::register($reg);
    return new PageRenderer($reg);
}

$CTX = RenderContext::for(1);

unit('icon-grid renders each repeater item\'s own icon/title/text/link, not a hardcoded card set', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'icon-grid', 'props' => [
        'heading' => 'Highlights', 'cols' => '2',
        'items' => [
            ['icon' => 'leaf', 'title' => 'Fresh', 'text' => 'Locally sourced.'],
            ['icon' => 'bolt', 'title' => 'Fast', 'text' => 'Quick service.', 'linkText' => 'More', 'linkHref' => '/about'],
        ],
    ]], $CTX);
    assert_true(str_contains($html, 'Fresh'), 'first item title must render');
    assert_true(str_contains($html, 'Fast'), 'second item title must render');
    assert_true(str_contains($html, 'cb-grid-2'), 'column count must render');
    assert_true(str_contains($html, 'href="/about"'), 'a link item must render its href');
    assert_true(!str_contains($html, 'Simple and clear'), 'the old hardcoded placeholder cards must be gone');
});

unit('icon-grid with zero items renders an empty grid, not a fatal error', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'icon-grid', 'props' => ['heading' => 'Empty', 'items' => []]], $CTX);
    assert_true(str_contains($html, 'cb-grid'), 'the grid wrapper must still render');
});

unit('image-grid renders a real image via the media key and falls back to a placeholder icon when empty', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'image-grid', 'props' => [
        'heading' => 'Gallery',
        'items' => [
            ['media' => ['key' => 'branding/x.png', 'alt' => 'X'], 'title' => 'One'],
            ['title' => 'Two'],
        ],
    ]], $CTX);
    assert_true(str_contains($html, '/uploads/branding/x.png'), 'the media key must resolve to a real uploads URL');
    assert_true(str_contains($html, 'cb-image-card-ph'), 'a missing image must fall back to the placeholder icon markup');
});

unit('image-grid item with a link href renders as a real anchor', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'image-grid', 'props' => ['items' => [['title' => 'Linked', 'href' => '/x']]]], $CTX);
    assert_true((bool) preg_match('#<a class="cb-image-card" href="[^"]*/x"#', $html), 'a linked item must render as an <a>, not a <div>');
});

unit('rx-hero renders eyebrow/heading/lead/buttons/card from real props, not the fake placeholder', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'rx-hero', 'props' => [
        'tone' => 'dark', 'eyebrow' => 'Welcome', 'heading' => 'A table worth the trip', 'accentLine' => 'seasonal',
        'lead' => 'Come hungry.', 'btnText' => 'Reserve', 'btnHref' => '/reserve',
        'cardTitle' => 'Chef special', 'cardPrice' => '$28',
    ]], $CTX);
    assert_true(str_contains($html, 'rx-tone-dark'), 'tone must apply');
    assert_true(str_contains($html, 'Welcome'), 'eyebrow must render');
    assert_true(str_contains($html, '<em>seasonal</em>'), 'the accent line must render as its own emphasized line');
    assert_true(str_contains($html, 'href="/reserve"'), 'the button href must render');
    assert_true(str_contains($html, 'Chef special') && str_contains($html, '$28'), 'the floating info card must render');
    assert_true(!str_contains($html, 'cb-legacy-rx-hero'), 'the old generic legacy-placeholder wrapper must be gone');
});

unit('rx-hero with a background photo sets the CSS background variable and the photo class', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'rx-hero', 'props' => ['heading' => 'H', 'media' => ['key' => 'branding/bg.png', 'alt' => '']]], $CTX);
    assert_true(str_contains($html, 'rx-hero-photo'), 'photo class must apply when a background image is set');
    assert_true(str_contains($html, "--rx-hero-img:url('") && str_contains($html, '/uploads/branding/bg.png'), 'the resolved image URL must be set as the CSS variable');
});

unit('rx-marquee repeats each phrase twice for the seamless scroll loop and renders nothing when empty', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'rx-marquee', 'props' => ['items' => [['text' => 'Farm to table']]]], $CTX);
    assert_eq(2, substr_count($html, 'Farm to table'), 'the phrase must be duplicated for the scroll loop');

    $empty = $r->renderBlock(['type' => 'rx-marquee', 'props' => ['items' => []]], $CTX);
    assert_eq('', $empty, 'an empty marquee must render nothing rather than an empty shell');
});

unit('rx-menu renders each dish repeater row with its own name/price/text/photo', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'rx-menu', 'props' => [
        'heading' => 'From the kitchen',
        'items' => [
            ['name' => 'Seared Scallops', 'price' => '$24', 'text' => 'With brown butter.', 'tag' => "Chef's pick"],
            ['name' => 'Soup', 'price' => '$9'],
        ],
    ]], $CTX);
    assert_true(str_contains($html, 'Seared Scallops') && str_contains($html, '$24'), 'the first dish must render');
    assert_true(str_contains($html, 'Soup') && str_contains($html, '$9'), 'the second dish must render');
    assert_eq(2, substr_count($html, 'rx-dish"'), 'each repeater row must render its own dish card');
});

unit('rx-reviews renders each review\'s own star count, quote, name and meta', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'rx-reviews', 'props' => [
        'items' => [['rating' => '3', 'quote' => 'It was fine.', 'name' => 'Sam', 'meta' => 'Google']],
    ]], $CTX);
    assert_true(str_contains($html, str_repeat('★', 3) . str_repeat('☆', 2)), 'the star rating must reflect the item\'s own rating');
    assert_true(str_contains($html, 'It was fine.') && str_contains($html, 'Sam') && str_contains($html, 'Google'), 'quote/name/meta must all render');
});

unit('rx-reviews falls back to the reviewer\'s initial when no avatar is set', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'rx-reviews', 'props' => ['items' => [['quote' => 'Q', 'name' => 'Zara']]]], $CTX);
    assert_true((bool) preg_match('#<span class="rx-avatar">Z</span>#', $html), 'the avatar fallback must show the first letter of the name');
});

unit('rx-story shows a stat medallion with no photo, and a stat chip once a photo is set', function () use ($CTX) {
    $r = _lbbFixture();
    $noPhoto = $r->renderBlock(['type' => 'rx-story', 'props' => ['statBig' => '12', 'statLabel' => 'years']], $CTX);
    assert_true(str_contains($noPhoto, 'rx-story-medallion'), 'no photo must show the medallion');

    $withPhoto = $r->renderBlock(['type' => 'rx-story', 'props' => [
        'statBig' => '12', 'statLabel' => 'years', 'media' => ['key' => 'branding/story.png', 'alt' => ''],
    ]], $CTX);
    assert_true(str_contains($withPhoto, 'rx-story-chip'), 'a photo must show the stat chip instead');
    assert_true(!str_contains($withPhoto, 'rx-story-medallion'), 'the medallion must not render alongside a photo');
});

unit('rx-story renders each feature repeater row', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'rx-story', 'props' => [
        'items' => [['icon' => '🌿', 'title' => 'Organic', 'text' => 'Always fresh'], ['title' => 'Local']],
    ]], $CTX);
    assert_eq(2, substr_count($html, 'rx-feat"'), 'both feature rows must render');
    assert_true(str_contains($html, 'Organic') && str_contains($html, 'Local'), 'each feature title must render');
});

unit('rx-visit renders address/phone meta and an hours table from repeater rows', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'rx-visit', 'props' => [
        'heading' => 'Come visit', 'address' => '123 Main St', 'phone' => '555-1234',
        'items' => [['label' => 'Mon-Fri', 'value' => '11am-10pm', 'highlight' => 'true'], ['label' => 'Sun', 'value' => 'Closed']],
    ]], $CTX);
    assert_true(str_contains($html, '123 Main St') && str_contains($html, '555-1234'), 'address and phone must render');
    assert_true((bool) preg_match('#<li class="rx-hours-open"><span>Mon-Fri</span><b>11am-10pm</b></li>#', $html), 'a highlighted row must carry the open-now class');
    assert_true((bool) preg_match('#<li><span>Sun</span><b>Closed</b></li>#', $html), 'a non-highlighted row must not carry the open-now class');
});

unit('rx-visit with no hours rows omits the hours list entirely', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'rx-visit', 'props' => ['heading' => 'Come visit']], $CTX);
    assert_true(!str_contains($html, 'rx-hours'), 'no hours rows means no <ul class="rx-hours">');
});

unit('columns with no per-device override renders exactly as before (no id, no <style> tag)', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'columns', 'props' => ['columns' => 3, 'gap' => 24, 'cols' => [['blocks' => []], ['blocks' => []], ['blocks' => []]]]], $CTX);
    assert_true(str_contains($html, 'cb-columns cb-cols-3"'), 'the bare class list must render with no id attribute');
    assert_true(!str_contains($html, '<style>'), 'no override means no extra <style> tag');
});

unit('columns with a tablet override emits a scoped @media(max-width:1024px) rule', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'columns', 'props' => ['columns' => 4, 'columnsTablet' => '2', 'cols' => []]], $CTX);
    assert_true((bool) preg_match('/id="(cb-cols-[a-f0-9]+)"/', $html, $m), 'a tablet override must assign the block a stable id');
    $id = $m[1];
    assert_true(str_contains($html, '@media (max-width:1024px){#' . $id . '{grid-template-columns:repeat(2,1fr)!important}}'), 'the tablet override must render as a scoped, important media rule');
});

unit('columns with a mobile override emits a scoped @media(max-width:640px) rule, independent of the tablet one', function () use ($CTX) {
    $r = _lbbFixture();
    $html = $r->renderBlock(['type' => 'columns', 'props' => ['columns' => 4, 'columnsTablet' => '3', 'columnsMobile' => '1', 'cols' => []]], $CTX);
    assert_true(str_contains($html, '@media (max-width:1024px)') && str_contains($html, 'repeat(3,1fr)'), 'the tablet rule must still be present');
    assert_true(str_contains($html, '@media (max-width:640px)') && str_contains($html, 'repeat(1,1fr)'), 'the mobile rule must render independently');
});
