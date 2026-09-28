<?php
/**
 * Membership — add member (manual plan assignment).
 *
 * Lets staff put an existing customer on an existing plan without the
 * customer buying it online first — a search step to pick the customer,
 * then the same manual-activation fields member.php's own "Manual / offline
 * activation" card uses, backed by the same MembershipAPI::manualActivate().
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/MembershipAPI.php';

Auth::require();
if (!Auth::can('membership.manage_members') && !Auth::isSuperAdmin()) {
    Auth::requirePerm('membership.manage_members');
}
ModuleGuard::require('membership');
MembershipAPI::ensureSchema();

$pageTitle  = 'Add member';
$currentNav = 'membership-members';
$tid = current_tenant_id();
$flash = null;

$q  = trim((string)($_GET['q'] ?? ''));
$customerId = (int)($_GET['customer'] ?? 0);
$customer = null;
if ($customerId > 0) {
    $customer = Database::row("SELECT * FROM customers WHERE id = ? AND tenant_id = ?", [$customerId, $tid]);
    if (!$customer) { $customerId = 0; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'assign') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $postCustomerId = (int)($_POST['customer_id'] ?? 0);
        $postCustomer = Database::row("SELECT id FROM customers WHERE id = ? AND tenant_id = ?", [$postCustomerId, $tid]);
        if (!$postCustomer) {
            $flash = ['type' => 'error', 'msg' => 'Customer not found.'];
        } else {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $note   = trim((string)($_POST['note'] ?? ''));
            $subId  = MembershipAPI::manualActivate($postCustomerId, $planId, $note, !empty($_POST['add_insurance']));
            if ($subId) {
                header('Location: ' . plugin_url('membership', 'admin/member.php?id=' . $postCustomerId . '&assigned=1'));
                exit;
            }
            $flash = ['type' => 'error', 'msg' => 'Could not activate — check the plan.'];
        }
    }
}

$plans = MembershipAPI::plans(true);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('membership', 'Membership'), 'href' => plugin_url('membership', 'admin/index.php')],
    ['label' => __('membership_members', 'Members'), 'href' => plugin_url('membership', 'admin/members.php')],
    ['label' => __('membership_add_member', 'Add member')],
]); ?>

<div class="page-header">
    <div><h1><?= __('membership_add_member', 'Add member') ?></h1></div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$customer): ?>

    <div class="card">
        <div class="card-header"><h2><?= __('membership_pick_customer', 'Pick a customer') ?></h2></div>
        <p class="text-sm text-muted"><?= __('membership_pick_customer_hint', 'Search for the existing customer to put on a plan.') ?></p>
        <form method="get" class="toolbar" style="margin-bottom:var(--space-3);">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('membership_search', 'Search name or email')) ?>">
            <button class="btn"><?= __('membership_search_btn', 'Search') ?></button>
        </form>

        <?php if ($q !== ''):
            $results = Database::rows(
                "SELECT id, name, email, phone FROM customers
                  WHERE tenant_id = ? AND (name LIKE ? OR email LIKE ?)
               ORDER BY name LIMIT 50",
                [$tid, '%' . $q . '%', '%' . $q . '%']
            );
        ?>
            <?php if (!$results): ?>
                <div class="empty"><div class="empty-title"><?= __('membership_no_members', 'No members found') ?></div></div>
            <?php else: ?>
                <ul class="kv-list">
                    <?php foreach ($results as $r): ?>
                        <li class="kv-row">
                            <span class="kv-label">
                                <strong style="color:var(--text);"><?= e($r['name'] ?: $r['email']) ?></strong><br>
                                <span class="text-xs"><?= e((string)$r['email']) ?></span>
                            </span>
                            <span class="kv-value">
                                <a href="?q=<?= e(rawurlencode($q)) ?>&customer=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary"><?= __('membership_select', 'Select') ?></a>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="card">
        <div class="card-header"><h2><?= __('membership_customer', 'Customer') ?></h2></div>
        <p style="margin:0 0 var(--space-2);">
            <strong style="color:var(--text);"><?= e($customer['name'] ?: $customer['email']) ?></strong><br>
            <span class="text-sm text-muted"><?= e((string)$customer['email']) ?></span>
        </p>
        <a href="?q=<?= e(rawurlencode($q)) ?>" class="btn btn-sm"><?= __('membership_change_customer', 'Change customer') ?></a>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('membership_manual_activate', 'Manual / offline activation') ?></h2></div>
        <p class="text-sm text-muted"><?= __('membership_manual_hint', 'Activate a membership for a cash or in-person payment. No card is charged.') ?></p>
        <?php if (!$plans): ?>
            <div class="empty"><div class="empty-title"><?= __('membership_no_plans', 'No plans yet') ?></div></div>
        <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="assign">
            <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
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
            <?php $insFeeAdd = MembershipAPI::insuranceFeeCents(); if ($insFeeAdd > 0): ?>
            <div class="field">
                <label class="switch-label" style="gap:8px;">
                    <input type="checkbox" name="add_insurance" value="1">
                    <span><?= __('membership_add_insurance', 'Add insurance') ?> (+<?= e(MembershipAPI::money($insFeeAdd)) ?>) — <?= __('membership_ins_opt_note', 'for optional-insurance plans; required plans always include it') ?></span>
                </label>
            </div>
            <?php endif; ?>
            <button class="btn btn-primary"><?= __('membership_activate', 'Activate') ?></button>
        </form>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
