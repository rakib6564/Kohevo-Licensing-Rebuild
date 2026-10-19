<?php
/**
 * Kohevo Studio — translation, accessibility and portfolio widgets (gap report W-F10, W-F9, W-F16, W-F8, W-F18, W-F28).
 *
 * Public strings and Inspector field labels go through the translation layer; the carousel keeps its translated
 * labels once the runtime runs, uses valid roles and can be paused; the two portfolio widgets no longer default to
 * the site owner's own contact details. Translation is checked in a child PHP process with a stub `__()`, so this
 * process keeps its English defaults.
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
    $studioI18nA11yStandalone = true;
}
require_once SLATE_ROOT . '/includes/Plugin.php';
require_once SLATE_ROOT . '/plugins/studio-builder/StudioBuilderPortfolioWidgets.php';
require_once SLATE_ROOT . '/plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php';

use Slate\Module\StudioBuilder\Application\StudioApplicationService;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers\CarouselRenderer;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Sdk\WidgetSdk;
use Slate\Module\StudioBuilder\StudioBuilderPortfolioWidgets;

final class _SbI18nNoMedia implements MediaResolverInterface
{
    public function resolveImage(int $mediaId): ?ResolvedMedia
    {
        return null;
    }
}

/** @param array<string, mixed> $props @param array<string, mixed> $attributes */
function sbia_scope(string $type, array $props, array $attributes = []): BlockRenderScope
{
    $context = RenderContext::forPublic(1, new SiteContext('https://example.test', 'Test Site', 'en'), null, $attributes);
    return new BlockRenderScope(['id' => 'blk_i18n', 'type' => $type, 'props' => $props], $context, new _SbI18nNoMedia(), new ResolvedTheme('default', []), new RenderCollector('nonce'));
}

/** Run PHP in a child process where `__($key, $default)` returns "«$key»", and return its output. */
function sbia_translated(string $body): string
{
    $file = tempnam(sys_get_temp_dir(), 'sbia') . '.php';
    file_put_contents($file, "<?php\nfunction __(string \$key, string \$default = ''): string { return '«' . \$key . '»'; }\n"
        . "define('SLATE_TESTING', true); define('SLATE_ROOT', " . var_export(SLATE_ROOT, true) . ");\n"
        . "require SLATE_ROOT . '/src/autoload.php';\n" . $body);
    try {
        return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
    } finally {
        @unlink($file);
    }
}

unit('public strings: archive titles, post meta, close buttons, video and the Query Loop go through translation (W-F10)', function (): void {
    $out = sbia_translated(<<<'PHP'
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Block\CoreRenderers as R;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
$r = static function ($renderer, string $type, array $props, array $attrs = []): string {
    $ctx = RenderContext::forPublic(1, new SiteContext('https://example.test', 'T', 'fr'), null, $attrs);
    return $renderer->render(new BlockRenderScope(['id' => 'blk_x', 'type' => $type, 'props' => $props], $ctx, new CoreMediaResolver(new \Slate\Tenancy\TenantContext()), new ResolvedTheme('default', []), new RenderCollector('nonce')));
};
echo $r(new R\ArchiveTitleRenderer(), 'theme.archive_title', [], ['is_search' => true, 'search_query' => 'q']), "\n";
echo $r(new R\ArchiveTitleRenderer(), 'theme.archive_title', [], ['archive_type' => 'category', 'term' => 'News']), "\n";
echo $r(new R\PostMetaRenderer(), 'theme.post_meta', ['show_date' => false, 'show_category' => false], ['author_name' => 'Ana']), "\n";
echo $r(new R\ModalRenderer(), 'core.modal', ['modal_id' => 'm1', 'title' => 'T']), "\n";
echo $r(new R\OffcanvasRenderer(), 'core.offcanvas', ['drawer_id' => 'd1', 'title' => 'T']), "\n";
echo $r(new R\VideoRenderer(), 'core.video', ['url' => 'nope']), "\n";
echo $r(new R\VideoRenderer(), 'core.video', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']), "\n";
echo $r(new R\QueryLoopRenderer(), 'core.query_loop', []), "\n";
PHP);
    foreach (['studio_archive_search_results', 'studio_archive_category', 'studio_post_by', 'studio_close', 'studio_video_unavailable', 'studio_video', 'studio_no_posts'] as $key) {
        assert_true(str_contains($out, '«' . $key . '»'), "{$key} is looked up: " . substr($out, 0, 600));
    }
    foreach (['Search Results:', 'Category:', 'By Ana', 'aria-label="Close"', 'Video unavailable', 'title="Video"', 'No posts found.'] as $english) {
        assert_false(str_contains($out, $english), "no hard-coded \"{$english}\"");
    }
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    foreach (['studio_archive_search_results', 'studio_archive_search', 'studio_archive_category', 'studio_archive_tag', 'studio_archive_author', 'studio_archive', 'studio_post_by', 'studio_close', 'studio_video_unavailable', 'studio_video', 'studio_untitled', 'studio_no_posts'] as $key) {
        assert_true(isset($fr[$key]) && $fr[$key] !== '', "French has {$key}");
    }
    assert_true(str_contains($fr['studio_archive_search_results'], '%s') && str_contains($fr['studio_post_by'], '%s'), 'the French keeps the placeholders');
});

unit('Inspector field labels: every label in the registry is translated, nested repeater labels too (W-F8)', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    $labels = [];
    $walk = function (array $fields) use (&$walk, &$labels): void {
        foreach ($fields as $f) {
            if (is_array($f) && is_string($f['label'] ?? null) && $f['label'] !== '') {
                $labels[$f['label']] = true;
            }
            foreach (is_array($f) ? $f : [] as $v) {
                if (is_array($v) && array_is_list($v) && isset($v[0]['key'])) {
                    $walk($v);
                }
            }
        }
    };
    foreach (ModuleBlockDefinitions::studioRegistry()->editorManifests() as $m) {
        $walk($m['field_schema'] ?? []);
    }
    $missing = array_values(array_filter(array_keys($labels), static fn(string $l): bool => !isset($fr[StudioApplicationService::fieldLabelKey($l)])));
    assert_eq([], $missing, 'every field label has a French entry');
    assert_eq('studio_field_heading_level', StudioApplicationService::fieldLabelKey('Heading Level'));

    $out = sbia_translated(<<<'PHP'
$m = new ReflectionMethod(\Slate\Module\StudioBuilder\Application\StudioApplicationService::class, 'translateBlockCopy');
$block = \Slate\Module\StudioBuilder\Registry\BlockRegistry::withAllCoreBlocks()->get('core.feature_list')->toEditorManifest();
echo json_encode($m->invoke(null, $block)['field_schema'], JSON_UNESCAPED_UNICODE);
PHP);
    $schema = json_decode($out, true);
    assert_true(is_array($schema), 'the translated manifest decodes: ' . substr($out, 0, 300));
    $labelsOut = array_column($schema, 'label');
    assert_true(in_array('«studio_field_columns»', $labelsOut, true), 'a top-level label is translated');
    $items = array_values(array_filter($schema, static fn(array $f): bool => $f['key'] === 'items'))[0];
    assert_true(in_array('«studio_field_heading»', array_column($items['item_schema'], 'label'), true), 'a repeater item label is translated');
});

unit('carousel: dots are a plain group, translated labels reach the runtime, and autoplay can be paused (W-F9, W-F16)', function (): void {
    $slides = [['quote' => 'One'], ['quote' => 'Two'], ['quote' => 'Three']];
    $html = (new CarouselRenderer())->render(sbia_scope('core.carousel', ['slides' => $slides, 'options' => ['autoplay_ms' => 4000, 'loop' => true]]));
    assert_true(str_contains($html, 'data-sb-car-dots role="group"'), 'the dots are a group of buttons, not a tablist');
    assert_false(str_contains($html, 'role="tablist"'), 'no tablist without tabs');
    foreach (['data-sb-label-slide="slide"', 'data-sb-label-slide-of="%1$s of %2$s"', 'data-sb-label-go="Go to slide"'] as $attr) {
        assert_true(str_contains($html, $attr), "the runtime gets {$attr}");
    }
    assert_true((bool) preg_match('~<button type="button" class="sb-carousel__btn sb-carousel__pause" data-sb-car-pause aria-pressed="false"[^>]*aria-label="Pause slideshow"~', $html), 'an autoplaying carousel has a pause button: ' . $html);
    $still = (new CarouselRenderer())->render(sbia_scope('core.carousel', ['slides' => $slides, 'options' => ['autoplay_ms' => 0]]));
    assert_false(str_contains($still, 'data-sb-car-pause'), 'no pause button without autoplay');
    $runtime = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_false(str_contains($runtime, "'Go to slide ' + (i + 1)"), 'the runtime no longer writes English dot labels');
    $carousel = substr($runtime, (int) strpos($runtime, 'function initCarousel'), (int) strpos($runtime, 'function initMarquee') - (int) strpos($runtime, 'function initCarousel'));
    assert_false(str_contains($carousel, "'role', 'tab'"), 'nor tab roles on carousel dots');
});

unit('carousel: the browser fixture is the renderer\'s markup with the stylesheet\'s carousel rules', function (): void {
    $slides = [
        ['quote' => 'A conversation comes before any plan.', 'author' => 'Listen first'],
        ['quote' => 'Body and mind are looked after as one.', 'author' => 'The whole person'],
        ['quote' => 'Support continues between sessions, not only during them.', 'author' => 'Follow through'],
    ];
    $html = (new CarouselRenderer())->render(sbia_scope('core.carousel', ['slides' => $slides, 'options' => ['loop' => true]]));
    preg_match_all('~(?<=^|})(?:\.sb-carousel|\[data-sb-per-view)[^{}]*\{[^{}]*\}~', \Slate\Module\StudioBuilder\Render\StudioStylesheet::css(), $m);
    $css = implode("\n", $m[0]);
    foreach (['.sb-carousel__slide{flex:0 0 100%;min-width:0;margin:0}', '.sb-carousel__quote{margin:0}', '.sb-carousel__dots{'] as $rule) {
        assert_true(str_contains($css, $rule), "the stylesheet has {$rule}");
    }
    $fixture = SLATE_ROOT . '/plugins/studio-builder/ui/tests/fixtures/carousel.html';
    $expected = '<style>' . $css . "</style>\n" . $html;
    if (getenv('SBEL_WRITE_FIXTURE') === '1') {
        file_put_contents($fixture, $expected . "\n");
    }
    assert_eq(trim((string) file_get_contents($fixture)), $expected, 'ui/tests/fixtures/carousel.html is stale — regenerate with SBEL_WRITE_FIXTURE=1');
});

unit('portfolio showcase: contact links are empty by default, the URL is checked, and the owner\'s address never shows (W-F18)', function (): void {
    // The widget registry is shared by every test in this run: take a copy and put it back afterwards.
    $sdk = WidgetSdk::instance();
    $widgetsProp = new \ReflectionProperty(WidgetSdk::class, 'widgets');
    $saved = $widgetsProp->getValue($sdk);
    try {
        $widgetsProp->setValue($sdk, []);
        StudioBuilderPortfolioWidgets::onRegisterWidgets($sdk);
        $def = WidgetSdk::instance()->get('portfolio.rakib_showcase');
        assert_true($def !== null, 'the widget registers');
        $defaults = $def->defaultProps();
        assert_true(($defaults['domain'] ?? '') === '' && ($defaults['domain_url'] ?? null) === null && ($defaults['email'] ?? '') === '', 'no default contact details');
        assert_false($def->schema()->validate(['domain_url' => 'javascript:alert(1)'])->isValid(), 'a javascript: address is refused');
        assert_false($def->schema()->validate(['email' => 'not an email'])->isValid(), 'a malformed email is refused');
        assert_true($def->schema()->validate(['domain_url' => 'https://example.com', 'email' => 'me@example.com'])->isValid(), 'real values are accepted');

        $empty = StudioBuilderPortfolioWidgets::renderShowcase(sbia_scope('portfolio.rakib_showcase', []));
        assert_false(str_contains($empty, 'rakibhasaan.com'), 'with nothing set, the owner\'s domain and email are not in the page');
        $set = StudioBuilderPortfolioWidgets::renderShowcase(sbia_scope('portfolio.rakib_showcase', ['domain' => 'example.com', 'domain_url' => 'https://example.com', 'email' => 'me@example.com']));
        assert_true(str_contains($set, 'href="https://example.com"') && str_contains($set, 'href="mailto:me@example.com"'), 'set values are linked');
        $stored = StudioBuilderPortfolioWidgets::renderShowcase(sbia_scope('portfolio.rakib_showcase', ['domain' => 'x', 'domain_url' => 'javascript:alert(1)', 'email' => '"><script>']));
        assert_false(str_contains($stored, 'javascript:alert') || str_contains($stored, '"><script>'), 'an unsafe stored value is never linked or output');
    } finally {
        $widgetsProp->setValue($sdk, $saved);
    }
});

unit('portfolio footer: social links and email are the owner\'s to fill in, and only filled ones show (W-F28)', function (): void {
    $render = static fn(array $props): string => StudioFreelancerPortfolio::renderFooter(sbia_scope('portfolio.footer', $props));
    $default = $render([]);
    assert_false(str_contains($default, 'mailto:'), 'no email by default');
    assert_false(str_contains($default, 'github.com') || str_contains($default, 'linkedin.com') || str_contains($default, 'twitter.com'), 'no placeholder social links');
    $filled = $render(['links' => [['label' => 'GitHub', 'url' => 'https://github.com/me'], ['label' => 'Bad', 'url' => 'javascript:x'], ['label' => '', 'url' => 'https://empty.example']], 'email' => 'me@example.com']);
    assert_true(str_contains($filled, 'href="https://github.com/me"') && str_contains($filled, '>GitHub ↗<'), 'a filled link shows');
    assert_false(str_contains($filled, 'javascript:') || str_contains($filled, 'empty.example'), 'unsafe or unlabelled links are skipped');
    assert_true(str_contains($filled, 'href="mailto:me@example.com"'), 'the email shows');
});

if (!empty($studioI18nA11yStandalone)) {
    exit(unit_summary());
}
