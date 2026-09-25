<?php
/**
 * Slate — Roles & permissions editor.
 *
 * Tenant-level: delegable via `roles.manage`, with the usual super-admin
 * bypass (Phase 0I RBAC audit — role/permission management is already
 * tenant-scoped, unlike the genuinely platform-only sites from Phase 0G).
 * The Super Admin role (id=1) stays read-only regardless of who reaches
 * this page — see the independent id===1 checks in the update/delete
 * handlers below, which are not gated by this permission at all.
 * Permissions sourced from Auth::knownPermissions() which unions
 * the core list with permissions declared in installed plugins.
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
if (!Auth::can('roles.manage') && !Auth::isSuperAdmin()) {
    http_response_code(403);
    echo '<h1>403</h1><p>You do not have permission to manage roles.</p>';
    exit;
}

$pageTitle  = __('roles', 'Roles');
$currentNav = 'roles';

$tenantId = current_tenant_id();
$flash    = null;
$editing  = null;
$mode     = 'list';

// ─── POST handlers ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        $roleId = (int)($_POST['role_id'] ?? 0);
        $name   = trim((string)($_POST['name'] ?? ''));
        $slug   = trim((string)($_POST['slug'] ?? ''));
        $desc   = trim((string)($_POST['description'] ?? ''));
        $perms  = $_POST['permissions'] ?? [];
        if (!is_array($perms)) $perms = [];

        // slugify
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $slug)) ?: '';
        $slug = trim($slug, '-');

        if ($action === 'create') {
            $err = validateRoleInput($name, $slug);
            if ($err) {
                $flash = ['type' => 'error', 'msg' => $err];
                $mode = 'edit';
                $editing = ['id' => 0, 'name' => $name, 'slug' => $slug, 'description' => $desc, 'is_system' => 0];
            } elseif (Database::row("SELECT id FROM roles WHERE tenant_id = ? AND slug = ?", [$tenantId, $slug])) {
                $flash = ['type' => 'error', 'msg' => __('role_slug_in_use', 'A role with that slug already exists.')];
                $mode = 'edit';
                $editing = ['id' => 0, 'name' => $name, 'slug' => $slug, 'description' => $desc, 'is_system' => 0];
            } else {
                $newId = Database::insert('roles', [
                    'tenant_id'   => $tenantId,
                    'name'        => $name,
                    'slug'        => $slug,
                    'description' => $desc !== '' ? $desc : null,
                    'is_system'   => 0,
                ]);
                saveRolePermissions($newId, $perms);
                AuditLog::record('role.created', "role#$newId", ['slug' => $slug]);
                Auth::invalidatePermCache();
                $flash = ['type' => 'success', 'msg' => sprintf(__('role_created', 'Role "%s" created.'), $name)];
            }
        }

        elseif ($action === 'update') {
            $target = Database::row("SELECT * FROM roles WHERE id = ? AND tenant_id = ?", [$roleId, $tenantId]);
            if (!$target) {
                $flash = ['type' => 'error', 'msg' => __('role_not_found', 'Role not found.')];
            } elseif ((int)$target['id'] === 1) {
                $flash = ['type' => 'error', 'msg' => __('cant_edit_super', 'Super Admin role cannot be modified.')];
            } else {
                $err = validateRoleInput($name, $slug);
                if ($err) {
                    $flash = ['type' => 'error', 'msg' => $err];
                    $mode = 'edit';
                    $editing = array_merge($target, ['name' => $name, 'slug' => $slug, 'description' => $desc]);
                } else {
                    $dup = Database::row("SELECT id FROM roles WHERE tenant_id = ? AND slug = ? AND id != ?",
                        [$tenantId, $slug, $roleId]);
                    if ($dup) {
                        $flash = ['type' => 'error', 'msg' => __('role_slug_in_use', 'A role with that slug already exists.')];
                        $mode = 'edit';
                        $editing = array_merge($target, ['name' => $name, 'slug' => $slug, 'description' => $desc]);
                    } else {
                        Database::update('roles', [
                            'name'        => $name,
                            'slug'        => $slug,
                            'description' => $desc !== '' ? $desc : null,
                        ], 'id = ? AND tenant_id = ?', [$roleId, $tenantId]);
                        saveRolePermissions($roleId, $perms);
                        AuditLog::record('role.updated', "role#$roleId");
                        Auth::invalidatePermCache();
                        $flash = ['type' => 'success', 'msg' => __('role_updated', 'Role updated.')];
                        $mode = 'edit';
                        // Reload scoped — see the note in admin/users.php; the same
                        // unscoped-reload-after-scoped-update shape.
                        $editing = Database::row(
                            "SELECT * FROM roles WHERE id = ? AND " . slate_tenant_clause(),
                            [$roleId, slate_tenant_id()]
                        );
                    }
                }
            }
        }

        elseif ($action === 'delete') {
            $target = Database::row("SELECT * FROM roles WHERE id = ? AND tenant_id = ?", [$roleId, $tenantId]);
            if (!$target) {
                $flash = ['type' => 'error', 'msg' => __('role_not_found', 'Role not found.')];
            } elseif ((int)$target['id'] === 1 || (int)$target['is_system'] === 1) {
                $flash = ['type' => 'error', 'msg' => __('cant_delete_system_role', 'System roles cannot be deleted.')];
            } else {
                $userCount = (int)Database::value(
                    "SELECT COUNT(*) FROM users WHERE role_id = ? AND tenant_id = ?", [$roleId, $tenantId]);
                if ($userCount > 0) {
                    $flash = ['type' => 'error',
                              'msg' => sprintf(__('role_in_use', '%d user(s) still have this role. Reassign them before deleting.'), $userCount)];
                } else {
                    Database::delete('roles', 'id = ? AND tenant_id = ?', [$roleId, $tenantId]);
                    AuditLog::record('role.deleted', "role#$roleId", ['slug' => $target['slug']]);
                    Auth::invalidatePermCache();
                    $flash = ['type' => 'success', 'msg' => __('role_deleted', 'Role deleted.')];
                }
            }
        }
    }
}

// ─── Mode selection ──────────────────────────────────────────
if ($mode === 'list') {
    if (isset($_GET['new'])) {
        $mode = 'edit';
        $editing = ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'is_system' => 0];
    } elseif (isset($_GET['edit'])) {
        $id = (int)$_GET['edit'];
        $editing = Database::row("SELECT * FROM roles WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        if ($editing) $mode = 'edit';
    }
}

// ─── Helpers ─────────────────────────────────────────────────
function validateRoleInput(string $name, string $slug): ?string {
    if ($name === '') return __('name_required', 'Name is required.');
    if ($slug === '') return __('slug_required', 'Slug is required.');
    if (!preg_match('/^[a-z][a-z0-9-]{1,62}$/', $slug))
        return __('slug_invalid', 'Slug must start with a letter and contain only lowercase letters, digits, and hyphens.');
    return null;
}

function saveRolePermissions(int $roleId, array $permKeys): void {
    // Delete existing grants, re-insert. Atomic enough for this use case.
    Database::delete('role_permissions', 'role_id = ?', [$roleId]);
    foreach (array_unique($permKeys) as $k) {
        if (!is_string($k) || $k === '') continue;
        // Validate format: lowercase, dots, hyphens
        if (!preg_match('/^[a-z][a-z0-9._-]{0,127}$/', $k)) continue;
        Database::insert('role_permissions', [
            'role_id'  => $roleId,
            'perm_key' => $k,
            'granted'  => 1,
        ]);
    }
}

// ─── Render ──────────────────────────────────────────────────
require __DIR__ . '/partials/header.php';
?>

<?php slate_breadcrumbs($mode === 'edit'
    ? [
        ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
        ['label' => __('roles', 'Roles'),         'href' => SLATE_URL . '/admin/roles.php'],
        ['label' => ($editing['id'] ?? 0) > 0 ? ($editing['name'] ?: __('edit', 'Edit')) : __('new_role', 'New role')],
      ]
    : [
        ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
        ['label' => __('roles', 'Roles')],
      ]
); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($mode === 'edit'):
    $isNew    = ((int)($editing['id'] ?? 0)) === 0;
    $isSuper  = (int)($editing['id'] ?? 0) === 1;
    $readOnly = $isSuper;

    // Current grants for this role
    $grantsRaw = !$isNew ? Database::rows(
        "SELECT perm_key FROM role_permissions WHERE role_id = ? AND granted = 1",
        [(int)$editing['id']]
    ) : [];
    $currentPerms = array_column($grantsRaw, 'perm_key');

    $groups = Auth::knownPermissions();
?>
    <div class="page-header">
        <div>
            <h1><?= e($isNew ? __('new_role', 'New role') : ($editing['name'] ?: __('edit_role', 'Edit role'))) ?></h1>
            <p class="page-header-sub">
                <?= e($isSuper
                    ? __('super_admin_readonly', 'The Super Admin role has all permissions and cannot be modified.')
                    : ($isNew
                        ? __('new_role_sub', 'Define a new role and grant it permissions.')
                        : __('edit_role_sub', 'Update this role and its permissions.'))) ?>
            </p>
        </div>
        <a href="<?= e(SLATE_URL) ?>/admin/roles.php" class="btn btn-ghost">
            ← <?= __('back_to_list', 'Back to list') ?>
        </a>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= $isNew ? 'create' : 'update' ?>">
        <?php if (!$isNew): ?>
            <input type="hidden" name="role_id" value="<?= (int)$editing['id'] ?>">
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2><?= __('details', 'Details') ?></h2></div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name"><?= __('name', 'Name') ?> <span class="field-required">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="64"
                           value="<?= e($editing['name'] ?? '') ?>"
                           <?= $readOnly ? 'disabled' : '' ?>>
                </div>
                <div class="field">
                    <label class="field-label" for="slug"><?= __('slug', 'Slug') ?> <span class="field-required">*</span></label>
                    <input type="text" id="slug" name="slug" required maxlength="64"
                           value="<?= e($editing['slug'] ?? '') ?>"
                           <?= $readOnly ? 'disabled' : '' ?>>
                    <div class="field-hint"><?= __('slug_hint', 'Lowercase, letters/digits/hyphens. Used in code.') ?></div>
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="description"><?= __('description', 'Description') ?></label>
                <input type="text" id="description" name="description" maxlength="255"
                       value="<?= e($editing['description'] ?? '') ?>"
                       <?= $readOnly ? 'disabled' : '' ?>>
            </div>
        </div>

        <?php /* ── Permission groups ─────────────────────── */ ?>
        <?php if ($isSuper): ?>
            <div class="card">
                <div class="card-header">
                    <h2><?= __('permissions', 'Permissions') ?></h2>
                    <span class="badge badge-accent"><?= __('all_perms', 'All permissions') ?></span>
                </div>
                <p class="text-muted">
                    <?= __('super_perms', 'Super Admin always has every permission, including any new ones declared by plugins after activation.') ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($groups as $groupName => $groupPerms): ?>
                <div class="card">
                    <div class="card-header"><h2><?= e($groupName) ?></h2></div>
                    <div class="flex flex-col gap-2">
                        <?php foreach ($groupPerms as $p): ?>
                            <?php $checked = in_array($p['key'], $currentPerms, true); ?>
                            <label class="flex items-center gap-3"
                                   style="padding:10px 12px;border-radius:var(--radius-sm);cursor:pointer"
                                   <?= $checked ? 'data-on' : '' ?>>
                                <input type="checkbox" name="permissions[]"
                                       value="<?= e($p['key']) ?>"
                                       style="width:18px;height:18px;flex-shrink:0;accent-color:var(--accent);cursor:pointer"
                                       <?= $checked ? 'checked' : '' ?>
                                       <?= $readOnly ? 'disabled' : '' ?>>
                                <span style="flex:1;min-width:0">
                                    <span style="font-weight:500;color:var(--text)"><?= e($p['label']) ?></span>
                                    <span class="text-muted text-sm" style="display:block">
                                        <code><?= e($p['key']) ?></code>
                                    </span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$readOnly): ?>
            <div class="flex gap-2 mt-4" style="flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">
                    <?= $isNew ? __('create_role', 'Create role') : __('save_changes', 'Save changes') ?>
                </button>
                <a href="<?= e(SLATE_URL) ?>/admin/roles.php" class="btn"><?= __('cancel', 'Cancel') ?></a>
            </div>
        <?php endif; ?>
    </form>

    <?php if (!$isNew && !$readOnly && !($editing['is_system'] ?? 0)): ?>
        <div class="card mt-4">
            <div class="card-header"><h2 class="text-danger"><?= __('danger_zone', 'Danger zone') ?></h2></div>
            <p class="text-muted text-sm mb-3">
                <?= __('delete_role_warn', 'Deleting a role is permanent. Users with this role must be reassigned first.') ?>
            </p>
            <form method="post"
                  onsubmit="return confirm(<?= e(json_encode(
                      sprintf(__('confirm_delete_role', 'Delete role "%s"? This cannot be undone.'), $editing['name'])
                  )) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="role_id" value="<?= (int)$editing['id'] ?>">
                <button type="submit" class="btn btn-danger"><?= __('delete_role', 'Delete role') ?></button>
            </form>
        </div>
    <?php endif; ?>

<?php else: /* ── LIST mode ───────────────────────────────── */ ?>

    <div class="page-header">
        <div>
            <h1><?= __('roles', 'Roles') ?></h1>
            <p class="page-header-sub">
                <?= __('roles_subtitle', 'Roles control what admin users can do.') ?>
            </p>
        </div>
        <a href="<?= e(SLATE_URL) ?>/admin/roles.php?new" class="btn btn-primary">
            + <?= __('new_role', 'New role') ?>
        </a>
    </div>

    <?php
    $roles = Database::rows(
        "SELECT r.*,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.tenant_id = r.tenant_id) AS user_count,
            (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id AND rp.granted = 1) AS perm_count
         FROM roles r
         WHERE r.tenant_id = ?
         ORDER BY r.id ASC",
        [$tenantId]
    );
    ?>

    <div class="data-list" data-single-open>
        <?php foreach ($roles as $r):
            $isSuper = (int)$r['id'] === 1;
            $initials = mb_strtoupper(mb_substr($r['name'] ?? '?', 0, 2));

            ob_start();
            ?>
            <a href="<?= e(SLATE_URL) ?>/admin/roles.php?edit=<?= (int)$r['id'] ?>"
               class="btn btn-sm btn-primary"><?= e($isSuper ? __('view', 'View') : __('edit', 'Edit')) ?></a>
            <?php
            $actions = ob_get_clean();

            slate_data_row([
                'avatar'       => $initials,
                'avatar_color' => $isSuper ? 'accent' : 'info',
                'title'        => $r['name'],
                'meta'         => $r['slug'] . ($r['description'] ? ' · ' . $r['description'] : ''),
                'badge'        => $isSuper
                    ? [__('super_admin', 'Super'), 'accent']
                    : [(int)$r['perm_count'] . ' ' . __('perms', 'perms'), ''],
                'detail'       => [
                    'Slug'           => $r['slug'],
                    'Description'    => $r['description'] ?: '—',
                    'Users'          => (int)$r['user_count'],
                    'Permissions'    => $isSuper
                        ? ['label' => 'Permissions', 'value' => 'All (super-admin)']
                        : (int)$r['perm_count'],
                    'Role ID'        => '#' . (int)$r['id'],
                ],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
