<?php
/**
 * Slate — Payments → Transactions.
 *
 * Every charge from every payment gateway (Stripe, PayPal, …) across the
 * active plugins, from the shared ledger. Filter by gateway, source plugin,
 * mode, status and customer email; refund (full or partial) inline — the
 * refund goes through the gateway that took the money.
 *
 * Also served as the Stripe plugin's Charges page
 * (plugins/stripe-payment/admin/charges.php), which checks its own
 * stripe.manage_charges permission first and then includes this file.
 */
require_once dirname(__DIR__) . '/config.php';

use Slate\Services\Payments\PaymentLedger;

Auth::require();
if (!Auth::can('payments.manage_charges') && !Auth::can('stripe.manage_charges')) {
    Auth::requirePerm('payments.manage_charges');
}

PaymentLedger::ensureSchema();
$tid       = current_tenant_id();
$gateways  = Payments::gateways();
$gwLabel   = static fn (string $id): string => isset($gateways[$id]) ? $gateways[$id]->label() : ucfirst($id);

// ── POST: refund ─────────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action    = $_POST['_action'] ?? '';
        $chargeId  = (int)($_POST['id'] ?? 0);
        $amountVal = trim((string)($_POST['amount'] ?? ''));
        if ($action === 'refund' && $chargeId > 0) {
            $charge      = Payments::getCharge($chargeId);
            $amountCents = ($amountVal !== '' && $charge)
                ? Payments::toMinor($amountVal, (string)$charge['currency'])
                : null;
            // Same charge, same prior refund total, same amount → same key, so a
            // double-submitted form cannot refund twice at the gateway.
            $idemKey = 'admin-refund-' . $chargeId . '-' . (int)($charge['refunded_cents'] ?? 0) . '-' . ($amountCents ?? 'rest');
            $result  = Payments::refundCharge($chargeId, $amountCents, $idemKey);
            if ($result['ok']) {
                $flash = ['type' => 'success',
                    'msg' => sprintf(__('payments_refunded_msg', 'Refunded. Gateway refund id: %s'), $result['refund_id'])];
            } else {
                $flash = ['type' => 'error', 'msg' => $result['error']
                    . (!empty($result['uncertain']) ? ' ' . __('payments_refund_uncertain', 'The gateway may still have processed it — check its dashboard before trying again.') : '')];
            }
        }
    }
}

// ── Filters ──────────────────────────────────────────────────
$gatewayF     = trim((string)($_GET['gateway']       ?? ''));
$sourcePlugin = trim((string)($_GET['source_plugin'] ?? ''));
$mode         = trim((string)($_GET['mode']          ?? ''));
$status       = trim((string)($_GET['status']        ?? ''));
$emailLike    = trim((string)($_GET['email']         ?? ''));
$days         = max(1, min(365, (int)($_GET['days'] ?? 30)));

$rows = Payments::listCharges([
    'gateway'       => $gatewayF !== ''     ? $gatewayF     : null,
    'source_plugin' => $sourcePlugin !== '' ? $sourcePlugin : null,
    'mode'          => $mode !== ''         ? $mode         : null,
    'status'        => $status !== ''       ? $status       : null,
    'email_like'    => $emailLike !== ''    ? $emailLike    : null,
    'days'          => $days,
], 200);

$summary = Database::row(
    "SELECT
        COUNT(*) AS n,
        COALESCE(SUM(amount_cents), 0)        AS gross,
        COALESCE(SUM(refunded_cents), 0)      AS refunded,
        SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END)     AS n_succeeded,
        SUM(CASE WHEN status IN ('refunded','partial_refund') THEN 1 ELSE 0 END) AS n_refunded
       FROM stripepayment_charges
      WHERE tenant_id = ? AND created_at >= NOW() - INTERVAL " . (int)$days . " DAY",
    [$tid]
) ?: ['n'=>0,'gross'=>0,'refunded'=>0,'n_succeeded'=>0,'n_refunded'=>0];

$plugins = array_column(Database::rows(
    "SELECT DISTINCT source_plugin FROM stripepayment_charges
      WHERE tenant_id = ? ORDER BY source_plugin", [$tid]
), 'source_plugin');
$usedGateways = array_column(Database::rows(
    "SELECT DISTINCT gateway FROM stripepayment_charges
      WHERE tenant_id = ? ORDER BY gateway", [$tid]
), 'gateway');
$gatewayOptions = array_values(array_unique(array_merge(array_keys($gateways), $usedGateways)));

$pageTitle  = __('payments_transactions', 'Transactions');
$currentNav = $currentNav ?? 'payments-transactions';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('payments', 'Payments')],
    ['label' => __('payments_transactions', 'Transactions')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('payments_transactions', 'Transactions')) ?></h1>
        <p class="page-header-sub"><?= e(__('payments_transactions_sub', 'Every payment taken through any gateway, across the active plugins.')) ?></p>
    </div>
    <?php if (Auth::can('payments.manage_gateways')): ?>
    <div class="toolbar">
        <a href="<?= e(SLATE_URL . '/admin/payment-gateways.php') ?>" class="btn"><?= e(__('payments_gateways', 'Payment gateways')) ?></a>
    </div>
    <?php endif; ?>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card tight">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="email"><?= e(__('stripe_charges_filter_email', 'Customer email')) ?></label>
            <input type="text" id="email" name="email" value="<?= e($emailLike) ?>" placeholder="<?= e(__('stripe_charges_email_placeholder', 'anything containing…')) ?>">
        </div>
        <?php if (count($gatewayOptions) > 1): ?>
        <div class="field">
            <label class="field-label" for="gateway"><?= e(__('payments_gateway', 'Gateway')) ?></label>
            <select id="gateway" name="gateway">
                <option value=""><?= e(__('all', 'All')) ?></option>
                <?php foreach ($gatewayOptions as $g): ?>
                    <option value="<?= e($g) ?>" <?= $gatewayF === $g ? 'selected' : '' ?>><?= e($gwLabel($g)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="field">
            <label class="field-label" for="source_plugin"><?= e(__('stripe_charges_filter_source', 'Source plugin')) ?></label>
            <select id="source_plugin" name="source_plugin">
                <option value=""><?= e(__('all', 'All')) ?></option>
                <?php foreach ($plugins as $sp): ?>
                    <option value="<?= e($sp) ?>" <?= $sourcePlugin === $sp ? 'selected' : '' ?>><?= e($sp) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="mode"><?= e(__('stripe_charges_mode_label', 'Mode')) ?></label>
            <select id="mode" name="mode">
                <option value=""><?= e(__('all', 'All')) ?></option>
                <option value="test" <?= $mode === 'test' ? 'selected' : '' ?>><?= e(__('stripe_charges_mode_test', 'Test')) ?></option>
                <option value="live" <?= $mode === 'live' ? 'selected' : '' ?>><?= e(__('stripe_charges_mode_live', 'Live')) ?></option>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="status"><?= e(__('status', 'Status')) ?></label>
            <select id="status" name="status">
                <option value=""><?= e(__('all', 'All')) ?></option>
                <option value="succeeded"      <?= $status === 'succeeded'      ? 'selected' : '' ?>><?= e(__('stripe_charges_status_succeeded', 'Succeeded')) ?></option>
                <option value="partial_refund" <?= $status === 'partial_refund' ? 'selected' : '' ?>><?= e(__('stripe_charges_status_partial_refund', 'Partial refund')) ?></option>
                <option value="refunded"       <?= $status === 'refunded'       ? 'selected' : '' ?>><?= e(__('stripe_charges_status_refunded', 'Refunded')) ?></option>
                <option value="failed"         <?= $status === 'failed'         ? 'selected' : '' ?>><?= e(__('stripe_charges_status_failed', 'Failed')) ?></option>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="days"><?= e(__('stripe_charges_filter_window', 'Window')) ?></label>
            <select id="days" name="days">
                <?php foreach ([7, 30, 90, 180, 365] as $d): ?>
                    <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= e(sprintf(__('stripe_charges_last_n_days', 'Last %d days'), $d)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn"><?= e(__('filter', 'Filter')) ?></button>
            <a href="?" class="btn btn-ghost"><?= e(__('reset', 'Reset')) ?></a>
        </div>
    </form>
</div>

<?php slate_page_layout('with-aside'); ?>

    <div class="page-main">
        <?php if (!$rows): ?>
            <div class="card"><div class="empty"><div class="empty-title"><?= e(__('stripe_charges_empty_title', 'No charges in this window')) ?></div><p class="text-sm"><?= e(__('payments_empty_desc', 'When a customer pays through any gateway, the charge appears here.')) ?></p></div></div>
        <?php else: ?>
            <div class="data-list" data-single-open>
                <?php foreach ($rows as $r):
                    $amount   = (int)$r['amount_cents'];
                    $refunded = (int)$r['refunded_cents'];
                    $net      = max(0, $amount - $refunded);
                    $gw       = (string)($r['gateway'] ?? 'stripe');

                    $statusBadge = [
                        'succeeded'      => [__('stripe_charges_status_succeeded', 'Succeeded'),      'active'],
                        'partial_refund' => [__('stripe_charges_status_partial_refund', 'Partial refund'), 'warning'],
                        'refunded'       => [__('stripe_charges_status_refunded', 'Refunded'),        'inactive'],
                        'failed'         => [__('stripe_charges_status_failed', 'Failed'),            'danger'],
                    ][$r['status']] ?? ['?', ''];

                    $txn = PaymentLedger::txnOf($r);
                    $ref = PaymentLedger::refOf($r);
                    $detail = [
                        __('stripe_charges_detail_amount', 'Amount')     => slate_format_price_plain($amount, $r['currency']),
                        __('stripe_charges_detail_refunded', 'Refunded') => $refunded > 0 ? slate_format_price_plain($refunded, $r['currency']) : '—',
                        __('stripe_charges_detail_net', 'Net')           => slate_format_price_plain($net, $r['currency']),
                        __('payments_gateway', 'Gateway')                => $gwLabel($gw),
                        'Mode' => ['label' => __('stripe_charges_mode_label', 'Mode'), 'html' =>
                                    '<span class="badge ' . ($r['mode'] === 'live' ? 'badge-accent' : 'badge-warning') . '">' . e($r['mode']) . '</span>'],
                        __('stripe_charges_detail_source', 'Source')     => $r['source_plugin']
                                    . ($r['source_id'] !== null && $r['source_id'] !== '' ? ' · ' . $r['source_id'] : ''),
                        'Txn' => ['label' => __('payments_detail_txn', 'Transaction'), 'html' =>
                                    $txn !== '' ? '<code style="font-size:11.5px;">' . e($txn) . '</code>' : '—'],
                    ];
                    if ($ref !== '') {
                        $detail['Ref'] = ['label' => __('payments_detail_ref', 'Checkout'), 'html' =>
                            '<code style="font-size:11.5px;">' . e($ref) . '</code>'];
                    }

                    $refundable = isset($gateways[$gw]) && $gateways[$gw]->supports('refund');
                    $partialOk  = $refundable && $gateways[$gw]->supports('partial_refund');
                    $canRefund  = $refundable && in_array($r['status'], ['succeeded', 'partial_refund'], true) && $net > 0;
                    if ($canRefund) {
                        $dec         = Payments::decimals((string)$r['currency']);
                        $maxRefund   = Payments::toMajor($net, (string)$r['currency']);
                        $step        = $dec === 0 ? '1' : '0.01';
                        $refundLabel = __('stripe_charges_refund_btn', 'Refund');
                        $actions = '<form method="post" style="display:inline-flex;align-items:center;gap:6px;margin:0;" onsubmit="return confirm(\'' . e($refundLabel) . ' ' . e($r['currency']) . ' \' + (this.amount ? (this.amount.value || \'' . $maxRefund . '\') : \'' . $maxRefund . '\') + \'?\')">'
                                 . csrf_field()
                                 . '<input type="hidden" name="_action" value="refund">'
                                 . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                                 . ($partialOk
                                     ? '<input type="number" name="amount" step="' . $step . '" min="' . $step . '" max="' . e($maxRefund) . '" placeholder="' . e($maxRefund) . '" style="width:100px;" class="input">'
                                     : '')
                                 . '<button class="btn btn-sm btn-danger">' . e($refundLabel) . '</button>'
                                 . '</form>';
                    } else {
                        $actions = '<span class="text-sm text-muted">' . e(__('stripe_charges_no_refund', 'No refund available')) . '</span>';
                    }

                    slate_data_row([
                        'avatar'       => mb_substr($r['source_plugin'], 0, 1),
                        'avatar_color' => $r['mode'] === 'live' ? 'success' : 'warning',
                        'title'        => ($r['customer_email'] ?: __('stripe_charges_no_email', '(no email)'))
                                          . ' · ' . slate_format_price_plain($amount, $r['currency']),
                        'meta'         => $gwLabel($gw) . ' · ' . $r['source_plugin'] . ' · ' . I18n::localDate('j M Y, H:i', strtotime($r['created_at'])),
                        'badge'        => $statusBadge,
                        'detail'       => $detail,
                        'actions'      => $actions,
                    ]);
                endforeach; ?>
            </div>
            <?php slate_data_list_script(); ?>
        <?php endif; ?>
    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title"><?= e(sprintf(__('stripe_charges_last_n_days', 'Last %d days'), (int)$days)) ?></div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label"><?= e(__('stripe_charges_label', 'Charges')) ?></span><span class="kv-value kv-mono"><?= (int)$summary['n'] ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('stripe_charges_status_succeeded', 'Succeeded')) ?></span><span class="kv-value kv-mono"><?= (int)$summary['n_succeeded'] ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('stripe_charges_kv_refunded_any', 'Refunded (any)')) ?></span><span class="kv-value kv-mono"><?= (int)$summary['n_refunded'] ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('stripe_charges_kv_gross', 'Gross')) ?></span><span class="kv-value kv-mono"><?= number_format(((int)$summary['gross']) / 100, 2) ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('stripe_charges_status_refunded', 'Refunded')) ?></span><span class="kv-value kv-mono"><?= number_format(((int)$summary['refunded']) / 100, 2) ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('stripe_charges_detail_net', 'Net')) ?></span><span class="kv-value kv-mono"><strong><?= number_format((((int)$summary['gross']) - ((int)$summary['refunded'])) / 100, 2) ?></strong></span></li>
            </ul>
            <p class="text-xs text-muted mt-2"><?= e(__('stripe_charges_currency_note', 'Amounts assume a single currency. Mixed-currency totals are approximate.')) ?></p>
        </div>

        <div class="aside-card">
            <div class="aside-card-title"><?= e(__('stripe_charges_how_title', 'How this works')) ?></div>
            <p class="text-sm text-muted" style="margin:0 0 var(--space-3);">
                <?= e(__('payments_how_desc1', 'Each gateway records a row here when a payment succeeds, whether the customer came back to the site or only the gateway\'s webhook arrived. A refund is sent to the gateway that took the money.')) ?>
            </p>
            <p class="text-sm text-muted" style="margin:0;">
                <?= __('payments_how_desc2', 'Plugins take payments through <code>Payments::startCheckout()</code> and react to the <code>payment_event</code> action — see <code>docs/BUILDING-PLUGINS.md</code> §12.') ?>
            </p>
        </div>
    </aside>

<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
