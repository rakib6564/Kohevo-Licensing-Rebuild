<?php
/**
 * Membership — plans CRUD (list + inline create/edit).
 *
 * Bilingual name/description (FR primary), pricing in cents, fixed-term
 * duration + grace period, and the plan type (base membership / insurance /
 * course-specific). Course plans may optionally link to a Booking service.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/MembershipAPI.php';
require_once SLATE_ROOT . '/includes/record_editor.php';   // slate_edit_* kit

Auth::require();
Auth::requirePerm('membership.manage_plans');
MembershipAPI::ensureSchema();

$pageTitle  = 'Membership plans';
$currentNav = 'membership-plans';
$tid = current_tenant_id();

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM membership_plans WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
}

// Optional: Booking services to link a course plan to (only if Booking is here).
$bookingServices = [];
try {
    $hasBooking = (int) Database::value(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_services'");
    if ($hasBooking > 0) {
        $bookingServices = Database::rows(
            "SELECT id, name FROM booking_services WHERE tenant_id = ? AND is_active = 1 ORDER BY name", [$tid]);
    }
} catch (\Throwable $e) { /* booking not installed — no linkage offered */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $type = (string)($_POST['plan_type'] ?? 'membership');
            if (!array_key_exists($type, MembershipAPI::planTypes())) $type = 'membership';

            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => 'Name is required.'];
            } else {
                $courseId = ($type === 'course' && !empty($_POST['course_id'])) ? (int)$_POST['course_id'] : null;
                $row = [
                    'tenant_id'          => $tid,
                    'name'               => mb_substr($name, 0, 160),
                    'name_fr'            => trim((string)($_POST['name_fr'] ?? '')) !== '' ? mb_substr(trim((string)$_POST['name_fr']), 0, 160) : null,
                    'description'        => trim((string)($_POST['description'] ?? '')) !== '' ? (string)$_POST['description'] : null,
                    'description_fr'     => trim((string)($_POST['description_fr'] ?? '')) !== '' ? (string)$_POST['description_fr'] : null,
                    'plan_type'          => $type,
                    'course_id'          => $courseId,
                    'price_cents'        => max(0, (int) round(((float)($_POST['price'] ?? 0)) * 100)),
                    'currency'           => MembershipAPI::currency(),
                    'duration_days'      => max(1, (int)($_POST['duration_days'] ?? 365)),
                    'session_quota'      => max(0, (int)($_POST['session_quota'] ?? 0)),
                    'grace_days'         => max(0, (int)($_POST['grace_days'] ?? 0)),
                    'insurance_mode'     => array_key_exists((string)($_POST['insurance_mode'] ?? 'none'), MembershipAPI::insuranceModes()) ? (string)$_POST['insurance_mode'] : 'none',
                    'requires_insurance' => (($_POST['insurance_mode'] ?? 'none') === 'required') ? 1 : 0,
                    'is_active'          => !empty($_POST['is_active']) ? 1 : 0,
                    'sort_order'         => (int)($_POST['sort_order'] ?? 0),
                ];
                if ($id > 0) {
                    Database::update('membership_plans', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('membership.plan_updated', (string)$id);
                } else {
                    $id = Database::insert('membership_plans', $row);
                    AuditLog::record('membership.plan_created', (string)$id);
                }
                header('Location: ' . plugin_url('membership', 'admin/plans.php'));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                // Guard: never delete a plan that has subscriptions — deactivate
                // instead so history (and the wallet ledger) stays intact.
                $inUse = (int) Database::value(
                    "SELECT COUNT(*) FROM membership_subscriptions WHERE plan_id = ? AND tenant_id = ?", [$id, $tid]);
                if ($inUse > 0) {
                    Database::update('membership_plans', ['is_active' => 0], 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('membership.plan_deactivated', (string)$id, ['reason' => 'in_use', 'subscriptions' => $inUse]);
                    $flash = ['type' => 'warning', 'msg' => sprintf(
                        __('membership_plan_deactivated_msg', 'This plan has %d subscription(s), so it was deactivated (hidden) instead of deleted — payment history is kept. Use “Delete permanently” to remove it and its subscriptions.'),
                        $inUse)];
                } else {
                    Database::delete('membership_plans', 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('membership.plan_deleted', (string)$id);
                    $flash = ['type' => 'success', 'msg' => __('membership_plan_deleted_msg', 'Plan deleted.')];
                }
                header('Location: ' . plugin_url('membership', 'admin/plans.php'));
                exit;
            }
        } elseif ($action === 'force_delete') {
            // Hard delete: removes the plan AND its subscriptions. Payment
            // records in the Stripe charges ledger are kept (financial record).
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $n = (int) Database::value(
                    "SELECT COUNT(*) FROM membership_subscriptions WHERE plan_id = ? AND tenant_id = ?", [$id, $tid]);
                Database::delete('membership_subscriptions', 'plan_id = ? AND tenant_id = ?', [$id, $tid]);
                Database::delete('membership_plans', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('membership.plan_force_deleted', (string)$id, ['subscriptions_removed' => $n]);
                $flash = ['type' => 'success', 'msg' => sprintf(
                    __('membership_plan_force_deleted_msg', 'Plan deleted, along with %d subscription(s).'), $n)];
                header('Location: ' . plugin_url('membership', 'admin/plans.php'));
                exit;
            }
        }
    }
}

$plans = Database::rows(
    "SELECT p.*, (SELECT COUNT(*) FROM membership_subscriptions s
                   WHERE s.plan_id = p.id AND s.status = 'active') AS active_count,
                 (SELECT COUNT(*) FROM membership_subscriptions s2
                   WHERE s2.plan_id = p.id) AS total_count
       FROM membership_plans p WHERE p.tenant_id = ? ORDER BY p.sort_order, p.name",
    [$tid]
);
$types = MembershipAPI::planTypes();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('membership', 'Membership'), 'href' => plugin_url('membership', 'admin/index.php')],
    ['label' => __('membership_plans', 'Plans')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $p = $editing ?: [
        'id'=>0,'name'=>'','name_fr'=>'','description'=>'','description_fr'=>'','plan_type'=>'membership',
        'course_id'=>null,'price_cents'=>0,'currency'=>MembershipAPI::currency(),'duration_days'=>365,
        'grace_days'=>0,'session_quota'=>0,'requires_insurance'=>0,'insurance_mode'=>'none','is_active'=>1,'sort_order'=>0,
    ];
    $pInitials = ($editing && trim((string)$editing['name']) !== '') ? mb_strtoupper(mb_substr($editing['name'], 0, 2)) : '–';
?>
<?php slate_editor_css(); ?>

<?php slate_edit_open(['title_fallback' => __('membership_new_plan', 'New plan')]); ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">

        <?php slate_edit_backlink([
            'back_href'  => plugin_url('membership', 'admin/plans.php'),
            'back_label' => __('membership_all_plans', 'All plans'),
        ]); ?>

        <?php slate_edit_hero([
            'icon'         => 'tag',
            'initials'     => $pInitials,
            'title'        => $editing ? MembershipAPI::planName($editing) : __('membership_new_plan', 'New plan'),
            'ref'          => $editing ? (int)$editing['id'] : null,
            'active'       => !empty($p['is_active']),
            'toggle_name'  => 'is_active',
            'toggle_id'    => 'is_active',
            'toggle_title' => __('membership_active_hint', 'Active (sellable to members)'),
            'status_on'    => __('membership_active', 'Active'),
            'status_off'   => __('membership_inactive', 'Inactive'),
            'stats'        => $editing ? [
                [__('membership_type', 'Type'), e($types[$p['plan_type']] ?? $p['plan_type'])],
                [__('membership_price', 'Price'), MembershipAPI::money((int)$p['price_cents'], $p['currency'])],
                [__('membership_duration', 'Duration'), (int)$p['duration_days'] . ' ' . __('membership_days', 'days')],
            ] : [],
        ]); ?>

        <?php slate_edit_card_open(['icon' => 'globe', 'eyebrow' => __('membership_general', 'General'), 'title' => __('membership_plan_details', 'Plan details')]); ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name"><?= __('membership_name_en', 'Name (English)') ?> <span class="field-required">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="160" value="<?= e($p['name']) ?>" placeholder="Annual membership">
                </div>
                <div class="field">
                    <label class="field-label" for="name_fr"><?= __('membership_name_fr', 'Name (French)') ?></label>
                    <input type="text" id="name_fr" name="name_fr" maxlength="160" value="<?= e($p['name_fr'] ?? '') ?>" placeholder="Adhésion annuelle">
                </div>
            </div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="description"><?= __('membership_desc_en', 'Description (English)') ?></label>
                    <textarea id="description" name="description" rows="3"><?= e($p['description'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label class="field-label" for="description_fr"><?= __('membership_desc_fr', 'Description (French)') ?></label>
                    <textarea id="description_fr" name="description_fr" rows="3"><?= e($p['description_fr'] ?? '') ?></textarea>
                </div>
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_card_open(['icon' => 'layers', 'eyebrow' => __('membership_type', 'Type'), 'title' => __('membership_type_pricing', 'Type & pricing')]); ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="plan_type"><?= __('membership_type', 'Type') ?></label>
                    <select id="plan_type" name="plan_type" data-membership-type>
                        <?php foreach ($types as $k => $label): ?>
                            <option value="<?= e($k) ?>" <?= ($p['plan_type'] === $k) ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" data-membership-course style="<?= $p['plan_type'] === 'course' ? '' : 'display:none;' ?>">
                    <label class="field-label" for="course_id"><?= __('membership_linked_course', 'Linked course (optional)') ?></label>
                    <select id="course_id" name="course_id">
                        <option value="">— <?= __('membership_none', 'None') ?> —</option>
                        <?php foreach ($bookingServices as $svc): ?>
                            <option value="<?= (int)$svc['id'] ?>" <?= ((int)($p['course_id'] ?? 0) === (int)$svc['id']) ? 'selected' : '' ?>><?= e($svc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$bookingServices): ?><div class="field-hint"><?= __('membership_no_courses', 'No active Booking courses found.') ?></div><?php endif; ?>
                </div>
            </div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="price"><?= __('membership_price', 'Price') ?> <span class="text-muted text-xs">(<?= e(MembershipAPI::currency()) ?>)</span></label>
                    <input type="number" id="price" name="price" min="0" step="0.01" value="<?= e(number_format(((int)$p['price_cents'])/100, 2, '.', '')) ?>">
                </div>
            </div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="duration_days"><?= __('membership_duration_days', 'Duration (days)') ?></label>
                    <input type="number" id="duration_days" name="duration_days" min="1" step="1" value="<?= (int)$p['duration_days'] ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="grace_days"><?= __('membership_grace_days', 'Grace period (days)') ?></label>
                    <input type="number" id="grace_days" name="grace_days" min="0" step="1" value="<?= (int)$p['grace_days'] ?>">
                    <div class="field-hint"><?= __('membership_grace_hint', 'Days after expiry before access is fully cut.') ?></div>
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="session_quota"><?= __('membership_session_quota', 'Session quota') ?></label>
                <input type="number" id="session_quota" name="session_quota" min="0" step="1" value="<?= (int)($p['session_quota'] ?? 0) ?>">
                <div class="field-hint"><?= __('membership_session_quota_hint', 'Number of sessions this plan includes. 0 = unlimited. Shown on the member dashboard as “used / quota”.') ?></div>
            </div>
            <?php $insFeeAdmin = MembershipAPI::insuranceFeeCents(); ?>
            <div class="field">
                <label class="field-label" for="insurance_mode"><?= __('membership_insurance_addon', 'Insurance add-on') ?></label>
                <select id="insurance_mode" name="insurance_mode">
                    <?php foreach (MembershipAPI::insuranceModes() as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= (($p['insurance_mode'] ?? 'none') === $k) ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint">
                    <?php if ($insFeeAdmin > 0): ?>
                        <?= __('membership_ins_fee_is', 'Global insurance fee:') ?> <strong><?= e(MembershipAPI::money($insFeeAdmin)) ?></strong> —
                    <?php else: ?>
                        <?= __('membership_ins_no_fee', 'No insurance fee set yet.') ?>
                    <?php endif; ?>
                    <a href="<?= e(plugin_url('membership', 'admin/settings.php')) ?>"><?= __('membership_ins_set_fee', 'set it in Settings') ?></a>.
                </div>
            </div>
            <div class="field" style="margin-bottom:0;">
                <label class="field-label" for="sort_order"><?= __('membership_sort_order', 'Sort order') ?></label>
                <input type="number" id="sort_order" name="sort_order" step="1" value="<?= (int)$p['sort_order'] ?>">
            </div>
        <?php slate_edit_card_close(); ?>

        <?php slate_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? __('membership_save', 'Save changes') : __('membership_create_plan', 'Create plan')) . '</button>'
              . '<a href="' . e(plugin_url('membership', 'admin/plans.php')) . '" class="btn btn-ghost">' . __('membership_cancel', 'Cancel') . '</a>',
        ]); ?>

    </form>
<?php slate_edit_close(); ?>

<?php slate_editor_js(); ?>
<script>
// Show the "linked course" picker only for course-type plans.
(function () {
    var sel = document.querySelector('[data-membership-type]');
    var box = document.querySelector('[data-membership-course]');
    if (!sel || !box) return;
    sel.addEventListener('change', function () {
        box.style.display = (sel.value === 'course') ? '' : 'none';
    });
})();
</script>

<?php else: ?>
    <div class="page-header">
        <div><h1><?= __('membership_plans', 'Plans') ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= __('membership_new_plan', 'New plan') ?></a>
    </div>

    <?php if (!$plans): ?>
    <div class="card"><div class="empty">
        <div class="empty-title"><?= __('membership_no_plans', 'No plans yet') ?></div>
        <p class="text-sm"><?= __('membership_no_plans_sub', 'Create your first membership, insurance or course plan to start selling.') ?></p>
    </div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($plans as $p):
            $typeLabel = $types[$p['plan_type']] ?? $p['plan_type'];
            $actions = '<a href="?edit=' . (int)$p['id'] . '" class="btn btn-sm">' . __('membership_edit', 'Edit') . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('membership_delete_confirm', 'Delete this plan?')) . '\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$p['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . __('membership_delete', 'Delete') . '</button></form>';
            if ((int)$p['total_count'] > 0) {
                $confirmF = sprintf(__('membership_force_confirm', 'Permanently delete this plan AND its %d subscription(s)? This cannot be undone.'), (int)$p['total_count']);
                $actions .= ' <form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e($confirmF) . '\')">'
                          . csrf_field()
                          . '<input type="hidden" name="_action" value="force_delete"><input type="hidden" name="id" value="' . (int)$p['id'] . '">'
                          . '<button class="btn btn-sm btn-danger">' . __('membership_delete_perm', 'Delete permanently') . '</button></form>';
            }
            slate_data_row([
                'avatar'       => mb_substr($p['name'], 0, 1),
                'avatar_color' => $p['is_active'] ? 'info' : 'muted',
                'title'        => MembershipAPI::planName($p),
                'meta'         => $typeLabel . ' · ' . MembershipAPI::money((int)$p['price_cents'], $p['currency']) . ' / ' . (int)$p['duration_days'] . ' ' . __('membership_days', 'days'),
                'badge'        => $p['is_active'] ? [__('membership_active', 'Active'), 'active'] : [__('membership_inactive', 'Inactive'), 'inactive'],
                'detail'       => [
                    __('membership_type', 'Type')      => $typeLabel,
                    __('membership_price', 'Price')     => MembershipAPI::money((int)$p['price_cents'], $p['currency']),
                    __('membership_duration', 'Duration') => (int)$p['duration_days'] . ' ' . __('membership_days', 'days'),
                    __('membership_active_subs', 'Active') => (int)$p['active_count'],
                ],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
