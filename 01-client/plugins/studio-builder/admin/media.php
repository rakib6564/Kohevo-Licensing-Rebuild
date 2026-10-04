<?php
/**
 * Kohevo Studio — Media & Asset Inspector.
 *
 * Dedicated admin page for browsing, uploading, and inspecting media assets
 * for Studio Builder, including primary Media IDs required for og_image_media_id
 * and dynamic image bindings.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

if (class_exists('Media')) {
    Media::ensureSchema();
}

$pageTitle = __('studio_media', 'Media & Assets');
$currentNav = 'studio-media';

$flash = null;

// POST Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage media.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');

        if ($action === 'upload') {
            $uploadedCount = 0;
            $uploadErrors = [];
            if (!empty($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
                $count = count($_FILES['files']['name']);
                for ($i = 0; $i < $count; $i++) {
                    $err = $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                    if ($err === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $name = $_FILES['files']['name'][$i] ?? "file $i";
                    if ($err !== UPLOAD_ERR_OK) {
                        $uploadErrors[] = "{$name}: upload error {$err}";
                        continue;
                    }
                    $_FILES['__sb_media_upload'] = [
                        'name'     => $_FILES['files']['name'][$i],
                        'type'     => $_FILES['files']['type'][$i],
                        'tmp_name' => $_FILES['files']['tmp_name'][$i],
                        'error'    => $_FILES['files']['error'][$i],
                        'size'     => $_FILES['files']['size'][$i],
                    ];
                    try {
                        if (class_exists('Media')) {
                            $res = Media::upload('__sb_media_upload');
                            if (!empty($res['id']) || !empty($res['path'])) {
                                $uploadedCount++;
                            } else {
                                $uploadErrors[] = $name . ': ' . ($res['error'] ?? 'upload failed');
                            }
                        }
                    } catch (\Throwable $e) {
                        $uploadErrors[] = "{$name}: " . $e->getMessage();
                    }
                }
            }

            if ($uploadedCount > 0) {
                $flash = ['type' => 'success', 'msg' => "Successfully uploaded {$uploadedCount} asset(s)."];
            } elseif (!empty($uploadErrors)) {
                $flash = ['type' => 'error', 'msg' => implode(' | ', $uploadErrors)];
            }
        } elseif ($action === 'delete') {
            $mediaId = (int) ($_POST['media_id'] ?? 0);
            if ($mediaId > 0) {
                try {
                    Database::delete('media_files', 'tenant_id = ? AND id = ?', [$tenantId, $mediaId]);
                    $flash = ['type' => 'success', 'msg' => "Asset #{$mediaId} deleted."];
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Failed to delete asset: ' . $e->getMessage()];
                }
            }
        }
    }
}

// Filters & Query
$kindFilter = trim((string) ($_GET['kind'] ?? 'all'));
$searchQuery = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT * FROM media_files WHERE tenant_id = ?";
$params = [$tenantId];

if ($kindFilter !== 'all' && in_array($kindFilter, ['image', 'document', 'video', 'audio'], true)) {
    $sql .= " AND kind = ?";
    $params[] = $kindFilter;
}

if ($searchQuery !== '') {
    $sql .= " AND (filename LIKE ? OR original_filename LIKE ?)";
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
}

$sql .= " ORDER BY id DESC LIMIT 100";

try {
    $mediaAssets = Database::all($sql, $params);
} catch (\Throwable $e) {
    $mediaAssets = [];
}

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('media', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
        <div class="sb-pill-filter" style="margin-bottom: 0;">
            <a href="<?= e(plugin_url('studio-builder', 'admin/media.php')) ?>" class="sb-pill-item <?= $kindFilter === 'all' ? 'active' : '' ?>">
                <?= sb_svg('media', 14) ?> All Assets (<?= count($mediaAssets) ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/media.php?kind=image')) ?>" class="sb-pill-item <?= $kindFilter === 'image' ? 'active' : '' ?>">
                <?= sb_svg('eye', 14) ?> Images
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/media.php?kind=document')) ?>" class="sb-pill-item <?= $kindFilter === 'document' ? 'active' : '' ?>">
                <?= sb_svg('pages', 14) ?> Documents
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/media.php?kind=video')) ?>" class="sb-pill-item <?= $kindFilter === 'video' ? 'active' : '' ?>">
                <?= sb_svg('zap', 14) ?> Video & Audio
            </a>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
            <form method="GET" action="<?= e(plugin_url('studio-builder', 'admin/media.php')) ?>" style="display: flex; gap: 8px;">
                <?php if ($kindFilter !== 'all'): ?>
                    <input type="hidden" name="kind" value="<?= e($kindFilter) ?>">
                <?php endif; ?>
                <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search filename..." class="form-control" style="font-size: 0.85rem; padding: 6px 12px; border-radius: 8px;">
                <button type="submit" class="btn btn-outline" style="padding: 6px 12px;">
                    <?= sb_svg('search', 14) ?>
                </button>
            </form>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('sb-upload-drawer').style.display='block'">
                <?= sb_svg('upload', 14) ?> Upload Assets
            </button>
        </div>
    </div>

    <!-- Upload Drawer / Modal -->
    <div id="sb-upload-drawer" style="display: none; background: #ffffff; border: 1px solid var(--sb-border); border-radius: 14px; padding: 24px; margin-bottom: 28px; box-shadow: 0 4px 12px rgba(0,0,0,0.06);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div style="font-weight: 700; font-size: 1.1rem; color: var(--sb-text); display: flex; align-items: center; gap: 8px;">
                <?= sb_svg('upload', 18) ?> Upload Media Assets
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('sb-upload-drawer').style.display='none'">
                <?= sb_svg('close', 14) ?>
            </button>
        </div>
        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/media.php')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="upload">
            <div style="border: 2px dashed #cbd5e1; border-radius: 12px; padding: 32px; text-align: center; background: #f8fafc; margin-bottom: 16px;">
                <?= sb_svg('upload', 36, 'text-muted') ?>
                <p style="margin: 12px 0 8px 0; font-weight: 600; color: var(--sb-text);">Select images or files to upload</p>
                <p style="font-size: 0.8rem; color: var(--sb-muted); margin-bottom: 16px;">PNG, JPG, WebP, SVG, PDF up to 20MB</p>
                <input type="file" name="files[]" multiple required class="form-control" style="max-width: 320px; margin: 0 auto;">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-upload-drawer').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary"><?= sb_svg('check', 14) ?> Upload to Studio</button>
            </div>
        </form>
    </div>

    <!-- Media Grid -->
    <?php if (empty($mediaAssets)): ?>
        <div class="card" style="padding: 48px 24px; text-align: center; border-radius: 16px; border: 1px dashed var(--sb-border);">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; color: var(--sb-muted);">
                <?= sb_svg('media', 28) ?>
            </div>
            <h3 style="font-weight: 700; color: var(--sb-text); margin-bottom: 8px;">No media assets found</h3>
            <p style="color: var(--sb-muted); font-size: 0.9rem; max-width: 420px; margin: 0 auto 20px auto;">
                Upload hero images, project screenshots, brand icons, and client logos to bind them to your pages and SEO metadata.
            </p>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('sb-upload-drawer').style.display='block'">
                <?= sb_svg('upload', 16) ?> Upload First Asset
            </button>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px; margin-bottom: 32px;">
            <?php foreach ($mediaAssets as $asset):
                $id = (int) $asset['id'];
                $path = (string) ($asset['path'] ?? '');
                $filename = (string) ($asset['filename'] ?? "asset-{$id}");
                $kind = (string) ($asset['kind'] ?? 'image');
                $size = isset($asset['size_bytes']) ? number_format((int) $asset['size_bytes'] / 1024, 1) . ' KB' : '—';
                $url = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') . '/' . ltrim($path, '/') : '/' . ltrim($path, '/');
                $isImg = ($kind === 'image' || preg_match('/\.(jpg|jpeg|png|webp|gif|svg)$/i', $path));
            ?>
                <div class="card" style="border-radius: 14px; overflow: hidden; border: 1px solid var(--sb-border); display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                    <!-- Thumbnail Preview -->
                    <div style="height: 140px; background: #0f172a; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative;">
                        <?php if ($isImg): ?>
                            <img src="<?= e($url) ?>" alt="<?= e($filename) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <div style="color: #94a3b8; display: flex; flex-direction: column; align-items: center; gap: 6px;">
                                <?= sb_svg('pages', 32) ?>
                                <span style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em;"><?= e($kind) ?></span>
                            </div>
                        <?php endif; ?>
                        <span style="position: absolute; top: 8px; left: 8px; background: rgba(15, 23, 42, 0.85); color: #ffffff; font-size: 0.72rem; font-weight: 700; padding: 2px 7px; border-radius: 6px; backdrop-filter: blur(4px);">
                            ID #<?= $id ?>
                        </span>
                    </div>

                    <!-- Metadata -->
                    <div style="padding: 14px; flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="font-weight: 600; font-size: 0.88rem; color: var(--sb-text); margin-bottom: 4px; word-break: break-all; line-height: 1.3;" title="<?= e($filename) ?>">
                                <?= e(mb_strimwidth($filename, 0, 28, '...')) ?>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--sb-muted); margin-bottom: 12px; display: flex; gap: 8px;">
                                <span><?= e($size) ?></span>
                                <span>•</span>
                                <span style="text-transform: uppercase;"><?= e($kind) ?></span>
                            </div>
                        </div>

                        <!-- Action Bar -->
                        <div style="display: flex; gap: 6px; border-top: 1px solid var(--sb-border); padding-top: 10px;">
                            <button type="button" class="btn btn-sm btn-outline" style="flex: 1; font-size: 0.75rem; padding: 4px 6px;" onclick="navigator.clipboard.writeText('<?= $id ?>'); alert('Media ID #<?= $id ?> copied to clipboard!');" title="Copy Media ID for widgets or SEO">
                                <?= sb_svg('copy', 12) ?> ID
                            </button>
                            <button type="button" class="btn btn-sm btn-outline" style="flex: 1; font-size: 0.75rem; padding: 4px 6px;" onclick="navigator.clipboard.writeText('<?= e($url) ?>'); alert('Asset URL copied to clipboard!');" title="Copy full asset URL">
                                <?= sb_svg('link', 12) ?> URL
                            </button>
                            <a href="<?= e($url) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline" style="padding: 4px 8px;" title="Open original asset">
                                <?= sb_svg('external-link', 12) ?>
                            </a>
                            <?php if ($canEdit): ?>
                                <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/media.php')) ?>" onsubmit="return confirm('Delete this media file?');" style="margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="media_id" value="<?= $id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444; padding: 4px 8px;" title="Delete file">
                                        <?= sb_svg('trash', 12) ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Studio Binding Guidance Box -->
    <div class="card" style="background: #f8fafc; border: 1px solid var(--sb-border); border-radius: 14px; padding: 20px;">
        <div style="display: flex; align-items: flex-start; gap: 14px;">
            <div style="color: var(--sb-accent); flex-shrink: 0; padding-top: 2px;">
                <?= sb_svg('info', 22) ?>
            </div>
            <div>
                <h4 style="font-weight: 700; color: var(--sb-text); margin: 0 0 6px 0; font-size: 0.95rem;">How Media IDs Power Studio Builder</h4>
                <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0 0 10px 0;">
                    Kohevo Studio resolves media via database-backed integer IDs. Copy the <strong>ID</strong> badge to use in the <code>core.image</code> widget, background section cover, or page SEO <code>og_image_media_id</code> to guarantee fast, responsive, and secure tenant-isolated asset delivery.
                </p>
                <div style="display: flex; gap: 12px;">
                    <a href="<?= e(SLATE_URL . '/admin/media.php') ?>" class="btn btn-sm btn-outline" target="_blank">
                        <?= sb_svg('external-link', 14) ?> Open Slate Core Media Library
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
