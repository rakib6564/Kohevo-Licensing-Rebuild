<?php
/**
 * Kohevo Studio — Content types: the site's post types.
 *
 * The list only. Each type is set up on its own screen (post-type-edit.php): its settings, labels, meta fields, admin
 * columns and filters. Its items are on pages.php?type=<key>; values are read through `PageMeta`.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PostTypeSettings;

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_types_fields', 'Types & fields');
$currentNav = 'studio-types-fields';

$flash = null;

// The site's post types: Studio ships none built in, so every type here is one the site created.
$allPostTypes = PageMeta::postTypes($tenantId);
// The field definitions, read exactly as the pages read them.
$metaFields = PageMeta::allFields($tenantId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage types and fields.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } elseif (($_POST['_action'] ?? '') === 'delete_post_type') {
        // Fields attached to the type go with it; content items already created are not touched.
        $key = trim((string) ($_POST['key'] ?? ''));
        if (isset($allPostTypes[$key])) {
            $label = (string) $allPostTypes[$key]['singular'];
            unset($allPostTypes[$key]);
            PageMeta::savePostTypes($tenantId, $allPostTypes);
            PageMeta::saveFields($tenantId, array_filter($metaFields, static fn(array $f): bool => ($f['post_type'] ?? '') !== $key));
            AuditLog::record('studio.post_type_deleted', (string) $tenantId, ['key' => $key]);
            sb_flash_redirect('types-fields.php', ['type' => 'success', 'msg' => sprintf(__('studio_tf_type_deleted', "Post type '%s' deleted."), $label)]);
        }
    }
}

$flash = $flash ?? sb_take_flash();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_tf_heading', 'Post Types & Dynamic Schema')) ?></h2>
            <p><?= e(__('studio_tf_intro', 'Create custom content models and bind dynamic fields to Query Loops and Single Templates.')) ?></p>
        </div>
        <?php if ($canEdit): ?>
        <div class="sb-screen-actions">
            <a class="btn btn-primary" href="post-type-edit.php" data-testid="new-post-type"><?= sb_svg('plus', 14) ?> <?= e(__('studio_tf_new_type', 'New Post Type')) ?></a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($allPostTypes === []): ?>
        <div class="sb-card" style="text-align: center; color: var(--sb-muted);"><?= e(__('studio_tf_no_types', 'No post types. Create one with “New Post Type”.')) ?></div>
    <?php endif; ?>
    <div class="sb-type-grid">
        <?php foreach (PostTypeSettings::sorted($allPostTypes) as $tKey => $pt):
            $fieldCount = count(array_filter($metaFields, fn($f) => ($f['post_type'] ?? '') === $tKey));
            $editHref = 'post-type-edit.php?key=' . rawurlencode((string) $tKey);
        ?>
            <div class="card sb-type-card" data-post-type="<?= e((string) $tKey) ?>">
                <div class="sb-type-card__top">
                    <code><?= e((string) $tKey) ?></code>
                    <span class="sb-field__hint"><?= e(sprintf(__('studio_tf_field_count', '%d field(s)'), $fieldCount)) ?></span>
                </div>
                <h3><a href="<?= e($editHref) ?>"><?= e((string) $pt['plural']) ?></a></h3>
                <p><?= e($pt['description'] !== '' ? (string) $pt['description'] : __('studio_tf_type_description_default', 'Custom content collection.')) ?></p>
                <p class="sb-field__hint"><?= e(__('studio_tf_address', 'Address:')) ?> /<?= e($pt['rewrite'] && $pt['rewrite_slug'] !== '' ? $pt['rewrite_slug'] . '/' : '') ?>item-slug</p>
                <div class="sb-type-card__actions">
                    <a class="btn btn-sm btn-primary" href="<?= e($editHref) ?>" data-testid="type-edit-<?= e((string) $tKey) ?>"><?= e(__('edit', 'Edit')) ?></a>
                    <?php if (PostTypeSettings::hasAdmin($pt)): ?>
                        <a class="btn btn-sm btn-outline" href="<?= e(plugin_url('studio-builder', 'admin/pages.php?type=' . rawurlencode((string) $tKey))) ?>" data-testid="type-items-<?= e((string) $tKey) ?>"><?= e(__('studio_tf_view_items', 'View items')) ?></a>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/types-fields.php')) ?>" onsubmit="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?= e(sprintf(__('studio_tf_delete_type_confirm', 'Delete the post type %s? Its meta fields are deleted too. Content already created is kept.'), (string) $pt['singular'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="delete_post_type">
                            <input type="hidden" name="key" value="<?= e((string) $tKey) ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="<?= e(__('studio_tf_delete_type_title', 'Delete this post type')) ?>" aria-label="<?= e(sprintf(__('studio_tf_delete_type_aria', 'Delete the post type %s'), (string) $pt['singular'])) ?>">
                                <?= sb_svg('trash', 12) ?> <?= e(__('delete', 'Delete')) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
