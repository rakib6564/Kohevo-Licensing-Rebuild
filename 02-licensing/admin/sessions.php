<?php
/** Slate — active admin sessions and devices. */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
Auth::requirePerm('users.view');

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'revoke_others') {
        $count = Auth::revokeOtherSessions();
        AuditLog::record('session.revoke_others', 'admin#' . (int)Auth::userId(), ['count' => $count]);
        $flash = ['type' => 'success', 'msg' => sprintf(__('sessions_revoked', '%d other session(s) revoked.'), $count)];
    } elseif (($_POST['_action'] ?? '') === 'revoke') {
        $id = (int)($_POST['session_id'] ?? 0);
        $ok = Auth::revokeSession($id);
        if ($ok) AuditLog::record('session.revoked', 'session#' . $id);
        $flash = ['type' => $ok ? 'success' : 'error', 'msg' => $ok ? __('session_revoked', 'Session revoked.') : __('session_not_found', 'Session not found or already revoked.')];
    }
}

$pageTitle = __('sessions_devices', 'Sessions & devices');
$currentNav = 'sessions';
$sessions = Auth::listSessions();
require __DIR__ . '/partials/header.php';
?>

<?php
require_once SLATE_ROOT . '/includes/breadcrumbs.php';
slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/index.php'],
    ['label' => __('sessions_devices', 'Sessions & devices')],
]);
?>

<div class="page-header">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="page-header-sub">
            <?= __('sessions_subtitle', 'Review active administrator sessions for this tenant and revoke access you no longer trust.') ?>
        </p>
    </div>
    <?php if (count($sessions) > 1): ?>
        <form method="post" style="margin:0" onsubmit="return confirm(<?= e(json_encode(__('confirm_revoke_others', 'Revoke all other active sessions for your account?'))) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="revoke_others">
            <button class="btn btn-secondary" type="submit">
                <?= __('revoke_other_sessions', 'Revoke other sessions') ?>
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if (empty($sessions)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('no_sessions', 'No active sessions') ?></div>
            <p><?= __('no_sessions_intro', 'No active sessions are registered for this account.') ?></p>
        </div>
    </div>
<?php else: ?>
    <?php
    $currentHash = hash('sha256', session_id());
    ?>
    <div class="data-list" data-single-open>
        <?php foreach ($sessions as $session):
            $isCurrent = !empty($session['session_hash']) && hash_equals($session['session_hash'], $currentHash);
            $ua = (string)($session['user_agent'] ?? '');
            $label = (string)($session['device_label'] ?? '');

            // Browser detection
            $browser = '';
            if (str_contains($ua, 'Edg/')) {
                $browser = 'Edge';
            } elseif (str_contains($ua, 'Chrome/')) {
                $browser = 'Chrome';
            } elseif (str_contains($ua, 'Firefox/')) {
                $browser = 'Firefox';
            } elseif (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome/')) {
                $browser = 'Safari';
            } elseif (str_contains($ua, 'Opera/') || str_contains($ua, 'OPR/')) {
                $browser = 'Opera';
            }

            // Platform / OS detection
            $os = '';
            $initials = 'DV';
            if (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X')) {
                $os = 'macOS';
                $initials = 'MC';
            } elseif (str_contains($ua, 'Windows')) {
                $os = 'Windows';
                $initials = 'WN';
            } elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
                $os = 'iOS';
                $initials = 'IP';
            } elseif (str_contains($ua, 'Android')) {
                $os = 'Android';
                $initials = 'AD';
            } elseif (str_contains($ua, 'Linux')) {
                $os = 'Linux';
                $initials = 'LX';
            }

            if ($browser !== '' && $os !== '') {
                $deviceTitle = $browser . ' on ' . $os;
            } elseif ($browser !== '') {
                $deviceTitle = $browser;
            } elseif ($os !== '') {
                $deviceTitle = $os . ' device';
            } else {
                $deviceTitle = $label !== '' ? $label : 'Admin browser';
                $initials = 'AB';
            }

            $statusColor = $isCurrent ? 'accent' : 'success';

            // Meta line
            $metaParts = [];
            if (!empty($session['ip_address'])) {
                $metaParts[] = $session['ip_address'];
            }
            if (!empty($session['last_seen_at'])) {
                $metaParts[] = __('last_active', 'Last active') . ' ' . $session['last_seen_at'];
            }
            $meta = implode(' · ', $metaParts);

            ob_start();
            ?>
            <form method="post" style="display:inline-block;margin:0;"
                  onsubmit="return confirm(<?= e(json_encode($isCurrent
                      ? __('confirm_revoke_self', 'Revoking this session will log you out immediately. Continue?')
                      : __('confirm_revoke_session', 'Revoke this session?'))) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="revoke">
                <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">
                    <?= $isCurrent ? __('revoke_and_logout', 'Revoke & log out') : __('revoke', 'Revoke') ?>
                </button>
            </form>
            <?php
            $actions = ob_get_clean();

            $detail = [
                'Device'        => $deviceTitle,
                'IP address'    => !empty($session['ip_address']) ? $session['ip_address'] : '—',
                'Status'        => [
                    'label' => 'Status',
                    'html'  => '<span class="badge badge-' . e($statusColor) . '">' . e($isCurrent ? __('current_session', 'Current session') : __('active', 'Active')) . '</span>'
                ],
                'Last activity' => !empty($session['last_seen_at']) ? (string)$session['last_seen_at'] : '—',
                'Created'       => !empty($session['created_at']) ? (string)$session['created_at'] : '—',
                'Expires'       => !empty($session['expires_at']) ? (string)$session['expires_at'] : '—',
            ];

            if ($ua !== '') {
                $detail['User agent'] = [
                    'label' => 'User agent',
                    'value' => $ua,
                    'muted' => true
                ];
            }

            slate_data_row([
                'avatar'       => $initials,
                'avatar_color' => $statusColor,
                'title'        => $deviceTitle,
                'meta'         => $meta,
                'badge'        => [$isCurrent ? __('current_session', 'Current session') : __('active', 'Active'), $statusColor],
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
