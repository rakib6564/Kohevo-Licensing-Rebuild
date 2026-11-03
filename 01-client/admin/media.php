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
 *
 * Query:
 *   ?type=all|image|video|document   ?usage=all|in_use|unused   ?q=…
 *   ?sort=newest|oldest|name|size    ?page=N    ?id=N (opens the details panel)
 *   ?view=grid|large|small|list      (remembered in the media_view cookie)
 */
require __DIR__ . '/../config.php';
Auth::require();
Auth::requirePerm('media.view');

Media::ensureSchema();

$pageTitle  = __('media_library', 'Media library');
$currentNav = 'media';

$canUpload = Auth::can('media.upload') || Auth::isSuperAdmin();
$canDelete = Auth::can('media.delete') || Auth::isSuperAdmin();

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
                $ok = 0; $errors = [];
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
                            $r = Media::upload('__media_one');
                            if (!empty($r['id']) || !empty($r['path'])) $ok++;
                            else $errors[] = $label . ': ' . ($r['error'] ?? 'unknown error');
                        } catch (\Throwable $e) {
                            $errors[] = $label . ': ' . $e->getMessage();
                        }
                        unset($_FILES['__media_one']);
                    }
                }
                if ($ok > 0) {
                    $msg = sprintf(__('uploaded_n', '%d file(s) uploaded.'), $ok);
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

        elseif ($action === 'delete') {
            if (!$canDelete) {
                $flash = ['type' => 'error', 'msg' => __('no_permission', 'You do not have permission to delete.')];
            } else {
                $r = Media::delete((int)($_POST['id'] ?? 0));
                $flash = $r['ok']
                    ? ['type' => 'success', 'msg' => __('file_deleted', 'File deleted.')]
                    : ['type' => 'error', 'msg' => $r['error']];
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
                if (!empty($r['ok'])) AuditLog::record('media.updated', (string)($r['item']['path'] ?? ''), ['id' => (int)($_POST['id'] ?? 0)]);
                $flash = !empty($r['ok'])
                    ? ['type' => 'success', 'msg' => __('media_details_saved', 'Details saved.')]
                    : ['type' => 'error', 'msg' => __('media_details_not_saved', 'The details could not be saved.')];
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
if (!in_array($type,  ['all', 'image', 'video', 'document'], true)) $type  = 'all';
if (!in_array($usage, ['all', 'in_use', 'unused'],  true)) $usage = 'all';
if (!in_array($sort,  ['newest', 'oldest', 'name', 'size'], true)) $sort = 'newest';

// View: grid (default), large tiles, small tiles or a list. A chosen view is remembered per browser.
$views = ['grid', 'large', 'small', 'list'];
$view  = (string)($_GET['view'] ?? $_COOKIE['media_view'] ?? 'grid');
if (!in_array($view, $views, true)) $view = 'grid';
if (isset($_GET['view']) && ($_COOKIE['media_view'] ?? '') !== $view) {
    setcookie('media_view', $view, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true]);
}
$perPage = ['grid' => 24, 'large' => 24, 'small' => 48, 'list' => 50][$view];

$res    = Media::listAll(['type' => $type, 'usage' => $usage, 'search' => $q, 'sort' => $sort, 'page' => $page, 'per_page' => $perPage]);
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
$tabUrl = static function (array $over) use ($type, $usage, $q, $sort): string {
    $p = array_merge(['type' => $type, 'usage' => $usage, 'q' => $q, 'sort' => $sort], $over);
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

.media-backdrop { display: none; }
/* Narrow screens: the grid stays in view and the details open as a bottom sheet over it. */
@media (max-width: 960px) {
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
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="upload">
            <input type="file" name="files[]" multiple
                   accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml,application/pdf,.doc,.docx,.xls,.xlsx,video/mp4,video/webm,video/ogg,video/quicktime,.mp4,.m4v,.webm,.ogv,.mov">
            <button type="submit" class="btn btn-sm btn-primary"><?= __('upload', 'Upload') ?></button>
        </form>
        <span class="media-spacer"></span>
        <form method="post" title="<?= e(__('media_import_hint', 'Scan upload folders for files added outside the library')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="import">
            <button type="submit" class="btn btn-sm"><?= __('import_existing', 'Import existing files') ?></button>
        </form>
    </div>
    <?php endif; ?>

    <div class="media-toolbar">
        <div class="media-tabs" role="tablist">
            <a class="media-tab <?= $type === 'all' && $usage === 'all' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'all', 'usage' => 'all'])) ?>"><?= __('all', 'All') ?> <span class="media-tab-n"><?= (int)$counts['all'] ?></span></a>
            <a class="media-tab <?= $type === 'image' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'image', 'usage' => 'all'])) ?>"><?= __('images', 'Images') ?> <span class="media-tab-n"><?= (int)$counts['image'] ?></span></a>
            <a class="media-tab <?= $type === 'video' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'video', 'usage' => 'all'])) ?>"><?= __('videos', 'Videos') ?> <span class="media-tab-n"><?= (int)$counts['video'] ?></span></a>
            <a class="media-tab <?= $type === 'document' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'document', 'usage' => 'all'])) ?>"><?= __('documents', 'Documents') ?> <span class="media-tab-n"><?= (int)$counts['document'] ?></span></a>
            <a class="media-tab <?= $usage === 'in_use' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['usage' => 'in_use', 'type' => 'all'])) ?>"><?= __('in_use', 'In use') ?> <span class="media-tab-n"><?= (int)$counts['in_use'] ?></span></a>
            <a class="media-tab <?= $usage === 'unused' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['usage' => 'unused', 'type' => 'all'])) ?>"><?= __('unused', 'Unused') ?> <span class="media-tab-n"><?= (int)$counts['unused'] ?></span></a>
        </div>
        <form class="media-sortform" method="get">
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
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="usage" value="<?= e($usage) ?>">
            <input type="hidden" name="sort" value="<?= e($sort) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('search_media', 'Search media…')) ?>" autocomplete="off">
        </form>
    </div>

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
                    <span></span><span><?= __('media_col_name', 'Name') ?></span><span class="media-col-type"><?= __('media_type', 'Type') ?></span>
                    <span class="media-col-dim"><?= __('media_dimensions', 'Size') ?></span><span class="media-col-size"><?= __('media_file_size', 'File size') ?></span>
                    <span class="media-col-date"><?= __('media_uploaded', 'Uploaded') ?></span><span></span>
                </div>
            <?php endif; ?>
            <?php foreach ($items as $f):
                $ext = strtoupper(pathinfo($f['original_name'] ?: $f['path'], PATHINFO_EXTENSION) ?: '?');
                $dim = ($f['width'] && $f['height']) ? $f['width'] . '×' . $f['height'] : '';
            ?>
            <article class="media-card <?= $sel && (int)$sel['id'] === (int)$f['id'] ? 'is-sel' : '' ?>">
                <a class="media-pick" href="<?= e($selUrl((int)$f['id'])) ?>" title="<?= e(__('media_show_details', 'Show details')) ?>">
                <div class="media-thumb">
                    <?php if ($f['kind'] === 'image'): ?>
                        <img src="<?= e($f['url']) ?>" alt="<?= e($f['original_name']) ?>" loading="lazy">
                    <?php elseif (Media::isVideo($f)): ?>
                        <video src="<?= e($f['url']) ?>" preload="metadata" muted playsinline aria-label="<?= e($f['original_name']) ?>"></video>
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
                        <?php if ($canDelete && !$f['in_use']): ?>
                            <form method="post" onsubmit="return confirm(<?= e(json_encode(sprintf(__('confirm_delete', 'Delete \"%s\"? This removes the file from disk.'), $f['original_name']))) ?>);">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button type="submit" class="media-ico-btn is-danger" title="<?= e(__('delete', 'Delete')) ?>" aria-label="<?= e(__('delete', 'Delete')) ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>

        <?php slate_pagination($res['page'], $res['pages'], ['type' => $type, 'usage' => $usage, 'q' => $q, 'sort' => $sort],
            ['total' => $res['total'], 'per_page' => $perPage, 'label' => __('files', 'files')]); ?>
    <?php endif; ?>
    </div>

    <?php if ($sel):
        $selIsImage = $sel['kind'] === 'image';
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
                <img src="<?= e($sel['url']) ?>" alt="<?= e($sel['alt_text']) ?>">
            <?php elseif (Media::isVideo($sel)): ?>
                <video src="<?= e($sel['url']) ?>" controls preload="metadata" playsinline></video>
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
            </dl>

            <?php if ($canUpload): ?>
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

            <?php if ($canDelete && !$sel['in_use']): ?>
            <form method="post" action="<?= e($closeUrl) ?>" onsubmit="return confirm(<?= e(json_encode(sprintf(__('confirm_delete', 'Delete \"%s\"? This removes the file from disk.'), $sel['original_name']))) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$sel['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"><?= __('media_delete_file', 'Delete file') ?></button>
            </form>
            <?php endif; ?>
        </div>
    </aside>
    <?php endif; ?>
    </div>

</div>

<script>
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
