<?php
/**
 * Shop — tax: whether prices include tax, tax is added at checkout or there is none, the tax's name, and the rates by
 * country, state and postcode (per tax class).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/ShopStoreAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('shop.manage_settings');
ModuleGuard::require('shop');
ShopStoreAPI::ensureSchema();

$pageTitle  = __('shop_tax', 'Tax');
$currentNav = 'shop-tax';
$self = plugin_url('shop', 'admin/tax.php');
$flash = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('shop_err_security', 'Security check failed. Please reload the page.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'save_mode') {
            $mode = in_array($_POST['tax_mode'] ?? '', ShopTax::MODES, true) ? (string) $_POST['tax_mode'] : 'inclusive';
            ShopStoreAPI::setSetting('tax_mode', $mode);
            ShopStoreAPI::setSetting('tax_label', mb_substr(trim((string) ($_POST['tax_label'] ?? '')), 0, 40));
            AuditLog::record('shop.tax_mode_saved', $mode);
            $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => __('shop_saved', 'Saved.')];
            header('Location: ' . $self);
            exit;
        }
        if ($action === 'save_rate') {
            $res = ShopTax::saveRate($_POST, $id);
            if ($res['ok']) {
                AuditLog::record('shop.tax_rate_saved', (string) $res['id']);
                $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => __('shop_saved', 'Saved.')];
                header('Location: ' . $self);
                exit;
            }
            $errors = $res['errors'] ?? [];
            $flash = ['type' => 'error', 'msg' => __('shop_err_fix_fields', 'Please fix the highlighted fields.')];
        }
        if ($action === 'delete_rate' && $id > 0) {
            ShopTax::deleteRate($id);
            AuditLog::record('shop.tax_rate_deleted', (string) $id);
            $_SESSION['shop_flash'] = ['type' => 'success', 'msg' => __('shop_tax_rate_deleted', 'Rate deleted.')];
            header('Location: ' . $self);
            exit;
        }
    }
}
if (!$flash && !empty($_SESSION['shop_flash'])) { $flash = $_SESSION['shop_flash']; unset($_SESSION['shop_flash']); }

$rates = ShopTax::rates();
$mode = ShopTax::mode();
$classes = ShopTax::classLabels();
$editId = (int) ($_GET['edit'] ?? 0);
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_rate' ? $_POST : null;
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="field-error" role="alert">' . e($errors[$k]) . '</div>' : '';

/** A rate's fields (new, or one rate's). */
$rateFields = static function (?array $r) use ($posted, $err, $classes): string {
    $mine = $posted !== null && (int) ($posted['id'] ?? 0) === (int) ($r['id'] ?? 0);
    $v = static fn(string $k, string $cur) => e($mine ? (string) ($posted[$k] ?? '') : $cur);
    $class = $mine ? (string) ($posted['tax_class'] ?? '') : (string) ($r['tax_class'] ?? '');
    $ship = $mine ? !empty($posted['shipping']) : (int) ($r['shipping'] ?? 1) === 1;
    ob_start(); ?>
    <div class="field-row" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;">
        <label class="field" style="width:6rem;"><span class="field-label"><?= __('shop_country', 'Country') ?></span><input type="text" name="country" maxlength="2" value="<?= $v('country', (string) ($r['country'] ?? 'US')) ?>"><?= $mine ? $err('country') : '' ?></label>
        <label class="field" style="width:7rem;"><span class="field-label"><?= __('shop_region', 'State or province') ?></span><input type="text" name="region" maxlength="80" value="<?= $v('region', (string) ($r['region'] ?? '')) ?>" placeholder="CA"></label>
        <label class="field" style="flex:1;min-width:10rem;"><span class="field-label"><?= __('shop_zone_postcodes', 'Postcodes (optional)') ?></span><input type="text" name="postcodes" value="<?= $v('postcodes', str_replace(',', ', ', (string) ($r['postcodes'] ?? ''))) ?>" placeholder="93446, 934*"><?= $mine ? $err('postcodes') : '' ?></label>
        <label class="field" style="width:7rem;"><span class="field-label"><?= __('shop_tax_rate', 'Rate (%)') ?></span><input type="text" inputmode="decimal" name="rate" required value="<?= $v('rate', isset($r['rate']) ? rtrim(rtrim((string) $r['rate'], '0'), '.') : '') ?>" placeholder="7.25"><?= $mine ? $err('rate') : '' ?></label>
        <label class="field" style="width:10rem;"><span class="field-label"><?= __('shop_tax_name', 'Name (optional)') ?></span><input type="text" name="name" maxlength="80" value="<?= $v('name', (string) ($r['name'] ?? '')) ?>" placeholder="<?= e(__('shop_sales_tax', 'Sales tax')) ?>"></label>
        <label class="field" style="width:9rem;"><span class="field-label"><?= __('shop_tax_class', 'Tax class') ?></span><select name="tax_class"><?php foreach ($classes as $k => $label): ?><option value="<?= e($k) ?>" <?= $class === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label class="field-check" style="margin-bottom:.6rem;"><input type="checkbox" name="shipping" value="1" <?= $ship ? 'checked' : '' ?>> <?= __('shop_tax_shipping', 'Tax shipping') ?></label>
    </div>
    <?php return (string) ob_get_clean();
};

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('shop', 'Shop'), 'href' => plugin_url('shop', 'admin/orders.php')],
    ['label' => __('shop_tax', 'Tax')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="page-header"><div><h1><?= __('shop_tax', 'Tax') ?></h1></div></div>

<div class="card" style="margin-bottom:1rem;">
    <div class="card-header"><h2><?= __('shop_tax_prices', 'Your prices') ?></h2></div>
    <form method="post" style="padding:0 1rem 1rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_mode">
        <?php foreach ([
            'inclusive' => [__('shop_tax_mode_inclusive', 'Prices include tax'), __('shop_tax_mode_inclusive_hint', 'European VAT. The customer pays the price shown; receipts say how much of it is tax.')],
            'exclusive' => [__('shop_tax_mode_exclusive', 'Tax is added at checkout'), __('shop_tax_mode_exclusive_hint', 'US sales tax. Prices are before tax; checkout adds the rate for the delivery address (the shop\'s own address for pickup).')],
            'none'      => [__('shop_tax_mode_none', 'No tax'), __('shop_tax_mode_none_hint', 'No tax line and no tax wording anywhere.')],
        ] as $k => [$label, $hint]): ?>
            <label class="field-check" style="display:flex;gap:.5rem;align-items:flex-start;margin:0 0 .6rem;"><input type="radio" name="tax_mode" value="<?= $k ?>" <?= $mode === $k ? 'checked' : '' ?> style="margin-top:.25rem;">
                <span><strong><?= e($label) ?></strong><br><span class="text-sm text-muted"><?= e($hint) ?></span></span></label>
        <?php endforeach; ?>
        <div class="field" style="max-width:20rem;">
            <label class="field-label" for="tax_label"><?= __('shop_tax_label', 'What the tax is called') ?></label>
            <input type="text" id="tax_label" name="tax_label" maxlength="40" value="<?= e(ShopStoreAPI::setting('tax_label')) ?>" placeholder="<?= e($mode === 'exclusive' ? __('shop_sales_tax', 'Sales tax') : __('shop_vat', 'VAT')) ?>">
            <div class="field-hint"><?= __('shop_tax_label_hint', 'Empty: VAT when prices include it, Sales tax when it is added.') ?></div>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('shop_save', 'Save changes') ?></button>
    </form>
</div>

<div class="card" style="margin-bottom:1rem;">
    <div class="card-header"><h2><?= __('shop_tax_rates', 'Rates') ?></h2></div>
    <div style="padding:0 1rem 1rem;">
        <p class="text-sm text-muted"><?= __('shop_tax_rates_hint', 'The most specific rate for an address wins: a postcode over a state, a state over a country, a country over *. Products in a class with no rate are not taxed. Rates are not looked up for you: enter the ones you collect.') ?></p>
        <?php if ($mode === 'none'): ?><div class="alert alert-info" role="status"><?= __('shop_tax_rates_off', 'The shop shows no tax, so these rates are not used.') ?></div><?php endif; ?>
        <?php if ($rates): ?>
        <table class="table">
            <thead><tr><th><?= __('shop_tax_where', 'Where') ?></th><th><?= __('shop_tax_rate', 'Rate (%)') ?></th><th><?= __('shop_tax_class', 'Tax class') ?></th><th><?= __('shop_shipping', 'Shipping') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rates as $r): $rid = (int) $r['id']; ?>
                <?php if ($editId === $rid): ?>
                <tr><td colspan="5"><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="save_rate"><input type="hidden" name="id" value="<?= $rid ?>">
                    <?= $rateFields($r) ?>
                    <button type="submit" class="btn btn-primary btn-sm"><?= __('shop_save', 'Save changes') ?></button> <a class="btn btn-ghost btn-sm" href="<?= e($self) ?>"><?= __('shop_cancel', 'Cancel') ?></a></form></td></tr>
                <?php else: ?>
                <tr>
                    <td><?= e($r['country'] === '*' ? __('shop_tax_anywhere', 'Anywhere') : (string) $r['country']) ?><?= $r['region'] !== '' ? ' · ' . e((string) $r['region']) : '' ?><?= $r['postcodes'] !== '' ? ' · ' . e(str_replace(',', ', ', (string) $r['postcodes'])) : '' ?><?= trim((string) $r['name']) !== '' ? ' <span class="text-muted text-sm">(' . e((string) $r['name']) . ')</span>' : '' ?></td>
                    <td><?= e(ShopTax::percentText((float) $r['rate'])) ?></td>
                    <td><?= e($classes[(string) $r['tax_class']] ?? (string) $r['tax_class']) ?></td>
                    <td><?= !empty($r['shipping']) ? __('shop_yes', 'Yes') : __('shop_no', 'No') ?></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <a class="btn btn-sm" href="?edit=<?= $rid ?>"><?= __('shop_edit', 'Edit') ?></a>
                        <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('<?= e(__('shop_tax_delete_confirm', 'Delete this rate?')) ?>')">
                            <?= csrf_field() ?><input type="hidden" name="_action" value="delete_rate"><input type="hidden" name="id" value="<?= $rid ?>">
                            <button class="btn btn-sm btn-danger"><?= __('shop_delete', 'Delete') ?></button></form>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="text-sm"><?= __('shop_tax_no_rates', 'No rates yet: no tax is worked out.') ?></p>
        <?php endif; ?>
        <h3 style="margin:1rem 0 .5rem;font-size:1rem;"><?= __('shop_tax_add_rate', 'Add a rate') ?></h3>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="save_rate"><input type="hidden" name="id" value="0">
            <?= $rateFields(null) ?>
            <button type="submit" class="btn btn-primary btn-sm"><?= __('shop_add', 'Add') ?></button></form>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
