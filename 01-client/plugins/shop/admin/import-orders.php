<?php
/**
 * Shop — import orders and customers from WooCommerce.
 *
 * 1. Upload the order export (tools/woo-export-orders.php, or a WooCommerce REST API order list). The file holds
 *    customers' personal details, so it is kept in a private folder, readable by the server only, and deleted after
 *    the import or after a day.
 * 2. Preview: how many orders and customers would be created, by status, and lines that match no product.
 * 3. Import. No email is sent and no stock moves; running the same file again skips the orders already imported.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/ShopStoreAPI.php';
require_once dirname(__DIR__) . '/ShopOrderImport.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
if (!Auth::can('shop.manage_orders') && !Auth::isSuperAdmin()) Auth::requirePerm('shop.manage_orders');
ModuleGuard::require('shop');
ShopStoreAPI::ensureSchema();

$pageTitle  = __('shop_orders_import_title', 'Import orders');
$currentNav = 'shop-orders';
$self = plugin_url('shop', 'admin/import-orders.php');
$ordersUrl = plugin_url('shop', 'admin/orders.php');
$flash = null;
$result = null;

// data/ is never served (its .htaccess and the dev router refuse it).
$dir = SLATE_ROOT . '/data/shop-import';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
foreach (glob($dir . '/*.orders') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);

$session = $_SESSION['shop_order_import'] ?? null;
$file = is_array($session) && preg_match('/^[a-f0-9]{32}$/', (string) ($session['token'] ?? '')) ? $dir . '/' . $session['token'] . '.orders' : '';
if ($file !== '' && !is_file($file)) { $file = ''; unset($_SESSION['shop_order_import']); $session = null; }
$forget = static function () use (&$file, &$session): void {
    if ($file !== '') @unlink($file);
    unset($_SESSION['shop_order_import']);
    $file = '';
    $session = null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('shop_err_security', 'Security check failed. Please reload the page.')];
    } elseif ($action === 'upload') {
        $up = $_FILES['file'] ?? null;
        if (!is_array($up) || (int) ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $up['tmp_name'])) {
            $flash = ['type' => 'error', 'msg' => __('shop_err_file_missing', 'No file was received.')];
        } elseif ((int) $up['size'] > ShopOrderImport::MAX_BYTES) {
            $flash = ['type' => 'error', 'msg' => __('shop_err_file_large', 'The file is too large.')];
        } else {
            $token = bin2hex(random_bytes(16));
            $target = $dir . '/' . $token . '.orders';
            if (@move_uploaded_file((string) $up['tmp_name'], $target)) {
                @chmod($target, 0600);
                $forget();
                $_SESSION['shop_order_import'] = ['token' => $token, 'name' => mb_substr(basename((string) $up['name']), 0, 120)];
                header('Location: ' . $self);
                exit;
            }
            $flash = ['type' => 'error', 'msg' => __('shop_err_file_save', 'The file could not be saved.')];
        }
    } elseif ($action === 'clear') {
        $forget();
        header('Location: ' . $self);
        exit;
    } elseif ($action === 'run' && $file !== '') {
        $read = ShopOrderImport::read((string) file_get_contents($file));
        if ($read['ok']) {
            $result = ShopOrderImport::run($read['orders'], ['include_failed' => !empty($_POST['include_failed'])]);
            $forget();
        }
    }
}

$read = $file !== '' ? ShopOrderImport::read((string) file_get_contents($file)) : null;
$includeFailed = !empty($_GET['failed']);
$plan = $read !== null && $read['ok'] ? ShopOrderImport::plan($read['orders'], ['include_failed' => $includeFailed]) : null;
$statusName = static fn(string $s): string => match ($s) {
    'completed' => __('shop_orders_import_s_completed', 'Completed (imported as fulfilled)'),
    'processing' => __('shop_orders_import_s_processing', 'Processing (imported as paid)'),
    'on-hold', 'pending' => __('shop_orders_import_s_pending', 'Pending payment or on hold'),
    'cancelled' => __('shop_orders_import_s_cancelled', 'Cancelled'),
    'refunded' => __('shop_orders_import_s_refunded', 'Refunded'),
    'failed' => __('shop_orders_import_s_failed', 'Failed (imported as cancelled)'),
    default => $s,
};

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop_orders', 'Orders'), 'href' => $ordersUrl],
    ['label' => __('shop_orders_import_title', 'Import orders')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="page-header"><div><h1><?= __('shop_orders_import_title', 'Import orders') ?></h1>
    <p class="text-muted text-sm"><?= __('shop_orders_import_sub', 'Bring a WooCommerce shop\'s orders and customers over, with their dates, addresses, lines and notes. No email is sent and stock does not change.') ?></p></div></div>

<?php if ($result !== null): ?>
    <div class="card" style="padding:20px;">
        <h2 style="margin-top:0;"><?= __('shop_import_done', 'Import finished') ?></h2>
        <p><?= e(sprintf(__('shop_orders_import_done_counts', '%1$d orders imported (%2$d lines), %3$d already here, %4$d left out. %5$d new customers, %6$d linked to existing customers.'),
            $result['created'], $result['lines'], $result['existing'], $result['skipped'], $result['customers_created'], $result['customers_linked'])) ?></p>
        <?php if ($result['unmatched'] > 0): ?><p class="text-muted text-sm"><?= e(sprintf(__('shop_orders_import_unmatched_count', '%d lines match no product in this shop; they keep their name and price.'), $result['unmatched'])) ?></p><?php endif; ?>
        <?php if ($result['errors']): ?>
            <div class="alert alert-warning"><strong><?= __('shop_import_problems', 'Some things did not carry over:') ?></strong>
                <ul style="margin:8px 0 0 18px;"><?php foreach (array_slice($result['errors'], 0, 50) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <p class="text-muted text-sm"><?= __('shop_orders_import_delete_file', 'The uploaded file has been deleted from the server. Delete your own copy too: it holds customers\' personal details.') ?></p>
        <p class="sb-button-row"><a class="btn btn-primary" href="<?= e($ordersUrl) ?>"><?= __('shop_open_orders', 'Open the orders') ?></a></p>
    </div>
<?php elseif ($file === ''): ?>
    <?php slate_editor_css(); ?>
    <?php slate_edit_open(['title_fallback' => __('shop_orders_import_title', 'Import orders')]); ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="upload">
        <?php slate_edit_card_open(['icon' => 'upload', 'eyebrow' => __('shop_import_step1', 'Step 1'), 'title' => __('shop_import_choose', 'Choose the file')]); ?>
            <div class="field">
                <input type="file" name="file" accept=".json,application/json" required aria-label="<?= e(__('shop_orders_import_file', 'Order file')) ?>">
                <div class="field-hint"><?= __('shop_orders_import_file_hint', 'On the WooCommerce site, run the Shop\'s export script with WP-CLI (wp eval-file plugins/shop/tools/woo-export-orders.php > orders.json), or use a JSON order list from the WooCommerce REST API. Import the products first, so order lines link to them. Nothing is imported before you see a preview.') ?></div>
            </div>
        <?php slate_edit_card_close(); ?>
        <?php slate_edit_actionbar(['buttons_html' => '<button type="submit" class="btn btn-primary">' . __('shop_import_preview', 'Preview') . '</button>'
            . '<a href="' . e($ordersUrl) . '" class="btn btn-ghost">' . __('shop_cancel', 'Cancel') . '</a>']); ?>
    </form>
    <?php slate_edit_close(); ?>
<?php elseif (!$read['ok']): ?>
    <div class="alert alert-error"><?= e($read['error']) ?></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="clear"><button class="btn"><?= __('shop_import_other', 'Choose another file') ?></button></form>
<?php else: ?>
    <div class="dwidget-kpis" style="margin-bottom:16px;">
        <?php foreach ([__('shop_orders_import_new_orders', 'Orders to import') => $plan['import'], __('shop_orders_import_existing', 'Already here') => $plan['existing'],
                        __('shop_orders_import_left_out', 'Left out') => $plan['skipped'], __('shop_orders_import_customers', 'Customers') => $plan['customers'],
                        __('shop_orders_import_new_customers', 'New customers') => $plan['new_customers']] as $k => $n): ?>
            <div class="dwidget-kpi"><div class="dwidget-kpi-k"><?= e($k) ?></div><div class="dwidget-kpi-v"><?= (int) $n ?></div></div>
        <?php endforeach; ?>
    </div>
    <div class="card" style="padding:16px 20px;margin-bottom:16px;">
        <ul style="margin:0 0 0 18px;">
            <?php foreach ($plan['by_status'] as $s => $n): ?><li><?= e($statusName((string) $s)) ?>: <?= (int) $n ?></li><?php endforeach; ?>
            <?php if ($plan['import'] > 0): ?><li><?= e(sprintf(__('shop_orders_import_value', 'Order value: %s'), ShopStoreAPI::money($plan['total_cents'], $plan['currency'] ?: ShopStoreAPI::currency()))) ?></li><?php endif; ?>
        </ul>
        <?php if ($plan['skipped'] > 0 && !$includeFailed): ?>
            <p class="text-muted text-sm" style="margin:10px 0 0;"><?= e(sprintf(__('shop_orders_import_failed_hint', '%d failed or draft orders are left out: declined cards and card testing, not sales.'), $plan['skipped'])) ?>
                <a href="<?= e($self . '?failed=1') ?>"><?= __('shop_orders_import_include_failed_link', 'Include them as cancelled orders') ?></a></p>
        <?php endif; ?>
        <?php if ($plan['warnings']): ?>
            <div class="alert alert-warning" style="margin:12px 0 0;"><ul style="margin:0 0 0 18px;"><?php foreach (array_slice($plan['warnings'], 0, 50) as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
    </div>

    <?php slate_editor_css(); ?>
    <?php slate_edit_open(['title_fallback' => __('shop_orders_import_title', 'Import orders')]); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="run">
        <?php if ($includeFailed): ?><input type="hidden" name="include_failed" value="1"><?php endif; ?>
        <?php slate_edit_actionbar(['buttons_html' => '<button type="submit" class="btn btn-primary"' . ($plan['import'] === 0 ? ' disabled' : '') . '>' . e(sprintf(__('shop_orders_import_run', 'Import %d orders'), $plan['import'])) . '</button>']); ?>
    </form>
    <form method="post" style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="_action" value="clear"><button class="btn btn-ghost"><?= __('shop_import_other', 'Choose another file') ?></button></form>
    <?php slate_edit_close(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
