<?php
/**
 * Kohevo Studio — renderer correctness (gap report W-F14, W-F17, W-F20, W-F11).
 *
 * A payment button follows Stripe's state instead of being frozen into a compiled page; a form field
 * renders the same markup every time; the Query Loop reads only a sane page number; and theme post
 * blocks show the post being viewed before their own fallback text.
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
    $studioRendererCorrectnessStandalone = true;
}

use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\FormFieldRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\PostContentRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\PostTitleRenderer;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\QueryLoopRenderer;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\PaymentButtonRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class _SbRendererCorrectnessNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return null;
    }
}

/** @param array<string, mixed> $props @param array<string, mixed> $attributes @param array<string, mixed> $rows */
function sbrc_render(BlockRendererInterface $renderer, string $type, array $props, array $attributes = [], string $id = 'blk_aaaaaaaaaaaaaaaaaaaaaaaa', array $rows = []): string
{
    $context = RenderContext::forPublic(1, new SiteContext('https://example.test', 'Test Site', 'en'), null, $attributes);
    $scope = new BlockRenderScope(['id' => $id, 'type' => $type, 'props' => $props, 'bindings' => $rows === [] ? [] : ['items' => ['provider' => 'content.posts']]], $context, new _SbRendererCorrectnessNoMedia(), new ResolvedTheme('default', []), new RenderCollector('nonce'), '', $rows === [] ? [] : ['items' => $rows]);
    return $renderer->render($scope);
}

unit('payment button: it is dynamic, so a page compiled before Stripe was set up still shows it once Stripe is ready (W-F14)', function (): void {
    assert_true((new PaymentButtonRenderer())->isDynamic(), 'payment.button is rendered per request');
});

unit('form field: the same document renders the same id, and two fields get different ids (W-F17)', function (): void {
    $props = ['name' => 'email', 'label' => 'Email', 'field_type' => 'email'];
    $a = sbrc_render(new FormFieldRenderer(), 'core.form_field', $props);
    $b = sbrc_render(new FormFieldRenderer(), 'core.form_field', $props);
    assert_eq($a, $b, 'two renders are identical');
    assert_true((bool) preg_match('/id="(sb_f_email_[0-9a-f]{8})"/', $a, $m), 'the id keeps its sb_f_<name>_<8 hex> shape: ' . $a);
    assert_true(str_contains($a, 'for="' . $m[1] . '"'), 'the label points at it');
    $other = sbrc_render(new FormFieldRenderer(), 'core.form_field', $props, [], 'blk_bbbbbbbbbbbbbbbbbbbbbbbb');
    assert_false(str_contains($other, 'id="' . $m[1] . '"'), 'another block with the same field name gets its own id');
});

unit('query loop: only a whole page number from 1 to 1000 is read from the link or the render context (W-F20)', function (): void {
    $rows = array_map(static fn(int $i): array => ['id' => $i, 'title' => "Post {$i}", 'url' => "/p{$i}"], range(1, 3));
    $saved = $_GET;
    try {
        $cases = [['2', 2], ['0', 1], ['-3', 1], ['abc', 1], ['2.5', 1], ['1001', 1], [['x'], 1], ['1000', 1000]];
        foreach ($cases as [$raw, $want]) {
            $_GET = ['page' => $raw];
            $html = sbrc_render(new QueryLoopRenderer(), 'core.query_loop', ['per_page' => 3, 'enable_pagination' => true], [], 'blk_loop', $rows);
            assert_true(str_contains($html, 'aria-current="page">' . $want . '<'), 'page ' . json_encode($raw) . ' is read as ' . $want . ': ' . substr($html, (int) strpos($html, '<nav')));
        }
        $_GET = ['page' => '5'];
        $html = sbrc_render(new QueryLoopRenderer(), 'core.query_loop', ['per_page' => 3, 'enable_pagination' => true], ['page' => 2], 'blk_loop', $rows);
        assert_true(str_contains($html, 'aria-current="page">2<'), 'a page set on the render context wins over the link');
    } finally {
        $_GET = $saved;
    }
});

unit('post title and content: the post being shown wins over the block\'s fallback text (W-F11)', function (): void {
    $title = sbrc_render(new PostTitleRenderer(), 'theme.post_title', ['text' => 'Fallback title'], ['title' => 'Real title']);
    assert_true(str_contains($title, '>Real title<'), 'the post title is shown: ' . $title);
    assert_false(str_contains($title, 'Fallback title'), 'the fallback is not');
    $noPost = sbrc_render(new PostTitleRenderer(), 'theme.post_title', ['text' => 'Fallback title']);
    assert_true(str_contains($noPost, '>Fallback title<'), 'with no post, the fallback is shown');

    $content = sbrc_render(new PostContentRenderer(), 'theme.post_content', ['content' => 'Fallback body'], ['post_content' => 'Real body']);
    assert_true(str_contains($content, 'Real body') && !str_contains($content, 'Fallback body'), 'the post content is shown: ' . $content);
    $noContent = sbrc_render(new PostContentRenderer(), 'theme.post_content', ['content' => 'Fallback body']);
    assert_true(str_contains($noContent, 'Fallback body'), 'with no post, the fallback is shown');
});

if (!empty($studioRendererCorrectnessStandalone)) {
    exit(unit_summary());
}
