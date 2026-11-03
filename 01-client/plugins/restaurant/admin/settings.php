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
        // Back to the tab that was open, so a save never loses the operator's place.
        $tab = preg_replace('/[^a-z]/', '', (string)($_POST['tab'] ?? ''));
        header('Location: ' . plugin_url('restaurant', 'admin/settings.php') . '?saved=1' . ($tab !== '' ? '&tab=' . $tab : ''));
        exit;
    }
}
if (!$flash && !empty($_GET['saved'])) $flash = ['type' => 'success', 'msg' => __('restaurant_settings_saved', 'Settings saved.')];
$TABS = ['general' => __('restaurant_settings_tab_general', 'Money & tax'), 'receipt' => __('restaurant_settings_tab_receipt', 'Tips & receipt'),
         'business' => __('restaurant_settings_tab_business', 'Business'), 'online' => __('restaurant_settings_tab_online', 'Online ordering')];
$tab = array_key_exists($_GET['tab'] ?? '', $TABS) ? (string)$_GET['tab'] : 'general';

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

<form method="post" id="rsForm">
    <?= csrf_field() ?>
    <input type="hidden" name="tab" id="rsTab" value="<?= e($tab) ?>">
    <nav class="settings-tabs" role="tablist" aria-label="<?= e(__('restaurant_settings', 'Restaurant settings')) ?>">
        <?php foreach ($TABS as $k => $label): ?>
            <a href="?tab=<?= e($k) ?>" class="settings-tab<?= $tab === $k ? ' is-active' : '' ?>" role="tab" data-tab="<?= e($k) ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="rs-panel" data-panel="general"<?= $tab === 'general' ? '' : ' hidden' ?>>

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

    </div>

    <div class="rs-panel" data-panel="receipt"<?= $tab === 'receipt' ? '' : ' hidden' ?>>
    <div class="rs-split">
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
    <aside class="card rs-preview" aria-label="<?= e(__('restaurant_settings_preview', 'Receipt preview')) ?>">
        <div class="card-header"><h2><?= e(__('restaurant_settings_preview', 'Receipt preview')) ?></h2>
            <span class="text-xs text-muted"><?= e(__('restaurant_settings_preview_hint', 'Updates as you type. Sample items.')) ?></span></div>
        <div class="rs-paper" id="rsPaper"></div>
    </aside>
    </div>
    </div>

    <div class="rs-panel" data-panel="business"<?= $tab === 'business' ? '' : ' hidden' ?>>

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

    </div>

    <div class="rs-panel" data-panel="online"<?= $tab === 'online' ? '' : ' hidden' ?>>
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

    </div>

    <div class="form-actions rs-actions">
        <button type="submit" class="btn btn-primary">Save settings</button>
        <span class="text-sm text-muted"><?= e(__('restaurant_settings_all_tabs', 'Saves every tab at once.')) ?></span>
    </div>
</form>

<style>
.settings-tabs { margin-bottom: 14px; }
.rs-split { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 14px; align-items: start; }
.rs-preview { position: sticky; top: 12px; }
.rs-paper { margin: 0 16px 16px; padding: 16px 14px; background: #fff; color: #111; border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm);
    font: 12.5px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.rs-paper .c { text-align: center; }
.rs-paper .b { font-size: 15px; font-weight: 700; }
.rs-paper .r { display: flex; justify-content: space-between; gap: 8px; }
.rs-paper hr { border: 0; border-top: 1px dashed #999; margin: 8px 0; }
.rs-paper .m { color: #555; padding-left: 2em; }
.rs-paper .t { font-weight: 700; }
.rs-paper .ph { color: #999; font-style: italic; }
.rs-actions { display: flex; align-items: center; gap: 12px; position: sticky; bottom: 0; padding: 12px 0; background: var(--bg); }
@media (max-width: 960px) { .rs-split { grid-template-columns: 1fr; } .rs-preview { position: static; } }
</style>
<script>
(function () {
    const tabs = document.querySelectorAll('.settings-tabs [data-tab]');
    tabs.forEach((a) => a.addEventListener('click', (e) => {
        e.preventDefault();
        const k = a.dataset.tab;
        tabs.forEach((x) => { x.classList.toggle('is-active', x === a); x.setAttribute('aria-selected', x === a ? 'true' : 'false'); });
        document.querySelectorAll('.rs-panel').forEach((p) => { p.hidden = p.dataset.panel !== k; });
        document.getElementById('rsTab').value = k;
        history.replaceState(null, '', '?tab=' + k);
    }));
    const L = <?= json_encode(__js([
        'restaurant_settings_ph_name'   => 'Your business name',
        'restaurant_rcpt_order'         => 'Order',
        'restaurant_rcpt_subtotal'      => 'Subtotal',
        'restaurant_rcpt_tax'           => 'Tax',
        'restaurant_rcpt_tip'           => 'Tip',
        'restaurant_rcpt_total'         => 'Total',
        'restaurant_rcpt_card'          => 'Card',
        'restaurant_settings_sample_1'  => 'House special',
        'restaurant_settings_sample_2'  => 'Side salad',
        'restaurant_settings_sample_mod'=> 'Extra sauce',
    ]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const v = (id) => (document.getElementById(id) || { value: '' }).value.trim();
    const sym = <?= json_encode(trim(preg_replace('/[0-9.,\s-]/u', '', RestaurantAPI::money(0))) ?: '$') ?>;
    const money = (c) => sym + (c / 100).toFixed(2);
    function paint() {
        const rate = parseFloat(v('default_tax_rate')) || 0, sub = 1850 + 650, tax = Math.round(1850 * rate / 100) + Math.round(650 * rate / 100);
        const tips = v('tip_presets').split(',').map((x) => parseInt(x, 10)).filter((x) => x > 0), tip = tips.length ? Math.round((sub + tax) * tips[0] / 100) : 0;
        const place = [v('addr_city'), v('addr_state'), v('addr_postal')].filter(Boolean).join(' ');
        document.getElementById('rsPaper').innerHTML =
            '<div class="c b">' + (v('biz_name') ? esc(v('biz_name')) : '<span class="ph">' + esc(L.restaurant_settings_ph_name) + '</span>') + '</div>'
            + [v('addr_line1'), v('addr_line2'), place].filter(Boolean).map((x) => '<div class="c">' + esc(x) + '</div>').join('')
            + (v('receipt_header') ? '<div class="c" style="margin-top:6px">' + esc(v('receipt_header')).replace(/
/g, '<br>') + '</div>' : '')
            + '<hr><div class="r"><span>' + esc(L.restaurant_rcpt_order) + '</span><span>#0000-001</span></div><hr>'
            + '<div class="r"><span>1 ' + esc(L.restaurant_settings_sample_1) + '</span><span>' + money(1850) + '</span></div><div class="m">+ ' + esc(L.restaurant_settings_sample_mod) + '</div>'
            + '<div class="r"><span>1 ' + esc(L.restaurant_settings_sample_2) + '</span><span>' + money(650) + '</span></div><hr>'
            + '<div class="r"><span>' + esc(L.restaurant_rcpt_subtotal) + '</span><span>' + money(sub) + '</span></div>'
            + '<div class="r"><span>' + esc(L.restaurant_rcpt_tax) + ' (' + rate + '%)</span><span>' + money(tax) + '</span></div>'
            + (tip ? '<div class="r"><span>' + esc(L.restaurant_rcpt_tip) + ' (' + tips[0] + '%)</span><span>' + money(tip) + '</span></div>' : '')
            + '<div class="r t"><span>' + esc(L.restaurant_rcpt_total) + '</span><span>' + money(sub + tax + tip) + '</span></div>'
            + '<hr><div class="r"><span>' + esc(L.restaurant_rcpt_card) + '</span><span>' + money(sub + tax + tip) + '</span></div>'
            + (v('receipt_footer') ? '<hr><div class="c">' + esc(v('receipt_footer')).replace(/
/g, '<br>') + '</div>' : '');
    }
    document.getElementById('rsForm').addEventListener('input', paint);
    paint();
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
