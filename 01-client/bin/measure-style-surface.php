<?php
/**
 * Measures what the B2-P3b style surface costs on a ~250-block page.
 *
 *   plain    - 250 blocks with no styling
 *   inline   - the same blocks styled with the pre-P3 inline keys (typography, border, shadow, ...)
 *   surface  - the same blocks styled with the P3b surface (layout, padding, effects, states); EVERY block has a
 *              different look (the worst case: nothing can be shared)
 *   shared   - like `surface`, but only 6 distinct looks repeated across the page (what real pages do)
 *
 * Reports document JSON bytes, compiled HTML bytes, stylesheet bytes and the median compile time of 15 runs.
 * Offline: no database.   Usage: php bin/measure-style-surface.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
define('SLATE_TESTING', true);
define('SLATE_ROOT', dirname(__DIR__));
require_once SLATE_ROOT . '/src/autoload.php';

use Slate\Module\StudioBuilder\Document\{CanonicalDocumentSchema as C, CanonicalJson};
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\Media\{MediaResolverInterface, ResolvedMedia};
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Render\{DocumentRenderer, ProviderBindingResolver, RenderContext, SiteContext};
use Slate\Tenancy\TenantContext;

final class MeasureMedia implements MediaResolverInterface { public function resolveImage(int $id): ?ResolvedMedia { return null; } }

$tenants = new TenantContext();
$reg     = ModuleBlockDefinitions::studioRegistry();
$rr      = BlockRendererRegistry::withStudioRenderers();
$media   = new MeasureMedia();
$comp    = new StudioCompiler($tenants, $reg, $rr, new DocumentRenderer($reg, $rr, $media, new ProviderBindingResolver(new DataProviderRegistry(), $tenants)), new ThemeResolver(null, static fn () => []), new ChromeResolver(), $media);
$page    = new PageAddress(5, '11111111-2222-4333-8444-555555555555', 'T', 't', 'page', 'published', 'standalone', 9, 9);
$ctx     = RenderContext::forPublic(101, new SiteContext('https://a.test', 'A', 'https://a.test/l.png', 'https://a.test/f.png', 'en'), static fn () => true);

/** @return array<string, mixed> the n-th look in a given mode */
function look(string $mode, int $n): array
{
    $hue = sprintf('#%02x%02x%02x', 40 + ($n * 7) % 200, 90 + ($n * 13) % 150, 60 + ($n * 29) % 180);
    return match ($mode) {
        'inline'  => ['typography' => ['size' => (1 + $n % 4) . 'rem', 'weight' => '600', 'color' => $hue], 'border' => ['style' => 'solid', 'width' => '1px', 'color' => $hue, 'radius' => '0.5rem'], 'shadow' => '0 4px 12px rgba(0,0,0,.15)', 'background' => ['color' => '#f5f3f0']],
        default   => ['padding' => ['top' => (1 + $n % 40) . 'px', 'bottom' => (1 + intdiv($n, 40)) . 'px'], 'effects' => ['transform' => ['scale' => 1.02], 'transition' => ['duration_ms' => 200], 'cursor' => 'pointer'], 'layout' => ['display' => 'flex', 'gap' => '1rem']],
    };
}

function document(string $mode, int $blocks, int $looks): array
{
    $doc = C::emptyDocument('page', 'default', 'T');
    $sections = [];
    $n = 0;
    for ($s = 0; $s < 10; $s++) {
        $list = [];
        for ($b = 0; $b < $blocks / 10; $b++) {
            $i = $looks > 0 ? $n % $looks : $n;
            $style = $mode === 'plain' ? [] : look($mode === 'inline' ? 'inline' : 'surface', $i);
            $block = ['id' => 'blk_' . str_pad((string) ($n + 1), 20, '0', STR_PAD_LEFT), 'type' => 'core.heading', 'version' => 1, 'props' => ['text' => 'Heading ' . $n, 'level' => 'h2'],
                'style' => $style + C::defaultBlockStyle(), 'visibility' => C::defaultVisibility(), 'bindings' => [], 'children' => []];
            if ($mode === 'surface' || $mode === 'shared') {
                $block['style_states'] = ['hover' => ['opacity' => 0.9, 'color' => '#ffffff']];
            }
            $list[] = $block;
            $n++;
        }
        $sections[] = ['id' => 'sec_' . str_pad((string) $s, 20, 'a', STR_PAD_LEFT), 'label' => 'S' . $s, 'global_ref' => null, 'layout' => C::defaultSectionLayout(), 'visibility' => C::defaultVisibility(), 'blocks' => $list];
    }
    $doc['sections'] = $sections;
    return $doc;
}

$rows = [];
foreach (['plain' => [0], 'inline' => [0], 'surface' => [0], 'shared' => [6]] as $mode => [$looks]) {
    $doc  = document($mode, 250, $looks);
    $json = CanonicalJson::encode($doc);
    $times = [];
    $out = null;
    for ($r = 0; $r < 15; $r++) {
        $t = hrtime(true);
        $out = $tenants->runAs(101, static fn () => $comp->compile($page, ['id' => 9, 'page_id' => 5, 'document_json' => $json], $ctx));
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    sort($times);
    $rows[$mode] = ['json' => strlen($json), 'html' => strlen((string) $out->html), 'css' => strlen((string) $out->css), 'ms' => $times[7]];
}

printf("%-8s %12s %12s %12s %10s\n", 'mode', 'doc JSON', 'HTML', 'stylesheet', 'compile');
foreach ($rows as $mode => $r) {
    printf("%-8s %10d B %10d B %10d B %7.1f ms\n", $mode, $r['json'], $r['html'], $r['css'], $r['ms']);
}
$base = $rows['plain'];
printf("\nOutput (HTML+CSS) over plain:  inline %+.1f%%   surface %+.1f%%   shared %+.1f%%\n",
    100 * (($rows['inline']['html'] + $rows['inline']['css']) / ($base['html'] + $base['css']) - 1),
    100 * (($rows['surface']['html'] + $rows['surface']['css']) / ($base['html'] + $base['css']) - 1),
    100 * (($rows['shared']['html'] + $rows['shared']['css']) / ($base['html'] + $base['css']) - 1));
printf("Output over the same page styled inline:  surface %+.1f%%   shared %+.1f%%\n",
    100 * (($rows['surface']['html'] + $rows['surface']['css']) / ($rows['inline']['html'] + $rows['inline']['css']) - 1),
    100 * (($rows['shared']['html'] + $rows['shared']['css']) / ($rows['inline']['html'] + $rows['inline']['css']) - 1));
