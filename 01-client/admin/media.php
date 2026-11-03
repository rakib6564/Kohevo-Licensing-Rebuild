<?php
/**
 * Slate — Media library (core).
 *
 * Shared, WordPress-style media library used by every plugin. Promoted
 * from the former media-library plugin. Backed by the core Media
 * service (includes/Media.php).
 *
 * Routes:
 *   GET  ?                       → grid of media (filter tabs + search + pages)
 *   POST _action=upload          → upload one or more files (images + docs)
 *   POST _action=delete          → delete one file by id (refused if in use)
 *   POST _action=import          → scan known /uploads folders for new files
 *   POST _action=update_meta     → save alt text, title and description of one file
 *   POST _action=replace         → swap a file for a new upload of the same type (keeps id and references)
 *   POST _action=edit_image      → rotate, flip or crop an image, in place or as a new file
 *   POST _action=generate_sizes  → make WebP sizes for images uploaded before sizes existed (one batch)
 *   POST _action=image_settings  → turn image sizes / photo clean-up on or off
 *   POST _action=delete          → move one file to the trash (refused if in use)
 *   POST _action=restore|purge   → take one file out of the trash / delete it for good
 *   POST _action=empty_trash     → delete every trashed file for good
 *   POST _action=bulk            → bulk_op move|tag|trash|restore|purge on ids[]
 *   POST _action=new_folder|rename_folder|delete_folder → library folders (labels; files never move on disk)
 *   POST _action=import_url      → download one file from a public web address into the library
 *
 * With JavaScript the upload bar sends files in pieces through admin/media-upload.php (no server upload limit),
 * then reloads with ?uploaded=N&dupes=N; the plain form above is the fallback.
 *
 * Query:
 *   ?type=all|image|video|audio|document   ?usage=all|in_use|unused   ?q=…
 *   ?sort=newest|oldest|name|size    ?page=N    ?id=N (opens the details panel)
 *   ?view=grid|large|small|list      (remembered in the media_view cookie)
 *   ?folder=none|N   ?tag=N   ?status=noalt|trash
 */
require __DIR__ . '/../config.php';
Auth::require();
Auth::requirePerm('media.view');

Media::ensureSchema();

$pageTitle  = __('media_library', 'Media library');
$currentNav = 'media';

$canUpload = Auth::can('media.upload') || Auth::isSuperAdmin();
$canDelete = Auth::can('media.delete') || Auth::isSuperAdmin();
$canSettings = Auth::can('settings.edit') || Auth::isSuperAdmin();
$continueSizes = false;

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'upload') {
            if (!$canUpload) {
                $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to upload.')];
            } else {
                $ok = 0; $dupes = 0; $errors = [];
                $uploadFolder = max(0, (int)($_POST['folder_id'] ?? 0));
                if (!empty($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
                    $n = count($_FILES['files']['name']);
                    for ($i = 0; $i < $n; $i++) {
                        $err = $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                        if ($err === UPLOAD_ERR_NO_FILE) continue;
                        $label = $_FILES['files']['name'][$i] ?? "file $i";
                        if ($err !== UPLOAD_ERR_OK) { $errors[] = "$label: upload error $err"; continue; }
                        $_FILES['__media_one'] = [
                            'name'     => $_FILES['files']['name'][$i],
                            'type'     => $_FILES['files']['type'][$i],
                            'tmp_name' => $_FILES['files']['tmp_name'][$i],
                            'error'    => $_FILES['files']['error'][$i],
                            'size'     => $_FILES['files']['size'][$i],
                        ];
                        try {
                            $r = Media::upload('__media_one', ['folder_id' => $uploadFolder]);
                            if (!empty($r['duplicate'])) $dupes++;
                            elseif (!empty($r['id']) || !empty($r['path'])) $ok++;
                            else $errors[] = $label . ': ' . ($r['error'] ?? 'unknown error');
                        } catch (\Throwable $e) {
                            $errors[] = $label . ': ' . $e->getMessage();
                        }
                        unset($_FILES['__media_one']);
                    }
                }
                if ($ok > 0 || $dupes > 0) {
                    $msg = $ok > 0 ? sprintf(__('uploaded_n', '%d file(s) uploaded.'), $ok) : '';
                    if ($dupes > 0) $msg = trim($msg . ' ' . sprintf(__('media_dupes_n', '%d file(s) were already in the library, so the existing copy was kept.'), $dupes));
                    if ($errors) { $msg .= ' ' . sprintf(__('with_errors', 'With %d error(s).'), count($errors)); $GLOBALS['_upload_errors'] = $errors; }
                    $flash = ['type' => 'success', 'msg' => $msg];
                } elseif ($errors) {
                    $flash = ['type' => 'error', 'msg' => __('uploads_failed', 'No files were uploaded.')];
                    $GLOBALS['_upload_errors'] = $errors;
                } else {
                    $flash = ['type' => 'error', 'msg' => __('no_files', 'Please select at least one file.')];
                }
            }
        }

        elseif (in_array($action, ['delete', 'restore', 'purge', 'empty_trash', 'bulk'], true) && !$canDelete && !($action === 'bulk' && in_array($_POST['bulk_op'] ?? '', ['move', 'tag'], true) && $canUpload)) {
            $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to delete.')];
        }

        elseif ($action === 'delete') {
            // Delete moves to the trash; files stay restorable for Media::TRASH_DAYS days.
            $r = Media::trash([(int)($_POST['id'] ?? 0)]);
            $flash = $r['trashed'] > 0
                ? ['type' => 'success', 'msg' => sprintf(__('media_trashed_one', 'Moved to the trash. It is deleted for good after %d days.'), Media::TRASH_DAYS)]
                : ['type' => 'error', 'msg' => __('media_trash_refused', 'This file is in use, so it cannot be deleted. Remove it from those pages first.')];
        }

        elseif ($action === 'restore') {
            $flash = Media::restore([(int)($_POST['id'] ?? 0)]) > 0
                ? ['type' => 'success', 'msg' => __('media_restored', 'Restored from the trash.')]
                : ['type' => 'error', 'msg' => __('media_restore_failed', 'The file could not be restored.')];
        }

        elseif ($action === 'purge') {
            $id = (int)($_POST['id'] ?? 0);
            $rec = Media::get($id);
            $r = $rec && $rec['deleted_at'] !== null ? Media::delete($id) : ['ok' => false, 'error' => __('media_purge_not_trashed', 'Only files in the trash can be deleted for good.')];
            $flash = !empty($r['ok'])
                ? ['type' => 'success', 'msg' => __('file_deleted', 'File deleted.')]
                : ['type' => 'error', 'msg' => (string)($r['error'] ?? '')];
        }

        elseif ($action === 'empty_trash') {
            $n = Media::purgeTrash(0);
            $flash = ['type' => 'success', 'msg' => sprintf(__('media_trash_emptied', '%d file(s) deleted for good.'), $n)];
        }

        elseif ($action === 'bulk') {
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
            $op  = (string)($_POST['bulk_op'] ?? '');
            if ($ids === []) {
                $flash = ['type' => 'error', 'msg' => __('media_bulk_none', 'Select at least one file.')];
            } elseif ($op === 'move') {
                $target = (string)($_POST['target_folder'] ?? '');
                $n = Media::moveToFolder($ids, $target === '' || $target === 'none' ? null : (int)$target);
                $flash = ['type' => 'success', 'msg' => sprintf(__('media_moved_n', '%d file(s) moved.'), $n)];
            } elseif ($op === 'tag') {
                $n = Media::addTags($ids, (string)($_POST['bulk_tags'] ?? ''));
                $flash = $n > 0
                    ? ['type' => 'success', 'msg' => sprintf(__('media_tagged_n', 'Tags added to %d file(s).'), $n)]
                    : ['type' => 'error', 'msg' => __('media_tag_none', 'Type a tag to add.')];
            } elseif ($op === 'trash') {
                $r = Media::trash($ids);
                $msg = sprintf(__('media_trashed_n', '%d file(s) moved to the trash.'), $r['trashed']);
                if ($r['refused'] > 0) $msg .= ' ' . sprintf(__('media_trash_refused_n', '%d in use, so kept.'), $r['refused']);
                $flash = ['type' => $r['trashed'] > 0 ? 'success' : 'error', 'msg' => $msg];
            } elseif ($op === 'restore') {
                $flash = ['type' => 'success', 'msg' => sprintf(__('media_restored_n', '%d file(s) restored.'), Media::restore($ids))];
            } elseif ($op === 'purge') {
                $n = 0;
                foreach ($ids as $id) {
                    $rec = Media::get($id);
                    if ($rec && $rec['deleted_at'] !== null && !empty(Media::delete($id)['ok'])) $n++;
                }
                $flash = ['type' => 'success', 'msg' => sprintf(__('media_trash_emptied', '%d file(s) deleted for good.'), $n)];
            }
        }

        elseif (in_array($action, ['new_folder', 'rename_folder', 'delete_folder'], true) && !$canUpload) {
            $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to edit media.')];
        }

        elseif ($action === 'new_folder') {
            $parent = (int)($_POST['parent_id'] ?? 0);
            $newId = Media::createFolder((string)($_POST['name'] ?? ''), $parent > 0 ? $parent : null);
            if ($newId > 0) {
                $_GET['folder'] = (string)$newId;
                $flash = ['type' => 'success', 'msg' => __('media_folder_created', 'Folder created.')];
            } else {
                $flash = ['type' => 'error', 'msg' => __('media_folder_name', 'Give the folder a name.')];
            }
        }

        elseif ($action === 'rename_folder') {
            $flash = Media::renameFolder((int)($_POST['folder_id'] ?? 0), (string)($_POST['name'] ?? ''))
                ? ['type' => 'success', 'msg' => __('media_folder_renamed', 'Folder renamed.')]
                : ['type' => 'error', 'msg' => __('media_folder_name', 'Give the folder a name.')];
        }

        elseif ($action === 'delete_folder') {
            if (Media::deleteFolder((int)($_POST['folder_id'] ?? 0))) {
                $_GET['folder'] = 'all';
                $flash = ['type' => 'success', 'msg' => __('media_folder_deleted', 'Folder removed. Its files moved up one level; none were deleted.')];
            } else {
                $flash = ['type' => 'error', 'msg' => __('media_folder_missing', 'That folder no longer exists.')];
            }
        }

        elseif ($action === 'update_meta') {
            if (!$canUpload) {
                $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to edit media.')];
            } else {
                $r = Media::updateMeta((int)($_POST['id'] ?? 0), [
                    'alt_text'    => mb_substr((string)($_POST['alt_text'] ?? ''), 0, 255),
                    'title'       => mb_substr((string)($_POST['title'] ?? ''), 0, 255),
                    'description' => mb_substr((string)($_POST['description'] ?? ''), 0, 5000),
                ]);
                if (!empty($r['ok'])) {
                    $metaId = (int)($_POST['id'] ?? 0);
                    if (array_key_exists('tags', $_POST)) Media::setTags($metaId, (string)$_POST['tags']);
                    if (array_key_exists('folder_id', $_POST)) {
                        $f = (string)$_POST['folder_id'];
                        Media::moveToFolder([$metaId], $f === '' ? null : (int)$f);
                    }
                    AuditLog::record('media.updated', (string)($r['item']['path'] ?? ''), ['id' => $metaId]);
                }
                $flash = !empty($r['ok'])
                    ? ['type' => 'success', 'msg' => __('media_details_saved', 'Details saved.')]
                    : ['type' => 'error', 'msg' => __('media_details_not_saved', 'The details could not be saved.')];
            }
        }

        elseif ($action === 'replace') {
            if (!$canUpload) {
                $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to edit media.')];
            } else {
                $r = Media::replaceFile((int)($_POST['id'] ?? 0), 'file');
                $flash = !empty($r['ok'])
                    ? ['type' => 'success', 'msg' => __('media_replaced', 'File replaced. Every page using it now shows the new file.')]
                    : ['type' => 'error', 'msg' => (string)($r['error'] ?? __('media_replace_failed', 'The file could not be replaced.'))];
            }
        }

        elseif ($action === 'edit_image') {
            if (!$canUpload) {
                $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to edit media.')];
            } else {
                $op  = (string)($_POST['op'] ?? '');
                $ops = match ($op) {
                    'rotate_left'  => ['rotate' => 270],
                    'rotate_right' => ['rotate' => 90],
                    'flip_h'       => ['flip' => 'h'],
                    'flip_v'       => ['flip' => 'v'],
                    'crop'         => ['crop' => [$_POST['x'] ?? 0, $_POST['y'] ?? 0, $_POST['w'] ?? 0, $_POST['h'] ?? 0]],
                    default        => [],
                };
                $asCopy = !empty($_POST['as_copy']);
                $r = $ops === [] ? ['ok' => false] : Media::editImage((int)($_POST['id'] ?? 0), $ops, $asCopy);
                if (!empty($r['ok'])) {
                    $flash = ['type' => 'success', 'msg' => $asCopy ? __('media_edited_copy', 'Saved as a new file.') : __('media_edited', 'Image updated.')];
                    // A copy opens in the panel; an in-place edit stays where it is.
                    if ($asCopy && !empty($r['item']['id'])) $_GET['id'] = (int)$r['item']['id'];
                } else {
                    $flash = ['type' => 'error', 'msg' => (string)($r['error'] ?? __('media_edit_failed', 'The image could not be edited.'))];
                }
            }
        }

        elseif ($action === 'generate_sizes') {
            if (!$canUpload) {
                $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to edit media.')];
            } else {
                $r = Media::generateMissing(20);
                $flash = ['type' => 'success', 'msg' => sprintf(
                    __('media_sizes_batch', 'Checked %1$d image(s), made %2$d resized cop(ies). %3$d left.'),
                    (int)$r['done'], (int)$r['files'], (int)$r['left'])];
                $continueSizes = !empty($_POST['continue']) && $r['left'] > 0 && $r['done'] > 0;
            }
        }

        elseif ($action === 'image_settings') {
            if (!$canSettings) {
                $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to change settings.')];
            } else {
                Database::setSetting('media_image_sizes', empty($_POST['image_sizes']) ? '0' : '1');
                Database::setSetting('media_strip_metadata', empty($_POST['strip_metadata']) ? '0' : '1');
                AuditLog::record('media.settings', '', ['image_sizes' => !empty($_POST['image_sizes']), 'strip_metadata' => !empty($_POST['strip_metadata'])]);
                $flash = ['type' => 'success', 'msg' => __('media_settings_saved', 'Image settings saved.')];
            }
        }

        elseif ($action === 'import_url') {
            if (!$canUpload) {
                $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to upload.')];
            } else {
                $r = Media::importUrl(trim((string)($_POST['url'] ?? '')), ['folder_id' => max(0, (int)($_POST['folder_id'] ?? 0))]);
                if (!empty($r['id'])) {
                    $flash = ['type' => 'success', 'msg' => !empty($r['duplicate'])
                        ? __('media_url_dupe', 'That file was already in the library, so the existing copy was kept.')
                        : sprintf(__('media_url_done', 'Imported %s.'), (string)$r['original_name'])];
                    $_GET['id'] = (string)(int)$r['id'];
                } else {
                    $flash = ['type' => 'error', 'msg' => (string)($r['error'] ?? __('media_url_err_fetch', 'The file could not be downloaded.'))];
                }
            }
        }

        elseif ($action === 'import') {
            if (!$canUpload) {
                $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to import.')];
            } else {
                $r = Media::importKnown();
                AuditLog::record('media.imported', '', $r);
                $flash = ['type' => 'success', 'msg' => sprintf(
                    __('media_imported', '%d new file(s) imported (%d scanned).'),
                    (int)$r['added'], (int)$r['scanned'])];
            }
        }
    }
}

// ── Listing ──────────────────────────────────────────────────
$type  = (string)($_GET['type']  ?? 'all');
$usage = (string)($_GET['usage'] ?? 'all');
$q     = trim((string)($_GET['q'] ?? ''));
$page  = max(1, (int)($_GET['page'] ?? 1));
$sort  = (string)($_GET['sort'] ?? 'newest');
$selId = max(0, (int)($_GET['id'] ?? 0));
if (!in_array($type,  ['all', 'image', 'video', 'audio', 'document'], true)) $type  = 'all';
// After a piece-by-piece upload (JavaScript) the page reloads with what happened.
if ($flash === null && isset($_GET['uploaded'])) {
    $upN = max(0, (int)$_GET['uploaded']); $dupN = max(0, (int)($_GET['dupes'] ?? 0));
    $msg = $upN > 0 ? sprintf(__('uploaded_n', '%d file(s) uploaded.'), $upN) : '';
    if ($dupN > 0) $msg = trim($msg . ' ' . sprintf(__('media_dupes_n', '%d file(s) were already in the library, so the existing copy was kept.'), $dupN));
    if ($msg !== '') $flash = ['type' => 'success', 'msg' => $msg];
}
if (!in_array($usage, ['all', 'in_use', 'unused'],  true)) $usage = 'all';
if (!in_array($sort,  ['newest', 'oldest', 'name', 'size'], true)) $sort = 'newest';
$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['noalt', 'trash'], true)) $status = '';
$folders  = Media::folders();
$folderIds = array_column($folders, 'id');
$folderParam = (string)($_GET['folder'] ?? 'all');
$folder = $folderParam === 'none' ? 'none' : (in_array((int)$folderParam, $folderIds, true) ? (int)$folderParam : 'all');
$tags  = Media::tags();
$tagId = (int)($_GET['tag'] ?? 0);
if (!in_array($tagId, array_column($tags, 'id'), true)) $tagId = 0;
$folderName = is_int($folder) ? (string)(array_values(array_filter($folders, fn($f) => $f['id'] === $folder))[0]['name'] ?? '') : '';

// View: grid (default), large tiles, small tiles or a list. A chosen view is remembered per browser.
$views = ['grid', 'large', 'small', 'list'];
$view  = (string)($_GET['view'] ?? $_COOKIE['media_view'] ?? 'grid');
if (!in_array($view, $views, true)) $view = 'grid';
if (isset($_GET['view']) && ($_COOKIE['media_view'] ?? '') !== $view) {
    setcookie('media_view', $view, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true]);
}
$perPage = ['grid' => 24, 'large' => 24, 'small' => 48, 'list' => 50][$view];

$res    = Media::listAll([
    'type' => $type, 'usage' => $usage, 'search' => $q, 'sort' => $sort, 'page' => $page, 'per_page' => $perPage,
    'folder' => $folder, 'tag' => $tagId, 'alt' => $status === 'noalt' ? 'missing' : '', 'trash' => $status === 'trash',
]);
$counts = Media::counts();
$items  = $res['items'];

// The details panel: the chosen file and every place that uses it.
$sel      = $selId > 0 ? Media::get($selId) : null;
$selUsage = $sel ? Media::usageDetails($selId) : [];

require __DIR__ . '/partials/header.php';

$csrf = csrf_token();

$fmtSize = static function (int $b): string {
    $u = ['B', 'KB', 'MB', 'GB']; $s = (float)$b; $i = 0;
    while ($s >= 1024 && $i < count($u) - 1) { $s /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$s : number_format($s, 1)) . ' ' . $u[$i];
};
$tabUrl = static function (array $over) use ($type, $usage, $q, $sort, $folder, $tagId, $status): string {
    $p = array_merge(['folder' => $folder, 'tag' => $tagId ?: null, 'status' => $status, 'type' => $type, 'usage' => $usage, 'q' => $q, 'sort' => $sort], $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== 'all' && $v !== 'newest' && $v !== 0 && $v !== null);
    return '?' . http_build_query($p);
};
$selUrl = static fn(int $id): string => $tabUrl(['id' => $id, 'page' => $page > 1 ? $page : null]);
$closeUrl = $tabUrl(['page' => $page > 1 ? $page : null]);
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('media_library', 'Media library')],
]); ?>

<style>
/* Media library — modern, compact, consistent with the plugins page. */
.media-wrap { display: flex; flex-direction: column; gap: 14px; }
.media-hero {
    position: relative; overflow: hidden;
    border: 1px solid var(--border); border-radius: var(--radius-xl);
    background: radial-gradient(120% 140% at 100% 0%, rgba(37,99,235,0.10), transparent 55%),
                linear-gradient(180deg, #0E1117, #161A22);
    color: #fff; padding: 16px 20px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
}
.media-hero h1 { margin: 0; font-size: 19px; font-weight: 700; letter-spacing: -0.02em; color: #fff; }
.media-hero p { margin: 4px 0 0; color: rgba(255,255,255,0.6); font-size: 12.5px; }
.media-hero-counts { display: inline-flex; }
.media-hc { padding: 2px 16px; text-align: center; }
.media-hc + .media-hc { border-left: 1px solid rgba(255,255,255,0.10); }
.media-hc-v { font-size: 18px; font-weight: 700; color: #fff; font-variant-numeric: tabular-nums; }
.media-hc-k { font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(255,255,255,0.5); margin-top: 2px; }

.media-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.media-tabs { display: inline-flex; align-items: center; gap: 2px; padding: 3px; border: 1px solid var(--border); background: var(--surface-2); border-radius: var(--radius-full); flex-wrap: wrap; }
.media-tab { display: inline-flex; align-items: center; gap: 6px; font: inherit; font-size: 12.5px; font-weight: 600; color: var(--muted); padding: 6px 12px; border: 0; background: transparent; border-radius: var(--radius-full); cursor: pointer; text-decoration: none; line-height: 1; }
.media-tab:hover { color: var(--text); text-decoration: none; }
.media-tab.is-on { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
.media-tab-n { font-family: var(--font-mono); font-size: 10.5px; color: var(--muted); background: var(--surface-sunken); border-radius: 999px; padding: 1px 6px; min-width: 18px; text-align: center; }
.media-tab.is-on .media-tab-n { background: var(--accent-soft); color: var(--accent); }
.media-search { margin-left: auto; display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px; border: 1px solid var(--border); border-radius: var(--radius-full); background: var(--surface); color: var(--subtle); min-width: 190px; }
.media-search:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--ring); }
.media-search svg { width: 15px; height: 15px; flex: none; }
.media-search input { border: 0; outline: none; background: transparent; font: inherit; font-size: 12.5px; color: var(--text); width: 100%; min-width: 0; }

.media-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
.media-card { position: relative; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface); overflow: hidden; transition: border-color .14s ease, box-shadow .14s ease, transform .14s ease; }
.media-card:hover { border-color: var(--border-stronger); box-shadow: var(--shadow-md); transform: translateY(-1px); }
.media-thumb { aspect-ratio: 1; background: var(--surface-2); display: flex; align-items: center; justify-content: center; }
.media-thumb img, .media-thumb video { max-width: 100%; max-height: 100%; object-fit: contain; }
.media-doc { display: flex; flex-direction: column; align-items: center; gap: 6px; color: var(--muted); }
.media-doc-ext { font-family: var(--font-mono); font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--accent); }
.media-meta { padding: 8px 10px; }
.media-name { font-size: 12px; font-weight: 600; word-break: break-all; line-height: 1.3; }
.media-name a { color: inherit; text-decoration: none; }
.media-name a:hover { color: var(--accent); }
.media-sub { color: var(--muted); font-size: 10.5px; margin-top: 3px; }
.media-row { display: flex; align-items: center; gap: 6px; margin-top: 8px; }
.media-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 600; padding: 2px 7px 2px 6px; border-radius: 999px; }
.media-badge::before { content: ""; width: 5px; height: 5px; border-radius: 999px; }
.media-badge.is-on { background: var(--success-soft); color: #166534; }
.media-badge.is-on::before { background: var(--success); }
.media-badge.is-off { background: var(--surface-2); color: var(--muted); border: 1px solid var(--border); }
.media-badge.is-off::before { background: var(--subtle); }
.media-spacer { flex: 1; }
.media-ico-btn { border: 1px solid var(--border-strong); background: var(--surface); color: var(--text-2); border-radius: var(--radius-sm); width: 26px; height: 26px; display: grid; place-items: center; cursor: pointer; padding: 0; }
.media-ico-btn:hover { background: var(--surface-2); color: var(--text); }
.media-ico-btn.is-danger:hover { background: var(--danger-soft); border-color: var(--danger-soft); color: var(--danger); }
.media-ico-btn svg { width: 14px; height: 14px; }

.media-uploadbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface); padding: 12px 14px; }
.media-uploadbar form { display: flex; align-items: center; gap: 8px; margin: 0; flex-wrap: wrap; }
.media-uploadbar input[type=file] { font-size: 12.5px; }
.media-empty { border: 1px dashed var(--border-stronger); border-radius: var(--radius-xl); background: var(--surface); padding: 40px 22px; text-align: center; color: var(--muted); }
.media-empty h3 { margin: 0 0 4px; font-size: 16px; font-weight: 650; color: var(--text); }
.media-sort { font: inherit; font-size: 12.5px; padding: 6px 30px 6px 12px; min-width: 0; width: auto; border: 1px solid var(--border); border-radius: var(--radius-full); background: var(--surface); color: var(--text); }
.media-sortform { margin: 0; display: inline-flex; }
.media-layout { display: grid; gap: 14px; grid-template-columns: minmax(0, 1fr); align-items: start; }
.media-layout.has-panel { grid-template-columns: minmax(0, 1fr) 340px; }
.media-pick { display: block; color: inherit; text-decoration: none; }
.media-pick:hover { text-decoration: none; }
.media-card.is-sel { border-color: var(--accent); box-shadow: 0 0 0 2px var(--ring); }
.media-panel { position: sticky; top: 14px; border: 1px solid var(--border); border-radius: var(--radius-xl); background: var(--surface); overflow: hidden; }
.media-panel-head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid var(--border); }
.media-panel-head h2 { margin: 0; font-size: 13.5px; font-weight: 650; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.media-panel-prev { background: var(--surface-2); display: flex; align-items: center; justify-content: center; max-height: 260px; min-height: 120px; }
.media-panel-prev img, .media-panel-prev video { max-width: 100%; max-height: 260px; object-fit: contain; display: block; }
.media-panel-body { padding: 12px; display: flex; flex-direction: column; gap: 12px; }
.media-facts { display: grid; grid-template-columns: auto 1fr; gap: 4px 10px; margin: 0; font-size: 12px; }
.media-facts dt { color: var(--muted); }
.media-facts dd { margin: 0; word-break: break-all; }
.media-panel label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; }
.media-panel input[type=text], .media-panel textarea { width: 100%; box-sizing: border-box; font: inherit; font-size: 12.5px; padding: 7px 9px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); }
.media-panel textarea { min-height: 64px; resize: vertical; }
.media-hint { color: var(--muted); font-size: 11px; margin-top: 3px; }
.media-hint.is-warn { color: var(--warning, #b45309); }
.media-panel h3 { margin: 0 0 6px; font-size: 12px; font-weight: 650; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
.media-used { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; font-size: 12.5px; }
.media-used li { display: flex; gap: 6px; align-items: baseline; }
.media-used-src { font-family: var(--font-mono); font-size: 10px; text-transform: uppercase; color: var(--muted); flex: none; }
.media-panel-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.media-views { display: inline-flex; gap: 2px; padding: 3px; border: 1px solid var(--border); background: var(--surface-2); border-radius: var(--radius-full); flex: none; }
.media-view { display: grid; place-items: center; width: 28px; height: 26px; border-radius: var(--radius-full); color: var(--muted); }
.media-view:hover { color: var(--text); }
.media-view.is-on { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
.media-view svg { width: 15px; height: 15px; }

/* Large and small tiles */
.media-grid.view-large { grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; }
.media-grid.view-large .media-thumb { aspect-ratio: 4 / 3; }
.media-grid.view-small { grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 8px; }
.media-grid.view-small .media-meta { padding: 5px 7px 6px; }
.media-grid.view-small .media-name { font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; word-break: normal; }
.media-grid.view-small .media-sub, .media-grid.view-small .media-row { display: none; }

/* List: one row per file with its facts in columns */
.media-grid.view-list { display: flex; flex-direction: column; gap: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface); overflow: hidden; }
.media-list-head, .view-list .media-card { display: grid; grid-template-columns: 56px minmax(0, 1fr) 64px 96px 76px 92px 150px; align-items: center; gap: 12px; padding: 0 12px; }
.media-list-head { padding-top: 8px; padding-bottom: 8px; background: var(--surface-2); border-bottom: 1px solid var(--border); font-family: var(--font-mono); font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
.view-list .media-card { border: 0; border-bottom: 1px solid var(--border); border-radius: 0; box-shadow: none; transform: none; min-height: 58px; }
.view-list .media-card:last-child { border-bottom: 0; }
.view-list .media-card:hover { background: var(--surface-2); box-shadow: none; transform: none; }
.view-list .media-card.is-sel { background: var(--accent-soft); box-shadow: inset 3px 0 0 var(--accent); }
.view-list .media-thumb { width: 44px; height: 44px; aspect-ratio: auto; border-radius: var(--radius-sm); overflow: hidden; }
.view-list .media-thumb img, .view-list .media-thumb video { width: 100%; height: 100%; object-fit: cover; }
.view-list .media-doc svg { width: 18px; height: 18px; }
.view-list .media-doc { gap: 0; }
.view-list .media-doc-ext { font-size: 8.5px; }
.view-list .media-meta { display: contents; }
.view-list .media-sub { display: none; }
.view-list .media-name { font-size: 12.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; word-break: normal; }
.view-list .media-col { font-size: 12px; color: var(--text-2); font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.view-list .media-col-type { font-family: var(--font-mono); font-size: 10.5px; color: var(--muted); }
.view-list .media-row { margin: 0; justify-content: flex-end; }
.view-list .media-row .media-spacer { display: none; }
.media-layout.has-panel .media-list-head, .media-layout.has-panel .view-list .media-card { grid-template-columns: 56px minmax(0, 1fr) 76px 150px; }
.media-layout.has-panel .view-list .media-col-type, .media-layout.has-panel .view-list .media-col-dim, .media-layout.has-panel .view-list .media-col-date { display: none; }

.media-settings { flex-basis: 100%; font-size: 12.5px; }
.media-settings summary { cursor: pointer; font-weight: 600; color: var(--text-2); display: inline-flex; align-items: center; gap: 6px; }
.media-settings-body { display: flex; flex-direction: column; gap: 10px; padding-top: 10px; }
.media-settings-form { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; margin: 0; }
.media-settings-form label { display: flex; gap: 8px; align-items: flex-start; font-weight: 400; line-height: 1.35; }
.media-progress { display: flex; align-items: center; gap: 8px; min-width: 200px; flex: 1 1 200px; font-size: 12px; color: var(--text-2); }
.media-progress[hidden] { display: none; }
.media-progress-bar { flex: 1; height: 6px; border-radius: 99px; background: var(--surface-2, rgba(0,0,0,.08)); overflow: hidden; }
.media-progress-bar span { display: block; height: 100%; width: 0; background: var(--primary, #4f46e5); transition: width .15s; }
.media-progress-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px; }
.media-urlimport { position: relative; }
.media-urlimport summary { list-style: none; }
.media-urlimport summary::-webkit-details-marker { display: none; }
.media-urlimport-form { position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; display: flex; gap: 6px; padding: 10px; width: min(420px, calc(100vw - 32px)); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: 0 8px 24px rgba(0,0,0,.12); margin: 0; }
.media-urlimport-form .input { flex: 1; min-width: 0; }
.media-storage-bar { display: flex; height: 10px; border-radius: 99px; overflow: hidden; background: var(--surface-2, rgba(0,0,0,.08)); max-width: 520px; }
.media-storage-bar span { height: 100%; }
.media-storage-list { display: grid; grid-template-columns: max-content max-content; gap: 4px 18px; margin: 0; }
.media-storage-list dt { display: flex; align-items: center; gap: 6px; color: var(--text-2); }
.media-storage-list dd { margin: 0; font-variant-numeric: tabular-nums; }
.media-storage-list i { width: 9px; height: 9px; border-radius: 3px; display: inline-block; }
.media-storage .is-image { background: #4f7fe0; } .media-storage .is-video { background: #9b59d0; } .media-storage .is-audio { background: #e0912f; }
.media-storage .is-doc { background: #3aa57a; } .media-storage .is-sizes { background: #9aa6b8; } .media-storage .is-trash { background: #d65b5b; }
.media-storage-big { display: flex; flex-direction: column; gap: 2px; max-width: 520px; }
.media-storage-big a { display: flex; justify-content: space-between; gap: 12px; padding: 3px 0; border-bottom: 1px dashed var(--border); color: inherit; text-decoration: none; }
.media-storage-big a span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.media-storage-big a span:last-child { white-space: nowrap; color: var(--text-2); }
.media-storage-big a:hover span:first-child { text-decoration: underline; }
.media-panel-prev audio { width: 100%; }
.media-progress.is-error .media-progress-text { color: var(--danger, #c0392b); white-space: normal; max-width: none; }
.media-sizes-form { margin: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 6px; }
.media-edit-form { margin: 0; display: flex; flex-direction: column; gap: 8px; }
.media-edit-tools, .media-crop-bar { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.media-edit-tools[hidden], .media-crop-bar[hidden] { display: none; }
.media-keep { display: flex !important; gap: 6px; align-items: center; font-weight: 400 !important; font-size: 12px !important; color: var(--text-2); }
.media-replace { margin: 0; display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
.media-replace input[type=file] { font-size: 12px; max-width: 100%; }
.media-panel-prev { position: relative; }
.media-panel-prev.is-cropping img { opacity: .55; }
.media-crop-box { position: absolute; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.6), 0 0 0 9999px rgba(0,0,0,.35); cursor: move; touch-action: none; }
.media-crop-h { position: absolute; width: 14px; height: 14px; background: #fff; border: 1px solid rgba(0,0,0,.6); border-radius: 3px; }
.media-crop-h.h-nw { left: -8px; top: -8px; cursor: nwse-resize; }
.media-crop-h.h-ne { right: -8px; top: -8px; cursor: nesw-resize; }
.media-crop-h.h-sw { left: -8px; bottom: -8px; cursor: nesw-resize; }
.media-crop-h.h-se { right: -8px; bottom: -8px; cursor: nwse-resize; }
.media-panel-prev.is-cropping { overflow: hidden; }

/* Folders and tags beside the files */
.media-shell { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 14px; align-items: start; }
.media-content { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.media-side { position: sticky; top: 14px; display: flex; flex-direction: column; gap: 10px; }
.media-side-box { border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface); padding: 8px; }
.media-side-box summary { cursor: pointer; font-family: var(--font-mono); font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); padding: 4px 6px; }
.media-side-current { text-transform: none; letter-spacing: 0; font-family: inherit; font-size: 11px; color: var(--text-2); }
.media-folders { display: flex; flex-direction: column; gap: 1px; margin: 4px 0; }
.media-folder { display: flex; justify-content: space-between; align-items: center; gap: 6px; padding: 6px 10px; border-radius: var(--radius-sm); color: var(--text-2); text-decoration: none; font-size: 12.5px; }
.media-folder > span:first-child { display: inline-flex; align-items: center; gap: 6px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.media-folder svg { width: 14px; height: 14px; flex: none; color: var(--muted); }
.media-folder:hover { background: var(--surface-2); color: var(--text); text-decoration: none; }
.media-folder.is-on { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
.media-side-form { display: flex; gap: 6px; margin: 6px 0 0; }
.media-side-form input[type=text] { flex: 1; min-width: 0; font: inherit; font-size: 12px; padding: 5px 8px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); }
.media-link-danger { color: var(--danger) !important; padding-left: 4px !important; }
.media-tagcloud { display: flex; flex-wrap: wrap; gap: 4px; padding: 4px; }
.media-tagchip { font-size: 11.5px; padding: 3px 8px; border-radius: 999px; border: 1px solid var(--border); color: var(--text-2); text-decoration: none; background: var(--surface); }
.media-tagchip span { color: var(--muted); font-size: 10px; }
.media-tagchip:hover { border-color: var(--border-stronger); text-decoration: none; }
.media-tagchip.is-on { background: var(--accent-soft); border-color: var(--accent); color: var(--accent); }
/* Bulk select */
.media-card { position: relative; }
.media-check { position: absolute; top: 6px; left: 6px; z-index: 2; display: grid; place-items: center; width: 24px; height: 24px; border-radius: 6px; background: rgba(255,255,255,.92); box-shadow: 0 1px 3px rgba(0,0,0,.18); opacity: 0; transition: opacity .12s ease; cursor: pointer; }
.media-check input { margin: 0; width: 15px; height: 15px; cursor: pointer; }
.media-card:hover .media-check, .media-check:has(input:checked), .media-grid.is-selecting .media-check, .media-check:focus-within { opacity: 1; }
.media-card:has(.media-check input:checked) { border-color: var(--accent); box-shadow: 0 0 0 2px var(--ring); }
.view-list .media-check { position: static; opacity: 1; box-shadow: none; background: none; width: auto; }
.view-list .media-card { grid-template-columns: 24px 56px minmax(0, 1fr) 64px 96px 76px 92px 150px; }
.view-list .media-list-head { grid-template-columns: 24px 56px minmax(0, 1fr) 64px 96px 76px 92px 150px; }
.media-layout.has-panel .view-list .media-card, .media-layout.has-panel .media-list-head { grid-template-columns: 24px 56px minmax(0, 1fr) 76px 150px; }
.media-bulk { position: sticky; top: 8px; z-index: 5; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 12px; border: 1px solid var(--accent); border-radius: var(--radius-lg); background: var(--surface); box-shadow: var(--shadow-md); margin: 0; }
.media-bulk[hidden] { display: none; }
.media-bulk-all { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; margin-right: 4px; }
.media-bulk-group { display: inline-flex; gap: 6px; align-items: center; }
.media-bulk input[type=text] { font: inherit; font-size: 12px; padding: 5px 8px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); width: 150px; background: var(--surface); color: var(--text); }
.media-note { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface-2); font-size: 12.5px; color: var(--text-2); }
.media-note form { margin: 0; }
.media-note.is-panel { flex-direction: column; align-items: flex-start; }

.media-backdrop { display: none; }
/* Narrow screens: the grid stays in view and the details open as a bottom sheet over it. */
@media (max-width: 960px) {
    .media-shell { grid-template-columns: minmax(0, 1fr); }
    .media-side { position: static; flex-direction: row; flex-wrap: wrap; align-items: flex-start; gap: 8px; }
    .media-side-box { flex: 1 1 150px; min-width: 0; }
    .media-side-box[open] { flex-basis: 100%; }
    .media-side-box:not([open]) { padding: 4px 8px; }
    .media-layout.has-panel { grid-template-columns: minmax(0, 1fr); }
    .media-backdrop { display: block; position: fixed; inset: 0; z-index: 1000; background: rgba(15, 17, 23, 0.45); }
    .media-panel { position: fixed; left: 0; right: 0; bottom: 0; top: auto; z-index: 1001; max-height: 82vh; overflow-y: auto; overscroll-behavior: contain;
        border-radius: var(--radius-xl) var(--radius-xl) 0 0; border-bottom: 0; box-shadow: 0 -12px 32px rgba(0, 0, 0, 0.18); }
    .media-panel-head { position: sticky; top: 0; z-index: 1; background: var(--surface); }
    .media-panel-prev, .media-panel-prev img, .media-panel-prev video { max-height: 200px; }
    .media-panel-body { padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px)); }
}
@media (max-width: 600px) {
    .media-wrap { gap: 10px; }
    .media-hero { padding: 10px 14px; }
    .media-hero h1 { font-size: 16px; }
    .media-hero p, .media-hero-counts { display: none; }
    .media-uploadbar { padding: 8px 10px; gap: 8px; }
    .media-uploadbar form { flex-wrap: nowrap; min-width: 0; }
    .media-uploadbar input[type=file] { min-width: 0; max-width: 100%; font-size: 12px; }
    .media-uploadbar .media-spacer { display: none; }
    .media-toolbar { gap: 8px; }
    .media-tabs { width: 100%; box-sizing: border-box; flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
    .media-tabs::-webkit-scrollbar { display: none; }
    .media-tab { flex: none; padding: 6px 10px; }
    .media-sortform { flex: none; }
    /* The admin's phone form styles reset select padding; keep room for the arrow. */
    .media-toolbar .media-sortform select.media-sort { padding: 6px 30px 6px 12px; font-size: 14px; }
    .media-search { flex: 1 1 100%; order: 5; margin-left: 0; min-width: 0; padding: 5px 10px; }
    .media-views { margin-left: auto; }
    .media-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
    .media-card { border-radius: var(--radius-md, 8px); }
    .media-card:hover { transform: none; }
    .media-meta { padding: 5px 6px 6px; }
    .media-name { font-size: 10.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; word-break: normal; }
    .media-sub { display: none; }
    .media-row { margin-top: 4px; gap: 4px; }
    .media-badge { font-size: 9.5px; padding: 1px 5px; }
    .media-ico-btn { width: 22px; height: 22px; }
    .media-ico-btn svg { width: 12px; height: 12px; }
    .media-row .media-copy { display: none; }
    .media-views { padding: 2px; }
    .media-view { width: 26px; height: 24px; }
    .media-grid.view-large { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .media-grid.view-small { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 4px; }
    .media-grid.view-small .media-meta { display: none; }
    .media-grid.view-list { display: flex; }
    .media-list-head { display: none; }
    .media-list-head, .view-list .media-card, .media-layout.has-panel .view-list .media-card { grid-template-columns: 44px minmax(0, 1fr) auto auto; gap: 8px; padding: 0 8px; }
    .view-list .media-thumb { width: 40px; height: 40px; }
    .view-list .media-col-type, .view-list .media-col-dim, .view-list .media-col-date { display: none; }
    .view-list .media-name { font-size: 12px; }
    .view-list .media-row .media-copy { display: grid; }
    .media-check { opacity: 1; width: 22px; height: 22px; top: 4px; left: 4px; }
    .view-list .media-card, .media-layout.has-panel .view-list .media-card { grid-template-columns: 22px 44px minmax(0, 1fr) auto auto; }
    .media-bulk { top: 4px; }
    .media-bulk input[type=text] { width: 110px; }
}
</style>

<div class="media-wrap">

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if (!empty($GLOBALS['_upload_errors'])): ?>
        <div class="alert alert-error" role="status">
            <?php foreach ($GLOBALS['_upload_errors'] as $err): ?>
                <div class="text-sm"><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="media-hero">
        <div>
            <h1><?= __('media_library', 'Media library') ?></h1>
            <p><?= __('media_hero_sub', 'One shared library for every plugin — upload once, reuse anywhere.') ?></p>
        </div>
        <div class="media-hero-counts">
            <div class="media-hc"><div class="media-hc-v"><?= (int)$counts['all'] ?></div><div class="media-hc-k"><?= __('total', 'Total') ?></div></div>
            <div class="media-hc"><div class="media-hc-v"><?= (int)$counts['image'] ?></div><div class="media-hc-k"><?= __('images', 'Images') ?></div></div>
            <div class="media-hc"><div class="media-hc-v"><?= (int)$counts['document'] ?></div><div class="media-hc-k"><?= __('documents', 'Docs') ?></div></div>
        </div>
    </section>

    <?php if ($canUpload): ?>
    <div class="media-uploadbar">
        <form method="post" enctype="multipart/form-data" data-media-upload="<?= e(SLATE_URL . '/admin/media-upload.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="upload">
            <input type="hidden" name="folder_id" value="<?= is_int($folder) ? (int)$folder : 0 ?>">
            <input type="file" name="files[]" multiple
                   accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml,application/pdf,.doc,.docx,.xls,.xlsx,video/mp4,video/webm,video/ogg,video/quicktime,.mp4,.m4v,.webm,.ogv,.mov,audio/*,.mp3,.m4a,.aac,.ogg,.oga,.opus,.wav">
            <button type="submit" class="btn btn-sm btn-primary"><?= __('upload', 'Upload') ?></button>
        </form>
        <div class="media-progress" data-media-progress hidden>
            <div class="media-progress-bar"><span data-media-progress-fill></span></div>
            <span class="media-progress-text" data-media-progress-text></span>
        </div>
        <span class="media-spacer"></span>
        <details class="media-urlimport" <?= ($_POST['_action'] ?? '') === 'import_url' && $flash && $flash['type'] === 'error' ? 'open' : '' ?>>
            <summary class="btn btn-sm"><?= __('media_import_url', 'Import from URL') ?></summary>
            <form method="post" class="media-urlimport-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="import_url">
                <input type="hidden" name="folder_id" value="<?= is_int($folder) ? (int)$folder : 0 ?>">
                <input type="url" name="url" class="input" required maxlength="2000" placeholder="https://…" value="<?= e(($_POST['_action'] ?? '') === 'import_url' && $flash && $flash['type'] === 'error' ? (string)($_POST['url'] ?? '') : '') ?>" aria-label="<?= e(__('media_import_url_label', 'Web address of the file')) ?>">
                <button type="submit" class="btn btn-sm btn-primary"><?= __('media_import_btn', 'Import') ?></button>
            </form>
        </details>
        <form method="post" title="<?= e(__('media_import_hint', 'Scan upload folders for files added outside the library')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="import">
            <button type="submit" class="btn btn-sm"><?= __('import_existing', 'Import existing files') ?></button>
        </form>
        <?php
        $sizesOn = Media::sizesEnabled();
        $pendingSizes = $sizesOn ? Media::pendingVariantsCount() : 0;
        ?>
        <details class="media-settings" <?= in_array($_POST['_action'] ?? '', ['image_settings', 'generate_sizes'], true) ? 'open' : '' ?>>
            <summary><?= __('media_image_settings', 'Image settings') ?><?php if ($pendingSizes > 0): ?> <span class="media-tab-n"><?= (int)$pendingSizes ?></span><?php endif; ?></summary>
            <div class="media-settings-body">
                <?php if ($canSettings): ?>
                <form method="post" class="media-settings-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="image_settings">
                    <label><input type="checkbox" name="image_sizes" value="1" <?= $sizesOn ? 'checked' : '' ?>>
                        <?= __('media_setting_sizes', 'Make smaller WebP copies of new images (480, 960 and 1600 px wide) so pages load faster') ?></label>
                    <label><input type="checkbox" name="strip_metadata" value="1" <?= Media::stripMetadataEnabled() ? 'checked' : '' ?>>
                        <?= __('media_setting_strip', 'Turn phone photos upright and remove their hidden data (GPS location, camera details)') ?></label>
                    <button type="submit" class="btn btn-sm"><?= __('save', 'Save') ?></button>
                </form>
                <?php endif; ?>
                <?php if ($sizesOn && $pendingSizes > 0): ?>
                <form method="post" class="media-sizes-form" id="media-sizes-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="generate_sizes">
                    <input type="hidden" name="continue" value="1">
                    <p class="media-hint"><?= sprintf(__('media_sizes_pending', '%d image(s) uploaded earlier have no smaller copies yet. Each image gets up to 3 extra files.'), (int)$pendingSizes) ?></p>
                    <button type="submit" class="btn btn-sm btn-primary"><?= __('media_generate_sizes', 'Generate sizes') ?></button>
                </form>
                <?php if ($continueSizes): ?><script>setTimeout(function () { var f = document.getElementById('media-sizes-form'); if (f) f.submit(); }, 400);</script><?php endif; ?>
                <?php elseif ($sizesOn): ?>
                <p class="media-hint"><?= __('media_sizes_done', 'Every image has its smaller copies.') ?></p>
                <?php endif; ?>
            </div>
        </details>
        <?php $stats = Media::storageStats(); ?>
        <details class="media-settings media-storage">
            <summary><?= __('media_storage', 'Storage') ?> <span class="media-tab-n"><?= e($fmtSize($stats['total_bytes'])) ?></span></summary>
            <div class="media-settings-body">
                <div class="media-storage-bar" role="img" aria-label="<?= e(__('media_storage_by_type', 'Space by file type')) ?>">
                    <?php foreach (['image' => 'is-image', 'video' => 'is-video', 'audio' => 'is-audio', 'document' => 'is-doc'] as $tk => $tc):
                        $pct = $stats['total_bytes'] > 0 ? $stats['types'][$tk]['bytes'] / $stats['total_bytes'] * 100 : 0; ?>
                        <?php if ($pct > 0): ?><span class="<?= $tc ?>" style="width: <?= number_format(max(0.5, $pct), 2, '.', '') ?>%"></span><?php endif; ?>
                    <?php endforeach; ?>
                    <?php foreach (['sizes' => 'is-sizes', 'trash' => 'is-trash'] as $tk => $tc):
                        $pct = $stats['total_bytes'] > 0 ? $stats[$tk]['bytes'] / $stats['total_bytes'] * 100 : 0; ?>
                        <?php if ($pct > 0): ?><span class="<?= $tc ?>" style="width: <?= number_format(max(0.5, $pct), 2, '.', '') ?>%"></span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <dl class="media-storage-list">
                    <?php foreach (['image' => [__('images', 'Images'), 'is-image'], 'video' => [__('videos', 'Videos'), 'is-video'], 'audio' => [__('media_audio', 'Audio'), 'is-audio'], 'document' => [__('documents', 'Documents'), 'is-doc']] as $tk => [$tl, $tc]): ?>
                    <dt><i class="<?= $tc ?>"></i><?= e($tl) ?></dt><dd><?= e($fmtSize($stats['types'][$tk]['bytes'])) ?> · <?= (int)$stats['types'][$tk]['count'] ?></dd>
                    <?php endforeach; ?>
                    <dt><i class="is-sizes"></i><?= __('media_storage_sizes', 'Smaller image copies') ?></dt><dd><?= e($fmtSize($stats['sizes']['bytes'])) ?> · <?= (int)$stats['sizes']['count'] ?></dd>
                    <dt><i class="is-trash"></i><?= __('media_trash', 'Trash') ?></dt><dd><?= e($fmtSize($stats['trash']['bytes'])) ?> · <?= (int)$stats['trash']['count'] ?></dd>
                </dl>
                <p class="media-hint"><?= sprintf(__('media_storage_files', '%d files on disk. Not used anywhere: %s in %d file(s).'), (int)$stats['files_on_disk'], e($fmtSize($stats['unused']['bytes'])), (int)$stats['unused']['count']) ?></p>
                <?php if ($stats['largest']): ?>
                <div class="media-storage-big">
                    <strong><?= __('media_storage_largest', 'Largest files') ?></strong>
                    <?php foreach ($stats['largest'] as $big): ?>
                    <a href="<?= e($selUrl((int)$big['id'])) ?>"><span><?= e($big['original_name']) ?></span><span><?= e($fmtSize($big['size_bytes'])) ?><?= $big['in_use'] ? '' : ' · ' . e(__('unused', 'Unused')) ?></span></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </details>
    </div>
    <?php endif; ?>

    <div class="media-shell">
    <aside class="media-side" aria-label="<?= e(__('media_folders', 'Folders')) ?>">
        <details class="media-side-box" open>
            <summary><?= __('media_folders', 'Folders') ?><?php if ($folderName !== ''): ?><span class="media-side-current"> · <?= e($folderName) ?></span><?php endif; ?></summary>
            <nav class="media-folders">
                <a class="media-folder <?= $folder === 'all' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['folder' => 'all', 'page' => null, 'id' => null])) ?>"><span><?= __('media_all_files', 'All files') ?></span><span class="media-tab-n"><?= (int)$counts['all'] ?></span></a>
                <a class="media-folder <?= $folder === 'none' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['folder' => 'none', 'page' => null, 'id' => null])) ?>"><span><?= __('media_unfiled', 'Not in a folder') ?></span></a>
                <?php foreach ($folders as $fd): ?>
                    <a class="media-folder <?= $folder === $fd['id'] ? 'is-on' : '' ?>" style="padding-left:<?= 10 + 14 * (int)$fd['depth'] ?>px" href="<?= e($tabUrl(['folder' => $fd['id'], 'page' => null, 'id' => null])) ?>">
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg><?= e($fd['name']) ?></span>
                        <span class="media-tab-n"><?= (int)$fd['count'] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php if ($canUpload): ?>
            <form method="post" class="media-side-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="new_folder">
                <input type="hidden" name="parent_id" value="<?= is_int($folder) ? (int)$folder : 0 ?>">
                <input type="text" name="name" maxlength="120" required placeholder="<?= e(is_int($folder) ? __('media_new_subfolder', 'New folder inside this one') : __('media_new_folder', 'New folder')) ?>" aria-label="<?= e(__('media_new_folder', 'New folder')) ?>">
                <button type="submit" class="btn btn-sm"><?= __('add', 'Add') ?></button>
            </form>
            <?php if (is_int($folder)): ?>
            <form method="post" class="media-side-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="rename_folder">
                <input type="hidden" name="folder_id" value="<?= (int)$folder ?>">
                <input type="text" name="name" maxlength="120" required value="<?= e($folderName) ?>" aria-label="<?= e(__('media_folder_rename', 'Rename folder')) ?>">
                <button type="submit" class="btn btn-sm"><?= __('media_rename', 'Rename') ?></button>
            </form>
            <form method="post" class="media-side-form" onsubmit="return confirm(<?= e(json_encode(__('media_folder_delete_confirm', 'Remove this folder? Its files move up one level; none are deleted.'))) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete_folder">
                <input type="hidden" name="folder_id" value="<?= (int)$folder ?>">
                <button type="submit" class="btn btn-sm btn-ghost media-link-danger"><?= __('media_folder_remove', 'Remove folder') ?></button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </details>
        <?php if ($tags): ?>
        <details class="media-side-box" open>
            <summary><?= __('media_tags', 'Tags') ?></summary>
            <div class="media-tagcloud">
                <?php foreach ($tags as $tg): ?>
                    <a class="media-tagchip <?= $tagId === $tg['id'] ? 'is-on' : '' ?>" href="<?= e($tabUrl(['tag' => $tagId === $tg['id'] ? null : $tg['id'], 'page' => null, 'id' => null])) ?>"><?= e($tg['name']) ?> <span><?= (int)$tg['count'] ?></span></a>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </aside>
    <div class="media-content">

    <div class="media-toolbar">
        <div class="media-tabs" role="tablist">
            <a class="media-tab <?= $status === '' && $type === 'all' && $usage === 'all' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'all', 'usage' => 'all', 'status' => '', 'id' => null])) ?>"><?= __('all', 'All') ?> <span class="media-tab-n"><?= (int)$counts['all'] ?></span></a>
            <a class="media-tab <?= $status === '' && $type === 'image' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'image', 'usage' => 'all', 'status' => '', 'id' => null])) ?>"><?= __('images', 'Images') ?> <span class="media-tab-n"><?= (int)$counts['image'] ?></span></a>
            <a class="media-tab <?= $status === '' && $type === 'video' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'video', 'usage' => 'all', 'status' => '', 'id' => null])) ?>"><?= __('videos', 'Videos') ?> <span class="media-tab-n"><?= (int)$counts['video'] ?></span></a>
            <a class="media-tab <?= $status === '' && $type === 'audio' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'audio', 'usage' => 'all', 'status' => '', 'id' => null])) ?>"><?= __('media_audio', 'Audio') ?> <span class="media-tab-n"><?= (int)$counts['audio'] ?></span></a>
            <a class="media-tab <?= $status === '' && $type === 'document' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'document', 'usage' => 'all', 'status' => '', 'id' => null])) ?>"><?= __('documents', 'Documents') ?> <span class="media-tab-n"><?= (int)$counts['document'] ?></span></a>
            <a class="media-tab <?= $status === '' && $usage === 'in_use' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['usage' => 'in_use', 'type' => 'all', 'status' => '', 'id' => null])) ?>"><?= __('in_use', 'In use') ?> <span class="media-tab-n"><?= (int)$counts['in_use'] ?></span></a>
            <a class="media-tab <?= $status === '' && $usage === 'unused' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['usage' => 'unused', 'type' => 'all', 'status' => '', 'id' => null])) ?>"><?= __('unused', 'Unused') ?> <span class="media-tab-n"><?= (int)$counts['unused'] ?></span></a>
            <a class="media-tab <?= $status === 'noalt' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['status' => 'noalt', 'type' => 'all', 'usage' => 'all', 'id' => null])) ?>"><?= __('media_missing_alt', 'Missing alt') ?> <span class="media-tab-n"><?= (int)$counts['missing_alt'] ?></span></a>
            <a class="media-tab <?= $status === 'trash' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['status' => 'trash', 'type' => 'all', 'usage' => 'all', 'id' => null])) ?>"><?= __('media_trash', 'Trash') ?> <span class="media-tab-n"><?= (int)$counts['trash'] ?></span></a>
        </div>
        <form class="media-sortform" method="get">
            <input type="hidden" name="folder" value="<?= e((string)$folder) ?>">
            <input type="hidden" name="tag" value="<?= (int)$tagId ?>">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="usage" value="<?= e($usage) ?>">
            <input type="hidden" name="q" value="<?= e($q) ?>">
            <select class="media-sort" name="sort" aria-label="<?= e(__('media_sort', 'Sort')) ?>" onchange="this.form.submit()">
                <?php foreach (['newest' => __('media_sort_newest', 'Newest first'), 'oldest' => __('media_sort_oldest', 'Oldest first'), 'name' => __('media_sort_name', 'Name A–Z'), 'size' => __('media_sort_size', 'Largest first')] as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-sm"><?= __('apply', 'Apply') ?></button></noscript>
        </form>
        <nav class="media-views" aria-label="<?= e(__('media_view', 'View')) ?>">
            <?php
            $viewIcons = [
                'grid'  => ['media_view_grid', 'Grid', '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'],
                'large' => ['media_view_large', 'Large tiles', '<rect x="3" y="3" width="18" height="8" rx="1"/><rect x="3" y="13" width="18" height="8" rx="1"/>'],
                'small' => ['media_view_small', 'Small tiles', '<rect x="3" y="3" width="4" height="4"/><rect x="10" y="3" width="4" height="4"/><rect x="17" y="3" width="4" height="4"/><rect x="3" y="10" width="4" height="4"/><rect x="10" y="10" width="4" height="4"/><rect x="17" y="10" width="4" height="4"/><rect x="3" y="17" width="4" height="4"/><rect x="10" y="17" width="4" height="4"/><rect x="17" y="17" width="4" height="4"/>'],
                'list'  => ['media_view_list', 'List', '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>'],
            ];
            foreach ($viewIcons as $v => [$key, $fallback, $paths]): $label = __($key, $fallback); ?>
                <a class="media-view <?= $view === $v ? 'is-on' : '' ?>" href="<?= e($tabUrl(['view' => $v, 'id' => $selId ?: null])) ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>" <?= $view === $v ? 'aria-current="true"' : '' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $paths ?></svg>
                </a>
            <?php endforeach; ?>
        </nav>
        <form class="media-search" method="get">
            <input type="hidden" name="folder" value="<?= e((string)$folder) ?>">
            <input type="hidden" name="tag" value="<?= (int)$tagId ?>">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="usage" value="<?= e($usage) ?>">
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('search_media', 'Search media…')) ?>" autocomplete="off">
        </form>
    </div>

    <?php if ($status === 'trash'): ?>
    <div class="media-note">
        <span><?= sprintf(__('media_trash_note', 'Files in the trash are deleted for good after %d days. Restore one to use it again.'), Media::TRASH_DAYS) ?></span>
        <?php if ($canDelete && $counts['trash'] > 0): ?>
        <form method="post" onsubmit="return confirm(<?= e(json_encode(__('media_empty_trash_confirm', 'Delete every file in the trash for good? This cannot be undone.'))) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="empty_trash">
            <button type="submit" class="btn btn-sm btn-danger"><?= __('media_empty_trash', 'Empty trash') ?></button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (($canUpload || $canDelete) && $items): ?>
    <form method="post" id="media-bulk" class="media-bulk" data-bulk hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="bulk">
        <label class="media-bulk-all"><input type="checkbox" data-bulk-all> <span data-bulk-count>0</span> <?= __('media_selected', 'selected') ?></label>
        <?php if ($status === 'trash'): ?>
            <?php if ($canDelete): ?>
            <button type="submit" name="bulk_op" value="restore" class="btn btn-sm"><?= __('media_restore', 'Restore') ?></button>
            <button type="submit" name="bulk_op" value="purge" class="btn btn-sm btn-danger" data-confirm="<?= e(__('media_purge_confirm', 'Delete the selected files for good? This cannot be undone.')) ?>"><?= __('media_delete_forever', 'Delete for good') ?></button>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($canUpload): ?>
            <span class="media-bulk-group">
                <select name="target_folder" class="media-sort" aria-label="<?= e(__('media_move_to', 'Move to')) ?>">
                    <option value="none"><?= __('media_unfiled', 'Not in a folder') ?></option>
                    <?php foreach ($folders as $fd): ?><option value="<?= (int)$fd['id'] ?>"><?= str_repeat('— ', (int)$fd['depth']) . e($fd['name']) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" name="bulk_op" value="move" class="btn btn-sm"><?= __('media_move', 'Move') ?></button>
            </span>
            <span class="media-bulk-group">
                <input type="text" name="bulk_tags" maxlength="200" placeholder="<?= e(__('media_tags_placeholder', 'tag, another tag')) ?>" aria-label="<?= e(__('media_tags', 'Tags')) ?>">
                <button type="submit" name="bulk_op" value="tag" class="btn btn-sm"><?= __('media_add_tags', 'Add tags') ?></button>
            </span>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <button type="submit" name="bulk_op" value="trash" class="btn btn-sm btn-danger"><?= __('media_move_to_trash', 'Move to trash') ?></button>
            <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-ghost" data-bulk-clear><?= __('media_clear_selection', 'Clear') ?></button>
    </form>
    <?php endif; ?>

    <div class="media-layout <?= $sel ? 'has-panel' : '' ?>">
    <div class="media-main">
    <?php if (empty($items)): ?>
        <div class="media-empty">
            <h3><?= __('no_media', 'No media found') ?></h3>
            <p><?php if ($q !== '' || $type !== 'all' || $usage !== 'all'): ?><?= __('no_media_filter', 'Nothing matches this filter.') ?><?php else: ?><?= __('no_media_yet', 'Upload your first file above, or import what is already on disk.') ?><?php endif; ?></p>
        </div>
    <?php else: ?>
        <div class="media-grid view-<?= e($view) ?>">
            <?php if ($view === 'list'): ?>
                <div class="media-list-head" aria-hidden="true">
                    <span></span><span></span><span><?= __('media_col_name', 'Name') ?></span><span class="media-col-type"><?= __('media_type', 'Type') ?></span>
                    <span class="media-col-dim"><?= __('media_dimensions', 'Size') ?></span><span class="media-col-size"><?= __('media_file_size', 'File size') ?></span>
                    <span class="media-col-date"><?= __('media_uploaded', 'Uploaded') ?></span><span></span>
                </div>
            <?php endif; ?>
            <?php foreach ($items as $f):
                $ext = strtoupper(pathinfo($f['original_name'] ?: $f['path'], PATHINFO_EXTENSION) ?: '?');
                $dim = ($f['width'] && $f['height']) ? $f['width'] . '×' . $f['height'] : '';
            ?>
            <article class="media-card <?= $sel && (int)$sel['id'] === (int)$f['id'] ? 'is-sel' : '' ?>">
                <?php if ($canUpload || $canDelete): ?>
                <label class="media-check" title="<?= e(__('media_select', 'Select')) ?>"><input type="checkbox" name="ids[]" value="<?= (int)$f['id'] ?>" form="media-bulk" data-bulk-item aria-label="<?= e(sprintf(__('media_select_file', 'Select %s'), $f['original_name'])) ?>"></label>
                <?php elseif ($view === 'list'): ?>
                <span class="media-check" aria-hidden="true"></span>
                <?php endif; ?>
                <a class="media-pick" href="<?= e($selUrl((int)$f['id'])) ?>" title="<?= e(__('media_show_details', 'Show details')) ?>">
                <div class="media-thumb">
                    <?php if ($f['kind'] === 'image'): ?>
                        <img src="<?= e($f['thumb_url']) ?>" alt="<?= e($f['original_name']) ?>" loading="lazy">
                    <?php elseif (Media::isVideo($f)): ?>
                        <video src="<?= e($f['url']) ?>" preload="metadata" muted playsinline aria-label="<?= e($f['original_name']) ?>"></video>
                    <?php elseif (Media::isAudio($f)): ?>
                        <div class="media-doc is-audio">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                            <span class="media-doc-ext"><?= e($ext) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="media-doc">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            <span class="media-doc-ext"><?= e($ext) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                </a>
                <div class="media-meta">
                    <div class="media-name" title="<?= e($f['original_name']) ?>"><a href="<?= e($selUrl((int)$f['id'])) ?>"><?= e($view === 'list' ? $f['original_name'] : mb_strimwidth($f['original_name'], 0, 26, '…')) ?></a></div>
                    <div class="media-sub"><?= e($dim ? $dim . ' · ' : '') ?><?= e($fmtSize($f['size_bytes'])) ?></div>
                    <?php if ($view === 'list'): ?>
                        <span class="media-col media-col-type"><?= e($ext) ?></span>
                        <span class="media-col media-col-dim"><?= e($dim ?: '—') ?></span>
                        <span class="media-col media-col-size"><?= e($fmtSize($f['size_bytes'])) ?></span>
                        <span class="media-col media-col-date"><?= e(substr($f['uploaded_at'], 0, 10)) ?></span>
                    <?php endif; ?>
                    <div class="media-row">
                        <?php if ($f['in_use']): ?>
                            <span class="media-badge is-on"><?= sprintf(__('used_n', 'Used (%d)'), $f['usage_count']) ?></span>
                        <?php else: ?>
                            <span class="media-badge is-off"><?= __('unused', 'Unused') ?></span>
                        <?php endif; ?>
                        <span class="media-spacer"></span>
                        <button type="button" class="media-ico-btn media-copy" data-url="<?= e($f['url']) ?>" title="<?= e(__('copy_url', 'Copy URL')) ?>" aria-label="<?= e(__('copy_url', 'Copy URL')) ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        </button>
                        <?php if ($canDelete && !$f['in_use'] && $status !== 'trash'): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button type="submit" class="media-ico-btn is-danger" title="<?= e(__('media_move_to_trash', 'Move to trash')) ?>" aria-label="<?= e(__('media_move_to_trash', 'Move to trash')) ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php slate_pagination($res['page'], $res['pages'], array_filter(['folder' => $folder, 'tag' => $tagId ?: null, 'status' => $status, 'type' => $type, 'usage' => $usage, 'q' => $q, 'sort' => $sort], fn($v) => $v !== null && $v !== '' && $v !== 'all'),
            ['total' => $res['total'], 'per_page' => $perPage, 'label' => __('files', 'files')]); ?>
    <?php endif; ?>
    </div>

    <?php if ($sel):
        $selIsImage = $sel['kind'] === 'image';
        $selTrashed = $sel['deleted_at'] !== null;
        $selDim = ($sel['width'] && $sel['height']) ? $sel['width'] . ' × ' . $sel['height'] . ' px' : '';
    ?>
    <a class="media-backdrop" href="<?= e($closeUrl) ?>" aria-hidden="true" tabindex="-1"></a>
    <aside class="media-panel" aria-labelledby="media-panel-title">
        <div class="media-panel-head">
            <h2 id="media-panel-title" title="<?= e($sel['original_name']) ?>"><?= e($sel['original_name']) ?></h2>
            <a class="media-ico-btn" href="<?= e($closeUrl) ?>" title="<?= e(__('close', 'Close')) ?>" aria-label="<?= e(__('close', 'Close')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </a>
        </div>
        <div class="media-panel-prev">
            <?php if ($selIsImage): ?>
                <img src="<?= e(Media::versioned($sel['url'], $sel)) ?>" alt="<?= e($sel['alt_text']) ?>" data-crop-img>
            <?php elseif (Media::isVideo($sel)): ?>
                <video src="<?= e($sel['url']) ?>" controls preload="metadata" playsinline></video>
            <?php elseif (Media::isAudio($sel)): ?>
                <audio src="<?= e($sel['url']) ?>" controls preload="metadata"></audio>
            <?php else: ?>
                <div class="media-doc"><span class="media-doc-ext"><?= e(strtoupper(pathinfo($sel['path'], PATHINFO_EXTENSION))) ?></span></div>
            <?php endif; ?>
        </div>
        <div class="media-panel-body">
            <dl class="media-facts">
                <dt><?= __('media_id', 'ID') ?></dt><dd><?= (int)$sel['id'] ?></dd>
                <dt><?= __('media_type', 'Type') ?></dt><dd><?= e($sel['mime'] ?: '—') ?></dd>
                <?php if ($selDim !== ''): ?><dt><?= __('media_dimensions', 'Size') ?></dt><dd><?= e($selDim) ?></dd><?php endif; ?>
                <dt><?= __('media_file_size', 'File size') ?></dt><dd><?= e($fmtSize($sel['size_bytes'])) ?></dd>
                <dt><?= __('media_uploaded', 'Uploaded') ?></dt><dd><?= e($sel['uploaded_at']) ?></dd>
                <dt><?= __('media_url', 'URL') ?></dt><dd><a href="<?= e($sel['url']) ?>" target="_blank" rel="noopener"><?= e($sel['path']) ?></a></dd>
                <?php if ($selIsImage && $sel['variants']): ?>
                <dt><?= __('media_sizes', 'Sizes') ?></dt><dd><?php foreach ($sel['variants'] as $vw => $vu): ?><a href="<?= e($vu) ?>" target="_blank" rel="noopener"><?= (int)$vw ?></a> <?php endforeach; ?><span class="media-hint">px · WebP</span></dd>
                <?php endif; ?>
            </dl>

            <?php if ($selTrashed): ?>
            <div class="media-note is-panel">
                <span><?= sprintf(__('media_in_trash', 'In the trash since %1$s. Deleted for good after %2$d days.'), e(substr((string)$sel['deleted_at'], 0, 10)), Media::TRASH_DAYS) ?></span>
                <?php if ($canDelete): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="restore">
                    <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-primary"><?= __('media_restore', 'Restore') ?></button>
                </form>
                <form method="post" action="<?= e($closeUrl) ?>" onsubmit="return confirm(<?= e(json_encode(__('media_purge_one_confirm', 'Delete this file for good? This cannot be undone.'))) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="purge">
                    <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><?= __('media_delete_forever', 'Delete for good') ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($canUpload && !$selTrashed && $selIsImage && \Slate\Services\Media\ImageProcessor::supports($sel['mime'])): ?>
            <div class="media-edit">
                <h3><?= __('media_edit_image', 'Edit image') ?></h3>
                <form method="post" class="media-edit-form" data-crop-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="edit_image">
                    <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
                    <input type="hidden" name="x" value=""><input type="hidden" name="y" value=""><input type="hidden" name="w" value=""><input type="hidden" name="h" value="">
                    <div class="media-edit-tools" data-tools>
                        <button type="submit" name="op" value="rotate_left" class="media-ico-btn" title="<?= e(__('media_rotate_left', 'Rotate left')) ?>" aria-label="<?= e(__('media_rotate_left', 'Rotate left')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg></button>
                        <button type="submit" name="op" value="rotate_right" class="media-ico-btn" title="<?= e(__('media_rotate_right', 'Rotate right')) ?>" aria-label="<?= e(__('media_rotate_right', 'Rotate right')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg></button>
                        <button type="submit" name="op" value="flip_h" class="media-ico-btn" title="<?= e(__('media_flip_h', 'Flip horizontally')) ?>" aria-label="<?= e(__('media_flip_h', 'Flip horizontally')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"/><path d="M8 7 3 12l5 5V7z"/><path d="M16 7l5 5-5 5V7z"/></svg></button>
                        <button type="submit" name="op" value="flip_v" class="media-ico-btn" title="<?= e(__('media_flip_v', 'Flip vertically')) ?>" aria-label="<?= e(__('media_flip_v', 'Flip vertically')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h18"/><path d="M7 8l5-5 5 5H7z"/><path d="M7 16l5 5 5-5H7z"/></svg></button>
                        <button type="button" class="btn btn-sm" data-crop-start><?= __('media_crop', 'Crop') ?></button>
                    </div>
                    <div class="media-crop-bar" data-crop-bar hidden>
                        <select class="media-sort" data-crop-ratio aria-label="<?= e(__('media_crop_ratio', 'Shape')) ?>">
                            <option value="0"><?= __('media_crop_free', 'Free') ?></option>
                            <option value="1">1:1</option>
                            <option value="1.3333">4:3</option>
                            <option value="1.5">3:2</option>
                            <option value="1.7778">16:9</option>
                            <option value="0.8">4:5</option>
                        </select>
                        <span class="media-hint" data-crop-size></span>
                        <button type="submit" name="op" value="crop" class="btn btn-sm btn-primary"><?= __('media_crop_apply', 'Apply crop') ?></button>
                        <button type="button" class="btn btn-sm" data-crop-cancel><?= __('cancel', 'Cancel') ?></button>
                    </div>
                    <label class="media-keep"><input type="checkbox" name="as_copy" value="1" checked> <?= __('media_keep_original', 'Keep the original (save as a new file)') ?></label>
                </form>
            </div>

            <?php endif; ?>

            <?php if ($canUpload && !$selTrashed): ?>
            <form method="post" enctype="multipart/form-data" class="media-replace">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="replace">
                <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
                <h3><?= __('media_replace', 'Replace file') ?></h3>
                <input type="file" name="file" required accept=".<?= e(strtolower(pathinfo($sel['path'], PATHINFO_EXTENSION))) ?>">
                <p class="media-hint"><?= sprintf(__('media_replace_hint', 'Same type (.%s). Keeps the ID, so every page using it shows the new file.'), e(strtolower(pathinfo($sel['path'], PATHINFO_EXTENSION)))) ?></p>
                <button type="submit" class="btn btn-sm"><?= __('media_replace_btn', 'Upload replacement') ?></button>
            </form>
            <?php endif; ?>

            <?php if ($canUpload && !$selTrashed): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="update_meta">
                <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
                <?php if ($selIsImage): ?>
                <div>
                    <label for="media-alt"><?= __('media_alt_text', 'Alt text') ?></label>
                    <input type="text" id="media-alt" name="alt_text" maxlength="255" value="<?= e($sel['alt_text']) ?>">
                    <div class="media-hint <?= $sel['alt_text'] === '' ? 'is-warn' : '' ?>"><?= $sel['alt_text'] === ''
                        ? __('media_alt_missing', 'No alt text yet. Describe the image for people who cannot see it.')
                        : __('media_alt_hint', 'Used wherever a page leaves the image\'s own alt text empty.') ?></div>
                </div>
                <?php endif; ?>
                <div style="margin-top:10px">
                    <label for="media-title"><?= __('media_title', 'Title') ?></label>
                    <input type="text" id="media-title" name="title" maxlength="255" value="<?= e($sel['title']) ?>">
                </div>
                <div style="margin-top:10px">
                    <label for="media-desc"><?= __('media_description', 'Description') ?></label>
                    <textarea id="media-desc" name="description" maxlength="5000"><?= e($sel['description']) ?></textarea>
                </div>
                <div style="margin-top:10px">
                    <label for="media-tags"><?= __('media_tags', 'Tags') ?></label>
                    <input type="text" id="media-tags" name="tags" maxlength="400" value="<?= e(implode(', ', array_column($sel['tags'], 'name'))) ?>" placeholder="<?= e(__('media_tags_placeholder', 'tag, another tag')) ?>" list="media-tag-list">
                    <datalist id="media-tag-list"><?php foreach ($tags as $tg): ?><option value="<?= e($tg['name']) ?>"><?php endforeach; ?></datalist>
                    <div class="media-hint"><?= __('media_tags_hint', 'Separate tags with commas.') ?></div>
                </div>
                <div style="margin-top:10px">
                    <label for="media-folder"><?= __('media_folder', 'Folder') ?></label>
                    <select id="media-folder" name="folder_id" class="media-sort">
                        <option value=""><?= __('media_unfiled', 'Not in a folder') ?></option>
                        <?php foreach ($folders as $fd): ?><option value="<?= (int)$fd['id'] ?>" <?= $sel['folder_id'] === $fd['id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int)$fd['depth']) . e($fd['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="media-panel-actions" style="margin-top:10px">
                    <button type="submit" class="btn btn-sm btn-primary"><?= __('save', 'Save') ?></button>
                </div>
            </form>
            <?php endif; ?>

            <div>
                <h3><?= __('media_used_on', 'Used on') ?></h3>
                <?php if ($selUsage): ?>
                    <ul class="media-used">
                        <?php foreach ($selUsage as $u): ?>
                            <li>
                                <?php if ($u['source'] !== ''): ?><span class="media-used-src"><?= e($u['source']) ?></span><?php endif; ?>
                                <?php if ($u['url'] !== ''): ?><a href="<?= e($u['url']) ?>"><?= e($u['label']) ?></a><?php else: ?><span><?= e($u['label']) ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($sel['in_use']): ?>
                    <p class="media-hint"><?= sprintf(__('media_used_unlisted', 'Used in %d place(s) that cannot be listed here.'), (int)$sel['usage_count']) ?></p>
                <?php else: ?>
                    <p class="media-hint"><?= __('media_used_nowhere', 'Not used anywhere yet.') ?></p>
                <?php endif; ?>
            </div>

            <?php if ($canDelete && !$sel['in_use'] && !$selTrashed): ?>
            <form method="post" action="<?= e($closeUrl) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"><?= __('media_move_to_trash', 'Move to trash') ?></button>
            </form>
            <?php endif; ?>
        </div>
    </aside>
    <?php endif; ?>
    </div>
    </div><!-- .media-content -->
    </div><!-- .media-shell -->

</div>

<script>
// Narrow screens: folders and tags start folded so the files stay in view (the summary names the open folder).
if (window.matchMedia && window.matchMedia('(max-width: 960px)').matches) {
    document.querySelectorAll('.media-side-box[open]').forEach(function (d) { d.removeAttribute('open'); });
}
// Bulk select: the bar appears once a file is ticked.
(function () {
    var bar = document.querySelector('[data-bulk]');
    if (!bar) return;
    var items = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-item]'));
    var all = bar.querySelector('[data-bulk-all]'), count = bar.querySelector('[data-bulk-count]');
    var grid = document.querySelector('.media-grid');
    function sync() {
        var n = items.filter(function (i) { return i.checked; }).length;
        bar.hidden = n === 0; count.textContent = n;
        all.checked = n > 0 && n === items.length; all.indeterminate = n > 0 && n < items.length;
        if (grid) grid.classList.toggle('is-selecting', n > 0);
    }
    items.forEach(function (i) { i.addEventListener('change', sync); });
    all.addEventListener('change', function () { items.forEach(function (i) { i.checked = all.checked; }); sync(); });
    bar.querySelector('[data-bulk-clear]').addEventListener('click', function () { items.forEach(function (i) { i.checked = false; }); sync(); });
    bar.addEventListener('submit', function (e) {
        var btn = e.submitter; var msg = btn && btn.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
    sync();
})();
// Crop: a box over the preview; its position in the image's own pixels goes into the form.
(function () {
    var form = document.querySelector('[data-crop-form]');
    var img = document.querySelector('[data-crop-img]');
    if (!form || !img) return;
    var bar = form.querySelector('[data-crop-bar]'), tools = form.querySelector('[data-tools]');
    var ratioSel = form.querySelector('[data-crop-ratio]'), sizeOut = form.querySelector('[data-crop-size]');
    var wrap = img.parentNode, box = null, r = null, drag = null;
    var field = function (n) { return form.querySelector('input[name="' + n + '"]'); };
    var W = function () { return img.clientWidth; }, H = function () { return img.clientHeight; };
    var ratio = function () { return parseFloat(ratioSel.value) || 0; };
    function clamp() {
        var min = 16;
        r.w = Math.max(min, Math.min(r.w, W())); r.h = Math.max(min, Math.min(r.h, H()));
        r.x = Math.max(0, Math.min(r.x, W() - r.w)); r.y = Math.max(0, Math.min(r.y, H() - r.h));
    }
    function draw() {
        clamp();
        box.style.left = (img.offsetLeft + r.x) + 'px'; box.style.top = (img.offsetTop + r.y) + 'px';
        box.style.width = r.w + 'px'; box.style.height = r.h + 'px';
        var k = img.naturalWidth / W();
        var px = { x: Math.round(r.x * k), y: Math.round(r.y * k), w: Math.round(r.w * k), h: Math.round(r.h * k) };
        field('x').value = px.x; field('y').value = px.y; field('w').value = px.w; field('h').value = px.h;
        sizeOut.textContent = px.w + ' × ' + px.h + ' px';
    }
    function fitRatio() {
        var q = ratio(); if (!q) return;
        var cx = r.x + r.w / 2, cy = r.y + r.h / 2;
        r.h = r.w / q; if (r.h > H()) { r.h = H(); r.w = r.h * q; }
        if (r.w > W()) { r.w = W(); r.h = r.w / q; }
        r.x = cx - r.w / 2; r.y = cy - r.h / 2;
    }
    function start() {
        if (box) return;
        wrap.classList.add('is-cropping');
        box = document.createElement('div'); box.className = 'media-crop-box';
        ['nw', 'ne', 'sw', 'se'].forEach(function (h) { var d = document.createElement('span'); d.className = 'media-crop-h h-' + h; d.setAttribute('data-h', h); box.appendChild(d); });
        wrap.appendChild(box);
        r = { x: W() * 0.1, y: H() * 0.1, w: W() * 0.8, h: H() * 0.8 };
        fitRatio(); draw();
        bar.hidden = false; tools.hidden = true;
        box.addEventListener('pointerdown', function (e) {
            e.preventDefault(); box.setPointerCapture(e.pointerId);
            drag = { h: e.target.getAttribute('data-h'), sx: e.clientX, sy: e.clientY, r: { x: r.x, y: r.y, w: r.w, h: r.h } };
        });
        box.addEventListener('pointermove', function (e) {
            if (!drag) return;
            var dx = e.clientX - drag.sx, dy = e.clientY - drag.sy, o = drag.r, q = ratio();
            if (!drag.h) { r.x = o.x + dx; r.y = o.y + dy; draw(); return; }
            var left = drag.h.indexOf('w') >= 0, top = drag.h.indexOf('n') >= 0;
            r.w = o.w + (left ? -dx : dx); r.h = q ? r.w / q : o.h + (top ? -dy : dy);
            r.x = left ? o.x + o.w - r.w : o.x; r.y = top ? o.y + o.h - r.h : o.y;
            draw();
        });
        ['pointerup', 'pointercancel'].forEach(function (t) { box.addEventListener(t, function () { drag = null; }); });
    }
    function stop() {
        if (box) box.remove(); box = null; drag = null;
        wrap.classList.remove('is-cropping');
        bar.hidden = true; tools.hidden = false;
        ['x', 'y', 'w', 'h'].forEach(function (n) { field(n).value = ''; });
    }
    form.querySelector('[data-crop-start]').addEventListener('click', function () { if (img.complete) start(); else img.addEventListener('load', start, { once: true }); });
    form.querySelector('[data-crop-cancel]').addEventListener('click', stop);
    ratioSel.addEventListener('change', function () { if (box) { fitRatio(); draw(); } });
})();
// Upload in pieces: a file larger than the server's upload limit still gets through, with a progress bar.
// Without fetch/Blob.slice the plain form posts as before.
(function () {
    var form = document.querySelector('form[data-media-upload]');
    if (!form || !window.fetch || !window.FormData || !Blob.prototype.slice) return;
    var endpoint = form.getAttribute('data-media-upload');
    var input = form.querySelector('input[type=file]');
    var csrf = (form.querySelector('input[name=_csrf]') || {}).value || '';
    var folderId = (form.querySelector('input[name=folder_id]') || {}).value || '0';
    var box = document.querySelector('[data-media-progress]');
    var fill = box && box.querySelector('[data-media-progress-fill]');
    var text = box && box.querySelector('[data-media-progress-text]');
    var T = <?= json_encode([
        'uploading' => __('media_uploading', 'Uploading %1$s (%2$d of %3$d)…'),
        'failed'    => __('media_upload_failed_one', '%1$s: %2$s'),
        'network'   => __('media_upload_network', 'The connection dropped. Please try again.'),
        'leave'     => __('media_upload_leave', 'An upload is still running. Leave anyway?'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var busy = false;
    function fmt(s) { var a = arguments, i = 0; return s.replace(/%(\d)\$[sd]/g, function (_, n) { return a[+n]; }); }
    function post(data) {
        data.append('_csrf', csrf);
        return fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: T.network }; }); });
    }
    function field(o) { var d = new FormData(); Object.keys(o).forEach(function (k) { d.append(k, o[k]); }); return d; }
    function send(file, onBytes) {
        return post(field({ op: 'start', name: file.name, size: file.size })).then(function (st) {
            if (!st.ok) throw new Error(st.error || T.network);
            var size = st.chunk_size, offset = 0, tries = 0;
            function next() {
                if (offset >= file.size) return post(field({ op: 'finish', token: st.token, folder_id: folderId }));
                var d = field({ op: 'chunk', token: st.token, offset: offset });
                d.append('file', file.slice(offset, offset + size), 'piece');
                return post(d).then(function (r) {
                    if (r.ok) { offset = r.received; tries = 0; onBytes(offset); return next(); }
                    // A piece that arrived but whose answer was lost: carry on from where the server is.
                    if (typeof r.received === 'number' && r.received > offset) { offset = r.received; onBytes(offset); return next(); }
                    throw new Error(r.error || T.network);
                }, function (err) {
                    if (++tries <= 3) return new Promise(function (res) { setTimeout(res, 1000 * tries); }).then(next);
                    throw err;
                });
            }
            return next().then(function (fin) {
                if (!fin.ok) throw new Error(fin.error || T.network);
                return fin;
            }).catch(function (err) { post(field({ op: 'abort', token: st.token })); throw err; });
        });
    }
    window.addEventListener('beforeunload', function (e) { if (busy) { e.preventDefault(); e.returnValue = T.leave; } });
    form.addEventListener('submit', function (ev) {
        var files = Array.prototype.slice.call(input.files || []);
        if (!files.length) return; // the server answers "Please select at least one file."
        ev.preventDefault();
        busy = true;
        var btn = form.querySelector('button[type=submit]'); if (btn) btn.disabled = true;
        var total = files.reduce(function (n, f) { return n + f.size; }, 0) || 1, done = 0, uploaded = 0, dupes = 0, errors = [];
        box.hidden = false;
        var i = 0;
        function one() {
            if (i >= files.length) return Promise.resolve();
            var f = files[i++];
            text.textContent = fmt(T.uploading, f.name, i, files.length);
            var base = done;
            return send(f, function (bytes) { fill.style.width = Math.min(100, (base + bytes) / total * 100) + '%'; })
                .then(function (r) { if (r.duplicate) dupes++; else uploaded++; }, function (err) { errors.push(fmt(T.failed, f.name, err.message || T.network)); })
                .then(function () { done = base + f.size; fill.style.width = Math.min(100, done / total * 100) + '%'; return one(); });
        }
        one().then(function () {
            busy = false;
            if (!errors.length) {
                var u = new URL(window.location.href);
                u.searchParams.set('uploaded', uploaded); u.searchParams.set('dupes', dupes); u.searchParams.delete('page'); u.searchParams.delete('id');
                window.location.href = u.toString();
                return;
            }
            if (btn) btn.disabled = false;
            text.textContent = errors.join(' · ');
            box.classList.add('is-error');
            if (uploaded || dupes) setTimeout(function () {
                var u = new URL(window.location.href); u.searchParams.set('uploaded', uploaded); u.searchParams.set('dupes', dupes); window.location.href = u.toString();
            }, 4000);
        });
    });
})();
// Escape closes the details panel (the bottom sheet on a phone).
(function () {
    var backdrop = document.querySelector('.media-backdrop');
    if (!backdrop) return;
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !/^(INPUT|TEXTAREA|SELECT)$/.test((ev.target && ev.target.tagName) || '')) window.location.href = backdrop.href;
    });
})();
document.querySelectorAll('.media-copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-url') || '';
        var done = function () { btn.classList.add('is-on'); setTimeout(function () { btn.classList.remove('is-on'); }, 900); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(done);
        } else {
            var ta = document.createElement('textarea'); ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta); done();
        }
    });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
