<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Theme Builder correctness (2026-10-26 builder audit, step 5).
 *
 * Autoloader only. Archive, search and 404 pages get a head of their own; tag and author conditions can match; a rating
 * field's scale is bounded.
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

use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Theme\TemplateConditionMatcher;

function sba5_head(): SeoHead
{
    return new SeoHead('Category Archive', 'Template description', 'index,follow', 'https://acme.test/archive', null, 'Acme');
}

function sba5_ctx(array $attributes): RenderContext
{
    return RenderContext::forPublic(7, new SiteContext('https://acme.test', 'Acme'), static fn(): bool => true, $attributes);
}

unit('listing heads: an archive is titled by its term with its own canonical; search and 404 are never indexed', function (): void {
    $archive = sba5_head()->forListing(sba5_ctx(['archive_type' => 'category', 'term' => 'Engineering & Ops']));
    assert_eq('Engineering & Ops | Acme', $archive->title);
    assert_eq('https://acme.test/category/Engineering%20%26%20Ops', $archive->canonicalUrl, 'its own address, not the template page');

    $search = sba5_head()->forListing(sba5_ctx(['is_search' => true, 'search_query' => 'kubernetes']));
    assert_eq(['kubernetes | Acme', 'noindex,follow', null], [$search->title, $search->robots, $search->canonicalUrl]);

    $missing = sba5_head()->forListing(sba5_ctx(['is_404' => true]));
    assert_eq(['noindex,follow', null], [$missing->robots, $missing->canonicalUrl]);

    $page = sba5_head();
    assert_true($page->forListing(sba5_ctx([])) === $page, 'an ordinary page is unchanged');

    $verified = new SeoHead('T', '', 'index,follow', null, null, 'Acme', ['google-site-verification' => 'gv_1']);
    foreach ([['archive_type' => 'tag', 'term' => 'x'], ['is_search' => true]] as $attributes) {
        assert_eq(['google-site-verification' => 'gv_1'], $verified->forListing(sba5_ctx($attributes))->verification, 'the site verification tag stays on listing pages');
    }
});

unit('single templates: tag and author conditions, and their excludes, now see the page', function (): void {
    $rules = ['rules' => [
        ['action' => 'include', 'condition' => 'entire_site'],
        ['action' => 'exclude', 'condition' => 'tag', 'value' => 'draft-notes'],
    ]];
    assert_true(TemplateConditionMatcher::score($rules, ['tags' => ['launch']]) > 0, 'a page without the tag gets the template');
    assert_eq(0, TemplateConditionMatcher::score($rules, ['tags' => ['draft-notes']]), 'the tag exclude applies');
    assert_true(TemplateConditionMatcher::score(['rules' => [['action' => 'include', 'condition' => 'author', 'value' => 12]]], ['author_id' => 12]) > 0, 'an author condition matches its author');
});

unit('rating fields: the scale is kept to 1-10 stars, so a huge max cannot exhaust memory', function (): void {
    assert_eq(MetaFieldTypes::MAX_RATING, MetaFieldTypes::options('rating', ['max' => '1000000000000'])['max'] ?? null);
    assert_eq(1, MetaFieldTypes::options('rating', ['max' => '0'])['max'] ?? null);
    assert_eq(7, MetaFieldTypes::options('rating', ['max' => '7'])['max'] ?? null);
    assert_eq(1000000, MetaFieldTypes::options('number', ['max' => '1000000'])['max'] ?? null, 'other number fields are not capped');
});
