<?php
/**
 * Licensing — single legacy license (licensing_installs): details and
 * check-in history.
 *
 * Phase 13 (docs/03-implementation/PHASE-13-LEGACY-HANDLING.md): restrict
 * only. Key regeneration and binding reset are refused (each would grant a
 * new credential or a new activation); an update keeps the label and may
 * only shorten expiry or lower the activation limit (LegacyLicensePolicy).
 * Delete is unchanged.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';
require_once dirname(__DIR__) . '/LegacyLicensePolicy.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

$id = (int) ($_GET['id'] ?? 0);
$install = $id > 0 ? Database::row(
    "SELECT i.*, c.name AS client_name, p.name AS product_name, p.slug AS product_slug, pl.name AS plan_name
       FROM licensing_installs i
       JOIN licensing_clients c ON c.id = i.client_id
       JOIN licensing_products p ON p.id = i.product_id
       LEFT JOIN licensing_plans pl ON pl.id = i.plan_id
      WHERE i.id = ?", [$id]
) : null;

if (!$install) { http_response_code(404); echo 'License not found.'; exit; }

$pageTitle  = $install['label'] ?: $install['domain'];
$currentNav = 'licensing-installs';
$flash      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('licensing_csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'update') {
            $requestedExpiry = trim((string) ($_POST['expires_at'] ?? ''));
            [$expiresAt, $expiryRefused] = LegacyLicensePolicy::restrictedExpiry(
                $install['expires_at'], $requestedExpiry !== '' ? $requestedExpiry . ' 00:00:00' : null
            );
            [$limit, $limitRefused] = LegacyLicensePolicy::restrictedActivationLimit(
                (int) $install['activation_limit'], (int) ($_POST['activation_limit'] ?? 1)
            );
            Database::update('licensing_installs', [
                'label'            => trim((string) ($_POST['label'] ?? '')) ?: $install['domain'],
                'expires_at'       => $expiresAt,
                'activation_limit' => $limit,
            ], 'id = ?', [$id]);
            AuditLog::record('licensing.install_updated', (string) $id,
                ($expiryRefused || $limitRefused) ? ['refused' => array_keys(array_filter(['expires_at' => $expiryRefused, 'activation_limit' => $limitRefused]))] : []);
            header('Location: ' . plugin_url('licensing', 'admin/install.php?id=' . $id));
            exit;
        } elseif ($action === 'regenerate' || $action === 'reset_bindings') {
            // Phase 13: a new key or a released binding would grant a new
            // activation on a legacy license. Nothing is written.
            AuditLog::record('licensing.install_' . $action . '_refused', (string) $id);
            $flash = ['type' => 'error', 'msg' => __('licensing_legacy_action_closed', 'Legacy licenses can no longer be re-keyed or have their bindings reset.')];
        } elseif ($action === 'delete') {
            Database::delete('licensing_checkins', 'install_id = ?', [$id]);
            Database::delete('licensing_installs', 'id = ?', [$id]);
            AuditLog::record('licensing.install_deleted', (string) $id);
            header('Location: ' . plugin_url('licensing', 'admin/installs.php'));
            exit;
        }
    }
}

$checkins = Database::rows(
    "SELECT * FROM licensing_checkins WHERE install_id = ? ORDER BY checked_at DESC LIMIT 25", [$id]
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('licensing_nav_dashboard', 'Licensing'), 'href' => plugin_url('licensing', 'admin/index.php')],
    ['label' => __('licensing_nav_installs', 'Licenses'), 'href' => plugin_url('licensing', 'admin/installs.php')],
    ['label' => $install['label'] ?: $install['domain']],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e($install['label'] ?: $install['domain']) ?></h1>
        <p class="page-header-sub"><?= e($install['client_name']) ?> · <?= e($install['product_name']) ?><?= $install['plan_name'] ? ' · ' . e($install['plan_name']) : '' ?></p>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<p class="text-muted"><?= e(__('licensing_legacy_detail_desc', 'Legacy license: it keeps checking in as it is. Expiry can only be brought forward and the activation limit only lowered; it cannot be re-keyed, reset, reactivated or extended.')) ?></p>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_details', 'Details')) ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="label"><?= __('licensing_label', 'Label') ?></label>
                <input type="text" id="label" name="label" maxlength="190" value="<?= e($install['label']) ?>">
            </div>
            <div class="field">
                <label class="field-label"><?= __('licensing_domain', 'Domain') ?></label>
                <input type="text" value="<?= e($install['domain']) ?>" disabled>
                <p class="field-help"><?= __('licensing_domain_fixed', 'Domain is fixed to this license — issue a new license to move to a different domain.') ?></p>
            </div>
        </div>
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="expires_at"><?= __('licensing_expires', 'Expires') ?></label>
                <input type="date" id="expires_at" name="expires_at" value="<?= $install['expires_at'] ? e(substr($install['expires_at'], 0, 10)) : '' ?>">
            </div>
            <div class="field">
                <label class="field-label" for="activation_limit"><?= __('licensing_activation_limit', 'Activation limit') ?></label>
                <input type="number" id="activation_limit" name="activation_limit" min="1" step="1" value="<?= (int) $install['activation_limit'] ?>">
            </div>
        </div>
        <button class="btn btn-primary" type="submit"><?= e(__('save', 'Save')) ?></button>
    </form>
</section>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_status_activity', 'Status & activity')) ?></h2></div>
    <div class="mcp-grid">
        <div>
            <strong><?= __('licensing_status', 'Status') ?></strong>
            <p><?= e(ucfirst($install['status'])) ?><?= $install['revoke_reason'] ? ' — ' . e($install['revoke_reason']) : '' ?></p>
        </div>
        <div>
            <strong><?= __('licensing_last_checkin', 'Last check-in') ?></strong>
            <p><?= $install['last_checkin_at'] ? e($install['last_checkin_at']) . ' (' . e($install['last_checkin_ip'] ?: '—') . ')' : e(__('licensing_never', 'Never')) ?></p>
        </div>
        <div>
            <strong><?= __('licensing_installed_version', 'Installed version') ?></strong>
            <p><?= e($install['installed_version'] ?: '—') ?></p>
        </div>
        <div>
            <strong><?= __('licensing_issued', 'Issued') ?></strong>
            <p><?= e($install['issued_at']) ?></p>
        </div>
    </div>
    <p class="field-help"><?= __('licensing_status_change_hint', 'Change status from the') ?> <a href="<?= e(plugin_url('licensing', 'admin/installs.php')) ?>"><?= __('licensing_nav_installs', 'Licenses') ?></a> <?= __('licensing_status_change_hint2', 'list.') ?></p>
</section>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_recent_checkins', 'Recent check-ins')) ?></h2></div>
    <?php if (!$checkins): ?>
        <p class="text-muted"><?= e(__('licensing_no_checkins', 'No check-ins recorded yet.')) ?></p>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($checkins as $ck):
                $ckStatus = (string) ($ck['response_status'] ?? '');
                $ckTone   = in_array($ckStatus, ['active', 'trial', 'ok'], true) ? 'active' : 'muted';
                $ckMeta   = ($ck['ip'] ?: '—') . (!empty($ck['reported_version']) ? ' · v' . $ck['reported_version'] : '');
                slate_data_row([
                    'avatar'       => mb_strtoupper(mb_substr($ckStatus ?: 'C', 0, 1)),
                    'avatar_color' => $ckTone === 'active' ? 'accent' : 'muted',
                    'title'        => (string) ($ck['checked_at'] ?? '—'),
                    'meta'         => $ckMeta,
                    'badge'        => [ucfirst($ckStatus), $ckTone],
                    'detail'       => [
                        __('licensing_timestamp', 'Timestamp')                => (string) ($ck['checked_at'] ?? '—'),
                        __('licensing_status', 'Response status')             => ucfirst($ckStatus),
                        __('licensing_ip', 'IP Address')                      => (string) ($ck['ip'] ?: '—'),
                        __('licensing_installed_version', 'Reported version') => $ck['reported_version'] ? 'v' . $ck['reported_version'] : '—',
                    ],
                ]);
            endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-header"><h2><?= e(__('licensing_danger_zone', 'Danger zone')) ?></h2></div>
    <div class="mcp-grid">
        <form method="post" onsubmit="return confirm(<?= e(json_encode(__('licensing_delete_install_confirm', 'Permanently delete this license and its check-in history? This cannot be undone.'))) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="delete">
            <button class="btn btn-danger" type="submit"><?= e(__('delete', 'Delete')) ?></button>
        </form>
    </div>
</section>

<style>
.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}
.mcp-token{display:block;width:100%;min-height:70px;resize:vertical;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}
.field-help{margin:7px 0 0;font-size:.82rem;color:var(--muted,#6b7280)}
@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}}
</style>

<?php slate_data_list_script(); ?>
<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
