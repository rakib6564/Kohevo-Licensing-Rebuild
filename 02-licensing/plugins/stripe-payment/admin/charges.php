<?php
/**
 * Stripe Payment — Charges admin page.
 *
 * Cross-plugin ledger of every successful Stripe charge that landed
 * via the Stripe Payment plugin. Filter by source plugin, mode,
 * status, customer email. Refund (full or partial) from inline action.
 *
 * The `stripepayment_charges` table is populated by the webhook
 * endpoint (public/webhook.php) on every `checkout.session.completed`
 * and `payment_intent.succeeded` event.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StripePaymentAPI.php';

Auth::require();
Auth::requirePerm('stripe.manage_charges');

StripePaymentAPI::ensureChargesSchema();

$tid = current_tenant_id();

// ── POST: refund ─────────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action     = $_POST['_action'] ?? '';
        $chargeId   = (int)($_POST['id'] ?? 0);
        $amountVal  = trim((string)($_POST['amount'] ?? ''));
        if ($action === 'refund' && $chargeId > 0) {
            $amountCents = null;
            if ($amountVal !== '') {
                $amountCents = (int) round(((float)$amountVal) * 100);
            }
            $result = StripePaymentAPI::refundCharge($chargeId, $amountCents);
            if ($result['ok']) {
                $flash = ['type' => 'success',
                    'msg' => sprintf(__('stripe_charges_refunded_msg', 'Refunded. Stripe refund id: %s'), $result['refund_id'])];
            } else {
                $flash = ['type' => 'error', 'msg' => $result['error']];
            }
        }
    }
}

// ── Filters ──────────────────────────────────────────────────
$sourcePlugin = trim((string)($_GET['source_plugin'] ?? ''));
$mode         = trim((string)($_GET['mode']          ?? ''));
$status       = trim((string)($_GET['status']        ?? ''));
$emailLike    = trim((string)($_GET['email']         ?? ''));
$days         = max(1, min(365, (int)($_GET['days'] ?? 30)));

$rows = StripePaymentAPI::listCharges([
    'source_plugin' => $sourcePlugin !== '' ? $sourcePlugin : null,
    'mode'          => $mode !== ''         ? $mode         : null,
    'status'        => $status !== ''       ? $status       : null,
    'email_like'    => $emailLike !== ''    ? $emailLike    : null,
    'days'          => $days,
], 200);

// Aside summary
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

// Distinct source plugins for filter dropdown
$plugins = array_column(Database::rows(
    "SELECT DISTINCT source_plugin FROM stripepayment_charges
      WHERE tenant_id = ? ORDER BY source_plugin", [$tid]
), 'source_plugin');

$pageTitle  = __('stripe_charges_page_title', 'Stripe Charges');
$currentNav = 'shop-stripe-charges';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Stripe', 'href' => plugin_url('stripe-payment', 'admin/settings.php')],
    ['label' => __('stripe_charges_label', 'Charges')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('stripe_charges_page_title', 'Stripe Charges')) ?></h1>
        <p class="page-header-sub"><?= e(__('stripe_charges_page_sub', 'Every Stripe charge across the active plugins.')) ?></p>
    </div>
    <div class="toolbar">
        <a href="<?= e(plugin_url('stripe-payment', 'admin/settings.php')) ?>" class="btn"><?= e(__('stripe_charges_settings_btn', 'Stripe settings')) ?></a>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card tight">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="email"><?= e(__('stripe_charges_filter_email', 'Customer email')) ?></label>
            <input type="text" id="email" name="email" value="<?= e($emailLike) ?>" placeholder="<?= e(__('stripe_charges_email_placeholder', 'anything containing…')) ?>">
        </div>
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
            <div class="card"><div class="empty"><div class="empty-title"><?= e(__('stripe_charges_empty_title', 'No charges in this window')) ?></div><p class="text-sm"><?= e(__('stripe_charges_empty_desc', 'When a customer pays through Stripe, the charge appears here.')) ?></p></div></div>
        <?php else: ?>
            <div class="data-list" data-single-open>
                <?php foreach ($rows as $r):
                    $amount   = (int)$r['amount_cents'];
                    $refunded = (int)$r['refunded_cents'];
                    $net      = max(0, $amount - $refunded);

                    $statusBadge = [
                        'succeeded'      => [__('stripe_charges_status_succeeded', 'Succeeded'),      'active'],
                        'partial_refund' => [__('stripe_charges_status_partial_refund', 'Partial refund'), 'warning'],
                        'refunded'       => [__('stripe_charges_status_refunded', 'Refunded'),        'inactive'],
                        'failed'         => [__('stripe_charges_status_failed', 'Failed'),            'danger'],
                    ][$r['status']] ?? ['?', ''];

                    $detail = [
                        __('stripe_charges_detail_amount', 'Amount')   => slate_format_price_plain((int)$amount, $r['currency']),
                        __('stripe_charges_detail_refunded', 'Refunded') => $refunded > 0
                                       ? slate_format_price_plain((int)$refunded, $r['currency'])
                                       : '—',
                        __('stripe_charges_detail_net', 'Net')      => slate_format_price_plain((int)$net, $r['currency']),
                        'Mode'     => ['label' => __('stripe_charges_mode_label', 'Mode'), 'html' =>
                                       '<span class="badge ' . ($r['mode'] === 'live' ? 'badge-accent' : 'badge-warning') . '">'
                                       . e($r['mode']) . '</span>'],
                        __('stripe_charges_detail_source', 'Source')   => $r['source_plugin']
                                      . ($r['source_id'] !== null && $r['source_id'] !== '' ? ' · ' . $r['source_id'] : ''),
                        'PaymentIntent' => ['label' => __('stripe_charges_detail_payment_intent', 'PaymentIntent'), 'html' =>
                                            $r['stripe_payment_intent_id']
                                            ? '<code style="font-size:11.5px;">' . e($r['stripe_payment_intent_id']) . '</code>'
                                            : '—'],
                    ];
                    if ($r['stripe_session_id']) {
                        $detail['Session'] = ['label' => __('stripe_charges_detail_session', 'Session'), 'html' =>
                            '<code style="font-size:11.5px;">' . e($r['stripe_session_id']) . '</code>'];
                    }

                    $canRefund = in_array($r['status'], ['succeeded', 'partial_refund'], true) && $net > 0;
                    $actions = '';
                    if ($canRefund) {
                        $maxRefund = number_format($net / 100, 2, '.', '');
                        $refundLabel = __('stripe_charges_refund_btn', 'Refund');
                        $actions = '<form method="post" style="display:inline-flex;align-items:center;gap:6px;margin:0;" onsubmit="return confirm(\'' . e($refundLabel) . ' ' . $r['currency'] . ' \' + (this.amount.value || \'' . $maxRefund . '\') + \'?\')">'
                                 . csrf_field()
                                 . '<input type="hidden" name="_action" value="refund">'
                                 . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                                 . '<input type="number" name="amount" step="0.01" min="0.01" max="' . e($maxRefund) . '" placeholder="' . e($maxRefund) . '" style="width:100px;" class="input">'
                                 . '<button class="btn btn-sm btn-danger">' . e($refundLabel) . '</button>'
                                 . '</form>';
                    } else {
                        $actions = '<span class="text-sm text-muted">' . e(__('stripe_charges_no_refund', 'No refund available')) . '</span>';
                    }

                    slate_data_row([
                        'avatar'       => mb_substr($r['source_plugin'], 0, 1),
                        'avatar_color' => $r['mode'] === 'live' ? 'success' : 'warning',
                        'title'        => ($r['customer_email'] ?: __('stripe_charges_no_email', '(no email)'))
                                          . ' · ' . slate_format_price_plain((int)$amount, $r['currency']),
                        'meta'         => $r['source_plugin'] . ' · ' . I18n::localDate('j M Y, H:i', strtotime($r['created_at'])),
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
                <?= __('stripe_charges_how_desc1', 'Every <code>checkout.session.completed</code> and <code>payment_intent.succeeded</code> webhook from Stripe records a row here. Refunds call <code>StripePaymentAPI::refundCharge()</code> which POSTs to <code>/v1/refunds</code> and updates the row.') ?>
            </p>
            <p class="text-sm text-muted" style="margin:0;">
                <?= __('stripe_charges_how_desc2', 'Plugins integrate via the <code>stripe_webhook_event</code> action — see <code>docs/BUILDING-PLUGINS.md</code> §12.') ?>
            </p>
        </div>
    </aside>

<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
