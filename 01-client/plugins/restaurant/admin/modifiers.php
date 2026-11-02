<?php
/**
 * Restaurant — modifier groups + their options (list + editor).
 *
 * A group ("Choose a side") holds N options ("Fries", "Salad +$1.50"). Options
 * are edited inline as repeating rows and synced on save: existing rows are
 * updated, removed rows deleted, new rows inserted.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('restaurant.manage_menu');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = 'Modifier groups';
$currentNav = 'restaurant-modifiers';
$tid = current_tenant_id();

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM restaurant_modifier_groups WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
    if (!$editing) { http_response_code(404); $editId = 0; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => 'Group name is required.'];
            } else {
                $row = [
                    'tenant_id'   => $tid,
                    'name'        => mb_substr($name, 0, 160),
                    'min_select'  => max(0, (int)($_POST['min_select'] ?? 0)),
                    'max_select'  => max(0, (int)($_POST['max_select'] ?? 1)),
                    'is_required' => !empty($_POST['is_required']) ? 1 : 0,
                    'sort_order'  => (int)($_POST['sort_order'] ?? 0),
                ];
                if ($id > 0) {
                    Database::update('restaurant_modifier_groups', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                } else {
                    $id = Database::insert('restaurant_modifier_groups', $row);
                }

                // Sync options.
                $optIds    = array_map('intval', (array)($_POST['opt_id'] ?? []));
                $optNames  = (array)($_POST['opt_name'] ?? []);
                $optPrices = (array)($_POST['opt_price'] ?? []);
                $keptIds   = [];
                foreach ($optNames as $i => $oName) {
                    $oName = trim((string)$oName);
                    if ($oName === '') continue;
                    $priceCents = (int) round(((float)($optPrices[$i] ?? 0)) * 100);
                    $oid = (int)($optIds[$i] ?? 0);
                    $optRow = [
                        'tenant_id'         => $tid,
                        'group_id'          => $id,
                        'name'              => mb_substr($oName, 0, 160),
                        'price_delta_cents' => $priceCents,
                        'is_active'         => 1,
                        'sort_order'        => $i,
                    ];
                    if ($oid > 0) {
                        Database::update('restaurant_modifiers', $optRow, 'id = ? AND tenant_id = ?', [$oid, $tid]);
                        $keptIds[] = $oid;
                    } else {
                        $keptIds[] = Database::insert('restaurant_modifiers', $optRow);
                    }
                }
                // Delete options that were removed in the form.
                $existing = Database::rows("SELECT id FROM restaurant_modifiers WHERE group_id = ? AND tenant_id = ?", [$id, $tid]);
                foreach ($existing as $ex) {
                    if (!in_array((int)$ex['id'], $keptIds, true)) {
                        Database::delete('restaurant_modifiers', 'id = ? AND tenant_id = ?', [(int)$ex['id'], $tid]);
                    }
                }
                AuditLog::record('restaurant.modifier_group_saved', (string)$id);
                header('Location: ' . plugin_url('restaurant', 'admin/modifiers.php'));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::delete('restaurant_modifiers', 'group_id = ? AND tenant_id = ?', [$id, $tid]);
                Database::delete('restaurant_item_modifier_groups', 'group_id = ? AND tenant_id = ?', [$id, $tid]);
                Database::delete('restaurant_modifier_groups', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('restaurant.modifier_group_deleted', (string)$id);
                header('Location: ' . plugin_url('restaurant', 'admin/modifiers.php'));
                exit;
            }
        }
    }
}

$groups = Database::rows(
    "SELECT g.*, (SELECT COUNT(*) FROM restaurant_modifiers m WHERE m.group_id = g.id) AS opt_count
       FROM restaurant_modifier_groups g WHERE g.tenant_id = ? ORDER BY g.sort_order, g.name", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Modifier groups'],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $g = $editing ?: ['id'=>0,'name'=>'','min_select'=>0,'max_select'=>1,'is_required'=>0,'sort_order'=>0];
    $options = $editing
        ? Database::rows("SELECT * FROM restaurant_modifiers WHERE group_id = ? AND tenant_id = ? ORDER BY sort_order, name", [(int)$editing['id'], $tid])
        : [];
?>
<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => 'New modifier group']); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">

        <?php slate_edit_backlink(['back_href' => plugin_url('restaurant', 'admin/modifiers.php'), 'back_label' => 'All modifier groups']); ?>

        <?php slate_edit_hero([
            'icon'        => 'list',
            'title'       => $editing ? $editing['name'] : 'New modifier group',
            'ref'         => $editing ? (int)$editing['id'] : null,
            'status_text' => $g['is_required'] ? 'Required' : 'Optional',
            'status_tone' => $g['is_required'] ? 'warn' : 'off',
            'stats'       => $editing ? [
                ['Options', (string)count($options)],
                ['Min', (string)(int)$g['min_select']],
                ['Max', (string)(int)$g['max_select']],
            ] : [],
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'list', 'eyebrow' => 'General', 'title' => 'Group rules']); ?>
            <div class="field">
                <label class="field-label" for="name">Group name <span class="field-required">*</span></label>
                <input type="text" id="name" name="name" required maxlength="160" value="<?= e($g['name']) ?>" placeholder="Choose a side">
            </div>
            <div class="field-row field-row-3">
                <div class="field">
                    <label class="field-label" for="min_select">Min select</label>
                    <input type="number" id="min_select" name="min_select" min="0" step="1" value="<?= (int)$g['min_select'] ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="max_select">Max select <span class="field-hint">(0 = unlimited)</span></label>
                    <input type="number" id="max_select" name="max_select" min="0" step="1" value="<?= (int)$g['max_select'] ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" step="1" value="<?= (int)$g['sort_order'] ?>">
                </div>
            </div>
            <label class="field-check" style="display:flex;align-items:center;gap:8px;margin-top:4px;">
                <input type="checkbox" name="is_required" value="1" <?= !empty($g['is_required']) ? 'checked' : '' ?>>
                <span>Required — the guest must choose before the item can be sent</span>
            </label>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'tag', 'eyebrow' => 'Options', 'title' => 'Modifier options',
            'aside_html' => '<button type="button" class="btn btn-sm" id="addOpt">+ Add option</button>']); ?>
            <?php slate_edit_card_note('Price delta applies on top of the item price. Use negative numbers for discounts (e.g. -1.00).'); ?>
            <div id="optList">
                <?php
                $render = function ($o) {
                    $oid   = (int)($o['id'] ?? 0);
                    $name  = e((string)($o['name'] ?? ''));
                    $price = number_format((int)($o['price_delta_cents'] ?? 0) / 100, 2);
                    return '<div class="opt-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">'
                         . '<input type="hidden" name="opt_id[]" value="' . $oid . '">'
                         . '<input type="text" name="opt_name[]" placeholder="Option name" value="' . $name . '" style="flex:1;">'
                         . '<input type="number" name="opt_price[]" step="0.01" placeholder="0.00" value="' . $price . '" style="width:110px;">'
                         . '<button type="button" class="btn btn-sm btn-danger optDel">×</button></div>';
                };
                if ($options) { foreach ($options as $o) echo $render($o); }
                ?>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? 'Save group' : 'Create group') . '</button>'
              . '<a href="' . e(plugin_url('restaurant', 'admin/modifiers.php')) . '" class="btn btn-ghost">Cancel</a>',
        ]); ?>
    </form>
<?php slate_edit_close(); ?>
<?php slate_editor_js(); ?>
<script>
(function () {
    var list = document.getElementById('optList');
    var tmpl = '<div class="opt-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">'
        + '<input type="hidden" name="opt_id[]" value="0">'
        + '<input type="text" name="opt_name[]" placeholder="Option name" style="flex:1;">'
        + '<input type="number" name="opt_price[]" step="0.01" placeholder="0.00" value="0.00" style="width:110px;">'
        + '<button type="button" class="btn btn-sm btn-danger optDel">&times;</button></div>';
    document.getElementById('addOpt').addEventListener('click', function () {
        list.insertAdjacentHTML('beforeend', tmpl);
    });
    list.addEventListener('click', function (e) {
        if (e.target.classList.contains('optDel')) e.target.closest('.opt-row').remove();
    });
})();
</script>

<?php else: ?>
    <div class="page-header">
        <div><h1>Modifier groups</h1><p class="page-header-sub">Reusable option sets you attach to menu items.</p></div>
        <a href="?new=1" class="btn btn-primary">New group</a>
    </div>

    <?php if (!$groups): ?>
    <div class="card"><div class="empty"><div class="empty-title">No modifier groups yet</div><p class="text-sm">Create a group like "Choose a side", then attach it to items.</p></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($groups as $g):
            $rule = ($g['is_required'] ? 'Required · ' : '') . 'min ' . (int)$g['min_select'] . ' / max ' . ((int)$g['max_select'] ?: '∞');
            $actions = '<a href="?edit=' . (int)$g['id'] . '" class="btn btn-sm">Edit</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'Delete this group and its options?\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$g['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">Delete</button></form>';
            slate_data_row([
                'avatar'       => mb_substr($g['name'], 0, 1),
                'avatar_color' => $g['is_required'] ? 'warning' : 'info',
                'title'        => $g['name'],
                'meta'         => $g['opt_count'] . ' option(s) · ' . $rule,
                'badge'        => $g['is_required'] ? ['Required', 'warning'] : ['Optional', 'inactive'],
                'detail'       => ['Options' => $g['opt_count'], 'Min' => (int)$g['min_select'],
                                   'Max' => ((int)$g['max_select'] ?: 'Unlimited')],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
