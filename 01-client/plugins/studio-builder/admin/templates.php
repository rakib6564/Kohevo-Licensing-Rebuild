<?php
/**
 * Kohevo Studio — Theme Builder & Dynamic Templates.
 *
 * Dedicated admin page for site-wide Headers, Footers, Single Page/Post templates,
 * Archive layouts, Loop Grid templates, and 404 Error pages.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_templates', 'Theme Builder');
$currentNav = 'studio-templates';

$flash = null;

// POST Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage templates.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');

        if ($action === 'create_template') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $type = trim((string) ($_POST['template_type'] ?? 'header'));
            $condition = trim((string) ($_POST['condition_rule'] ?? 'entire_site'));
            $description = trim((string) ($_POST['description'] ?? ''));

            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => 'Template name is required.'];
            } else {
                $templateKey = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name));
                $templateKey = trim($templateKey, '-') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);

                // Default minimal document JSON
                $starterDoc = [
                    'schema_version' => 1,
                    'root' => [
                        'id' => 'root',
                        'type' => 'layout.section',
                        'label' => $name,
                        'props' => [
                            'tag' => ($type === 'header' ? 'header' : ($type === 'footer' ? 'footer' : 'section')),
                            'padding_y' => 'medium',
                            'theme_type' => $type,
                            'condition' => $condition,
                        ],
                        'children' => []
                    ]
                ];

                try {
                    // Create in studiobuilder_templates table
                    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );

                    $templateId = Database::insert('studiobuilder_templates', [
                        'tenant_id' => $tenantId,
                        'uuid' => $uuid,
                        'template_key' => $templateKey,
                        'template_type' => $type,
                        'category' => 'theme_builder',
                        'name' => $name,
                        'description' => $description !== '' ? $description : "Theme {$type} template: {$name}",
                        'schema_version' => 1,
                        'document_json' => json_encode($starterDoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'is_system' => 0,
                        'created_by' => (int) (Auth::id() ?? 1),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                    // Also create a linked backing page so it can be edited immediately in visual builder.php
                    $pageSlug = 'template-' . $templateKey;
                    $pageId = Database::insert('studiobuilder_pages', [
                        'tenant_id' => $tenantId,
                        'slug' => $pageSlug,
                        'title' => "[Theme] {$name}",
                        'route_mode' => 'custom',
                        'status' => 'draft',
                        'published_revision_id' => null,
                        'seo_json' => json_encode(['noindex' => true]),
                        'settings_json' => json_encode([
                            'is_theme_template' => true,
                            'template_id' => $templateId,
                            'template_type' => $type,
                            'condition_rule' => $condition,
                        ]),
                        'created_by' => (int) (Auth::id() ?? 1),
                        'updated_by' => (int) (Auth::id() ?? 1),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                    // Create revision #1 for the page
                    Database::insert('studiobuilder_revisions', [
                        'tenant_id' => $tenantId,
                        'page_id' => $pageId,
                        'revision_number' => 1,
                        'revision_kind' => 'manual',
                        'schema_version' => 1,
                        'document_json' => json_encode($starterDoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'summary' => "Theme template created: {$name} ({$type})",
                        'parent_revision_id' => null,
                        'created_by' => (int) (Auth::id() ?? 1),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);

                    $flash = ['type' => 'success', 'msg' => "Theme template '{$name}' created! Backing page ID #{$pageId} ready for visual editing."];
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Failed to create template: ' . $e->getMessage()];
                }
            }
        } elseif ($action === 'delete_template') {
            $templateId = (int) ($_POST['template_id'] ?? 0);
            if ($templateId > 0) {
                try {
                    Database::delete('studiobuilder_templates', 'tenant_id = ? AND id = ? AND is_system = 0', [$tenantId, $templateId]);
                    $flash = ['type' => 'success', 'msg' => 'Template deleted successfully.'];
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Error deleting template: ' . $e->getMessage()];
                }
            }
        }
    }
}

// Filter
$typeFilter = trim((string) ($_GET['type'] ?? 'all'));

$sql = "SELECT * FROM studiobuilder_templates WHERE tenant_id = ?";
$params = [$tenantId];

if ($typeFilter !== 'all') {
    $sql .= " AND template_type = ?";
    $params[] = $typeFilter;
}

$sql .= " ORDER BY id DESC";

try {
    $templates = Database::all($sql, $params);
} catch (\Throwable $e) {
    $templates = [];
}

// Fetch backing pages to link visual builder
$pagesMap = [];
try {
    $tplPages = Database::all("SELECT id, slug, title, settings_json FROM studiobuilder_pages WHERE tenant_id = ? AND settings_json LIKE '%is_theme_template%'", [$tenantId]);
    foreach ($tplPages as $p) {
        $meta = json_decode((string) ($p['settings_json'] ?? '{}'), true) ?: [];
        $tid = (int) ($meta['template_id'] ?? 0);
        if ($tid > 0) {
            $pagesMap[$tid] = (int) $p['id'];
        }
    }
} catch (\Throwable $e) {
    $pagesMap = [];
}

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('templates', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Subtitle & Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div class="sb-pill-filter" style="margin-bottom: 0;">
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" class="sb-pill-item <?= $typeFilter === 'all' ? 'active' : '' ?>">
                <?= sb_svg('templates', 14) ?> All Templates (<?= count($templates) ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=header')) ?>" class="sb-pill-item <?= $typeFilter === 'header' ? 'active' : '' ?>">
                <?= sb_svg('layer', 14) ?> Headers
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=footer')) ?>" class="sb-pill-item <?= $typeFilter === 'footer' ? 'active' : '' ?>">
                <?= sb_svg('layer', 14) ?> Footers
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=single')) ?>" class="sb-pill-item <?= $typeFilter === 'single' ? 'active' : '' ?>">
                <?= sb_svg('pages', 14) ?> Single Pages
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=archive')) ?>" class="sb-pill-item <?= $typeFilter === 'archive' ? 'active' : '' ?>">
                <?= sb_svg('content', 14) ?> Archives & Loop Grids
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=not_found')) ?>" class="sb-pill-item <?= $typeFilter === 'not_found' ? 'active' : '' ?>">
                <?= sb_svg('alert-circle', 14) ?> 404 Pages
            </a>
        </div>

        <button type="button" class="btn btn-primary" onclick="document.getElementById('sb-tpl-drawer').style.display='block'">
            <?= sb_svg('plus', 14) ?> New Theme Template
        </button>
    </div>

    <!-- Create Template Drawer / Form -->
    <div id="sb-tpl-drawer" style="display: none; background: #ffffff; border: 1px solid var(--sb-border); border-radius: 14px; padding: 24px; margin-bottom: 28px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
            <div style="font-weight: 700; font-size: 1.15rem; color: var(--sb-text); display: flex; align-items: center; gap: 8px;">
                <?= sb_svg('templates', 20) ?> Create Theme Builder Template
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('sb-tpl-drawer').style.display='none'">
                <?= sb_svg('close', 14) ?>
            </button>
        </div>
        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="create_template">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Template Name <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Modern Glass Header" required>
                </div>
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Template Type</label>
                    <select name="template_type" class="form-control" style="background: #ffffff;">
                        <option value="header">Header (Top navigation & hero chrome)</option>
                        <option value="footer">Footer (Site-wide footer & bottom chrome)</option>
                        <option value="single">Single Post / Page (Dynamic single item view)</option>
                        <option value="archive">Archive & Loop Grid (Dynamic collection feed)</option>
                        <option value="not_found">404 Error (Custom Not Found page)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Display Condition</label>
                    <select name="condition_rule" class="form-control" style="background: #ffffff;">
                        <option value="entire_site">Entire Site (Global Default)</option>
                        <option value="front_page">Front Page / Homepage Only</option>
                        <option value="all_portfolio">All Portfolio Items</option>
                        <option value="all_posts">All Blog Posts</option>
                        <option value="all_services">All Service Offerings</option>
                        <option value="singular_pages">Regular Pages</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom: 20px;">
                <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Description / Notes</label>
                <input type="text" name="description" class="form-control" placeholder="Optional notes about design intent or typography...">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-tpl-drawer').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary"><?= sb_svg('check', 14) ?> Create & Open in Builder</button>
            </div>
        </form>
    </div>

    <!-- Active Templates List -->
    <?php if (empty($templates)): ?>
        <div class="card" style="padding: 48px 24px; text-align: center; border-radius: 16px; border: 1px dashed var(--sb-border); margin-bottom: 32px;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; color: var(--sb-muted);">
                <?= sb_svg('templates', 28) ?>
            </div>
            <h3 style="font-weight: 700; color: var(--sb-text); margin-bottom: 8px;">No theme templates yet</h3>
            <p style="color: var(--sb-muted); font-size: 0.9rem; max-width: 460px; margin: 0 auto 20px auto;">
                Create full-site Headers, Footers, dynamic Loop Grids, or Single Page layouts to build a completely unified visual design system.
            </p>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('sb-tpl-drawer').style.display='block'">
                <?= sb_svg('plus', 16) ?> Create First Template
            </button>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; margin-bottom: 36px;">
            <?php foreach ($templates as $tpl):
                $tid = (int) $tpl['id'];
                $tName = (string) ($tpl['name'] ?? 'Untitled Template');
                $tType = (string) ($tpl['template_type'] ?? 'header');
                $tDesc = (string) ($tpl['description'] ?? '');
                $isSys = (bool) ($tpl['is_system'] ?? false);
                $backingPageId = $pagesMap[$tid] ?? ($portfolioPageId ?? 1);
                $builderUrl = plugin_url('studio-builder', 'admin/builder.php') . '?page=' . $backingPageId;

                $typeBadgeColor = match($tType) {
                    'header' => '#6366f1',
                    'footer' => '#8b5cf6',
                    'single' => '#0ea5e9',
                    'archive' => '#10b981',
                    'not_found' => '#f59e0b',
                    default => '#64748b',
                };
            ?>
                <div class="card" style="border-radius: 14px; border: 1px solid var(--sb-border); padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <span style="background: <?= $typeBadgeColor ?>15; color: <?= $typeBadgeColor ?>; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em;">
                                <?= e($tType) ?>
                            </span>
                            <?php if ($isSys): ?>
                                <span style="font-size: 0.72rem; color: var(--sb-muted); font-weight: 600;">System Preset</span>
                            <?php else: ?>
                                <span style="font-size: 0.72rem; color: #10b981; font-weight: 600; display: flex; align-items: center; gap: 4px;">
                                    <?= sb_svg('check', 12) ?> Active
                                </span>
                            <?php endif; ?>
                        </div>
                        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--sb-text); margin: 0 0 6px 0;"><?= e($tName) ?></h3>
                        <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0 0 14px 0;">
                            <?= e($tDesc !== '' ? $tDesc : "Theme {$tType} layout with dynamic slot bindings.") ?>
                        </p>
                    </div>

                    <div style="border-top: 1px solid var(--sb-border); padding-top: 14px; display: flex; justify-content: space-between; align-items: center;">
                        <a href="<?= e($builderUrl) ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener">
                            <?= sb_svg('edit', 12) ?> Edit in Canvas
                        </a>
                        <?php if (!$isSys && $canEdit): ?>
                            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" onsubmit="return confirm('Delete this theme template?');" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_template">
                                <input type="hidden" name="template_id" value="<?= $tid ?>">
                                <button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444;" title="Delete Template">
                                    <?= sb_svg('trash', 12) ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Theme Builder Architecture & Blueprint Guidance -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
        <div class="card" style="background: #ffffff; border: 1px solid var(--sb-border); border-radius: 14px; padding: 20px;">
            <div style="color: var(--sb-accent); margin-bottom: 10px;">
                <?= sb_svg('layer', 24) ?>
            </div>
            <h4 style="font-weight: 700; color: var(--sb-text); font-size: 1rem; margin-bottom: 6px;">Global Chrome Integration</h4>
            <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0;">
                Headers and Footers attach automatically to published pages matching their display conditions (via <code>ThemeTemplateResolver</code>), eliminating duplicate layout markup across your site.
            </p>
        </div>

        <div class="card" style="background: #ffffff; border: 1px solid var(--sb-border); border-radius: 14px; padding: 20px;">
            <div style="color: #10b981; margin-bottom: 10px;">
                <?= sb_svg('content', 24) ?>
            </div>
            <h4 style="font-weight: 700; color: var(--sb-text); font-size: 1rem; margin-bottom: 6px;">Loop Grid & Query Builder</h4>
            <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0;">
                Archive and loop grid templates consume data feeds from <code>core.query_loop</code>. Bind custom fields like client, year, tags, and cover images to repeat automatically across cards.
            </p>
        </div>

        <div class="card" style="background: #ffffff; border: 1px solid var(--sb-border); border-radius: 14px; padding: 20px;">
            <div style="color: #0ea5e9; margin-bottom: 10px;">
                <?= sb_svg('pages', 24) ?>
            </div>
            <h4 style="font-weight: 700; color: var(--sb-text); font-size: 1rem; margin-bottom: 6px;">Dynamic Single Post Templates</h4>
            <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0;">
                Design once, render for every portfolio item or case study. Single templates inject dynamic content tokens: <code>{{post.title}}</code>, <code>{{meta.client}}</code>, and <code>{{post.content}}</code>.
            </p>
        </div>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
