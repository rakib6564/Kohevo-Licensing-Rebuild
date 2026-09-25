<?php
/**
 * Slate — Tenant Management (Phase 1E B1).
 *
 * Platform-only admin page for creating, listing, and managing the lifecycle
 * of every tenant on this install. Backed by TenantService, which owns the
 * `tenants` + `tenant_profiles` (migration 0014) reads/writes — this page is
 * presentation + request handling only.
 *
 * Routes:
 *   GET  /admin/tenants.php                → list, with ?q= search and ?status= filter
 *   GET  /admin/tenants.php?new            → new-tenant form
 *   GET  /admin/tenants.php?edit=42        → edit existing tenant
 *   POST (_action=create|update|transition) → mutation
 *
 * "Enter Tenant" is NOT part of this page (Phase 1E B2, a separate,
 * higher-scrutiny action layered on top of this list once it exists).
 */
require_once dirname(__DIR__) . '/config.php';

use Slate\Services\Tenancy\TenantService;

Auth::require();
Auth::requirePlatformAdmin();

$pageTitle  = __('tenants', 'Tenants');
$currentNav = 'tenants';
$flash      = null;
$mode       = 'list';
$editing    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'create') {
            try {
                $defaultPlugins = array_values(array_filter((array)($_POST['default_plugins'] ?? []), 'is_string'));
                $newId = TenantService::create([
                    'name'             => $_POST['name']         ?? '',
                    'slug'             => $_POST['slug']          ?? '',
                    'owner_name'       => $_POST['owner_name']    ?? '',
                    'owner_email'      => $_POST['owner_email']   ?? '',
                    'lifecycle_status' => $_POST['lifecycle_status'] ?? 'trial',
                    'trial_ends_at'    => !empty($_POST['trial_ends_at']) ? $_POST['trial_ends_at'] . ' 00:00:00' : null,
                    'timezone'         => $_POST['timezone']      ?? 'UTC',
                    'locale'           => $_POST['locale']        ?? 'en',
                    'default_plugins'  => $defaultPlugins,
                ]);
                header('Location: ' . SLATE_URL . '/admin/tenants.php?created=1');
                exit;
            } catch (\InvalidArgumentException $e) {
                $flash = ['type' => 'error', 'msg' => $e->getMessage()];
                $mode = 'new';
            }
        } elseif ($action === 'update') {
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            try {
                TenantService::updateProfile($tenantId, [
                    'name'        => $_POST['name']        ?? '',
                    'owner_name'  => $_POST['owner_name']  ?? '',
                    'owner_email' => $_POST['owner_email'] ?? '',
                    'timezone'    => $_POST['timezone']    ?? 'UTC',
                    'locale'      => $_POST['locale']      ?? 'en',
                ]);
                header('Location: ' . SLATE_URL . '/admin/tenants.php?edit=' . $tenantId . '&saved=1');
                exit;
            } catch (\InvalidArgumentException $e) {
                $flash = ['type' => 'error', 'msg' => $e->getMessage()];
                $mode = 'edit';
                $editing = TenantService::find($tenantId);
            }
        } elseif ($action === 'transition') {
            $tenantId  = (int)($_POST['tenant_id'] ?? 0);
            $newStatus = (string)($_POST['status'] ?? '');
            try {
                TenantService::transitionStatus($tenantId, $newStatus);
                $flash = ['type' => 'success', 'msg' => __('tenant_status_updated', 'Tenant status updated.')];
            } catch (\InvalidArgumentException $e) {
                $flash = ['type' => 'error', 'msg' => $e->getMessage()];
            }
        } elseif ($action === 'enter') {
            // Phase 1E B2 — safe tenant switching. This sets the SAME
            // $_SESSION['slate_override_tenant'] key current_tenant_id()
            // (includes/helpers.php) already knows how to resolve safely —
            // that mechanism, and its recursion-avoidance via
            // TenantContext::runAs() pinned to the admin's own home tenant,
            // predates this page and is proven by
            // tests/integration/TenantOverrideRecursionTest.php. This is
            // purely the missing "something writes the session key" half.
            // Auth::requirePlatformAdmin() already gated the whole page
            // above, matching the authorization runAs() itself re-checks.
            $tenantId = (int)($_POST['tenant_id'] ?? 0);
            $target = TenantService::find($tenantId);
            if ($target === null) {
                $flash = ['type' => 'error', 'msg' => __('tenant_not_found', 'Tenant not found.')];
            } else {
                $_SESSION['slate_override_tenant'] = $tenantId;
                AuditLog::record('tenant.entered', "tenant#$tenantId", ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
                header('Location: ' . SLATE_URL . '/admin/');
                exit;
            }
        }
    }
}

if ($mode === 'list' && isset($_GET['edit'])) {
    $editing = TenantService::find((int)$_GET['edit']);
    $mode = $editing ? 'edit' : 'list';
    if (!$editing) {
        $flash = ['type' => 'error', 'msg' => __('tenant_not_found', 'Tenant not found.')];
    }
} elseif ($mode === 'list' && isset($_GET['new'])) {
    $mode = 'new';
}

if (isset($_GET['created'])) {
    $flash = ['type' => 'success', 'msg' => __('tenant_created', 'Tenant created.')];
}
if (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')];
}

$allPlugins = PluginLoader::listAll();

require __DIR__ . '/partials/header.php';
?>

<?php slate_breadcrumbs($mode === 'list'
    ? [
        ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
        ['label' => __('tenants', 'Tenants')],
      ]
    : [
        ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
        ['label' => __('tenants', 'Tenants'), 'href' => SLATE_URL . '/admin/tenants.php'],
        ['label' => $mode === 'new' ? __('new_tenant', 'New tenant') : (($editing['name'] ?? '') ?: __('edit', 'Edit'))],
      ]
); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($mode === 'new' || $mode === 'edit'): ?>
    <?php $t = $editing ?: []; ?>
    <div class="page-header">
        <div>
            <h1><?= $mode === 'new' ? __('new_tenant', 'New tenant') : e($t['name'] ?? '') ?></h1>
        </div>
        <a href="<?= e(SLATE_URL) ?>/admin/tenants.php" class="btn"><?= __('back_to_list', 'Back to list') ?></a>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="<?= $mode === 'new' ? 'create' : 'update' ?>">
                <?php if ($mode === 'edit'): ?>
                    <input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
                <?php endif; ?>

                <div class="field">
                    <label class="field-label" for="t_name"><?= __('tenant_name', 'Tenant name') ?></label>
                    <input type="text" id="t_name" name="name" required maxlength="120"
                           value="<?= e($t['name'] ?? '') ?>">
                </div>

                <?php if ($mode === 'new'): ?>
                    <div class="field">
                        <label class="field-label" for="t_slug"><?= __('tenant_slug', 'Slug') ?></label>
                        <input type="text" id="t_slug" name="slug" required maxlength="64"
                               pattern="[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?"
                               placeholder="acme-clinic">
                        <div class="field-hint"><?= __('tenant_slug_hint', 'Lowercase letters, numbers, and hyphens only. Cannot be changed later.') ?></div>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label class="field-label"><?= __('tenant_slug', 'Slug') ?></label>
                        <input type="text" value="<?= e($t['slug'] ?? '') ?>" disabled>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label class="field-label" for="t_owner_name"><?= __('owner_name', 'Owner name') ?></label>
                    <input type="text" id="t_owner_name" name="owner_name" maxlength="120"
                           value="<?= e($t['owner_name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label class="field-label" for="t_owner_email"><?= __('owner_email', 'Owner email') ?></label>
                    <input type="email" id="t_owner_email" name="owner_email" maxlength="190"
                           value="<?= e($t['owner_email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label class="field-label" for="t_timezone"><?= __('timezone', 'Timezone') ?></label>
                    <input type="text" id="t_timezone" name="timezone" maxlength="64"
                           value="<?= e($t['timezone'] ?? 'UTC') ?>" placeholder="UTC">
                </div>

                <div class="field">
                    <label class="field-label" for="t_locale"><?= __('locale', 'Locale') ?></label>
                    <input type="text" id="t_locale" name="locale" maxlength="16"
                           value="<?= e($t['locale'] ?? 'en') ?>" placeholder="en">
                </div>

                <?php if ($mode === 'new'): ?>
                    <div class="field">
                        <label class="field-label" for="t_lifecycle"><?= __('starting_status', 'Starting status') ?></label>
                        <select id="t_lifecycle" name="lifecycle_status">
                            <?php foreach (TenantService::LIFECYCLE_STATUSES as $st): ?>
                                <option value="<?= e($st) ?>" <?= $st === 'trial' ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field-label" for="t_trial_ends"><?= __('trial_ends', 'Trial ends') ?></label>
                        <input type="date" id="t_trial_ends" name="trial_ends_at">
                        <div class="field-hint"><?= __('trial_ends_hint', 'Only meaningful if starting status is Trial.') ?></div>
                    </div>

                    <div class="field">
                        <label class="field-label"><?= __('initial_plugins', 'Initially enabled plugins') ?></label>
                        <?php if (empty($allPlugins)): ?>
                            <div class="field-hint"><?= __('no_plugins_installed', 'No plugins installed on this platform yet.') ?></div>
                        <?php else: foreach ($allPlugins as $p): ?>
                            <label style="display:block; font-weight:400; margin-bottom:4px;">
                                <input type="checkbox" name="default_plugins[]" value="<?= e($p['slug']) ?>">
                                <?= e($p['name'] ?: $p['slug']) ?>
                            </label>
                        <?php endforeach; endif; ?>
                        <div class="field-hint"><?= __('initial_plugins_hint', 'Recorded on the tenant profile for reference; does not itself install or activate plugin code.') ?></div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary"><?= $mode === 'new' ? __('create_tenant', 'Create tenant') : __('save_changes', 'Save changes') ?></button>
            </form>
        </div>
    </div>

<?php else: ?>

    <div class="page-header">
        <div>
            <h1><?= __('tenants', 'Tenants') ?></h1>
            <p class="page-header-sub"><?= __('tenants_intro', 'Every tenant on this install. Platform administrators only.') ?></p>
        </div>
        <a href="<?= e(SLATE_URL) ?>/admin/tenants.php?new" class="btn btn-primary"><?= __('new_tenant', 'New tenant') ?></a>
    </div>

    <form method="get" style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap;">
        <input type="search" name="q" placeholder="<?= e(__('search_tenants', 'Search name, slug, or owner email…')) ?>"
               value="<?= e((string)($_GET['q'] ?? '')) ?>" style="flex:1; min-width:220px;">
        <select name="status">
            <option value=""><?= __('all_statuses', 'All statuses') ?></option>
            <?php foreach (TenantService::LIFECYCLE_STATUSES as $st): ?>
                <option value="<?= e($st) ?>" <?= (($_GET['status'] ?? '') === $st) ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn"><?= __('filter', 'Filter') ?></button>
    </form>

    <?php
    $tenants = TenantService::list([
        'search' => (string)($_GET['q'] ?? ''),
        'status' => (string)($_GET['status'] ?? ''),
    ]);
    ?>

    <?php if (empty($tenants)): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-title"><?= __('no_tenants', 'No tenants found') ?></div>
                <p><?= __('no_tenants_intro', 'Create your first tenant to get started.') ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($tenants as $t):
                $lifecycle = $t['lifecycle_status'] ?? 'trial';
                $statusColor = match ($lifecycle) {
                    'active'                  => 'success',
                    'trial'                   => 'accent',
                    'suspended', 'deactivated' => 'warning',
                    'archived'                => 'muted',
                    default                   => 'muted',
                };

                ob_start(); ?>
                <a href="<?= e(SLATE_URL) ?>/admin/tenants.php?edit=<?= (int)$t['id'] ?>" class="btn btn-sm btn-primary"><?= __('edit', 'Edit') ?></a>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="enter">
                    <input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="btn btn-sm"><?= __('enter_tenant', 'Enter Tenant') ?></button>
                </form>
                <?php foreach (TenantService::LIFECYCLE_STATUSES as $st):
                    if ($st === $lifecycle) continue; ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm(<?= e(json_encode(sprintf(__('confirm_tenant_status', 'Move %s to %s?'), $t['name'], ucfirst($st)))) ?>);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="transition">
                        <input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>">
                        <input type="hidden" name="status" value="<?= e($st) ?>">
                        <button type="submit" class="btn btn-sm"><?= e(ucfirst($st)) ?></button>
                    </form>
                <?php endforeach;
                $actions = ob_get_clean();

                slate_data_row([
                    'avatar_html'  => slate_avatar_overlay_html(mb_strtoupper(mb_substr($t['name'] ?? '?', 0, 2)), (string)($t['owner_email'] ?? '')),
                    'avatar_color' => $statusColor,
                    'title'        => $t['name'],
                    'meta'         => $t['slug'],
                    'badge'        => [ucfirst($lifecycle), $statusColor === 'accent' ? 'accent' : $statusColor],
                    'detail'       => [
                        'Owner'     => $t['owner_name'] ?: ($t['owner_email'] ?: '—'),
                        'Users'     => (string)(int)($t['user_count'] ?? 0),
                        'Customers' => (string)(int)($t['customer_count'] ?? 0),
                        'Created'   => $t['created_at'],
                    ],
                    'actions'      => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
