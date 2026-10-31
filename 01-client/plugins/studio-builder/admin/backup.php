<?php
/**
 * Kohevo Studio — Backup.
 *
 * One file holding everything Studio keeps for this site: make one, download it, keep the last few here, and put a site
 * back or move it to another one. Nothing is restored from this screen without a preview first, and a restore starts by
 * taking a safety backup (see `StudioApplicationService::restoreBackup()`).
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Backup\StudioBackupStore;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();
Auth::requirePerm('studio-builder.admin');

$pageTitle = __('studio_backup', 'Backup');
$currentNav = 'studio-backup';
$self = plugin_url('studio-builder', 'admin/backup.php');
$store = new StudioBackupStore(current_tenant_id());
$canZip = class_exists(\ZipArchive::class);
$flash = null;

$sbIniBytes = static function (string $name): int {
    $raw = trim((string) ini_get($name));
    $n = (int) $raw;
    return match (strtolower(substr($raw, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
};
$sbSize = static function (int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max($bytes, 1024) / 1024, 0) . ' KB';
};
$maxUpload = min(array_filter([$sbIniBytes('upload_max_filesize'), $sbIniBytes('post_max_size')]) ?: [0]);

if (isset($_GET['download'])) {
    $path = $canZip ? StudioRuntimeFactory::build()->app->backupDownload(StudioActor::fromCurrentSession(), $store, (string) $_GET['download']) : null;
    if ($path === null) {
        http_response_code(404);
        exit;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . (int) filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tooLarge = $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $sbIniBytes('post_max_size');
    if ($tooLarge) {
        $flash = ['type' => 'error', 'msg' => sprintf(__('studio_backup_too_large', 'This file is larger than the %s this server accepts. Raise the upload limit, or make the backup without media files.'), $sbSize($maxUpload))];
    } elseif (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account.')];
    } elseif (!$canZip) {
        $flash = ['type' => 'error', 'msg' => __('studio_backup_no_zip', 'This server has no Zip extension, so backups cannot be made here.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $id = (string) ($_POST['backup_id'] ?? '');
        @set_time_limit(0);
        try {
            $app = StudioRuntimeFactory::build()->app;
            $actor = StudioActor::fromCurrentSession();

            if ($action === 'create') {
                $made = $app->createBackup($actor, $store, !empty($_POST['with_media']));
                sb_flash_redirect($self, ['type' => 'success', 'msg' => sprintf(__('studio_backup_created', 'Backup made: %1$d pages, %2$d media files.'), $made['counts']['pages'], $made['counts']['files'])]);
            }
            if ($action === 'upload') {
                $file = $_FILES['backup_file'] ?? null;
                if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                    throw new StudioValidationException([['path' => '$', 'code' => 'invalid_backup', 'message' => __('studio_backup_upload_failed', 'The file did not arrive. Check its size and try again.')]]);
                }
                $target = $store->newPath(StudioBackupStore::KIND_UPLOAD);
                if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                    throw new \RuntimeException('The uploaded backup could not be moved.');
                }
                try {
                    $app->previewBackup($actor, $target);
                } catch (\Throwable $e) {
                    @unlink($target);
                    throw $e;
                }
                $store->prune(StudioBackupStore::KIND_UPLOAD);
                sb_flash_redirect($self . '?preview=' . rawurlencode(basename($target)), ['type' => 'info', 'msg' => __('studio_backup_uploaded', 'Backup received. Nothing has changed yet: check what it would do.')]);
            }
            if ($action === 'restore') {
                $path = $store->path($id);
                if ($path === null) {
                    sb_flash_redirect($self, ['type' => 'error', 'msg' => __('studio_backup_not_found', 'That backup is no longer here.')]);
                }
                if (empty($_POST['confirm'])) {
                    sb_flash_redirect($self . '?preview=' . rawurlencode($id), ['type' => 'error', 'msg' => __('studio_backup_confirm_required', 'Tick the box to confirm the restore.')]);
                }
                $_SESSION['sb_backup_result'] = $app->restoreBackup($actor, $store, $path);
                sb_flash_redirect($self, ['type' => 'success', 'msg' => __('studio_backup_restored', 'Restore finished.')]);
            }
            if ($action === 'delete') {
                $app->deleteBackup($actor, $store, $id);
                sb_flash_redirect($self, ['type' => 'success', 'msg' => __('studio_backup_deleted', 'Backup deleted.')]);
            }
        } catch (StudioValidationException $e) {
            $flash = ['type' => 'error', 'msg' => (string) ($e->errors()[0]['message'] ?? __('studio_backup_failed', 'That did not work. Nothing else was changed.'))];
        } catch (\Throwable $e) {
            sb_log_error('backup', $e);
            $flash = ['type' => 'error', 'msg' => __('studio_backup_failed', 'That did not work. Nothing else was changed.')];
        }
    }
}

$flash = $flash ?? sb_take_flash();
$result = $_SESSION['sb_backup_result'] ?? null;
unset($_SESSION['sb_backup_result']);

$previewId = (string) ($_GET['preview'] ?? '');
$preview = null;
if ($previewId !== '' && $canZip) {
    $previewPath = $store->path($previewId);
    if ($previewPath === null) {
        $flash = ['type' => 'error', 'msg' => __('studio_backup_not_found', 'That backup is no longer here.')];
    } else {
        @set_time_limit(0);
        try {
            $preview = StudioRuntimeFactory::build()->app->previewBackup(StudioActor::fromCurrentSession(), $previewPath);
        } catch (StudioValidationException $e) {
            $flash = ['type' => 'error', 'msg' => (string) ($e->errors()[0]['message'] ?? __('studio_backup_failed', 'That did not work. Nothing else was changed.'))];
        } catch (\Throwable $e) {
            sb_log_error('backup preview', $e);
            $flash = ['type' => 'error', 'msg' => __('studio_backup_failed', 'That did not work. Nothing else was changed.')];
        }
    }
}

$kinds = [
    StudioBackupStore::KIND_MANUAL => __('studio_backup_kind_manual', 'Backup'),
    StudioBackupStore::KIND_SAFETY => __('studio_backup_kind_safety', 'Before a restore'),
    StudioBackupStore::KIND_UPLOAD => __('studio_backup_kind_upload', 'Uploaded'),
];
$warningText = static function (array $warning): string {
    $title = (string) ($warning['title'] ?? '');
    $detail = (string) ($warning['detail'] ?? '');
    return match ((string) ($warning['code'] ?? '')) {
        'still_live'      => sprintf(__('studio_backup_warn_live', '%s was a draft in the backup but is live now. Its draft was restored and it stays live.'), $title),
        'address_kept'    => sprintf(__('studio_backup_warn_address', '%s kept its current address: %s'), $title, $detail),
        'tokens_skipped'  => sprintf(__('studio_backup_warn_tokens', 'Design tokens "%s" were skipped: %s'), $title, $detail),
        'setting_skipped' => sprintf(__('studio_backup_warn_setting', 'Setting %s was skipped: %s'), $title, $detail),
        'media_skipped'   => sprintf(__('studio_backup_warn_media', '%s was skipped: %s'), $title, $detail),
        default           => trim($title . ' ' . $detail),
    };
};
$pageNames = static function (array $rows, int $max = 12): string {
    $names = array_map(static fn(array $r): string => (string) $r['title'], array_slice($rows, 0, $max));
    $more = count($rows) - count($names);
    return implode(', ', $names) . ($more > 0 ? ' ' . sprintf(__('studio_backup_and_more', 'and %d more'), $more) : '');
};

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <?php if (is_array($result)): ?>
        <div class="sb-card" data-testid="backup-result">
            <div class="sb-card__head"><div class="sb-card__title"><?= e(__('studio_backup_result', 'What the restore did')) ?></div></div>
            <ul class="sb-backup-list">
                <li><?= e(sprintf(__('studio_backup_result_pages', '%1$d pages created, %2$d updated, %3$d already the same.'), $result['created'], $result['updated'], $result['unchanged'])) ?></li>
                <li><?= e(sprintf(__('studio_backup_result_rest', '%1$d templates, %2$d settings and %3$d design token groups restored.'), $result['templates'], $result['settings'], count($result['token_groups']))) ?></li>
                <li><?= e(sprintf(__('studio_backup_result_media', 'Media: %1$d added, %2$d already here.'), $result['media']['new'], $result['media']['existing'])) ?></li>
                <?php if ($result['media']['missing'] + $result['media']['conflict'] > 0): ?>
                    <li><?= e(sprintf(__('studio_backup_result_media_lost', '%d media files could not be restored; blocks using them lost their image.'), $result['media']['missing'] + $result['media']['conflict'])) ?></li>
                <?php endif; ?>
                <?php foreach ($result['failed'] as $failed): ?>
                    <li class="sb-backup-bad"><?= e(sprintf(__('studio_backup_result_failed', '%1$s was not restored: %2$s'), (string) $failed['title'], (string) $failed['reason'])) ?></li>
                <?php endforeach; ?>
                <?php foreach ($result['warnings'] as $warning): ?>
                    <li class="sb-backup-warn"><?= e($warningText($warning)) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="sb-field__hint"><?= e(__('studio_backup_result_undo', 'To go back, restore the "Before a restore" backup below.')) ?></p>
        </div>
    <?php endif; ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_backup_heading', 'Studio backup')) ?></h2>
            <p><?= e(__('studio_backup_intro', 'One file with your pages, drafts, templates, design tokens, menus, Studio settings and media. Use it to put this site back, or to move it to another one.')) ?></p>
        </div>
    </div>

    <?php if (!$canZip): ?>
        <div class="alert alert-warning sb-alert" role="alert"><?= e(__('studio_backup_no_zip', 'This server has no Zip extension, so backups cannot be made here.')) ?></div>
    <?php endif; ?>

    <?php if (is_array($preview)):
        $m = $preview['manifest'];
        $previewSource = (string) ($m['site'] ?? ''); ?>
        <div class="sb-card" data-testid="backup-preview">
            <div class="sb-card__head"><div class="sb-card__title"><?= e(__('studio_backup_preview', 'Check before you restore')) ?></div></div>
            <p class="sb-field__hint"><?= e(sprintf(__('studio_backup_preview_from', 'Made %1$s%2$s.'), date('M j, Y H:i', (int) strtotime((string) ($m['created_at'] ?? ''))), $previewSource !== '' ? ' ' . sprintf(__('studio_backup_preview_site', 'on %s'), $previewSource) : '')) ?></p>
            <ul class="sb-backup-list">
                <li><?= e(sprintf(__('studio_backup_preview_pages', '%1$d pages will be updated and %2$d created.'), count($preview['update']), count($preview['new']))) ?></li>
                <li><?= e(sprintf(__('studio_backup_preview_rest', '%1$d templates, %2$d settings and %3$d design token groups will replace the current ones. These change the live site at once.'), $preview['templates'], $preview['settings'], $preview['token_groups'])) ?></li>
                <li><?= e(sprintf(__('studio_backup_preview_media', 'Media: %1$d files added, %2$d already here.'), $preview['media']['new'], $preview['media']['existing'])) ?></li>
                <?php if ($preview['media']['missing'] > 0): ?>
                    <li class="sb-backup-warn"><?= e(sprintf(__('studio_backup_preview_missing', '%d media files are not in this backup or on this site. Blocks using them will lose their image.'), $preview['media']['missing'])) ?></li>
                <?php endif; ?>
                <?php if ($preview['media']['conflict'] > 0): ?>
                    <li class="sb-backup-warn"><?= e(sprintf(__('studio_backup_preview_conflict', '%d media files have the same name as a different file here. The existing file stays, and blocks using the backup\'s file will lose their image.'), $preview['media']['conflict'])) ?></li>
                <?php endif; ?>
                <?php if ($preview['kept'] !== []): ?>
                    <li><?= e(sprintf(__('studio_backup_preview_kept', 'Pages not in the backup stay as they are: %s.'), $pageNames($preview['kept']))) ?></li>
                <?php endif; ?>
                <?php if ($preview['still_live'] !== []): ?>
                    <li class="sb-backup-warn"><?= e(sprintf(__('studio_backup_preview_live', 'These were drafts in the backup but are live now, and they stay live: %s.'), $pageNames($preview['still_live']))) ?></li>
                <?php endif; ?>
                <?php foreach ($preview['blocked'] as $blocked): ?>
                    <li class="sb-backup-bad"><?= e(sprintf(__('studio_backup_preview_blocked', '%1$s cannot be restored and will be skipped: %2$s'), (string) $blocked['title'], (string) $blocked['reason'])) ?></li>
                <?php endforeach; ?>
            </ul>
            <form method="POST" action="<?= e($self) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="restore">
                <input type="hidden" name="backup_id" value="<?= e($previewId) ?>">
                <label class="sb-backup-check"><input type="checkbox" name="confirm" value="1" required> <?= e(__('studio_backup_confirm', 'I understand this changes the live site. A safety backup is made first.')) ?></label>
                <div class="sb-card-actions">
                    <a class="btn btn-outline" href="<?= e($self) ?>"><?= e(__('cancel', 'Cancel')) ?></a>
                    <button type="submit" class="btn btn-primary"><?= sb_svg('refresh', 14) ?> <?= e(__('studio_backup_restore_now', 'Restore this backup')) ?></button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="sb-form-grid sb-backup-actions">
        <form class="sb-card" method="POST" action="<?= e($self) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="create">
            <div class="sb-card__head"><div class="sb-card__title"><?= sb_svg('save', 18) ?> <?= e(__('studio_backup_make', 'Make a backup')) ?></div></div>
            <label class="sb-backup-check"><input type="checkbox" name="with_media" value="1" checked> <?= e(__('studio_backup_with_media', 'Include media files')) ?></label>
            <p class="sb-field__hint"><?= e(__('studio_backup_with_media_hint', 'Needed to move the site. Without them the file is much smaller, and images must already be on the site you restore to.')) ?></p>
            <div class="sb-card-actions"><button type="submit" class="btn btn-primary"<?= $canZip ? '' : ' disabled' ?>><?= e(__('studio_backup_make_now', 'Make backup')) ?></button></div>
        </form>
        <form class="sb-card" method="POST" action="<?= e($self) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="upload">
            <div class="sb-card__head"><div class="sb-card__title"><?= sb_svg('upload', 18) ?> <?= e(__('studio_backup_upload', 'Restore from a file')) ?></div></div>
            <input type="file" name="backup_file" accept=".zip,application/zip" class="form-control" required>
            <p class="sb-field__hint"><?= e(sprintf(__('studio_backup_upload_hint', 'You see what it would do before anything changes. Largest file: %s.'), $sbSize($maxUpload))) ?></p>
            <div class="sb-card-actions"><button type="submit" class="btn btn-outline"<?= $canZip ? '' : ' disabled' ?>><?= e(__('studio_backup_upload_now', 'Upload and check')) ?></button></div>
        </form>
    </div>

    <?php $saved = $store->all(); ?>
    <?php if ($saved === []): ?>
        <div class="card sb-backup-empty"><?= e(__('studio_backup_empty', 'No backups yet.')) ?></div>
    <?php else: ?>
        <div class="data-list is-columnar" data-single-open>
            <?php
            slate_data_list_head(['main' => __('studio_backup_col_date', 'Backup'), 'value' => __('studio_backup_col_size', 'Size'), 'status' => __('studio_backup_col_kind', 'Kind')]);
            foreach ($saved as $backup) {
                $actions = '<a class="btn btn-sm btn-outline" href="' . e($self . '?download=' . rawurlencode($backup['id'])) . '">' . e(__('studio_backup_download', 'Download')) . '</a>'
                    . ' <a class="btn btn-sm btn-outline" href="' . e($self . '?preview=' . rawurlencode($backup['id'])) . '">' . e(__('studio_backup_restore', 'Restore')) . '</a>'
                    . ' <form method="POST" action="' . e($self) . '" style="display:inline" onsubmit="return confirm(' . e((string) json_encode(__('studio_backup_delete_confirm', 'Delete this backup?'))) . ');">'
                    . csrf_field()
                    . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="backup_id" value="' . e($backup['id']) . '">'
                    . '<button type="submit" class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button></form>';
                slate_data_row([
                    'avatar'       => $backup['kind'] === StudioBackupStore::KIND_MANUAL ? 'B' : ($backup['kind'] === StudioBackupStore::KIND_SAFETY ? 'S' : 'U'),
                    'avatar_color' => $backup['kind'] === StudioBackupStore::KIND_MANUAL ? 'success' : 'info',
                    'title'        => date('M j, Y H:i', $backup['created_at']),
                    'value'        => $sbSize($backup['size']),
                    'badge'        => [$kinds[$backup['kind']] ?? $backup['kind'], $backup['kind'] === StudioBackupStore::KIND_MANUAL ? 'active' : 'info'],
                    'detail'       => ['file' => ['label' => __('studio_backup_col_file', 'File'), 'html' => '<code>' . e($backup['id']) . '</code>']],
                    'actions'      => $actions,
                ]);
            }
            ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
