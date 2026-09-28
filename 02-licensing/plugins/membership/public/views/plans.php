<?php
/** Member app — Plans tab (premium). Scope: router.php. */
if (!defined('SLATE_ROOT')) exit;
$plans  = MembershipAPI::plans(true);
$insFee = MembershipAPI::insuranceFeeCents();
$types  = MembershipAPI::planTypes();
$cust   = Auth::customer();
$featuredId = 0;
foreach ($plans as $p) { if ($p['plan_type'] === 'membership') { $featuredId = (int)$p['id']; break; } }
if (!$featuredId && $plans) $featuredId = (int)$plans[0]['id'];
$chk = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
?>
<div class="mview-head">
    <div>
        <h1><?= __('membership_plans', 'Plans') ?></h1>
        <p><?= __('membership_lp_plans_p', 'Transparent pricing. No hidden fees.') ?></p>
    </div>
</div>

<?php
// Assurance upsell — when the member lacks insurance but courses require it.
$hasIns  = $cust ? MembershipAPI::hasInsurance((int)$cust['id']) : false;
$insPlan = null;
foreach ($plans as $pp) { if (($pp['plan_type'] ?? '') === 'insurance') { $insPlan = $pp; break; } }
$locked  = $cust ? array_values(array_filter(MembershipAPI::courseAccess((int)$cust['id']), fn($c) => !empty($c['locked']))) : [];
if (!$hasIns && $insPlan && $locked):
    $lnames = array_slice(array_map(fn($c) => $c['name'], $locked), 0, 3);
?>
<div class="upsell">
    <div class="upsell-txt">
        <b><?= slate_icon('lock') ?> <?= sprintf(__('membership_unlock_n', 'Unlock %d more course(s)'), count($locked)) ?></b>
        <p><?= sprintf(__('membership_unlock_p', 'Add the %s plan to access %s.'), e(MembershipAPI::planName($insPlan)), e(implode(' · ', $lnames))) ?></p>
    </div>
    <form method="post" action="<?= e(SLATE_URL) ?>/member?view=plans">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="buy">
        <input type="hidden" name="plan_id" value="<?= (int)$insPlan['id'] ?>">
        <button type="submit" class="mbtn mbtn-primary">+ <?= e(MembershipAPI::planName($insPlan)) ?> — <?= e(MembershipAPI::money((int)$insPlan['price_cents'], $insPlan['currency'])) ?></button>
    </form>
</div>
<?php endif; ?>

<?php if (!$plans): ?>
    <div class="pcard" style="text-align:center;"><div class="pcard-title"><?= __('membership_no_plans', 'No plans yet') ?></div></div>
<?php else: ?>
<div class="mpg">
    <?php foreach ($plans as $p):
        $feat    = (int)$p['id'] === $featuredId;
        $desc    = MembershipAPI::planDescription($p);
        $insMode = (string)($p['insurance_mode'] ?? 'none');
        $quota   = (int)($p['session_quota'] ?? 0);
    ?>
        <div class="mplan-card <?= $feat ? 'feat' : '' ?>">
            <div class="ptype"><?= e($types[$p['plan_type']] ?? $p['plan_type']) ?></div>
            <h3><?= e(MembershipAPI::planName($p)) ?></h3>
            <?php if ($desc !== ''): ?><p class="pdesc"><?= e($desc) ?></p><?php endif; ?>
            <div class="pprice"><b><?= e(MembershipAPI::money((int)$p['price_cents'], $p['currency'])) ?></b><span>/ <?= (int)$p['duration_days'] ?> <?= __('membership_days','days') ?></span></div>
            <ul>
                <li><?= $chk ?> <span><?= (int)$p['duration_days'] ?> <?= __('membership_lp_day_access', 'days of access') ?></span></li>
                <?php if ($quota > 0): ?><li><?= $chk ?> <span><?= $quota ?> <?= __('membership_sessions','sessions') ?></span></li><?php endif; ?>
                <?php if ((int)$p['grace_days'] > 0): ?><li><?= $chk ?> <span><?= (int)$p['grace_days'] ?> <?= __('membership_lp_grace', 'day grace period') ?></span></li><?php endif; ?>
            </ul>
            <div class="mplan-foot">
            <?php if ($cust): ?>
                <form method="post" action="<?= e(SLATE_URL) ?>/member?view=plans">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="buy">
                    <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                    <?php if ($insMode !== 'none' && $insFee > 0): ?>
                        <?php if ($insMode === 'required'): ?>
                            <input type="hidden" name="add_insurance" value="1">
                            <p class="ins-note" style="font-size:13px;color:var(--m-muted);margin:0 0 12px;">+ <?= e(MembershipAPI::money($insFee, $p['currency'])) ?> · <?= __('membership_ins_inc', 'insurance included') ?></p>
                        <?php else: ?>
                            <label class="mlp-ins" style="display:flex;gap:9px;align-items:center;font-size:13.5px;color:var(--m-ink-2);background:var(--accent-soft);border:1px solid var(--accent-ring);border-radius:11px;padding:11px 13px;margin:0 0 12px;cursor:pointer;">
                                <input type="checkbox" name="add_insurance" value="1" style="accent-color:var(--accent);width:17px;height:17px;">
                                <span><?= __('membership_add_insurance', 'Add insurance') ?> (+<?= e(MembershipAPI::money($insFee, $p['currency'])) ?>)</span>
                            </label>
                        <?php endif; ?>
                    <?php endif; ?>
                    <button type="submit" class="mbtn <?= $feat ? 'mbtn-primary' : 'mbtn-ghost' ?> mbtn-block"><?= __('membership_buy', 'Buy') ?></button>
                </form>
            <?php else: ?>
                <a href="<?= e(SLATE_URL) ?>/customer/register.php?next=<?= rawurlencode('/member?view=plans') ?>" class="mbtn mbtn-primary mbtn-block"><?= __('membership_buy', 'Buy') ?></a>
            <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
