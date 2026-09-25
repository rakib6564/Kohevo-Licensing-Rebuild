<?php
/**
 * Slate — Users CRUD.
 *
 * One page handles list, create, edit, delete via ?edit=N and POST
 * actions. Keeps everything in one route so the card-row "Edit"
 * button is just a link, simple to reason about.
 *
 * Routes:
 *   GET  /admin/users.php              → list of users
 *   GET  /admin/users.php?new          → new-user form
 *   GET  /admin/users.php?edit=42      → edit existing user
 *   POST (action=create|update|delete|reset_password) → mutation
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
Auth::requirePerm('users.view');

$canEdit  = Auth::can('users.edit') || Auth::isSuperAdmin();
$pageTitle  = __('users', 'Users');
$currentNav = 'users';

$tenantId = current_tenant_id();
$flash    = null;
$editing  = null; // user being edited (or null for new)
$mode     = 'list';

// ─── POST handlers ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission for this action.')];
    } else {
        $action = $_POST['_action'] ?? '';
        $name     = trim((string)($_POST['name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $roleId   = (int)($_POST['role_id'] ?? 0);
        $status   = (string)($_POST['status'] ?? 'active');
        $password = (string)($_POST['password'] ?? '');
        $userId   = (int)($_POST['user_id'] ?? 0);
        $statusValid = in_array($status, ['active', 'suspended', 'invited'], true) ? $status : 'active';

        // Role-assignment guard. Applies to both create and update. Without
        // this, any user holding the delegable `users.edit` permission could
        // POST role_id=1 and self-escalate to Super Admin (which short-circuits
        // every permission check). Two rules:
        //   1. Only a platform admin may assign/keep the Super Admin role
        //      (id=1) — granting platform-equivalent authority is itself a
        //      genuinely platform-level operation, not a tenant one, so this
        //      checks the dedicated concept (isPlatformSuperAdmin()) rather
        //      than the generic isSuperAdmin() bypass. Any future UI that
        //      lets someone grant `platform_admins` membership directly must
        //      carry this same guard. See Phase 0E/0G RBAC audit.
        //   2. The role must actually exist for this tenant (also blocks
        //      assigning another tenant's role id).
        if (in_array($action, ['create', 'update'], true)) {
            if ($roleId === 1 && !Auth::isPlatformSuperAdmin()) {
                $flash  = ['type' => 'error',
                           'msg' => __('forbidden_super_role', 'Only a Super Admin can assign the Super Admin role.')];
                $action = '';
            } elseif (!Database::row("SELECT id FROM roles WHERE id = ? AND tenant_id = ?", [$roleId, $tenantId])) {
                $flash  = ['type' => 'error', 'msg' => __('invalid_role', 'Please choose a valid role.')];
                $action = '';
            }
        }

        if ($action === 'create') {
            $err = validateUserInput($name, $email, $roleId, $password, true);
            if ($err) {
                $flash = ['type' => 'error', 'msg' => $err];
                $mode = 'edit';
                $editing = ['id' => 0, 'name' => $name, 'email' => $email, 'role_id' => $roleId, 'status' => $statusValid];
            } elseif (Database::row("SELECT id FROM users WHERE tenant_id = ? AND email = ?", [$tenantId, $email])) {
                $flash = ['type' => 'error', 'msg' => __('email_in_use', 'Another user already has that email.')];
                $mode = 'edit';
                $editing = ['id' => 0, 'name' => $name, 'email' => $email, 'role_id' => $roleId, 'status' => $statusValid];
            } else {
                $newId = Database::insert('users', [
                    'tenant_id'     => $tenantId,
                    'email'         => $email,
                    'name'          => $name,
                    'role_id'       => $roleId,
                    'status'        => $statusValid,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                AuditLog::record('user.created', "user#$newId", ['email' => $email]);
                $flash = ['type' => 'success', 'msg' => sprintf(__('user_created', 'User "%s" created.'), $name)];
            }
        }

        elseif ($action === 'update') {
            $target = Database::row("SELECT * FROM users WHERE id = ? AND tenant_id = ?", [$userId, $tenantId]);
            if (!$target) {
                $flash = ['type' => 'error', 'msg' => __('user_not_found', 'User not found.')];
            } else {
                $isSuper = (int)$target['role_id'] === 1;
                $isSelf  = $userId === (int)Auth::userId();

                // Don't allow demoting yourself out of super-admin
                if ($isSelf && $isSuper && $roleId !== 1) {
                    $flash = ['type' => 'error',
                              'msg' => __('cant_demote_self', 'You cannot demote yourself out of Super Admin.')];
                    $mode = 'edit';
                    $editing = $target;
                }
                // Don't allow demoting the LAST super-admin
                elseif ($isSuper && $roleId !== 1) {
                    $superCount = (int)Database::value(
                        "SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role_id = 1 AND status = 'active'",
                        [$tenantId]);
                    if ($superCount <= 1) {
                        $flash = ['type' => 'error',
                                  'msg' => __('last_super_admin', 'You cannot demote the last active Super Admin.')];
                        $mode = 'edit';
                        $editing = $target;
                    }
                }
                // Don't allow suspending yourself
                elseif ($isSelf && $statusValid !== 'active') {
                    $flash = ['type' => 'error',
                              'msg' => __('cant_suspend_self', 'You cannot change your own status.')];
                    $mode = 'edit';
                    $editing = $target;
                }
                else {
                    $err = validateUserInput($name, $email, $roleId, '', false);
                    if ($err) {
                        $flash = ['type' => 'error', 'msg' => $err];
                        $mode = 'edit';
                        $editing = array_merge($target,
                            ['name' => $name, 'email' => $email, 'role_id' => $roleId, 'status' => $statusValid]);
                    } else {
                        $dup = Database::row("SELECT id FROM users WHERE tenant_id = ? AND email = ? AND id != ?",
                            [$tenantId, $email, $userId]);
                        if ($dup) {
                            $flash = ['type' => 'error', 'msg' => __('email_in_use', 'Another user already has that email.')];
                            $mode = 'edit';
                            $editing = array_merge($target, ['name' => $name, 'email' => $email]);
                        } else {
                            Database::update('users', [
                                'name'    => $name,
                                'email'   => $email,
                                'role_id' => $roleId,
                                'status'  => $statusValid,
                            ], 'id = ? AND tenant_id = ?', [$userId, $tenantId]);
                            AuditLog::record('user.updated', "user#$userId");
                            $flash = ['type' => 'success', 'msg' => __('user_updated', 'User updated.')];
                            $mode = 'edit';
                            // Reload scoped: the UPDATE above matches zero rows for a
                            // foreign id, but an unscoped reload would still fetch and
                            // render that tenant's user in the edit form.
                            $editing = Database::row(
                                "SELECT * FROM users WHERE id = ? AND " . slate_tenant_clause(),
                                [$userId, slate_tenant_id()]
                            );
                        }
                    }
                }
            }
        }

        elseif ($action === 'reset_password') {
            $target = Database::row("SELECT * FROM users WHERE id = ? AND tenant_id = ?", [$userId, $tenantId]);
            if (!$target) {
                $flash = ['type' => 'error', 'msg' => __('user_not_found', 'User not found.')];
            } elseif (strlen($password) < 8) {
                $flash = ['type' => 'error',
                          'msg' => __('password_too_short', 'Password must be at least 8 characters.')];
                $mode = 'edit';
                $editing = $target;
            } else {
                Database::update('users',
                    ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
                    'id = ?', [$userId]);
                AuditLog::record('user.password_reset', "user#$userId");
                $flash = ['type' => 'success', 'msg' => __('password_updated', 'Password updated.')];
                $mode = 'edit';
                $editing = $target;
            }
        }

        elseif ($action === 'delete') {
            $target = Database::row("SELECT * FROM users WHERE id = ? AND tenant_id = ?", [$userId, $tenantId]);
            if (!$target) {
                $flash = ['type' => 'error', 'msg' => __('user_not_found', 'User not found.')];
            } elseif ($userId === (int)Auth::userId()) {
                $flash = ['type' => 'error', 'msg' => __('cant_delete_self', 'You cannot delete your own account.')];
            } elseif ((int)$target['role_id'] === 1) {
                $superCount = (int)Database::value(
                    "SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role_id = 1", [$tenantId]);
                if ($superCount <= 1) {
                    $flash = ['type' => 'error', 'msg' => __('last_super_admin_delete', 'You cannot delete the last Super Admin.')];
                } else {
                    Database::delete('users', 'id = ? AND tenant_id = ?', [$userId, $tenantId]);
                    AuditLog::record('user.deleted', "user#$userId", ['email' => $target['email']]);
                    $flash = ['type' => 'success', 'msg' => __('user_deleted', 'User deleted.')];
                }
            } else {
                Database::delete('users', 'id = ? AND tenant_id = ?', [$userId, $tenantId]);
                AuditLog::record('user.deleted', "user#$userId", ['email' => $target['email']]);
                $flash = ['type' => 'success', 'msg' => __('user_deleted', 'User deleted.')];
            }
        }
    }
}

// ─── Mode selection (GET) ────────────────────────────────────
if ($mode === 'list') {
    if (isset($_GET['new']) && $canEdit) {
        $mode = 'edit';
        $editing = ['id' => 0, 'name' => '', 'email' => '', 'role_id' => 0, 'status' => 'active'];
    } elseif (isset($_GET['edit'])) {
        $id = (int)$_GET['edit'];
        $editing = Database::row("SELECT * FROM users WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        if ($editing) $mode = 'edit';
    }
}

// ─── Helpers ─────────────────────────────────────────────────
function validateUserInput(string $name, string $email, int $roleId, string $password, bool $requirePassword): ?string {
    if ($name === '')  return __('name_required', 'Name is required.');
    if ($email === '') return __('email_required', 'Email is required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        return __('email_invalid', 'Email address is not valid.');
    if ($roleId <= 0) return __('role_required', 'A role must be selected.');
    if ($requirePassword && strlen($password) < 8)
        return __('password_too_short', 'Password must be at least 8 characters.');
    return null;
}

// ─── Render ──────────────────────────────────────────────────
require __DIR__ . '/partials/header.php';

$roles = Database::rows("SELECT id, name, slug FROM roles WHERE tenant_id = ? ORDER BY id", [$tenantId]);
?>

<?php slate_breadcrumbs($mode === 'edit'
    ? [
        ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
        ['label' => __('users', 'Users'),         'href' => SLATE_URL . '/admin/users.php'],
        ['label' => ($editing['id'] ?? 0) > 0 ? ($editing['name'] ?: __('edit', 'Edit')) : __('new_user', 'New user')],
      ]
    : [
        ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
        ['label' => __('users', 'Users')],
      ]
); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($mode === 'edit'): ?>
    <?php $isNew = ((int)($editing['id'] ?? 0)) === 0; ?>
    <div class="page-header">
        <div>
            <h1><?= e($isNew ? __('new_user', 'New user') : ($editing['name'] ?: __('edit_user', 'Edit user'))) ?></h1>
            <p class="page-header-sub">
                <?= e($isNew
                    ? __('new_user_sub', 'Add a new admin or staff account.')
                    : sprintf(__('edit_user_sub', 'Editing user #%d'), $editing['id'])) ?>
            </p>
        </div>
        <a href="<?= e(SLATE_URL) ?>/admin/users.php" class="btn btn-ghost">
            ← <?= __('back_to_list', 'Back to list') ?>
        </a>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('details', 'Details') ?></h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="<?= $isNew ? 'create' : 'update' ?>">
            <?php if (!$isNew): ?>
                <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
            <?php endif; ?>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name"><?= __('name', 'Name') ?> <span class="field-required">*</span></label>
                    <input type="text" id="name" name="name" required maxlength="120"
                           value="<?= e($editing['name'] ?? '') ?>"
                           <?= $canEdit ? '' : 'disabled' ?>>
                </div>
                <div class="field">
                    <label class="field-label" for="email"><?= __('email', 'Email') ?> <span class="field-required">*</span></label>
                    <input type="email" id="email" name="email" required maxlength="190"
                           value="<?= e($editing['email'] ?? '') ?>"
                           <?= $canEdit ? '' : 'disabled' ?>>
                </div>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="role_id"><?= __('role', 'Role') ?> <span class="field-required">*</span></label>
                    <select id="role_id" name="role_id" required <?= $canEdit ? '' : 'disabled' ?>>
                        <option value="">— <?= __('select_role', 'Select a role') ?> —</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"
                                <?= (int)$r['id'] === (int)($editing['role_id'] ?? 0) ? 'selected' : '' ?>>
                                <?= e($r['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="status"><?= __('status', 'Status') ?></label>
                    <select id="status" name="status" <?= $canEdit ? '' : 'disabled' ?>>
                        <option value="active"    <?= ($editing['status'] ?? '') === 'active'    ? 'selected' : '' ?>><?= __('active',    'Active') ?></option>
                        <option value="suspended" <?= ($editing['status'] ?? '') === 'suspended' ? 'selected' : '' ?>><?= __('suspended', 'Suspended') ?></option>
                        <option value="invited"   <?= ($editing['status'] ?? '') === 'invited'   ? 'selected' : '' ?>><?= __('invited',   'Invited') ?></option>
                    </select>
                    <div class="field-hint"><?= __('status_hint', 'Suspended users cannot log in but their records are preserved.') ?></div>
                </div>
            </div>

            <?php if ($isNew): ?>
                <div class="field">
                    <label class="field-label" for="password">
                        <?= __('password', 'Password') ?> <span class="field-required">*</span>
                    </label>
                    <input type="password" id="password" name="password" required minlength="8"
                           autocomplete="new-password">
                    <div class="field-hint"><?= __('password_hint', 'At least 8 characters.') ?></div>
                </div>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <div class="flex gap-2 mt-4" style="flex-wrap:wrap">
                    <button type="submit" class="btn btn-primary">
                        <?= $isNew ? __('create_user', 'Create user') : __('save_changes', 'Save changes') ?>
                    </button>
                    <a href="<?= e(SLATE_URL) ?>/admin/users.php" class="btn"><?= __('cancel', 'Cancel') ?></a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$isNew && $canEdit): ?>
        <div class="card">
            <div class="card-header"><h2><?= __('reset_password', 'Reset password') ?></h2></div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="reset_password">
                <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
                <div class="field">
                    <label class="field-label" for="new_password"><?= __('new_password', 'New password') ?></label>
                    <input type="password" id="new_password" name="password" required minlength="8"
                           autocomplete="new-password">
                    <div class="field-hint"><?= __('reset_hint', 'The user will need to use this new password to log in.') ?></div>
                </div>
                <button type="submit" class="btn"><?= __('set_new_password', 'Set new password') ?></button>
            </form>
        </div>

        <?php
        $isSelf  = (int)$editing['id'] === (int)Auth::userId();
        $isSuper = (int)$editing['role_id'] === 1;
        ?>
        <?php if (!$isSelf): ?>
        <div class="card">
            <div class="card-header"><h2 class="text-danger"><?= __('danger_zone', 'Danger zone') ?></h2></div>
            <p class="text-muted text-sm mb-3">
                <?= __('delete_user_warn', 'Deleting a user is permanent. Their audit log entries are preserved but no longer link to a live account.') ?>
            </p>
            <form method="post"
                  onsubmit="return confirm(<?= e(json_encode(
                      sprintf(__('confirm_delete_user', 'Delete %s? This cannot be undone.'), $editing['name'] ?: $editing['email'])
                  )) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
                <button type="submit" class="btn btn-danger"><?= __('delete_user', 'Delete user') ?></button>
            </form>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<?php else: /* ── LIST mode ──────────────────────────────── */ ?>

    <div class="page-header">
        <div>
            <h1><?= __('users', 'Users') ?></h1>
            <p class="page-header-sub">
                <?= __('users_subtitle', 'Admin and staff who can log into Kohevo.') ?>
            </p>
        </div>
        <?php if ($canEdit): ?>
            <a href="<?= e(SLATE_URL) ?>/admin/users.php?new" class="btn btn-primary">
                + <?= __('new_user', 'New user') ?>
            </a>
        <?php endif; ?>
    </div>

    <?php
    $users = Database::rows(
        "SELECT u.*, r.name AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.tenant_id = ?
         ORDER BY u.created_at DESC",
        [$tenantId]
    );
    ?>

    <?php if (empty($users)): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-title"><?= __('no_users', 'No users yet') ?></div>
                <p><?= __('no_users_intro', 'Add your first admin or staff account.') ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($users as $u):
                $statusColor = match($u['status']) {
                    'active'    => 'success',
                    'suspended' => 'muted',
                    'invited'   => 'warning',
                    default     => 'muted',
                };
                $initials = mb_strtoupper(mb_substr($u['name'] ?? '?', 0, 2));

                ob_start();
                ?>
                <?php if ($canEdit): ?>
                    <a href="<?= e(SLATE_URL) ?>/admin/users.php?edit=<?= (int)$u['id'] ?>"
                       class="btn btn-sm btn-primary"><?= __('edit', 'Edit') ?></a>
                <?php else: ?>
                    <a href="<?= e(SLATE_URL) ?>/admin/users.php?edit=<?= (int)$u['id'] ?>"
                       class="btn btn-sm"><?= __('view', 'View') ?></a>
                <?php endif; ?>
                <?php
                $actions = ob_get_clean();

                slate_data_row([
                    'avatar_html'  => slate_avatar_overlay_html($initials, (string)($u['email'] ?? '')),
                    'avatar_color' => $statusColor,
                    'title'        => $u['name'] ?: '(no name)',
                    'meta'         => $u['email'],
                    'badge'        => [$u['role_name'] ?? '—', 'accent'],
                    'detail'       => [
                        'Email'         => $u['email'],
                        'Role'          => $u['role_name'] ?? '—',
                        'Status'        => ['label' => 'Status', 'html' =>
                            '<span class="badge badge-' . e($statusColor) . '">' . e($u['status']) . '</span>'],
                        'Last login'    => $u['last_login_at'] ?: __('never', 'Never'),
                        'Created'       => $u['created_at'],
                    ],
                    'actions'      => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
