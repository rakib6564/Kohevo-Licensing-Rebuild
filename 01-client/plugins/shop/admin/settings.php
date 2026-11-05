<?php
/**
 * Shop — settings: delivery (flat shipping, free-shipping threshold, pickup), the countries checkout ships to, the
 * addresses of the shop's Studio pages and of a product page, the email that hears about new orders, the units of
 * weight and size, the low-stock level and who hears about it, and a button that creates the Studio pages a shop
 * needs (ShopStudioPages). Payment keys live in each payment gateway's settings.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/ShopStoreAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();
Auth::requirePerm('shop.manage_settings');
ModuleGuard::require('shop');
ShopStoreAPI::ensureSchema();

$pageTitle  = __('shop_settings', 'Shop settings');
$currentNav = 'shop-settings';
$self = plugin_url('shop', 'admin/settings.php');
$flash = null;
$errors = [];

$slugOk = static fn(string $s): bool => (bool) preg_match('#^[a-z0-9-]+(/[a-z0-9-]+){0,2}$#', $s);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('shop_err_security', 'Security check failed. Please reload the page.')];
    } elseif (($_POST['_action'] ?? '') === 'selling') {
        $closedOn = !empty($_POST['closed']);
        ShopStoreAPI::setSetting('closed', $closedOn ? '1' : '0');
        ShopStoreAPI::setSetting('closed_message', mb_substr(trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['closed_message'] ?? ''))), 0, 200));
        if (class_exists('AuditLog')) AuditLog::record($closedOn ? 'shop.selling_off' : 'shop.selling_on', 'shop');
        $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => $closedOn
            ? __('shop_selling_off_saved', 'Selling is off: products stay visible, nothing can be ordered.')
            : __('shop_selling_on_saved', 'Selling is on: customers can order again.')];
        header('Location: ' . $self);
        exit;
    } elseif (in_array($_POST['_action'] ?? '', ['guard', 'guard_resume'], true)) {
        require_once dirname(__DIR__) . '/ShopGuard.php';
        if ($_POST['_action'] === 'guard_resume') {
            ShopGuard::resume();
            AuditLog::record('shop.checkout_resumed', 'shop');
            $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => __('shop_guard_resumed', 'Online checkout is open again.')];
        } else {
            foreach (['guard_ip_10min', 'guard_ip_day', 'guard_email_hour', 'guard_pause_hour', 'guard_coupon_10min'] as $k) {
                $v = trim((string) ($_POST[$k] ?? ''));
                ShopStoreAPI::setSetting($k, preg_match('/^\d{1,5}$/', $v) ? (string) (int) $v : (string) ShopGuard::defaults()[$k]);
            }
            ShopStoreAPI::setSetting('guard_enabled', !empty($_POST['guard_enabled']) ? '1' : '0');
            $site = trim((string) ($_POST['turnstile_site_key'] ?? ''));
            ShopStoreAPI::setSetting('turnstile_site_key', preg_match('/^[0-9A-Za-z_-]{10,100}$/', $site) ? $site : '');
            $msg = ['type' => 'success', 'msg' => __('shop_saved', 'Saved.')];
            try {
                if (!empty($_POST['turnstile_forget'])) ShopGuard::setTurnstileSecret('');
                elseif (trim((string) ($_POST['turnstile_secret'] ?? '')) !== '') ShopGuard::setTurnstileSecret((string) $_POST['turnstile_secret']);
            } catch (\RuntimeException $e) {
                $msg = ['type' => 'error', 'msg' => $e->getMessage()];
            }
            AuditLog::record('shop.guard_saved', 'shop');
            $_SESSION['shop_flash'] = $msg;
        }
        header('Location: ' . $self . '#checkout-protection');
        exit;
    } elseif (($_POST['_action'] ?? '') === 'create_pages') {
        try {
            require_once dirname(__DIR__) . '/studio-widgets.php';
            require_once dirname(__DIR__) . '/studio/ShopStudioPages.php';
            $made = \Slate\Plugin\Shop\Studio\ShopStudioPages::create(StudioRuntimeFactory::build(), StudioActor::fromCurrentSession());
            $new = array_filter($made, static fn(array $p): bool => $p['created']);
            AuditLog::record('shop.pages_created', implode(',', array_column($new, 'slug')));
            $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => $new === []
                ? __('shop_pages_exist', 'The shop pages already exist; nothing was changed.')
                : sprintf(__('shop_pages_created', 'Created and published: %s.'), implode(', ', array_column($new, 'title')))];
        } catch (\Throwable $e) {
            slate_log('Shop: creating Studio pages failed: ' . $e->getMessage(), 'error');
            $_SESSION['shop_flash'] = ['type' => 'error', 'msg' => __('shop_pages_failed', 'The pages could not be created. Check that Kohevo Studio is active and that you can edit pages.')];
        }
        header('Location: ' . $self);
        exit;
    } else {
        $in = static fn(string $k): string => trim((string) ($_POST[$k] ?? ''));
        $shipping = ShopStoreAPI::parseMoney($in('shipping') === '' ? '0' : $in('shipping'));
        $freeOver = ShopStoreAPI::parseMoney($in('free_over') === '' ? '0' : $in('free_over'));
        if ($shipping === null) $errors['shipping'] = __('shop_err_price', 'Enter a price, such as 24.90.');
        if ($freeOver === null) $errors['free_over'] = __('shop_err_price', 'Enter a price, such as 24.90.');
        $shipOn = !empty($_POST['shipping_enabled']);
        $pickOn = !empty($_POST['pickup_enabled']);
        if (!$shipOn && !$pickOn) $errors['delivery'] = __('shop_err_one_delivery', 'Offer at least one way to receive an order.');
        $countries = strtoupper(preg_replace('/[^A-Za-z,\s]/', '', $in('countries')) ?? '');
        foreach (['product_prefix', 'cart_page', 'checkout_page', 'thanks_page'] as $k) {
            if (!$slugOk(strtolower(trim($in($k), '/')))) $errors[$k] = __('shop_err_address_format', 'Use lowercase letters, digits and hyphens.');
        }
        $shopPage = strtolower(trim($in('shop_page'), '/'));
        if ($shopPage !== '' && !$slugOk($shopPage)) $errors['shop_page'] = __('shop_err_address_format', 'Use lowercase letters, digits and hyphens.');
        // A Studio content type with the same address prefix would be served there first: products would never show.
        if (!isset($errors['product_prefix']) && class_exists(\Slate\Module\StudioBuilder\Meta\PageRoutes::class)) {
            $taken = array_search(strtolower(trim($in('product_prefix'), '/')), \Slate\Module\StudioBuilder\Meta\PageRoutes::prefixes((int) current_tenant_id()), true);
            if ($taken !== false) $errors['product_prefix'] = sprintf(__('shop_err_prefix_taken', 'The content type “%s” already uses this address.'), (string) $taken);
        }
        $notify = $in('notify_email');
        if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) $errors['notify_email'] = __('shop_err_email', 'Enter a valid email address.');
        $lowStock = $in('low_stock');
        if ($lowStock !== '' && !preg_match('/^\d{1,6}$/', $lowStock)) $errors['low_stock'] = __('shop_err_whole_number', 'Enter a whole number, or leave it empty.');
        $lowEmail = $in('low_stock_email');
        if ($lowEmail !== '' && !filter_var($lowEmail, FILTER_VALIDATE_EMAIL)) $errors['low_stock_email'] = __('shop_err_email', 'Enter a valid email address.');
        $currency = strtoupper($in('currency'));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) $errors['currency'] = __('shop_err_currency_code', 'Use a three-letter code such as EUR.');
        if (!$errors) {
            ShopStoreAPI::setSetting('shipping_enabled', $shipOn ? '1' : '0');
            ShopStoreAPI::setSetting('pickup_enabled', $pickOn ? '1' : '0');
            ShopStoreAPI::setSetting('shipping_cents', (string) $shipping);
            ShopStoreAPI::setSetting('free_over_cents', (string) $freeOver);
            ShopStoreAPI::setSetting('pickup_label', mb_substr($in('pickup_label'), 0, 120));
            ShopStoreAPI::setSetting('countries', $countries !== '' ? $countries : 'BE');
            ShopStoreAPI::setSetting('currency', $currency);
            ShopStoreAPI::setSetting('notify_email', $notify);
            ShopStoreAPI::setSetting('weight_unit', in_array($in('weight_unit'), ShopStoreAPI::WEIGHT_UNITS, true) ? $in('weight_unit') : 'kg');
            ShopStoreAPI::setSetting('dimension_unit', in_array($in('dimension_unit'), ShopStoreAPI::DIMENSION_UNITS, true) ? $in('dimension_unit') : 'cm');
            ShopStoreAPI::setSetting('low_stock', $lowStock);
            ShopStoreAPI::setSetting('low_stock_email', $lowEmail);
            foreach (['product_prefix', 'cart_page', 'checkout_page', 'thanks_page'] as $k) {
                ShopStoreAPI::setSetting($k, strtolower(trim($in($k), '/')));
            }
            ShopStoreAPI::setSetting('shop_page', $shopPage);
            AuditLog::record('shop.settings_saved', 'shop');
            $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => __('shop_saved', 'Saved.')];
            header('Location: ' . $self);
            exit;
        }
        $flash = ['type' => 'error', 'msg' => __('shop_err_fix_fields', 'Please fix the highlighted fields.')];
    }
}
if (!$flash && !empty($_SESSION['shop_flash'])) { $flash = $_SESSION['shop_flash']; unset($_SESSION['shop_flash']); }

$posted = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null;
$val = static function (string $postKey, string $current) use ($posted): string { return e($posted !== null ? (string) ($posted[$postKey] ?? '') : $current); };
$money = static fn(string $setting): string => number_format(((int) ShopStoreAPI::setting($setting)) / 100, 2, '.', '');
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field-error" role="alert">' . e($errors[$k]) . '</div>' : '';
$paymentsReady = ShopStoreAPI::canTakePayments();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop', 'Shop'), 'href' => plugin_url('shop', 'admin/orders.php')],
    ['label' => __('shop_settings', 'Shop settings')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$paymentsReady): ?>
    <div class="alert alert-warning" role="status"><?= __('shop_payments_not_ready', 'No payment gateway is set up for the shop yet, so checkout cannot take payments. Connect Stripe or PayPal, and check that it is offered for the shop.') ?>
        <a href="<?= e(SLATE_URL . '/admin/payment-gateways.php') ?>"><?= __('shop_open_gateways', 'Open Payment gateways') ?></a></div>
<?php endif; ?>

<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => __('shop_settings', 'Shop settings')]); ?>
<?php $closedNow = ShopStoreAPI::closed(); ?>
<?php slate_edit_card_open(['icon' => 'tag', 'eyebrow' => __('shop_selling', 'Selling'), 'title' => __('shop_selling_title', 'Online ordering')]); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="selling">
        <?php if ($closedNow): ?><div class="alert alert-warning" role="status"><?= e(__('shop_selling_is_off', 'Selling is off. Products are visible but cannot be added to the cart or ordered.')) ?></div><?php endif; ?>
        <label class="field-check" style="margin-bottom:12px;"><input type="checkbox" name="closed" value="1" <?= $closedNow ? 'checked' : '' ?>> <?= __('shop_selling_off', 'Pause selling (out of season, not producing)') ?></label>
        <p class="field-hint" style="margin:-6px 0 12px;"><?= __('shop_selling_off_hint', 'Product pages, prices and photos stay online. Add to cart is replaced by the line below, and the cart and checkout refuse orders. Search engines are told the products are out of stock.') ?></p>
        <label class="field-label" for="closed_message"><?= __('shop_closed_message', 'Line shown instead of Add to cart') ?></label>
        <input type="text" id="closed_message" name="closed_message" maxlength="200" value="<?= e(ShopStoreAPI::setting('closed_message')) ?>" placeholder="<?= e(__('shop_closed_default', 'Currently not available.')) ?>">
        <p style="margin-top:14px;"><button type="submit" class="btn btn-primary"><?= __('shop_selling_save', 'Save') ?></button></p>
    </form>
<?php slate_edit_card_close(); ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save">

    <?php slate_edit_card_open(['icon' => 'truck', 'eyebrow' => __('shop_delivery', 'Delivery'), 'title' => __('shop_shipping_pickup', 'Shipping and pickup')]); ?>
        <?php $shipOn = $posted !== null ? !empty($posted['shipping_enabled']) : ShopStoreAPI::setting('shipping_enabled') !== '0';
              $pickOn = $posted !== null ? !empty($posted['pickup_enabled']) : ShopStoreAPI::setting('pickup_enabled') !== '0'; ?>
        <div class="field"><label class="field-check"><input type="checkbox" name="shipping_enabled" value="1" <?= $shipOn ? 'checked' : '' ?>> <?= __('shop_offer_shipping_any', 'Ship orders') ?></label></div>
        <?php if (ShopShipping::hasZones()): ?>
            <div class="alert alert-info" role="status"><?= __('shop_zones_in_use', 'Shipping zones set the rates, so the flat rate and threshold below are not used.') ?> <a href="<?= e(plugin_url('shop', 'admin/shipping.php')) ?>"><?= __('shop_open_shipping', 'Open Shipping') ?></a></div>
        <?php else: ?>
            <p class="text-sm text-muted"><?= __('shop_flat_until_zones', 'One flat rate everywhere. For rates by place or weight, or live carrier rates, add zones in Shipping.') ?> <a href="<?= e(plugin_url('shop', 'admin/shipping.php')) ?>"><?= __('shop_open_shipping', 'Open Shipping') ?></a></p>
        <?php endif; ?>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="shipping"><?= __('shop_flat_rate', 'Flat shipping rate') ?> </label>
                <input type="text" inputmode="decimal" id="shipping" name="shipping" value="<?= $val('shipping', $money('shipping_cents')) ?>">
                <?= $err('shipping') ?>
            </div>
            <div class="field">
                <label class="field-label" for="free_over"><?= __('shop_free_over', 'Free shipping from') ?></label>
                <input type="text" inputmode="decimal" id="free_over" name="free_over" value="<?= $val('free_over', $money('free_over_cents')) ?>">
                <div class="field-hint"><?= __('shop_free_over_hint', 'Order subtotal at which shipping is free. 0 = never free.') ?></div>
                <?= $err('free_over') ?>
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="countries"><?= __('shop_countries', 'Countries we ship to') ?></label>
            <input type="text" id="countries" name="countries" value="<?= $val('countries', ShopStoreAPI::setting('countries')) ?>" placeholder="BE, FR, NL, LU">
            <div class="field-hint"><?= __('shop_countries_hint', 'Two-letter codes, separated by commas.') ?></div>
        </div>
        <div class="field"><label class="field-check"><input type="checkbox" name="pickup_enabled" value="1" <?= $pickOn ? 'checked' : '' ?>> <?= __('shop_offer_pickup', 'Let customers pick up their order (free)') ?></label></div>
        <div class="field" style="margin-bottom:0;">
            <label class="field-label" for="pickup_label"><?= __('shop_pickup_label', 'Pickup wording') ?></label>
            <input type="text" id="pickup_label" name="pickup_label" maxlength="120" value="<?= $val('pickup_label', ShopStoreAPI::setting('pickup_label')) ?>" placeholder="<?= e(__('shop_pickup', 'Pick up (free)')) ?>">
            <?= $err('delivery') ?>
        </div>
    <?php slate_edit_card_close(); ?>

    <?php slate_edit_card_open(['icon' => 'globe', 'eyebrow' => __('shop_site', 'Site'), 'title' => __('shop_pages', 'Pages')]); ?>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="product_prefix"><?= __('shop_product_prefix', 'Product address') ?></label>
                <input type="text" id="product_prefix" name="product_prefix" value="<?= $val('product_prefix', ShopStoreAPI::productPrefix()) ?>">
                <div class="field-hint">/<?= e(ShopStoreAPI::productPrefix()) ?>/t-shirt</div>
                <?= $err('product_prefix') ?>
            </div>
            <div class="field">
                <label class="field-label" for="cart_page"><?= __('shop_cart_page', 'Cart page') ?></label>
                <input type="text" id="cart_page" name="cart_page" value="<?= $val('cart_page', ShopStoreAPI::setting('cart_page')) ?>">
                <?= $err('cart_page') ?>
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="checkout_page"><?= __('shop_checkout_page', 'Checkout page') ?></label>
                <input type="text" id="checkout_page" name="checkout_page" value="<?= $val('checkout_page', ShopStoreAPI::setting('checkout_page')) ?>">
                <?= $err('checkout_page') ?>
            </div>
            <div class="field">
                <label class="field-label" for="thanks_page"><?= __('shop_thanks_page', 'Order received page') ?></label>
                <input type="text" id="thanks_page" name="thanks_page" value="<?= $val('thanks_page', ShopStoreAPI::setting('thanks_page')) ?>">
                <?= $err('thanks_page') ?>
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="shop_page"><?= __('shop_shop_page', 'Shop page') ?></label>
                <input type="text" id="shop_page" name="shop_page" value="<?= $val('shop_page', ShopStoreAPI::setting('shop_page')) ?>">
                <div class="field-hint"><?= __('shop_shop_page_hint', 'The cart’s “Continue shopping” button leads here. Leave empty to hide it.') ?></div>
                <?= $err('shop_page') ?>
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="currency"><?= __('shop_currency', 'Currency for new products') ?></label>
                <input type="text" id="currency" name="currency" maxlength="3" value="<?= $val('currency', ShopStoreAPI::setting('currency')) ?>" placeholder="<?= e(ShopStoreAPI::currency()) ?>">
                <div class="field-hint"><?= __('shop_currency_hint', 'A product keeps the currency it was created in; changing this never re-prices existing products.') ?></div>
                <?= $err('currency') ?>
            </div>
            <div class="field">
                <label class="field-label" for="notify_email"><?= __('shop_notify_email', 'Email new orders to') ?></label>
                <input type="email" id="notify_email" name="notify_email" value="<?= $val('notify_email', ShopStoreAPI::setting('notify_email')) ?>">
                <?= $err('notify_email') ?>
            </div>
        </div>
    <?php slate_edit_card_close(); ?>

    <?php slate_edit_card_open(['icon' => 'package', 'eyebrow' => __('shop_catalogue', 'Catalogue'), 'title' => __('shop_units_stock', 'Units and stock alerts')]); ?>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="weight_unit"><?= __('shop_weight_unit', 'Weight unit') ?></label>
                <?php $wu = $posted !== null ? (string) ($posted['weight_unit'] ?? '') : ShopStoreAPI::weightUnit(); ?>
                <select id="weight_unit" name="weight_unit"><?php foreach (ShopStoreAPI::WEIGHT_UNITS as $u): ?><option value="<?= $u ?>" <?= $wu === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select>
            </div>
            <div class="field">
                <label class="field-label" for="dimension_unit"><?= __('shop_dimension_unit', 'Size unit') ?></label>
                <?php $du = $posted !== null ? (string) ($posted['dimension_unit'] ?? '') : ShopStoreAPI::dimensionUnit(); ?>
                <select id="dimension_unit" name="dimension_unit"><?php foreach (ShopStoreAPI::DIMENSION_UNITS as $u): ?><option value="<?= $u ?>" <?= $du === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="field-hint" style="margin:-4px 0 14px;"><?= __('shop_units_hint', 'Products keep the numbers you typed: changing a unit does not convert them.') ?></div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="low_stock"><?= __('shop_low_stock_level', 'Low-stock alert at') ?></label>
                <input type="number" id="low_stock" name="low_stock" min="0" step="1" value="<?= $val('low_stock', ShopStoreAPI::setting('low_stock')) ?>">
                <div class="field-hint"><?= __('shop_low_stock_shop_hint', 'For products that track stock. A product can set its own level. Empty: no alerts.') ?></div>
                <?= $err('low_stock') ?>
            </div>
            <div class="field">
                <label class="field-label" for="low_stock_email"><?= __('shop_low_stock_email', 'Email low stock to') ?></label>
                <input type="email" id="low_stock_email" name="low_stock_email" value="<?= $val('low_stock_email', ShopStoreAPI::setting('low_stock_email')) ?>" placeholder="<?= e(ShopStoreAPI::setting('notify_email')) ?>">
                <div class="field-hint"><?= __('shop_low_stock_email_hint', 'Empty: the address that hears about new orders.') ?></div>
                <?= $err('low_stock_email') ?>
            </div>
        </div>
    <?php slate_edit_card_close(); ?>

    <?php slate_edit_actionbar(['buttons_html' => '<button type="submit" class="btn btn-primary">' . __('shop_save', 'Save changes') . '</button>']); ?>
</form>

<?php require_once dirname(__DIR__) . '/ShopGuard.php';
$gOn = ShopGuard::enabled();
$paused = ShopGuard::pausedUntil() > time();
$hasSecret = ShopGuard::turnstileSecret() !== '';
$gnum = static fn(string $k, string $label, string $hint): string => '<div class="field"><label class="field-label" for="' . $k . '">' . e($label) . '</label>'
    . '<input type="number" id="' . $k . '" name="' . $k . '" min="0" max="99999" step="1" style="max-width:120px;" value="' . e(ShopStoreAPI::setting($k)) . '"><div class="field-hint">' . e($hint) . '</div></div>'; ?>
<div id="checkout-protection"></div>
<?php slate_edit_card_open(['icon' => 'shield', 'eyebrow' => __('shop_guard_eyebrow', 'Security'), 'title' => __('shop_guard_title', 'Checkout protection')]); ?>
    <p class="text-sm"><?= e(__('shop_guard_intro', 'Stops card testers and bots from placing order after order: a hidden trap and a minimum time to fill the form, limits per visitor and per email, and a pause of online checkout when unpaid orders pile up (the notification address is told). Card numbers are typed on the payment page, never here.')) ?></p>
    <?php if ($paused): ?>
        <div class="alert alert-warning" role="status"><?= e(sprintf(__('shop_guard_paused_admin', 'Online checkout is paused until %s because many orders were left unpaid in the last hour.'), date('H:i', ShopGuard::pausedUntil()))) ?>
            <form method="post" style="display:inline;margin-left:8px;"><?= csrf_field() ?><input type="hidden" name="_action" value="guard_resume"><button type="submit" class="btn btn-sm"><?= __('shop_guard_resume', 'Start checkout again') ?></button></form></div>
    <?php endif; ?>
    <form method="post" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="guard">
        <label class="field-check" style="margin-bottom:12px;"><input type="checkbox" name="guard_enabled" value="1" <?= $gOn ? 'checked' : '' ?>> <?= __('shop_guard_on', 'Protect checkout (recommended)') ?></label>
        <div class="field-row field-row-3">
            <?= $gnum('guard_ip_10min', __('shop_guard_ip_10min', 'Orders per visitor, 10 minutes'), __('shop_guard_zero_off', '0 switches this limit off.')) ?>
            <?= $gnum('guard_ip_day', __('shop_guard_ip_day', 'Orders per visitor, a day'), __('shop_guard_zero_off', '0 switches this limit off.')) ?>
            <?= $gnum('guard_email_hour', __('shop_guard_email_hour', 'Unpaid orders per email, an hour'), __('shop_guard_zero_off', '0 switches this limit off.')) ?>
        </div>
        <div class="field-row field-row-3">
            <?= $gnum('guard_pause_hour', __('shop_guard_pause_hour', 'Pause checkout at unpaid orders, an hour'), __('shop_guard_pause_hint', 'For the whole shop. Set it above your busiest hour.')) ?>
            <?= $gnum('guard_coupon_10min', __('shop_guard_coupon_10min', 'Discount codes tried per visitor, 10 minutes'), __('shop_guard_zero_off', '0 switches this limit off.')) ?>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="turnstile_site_key"><?= __('shop_turnstile_site', 'Cloudflare Turnstile site key (optional)') ?></label>
                <input type="text" id="turnstile_site_key" name="turnstile_site_key" autocomplete="off" spellcheck="false" value="<?= e(ShopStoreAPI::setting('turnstile_site_key')) ?>">
                <div class="field-hint"><?= __('shop_turnstile_hint', 'A free check from Cloudflare that most people never see and scripts cannot pass. Create a widget for this site in the Cloudflare dashboard (Turnstile) and paste both keys.') ?></div>
            </div>
            <div class="field">
                <label class="field-label" for="turnstile_secret"><?= __('shop_turnstile_secret', 'Turnstile secret key') ?></label>
                <input type="password" id="turnstile_secret" name="turnstile_secret" autocomplete="new-password" placeholder="<?= e($hasSecret ? __('shop_key_kept', 'Saved — leave empty to keep it') : '') ?>">
                <?php if ($hasSecret): ?><label class="field-check"><input type="checkbox" name="turnstile_forget" value="1"> <?= __('shop_key_forget', 'Remove the saved key') ?></label><?php endif; ?>
                <div class="field-hint"><?= __('shop_turnstile_secret_hint', 'Stored sealed. Turnstile is used only when both keys are set.') ?></div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('shop_guard_save', 'Save protection') ?></button>
    </form>
<?php slate_edit_card_close(); ?>

<?php slate_edit_card_open(['icon' => 'layout', 'eyebrow' => 'Kohevo Studio', 'title' => __('shop_studio_pages', 'Shop pages in Studio')]); ?>
    <p class="text-sm"><?= __('shop_studio_pages_hint', 'Creates and publishes the Cart, Checkout and Order received pages, and the product page template (a Single template for Products), each laid out with the Shop widgets. Pages that already exist are left as they are. Add the Mini Cart widget to your header yourself.') ?></p>
    <form method="post" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="create_pages">
        <button type="submit" class="btn"><?= __('shop_create_pages', 'Create the shop pages') ?></button>
    </form>
<?php slate_edit_card_close(); ?>
<?php slate_edit_close(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
