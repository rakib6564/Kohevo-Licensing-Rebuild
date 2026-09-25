<?php
/**
 * Backups — settings, Google Drive connection, history, and the manual
 * "Run backup now" button. Locked to Super Admin — this feature touches the
 * entire shared database (every tenant) and holds OAuth credentials.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/GoogleDriveClient.php';
require_once dirname(__DIR__) . '/BackupRunner.php';

Auth::require();
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    echo '<h1>' . e(__('backups_forbidden_title', '403 Forbidden')) . '</h1><p>' . e(__('only_super_admin', 'Super Admin only.')) . '</p>';
    exit;
}

$pageTitle  = __('backups_settings', 'Backups');
$currentNav = 'backups-settings';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'save_settings') {
            Database::setSetting('backups.google_client_id', trim((string)($_POST['client_id'] ?? '')));
            $secret = trim((string)($_POST['client_secret'] ?? ''));
            if ($secret !== '') { // blank means "leave the stored secret alone"
                Database::setSetting('backups.google_client_secret', slate_encrypt_secret($secret));
            }
            Database::setSetting('backups.retention_count', (string) max(1, (int)($_POST['retention_count'] ?? 14)));
            Database::setSetting('backups.enabled', !empty($_POST['enabled']) ? '1' : '0');
            AuditLog::record('backups.settings_updated');
            $flash = ['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')];

        } elseif ($action === 'connect') {
            if (!GoogleDriveClient::isConfigured()) {
                $flash = ['type' => 'error', 'msg' => __('backups_add_credentials_first', 'Add a Client ID and Client Secret first, then save, then connect.')];
            } else {
                $nonce = bin2hex(random_bytes(16));
                $_SESSION['backups_gdrive_nonce'] = $nonce;
                header('Location: ' . GoogleDriveClient::authUrl($nonce));
                exit;
            }

        } elseif ($action === 'disconnect') {
            GoogleDriveClient::disconnect();
            $flash = ['type' => 'success', 'msg' => __('backups_gdrive_disconnected', 'Google Drive disconnected.')];

        } elseif ($action === 'run_now') {
            $res = BackupRunner::runNow();
            $flash = !empty($res['ok'])
                ? ['type' => 'success', 'msg' => sprintf(__('backups_run_started', 'Backup started (run #%d). It will continue in the background over the next few cron ticks.'), (int)$res['run_id'])]
                : ['type' => 'error', 'msg' => (string)($res['error'] ?? __('backups_run_failed_generic', 'Could not start a backup.'))];
        }
    }
}

if (!empty($_GET['gdrive_connected'])) $flash = ['type' => 'success', 'msg' => sprintf(__('backups_gdrive_connected', 'Google Drive connected (%s).'), e((string)$_GET['gdrive_connected']))];
if (!empty($_GET['gdrive_error']))     $flash = ['type' => 'error',   'msg' => (string)$_GET['gdrive_error']];

$g = fn(string $k, $d = '') => (string)(Database::setting('backups.' . $k) ?? $d);

$connected = GoogleDriveClient::isConnected();
$runs = Database::rows(
    "SELECT * FROM backups_runs WHERE tenant_id = ? ORDER BY id DESC LIMIT 20",
    [current_tenant_id()]
);

function backups_format_bytes(?int $bytes): string {
    if (!$bytes) return '—';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $b = (float)$bytes;
    while ($b >= 1024 && $i < count($units) - 1) { $b /= 1024; $i++; }
    return round($b, 1) . ' ' . $units[$i];
}

function backups_status_label(string $status): string {
    static $labels = null;
    if ($labels === null) {
        $labels = [
            'pending'   => __('backups_status_pending', 'Pending'),
            'dumping'   => __('backups_status_dumping', 'Dumping'),
            'zipping'   => __('backups_status_zipping', 'Zipping'),
            'uploading' => __('backups_status_uploading', 'Uploading'),
            'pruning'   => __('backups_status_pruning', 'Pruning'),
            'done'      => __('backups_status_done', 'Done'),
            'failed'    => __('backups_status_failed', 'Failed'),
        ];
    }
    return $labels[$status] ?? ucfirst($status);
}

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('backups_settings', 'Backups')],
]); ?>

<div class="page-header"><div><h1><?= e(__('backups_settings', 'Backups')) ?></h1></div></div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><h2><?= e(__('backups_gdrive_connection_heading', 'Google Drive connection')) ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_settings">

        <div class="field">
            <label class="field-label" for="client_id"><?= e(__('backups_client_id', 'Client ID')) ?></label>
            <input type="text" id="client_id" name="client_id" value="<?= e($g('google_client_id')) ?>" autocomplete="off">
        </div>
        <div class="field">
            <label class="field-label" for="client_secret"><?= e(__('backups_client_secret', 'Client Secret')) ?></label>
            <input type="password" id="client_secret" name="client_secret" placeholder="<?= $g('google_client_secret') !== '' ? '••••••••  (' . __('oauth_secret_keep', 'Leave blank to keep the saved secret.') . ')' : '' ?>" autocomplete="off">
        </div>
        <div class="field">
            <label class="field-label" for="retention_count"><?= e(__('backups_retention_label', 'Keep the last')) ?></label>
            <input type="number" id="retention_count" name="retention_count" min="1" max="365" value="<?= e($g('retention_count', '14')) ?>" style="max-width:120px;"> <?= e(__('backups_retention_suffix', 'backups in Drive')) ?>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="enabled" value="1" <?= $g('enabled') === '1' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= e(__('backups_auto_daily', 'Run automatically once a day')) ?></span>
            </label>
        </div>
        <div class="flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><?= e(__('backups_save_settings', 'Save settings')) ?></button>
        </div>
    </form>

    <hr style="border:none;border-top:1px solid var(--border);margin:var(--space-3) 0;">

    <?php if ($connected): ?>
        <p>
            <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;color:#15803D;background:#DCFCE7;"><?= e(__('oauth_connected', 'Connected')) ?></span>
            <?php $email = $g('google_connected_email'); if ($email !== ''): ?> <?= e(__('backups_connected_as', 'as')) ?> <strong><?= e($email) ?></strong><?php endif; ?>
        </p>
        <form method="post" style="display:inline;" onsubmit="return confirm('<?= e(__('backups_confirm_disconnect', 'Disconnect Google Drive? Scheduled backups will stop until you reconnect.')) ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="disconnect">
            <button type="submit" class="btn btn-ghost"><?= e(__('backups_disconnect', 'Disconnect')) ?></button>
        </form>
    <?php else: ?>
        <p class="text-sm text-muted"><?= e(__('backups_not_connected_hint', 'Not connected yet. Add a Client ID/Secret above, save, then connect.')) ?></p>
        <form method="post" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="connect">
            <button type="submit" class="btn btn-primary"><?= e(__('backups_connect_gdrive', 'Connect Google Drive')) ?></button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= e(__('backups_history_heading', 'Backup history')) ?></h2>
        <form method="post" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="run_now">
            <button type="submit" class="btn btn-sm btn-primary"><?= e(__('backups_run_now', 'Run backup now')) ?></button>
        </form>
    </div>

    <?php if (!$runs): ?>
        <div class="empty"><div class="empty-title"><?= e(__('backups_no_runs_yet', 'No backups yet.')) ?></div></div>
    <?php else: ?>
        <div class="dlist">
            <?php foreach ($runs as $r):
                $statusColor = [
                    'done'   => 'color:#15803D;background:#DCFCE7;',
                    'failed' => 'color:#B91C1C;background:#FEE2E2;',
                ][$r['status']] ?? 'color:#B45309;background:#FEF3C7;';
                $driveUrl = $r['drive_file_id'] ? 'https://drive.google.com/file/d/' . rawurlencode($r['drive_file_id']) . '/view' : '';
            ?>
                <div class="dlist-row">
                    <span class="dlist-body">
                        <span class="dlist-title">
                            <span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;<?= $statusColor ?>"><?= e(backups_status_label($r['status'])) ?></span>
                            #<?= (int)$r['id'] ?>
                        </span>
                        <span class="dlist-sub">
                            <?= e($r['started_at']) ?><?= $r['finished_at'] ? ' → ' . e($r['finished_at']) : '' ?>
                            <?php if ($r['status'] === 'failed' && $r['error']): ?> — <?= e($r['error']) ?><?php endif; ?>
                        </span>
                    </span>
                    <span class="dlist-trail">
                        <span class="dlist-amount"><?= backups_format_bytes($r['size_bytes'] ?? null) ?></span>
                        <?php if ($driveUrl): ?><a href="<?= e($driveUrl) ?>" target="_blank" rel="noopener"><?= e(__('backups_open_in_drive', 'Open in Drive')) ?></a><?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
