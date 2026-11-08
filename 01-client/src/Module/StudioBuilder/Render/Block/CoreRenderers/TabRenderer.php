<?php
/**
 * Kohevo Studio — `layout.tab` renderer: one tab's panel, holding the tab's child blocks. Its button is rendered by the
 * Tabs with blocks around it (TabSetRenderer), which shares the panel's id through TabSetRenderer::key().
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class TabRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'layout.tab';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $title = trim($scope->string('title'));
        if ($title === '') {
            return '';
        }
        $key = TabSetRenderer::key($scope->block());
        $body = $scope->childrenHtml();
        if (trim($body) === '' && $scope->showsDiagnostics()) {
            $body = '<p class="sb-tabset__empty">' . Html::e(Html::t('studio_tab_empty', 'Add blocks to this tab')) . '</p>';
        }
        // The editor canvas runs no script and shows every panel, so each carries its title there (the strip above does
        // that job on the page and in Preview).
        $label = $scope->mode() === RenderMode::Editor ? '<p class="sb-tabset__label">' . Html::e($title) . '</p>' : '';
        return '<div class="sb-tabset__panel" role="tabpanel" id="' . Html::e($key) . '-panel" data-sb-panel="' . Html::e($key) . '"'
            . ' aria-labelledby="' . Html::e($key) . '-tab" tabindex="0">' . $label . $body . '</div>';
    }
}
