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

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_templates', 'Theme Builder');
$currentNav = 'studio-templates';

$flash = null;

// Template type configuration mapping
$typeConfigs = [
    'header' => [
        'template_type' => 'header_preset',
        'page_type'     => 'header_partial',
        'doc_type'      => 'header_partial',
        'category'      => 'header',
        'role'          => 'header',
        'label'         => 'Header',
    ],
    'footer' => [
        'template_type' => 'footer_preset',
        'page_type'     => 'footer_partial',
        'doc_type'      => 'footer_partial',
        'category'      => 'footer',
        'role'          => 'footer',
        'label'         => 'Footer',
    ],
    'single' => [
        'template_type' => 'page_template',
        'page_type'     => 'page',
        'doc_type'      => 'page',
        'category'      => 'single',
        'role'          => 'single',
        'label'         => 'Single Page',
    ],
    'archive' => [
        'template_type' => 'page_template',
        'page_type'     => 'system',
        'doc_type'      => 'system',
        'category'      => 'archive',
        'role'          => 'archive',
        'label'         => 'Archive & Loop Grid',
    ],
    'not_found' => [
        'template_type' => 'page_template',
        'page_type'     => 'system',
        'doc_type'      => 'system',
        'category'      => 'not_found',
        'role'          => '404',
        'label'         => '404 Not Found',
    ],
    'section_preset' => [
        'template_type' => 'section_preset',
        'page_type'     => 'section_preset',
        'doc_type'      => 'section_preset',
        'category'      => 'section',
        'role'          => 'section_preset',
        'label'         => 'Section Preset',
    ],
];

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
            $uiType = trim((string) ($_POST['template_type'] ?? 'header'));
            $condition = trim((string) ($_POST['condition_rule'] ?? 'entire_site'));
            $description = trim((string) ($_POST['description'] ?? ''));
            $publishNow = !empty($_POST['publish_now']);

            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => 'Template name is required.'];
            } else {
                $cfg = $typeConfigs[$uiType] ?? $typeConfigs['header'];

                // Clean slug for template_key conforming to TEMPLATE_KEY_PATTERN
                $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
                $slug = trim((string) $slug, '-');
                if ($slug === '') {
                    $slug = 'theme';
                }
                $templateKey = $slug . '-' . substr(bin2hex(random_bytes(3)), 0, 4);

                // Map condition rules into structured format evaluated by TemplateConditionMatcher
                $conditionRules = match($condition) {
                    'front_page'     => [['type' => 'include', 'condition' => 'front_page'], ['type' => 'include', 'condition' => 'slug', 'value' => 'home']],
                    'all_portfolio'  => [['type' => 'include', 'condition' => 'singular', 'value' => 'portfolio']],
                    'all_posts'      => [['type' => 'include', 'condition' => 'singular', 'value' => 'post']],
                    'all_services'   => [['type' => 'include', 'condition' => 'singular', 'value' => 'services']],
                    'singular_pages' => [['type' => 'include', 'condition' => 'singular', 'value' => 'page']],
                    default          => [['type' => 'include', 'condition' => 'entire_site']],
                };

                // Canonical starter document
                $starterDoc = CanonicalDocumentSchema::emptyDocument($cfg['doc_type'], 'default', $name);
                $starterDoc['settings']['conditions'] = ['rules' => $conditionRules];
                $starterDoc['settings']['template_type'] = $cfg['role'];

                $secId = CanonicalDocumentSchema::newSectionId();
                $blkId = CanonicalDocumentSchema::newBlockId();

                if ($uiType === 'header') {
                    $starterDoc['sections'] = [
                        [
                            'id'         => $secId,
                            'label'      => 'Header Navigation',
                            'global_ref' => null,
                            'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
                                'padding_y' => ['base' => 'sm', 'md' => 'md'],
                                'width'     => 'wide',
                            ]),
                            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                            'blocks'     => [
                                [
                                    'id'         => $blkId,
                                    'type'       => 'core.heading',
                                    'version'    => 1,
                                    'props'      => [
                                        'text'  => $name,
                                        'level' => 'h2',
                                    ],
                                    'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                                    'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                                    'bindings'   => [],
                                    'children'   => [],
                                ],
                            ],
                        ],
                    ];
                } elseif ($uiType === 'footer') {
                    $starterDoc['sections'] = [
                        [
                            'id'         => $secId,
                            'label'      => 'Footer Chrome',
                            'global_ref' => null,
                            'layout'     => array_merge(CanonicalDocumentSchema::defaultSectionLayout(), [
                                'padding_y' => ['base' => 'md', 'md' => 'lg'],
                                'width'     => 'wide',
                            ]),
                            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                            'blocks'     => [
                                [
                                    'id'         => $blkId,
                                    'type'       => 'core.rich_text',
                                    'version'    => 1,
                                    'props'      => [
                                        'content' => '<p>© ' . date('Y') . ' ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '. All rights reserved.</p>',
                                    ],
                                    'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                                    'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                                    'bindings'   => [],
                                    'children'   => [],
                                ],
                            ],
                        ],
                    ];
                } else {
                    $starterDoc['sections'] = [
                        [
                            'id'         => $secId,
                            'label'      => $name . ' Layout',
                            'global_ref' => null,
                            'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
                            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                            'blocks'     => [
                                [
                                    'id'         => $blkId,
                                    'type'       => 'core.heading',
                                    'version'    => 1,
                                    'props'      => [
                                        'text'  => $name,
                                        'level' => 'h1',
                                    ],
                                    'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                                    'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                                    'bindings'   => [],
                                    'children'   => [],
                                ],
                            ],
                        ],
                    ];
                }

                try {
                    $genUuid = static fn(): string => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    $tplUuid = $genUuid();
                    $pageUuid = $genUuid();

                    // 1. Create in studiobuilder_templates
                    $templateId = Database::insert('studiobuilder_templates', [
                        'tenant_id'          => $tenantId,
                        'uuid'               => $tplUuid,
                        'template_key'       => $templateKey,
                        'template_type'      => $cfg['template_type'],
                        'category'           => $cfg['category'],
                        'name'               => $name,
                        'description'        => $description !== '' ? $description : "Theme {$uiType} template: {$name}",
                        'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
                        'document_json'      => CanonicalJson::encode($starterDoc),
                        'is_system'          => 0,
                        'created_by'         => (int) (Auth::id() ?? 1),
                        'created_at'         => date('Y-m-d H:i:s'),
                        'updated_at'         => date('Y-m-d H:i:s'),
                    ]);

                    // 2. Create linked backing page so it can be edited in builder.php and resolved by ThemeTemplateResolver
                    $pageSlug = 'template-' . $templateKey;
                    $pageSettings = [
                        'is_theme_template' => true,
                        'template_id'       => $templateId,
                        'template_type'     => $cfg['role'],
                        'condition_rule'    => $condition,
                        'conditions'        => ['rules' => $conditionRules],
                    ];

                    $pageId = Database::insert('studiobuilder_pages', [
                        'tenant_id'                => $tenantId,
                        'uuid'                     => $pageUuid,
                        'slug'                     => $pageSlug,
                        'title'                    => "[Theme] {$name}",
                        'page_type'                => $cfg['page_type'],
                        'route_mode'               => 'standalone',
                        'status'                   => 'draft',
                        'active_draft_revision_id' => null,
                        'published_revision_id'    => null,
                        'seo_json'                 => json_encode(['noindex' => true]),
                        'settings_json'            => CanonicalJson::encode($pageSettings),
                        'created_by'               => (int) (Auth::id() ?? 1),
                        'updated_by'               => (int) (Auth::id() ?? 1),
                        'created_at'               => date('Y-m-d H:i:s'),
                        'updated_at'               => date('Y-m-d H:i:s'),
                    ]);

                    // 3. Create revision #1 for the page
                    $revId = Database::insert('studiobuilder_revisions', [
                        'tenant_id'          => $tenantId,
                        'page_id'            => $pageId,
                        'revision_number'    => 1,
                        'revision_kind'      => 'manual',
                        'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
                        'document_json'      => CanonicalJson::encode($starterDoc),
                        'summary'            => "Theme template created: {$name} ({$uiType})",
                        'parent_revision_id' => null,
                        'created_by'         => (int) (Auth::id() ?? 1),
                        'created_at'         => date('Y-m-d H:i:s'),
                    ]);

                    // Update active_draft_revision_id pointer on the page
                    Database::query("UPDATE studiobuilder_pages SET active_draft_revision_id = ? WHERE id = ? AND tenant_id = ?", [$revId, $pageId, $tenantId]);

                    // 4. Optionally publish immediately
                    if ($publishNow) {
                        try {
                            $runtime = StudioRuntimeFactory::build();
                            $actor = StudioActor::fromCurrentSession();
                            $runtime->app->publish($actor, (int) $pageId, (int) $revId, "Initial publish of {$name}");
                            $flash = ['type' => 'success', 'msg' => "Theme template '{$name}' created and published! It is now active on the site."];
                        } catch (\Throwable $pubEx) {
                            $flash = ['type' => 'warning', 'msg' => "Template created as draft. Note on auto-publish: " . $pubEx->getMessage() . ". You can publish it in the Canvas editor."];
                        }
                    } else {
                        $flash = ['type' => 'success', 'msg' => "Theme template '{$name}' created as draft. Click 'Edit in Canvas' to design and publish."];
                    }
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Failed to create template: ' . $e->getMessage()];
                }
            }
        } elseif ($action === 'publish_template') {
            $pageId = (int) ($_POST['page_id'] ?? 0);
            if ($pageId > 0) {
                try {
                    $pageRow = Database::row("SELECT id, active_draft_revision_id, published_revision_id FROM studiobuilder_pages WHERE id = ? AND tenant_id = ?", [$pageId, $tenantId]);
                    if ($pageRow) {
                        $targetRev = (int) ($pageRow['active_draft_revision_id'] ?? $pageRow['published_revision_id'] ?? 0);
                        if ($targetRev > 0) {
                            $runtime = StudioRuntimeFactory::build();
                            $actor = StudioActor::fromCurrentSession();
                            $runtime->app->publish($actor, $pageId, $targetRev, "Published from Theme Builder");
                            $flash = ['type' => 'success', 'msg' => "Template published and activated!"];
                        }
                    }
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Failed to publish template: ' . $e->getMessage()];
                }
            }
        } elseif ($action === 'unpublish_template') {
            $pageId = (int) ($_POST['page_id'] ?? 0);
            if ($pageId > 0) {
                try {
                    Database::query("UPDATE studiobuilder_pages SET status = 'draft', published_revision_id = NULL, published_at = NULL WHERE id = ? AND tenant_id = ?", [$pageId, $tenantId]);
                    $flash = ['type' => 'success', 'msg' => "Template unpublished (reverted to draft)."];
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Failed to unpublish template: ' . $e->getMessage()];
                }
            }
        } elseif ($action === 'delete_template') {
            $templateId = (int) ($_POST['template_id'] ?? 0);
            if ($templateId > 0) {
                try {
                    Database::delete('studiobuilder_templates', 'tenant_id = ? AND id = ? AND is_system = 0', [$tenantId, $templateId]);
                    // Clean up linked backing page and revisions
                    $backingPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND settings_json LIKE ?", [$tenantId, '%"template_id":' . $templateId . '%']);
                    if ($backingPage) {
                        $pId = (int) $backingPage['id'];
                        Database::delete('studiobuilder_revisions', 'tenant_id = ? AND page_id = ?', [$tenantId, $pId]);
                        Database::delete('studiobuilder_compilations', 'tenant_id = ? AND page_id = ?', [$tenantId, $pId]);
                        Database::delete('studiobuilder_pages', 'tenant_id = ? AND id = ?', [$tenantId, $pId]);
                    }
                    $flash = ['type' => 'success', 'msg' => 'Template deleted successfully.'];
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Error deleting template: ' . $e->getMessage()];
                }
            }
        }
    }
}

// Filter and query templates
$typeFilter = trim((string) ($_GET['type'] ?? 'all'));

$allTemplates = [];
try {
    $allTemplates = Database::all("SELECT * FROM studiobuilder_templates WHERE tenant_id = ? ORDER BY id DESC", [$tenantId]);
} catch (\Throwable $e) {
    $allTemplates = [];
}

// Compute accurate counts by category/type
$counts = [
    'all'       => count($allTemplates),
    'header'    => 0,
    'footer'    => 0,
    'single'    => 0,
    'archive'   => 0,
    'not_found' => 0,
    'sections'  => 0,
];

foreach ($allTemplates as $t) {
    $tt = (string) ($t['template_type'] ?? '');
    $cat = (string) ($t['category'] ?? '');
    if ($tt === 'header_preset' || $cat === 'header') {
        $counts['header']++;
    } elseif ($tt === 'footer_preset' || $cat === 'footer') {
        $counts['footer']++;
    } elseif ($tt === 'section_preset' || $tt === 'block_preset' || $cat === 'section' || $cat === 'component') {
        $counts['sections']++;
    } elseif ($cat === 'single' || $cat === 'single_page') {
        $counts['single']++;
    } elseif ($cat === 'archive' || $cat === 'loop_grid') {
        $counts['archive']++;
    } elseif ($cat === 'not_found' || $cat === '404') {
        $counts['not_found']++;
    }
}

// Filter templates
$templates = array_filter($allTemplates, static function(array $t) use ($typeFilter): bool {
    if ($typeFilter === 'all') {
        return true;
    }
    $tt = (string) ($t['template_type'] ?? '');
    $cat = (string) ($t['category'] ?? '');
    if ($typeFilter === 'header') {
        return $tt === 'header_preset' || $cat === 'header';
    }
    if ($typeFilter === 'footer') {
        return $tt === 'footer_preset' || $cat === 'footer';
    }
    if ($typeFilter === 'sections') {
        return $tt === 'section_preset' || $tt === 'block_preset' || $cat === 'section' || $cat === 'component';
    }
    if ($typeFilter === 'single') {
        return $cat === 'single' || $cat === 'single_page';
    }
    if ($typeFilter === 'archive') {
        return $cat === 'archive' || $cat === 'loop_grid';
    }
    if ($typeFilter === 'not_found') {
        return $cat === 'not_found' || $cat === '404';
    }
    return true;
});

// Fetch backing pages to link visual builder and show real publish status
$pagesMap = [];
$pagesInfo = [];
try {
    $tplPages = Database::all("SELECT id, slug, title, page_type, status, published_revision_id, settings_json FROM studiobuilder_pages WHERE tenant_id = ? AND settings_json LIKE '%is_theme_template%'", [$tenantId]);
    foreach ($tplPages as $p) {
        $meta = json_decode((string) ($p['settings_json'] ?? '{}'), true) ?: [];
        $tid = (int) ($meta['template_id'] ?? 0);
        if ($tid > 0) {
            $pagesMap[$tid] = (int) $p['id'];
            $pagesInfo[$tid] = $p;
        }
    }
} catch (\Throwable $e) {
    $pagesMap = [];
    $pagesInfo = [];
}

// Portfolio page ID for nav
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('templates', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Subtitle & Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div class="sb-pill-filter" style="margin-bottom: 0;">
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" class="sb-pill-item <?= $typeFilter === 'all' ? 'active' : '' ?>">
                <?= sb_svg('templates', 14) ?> All Templates (<?= $counts['all'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=header')) ?>" class="sb-pill-item <?= $typeFilter === 'header' ? 'active' : '' ?>">
                <?= sb_svg('layer', 14) ?> Headers (<?= $counts['header'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=footer')) ?>" class="sb-pill-item <?= $typeFilter === 'footer' ? 'active' : '' ?>">
                <?= sb_svg('layer', 14) ?> Footers (<?= $counts['footer'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=single')) ?>" class="sb-pill-item <?= $typeFilter === 'single' ? 'active' : '' ?>">
                <?= sb_svg('pages', 14) ?> Single Pages (<?= $counts['single'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=archive')) ?>" class="sb-pill-item <?= $typeFilter === 'archive' ? 'active' : '' ?>">
                <?= sb_svg('content', 14) ?> Archives & Loop Grids (<?= $counts['archive'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=not_found')) ?>" class="sb-pill-item <?= $typeFilter === 'not_found' ? 'active' : '' ?>">
                <?= sb_svg('alert-circle', 14) ?> 404 Pages (<?= $counts['not_found'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=sections')) ?>" class="sb-pill-item <?= $typeFilter === 'sections' ? 'active' : '' ?>">
                <?= sb_svg('grid', 14) ?> Sections & Components (<?= $counts['sections'] ?>)
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
                        <option value="section_preset">Section & Component Preset (Reusable block layout)</option>
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
            <div style="margin-bottom: 16px;">
                <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Description / Notes</label>
                <input type="text" name="description" class="form-control" placeholder="Optional notes about design intent or typography...">
            </div>
            <div style="margin-bottom: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px;">
                <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; margin: 0;">
                    <input type="checkbox" name="publish_now" value="1" checked style="width: 16px; height: 16px;">
                    Publish & activate template immediately
                </label>
                <div style="font-size: 0.78rem; color: var(--sb-muted); margin-top: 4px; margin-left: 24px;">
                    Publishes revision #1 so <code>ThemeTemplateResolver</code> attaches it to live matching pages immediately. You can further customize layout in the visual Canvas editor at any time.
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-tpl-drawer').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary"><?= sb_svg('check', 14) ?> Create Template</button>
            </div>
        </form>
    </div>

    <!-- Active Templates List -->
    <?php if (empty($templates)): ?>
        <div class="card" style="padding: 48px 24px; text-align: center; border-radius: 16px; border: 1px dashed var(--sb-border); margin-bottom: 32px;">
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; color: var(--sb-muted);">
                <?= sb_svg('templates', 28) ?>
            </div>
            <h3 style="font-weight: 700; color: var(--sb-text); margin-bottom: 8px;">No theme templates found</h3>
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
                $tType = (string) ($tpl['template_type'] ?? 'header_preset');
                $tCat = (string) ($tpl['category'] ?? '');
                $tDesc = (string) ($tpl['description'] ?? '');
                $isSys = (bool) ($tpl['is_system'] ?? false);
                $backingPageId = $pagesMap[$tid] ?? null;
                $pageRow = $pagesInfo[$tid] ?? null;
                $isPublished = $pageRow && (($pageRow['status'] ?? '') === 'published') && !empty($pageRow['published_revision_id']);

                $displayType = match(true) {
                    $tType === 'header_preset' || $tCat === 'header' => 'header',
                    $tType === 'footer_preset' || $tCat === 'footer' => 'footer',
                    $tType === 'section_preset' || $tType === 'block_preset' || $tCat === 'section' || $tCat === 'component' => 'section',
                    $tCat === 'single' => 'single',
                    $tCat === 'archive' => 'archive',
                    $tCat === 'not_found' => 'not_found',
                    default => 'template',
                };

                $builderUrl = $backingPageId
                    ? plugin_url('studio-builder', 'admin/builder.php') . '?page=' . $backingPageId
                    : '#';

                $typeBadgeColor = match($displayType) {
                    'header'  => '#6366f1',
                    'footer'  => '#8b5cf6',
                    'single'  => '#0ea5e9',
                    'archive' => '#10b981',
                    'not_found' => '#f59e0b',
                    'section' => '#10b981',
                    default   => '#64748b',
                };
            ?>
                <div class="card" style="border-radius: 14px; border: 1px solid var(--sb-border); padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <span style="background: <?= $typeBadgeColor ?>15; color: <?= $typeBadgeColor ?>; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em;">
                                <?= e($displayType) ?>
                            </span>
                            <?php if ($isSys): ?>
                                <span style="font-size: 0.72rem; color: var(--sb-muted); font-weight: 600;">System Preset</span>
                            <?php elseif ($isPublished): ?>
                                <span style="font-size: 0.72rem; color: #10b981; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; background: #ecfdf5; padding: 2px 8px; border-radius: 9999px;">
                                    <?= sb_svg('check', 12) ?> Published & Active
                                </span>
                            <?php else: ?>
                                <span style="font-size: 0.72rem; color: #f59e0b; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; background: #fffbeb; padding: 2px 8px; border-radius: 9999px;">
                                    Draft (Inactive)
                                </span>
                            <?php endif; ?>
                        </div>
                        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--sb-text); margin: 0 0 6px 0;"><?= e($tName) ?></h3>
                        <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0 0 14px 0;">
                            <?= e($tDesc !== '' ? $tDesc : "Theme {$displayType} layout with dynamic slot bindings.") ?>
                        </p>
                    </div>

                    <div style="border-top: 1px solid var(--sb-border); padding-top: 14px; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <?php if ($backingPageId): ?>
                                <a href="<?= e($builderUrl) ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener">
                                    <?= sb_svg('edit', 12) ?> Edit in Canvas
                                </a>
                            <?php endif; ?>
                            <?php if (!$isSys && $backingPageId && $canEdit): ?>
                                <?php if (!$isPublished): ?>
                                    <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="publish_template">
                                        <input type="hidden" name="template_id" value="<?= $tid ?>">
                                        <input type="hidden" name="page_id" value="<?= $backingPageId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" style="color: #10b981; border-color: #10b981;" title="Publish this template to activate it on the site">
                                            <?= sb_svg('check', 12) ?> Publish
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="unpublish_template">
                                        <input type="hidden" name="page_id" value="<?= $backingPageId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" style="color: #64748b;" title="Revert to draft">
                                            Unpublish
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
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
                Headers and Footers attach automatically to published pages matching their display conditions (via <code>ThemeTemplateResolver</code>).
                <em>Note: Pages with <code>header_mode: hidden</code> or <code>footer_mode: hidden</code> in their Page Settings deliberately suppress chrome.</em>
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
