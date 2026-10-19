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
use Slate\Module\StudioBuilder\Document\DocumentCopier;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Presets\HeaderPresetCatalog;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_templates', 'Theme Builder');
$currentNav = 'studio-templates';

$flash = null;

/**
 * What a failed action may tell the user: a Studio validation message is written for people and is shown;
 * anything else (a database or runtime error) is logged and replaced by the generic text.
 */
function sb_templates_error_message(\Throwable $e, string $generic): string
{
    if ($e instanceof StudioValidationException) {
        $first = $e->errors()[0]['message'] ?? '';
        if (is_string($first) && $first !== '') {
            return $first;
        }
    }
    error_log('[studio-builder] theme builder: ' . $e::class . ': ' . $e->getMessage());
    return $generic;
}

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
    // A Single template is a system page: it lays out the pages its conditions match and is never public itself.
    'single' => [
        'template_type' => 'page_template',
        'page_type'     => 'system',
        'doc_type'      => 'system',
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
        // Each action needs the Studio permission its application service
        // requires (createTemplate / deleteTemplate: admin; publish: publish),
        // not just edit: this page writes the same rows directly.
        $requiredPerm = match ($action) {
            'create_template', 'delete_template'       => StudioPermissions::ADMIN,
            'publish_template', 'unpublish_template'   => StudioPermissions::PUBLISH,
            default                                     => StudioPermissions::ADMIN,
        };
        if (!StudioActor::fromCurrentSession()->can($requiredPerm)) {
            $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage templates.')];
            $action = '';
        }

        if ($action === 'create_template') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $uiType = trim((string) ($_POST['template_type'] ?? 'header'));
            $condition = trim((string) ($_POST['condition_rule'] ?? 'entire_site'));
            $description = trim((string) ($_POST['description'] ?? ''));
            $publishNow = !empty($_POST['publish_now']);
            // A header may start from one of the built-in designs (HeaderPresetCatalog); anything else starts blank.
            $headerDesign = HeaderPresetCatalog::find(trim((string) ($_POST['header_design'] ?? '')));

            if (!isset($typeConfigs[$uiType])) {
                $uiType = 'header';
            }

            // The same name and description rules the template service applies, checked first so the
            // message is a translated one and nothing is touched.
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => 'Template name is required.'];
            } elseif (mb_strlen($name, 'UTF-8') > 191 || preg_match('/[<>]/', $name) === 1) {
                $flash = ['type' => 'error', 'msg' => __('studio_tpl_invalid_name', 'A template name is plain text of at most 191 characters, without < or >.')];
            } elseif (mb_strlen($description, 'UTF-8') > 1000 || preg_match('/[<>]/', $description) === 1) {
                $flash = ['type' => 'error', 'msg' => __('studio_tpl_invalid_description', 'A description is plain text of at most 1000 characters, without < or >.')];
            } else {
                $cfg = $typeConfigs[$uiType];

                // Clean slug for template_key conforming to TEMPLATE_KEY_PATTERN, with a 32-bit random suffix.
                $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
                $slug = trim(substr(trim((string) $slug, '-'), 0, 100), '-');
                if ($slug === '') {
                    $slug = 'theme';
                }
                $templateKey = $slug . '-' . bin2hex(random_bytes(4));

                // Map condition rules into structured format evaluated by TemplateConditionMatcher
                $conditionRules = match($condition) {
                    'front_page'     => [['type' => 'include', 'condition' => 'front_page']],
                    'all_portfolio'  => [['type' => 'include', 'condition' => 'singular', 'value' => 'portfolio']],
                    'all_posts'      => [['type' => 'include', 'condition' => 'singular', 'value' => 'post']],
                    'all_services'   => [['type' => 'include', 'condition' => 'singular', 'value' => 'services']],
                    'singular_pages' => [['type' => 'include', 'condition' => 'singular', 'value' => 'page']],
                    default          => [['type' => 'include', 'condition' => 'entire_site']],
                };

                // Canonical starter document
                $starterDoc = CanonicalDocumentSchema::emptyDocument($cfg['doc_type'], 'default', $name);
                $starterDoc['seo']['robots'] = 'noindex,nofollow';
                // A section preset is inserted into pages; it has no display conditions and no theme role.
                if ($uiType !== 'section_preset') {
                    $starterDoc['settings']['conditions'] = ['rules' => $conditionRules];
                    $starterDoc['settings']['template_type'] = $cfg['role'];
                }

                $secId = CanonicalDocumentSchema::newSectionId();
                $blkId = CanonicalDocumentSchema::newBlockId();

                if ($uiType === 'header' && $headerDesign !== null) {
                    // The built-in design's sections, copied with new ids (the catalogue's ids are shared by every site).
                    $starterDoc['sections'] = array_map(
                        static fn(array $section): array => DocumentCopier::copySection($section),
                        $headerDesign['document']['sections'],
                    );
                } elseif ($uiType === 'header') {
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

                // Through the Studio services, as the editor saves: the template and the backing page's draft are
                // validated and normalized as canonical documents, uuids come from random_bytes, and each write is
                // audited. A refused step undoes the earlier ones (as duplicatePage does), so neither the template
                // nor a half-made page is left behind.
                $actor = StudioActor::fromCurrentSession();
                $created = null;
                $savedTemplate = false;
                $pageId = 0;
                try {
                    $runtime = StudioRuntimeFactory::build();
                    $runtime->app->saveTemplate($actor, $templateKey, $cfg['template_type'], $cfg['category'], $name, $description !== '' ? $description : "Theme {$uiType} template: {$name}", $starterDoc);
                    $savedTemplate = true;
                    // The backing page the Canvas editor opens, at the `template-<key>` address this page's list and
                    // delete action look for. Its first draft is the starter document.
                    $page = $runtime->app->createPage($actor, "[Theme] {$name}", 'template-' . $templateKey, $cfg['page_type'], 'standalone');
                    $pageId = (int) $page['page']['id'];
                    $draft = $runtime->app->saveDraft($actor, $pageId, $starterDoc, (int) $page['revision']['id'], 'manual', "Theme template created: {$name} ({$uiType})");
                    $created = ['page_id' => $pageId, 'revision_id' => (int) $draft['revision']['id']];
                } catch (\Throwable $e) {
                    try {
                        if ($pageId > 0) {
                            $runtime->app->archivePage($actor, $pageId);
                        }
                        if ($savedTemplate) {
                            $runtime->app->deleteTemplate($actor, $templateKey);
                        }
                    } catch (\Throwable $ignored) {
                        // the original failure is the one worth reporting
                    }
                    $flash = ['type' => 'error', 'msg' => sb_templates_error_message($e, __('studio_tpl_create_failed', 'The template could not be created. Nothing was saved.'))];
                }

                // Optionally publish immediately
                if ($created !== null && $publishNow) {
                    try {
                        $runtime->app->publish($actor, $created['page_id'], $created['revision_id'], "Initial publish of {$name}");
                        $flash = ['type' => 'success', 'msg' => "Theme template '{$name}' created and published! It is now active on the site."];
                    } catch (\Throwable $pubEx) {
                        $flash = ['type' => 'warning', 'msg' => rtrim(__('studio_tpl_created_not_published', 'Template created as draft, but it could not be published automatically. You can publish it in the Canvas editor.') . ' ' . sb_templates_error_message($pubEx, ''))];
                    }
                } elseif ($created !== null) {
                    $flash = ['type' => 'success', 'msg' => "Theme template '{$name}' created as draft. Click 'Edit in Canvas' to design and publish."];
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
                    $flash = ['type' => 'error', 'msg' => sb_templates_error_message($e, __('studio_tpl_publish_failed', 'The template could not be published.'))];
                }
            }
        } elseif ($action === 'unpublish_template') {
            $pageId = (int) ($_POST['page_id'] ?? 0);
            if ($pageId > 0) {
                try {
                    // Only a Theme Builder page (never an ordinary site page), with
                    // the compiled output dropped and the change audit-logged.
                    $tplPage = Database::row("SELECT id, slug, settings_json FROM studiobuilder_pages WHERE id = ? AND tenant_id = ?", [$pageId, $tenantId]);
                    $tplSettings = $tplPage !== null ? json_decode((string) ($tplPage['settings_json'] ?? ''), true) : null;
                    $isTemplatePage = $tplPage !== null
                        && (str_starts_with((string) $tplPage['slug'], 'template-') || (is_array($tplSettings) && !empty($tplSettings['is_theme_template'])));
                    if (!$isTemplatePage) {
                        $flash = ['type' => 'error', 'msg' => 'Template not found.'];
                    } else {
                        Database::query("UPDATE studiobuilder_pages SET status = 'draft', published_revision_id = NULL, published_at = NULL WHERE id = ? AND tenant_id = ?", [$pageId, $tenantId]);
                        StudioRuntimeFactory::build()->invalidator->invalidatePage($pageId);
                        AuditLog::record('studio.page.unpublished', (string) $pageId, ['via' => 'theme-builder']);
                        $flash = ['type' => 'success', 'msg' => "Template unpublished (reverted to draft)."];
                    }
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => sb_templates_error_message($e, __('studio_tpl_unpublish_failed', 'The template could not be unpublished.'))];
                }
            }
        } elseif ($action === 'delete_template') {
            $templateId = (int) ($_POST['template_id'] ?? 0);
            $doomed = $templateId > 0
                ? Database::row("SELECT id, template_key FROM studiobuilder_templates WHERE tenant_id = ? AND id = ? AND is_system = 0", [$tenantId, $templateId])
                : null;
            if ($doomed === null) {
                $flash = ['type' => 'error', 'msg' => 'Template not found.'];
            } else {
                try {
                    $runtime = StudioRuntimeFactory::build();
                    $actor = StudioActor::fromCurrentSession();
                    $templateKey = (string) $doomed['template_key'];
                    // The backing page is the one created with this template: its
                    // slug, or settings_json.template_id equal to this id exactly
                    // (a LIKE on the number alone also matched 50, 512, ...).
                    $candidates = Database::all(
                        "SELECT id, slug, settings_json FROM studiobuilder_pages WHERE tenant_id = ? AND status <> 'archived' AND (slug = ? OR settings_json LIKE ?)",
                        [$tenantId, 'template-' . $templateKey, '%"template_id":' . $templateId . '%']
                    );
                    $backingIds = [];
                    foreach ($candidates as $c) {
                        $settings = json_decode((string) ($c['settings_json'] ?? ''), true);
                        $linked = is_array($settings) && !empty($settings['is_theme_template'])
                            && isset($settings['template_id']) && (int) $settings['template_id'] === $templateId;
                        if ($linked || (string) $c['slug'] === 'template-' . $templateKey) {
                            $backingIds[] = (int) $c['id'];
                        }
                    }
                    // Through the Studio services: admin permission, audit log, cache
                    // invalidation. The backing page is archived, not deleted, so its
                    // revision history is kept (revisions are never deleted).
                    $runtime->app->deleteTemplate($actor, $templateKey);
                    foreach ($backingIds as $pId) {
                        $runtime->app->archivePage($actor, $pId);
                    }
                    $flash = ['type' => 'success', 'msg' => 'Template deleted successfully.'];
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => sb_templates_error_message($e, __('studio_tpl_delete_failed', 'The template could not be deleted.'))];
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

// Fetch backing pages to link visual builder and show real publish status. Publishing rewrites a page's settings from its
// document, which drops the `template_id` link written at creation, so a page is also found by its `template-<key>` slug.
$pagesMap = [];
$pagesInfo = [];
$pagesByKey = [];
try {
    $tplPages = Database::all("SELECT id, slug, title, page_type, status, published_revision_id, settings_json FROM studiobuilder_pages WHERE tenant_id = ? AND (settings_json LIKE '%is_theme_template%' OR slug LIKE 'template-%')", [$tenantId]);
    foreach ($tplPages as $p) {
        $meta = json_decode((string) ($p['settings_json'] ?? '{}'), true) ?: [];
        $tid = (int) ($meta['template_id'] ?? 0);
        if ($tid > 0) {
            $pagesMap[$tid] = (int) $p['id'];
            $pagesInfo[$tid] = $p;
        }
        if (str_starts_with((string) $p['slug'], 'template-')) {
            $pagesByKey[substr((string) $p['slug'], 9)] = $p;
        }
    }
} catch (\Throwable $e) {
    $pagesMap = [];
    $pagesInfo = [];
    $pagesByKey = [];
}

// Portfolio page ID for nav
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('templates', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?>" >
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
    <div id="sb-tpl-drawer" class="sb-card sb-drawer" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
            <div class="sb-card__title" style="display: flex; align-items: center; gap: 8px;">
                <?= sb_svg('templates', 20) ?> Create Theme Builder Template
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('sb-tpl-drawer').style.display='none'">
                <?= sb_svg('close', 14) ?>
            </button>
        </div>
        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="create_template">
            <div class="sb-form-grid">
                <div class="sb-field">
                    <label class="sb-field__label">Template Name <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Modern Glass Header" required>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label">Template Type</label>
                    <select name="template_type" class="form-control" style="background: #ffffff;">
                        <option value="header">Header (Top navigation & hero chrome)</option>
                        <option value="footer">Footer (Site-wide footer & bottom chrome)</option>
                        <option value="single">Single Post / Page (Dynamic single item view)</option>
                        <option value="archive">Archive & Loop Grid (Dynamic collection feed)</option>
                        <option value="not_found">404 Error (Custom Not Found page)</option>
                        <option value="section_preset">Section & Component Preset (Reusable block layout)</option>
                    </select>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label">Display Condition</label>
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
            <div class="sb-field" data-header-only>
                <label class="sb-field__label" for="sb-header-design"><?= e(__('studio_tpl_header_design', 'Header design')) ?></label>
                <select name="header_design" id="sb-header-design" class="form-control" style="background: #ffffff;">
                    <option value=""><?= e(__('studio_tpl_header_design_blank', 'Blank: start from an empty header')) ?></option>
                    <?php foreach (HeaderPresetCatalog::all() as $design):
                        $designSlug = str_replace('-', '_', $design['slug']); ?>
                        <option value="<?= e($design['key']) ?>"><?= e(__("studio_header_preset_{$designSlug}_name", $design['name'])) ?> — <?= e(__("studio_header_preset_{$designSlug}_desc", $design['description'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <script>
            (function () {
                var type = document.querySelector('select[name="template_type"]');
                var only = document.querySelector('[data-header-only]');
                if (!type || !only) return;
                var sync = function () { only.hidden = type.value !== 'header'; };
                type.addEventListener('change', sync);
                sync();
            }());
            </script>
            <div class="sb-field">
                <label class="sb-field__label">Description / Notes</label>
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
            <div class="sb-card-actions">
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
                $byKey = $pagesByKey[(string) ($tpl['template_key'] ?? '')] ?? null;
                $backingPageId = $pagesMap[$tid] ?? ($byKey ? (int) $byKey['id'] : null);
                $pageRow = $pagesInfo[$tid] ?? $byKey;
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
    <div class="sb-form-grid">
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
