<?php
/**
 * Licensing — products CRUD (list + inline create/edit).
 *
 * A product is what a license is issued for (e.g. "kohevo"). Deliberately
 * minimal, matching install.sql: just a slug and a name.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

$pageTitle  = __('licensing_products_nav', 'Products');
$currentNav = 'licensing-products';

$flash  = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM licensing_products WHERE id = ?", [$editId]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('licensing_csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id   = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
            $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '';
            $slug = trim($slug, '-');

            if ($name === '' || $slug === '') {
                $flash = ['type' => 'error', 'msg' => __('licensing_product_required', 'Name and slug are required.')];
            } else {
                $existing = Database::row("SELECT id FROM licensing_products WHERE slug = ? AND id != ?", [$slug, $id]);
                if ($existing) {
                    $flash = ['type' => 'error', 'msg' => __('licensing_product_slug_taken', 'That slug is already in use by another product.')];
                } else {
                    if ($id > 0) {
                        Database::update('licensing_products', ['name' => mb_substr($name, 0, 160), 'slug' => mb_substr($slug, 0, 64)], 'id = ?', [$id]);
                        AuditLog::record('licensing.product_updated', (string) $id);
                    } else {
                        $id = Database::insert('licensing_products', ['name' => mb_substr($name, 0, 160), 'slug' => mb_substr($slug, 0, 64)]);
                        AuditLog::record('licensing.product_created', (string) $id);
                    }
                    header('Location: ' . plugin_url('licensing', 'admin/products.php'));
                    exit;
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                // licensing_licenses.product_id carries no FK back to
                // licensing_products (docs/02-architecture/13-MIGRATION-
                // STRATEGY.md §1/§4 — purely additive, no schema change to
                // the pre-existing product/client tables), so a Phase-3
                // license referencing this product would otherwise be
                // silently orphaned. Counted here the same way plan
                // deletion already counts both tables.
                $inUse = (int) Database::value("SELECT COUNT(*) FROM licensing_installs WHERE product_id = ?", [$id])
                       + (int) Database::value("SELECT COUNT(*) FROM licensing_licenses WHERE product_id = ?", [$id]);
                if ($inUse > 0) {
                    $flash = ['type' => 'error', 'msg' => sprintf(
                        __('licensing_product_in_use', 'Cannot delete — %d license(s) still reference this product.'), $inUse)];
                } else {
                    $pdo = Database::get();
                    $pdo->beginTransaction();
                    try {
                        // Clean up each plan's template modules before the
                        // plan itself goes — same orphan this product's
                        // cascading plan delete would otherwise leave behind
                        // (see admin/plans.php's own delete action).
                        $planIds = array_map('intval', array_column(
                            Database::rows("SELECT id FROM licensing_plans WHERE product_id = ?", [$id]), 'id'
                        ));
                        if ($planIds) {
                            Database::delete('licensing_plan_modules', 'plan_id IN (' . implode(',', $planIds) . ')');
                        }
                        Database::delete('licensing_plans', 'product_id = ?', [$id]);
                        Database::delete('licensing_products', 'id = ?', [$id]);
                        $pdo->commit();
                        AuditLog::record('licensing.product_deleted', (string) $id);
                        $flash = ['type' => 'success', 'msg' => __('licensing_product_deleted', 'Product deleted.')];
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        slate_log('Licensing product delete failed: ' . $e->getMessage(), 'error');
                        $flash = ['type' => 'error', 'msg' => __('licensing_action_failed', 'That action could not be completed.')];
                    }
                }
                // Deliberately no redirect here (unlike 'save' above) — a
                // redirect discarded $flash before it could ever render,
                // silently swallowing both the success and the "still in
                // use" error message (same fix already applied to
                // admin/plans.php's own delete action).
            }
        }
    }
}

// install_count is shown to the admin as "licenses" and must reflect both
// the legacy licensing_installs table AND the Phase-3 licensing_licenses
// table — a product with only Phase-3 licenses previously showed 0.
$products = Database::rows(
    "SELECT p.*,
            (SELECT COUNT(*) FROM licensing_installs i WHERE i.product_id = p.id)
          + (SELECT COUNT(*) FROM licensing_licenses l WHERE l.product_id = p.id) AS install_count,
            (SELECT COUNT(*) FROM licensing_plans pl WHERE pl.product_id = p.id) AS plan_count
       FROM licensing_products p ORDER BY p.name"
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('licensing_nav_dashboard', 'Licensing'), 'href' => plugin_url('licensing', 'admin/index.php')],
    ['label' => __('licensing_products_nav', 'Products')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing): $p = $editing ?: ['id' => 0, 'name' => '', 'slug' => '']; ?>
<section class="card mb-3">
    <div class="card-header"><h2><?= $editing ? e(__('licensing_edit_product', 'Edit product')) : e(__('licensing_new_product', 'New product')) ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="name"><?= __('licensing_name', 'Name') ?> <span class="field-required">*</span></label>
                <input type="text" id="name" name="name" required maxlength="160" value="<?= e($p['name']) ?>" placeholder="Kohevo">
            </div>
            <div class="field">
                <label class="field-label" for="slug"><?= __('licensing_slug', 'Slug') ?> <span class="field-required">*</span></label>
                <input type="text" id="slug" name="slug" required maxlength="64" value="<?= e($p['slug']) ?>" placeholder="kohevo">
                <p class="field-help"><?= __('licensing_product_slug_hint', 'Lowercase, used by client installs when checking in. Changing it after installs are issued will break their next check-in.') ?></p>
            </div>
        </div>
        <button class="btn btn-primary" type="submit"><?= $editing ? e(__('save', 'Save')) : e(__('licensing_create_product', 'Create product')) ?></button>
        <a href="<?= e(plugin_url('licensing', 'admin/products.php')) ?>" class="btn btn-ghost"><?= __('cancel', 'Cancel') ?></a>
    </form>
</section>
<?php else: ?>
    <div class="page-header">
        <div><h1><?= __('licensing_products_nav', 'Products') ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= __('licensing_new_product', 'New product') ?></a>
    </div>

    <?php if (!$products): ?>
    <div class="card"><div class="empty">
        <div class="empty-title"><?= __('licensing_no_products', 'No products yet') ?></div>
        <p class="text-sm"><?= __('licensing_no_products_sub', 'Create a product before you can issue plans or licenses for it.') ?></p>
    </div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($products as $p):
            $actions = '<a href="?edit=' . (int) $p['id'] . '" class="btn btn-sm">' . __('edit', 'Edit') . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(' . e(json_encode(__('licensing_delete_product_confirm', 'Delete this product? Its plans will be deleted too.'))) . ');">'
                     . csrf_field() . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int) $p['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . __('delete', 'Delete') . '</button></form>';
            slate_data_row([
                'avatar' => mb_substr($p['name'], 0, 1),
                'avatar_color' => 'info',
                'title'  => $p['name'],
                'meta'   => $p['slug'],
                'badge'  => [$p['install_count'] . ' ' . __('licensing_licenses', 'license(s)'), 'active'],
                'detail' => [
                    __('licensing_slug', 'Slug') => $p['slug'],
                    __('licensing_plans_nav', 'Plans') => (int) $p['plan_count'],
                    __('licensing_licenses', 'Licenses') => (int) $p['install_count'],
                    __('licensing_created', 'Created') => $p['created_at'],
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<style>.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}}</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
