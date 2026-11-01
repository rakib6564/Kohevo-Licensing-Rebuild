<?php
/**
 * Kohevo Studio — a post type's settings: what an older stored type means, and what a posted edit screen may save.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Admin\PageListView;
use Slate\Module\StudioBuilder\Backup\StudioBackupFormat;
use Slate\Module\StudioBuilder\Menu\MenuSources;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Meta\PostTypeSettings;

$sbpt_post = static fn(array $extra = []): array => $extra + ['name' => 'Events', 'singular' => 'Event', 'key' => 'event', 'public' => '1', 'publicly_queryable' => '1', 'show_ui' => '1', 'show_in_menu' => '1', 'show_in_nav_menus' => '1'];

unit('post type settings: a type stored before the edit screen keeps working as it did', function (): void {
    $t = PostTypeSettings::normalize(['key' => 'cours', 'singular' => 'Cours', 'plural' => 'Cours', 'route' => '/cours', 'show_in_menu' => false]);
    assert_true($t['public'] && $t['publicly_queryable'] && $t['show_ui'] && $t['show_in_nav_menus'], 'public as before');
    assert_false($t['exclude_from_search'] || $t['has_archive'] || $t['edit_link'], 'nothing new is switched on');
    assert_true($t['hide_field_names'], 'editors see the same screen');
    assert_true($t['rewrite']);
    assert_eq('cours', $t['rewrite_slug'], 'the route is the rewrite slug');
    assert_eq(PostTypeSettings::SUPPORTS, $t['supports']);
    assert_false($t['show_in_menu'], 'its stored choice stays');
    assert_eq('/cours', $t['route'], 'the routing key is untouched');
    assert_false(PostTypeSettings::normalize(['key' => 'x', 'route' => '/'])['rewrite'], 'no prefix: no rewrite');
    assert_eq('x', PostTypeSettings::normalize(['key' => 'x'])['plural'], 'names fall back to the key');
    assert_eq('z', PostTypeSettings::normalize(['key' => 'x', 'future' => 'z'])['future'], 'a newer setting survives');
});

unit('post type settings: each label has a default, and the site\'s own wins', function (): void {
    $t = PostTypeSettings::normalize(['key' => 'event', 'singular' => 'Event', 'plural' => 'Events']);
    foreach (PostTypeSettings::labelKeys() as $k) {
        assert_true(trim(PostTypeSettings::label($t, $k)) !== '', "{$k} has a default");
    }
    assert_eq('Add new event', PostTypeSettings::label($t, 'add_new_item'));
    assert_eq('Events', PostTypeSettings::label($t, 'menu_name'));
    assert_eq('Event published.', PostTypeSettings::label($t, 'item_published'));
    $t['labels']['add_new_item'] = 'Plan an event';
    assert_eq('Plan an event', PostTypeSettings::label($t, 'add_new_item'));
});

unit('post type settings: public is the master switch, and the others narrow it', function (): void {
    $t = PostTypeSettings::normalize(['key' => 'a']);
    assert_true(PostTypeSettings::isQueryable($t) && PostTypeSettings::isListed($t, true) && PostTypeSettings::inNavMenus($t));
    $off = ['public' => false] + $t;
    assert_false(PostTypeSettings::isQueryable($off) || PostTypeSettings::isListed($off, false) || PostTypeSettings::inNavMenus($off));
    assert_false(PostTypeSettings::isQueryable(['publicly_queryable' => false] + $t), 'no page of its own');
    assert_true(PostTypeSettings::isListed(['publicly_queryable' => false] + $t, true), 'still listed');
    $noSearch = ['exclude_from_search' => true] + $t;
    assert_false(PostTypeSettings::isListed($noSearch, true));
    assert_true(PostTypeSettings::isListed($noSearch, false), 'other Query Loops keep it');
    assert_false(PostTypeSettings::inSidebar(['show_ui' => false] + $t), 'no admin, no sidebar');
    assert_false(PostTypeSettings::inSidebar(['show_in_menu' => false] + $t));
});

unit('post type settings: the sidebar follows menu position, unset last, then creation order', function (): void {
    $types = ['a' => ['menu_position' => null], 'b' => ['menu_position' => 5], 'c' => ['menu_position' => 1], 'd' => ['menu_position' => null]];
    assert_eq(['c', 'b', 'a', 'd'], array_keys(PostTypeSettings::sorted($types)));
});

unit('post type settings: a posted type is cleaned, and rewrite decides the route', function () use ($sbpt_post): void {
    $r = PostTypeSettings::fromPost($sbpt_post(['rewrite' => '1', 'rewrite_slug' => '/Agenda/']), null, [], []);
    assert_eq([], $r['errors']);
    assert_eq('/agenda', $r['type']['route']);
    $r = PostTypeSettings::fromPost($sbpt_post(['rewrite' => '1', 'key' => 'case_study']), null, [], []);
    assert_eq('/case-study', $r['type']['route'], 'an empty slug takes the key, in address form');
    $r = PostTypeSettings::fromPost($sbpt_post(['rewrite_slug' => 'agenda']), null, [], []);
    assert_eq('/', $r['type']['route'], 'rewrite off: at /their-slug');
    assert_eq('agenda', $r['type']['rewrite_slug'], 'the slug is remembered');
    assert_true(PostTypeSettings::fromPost($sbpt_post(['rewrite' => '1', 'rewrite_slug' => 'category/x']), null, [], [])['errors'] !== [], 'an address Theme Builder owns');
    assert_true(PostTypeSettings::fromPost($sbpt_post(), null, ['event'], [])['errors'] !== [], 'a taken key');
    assert_true(PostTypeSettings::fromPost(['key' => 'x'], null, [], [])['errors'] !== [], 'a name is required');
    $r = PostTypeSettings::fromPost($sbpt_post(['key' => 'other', 'menu_icon' => '<svg>', 'menu_position' => '5000', 'supports' => ['excerpt', 'comments']]), ['key' => 'event'], [], []);
    assert_eq('event', $r['type']['key'], 'an existing type keeps its key');
    assert_eq(PostTypeSettings::DEFAULT_ICON, $r['type']['menu_icon']);
    assert_eq(999, $r['type']['menu_position']);
    assert_eq(['excerpt'], $r['type']['supports']);
});

unit('post type settings: posted fields keep their order and id, and bad ones are named', function (): void {
    $r = PostTypeSettings::fieldsFromPost([
        'n2' => ['id' => 'f_0000abcd', 'label' => 'Second', 'key' => 'Second Key', 'type' => 'text'],
        '0'  => ['label' => 'First', 'key' => 'first', 'type' => 'number', 'min' => '1'],
    ], 'event', ['event']);
    assert_eq([], $r['errors']);
    assert_eq(['second_key', 'first'], array_column($r['fields'], 'key'));
    assert_eq('f_0000abcd', $r['fields'][0]['id']);
    assert_true(preg_match('/^f_[0-9a-f]{8}$/', $r['fields'][1]['id']) === 1, 'a new field gets an id');
    assert_eq(['min' => 1], $r['fields'][1]['options']);
    assert_eq('event', $r['fields'][0]['post_type']);
    assert_true(PostTypeSettings::fieldsFromPost([['label' => 'A', 'key' => 'a'], ['label' => 'B', 'key' => 'a']], 'e', [])['errors'] !== [], 'a repeated key');
    assert_true(PostTypeSettings::fieldsFromPost([['label' => 'A', 'key' => 'a', 'type' => 'select']], 'e', [])['errors'] !== [], 'no choices');
    assert_true(PostTypeSettings::fieldsFromPost([['label' => 'A', 'key' => 'a', 'type' => 'relation', 'target' => 'gone']], 'e', ['event'])['errors'] !== [], 'no target');
});

unit('post type settings: columns and filters must name a field or a built-in value', function () use ($sbpt_post): void {
    $r = PostTypeSettings::fromPost($sbpt_post(['columns' => [['source' => 'genre', 'label' => 'G', 'sortable' => '1'], ['source' => '_status'], ['source' => '']], 'filters' => [['source' => 'genre']]]), null, [], ['genre']);
    assert_eq([], $r['errors']);
    assert_eq(['genre', '_status'], array_column($r['type']['columns'], 'source'));
    assert_true($r['type']['columns'][0]['sortable']);
    assert_true(PostTypeSettings::fromPost($sbpt_post(['columns' => [['source' => 'nope']]]), null, [], [])['errors'] !== []);
    assert_true(PostTypeSettings::fromPost($sbpt_post(['filters' => [['source' => '_status']]]), null, [], ['genre'])['errors'] !== [], 'a filter reads a field');
});

unit('admin list: columns read as a person reads them, and a field deleted since is dropped', function (): void {
    $fields = ['genre' => ['key' => 'genre', 'label' => 'Genre', 'type' => 'select', 'options' => ['choices' => [['value' => 'n', 'label' => 'Novel']]]]];
    $cols = PageListView::columns([['source' => 'genre'], ['source' => 'gone'], ['source' => '_status', 'label' => 'State']], $fields);
    assert_eq(['Genre', 'State'], array_column($cols, 'title'));
    $choices = PageListView::filterChoices([['source' => 'genre'], ['source' => 'gone']], $fields, []);
    assert_eq(['genre' => ['n' => 'Novel']], $choices);
    assert_eq(['a', 'b'], PageListView::valueWords(['a', 'b']));
    assert_eq(['0'], PageListView::valueWords(false));
    $rows = [['id' => 1, 'title' => 'x'], ['id' => 2, 'title' => 'y'], ['id' => 3, 'title' => 'z']];
    $values = [1 => ['n' => '10'], 2 => [], 3 => ['n' => '9']];
    assert_eq([3, 1, 2], array_column(PageListView::sorted($rows, 'n', 'asc', $values), 'id'), 'numbers sort as numbers, empty last');
    assert_eq([1, 3, 2], array_column(PageListView::sorted($rows, 'n', 'desc', $values), 'id'), 'empty is last either way');
});

unit('nav menus: a type off them offers no group, and its items do not fall into Pages', function (): void {
    $pages = [['id' => 1, 'page_type' => 'page', 'is_published' => true, 'public_path' => '/a', 'title' => 'A'], ['id' => 2, 'page_type' => 'page', 'is_published' => true, 'public_path' => '/b', 'title' => 'B']];
    $groups = MenuSources::groups($pages, [1 => 'hid'], ['hid' => PostTypeSettings::normalize(['key' => 'hid', 'show_in_nav_menus' => false])]);
    assert_eq(['page'], array_column($groups, 'key'));
    assert_eq(['B'], array_column($groups[0]['items'], 'title'));
});

unit('post type settings: the whole stored shape travels in settings a backup already carries', function (): void {
    assert_true(in_array('studio_custom_post_types', StudioBackupFormat::SETTING_KEYS, true));
    assert_true(in_array('studio_meta_fields', StudioBackupFormat::SETTING_KEYS, true));
    assert_eq('1.0', StudioBackupFormat::VERSION, 'the format did not change');
});

unit('admin screens: a post type has its own edit screen, and the item screen saves in one form', function (): void {
    $dir = SLATE_ROOT . '/plugins/studio-builder/admin/';
    $edit = (string) file_get_contents($dir . 'post-type-edit.php');
    $at = -1;
    foreach (['studio_tf_sec_general', 'studio_tf_sec_labels', 'studio_tf_sec_advanced', 'studio_tf_sec_fields', 'studio_tf_sec_columns', 'studio_tf_sec_filters', 'type-actions'] as $part) {
        $next = strpos($edit, $part);
        assert_true($next !== false && $next > $at, "{$part} in order");
        $at = $next;
    }
    $item = (string) file_get_contents($dir . 'page-edit.php');
    foreach (['item-settings', 'item-publish', 'item-template', 'item-image', 'item-seo'] as $box) {
        assert_true(str_contains($item, $box), $box);
    }
    assert_eq('', PageRoutes::normalizePrefix('/'), 'unchanged');
});
