<?php
/**
 * Restaurant — admin overview + order history.
 * Today's KPIs, open checks, and recent orders. Doubles as the Orders page.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.view');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = __('restaurant', 'Restaurant');
$currentNav = 'restaurant';
$tid = current_tenant_id();

$sales = (int) Database::value(
    "SELECT COALESCE(SUM(total_cents),0) FROM restaurant_orders
      WHERE tenant_id = ? AND status IN ('paid','closed') AND DATE(opened_at) = CURDATE()", [$tid]);
$covers = (int) Database::value(
    "SELECT COALESCE(SUM(guest_count),0) FROM restaurant_orders
      WHERE tenant_id = ? AND DATE(opened_at) = CURDATE()", [$tid]);
$openOrders = RestaurantAPI::getOpenOrders();

$recent = Database::rows(
    "SELECT o.*, t.label AS table_label FROM restaurant_orders o
       LEFT JOIN restaurant_tables t ON t.id = o.table_id
      WHERE o.tenant_id = ? AND o.status IN ('paid','closed','voided')
   ORDER BY o.id DESC LIMIT 40", [$tid]);

$orderUrl = fn(int $id) => plugin_url('restaurant', 'admin/order.php') . '?id=' . $id;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant')],
]); ?>

<div class="page-header">
    <div><h1>Restaurant</h1><p class="page-header-sub">Today at a glance.</p></div>
    <div class="toolbar">
        <a href="<?= e(plugin_url('restaurant', 'admin/items.php')) ?>" class="btn">Menu</a>
        <a href="<?= e(plugin_url('restaurant', 'admin/pos.php')) ?>" class="btn btn-primary">Open POS →</a>
    </div>
</div>

<div class="dwidget-kpis" style="margin-bottom:var(--space-4);">
    <div class="dwidget-kpi"><div class="dwidget-kpi-k">Today's sales</div><div class="dwidget-kpi-v"><?= e(RestaurantAPI::money($sales)) ?></div></div>
    <div class="dwidget-kpi"><div class="dwidget-kpi-k">Open checks</div><div class="dwidget-kpi-v"><?= count($openOrders) ?></div></div>
    <div class="dwidget-kpi"><div class="dwidget-kpi-k">Covers today</div><div class="dwidget-kpi-v"><?= $covers ?></div></div>
</div>

<?php slate_page_layout('with-aside'); ?>
    <div class="page-main">
        <div class="card">
            <div class="card-header"><h2>Open checks (<?= count($openOrders) ?>)</h2>
                <a href="<?= e(plugin_url('restaurant', 'admin/pos.php')) ?>" class="btn btn-sm">POS</a></div>
            <?php if (!$openOrders): ?>
                <div class="empty"><div class="empty-title">No open checks</div></div>
            <?php else: ?>
                <ul class="kv-list">
                    <?php foreach ($openOrders as $o):
                        $where = trim((string)($o['table_label'] ?? '')) !== '' ? 'Table ' . $o['table_label'] : ucfirst(str_replace('_',' ',(string)$o['type'])); ?>
                        <li class="kv-row">
                            <span class="kv-label"><strong style="color:var(--text);">#<?= e($o['order_no']) ?></strong>
                                · <?= e($where) ?> <span class="text-xs text-muted"><?= ucfirst($o['status']) ?></span></span>
                            <span class="kv-value"><?= e(RestaurantAPI::money((int)$o['total_cents'])) ?>
                                <a class="text-xs" href="<?= e(plugin_url('restaurant','admin/pos.php')) ?>?order=<?= (int)$o['id'] ?>">Open</a></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><h2>Recent orders</h2></div>
            <?php if (!$recent): ?>
                <div class="empty"><div class="empty-title">No orders yet</div></div>
            <?php else: ?>
            <div class="data-list" data-single-open>
                <?php foreach ($recent as $o):
                    $where = trim((string)($o['table_label'] ?? '')) !== '' ? 'Table ' . $o['table_label'] : ucfirst(str_replace('_',' ',(string)$o['type']));
                    $colors = ['paid' => 'success', 'closed' => 'muted', 'voided' => 'danger'];
                    slate_data_row([
                        'avatar'       => (string)$o['order_no'],
                        'avatar_color' => $colors[$o['status']] ?? 'muted',
                        'title'        => '#' . $o['order_no'],
                        'meta'         => $where . ' · ' . ($o['opened_at'] ? date('M j, g:ia', strtotime($o['opened_at'])) : ''),
                        'value'        => RestaurantAPI::money((int)$o['total_cents']),
                        'badge'        => [ucfirst($o['status']), $colors[$o['status']] ?? 'muted'],
                        'detail'       => ['Type' => ucfirst(str_replace('_',' ',$o['type'])), 'Guests' => (int)$o['guest_count'],
                                           'Total' => RestaurantAPI::money((int)$o['total_cents']), 'Paid' => RestaurantAPI::money((int)$o['paid_cents'])],
                        'actions'      => '<a href="' . e($orderUrl((int)$o['id'])) . '" class="btn btn-sm">View</a>',
                    ]);
                endforeach; ?>
            </div>
            <?php slate_data_list_script(); ?>
            <?php endif; ?>
        </div>
    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title">Setup checklist</div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label">1. Settings (tax, tips)</span><span class="kv-value"><a href="<?= e(plugin_url('restaurant','admin/settings.php')) ?>">→</a></span></li>
                <li class="kv-row"><span class="kv-label">2. Categories</span><span class="kv-value"><a href="<?= e(plugin_url('restaurant','admin/categories.php')) ?>">→</a></span></li>
                <li class="kv-row"><span class="kv-label">3. Menu items</span><span class="kv-value"><a href="<?= e(plugin_url('restaurant','admin/items.php')) ?>">→</a></span></li>
                <li class="kv-row"><span class="kv-label">4. Floor &amp; tables</span><span class="kv-value"><a href="<?= e(plugin_url('restaurant','admin/floor.php')) ?>">→</a></span></li>
                <li class="kv-row"><span class="kv-label">5. Card readers</span><span class="kv-value"><a href="<?= e(plugin_url('restaurant','admin/readers.php')) ?>">→</a></span></li>
            </ul>
        </div>
    </aside>
<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
