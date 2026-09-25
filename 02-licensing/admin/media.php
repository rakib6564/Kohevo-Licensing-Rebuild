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
 *
 * Query:
 *   ?type=all|image|document   ?usage=all|in_use|unused   ?q=…   ?page=N
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
if (!in_array($type,  ['all', 'image', 'document'], true)) $type  = 'all';
if (!in_array($usage, ['all', 'in_use', 'unused'],  true)) $usage = 'all';

$res    = Media::listAll(['type' => $type, 'usage' => $usage, 'search' => $q, 'page' => $page, 'per_page' => 24]);
$counts = Media::counts();
$items  = $res['items'];

require __DIR__ . '/partials/header.php';

$csrf = csrf_token();

$fmtSize = static function (int $b): string {
    $u = ['B', 'KB', 'MB', 'GB']; $s = (float)$b; $i = 0;
    while ($s >= 1024 && $i < count($u) - 1) { $s /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$s : number_format($s, 1)) . ' ' . $u[$i];
};
$tabUrl = static function (array $over) use ($type, $usage, $q): string {
    $p = array_merge(['type' => $type, 'usage' => $usage, 'q' => $q], $over);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== 'all');
    return '?' . http_build_query($p);
};
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
.media-thumb img { max-width: 100%; max-height: 100%; object-fit: contain; }
.media-doc { display: flex; flex-direction: column; align-items: center; gap: 6px; color: var(--muted); }
.media-doc-ext { font-family: var(--font-mono); font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--accent); }
.media-meta { padding: 8px 10px; }
.media-name { font-size: 12px; font-weight: 600; word-break: break-all; line-height: 1.3; }
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
@media (max-width: 600px) { .media-hero-counts { display: none; } .media-search { margin-left: 0; width: 100%; } }
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
                   accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml,application/pdf,.doc,.docx,.xls,.xlsx">
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
            <a class="media-tab <?= $type === 'document' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['type' => 'document', 'usage' => 'all'])) ?>"><?= __('documents', 'Documents') ?> <span class="media-tab-n"><?= (int)$counts['document'] ?></span></a>
            <a class="media-tab <?= $usage === 'in_use' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['usage' => 'in_use', 'type' => 'all'])) ?>"><?= __('in_use', 'In use') ?> <span class="media-tab-n"><?= (int)$counts['in_use'] ?></span></a>
            <a class="media-tab <?= $usage === 'unused' ? 'is-on' : '' ?>" href="<?= e($tabUrl(['usage' => 'unused', 'type' => 'all'])) ?>"><?= __('unused', 'Unused') ?> <span class="media-tab-n"><?= (int)$counts['unused'] ?></span></a>
        </div>
        <form class="media-search" method="get">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="usage" value="<?= e($usage) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('search_media', 'Search media…')) ?>" autocomplete="off">
        </form>
    </div>

    <?php if (empty($items)): ?>
        <div class="media-empty">
            <h3><?= __('no_media', 'No media found') ?></h3>
            <p><?php if ($q !== '' || $type !== 'all' || $usage !== 'all'): ?><?= __('no_media_filter', 'Nothing matches this filter.') ?><?php else: ?><?= __('no_media_yet', 'Upload your first file above, or import what is already on disk.') ?><?php endif; ?></p>
        </div>
    <?php else: ?>
        <div class="media-grid">
            <?php foreach ($items as $f):
                $ext = strtoupper(pathinfo($f['original_name'] ?: $f['path'], PATHINFO_EXTENSION) ?: '?');
                $dim = ($f['width'] && $f['height']) ? $f['width'] . '×' . $f['height'] : '';
            ?>
            <article class="media-card">
                <div class="media-thumb">
                    <?php if ($f['kind'] === 'image'): ?>
                        <img src="<?= e($f['url']) ?>" alt="<?= e($f['original_name']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="media-doc">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            <span class="media-doc-ext"><?= e($ext) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="media-meta">
                    <div class="media-name" title="<?= e($f['original_name']) ?>"><?= e(mb_strimwidth($f['original_name'], 0, 26, '…')) ?></div>
                    <div class="media-sub"><?= e($dim ? $dim . ' · ' : '') ?><?= e($fmtSize($f['size_bytes'])) ?></div>
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

        <?php slate_pagination($res['page'], $res['pages'], ['type' => $type, 'usage' => $usage, 'q' => $q],
            ['total' => $res['total'], 'per_page' => 24, 'label' => __('files', 'files')]); ?>
    <?php endif; ?>

</div>

<script>
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
