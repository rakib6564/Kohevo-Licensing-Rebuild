<?php
/**
 * Restaurant — combos (list + editor).
 *
 * A combo is a menu item sold at its own price with slots to fill ("2
 * sides", "a drink"), each a choice of other menu items with an optional
 * price delta. The editor picks the base item, then edits slots and their
 * choices; RestaurantAPI::saveCombo() validates and replaces them in one
 * transaction. Removing every slot turns the combo back into a plain item.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('restaurant.manage_menu');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = __('restaurant_combos', 'Combos');
$currentNav = 'restaurant-combos';
$tid  = current_tenant_id();
$self = plugin_url('restaurant', 'admin/combos.php');

$flash  = null;
$editId = (int)($_GET['edit'] ?? 0);
$isNew  = !empty($_GET['new']);
$posted = null;   // slots as submitted, re-shown when a save is refused

$items = Database::rows(
    "SELECT i.id, i.name, i.price_cents, i.is_active, i.is_combo, i.is_86, c.name AS category
       FROM restaurant_items i LEFT JOIN restaurant_menu_categories c ON c.id = i.category_id
      WHERE i.tenant_id = ? ORDER BY c.sort_order, c.name, i.sort_order, i.name", [$tid]);
$byId = array_column($items, null, 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('restaurant_err_csrf', 'Security check failed. Reload the page and try again.')];
    } else {
        $id = (int)($_POST['item_id'] ?? 0);
        $slots = [];
        foreach ((array)($_POST['slot'] ?? []) as $sl) {
            $choices = [];
            foreach ((array)($sl['item'] ?? []) as $k => $iid) {
                $choices[] = ['item_id' => (int)$iid,
                              'price_delta_cents' => (int) round((float) str_replace(',', '.', (string)($sl['delta'][$k] ?? '0')) * 100)];
            }
            $slots[] = ['label' => (string)($sl['label'] ?? ''), 'min_pick' => (int)($sl['min'] ?? 1),
                        'max_pick' => (int)($sl['max'] ?? 1), 'choices' => $choices];
        }
        if (($_POST['_action'] ?? '') === 'remove') $slots = [];
        try {
            if (!isset($byId[$id])) throw new \RuntimeException(__('restaurant_combo_err_base', 'Choose the menu item to sell as a combo.'));
            if (($_POST['_action'] ?? '') !== 'remove' && !$slots) throw new \RuntimeException(__('restaurant_combo_err_noslots', 'Add at least one slot.'));
            RestaurantAPI::saveCombo($id, $slots);
            header('Location: ' . $self . '?saved=' . (($_POST['_action'] ?? '') === 'remove' ? 'removed' : '1'));
            exit;
        } catch (\RuntimeException $e) {
            $flash  = ['type' => 'error', 'msg' => $e->getMessage()];
            $posted = $slots;
            $editId = $id;
            $isNew  = $id === 0 || empty($byId[$id]['is_combo']);
        }
    }
}
if (($_GET['saved'] ?? '') === '1')       $flash = ['type' => 'success', 'msg' => __('restaurant_combo_saved', 'Combo saved.')];
if (($_GET['saved'] ?? '') === 'removed') $flash = ['type' => 'success', 'msg' => __('restaurant_combo_removed', 'That item is a plain menu item again.')];

$editing = $editId > 0 && isset($byId[$editId]) ? $byId[$editId] : null;
if ($editId > 0 && !$editing) http_response_code(404);

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => __('restaurant_combos', 'Combos')],
]);
if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif;

// The item options a slot can offer: active items that are not combos.
$choiceOptions = function (int $selected, int $baseId) use ($items): string {
    $html = '<option value="">' . e(__('restaurant_combo_pick_item', 'Choose an item…')) . '</option>';
    $group = null;
    foreach ($items as $it) {
        $ok = !empty($it['is_active']) && empty($it['is_combo']) && (int)$it['id'] !== $baseId;
        if (!$ok && (int)$it['id'] !== $selected) continue;
        $cat = (string)($it['category'] ?? __('restaurant_rep_no_category', 'No category'));
        if ($cat !== $group) { $html .= ($group !== null ? '</optgroup>' : '') . '<optgroup label="' . e($cat) . '">'; $group = $cat; }
        $html .= '<option value="' . (int)$it['id'] . '"' . ((int)$it['id'] === $selected ? ' selected' : '') . '>'
               . e($it['name']) . ' · ' . e(RestaurantAPI::money((int)$it['price_cents'])) . '</option>';
    }
    return $html . ($group !== null ? '</optgroup>' : '');
};

if ($isNew || $editing):
    $baseId = $editing ? (int)$editing['id'] : 0;
    $slots  = $posted ?? array_map(fn($s) => ['label' => $s['label'], 'min_pick' => (int)$s['min_pick'], 'max_pick' => (int)$s['max_pick'],
        'choices' => array_map(fn($c) => ['item_id' => (int)$c['item_id'], 'price_delta_cents' => (int)$c['price_delta_cents']], $s['choices'])],
        $editing ? RestaurantAPI::getComboSlots($baseId) : []);
    if (!$slots) $slots = [['label' => '', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [['item_id' => 0, 'price_delta_cents' => 0]]]];
    $usedAsChoice = array_map('intval', array_column(Database::rows("SELECT DISTINCT item_id FROM restaurant_combo_choices WHERE tenant_id = ?", [$tid]), 'item_id'));

    $slotHtml = function (int $n, array $sl) use ($choiceOptions, $baseId): string {
        $rows = '';
        foreach ($sl['choices'] as $c) {
            $rows .= '<div class="cb-choice"><select name="slot[' . $n . '][item][]">' . $choiceOptions((int)$c['item_id'], $baseId) . '</select>'
                   . '<label class="cb-delta"><span>' . e(__('restaurant_combo_delta', 'Extra')) . '</span><input type="number" step="0.01" name="slot[' . $n . '][delta][]" value="'
                   . e(number_format((int)$c['price_delta_cents'] / 100, 2, '.', '')) . '"></label>'
                   . '<button type="button" class="btn btn-sm btn-ghost" data-del-choice aria-label="' . e(__('restaurant_combo_remove_choice', 'Remove choice')) . '">&times;</button></div>';
        }
        return '<fieldset class="cb-slot" data-slot="' . $n . '">'
             . '<div class="field-row field-row-3">'
             . '<div class="field"><label class="field-label">' . e(__('restaurant_combo_slot_label', 'Slot name')) . ' <span class="field-required">*</span></label>'
             . '<input type="text" name="slot[' . $n . '][label]" maxlength="120" value="' . e($sl['label']) . '" placeholder="' . e(__('restaurant_combo_slot_ph', 'Sides')) . '"></div>'
             . '<div class="field"><label class="field-label">' . e(__('restaurant_combo_min', 'Pick at least')) . '</label><input type="number" min="0" step="1" name="slot[' . $n . '][min]" value="' . (int)$sl['min_pick'] . '"></div>'
             . '<div class="field"><label class="field-label">' . e(__('restaurant_combo_max', 'Pick at most')) . '</label><input type="number" min="1" step="1" name="slot[' . $n . '][max]" value="' . (int)$sl['max_pick'] . '"></div>'
             . '</div><div class="cb-choices">' . $rows . '</div>'
             . '<div class="cb-slot-foot"><button type="button" class="btn btn-sm" data-add-choice>' . e(__('restaurant_combo_add_choice', '+ Add a choice')) . '</button>'
             . '<button type="button" class="btn btn-sm btn-ghost" data-del-slot>' . e(__('restaurant_combo_remove_slot', 'Remove slot')) . '</button></div></fieldset>';
    };
    slate_editor_css(); ?>
<style>
.cb-slot { border: 1px solid var(--border); border-radius: var(--radius-lg, 12px); padding: 14px 16px 10px; margin: 0 0 14px; }
.cb-choices { display: flex; flex-direction: column; gap: 8px; margin: 4px 0 10px; }
.cb-choice { display: flex; gap: 8px; align-items: center; }
.cb-choice select { flex: 1; min-width: 0; }
.cb-delta { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-2); }
.cb-delta input { width: 96px; }
.cb-slot-foot { display: flex; justify-content: space-between; gap: 8px; }
@media (max-width: 560px) { .cb-choice { flex-wrap: wrap; } .cb-choice select { flex-basis: 100%; } }
</style>
<?php slate_edit_open(['title_fallback' => __('restaurant_combo_new', 'New combo')]); ?>
    <form method="post" id="cbForm">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <?php slate_edit_backlink(['back_href' => $self, 'back_label' => __('restaurant_combo_all', 'All combos')]); ?>
        <?php slate_edit_hero([
            'icon' => 'box', 'title' => $editing ? $editing['name'] : __('restaurant_combo_new', 'New combo'),
            'ref' => $editing ? (int)$editing['id'] : null,
            'status_text' => __('restaurant_combo', 'Combo'), 'status_tone' => 'on',
            'stats' => $editing ? [[__('restaurant_combo_price', 'Combo price'), RestaurantAPI::money((int)$editing['price_cents'])],
                                   [__('restaurant_combo_slots', 'Slots'), (string)count($slots)]] : [],
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'tag', 'eyebrow' => __('restaurant_combo_base_eyebrow', 'Base item'), 'title' => __('restaurant_combo_base', 'What the guest orders')]); ?>
            <?php if ($editing): ?>
                <input type="hidden" name="item_id" value="<?= (int)$editing['id'] ?>">
                <p><?= e(sprintf(__('restaurant_combo_base_note', '%1$s sells at %2$s. Change its name, price or tax on the menu item.'), $editing['name'], RestaurantAPI::money((int)$editing['price_cents']))) ?>
                   <a href="<?= e(plugin_url('restaurant', 'admin/items.php') . '?edit=' . (int)$editing['id']) ?>"><?= e(__('restaurant_combo_edit_item', 'Edit the item')) ?></a></p>
            <?php else: ?>
                <div class="field">
                    <label class="field-label" for="item_id"><?= e(__('restaurant_combo_base_pick', 'Menu item to sell as a combo')) ?> <span class="field-required">*</span></label>
                    <select id="item_id" name="item_id" required>
                        <option value=""><?= e(__('restaurant_combo_pick_item', 'Choose an item…')) ?></option>
                        <?php foreach ($items as $it): if (!empty($it['is_combo']) || in_array((int)$it['id'], $usedAsChoice, true)) continue; ?>
                            <option value="<?= (int)$it['id'] ?>"<?= (int)($_POST['item_id'] ?? 0) === (int)$it['id'] ? ' selected' : '' ?>><?= e($it['name']) ?> · <?= e(RestaurantAPI::money((int)$it['price_cents'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint"><?= e(__('restaurant_combo_base_hint', 'Create the item first under Menu items (name, combo price, tax, photo). Items offered inside a combo are not listed.')) ?></p>
                </div>
            <?php endif; ?>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'list', 'eyebrow' => __('restaurant_combo_slots', 'Slots'), 'title' => __('restaurant_combo_slots_title', 'What the guest picks'),
            'aside_html' => '<button type="button" class="btn btn-sm" id="cbAddSlot">' . e(__('restaurant_combo_add_slot', '+ Add a slot')) . '</button>']); ?>
            <?php slate_edit_card_note(__('restaurant_combo_slots_note', 'Each slot is a choice of menu items, like "2 sides" or "a drink". The combo price covers the picks; "Extra" adds to it for a premium pick (e.g. 1.00). The POS and the online menu both make the guest pick at least and at most the numbers set here.')); ?>
            <div id="cbSlots"><?php foreach (array_values($slots) as $n => $sl) echo $slotHtml($n, $sl); ?></div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar(['buttons_html' =>
            '<button type="submit" class="btn btn-primary">' . e(__('restaurant_combo_save', 'Save combo')) . '</button>'
          . ($editing ? '<button type="submit" class="btn btn-ghost" name="_action" value="remove" formnovalidate data-confirm="' . e(__('restaurant_combo_remove_confirm', 'Remove the slots and sell this as a plain item?')) . '" onclick="return confirm(this.dataset.confirm)">' . e(__('restaurant_combo_remove', 'Stop selling as a combo')) . '</button>' : '')
          . '<a href="' . e($self) . '" class="btn btn-ghost">' . e(__('restaurant_cancel', 'Cancel')) . '</a>']); ?>
    </form>
<?php slate_edit_close(); ?>
<?php slate_editor_js(); ?>
<template id="cbSlotTpl"><?= $slotHtml(999, ['label' => '', 'min_pick' => 1, 'max_pick' => 1, 'choices' => [['item_id' => 0, 'price_delta_cents' => 0]]]) ?></template>
<script>
(function () {
    var box = document.getElementById('cbSlots'), tpl = document.getElementById('cbSlotTpl').innerHTML;
    var next = box.querySelectorAll('.cb-slot').length;
    document.getElementById('cbAddSlot').addEventListener('click', function () {
        box.insertAdjacentHTML('beforeend', tpl.replace(/slot\[999\]/g, 'slot[' + (next++) + ']'));
    });
    box.addEventListener('click', function (e) {
        var slot = e.target.closest('.cb-slot');
        if (e.target.closest('[data-add-choice]')) {
            var first = slot.querySelector('.cb-choice');
            var copy = first.cloneNode(true);
            copy.querySelector('select').value = ''; copy.querySelector('input').value = '0.00';
            slot.querySelector('.cb-choices').appendChild(copy);
        } else if (e.target.closest('[data-del-choice]')) {
            if (slot.querySelectorAll('.cb-choice').length > 1) e.target.closest('.cb-choice').remove();
        } else if (e.target.closest('[data-del-slot]')) {
            slot.remove();
        }
    });
})();
</script>

<?php else:
    $combos = array_values(array_filter($items, fn($it) => !empty($it['is_combo']))); ?>
    <div class="page-header">
        <div><h1><?= e(__('restaurant_combos', 'Combos')) ?></h1>
            <p class="page-header-sub"><?= e(__('restaurant_combos_sub', 'Priced bundles like "Plate + 2 sides + drink", sold in the POS and online.')) ?></p></div>
        <a href="?new=1" class="btn btn-primary"><?= e(__('restaurant_combo_new', 'New combo')) ?></a>
    </div>
    <?php if (!$combos): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= e(__('restaurant_combos_none', 'No combos yet')) ?></div>
            <p class="text-sm"><?= e(__('restaurant_combos_none_hint', 'Create the combo as a menu item with its price, then add the slots the guest picks from here.')) ?></p></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($combos as $it):
            $sl = RestaurantAPI::getComboSlots((int)$it['id']);
            $detail = [];
            $short = false;
            foreach ($sl as $s) {
                $live = array_filter($s['choices'], fn($c) => !empty($c['is_active']));
                if (count($live) < (int)$s['min_pick']) $short = true;
                $detail[$s['label'] . ' (' . ((int)$s['min_pick'] === (int)$s['max_pick'] ? (int)$s['max_pick'] : (int)$s['min_pick'] . '–' . (int)$s['max_pick']) . ')']
                    = implode(', ', array_map(fn($c) => $c['name'] . ((int)$c['price_delta_cents'] ? ' +' . RestaurantAPI::money((int)$c['price_delta_cents']) : '')
                        . (empty($c['is_active']) ? ' (' . __('restaurant_combo_inactive', 'hidden') . ')' : ''), $s['choices'])) ?: '—';
            }
            slate_data_row([
                'avatar' => mb_substr($it['name'], 0, 2), 'avatar_color' => $short ? 'warning' : 'info',
                'title'  => $it['name'],
                'meta'   => RestaurantAPI::money((int)$it['price_cents']) . ' · ' . implode(' + ', array_column($sl, 'label')),
                'badge'  => $short ? [__('restaurant_combo_short', 'Needs choices'), 'warning'] : (!empty($it['is_86']) ? ['86', 'danger'] : null),
                'detail' => $detail,
                'actions'=> '<a href="?edit=' . (int)$it['id'] . '" class="btn btn-sm">' . e(__('restaurant_edit', 'Edit')) . '</a>',
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
