<?php
/**
 * Restaurant — receipt markup for one order.
 *
 * Kept apart from admin/receipt.php so the page (which needs a signed-in
 * user) and the tests render the very same receipt. Everything shown comes
 * from the order (RestaurantAPI::getOrder) and the per-tenant settings:
 * business name and address, receipt header and footer.
 */

/** Payment method label as printed on a receipt. */
function restaurant_receipt_method(array $p): string {
    if ($p['method'] === 'cash')     return __('restaurant_rcpt_cash', 'Cash');
    if ($p['method'] === 'terminal') return __('restaurant_rcpt_card', 'Card');
    return !empty($p['stripe_pi_id']) || !empty($p['stripe_session_id'])
        ? __('restaurant_rcpt_card_online', 'Card (online)')
        : __('restaurant_rcpt_other', 'Other');
}

/** The receipt body (no page chrome) for an order from RestaurantAPI::getOrder(). */
function restaurant_receipt_html(array $order): string {
    $money = fn(int $c): string => e(RestaurantAPI::money($c));
    $biz   = RestaurantAPI::settings();
    $head  = trim((string) RestaurantAPI::setting('receipt_header', ''));
    $foot  = trim((string) RestaurantAPI::setting('receipt_footer', ''));
    $place = trim(implode(' ', array_filter([$biz['addr_city'], $biz['addr_state'], $biz['addr_postal']])));
    $types = [
        'dine_in'  => __('restaurant_rcpt_dine_in', 'Dine in'),
        'takeout'  => __('restaurant_rcpt_takeout', 'Takeout'),
        'delivery' => __('restaurant_rcpt_delivery', 'Delivery'),
    ];
    $server = !empty($order['server_id'])
        ? (string) Database::value("SELECT name FROM users WHERE id = ? AND tenant_id = ?",
            [(int) $order['server_id'], current_tenant_id()])
        : '';
    $when = fn(?string $ts): string => $ts ? e(date('M j, Y g:ia', strtotime($ts))) : '';

    ob_start(); ?>
<div class="rcpt">
    <header class="rcpt-head">
        <?php if ($biz['biz_name'] !== ''): ?><div class="rcpt-biz"><?= e($biz['biz_name']) ?></div><?php endif; ?>
        <?php foreach (array_filter([$biz['addr_line1'], $biz['addr_line2'], $place]) as $line): ?>
            <div><?= e($line) ?></div>
        <?php endforeach; ?>
        <?php if ($head !== ''): ?><div class="rcpt-text"><?= nl2br(e($head)) ?></div><?php endif; ?>
    </header>

    <div class="rcpt-meta">
        <div><span><?= e(__('restaurant_rcpt_order', 'Order')) ?></span><span>#<?= e((string) $order['order_no']) ?></span></div>
        <div><span><?= e(__('restaurant_rcpt_type', 'Type')) ?></span><span><?= e($types[$order['type']] ?? (string) $order['type']) ?></span></div>
        <?php if (trim((string) ($order['table_label'] ?? '')) !== ''): ?>
            <div><span><?= e(__('restaurant_rcpt_table', 'Table')) ?></span><span><?= e((string) $order['table_label']) ?></span></div>
        <?php endif; ?>
        <?php if ($server !== ''): ?>
            <div><span><?= e(__('restaurant_rcpt_server', 'Server')) ?></span><span><?= e($server) ?></span></div>
        <?php endif; ?>
        <?php if (trim((string) ($order['customer_name'] ?? '')) !== ''): ?>
            <div><span><?= e(__('restaurant_rcpt_customer', 'Customer')) ?></span><span><?= e((string) $order['customer_name']) ?></span></div>
        <?php endif; ?>
        <div><span><?= e(__('restaurant_rcpt_opened', 'Opened')) ?></span><span><?= $when($order['opened_at'] ?? null) ?></span></div>
        <?php if (!empty($order['closed_at'])): ?>
            <div><span><?= e(__('restaurant_rcpt_closed', 'Closed')) ?></span><span><?= $when($order['closed_at']) ?></span></div>
        <?php endif; ?>
    </div>

    <table class="rcpt-items">
        <?php foreach ($order['items'] as $it):
            if ($it['kitchen_status'] === 'void') continue; ?>
            <tr>
                <td class="q"><?= (int) $it['qty'] ?></td>
                <td>
                    <?= e((string) $it['name_snapshot']) ?>
                    <?php foreach (($it['modifiers'] ?? []) as $m): ?>
                        <div class="rcpt-mod">+ <?= e((string) $m['name_snapshot']) ?><?= (int) $m['price_delta_cents'] !== 0 ? ' ' . $money((int) $m['price_delta_cents']) : '' ?></div>
                    <?php endforeach; ?>
                </td>
                <td class="a"><?= $money((int) $it['line_total_cents']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div class="rcpt-sums">
        <div><span><?= e(__('restaurant_rcpt_subtotal', 'Subtotal')) ?></span><span><?= $money((int) $order['subtotal_cents']) ?></span></div>
        <?php if ((int) $order['discount_cents'] > 0): ?>
            <div><span><?= e(__('restaurant_rcpt_discount', 'Discount')) ?></span><span>-<?= $money((int) $order['discount_cents']) ?></span></div>
        <?php endif; ?>
        <?php if ((int) $order['service_charge_cents'] > 0): ?>
            <div><span><?= e(__('restaurant_rcpt_service', 'Service charge')) ?><?= (float) $order['service_charge_pct'] > 0 ? ' (' . e(rtrim(rtrim(number_format((float) $order['service_charge_pct'], 2), '0'), '.')) . '%)' : '' ?></span><span><?= $money((int) $order['service_charge_cents']) ?></span></div>
        <?php endif; ?>
        <div><span><?= e(__('restaurant_rcpt_tax', 'Tax')) ?></span><span><?= $money((int) $order['tax_cents']) ?></span></div>
        <?php if ((int) $order['tip_cents'] > 0): ?>
            <div><span><?= e(__('restaurant_rcpt_tip', 'Tip')) ?></span><span><?= $money((int) $order['tip_cents']) ?></span></div>
        <?php endif; ?>
        <div class="rcpt-total"><span><?= e(__('restaurant_rcpt_total', 'Total')) ?></span><span><?= $money((int) $order['total_cents']) ?></span></div>
    </div>

    <?php
    $settled = array_filter($order['payments'], fn($p) => in_array($p['status'], ['paid', 'refunded'], true));
    $refunds = array_filter($order['refunds'] ?? [], fn($r) => $r['status'] === 'succeeded');
    $balance = (int) $order['total_cents'] - (int) $order['paid_cents'];
    ?>
    <?php if ($settled || $refunds): ?>
        <div class="rcpt-pays">
            <?php foreach ($settled as $p): ?>
                <div><span><?= e(restaurant_receipt_method($p)) ?><?= (int) $p['tip_cents'] > 0 ? ' · ' . e(__('restaurant_rcpt_incl_tip', 'incl. tip')) . ' ' . $money((int) $p['tip_cents']) : '' ?></span><span><?= $money((int) $p['amount_cents'] + (int) $p['tip_cents']) ?></span></div>
            <?php endforeach; ?>
            <?php foreach ($refunds as $r): ?>
                <div><span><?= e(__('restaurant_rcpt_refund', 'Refund')) ?></span><span>-<?= $money((int) $r['amount_cents']) ?></span></div>
            <?php endforeach; ?>
            <div class="rcpt-total"><span><?= e(__('restaurant_rcpt_paid', 'Paid')) ?></span><span><?= $money((int) $order['paid_cents']) ?></span></div>
            <?php if ($balance > 0 && in_array($order['status'], ['open', 'sent'], true)): ?>
                <div><span><?= e(__('restaurant_rcpt_balance', 'Balance due')) ?></span><span><?= $money($balance) ?></span></div>
            <?php endif; ?>
        </div>
    <?php elseif (in_array($order['status'], ['open', 'sent'], true)): ?>
        <div class="rcpt-pays"><div class="rcpt-total"><span><?= e(__('restaurant_rcpt_balance', 'Balance due')) ?></span><span><?= $money((int) $order['total_cents']) ?></span></div></div>
    <?php endif; ?>
    <?php if ($order['status'] === 'voided'): ?>
        <div class="rcpt-void"><?= e(__('restaurant_rcpt_voided', 'VOID')) ?></div>
    <?php endif; ?>

    <footer class="rcpt-foot">
        <?php if ($foot !== ''): ?><div class="rcpt-text"><?= nl2br(e($foot)) ?></div><?php endif; ?>
        <div class="rcpt-printed"><?= e(__('restaurant_rcpt_printed', 'Printed')) ?> <?= $when(slate_db_now()) ?></div>
    </footer>
</div>
    <?php
    return (string) ob_get_clean();
}
