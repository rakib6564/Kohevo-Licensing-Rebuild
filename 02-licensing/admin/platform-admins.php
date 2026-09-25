<?php
/**
 * Slate — Platform Administrators.
 *
 * platform_admins (migration 0013_platform_admins) and its backing
 * Auth::grantPlatformAdmin()/revokePlatformAdmin()/isPlatformAdmin() already
 * existed with no UI to manage them. This page is that UI.
 *
 * platform_admins carries no tenant_id (it's a genuinely global concept, per
 * its own migration) — the "look up a user to grant" query is deliberately
 * cross-tenant; see the anti-drift-ignore annotation below.
 *
 * Routes:
 *   GET  /admin/platform-admins.php        → list
 *   POST (_action=add|remove)              → mutation
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
Auth::requirePlatformAdmin();

$pageTitle  = __('platform_admins', 'Platform Administrators');
$currentNav = 'platform-admins';
$flash      = null;
$meId       = Auth::userId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'add') {
            $email = trim((string)($_POST['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'error', 'msg' => __('email_invalid', 'Enter a valid email address.')];
            } else {
                // Deliberately cross-tenant: platform-admin status is a
                // global concept (platform_admins has no tenant_id), so the
                // user being granted it may belong to any tenant on this
                // install, not just the caller's own current_tenant_id().
                // anti-drift-ignore: TENANT — platform_admins/this lookup are global by design, matching platform_admins' own schema (migration 0013)
                $user = Database::row("SELECT id, name, email FROM users WHERE email = ?", [$email]);
                if (!$user) {
                    $flash = ['type' => 'error', 'msg' => __('user_not_found', 'No user with that email exists. Only an existing user account can be granted platform-admin status.')];
                } else {
                    $userId = (int)$user['id'];
                    Auth::grantPlatformAdmin($userId, $meId);
                    AuditLog::record('platform_admin.granted', "user#$userId", ['email' => $user['email']]);
                    $flash = ['type' => 'success', 'msg' => sprintf(__('platform_admin_added', '%s is now a platform administrator.'), $user['name'] ?: $user['email'])];
                }
            }
        } elseif ($action === 'remove') {
            $userId = (int)($_POST['user_id'] ?? 0);

            if ($userId === (int)$meId) {
                // A platform admin cannot remove themselves through this
                // page — prevents an accidental self-lockout with no one
                // left to reverse it. (An intentional handover should be
                // done by another platform admin removing this one, not by
                // self-service.)
                $flash = ['type' => 'error', 'msg' => __('cannot_remove_self', 'You cannot remove your own platform-admin access. Ask another platform administrator to do it.')];
            } else {
                $total = (int)Database::value("SELECT COUNT(*) FROM platform_admins");
                $isMember = (bool)Database::value("SELECT 1 FROM platform_admins WHERE user_id = ?", [$userId]);
                if ($isMember && $total <= 1) {
                    // Should be unreachable in practice (removing the sole
                    // remaining admin would always also be a self-removal,
                    // already blocked above), but kept as an explicit,
                    // independent guard rather than relying on that being
                    // true forever.
                    $flash = ['type' => 'error', 'msg' => __('cannot_remove_last', 'At least one platform administrator must remain.')];
                } else {
                    // anti-drift-ignore: TENANT — platform_admins is global (no tenant_id, migration 0013); the target user may belong to any tenant, matching the lookup above
                    $target = Database::row("SELECT email FROM users WHERE id = ?", [$userId]);
                    Auth::revokePlatformAdmin($userId);
                    AuditLog::record('platform_admin.revoked', "user#$userId", ['email' => $target['email'] ?? null]);
                    $flash = ['type' => 'success', 'msg' => __('platform_admin_removed', 'Platform-admin access removed.')];
                }
            }
        }
    }
}

// anti-drift-ignore: TENANT — platform_admins is a global table (no tenant_id column, migration 0013); listing its members is intentionally cross-tenant
$admins = Database::rows(
    "SELECT pa.user_id, pa.granted_at, u.name, u.email,
            gb.name AS granted_by_name, gb.email AS granted_by_email
       FROM platform_admins pa
       JOIN users u ON u.id = pa.user_id
  LEFT JOIN users gb ON gb.id = pa.granted_by
   ORDER BY pa.granted_at ASC"
);

require __DIR__ . '/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('platform_admins', 'Platform Administrators')],
]); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1><?= __('platform_admins', 'Platform Administrators') ?></h1>
        <p class="page-header-sub"><?= __('platform_admins_intro', 'Platform administrators can manage every tenant on this install, install/uninstall plugin code, and access platform-wide diagnostics. Grant this sparingly.') ?></p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2><?= __('add_platform_admin', 'Add a platform administrator') ?></h2></div>
    <div class="card-body">
        <form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="add">
            <div class="field" style="flex:1; min-width:240px; margin:0;">
                <label class="field-label" for="pa_email"><?= __('user_email', 'User email') ?></label>
                <input type="email" id="pa_email" name="email" required
                       placeholder="<?= e(__('user_email_ph', 'someone@example.com')) ?>">
                <div class="field-hint"><?= __('add_platform_admin_hint', 'Must be an existing user account. They keep their normal tenant role — this only adds platform-wide authority on top of it.') ?></div>
            </div>
            <button type="submit" class="btn btn-primary"><?= __('grant_access', 'Grant access') ?></button>
        </form>
    </div>
</div>

<?php if (empty($admins)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('no_platform_admins', 'No platform administrators found') ?></div>
            <p><?= __('no_platform_admins_intro', 'This should not normally happen — add one above.') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($admins as $a):
            $initials = mb_strtoupper(mb_substr($a['name'] ?? '?', 0, 2));
            $isSelf   = (int)$a['user_id'] === (int)$meId;

            ob_start();
            if (!$isSelf): ?>
                <form method="post" onsubmit="return confirm(<?= e(json_encode(sprintf(
                        __('confirm_remove_platform_admin', 'Remove platform-admin access from %s?'),
                        $a['name'] ?: $a['email']
                    ))) ?>);" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="remove">
                    <input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><?= __('remove', 'Remove') ?></button>
                </form>
            <?php else: ?>
                <span class="badge badge-muted"><?= __('you', 'You') ?></span>
            <?php endif;
            $actions = ob_get_clean();

            slate_data_row([
                'avatar_html'  => slate_avatar_overlay_html($initials, (string)($a['email'] ?? '')),
                'avatar_color' => 'accent',
                'title'        => $a['name'] ?: $a['email'],
                'meta'         => $a['email'],
                'badge'        => [__('platform_admin', 'Platform admin'), 'accent'],
                'detail'       => [
                    'Email'      => $a['email'],
                    'Granted by' => $a['granted_by_name'] ?: ($a['granted_by_email'] ?: __('unknown', 'Unknown')),
                    'Granted at' => $a['granted_at'],
                ],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
