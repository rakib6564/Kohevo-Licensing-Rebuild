<?php
/**
 * Membership — single member view.
 *
 * Shows the member (core customer) + their profile, subscriptions and wallet.
 * Staff actions: manual/offline activation (cash/in-person), cancel a
 * subscription, and adjust the wallet balance.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/MembershipAPI.php';

Auth::require();
if (!Auth::can('membership.view') && !Auth::isSuperAdmin()) {
    Auth::requirePerm('membership.view');
}
ModuleGuard::require('membership');
MembershipAPI::ensureSchema();

$tid = current_tenant_id();
$cid = (int)($_GET['id'] ?? 0);

$member = Database::row("SELECT * FROM customers WHERE id = ? AND tenant_id = ?", [$cid, $tid]);
if (!$member) { http_response_code(404); echo 'Member not found.'; exit; }

$pageTitle  = 'Member — ' . ($member['name'] ?: $member['email']);
$currentNav = 'membership-members';
$flash = !empty($_GET['assigned']) ? ['type' => 'success', 'msg' => __('membership_activated_msg', 'Membership activated manually.')] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } elseif (!Auth::can('membership.manage_members') && !Auth::isSuperAdmin()) {
        $flash = ['type' => 'error', 'msg' => 'You do not have permission to manage members.'];
    } else {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'manual_activate') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $note   = trim((string)($_POST['note'] ?? ''));
            $subId  = MembershipAPI::manualActivate($cid, $planId, $note, !empty($_POST['add_insurance']));
            $flash  = $subId
                ? ['type' => 'success', 'msg' => 'Membership activated manually.']
                : ['type' => 'error', 'msg' => 'Could not activate — check the plan.'];
        }

        elseif ($action === 'cancel_sub') {
            $subId = (int)($_POST['sub_id'] ?? 0);
            $sub   = MembershipAPI::subscription($subId);
            if ($sub && (int)$sub['customer_id'] === $cid) {
                MembershipAPI::cancelSubscription($subId, false);
                $flash = ['type' => 'success', 'msg' => 'Subscription cancelled.'];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Subscription not found.'];
            }
        }

        elseif ($action === 'wallet_adjust') {
            $amount = (int) round(((float)($_POST['amount'] ?? 0)) * 100);
            $desc   = trim((string)($_POST['description'] ?? '')) ?: 'Admin adjustment';
            if ($amount !== 0) {
                MembershipAPI::walletAdjust($cid, $amount, 'adjustment', $desc, 'admin');
                AuditLog::record('membership.wallet_adjusted', (string)$cid, ['amount' => $amount]);
                $flash = ['type' => 'success', 'msg' => 'Wallet adjusted.'];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Enter a non-zero amount.'];
            }
        }
    }
}

$profile = MembershipAPI::profile($cid);
$subs    = MembershipAPI::subscriptionsForCustomer($cid);
$wallet  = MembershipAPI::ensureWallet($cid);
$txns    = MembershipAPI::walletTxns($cid, 20);
$plans   = MembershipAPI::plans(true);
$types   = MembershipAPI::planTypes();
$canManage = Auth::can('membership.manage_members') || Auth::isSuperAdmin();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('membership', 'Membership'), 'href' => plugin_url('membership', 'admin/index.php')],
    ['label' => __('membership_members', 'Members'), 'href' => plugin_url('membership', 'admin/members.php')],
    ['label' => $member['name'] ?: $member['email']],
]); ?>

<div class="page-header"><div><h1><?= e($member['name'] ?: $member['email']) ?></h1>
    <p class="page-header-sub"><?= e($member['email']) ?></p></div></div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php slate_page_layout('with-aside'); ?>

    <div class="page-main">

        <div class="card">
            <div class="card-header"><h2><?= __('membership_subscriptions', 'Subscriptions') ?></h2></div>
            <?php if (!$subs): ?>
                <div class="empty"><div class="empty-title"><?= __('membership_no_subs', 'No subscriptions yet') ?></div></div>
            <?php else: ?>
                <ul class="kv-list">
                    <?php foreach ($subs as $s):
                        $colors = ['active'=>'badge-active','expired'=>'badge-muted','cancelled'=>'badge-danger','paused'=>'badge-warning','pending'=>'badge-warning'];
                    ?>
                        <li class="kv-row">
                            <span class="kv-label">
                                <strong style="color:var(--text);"><?= e(MembershipAPI::planName(['name'=>$s['plan_name']??'','name_fr'=>$s['plan_name_fr']??''])) ?></strong><br>
                                <span class="text-xs">
                                    <?= e($types[$s['plan_type']] ?? $s['plan_type']) ?> ·
                                    <?= e(MembershipAPI::money((int)$s['amount_cents'], $s['currency'])) ?> ·
                                    <?= e((string)$s['activation']) ?>
                                    <?php if (!empty($s['expires_at'])): ?> · <?= __('membership_expires', 'Expires') ?> <?= e(slate_format_date($s['expires_at'])) ?><?php endif; ?>
                                </span>
                            </span>
                            <span class="kv-value" style="display:flex;align-items:center;gap:8px;">
                                <span class="badge <?= $colors[$s['status']] ?? 'badge-muted' ?>"><?= e(ucfirst((string)$s['status'])) ?></span>
                                <?php if ($canManage && in_array($s['status'], ['active','pending','paused'], true)): ?>
                                    <form method="post" style="margin:0;" onsubmit="return confirm('<?= e(__('membership_cancel_confirm_admin', 'Cancel this subscription?')) ?>')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="cancel_sub">
                                        <input type="hidden" name="sub_id" value="<?= (int)$s['id'] ?>">
                                        <button class="btn btn-sm btn-danger"><?= __('membership_cancel', 'Cancel') ?></button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($canManage): ?>
        <div class="card">
            <div class="card-header"><h2><?= __('membership_manual_activate', 'Manual / offline activation') ?></h2></div>
            <p class="text-sm text-muted"><?= __('membership_manual_hint', 'Activate a membership for a cash or in-person payment. No card is charged.') ?></p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="manual_activate">
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="plan_id"><?= __('membership_plan', 'Plan') ?></label>
                        <select id="plan_id" name="plan_id" required>
                            <option value="">— <?= __('membership_choose_plan', 'Choose a plan') ?> —</option>
                            <?php foreach ($plans as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= e(MembershipAPI::planName($p)) ?> — <?= e(MembershipAPI::money((int)$p['price_cents'], $p['currency'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="note"><?= __('membership_note', 'Note (optional)') ?></label>
                        <input type="text" id="note" name="note" maxlength="255" placeholder="<?= e(__('membership_note_ph', 'Cash, paid at front desk')) ?>">
                    </div>
                </div>
                <?php $insFeeM = MembershipAPI::insuranceFeeCents(); if ($insFeeM > 0): ?>
                <div class="field">
                    <label class="switch-label" style="gap:8px;">
                        <input type="checkbox" name="add_insurance" value="1">
                        <span><?= __('membership_add_insurance', 'Add insurance') ?> (+<?= e(MembershipAPI::money($insFeeM)) ?>) — <?= __('membership_ins_opt_note', 'for optional-insurance plans; required plans always include it') ?></span>
                    </label>
                </div>
                <?php endif; ?>
                <button class="btn btn-primary"><?= __('membership_activate', 'Activate') ?></button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2><?= __('membership_wallet', 'Wallet') ?></h2></div>
            <p style="font-size:24px;font-weight:700;margin:0 0 var(--space-2);">
                <?= e(MembershipAPI::money((int)($wallet['balance_cents'] ?? 0), $wallet['currency'] ?? null)) ?>
            </p>
            <?php if ($canManage): ?>
            <form method="post" class="flex gap-2" style="align-items:flex-end;flex-wrap:wrap;margin-bottom:var(--space-3);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="wallet_adjust">
                <div class="field" style="margin:0;">
                    <label class="field-label" for="amount"><?= __('membership_amount', 'Amount (±)') ?></label>
                    <input type="number" id="amount" name="amount" step="0.01" placeholder="-10.00" style="width:120px;">
                </div>
                <div class="field" style="margin:0;flex:1;min-width:160px;">
                    <label class="field-label" for="description"><?= __('membership_description', 'Description') ?></label>
                    <input type="text" id="description" name="description" maxlength="255">
                </div>
                <button class="btn"><?= __('membership_apply', 'Apply') ?></button>
            </form>
            <?php endif; ?>
            <?php if ($txns): ?>
                <ul class="kv-list">
                    <?php foreach ($txns as $t): $d=(int)$t['delta_cents']; $sign=$d>0?'+':($d<0?'−':''); ?>
                        <li class="kv-row">
                            <span class="kv-label"><strong style="color:var(--text);"><?= e(ucfirst((string)$t['type'])) ?></strong><br>
                                <span class="text-xs"><?= e((string)($t['description'] ?? '')) ?> · <?= e(slate_format_date($t['created_at'])) ?></span></span>
                            <span class="kv-value"><?= $d!==0 ? e($sign . MembershipAPI::money(abs($d), $wallet['currency'] ?? null)) : '—' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title"><?= __('membership_profile', 'Profile') ?></div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label"><?= __('membership_email', 'Email') ?></span><span class="kv-value"><?= e($member['email']) ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= __('membership_phone', 'Phone') ?></span><span class="kv-value"><?= e($member['phone'] ?? '—') ?></span></li>
                <?php if ($profile): ?>
                    <li class="kv-row"><span class="kv-label"><?= __('membership_gender', 'Gender') ?></span><span class="kv-value"><?= e(MembershipAPI::genders()[$profile['gender']] ?? $profile['gender']) ?></span></li>
                    <li class="kv-row"><span class="kv-label"><?= __('membership_skill', 'Skill') ?></span><span class="kv-value"><?= e(MembershipAPI::skillLevels()[$profile['skill_level']] ?? $profile['skill_level']) ?></span></li>
                    <li class="kv-row"><span class="kv-label"><?= __('membership_dob', 'DOB') ?></span><span class="kv-value"><?= e($profile['dob'] ?: '—') ?></span></li>
                    <li class="kv-row"><span class="kv-label"><?= __('membership_emergency', 'Emergency') ?></span><span class="kv-value"><?= e($profile['emergency_name'] ?: '—') ?><?php if (!empty($profile['emergency_phone'])): ?><br><span class="text-xs"><?= e($profile['emergency_phone']) ?></span><?php endif; ?></span></li>
                    <li class="kv-row"><span class="kv-label"><?= __('membership_profile', 'Profile') ?></span><span class="kv-value"><?= !empty($profile['onboarding_complete']) ? '<span class="badge badge-active">'.__('membership_complete','Complete').'</span>' : '<span class="badge badge-warning">'.__('membership_incomplete','Incomplete').'</span>' ?></span></li>
                <?php else: ?>
                    <li class="kv-row"><span class="kv-value text-muted"><?= __('membership_no_profile', 'No profile yet') ?></span></li>
                <?php endif; ?>
            </ul>
        </div>
    </aside>

<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
