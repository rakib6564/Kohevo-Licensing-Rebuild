<?php
/**
 * Kohevo Studio — `core.nav_menu` renderer: a list of links in a `<nav>`.
 *
 * A site menu's item with `children` opens a submenu (`.sb-nav__sub`, nested up to StudioMenus::MAX_DEPTH levels). In a
 * horizontal menu it drops down on hover and keyboard focus with CSS alone, so the canvas (no scripts) shows it too, and
 * the arrow is drawn in the link; in a vertical one (a drawer) it is listed indented, behind an arrow button. studio-runtime.js adds the toggle: its button sets `aria-expanded`
 * and `data-open`, a vertical menu folds its submenus away, and Escape or a click elsewhere closes them. An item with
 * no address of its own is the toggle itself.
 *
 * With a `toggle_breakpoint` the same list folds behind a menu button below that width (NavChrome, StudioStylesheet::navWidgetCss()),
 * as a dropdown under the header, an off-canvas panel or a full-screen menu. There is one list of links in the page whatever the width, so a
 * crawler reads each link once. The open state is a checkbox laid over the button: without scripts it opens the menu
 * by itself and is what the keyboard reaches. Once studio-runtime.js runs, the checkbox hides, the real
 * `<button aria-expanded aria-controls>` takes over and the runtime handles focus, Escape and closing on a link.
 *
 * Current page: a header is rendered once for every page, so the server cannot know which link is current;
 * studio-runtime.js marks it (`aria-current`, `is-current`) from the address the page is served at.
 *
 * A big menu (a full-screen one): `numbered` puts 01, 02… before each top-level link, `descriptions` a line under it
 * (the menu item's description), `arrow` an arrow shown on hover, `columns` lays a vertical menu out in up to three
 * columns, and `preview` adds a panel beside the list with the picture of each link that has one (the item's image):
 * CSS shows the first, studio-runtime.js the hovered or focused one, with its description as the caption. The
 * pictures are decoration (aria-hidden): the links say everything. `reveal` makes the links rise in, one after
 * another, when the drawer around the menu opens. With none of these on, the links are the plain ones.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Menu\StudioMenus;
use Slate\Module\StudioBuilder\Render\Html;

final class NavMenuRenderer implements BlockRendererInterface
{
    public const LAYOUTS = ['horizontal', 'vertical'];
    public const ALIGNS  = ['left', 'center', 'right'];
    public const STYLES  = ['plain', 'underline', 'pill'];

    public function type(): string
    {
        return 'core.nav_menu';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $layout = self::pick($scope->string('layout', 'horizontal'), self::LAYOUTS, 'horizontal');
        $align  = self::pick($scope->string('align', 'left'), self::ALIGNS, 'left');
        $look   = self::pick($scope->string('style', 'plain'), self::STYLES, 'plain');
        $name   = trim($scope->string('aria_label', 'Menu'));

        // A chosen site menu wins; a menu that was since deleted falls back to this block's own links, so a header never goes blank.
        $links = (array) $scope->prop('items', []);
        $menuKey = trim($scope->string('menu', ''));
        $menu = $menuKey !== '' ? StudioMenus::find($scope->context()->tenantId, $menuKey) : null;
        if ($menu !== null) {
            $links = $menu['items'];
        }

        $big = [
            'numbered'     => $scope->bool('numbered', false),
            'descriptions' => $scope->bool('descriptions', false),
            'arrow'        => $scope->bool('arrow', false),
            'reveal'       => $scope->bool('reveal', false),
        ];
        $preview = $scope->bool('preview', false);
        $pictures = $preview ? [] : null;
        $items = $this->items($links, $scope, 0, 'sub', $big, $pictures);
        if ($items === '') {
            return '';
        }

        $classes = ['sb-nav', 'sb-nav--' . $layout, 'sb-nav--' . $align, 'sb-nav--' . $look];
        $columns = $scope->prop('columns');
        if ($layout === 'vertical' && is_int($columns) && $columns >= 2 && $columns <= 3) {
            $classes[] = 'sb-nav--cols-' . $columns;
        }
        if ($big['numbered']) {
            $classes[] = 'sb-nav--numbered';
        }
        if ($big['descriptions']) {
            $classes[] = 'sb-nav--described';
        }
        if ($big['reveal']) {
            $classes[] = 'sb-nav--reveal';
        }
        $previewHtml = $pictures !== null ? $this->preview($pictures) : '';
        if ($previewHtml !== '') {
            $classes[] = 'sb-nav--preview';
        }
        [$pointer, $style] = NavChrome::pointer($scope);
        array_push($classes, ...$pointer);
        if ($scope->bool('divider', false)) {
            $classes[] = 'sb-nav--divider';
        }

        $listId = $scope->domId('menu');
        [$toggle, $bar] = NavChrome::toggle($scope, $listId);
        array_push($classes, ...$toggle);

        return '<nav' . Html::classAttr($classes)
            . ($name !== '' ? ' aria-label="' . Html::e($name) . '"' : '') . $style . ($toggle !== [] ? ' data-sb-nav-toggle' : '')
            . ($previewHtml !== '' ? ' data-sb-nav-preview' : '') . '>'
            . $bar . '<ul class="sb-nav__list"' . ($toggle !== [] ? ' id="' . Html::e($listId) . '"' : '') . '>' . $items . NavChrome::cta($scope, 'li') . '</ul>' . $previewHtml . '</nav>';
    }

    /**
     * The `<li>`s of one level of the menu, submenus included. A submenu's id is the block's and the item's place in the menu.
     *
     * @param array<int, mixed> $links
     * @param array{numbered?: bool, descriptions?: bool, arrow?: bool, reveal?: bool} $big the big-menu extras, for the top level only
     * @param list<array{url: string, alt: string, caption: string}>|null $pictures collects each top-level link's picture when previews are on
     */
    private function items(array $links, BlockRenderScope $scope, int $depth, string $path, array $big = [], ?array &$pictures = null): string
    {
        $html = '';
        $n = 0;
        foreach ($links as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = (string) ($item['label'] ?? '');
            $children = $depth < StudioMenus::MAX_DEPTH && is_array($item['children'] ?? null) ? $item['children'] : [];
            $sub = $children !== [] ? $this->items($children, $scope, $depth + 1, $path . '-' . $i) : '';
            $caret = '<span class="sb-nav__caret" aria-hidden="true"></span>';
            // A site menu item's own CSS classes (checked by StudioMenus) go on its <li>, as a theme would expect.
            $own = preg_split('/\s+/', trim((string) ($item['class'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            // A link with a submenu carries the arrow inside it, so the arrow sits by its label whatever spacing the link is given.
            $inner = $depth === 0 && $label !== '' ? $this->bigInner($item, $label, $big, $n + 1) : null;
            if ($sub !== '') {
                $inner = ($inner ?? Html::e($label)) . $caret;
            }
            $anchor = Html::link(['href' => $item['url'] ?? null, 'label' => $label, 'target' => !empty($item['new_tab']) ? '_blank' : '_self'], 'sb-nav__link', $inner);
            $index = '';
            if ($depth === 0 && $pictures !== null && trim($label) !== '' && str_starts_with($anchor, '<a')) {
                $picture = $this->picture($item, $scope);
                if ($picture !== null) {
                    $index = ' data-sb-preview="' . count($pictures) . '"';
                    $pictures[] = $picture + ['caption' => trim((string) ($item['description'] ?? ''))];
                }
            }
            if ($sub === '') {
                if ($anchor !== '' && str_starts_with($anchor, '<a')) {
                    $n++;
                    $html .= '<li' . Html::classAttr(['sb-nav__item', ...$own]) . $index . ($depth === 0 && !empty($big['reveal']) ? ' style="--i:' . ($n - 1) . '"' : '') . '>' . $anchor . '</li>';
                }
                continue;
            }
            if (trim($label) === '') {
                continue;
            }
            $id = $scope->domId($path . '-' . $i);
            $expanded = ' aria-expanded="false" aria-controls="' . Html::e($id) . '"';
            $head = str_starts_with($anchor, '<a')
                ? $anchor . '<button type="button" class="sb-nav__toggle"' . $expanded . ' aria-label="' . Html::e(sprintf(Html::t('studio_nav_submenu', 'Show the links under %s'), $label)) . '"></button>'
                : '<button type="button" class="sb-nav__link sb-nav__parent"' . $expanded . '>' . $inner . '</button>';
            $n++;
            $html .= '<li' . Html::classAttr(['sb-nav__item', 'sb-nav__item--parent', ...$own]) . $index . ($depth === 0 && !empty($big['reveal']) ? ' style="--i:' . ($n - 1) . '"' : '') . '>' . $head . '<ul class="sb-nav__sub sb-nav__sub--' . ($depth + 1) . '" id="' . Html::e($id) . '">' . $sub . '</ul></li>';
        }
        return $html;
    }

    /**
     * A top-level link's inside when a big-menu extra is on: its number, its label, its description and the arrow;
     * null keeps the plain escaped label (and the markup every menu had before).
     *
     * @param array<string, mixed> $item
     * @param array{numbered?: bool, descriptions?: bool, arrow?: bool} $big
     */
    private function bigInner(array $item, string $label, array $big, int $n): ?string
    {
        if (empty($big['numbered']) && empty($big['descriptions']) && empty($big['arrow'])) {
            return null;
        }
        $description = !empty($big['descriptions']) ? trim((string) ($item['description'] ?? '')) : '';
        return (!empty($big['numbered']) ? '<span class="sb-nav__num" aria-hidden="true">' . sprintf('%02d', $n) . '</span>' : '')
            . '<span class="sb-nav__text"><span class="sb-nav__label">' . Html::e($label) . '</span>'
            . ($description !== '' ? '<span class="sb-nav__desc">' . Html::e($description) . '</span>' : '') . '</span>'
            . (!empty($big['arrow']) ? '<span class="sb-nav__arrow" aria-hidden="true">↗</span>' : '');
    }

    /**
     * A link's picture: a block item's image (from the library) or a site menu item's address.
     *
     * @param array<string, mixed> $item
     * @return array{url: string, alt: string}|null
     */
    private function picture(array $item, BlockRenderScope $scope): ?array
    {
        $image = $item['image'] ?? null;
        if (is_array($image)) {
            $resolved = $scope->image($image);
            return $resolved !== null ? ['url' => $resolved->url, 'alt' => ''] : null;
        }
        $url = is_string($image) && trim($image) !== '' ? Html::safeUrl(trim($image)) : null;
        return $url !== null && preg_match('#^(mailto|tel):#i', $url) !== 1 ? ['url' => $url, 'alt' => ''] : null;
    }

    /**
     * The preview panel: one picture per link that has one, the first shown until another link is hovered.
     *
     * @param list<array{url: string, alt: string, caption: string}> $pictures
     */
    private function preview(array $pictures): string
    {
        if ($pictures === []) {
            return '';
        }
        $html = '';
        foreach ($pictures as $i => $p) {
            $html .= '<figure class="sb-nav__shot"' . ($i === 0 ? ' data-on' : '') . ' data-sb-preview="' . $i . '">'
                . '<img src="' . Html::e($p['url']) . '" alt="" loading="lazy" decoding="async">'
                . ($p['caption'] !== '' ? '<figcaption class="sb-nav__preview-caption">' . Html::e($p['caption']) . '</figcaption>' : '')
                . '</figure>';
        }
        return '<div class="sb-nav__preview" aria-hidden="true">' . $html . '</div>';
    }

    /** @param list<string> $allowed */
    private static function pick(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
