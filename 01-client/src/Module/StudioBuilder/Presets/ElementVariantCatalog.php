<?php
/**
 * Kohevo Studio — Add-panel element variants.
 *
 * A variant is a ready-set configuration of a block type that already exists: "Columns 3" is a `layout.grid`
 * with `columns: 3`, a "Text area" is a `core.form_field` with `field_type: textarea`. No new block type is
 * created (type strings are stored in documents forever), so documents stay portable. Variants are properties
 * only, never subtrees; the author then adds content into the selected container.
 *
 * Titles and descriptions are English here and translated at the application boundary with
 * `studio_variant_<key>_title|desc` (dashes in the key become underscores).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Presets;

final class ElementVariantCatalog
{
    /** @return list<array{key: string, type: string, category: string, icon: string, title: string, description: string, props: array<string, mixed>}> */
    public static function all(): array
    {
        return [
            self::v('columns-2', 'layout.grid', 'layout', 'grid-panes', 'Two columns', 'Content side by side', ['columns' => 2, 'gap' => 'lg']),
            self::v('columns-3', 'layout.grid', 'layout', 'grid-panes', 'Three columns', 'Three equal columns', ['columns' => 3, 'gap' => 'lg']),
            self::v('columns-4', 'layout.grid', 'layout', 'grid-panes', 'Four columns', 'Four equal columns', ['columns' => 4, 'gap' => 'lg']),
            self::v('stack', 'layout.flex', 'layout', 'box', 'Stack', 'Elements one under another', ['direction' => 'column', 'align' => 'stretch']),
            self::v('row', 'layout.flex', 'layout', 'box', 'Row', 'Elements in a line that wraps on small screens', ['direction' => 'row', 'wrap' => 'wrap', 'align' => 'center']),
            self::v('textarea', 'core.form_field', 'forms', 'form', 'Text area', 'A multi-line input for a form', ['name' => 'message', 'label' => 'Message', 'field_type' => 'textarea']),
            self::v('select', 'core.form_field', 'forms', 'form', 'Dropdown', 'A list to choose one option from', ['name' => 'choice', 'label' => 'Choose one', 'field_type' => 'select', 'options' => 'Option one, Option two, Option three']),
        ];
    }

    /**
     * @param array<string, mixed> $props
     * @return array{key: string, type: string, category: string, icon: string, title: string, description: string, props: array<string, mixed>}
     */
    private static function v(string $key, string $type, string $category, string $icon, string $title, string $description, array $props): array
    {
        return ['key' => $key, 'type' => $type, 'category' => $category, 'icon' => $icon, 'title' => $title, 'description' => $description, 'props' => $props];
    }
}
