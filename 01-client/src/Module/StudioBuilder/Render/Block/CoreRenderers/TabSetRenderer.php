<?php
/**
 * Kohevo Studio — `layout.tabs` renderer: tabs whose panels hold any blocks. Each child is a `layout.tab`, which renders
 * its own panel (TabRenderer); this renders the strip of tabs from the children's titles, around them.
 *
 * The markup keeps the core Tabs block's contract (`data-sb-tabs`, `data-sb-tab` / `data-sb-panel` keys, roving
 * tabindex), so studio-runtime.js gives it the same clicks and arrow keys. Every panel ships visible: without script, and
 * in the editor, all the content is there to read; the runtime hides all but the chosen one. With `autoplay_ms` the tabs
 * turn by themselves while on screen (each tab shows the time left as a bar), pausing under the pointer or on focus.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class TabSetRenderer implements BlockRendererInterface
{
    public const POSITIONS = ['top', 'left'];
    public const MAX_AUTOPLAY = 30000;
    /** Below this a tab turns before anyone could read it: such a value turns autoplay off. */
    public const MIN_AUTOPLAY = 1500;

    public function type(): string
    {
        return 'layout.tabs';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    /** The DOM id a tab's button and panel share, from the tab block's node id (as BlockRenderScope::domId builds it). */
    public static function key(array $tab): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($tab['id'] ?? ''));
        return ($safe === null || $safe === '') ? 'sb-tab' : 'sb-' . $safe;
    }

    public function render(BlockRenderScope $scope): string
    {
        $panels = $scope->childrenHtml();
        $tabs = array_values(array_filter(
            is_array($scope->block()['children'] ?? null) ? $scope->block()['children'] : [],
            static fn(mixed $c): bool => is_array($c) && ($c['type'] ?? '') === 'layout.tab' && trim((string) ($c['props']['title'] ?? '')) !== ''
        ));
        if ($tabs === [] || trim($panels) === '') {
            return $scope->showsDiagnostics() ? '<p class="sb-tabset__empty">' . Html::e(Html::t('studio_tabset_empty', 'Add a Tab')) . '</p>' : '';
        }

        $position = in_array($scope->string('position', 'top'), self::POSITIONS, true) ? $scope->string('position', 'top') : 'top';
        $autoplay = $scope->prop('autoplay_ms');
        $autoplay = is_numeric($autoplay) ? (int) $autoplay : 0;
        $autoplay = $autoplay >= self::MIN_AUTOPLAY ? min(self::MAX_AUTOPLAY, $autoplay) : 0;

        $buttons = '';
        foreach ($tabs as $i => $tab) {
            $key = self::key($tab);
            $sub = trim((string) ($tab['props']['subtitle'] ?? ''));
            $buttons .= '<button type="button" class="sb-tabset__tab" role="tab"'
                . ' id="' . Html::e($key) . '-tab" data-sb-tab="' . Html::e($key) . '" aria-controls="' . Html::e($key) . '-panel"'
                . ' aria-selected="' . ($i === 0 ? 'true' : 'false') . '" tabindex="' . ($i === 0 ? '0' : '-1') . '">'
                . '<span class="sb-tabset__title">' . Html::e(mb_substr(trim((string) $tab['props']['title']), 0, 80, 'UTF-8')) . '</span>'
                . ($sub !== '' ? '<span class="sb-tabset__sub">' . Html::e(mb_substr($sub, 0, 120, 'UTF-8')) . '</span>' : '')
                . ($autoplay > 0 ? '<span class="sb-tabset__bar" aria-hidden="true"><i></i></span>' : '')
                . '</button>';
        }
        $name = trim($scope->string('aria_label'));

        return '<div class="sb-tabset sb-tabset--' . $position . '" data-sb-tabs'
            . ($autoplay > 0 ? ' data-sb-tabs-autoplay="' . $autoplay . '"' . ($scope->bool('pause_on_hover', true) ? ' data-sb-tabs-pause' : '') : '') . '>'
            . '<div class="sb-tabset__list" role="tablist" aria-label="' . Html::e($name !== '' ? $name : Html::t('studio_tabs_label', 'Content tabs')) . '"'
            . ($position === 'left' ? ' aria-orientation="vertical"' : '') . '>' . $buttons . '</div>'
            . '<div class="sb-tabset__panels">' . $panels . '</div></div>';
    }
}
