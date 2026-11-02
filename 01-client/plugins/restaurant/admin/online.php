<?php
/**
 * Restaurant — online orders queue.
 *
 * Staff-facing list of orders placed through the public storefront (/order):
 * contact, items, payment status, and a fulfilment workflow
 * (new → accepted → preparing → ready → completed). Also fires lines to the
 * kitchen and links to the full check in the POS view.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.view');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$tid   = current_tenant_id();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $own = $orderId > 0 && Database::value("SELECT 1 FROM restaurant_orders WHERE id = ? AND tenant_id = ? AND source = 'online'", [$orderId, $tid]);
        if ($own) {
            switch ($_POST['_action'] ?? '') {
                case 'fulfill':
                    RestaurantAPI::setFulfillment($orderId, (string)($_POST['status'] ?? ''));
                    $flash = ['type' => 'success', 'msg' => 'Order updated.'];
                    break;
                case 'send':
                    RestaurantAPI::sendToKitchen($orderId);
                    RestaurantAPI::setFulfillment($orderId, 'preparing');
                    $flash = ['type' => 'success', 'msg' => 'Sent to kitchen.'];
                    break;
            }
        }
        header('Location: ' . plugin_url('restaurant', 'admin/online.php'));
        exit;
    }
}

$orders = RestaurantAPI::getOnlineOrders(100);
// Hydrate each with its items for the inline summary.
foreach ($orders as &$o) { $o['_full'] = RestaurantAPI::getOrder((int)$o['id']); }
unset($o);

$active = array_filter($orders, fn($o) => !in_array($o['fulfillment_status'], ['completed', 'cancelled'], true));

$pageTitle  = 'Online orders';
$currentNav = 'restaurant-online';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Online orders'],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="page-header">
    <div><h1>Online orders</h1><p class="page-header-sub"><?= count($active) ?> active · <?= count($orders) ?> total</p></div>
    <?php if (RestaurantAPI::onlineOrderingEnabled()): ?>
        <a href="<?= e(rtrim(SLATE_URL, '/')) ?>/order" target="_blank" rel="noopener" class="btn">View storefront ↗</a>
    <?php else: ?>
        <a href="<?= e(plugin_url('restaurant', 'admin/settings.php')) ?>" class="btn btn-primary">Enable online ordering</a>
    <?php endif; ?>
</div>

<?php if (!RestaurantAPI::onlineOrderingEnabled()): ?>
    <div class="alert alert-info">Online ordering is currently <strong>off</strong>. Turn it on in <a href="<?= e(plugin_url('restaurant', 'admin/settings.php')) ?>">Settings</a> to start taking orders at <code><?= e(rtrim(SLATE_URL, '/')) ?>/order</code>.</div>
<?php endif; ?>

<?php if (!$orders): ?>
    <div class="card"><div class="empty"><div class="empty-title">No online orders yet</div><p class="text-sm">Orders placed at your storefront will appear here.</p></div></div>
<?php else: ?>
    <style>
    .ron-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
    .ron-card{border:1px solid var(--border,#e5e7eb);border-radius:12px;background:var(--surface,#fff);overflow:hidden}
    .ron-h{padding:10px 14px;border-bottom:1px solid var(--border,#e5e7eb);display:flex;justify-content:space-between;align-items:center}
    .ron-h b{font-size:1.02rem}
    .ron-body{padding:10px 14px}
    .ron-li{display:flex;justify-content:space-between;padding:3px 0;font-size:.9rem}
    .ron-meta{color:var(--muted,#6b7280);font-size:.85rem;margin:6px 0}
    .ron-foot{padding:10px 14px;border-top:1px solid var(--border,#e5e7eb);display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    .ron-pill{font-size:.72rem;padding:2px 8px;border-radius:999px;background:var(--surface-2,#f3f4f6)}
    .ron-pill.paid{background:#dcfce7;color:#166534}.ron-pill.due{background:#fef9c3;color:#854d0e}
    .ron-pill.s-new{background:#dbeafe;color:#1e40af}.ron-pill.s-ready{background:#dcfce7;color:#166534}
    .ron-pill.s-cancelled{background:#fee2e2;color:#991b1b}
    </style>
    <div class="ron-grid">
    <?php foreach ($orders as $o):
        $f = $o['_full']; if (!$f) continue;
        $paid = (int)$f['paid_cents'] >= (int)$f['total_cents'] && (int)$f['total_cents'] > 0;
        $st = (string)$o['fulfillment_status'];
        $hasNew = false; foreach ($f['items'] as $it) { if ($it['kitchen_status'] === 'new') $hasNew = true; }
        $next = ['new' => 'accepted', 'accepted' => 'preparing', 'preparing' => 'ready', 'ready' => 'completed'];
    ?>
        <div class="ron-card">
            <div class="ron-h">
                <b>#<?= e($o['order_no']) ?></b>
                <span>
                    <span class="ron-pill <?= $paid ? 'paid' : 'due' ?>"><?= $paid ? 'Paid' : 'Due' ?></span>
                    <span class="ron-pill s-<?= e($st) ?>"><?= e(ucfirst($st)) ?></span>
                </span>
            </div>
            <div class="ron-body">
                <div class="ron-meta">
                    <strong><?= e((string)($o['customer_name'] ?? 'Guest')) ?></strong>
                    <?= $o['customer_phone'] ? ' · ' . e($o['customer_phone']) : '' ?><br>
                    <?= e(ucfirst((string)$f['type'])) ?><?= $o['requested_time'] ? ' · ' . e($o['requested_time']) : '' ?>
                    · <?= e(date('M j, g:ia', strtotime((string)$o['opened_at']))) ?>
                </div>
                <?php foreach ($f['items'] as $it): if ($it['kitchen_status'] === 'void') continue;
                    $mods = array_map(fn($m) => $m['name_snapshot'], $it['modifiers'] ?? []); ?>
                    <div class="ron-li"><span><?= (int)$it['qty'] ?>× <?= e($it['name_snapshot']) ?><?= $mods ? ' <span class="text-muted">(' . e(implode(', ', $mods)) . ')</span>' : '' ?></span>
                        <span><?= e(RestaurantAPI::money((int)$it['line_total_cents'])) ?></span></div>
                <?php endforeach; ?>
                <?php if (trim((string)$f['notes']) !== ''): ?><div class="ron-meta"><?= slate_admin_nav_icon('edit-3') ?> <?= e($f['notes']) ?></div><?php endif; ?>
                <div class="ron-li" style="font-weight:700;border-top:1px solid var(--border,#e5e7eb);margin-top:6px;padding-top:6px">
                    <span>Total</span><span><?= e(RestaurantAPI::money((int)$f['total_cents'])) ?></span></div>
            </div>
            <div class="ron-foot">
                <?php if ($hasNew): ?>
                    <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="_action" value="send"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><button class="btn btn-sm btn-primary">Send to kitchen</button></form>
                <?php endif; ?>
                <?php if (isset($next[$st])): ?>
                    <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="_action" value="fulfill"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="<?= e($next[$st]) ?>"><button class="btn btn-sm">Mark <?= e($next[$st]) ?></button></form>
                <?php endif; ?>
                <?php if (!in_array($st, ['completed', 'cancelled'], true)): ?>
                    <form method="post" style="margin:0" onsubmit="return confirm('Cancel this order?')"><?= csrf_field() ?><input type="hidden" name="_action" value="fulfill"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="cancelled"><button class="btn btn-sm btn-danger">Cancel</button></form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
