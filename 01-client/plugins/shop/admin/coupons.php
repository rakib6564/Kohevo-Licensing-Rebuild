<?php
/**
 * Shop — coupons: percentage off, an amount off, or free shipping; with an optional minimum, dates, usage limits,
 * the products and categories they apply to, and whether sale items are left out.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/ShopStoreAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('shop.manage_products');
ModuleGuard::require('shop');
ShopStoreAPI::ensureSchema();

$pageTitle  = __('shop_coupons', 'Coupons');
$currentNav = 'shop-coupons';
$self = plugin_url('shop', 'admin/coupons.php');
$flash = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('shop_err_security', 'Security check failed. Please reload the page.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'save') {
            $res = ShopCoupons::save($_POST, $id);
            if ($res['ok']) {
                AuditLog::record('shop.coupon_saved', (string) $res['id']);
                $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => __('shop_saved', 'Saved.')];
                header('Location: ' . $self);
                exit;
            }
            $errors = $res['errors'] ?? [];
            $flash = ['type' => 'error', 'msg' => __('shop_err_fix_fields', 'Please fix the highlighted fields.')];
        } elseif ($action === 'delete' && $id > 0) {
            ShopCoupons::delete($id);
            AuditLog::record('shop.coupon_deleted', (string) $id);
            $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => __('shop_coupon_deleted', 'Coupon deleted.')];
            header('Location: ' . $self);
            exit;
        }
    }
}
if (!$flash && !empty($_SESSION['shop_flash'])) { $flash = $_SESSION['shop_flash']; unset($_SESSION['shop_flash']); }

$edit = (string) ($_GET['edit'] ?? '');
$coupon = ctype_digit($edit) ? ShopCoupons::get((int) $edit) : null;
$editing = $edit === 'new' || $coupon !== null;
$cur = ShopStoreAPI::currency();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs(array_values(array_filter([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop_coupons', 'Coupons'), 'href' => $editing ? $self : null],
    $editing ? ['label' => $coupon ? (string) $coupon['code'] : __('shop_coupon_new', 'New coupon')] : null,
]))); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($editing):
    $posted = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null;
    $c = $coupon ?? ['code' => '', 'kind' => 'percent', 'value' => 10, 'min_subtotal_cents' => 0, 'starts_at' => null, 'ends_at' => null, 'usage_limit' => null,
                     'per_customer' => null, 'used_count' => 0, 'product_ids' => '', 'category_ids' => '', 'exclude_sale' => 0, 'enabled' => 1, 'note' => ''];
    $v = static fn(string $k, string $current): string => e($posted !== null ? (string) ($posted[$k] ?? '') : $current);
    $err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field-error" role="alert">' . e($errors[$k]) . '</div>' : '';
    $kind = $posted !== null ? (string) ($posted['kind'] ?? 'percent') : (string) $c['kind'];
    $valueText = $c['kind'] === 'fixed' ? number_format((int) $c['value'] / 100, 2, '.', '') : (string) $c['value'];
    $dt = static fn(?string $d): string => $d ? substr($d, 0, 16) : '';
    $pickedProducts = ShopCoupons::ids($posted !== null ? ($posted['product_ids'] ?? []) : (string) $c['product_ids']);
    $pickedCats = ShopCoupons::ids($posted !== null ? ($posted['category_ids'] ?? []) : (string) $c['category_ids']);
    $products = Database::rows("SELECT id, title FROM shop_products WHERE tenant_id = ? ORDER BY title LIMIT 1000", [current_tenant_id()]);
?>
<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => __('shop_coupons', 'Coupons')]); ?>
    <?php slate_edit_backlink(['back_href' => $self, 'back_label' => __('shop_all_coupons', 'All coupons')]); ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= (int) ($coupon['id'] ?? 0) ?>">
    <?php slate_edit_card_open(['icon' => 'tag', 'eyebrow' => __('shop_coupon', 'Coupon'), 'title' => $coupon ? (string) $coupon['code'] : __('shop_coupon_new', 'New coupon')]); ?>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="code"><?= __('shop_coupon_code', 'Code') ?></label>
                <input type="text" id="code" name="code" maxlength="40" required style="text-transform:uppercase;" value="<?= $v('code', (string) $c['code']) ?>" placeholder="SPRING10">
                <div class="field-hint"><?= __('shop_coupon_code_hint', 'What customers type. Letters, digits, hyphens and underscores; not case-sensitive.') ?></div><?= $err('code') ?></div>
            <div class="field"><label class="field-label" for="note"><?= __('shop_coupon_note', 'Note (only you see it)') ?></label>
                <input type="text" id="note" name="note" maxlength="255" value="<?= $v('note', (string) ($c['note'] ?? '')) ?>"></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="kind"><?= __('shop_coupon_kind', 'Discount') ?></label>
                <select id="kind" name="kind">
                    <option value="percent" <?= $kind === 'percent' ? 'selected' : '' ?>><?= __('shop_coupon_kind_percent', 'Percentage off') ?></option>
                    <option value="fixed" <?= $kind === 'fixed' ? 'selected' : '' ?>><?= sprintf(__('shop_coupon_kind_fixed', 'Amount off (%s)'), e($cur)) ?></option>
                    <option value="free_shipping" <?= $kind === 'free_shipping' ? 'selected' : '' ?>><?= __('shop_coupon_free_shipping', 'Free shipping') ?></option>
                </select></div>
            <div class="field"><label class="field-label" for="value"><?= __('shop_coupon_value', 'Percentage or amount') ?></label>
                <input type="text" inputmode="decimal" id="value" name="value" value="<?= $v('value', $valueText) ?>">
                <div class="field-hint"><?= __('shop_coupon_value_hint', '10 for 10% off, or 5.00 for 5.00 off. Not used for free shipping.') ?></div><?= $err('value') ?></div>
        </div>
    <?php slate_edit_card_close(); ?>
    <?php slate_edit_card_open(['icon' => 'sliders', 'eyebrow' => __('shop_coupon_rules', 'Rules'), 'title' => __('shop_coupon_when', 'When it works')]); ?>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="min_subtotal"><?= __('shop_coupon_min', 'Minimum order') ?></label>
                <input type="text" inputmode="decimal" id="min_subtotal" name="min_subtotal" value="<?= $v('min_subtotal', number_format((int) $c['min_subtotal_cents'] / 100, 2, '.', '')) ?>"><?= $err('min_subtotal') ?></div>
            <div class="field"><label class="field-check" style="margin-top:1.8rem;"><input type="checkbox" name="exclude_sale" value="1" <?= ($posted !== null ? !empty($posted['exclude_sale']) : !empty($c['exclude_sale'])) ? 'checked' : '' ?>> <?= __('shop_coupon_exclude_sale', 'Not on items already on sale') ?></label></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="starts_at"><?= __('shop_coupon_starts', 'Starts (optional)') ?></label>
                <input type="datetime-local" id="starts_at" name="starts_at" value="<?= $v('starts_at', $dt($c['starts_at'])) ?>"><?= $err('starts_at') ?></div>
            <div class="field"><label class="field-label" for="ends_at"><?= __('shop_coupon_ends', 'Ends (optional)') ?></label>
                <input type="datetime-local" id="ends_at" name="ends_at" value="<?= $v('ends_at', $dt($c['ends_at'])) ?>"><?= $err('ends_at') ?></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="usage_limit"><?= __('shop_coupon_limit', 'Uses in all (optional)') ?></label>
                <input type="number" min="1" id="usage_limit" name="usage_limit" value="<?= $v('usage_limit', $c['usage_limit'] === null ? '' : (string) $c['usage_limit']) ?>">
                <div class="field-hint"><?= sprintf(__('shop_coupon_used', 'Used %d times so far (paid orders).'), (int) $c['used_count']) ?></div><?= $err('usage_limit') ?></div>
            <div class="field"><label class="field-label" for="per_customer"><?= __('shop_coupon_per_customer', 'Uses per customer email (optional)') ?></label>
                <input type="number" min="1" id="per_customer" name="per_customer" value="<?= $v('per_customer', $c['per_customer'] === null ? '' : (string) $c['per_customer']) ?>"><?= $err('per_customer') ?></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="product_ids"><?= __('shop_coupon_products', 'Only these products (optional)') ?></label>
                <select id="product_ids" name="product_ids[]" multiple size="6"><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $pickedProducts, true) ? 'selected' : '' ?>><?= e((string) $p['title']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label class="field-label" for="category_ids"><?= __('shop_coupon_categories', 'Or these categories (optional)') ?></label>
                <select id="category_ids" name="category_ids[]" multiple size="6"><?php foreach (ShopStoreAPI::categories() as $cat): ?><option value="<?= (int) $cat['id'] ?>" <?= in_array((int) $cat['id'], $pickedCats, true) ? 'selected' : '' ?>><?= e((string) $cat['name']) ?></option><?php endforeach; ?></select>
                <div class="field-hint"><?= __('shop_coupon_scope_hint', 'Neither: the whole cart. Ctrl or Cmd to pick several.') ?></div></div>
        </div>
        <div class="field"><label class="field-check"><input type="checkbox" name="enabled" value="1" <?= ($posted !== null ? !empty($posted['enabled']) : !empty($c['enabled'])) ? 'checked' : '' ?>> <?= __('shop_coupon_enabled', 'Active') ?></label></div>
    <?php slate_edit_card_close(); ?>
    <?php slate_edit_actionbar(['buttons_html' => '<button type="submit" class="btn btn-primary">' . __('shop_save', 'Save changes') . '</button>']); ?>
</form>
<?php slate_edit_close(); ?>

<?php else: $coupons = ShopCoupons::all(); ?>
    <div class="page-header"><div><h1><?= __('shop_coupons', 'Coupons') ?></h1></div>
        <div><a class="btn btn-primary" href="?edit=new"><?= __('shop_coupon_add', 'Add a coupon') ?></a></div></div>
    <?php if (!$coupons): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= __('shop_no_coupons', 'No coupons yet') ?></div>
            <p class="text-sm"><?= __('shop_no_coupons_sub', 'Customers type a coupon\'s code in the cart or at checkout.') ?></p></div></div>
    <?php else: ?>
    <div class="card">
        <table class="table">
            <thead><tr><th><?= __('shop_coupon_code', 'Code') ?></th><th><?= __('shop_coupon_kind', 'Discount') ?></th><th><?= __('shop_coupon_uses', 'Uses') ?></th><th><?= __('shop_coupon_dates', 'Dates') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($coupons as $c):
                $ended = !empty($c['ends_at']) && strtotime((string) $c['ends_at']) <= time(); ?>
                <tr<?= empty($c['enabled']) || $ended ? ' style="opacity:.55"' : '' ?>>
                    <td><code><?= e((string) $c['code']) ?></code><?= !empty($c['note']) ? '<br><span class="text-muted text-sm">' . e((string) $c['note']) . '</span>' : '' ?></td>
                    <td><?= e(ShopCoupons::describe($c, $cur)) ?><?= (int) $c['min_subtotal_cents'] > 0 ? '<br><span class="text-muted text-sm">' . e(sprintf(__('shop_from_subtotal', 'from %s'), ShopStoreAPI::money((int) $c['min_subtotal_cents'], $cur))) . '</span>' : '' ?></td>
                    <td><?= (int) $c['used_count'] ?><?= $c['usage_limit'] !== null ? ' / ' . (int) $c['usage_limit'] : '' ?></td>
                    <td class="text-sm"><?= e(trim(($c['starts_at'] ? slate_format_date((string) $c['starts_at']) : '') . ' – ' . ($c['ends_at'] ? slate_format_date((string) $c['ends_at']) : ''), ' –')) ?><?= empty($c['enabled']) ? ' <span class="text-muted">(' . __('shop_off', 'off') . ')</span>' : '' ?></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <a class="btn btn-sm" href="?edit=<?= (int) $c['id'] ?>"><?= __('shop_edit', 'Edit') ?></a>
                        <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('<?= e(__('shop_coupon_delete_confirm', 'Delete this coupon? Orders that used it keep their discount.')) ?>')">
                            <?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button class="btn btn-sm btn-danger"><?= __('shop_delete', 'Delete') ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
