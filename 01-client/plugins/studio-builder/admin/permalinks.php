<?php
/**
 * Kohevo Studio — Permalinks: how the site's addresses are formed. The address base of each content type, and whether
 * addresses end with a slash.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Meta\PostTypeSettings;

Auth::require();
Auth::requirePerm('studio-builder.view');

// Changing an address changes the public site at once, as on the post type screen.
$canEdit = Auth::can('studio-builder.publish') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_permalinks', 'Permalinks');
$currentNav = 'studio-permalinks';

$types = PostTypeSettings::sorted(PageMeta::postTypes($tenantId));
$flash = null;
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = is_array($_POST['base'] ?? null) ? $_POST['base'] : [];
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('studio_tf_live_denied', 'These settings change the public site at once. Ask someone who can publish pages.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } else {
        $errors = [];
        $next = $types;
        $taken = [];
        foreach ($types as $key => $type) {
            if (empty($type['rewrite'])) {
                continue;
            }
            $prefix = PageRoutes::normalizePrefix((string) ($posted[$key] ?? $type['rewrite_slug']));
            if ($prefix === null || $prefix === '') {
                $errors[] = sprintf(__('studio_permalinks_bad_base', '%s: an address base is one to three lowercase words separated by /, like /events.'), (string) $type['plural']);
                continue;
            }
            if (isset($taken[$prefix])) {
                $errors[] = sprintf(__('studio_permalinks_taken', 'Two content types cannot share /%s.'), $prefix);
            }
            $taken[$prefix] = true;
            $next[$key]['route'] = '/' . $prefix;
            $next[$key]['rewrite_slug'] = $prefix;
        }
        if ($errors !== []) {
            $flash = ['type' => 'error', 'msg' => implode(' ', array_unique($errors))];
        } else {
            PageMeta::savePostTypes($tenantId, $next);
            Database::setSetting(PageRoutes::SETTING_TRAILING_SLASH, !empty($_POST['trailing_slash']) ? '1' : '0', $tenantId);
            PageRoutes::forget($tenantId);
            foreach ($next as $key => $type) {
                if (($types[$key]['route'] ?? '') !== $type['route']) {
                    AuditLog::record('studio.post_type_route_changed', (string) $tenantId, ['key' => $key, 'route' => $type['route']]);
                }
            }
            sb_flash_redirect('permalinks.php', ['type' => 'success', 'msg' => __('studio_permalinks_saved', 'Permalinks saved. If you changed a base, items no longer open at their old addresses: add Redirects for any you need to keep.')]);
        }
    }
}
$flash = $flash ?? sb_take_flash();

$slash = (string) Database::setting(PageRoutes::SETTING_TRAILING_SLASH, $tenantId) === '1';
$dis = $canEdit ? '' : ' disabled';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_permalinks_heading', 'Permalinks')) ?></h2>
            <p><?= e(__('studio_permalinks_intro', 'How the addresses of your pages and content are formed. A page lives at /its-slug; an item lives under its content type’s base.')) ?></p>
        </div>
    </div>

    <form method="post" class="sb-card">
        <?= csrf_field() ?>

        <div class="sb-field">
            <span class="sb-field__label"><?= e(__('studio_permalinks_bases', 'Content type addresses')) ?></span>
            <?php foreach ($types as $key => $type): ?>
                <?php $name = (string) $type['plural']; ?>
                <div class="sb-field">
                    <?php if (!empty($type['rewrite'])): ?>
                        <label class="sb-field__label" for="sb-base-<?= e((string) $key) ?>"><?= e($name) ?></label>
                        <input type="text" class="form-control" id="sb-base-<?= e((string) $key) ?>" name="base[<?= e((string) $key) ?>]" maxlength="120" value="<?= e('/' . trim((string) ($posted[$key] ?? $type['rewrite_slug']), '/')) ?>"<?= $dis ?>>
                        <p class="sb-field__hint"><code><?= e('/' . trim((string) $type['rewrite_slug'], '/') . '/' . __('studio_permalinks_sample', 'item-slug') . ($slash ? '/' : '')) ?></code></p>
                    <?php else: ?>
                        <span class="sb-field__label"><?= e($name) ?></span>
                        <p class="sb-field__hint"><code><?= e('/' . __('studio_permalinks_sample', 'item-slug') . ($slash ? '/' : '')) ?></code> · <a href="post-type-edit.php?key=<?= e(rawurlencode((string) $key)) ?>"><?= e(__('studio_permalinks_edit_type', 'Rewrite is off for this type')) ?></a></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($types === []): ?>
                <p class="sb-field__hint"><?= e(__('studio_permalinks_no_types', 'No content types yet. Pages live at /their-slug.')) ?></p>
            <?php endif; ?>
        </div>

        <div class="sb-field">
            <label class="sb-check" for="sb-trailing-slash" style="display: flex; gap: 8px; align-items: flex-start;">
                <input type="checkbox" name="trailing_slash" id="sb-trailing-slash" value="1" <?= $slash ? 'checked' : '' ?><?= $dis ?>>
                <span><?= e(__('studio_seo_trailing_slash', 'Page addresses end with a slash')) ?> (<code>/contact/</code>)</span>
            </label>
            <p class="sb-field__hint"><?= e(__('studio_seo_trailing_slash_hint', 'Both forms open the page; this sets the one in canonical tags, the sitemap and post links. Turn it on for a site moved from WordPress, so search engines keep the addresses they know.')) ?></p>
        </div>

        <?php if ($canEdit): ?>
            <button type="submit" class="btn btn-primary"><?= e(__('save_changes', 'Save changes')) ?></button>
        <?php endif; ?>
    </form>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
