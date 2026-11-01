<?php
/**
 * Kohevo Studio — Saved Templates: everything saved from the builder as a reusable page, section or widget.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();
Auth::requirePerm('studio-builder.view');

$canDelete = Auth::can('studio-builder.admin') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_saved_templates', 'Saved Templates');
$currentNav = 'studio-saved-templates';

$types = ['page_template' => __('studio_saved_page', 'Page'), 'container_preset' => __('studio_saved_section', 'Section'), 'block_preset' => __('studio_saved_widget', 'Widget')];

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canDelete) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to do this.')];
    } else {
        try {
            StudioRuntimeFactory::build()->app->deleteTemplate(StudioActor::fromCurrentSession(), (string) ($_POST['template_key'] ?? ''));
            sb_flash_redirect('saved-templates.php', ['type' => 'success', 'msg' => __('studio_saved_deleted', 'Template deleted.')]);
        } catch (\Throwable $e) {
            error_log('[studio-builder] saved templates: ' . $e::class . ': ' . $e->getMessage());
            $flash = ['type' => 'error', 'msg' => __('studio_saved_delete_failed', 'That template could not be deleted.')];
        }
    }
}
$flash = $flash ?? sb_take_flash();

// Yours only: the shipped presets, and the templates that back Theme Builder pages, have their own screens.
$rows = Database::rows(
    "SELECT t.template_key, t.template_type, t.category, t.name, t.description, t.created_at, u.name AS author
     FROM studiobuilder_templates t
     LEFT JOIN users u ON u.id = t.created_by
     LEFT JOIN studiobuilder_pages p ON p.tenant_id = t.tenant_id AND p.slug = CONCAT('template-', t.template_key)
     WHERE t.tenant_id = ? AND t.is_system = 0 AND p.id IS NULL AND t.template_type IN ('page_template', 'container_preset', 'block_preset')
     ORDER BY t.created_at DESC",
    [$tenantId]
);
$counts = array_count_values(array_column($rows, 'template_type'));
$filter = isset($types[$_GET['type'] ?? '']) ? (string) $_GET['type'] : '';
$search = trim((string) ($_GET['q'] ?? ''));
$shown = array_filter($rows, static fn(array $r): bool => ($filter === '' || $r['template_type'] === $filter)
    && ($search === '' || mb_stripos((string) $r['name'], $search) !== false));

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_saved_heading', 'Saved Templates')) ?></h2>
            <p><?= e(__('studio_saved_intro', 'Pages, sections and widgets you saved in the builder. Insert them from the builder’s Library.')) ?></p>
        </div>
        <form method="get">
            <?php if ($filter !== ''): ?><input type="hidden" name="type" value="<?= e($filter) ?>"><?php endif; ?>
            <input type="search" name="q" class="form-control" value="<?= e($search) ?>" placeholder="<?= e(__('studio_saved_search', 'Search templates')) ?>" aria-label="<?= e(__('studio_saved_search', 'Search templates')) ?>">
        </form>
    </div>

    <div class="sb-pills" style="margin-bottom: 12px;">
        <a href="saved-templates.php" class="sb-pill-item <?= $filter === '' ? 'active' : '' ?>"><?= e(__('studio_filter_all', 'All')) ?> (<?= count($rows) ?>)</a>
        <?php foreach ($types as $type => $label): ?>
            <a href="saved-templates.php?type=<?= e($type) ?>" class="sb-pill-item <?= $filter === $type ? 'active' : '' ?>"><?= e($label) ?> (<?= (int) ($counts[$type] ?? 0) ?>)</a>
        <?php endforeach; ?>
    </div>

    <section class="sb-card">
        <?php if ($shown === []): ?>
            <p class="sb-card__note"><?= e(__('studio_saved_empty', 'Nothing here yet. In the builder, use Save as template on a page, section or widget.')) ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="sb-table table" data-testid="saved-templates">
                    <thead>
                        <tr>
                            <th><?= e(__('studio_saved_title', 'Title')) ?></th>
                            <th><?= e(__('studio_saved_type', 'Type')) ?></th>
                            <th><?= e(__('studio_saved_category', 'Category')) ?></th>
                            <th><?= e(__('studio_saved_author', 'Author')) ?></th>
                            <th><?= e(__('studio_saved_date', 'Date')) ?></th>
                            <?php if ($canDelete): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shown as $row): ?>
                            <tr>
                                <td><strong><?= e((string) $row['name']) ?></strong><?php if (!empty($row['description'])): ?><br><small><?= e((string) $row['description']) ?></small><?php endif; ?></td>
                                <td><?= e($types[$row['template_type']]) ?></td>
                                <td><?= e((string) $row['category']) ?></td>
                                <td><?= e((string) ($row['author'] ?? '—')) ?></td>
                                <td><?= e((string) date('Y/m/d', (int) strtotime((string) $row['created_at']))) ?></td>
                                <?php if ($canDelete): ?>
                                    <td>
                                        <form method="post" onsubmit="return confirm(<?= e((string) json_encode(__('studio_saved_confirm', 'Delete this template? Pages that used it keep their content.'), JSON_HEX_APOS | JSON_HEX_QUOT)) ?>);">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="template_key" value="<?= e((string) $row['template_key']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline"><?= e(__('studio_saved_delete', 'Delete')) ?></button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
