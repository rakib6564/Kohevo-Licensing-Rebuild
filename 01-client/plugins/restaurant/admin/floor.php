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
        } elseif ($action === 'table_status') {
            RestaurantAPI::setTableStatus((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''));
            header('Location: ' . plugin_url('restaurant', 'admin/floor.php')); exit;
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
    $t = $editingTable ?: ['id'=>0,'section_id'=>((int)($_GET['section'] ?? 0) ?: null),'label'=>'','seats'=>2,'pos_x'=>0,'pos_y'=>0,'is_active'=>1];
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

<?php else:
    $open = Database::rows(
        "SELECT table_id, COUNT(*) AS n, SUM(total_cents) AS total, MIN(opened_at) AS since
           FROM restaurant_orders WHERE tenant_id = ? AND status IN ('open','sent') AND table_id IS NOT NULL
       GROUP BY table_id", [$tid]);
    $openBy = [];
    foreach ($open as $o) $openBy[(int)$o['table_id']] = $o;
    $counts = ['open' => 0, 'seated' => 0, 'dirty' => 0];
    $seats  = 0;
    foreach ($tables as $t) { if ($t['is_active']) { $counts[$t['status']]++; $seats += (int)$t['seats']; } }
    $groups = [];
    foreach ($sections as $sec) $groups[(int)$sec['id']] = ['sec' => $sec, 'tables' => []];
    foreach ($tables as $t) {
        $k = (int)($t['section_id'] ?? 0);
        $groups[$k] ??= ['sec' => ['id' => 0, 'name' => __('restaurant_pos_no_section', 'No section'), 'sort_order' => 999], 'tables' => []];
        $groups[$k]['tables'][] = $t;
    }
    $ST = ['open' => __('restaurant_pos_t_open', 'Free'), 'seated' => __('restaurant_pos_t_seated', 'Seated'), 'dirty' => __('restaurant_pos_t_dirty', 'To clear')];
    $canPos = Auth::can('restaurant.pos') || Auth::isSuperAdmin();
?>
    <div class="page-header">
        <div><h1><?= e(__('restaurant_floor', 'Floor & tables')) ?></h1>
            <p class="page-header-sub"><?= e(sprintf(__('restaurant_floor_sub', '%1$s tables · %2$s seats in service'), count($tables), $seats)) ?></p></div>
        <div class="toolbar">
            <?php if ($canPos): ?><a href="<?= e(plugin_url('restaurant', 'admin/pos.php')) ?>" class="btn"><?= e(__('restaurant_floor_live', 'Live table map')) ?> →</a><?php endif; ?>
            <a href="?new_table=1" class="btn btn-primary"><?= e(__('restaurant_floor_new_table', 'New table')) ?></a>
        </div>
    </div>

    <div class="rf-stats">
        <?php foreach ($ST as $k => $label): ?>
            <div class="rf-stat is-<?= e($k) ?>"><span><?= e($label) ?></span><b><?= (int)$counts[$k] ?></b></div>
        <?php endforeach; ?>
        <form method="post" class="rf-addsec card">
            <?= csrf_field() ?><input type="hidden" name="_action" value="save_section">
            <input type="text" name="name" maxlength="120" required placeholder="<?= e(__('restaurant_floor_section_ph', 'New section: Patio, Bar…')) ?>" aria-label="<?= e(__('restaurant_floor_add_section', 'Add a section')) ?>">
            <input type="hidden" name="sort_order" value="<?= count($sections) + 1 ?>">
            <button class="btn btn-sm"><?= e(__('restaurant_floor_add_section', 'Add a section')) ?></button>
        </form>
    </div>

    <?php if (!$tables && !$sections): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= e(__('restaurant_floor_empty', 'No tables yet')) ?></div>
            <p class="text-sm"><?= e(__('restaurant_floor_empty_hint', 'Add a section, then the tables in it, so servers can open dine-in checks.')) ?></p></div></div>
    <?php endif; ?>

    <?php foreach ($groups as $g): $sec = $g['sec']; ?>
    <section class="card rf-sec">
        <div class="card-header">
            <h2><?= e($sec['name']) ?> <span class="text-sm text-muted"><?= e(sprintf(__('restaurant_floor_n_tables', '%1$s tables · %2$s seats'), count($g['tables']), array_sum(array_map(fn($t) => (int)$t['seats'], $g['tables'])))) ?></span></h2>
            <div class="toolbar">
                <a href="?new_table=1&amp;section=<?= (int)$sec['id'] ?>" class="btn btn-sm"><?= e(__('restaurant_floor_add_table', '+ Table')) ?></a>
                <?php if ((int)$sec['id'] > 0): ?>
                <form method="post" style="display:inline;margin:0;" onsubmit="return confirm(<?= e(json_encode(__('restaurant_floor_del_section_q', 'Delete this section? Its tables stay, without a section.'))) ?>)">
                    <?= csrf_field() ?><input type="hidden" name="_action" value="delete_section"><input type="hidden" name="id" value="<?= (int)$sec['id'] ?>">
                    <button class="btn btn-sm btn-ghost"><?= e(__('restaurant_floor_del_section', 'Delete section')) ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="rf-grid">
            <?php if (!$g['tables']): ?><p class="text-sm text-muted" style="margin:0;"><?= e(__('restaurant_floor_sec_empty', 'No tables in this section yet.')) ?></p><?php endif; ?>
            <?php foreach ($g['tables'] as $t): $o = $openBy[(int)$t['id']] ?? null; ?>
                <div class="rf-table is-<?= e($t['status']) ?><?= $t['is_active'] ? '' : ' is-off' ?>">
                    <a class="rf-main" href="?edit_table=<?= (int)$t['id'] ?>" title="<?= e(__('restaurant_floor_edit', 'Edit table')) ?>">
                        <b><?= e($t['label']) ?></b>
                        <span><?= e(sprintf(__('restaurant_pos_seats_n', '%s seats'), (int)$t['seats'])) ?></span>
                        <em><?= e($t['is_active'] ? $ST[$t['status']] : __('restaurant_floor_off', 'Out of service')) ?></em>
                    </a>
                    <?php if ($o): ?>
                        <div class="rf-open"><?= e(RestaurantAPI::money((int)$o['total'])) ?> · <?= e(date('g:ia', strtotime((string)$o['since']))) ?><?= (int)$o['n'] > 1 ? ' · ×' . (int)$o['n'] : '' ?></div>
                    <?php endif; ?>
                    <div class="rf-acts">
                        <?php if ($t['status'] === 'dirty' || ($t['status'] === 'seated' && !$o)): ?>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="table_status"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="status" value="open">
                                <button class="btn btn-sm"><?= e(__('restaurant_floor_mark_free', 'Mark free')) ?></button></form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm(<?= e(json_encode(__('restaurant_floor_del_table_q', 'Delete this table?'))) ?>)"><?= csrf_field() ?><input type="hidden" name="_action" value="delete_table"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button class="btn btn-sm btn-ghost" aria-label="<?= e(__('restaurant_floor_del_table', 'Delete table')) ?>">×</button></form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <style>
    .rf-stats { display: grid; grid-template-columns: repeat(3, minmax(110px, 160px)) 1fr; gap: 12px; margin-bottom: 14px; align-items: stretch; }
    .rf-stat { display: flex; flex-direction: column; padding: 12px 16px; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); border-left-width: 4px; }
    .rf-stat span { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
    .rf-stat b { font-size: 26px; }
    .rf-stat.is-open { border-left-color: var(--success); } .rf-stat.is-seated { border-left-color: var(--info); } .rf-stat.is-dirty { border-left-color: var(--warning); }
    .rf-addsec { display: flex; gap: 8px; align-items: center; padding: 10px 12px; margin: 0; }
    .rf-addsec input { flex: 1; min-width: 0; }
    .rf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; padding: 4px 16px 16px; }
    .rf-table { display: flex; flex-direction: column; border: 2px solid var(--border); border-radius: var(--radius-xl); background: var(--surface); overflow: hidden; }
    .rf-table.is-open { border-color: color-mix(in srgb, var(--success) 45%, var(--border)); }
    .rf-table.is-seated { border-color: var(--info); background: var(--info-soft); }
    .rf-table.is-dirty { border-color: var(--warning); background: var(--warning-soft); }
    .rf-table.is-off { opacity: .55; border-style: dashed; }
    .rf-main { display: flex; flex-direction: column; align-items: center; padding: 14px 8px 6px; color: inherit; text-decoration: none; }
    .rf-main b { font-size: 24px; }
    .rf-main span { font-size: 12.5px; color: var(--muted); }
    .rf-main em { font-style: normal; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-top: 2px; }
    .rf-open { text-align: center; font-size: 12.5px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .rf-acts { display: flex; justify-content: center; gap: 4px; padding: 6px 8px 10px; }
    .rf-acts form { margin: 0; }
    @media (max-width: 760px) { .rf-stats { grid-template-columns: repeat(3, 1fr); } .rf-addsec { grid-column: 1 / -1; } }
    </style>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
