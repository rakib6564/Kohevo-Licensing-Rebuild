<?php
/**
 * Kohevo Studio — the shared menu editor, in two columns as in a classic site admin:
 *
 *   - "Add menu items": one collapsible box per source (Pages, each content type, Shop products while Shop is active;
 *     MenuSources) with Most recent / View all / Search, ticks, Select all and "Add to menu", plus a Custom links box.
 *   - "Menu structure": the menu's name and key and its items. Drag an item by its handle to reorder it, and right or
 *     left to put it in (or take it out of) the submenu of the item above, up to StudioMenus::MAX_DEPTH levels; the
 *     handle also takes the arrow keys. Each item's arrow opens its settings: label, address, CSS classes, new tab, and
 *     Move / Remove. Bulk select removes several items at once.
 *
 * The form still posts a flat list where each row carries its `depth` (items[i][label|url|new_tab|class|depth]), which
 * StudioMenus::validate() turns into the stored tree, so menus saved by the earlier form read back unchanged.
 *
 * Used by menu-new.php and menu-edit.php. Expects: $menuForm = ['key','name','items'], $isNew (bool),
 * $menuSources (MenuSources groups), $menuSubmitLabel (string) and $menuDeleteForm (form id of the delete form, or '').
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Menu\StudioMenus;

$items = StudioMenus::flatten($menuForm['items']);
$typeByUrl = [];
foreach ($menuSources as $group) {
    foreach ($group['items'] as $source) {
        $typeByUrl[$source['url']] ??= $group['singular'];
    }
}
$customType = __('studio_menu_type_custom', 'Custom link');
$sbMenuStrings = [
    'custom'        => $customType,
    'subItem'       => __('studio_menu_sub_item', 'sub item'),
    'added'         => __('studio_menu_added', '%d added to the menu.'),
    'removed'       => __('studio_menu_removed', '%d removed from the menu.'),
    'moved'         => __('studio_menu_moved', '%s moved.'),
    'full'          => sprintf(__('studio_menu_full', 'A menu holds up to %d links.'), StudioMenus::MAX_ITEMS),
    'labelRequired' => __('studio_menu_label_required', 'Give this item a label.'),
    'urlRequired'   => __('studio_menu_url_required', 'Enter an address.'),
    'unsaved'       => __('studio_menu_unsaved', 'You have changes that are not saved.'),
    'moveOut'       => __('studio_menu_move_out', 'Out from under %s'),
    'moveUnder'     => __('studio_menu_move_under', 'Under %s'),
    'select'        => __('studio_menu_select_item', 'Select %s'),
    'handle'        => __('studio_menu_handle', 'Move %s: drag it, or use the arrow keys'),
    'settings'      => __('studio_menu_item_settings', 'Settings of %s'),
];

/** One item of the structure list. `$i` is its index in the posted list (`__i__` in the template). */
$sbMenuRow = static function (array $item, string $i) use ($typeByUrl, $customType): void {
    $label = (string) ($item['label'] ?? '');
    $url   = (string) ($item['url'] ?? '');
    $depth = (int) ($item['depth'] ?? 0);
    $id    = 'sb-mi-' . $i;
    ?>
    <li class="sb-mi" data-testid="menu-item" data-depth="<?= $depth ?>">
        <input type="hidden" name="items[<?= e($i) ?>][depth]" value="<?= $depth ?>" data-depth-input>
        <div class="sb-mi__bar">
            <input type="checkbox" class="sb-mi__pick" data-pick aria-label="<?= e(sprintf(__('studio_menu_select_item', 'Select %s'), $label)) ?>">
            <button type="button" class="sb-mi__handle" data-handle aria-label="<?= e(sprintf(__('studio_menu_handle', 'Move %s: drag it, or use the arrow keys'), $label)) ?>"><?= sb_svg('grip', 16) ?></button>
            <span class="sb-mi__title"><span data-title><?= e($label) ?></span> <small class="sb-mi__sub" data-sub><?= e(__('studio_menu_sub_item', 'sub item')) ?></small></span>
            <span class="sb-mi__type" data-type><?= e($url === '' ? '' : ($typeByUrl[$url] ?? $customType)) ?></span>
            <button type="button" class="sb-mi__toggle" data-toggle aria-expanded="false" aria-controls="<?= e($id) ?>" aria-label="<?= e(sprintf(__('studio_menu_item_settings', 'Settings of %s'), $label)) ?>"><?= sb_svg('chevron-down', 16) ?></button>
        </div>
        <div class="sb-mi__panel" id="<?= e($id) ?>" hidden>
            <div class="sb-mi__fields">
                <label class="sb-field"><span class="sb-field__label"><?= e(__('studio_menu_nav_label', 'Navigation label')) ?></span>
                    <input type="text" name="items[<?= e($i) ?>][label]" maxlength="<?= StudioMenus::MAX_LABEL ?>" value="<?= e($label) ?>" data-field="label"></label>
                <label class="sb-field"><span class="sb-field__label"><?= e(__('studio_menu_url_label', 'Address')) ?></span>
                    <input type="text" name="items[<?= e($i) ?>][url]" maxlength="<?= StudioMenus::MAX_URL ?>" list="sb-menu-paths" placeholder="<?= e(__('studio_menu_url', '/about or https://…')) ?>" value="<?= e($url) ?>" data-field="url"></label>
                <label class="sb-field"><span class="sb-field__label"><?= e(__('studio_menu_css_class', 'CSS classes (optional)')) ?></span>
                    <input type="text" name="items[<?= e($i) ?>][class]" maxlength="<?= StudioMenus::MAX_CLASS ?>" value="<?= e((string) ($item['class'] ?? '')) ?>" data-field="class" spellcheck="false"></label>
                <label class="sb-mi__check"><input type="checkbox" name="items[<?= e($i) ?>][new_tab]" value="1"<?= !empty($item['new_tab']) ? ' checked' : '' ?> data-field="new_tab"> <span><?= e(__('studio_menu_open_new_tab', 'Open the link in a new tab')) ?></span></label>
            </div>
            <div class="sb-mi__move" data-move-links>
                <span><?= e(__('studio_menu_move', 'Move')) ?></span>
                <button type="button" class="sb-linkbtn" data-move="up"><?= e(__('studio_menu_move_up_one', 'Up one')) ?></button>
                <button type="button" class="sb-linkbtn" data-move="down"><?= e(__('studio_menu_move_down_one', 'Down one')) ?></button>
                <button type="button" class="sb-linkbtn" data-move="out"></button>
                <button type="button" class="sb-linkbtn" data-move="in"></button>
                <button type="button" class="sb-linkbtn" data-move="top"><?= e(__('studio_menu_move_top', 'To the top')) ?></button>
            </div>
            <div class="sb-mi__actions">
                <button type="button" class="sb-linkbtn sb-linkbtn--danger" data-remove><?= e(__('studio_menu_remove', 'Remove link')) ?></button>
                <span aria-hidden="true">|</span>
                <button type="button" class="sb-linkbtn" data-close><?= e(__('studio_menu_done', 'Done')) ?></button>
            </div>
        </div>
    </li>
    <?php
};
?>
<div class="sb-menu-editor" id="sb-menu-editor" data-max="<?= StudioMenus::MAX_ITEMS ?>" data-max-depth="<?= StudioMenus::MAX_DEPTH ?>">
    <section class="sb-menu-add" aria-labelledby="sb-menu-add-title">
        <h3 class="sb-menu-col-title" id="sb-menu-add-title"><?= e(__('studio_menu_add_items', 'Add menu items')) ?></h3>
        <div class="sb-menu-boxes">
            <?php foreach ($menuSources as $n => $group): $boxId = 'sb-menu-box-' . $n; ?>
            <div class="sb-menu-box" data-testid="menu-source" data-source="<?= e($group['key']) ?>">
                <h4 class="sb-menu-box__head"><button type="button" aria-expanded="<?= $n === 0 ? 'true' : 'false' ?>" aria-controls="<?= e($boxId) ?>" data-box-toggle><span><?= e($group['label']) ?></span><?= sb_svg('chevron-down', 16) ?></button></h4>
                <div class="sb-menu-box__body" id="<?= e($boxId) ?>"<?= $n === 0 ? '' : ' hidden' ?>>
                    <div class="sb-menu-tabs" role="tablist" aria-label="<?= e($group['label']) ?>">
                        <button type="button" role="tab" aria-selected="true" data-tab="recent"><?= e(__('studio_menu_tab_recent', 'Most recent')) ?></button>
                        <button type="button" role="tab" aria-selected="false" data-tab="all"><?= e(__('studio_menu_tab_all', 'View all')) ?></button>
                        <button type="button" role="tab" aria-selected="false" data-tab="search"><?= e(__('studio_menu_tab_search', 'Search')) ?></button>
                    </div>
                    <div class="sb-menu-box__panel" role="tabpanel">
                        <input type="search" class="sb-menu-box__search" data-search hidden aria-label="<?= e(sprintf(__('studio_menu_search_label', 'Search %s'), $group['label'])) ?>" placeholder="<?= e(__('studio_menu_tab_search', 'Search')) ?>">
                        <ul class="sb-menu-box__list" data-list>
                            <?php foreach ($group['items'] as $source): ?>
                            <li data-updated="<?= e($source['updated']) ?>"><label><input type="checkbox" data-title="<?= e($source['title']) ?>" data-url="<?= e($source['url']) ?>" data-type="<?= e($group['singular']) ?>"> <span><?= e($source['title']) ?></span></label></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="sb-menu-box__empty" data-empty<?= $group['items'] === [] ? '' : ' hidden' ?>><?= e($group['items'] === [] ? __('studio_menu_source_empty', 'Nothing published here yet.') : __('studio_menu_search_empty', 'No match.')) ?></p>
                    </div>
                    <div class="sb-menu-box__foot">
                        <label class="sb-mi__check"><input type="checkbox" data-select-all> <span><?= e(__('studio_menu_select_all', 'Select all')) ?></span></label>
                        <button type="button" class="btn btn-outline btn-sm" data-add-checked<?= $canEdit ? '' : ' disabled' ?>><?= e(__('studio_menu_add_to_menu', 'Add to menu')) ?></button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="sb-menu-box" data-testid="menu-source" data-source="custom">
                <h4 class="sb-menu-box__head"><button type="button" aria-expanded="<?= $menuSources === [] ? 'true' : 'false' ?>" aria-controls="sb-menu-box-custom" data-box-toggle><span><?= e(__('studio_menu_custom_links', 'Custom links')) ?></span><?= sb_svg('chevron-down', 16) ?></button></h4>
                <div class="sb-menu-box__body" id="sb-menu-box-custom"<?= $menuSources === [] ? '' : ' hidden' ?>>
                    <label class="sb-field"><span class="sb-field__label"><?= e(__('studio_menu_custom_url', 'URL')) ?></span>
                        <input type="text" id="sb-menu-custom-url" maxlength="<?= StudioMenus::MAX_URL ?>" list="sb-menu-paths" placeholder="https://"></label>
                    <label class="sb-field"><span class="sb-field__label"><?= e(__('studio_menu_custom_text', 'Link text')) ?></span>
                        <input type="text" id="sb-menu-custom-text" maxlength="<?= StudioMenus::MAX_LABEL ?>"></label>
                    <div class="sb-menu-box__foot">
                        <span></span>
                        <button type="button" class="btn btn-outline btn-sm" id="sb-menu-custom-add" data-testid="menu-add-link"<?= $canEdit ? '' : ' disabled' ?>><?= e(__('studio_menu_add_to_menu', 'Add to menu')) ?></button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="sb-menu-structure card" aria-labelledby="sb-menu-structure-title">
        <h3 class="sb-menu-col-title" id="sb-menu-structure-title"><?= e(__('studio_menu_structure', 'Menu structure')) ?></h3>
        <div class="sb-menu-structure__head">
            <div class="sb-form-grid">
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-menu-name"><?= e(__('studio_menu_name', 'Menu name')) ?> <span class="field-required">*</span></label>
                    <input type="text" id="sb-menu-name" name="name" required maxlength="<?= StudioMenus::MAX_NAME ?>" placeholder="<?= e(__('studio_menu_name_placeholder', 'Header menu')) ?>" value="<?= e($menuForm['name']) ?>">
                </div>
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-menu-key"><?= e(__('studio_menu_key', 'Key')) ?></label>
                    <input type="text" id="sb-menu-key" name="key" maxlength="63" pattern="[a-z0-9][a-z0-9\-]*" placeholder="header" value="<?= e($menuForm['key']) ?>">
                    <p class="sb-field__hint"><?= e(__('studio_menu_key_hint', 'Lowercase letters, numbers and hyphens. Leave blank to generate from the name.')) ?></p>
                </div>
            </div>
            <?php if ($isNew): ?>
            <div class="sb-suggest" role="group" aria-label="<?= e(__('studio_menu_suggested', 'Suggested menus')) ?>">
                <span class="sb-suggest__label"><?= e(__('studio_menu_suggested', 'Suggested menus')) ?>:</span>
                <?php foreach (['header' => __('studio_menu_header', 'Header menu'), 'footer' => __('studio_menu_footer', 'Footer menu'), 'services' => __('studio_menu_services', 'Services menu')] as $k => $label): ?>
                    <button type="button" class="btn btn-sm btn-ghost" data-suggest-key="<?= e($k) ?>" data-suggest-name="<?= e($label) ?>"><?= e($label) ?></button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="sb-menu-structure__body">
            <p class="sb-menu-hint"><?= e(__('studio_menu_drag_hint', 'Drag the items into the order you prefer. Drag an item to the right to put it in the submenu of the item above, up to two levels. Click the arrow on the right of an item to change its label, address and options.')) ?></p>
            <div class="sb-menu-bulk">
                <label class="sb-menu-bulk__toggle"><input type="checkbox" data-bulk-toggle data-testid="menu-bulk"> <span><?= e(__('studio_menu_bulk', 'Bulk select')) ?></span></label>
            </div>
            <datalist id="sb-menu-paths">
                <?php foreach (array_keys($typeByUrl) as $path): ?><option value="<?= e((string) $path) ?>"></option><?php endforeach; ?>
            </datalist>
            <ol class="sb-menu-items" id="sb-menu-items" data-testid="menu-items">
                <?php foreach ($items as $i => $item) { $sbMenuRow($item, (string) $i); } ?>
            </ol>
            <div class="sb-menu-empty" id="sb-menu-empty"<?= $items === [] ? '' : ' hidden' ?>>
                <strong><?= e(__('studio_menu_empty', 'No links yet')) ?></strong>
                <span><?= e(__('studio_menu_empty_hint', 'Tick items in "Add menu items" and choose Add to menu.')) ?></span>
            </div>
            <div class="sb-menu-bulk sb-menu-bulk--foot" id="sb-menu-bulk-foot" hidden>
                <label class="sb-menu-bulk__toggle"><input type="checkbox" data-bulk-all> <span><?= e(__('studio_menu_select_all', 'Select all')) ?></span></label>
                <button type="button" class="sb-linkbtn sb-linkbtn--danger" data-bulk-remove data-testid="menu-bulk-remove" disabled><?= e(__('studio_menu_remove_selected', 'Remove selected items')) ?></button>
            </div>
        </div>

        <?php if ($canEdit): ?>
        <div class="sb-menu-structure__foot">
            <button type="submit" class="btn btn-primary" data-testid="menu-save"><?= sb_svg('save', 14) ?> <span><?= e($menuSubmitLabel) ?></span></button>
            <?php if ($menuDeleteForm !== ''): ?>
                <button type="submit" form="<?= e($menuDeleteForm) ?>" class="sb-linkbtn sb-linkbtn--danger"><?= e(__('studio_menu_delete', 'Delete menu')) ?></button>
            <?php else: ?>
                <a href="menus.php" class="btn btn-ghost"><?= e(__('cancel', 'Cancel')) ?></a>
            <?php endif; ?>
            <span class="sb-menu-dirty" id="sb-menu-dirty" hidden><?= e(__('studio_menu_unsaved', 'You have changes that are not saved.')) ?></span>
        </div>
        <?php endif; ?>
    </section>
    <div class="sb-visually-hidden" role="status" aria-live="polite" id="sb-menu-live"></div>
</div>
<template id="sb-menu-row"><?php $sbMenuRow(['label' => '', 'url' => '', 'depth' => 0], '__i__'); ?></template>
<script type="application/json" id="sb-menu-strings"><?= json_encode($sbMenuStrings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<style>
    .sb-menu-editor { display: grid; grid-template-columns: minmax(260px, 340px) minmax(0, 1fr); gap: 28px; align-items: start; }
    .sb-menu-col-title { margin: 0 0 12px; font-size: 1.15rem; font-weight: 700; color: var(--sb-text); }
    .sb-visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    .sb-linkbtn { background: none; border: 0; padding: 0; font: inherit; font-size: 0.86rem; color: var(--sb-accent); text-decoration: underline; text-underline-offset: 2px; cursor: pointer; }
    .sb-linkbtn:disabled { color: var(--sb-muted); text-decoration: none; cursor: default; opacity: .6; }
    .sb-linkbtn--danger { color: #b91c1c; }
    .sb-linkbtn[hidden] { display: none; }

    /* Add menu items: one box per source */
    .sb-menu-boxes { background: #fff; border: 1px solid var(--sb-border); border-radius: 12px; overflow: hidden; }
    .sb-menu-box + .sb-menu-box { border-top: 1px solid var(--sb-border); }
    .sb-menu-box__head { margin: 0; }
    .sb-menu-box__head button { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 8px; padding: 14px 18px; background: #fff; border: 0; font: inherit; font-size: 0.95rem; font-weight: 700; color: var(--sb-text); cursor: pointer; text-align: left; }
    .sb-menu-box__head button:hover { background: #f8fafc; }
    .sb-menu-box__head svg { flex: none; color: var(--sb-muted); transition: transform .15s ease; }
    .sb-menu-box__head [aria-expanded="true"] { border-bottom: 1px solid var(--sb-border); }
    .sb-menu-box__head [aria-expanded="true"] svg { transform: rotate(180deg); }
    .sb-menu-box__body { padding: 14px 18px 16px; display: flex; flex-direction: column; gap: 12px; }
    .sb-menu-box__body[hidden] { display: none; }
    .sb-menu-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--sb-border); }
    .sb-menu-tabs button { padding: 7px 10px; margin-bottom: -1px; background: none; border: 1px solid transparent; border-radius: 6px 6px 0 0; font: inherit; font-size: 0.84rem; color: var(--sb-accent); cursor: pointer; }
    .sb-menu-tabs [aria-selected="true"] { color: var(--sb-text); border-color: var(--sb-border); border-bottom-color: #fff; background: #fff; font-weight: 600; }
    .sb-menu-box__panel { display: flex; flex-direction: column; gap: 8px; }
    .sb-menu-box__search { width: 100%; box-sizing: border-box; }
    .sb-menu-box__list { list-style: none; margin: 0; padding: 8px 10px; max-height: 220px; overflow: auto; border: 1px solid var(--sb-border); border-radius: 8px; background: #fbfcfe; }
    .sb-menu-box__list:empty { display: none; }
    .sb-menu-box__list li[hidden] { display: none; }
    .sb-menu-box__list label { display: flex; align-items: flex-start; gap: 8px; padding: 4px 2px; font-size: 0.88rem; color: var(--sb-text); cursor: pointer; }
    .sb-menu-box__list input { margin-top: 3px; }
    .sb-menu-box__empty { margin: 0; font-size: 0.84rem; color: var(--sb-muted); }
    .sb-menu-box__foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; }

    /* Menu structure */
    .sb-menu-structure { padding: 0; margin: 0; overflow: hidden; }
    .sb-menu-structure > .sb-menu-col-title { padding: 18px 22px 0; }
    .sb-menu-structure__head { padding: 6px 22px 18px; border-bottom: 1px solid var(--sb-border); background: #f8fafc; }
    .sb-menu-structure__body { padding: 18px 22px; }
    .sb-menu-structure__foot { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; padding: 16px 22px; border-top: 1px solid var(--sb-border); background: #f8fafc; }
    .sb-menu-dirty { font-size: 0.84rem; color: #b45309; }
    .sb-menu-hint { margin: 0 0 12px; font-size: 0.88rem; color: var(--sb-muted); max-width: 80ch; }
    .sb-menu-bulk { display: flex; align-items: center; gap: 16px; margin: 0 0 12px; }
    .sb-menu-bulk[hidden] { display: none; }
    .sb-menu-bulk--foot { margin: 12px 0 0; }
    .sb-menu-bulk__toggle, .sb-mi__check { display: inline-flex; align-items: center; gap: 8px; font-size: 0.86rem; color: var(--sb-text); cursor: pointer; }
    .sb-menu-bulk__toggle { padding: 6px 12px; border: 1px solid var(--sb-border); border-radius: 8px; background: #fff; }
    .sb-suggest { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin: 10px 0 0; font-size: 0.84rem; color: var(--sb-muted); }
    .sb-menu-empty { display: flex; flex-direction: column; gap: 4px; padding: 22px; border: 1px dashed var(--sb-border); border-radius: 10px; text-align: center; font-size: 0.88rem; color: var(--sb-muted); }
    .sb-menu-empty strong { color: var(--sb-text); }
    .sb-menu-empty[hidden] { display: none; }

    .sb-menu-items { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; max-width: 640px; }
    .sb-mi, .sb-mi-ph { --sb-mi-indent: 32px; margin-left: calc(var(--sb-mi-indent) * var(--sb-mi-depth, 0)); }
    .sb-mi[data-depth="1"], .sb-mi-ph[data-depth="1"] { --sb-mi-depth: 1; }
    .sb-mi[data-depth="2"], .sb-mi-ph[data-depth="2"] { --sb-mi-depth: 2; }
    .sb-mi { border: 1px solid #dbe1ea; border-radius: 8px; background: #f8fafc; }
    .sb-mi.is-open { background: #fff; box-shadow: 0 2px 10px rgba(15, 23, 42, .06); }
    .sb-mi__bar { display: flex; align-items: center; gap: 10px; min-height: 48px; padding: 0 8px 0 6px; }
    .sb-mi__pick { display: none; flex: none; }
    .sb-menu-items.is-bulk .sb-mi__pick { display: block; }
    .sb-mi__handle { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 32px; padding: 0; border: 0; border-radius: 6px; background: none; color: var(--sb-muted); cursor: grab; touch-action: none; }
    .sb-mi__handle:hover, .sb-mi__handle:focus-visible { background: #e9edf5; color: var(--sb-text); }
    .sb-mi__title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; font-size: 0.93rem; color: var(--sb-text); }
    .sb-mi__sub { font-weight: 400; color: var(--sb-muted); font-size: 0.8rem; }
    .sb-mi[data-depth="0"] .sb-mi__sub { display: none; }
    .sb-mi__type { flex: none; font-size: 0.84rem; color: var(--sb-muted); }
    .sb-mi__toggle { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border: 0; border-radius: 6px; background: none; color: var(--sb-text); cursor: pointer; }
    .sb-mi__toggle:hover { background: #e9edf5; }
    .sb-mi__toggle svg { transition: transform .15s ease; }
    .sb-mi__toggle[aria-expanded="true"] svg { transform: rotate(180deg); }
    .sb-mi__panel { padding: 14px 16px 16px; border-top: 1px solid #dbe1ea; display: flex; flex-direction: column; gap: 14px; }
    .sb-mi__panel[hidden] { display: none; }
    .sb-mi__fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 12px 16px; }
    .sb-mi__fields .sb-mi__check { grid-column: 1 / -1; }
    .sb-mi__move, .sb-mi__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; font-size: 0.86rem; color: var(--sb-muted); }
    .sb-mi.is-dragging { opacity: .35; }
    .sb-mi-ph { height: 46px; border: 2px dashed var(--sb-accent); border-radius: 8px; background: rgba(99, 102, 241, .06); box-sizing: border-box; }
    .sb-mi-ghost { position: fixed; z-index: 1000; pointer-events: none; width: min(560px, 80vw); box-shadow: 0 10px 30px rgba(15, 23, 42, .18); background: #fff; opacity: .95; }
    .sb-mi-ghost .sb-mi__pick { display: none; }
    body.sb-menu-dragging, body.sb-menu-dragging * { cursor: grabbing !important; user-select: none; }
    @media (max-width: 960px) { .sb-menu-editor { grid-template-columns: 1fr; } }
    @media (max-width: 560px) { .sb-mi, .sb-mi-ph { --sb-mi-indent: 18px; } .sb-mi__type { display: none; } }
</style>
<script>
(function () {
    var editor = document.getElementById('sb-menu-editor');
    var list = document.getElementById('sb-menu-items');
    if (!editor || !list) return;
    var form = editor.closest('form');
    var T = JSON.parse(document.getElementById('sb-menu-strings').textContent);
    var max = parseInt(editor.getAttribute('data-max'), 10) || 60;
    var maxDepth = parseInt(editor.getAttribute('data-max-depth'), 10) || 0;
    var live = document.getElementById('sb-menu-live');
    var dirty = false;
    var counter = 0;
    var typeByUrl = {};
    Array.prototype.forEach.call(editor.querySelectorAll('[data-list] input[data-url]'), function (c) {
        if (!(c.getAttribute('data-url') in typeByUrl)) typeByUrl[c.getAttribute('data-url')] = c.getAttribute('data-type');
    });

    function fmt(s, v) { return String(s).replace(/%[sd]/, v); }
    function say(msg) { live.textContent = ''; window.setTimeout(function () { live.textContent = msg; }, 30); }
    function rows() { return Array.prototype.filter.call(list.children, function (li) { return li.classList.contains('sb-mi'); }); }
    function depthOf(li) { return li ? parseInt(li.getAttribute('data-depth'), 10) || 0 : -1; }
    function setDepth(li, d) { li.setAttribute('data-depth', String(d)); li.querySelector('[data-depth-input]').value = String(d); }
    function field(li, name) { return li.querySelector('[data-field="' + name + '"]'); }
    function labelOf(li) { return field(li, 'label').value.trim(); }
    function markDirty() {
        dirty = true;
        var d = document.getElementById('sb-menu-dirty');
        if (d) d.hidden = false;
    }
    // A row and the rows under it (its submenu): they always move together.
    function block(li) {
        var out = [li], d = depthOf(li), n = li.nextElementSibling;
        while (n && n.classList.contains('sb-mi') && depthOf(n) > d) { out.push(n); n = n.nextElementSibling; }
        return out;
    }
    function span(b) { var d0 = depthOf(b[0]), s = 0; b.forEach(function (r) { s = Math.max(s, depthOf(r) - d0); }); return s; }
    function shift(b, delta) { b.forEach(function (r) { setDepth(r, Math.max(0, depthOf(r) + delta)); }); }
    function prevSibling(li) {
        var d = depthOf(li), p = li.previousElementSibling;
        while (p && depthOf(p) > d) p = p.previousElementSibling;
        return p && depthOf(p) === d ? p : null;
    }
    function nextSibling(li) {
        var b = block(li), n = b[b.length - 1].nextElementSibling;
        return n && depthOf(n) === depthOf(li) ? n : null;
    }
    function parentOf(li) {
        var d = depthOf(li), p = li.previousElementSibling;
        while (p && depthOf(p) >= d) p = p.previousElementSibling;
        return d > 0 ? p : null;
    }
    function snapshot() { return rows().map(function (r) { return r.getAttribute('data-uid') + ':' + depthOf(r); }).join(','); }
    function insertBlock(b, before) { b.forEach(function (r) { list.insertBefore(r, before); }); }

    function refresh() {
        // A row is never more than one level below the row above (the first is always at the top).
        var all = rows();
        all.forEach(function (li, i) {
            var cap = Math.min(maxDepth, depthOf(all[i - 1]) + 1);
            if (depthOf(li) > cap) setDepth(li, cap);
        });
        all.forEach(function (li, i) {
            if (!li.hasAttribute('data-uid')) li.setAttribute('data-uid', String(++counter));
            Array.prototype.forEach.call(li.querySelectorAll('[name^="items["]'), function (el) {
                el.name = el.name.replace(/^items\[[^\]]+\]/, 'items[' + i + ']');
            });
            var label = labelOf(li) || T.custom;
            li.querySelector('[data-title]').textContent = label;
            li.querySelector('[data-pick]').setAttribute('aria-label', fmt(T.select, label));
            li.querySelector('[data-handle]').setAttribute('aria-label', fmt(T.handle, label));
            li.querySelector('[data-toggle]').setAttribute('aria-label', fmt(T.settings, label));
            var url = field(li, 'url').value.trim();
            li.querySelector('[data-type]').textContent = url === '' ? '' : (typeByUrl[url] || T.custom);
            // The Move links say where the row would go, and only show when it can go there.
            var prev = prevSibling(li), parent = parentOf(li), b = block(li);
            var out = li.querySelector('[data-move="out"]'), under = li.querySelector('[data-move="in"]');
            li.querySelector('[data-move="up"]').hidden = !prev;
            li.querySelector('[data-move="down"]').hidden = !nextSibling(li);
            li.querySelector('[data-move="top"]').hidden = i === 0;
            out.hidden = !parent;
            if (parent) out.textContent = fmt(T.moveOut, labelOf(parent) || T.custom);
            under.hidden = !prev || depthOf(li) + span(b) >= maxDepth;
            if (prev) under.textContent = fmt(T.moveUnder, labelOf(prev) || T.custom);
        });
        document.getElementById('sb-menu-empty').hidden = all.length > 0;
        updateBulk();
    }

    function addRow(label, url, open) {
        if (rows().length >= max) { say(T.full); window.alert(T.full); return null; }
        var tpl = document.getElementById('sb-menu-row').content.firstElementChild.cloneNode(true);
        var id = 'sb-mi-new-' + (++counter);
        tpl.querySelector('.sb-mi__panel').id = id;
        tpl.querySelector('[data-toggle]').setAttribute('aria-controls', id);
        field(tpl, 'label').value = label;
        field(tpl, 'url').value = url;
        list.appendChild(tpl);
        if (open) toggle(tpl, true);
        return tpl;
    }
    function toggle(li, force) {
        var btn = li.querySelector('[data-toggle]');
        var open = force !== undefined ? force : btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        li.querySelector('.sb-mi__panel').hidden = !open;
        li.classList.toggle('is-open', open);
    }
    function removeRows(targets) {
        targets.forEach(function (li) {
            // Its submenu moves up one level rather than disappearing with it.
            shift(block(li).slice(1), -1);
            li.remove();
        });
        refresh();
        markDirty();
    }

    // ── Moves (the Move links, the handle's arrow keys and drag all end here) ──
    function move(li, how) {
        var b = block(li), d = depthOf(li), target;
        if (how === 'up' && (target = prevSibling(li))) insertBlock(b, target);
        else if (how === 'down' && (target = nextSibling(li))) { var tb = block(target); insertBlock(b, tb[tb.length - 1].nextElementSibling); }
        else if (how === 'in' && prevSibling(li) && d + span(b) < maxDepth) shift(b, 1);
        else if (how === 'out' && (target = parentOf(li))) { var pb = block(target); insertBlock(b, pb[pb.length - 1].nextElementSibling); shift(b, -1); }
        else if (how === 'top' && rows()[0] !== li) { insertBlock(b, rows()[0]); shift(b, -d); }
        else return false;
        refresh();
        markDirty();
        say(fmt(T.moved, labelOf(li) || T.custom));
        return true;
    }

    list.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var li = btn.closest('.sb-mi');
        if (btn.hasAttribute('data-toggle')) toggle(li);
        else if (btn.hasAttribute('data-close')) { toggle(li, false); li.querySelector('[data-toggle]').focus(); }
        else if (btn.hasAttribute('data-remove')) { removeRows([li]); say(fmt(T.removed, 1)); }
        else if (btn.hasAttribute('data-move')) move(li, btn.getAttribute('data-move'));
    });
    list.addEventListener('input', function (e) {
        if (e.target.hasAttribute('data-field')) { e.target.setCustomValidity(''); markDirty(); if (e.target.getAttribute('data-field') !== 'class') refresh(); }
    });
    list.addEventListener('change', function (e) {
        if (e.target.hasAttribute('data-pick')) updateBulk();
        else if (e.target.hasAttribute('data-field')) markDirty();
    });
    list.addEventListener('keydown', function (e) {
        if (!e.target.hasAttribute('data-handle')) return;
        var keys = { ArrowUp: 'up', ArrowDown: 'down', ArrowRight: 'in', ArrowLeft: 'out' };
        if (!keys[e.key]) return;
        e.preventDefault();
        var li = e.target.closest('.sb-mi');
        if (move(li, keys[e.key])) li.querySelector('[data-handle]').focus();
    });

    // ── Drag: the handle carries the row and its submenu; left/right while dragging sets the level ──
    var drag = null;
    list.addEventListener('pointerdown', function (e) {
        var h = e.target.closest('[data-handle]');
        if (!h || e.button !== 0) return;
        e.preventDefault();
        var li = h.closest('.sb-mi');
        var b = block(li);
        var rect = li.getBoundingClientRect();
        var ph = document.createElement('li');
        ph.className = 'sb-mi-ph';
        ph.setAttribute('aria-hidden', 'true');
        ph.setAttribute('data-depth', String(depthOf(li)));
        var ghost = li.cloneNode(true);
        ghost.classList.add('sb-mi-ghost');
        ghost.removeAttribute('data-testid');
        ghost.querySelector('.sb-mi__panel').remove();
        Array.prototype.forEach.call(ghost.querySelectorAll('[name], [id]'), function (el) { el.removeAttribute('name'); el.removeAttribute('id'); });
        ghost.style.left = rect.left + 'px';
        ghost.style.top = rect.top + 'px';
        document.body.appendChild(ghost);
        list.insertBefore(ph, li);
        b.forEach(function (r) { r.classList.add('is-dragging'); r.hidden = true; });
        drag = { li: li, block: b, span: span(b), d0: depthOf(li), x0: e.clientX, y0: e.clientY, top: rect.top, left: rect.left, ph: ph, ghost: ghost, home: b[b.length - 1].nextElementSibling, moved: false, handle: h, order: snapshot() };
        try { h.setPointerCapture(e.pointerId); } catch (err) { /* synthetic pointers */ }
        document.body.classList.add('sb-menu-dragging');
    });
    function onMove(e) {
        if (!drag) return;
        drag.moved = drag.moved || Math.abs(e.clientX - drag.x0) + Math.abs(e.clientY - drag.y0) > 3;
        drag.ghost.style.top = (drag.top + e.clientY - drag.y0) + 'px';
        drag.ghost.style.left = (drag.left + e.clientX - drag.x0) + 'px';
        // Where: before the first visible row whose middle is below the pointer.
        var before = null;
        rows().some(function (r) {
            if (r.hidden) return false;
            var rr = r.getBoundingClientRect();
            if (e.clientY < rr.top + rr.height / 2) { before = r; return true; }
            return false;
        });
        if (before !== drag.ph.nextElementSibling || (before === null && list.lastElementChild !== drag.ph)) {
            list.insertBefore(drag.ph, before);
        }
        // Level: one step per indent of horizontal travel, within what the row above allows, and never so shallow that
        // the row below (a submenu link of the row above) would end up in the dragged row's submenu.
        var above = drag.ph.previousElementSibling, below = drag.ph.nextElementSibling;
        while (above && above.hidden) above = above.previousElementSibling;
        while (below && below.hidden) below = below.nextElementSibling;
        var indent = parseFloat(getComputedStyle(drag.ph).getPropertyValue('--sb-mi-indent')) || 32;
        var want = drag.d0 + Math.round((e.clientX - drag.x0) / indent);
        var cap = Math.min(maxDepth - drag.span, above ? depthOf(above) + 1 : 0);
        var floor = below && below.classList.contains('sb-mi') ? Math.min(depthOf(below), cap) : 0;
        drag.ph.setAttribute('data-depth', String(Math.max(0, floor, Math.min(want, cap))));
    }
    function onUp(e, cancel) {
        if (!drag) return;
        var d = drag;
        drag = null;
        document.body.classList.remove('sb-menu-dragging');
        d.ghost.remove();
        d.block.forEach(function (r) { r.classList.remove('is-dragging'); r.hidden = false; });
        if (cancel || !d.moved) {
            insertBlock(d.block, d.home);
            d.ph.remove();
            refresh();
            d.handle.focus();
            return;
        }
        var target = parseInt(d.ph.getAttribute('data-depth'), 10) || 0;
        insertBlock(d.block, d.ph);
        d.ph.remove();
        shift(d.block, target - d.d0);
        refresh();
        if (snapshot() !== d.order) { markDirty(); say(fmt(T.moved, labelOf(d.li) || T.custom)); }
        d.handle.focus();
    }
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', function (e) { onUp(e, false); });
    document.addEventListener('pointercancel', function (e) { onUp(e, true); });
    document.addEventListener('keydown', function (e) { if (drag && e.key === 'Escape') onUp(e, true); });

    // ── Bulk select ──
    var bulkToggle = editor.querySelector('[data-bulk-toggle]');
    var bulkFoot = document.getElementById('sb-menu-bulk-foot');
    var bulkAll = editor.querySelector('[data-bulk-all]');
    var bulkRemove = editor.querySelector('[data-bulk-remove]');
    function picked() { return rows().filter(function (li) { return li.querySelector('[data-pick]').checked; }); }
    function updateBulk() {
        var on = bulkToggle.checked;
        list.classList.toggle('is-bulk', on);
        bulkFoot.hidden = !on;
        var n = picked().length;
        bulkRemove.disabled = n === 0;
        bulkAll.checked = n > 0 && n === rows().length;
        bulkAll.indeterminate = n > 0 && n < rows().length;
    }
    bulkToggle.addEventListener('change', function () {
        if (!bulkToggle.checked) rows().forEach(function (li) { li.querySelector('[data-pick]').checked = false; });
        updateBulk();
    });
    bulkAll.addEventListener('change', function () {
        rows().forEach(function (li) { li.querySelector('[data-pick]').checked = bulkAll.checked; });
        updateBulk();
    });
    bulkRemove.addEventListener('click', function () {
        var p = picked();
        if (p.length === 0) return;
        removeRows(p);
        say(fmt(T.removed, p.length));
    });

    // ── Add menu items ──
    Array.prototype.forEach.call(editor.querySelectorAll('[data-box-toggle]'), function (btn) {
        btn.addEventListener('click', function () {
            var open = btn.getAttribute('aria-expanded') !== 'true';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.getElementById(btn.getAttribute('aria-controls')).hidden = !open;
        });
    });
    Array.prototype.forEach.call(editor.querySelectorAll('.sb-menu-box[data-source]:not([data-source="custom"])'), function (box) {
        var ul = box.querySelector('[data-list]');
        var search = box.querySelector('[data-search]');
        var empty = box.querySelector('[data-empty]');
        var all = Array.prototype.slice.call(ul.children);
        var byTitle = all.slice();
        var byDate = all.slice().sort(function (a, b) { return (b.getAttribute('data-updated') || '').localeCompare(a.getAttribute('data-updated') || ''); });
        var tab = 'recent';
        function show() {
            var q = search.value.trim().toLowerCase();
            var order = tab === 'recent' ? byDate : byTitle;
            order.forEach(function (li) { ul.appendChild(li); });
            var shown = 0;
            order.forEach(function (li, i) {
                var hit = tab === 'all' || (tab === 'recent' && i < 10) || (tab === 'search' && q !== '' && li.textContent.toLowerCase().indexOf(q) !== -1);
                li.hidden = !hit;
                if (hit) shown++;
            });
            search.hidden = tab !== 'search';
            empty.hidden = all.length > 0 && (shown > 0 || (tab === 'search' && q === ''));
        }
        Array.prototype.forEach.call(box.querySelectorAll('[data-tab]'), function (t) {
            t.addEventListener('click', function () {
                tab = t.getAttribute('data-tab');
                Array.prototype.forEach.call(box.querySelectorAll('[data-tab]'), function (o) { o.setAttribute('aria-selected', o === t ? 'true' : 'false'); });
                show();
                if (tab === 'search') search.focus();
            });
        });
        search.addEventListener('input', show);
        search.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
        box.querySelector('[data-select-all]').addEventListener('change', function (e) {
            all.forEach(function (li) { if (!li.hidden) li.querySelector('input').checked = e.target.checked; });
        });
        box.querySelector('[data-add-checked]').addEventListener('click', function () {
            var n = 0;
            all.forEach(function (li) {
                var c = li.querySelector('input');
                if (c.checked) {
                    if (addRow(c.getAttribute('data-title'), c.getAttribute('data-url'), false)) n++;
                    c.checked = false;
                }
            });
            box.querySelector('[data-select-all]').checked = false;
            if (n > 0) { refresh(); markDirty(); say(fmt(T.added, n)); }
        });
        show();
    });
    var cUrl = document.getElementById('sb-menu-custom-url');
    var cText = document.getElementById('sb-menu-custom-text');
    function addCustom() {
        var url = cUrl.value.trim();
        if (url === '') { cUrl.setCustomValidity(T.urlRequired); cUrl.reportValidity(); return; }
        var row = addRow(cText.value.trim() || url, url, false);
        if (!row) return;
        cUrl.value = '';
        cText.value = '';
        refresh();
        markDirty();
        say(fmt(T.added, 1));
        cUrl.focus();
    }
    document.getElementById('sb-menu-custom-add').addEventListener('click', addCustom);
    [cUrl, cText].forEach(function (el) {
        el.addEventListener('input', function () { cUrl.setCustomValidity(''); });
        el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addCustom(); } });
    });

    // ── Save ──
    Array.prototype.forEach.call(editor.querySelectorAll('[data-suggest-key]'), function (b) {
        b.addEventListener('click', function () {
            document.getElementById('sb-menu-key').value = b.getAttribute('data-suggest-key');
            document.getElementById('sb-menu-name').value = b.getAttribute('data-suggest-name');
            markDirty();
        });
    });
    editor.querySelector('.sb-menu-structure__head').addEventListener('input', markDirty);
    if (form) {
        form.addEventListener('submit', function (e) {
            // Every item needs a label; one with nothing under it needs an address too (the server says so as well).
            var bad = null;
            rows().some(function (li, i) {
                var all = rows();
                var hasSub = all[i + 1] && depthOf(all[i + 1]) > depthOf(li);
                if (labelOf(li) === '') bad = [li, 'label', T.labelRequired];
                else if (field(li, 'url').value.trim() === '' && !hasSub) bad = [li, 'url', T.urlRequired];
                return bad !== null;
            });
            if (bad) {
                e.preventDefault();
                toggle(bad[0], true);
                var input = field(bad[0], bad[1]);
                input.setCustomValidity(bad[2]);
                input.reportValidity();
                return;
            }
            dirty = false;
        });
    }
    document.addEventListener('submit', function (e) {
        if (e.target.id === 'sb-menu-delete' && !e.defaultPrevented) dirty = false; // deleting asks first, once
    });
    window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = T.unsaved;
    });
    refresh();
})();
</script>
