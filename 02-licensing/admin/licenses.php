<?php
/**
 * Slate — Licenses (Phase 1E C2).
 *
 * Platform-only license lifecycle management. Backed by LicenseService,
 * which owns the `licenses` table and never persists a raw key (only its
 * SHA-256 hash) — the raw key is shown here exactly once, immediately
 * after issuance, and never again.
 *
 * Routes:
 *   GET  /admin/licenses.php               → list, with ?status= / ?tenant_id= filters
 *   GET  /admin/licenses.php?view=7        → details + activity for one license
 *   GET  /admin/licenses.php?new           → issue a new license
 *   POST (_action=issue|activate|suspend|revoke|cancel|extend) → mutation
 */
require_once dirname(__DIR__) . '/config.php';

use Slate\Services\Licensing\LicenseService;
use Slate\Services\Licensing\PlanService;
use Slate\Services\Tenancy\TenantService;

Auth::require();
Auth::requirePlatformAdmin();

$pageTitle  = __('licenses', 'Licenses');
$currentNav = 'licenses';
$flash      = null;
$mode       = 'list';
$issuedKey  = null; // shown exactly once, right after a successful "issue"

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'issue') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $planId   = !empty($_POST['plan_id']) ? (int)$_POST['plan_id'] : null;
            if (TenantService::find($tenantId) === null) {
                $flash = ['type' => 'error', 'msg' => __('tenant_not_found', 'Tenant not found.')];
                $mode = 'new';
            } else {
                $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] . ' 23:59:59' : null;
                $result = LicenseService::issue($tenantId, $planId, [
                    'status'           => $_POST['status'] ?? 'trial',
                    'expires_at'       => $expiresAt,
                    'activation_limit' => (int)($_POST['activation_limit'] ?? 1),
                ]);
                $issuedKey = $result['key'];
                $mode = 'issued';
            }
        } elseif (in_array($action, ['activate', 'suspend', 'revoke', 'cancel'], true)) {
            $licenseId = (int)($_POST['license_id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            try {
                match ($action) {
                    'activate' => LicenseService::activate($licenseId),
                    'suspend'  => LicenseService::suspend($licenseId, $reason),
                    'revoke'   => LicenseService::revoke($licenseId, $reason),
                    'cancel'   => LicenseService::cancel($licenseId),
                };
                $flash = ['type' => 'success', 'msg' => __('license_updated', 'License updated.')];
            } catch (\InvalidArgumentException $e) {
                $flash = ['type' => 'error', 'msg' => $e->getMessage()];
            }
        } elseif ($action === 'extend') {
            $licenseId = (int)($_POST['license_id'] ?? 0);
            $days = (int)($_POST['days'] ?? 0);
            try {
                LicenseService::extend($licenseId, $days);
                $flash = ['type' => 'success', 'msg' => __('license_extended', 'License extended.')];
            } catch (\InvalidArgumentException $e) {
                $flash = ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
    }
}

$viewing = null;
$activity = [];
if ($mode === 'list' && isset($_GET['view'])) {
    $viewing = LicenseService::find((int)$_GET['view']);
    if ($viewing === null) {
        $flash = ['type' => 'error', 'msg' => __('license_not_found', 'License not found.')];
    } else {
        $mode = 'view';
        $tenant = TenantService::find((int)$viewing['tenant_id']);
        $plan   = !empty($viewing['plan_id']) ? PlanService::find((int)$viewing['plan_id']) : null;
        // anti-drift-ignore: TENANT — a platform admin viewing one license's own history, by its own id; not scoped to the caller's current tenant
        $activity = Database::rows(
            "SELECT al.*, u.name AS user_name, u.email AS user_email
               FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
              WHERE al.target = ? ORDER BY al.created_at DESC LIMIT 100",
            ['license#' . (int)$viewing['id']]
        );
    }
} elseif ($mode === 'list' && isset($_GET['new'])) {
    $mode = 'new';
}

$allTenants = TenantService::list();
$allPlans   = PlanService::list();

require __DIR__ . '/partials/header.php';

/** Mask a key's stored hash for display — never the raw key, which isn't stored. */
$maskHash = static fn(string $hash): string => 'SLT-••••-••••-••••-' . strtoupper(substr($hash, -4));
?>

<?php slate_breadcrumbs(
    $mode === 'list'
        ? [['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'], ['label' => __('licenses', 'Licenses')]]
        : [
            ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
            ['label' => __('licenses', 'Licenses'), 'href' => SLATE_URL . '/admin/licenses.php'],
            ['label' => $mode === 'new' ? __('issue_license', 'Issue license') : __('license_details', 'License details')],
          ]
); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($mode === 'issued'): ?>
    <div class="card">
        <div class="card-header"><h2><?= __('license_issued', 'License issued') ?></h2></div>
        <div class="card-body">
            <div class="alert alert-warning"><?= __('license_shown_once', 'This is the only time the raw key is shown. Copy it now — only its hash is stored.') ?></div>
            <p style="font-family:monospace; font-size:18px; font-weight:600;"><?= e($issuedKey) ?></p>
            <a href="<?= e(SLATE_URL) ?>/admin/licenses.php" class="btn btn-primary"><?= __('back_to_list', 'Back to list') ?></a>
        </div>
    </div>

<?php elseif ($mode === 'new'): ?>
    <div class="page-header">
        <div><h1><?= __('issue_license', 'Issue a license') ?></h1></div>
        <a href="<?= e(SLATE_URL) ?>/admin/licenses.php" class="btn"><?= __('back_to_list', 'Back to list') ?></a>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="issue">

                <div class="field">
                    <label class="field-label" for="l_tenant"><?= __('tenant', 'Tenant') ?></label>
                    <select id="l_tenant" name="tenant_id" required>
                        <option value=""></option>
                        <?php foreach ($allTenants as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?> (<?= e($t['slug']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="l_plan"><?= __('plan', 'Plan') ?></label>
                    <select id="l_plan" name="plan_id">
                        <option value=""><?= __('no_plan', 'No plan') ?></option>
                        <?php foreach ($allPlans as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="l_status"><?= __('starting_status', 'Starting status') ?></label>
                    <select id="l_status" name="status">
                        <?php foreach (LicenseService::STATUSES as $st): ?>
                            <option value="<?= e($st) ?>" <?= $st === 'trial' ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="l_expires"><?= __('expires', 'Expires') ?></label>
                    <input type="date" id="l_expires" name="expires_at">
                </div>
                <div class="field">
                    <label class="field-label" for="l_activation_limit"><?= __('activation_limit', 'Activation limit') ?></label>
                    <input type="number" id="l_activation_limit" name="activation_limit" min="1" value="1">
                </div>

                <button type="submit" class="btn btn-primary"><?= __('issue_license', 'Issue license') ?></button>
            </form>
        </div>
    </div>

<?php elseif ($mode === 'view' && $viewing): ?>
    <div class="page-header">
        <div><h1><?= e($tenant['name'] ?? ('#' . $viewing['tenant_id'])) ?></h1></div>
        <a href="<?= e(SLATE_URL) ?>/admin/licenses.php" class="btn"><?= __('back_to_list', 'Back to list') ?></a>
    </div>

    <div class="card">
        <div class="card-body">
            <dl class="dlist">
                <div class="dlist-row"><dt><?= __('license_key', 'License Key') ?></dt><dd><?= e($maskHash($viewing['license_key_hash'])) ?></dd></div>
                <div class="dlist-row"><dt><?= __('tenant', 'Tenant') ?></dt><dd><?= e($tenant['name'] ?? '—') ?></dd></div>
                <div class="dlist-row"><dt><?= __('plan', 'Plan') ?></dt><dd><?= e($plan['name'] ?? __('no_plan', 'No plan')) ?></dd></div>
                <div class="dlist-row"><dt><?= __('status', 'Status') ?></dt><dd><span class="badge"><?= e(ucfirst($viewing['status'])) ?></span></dd></div>
                <div class="dlist-row"><dt><?= __('issued', 'Issued') ?></dt><dd><?= e($viewing['issued_at']) ?></dd></div>
                <div class="dlist-row"><dt><?= __('starts', 'Starts') ?></dt><dd><?= e($viewing['starts_at']) ?></dd></div>
                <div class="dlist-row"><dt><?= __('expires', 'Expires') ?></dt><dd><?= e($viewing['expires_at'] ?: __('never', 'Never')) ?></dd></div>
                <div class="dlist-row"><dt><?= __('trial', 'Trial') ?></dt><dd><?= $viewing['status'] === 'trial' ? __('yes', 'Yes') : __('no', 'No') ?></dd></div>
                <div class="dlist-row"><dt><?= __('last_validation', 'Last validation') ?></dt><dd><?= e($viewing['last_validated_at'] ?: __('never', 'Never')) ?></dd></div>
                <div class="dlist-row"><dt><?= __('features', 'Features') ?></dt><dd><?= e($plan ? (implode(', ', PlanService::entitlementsFor((int)$plan['id'])) ?: '—') : '—') ?></dd></div>
                <?php if (!empty($viewing['revoke_reason'])): ?>
                    <div class="dlist-row"><dt><?= __('reason', 'Reason') ?></dt><dd><?= e($viewing['revoke_reason']) ?></dd></div>
                <?php endif; ?>
            </dl>

            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:16px;">
                <?php foreach (['activate', 'suspend', 'cancel'] as $act): if ($act === $viewing['status']) continue; ?>
                    <form method="post" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="<?= e($act) ?>">
                        <input type="hidden" name="license_id" value="<?= (int)$viewing['id'] ?>">
                        <button type="submit" class="btn btn-sm"><?= e(ucfirst($act)) ?></button>
                    </form>
                <?php endforeach; ?>

                <form method="post" style="display:inline;" onsubmit="var r = prompt(<?= e(json_encode(__('revoke_reason_prompt', 'Reason for revoking (required):'))) ?>); if (!r) return false; this.reason.value = r; return confirm(<?= e(json_encode(__('confirm_revoke_license', 'Revoke this license? This cannot be undone.'))) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="revoke">
                    <input type="hidden" name="license_id" value="<?= (int)$viewing['id'] ?>">
                    <input type="hidden" name="reason" value="">
                    <button type="submit" class="btn btn-sm btn-danger"><?= __('revoke', 'Revoke') ?></button>
                </form>

                <form method="post" style="display:inline; display:flex; gap:4px; align-items:center;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="extend">
                    <input type="hidden" name="license_id" value="<?= (int)$viewing['id'] ?>">
                    <input type="number" name="days" value="30" min="1" style="width:70px;">
                    <button type="submit" class="btn btn-sm"><?= __('extend_days', 'Extend (days)') ?></button>
                </form>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <div class="card-header"><h2><?= __('activity', 'Activity') ?></h2></div>
        <div class="card-body">
            <?php if (empty($activity)): ?>
                <p><?= __('no_activity', 'No activity recorded yet.') ?></p>
            <?php else: foreach ($activity as $a): ?>
                <div class="dlist-row">
                    <dt><?= e($a['created_at']) ?></dt>
                    <dd><?= e($a['action']) ?> — <?= e($a['user_name'] ?: $a['user_email'] ?: __('system', 'System')) ?></dd>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

<?php else: ?>

    <div class="page-header">
        <div>
            <h1><?= __('licenses', 'Licenses') ?></h1>
            <p class="page-header-sub"><?= __('licenses_intro', 'Every license issued on this platform.') ?></p>
        </div>
        <a href="<?= e(SLATE_URL) ?>/admin/licenses.php?new" class="btn btn-primary"><?= __('issue_license', 'Issue license') ?></a>
    </div>

    <form method="get" style="margin-bottom:16px;">
        <select name="status" onchange="this.form.submit()">
            <option value=""><?= __('all_statuses', 'All statuses') ?></option>
            <?php foreach (LicenseService::STATUSES as $st): ?>
                <option value="<?= e($st) ?>" <?= (($_GET['status'] ?? '') === $st) ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php $licenses = LicenseService::list(['status' => (string)($_GET['status'] ?? '')]); ?>
    <?php if (empty($licenses)): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-title"><?= __('no_licenses', 'No licenses found') ?></div>
                <p><?= __('no_licenses_intro', 'Create a license or assign a plan to a tenant.') ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($licenses as $l):
                $statusColor = match ($l['status']) {
                    'active'  => 'success', 'trial' => 'accent',
                    'suspended', 'expired' => 'warning',
                    'revoked', 'cancelled' => 'muted',
                    default => 'muted',
                };
                ob_start(); ?>
                <a href="<?= e(SLATE_URL) ?>/admin/licenses.php?view=<?= (int)$l['id'] ?>" class="btn btn-sm btn-primary"><?= __('view', 'View') ?></a>
                <?php $actions = ob_get_clean();

                slate_data_row([
                    'avatar_html'  => slate_avatar_overlay_html(mb_strtoupper(mb_substr($l['tenant_name'] ?? '?', 0, 2))),
                    'avatar_color' => $statusColor,
                    'title'        => $l['tenant_name'] ?: ('#' . $l['tenant_id']),
                    'meta'         => $maskHash($l['license_key_hash']),
                    'badge'        => [ucfirst($l['status']), $statusColor],
                    'detail'       => [
                        'Plan'    => $l['plan_name'] ?: __('no_plan', 'No plan'),
                        'Expires' => $l['expires_at'] ?: __('never', 'Never'),
                        'Issued'  => $l['issued_at'],
                    ],
                    'actions'      => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
