<?php
/**
 * Shop — settings: delivery (flat shipping, free-shipping threshold, pickup), the countries checkout ships to, the
 * addresses of the shop's Studio pages and of a product page, the email that hears about new orders, and a button that
 * creates the Studio pages a shop needs (ShopStudioPages). Payment keys live in the Stripe Payment plugin's settings.
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
        $notify = $in('notify_email');
        if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) $errors['notify_email'] = __('shop_err_email', 'Enter a valid email address.');
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
            foreach (['product_prefix', 'cart_page', 'checkout_page', 'thanks_page'] as $k) {
                ShopStoreAPI::setSetting($k, strtolower(trim($in($k), '/')));
            }
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
$stripeReady = class_exists('StripePaymentAPI') && StripePaymentAPI::isConfigured();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop', 'Shop'), 'href' => plugin_url('shop', 'admin/orders.php')],
    ['label' => __('shop_settings', 'Shop settings')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
<?php if (!$stripeReady): ?>
    <div class="alert alert-warning" role="status"><?= __('shop_stripe_not_ready', 'Stripe is not set up yet, so checkout cannot take payments. Add your keys in the Stripe Payment settings.') ?>
        <?php if (function_exists('plugin_url')): ?> <a href="<?= e(plugin_url('stripe-payment', 'admin/settings.php')) ?>"><?= __('shop_open_stripe', 'Open Stripe settings') ?></a><?php endif; ?></div>
<?php endif; ?>

<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => __('shop_settings', 'Shop settings')]); ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save">

    <?php slate_edit_card_open(['icon' => 'truck', 'eyebrow' => __('shop_delivery', 'Delivery'), 'title' => __('shop_shipping_pickup', 'Shipping and pickup')]); ?>
        <?php $shipOn = $posted !== null ? !empty($posted['shipping_enabled']) : ShopStoreAPI::setting('shipping_enabled') !== '0';
              $pickOn = $posted !== null ? !empty($posted['pickup_enabled']) : ShopStoreAPI::setting('pickup_enabled') !== '0'; ?>
        <div class="field"><label class="field-check"><input type="checkbox" name="shipping_enabled" value="1" <?= $shipOn ? 'checked' : '' ?>> <?= __('shop_offer_shipping', 'Ship orders (flat rate)') ?></label></div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="shipping"><?= __('shop_flat_rate', 'Flat shipping rate') ?> <span class="text-muted text-xs">(<?= __('shop_incl_vat', 'incl. VAT') ?>)</span></label>
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

    <?php slate_edit_actionbar(['buttons_html' => '<button type="submit" class="btn btn-primary">' . __('shop_save', 'Save changes') . '</button>']); ?>
</form>

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
