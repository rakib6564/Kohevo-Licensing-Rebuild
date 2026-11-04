<?php
/**
 * Shop — shipping: zones and the methods each offers (flat, free, weight bands, live carrier rates), and the EasyPost
 * connection with the ship-from address and the box a parcel goes in.
 *
 * With no zone, checkout keeps the flat rate and free-shipping threshold of Shop settings. The first zone is offered
 * pre-filled from those (the shop's countries and its flat rate), so turning zones on changes nothing until edited.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/ShopStoreAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('shop.manage_settings');
ModuleGuard::require('shop');
ShopStoreAPI::ensureSchema();

$pageTitle  = __('shop_shipping', 'Shipping');
$currentNav = 'shop-shipping';
$self = plugin_url('shop', 'admin/shipping.php');
$flash = null;
$errors = [];

$back = static function (string $msg, string $query = '') use ($self): never {
    $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => $msg];
    header('Location: ' . $self . $query);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('shop_err_security', 'Security check failed. Please reload the page.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'save_zone') {
            $res = ShopShipping::saveZone($_POST, $id);
            if ($res['ok']) {
                AuditLog::record('shop.zone_saved', (string) $res['id']);
                // A new zone starts with the shop's flat rate, so checkout offers what it did before.
                if ($id === 0) {
                    ShopShipping::saveMethod($res['id'], ['kind' => 'flat', 'label' => __('shop_standard_shipping', 'Standard shipping'),
                        'cost' => number_format(((int) ShopStoreAPI::setting('shipping_cents')) / 100, 2, '.', ''), 'enabled' => 1]);
                    $free = (int) ShopStoreAPI::setting('free_over_cents');
                    if ($free > 0) ShopShipping::saveMethod($res['id'], ['kind' => 'free', 'label' => __('shop_free_shipping', 'Free shipping'), 'min_subtotal' => number_format($free / 100, 2, '.', ''), 'enabled' => 1, 'sort' => 1]);
                }
                $back(__('shop_saved', 'Saved.'));
            }
            $errors = $res['errors'] ?? [];
        } elseif ($action === 'delete_zone' && $id > 0) {
            ShopShipping::deleteZone($id);
            AuditLog::record('shop.zone_deleted', (string) $id);
            $back(__('shop_zone_deleted', 'Zone deleted.'));
        } elseif ($action === 'save_method') {
            $res = ShopShipping::saveMethod((int) ($_POST['zone_id'] ?? 0), $_POST, $id);
            if ($res['ok']) {
                AuditLog::record('shop.shipping_method_saved', (string) $res['id']);
                $back(__('shop_saved', 'Saved.'));
            }
            $errors = $res['errors'] ?? [];
        } elseif ($action === 'delete_method' && $id > 0) {
            ShopShipping::deleteMethod($id);
            AuditLog::record('shop.shipping_method_deleted', (string) $id);
            $back(__('shop_method_deleted', 'Method deleted.'));
        } elseif ($action === 'save_carrier') {
            $in = static fn(string $k): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 190);
            foreach (['box_length', 'box_width', 'box_height', 'box_weight'] as $k) {
                if ($in($k) !== '' && ShopStoreAPI::parseMeasure($in($k)) === false) $errors[$k] = __('shop_err_measure', 'Enter a number, such as 12.5.');
            }
            $country = strtoupper($in('from_country'));
            if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) $errors['from_country'] = __('shop_err_country_code', 'Use a two-letter country code, or * for any.');
            if (!$errors) {
                foreach (['name', 'company', 'street1', 'street2', 'city', 'region', 'postcode', 'phone'] as $k) ShopStoreAPI::setSetting('from_' . $k, $in('from_' . $k));
                ShopStoreAPI::setSetting('from_region', strtoupper($in('from_region')));
                ShopStoreAPI::setSetting('from_country', $country);
                foreach (['box_length', 'box_width', 'box_height', 'box_weight'] as $k) {
                    $v = ShopStoreAPI::parseMeasure($in($k));
                    ShopStoreAPI::setSetting($k, $v === null || $v === false ? '' : (string) $v);
                }
                $key = trim((string) ($_POST['easypost_key'] ?? ''));
                if ($key !== '') {
                    try {
                        ShopShipping::setApiKey($key);
                    } catch (\RuntimeException $e) {
                        $_SESSION['shop_flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
                        header('Location: ' . $self);
                        exit;
                    }
                }
                AuditLog::record('shop.carrier_saved', 'shop');
                $back(__('shop_saved', 'Saved.'));
            }
        } elseif ($action === 'forget_key') {
            ShopShipping::setApiKey('');
            AuditLog::record('shop.carrier_key_removed', 'shop');
            $back(__('shop_easypost_key_removed', 'The EasyPost key was removed.'));
        } elseif ($action === 'test_key') {
            $res = ShopShipping::testConnection();
            $_SESSION['shop_flash'] = ['type' => $res['ok'] ? 'success' : 'error', 'msg' => $res['message']];
            header('Location: ' . $self);
            exit;
        }
        if ($errors && !$flash) $flash = ['type' => 'error', 'msg' => __('shop_err_fix_fields', 'Please fix the highlighted fields.')];
    }
}
if (!$flash && !empty($_SESSION['shop_flash'])) { $flash = $_SESSION['shop_flash']; unset($_SESSION['shop_flash']); }

$zones = ShopShipping::zones();
$editZone = (string) ($_GET['zone'] ?? '');
$editMethod = (string) ($_GET['method'] ?? '');
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : null;
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field-error" role="alert">' . e($errors[$k]) . '</div>' : '';
$money = static fn(int $c): string => number_format($c / 100, 2, '.', '');
$cur = ShopStoreAPI::currency();
$wu = ShopStoreAPI::weightUnit();
$du = ShopStoreAPI::dimensionUnit();
$kinds = [
    'flat'    => __('shop_kind_flat', 'Flat rate'),
    'free'    => __('shop_kind_free', 'Free shipping'),
    'weight'  => __('shop_kind_weight', 'By weight'),
    'carrier' => __('shop_kind_carrier', 'Carrier rates (EasyPost)'),
    'pickup'  => __('shop_kind_pickup', 'Local pickup'),
];
$describe = static function (array $m) use ($money, $cur, $wu): string {
    $min = (int) $m['min_subtotal_cents'] > 0 ? ' · ' . sprintf(__('shop_from_subtotal', 'from %s'), ShopStoreAPI::money((int) $m['min_subtotal_cents'], $cur)) : '';
    switch ($m['kind']) {
        case 'flat':
            return ShopStoreAPI::money((int) $m['cost_cents'], $cur) . ((int) $m['per_item_cents'] > 0 ? ' + ' . sprintf(__('shop_per_extra_item', '%s per extra item'), ShopStoreAPI::money((int) $m['per_item_cents'], $cur)) : '') . $min;
        case 'free':
            return __('shop_free', 'free') . $min;
        case 'pickup':
            return ((int) $m['cost_cents'] > 0 ? ShopStoreAPI::money((int) $m['cost_cents'], $cur) : __('shop_free', 'free')) . $min;
        case 'weight':
            $bands = json_decode((string) $m['rates_json'], true) ?: [];
            return sprintf(__('shop_n_bands', '%1$d bands (%2$s)'), count($bands), $wu) . $min;
        default:
            $o = json_decode((string) $m['rates_json'], true) ?: [];
            $parts = [($o['carriers'] ?? []) !== [] ? implode(', ', $o['carriers']) : __('shop_all_carriers', 'all carriers')];
            if (($o['services'] ?? []) !== []) $parts[] = implode(', ', $o['services']);
            if ((float) ($o['markup_pct'] ?? 0) > 0 || (int) ($o['markup_cents'] ?? 0) > 0) $parts[] = '+' . ((float) ($o['markup_pct'] ?? 0) > 0 ? $o['markup_pct'] . '%' : '') . ((int) ($o['markup_cents'] ?? 0) > 0 ? ' ' . ShopStoreAPI::money((int) $o['markup_cents'], $cur) : '');
            return implode(' · ', $parts) . $min;
    }
};

/** The zone form (new, or one zone's). */
$zoneForm = static function (?array $z) use ($posted, $err, $self): string {
    $v = static fn(string $k, string $cur) => e($posted !== null ? (string) ($posted[$k] ?? '') : $cur);
    ob_start(); ?>
    <form method="post" class="field-grid" style="padding:0 1rem 1rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_zone"><input type="hidden" name="id" value="<?= (int) ($z['id'] ?? 0) ?>">
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="zone-name"><?= __('shop_name', 'Name') ?></label>
                <input type="text" id="zone-name" name="name" maxlength="120" required value="<?= $v('name', (string) ($z['name'] ?? '')) ?>" placeholder="<?= e(__('shop_zone_name_example', 'Continental US')) ?>"><?= $err('name') ?></div>
            <div class="field"><label class="field-label" for="zone-countries"><?= __('shop_zone_countries', 'Countries') ?></label>
                <input type="text" id="zone-countries" name="countries" value="<?= $v('countries', str_replace(',', ', ', (string) ($z['countries'] ?? implode(', ', ShopStoreAPI::countries())))) ?>" placeholder="US">
                <div class="field-hint"><?= __('shop_zone_countries_hint', 'Two-letter codes. * covers every country the shop ships to that no other zone names.') ?></div><?= $err('countries') ?></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="zone-regions"><?= __('shop_zone_regions', 'States or regions (optional)') ?></label>
                <input type="text" id="zone-regions" name="regions" value="<?= $v('regions', str_replace(',', ', ', (string) ($z['regions'] ?? ''))) ?>" placeholder="AK, HI">
                <div class="field-hint"><?= __('shop_zone_regions_hint', 'Codes as customers type them, such as CA or AK. Empty: the whole country.') ?></div></div>
            <div class="field"><label class="field-label" for="zone-postcodes"><?= __('shop_zone_postcodes', 'Postcodes (optional)') ?></label>
                <input type="text" id="zone-postcodes" name="postcodes" value="<?= $v('postcodes', str_replace(',', ', ', (string) ($z['postcodes'] ?? ''))) ?>" placeholder="93401-93499, 934*">
                <div class="field-hint"><?= __('shop_zone_postcodes_hint', 'Exact (93446), starting with (934*) or a range (93401-93499).') ?></div><?= $err('postcodes') ?></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="zone-sort"><?= __('shop_sort_order', 'Sort order') ?></label>
                <input type="number" id="zone-sort" name="sort" value="<?= $v('sort', (string) ($z['sort'] ?? 0)) ?>">
                <div class="field-hint"><?= __('shop_zone_sort_hint', 'The first zone that matches an address is used: put narrow zones (a city, a state) before wide ones.') ?></div></div>
            <div class="field" style="align-self:end;"><button type="submit" class="btn btn-primary"><?= __('shop_save', 'Save changes') ?></button>
                <?php if ($z): ?> <a class="btn btn-ghost" href="<?= e($self) ?>"><?= __('shop_cancel', 'Cancel') ?></a><?php endif; ?></div>
        </div>
    </form>
    <?php return (string) ob_get_clean();
};

/** The method form for a zone (new of a kind, or one method's). */
$methodForm = static function (array $zone, ?array $m, string $kind) use ($posted, $err, $self, $money, $kinds, $wu): string {
    $v = static fn(string $k, string $cur) => e($posted !== null ? (string) ($posted[$k] ?? '') : $cur);
    $o = $m && $m['kind'] === 'carrier' ? (json_decode((string) $m['rates_json'], true) ?: []) : [];
    ob_start(); ?>
    <form method="post" style="padding:.75rem 0;">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_method"><input type="hidden" name="id" value="<?= (int) ($m['id'] ?? 0) ?>">
        <input type="hidden" name="zone_id" value="<?= (int) $zone['id'] ?>"><input type="hidden" name="kind" value="<?= e($kind) ?>">
        <p class="text-sm" style="margin:0 0 .5rem;"><strong><?= e($kinds[$kind]) ?></strong></p>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="m-label"><?= $kind === 'carrier' ? __('shop_method_label_carrier', 'Name before each rate (optional)') : __('shop_method_label', 'Name customers see') ?></label>
                <input type="text" id="m-label" name="label" maxlength="120" value="<?= $v('label', (string) ($m['label'] ?? '')) ?>" placeholder="<?= e($kind === 'free' ? __('shop_free_shipping', 'Free shipping') : ($kind === 'carrier' ? '' : ($kind === 'pickup' ? __('shop_kind_pickup', 'Local pickup') : __('shop_standard_shipping', 'Standard shipping')))) ?>"><?= $err('label') ?></div>
            <div class="field"><label class="field-label" for="m-min"><?= __('shop_method_min', 'Offered from a subtotal of') ?></label>
                <input type="text" inputmode="decimal" id="m-min" name="min_subtotal" value="<?= $v('min_subtotal', $money((int) ($m['min_subtotal_cents'] ?? 0))) ?>">
                <div class="field-hint"><?= __('shop_method_min_hint', 'After discounts. 0: always offered.') ?></div><?= $err('min_subtotal') ?></div>
        </div>
        <?php if ($kind === 'pickup'): ?>
        <div class="field"><label class="field-label" for="m-cost"><?= __('shop_method_cost', 'Cost') ?></label>
            <input type="text" inputmode="decimal" id="m-cost" name="cost" value="<?= $v('cost', $money((int) ($m['cost_cents'] ?? 0))) ?>" style="max-width:10rem;">
            <div class="field-hint"><?= __('shop_pickup_method_hint', 'Customers in this zone may collect their order. Say where and how in the name, such as Pick up in Paso Robles (by appointment). Pickup in Shop settings offers it to everyone instead.') ?></div><?= $err('cost') ?></div>
        <?php endif; ?>
        <?php if ($kind === 'flat'): ?>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="m-cost"><?= __('shop_method_cost', 'Cost') ?></label>
                <input type="text" inputmode="decimal" id="m-cost" name="cost" value="<?= $v('cost', $money((int) ($m['cost_cents'] ?? 0))) ?>"><?= $err('cost') ?></div>
            <div class="field"><label class="field-label" for="m-per"><?= __('shop_method_per_item', 'Plus, per extra item') ?></label>
                <input type="text" inputmode="decimal" id="m-per" name="per_item" value="<?= $v('per_item', $money((int) ($m['per_item_cents'] ?? 0))) ?>"><?= $err('per_item') ?></div>
        </div>
        <?php elseif ($kind === 'weight'): ?>
        <div class="field"><label class="field-label" for="m-bands"><?= sprintf(__('shop_method_bands', 'Weight bands (%s)'), e($wu)) ?></label>
            <textarea id="m-bands" name="bands" rows="5" placeholder="<?= e("16: 7.50\n48: 12.00\n*: 18.00") ?>"><?= $v('bands', $m ? ShopShipping::bandsText(json_decode((string) $m['rates_json'], true) ?: []) : '') ?></textarea>
            <div class="field-hint"><?= __('shop_method_bands_hint', 'One per line: up to that weight, that price. * covers anything heavier. The weight is the products\' weights plus the box.') ?></div><?= $err('bands') ?></div>
        <?php elseif ($kind === 'carrier'): ?>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="m-carriers"><?= __('shop_method_carriers', 'Carriers (optional)') ?></label>
                <input type="text" id="m-carriers" name="carriers" value="<?= $v('carriers', implode(', ', $o['carriers'] ?? [])) ?>" placeholder="USPS, UPS">
                <div class="field-hint"><?= __('shop_method_carriers_hint', 'As EasyPost names them. Empty: every carrier on your EasyPost account.') ?></div></div>
            <div class="field"><label class="field-label" for="m-services"><?= __('shop_method_services', 'Services (optional)') ?></label>
                <input type="text" id="m-services" name="services" value="<?= $v('services', implode(', ', $o['services'] ?? [])) ?>" placeholder="GroundAdvantage, Priority, Express">
                <div class="field-hint"><?= __('shop_method_services_hint', 'Empty: every service the carriers offer for the parcel.') ?></div></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="m-pct"><?= __('shop_method_markup_pct', 'Markup (%)') ?></label>
                <input type="text" inputmode="decimal" id="m-pct" name="markup_pct" value="<?= $v('markup_pct', (string) ($o['markup_pct'] ?? '0')) ?>"><?= $err('markup_pct') ?></div>
            <div class="field"><label class="field-label" for="m-markup"><?= __('shop_method_markup', 'Plus a handling fee') ?></label>
                <input type="text" inputmode="decimal" id="m-markup" name="markup" value="<?= $v('markup', $money((int) ($o['markup_cents'] ?? 0))) ?>"><?= $err('markup') ?></div>
        </div>
        <?php if (!ShopShipping::carrierReady()): ?><div class="alert alert-warning" role="status"><?= __('shop_carrier_not_ready', 'Carrier rates need an EasyPost key and a ship-from address (below). Until then this method offers nothing.') ?></div><?php endif; ?>
        <?php endif; ?>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-check"><input type="checkbox" name="enabled" value="1" <?= ($posted !== null ? !empty($posted['enabled']) : (int) ($m['enabled'] ?? 1) === 1) ? 'checked' : '' ?>> <?= __('shop_method_enabled', 'Offered at checkout') ?></label></div>
            <div class="field"><label class="field-label" for="m-sort"><?= __('shop_sort_order', 'Sort order') ?></label><input type="number" id="m-sort" name="sort" value="<?= $v('sort', (string) ($m['sort'] ?? 0)) ?>"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><?= __('shop_save', 'Save changes') ?></button>
        <a class="btn btn-ghost btn-sm" href="<?= e($self) ?>"><?= __('shop_cancel', 'Cancel') ?></a>
    </form>
    <?php return (string) ob_get_clean();
};

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop', 'Shop'), 'href' => plugin_url('shop', 'admin/orders.php')],
    ['label' => __('shop_shipping', 'Shipping')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="page-header"><div><h1><?= __('shop_shipping', 'Shipping') ?></h1>
    <p class="text-sm text-muted"><?= __('shop_shipping_intro', 'Zones say where you ship; each zone\'s methods are what checkout offers there. Pickup and the countries you ship to are in Shop settings.') ?></p></div></div>

<?php if (!$zones): ?>
    <div class="alert alert-info" role="status"><?= sprintf(__('shop_no_zones', 'No zones yet: checkout offers the flat rate from Shop settings (%s). Add a zone for rates by place, weight bands or live carrier rates.'), e(ShopStoreAPI::money((int) ShopStoreAPI::setting('shipping_cents'), $cur))) ?></div>
<?php endif; ?>

<?php foreach ($zones as $z): $zid = (int) $z['id']; ?>
<div class="card" style="margin-bottom:1rem;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
        <div><h2 style="margin:0;"><?= e((string) $z['name']) ?></h2>
            <p class="text-sm text-muted" style="margin:.25rem 0 0;"><?= e(str_replace(',', ', ', (string) $z['countries'])) ?><?= $z['regions'] !== '' ? ' · ' . e(str_replace(',', ', ', (string) $z['regions'])) : '' ?><?= $z['postcodes'] !== '' ? ' · ' . e(str_replace(',', ', ', (string) $z['postcodes'])) : '' ?></p></div>
        <div style="white-space:nowrap;">
            <a class="btn btn-sm" href="?zone=<?= $zid ?>"><?= __('shop_edit', 'Edit') ?></a>
            <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('<?= e(__('shop_delete_zone_confirm', 'Delete this zone and its methods?')) ?>')">
                <?= csrf_field() ?><input type="hidden" name="_action" value="delete_zone"><input type="hidden" name="id" value="<?= $zid ?>">
                <button class="btn btn-sm btn-danger"><?= __('shop_delete', 'Delete') ?></button></form>
        </div>
    </div>
    <?php if ($editZone === (string) $zid) echo $zoneForm($z); ?>
    <div style="padding:0 1rem 1rem;">
        <?php if ($z['methods']): ?>
        <table class="table">
            <thead><tr><th><?= __('shop_method', 'Method') ?></th><th><?= __('shop_method_type', 'Type') ?></th><th><?= __('shop_price', 'Price') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($z['methods'] as $m): $mid = (int) $m['id']; ?>
                <?php if ($editMethod === (string) $mid): ?>
                <tr><td colspan="4"><?= $methodForm($z, $m, (string) $m['kind']) ?></td></tr>
                <?php else: ?>
                <tr<?= empty($m['enabled']) ? ' style="opacity:.55"' : '' ?>>
                    <td><?= e((string) $m['label'] !== '' ? (string) $m['label'] : $kinds[$m['kind']]) ?><?= empty($m['enabled']) ? ' <span class="text-muted text-xs">(' . __('shop_off', 'off') . ')</span>' : '' ?></td>
                    <td><?= e($kinds[$m['kind']] ?? (string) $m['kind']) ?></td>
                    <td><?= e($describe($m)) ?></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <a class="btn btn-sm" href="?method=<?= $mid ?>"><?= __('shop_edit', 'Edit') ?></a>
                        <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('<?= e(__('shop_delete_method_confirm', 'Delete this method?')) ?>')">
                            <?= csrf_field() ?><input type="hidden" name="_action" value="delete_method"><input type="hidden" name="id" value="<?= $mid ?>">
                            <button class="btn btn-sm btn-danger"><?= __('shop_delete', 'Delete') ?></button></form>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="text-sm text-muted"><?= __('shop_zone_no_methods', 'No method yet: checkout cannot ship to this zone.') ?></p>
        <?php endif; ?>
        <?php if (str_starts_with($editMethod, 'new-') && (int) ($_GET['zone_id'] ?? 0) === $zid && isset($kinds[substr($editMethod, 4)])): ?>
            <?= $methodForm($z, null, substr($editMethod, 4)) ?>
        <?php else: ?>
        <p class="text-sm" style="margin:.5rem 0 0;display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;"><span><?= __('shop_add_method', 'Add a method:') ?></span>
            <?php foreach ($kinds as $k => $label): ?><a class="btn btn-sm btn-ghost" href="?zone_id=<?= $zid ?>&amp;method=new-<?= e($k) ?>"><?= e($label) ?></a><?php endforeach; ?></p>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if ($editZone === 'new' || !$zones): ?>
<div class="card" style="margin-bottom:1rem;">
    <div class="card-header"><h2><?= __('shop_add_zone', 'Add a zone') ?></h2></div>
    <?= $zoneForm(null) ?>
</div>
<?php else: ?>
<p style="margin:0 0 1.5rem;"><a class="btn" href="?zone=new"><?= __('shop_add_zone', 'Add a zone') ?></a></p>
<?php endif; ?>

<?php slate_editor_css(); ?>
<?php slate_edit_open(['title_fallback' => __('shop_carrier_rates', 'Carrier rates and labels')]); ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save_carrier">
    <?php $key = ShopShipping::apiKey(); $sv = static fn(string $k): string => e($posted !== null ? (string) ($posted[$k] ?? '') : ShopStoreAPI::setting($k)); ?>
    <?php slate_edit_card_open(['icon' => 'truck', 'eyebrow' => 'EasyPost', 'title' => __('shop_carrier_rates', 'Carrier rates and labels')]); ?>
        <p class="text-sm"><?= __('shop_easypost_intro', 'EasyPost gives live USPS, UPS, FedEx and other carrier rates at checkout, and buys shipping labels from an order. A test key (EZTK…) costs nothing.') ?></p>
        <div class="field">
            <label class="field-label" for="easypost_key"><?= __('shop_easypost_key', 'API key') ?></label>
            <input type="password" id="easypost_key" name="easypost_key" autocomplete="off" value="" placeholder="<?= $key !== '' ? e(sprintf(__('shop_key_saved', 'Saved (%s key): type a new one to replace it'), ShopShipping::isTestKey() ? __('shop_easypost_test', 'test') : __('shop_easypost_live', 'live'))) : 'EZAK… / EZTK…' ?>">
            <div class="field-hint"><?= __('shop_easypost_key_hint', 'Kept encrypted. It is never shown again.') ?></div>
        </div>
    <?php slate_edit_card_close(); ?>
    <?php slate_edit_card_open(['icon' => 'map-pin', 'eyebrow' => __('shop_ship_from', 'Ship from'), 'title' => __('shop_ship_from_title', 'Where parcels leave from')]); ?>
        <p class="text-sm text-muted"><?= __('shop_ship_from_hint', 'Carriers price from this address. It is also the shop\'s address for tax on pickup orders and downloads. Customers never see it.') ?></p>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="from_name"><?= __('shop_from_name', 'Name') ?></label><input type="text" id="from_name" name="from_name" value="<?= $sv('from_name') ?>"></div>
            <div class="field"><label class="field-label" for="from_company"><?= __('shop_from_company', 'Business') ?></label><input type="text" id="from_company" name="from_company" value="<?= $sv('from_company') ?>"></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="from_street1"><?= __('shop_address_line1', 'Street and number') ?></label><input type="text" id="from_street1" name="from_street1" value="<?= $sv('from_street1') ?>"></div>
            <div class="field"><label class="field-label" for="from_street2"><?= __('shop_address_line2', 'Apartment, box (optional)') ?></label><input type="text" id="from_street2" name="from_street2" value="<?= $sv('from_street2') ?>"></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="from_city"><?= __('shop_city', 'Town or city') ?></label><input type="text" id="from_city" name="from_city" value="<?= $sv('from_city') ?>"></div>
            <div class="field"><label class="field-label" for="from_region"><?= __('shop_region', 'State or province') ?></label><input type="text" id="from_region" name="from_region" maxlength="80" value="<?= $sv('from_region') ?>"></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="from_postcode"><?= __('shop_postal_code', 'Postal code') ?></label><input type="text" id="from_postcode" name="from_postcode" value="<?= $sv('from_postcode') ?>"></div>
            <div class="field"><label class="field-label" for="from_country"><?= __('shop_country', 'Country') ?></label><input type="text" id="from_country" name="from_country" maxlength="2" value="<?= $sv('from_country') ?>" placeholder="US"><?= $err('from_country') ?></div>
        </div>
        <div class="field"><label class="field-label" for="from_phone"><?= __('shop_from_phone', 'Phone (carriers ask for one on labels)') ?></label><input type="tel" id="from_phone" name="from_phone" value="<?= $sv('from_phone') ?>"></div>
    <?php slate_edit_card_close(); ?>
    <?php slate_edit_card_open(['icon' => 'package', 'eyebrow' => __('shop_parcel', 'Parcel'), 'title' => __('shop_box', 'The box')]); ?>
        <p class="text-sm text-muted"><?= sprintf(__('shop_box_hint', 'The box an order ships in, in %1$s and %2$s. Its weight is added to the products\' weights for weight bands and carrier rates. Leave the size empty to rate by weight only.'), e($du), e($wu)) ?></p>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="box_length"><?= __('shop_length', 'Length') ?></label><input type="text" inputmode="decimal" id="box_length" name="box_length" value="<?= $sv('box_length') ?>"><?= $err('box_length') ?></div>
            <div class="field"><label class="field-label" for="box_width"><?= __('shop_width', 'Width') ?></label><input type="text" inputmode="decimal" id="box_width" name="box_width" value="<?= $sv('box_width') ?>"><?= $err('box_width') ?></div>
        </div>
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="box_height"><?= __('shop_height', 'Height') ?></label><input type="text" inputmode="decimal" id="box_height" name="box_height" value="<?= $sv('box_height') ?>"><?= $err('box_height') ?></div>
            <div class="field"><label class="field-label" for="box_weight"><?= __('shop_box_weight', 'Empty box weight') ?></label><input type="text" inputmode="decimal" id="box_weight" name="box_weight" value="<?= $sv('box_weight') ?>"><?= $err('box_weight') ?></div>
        </div>
    <?php slate_edit_card_close(); ?>
    <?php slate_edit_actionbar(['buttons_html' => '<button type="submit" class="btn btn-primary">' . __('shop_save', 'Save changes') . '</button>']); ?>
</form>
<?php if ($key !== ''): ?>
    <p style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <form method="post" style="margin:0;"><?= csrf_field() ?><input type="hidden" name="_action" value="test_key"><button class="btn"><?= __('shop_easypost_check', 'Check the connection') ?></button></form>
        <form method="post" style="margin:0;" onsubmit="return confirm('<?= e(__('shop_easypost_forget_confirm', 'Remove the EasyPost key? Carrier rates stop at checkout.')) ?>')"><?= csrf_field() ?><input type="hidden" name="_action" value="forget_key"><button class="btn btn-ghost"><?= __('shop_easypost_forget', 'Remove the key') ?></button></form>
    </p>
<?php endif; ?>
<?php slate_edit_close(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
