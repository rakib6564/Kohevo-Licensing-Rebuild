<?php
/**
 * Kohevo Studio — one post type's edit screen.
 *
 *   post-type-edit.php?key=<type>   edit a type
 *   post-type-edit.php              a new type
 *
 * General settings, labels, advanced settings, meta fields, admin columns and admin filters, saved together by the
 * Actions card's Update. What each setting does is in `Meta/PostTypeSettings`; the list of types is types-fields.php.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Admin\PostTypeForm;
use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Meta\PostTypeSettings;

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit  = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
// What takes items on or off the public site changes it at once, like a menu: it needs the publish permission.
$canLive  = Auth::can('studio-builder.publish') || Auth::isSuperAdmin();
$liveSettings = ['public', 'publicly_queryable', 'exclude_from_search', 'route', 'has_archive'];
$tenantId = current_tenant_id();
$currentNav = 'studio-types-fields';
$flash = null;

$types = PageMeta::postTypes($tenantId);
$key   = (string) ($_GET['key'] ?? $_POST['type_key'] ?? '');
if ($key !== '' && !isset($types[$key])) {
    sb_flash_redirect('types-fields.php', ['type' => 'error', 'msg' => __('studio_tf_field_bad_type', 'Choose a post type that exists.')]);
}
$existing = $types[$key] ?? null;
$isNew    = $existing === null;
$type     = $existing ?? PostTypeSettings::normalize(['key' => '', 'rewrite' => true, 'hide_field_names' => false]);
$fields   = $isNew ? [] : PageMeta::fieldsFor($tenantId, $key);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage types and fields.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } elseif ($action === 'save') {
        $typeKeys = array_map('strval', array_keys($types));
        $newKey = $isNew ? (string) preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string) ($_POST['key'] ?? '')))) : $key;
        $parsed = PostTypeSettings::fieldsFromPost($_POST['fields'] ?? [], $newKey, array_values(array_unique([...$typeKeys, $newKey])));
        $built = PostTypeSettings::fromPost($_POST, $existing, $typeKeys, array_column($parsed['fields'], 'key'));
        $errors = [...$built['errors'], ...$parsed['errors']];
        if ($existing !== null && !$canLive && array_intersect_key($built['type'], array_flip($liveSettings)) != array_intersect_key($existing, array_flip($liveSettings))) {
            $errors[] = __('studio_tf_live_denied', 'These settings change the public site at once. Ask someone who can publish pages.');
        }
        if ($errors === []) {
            $types[$built['type']['key']] = $built['type'];
            PageMeta::savePostTypes($tenantId, $types);
            PageMeta::replaceFieldsFor($tenantId, (string) $built['type']['key'], $parsed['fields']);
            if ($existing !== null && ($existing['route'] ?? '') !== $built['type']['route']) {
                AuditLog::record('studio.post_type_route_changed', (string) $tenantId, ['key' => $key, 'route' => $built['type']['route']]);
            }
            sb_flash_redirect('post-type-edit.php?key=' . rawurlencode((string) $built['type']['key']), ['type' => 'success', 'msg' => sprintf(__('studio_tf_type_saved', "Post type '%s' saved."), (string) $built['type']['plural'])]);
        }
        // Back on screen as typed, with what is wrong.
        $flash = ['type' => 'error', 'msg' => implode(' ', array_unique($errors))];
        $built['type']['labels'] = (array) $built['type']['labels'];
        $type = PostTypeSettings::normalize($built['type']);
        $fields = $parsed['fields'];
    } elseif ($action === 'delete_metadata' && !$isNew && !$canLive) {
        $flash = ['type' => 'error', 'msg' => __('studio_tf_live_denied', 'These settings change the public site at once. Ask someone who can publish pages.')];
    } elseif ($action === 'delete_metadata' && !$isNew) {
        $count = PageMeta::clearValues($tenantId, $key);
        AuditLog::record('studio.post_type_metadata_deleted', (string) $tenantId, ['key' => $key, 'items' => $count]);
        sb_flash_redirect('post-type-edit.php?key=' . rawurlencode($key), ['type' => 'success', 'msg' => sprintf(__('studio_tf_metadata_deleted', 'Field values deleted from %d item(s).'), $count)]);
    } elseif ($action === 'delete' && !$isNew) {
        // Fields attached to the type go with it; content items already created are not touched.
        unset($types[$key]);
        PageMeta::savePostTypes($tenantId, $types);
        PageMeta::saveFields($tenantId, array_filter(PageMeta::allFields($tenantId), static fn(array $f): bool => ($f['post_type'] ?? '') !== $key));
        AuditLog::record('studio.post_type_deleted', (string) $tenantId, ['key' => $key]);
        sb_flash_redirect('types-fields.php', ['type' => 'success', 'msg' => sprintf(__('studio_tf_type_deleted', "Post type '%s' deleted."), (string) $existing['singular'])]);
    }
}

$flash = $flash ?? sb_take_flash();
$pageTitle = $isNew ? __('studio_tf_new_type', 'New Post Type') : (string) $type['plural'];
$typeOptions = array_map(static fn(array $t): string => (string) $t['singular'], $types);
$itemCount = $isNew ? 0 : count(PageMeta::pageIdsOfType($tenantId, $key));
$dis = $canEdit ? '' : ' disabled';

$check = static function (string $name, string $label, bool $on, string $hint = '', string $value = '1') use ($dis): string {
    return '<label class="sb-toggle-row"><input type="checkbox" name="' . e($name) . '" value="' . e($value) . '"' . ($on ? ' checked' : '') . $dis . '><span><strong>' . e($label) . '</strong>'
        . ($hint !== '' ? '<span class="sb-field__hint">' . e($hint) . '</span>' : '') . '</span></label>';
};

$supportsLabels = [
    'editor'    => [__('studio_tf_sup_editor', 'Builder content'), __('studio_tf_sup_editor_hint', 'Items are designed in the builder. Off: an item is only its fields, shown by its template.')],
    'thumbnail' => [__('studio_tf_sup_thumbnail', 'Featured image'), __('studio_tf_sup_thumbnail_hint', 'A featured image box on the item screen, used by Query Loop cards and social sharing.')],
    'excerpt'   => [__('studio_tf_sup_excerpt', 'Excerpt'), __('studio_tf_sup_excerpt_hint', 'A short summary box on the item screen, used by Query Loop cards.')],
];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">
    <?php sb_render_crumbs([[__('studio_nav_content_types', 'Content types'), 'types-fields.php'], [$pageTitle, null]]); ?>
    <?php sb_render_flash($flash); ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e($isNew ? __('studio_tf_new_type', 'New Post Type') : sprintf(__('studio_tf_edit_type', 'Edit post type: %s'), (string) $type['plural'])) ?></h2>
            <p><?= e(__('studio_tf_edit_sub', 'How this content type is named, reached and managed, and the fields each of its items holds.')) ?></p>
        </div>
        <?php if (!$isNew && PostTypeSettings::hasAdmin($type)): ?>
        <div class="sb-screen-actions">
            <a class="btn btn-outline" href="pages.php?type=<?= e(rawurlencode($key)) ?>" data-testid="type-items-<?= e($key) ?>"><?= e(PostTypeSettings::label($type, 'all_items')) ?></a>
        </div>
        <?php endif; ?>
    </div>

    <div class="sb-edit-layout">
        <form method="POST" id="sb-type-form" class="sb-edit-main">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="type_key" value="<?= e($key) ?>">

            <section class="sb-card" id="sb-sec-general" data-testid="type-general">
                <div class="sb-card__head"><div class="sb-card__title"><?= e(__('studio_tf_sec_general', 'General Settings')) ?></div></div>
                <div class="sb-form-grid">
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-type-name"><?= e(__('studio_tf_type_name', 'Post Type Name')) ?> <span class="field-required">*</span></label>
                        <input type="text" id="sb-type-name" name="name" maxlength="120" required value="<?= e((string) ($isNew && $_SERVER['REQUEST_METHOD'] !== 'POST' ? '' : $type['plural'])) ?>" placeholder="<?= e(__('studio_tf_plural_placeholder', 'e.g. Clients, Events')) ?>"<?= $dis ?>>
                        <p class="sb-field__hint"><?= e(__('studio_tf_type_name_hint', 'The plural name, shown on its list and in the sidebar.')) ?></p>
                    </div>
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-type-singular"><?= e(__('studio_tf_singular', 'Singular Label')) ?></label>
                        <input type="text" id="sb-type-singular" name="singular" maxlength="120" value="<?= e((string) ($isNew && $_SERVER['REQUEST_METHOD'] !== 'POST' ? '' : $type['singular'])) ?>" placeholder="<?= e(__('studio_tf_singular_placeholder', 'e.g. Client, Event')) ?>"<?= $dis ?>>
                    </div>
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-type-key"><?= e(__('studio_tf_type_slug', 'Post Type Slug')) ?> <span class="field-required">*</span></label>
                        <input type="text" id="sb-type-key" name="key" maxlength="64" value="<?= e($isNew ? (string) ($_POST['key'] ?? '') : $key) ?>"<?= $isNew ? ' required pattern="[a-z0-9_\-]+"' : ' readonly' ?><?= $dis ?>>
                        <p class="sb-field__hint"><?= e($isNew ? __('studio_tf_type_key_hint', 'Lower-case letters, numbers, hyphens and underscores.') : __('studio_tf_type_slug_locked', 'Fixed once created: items, relations and display conditions point at it. The address is set under Rewrite slug.')) ?></p>
                    </div>
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-type-desc"><?= e(__('description', 'Description')) ?></label>
                        <input type="text" id="sb-type-desc" name="description" maxlength="500" value="<?= e((string) $type['description']) ?>" placeholder="<?= e(__('studio_tf_type_description_placeholder', 'Purpose and data description...')) ?>"<?= $dis ?>>
                    </div>
                </div>
                <?= $check('edit_link', __('studio_tf_edit_link', 'Show edit link'), (bool) $type['edit_link'], __('studio_tf_edit_link_hint', 'A link to this screen on every item.')) ?>
                <?= $check('hide_field_names', __('studio_tf_hide_names', 'Hide field names'), (bool) $type['hide_field_names'], __('studio_tf_hide_names_hint', 'Hides each field\'s key under its label on the item screen.')) ?>
            </section>

            <section class="sb-card" id="sb-sec-labels" data-testid="type-labels">
                <div class="sb-card__head"><div class="sb-card__title"><?= e(__('studio_tf_sec_labels', 'Labels')) ?></div></div>
                <p class="sb-field__hint"><?= e(__('studio_tf_labels_hint', 'Leave a label empty to use the default shown in it.')) ?></p>
                <div class="sb-form-grid">
                    <?php foreach (PostTypeSettings::labelKeys() as $lk): ?>
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-label-<?= e($lk) ?>"><?= e(PostTypeSettings::labelTitle($lk)) ?></label>
                        <input type="text" id="sb-label-<?= e($lk) ?>" name="labels[<?= e($lk) ?>]" maxlength="120" value="<?= e((string) ($type['labels'][$lk] ?? '')) ?>" placeholder="<?= e(PostTypeSettings::defaultLabel($type, $lk)) ?>"<?= $dis ?>>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="sb-card" id="sb-sec-advanced" data-testid="type-advanced">
                <div class="sb-card__head"><div class="sb-card__title"><?= e(__('studio_tf_sec_advanced', 'Advanced Settings')) ?></div></div>
                <div class="sb-toggle-grid">
                    <?= $check('public', __('studio_tf_public', 'Public'), (bool) $type['public'], __('studio_tf_public_hint', 'Off: items leave the public site entirely: no page, sitemap, Query Loop, search or menu.')) ?>
                    <?= $check('exclude_from_search', __('studio_tf_exclude_search', 'Exclude from search'), (bool) $type['exclude_from_search'], __('studio_tf_exclude_search_hint', 'Items stay out of search results and stay in other Query Loops.')) ?>
                    <?= $check('publicly_queryable', __('studio_tf_queryable', 'Publicly queryable'), (bool) $type['publicly_queryable'], __('studio_tf_queryable_hint', 'Off: items have no page of their own, but Query Loops still list them.')) ?>
                    <?= $check('show_ui', __('studio_tf_show_ui', 'Show in admin'), (bool) $type['show_ui'], __('studio_tf_show_ui_hint', 'Off: items cannot be listed, created or edited in the admin. This screen stays.')) ?>
                    <?= $check('show_in_menu', __('studio_tf_show_in_menu', 'Show in the sidebar'), (bool) $type['show_in_menu'], __('studio_tf_show_in_menu_hint', 'Gives the type its own list in the Studio sidebar, under Pages.')) ?>
                    <?= $check('show_in_nav_menus', __('studio_tf_show_nav_menus', 'Show in nav menus'), (bool) $type['show_in_nav_menus'], __('studio_tf_show_nav_menus_hint', 'Offers its items when building a site menu.')) ?>
                    <?= $check('rewrite', __('studio_tf_rewrite', 'Rewrite'), (bool) $type['rewrite'], __('studio_tf_rewrite_hint', 'On: items live under the rewrite slug. Off: at /their-slug.')) ?>
                    <?= $check('has_archive', __('studio_tf_has_archive', 'Has archive'), (bool) $type['has_archive'], __('studio_tf_has_archive_hint', 'A list of the items at the rewrite slug, shown by your published Archive template. Needs Rewrite on.')) ?>
                </div>
                <div class="sb-form-grid">
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-rewrite-slug"><?= e(__('studio_tf_rewrite_slug', 'Rewrite slug')) ?></label>
                        <input type="text" id="sb-rewrite-slug" name="rewrite_slug" maxlength="120" value="<?= e((string) $type['rewrite_slug']) ?>" placeholder="/clients"<?= $dis ?>>
                        <p class="sb-field__hint"><?= e(__('studio_tf_rewrite_slug_hint', 'Items live at /this/item-slug; their old addresses redirect. Empty: the post type slug.')) ?></p>
                    </div>
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-menu-position"><?= e(__('studio_tf_menu_position', 'Menu position')) ?></label>
                        <input type="number" id="sb-menu-position" name="menu_position" min="0" max="999" value="<?= e((string) ($type['menu_position'] ?? '')) ?>"<?= $dis ?>>
                        <p class="sb-field__hint"><?= e(__('studio_tf_menu_position_hint', 'Lower comes first among the content types in the sidebar.')) ?></p>
                    </div>
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-menu-icon"><?= e(__('studio_tf_menu_icon', 'Menu icon')) ?></label>
                        <select id="sb-menu-icon" name="menu_icon"<?= $dis ?>>
                            <?php foreach (PostTypeSettings::ICONS as $icon): ?>
                                <option value="<?= e($icon) ?>"<?= $icon === $type['menu_icon'] ? ' selected' : '' ?>><?= e(__('studio_icon_' . str_replace('-', '_', $icon), ucfirst(str_replace('-', ' ', $icon)))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <h4 class="sb-subhead"><?= e(__('studio_tf_supports', 'Supports')) ?></h4>
                <div class="sb-toggle-grid">
                    <?php foreach (PostTypeSettings::SUPPORTS as $part): ?>
                        <?= $check('supports[]', $supportsLabels[$part][0], in_array($part, $type['supports'], true), $supportsLabels[$part][1], $part) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="sb-card" id="sb-sec-fields" data-testid="type-fields">
                <div class="sb-card__head sb-card__head--split">
                    <div class="sb-card__title"><?= e(__('studio_tf_sec_fields', 'Meta Fields')) ?></div>
                    <?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-outline" data-add="field"><?= sb_svg('plus', 12) ?> <?= e(__('studio_tf_add_field', 'Add Meta Field')) ?></button><?php endif; ?>
                </div>
                <div id="sb-list-field" class="sb-acc-list">
                    <?php foreach ($fields as $i => $f): ?>
                        <?= PostTypeForm::fieldRow($i, $f, $typeOptions) ?>
                    <?php endforeach; ?>
                </div>
                <template id="sb-tpl-field"><?= PostTypeForm::fieldRow(PostTypeForm::INDEX, [], $typeOptions, true) ?></template>
            </section>

            <section class="sb-card" id="sb-sec-columns" data-testid="type-columns">
                <div class="sb-card__head sb-card__head--split">
                    <div class="sb-card__title"><?= e(__('studio_tf_sec_columns', 'Admin Columns')) ?></div>
                    <?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-outline" data-add="column"><?= sb_svg('plus', 12) ?> <?= e(__('studio_tf_add_column', 'Add column')) ?></button><?php endif; ?>
                </div>
                <p class="sb-field__hint"><?= e(__('studio_tf_columns_hint', 'Extra columns on this type\'s list. A column shows a field key or one of the built-in values.')) ?></p>
                <div id="sb-list-column" class="sb-line-list">
                    <?php foreach ($type['columns'] as $i => $c): ?>
                        <?= PostTypeForm::columnRow($i, $c) ?>
                    <?php endforeach; ?>
                </div>
                <template id="sb-tpl-column"><?= PostTypeForm::columnRow(PostTypeForm::INDEX, []) ?></template>
                <datalist id="sb-col-sources">
                    <option value="_status"><?= e(__('status', 'Status')) ?></option>
                    <option value="_date"><?= e(__('studio_item_publish_date', 'Publish date')) ?></option>
                    <option value="_updated"><?= e(__('updated', 'Updated')) ?></option>
                    <option value="_slug"><?= e(__('studio_address', 'Address')) ?></option>
                    <?php foreach ($fields as $f): ?><option value="<?= e((string) $f['key']) ?>"><?= e((string) $f['label']) ?></option><?php endforeach; ?>
                </datalist>
            </section>

            <section class="sb-card" id="sb-sec-filters" data-testid="type-filters">
                <div class="sb-card__head sb-card__head--split">
                    <div class="sb-card__title"><?= e(__('studio_tf_sec_filters', 'Admin Filters')) ?></div>
                    <?php if ($canEdit): ?><button type="button" class="btn btn-sm btn-outline" data-add="filter"><?= sb_svg('plus', 12) ?> <?= e(__('studio_tf_add_filter', 'Add filter')) ?></button><?php endif; ?>
                </div>
                <p class="sb-field__hint"><?= e(__('studio_tf_filters_hint', 'Dropdowns over this type\'s list, one per meta field, offering its choices or the values saved so far.')) ?></p>
                <div id="sb-list-filter" class="sb-line-list">
                    <?php foreach ($type['filters'] as $i => $c): ?>
                        <?= PostTypeForm::filterRow($i, $c) ?>
                    <?php endforeach; ?>
                </div>
                <template id="sb-tpl-filter"><?= PostTypeForm::filterRow(PostTypeForm::INDEX, []) ?></template>
                <datalist id="sb-filter-sources">
                    <?php foreach ($fields as $f): ?><option value="<?= e((string) $f['key']) ?>"><?= e((string) $f['label']) ?></option><?php endforeach; ?>
                </datalist>
            </section>
        </form>

        <aside class="sb-edit-side">
            <div class="sb-card sb-sticky" data-testid="type-actions">
                <div class="sb-card__head"><div class="sb-card__title"><?= e(__('studio_tf_actions', 'Actions')) ?></div></div>
                <?php if ($canEdit): ?>
                    <button type="submit" form="sb-type-form" class="btn btn-primary sb-block-btn" data-testid="type-update"><?= sb_svg('save', 14) ?> <?= e($isNew ? __('studio_tf_save_type', 'Save Post Type') : __('update', 'Update')) ?></button>
                    <?php if (!$isNew): ?>
                    <form method="POST" onsubmit="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?= e(sprintf(__('studio_tf_delete_metadata_confirm', 'Delete the saved field values of all %d item(s)? The items and the fields stay. This cannot be undone.'), $itemCount)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="delete_metadata">
                        <input type="hidden" name="type_key" value="<?= e($key) ?>">
                        <button type="submit" class="btn btn-outline sb-block-btn" data-testid="type-delete-metadata"><?= e(__('studio_tf_delete_metadata', 'Delete metadata')) ?></button>
                    </form>
                    <form method="POST" onsubmit="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?= e(sprintf(__('studio_tf_delete_type_confirm', 'Delete the post type %s? Its meta fields are deleted too. Content already created is kept.'), (string) $type['singular'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="delete">
                        <input type="hidden" name="type_key" value="<?= e($key) ?>">
                        <button type="submit" class="btn btn-danger sb-block-btn" data-testid="type-delete"><?= sb_svg('trash', 14) ?> <?= e(__('delete', 'Delete')) ?></button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
                <nav class="sb-anchor-nav" aria-label="<?= e(__('studio_tf_sections', 'Sections')) ?>">
                    <a href="#sb-sec-general"><?= e(__('studio_tf_sec_general', 'General Settings')) ?></a>
                    <a href="#sb-sec-labels"><?= e(__('studio_tf_sec_labels', 'Labels')) ?></a>
                    <a href="#sb-sec-advanced"><?= e(__('studio_tf_sec_advanced', 'Advanced Settings')) ?></a>
                    <a href="#sb-sec-fields"><?= e(__('studio_tf_sec_fields', 'Meta Fields')) ?></a>
                    <a href="#sb-sec-columns"><?= e(__('studio_tf_sec_columns', 'Admin Columns')) ?></a>
                    <a href="#sb-sec-filters"><?= e(__('studio_tf_sec_filters', 'Admin Filters')) ?></a>
                </nav>
            </div>
        </aside>
    </div>
</div>

<?= PostTypeForm::script() ?>
<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
