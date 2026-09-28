<?php
/**
 * Licensing — Licenses (Phase 2/3 schema): list all, issue a new one.
 *
 * Operates on `licensing_licenses` / `licensing_license_modules` via
 * LicenseService — the actual commercial entitlement, split out of the
 * legacy conflated `licensing_installs` table (docs/02-architecture/
 * 02-CENTRAL-LICENSING-DOMAIN.md §1.4). This screen supersedes installs.php
 * for new licenses, per docs/02-architecture/13-MIGRATION-STRATEGY.md §2 —
 * installs.php is kept, unmodified, as "Licenses (Legacy)" since the live
 * public check-in endpoint still validates against the old schema
 * (docs/02-architecture/13-MIGRATION-STRATEGY.md §4; wiring the endpoint
 * onto this schema is a later phase).
 *
 * Same one-time-reveal convention as installs.php: the raw license key
 * exists only for the response that creates it (LicenseService::issue()) —
 * only its hash is persisted (INV-05) — so a successful "issue" renders the
 * key inline rather than redirecting.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';
require_once dirname(__DIR__) . '/ModuleCatalog.php';
require_once dirname(__DIR__) . '/LicenseService.php';
require_once dirname(__DIR__) . '/PlanService.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

$pageTitle  = __('licensing_nav_licenses', 'Licenses');
$currentNav = 'licensing-licenses';

$flash      = null;
$newLicense = null; // ['license_key' => ..., 'id' => ..., 'label' => ...]

$clients  = Database::rows("SELECT id, name FROM licensing_clients ORDER BY name");
$products = Database::rows("SELECT id, slug, name FROM licensing_products ORDER BY name");
$plans    = Database::rows("SELECT id, product_id, name FROM licensing_plans WHERE is_active = 1 ORDER BY name");
$selectionCatalog = ModuleCatalog::selectionCatalog();

// Plan -> template module map, embedded as a data attribute per <option> so
// the issue form can pre-fill checkboxes client-side (§ mirrors installs.php's
// existing product/plan JS filter pattern).
$planModuleMap = [];
foreach ($plans as $pl) {
    $planModuleMap[(int) $pl['id']] = PlanService::modules((int) $pl['id']);
}

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
            $startsAt  = trim((string) ($_POST['starts_at'] ?? ''));
            $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
            $warningDays = (int) ($_POST['warning_days'] ?? 7);
            $graceDays   = (int) ($_POST['grace_days'] ?? 7);
            $actLimit  = max(1, (int) ($_POST['activation_limit'] ?? 1));
            $rawModules = $_POST['modules'] ?? null;

            $product = $productId > 0 ? Database::row("SELECT slug FROM licensing_products WHERE id = ?", [$productId]) : null;
            $client  = $clientId > 0 ? Database::row("SELECT id FROM licensing_clients WHERE id = ?", [$clientId]) : null;
            $plan    = $planId > 0 ? Database::row("SELECT product_id, is_active FROM licensing_plans WHERE id = ?", [$planId]) : null;

            if (!$client || !$product) {
                $flash = ['type' => 'error', 'msg' => __('licensing_issue_required', 'Client and product are required.')];
            } elseif ($planId > 0 && (!$plan || (int) $plan['product_id'] !== $productId)) {
                $flash = ['type' => 'error', 'msg' => __('licensing_issue_invalid_plan', 'The selected plan does not exist or does not belong to the selected product.')];
            } elseif ($planId > 0 && empty($plan['is_active'])) {
                $flash = ['type' => 'error', 'msg' => __('licensing_issue_inactive_plan', 'The selected plan is not active and cannot be used to issue new licenses.')];
            } elseif ($rawModules !== null && !is_array($rawModules)) {
                $flash = ['type' => 'error', 'msg' => __('licensing_invalid_modules', 'Invalid module selection.')];
            } else {
                try {
                    if ($rawModules === null && $planId > 0 && empty($_POST['_modules_explicit'])) {
                        $modules = ModuleCatalog::validateCommercialSelection(PlanService::modules($planId));
                    } else {
                        $modules = ModuleCatalog::validateCommercialSelection(is_array($rawModules) ? $rawModules : []);
                    }

                    $result = LicenseService::issue([
                        'client_id'            => $clientId,
                        'product_id'           => $productId,
                        'plan_id'              => $planId > 0 ? $planId : null,
                        'label'                => $label,
                        'starts_at'            => $startsAt !== '' ? LicenseService::parseDate($startsAt) : null,
                        'expires_at'           => $expiresAt !== '' ? LicenseService::parseDate($expiresAt) : null,
                        'warning_days'         => max(0, $warningDays),
                        'grace_days'           => max(0, $graceDays),
                        'activation_limit'     => $actLimit,
                        'modules'              => $modules,
                        'enforce_plan_modules' => $planId > 0,
                    ], (string) $product['slug'], Auth::userId());
                    AuditLog::record('licensing.license_issued', (string) $result['id'], ['modules' => $modules]);
                    $newLicense = ['license_key' => $result['license_key'], 'id' => $result['id'], 'label' => $label !== '' ? $label : ('#' . $result['id'])];
                    $flash = ['type' => 'success', 'msg' => __('licensing_issue_success', 'License issued. Copy the key now — it cannot be shown again.')];
                } catch (\InvalidArgumentException $e) {
                    $flash = ['type' => 'error', 'msg' => $e->getMessage() ?: __('licensing_issue_failed', 'Could not issue license.')];
                } catch (\Throwable $e) {
                    slate_log('Licensing: license issue failed: ' . $e->getMessage(), 'error');
                    $flash = ['type' => 'error', 'msg' => __('licensing_issue_failed', 'Could not issue license.')];
                }
            }
        }
    }
}

$statuses = ['unactivated', 'trial', 'active', 'expired', 'suspended', 'revoked', 'cancelled'];
$statusFilter = (string) ($_GET['status'] ?? '');
$where = [];
$params = [];
if (in_array($statusFilter, $statuses, true)) {
    $where[] = 'l.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$licenses = Database::rows(
    "SELECT l.*, c.name AS client_name, p.name AS product_name, pl.name AS plan_name,
            inst.domain AS active_domain, inst.installation_id AS active_installation_id
       FROM licensing_licenses l
       JOIN licensing_clients c ON c.id = l.client_id
       JOIN licensing_products p ON p.id = l.product_id
       LEFT JOIN licensing_plans pl ON pl.id = l.plan_id
       LEFT JOIN licensing_installations inst ON inst.license_id = l.id AND inst.status = 'active'
      $whereSql
      ORDER BY l.created_at DESC",
    $params
);

$statusTone = [
    'unactivated' => 'muted', 'trial' => 'info', 'active' => 'active', 'expired' => 'warning',
    'suspended' => 'warning', 'revoked' => 'inactive', 'cancelled' => 'inactive',
];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('licensing_nav_dashboard', 'Licensing'), 'href' => plugin_url('licensing', 'admin/index.php')],
    ['label' => __('licensing_nav_licenses', 'Licenses')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('licensing_nav_licenses', 'Licenses') ?></h1>
        <p class="page-header-sub"><?= __('licensing_licenses_sub', 'The commercial entitlement for a specific installation — independent of the plan it was created from.') ?></p>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($newLicense): ?>
<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_copy_key_now', 'Copy this license key now')) ?></h2></div>
    <p class="text-muted"><?= e(__('licensing_copy_key_desc', 'Send it to the client for their install\'s .env. It cannot be shown again — only its hash is stored.')) ?></p>
    <textarea class="mcp-token" readonly onclick="this.select()"><?= e($newLicense['license_key']) ?></textarea>
    <p class="field-help"><a href="<?= e(plugin_url('licensing', 'admin/license.php?id=' . (int) $newLicense['id'])) ?>"><?= e(sprintf(__('licensing_view_license', 'View “%s”'), $newLicense['label'])) ?></a></p>
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
                <label class="field-label" for="plan_id"><?= __('licensing_plan', 'Plan') ?> <span class="text-muted"><?= __('auth_optional', 'optional — pre-fills & constrains modules below') ?></span></label>
                <select id="plan_id" name="plan_id" data-licensing-plan-select data-module-map="<?= e((string) json_encode($planModuleMap)) ?>">
                    <option value="">— <?= __('licensing_none', 'None') ?> —</option>
                    <?php foreach ($plans as $pl): ?>
                        <option value="<?= (int) $pl['id'] ?>" data-product="<?= (int) $pl['product_id'] ?>"><?= e($pl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="label"><?= __('licensing_label', 'Label') ?> <span class="text-muted"><?= __('auth_optional', 'optional') ?></span></label>
                <input type="text" id="label" name="label" maxlength="190" placeholder="Acme production">
            </div>
        </div>
        <div class="field">
            <label class="field-label"><?= __('licensing_optional_modules', 'Commercial modules (V1 catalog)') ?></label>
            <div class="mcp-checkbox-row" data-licensing-modules>
                <?php foreach ($selectionCatalog as $key => $catItem): ?>
                    <?php if ($catItem['selectable']): ?>
                        <label>
                            <input type="checkbox" name="modules[]" value="<?= e($key) ?>" data-v1-commercial="1">
                            <?= e($catItem['display_name']) ?>
                        </label>
                    <?php else: ?>
                        <label class="text-muted" style="opacity:0.65;cursor:not-allowed;" title="<?= e($catItem['description']) ?>">
                            <input type="checkbox" disabled aria-disabled="true" value="<?= e($key) ?>">
                            <?= e($catItem['display_name']) ?>
                            <span class="badge badge-inactive" style="font-size:10px;padding:1px 6px;"><?= e((string) $catItem['badge']) ?></span>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="field-help"><?= __('licensing_optional_modules_hint', 'Core (Admin/User, Dashboard, Site Settings) is always included with a valid license. Supporting infrastructure (Stripe Payment) is automatically enabled on client installs when Membership or Booking is selected. Editor and Content are future modules (Not V1 / Future) and cannot be granted.') ?></p>
        </div>
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="starts_at"><?= __('licensing_starts', 'Starts') ?> <span class="text-muted"><?= __('auth_optional', 'optional, defaults to now') ?></span></label>
                <input type="date" id="starts_at" name="starts_at">
            </div>
            <div class="field">
                <label class="field-label" for="expires_at"><?= __('licensing_expires', 'Expires') ?> <span class="text-muted"><?= __('auth_optional', 'optional') ?></span></label>
                <input type="date" id="expires_at" name="expires_at">
            </div>
        </div>
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="warning_days"><?= __('licensing_warning_days', 'Warning days before expiry') ?></label>
                <input type="number" id="warning_days" name="warning_days" min="0" step="1" value="7">
            </div>
            <div class="field">
                <label class="field-label" for="grace_days"><?= __('licensing_grace_days', 'Grace days after expiry') ?></label>
                <input type="number" id="grace_days" name="grace_days" min="0" step="1" value="7">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="activation_limit"><?= __('licensing_activation_limit', 'Activation limit') ?></label>
            <input type="number" id="activation_limit" name="activation_limit" min="1" step="1" value="1">
            <p class="field-help"><?= __('licensing_activation_limit_hint', '1 License → 1 Installation is enforced at the database and domain-service layer regardless of this value; raising it is a deliberate, audited exception.') ?></p>
        </div>
        <button class="btn btn-primary" type="submit"><?= e(__('licensing_issue_button', 'Issue license')) ?></button>
    </form>
</section>

<section class="card">
    <div class="card-header">
        <h2><?= e(__('licensing_issued_licenses', 'Issued licenses')) ?></h2>
        <form method="get" style="margin:0;">
            <select name="status" onchange="this.form.submit()" class="btn-sm">
                <option value=""><?= __('licensing_all_statuses', 'All statuses') ?></option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= e($s) ?>" <?= $s === $statusFilter ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if (!$licenses): ?>
        <p class="text-muted"><?= e(__('licensing_no_licenses', 'No licenses issued yet.')) ?></p>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($licenses as $l):
                $tone = $statusTone[$l['status']] ?? 'muted';
                $mods = LicenseService::modules((int) $l['id']);
                $detail = [
                    __('licensing_client', 'Client') => $l['client_name'],
                    __('licensing_product', 'Product') => $l['product_name'],
                    __('licensing_plan', 'Plan') => $l['plan_name'] ?: '—',
                    __('licensing_optional_modules', 'Optional modules') => $mods ? implode(', ', $mods) : '—',
                    __('licensing_installation', 'Installation') => $l['active_domain'] ? $l['active_domain'] : __('licensing_none_bound', 'none bound'),
                    __('licensing_expires', 'Expires') => $l['expires_at'] ?: '—',
                ];
                $actions = '<a href="' . e(plugin_url('licensing', 'admin/license.php?id=' . (int) $l['id'])) . '" class="btn btn-sm">' . __('licensing_view', 'View') . '</a>';
                slate_data_row([
                    'avatar' => mb_substr($l['client_name'], 0, 1),
                    'avatar_color' => $tone === 'active' ? 'info' : 'muted',
                    'title'  => ($l['label'] ?: ('#' . $l['id'])) . ' — ' . $l['client_name'],
                    'meta'   => $l['product_name'] . ' · ' . ($l['active_domain'] ?: __('licensing_none_bound', 'no installation bound')),
                    'badge'  => [ucfirst($l['status']), $tone],
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
    var planSel = document.querySelector('[data-licensing-plan-select]');
    if (!productSel || !planSel) return;
    var options = Array.prototype.slice.call(planSel.querySelectorAll('option[data-product]'));
    function syncProduct() {
        var pid = productSel.value;
        options.forEach(function (o) { o.hidden = pid !== '' && o.getAttribute('data-product') !== pid; });
    }
    productSel.addEventListener('change', syncProduct);
    syncProduct();

    var moduleMap = {};
    try { moduleMap = JSON.parse(planSel.getAttribute('data-module-map') || '{}'); } catch (e) {}
    var moduleBoxes = document.querySelectorAll('[data-licensing-modules] input[type=checkbox][data-v1-commercial="1"]');
    planSel.addEventListener('change', function () {
        var hasPlan = planSel.value !== '';
        var mods = moduleMap[planSel.value] || [];
        moduleBoxes.forEach(function (box) {
            var allowed = !hasPlan || mods.indexOf(box.value) !== -1;
            box.checked = hasPlan && allowed;
            box.disabled = !allowed;
        });
    });
})();
</script>

<?php endif; ?>

<style>
.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}
.mcp-token{display:block;width:100%;min-height:70px;resize:vertical;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}
.mcp-checkbox-row{display:flex;flex-wrap:wrap;gap:16px}
.mcp-checkbox-row label{display:flex;align-items:center;gap:6px;font-weight:400}
.field-help{margin:7px 0 0;font-size:.82rem;color:var(--muted,#6b7280)}
.card-header{display:flex;align-items:center;justify-content:space-between;gap:12px}
@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}}
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
