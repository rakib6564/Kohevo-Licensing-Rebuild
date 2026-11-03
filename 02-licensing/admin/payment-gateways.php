<?php
/**
 * Slate — Payments → Gateways.
 *
 * One place to see every payment gateway (Stripe, PayPal, …), whether its
 * keys are in, and which modules may offer it to customers. Keys and
 * webhooks stay on each gateway's own settings page; this page only decides
 * where a configured gateway is offered and which one is listed first.
 */
require_once dirname(__DIR__) . '/config.php';

Auth::require();
Auth::requirePerm('payments.manage_gateways');

$gateways  = Payments::gateways();
$consumers = Payments::consumers();

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $on = (array)($_POST['on'] ?? []);
        $changes = [];
        foreach ($gateways as $gid => $gw) {
            foreach ($consumers as $slug => $label) {
                $want = !empty($on[$gid][$slug]);
                if ($want !== Payments::enabledFor($gid, $slug)) {
                    Payments::setEnabledFor($gid, $slug, $want);
                    $changes[] = "{$gid}:{$slug}=" . ($want ? 'on' : 'off');
                }
            }
        }
        $default = (string)($_POST['default_gateway'] ?? '');
        if ($default === '' || isset($gateways[$default])) {
            if ($default !== (string)(Database::setting('payments.default_gateway') ?? '')) {
                Database::setSetting('payments.default_gateway', $default);
                $changes[] = 'default=' . ($default ?: 'none');
            }
        }
        if ($changes) AuditLog::record('payments.gateways_changed', '', ['changes' => $changes]);
        $flash = ['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')];
    }
}

$default = (string)(Database::setting('payments.default_gateway') ?? '');
$capLabels = [
    'hosted'         => __('payments_cap_hosted', 'Checkout page'),
    'recurring'      => __('payments_cap_recurring', 'Monthly / yearly'),
    'partial_refund' => __('payments_cap_partial_refund', 'Partial refunds'),
    'inline_card'    => __('payments_cap_inline_card', 'Card form on your site'),
    'card_present'   => __('payments_cap_card_present', 'Card readers'),
];

$pageTitle  = __('payments_gateways', 'Payment gateways');
$currentNav = 'payment-gateways';
require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('payments', 'Payments')],
    ['label' => __('payments_gateways', 'Payment gateways')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('payments_gateways', 'Payment gateways')) ?></h1>
        <p class="page-header-sub"><?= e(__('payments_gateways_sub', 'Choose which gateways each module offers at checkout. When more than one is offered, the customer picks.')) ?></p>
    </div>
    <div class="toolbar">
        <a href="<?= e(SLATE_URL . '/admin/payments.php') ?>" class="btn"><?= e(__('payments_transactions', 'Transactions')) ?></a>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$gateways): ?>
    <div class="card"><div class="empty">
        <div class="empty-title"><?= e(__('payments_no_gateways', 'No payment gateway is installed')) ?></div>
        <p class="text-sm"><?= e(__('payments_no_gateways_desc', 'Activate the Stripe or PayPal plugin under Plugins to start taking payments.')) ?></p>
    </div></div>
<?php else: ?>

<div class="card">
    <div class="card-header"><h2><?= e(__('payments_gateways_installed', 'Installed gateways')) ?></h2></div>
    <div class="dlist">
        <?php foreach ($gateways as $gid => $gw):
            $caps = array_values(array_filter($capLabels, fn ($k) => $gw->supports($k), ARRAY_FILTER_USE_KEY));
            slate_dlist_row([
                'avatar_html'  => slate_avatar_overlay_html(mb_substr($gw->label(), 0, 2), $gw->label()),
                'avatar_color' => $gw->isConfigured() ? 'success' : 'muted',
                'title'        => $gw->label(),
                'sub'          => ($gw->isConfigured()
                                    ? sprintf(__('payments_gateway_ready', 'Ready · %s mode'), $gw->mode())
                                    : __('payments_gateway_needs_keys', 'Needs API keys'))
                                  . ($caps ? ' · ' . implode(', ', $caps) : ''),
                'amount'       => __('settings', 'Settings') . ' →',
                'href'         => $gw->settingsUrl(),
            ]);
        endforeach; ?>
    </div>
</div>

<form method="post" class="card">
    <?= csrf_field() ?>
    <div class="card-header"><h2><?= e(__('payments_where_offered', 'Where each gateway is offered')) ?></h2></div>

    <?php if (!$consumers): ?>
        <p class="text-sm text-muted"><?= e(__('payments_no_consumers', 'No active module takes payments yet.')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= e(__('payments_module', 'Module')) ?></th>
                        <?php foreach ($gateways as $gw): ?><th><?= e($gw->label()) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($consumers as $slug => $label): ?>
                        <tr>
                            <td><?= e($label) ?></td>
                            <?php foreach ($gateways as $gid => $gw): ?>
                                <td>
                                    <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                                        <input type="checkbox" name="on[<?= e($gid) ?>][<?= e($slug) ?>]" value="1"
                                               <?= Payments::enabledFor($gid, $slug) ? 'checked' : '' ?>
                                               aria-label="<?= e($gw->label() . ' — ' . $label) ?>">
                                        <span><?= e(__('payments_offer', 'Offer')) ?></span>
                                    </label>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="field mt-4">
        <label class="field-label" for="default_gateway"><?= e(__('payments_default_gateway', 'Listed first')) ?></label>
        <select id="default_gateway" name="default_gateway">
            <option value=""><?= e(__('payments_default_none', 'No preference')) ?></option>
            <?php foreach ($gateways as $gid => $gw): ?>
                <option value="<?= e($gid) ?>" <?= $default === $gid ? 'selected' : '' ?>><?= e($gw->label()) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="field-hint"><?= e(__('payments_default_help', 'The gateway shown first, and used when a module can only offer one.')) ?></p>
    </div>

    <div class="form-actions">
        <button class="btn btn-primary"><?= e(__('save', 'Save')) ?></button>
    </div>
</form>

<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
