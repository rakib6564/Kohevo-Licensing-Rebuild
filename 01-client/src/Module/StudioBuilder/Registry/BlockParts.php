<?php
/**
 * Kohevo Studio — the named parts of a widget an author may style on their own (`block.style_parts`).
 *
 * A widget's own style reaches its wrapper, and the text inside follows it. A part is one element inside: the titles of
 * a Tabs block, the number of a Stat, the inputs of a Form. Each part is a list of selectors inside the block (they are
 * prefixed with the block's own scoped class, never written as they are), so a part can only ever reach its own block.
 *
 * Every part may also take a hover style (`<part>:hover`), except one marked `'states' => false` (a pseudo-element, whose
 * hover would be written on its element). Tablet and phone may override a part too (`responsive.<device>.style_parts`).
 * A part marked `'portal' => true` is inside an element studio-runtime.js moves to <body> (the Off-canvas drawer): its
 * rules also match under `[data-sb-scope~="<the block's class>"]`, which the runtime writes on what it moves.
 * The selectors are the renderers' own class names: a renderer that renames one must rename it here too (a unit test
 * renders each widget and checks every selector still matches).
 *
 * A Kohevo plugin's widgets declare their parts in the plugin's `studio-widgets.php` (`'parts'`, read by
 * PluginWidgetCatalog); Studio's own map wins for a type both name.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

final class BlockParts
{
    /** States a part may take, with the pseudo-class each adds to every selector. */
    public const STATES = ['not_first' => ':not(:first-child)', 'hover' => ':hover', 'focus' => ':focus-visible'];

    /** @var array<string, array<string, array{label: string, selectors: list<string>, states?: bool, portal?: bool}>> type => part => definition */
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
            'button_row' => ['label' => 'Read more row', 'selectors' => ['.sb-post-card .sb-button-row']],
            'body'     => ['label' => 'Card text area', 'selectors' => ['.sb-post-card__body']],
            'grid'     => ['label' => 'Cards layout', 'selectors' => ['.sb-query-loop__grid']],
        ],
        'core.form' => [
            'form'     => ['label' => 'Fields area', 'selectors' => ['.sb-form']],
            'field'    => ['label' => 'Field', 'selectors' => ['.sb-form-group', '.sb-form-choices']],
            'label'    => ['label' => 'Labels', 'selectors' => ['.sb-form-group > label', '.sb-form-choices > legend']],
            'input'    => ['label' => 'Inputs', 'selectors' => ['.sb-form-group input:not([type="checkbox"]):not([type="radio"]):not([type="file"])', '.sb-form-group select', '.sb-form-group textarea']],
            // After `input`, so a text area's own height wins over the inputs' at equal weight.
            'textarea' => ['label' => 'Text areas', 'selectors' => ['.sb-form-group textarea']],
            'help'     => ['label' => 'Help text', 'selectors' => ['.sb-form-help']],
            'actions'  => ['label' => 'Button row', 'selectors' => ['.sb-form__actions']],
            'button'   => ['label' => 'Submit button', 'selectors' => ['.sb-form__actions .sb-button']],
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
            'units'  => ['label' => 'Boxes row', 'selectors' => ['.sb-countdown__units']],
            'message' => ['label' => 'Expire message', 'selectors' => ['.sb-countdown__done']],
        ],
        'core.nav_menu' => [
            'list'    => ['label' => 'Menu list', 'selectors' => ['.sb-nav__list']],
            // The menu's own row of items and links; a submenu's are styled by its own parts below.
            'item'    => ['label' => 'Items', 'selectors' => ['.sb-nav__list>.sb-nav__item']],
            'link'    => ['label' => 'Links', 'selectors' => ['.sb-nav__list>.sb-nav__item>.sb-nav__link']],
            // studio-runtime.js marks the link of the page being viewed (a header is rendered once for every page).
            // As specific as `link` and `sublink` and more, or their colour would win over this one.
            'current' => ['label' => 'Current page link', 'selectors' => ['.sb-nav__list>.sb-nav__item>.sb-nav__link[aria-current="page"]', '.sb-nav__sub .sb-nav__item>.sb-nav__link[aria-current="page"]', '.sb-nav__list .sb-nav__item.is-current-parent>:is(.sb-nav__link,.sb-nav__parent)']],
            'submenu' => ['label' => 'Submenu', 'selectors' => ['.sb-nav__sub']],
            'sublink' => ['label' => 'Submenu links', 'selectors' => ['.sb-nav__sub .sb-nav__link']],
            'toggle'  => ['label' => 'Submenu arrow', 'selectors' => ['.sb-nav__caret', '.sb-nav__toggle']],
            'burger'  => ['label' => 'Menu button', 'selectors' => ['.sb-nav__burger']],
            // Only an open menu matches, so a panel's look never reaches the links shown in a row.
            'panel'   => ['label' => 'Open menu panel', 'selectors' => ['.sb-nav__bar:has(.sb-nav__check:checked)~.sb-nav__list'], 'states' => false],
            'cta'     => ['label' => 'Open menu button', 'selectors' => ['.sb-nav__cta-link']],
        ],
        // The menu's own links are named from the top of the menu: a panel can hold a Nav menu with parts of its own.
        'layout.mega_menu' => [
            'list'    => ['label' => 'Menu list', 'selectors' => ['.sb-nav--mega>.sb-nav__list']],
            'link'    => ['label' => 'Links', 'selectors' => ['.sb-nav--mega>.sb-nav__list>.sb-nav__item>:is(.sb-nav__link,.sb-nav__parent)']],
            'current' => ['label' => 'Current page link', 'selectors' => ['.sb-nav--mega>.sb-nav__list>.sb-nav__item>.sb-nav__link[aria-current="page"]', '.sb-nav--mega>.sb-nav__list>.sb-nav__item.is-current-parent>:is(.sb-nav__link,.sb-nav__parent)']],
            'open'    => ['label' => 'Link of the open panel', 'selectors' => ['.sb-nav--mega>.sb-nav__list>.sb-nav__item:is(:hover,:focus-within,[data-open])>:is(.sb-nav__link,.sb-nav__parent)'], 'states' => false],
            'submenu' => ['label' => 'Panels', 'selectors' => ['.sb-nav--mega>.sb-nav__list>.sb-nav__item>.sb-nav__mega']],
            'grid'    => ['label' => 'Panel columns', 'selectors' => ['.sb-mega__grid']],
            'burger'  => ['label' => 'Menu button', 'selectors' => ['.sb-nav__burger']],
            'panel'   => ['label' => 'Open menu panel', 'selectors' => ['.sb-nav__bar:has(.sb-nav__check:checked)~.sb-nav__list'], 'states' => false],
            'cta'     => ['label' => 'Open menu button', 'selectors' => ['.sb-nav__cta-link']],
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
            'list'   => ['label' => 'List', 'selectors' => ['.sb-list']],
            'item'   => ['label' => 'Items', 'selectors' => ['.sb-list__item']],
            'marker' => ['label' => 'Markers', 'selectors' => ['.sb-list__item::marker', '.sb-list__item::before'], 'states' => false],
        ],
        'layout.offcanvas' => [
            'trigger'  => ['label' => 'Open button', 'selectors' => ['.sb-offcanvas-trigger']],
            'panel'    => ['label' => 'Panel', 'selectors' => ['.sb-offcanvas__panel'], 'portal' => true],
            'header'   => ['label' => 'Panel header', 'selectors' => ['.sb-offcanvas__header'], 'portal' => true],
            'close'    => ['label' => 'Close button', 'selectors' => ['.sb-offcanvas__close'], 'portal' => true],
            'body'     => ['label' => 'Panel content', 'selectors' => ['.sb-offcanvas__body'], 'portal' => true],
            'backdrop' => ['label' => 'Backdrop', 'selectors' => ['.sb-offcanvas__backdrop'], 'portal' => true],
        ],
        'core.button' => [
            'button' => ['label' => 'Button', 'selectors' => ['.sb-button']],
            'label'  => ['label' => 'Text', 'selectors' => ['.sb-button__label']],
            'icon'   => ['label' => 'Icon', 'selectors' => ['.sb-button__icon']],
        ],
        'payment.button' => [
            'button' => ['label' => 'Button', 'selectors' => ['.sb-pay__button']],
            'form'   => ['label' => 'Button and amount row', 'selectors' => ['.sb-pay']],
            'amount' => ['label' => 'Amount box', 'selectors' => ['.sb-pay__amount-input']],
        ],
        'core.gallery' => [
            'grid'  => ['label' => 'Gallery layout', 'selectors' => ['.sb-gallery']],
            'item'  => ['label' => 'Tiles', 'selectors' => ['.sb-gallery__item']],
            'image' => ['label' => 'Images', 'selectors' => ['.sb-gallery__item img']],
        ],
        'core.rich_text' => [
            'paragraph' => ['label' => 'Paragraphs', 'selectors' => ['p']],
            'heading'   => ['label' => 'Headings', 'selectors' => ['h2', 'h3', 'h4']],
            'list'      => ['label' => 'Lists', 'selectors' => ['ul', 'ol']],
            'item'      => ['label' => 'List items', 'selectors' => ['li']],
            'marker'    => ['label' => 'List markers', 'selectors' => ['li::marker'], 'states' => false],
            'link'      => ['label' => 'Links', 'selectors' => ['a']],
            'bold'      => ['label' => 'Bold text', 'selectors' => ['strong', 'b']],
        ],
        'core.heading' => [
            'highlight' => ['label' => 'Highlighted words', 'selectors' => ['.sb-hl']],
            'link'      => ['label' => 'Link', 'selectors' => ['.sb-heading__link']],
        ],
        'core.icon' => [
            'link' => ['label' => 'Icon link', 'selectors' => ['.sb-icon__link']],
            'svg'  => ['label' => 'Icon', 'selectors' => ['svg']],
        ],
        'core.marquee' => [
            'text' => ['label' => 'Text', 'selectors' => ['.sb-marquee__text']],
        ],
        'core.audio' => [
            'card'     => ['label' => 'Card', 'selectors' => ['.sb-audio']],
            'title'    => ['label' => 'Title', 'selectors' => ['.sb-audio__title']],
            'subtitle' => ['label' => 'Subtitle', 'selectors' => ['.sb-audio__subtitle']],
            'play'     => ['label' => 'Play button', 'selectors' => ['.sb-audio__play']],
            'link'     => ['label' => 'Source link', 'selectors' => ['.sb-audio__link']],
        ],
        'core.video' => [
            'poster' => ['label' => 'Overlay image', 'selectors' => ['.sb-video__overlay img']],
            'play'   => ['label' => 'Play icon', 'selectors' => ['.sb-video__play']],
            'svg'    => ['label' => 'Play symbol', 'selectors' => ['.sb-video__play svg']],
        ],
        'theme.post_meta' => [
            'meta' => ['label' => 'Meta line', 'selectors' => ['.sb-post-meta']],
            'item' => ['label' => 'Items', 'selectors' => ['.sb-post-meta > span']],
        ],
        'theme.field_value' => [
            'value'     => ['label' => 'Value', 'selectors' => ['.sb-field-value']],
            'label'     => ['label' => 'Label', 'selectors' => ['.sb-field-value__label']],
            'paragraph' => ['label' => 'Paragraphs', 'selectors' => ['.sb-field-value p']],
            'list'      => ['label' => 'Lists', 'selectors' => ['.sb-field-value ul', '.sb-field-value ol']],
        ],
        'core.map' => [
            'frame' => ['label' => 'Map frame', 'selectors' => ['.sb-map']],
        ],
    ];

    /** @return array<string, array{label: string, selectors: list<string>}> the parts of a block type (none for most). */
    public static function for(string $type): array
    {
        return self::PARTS[$type] ?? (PluginWidgetCatalog::parts()[$type] ?? []);
    }

    /** True when the part is inside an element the runtime moves to <body> (see the class comment). */
    public static function isPortal(array $definition): bool
    {
        return ($definition['portal'] ?? false) === true;
    }

    /** True unless the part is marked as taking no state (a pseudo-element). */
    public static function takesStates(array $definition): bool
    {
        return ($definition['states'] ?? true) !== false;
    }

    /** @return list<string> every block type that has parts */
    public static function types(): array
    {
        return array_values(array_unique([...array_keys(self::PARTS), ...array_keys(PluginWidgetCatalog::parts())]));
    }

    /** @return list<array{key: string, label: string}> what the editor offers for a type, in order */
    public static function forEditor(string $type): array
    {
        $out = [];
        foreach (self::for($type) as $key => $part) {
            $out[] = ['key' => $key, 'label' => $part['label']] + (self::takesStates($part) ? [] : ['states' => false]);
        }
        return $out;
    }
}
