<?php
/**
 * Licensing — installs/licenses: list all, issue a new one.
 *
 * The raw license key exists only in memory for the one request that
 * creates it (LicensingAPI::issueInstall()) — only its hash is ever
 * persisted (install.sql). So, same pattern as MCP Gateway's token
 * creation, this does NOT redirect after a successful "issue": it renders
 * the key inline, once, on this same response.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

$pageTitle  = __('licensing_nav_installs', 'Licenses');
$currentNav = 'licensing-installs';

$flash      = null;
$newLicense = null; // ['license_key' => ..., 'install_id' => ..., 'label' => ...]

$clients  = Database::rows("SELECT id, name FROM licensing_clients ORDER BY name");
$products = Database::rows("SELECT id, slug, name FROM licensing_products ORDER BY name");
$plans    = Database::rows("SELECT id, product_id, name FROM licensing_plans ORDER BY name");
$statuses = ['trial', 'active', 'expired', 'suspended', 'revoked', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('licensing_csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'issue') {
            $clientId  = (int) ($_POST['client_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            $planId    = (int) ($_POST['plan_id'] ?? 0);
            $label     = trim((string) ($_POST['label'] ?? ''));
            $domain    = trim((string) ($_POST['domain'] ?? ''));
            $status    = (string) ($_POST['status'] ?? 'trial');
            $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
            $actLimit  = (int) ($_POST['activation_limit'] ?? 1);

            $product = $productId > 0 ? Database::row("SELECT slug FROM licensing_products WHERE id = ?", [$productId]) : null;

            if ($clientId <= 0 || !$product || $domain === '' || !in_array($status, $statuses, true)) {
                $flash = ['type' => 'error', 'msg' => __('licensing_issue_required', 'Client, product, domain, and status are required.')];
            } else {
                try {
                    $result = LicensingAPI::issueInstall([
                        'client_id'        => $clientId,
                        'product_id'       => $productId,
                        'plan_id'          => $planId > 0 ? $planId : null,
                        'label'            => $label !== '' ? $label : $domain,
                        'domain'           => $domain,
                        'status'           => $status,
                        'expires_at'       => $expiresAt !== '' ? $expiresAt . ' 00:00:00' : '',
                        'activation_limit' => $actLimit,
                    ], (string) $product['slug']);
                    AuditLog::record('licensing.install_issued', (string) $result['id']);
                    $newLicense = ['license_key' => $result['license_key'], 'install_id' => $result['id'], 'label' => $label !== '' ? $label : $domain];
                    $flash = ['type' => 'success', 'msg' => __('licensing_issue_success', 'License issued. Copy the key now — it cannot be shown again.')];
                } catch (\Throwable $e) {
                    // Most likely the unique (product_id, domain) constraint.
                    $flash = ['type' => 'error', 'msg' => __('licensing_issue_failed', 'Could not issue license — that domain may already have a license for this product.')];
                }
            }
        } elseif ($action === 'set_status') {
            $id     = (int) ($_POST['id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');
            if ($id > 0 && in_array($status, $statuses, true)) {
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

<?php if ($newLicense): ?>
<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_copy_key_now', 'Copy this license key now')) ?></h2></div>
    <p class="text-muted"><?= e(__('licensing_copy_key_desc', 'Send it to the client for their install\'s .env. It cannot be shown again — only its hash is stored.')) ?></p>
    <textarea class="mcp-token" readonly onclick="this.select()"><?= e($newLicense['license_key']) ?></textarea>
    <p class="field-help"><a href="<?= e(plugin_url('licensing', 'admin/install.php?id=' . (int) $newLicense['install_id'])) ?>"><?= e(sprintf(__('licensing_view_install', 'View “%s”'), $newLicense['label'])) ?></a></p>
</section>
<?php endif; ?>

<?php if (!$clients || !$products): ?>
    <div class="card"><div class="empty">
        <div class="empty-title"><?= __('licensing_setup_needed', 'Set up first') ?></div>
        <p class="text-sm">
            <?= __('licensing_setup_needed_sub', 'You need at least one product and one client before you can issue a license.') ?>
            <?php if (!$products): ?><a href="<?= e(plugin_url('licensing', 'admin/products.php')) ?>"><?= __('licensing_add_product', 'Add a product') ?></a><?php endif; ?>
            <?php if (!$clients): ?><a href="<?= e(plugin_url('licensing', 'admin/clients.php')) ?>"><?= __('licensing_add_client', 'Add a client') ?></a><?php endif; ?>
        </p>
    </div></div>
<?php else: ?>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_issue_new', 'Issue a new license')) ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="issue">
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="client_id"><?= __('licensing_client', 'Client') ?> <span class="field-required">*</span></label>
                <select id="client_id" name="client_id" required>
                    <option value="">— <?= __('licensing_select', 'Select') ?> —</option>
                    <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="product_id"><?= __('licensing_product', 'Product') ?> <span class="field-required">*</span></label>
                <select id="product_id" name="product_id" required data-licensing-product>
                    <option value="">— <?= __('licensing_select', 'Select') ?> —</option>
                    <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="plan_id"><?= __('licensing_plan', 'Plan') ?> <span class="text-muted"><?= __('auth_optional', 'optional') ?></span></label>
                <select id="plan_id" name="plan_id">
                    <option value="">— <?= __('licensing_none', 'None') ?> —</option>
                    <?php foreach ($plans as $pl): ?>
                        <option value="<?= (int) $pl['id'] ?>" data-product="<?= (int) $pl['product_id'] ?>"><?= e($pl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="status"><?= __('licensing_status', 'Status') ?></label>
                <select id="status" name="status">
                    <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $s === 'trial' ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="domain"><?= __('licensing_domain', 'Domain') ?> <span class="field-required">*</span></label>
                <input type="text" id="domain" name="domain" required maxlength="190" placeholder="client-site.com">
            </div>
            <div class="field">
                <label class="field-label" for="label"><?= __('licensing_label', 'Label') ?> <span class="text-muted"><?= __('auth_optional', 'optional') ?></span></label>
                <input type="text" id="label" name="label" maxlength="190" placeholder="Acme production">
            </div>
        </div>
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="expires_at"><?= __('licensing_expires', 'Expires') ?> <span class="text-muted"><?= __('auth_optional', 'optional') ?></span></label>
                <input type="date" id="expires_at" name="expires_at">
            </div>
            <div class="field">
                <label class="field-label" for="activation_limit"><?= __('licensing_activation_limit', 'Activation limit') ?></label>
                <input type="number" id="activation_limit" name="activation_limit" min="1" step="1" value="1">
            </div>
        </div>
        <button class="btn btn-primary" type="submit"><?= e(__('licensing_issue_button', 'Issue license')) ?></button>
    </form>
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

<script>
(function () {
    var productSel = document.querySelector('[data-licensing-product]');
    var planSel = document.getElementById('plan_id');
    if (!productSel || !planSel) return;
    var options = Array.prototype.slice.call(planSel.querySelectorAll('option[data-product]'));
    function sync() {
        var pid = productSel.value;
        options.forEach(function (o) { o.hidden = pid !== '' && o.getAttribute('data-product') !== pid; });
    }
    productSel.addEventListener('change', sync);
    sync();
})();
</script>

<?php endif; ?>

<style>
.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}
.mcp-token{display:block;width:100%;min-height:70px;resize:vertical;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}
.field-help{margin:7px 0 0;font-size:.82rem;color:var(--muted,#6b7280)}
@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}}
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
