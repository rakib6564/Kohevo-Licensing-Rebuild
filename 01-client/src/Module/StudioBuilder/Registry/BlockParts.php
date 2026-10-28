<?php
/**
 * Kohevo Studio — the named parts of a widget an author may style on their own (`block.style_parts`).
 *
 * A widget's own style reaches its wrapper, and the text inside follows it. A part is one element inside: the titles of
 * a Tabs block, the number of a Stat, the inputs of a Form. Each part is a list of selectors inside the block (they are
 * prefixed with the block's own scoped class, never written as they are), so a part can only ever reach its own block.
 *
 * Every part may also take a hover style (`<part>:hover`). The selectors are the renderers' own class names: a renderer
 * that renames one must rename it here too (a unit test renders each widget and checks every selector still matches).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

final class BlockParts
{
    /** States a part may take, with the pseudo-class each adds to every selector. */
    public const STATES = ['hover' => ':hover'];

    /** @var array<string, array<string, array{label: string, selectors: list<string>}>> type => part => definition */
    private const PARTS = [
        'core.tabs' => [
            'tab'        => ['label' => 'Tab titles', 'selectors' => ['.sb-tabs__tab']],
            'tab_active' => ['label' => 'Active tab', 'selectors' => ['.sb-tabs__tab[aria-selected="true"]']],
            'panel'      => ['label' => 'Panel', 'selectors' => ['.sb-tabs__panel']],
        ],
        'core.accordion' => [
            'item'    => ['label' => 'Item', 'selectors' => ['.sb-accordion__item']],
            'title'   => ['label' => 'Title', 'selectors' => ['.sb-accordion__button']],
            'icon'    => ['label' => 'Icon', 'selectors' => ['.sb-accordion__icon']],
            'content' => ['label' => 'Content', 'selectors' => ['.sb-accordion__panel']],
        ],
        'core.stats' => [
            'number' => ['label' => 'Number', 'selectors' => ['.sb-stat__value']],
            'label'  => ['label' => 'Label', 'selectors' => ['.sb-stat__label']],
        ],
        'core.hero' => [
            'eyebrow'    => ['label' => 'Eyebrow', 'selectors' => ['.sb-hero__eyebrow']],
            'heading'    => ['label' => 'Heading', 'selectors' => ['.sb-hero__heading']],
            'subheading' => ['label' => 'Subheading', 'selectors' => ['.sb-hero__subheading']],
            'media'      => ['label' => 'Image', 'selectors' => ['.sb-hero__media']],
        ],
        'core.query_loop' => [
            'card'     => ['label' => 'Card', 'selectors' => ['.sb-post-card']],
            'image'    => ['label' => 'Image', 'selectors' => ['.sb-post-card__media']],
            'category' => ['label' => 'Category', 'selectors' => ['.sb-post-card__category']],
            'title'    => ['label' => 'Title', 'selectors' => ['.sb-post-card__title', '.sb-post-card__title a']],
            'excerpt'  => ['label' => 'Excerpt', 'selectors' => ['.sb-post-card__excerpt']],
            'meta'     => ['label' => 'Date and author', 'selectors' => ['.sb-post-card__meta']],
            'button'   => ['label' => 'Read more', 'selectors' => ['.sb-post-card .sb-button']],
        ],
        'core.form' => [
            'label'  => ['label' => 'Labels', 'selectors' => ['.sb-form-group > label', '.sb-form-choices > legend']],
            'input'  => ['label' => 'Inputs', 'selectors' => ['.sb-form-group input:not([type="checkbox"]):not([type="radio"]):not([type="file"])', '.sb-form-group select', '.sb-form-group textarea']],
            'help'   => ['label' => 'Help text', 'selectors' => ['.sb-form-help']],
            'button' => ['label' => 'Submit button', 'selectors' => ['.sb-form__actions .sb-button']],
        ],
        'core.feature_list' => [
            'title'   => ['label' => 'Title', 'selectors' => ['.sb-feature-list__title']],
            'card'    => ['label' => 'Item', 'selectors' => ['.sb-feature']],
            'heading' => ['label' => 'Item heading', 'selectors' => ['.sb-feature__heading']],
            'body'    => ['label' => 'Item text', 'selectors' => ['.sb-feature__body']],
            'link'    => ['label' => 'Link', 'selectors' => ['.sb-feature__link']],
        ],
        'core.quote' => [
            'text'   => ['label' => 'Quote', 'selectors' => ['.sb-quote__text']],
            'author' => ['label' => 'Author', 'selectors' => ['.sb-quote__author']],
            'role'   => ['label' => 'Role', 'selectors' => ['.sb-quote__role']],
        ],
        'core.countdown' => [
            'title'  => ['label' => 'Title', 'selectors' => ['.sb-countdown__label']],
            'box'    => ['label' => 'Box', 'selectors' => ['.sb-countdown__unit']],
            'number' => ['label' => 'Number', 'selectors' => ['.sb-countdown__num']],
            'unit'   => ['label' => 'Unit name', 'selectors' => ['.sb-countdown__name']],
        ],
        'core.nav_menu' => [
            'link' => ['label' => 'Links', 'selectors' => ['.sb-nav__link']],
        ],
        'core.carousel' => [
            'quote'  => ['label' => 'Quote', 'selectors' => ['.sb-carousel__quote']],
            'author' => ['label' => 'Author', 'selectors' => ['.sb-carousel__author']],
            'arrows' => ['label' => 'Arrows', 'selectors' => ['.sb-carousel__btn']],
            'dots'   => ['label' => 'Dots', 'selectors' => ['.sb-carousel__dot']],
        ],
        'core.table' => [
            'header'  => ['label' => 'Header cells', 'selectors' => ['th']],
            'cell'    => ['label' => 'Cells', 'selectors' => ['td']],
            'caption' => ['label' => 'Caption', 'selectors' => ['.sb-table__caption']],
        ],
        'core.list' => [
            'item' => ['label' => 'Items', 'selectors' => ['.sb-list__item']],
        ],
        'layout.offcanvas' => [
            'trigger' => ['label' => 'Open button', 'selectors' => ['.sb-offcanvas-trigger']],
        ],
    ];

    /** @return array<string, array{label: string, selectors: list<string>}> the parts of a block type (none for most). */
    public static function for(string $type): array
    {
        return self::PARTS[$type] ?? [];
    }

    /** @return list<string> every block type that has parts */
    public static function types(): array
    {
        return array_keys(self::PARTS);
    }

    /** @return list<array{key: string, label: string}> what the editor offers for a type, in order */
    public static function forEditor(string $type): array
    {
        $out = [];
        foreach (self::for($type) as $key => $part) {
            $out[] = ['key' => $key, 'label' => $part['label']];
        }
        return $out;
    }
}
