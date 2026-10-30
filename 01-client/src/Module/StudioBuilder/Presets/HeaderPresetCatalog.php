<?php
/**
 * Kohevo Studio (studio-builder) — the built-in header catalogue.
 *
 * Six ready-made site headers, built only from registered blocks, synced as system `header_preset` templates exactly
 * like SectionPresetCatalog's sections: reserved keys, immutable, copyable, offered in the builder Library while a
 * header is open and as the starting design of a new Theme Builder header.
 *
 * Pure and deterministic: no database, no clock, no randomness. Ids are derived from the preset key, so a rebuild is
 * byte-identical and the seeder can tell "unchanged" from "changed" by comparing JSON. Everything in a header is edited
 * in place: the brand, buttons and text by double-clicking them on the canvas, and each menu carries its own links
 * (no site menu chosen), so every link's text is edited on the canvas too and its address in the Inspector. Choosing a
 * menu from the Menus screen in the Inspector switches a menu to that one. Copy is neutral placeholder text.
 *
 * Every menu carries its own menu button (the Nav menu's `toggle_*` props), so one list of links serves every screen.
 *
 * Each section is one column wide: a section's blocks are equal-width columns, and the phone-only and wide-only rows a
 * header needs would otherwise split it in half. Grids that centre the brand keep three columns on a phone (a grid of
 * more than two columns otherwise collapses to one there).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Presets;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

final class HeaderPresetCatalog
{
    public const VERSION = 2;

    /** Template keys are `system-header-<slug>`: reserved, immutable, copyable. */
    public const KEY_PREFIX = 'system-header-';

    public const CATEGORY = 'header';

    private const PHONE = ['base', 'sm'];
    private const WIDE  = ['md', 'lg'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $memo = null;

    /**
     * @return list<array{key: string, slug: string, name: string, category: string, description: string, document: array<string, mixed>}>
     */
    public static function all(): array
    {
        if (self::$memo === null) {
            self::$memo = self::build();
        }
        return array_values(self::$memo);
    }

    /** @return array<string, mixed>|null the preset with this key, or null */
    public static function find(string $key): ?array
    {
        self::all();
        return self::$memo[$key] ?? null;
    }

    // ── catalogue ───────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private static function build(): array
    {
        $presets = [];
        /** @param callable(callable): list<array<string, mixed>> $sections */
        $add = static function (string $slug, string $name, string $description, callable $sections) use (&$presets): void {
            $key = self::KEY_PREFIX . $slug;
            $n = 0;
            $id = static function (string $prefix = 'blk') use ($key, &$n): string {
                $n++;
                return $prefix . '_' . substr(hash('sha256', $key . ':' . $n), 0, 24);
            };
            $document = CanonicalDocumentSchema::emptyDocument('header_partial', 'default', $name);
            $document['settings']['template_type'] = 'header';
            $document['sections'] = $sections($id);
            $presets[$key] = [
                'key' => $key, 'slug' => $slug, 'name' => $name, 'category' => self::CATEGORY,
                'description' => $description, 'document' => $document,
            ];
        };

        // 1. Classic: brand on the left, menu and a button on the right.
        $add('classic', 'Header — Classic', 'Brand on the left, menu and a button on the right.',
            static fn(callable $id): array => [
                self::section($id, 'Header', [
                    self::classicRow($id),
                ]),
            ]);

        // 2. Centred: a menu button, the brand at the true centre, a button.
        $add('centered', 'Header — Centred', 'Menu button, centred brand and a button: balanced on every screen.',
            static fn(callable $id): array => [
                self::section($id, 'Header', [
                    self::threeColumns($id, [
                        self::menu($id, 'horizontal', 'left', null, ['toggle_breakpoint' => 'always', 'toggle_mode' => 'offcanvas', 'toggle_align' => 'left']),
                        self::brand($id, 'center'),
                        self::button($id, 'Book now', '/contact', 'primary', 'sm', ['align' => ['base' => 'right']], self::WIDE),
                    ]),
                ]),
            ]);

        // 3. Split: links either side of a centred brand; one menu button on a phone.
        $add('split', 'Header — Split', 'Links on either side of a centred brand.',
            static fn(callable $id): array => [
                self::section($id, 'Header', [
                    self::threeColumns($id, [
                        self::menu($id, 'horizontal', 'left', [['label' => 'Home', 'url' => '/'], ['label' => 'About', 'url' => '/about']]),
                        self::brand($id, 'center'),
                        self::menu($id, 'horizontal', 'right', [['label' => 'Services', 'url' => '/services'], ['label' => 'Contact', 'url' => '/contact']]),
                    ], self::WIDE),
                    self::block($id, 'layout.flex', ['direction' => 'row', 'wrap' => 'nowrap', 'justify' => 'between', 'align' => 'center', 'gap' => 'md'], [
                        self::brand($id),
                        self::menu($id, 'horizontal', 'right', null, ['toggle_breakpoint' => 'always']),
                    ], [], self::PHONE),
                ]),
            ]);

        // 4. Minimal: the brand and one labelled menu button that opens a full-width panel.
        $add('minimal', 'Header — Minimal', 'Brand and a single Menu button that opens a full-width panel.',
            static fn(callable $id): array => [
                self::section($id, 'Header', [
                    self::block($id, 'layout.flex', ['direction' => 'row', 'wrap' => 'nowrap', 'justify' => 'between', 'align' => 'center', 'gap' => 'md'], [
                        self::brand($id),
                        self::menu($id, 'horizontal', 'right', null, ['toggle_breakpoint' => 'always', 'toggle_icon' => 'text_icon']),
                    ]),
                ]),
            ]);

        // 5. Two-tier: a slim contact bar above a classic row.
        $add('two-tier', 'Header — Two-tier', 'A slim contact bar above the brand, menu and a button.',
            static fn(callable $id): array => [
                self::section($id, 'Top bar', [
                    self::block($id, 'layout.flex', ['direction' => 'row', 'wrap' => 'wrap', 'justify' => 'between', 'align' => 'center', 'gap' => 'md'], [
                        self::block($id, 'layout.flex', ['direction' => 'row', 'wrap' => 'wrap', 'justify' => 'start', 'align' => 'center', 'gap' => 'sm'], [
                            self::block($id, 'core.icon', ['name' => 'mail', 'size' => 'sm', 'align' => 'left', 'color_token' => null, 'label' => 'Email']),
                            self::text($id, 'hello@example.com'),
                            self::block($id, 'core.icon', ['name' => 'phone', 'size' => 'sm', 'align' => 'left', 'color_token' => null, 'label' => 'Phone']),
                            self::text($id, '+00 000 000 000'),
                        ]),
                        self::block($id, 'core.link', ['link' => ['label' => 'Free consultation', 'href' => '/contact'], 'style' => 'arrow', 'align' => 'right'], [], [], self::WIDE),
                    ]),
                ], ['background_token' => 'surface.secondary', 'padding_y' => ['base' => 'xs']]),
                self::section($id, 'Header', [
                    self::classicRow($id),
                ]),
            ]);

        // 6. Hamburger only: the brand and a hamburger that slides the menu in, on every screen.
        $add('hamburger', 'Header — Hamburger only', 'Brand and a hamburger that opens the menu in a side panel, on every screen.',
            static fn(callable $id): array => [
                self::section($id, 'Header', [
                    self::block($id, 'layout.flex', ['direction' => 'row', 'wrap' => 'nowrap', 'justify' => 'between', 'align' => 'center', 'gap' => 'md'], [
                        self::brand($id),
                        self::menu($id, 'horizontal', 'right', null, ['toggle_breakpoint' => 'always', 'toggle_mode' => 'offcanvas']),
                    ]),
                ]),
            ]);

        return $presets;
    }

    // ── building blocks ─────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    private static function section(callable $id, string $label, array $blocks, array $layout = []): array
    {
        return [
            'id'         => $id('sec'),
            'label'      => $label,
            'global_ref' => null,
            'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
                'columns' => ['base' => 1, 'md' => 1], 'padding_y' => ['base' => 'sm'], 'width' => 'wide',
            ], $layout),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks'     => $blocks,
        ];
    }

    /**
     * @param array<string, mixed> $props
     * @param list<array<string, mixed>> $children
     * @param array<string, mixed> $style
     * @param list<string>|null $devices null = every screen
     * @return array<string, mixed>
     */
    private static function block(callable $id, string $type, array $props, array $children = [], array $style = [], ?array $devices = null): array
    {
        $visibility = CanonicalDocumentSchema::defaultVisibility();
        if ($devices !== null) {
            $visibility['devices'] = $devices;
        }
        return [
            'id'         => $id(),
            'type'       => $type,
            'version'    => 1,
            'props'      => $props,
            'style'      => array_merge(CanonicalDocumentSchema::defaultBlockStyle(), $style),
            'visibility' => $visibility,
            'bindings'   => [],
            'children'   => $children,
        ];
    }

    /**
     * Three equal columns (left, centre, right) on every screen, so the middle one sits at the true centre.
     *
     * @param list<array<string, mixed>> $children
     * @param list<string>|null $devices
     * @return array<string, mixed>
     */
    private static function threeColumns(callable $id, array $children, ?array $devices = null): array
    {
        $grid = self::block($id, 'layout.grid', ['columns' => 3, 'gap' => 'md', 'align' => 'center'], $children, [], $devices);
        $grid['responsive'] = ['mobile' => ['style' => ['layout' => ['columns' => 3]]]];
        return $grid;
    }

    /** The site's name as a link home: there is no logo block, so the brand is text the owner renames. */
    private static function brand(callable $id, string $align = 'left'): array
    {
        return self::block($id, 'core.link', ['link' => ['label' => 'Your brand', 'href' => '/'], 'style' => 'plain', 'align' => $align],
            [], ['align' => ['base' => $align]]);
    }

    /**
     * A menu with its own links, edited in place (no site menu chosen).
     *
     * @param list<array{label: string, url: string}>|null $items
     * @param array<string, mixed> $toggle the menu button's props
     * @return array<string, mixed>
     */
    private static function menu(callable $id, string $layout, string $align, ?array $items = null, array $toggle = []): array
    {
        return self::block($id, 'core.nav_menu', $toggle + [
            'menu'       => '',
            'aria_label' => 'Main menu',
            'layout'     => $layout,
            'align'      => $align,
            'style'      => 'plain',
            'items'      => $items ?? [
                ['label' => 'Home', 'url' => '/'],
                ['label' => 'About', 'url' => '/about'],
                ['label' => 'Services', 'url' => '/services'],
                ['label' => 'Contact', 'url' => '/contact'],
            ],
        ], [], ['align' => ['base' => $align]]);
    }

    /** Brand, then the menu and a wide-screen button: the menu folds behind its own hamburger on a phone. */
    private static function classicRow(callable $id): array
    {
        return self::block($id, 'layout.flex', ['direction' => 'row', 'wrap' => 'nowrap', 'justify' => 'between', 'align' => 'center', 'gap' => 'lg'], [
            self::brand($id),
            self::block($id, 'layout.flex', ['direction' => 'row', 'wrap' => 'nowrap', 'justify' => 'end', 'align' => 'center', 'gap' => 'lg'], [
                self::menu($id, 'horizontal', 'right', null, ['toggle_breakpoint' => 'mobile']),
                self::button($id, 'Get in touch', '/contact', 'primary', 'sm', [], self::WIDE),
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $style
     * @param list<string>|null $devices
     */
    private static function button(callable $id, string $label, string $href, string $variant, string $size, array $style = [], ?array $devices = null): array
    {
        return self::block($id, 'core.button', [
            'link' => ['label' => $label, 'href' => $href], 'variant' => $variant, 'size' => $size, 'full_width' => false,
        ], [], $style, $devices);
    }

    private static function text(callable $id, string $content): array
    {
        return self::block($id, 'core.text', ['content' => $content, 'size' => 'sm', 'align' => 'left']);
    }
}
