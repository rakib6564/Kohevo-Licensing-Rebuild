<?php
/**
 * Kohevo Studio — Overview Dashboard (Executive Studio Console).
 *
 * Provides high-level KPIs, canvas builder launcher, live site preview,
 * feature showcase cards, and recent pages list.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/_page_bulk.php';

use Slate\Module\StudioBuilder\Admin\PageListView;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Mcp\StudioMcpToolCatalog;
use Slate\Module\StudioBuilder\Meta\MetaFieldTypes;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();

$studioApp   = StudioRuntimeFactory::build()->app;
$studioActor = StudioActor::fromCurrentSession();
$pageTitle   = __('studio_overview', 'Studio Overview');
$currentNav  = 'studio-overview';
$flash       = null;

// The Recent Studio Pages list has the same selection bar as the Pages screen, so it deletes the same way.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['bulk_action'] ?? '') === 'delete') {
        $result = sb_archive_pages($studioApp, $studioActor, $_POST['ids'] ?? []);
        header('Location: index.php?' . http_build_query($result), true, 303);
        exit;
    }
}
$flash = $flash ?? sb_bulk_delete_flash($_GET);

$pages = [];
$templates = [];
$listError = null;
try {
    $pages = $studioApp->listPages($studioActor);
    $templates = $studioApp->listTemplates($studioActor, 'page_template');
} catch (StudioException $e) {
    $listError = $e->httpStatus() === 403
        ? __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')
        : __('studio_pages_unavailable', 'Studio pages could not be loaded.');
}
$canEdit = $studioActor->can(StudioPermissions::EDIT) && $listError === null;

// The site's homepage (the page that serves `/`), for the homepage card. Pages come newest first, so a published one wins.
$homePage = null;
foreach ($pages as $p) {
    if (($p['route_mode'] ?? '') === 'homepage' && ($homePage === null || (!empty($p['is_published']) && empty($homePage['is_published'])))) {
        $homePage = $p;
    }
}
$mcpTools = count(StudioMcpToolCatalog::tools());
$mcpGateway = class_exists('PluginLoader') && PluginLoader::isActive('mcp-gateway');

// Stats calculations
$totalPages = count($pages);
$publishedCount = 0;
$draftCount = 0;
foreach ($pages as $p) {
    if (!empty($p['is_published'])) {
        $publishedCount++;
    } else {
        $draftCount++;
    }
}

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin-wrapper">
    <?php sb_render_admin_nav('overview', $homePage !== null ? (int) $homePage['id'] : null); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($listError !== null): ?>
        <div class="alert alert-error" role="status"><?= e($listError) ?></div>
    <?php endif; ?>

    <!-- KPI Metrics Strip -->
    <div class="sb-kpi-grid">
        <div class="sb-kpi-card">
            <div class="sb-kpi-top">
                <span class="sb-kpi-label"><?= e(__('studio_overview_total_pages', 'Total Pages')) ?></span>
                <?= sb_svg('pages', 20, 'sb-muted') ?>
            </div>
            <div class="sb-kpi-value"><?= $totalPages ?></div>
            <div class="sb-kpi-meta"><?= e(sprintf(__('studio_overview_published_drafts', '%1$d published · %2$d drafts'), $publishedCount, $draftCount)) ?></div>
        </div>
        <div class="sb-kpi-card">
            <div class="sb-kpi-top">
                <span class="sb-kpi-label"><?= e(__('studio_overview_templates', 'Page templates')) ?></span>
                <?= sb_svg('templates', 20, 'sb-muted') ?>
            </div>
            <div class="sb-kpi-value"><?= count($templates) ?></div>
            <div class="sb-kpi-meta"><?= e(__('studio_overview_templates_meta', 'In your template library')) ?></div>
        </div>
        <div class="sb-kpi-card">
            <div class="sb-kpi-top">
                <span class="sb-kpi-label"><?= e(__('studio_overview_homepage', 'Homepage')) ?></span>
                <?= sb_svg('themes', 20, 'sb-muted') ?>
            </div>
            <div class="sb-kpi-value" style="font-size: 1.3rem; font-weight: 700;"><?= e($homePage !== null ? (string) $homePage['title'] : __('studio_overview_no_homepage', 'None yet')) ?></div>
            <div class="sb-kpi-meta"><?= e($homePage === null ? __('studio_overview_no_homepage_meta', 'Set one in a page\'s settings') : (!empty($homePage['is_published']) ? __('studio_overview_home_live', 'Live at /') : __('studio_overview_home_draft', 'Not published yet'))) ?></div>
        </div>
        <div class="sb-kpi-card">
            <div class="sb-kpi-top">
                <span class="sb-kpi-label"><?= e(__('studio_overview_ai', 'AI assistant tools')) ?></span>
                <?= sb_svg('ai-mcp', 20, 'sb-muted') ?>
            </div>
            <div class="sb-kpi-value"><?= $mcpTools ?></div>
            <div class="sb-kpi-meta"><?= e($mcpGateway ? __('studio_overview_ai_on', 'MCP gateway installed') : __('studio_overview_ai_off', 'MCP gateway not installed')) ?></div>
        </div>
    </div>

    <!-- Featured Subsystems Showcase -->
    <div class="sb-feature-grid">
        <div class="sb-feature-card">
            <div>
                <div class="sb-feature-header">
                    <div class="sb-feature-icon-box"><?= sb_svg('zap', 22) ?></div>
                    <div>
                        <h3 class="sb-feature-title"><?= e($homePage !== null ? (string) $homePage['title'] : __('studio_overview_homepage', 'Homepage')) ?></h3>
                        <p class="sb-feature-desc"><?= e($homePage !== null ? __('studio_overview_home_desc', 'The page visitors see at your site\'s address.') : __('studio_overview_home_none_desc', 'No page is set as the homepage yet. Open a page\'s settings and choose Site Homepage.')) ?></p>
                    </div>
                </div>
                <?php if ($homePage !== null): ?>
                    <div style="font-size: 0.85rem; color: #64748b; margin-top: 8px;">
                        <?= e(__('studio_overview_path', 'Path')) ?>: <code>/</code> · <span class="badge <?= !empty($homePage['is_published']) ? 'badge-active' : 'badge-muted' ?>"><?= e(!empty($homePage['is_published']) ? __('studio_status_published', 'Published') : __('studio_status_draft', 'Draft')) ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="sb-feature-footer">
                <?php if ($homePage !== null): ?>
                    <a href="<?= e(plugin_url('studio-builder', 'admin/builder.php') . '?page=' . (int) $homePage['id']) ?>" class="btn btn-sm btn-primary">
                        <?= sb_svg('edit', 14) ?>
                        <span><?= e(__('studio_overview_edit', 'Edit in Canvas')) ?></span>
                    </a>
                    <a href="<?= e(rtrim(SLATE_URL, '/') . '/') ?>" class="btn btn-sm btn-outline" target="_blank" rel="noopener">
                        <?= sb_svg('external-link', 14) ?>
                        <span><?= e(__('studio_overview_view', 'View live')) ?></span>
                    </a>
                <?php else: ?>
                    <a href="pages.php" class="btn btn-sm btn-primary"><?= sb_svg('pages', 14) ?> <span><?= e(__('studio_pages', 'Pages')) ?></span></a>
                <?php endif; ?>
            </div>
        </div>

        <div class="sb-feature-card">
            <div>
                <div class="sb-feature-header">
                    <div class="sb-feature-icon-box"><?= sb_svg('templates', 22) ?></div>
                    <div>
                        <h3 class="sb-feature-title"><?= e(__('studio_overview_tb_title', 'Theme Builder Engine')) ?></h3>
                        <p class="sb-feature-desc"><?= e(__('studio_overview_tb_desc', 'Craft reusable site headers, footers, archive templates, loop grids, and single page templates across your brand.')) ?></p>
                    </div>
                </div>
                <div style="font-size: 0.85rem; color: #64748b; margin-top: 8px;">
                    <?= e(__('studio_overview_tb_kinds', 'Headers · Footers · Archive Templates · Bento Grids · Single Post Layouts')) ?>
                </div>
            </div>
            <div class="sb-feature-footer">
                <a href="templates.php" class="btn btn-sm btn-primary">
                    <?= sb_svg('templates', 14) ?>
                    <span><?= e(__('studio_overview_tb_manage', 'Manage Templates')) ?></span>
                </a>
                <span class="badge badge-info"><?= e(__('studio_overview_tb_badge', 'Theme Builder')) ?></span>
            </div>
        </div>

        <div class="sb-feature-card">
            <div>
                <div class="sb-feature-header">
                    <div class="sb-feature-icon-box"><?= sb_svg('types-fields', 22) ?></div>
                    <div>
                        <h3 class="sb-feature-title"><?= e(__('studio_overview_tf_title', 'Post Types & Custom Meta Fields')) ?></h3>
                        <p class="sb-feature-desc"><?= e(__('studio_overview_tf_desc', 'Architect custom data types (Portfolio, Case Studies, Services) and bind dynamic fields to visual blocks seamlessly.')) ?></p>
                    </div>
                </div>
                <div style="font-size: 0.85rem; color: #64748b; margin-top: 8px;">
                    <?= e(sprintf(__('studio_overview_field_types', '%d field types, from text and numbers to images, relations and repeaters.'), count(MetaFieldTypes::all()))) ?>
                </div>
            </div>
            <div class="sb-feature-footer">
                <a href="types-fields.php" class="btn btn-sm btn-primary">
                    <?= sb_svg('tag', 14) ?>
                    <span><?= e(__('studio_overview_tf_manage', 'Define Types & Fields')) ?></span>
                </a>
                <span class="badge badge-info"><?= e(__('studio_overview_tf_badge', 'Query Loop Ready')) ?></span>
            </div>
        </div>
    </div>

    <!-- Recent Studio Pages Section (Satisfies StudioBuilderAdminListTest.php) -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2><?= e(__('studio_overview_recent', 'Recent Studio Pages')) ?></h2>
                <div class="card-sub"><?= e(__('studio_overview_recent_sub', 'Open any page in the Visual Builder or inspect public preview.')) ?></div>
            </div>
            <a href="pages.php" class="btn btn-sm btn-outline">
                <?= sb_svg('pages', 14) ?>
                <span><?= e(__('studio_overview_all_pages', 'View All Pages')) ?></span>
            </a>
        </div>
        <?php if ($pages === []): ?>
            <div class="empty">
                <div class="empty-title"><?= e(__('studio_no_pages', 'No Studio pages yet')) ?></div>
                <p class="text-sm"><?= e(__('studio_no_pages_first', 'Create your first page to open it in the builder.')) ?></p>
            </div>
        <?php else: ?>
            <form method="post" id="studio-bulk-form">
                <?= csrf_field() ?>
                <?= PageListView::render($pages, $canEdit, plugin_url('studio-builder', 'admin/builder.php'), plugin_url('studio-builder', 'admin/preview.php'), $canEdit, 'page-edit.php') ?>
            </form>
            <?php slate_data_list_script(); ?>
            <?= PageListView::selectionScript() ?>
        <?php endif; ?>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
