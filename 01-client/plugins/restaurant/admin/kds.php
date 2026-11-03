<?php
/**
 * Restaurant — kitchen display (full screen).
 *
 * Every check with a line in the kitchen shows as a ticket, oldest first,
 * with the time since it was fired (green, then amber, then red). Tap a line
 * to mark it ready, tap a ready line to mark it served; "Ready" and "Served"
 * on the ticket do the whole ticket. Refreshes itself every few seconds and
 * chimes when a new ticket arrives. Needs restaurant.kitchen.
 *
 * Lifecycle: new (on the check) → sent (Send to kitchen) → ready → served.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.kitchen');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$tid = current_tenant_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_verify()) { echo json_encode(['ok' => false, 'error' => __('restaurant_err_csrf', 'Security check failed. Reload the page and try again.')]); exit; }
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'line') {
            $line = Database::row("SELECT id FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [(int)($_POST['line_id'] ?? 0), $tid]);
            if (!$line) throw new \RuntimeException(__('restaurant_err_line', 'Line not found.'));
            RestaurantAPI::setKitchenStatus((int)$line['id'], (string)($_POST['to'] ?? ''));
        } elseif ($action === 'ticket') {
            $oid = (int)($_POST['order_id'] ?? 0);
            if (!Database::value("SELECT 1 FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$oid, $tid])) {
                throw new \RuntimeException(__('restaurant_err_check', 'Check not found.'));
            }
            $to = (string)($_POST['to'] ?? 'ready');
            if ($to === 'sent') {
                // Recall: every ready line goes back to cooking.
                foreach (Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ? AND tenant_id = ? AND kitchen_status = 'ready'", [$oid, $tid]) as $l) {
                    RestaurantAPI::setKitchenStatus((int)$l['id'], 'sent');
                }
            } else {
                RestaurantAPI::bumpOrder($oid, $to === 'served' ? 'served' : 'ready');
            }
        } elseif ($action !== 'tickets') {
            throw new \RuntimeException(__('restaurant_err_action', 'Unknown action.'));
        }
        echo json_encode(['ok' => true, 'tickets' => RestaurantAPI::kitchenTickets(), 'now' => slate_db_now()]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$boot = [
    'tickets'  => RestaurantAPI::kitchenTickets(),
    'now'      => slate_db_now(),
    'endpoint' => plugin_url('restaurant', 'admin/kds.php'),
    'csrf'     => csrf_token(),
    't'        => __js([
        'restaurant_kds_empty'      => 'All caught up. New tickets appear here when the register sends them.',
        'restaurant_kds_ready'      => 'Ready',
        'restaurant_kds_served'     => 'Served',
        'restaurant_kds_recall'     => 'Recall',
        'restaurant_pos_k_sent'     => 'Cooking',
        'restaurant_pos_k_ready'    => 'Ready',
        'restaurant_rcpt_table'     => 'Table',
        'restaurant_rcpt_dine_in'   => 'Dine in',
        'restaurant_rcpt_takeout'   => 'Takeout',
        'restaurant_rcpt_delivery'  => 'Delivery',
        'restaurant_pos_online'     => 'Online',
        'restaurant_kds_for'        => 'for %s',
        'restaurant_kds_seat'       => 'Seat %s',
        'restaurant_kds_offline'    => 'Lost connection. Retrying…',
        'restaurant_pos_failed'     => 'That did not work. Try again.',
    ]),
];
?><!doctype html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0E1117">
<title><?= e(__('restaurant_kds_title', 'Kitchen display')) ?></title>
<link rel="icon" href="<?= e(slate_favicon_url()) ?>">
<?php slate_ui_emit_css(); ?>
<?php slate_brand_accent_emit(); ?>
<link rel="stylesheet" href="<?= e(plugin_url('restaurant', 'assets/pos.css')) ?>?v=<?= e((string)@filemtime(dirname(__DIR__) . '/assets/pos.css')) ?>">
<link rel="stylesheet" href="<?= e(plugin_url('restaurant', 'assets/kds.css')) ?>?v=<?= e((string)@filemtime(dirname(__DIR__) . '/assets/kds.css')) ?>">
</head>
<body class="pos-body kds-body">
<header class="pos-top">
    <a class="pos-top-back" href="<?= e(plugin_url('restaurant', Auth::can('restaurant.pos') || Auth::isSuperAdmin() ? 'admin/pos.php' : 'admin/index.php')) ?>">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        <span><?= e(__('restaurant_kds_title', 'Kitchen display')) ?></span>
    </a>
    <nav class="pos-tabs" id="kdsFilter">
        <button type="button" class="pos-tab is-active" data-f="all"><?= e(__('restaurant_pos_all', 'All')) ?> <span data-n="all"></span></button>
        <button type="button" class="pos-tab" data-f="dine_in"><?= e(__('restaurant_rcpt_dine_in', 'Dine in')) ?> <span data-n="dine_in"></span></button>
        <button type="button" class="pos-tab" data-f="togo"><?= e(__('restaurant_pos_togo', 'To go')) ?> <span data-n="togo"></span></button>
        <button type="button" class="pos-tab" data-f="online"><?= e(__('restaurant_pos_online', 'Online')) ?> <span data-n="online"></span></button>
    </nav>
    <div class="pos-top-right">
        <span class="kds-status" id="kdsStatus"></span>
        <label class="kds-sound"><input type="checkbox" id="kdsSound" checked> <?= e(__('restaurant_kds_sound', 'Chime')) ?></label>
        <span class="pos-clock" id="kdsClock"></span>
    </div>
</header>
<main class="kds-board" id="kdsBoard" aria-live="polite"></main>
<script>window.KDS_BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= e(plugin_url('restaurant', 'assets/kds.js')) ?>?v=<?= e((string)@filemtime(dirname(__DIR__) . '/assets/kds.js')) ?>"></script>
</body>
</html>
