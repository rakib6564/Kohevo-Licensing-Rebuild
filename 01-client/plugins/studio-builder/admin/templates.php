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
use Slate\Module\StudioBuilder\Admin\PageListView;
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
    'container_preset' => [
        'template_type' => 'container_preset',
        'page_type'     => 'container_preset',
        'doc_type'      => 'container_preset',
        'category'      => 'container',
        'role'          => 'container_preset',
        'label'         => 'Container Preset',
    ],
];

// POST Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage templates.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
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

        // "Edit in canvas" on a built-in header: a new draft header from that design (not live until it is published),
        // opened straight in the builder. The built-in design itself stays as it is, for the next one.
        $openInCanvas = false;
        if ($action === 'use_header_design') {
            $design = HeaderPresetCatalog::find(trim((string) ($_POST['header_design'] ?? '')));
            if ($design === null) {
                $flash = ['type' => 'error', 'msg' => __('studio_tpl_header_design_unknown', 'That header design does not exist.')];
                $action = '';
            } else {
                $designSlug = str_replace('-', '_', $design['slug']);
                $_POST['name'] = (string) __("studio_header_preset_{$designSlug}_name", $design['name']);
                $_POST['description'] = (string) __("studio_header_preset_{$designSlug}_desc", $design['description']);
                $_POST['template_type'] = 'header';
                $_POST['condition_rule'] = 'entire_site';
                $_POST['header_design'] = $design['key'];
                unset($_POST['publish_now']);
                $action = 'create_template';
                $openInCanvas = true;
            }
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
                $flash = ['type' => 'error', 'msg' => __('studio_tpl_name_required', 'Template name is required.')];
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
                // A Container preset is inserted into pages; it has no display conditions and no theme role.
                if ($uiType !== 'container_preset') {
                    $starterDoc['settings']['conditions'] = ['rules' => $conditionRules];
                    $starterDoc['settings']['template_type'] = $cfg['role'];
                }

                $starterBlock = static fn(string $type, array $props): array => [
                    'id'         => CanonicalDocumentSchema::newBlockId(),
                    'type'       => $type,
                    'version'    => 1,
                    'props'      => $props,
                    'style'      => CanonicalDocumentSchema::defaultBlockStyle(),
                    'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                    'bindings'   => [],
                    'children'   => [],
                ];
                $starterContainer = static fn(string $label, array $block, string $paddingY, string $phonePaddingY, array $layout = []): array => CanonicalDocumentSchema::newContainer([
                    'metadata'   => ['label' => mb_substr($label, 0, CanonicalDocumentSchema::BLOCK_LABEL_MAX_LENGTH, 'UTF-8')],
                    'props'      => ['gap' => 'md'],
                    'style'      => ['surface_token' => 'surface.primary', 'layout' => ['content_width' => 'boxed', 'gutter' => 'site', 'padding_y' => $paddingY] + $layout],
                    'responsive' => ['mobile' => ['style' => ['layout' => ['padding_y' => $phonePaddingY]]]],
                    'children'   => [$block],
                ]);

                if ($uiType === 'header' && $headerDesign !== null) {
                    // The built-in design's Containers, copied with new ids (the catalogue's ids are shared by every site).
                    $starterDoc['containers'] = DocumentCopier::copyBlocks($headerDesign['document']['containers']);
                } elseif ($uiType === 'header') {
                    $starterDoc['containers'] = [$starterContainer('Header Navigation', $starterBlock('core.heading', ['text' => $name, 'level' => 'h2']), 'md', 'sm', ['content_size' => 'wide'])];
                } elseif ($uiType === 'footer') {
                    $starterDoc['containers'] = [$starterContainer('Footer Chrome', $starterBlock('core.rich_text', [
                        'content' => '<p>© ' . date('Y') . ' ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '. All rights reserved.</p>',
                    ]), 'lg', 'md', ['content_size' => 'wide'])];
                } else {
                    $starterDoc['containers'] = [$starterContainer($name . ' Layout', $starterBlock('core.heading', ['text' => $name, 'level' => 'h1']), 'lg', 'md')];
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
                        $flash = ['type' => 'success', 'msg' => sprintf(__('studio_tpl_created_published', "Theme template '%s' created and published! It is now active on the site."), $name)];
                    } catch (\Throwable $pubEx) {
                        $flash = ['type' => 'warning', 'msg' => rtrim(__('studio_tpl_created_not_published', 'Template created as draft, but it could not be published automatically. You can publish it in the Canvas editor.') . ' ' . sb_templates_error_message($pubEx, ''))];
                    }
                    sb_flash_redirect('templates.php', $flash);
                } elseif ($created !== null && $openInCanvas) {
                    header('Location: ' . plugin_url('studio-builder', 'admin/builder.php') . '?page=' . (int) $created['page_id'], true, 303);
                    exit;
                } elseif ($created !== null) {
                    sb_flash_redirect('templates.php', ['type' => 'success', 'msg' => sprintf(__('studio_tpl_created_draft', "Theme template '%s' created as draft. Click 'Edit in Canvas' to design and publish."), $name)]);
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
                            sb_flash_redirect('templates.php', ['type' => 'success', 'msg' => __('studio_tpl_published', 'Template published and activated!')]);
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
                        $flash = ['type' => 'error', 'msg' => __('studio_tpl_not_found', 'Template not found.')];
                    } else {
                        Database::query("UPDATE studiobuilder_pages SET status = 'draft', published_revision_id = NULL, published_at = NULL WHERE id = ? AND tenant_id = ?", [$pageId, $tenantId]);
                        StudioRuntimeFactory::build()->invalidator->invalidatePage($pageId);
                        AuditLog::record('studio.page.unpublished', (string) $pageId, ['via' => 'theme-builder']);
                        sb_flash_redirect('templates.php', ['type' => 'success', 'msg' => __('studio_tpl_unpublished', 'Template unpublished (reverted to draft).')]);
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
                $flash = ['type' => 'error', 'msg' => __('studio_tpl_not_found', 'Template not found.')];
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
                    sb_flash_redirect('templates.php', ['type' => 'success', 'msg' => __('studio_tpl_deleted', 'Template deleted successfully.')]);
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => sb_templates_error_message($e, __('studio_tpl_delete_failed', 'The template could not be deleted.'))];
                }
            }
        }
    }
}

$flash = $flash ?? sb_take_flash();

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
    'containers' => 0,
];

foreach ($allTemplates as $t) {
    $tt = (string) ($t['template_type'] ?? '');
    $cat = (string) ($t['category'] ?? '');
    if ($tt === 'header_preset' || $cat === 'header') {
        $counts['header']++;
    } elseif ($tt === 'footer_preset' || $cat === 'footer') {
        $counts['footer']++;
    } elseif ($tt === 'container_preset' || $tt === 'block_preset' || $cat === 'container' || $cat === 'component') {
        $counts['containers']++;
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
    if ($typeFilter === 'containers') {
        return $tt === 'container_preset' || $tt === 'block_preset' || $cat === 'container' || $cat === 'component';
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

// The site's own headers, footers and theme templates (pages of those kinds), whatever made them: the Pages screen lists
// only pages, so these are found here.
$siteParts = [];
$sitePartsActor = StudioActor::fromCurrentSession();
try {
    $siteParts = array_values(array_filter(
        StudioRuntimeFactory::build()->app->listPages($sitePartsActor),
        static fn(array $p): bool => in_array((string) ($p['page_type'] ?? ''), ['header_partial', 'footer_partial', 'system'], true)
    ));
} catch (\Throwable $e) {
    $siteParts = [];
}
$sitePartsCanEdit = $sitePartsActor->can(StudioPermissions::EDIT);

// Portfolio page ID for nav

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_nav_templates', 'Templates')) ?></h2>
            <p><?= e(__('studio_tpl_intro', 'The headers, footers and theme templates your site uses, and saved templates to start new ones from.')) ?></p>
        </div>
    </div>

    <?php sb_render_flash($flash); ?>

    <?php if ($siteParts !== []): ?>
    <div class="card" style="margin-bottom: 24px;" data-testid="site-parts">
        <div class="card-header"><div>
            <h2><?= e(__('studio_site_parts', 'Your header, footer and theme templates')) ?></h2>
            <div class="card-sub"><?= e(__('studio_site_parts_sub', 'What your site uses now. Open one in the builder to change it everywhere it applies.')) ?></div>
        </div></div>
        <?= PageListView::render($siteParts, $sitePartsCanEdit, plugin_url('studio-builder', 'admin/builder.php'), plugin_url('studio-builder', 'admin/preview.php'), false, 'page-edit.php') ?>
        <?php slate_data_list_script(); ?>
    </div>
    <?php endif; ?>

    <!-- Subtitle & Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div class="sb-pill-filter" style="margin-bottom: 0;">
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" class="sb-pill-item <?= $typeFilter === 'all' ? 'active' : '' ?>">
                <?= sb_svg('templates', 14) ?> <?= e(__('studio_tpl_filter_all_saved', 'All saved')) ?> (<?= $counts['all'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=header')) ?>" class="sb-pill-item <?= $typeFilter === 'header' ? 'active' : '' ?>">
                <?= sb_svg('layer', 14) ?> <?= e(__('studio_tpl_filter_headers', 'Headers')) ?> (<?= $counts['header'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=footer')) ?>" class="sb-pill-item <?= $typeFilter === 'footer' ? 'active' : '' ?>">
                <?= sb_svg('layer', 14) ?> <?= e(__('studio_tpl_filter_footers', 'Footers')) ?> (<?= $counts['footer'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=single')) ?>" class="sb-pill-item <?= $typeFilter === 'single' ? 'active' : '' ?>">
                <?= sb_svg('pages', 14) ?> <?= e(__('studio_tpl_filter_single', 'Single Pages')) ?> (<?= $counts['single'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=archive')) ?>" class="sb-pill-item <?= $typeFilter === 'archive' ? 'active' : '' ?>">
                <?= sb_svg('content', 14) ?> <?= e(__('studio_tpl_filter_archive', 'Archives & Loop Grids')) ?> (<?= $counts['archive'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=not_found')) ?>" class="sb-pill-item <?= $typeFilter === 'not_found' ? 'active' : '' ?>">
                <?= sb_svg('alert-circle', 14) ?> <?= e(__('studio_tpl_filter_not_found', '404 Pages')) ?> (<?= $counts['not_found'] ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/templates.php?type=containers')) ?>" class="sb-pill-item <?= $typeFilter === 'containers' ? 'active' : '' ?>">
                <?= sb_svg('overview', 14) ?> <?= e(__('studio_tpl_filter_containers', 'Containers & Components')) ?> (<?= $counts['containers'] ?>)
            </a>
        </div>

        <button type="button" class="btn btn-primary" onclick="document.getElementById('sb-tpl-drawer').style.display='block'">
            <?= sb_svg('plus', 14) ?> <?= e(__('studio_tpl_new', 'New Theme Template')) ?>
        </button>
    </div>

    <?php if ($typeFilter === 'all' || $typeFilter === 'header'): ?>
    <!-- Built-in headers: each opens as an editable header in the builder -->
    <div class="sb-card" data-builtin-headers style="margin-bottom: 24px;">
        <div class="sb-card__head">
            <div class="sb-card__title"><?= e(__('studio_tpl_builtin_headers', 'Built-in headers')) ?></div>
            <p class="sb-muted" style="margin: 4px 0 0;"><?= e(__('studio_tpl_builtin_headers_hint', 'Pick a design to edit it in the canvas. It starts as a draft header for the whole site and goes live when you publish it.')) ?></p>
        </div>
        <ul style="list-style: none; margin: 16px 0 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px;">
            <?php foreach (HeaderPresetCatalog::all() as $design):
                $designSlug = str_replace('-', '_', $design['slug']); ?>
                <li data-header-design="<?= e($design['key']) ?>" style="border: 1px solid var(--sb-border); border-radius: 10px; padding: 14px; display: flex; flex-direction: column; gap: 8px;">
                    <strong><?= e(__("studio_header_preset_{$designSlug}_name", $design['name'])) ?></strong>
                    <span class="sb-muted" style="font-size: .85rem; flex: 1;"><?= e(__("studio_header_preset_{$designSlug}_desc", $design['description'])) ?></span>
                    <?php if (StudioActor::fromCurrentSession()->can(StudioPermissions::ADMIN)): ?>
                    <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" style="margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="use_header_design">
                        <input type="hidden" name="header_design" value="<?= e($design['key']) ?>">
                        <button type="submit" class="btn btn-sm btn-primary"><?= sb_svg('edit', 14) ?> <?= e(__('studio_tpl_edit_in_canvas', 'Edit in canvas')) ?></button>
                    </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Create Template Drawer / Form -->
    <div id="sb-tpl-drawer" class="sb-card sb-drawer" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
            <div class="sb-card__title" style="display: flex; align-items: center; gap: 8px;">
                <?= sb_svg('templates', 20) ?> <?= e(__('studio_tpl_create_heading', 'Create Theme Builder Template')) ?>
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
                    <label class="sb-field__label"><?= e(__('studio_tpl_name', 'Template Name')) ?> <span class="field-required">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="<?= e(__('studio_tpl_name_placeholder', 'e.g. Modern Glass Header')) ?>" required>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tpl_type', 'Template Type')) ?></label>
                    <select name="template_type" class="form-control" style="background: #ffffff;">
                        <option value="header"><?= e(__('studio_tpl_type_header', 'Header (Top navigation & hero chrome)')) ?></option>
                        <option value="footer"><?= e(__('studio_tpl_type_footer', 'Footer (Site-wide footer & bottom chrome)')) ?></option>
                        <option value="single"><?= e(__('studio_tpl_type_single', 'Single Post / Page (Dynamic single item view)')) ?></option>
                        <option value="archive"><?= e(__('studio_tpl_type_archive', 'Archive & Loop Grid (Dynamic collection feed)')) ?></option>
                        <option value="not_found"><?= e(__('studio_tpl_type_not_found', '404 Error (Custom Not Found page)')) ?></option>
                        <option value="container_preset"><?= e(__('studio_tpl_type_container', 'Container & Component Preset (Reusable block layout)')) ?></option>
                    </select>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label"><?= e(__('studio_tpl_condition', 'Display Condition')) ?></label>
                    <select name="condition_rule" class="form-control" style="background: #ffffff;">
                        <option value="entire_site"><?= e(__('studio_tpl_cond_entire_site', 'Entire Site (Global Default)')) ?></option>
                        <option value="front_page"><?= e(__('studio_tpl_cond_front_page', 'Front Page / Homepage Only')) ?></option>
                        <option value="all_portfolio"><?= e(__('studio_tpl_cond_all_portfolio', 'All Portfolio Items')) ?></option>
                        <option value="all_posts"><?= e(__('studio_tpl_cond_all_posts', 'All Blog Posts')) ?></option>
                        <option value="all_services"><?= e(__('studio_tpl_cond_all_services', 'All Service Offerings')) ?></option>
                        <option value="singular_pages"><?= e(__('studio_tpl_cond_singular_pages', 'Regular Pages')) ?></option>
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
                <label class="sb-field__label"><?= e(__('studio_tpl_description', 'Description / Notes')) ?></label>
                <input type="text" name="description" class="form-control" placeholder="<?= e(__('studio_tpl_description_placeholder', 'Optional notes about design intent or typography...')) ?>">
            </div>
            <div style="margin-bottom: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px;">
                <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; margin: 0;">
                    <input type="checkbox" name="publish_now" value="1" checked style="width: 16px; height: 16px;">
                    <?= e(__('studio_tpl_publish_now', 'Publish & activate template immediately')) ?>
                </label>
                <div style="font-size: 0.78rem; color: var(--sb-muted); margin-top: 4px; margin-left: 24px;">
                    <?= sprintf(e(__('studio_tpl_publish_now_hint', 'Publishes revision #1 so %s attaches it to live matching pages immediately. You can further customize layout in the visual Canvas editor at any time.')), '<code>ThemeTemplateResolver</code>') ?>
                </div>
            </div>
            <div class="sb-card-actions">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-tpl-drawer').style.display='none'"><?= e(__('cancel', 'Cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= sb_svg('check', 14) ?> <?= e(__('studio_tpl_create', 'Create Template')) ?></button>
            </div>
        </form>
    </div>

    <!-- Active Templates List -->
    <?php if (empty($templates)): ?>
        <div class="sb-empty" data-testid="templates-empty">
            <h3><?= e($typeFilter === 'all' ? __('studio_tpl_empty_title', 'No saved templates yet') : __('studio_tpl_empty_filtered', 'No saved templates of this kind')) ?></h3>
            <p><?= e($siteParts !== []
                ? __('studio_tpl_empty_has_parts', 'The headers, footers and theme templates your site uses are listed above. A saved template is a starting point you can reuse.')
                : __('studio_tpl_empty_sub', 'Save a header, footer, single page, archive or 404 layout here to reuse it across the site.')) ?></p>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('sb-tpl-drawer').style.display='block'">
                <?= sb_svg('plus', 16) ?> <?= e(__('studio_tpl_create_first', 'Create First Template')) ?>
            </button>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; margin-bottom: 36px;">
            <?php foreach ($templates as $tpl):
                $tid = (int) $tpl['id'];
                $tName = (string) ($tpl['name'] ?? __('studio_tpl_untitled', 'Untitled Template'));
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
                    $tType === 'container_preset' || $tType === 'block_preset' || $tCat === 'container' || $tCat === 'component' => 'container',
                    $tCat === 'single' => 'single',
                    $tCat === 'archive' => 'archive',
                    $tCat === 'not_found' => 'not_found',
                    default => 'template',
                };

                $displayTypeLabel = match ($displayType) {
                    'header'    => __('studio_kind_header', 'Header'),
                    'footer'    => __('studio_kind_footer', 'Footer'),
                    'container' => __('studio_ui_container', 'Container'),
                    'single'    => __('studio_tpl_kind_single', 'Single'),
                    'archive'   => __('studio_tpl_kind_archive', 'Archive'),
                    'not_found' => __('studio_tpl_kind_not_found', '404'),
                    default     => __('studio_kind_template', 'Template'),
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
                    'container' => '#10b981',
                    default   => '#64748b',
                };
            ?>
                <div class="card" style="border-radius: 14px; border: 1px solid var(--sb-border); padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <span style="background: <?= $typeBadgeColor ?>15; color: <?= $typeBadgeColor ?>; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.05em;">
                                <?= e($displayTypeLabel) ?>
                            </span>
                            <?php if ($isSys): ?>
                                <span style="font-size: 0.72rem; color: var(--sb-muted); font-weight: 600;"><?= e(__('studio_tpl_system_preset', 'System Preset')) ?></span>
                            <?php elseif ($isPublished): ?>
                                <span style="font-size: 0.72rem; color: #10b981; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; background: #ecfdf5; padding: 2px 8px; border-radius: 9999px;">
                                    <?= sb_svg('check', 12) ?> <?= e(__('studio_tpl_published_active', 'Published & Active')) ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size: 0.72rem; color: #f59e0b; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; background: #fffbeb; padding: 2px 8px; border-radius: 9999px;">
                                    <?= e(__('studio_tpl_draft_inactive', 'Draft (Inactive)')) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--sb-text); margin: 0 0 6px 0;"><?= e($tName) ?></h3>
                        <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0 0 14px 0;">
                            <?= e($tDesc !== '' ? $tDesc : sprintf(__('studio_tpl_desc_default', 'Theme %s layout with dynamic slot bindings.'), $displayTypeLabel)) ?>
                        </p>
                    </div>

                    <div style="border-top: 1px solid var(--sb-border); padding-top: 14px; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <?php if ($backingPageId): ?>
                                <a href="<?= e($builderUrl) ?>" class="btn btn-sm btn-primary" target="_blank" rel="noopener">
                                    <?= sb_svg('edit', 12) ?> <?= e(__('studio_overview_edit', 'Edit in Canvas')) ?>
                                </a>
                            <?php endif; ?>
                            <?php if (!$isSys && $backingPageId && $canEdit): ?>
                                <?php if (!$isPublished): ?>
                                    <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="publish_template">
                                        <input type="hidden" name="template_id" value="<?= $tid ?>">
                                        <input type="hidden" name="page_id" value="<?= $backingPageId ?>">
                                        <button type="submit" class="btn btn-sm btn-success-outline" title="<?= e(__('studio_tpl_publish_title', 'Publish this template to activate it on the site')) ?>">
                                            <?= sb_svg('check', 12) ?> <?= e(__('studio_ui_publish', 'Publish')) ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="unpublish_template">
                                        <input type="hidden" name="page_id" value="<?= $backingPageId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" style="color: #64748b;" title="<?= e(__('studio_tpl_unpublish_title', 'Revert to draft')) ?>">
                                            <?= e(__('studio_tpl_unpublish', 'Unpublish')) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isSys && $canEdit): ?>
                            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/templates.php')) ?>" onsubmit="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?= e(__('studio_tpl_delete_confirm', 'Delete this theme template?')) ?>" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_template">
                                <input type="hidden" name="template_id" value="<?= $tid ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="<?= e(__('studio_tpl_delete_title', 'Delete Template')) ?>">
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
            <h4 style="font-weight: 700; color: var(--sb-text); font-size: 1rem; margin-bottom: 6px;"><?= e(__('studio_tpl_guide_chrome', 'Global Chrome Integration')) ?></h4>
            <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0;">
                <?= sprintf(e(__('studio_tpl_guide_chrome_text', 'Headers and Footers attach automatically to published pages matching their display conditions (via %s).')), '<code>ThemeTemplateResolver</code>') ?>
                <em><?= sprintf(e(__('studio_tpl_guide_chrome_note', 'Note: Pages with %1$s or %2$s in their Page Settings deliberately suppress chrome.')), '<code>header_mode: hidden</code>', '<code>footer_mode: hidden</code>') ?></em>
            </p>
        </div>

        <div class="card" style="background: #ffffff; border: 1px solid var(--sb-border); border-radius: 14px; padding: 20px;">
            <div style="color: #10b981; margin-bottom: 10px;">
                <?= sb_svg('content', 24) ?>
            </div>
            <h4 style="font-weight: 700; color: var(--sb-text); font-size: 1rem; margin-bottom: 6px;"><?= e(__('studio_tpl_guide_loop', 'Loop Grid & Query Builder')) ?></h4>
            <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0;">
                <?= sprintf(e(__('studio_tpl_guide_loop_text', 'Archive and loop grid templates consume data feeds from %s. Bind custom fields like client, year, tags, and cover images to repeat automatically across cards.')), '<code>core.query_loop</code>') ?>
            </p>
        </div>

        <div class="card" style="background: #ffffff; border: 1px solid var(--sb-border); border-radius: 14px; padding: 20px;">
            <div style="color: #0ea5e9; margin-bottom: 10px;">
                <?= sb_svg('pages', 24) ?>
            </div>
            <h4 style="font-weight: 700; color: var(--sb-text); font-size: 1rem; margin-bottom: 6px;"><?= e(__('studio_tpl_guide_single', 'Dynamic Single Post Templates')) ?></h4>
            <p style="font-size: 0.85rem; color: var(--sb-muted); line-height: 1.5; margin: 0;">
                <?= sprintf(e(__('studio_tpl_guide_single_text', 'Design once, render for every portfolio item or case study. Single templates inject dynamic content tokens: %1$s, %2$s, and %3$s.')), '<code>{{post.title}}</code>', '<code>{{meta.client}}</code>', '<code>{{post.content}}</code>') ?>
            </p>
        </div>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
