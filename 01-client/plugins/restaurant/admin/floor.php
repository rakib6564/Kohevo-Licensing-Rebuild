<?php
/**
 * Restaurant — floor management: sections + tables on one page.
 *
 * Sections are managed inline at the top; tables are listed below with an
 * inline create/edit editor. A full drag-to-arrange floor map (pos_x/pos_y)
 * is a later enhancement — Phase 1 captures coordinates as plain numbers.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('restaurant.manage_floor');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = 'Floor & tables';
$currentNav = 'restaurant-floor';
$tid = current_tenant_id();

$flash    = null;
$editTbl  = isset($_GET['edit_table']) ? (int)$_GET['edit_table'] : 0;
$isNewTbl = !empty($_GET['new_table']);

$editingTable = null;
if ($editTbl > 0) {
    $editingTable = Database::row("SELECT * FROM restaurant_tables WHERE id = ? AND tenant_id = ?", [$editTbl, $tid]);
    if (!$editingTable) { http_response_code(404); $editTbl = 0; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save_section') {
            $name = trim((string)($_POST['name'] ?? ''));
            $sid  = (int)($_POST['id'] ?? 0);
            if ($name !== '') {
                $row = ['tenant_id' => $tid, 'name' => mb_substr($name, 0, 120),
                        'sort_order' => (int)($_POST['sort_order'] ?? 0), 'is_active' => 1];
                if ($sid > 0) Database::update('restaurant_sections', $row, 'id = ? AND tenant_id = ?', [$sid, $tid]);
                else          Database::insert('restaurant_sections', $row);
            }
            header('Location: ' . plugin_url('restaurant', 'admin/floor.php')); exit;
        } elseif ($action === 'delete_section') {
            $sid = (int)($_POST['id'] ?? 0);
            if ($sid > 0) {
                Database::query("UPDATE restaurant_tables SET section_id = NULL WHERE section_id = ? AND tenant_id = ?", [$sid, $tid]);
                Database::delete('restaurant_sections', 'id = ? AND tenant_id = ?', [$sid, $tid]);
            }
            header('Location: ' . plugin_url('restaurant', 'admin/floor.php')); exit;
        } elseif ($action === 'save_table') {
            $id    = (int)($_POST['id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            if ($label === '') {
                $flash = ['type' => 'error', 'msg' => 'Table label is required.'];
            } else {
                $row = [
                    'tenant_id'  => $tid,
                    'section_id' => !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null,
                    'label'      => mb_substr($label, 0, 40),
                    'seats'      => max(1, (int)($_POST['seats'] ?? 2)),
                    'pos_x'      => (int)($_POST['pos_x'] ?? 0),
                    'pos_y'      => (int)($_POST['pos_y'] ?? 0),
                    'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
                ];
                if ($id > 0) Database::update('restaurant_tables', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                else         Database::insert('restaurant_tables', $row);
                header('Location: ' . plugin_url('restaurant', 'admin/floor.php')); exit;
            }
        } elseif ($action === 'delete_table') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) Database::delete('restaurant_tables', 'id = ? AND tenant_id = ?', [$id, $tid]);
            header('Location: ' . plugin_url('restaurant', 'admin/floor.php')); exit;
        }
    }
}

$sections = Database::rows("SELECT * FROM restaurant_sections WHERE tenant_id = ? ORDER BY sort_order, name", [$tid]);
$tables   = Database::rows(
    "SELECT t.*, s.name AS section_name FROM restaurant_tables t
       LEFT JOIN restaurant_sections s ON s.id = t.section_id
      WHERE t.tenant_id = ? ORDER BY s.sort_order, t.label", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Floor & tables'],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNewTbl || $editingTable):
    $t = $editingTable ?: ['id'=>0,'section_id'=>null,'label'=>'','seats'=>2,'pos_x'=>0,'pos_y'=>0,'is_active'=>1];
?>
<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => 'New table']); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_table">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">

        <?php slate_edit_backlink(['back_href' => plugin_url('restaurant', 'admin/floor.php'), 'back_label' => 'Back to floor']); ?>

        <?php slate_edit_hero([
            'icon'         => 'box',
            'title'        => $editingTable ? ('Table ' . $editingTable['label']) : 'New table',
            'ref'          => $editingTable ? (int)$editingTable['id'] : null,
            'active'       => !empty($t['is_active']),
            'toggle_name'  => 'is_active', 'toggle_id' => 'is_active', 'toggle_title' => 'In service',
            'status_on'    => 'In service', 'status_off' => 'Out of service',
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'box', 'eyebrow' => 'General', 'title' => 'Table details']); ?>
            <div class="field-row field-row-3">
                <div class="field">
                    <label class="field-label" for="label">Label <span class="field-required">*</span></label>
                    <input type="text" id="label" name="label" required maxlength="40" value="<?= e($t['label']) ?>" placeholder="12">
                </div>
                <div class="field">
                    <label class="field-label" for="seats">Seats</label>
                    <input type="number" id="seats" name="seats" min="1" step="1" value="<?= (int)$t['seats'] ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="section_id">Section</label>
                    <select id="section_id" name="section_id">
                        <option value="">— None —</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= (int)($t['section_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field-row field-row-2">
                <div class="field"><label class="field-label" for="pos_x">Map X</label>
                    <input type="number" id="pos_x" name="pos_x" step="1" value="<?= (int)$t['pos_x'] ?>"></div>
                <div class="field" style="margin-bottom:0;"><label class="field-label" for="pos_y">Map Y</label>
                    <input type="number" id="pos_y" name="pos_y" step="1" value="<?= (int)$t['pos_y'] ?>"></div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editingTable ? 'Save table' : 'Create table') . '</button>'
              . '<a href="' . e(plugin_url('restaurant', 'admin/floor.php')) . '" class="btn btn-ghost">Cancel</a>',
        ]); ?>
    </form>
<?php slate_edit_close(); ?>
<?php slate_editor_js(); ?>

<?php else: ?>
    <div class="page-header">
        <div><h1>Floor &amp; tables</h1><p class="page-header-sub">Sections and tables for dine-in service.</p></div>
        <a href="?new_table=1" class="btn btn-primary">New table</a>
    </div>

    <div class="card">
        <div class="card-header"><h2>Sections</h2></div>
        <div class="card-body">
            <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_section">
                <div class="field" style="margin:0;flex:1;min-width:180px;">
                    <label class="field-label">Add a section</label>
                    <input type="text" name="name" maxlength="120" placeholder="Patio, Bar, Main…">
                </div>
                <div class="field" style="margin:0;width:120px;">
                    <label class="field-label">Sort</label>
                    <input type="number" name="sort_order" step="1" value="0">
                </div>
                <button class="btn btn-primary">Add</button>
            </form>
            <?php if (!$sections): ?>
                <p class="text-sm text-muted" style="margin:0;">No sections yet — tables can still exist without one.</p>
            <?php else: ?>
                <ul class="kv-list">
                    <?php foreach ($sections as $s):
                        $cnt = (int) Database::value("SELECT COUNT(*) FROM restaurant_tables WHERE section_id = ? AND tenant_id = ?", [(int)$s['id'], $tid]); ?>
                        <li class="kv-row">
                            <span class="kv-label"><strong style="color:var(--text);"><?= e($s['name']) ?></strong>
                                <span class="text-xs text-muted"><?= $cnt ?> table(s)</span></span>
                            <span class="kv-value">
                                <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Delete section? Its tables stay but lose the section.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete_section"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                    <button class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Tables (<?= count($tables) ?>)</h2><a href="?new_table=1" class="btn btn-sm">New table</a></div>
        <?php if (!$tables): ?>
            <div class="empty"><div class="empty-title">No tables yet</div><p class="text-sm">Add tables so servers can open dine-in checks.</p></div>
        <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($tables as $t):
                $statusBadge = ['open' => ['Open','active'], 'seated' => ['Seated','info'], 'dirty' => ['Needs bussing','warning']][$t['status']] ?? ['—','muted'];
                $actions = '<a href="?edit_table=' . (int)$t['id'] . '" class="btn btn-sm">Edit</a> '
                         . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'Delete this table?\')">'
                         . csrf_field()
                         . '<input type="hidden" name="_action" value="delete_table"><input type="hidden" name="id" value="' . (int)$t['id'] . '">'
                         . '<button class="btn btn-sm btn-danger">Delete</button></form>';
                slate_data_row([
                    'avatar'       => mb_substr($t['label'], 0, 2),
                    'avatar_color' => $t['is_active'] ? 'info' : 'muted',
                    'title'        => 'Table ' . $t['label'],
                    'meta'         => ($t['section_name'] ?: 'No section') . ' · ' . (int)$t['seats'] . ' seats',
                    'badge'        => $statusBadge,
                    'detail'       => ['Section' => $t['section_name'] ?: '—', 'Seats' => (int)$t['seats'],
                                       'Status' => ucfirst($t['status']), 'Map' => '(' . (int)$t['pos_x'] . ', ' . (int)$t['pos_y'] . ')'],
                    'actions'      => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
