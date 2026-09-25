<?php
/**
 * Slate — Legacy Platform Plans (Phase 1E C1).
 *
 * Platform-level plan records retained for support/migration. They are not
 * the commercial authority in remote mode. Historically these were plans
 * sold BY the platform TO tenants (Free/Starter/
 * Professional/Enterprise, seeded by migration 0015 as editable starting
 * data — NOT the same concept as plugins/membership's own "plans", which a
 * tenant sells to its own end customers). Backed by PlanService.
 *
 * Routes:
 *   GET  /admin/plans.php          → list
 *   GET  /admin/plans.php?new      → new-plan form
 *   GET  /admin/plans.php?edit=3   → edit existing plan
 *   POST (_action=save|delete)     → mutation
 */
require_once dirname(__DIR__) . '/config.php';

use Slate\Services\Licensing\PlanService;

Auth::require();
Auth::requirePlatformAdmin();

$pageTitle  = __('plans', 'Plans');
$currentNav = 'plans';
$flash      = null;
$mode       = 'list';
$editing    = null;

// The full set of feature_keys a plan can grant: every installed plugin's
// slug — never an invented feature, per the approved design.
$availableFeatures = array_column(PluginLoader::listAll(), 'name', 'slug');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'save') {
            $planId = !empty($_POST['plan_id']) ? (int)$_POST['plan_id'] : null;
            $features = array_values(array_filter((array)($_POST['features'] ?? []), 'is_string'));
            try {
                PlanService::save($planId, [
                    'name'        => $_POST['name']        ?? '',
                    'slug'        => $_POST['slug']         ?? '',
                    'description' => $_POST['description']  ?? '',
                    'is_active'   => !empty($_POST['is_active']),
                    'sort_order'  => $_POST['sort_order']    ?? 0,
                    'limits'      => [
                        'max_users'     => $_POST['max_users']     ?? '',
                        'max_customers' => $_POST['max_customers'] ?? '',
                    ],
                ], $features);
                header('Location: ' . SLATE_URL . '/admin/plans.php?saved=1');
                exit;
            } catch (\InvalidArgumentException $e) {
                $flash = ['type' => 'error', 'msg' => $e->getMessage()];
                $mode = $planId ? 'edit' : 'new';
                $editing = $planId ? PlanService::find($planId) : null;
            }
        } elseif ($action === 'delete') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            try {
                PlanService::delete($planId);
                $flash = ['type' => 'success', 'msg' => __('plan_deleted', 'Plan deleted.')];
            } catch (\InvalidArgumentException $e) {
                $flash = ['type' => 'error', 'msg' => $e->getMessage()];
            }
        }
    }
}

if ($mode === 'list' && isset($_GET['edit'])) {
    $editing = PlanService::find((int)$_GET['edit']);
    $mode = $editing ? 'edit' : 'list';
    if (!$editing) {
        $flash = ['type' => 'error', 'msg' => __('plan_not_found', 'Plan not found.')];
    }
} elseif ($mode === 'list' && isset($_GET['new'])) {
    $mode = 'new';
}
if (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')];
}

$editingFeatures = [];
if ($editing) {
    $editingFeatures = PlanService::entitlementsFor((int)$editing['id']);
}
$editingLimits = $editing && !empty($editing['limits']) ? (json_decode((string)$editing['limits'], true) ?: []) : [];

require __DIR__ . '/partials/header.php';
?>

<?php slate_breadcrumbs($mode === 'list'
    ? [['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'], ['label' => __('plans', 'Plans')]]
    : [
        ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
        ['label' => __('plans', 'Plans'), 'href' => SLATE_URL . '/admin/plans.php'],
        ['label' => $mode === 'new' ? __('new_plan', 'New plan') : (($editing['name'] ?? '') ?: __('edit', 'Edit'))],
      ]
); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($mode === 'new' || $mode === 'edit'): ?>
    <div class="page-header">
        <div><h1><?= $mode === 'new' ? __('new_plan', 'New plan') : e($editing['name'] ?? '') ?></h1></div>
        <a href="<?= e(SLATE_URL) ?>/admin/plans.php" class="btn"><?= __('back_to_list', 'Back to list') ?></a>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save">
                <?php if ($editing): ?><input type="hidden" name="plan_id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

                <div class="field">
                    <label class="field-label" for="p_name"><?= __('plan_name', 'Plan name') ?></label>
                    <input type="text" id="p_name" name="name" required maxlength="120" value="<?= e($editing['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="p_slug"><?= __('plan_slug', 'Slug') ?></label>
                    <input type="text" id="p_slug" name="slug" required maxlength="64" pattern="[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?" value="<?= e($editing['slug'] ?? '') ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="p_description"><?= __('description', 'Description') ?></label>
                    <textarea id="p_description" name="description" maxlength="500"><?= e($editing['description'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label class="field-label" for="p_sort_order"><?= __('sort_order', 'Sort order') ?></label>
                    <input type="number" id="p_sort_order" name="sort_order" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
                </div>
                <div class="field">
                    <label style="font-weight:400;"><input type="checkbox" name="is_active" value="1" <?= (!$editing || !empty($editing['is_active'])) ? 'checked' : '' ?>> <?= __('active', 'Active') ?></label>
                </div>

                <h3><?= __('limits', 'Limits') ?></h3>
                <div class="field">
                    <label class="field-label" for="p_max_users"><?= __('max_users', 'Max users') ?></label>
                    <input type="number" id="p_max_users" name="max_users" min="0" value="<?= e((string)($editingLimits['max_users'] ?? '')) ?>" placeholder="<?= e(__('unlimited', 'Unlimited')) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="p_max_customers"><?= __('max_customers', 'Max customers') ?></label>
                    <input type="number" id="p_max_customers" name="max_customers" min="0" value="<?= e((string)($editingLimits['max_customers'] ?? '')) ?>" placeholder="<?= e(__('unlimited', 'Unlimited')) ?>">
                </div>

                <h3><?= __('feature_entitlements', 'Feature entitlements') ?></h3>
                <?php if (empty($availableFeatures)): ?>
                    <div class="field-hint"><?= __('no_plugins_installed', 'No plugins installed on this platform yet.') ?></div>
                <?php else: foreach ($availableFeatures as $slug => $name): ?>
                    <label style="display:block; font-weight:400; margin-bottom:4px;">
                        <input type="checkbox" name="features[]" value="<?= e($slug) ?>" <?= in_array($slug, $editingFeatures, true) ? 'checked' : '' ?>>
                        <?= e($name ?: $slug) ?>
                    </label>
                <?php endforeach; endif; ?>

                <button type="submit" class="btn btn-primary" style="margin-top:16px;"><?= __('save_changes', 'Save changes') ?></button>
            </form>
        </div>
    </div>

<?php else: ?>

    <div class="page-header">
        <div>
            <h1><?= __('plans', 'Plans') ?></h1>
            <p class="page-header-sub"><?= __('plans_intro', 'Legacy plan records retained for platform support and migration. They do not override verified remote entitlements.') ?></p>
        </div>
        <a href="<?= e(SLATE_URL) ?>/admin/plans.php?new" class="btn btn-primary"><?= __('new_plan', 'New plan') ?></a>
    </div>

    <?php $plans = PlanService::list(); ?>
    <?php if (empty($plans)): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-title"><?= __('no_plans', 'No plans found') ?></div>
                <p><?= __('no_plans_intro', 'Create a plan or assign one to a tenant.') ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($plans as $p):
                $tenantCount = PlanService::tenantCount((int)$p['id']);
                $features = PlanService::entitlementsFor((int)$p['id']);

                ob_start(); ?>
                <a href="<?= e(SLATE_URL) ?>/admin/plans.php?edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary"><?= __('edit', 'Edit') ?></a>
                <form method="post" style="display:inline;" onsubmit="return confirm(<?= e(json_encode(sprintf(__('confirm_delete_plan', 'Delete plan %s?'), $p['name']))) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><?= __('delete', 'Delete') ?></button>
                </form>
                <?php $actions = ob_get_clean();

                slate_data_row([
                    'avatar_html'  => slate_avatar_overlay_html(mb_strtoupper(mb_substr($p['name'], 0, 2))),
                    'avatar_color' => $p['is_active'] ? 'success' : 'muted',
                    'title'        => $p['name'],
                    'meta'         => $p['slug'],
                    'badge'        => [$p['is_active'] ? __('active', 'Active') : __('inactive', 'Inactive'), $p['is_active'] ? 'success' : 'muted'],
                    'detail'       => [
                        'Tenants'  => (string)$tenantCount,
                        'Features' => $features ? implode(', ', $features) : '—',
                        'Sort'     => (string)(int)$p['sort_order'],
                    ],
                    'actions'      => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
