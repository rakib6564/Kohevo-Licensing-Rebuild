<?php
/**
 * Licensing — plans CRUD (list + inline create/edit), scoped to a product.
 *
 * `entitlements_json` is a freeform list of feature-key strings — edited
 * here as one key per line and joined/split as JSON on save/load, same
 * convention as the local per-tenant white_label entitlement elsewhere.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';
require_once dirname(__DIR__) . '/LicenseService.php';
require_once dirname(__DIR__) . '/PlanService.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

$pageTitle  = __('licensing_plans_nav', 'Plans');
$currentNav = 'licensing-plans';

$flash  = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$products = Database::rows("SELECT id, slug, name FROM licensing_products ORDER BY name");

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM licensing_plans WHERE id = ?", [$editId]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('licensing_csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id          = (int) ($_POST['id'] ?? 0);
            $productId   = (int) ($_POST['product_id'] ?? 0);
            $name        = trim((string) ($_POST['name'] ?? ''));
            $slug        = strtolower(trim((string) ($_POST['slug'] ?? '')));
            $slug        = trim(preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '', '-');
            $description = trim((string) ($_POST['description'] ?? ''));
            $isActive    = !empty($_POST['is_active']);
            $entLines    = preg_split('/\r\n|\r|\n/', (string) ($_POST['entitlements'] ?? '')) ?: [];
            $entitlements = array_values(array_filter(array_map('trim', $entLines), fn($v) => $v !== ''));
            // Module template (licensing_plan_modules) — a pre-fill convenience for
            // the license-creation screen only, independent of the legacy
            // entitlements_json textarea above (docs/02-architecture/
            // 04-ENTITLEMENT-ARCHITECTURE.md §4). Never write a Core key here.
            $moduleKeys = array_values(array_intersect(
                (array) ($_POST['modules'] ?? []),
                LicenseService::V1_OPTIONAL_MODULE_KEYS
            ));

            $productExists = $productId > 0 && Database::value("SELECT id FROM licensing_products WHERE id = ?", [$productId]) !== null;
            // A tampered POST naming an $id that doesn't (or no longer)
            // exist must be rejected here, before Database::update() silently
            // no-ops against zero rows and PlanService::setModules() is
            // reached for a plan_id that was never actually created/updated.
            // PlanService::setModules() also enforces this itself now — this
            // is the admin-level half of that defense-in-depth.
            $planExists = $id <= 0 || Database::value("SELECT id FROM licensing_plans WHERE id = ?", [$id]) !== null;

            if ($productId <= 0 || $name === '' || $slug === '') {
                $flash = ['type' => 'error', 'msg' => __('licensing_plan_required', 'Product, name, and slug are required.')];
            } elseif (!$productExists) {
                $flash = ['type' => 'error', 'msg' => __('licensing_plan_invalid_product', 'Unknown product.')];
            } elseif (!$planExists) {
                $flash = ['type' => 'error', 'msg' => __('licensing_plan_not_found', 'That plan no longer exists.')];
            } else {
                $existing = Database::row(
                    "SELECT id FROM licensing_plans WHERE product_id = ? AND slug = ? AND id != ?",
                    [$productId, $slug, $id]
                );
                if ($existing) {
                    $flash = ['type' => 'error', 'msg' => __('licensing_plan_slug_taken', 'That slug is already used by another plan on this product.')];
                } else {
                    $row = [
                        'product_id'        => $productId,
                        'name'              => mb_substr($name, 0, 160),
                        'slug'              => mb_substr($slug, 0, 64),
                        'description'       => $description !== '' ? mb_substr($description, 0, 2000) : null,
                        'is_active'         => $isActive ? 1 : 0,
                        'entitlements_json' => (string) json_encode($entitlements),
                    ];
                    try {
                        if ($id > 0) {
                            Database::update('licensing_plans', $row, 'id = ?', [$id]);
                            AuditLog::record('licensing.plan_updated', (string) $id);
                        } else {
                            $id = Database::insert('licensing_plans', $row);
                            AuditLog::record('licensing.plan_created', (string) $id);
                        }
                        PlanService::setModules($id, $moduleKeys);
                        AuditLog::record('licensing.plan_modules_updated', (string) $id, ['modules' => $moduleKeys]);
                        header('Location: ' . plugin_url('licensing', 'admin/plans.php'));
                        exit;
                    } catch (\InvalidArgumentException $e) {
                        // e.g. PlanService::setModules()'s own "Unknown plan."
                        // guard, reached if the plan vanished between the
                        // $planExists check above and here.
                        $flash = ['type' => 'error', 'msg' => $e->getMessage() ?: __('licensing_action_failed', 'That action could not be completed.')];
                    } catch (\Throwable $e) {
                        // Never surface a raw exception message to the admin
                        // — same rule admin/license.php follows.
                        slate_log('Licensing plan save failed: ' . $e->getMessage(), 'error');
                        $flash = ['type' => 'error', 'msg' => __('licensing_action_failed', 'That action could not be completed.')];
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                // A Plan is only ever a creation-time template (docs/02-architecture/
                // 04-ENTITLEMENT-ARCHITECTURE.md §4) — deleting it has no runtime
                // effect on already-issued licenses (plan_id carries no FK), but we
                // still block deletion while anything references it for admin
                // clarity, across both the legacy and Phase-2 license tables.
                $inUse = (int) Database::value("SELECT COUNT(*) FROM licensing_installs WHERE plan_id = ?", [$id])
                       + (int) Database::value("SELECT COUNT(*) FROM licensing_licenses WHERE plan_id = ?", [$id]);
                if ($inUse > 0) {
                    $flash = ['type' => 'error', 'msg' => sprintf(
                        __('licensing_plan_in_use', 'Cannot delete — %d license(s) still reference this plan.'), $inUse)];
                } else {
                    // licensing_plan_modules carries no FK back to
                    // licensing_plans (it's a template pre-fill table, not a
                    // licensing_plans-owned schema relationship) — deleted
                    // explicitly here, in the same transaction, so it never
                    // outlives the plan it templated.
                    $pdo = Database::get();
                    $pdo->beginTransaction();
                    try {
                        Database::delete('licensing_plan_modules', 'plan_id = ?', [$id]);
                        Database::delete('licensing_plans', 'id = ?', [$id]);
                        $pdo->commit();
                        AuditLog::record('licensing.plan_deleted', (string) $id);
                        $flash = ['type' => 'success', 'msg' => __('licensing_plan_deleted', 'Plan deleted.')];
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        slate_log('Licensing plan delete failed: ' . $e->getMessage(), 'error');
                        $flash = ['type' => 'error', 'msg' => __('licensing_action_failed', 'That action could not be completed.')];
                    }
                }
                // Deliberately no redirect here (unlike 'save' above) — a
                // redirect discarded $flash before it could ever render,
                // silently swallowing both the success and the "still in
                // use" error message. Matches the no-redirect pattern
                // admin/license.php and 01-client/admin/plans.php already
                // use for exactly this reason.
            }
        }
    }
}

$plans = Database::rows(
    "SELECT pl.*, p.name AS product_name,
            (SELECT COUNT(*) FROM licensing_installs i WHERE i.plan_id = pl.id) AS install_count,
            (SELECT COUNT(*) FROM licensing_licenses l WHERE l.plan_id = pl.id) AS license_count
       FROM licensing_plans pl JOIN licensing_products p ON p.id = pl.product_id
      ORDER BY p.name, pl.name"
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('licensing_nav_dashboard', 'Licensing'), 'href' => plugin_url('licensing', 'admin/index.php')],
    ['label' => __('licensing_plans_nav', 'Plans')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$products): ?>
    <div class="card"><div class="empty">
        <div class="empty-title"><?= __('licensing_no_products_yet', 'No products yet') ?></div>
        <p class="text-sm"><?= __('licensing_create_product_first', 'Create a product first.') ?> <a href="<?= e(plugin_url('licensing', 'admin/products.php')) ?>"><?= __('licensing_go_to_products', 'Go to Products') ?></a></p>
    </div></div>
<?php elseif ($isNew || $editing):
    $p = $editing ?: ['id' => 0, 'product_id' => (int) $products[0]['id'], 'name' => '', 'slug' => '', 'description' => '', 'is_active' => 1, 'entitlements_json' => '[]'];
    $entText = implode("\n", (array) (json_decode((string) $p['entitlements_json'], true) ?: []));
    $planModules = $editing ? PlanService::modules((int) $p['id']) : [];
    $moduleLabels = [
        'forms' => __('licensing_module_forms', 'Form Builder'),
        'membership' => __('licensing_module_membership', 'Membership'),
        'booking' => __('licensing_module_booking', 'Booking'),
    ];
?>
<section class="card mb-3">
    <div class="card-header"><h2><?= $editing ? e(__('licensing_edit_plan', 'Edit plan')) : e(__('licensing_new_plan', 'New plan')) ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="product_id"><?= __('licensing_product', 'Product') ?> <span class="field-required">*</span></label>
                <select id="product_id" name="product_id" required>
                    <?php foreach ($products as $prod): ?>
                        <option value="<?= (int) $prod['id'] ?>" <?= ((int) $p['product_id'] === (int) $prod['id']) ? 'selected' : '' ?>><?= e($prod['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="name"><?= __('licensing_name', 'Name') ?> <span class="field-required">*</span></label>
                <input type="text" id="name" name="name" required maxlength="160" value="<?= e($p['name']) ?>" placeholder="Pro">
            </div>
        </div>
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="slug"><?= __('licensing_slug', 'Slug') ?> <span class="field-required">*</span></label>
                <input type="text" id="slug" name="slug" required maxlength="64" value="<?= e($p['slug']) ?>" placeholder="pro">
            </div>
            <div class="field">
                <label class="field-label"><?= __('licensing_status', 'Status') ?></label>
                <label style="display:flex;align-items:center;gap:8px;font-weight:400;">
                    <input type="checkbox" name="is_active" value="1" <?= !empty($p['is_active']) ? 'checked' : '' ?>>
                    <?= __('licensing_plan_is_active', 'Active — offered when issuing new licenses') ?>
                </label>
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="description"><?= __('licensing_description', 'Description') ?> <span class="text-muted"><?= __('auth_optional', 'optional') ?></span></label>
            <textarea id="description" name="description" rows="2" maxlength="2000" placeholder="<?= e(__('licensing_description_placeholder', 'Shown to admins only, presentational.')) ?>"><?= e((string) ($p['description'] ?? '')) ?></textarea>
        </div>
        <div class="field">
            <label class="field-label"><?= __('licensing_module_template', 'Module template') ?></label>
            <div class="mcp-checkbox-row">
                <?php foreach ($moduleLabels as $key => $label): ?>
                    <label>
                        <input type="checkbox" name="modules[]" value="<?= e($key) ?>" <?= in_array($key, $planModules, true) ? 'checked' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="field-help"><?= __('licensing_module_template_hint', 'Pre-fills the optional modules when an admin issues a new license from this plan. Core (Admin/User, Dashboard, Site Settings) is always included and cannot be disabled. Changing this has no effect on licenses already issued.') ?></p>
        </div>
        <div class="field">
            <label class="field-label" for="entitlements"><?= __('licensing_entitlements', 'Entitlements (legacy)') ?></label>
            <textarea id="entitlements" name="entitlements" rows="5" placeholder="white_label&#10;api_access"><?= e($entText) ?></textarea>
            <p class="field-help"><?= __('licensing_entitlements_hint', 'One feature key per line. Still read by the live check-in endpoint for licenses issued through the legacy Licenses (Legacy) screen — the Module template above is the current source for new-schema licenses.') ?></p>
        </div>
        <button class="btn btn-primary" type="submit"><?= $editing ? e(__('save', 'Save')) : e(__('licensing_create_plan', 'Create plan')) ?></button>
        <a href="<?= e(plugin_url('licensing', 'admin/plans.php')) ?>" class="btn btn-ghost"><?= __('cancel', 'Cancel') ?></a>
    </form>
</section>
<?php else: ?>
    <div class="page-header">
        <div><h1><?= __('licensing_plans_nav', 'Plans') ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= __('licensing_new_plan', 'New plan') ?></a>
    </div>

    <?php if (!$plans): ?>
    <div class="card"><div class="empty">
        <div class="empty-title"><?= __('licensing_no_plans', 'No plans yet') ?></div>
        <p class="text-sm"><?= __('licensing_no_plans_sub', 'Plans are optional — an install can be issued without one.') ?></p>
    </div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($plans as $p):
            $ents = (array) (json_decode((string) $p['entitlements_json'], true) ?: []);
            $mods = PlanService::modules((int) $p['id']);
            $totalLicenses = (int) $p['install_count'] + (int) $p['license_count'];
            $actions = '<a href="?edit=' . (int) $p['id'] . '" class="btn btn-sm">' . __('edit', 'Edit') . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(' . e(json_encode(__('licensing_delete_plan_confirm', 'Delete this plan?'))) . ');">'
                     . csrf_field() . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int) $p['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . __('delete', 'Delete') . '</button></form>';
            slate_data_row([
                'avatar' => mb_substr($p['name'], 0, 1),
                'avatar_color' => 'info',
                'title'  => $p['name'] . ' (' . $p['product_name'] . ')',
                'meta'   => $p['slug'] . ' · ' . ($mods ? implode(', ', $mods) : __('licensing_no_modules', 'no optional modules')),
                'badge'  => empty($p['is_active'])
                    ? [__('licensing_inactive', 'Inactive'), 'muted']
                    : [$totalLicenses . ' ' . __('licensing_licenses', 'license(s)'), $totalLicenses > 0 ? 'active' : 'muted'],
                'detail' => [
                    __('licensing_product', 'Product') => $p['product_name'],
                    __('licensing_slug', 'Slug') => $p['slug'],
                    __('licensing_status', 'Status') => empty($p['is_active']) ? __('licensing_inactive', 'Inactive') : __('licensing_active', 'Active'),
                    __('licensing_module_template', 'Module template') => $mods ? implode(', ', $mods) : '—',
                    __('licensing_entitlements', 'Entitlements (legacy)') => $ents ? implode(', ', $ents) : '—',
                    __('licensing_licenses', 'Licenses') => $totalLicenses,
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<style>
.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}
.mcp-checkbox-row{display:flex;flex-wrap:wrap;gap:16px}
.mcp-checkbox-row label{display:flex;align-items:center;gap:6px;font-weight:400}
@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}}
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
