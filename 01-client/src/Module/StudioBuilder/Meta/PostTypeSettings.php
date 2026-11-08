<?php
/**
 * Kohevo Studio — the settings of one post type: the shape stored in `studio_custom_post_types`.
 *
 * A type saved before this screen existed holds only key, singular, plural, route, description and show_in_menu.
 * `normalize()` gives every type the full shape, so everything else reads a setting without asking whether it is there.
 * An absent setting means "as the site worked before it existed": public, in search, in menus, no archive.
 *
 * Only settings that change something in Kohevo are here. WordPress settings with no meaning in Kohevo (custom meta
 * storage, REST, query var, with front, capability type, map meta cap, hierarchical, and the supports other than the
 * ones in SUPPORTS) are left out on purpose: a control that does nothing is worse than no control.
 *
 * `fromPost()` is the only way a type gets in: it keeps what is valid and names what is not, whatever was posted.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Meta;

final class PostTypeSettings
{
    public const DEFAULT_ICON = 'folder';

    /** Item screen parts a type can switch off. Title, status and address are always there. */
    public const SUPPORTS = ['editor', 'thumbnail', 'excerpt'];

    /** Admin sidebar icons a type can pick, all drawn by `slate_admin_nav_icon()`. */
    public const ICONS = ['folder', 'file-text', 'book', 'briefcase', 'calendar', 'camera', 'clipboard', 'film', 'globe', 'image', 'layout', 'list', 'map-pin', 'message-square', 'package', 'shopping-bag', 'star', 'tag', 'target', 'user', 'users'];

    /** Admin column sources that are not a meta field. */
    public const COLUMN_SOURCES = ['_status', '_date', '_updated', '_slug'];

    /**
     * Each label's translation key, English default and the name it is built from.
     *
     * @var array<string, array{0: string, 1: string, 2: ''|'singular'|'plural'}>
     */
    private const LABELS = [
        'menu_name'             => ['studio_ptl_menu_name', '%s', 'plural'],
        'all_items'             => ['studio_ptl_all_items', 'All %s', 'plural'],
        'add_new'               => ['studio_ptl_add_new', 'Add new', ''],
        'add_new_item'          => ['studio_ptl_add_new_item', 'Add new %s', 'singular'],
        'edit_item'             => ['studio_ptl_edit_item', 'Edit %s', 'singular'],
        'view_item'             => ['studio_ptl_view_item', 'View %s', 'singular'],
        'search_items'          => ['studio_ptl_search_items', 'Search %s', 'plural'],
        'not_found'             => ['studio_ptl_not_found', 'No %s found', 'plural'],
        'filter_items_list'     => ['studio_ptl_filter_items_list', 'Filter %s', 'plural'],
        'featured_image'        => ['studio_ptl_featured_image', 'Featured image', ''],
        'set_featured_image'    => ['studio_ptl_set_featured_image', 'Set featured image', ''],
        'remove_featured_image' => ['studio_ptl_remove_featured_image', 'Remove featured image', ''],
        'archives'              => ['studio_ptl_archives', '%s archives', 'plural'],
        'item_published'        => ['studio_ptl_item_published', '%s published.', 'singular'],
        'item_scheduled'        => ['studio_ptl_item_scheduled', '%s scheduled.', 'singular'],
        'item_updated'          => ['studio_ptl_item_updated', '%s updated.', 'singular'],
        'item_draft'            => ['studio_ptl_item_draft', '%s moved back to draft.', 'singular'],
    ];

    /** What the edit screen calls each label. @var array<string, string> */
    private const LABEL_TITLES = [
        'menu_name'             => 'Menu name',
        'all_items'             => 'All items',
        'add_new'               => 'Add new',
        'add_new_item'          => 'Add new item',
        'edit_item'             => 'Edit item',
        'view_item'             => 'View item',
        'search_items'          => 'Search items',
        'not_found'             => 'Not found',
        'filter_items_list'     => 'Filter items list',
        'featured_image'        => 'Featured image',
        'set_featured_image'    => 'Set featured image',
        'remove_featured_image' => 'Remove featured image',
        'archives'              => 'Archives',
        'item_published'        => 'Item published',
        'item_scheduled'        => 'Item scheduled',
        'item_updated'          => 'Item updated',
        'item_draft'            => 'Item back to draft',
    ];

    /** What the edit screen calls one label. */
    public static function labelTitle(string $key): string
    {
        return self::t('studio_ptl_title_' . $key, self::LABEL_TITLES[$key] ?? $key);
    }

    /** @return list<string> the label keys, in screen order */
    public static function labelKeys(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * The full shape of a stored type. Unknown keys are kept, so a newer setting survives an older screen.
     *
     * @param array<string, mixed> $type
     * @return array<string, mixed>
     */
    public static function normalize(array $type): array
    {
        $key = (string) ($type['key'] ?? '');
        $prefix = PageRoutes::normalizePrefix($type['route'] ?? '') ?? '';
        $singular = trim((string) ($type['singular'] ?? '')) !== '' ? (string) $type['singular'] : $key;
        $plural = trim((string) ($type['plural'] ?? '')) !== '' ? (string) $type['plural'] : $singular;
        $flag = static fn(string $k, bool $default): bool => is_bool($type[$k] ?? null) ? $type[$k] : $default;
        $position = is_numeric($type['menu_position'] ?? null) ? (int) $type['menu_position'] : null;
        $icon = (string) ($type['menu_icon'] ?? '');

        return [
            'singular'            => $singular,
            'plural'              => $plural,
            'description'         => (string) ($type['description'] ?? ''),
            'edit_link'           => $flag('edit_link', false),
            // A type older than this setting never showed field keys to its editors.
            'hide_field_names'    => $flag('hide_field_names', true),
            'labels'              => array_filter(is_array($type['labels'] ?? null) ? $type['labels'] : [], 'is_string'),
            'public'              => $flag('public', true),
            'exclude_from_search' => $flag('exclude_from_search', false),
            'publicly_queryable'  => $flag('publicly_queryable', true),
            'show_ui'             => $flag('show_ui', true),
            'show_in_menu'        => ($type['show_in_menu'] ?? true) !== false,
            'show_in_nav_menus'   => $flag('show_in_nav_menus', true),
            'rewrite'             => $flag('rewrite', $prefix !== ''),
            'rewrite_slug'        => is_string($type['rewrite_slug'] ?? null) ? $type['rewrite_slug'] : $prefix,
            'has_archive'         => $flag('has_archive', false),
            'menu_position'       => $position,
            'menu_icon'           => in_array($icon, self::ICONS, true) ? $icon : self::DEFAULT_ICON,
            'supports'            => is_array($type['supports'] ?? null) ? array_values(array_intersect(self::SUPPORTS, $type['supports'])) : self::SUPPORTS,
            'columns'             => is_array($type['columns'] ?? null) ? array_values(array_filter($type['columns'], 'is_array')) : [],
            'filters'             => is_array($type['filters'] ?? null) ? array_values(array_filter($type['filters'], 'is_array')) : [],
        ] + $type;
    }

    /** What a label says when the site has not set it, built from the type's names. */
    public static function defaultLabel(array $type, string $key): string
    {
        [$i18n, $default, $from] = self::LABELS[$key] ?? ['', $key, ''];
        $text = $i18n !== '' && function_exists('__') ? (string) \__($i18n, $default) : $default;
        if ($from === '') {
            return $text;
        }
        $name = (string) ($type[$from] ?? $type['key'] ?? '');
        // Mid-sentence a name reads in lower case ("Add new event"); alone or leading it keeps its capitals.
        return sprintf($text, str_starts_with($text, '%s') ? $name : mb_strtolower($name));
    }

    /** The site's own label, else its default. */
    public static function label(array $type, string $key): string
    {
        $set = trim((string) ($type['labels'][$key] ?? ''));
        return $set !== '' ? $set : self::defaultLabel($type, $key);
    }

    /** Whether the type has the given item screen part. */
    public static function supports(array $type, string $part): bool
    {
        return in_array($part, is_array($type['supports'] ?? null) ? $type['supports'] : self::SUPPORTS, true);
    }

    /** Whether its items have a public address at all. */
    public static function isQueryable(array $type): bool
    {
        return ($type['public'] ?? true) !== false && ($type['publicly_queryable'] ?? true) !== false;
    }

    /** Whether Query Loops and search may list its items. */
    public static function isListed(array $type, bool $search): bool
    {
        return ($type['public'] ?? true) !== false && !($search && !empty($type['exclude_from_search']));
    }

    /** Whether the site's menus may offer its items. */
    public static function inNavMenus(array $type): bool
    {
        return ($type['public'] ?? true) !== false && ($type['show_in_nav_menus'] ?? true) !== false;
    }

    /** Whether its items are managed in the admin. */
    public static function hasAdmin(array $type): bool
    {
        return ($type['show_ui'] ?? true) !== false;
    }

    /** Whether it has its own entry in the sidebar. */
    public static function inSidebar(array $type): bool
    {
        return self::hasAdmin($type) && ($type['show_in_menu'] ?? true) !== false;
    }

    /**
     * Types in sidebar order: by menu position (a type without one goes last), then as created.
     *
     * @param array<string, array<string, mixed>> $types
     * @return array<string, array<string, mixed>>
     */
    public static function sorted(array $types): array
    {
        $rank = array_flip(array_keys($types));
        uksort($types, static function (string|int $a, string|int $b) use ($types, $rank): int {
            return [$types[$a]['menu_position'] ?? PHP_INT_MAX, $rank[$a]] <=> [$types[$b]['menu_position'] ?? PHP_INT_MAX, $rank[$b]];
        });
        return $types;
    }

    /**
     * A type as posted by the edit screen.
     *
     * @param array<string, mixed>  $post
     * @param ?array<string, mixed> $existing   the stored type, null for a new one (its key is then taken from the post)
     * @param list<string>          $takenKeys  the keys other types already use
     * @param list<string>          $fieldKeys  the type's field keys, as they will be after this save
     * @return array{type: array<string, mixed>, errors: list<string>}
     */
    public static function fromPost(array $post, ?array $existing, array $takenKeys, array $fieldKeys): array
    {
        $errors = [];
        $text = static fn(string $k, int $max): string => mb_substr(trim(is_scalar($post[$k] ?? null) ? (string) $post[$k] : ''), 0, $max);
        $on = static fn(string $k): bool => !empty($post[$k]);

        $key = $existing !== null
            ? (string) $existing['key']
            : (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($text('key', 64)));
        $plural = $text('name', 120);
        $singular = $text('singular', 120) !== '' ? $text('singular', 120) : $plural;

        if ($key === '' || $plural === '') {
            $errors[] = self::t('studio_tf_type_required', 'Post type name and slug are required.');
        } elseif ($existing === null && in_array($key, $takenKeys, true)) {
            $errors[] = sprintf(self::t('studio_tf_type_exists', "Post type '%s' already exists."), $key);
        }

        $rewrite = $on('rewrite');
        $slugRaw = $text('rewrite_slug', 120);
        if ($rewrite && $slugRaw === '') {
            $slugRaw = str_replace('_', '-', $key);
        }
        $prefix = PageRoutes::normalizePrefix($slugRaw);
        if ($prefix === null) {
            $errors[] = self::t('studio_tf_route_invalid', 'A URL route prefix is one to three lowercase words separated by /, like /events. Leave it empty for none.');
            $prefix = '';
        }

        $position = trim((string) ($post['menu_position'] ?? ''));
        $labels = [];
        foreach (self::labelKeys() as $k) {
            $value = mb_substr(trim(is_scalar($post['labels'][$k] ?? null) ? (string) $post['labels'][$k] : ''), 0, 120);
            if ($value !== '') {
                $labels[$k] = $value;
            }
        }

        $columns = self::columnsFromPost($post['columns'] ?? [], $fieldKeys, $errors);
        $filters = self::filtersFromPost($post['filters'] ?? [], $fieldKeys, $errors);

        $type = [
            'key'                 => $key,
            'singular'            => $singular,
            'plural'              => $plural,
            'route'               => '/' . ($rewrite ? $prefix : ''),
            'is_system'           => false,
            'description'         => $text('description', 500),
            'edit_link'           => $on('edit_link'),
            'hide_field_names'    => $on('hide_field_names'),
            'labels'              => (object) $labels,
            'public'              => $on('public'),
            'exclude_from_search' => $on('exclude_from_search'),
            'publicly_queryable'  => $on('publicly_queryable'),
            'show_ui'             => $on('show_ui'),
            'show_in_menu'        => $on('show_in_menu'),
            'show_in_nav_menus'   => $on('show_in_nav_menus'),
            'rewrite'             => $rewrite,
            'rewrite_slug'        => $prefix,
            'has_archive'         => $on('has_archive'),
            'menu_position'       => is_numeric($position) ? max(0, min(999, (int) $position)) : null,
            'menu_icon'           => in_array($post['menu_icon'] ?? '', self::ICONS, true) ? (string) $post['menu_icon'] : self::DEFAULT_ICON,
            'supports'            => array_values(array_intersect(self::SUPPORTS, is_array($post['supports'] ?? null) ? $post['supports'] : [])),
            'columns'             => $columns,
            'filters'             => $filters,
        ];
        return ['type' => $type + ($existing ?? []), 'errors' => $errors];
    }

    /**
     * The meta fields of a type as posted, in posted order. A field keeps its id, so a renamed key can follow its values.
     *
     * @param mixed        $rows      the posted `fields` array
     * @param list<string> $typeKeys  every post type key, for a relation's target
     * @return array{fields: list<array<string, mixed>>, errors: list<string>}
     */
    public static function fieldsFromPost(mixed $rows, string $postType, array $typeKeys): array
    {
        $fields = [];
        $errors = [];
        $seen = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 120);
            $key = trim((string) preg_replace('/[^a-z0-9_]/', '_', strtolower(trim((string) ($row['key'] ?? '')))), '_');
            $fieldType = MetaFieldTypes::normalize((string) ($row['type'] ?? 'text'));
            $options = MetaFieldTypes::options($fieldType, $row, $typeKeys);
            $id = preg_match('/^f_[0-9a-f]{8}$/', (string) ($row['id'] ?? '')) === 1 ? (string) $row['id'] : 'f_' . bin2hex(random_bytes(4));

            if ($label === '' || $key === '') {
                $errors[] = self::t('studio_tf_field_required', 'Field label and field key are required.');
            } elseif (isset($seen[$key])) {
                $errors[] = sprintf(self::t('studio_tf_field_exists', "A field with the key '%1\$s' already exists on %2\$s."), $key, $postType);
            } elseif (in_array($fieldType, ['select', 'radio', 'multiselect', 'checkboxes', 'terms'], true) && empty($options['choices'])) {
                $errors[] = sprintf('%s: %s', $label, self::t('studio_tf_field_choices', 'Add at least one choice (one per line, as value or value : Label).'));
            } elseif (in_array($fieldType, ['relation', 'relations'], true) && empty($options['target'])) {
                $errors[] = sprintf('%s: %s', $label, self::t('studio_tf_field_target', 'Pick the post type this relation links to.'));
            }
            $seen[$key] = true;
            $fields[] = [
                'id'          => $id,
                'post_type'   => $postType,
                'label'       => $label,
                'key'         => $key,
                'type'        => $fieldType,
                'required'    => !empty($row['required']),
                'placeholder' => mb_substr(trim((string) ($row['placeholder'] ?? '')), 0, 255),
                'help'        => mb_substr(trim((string) ($row['help'] ?? '')), 0, 300),
                'options'     => $options,
            ];
        }
        return ['fields' => $fields, 'errors' => $errors];
    }

    /**
     * @param list<string> $fieldKeys
     * @param list<string> $errors
     * @return list<array{label: string, source: string, prefix: string, suffix: string, sortable: bool}>
     */
    private static function columnsFromPost(mixed $rows, array $fieldKeys, array &$errors): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $source = is_array($row) ? trim((string) ($row['source'] ?? '')) : '';
            if ($source === '') {
                continue;
            }
            if (!in_array($source, $fieldKeys, true) && !in_array($source, self::COLUMN_SOURCES, true)) {
                $errors[] = sprintf(self::t('studio_tf_column_unknown', "The column '%s' does not match a field of this post type."), $source);
                continue;
            }
            $out[] = [
                'label'    => mb_substr(trim((string) ($row['label'] ?? '')), 0, 80),
                'source'   => $source,
                'prefix'   => mb_substr((string) ($row['prefix'] ?? ''), 0, 20),
                'suffix'   => mb_substr((string) ($row['suffix'] ?? ''), 0, 20),
                'sortable' => !empty($row['sortable']),
            ];
        }
        return $out;
    }

    /**
     * @param list<string> $fieldKeys
     * @param list<string> $errors
     * @return list<array{label: string, source: string}>
     */
    private static function filtersFromPost(mixed $rows, array $fieldKeys, array &$errors): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $source = is_array($row) ? trim((string) ($row['source'] ?? '')) : '';
            if ($source === '') {
                continue;
            }
            if (!in_array($source, $fieldKeys, true)) {
                $errors[] = sprintf(self::t('studio_tf_filter_unknown', "The filter '%s' does not match a field of this post type."), $source);
                continue;
            }
            $out[] = ['label' => mb_substr(trim((string) ($row['label'] ?? '')), 0, 80), 'source' => $source];
        }
        return $out;
    }

    private static function t(string $key, string $default): string
    {
        return function_exists('__') ? (string) \__($key, $default) : $default;
    }
}
