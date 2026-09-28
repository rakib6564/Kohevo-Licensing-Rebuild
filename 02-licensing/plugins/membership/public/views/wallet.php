<?php
/** Member area — wallet balance + ledger. Scope: router.php ($cid). */
if (!defined('SLATE_ROOT')) exit;
$wallet = MembershipAPI::ensureWallet($cid);
$txns   = MembershipAPI::walletTxns($cid, 50);
?>
<div class="card">
    <div class="card-header"><h2><?= __('membership_wallet', 'Wallet') ?></h2></div>
    <p style="font-size:28px;font-weight:700;margin:0;">
        <?= e(MembershipAPI::money((int)($wallet['balance_cents'] ?? 0), $wallet['currency'] ?? null)) ?>
    </p>
    <p class="text-xs text-muted"><?= __('membership_balance', 'Current balance') ?></p>
</div>
<div class="card">
    <div class="card-header"><h2><?= __('membership_transactions', 'Transactions') ?></h2></div>
    <?php if (!$txns): ?>
        <div class="empty"><div class="empty-title"><?= __('membership_no_txns', 'No transactions yet') ?></div></div>
    <?php else: ?>
        <ul class="kv-list">
            <?php foreach ($txns as $t): $delta=(int)$t['delta_cents']; $sign=$delta>0?'+':($delta<0?'−':''); ?>
                <li class="kv-row">
                    <span class="kv-label"><strong style="color:var(--text);"><?= e(ucfirst((string)$t['type'])) ?></strong><br>
                        <span class="text-xs"><?= e((string)($t['description'] ?? '')) ?> · <?= e(slate_format_date($t['created_at'])) ?></span></span>
                    <span class="kv-value"><?= $delta!==0 ? e($sign . MembershipAPI::money(abs($delta), $wallet['currency'] ?? null)) : '—' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
