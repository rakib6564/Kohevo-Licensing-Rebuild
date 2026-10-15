<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — the sample a single, archive, search or 404 template is previewed with.
 *
 * Autoloader only. A template's kind comes from its settings or conventional slug; the sample is a fixed set of values the
 * caller cannot name keys for, every one cut to a safe length; and the renderers that read them show them.
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

use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Theme\TemplatePreviewContext;

unit('template preview: a template kind comes from its settings or conventional slug, anything else has none', function (): void {
    assert_eq('single', TemplatePreviewContext::kind('single'));
    assert_eq('archive', TemplatePreviewContext::kind('archive'));
    assert_eq('search', TemplatePreviewContext::kind('search'));
    assert_eq('404', TemplatePreviewContext::kind('404'));
    assert_eq('404', TemplatePreviewContext::kind('not_found'));
    assert_eq('archive', TemplatePreviewContext::kind('', 'theme-archive'));
    assert_eq('search', TemplatePreviewContext::kind('page_template', 'search'));
    assert_null(TemplatePreviewContext::kind('header'));
    assert_null(TemplatePreviewContext::kind('', 'about'));
    assert_null(TemplatePreviewContext::kind('custom', 'my-single-thing'));
});

unit('template preview: archive, search and 404 samples are the fixed attributes, with text cut and cleaned', function (): void {
    $site = new SiteContext('https://example.test', 'Example');
    assert_eq(
        ['archive_type' => 'tag', 'term' => 'Dev', 'category' => '', 'tag' => 'Dev'],
        TemplatePreviewContext::attributes('archive', ['archive_type' => 'tag', 'term' => "Dev\n"], null, $site)
    );
    $other = TemplatePreviewContext::attributes('archive', ['archive_type' => 'galaxy', 'term' => ''], null, $site);
    assert_eq('category', $other['archive_type'], 'an unknown archive type becomes a category');
    assert_eq('Sample', $other['term'], 'an empty term gets a sample');
    assert_eq('Sample', $other['category']);

    $search = TemplatePreviewContext::attributes('search', ['search_query' => "a\x00b", 'is_404' => true, 'evil' => 'x'], null, $site);
    assert_eq(['search_query' => 'a b', 'is_search' => true], $search, 'only the named values pass, control characters go');
    assert_eq(['is_404' => true], TemplatePreviewContext::attributes('404', ['term' => 'x'], null, $site));
    assert_eq(TemplatePreviewContext::MAX_TEXT, mb_strlen(TemplatePreviewContext::attributes('search', ['search_query' => str_repeat('é', 500)], null, $site)['search_query']));
    assert_eq([], TemplatePreviewContext::attributes('header', ['term' => 'x'], null, $site));
});

unit('template preview: a single template takes its title, address and category from the page it is shown for', function (): void {
    $site = new SiteContext('https://example.test', 'Example');
    $host = ['title' => 'Hello world', 'id' => 12, 'slug' => 'hello', 'settings_json' => json_encode(['category' => 'News'])];
    assert_eq(
        ['post_title' => 'Hello world', 'post_url' => 'https://example.test/hello', 'category' => 'News', 'meta_page_id' => 12],
        TemplatePreviewContext::attributes('single', ['post_title' => 'forged'], $host, $site)
    );
    assert_eq([], TemplatePreviewContext::attributes('single', [], null, $site), 'no page, no sample');
    $bare = TemplatePreviewContext::attributes('single', [], ['title' => 'T', 'slug' => 't', 'settings_json' => 'not json'], $site);
    assert_eq('', $bare['category']);
});

unit('theme templates admin: it asks Auth for the user id by the name Auth has (Auth::id() does not exist and broke every creation)', function (): void {
    $auth = (string) file_get_contents(SLATE_ROOT . '/src/Services/Auth/Auth.php');
    assert_true(str_contains($auth, 'public static function userId('), 'Auth::userId() exists');
    assert_true(!str_contains($auth, 'public static function id('), 'Auth::id() does not');
    $admin = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/templates.php');
    assert_true(!str_contains($admin, 'Auth::id('), 'templates.php does not call Auth::id()');
    assert_true(str_contains($admin, "slug LIKE 'template-%'"), 'a published template page is still found by its slug');
});
