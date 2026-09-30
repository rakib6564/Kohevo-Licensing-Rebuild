<?php
/**
 * Kohevo Studio (studio-builder) — Request-time dynamic node resolution.
 *
 * Replaces each nonce marker in a compiled artifact with the live render of
 * its deferred node, under the CURRENT request's RenderContext: entitlement is
 * re-checked now (a revoked module disappears immediately, with no
 * recompilation), and provider data is fetched now through
 * `DataProviderRegistry` — so tenant- or entitlement-sensitive data is never
 * baked into the stored artifact.
 *
 * A node whose render throws degrades to the non-executable fallback; one
 * failing business provider can never take the rest of the page down, and
 * its error never reaches the output.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Compile;

use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Runtime\StudioLog;

final class DynamicSlotResolver
{
    public function __construct(private readonly DocumentRenderer $documents) {}

    public function fill(CompiledPage $compiled, RenderContext $context): FilledPage
    {
        $nodes = $compiled->dynamicManifest['nodes'] ?? [];
        if (!is_array($nodes) || $nodes === []) {
            return new FilledPage($compiled->html, '');
        }

        $nonce = (string) $compiled->dynamicManifest['nonce'];
        $themeData = is_array($compiled->dynamicManifest['theme'] ?? null) ? $compiled->dynamicManifest['theme'] : [];
        $theme = new ResolvedTheme(
            is_string($themeData['group'] ?? null) ? $themeData['group'] : 'default',
            is_array($themeData['tokens'] ?? null) ? $themeData['tokens'] : [],
        );
        $collector = new RenderCollector($nonce);

        $replacements = [];
        foreach (array_values($nodes) as $index => $block) {
            $html = '';
            if (is_array($block)) {
                try {
                    $html = $this->documents->renderBlock($block, $context, $theme, $collector, false);
                } catch (\Throwable $e) {
                    StudioLog::failure('render', 'dynamic_node', $e, 'warning');
                    $html = $this->documents->unavailable('render_failed', $context);
                }
            }
            $replacements[RenderCollector::marker($nonce, $index)] = $html;
        }

        return new FilledPage(strtr($compiled->html, $replacements), $collector->css());
    }
}
