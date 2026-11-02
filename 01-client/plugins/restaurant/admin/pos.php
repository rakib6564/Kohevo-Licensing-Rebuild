<?php
/**
 * Restaurant — POS register (full screen).
 *
 * A GET renders a full-screen touch register outside the admin chrome
 * (assets/pos.js + assets/pos.css on the shared Kohevo design tokens):
 * open checks, the menu with search and photos, the live check with seats,
 * split checks, discounts and guests, a table map, and the tender window
 * (cash keypad with change, or a Stripe Terminal reader). A POST with
 * `_ajax=1` is the JSON endpoint that drives RestaurantAPI. All money is
 * integer cents end to end.
 *
 * Permissions: restaurant.pos for everything here; restaurant.manage_orders
 * additionally for void, comp (line or check) and reopen.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.pos');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$tid = current_tenant_id();
$can = fn(string $perm): bool => Auth::can($perm) || Auth::isSuperAdmin();

// ── AJAX endpoint ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $fail = function (string $msg) { echo json_encode(['ok' => false, 'error' => $msg]); exit; };
    if (!csrf_verify()) $fail(__('restaurant_err_csrf', 'Security check failed. Reload the page and try again.'));

    $action  = (string)($_POST['action'] ?? '');
    $orderId = (int)($_POST['order_id'] ?? 0);
    $cents   = fn(string $k): int => (int) round((float) str_replace(',', '.', (string)($_POST[$k] ?? 0)) * 100);
    $reason  = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 160);

    // Confirm an order / line belongs to this tenant before any mutation.
    $ownOrder = function (int $id) use ($tid): bool {
        return $id > 0 && (bool) Database::value(
            "SELECT 1 FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$id, $tid]);
    };
    $lineOrder = function () use ($tid, $fail): int {
        $row = Database::row("SELECT order_id FROM restaurant_order_items WHERE id = ? AND tenant_id = ?",
            [(int)($_POST['line_id'] ?? 0), $tid]);
        if (!$row) $fail(__('restaurant_err_line', 'Line not found.'));
        return (int)$row['order_id'];
    };
    $needManager = function () use ($can, $fail): void {
        if (!$can('restaurant.manage_orders')) $fail(__('restaurant_err_manager', 'This needs a manager (restaurant.manage_orders).'));
    };
    $reply = function (int $id, array $extra = []): void {
        echo json_encode(['ok' => true, 'order' => RestaurantAPI::getOrder($id)] + $extra);
    };

    try {
        switch ($action) {
            case 'open':
                $newId = RestaurantAPI::openOrder([
                    'type'        => (string)($_POST['type'] ?? 'dine_in'),
                    'table_id'    => (int)($_POST['table_id'] ?? 0),
                    'guest_count' => (int)($_POST['guest_count'] ?? 1),
                    'notes'       => (string)($_POST['notes'] ?? ''),
                ]);
                $reply($newId);
                break;

            case 'order':
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                $reply($orderId);
                break;

            case 'item_mods':
                echo json_encode(['ok' => true, 'groups' => RestaurantAPI::getItemModifierGroups((int)($_POST['item_id'] ?? 0))]);
                break;

            case 'add_line':
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                $mods = $_POST['modifiers'] ?? [];
                if (is_string($mods)) $mods = array_filter(explode(',', $mods));
                RestaurantAPI::addLine($orderId, [
                    'item_id'   => (int)($_POST['item_id'] ?? 0),
                    'qty'       => (int)($_POST['qty'] ?? 1),
                    'modifiers' => array_map('intval', (array)$mods),
                    'notes'     => (string)($_POST['notes'] ?? ''),
                    'seat'      => (int)($_POST['seat'] ?? 0),
                    'check_no'  => (int)($_POST['check_no'] ?? 1),
                ]);
                $reply($orderId);
                break;

            case 'line_qty':
                $oid = $lineOrder();
                RestaurantAPI::setLineQty((int)$_POST['line_id'], (int)($_POST['qty'] ?? 1));
                $reply($oid);
                break;

            case 'remove_line':
                $oid = $lineOrder();
                RestaurantAPI::removeLine((int)$_POST['line_id']);
                $reply($oid);
                break;

            case 'line_seat':
                $oid = $lineOrder();
                $seat = (int)($_POST['seat'] ?? 0);
                RestaurantAPI::setLineSeat((int)$_POST['line_id'], $seat > 0 ? $seat : null);
                $reply($oid);
                break;

            case 'line_move':
                $oid = $lineOrder();
                RestaurantAPI::moveLineToCheck((int)$_POST['line_id'], (int)($_POST['check_no'] ?? 1));
                $reply($oid);
                break;

            case 'line_split':
                $oid = $lineOrder();
                RestaurantAPI::splitLine((int)$_POST['line_id'], (int)($_POST['qty'] ?? 1), (int)($_POST['check_no'] ?? 1));
                $reply($oid);
                break;

            case 'line_discount':
                $oid  = $lineOrder();
                $type = (string)($_POST['type'] ?? 'none');
                $val  = $type === 'amount' ? $cents('value') : (float)($_POST['value'] ?? 0);
                RestaurantAPI::setLineDiscount((int)$_POST['line_id'], $type, (float)$val, $reason);
                $reply($oid);
                break;

            case 'line_comp':
                $needManager();
                $oid = $lineOrder();
                RestaurantAPI::compLine((int)$_POST['line_id'], $reason);
                $reply($oid);
                break;

            case 'line_served':
                $oid = $lineOrder();
                RestaurantAPI::setKitchenStatus((int)$_POST['line_id'], 'served');
                $reply($oid);
                break;

            case 'discount':
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                if (($_POST['type'] ?? 'amount') === 'pct') {
                    RestaurantAPI::setDiscountPct($orderId, (float)($_POST['value'] ?? 0), $reason);
                } else {
                    RestaurantAPI::setDiscount($orderId, $cents('value'), $reason);
                }
                $reply($orderId);
                break;

            case 'meta':
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                $fields = [];
                if (isset($_POST['notes']))       $fields['notes'] = (string)$_POST['notes'];
                if (isset($_POST['guest_count'])) $fields['guest_count'] = (int)$_POST['guest_count'];
                RestaurantAPI::updateOrderMeta($orderId, $fields);
                $reply($orderId);
                break;

            case 'customer_search':
                echo json_encode(['ok' => true, 'customers' => RestaurantAPI::searchCustomers((string)($_POST['q'] ?? ''))]);
                break;

            case 'set_customer':
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                $cid = (int)($_POST['customer_id'] ?? 0);
                if ($cid > 0 && !Database::value("SELECT 1 FROM restaurant_customers WHERE id = ? AND tenant_id = ?", [$cid, $tid])) {
                    $fail(__('restaurant_err_customer', 'Customer not found.'));
                }
                if ($cid === 0 && (trim((string)($_POST['name'] ?? '')) !== '' || trim((string)($_POST['phone'] ?? '')) !== '')) {
                    $cid = (int) RestaurantAPI::findOrCreateCustomer((string)($_POST['name'] ?? ''), (string)($_POST['phone'] ?? ''),
                        (string)($_POST['email'] ?? ''));
                }
                RestaurantAPI::updateOrderMeta($orderId, ['customer_id' => $cid ?: null]);
                $reply($orderId);
                break;

            case 'send':
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                RestaurantAPI::sendToKitchen($orderId);
                $reply($orderId);
                break;

            case 'cash':
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                $tendered = trim((string)($_POST['tendered'] ?? '')) === '' ? null : $cents('tendered');
                $pid = RestaurantAPI::recordCashPayment($orderId, $cents('amount'), $cents('tip'), $tendered,
                    (int)($_POST['check_no'] ?? 1));
                $reply($orderId, ['change_cents' => (int)Database::value("SELECT change_cents FROM restaurant_payments WHERE id = ?", [$pid])]);
                break;

            case 'terminal_begin':
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                echo json_encode(RestaurantAPI::beginTerminalPayment($orderId, (int)($_POST['reader_id'] ?? 0),
                    $cents('amount'), $cents('tip'), (int)($_POST['check_no'] ?? 1)));
                break;

            case 'terminal_poll':
                $pid = (int)($_POST['payment_id'] ?? 0);
                $pay = Database::row("SELECT order_id FROM restaurant_payments WHERE id = ? AND tenant_id = ?", [$pid, $tid]);
                if (!$pay) $fail(__('restaurant_refund_err_not_found', 'Payment not found.'));
                $res = RestaurantAPI::pollTerminalPayment($pid);
                $res['order'] = RestaurantAPI::getOrder((int)$pay['order_id']);
                echo json_encode($res);
                break;

            case 'terminal_cancel':
                $pid = (int)($_POST['payment_id'] ?? 0);
                $pay = Database::row("SELECT order_id FROM restaurant_payments WHERE id = ? AND tenant_id = ?", [$pid, $tid]);
                if (!$pay) $fail(__('restaurant_refund_err_not_found', 'Payment not found.'));
                RestaurantAPI::cancelTerminalPayment($pid);
                $reply((int)$pay['order_id']);
                break;

            case 'merge':
                $src = (int)($_POST['source_id'] ?? 0);
                if (!$ownOrder($orderId) || !$ownOrder($src)) $fail(__('restaurant_err_check', 'Check not found.'));
                RestaurantAPI::mergeOrders($orderId, $src);
                $reply($orderId);
                break;

            case 'close':
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                RestaurantAPI::closeOrder($orderId);
                $reply($orderId);
                break;

            case 'comp':
                $needManager();
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                RestaurantAPI::compOrder($orderId, $reason);
                $reply($orderId);
                break;

            case 'reopen':
                $needManager();
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                RestaurantAPI::reopenOrder($orderId, $reason);
                $reply($orderId);
                break;

            case 'void':
                $needManager();
                if (!$ownOrder($orderId)) $fail(__('restaurant_err_check', 'Check not found.'));
                RestaurantAPI::voidOrder($orderId, $reason);
                $reply($orderId);
                break;

            case 'item_86':
                RestaurantAPI::set86((int)($_POST['item_id'] ?? 0), !empty($_POST['on']));
                echo json_encode(['ok' => true, 'menu' => RestaurantAPI::getPosMenu()]);
                break;

            case 'table_status':
                RestaurantAPI::setTableStatus((int)($_POST['table_id'] ?? 0), (string)($_POST['status'] ?? ''));
                echo json_encode(['ok' => true, 'floor' => RestaurantAPI::floorMap()]);
                break;

            case 'refresh':
                echo json_encode([
                    'ok'     => true,
                    'open'   => RestaurantAPI::getOpenOrders(),
                    'recent' => Database::rows(
                        "SELECT o.id, o.order_no, o.type, o.status, o.total_cents, o.closed_at, t.label AS table_label
                           FROM restaurant_orders o LEFT JOIN restaurant_tables t ON t.id = o.table_id
                          WHERE o.tenant_id = ? AND o.status IN ('paid','closed')
                       ORDER BY o.closed_at DESC, o.id DESC LIMIT 15", [$tid]),
                    'floor'  => RestaurantAPI::floorMap(),
                ]);
                break;

            default:
                $fail(__('restaurant_err_action', 'Unknown action.'));
        }
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Bootstrap data for the initial render ──────────────────────────────────
$modItemIds = array_map('intval', array_column(Database::rows(
    "SELECT DISTINCT item_id FROM restaurant_item_modifier_groups WHERE tenant_id = ?", [$tid]), 'item_id'));
$sym = trim(preg_replace('/[0-9.,\s-]/u', '', RestaurantAPI::money(0)));

$boot = [
    'menu'        => RestaurantAPI::getPosMenu(),
    'openOrders'  => RestaurantAPI::getOpenOrders(),
    'floor'       => RestaurantAPI::floorMap(),
    'modItemIds'  => $modItemIds,
    'readers'     => RestaurantAPI::readers(),
    'terminal'    => RestaurantAPI::terminalAvailable(),
    'tipPresets'  => RestaurantAPI::tipPresets(),
    'currencySym' => $sym ?: '$',
    'perm'        => [
        'manager' => $can('restaurant.manage_orders'),
        'kitchen' => $can('restaurant.kitchen'),
    ],
    'urls'        => [
        'endpoint' => plugin_url('restaurant', 'admin/pos.php'),
        'receipt'  => plugin_url('restaurant', 'admin/receipt.php'),
        'order'    => plugin_url('restaurant', 'admin/order.php'),
        'kds'      => plugin_url('restaurant', 'admin/kds.php'),
        'admin'    => plugin_url('restaurant', 'admin/index.php'),
        'media'    => rtrim(SLATE_URL, '/'),
    ],
    'csrf'        => csrf_token(),
    'openId'      => (int)($_GET['order'] ?? 0),
    't'           => require dirname(__DIR__) . '/includes/pos-strings.php',
];
$user = Auth::user();
?><!doctype html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0E1117">
<title><?= e(__('restaurant_pos', 'POS register')) ?></title>
<link rel="icon" href="<?= e(slate_favicon_url()) ?>">
<?php slate_ui_emit_css(); ?>
<?php slate_brand_accent_emit(); ?>
<link rel="stylesheet" href="<?= e(plugin_url('restaurant', 'assets/pos.css')) ?>?v=<?= e((string)@filemtime(dirname(__DIR__) . '/assets/pos.css')) ?>">
</head>
<body class="pos-body">
<div class="pos" id="pos" data-view="register">
    <header class="pos-top">
        <a class="pos-top-back" href="<?= e(plugin_url('restaurant', 'admin/index.php')) ?>" title="<?= e(__('restaurant_pos_exit', 'Back to admin')) ?>">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg><span><?= e(__('restaurant', 'Restaurant')) ?></span>
        </a>
        <nav class="pos-tabs" role="tablist">
            <button type="button" class="pos-tab is-active" data-view="register" role="tab"><?= e(__('restaurant_pos_tab_register', 'Register')) ?><kbd>R</kbd></button>
            <button type="button" class="pos-tab" data-view="tables" role="tab"><?= e(__('restaurant_pos_tab_tables', 'Tables')) ?><kbd>T</kbd></button>
            <?php if ($can('restaurant.kitchen')): ?>
                <a class="pos-tab" href="<?= e(plugin_url('restaurant', 'admin/kds.php')) ?>"><?= e(__('restaurant_kds', 'Kitchen')) ?></a>
            <?php endif; ?>
        </nav>
        <label class="pos-search">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            <input type="search" id="posSearch" placeholder="<?= e(__('restaurant_pos_search', 'Search the menu')) ?>" autocomplete="off">
            <kbd>/</kbd>
        </label>
        <div class="pos-top-right">
            <span class="pos-clock" id="posClock"></span>
            <button type="button" class="pos-icon-btn" id="posHelpBtn" title="<?= e(__('restaurant_pos_shortcuts', 'Keyboard shortcuts')) ?>">?</button>
            <button type="button" class="pos-icon-btn" id="posFullBtn" title="<?= e(__('restaurant_pos_fullscreen', 'Full screen')) ?>"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg></button>
            <span class="pos-user"><?= e((string)($user['name'] ?? '')) ?></span>
        </div>
    </header>

    <aside class="pos-rail">
        <button type="button" class="pos-btn pos-btn-primary pos-new" id="posNew"><?= e(__('restaurant_pos_new_check', 'New check')) ?><kbd>N</kbd></button>
        <div class="pos-seg" id="posRailFilter">
            <button type="button" class="is-active" data-f="all"><?= e(__('restaurant_pos_all', 'All')) ?></button>
            <button type="button" data-f="dine_in"><?= e(__('restaurant_rcpt_dine_in', 'Dine in')) ?></button>
            <button type="button" data-f="togo"><?= e(__('restaurant_pos_togo', 'To go')) ?></button>
        </div>
        <div class="pos-rail-list" id="posChecks"></div>
        <details class="pos-recent">
            <summary><?= e(__('restaurant_pos_recent', 'Recently closed')) ?></summary>
            <div id="posRecent"></div>
        </details>
    </aside>

    <main class="pos-main">
        <section class="pos-menu" id="posMenu">
            <input type="search" class="pos-msearch" id="posSearch2" placeholder="<?= e(__('restaurant_pos_search', 'Search the menu')) ?>" autocomplete="off" aria-label="<?= e(__('restaurant_pos_search', 'Search the menu')) ?>">
            <div class="pos-cats" id="posCats"></div>
            <div class="pos-grid" id="posGrid"></div>
        </section>
        <section class="pos-floor" id="posFloor" hidden></section>
    </main>

    <section class="pos-check" id="posCheck" aria-live="polite"></section>

    <nav class="pos-mobile-nav">
        <button type="button" data-m="rail"><?= e(__('restaurant_pos_checks', 'Checks')) ?></button>
        <button type="button" data-m="main" class="is-active"><?= e(__('restaurant_pos_menu', 'Menu')) ?></button>
        <button type="button" data-m="check"><?= e(__('restaurant_pos_check', 'Check')) ?> <span id="posMobileTotal"></span></button>
    </nav>
</div>

<div class="pos-modal" id="posModal" hidden><div class="pos-sheet" role="dialog" aria-modal="true" id="posSheet"></div></div>
<div class="pos-toast" id="posToast" role="status" hidden></div>

<script>window.POS_BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= e(plugin_url('restaurant', 'assets/pos.js')) ?>?v=<?= e((string)@filemtime(dirname(__DIR__) . '/assets/pos.js')) ?>"></script>
</body>
</html>
