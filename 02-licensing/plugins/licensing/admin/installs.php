<?php
/**
 * Licensing — legacy licenses (licensing_installs): list, and wind down.
 *
 * Phase 13 (docs/03-implementation/PHASE-13-LEGACY-HANDLING.md): this screen
 * no longer issues licenses. New licenses are issued from admin/licenses.php
 * (Plan → License → Installation → Entitlements). Existing legacy licenses
 * keep checking in unchanged; here they can only be moved to a restrictive
 * status (LegacyLicensePolicy::RESTRICTIVE_STATUSES).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';
require_once dirname(__DIR__) . '/LegacyLicensePolicy.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

$pageTitle  = __('licensing_nav_installs', 'Licenses');
$currentNav = 'licensing-installs';

$flash      = null;

$statuses = ['trial', 'active', 'expired', 'suspended', 'revoked', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('licensing_csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'issue') {
            // Phase 13: legacy issuance is closed. Nothing is written.
            $flash = ['type' => 'error', 'msg' => __('licensing_legacy_issue_closed', 'Legacy licenses can no longer be issued. Issue a license from the Licenses screen instead.')];
        } elseif ($action === 'set_status') {
            $id     = (int) ($_POST['id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');
            if ($id > 0 && !LegacyLicensePolicy::allowsStatus($status)) {
                // Phase 13: never back to trial/active from here.
                AuditLog::record('licensing.install_status_refused', (string) $id, ['status' => $status]);
            } elseif ($id > 0 && in_array($status, $statuses, true)) {
                $update = ['status' => $status];
                if ($status === 'revoked' || $status === 'cancelled') {
                    $update['revoke_reason'] = trim((string) ($_POST['reason'] ?? '')) ?: null;
                }
                Database::update('licensing_installs', $update, 'id = ?', [$id]);
                AuditLog::record('licensing.install_status_changed', (string) $id, ['status' => $status]);
                $flash = ['type' => 'success', 'msg' => __('licensing_status_updated', 'Status updated.')];
            }
            header('Location: ' . plugin_url('licensing', 'admin/installs.php'));
            exit;
        }
    }
}

$installs = Database::rows(
    "SELECT i.*, c.name AS client_name, p.name AS product_name, pl.name AS plan_name
       FROM licensing_installs i
       JOIN licensing_clients c ON c.id = i.client_id
       JOIN licensing_products p ON p.id = i.product_id
       LEFT JOIN licensing_plans pl ON pl.id = i.plan_id
      ORDER BY i.created_at DESC"
);

$statusTone = [
    'trial' => 'info', 'active' => 'active', 'expired' => 'warning',
    'suspended' => 'warning', 'revoked' => 'inactive', 'cancelled' => 'inactive',
];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('licensing_nav_dashboard', 'Licensing'), 'href' => plugin_url('licensing', 'admin/index.php')],
    ['label' => __('licensing_nav_installs', 'Licenses')],
]); ?>

<div class="page-header">
    <div><h1><?= __('licensing_nav_installs', 'Licenses') ?></h1></div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_legacy_title', 'Legacy licenses')) ?></h2></div>
    <p class="text-muted"><?= e(__('licensing_legacy_desc', 'Licenses issued before the current licensing model. They keep checking in as they are, but can no longer be issued, re-keyed, reset, reactivated or extended — only suspended, revoked, expired, cancelled or deleted. Issue new licenses from the Licenses screen.')) ?></p>
    <p><a class="btn btn-sm" href="<?= e(plugin_url('licensing', 'admin/licenses.php')) ?>"><?= e(__('licensing_nav_licenses', 'Licenses')) ?></a></p>
</section>

<section class="card">
    <div class="card-header"><h2><?= e(__('licensing_issued_licenses', 'Issued licenses')) ?></h2></div>
    <?php if (!$installs): ?>
        <p class="text-muted"><?= e(__('licensing_no_installs', 'No licenses issued yet.')) ?></p>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($installs as $i):
                $tone = $statusTone[$i['status']] ?? 'muted';
                $detail = [
                    __('licensing_client', 'Client') => $i['client_name'],
                    __('licensing_product', 'Product') => $i['product_name'],
                    __('licensing_plan', 'Plan') => $i['plan_name'] ?: '—',
                    __('licensing_domain', 'Domain') => $i['domain'],
                    __('licensing_last_checkin', 'Last check-in') => $i['last_checkin_at'] ?: __('licensing_never', 'Never'),
                    __('licensing_expires', 'Expires') => $i['expires_at'] ?: '—',
                ];
                $statusForm = '<form method="post" style="display:inline-flex;gap:6px;align-items:center;margin:0;" onsubmit="return this.status.value===' . "'" . e($i['status']) . "'" . ' ? false : confirm(' . e(json_encode(__('licensing_status_change_confirm', 'Change this license\'s status?'))) . ');">'
                            . csrf_field()
                            . '<input type="hidden" name="_action" value="set_status">'
                            . '<input type="hidden" name="id" value="' . (int) $i['id'] . '">'
                            . '<select name="status" class="btn-sm">';
                foreach ($statuses as $s) {
                    if ($s !== $i['status'] && !LegacyLicensePolicy::allowsStatus($s)) continue;
                    $statusForm .= '<option value="' . e($s) . '"' . ($s === $i['status'] ? ' selected' : '') . '>' . e(ucfirst($s)) . '</option>';
                }
                $statusForm .= '</select><button class="btn btn-sm" type="submit">' . __('licensing_update', 'Update') . '</button></form>';
                $actions = '<a href="' . e(plugin_url('licensing', 'admin/install.php?id=' . (int) $i['id'])) . '" class="btn btn-sm">' . __('licensing_view', 'View') . '</a> ' . $statusForm;
                slate_data_row([
                    'avatar' => mb_substr($i['client_name'], 0, 1),
                    'avatar_color' => $tone === 'active' ? 'info' : 'muted',
                    'title'  => ($i['label'] ?: $i['domain']) . ' — ' . $i['client_name'],
                    'meta'   => $i['product_name'] . ' · ' . $i['domain'],
                    'badge'  => [ucfirst($i['status']), $tone],
                    'detail' => $detail,
                    'actions' => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>
</section>

<style>
.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}
.mcp-token{display:block;width:100%;min-height:70px;resize:vertical;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}
.field-help{margin:7px 0 0;font-size:.82rem;color:var(--muted,#6b7280)}
@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}}
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
