<?php
/**
 * Restaurant — settings: currency, tax, service charge, tip presets, receipt
 * text, and the business address used for the Stripe Terminal Location.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.manage_settings');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = 'Restaurant settings';
$currentNav = 'restaurant-settings';

$flash = null;
$set = function (string $k, string $v): void { Database::setSetting('restaurant.' . $k, $v); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $set('currency',            strtoupper(substr(trim((string)($_POST['currency'] ?? 'USD')), 0, 8)));
        $set('default_tax_rate',    (string)max(0, (float)($_POST['default_tax_rate'] ?? 0)));
        $set('service_charge_auto', !empty($_POST['service_charge_auto']) ? '1' : '0');
        $set('service_charge_pct',  (string)max(0, (float)($_POST['service_charge_pct'] ?? 0)));
        // Normalise tip presets to a clean CSV of positive ints.
        $tips = array_filter(array_map('intval', explode(',', (string)($_POST['tip_presets'] ?? ''))), fn($n) => $n > 0);
        $set('tip_presets', implode(',', $tips));
        $set('receipt_header', mb_substr(trim((string)($_POST['receipt_header'] ?? '')), 0, 500));
        $set('receipt_footer', mb_substr(trim((string)($_POST['receipt_footer'] ?? '')), 0, 500));
        // Business address (Terminal Location).
        $set('biz_name',    mb_substr(trim((string)($_POST['biz_name'] ?? '')), 0, 200));
        $set('addr_line1',  mb_substr(trim((string)($_POST['addr_line1'] ?? '')), 0, 200));
        $set('addr_line2',  mb_substr(trim((string)($_POST['addr_line2'] ?? '')), 0, 200));
        $set('addr_city',   mb_substr(trim((string)($_POST['addr_city'] ?? '')), 0, 120));
        $set('addr_state',  mb_substr(trim((string)($_POST['addr_state'] ?? '')), 0, 120));
        $set('addr_postal', mb_substr(trim((string)($_POST['addr_postal'] ?? '')), 0, 40));
        $set('addr_country',strtoupper(substr(trim((string)($_POST['addr_country'] ?? 'US')), 0, 2)));
        // Online ordering (public storefront).
        $set('online_enabled',     !empty($_POST['online_enabled'])     ? '1' : '0');
        $set('online_pay',         !empty($_POST['online_pay'])         ? '1' : '0');
        $set('online_pay_pickup',  !empty($_POST['online_pay_pickup'])  ? '1' : '0');
        $set('online_delivery',    !empty($_POST['online_delivery'])    ? '1' : '0');
        AuditLog::record('restaurant.settings_saved', 'settings');
        $flash = ['type' => 'success', 'msg' => 'Settings saved.'];
    }
}

$g = fn(string $k, $d = '') => RestaurantAPI::setting($k, $d);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Settings'],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="page-header">
    <div><h1>Restaurant settings</h1></div>
    <a href="<?= e(plugin_url('restaurant', 'admin/help.php')) ?>" class="btn btn-ghost">Help &amp; docs</a>
</div>

<form method="post">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header"><h2>Money &amp; tax</h2></div>
        <div class="card-body">
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="currency">Currency (ISO code)</label>
                    <input type="text" id="currency" name="currency" maxlength="8" value="<?= e((string)$g('currency','USD')) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="default_tax_rate">Default tax rate (%) <span class="field-hint">new items prefill with this</span></label>
                    <input type="number" id="default_tax_rate" name="default_tax_rate" step="0.001" min="0" value="<?= e((string)$g('default_tax_rate','0')) ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Service charge</h2></div>
        <div class="card-body">
            <label class="field-check" style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                <input type="checkbox" name="service_charge_auto" value="1" <?= (string)$g('service_charge_auto','0') === '1' ? 'checked' : '' ?>>
                <span>Add an automatic service charge to new checks</span>
            </label>
            <div class="field" style="max-width:240px;margin:0;">
                <label class="field-label" for="service_charge_pct">Service charge (% of subtotal)</label>
                <input type="number" id="service_charge_pct" name="service_charge_pct" step="0.001" min="0" value="<?= e((string)$g('service_charge_pct','0')) ?>">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Tipping &amp; receipt</h2></div>
        <div class="card-body">
            <div class="field">
                <label class="field-label" for="tip_presets">Tip preset percentages <span class="field-hint">comma-separated, e.g. 15,18,20</span></label>
                <input type="text" id="tip_presets" name="tip_presets" value="<?= e((string)$g('tip_presets','15,18,20')) ?>" style="max-width:240px;">
            </div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="receipt_header">Receipt header</label>
                    <textarea id="receipt_header" name="receipt_header" placeholder="Restaurant name, address…"><?= e((string)$g('receipt_header','')) ?></textarea>
                </div>
                <div class="field">
                    <label class="field-label" for="receipt_footer">Receipt footer</label>
                    <textarea id="receipt_footer" name="receipt_footer" placeholder="Thank you!"><?= e((string)$g('receipt_footer','')) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Business address</h2></div>
        <div class="card-body">
            <p class="text-sm text-muted" style="margin-top:0;">Used to register the Stripe Terminal <strong>Location</strong> (required for card readers). Set this before pairing a reader on the <a href="<?= e(plugin_url('restaurant', 'admin/readers.php')) ?>">Card readers</a> page.</p>
            <div class="field">
                <label class="field-label" for="biz_name">Business name</label>
                <input type="text" id="biz_name" name="biz_name" maxlength="200" value="<?= e((string)$g('biz_name','')) ?>">
            </div>
            <div class="field-row field-row-2">
                <div class="field"><label class="field-label" for="addr_line1">Address line 1</label>
                    <input type="text" id="addr_line1" name="addr_line1" maxlength="200" value="<?= e((string)$g('addr_line1','')) ?>"></div>
                <div class="field"><label class="field-label" for="addr_line2">Address line 2</label>
                    <input type="text" id="addr_line2" name="addr_line2" maxlength="200" value="<?= e((string)$g('addr_line2','')) ?>"></div>
            </div>
            <div class="field-row field-row-4" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:11px;">
                <div class="field"><label class="field-label" for="addr_city">City</label>
                    <input type="text" id="addr_city" name="addr_city" maxlength="120" value="<?= e((string)$g('addr_city','')) ?>"></div>
                <div class="field"><label class="field-label" for="addr_state">State / region</label>
                    <input type="text" id="addr_state" name="addr_state" maxlength="120" value="<?= e((string)$g('addr_state','')) ?>"></div>
                <div class="field"><label class="field-label" for="addr_postal">Postal code</label>
                    <input type="text" id="addr_postal" name="addr_postal" maxlength="40" value="<?= e((string)$g('addr_postal','')) ?>"></div>
                <div class="field"><label class="field-label" for="addr_country">Country (ISO-2)</label>
                    <input type="text" id="addr_country" name="addr_country" maxlength="2" value="<?= e((string)$g('addr_country','US')) ?>"></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Online ordering</h2></div>
        <div class="card-body">
            <p class="text-sm text-muted" style="margin-top:0;">A public storefront at
                <code><?= e(rtrim(SLATE_URL, '/')) ?>/order</code> where customers browse the menu, build a cart, and order.
                Paid/placed orders land in <a href="<?= e(plugin_url('restaurant', 'admin/online.php')) ?>">Online orders</a>.</p>
            <label class="field" style="display:flex;gap:10px;align-items:center;">
                <input type="checkbox" name="online_enabled" value="1" <?= $g('online_enabled','0') === '1' ? 'checked' : '' ?>>
                <span><strong>Enable online ordering</strong> — turn the public storefront on.</span>
            </label>
            <label class="field" style="display:flex;gap:10px;align-items:center;">
                <input type="checkbox" name="online_pay" value="1" <?= $g('online_pay','1') === '1' ? 'checked' : '' ?>>
                <span>Accept <strong>card payment online</strong> (secure Stripe Checkout). Requires the stripe-payment plugin configured.</span>
            </label>
            <label class="field" style="display:flex;gap:10px;align-items:center;">
                <input type="checkbox" name="online_pay_pickup" value="1" <?= $g('online_pay_pickup','1') === '1' ? 'checked' : '' ?>>
                <span>Allow <strong>pay at pickup</strong> (order now, pay in person).</span>
            </label>
            <label class="field" style="display:flex;gap:10px;align-items:center;margin-bottom:0;">
                <input type="checkbox" name="online_delivery" value="1" <?= $g('online_delivery','0') === '1' ? 'checked' : '' ?>>
                <span>Offer <strong>delivery</strong> as an order type (address collected at checkout).</span>
            </label>
        </div>
    </div>

    <div class="form-actions" style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary">Save settings</button>
    </div>
</form>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
