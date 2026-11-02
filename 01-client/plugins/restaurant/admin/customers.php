<?php
/**
 * Restaurant — standalone customer records (list + inline create/edit).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('restaurant.manage_customers');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = 'Customers';
$currentNav = 'restaurant-customers';
$tid = current_tenant_id();

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);
$q      = trim((string)($_GET['q'] ?? ''));

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM restaurant_customers WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
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
                $flash = ['type' => 'error', 'msg' => 'Name is required.'];
            } else {
                $row = [
                    'tenant_id' => $tid,
                    'name'      => mb_substr($name, 0, 160),
                    'phone'     => mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 40) ?: null,
                    'email'     => mb_substr(trim((string)($_POST['email'] ?? '')), 0, 200) ?: null,
                    'notes'     => trim((string)($_POST['notes'] ?? '')) ?: null,
                ];
                if ($id > 0) Database::update('restaurant_customers', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                else         $id = Database::insert('restaurant_customers', $row);
                AuditLog::record('restaurant.customer_saved', (string)$id);
                header('Location: ' . plugin_url('restaurant', 'admin/customers.php')); exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::query("UPDATE restaurant_orders SET customer_id = NULL WHERE customer_id = ? AND tenant_id = ?", [$id, $tid]);
                Database::delete('restaurant_customers', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('restaurant.customer_deleted', (string)$id);
                header('Location: ' . plugin_url('restaurant', 'admin/customers.php')); exit;
            }
        }
    }
}

$where  = ['tenant_id = ?']; $params = [$tid];
if ($q !== '') { $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$customers = Database::rows(
    "SELECT * FROM restaurant_customers WHERE " . implode(' AND ', $where) . " ORDER BY name LIMIT 300", $params);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Customers'],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $c = $editing ?: ['id'=>0,'name'=>'','phone'=>'','email'=>'','notes'=>''];
?>
<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => 'New customer']); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">

        <?php slate_edit_backlink(['back_href' => plugin_url('restaurant', 'admin/customers.php'), 'back_label' => 'All customers']); ?>

        <?php slate_edit_hero([
            'icon'        => 'user',
            'title'       => $editing ? $editing['name'] : 'New customer',
            'ref'         => $editing ? (int)$editing['id'] : null,
            'status_text' => 'Customer', 'status_tone' => 'on',
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'user', 'eyebrow' => 'Contact', 'title' => 'Customer details']); ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name">Name <span class="field-required">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="160" value="<?= e($c['name']) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="phone">Phone</label>
                    <input type="text" id="phone" name="phone" maxlength="40" value="<?= e((string)($c['phone'] ?? '')) ?>">
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="200" value="<?= e((string)($c['email'] ?? '')) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" placeholder="Allergies, preferences…"><?= e((string)($c['notes'] ?? '')) ?></textarea>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? 'Save customer' : 'Create customer') . '</button>'
              . '<a href="' . e(plugin_url('restaurant', 'admin/customers.php')) . '" class="btn btn-ghost">Cancel</a>',
        ]); ?>
    </form>
<?php slate_edit_close(); ?>
<?php slate_editor_js(); ?>

<?php else: ?>
    <div class="page-header">
        <div><h1>Customers</h1><p class="page-header-sub"><?= count($customers) ?> record(s).</p></div>
        <a href="?new=1" class="btn btn-primary">New customer</a>
    </div>

    <form method="get" style="margin-bottom:14px;">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, phone or email…" style="max-width:320px;">
    </form>

    <?php if (!$customers): ?>
    <div class="card"><div class="empty"><div class="empty-title">No customers<?= $q !== '' ? ' match' : ' yet' ?></div></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($customers as $c):
            $actions = '<a href="?edit=' . (int)$c['id'] . '" class="btn btn-sm">Edit</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'Delete this customer?\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$c['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">Delete</button></form>';
            slate_data_row([
                'avatar'       => mb_substr($c['name'], 0, 2),
                'avatar_color' => 'info',
                'title'        => $c['name'],
                'meta'         => trim(((string)($c['phone'] ?? '')) . ' · ' . ((string)($c['email'] ?? '')), ' ·'),
                'detail'       => ['Phone' => $c['phone'] ?: '—', 'Email' => $c['email'] ?: '—',
                                   'Notes' => $c['notes'] ?: '—'],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
