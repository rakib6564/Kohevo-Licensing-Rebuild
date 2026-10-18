<?php
/**
 * Kohevo Studio — theme.search_box (gap report TB-07, W-F15).
 *
 * The box is rendered per request (it shows the visitor's own query), has an accessible name, and
 * submits to the site's own /search address, including when the install sits in a folder.
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
    $studioSearchBoxStandalone = true;
}

use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\SearchBoxRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class _SbSearchBoxNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return null;
    }
}

/** @param array<string, mixed> $props @param array<string, mixed> $attributes */
function sbsb_render(string $baseUrl, array $props, array $attributes = []): string
{
    $context = RenderContext::forPublic(1, new SiteContext($baseUrl, 'Test Site', 'en'), null, $attributes);
    $scope = new BlockRenderScope(['id' => 'blk_search', 'type' => 'theme.search_box', 'props' => $props], $context, new _SbSearchBoxNoMedia(), new ResolvedTheme('default', []), new RenderCollector('nonce'));
    return (new SearchBoxRenderer())->render($scope);
}

unit('search box: it is rendered per request, so one visitor\'s query is never shown to another', function (): void {
    assert_true((new SearchBoxRenderer())->isDynamic(), 'theme.search_box is dynamic');
    assert_true(str_contains(sbsb_render('https://example.test', [], ['search_query' => 'studio']), 'value="studio"'), 'it shows the query it was given');
});

unit('search box: it submits to the site\'s own /search, also from a folder install', function (): void {
    assert_true(str_contains(sbsb_render('https://example.test', []), 'action="https://example.test/search"'), 'root install');
    assert_true(str_contains(sbsb_render('https://example.test/solaya', []), 'action="https://example.test/solaya/search"'), 'folder install');
});

unit('search box: the input has an accessible name: the placeholder, or "Search" when there is none', function (): void {
    assert_true((bool) preg_match('~<input type="search"[^>]* aria-label="Find a post"~', sbsb_render('https://example.test', ['placeholder' => 'Find a post'])), 'the placeholder names it');
    assert_true((bool) preg_match('~<input type="search"[^>]* aria-label="Search"~', sbsb_render('https://example.test', ['placeholder' => ''])), 'an empty placeholder falls back to "Search"');
});

if (!empty($studioSearchBoxStandalone)) {
    exit(unit_summary());
}
