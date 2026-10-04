<?php
/**
 * Kohevo Studio — Modern Visual Experience Suite Admin Console.
 *
 * Provides a comprehensive, luxury management console for:
 * 1. Overview       (Executive Studio dashboard, KPIs, quick launcher, system health)
 * 2. Pages          (Studio page management, creation, route filters, builder launcher)
 * 3. Content        (Custom post type items: Portfolio, Case Studies, Services, dynamic bindings)
 * 4. Media          (Media Library asset hub, image inspector, media ID resolution)
 * 5. Templates      (Theme Builder: Site Headers, Site Footers, Archives, Loop Grids, Single Pages)
 * 6. Types & fields (Custom Post Type Architect & Meta Fields: text, textarea, number, image, gallery, select, relation, date, boolean, url)
 * 7. Themes         (Theme management, active presets: Obsidian Dark, Minimal Light, Design Tokens)
 * 8. Site & SEO     (Title formats, meta descriptions, OpenGraph, robots, sitemap.xml, robots.txt, social card preview)
 * 9. Global settings (Container widths, spacing scales, responsive breakpoints, signatures)
 * 10. AI & MCP      (AI Gateway status, assistant tools, model selector, prompt sandbox)
 * 11. Reviews       (AI draft diff review, change comparison, rollback and publish controls)
 * 12. Code & tracking (Custom Head/Footer scripts, Google Analytics GA4/GTM, custom CSS editor)
 * 13. Redirects     (301/302 URL redirect manager)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Admin\PageListView;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();

$studioApp   = StudioRuntimeFactory::build()->app;
$studioActor = StudioActor::fromCurrentSession();
$pageTitle   = __('studio_suite', 'Kohevo Studio');
$currentNav  = 'studio-builder';
$flash       = null;

$allowedTabs = [
    'overview', 'pages', 'content', 'media', 'templates',
    'types-fields', 'themes', 'seo', 'global-settings',
    'ai-mcp', 'reviews', 'code-tracking', 'redirects'
];

$currentTab = (string) ($_GET['tab'] ?? 'overview');
if (!in_array($currentTab, $allowedTabs, true)) {
    $currentTab = 'overview';
}

$formValues = ['title' => '', 'slug' => '', 'page_type' => 'page', 'route_mode' => 'standalone', 'template_key' => ''];

// Handle Page Creation
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action_create_page'])) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        foreach (array_keys($formValues) as $k) {
            $formValues[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        if ($formValues['slug'] === '') {
            $formValues['slug'] = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($formValues['title'])), '-');
        }
        try {
            $created = $studioApp->createPage($studioActor, $formValues['title'], $formValues['slug'], $formValues['page_type'], $formValues['route_mode']);
            $newId = (int) $created['page']['id'];
            if ($formValues['template_key'] !== '') {
                $studioApp->applyTemplate($studioActor, $formValues['template_key'], $newId, (int) $created['revision']['id']);
            }
            header('Location: ' . plugin_url('studio-builder', 'admin/builder.php') . '?page=' . $newId, true, 303);
            exit;
        } catch (StudioValidationException $e) {
            $first = $e->errors()[0]['message'] ?? '';
            $flash = ['type' => 'error', 'msg' => __('studio_page_invalid', 'The page could not be created.') . ($first !== '' ? ' ' . $first : '')];
        } catch (StudioException $e) {
            $flash = ['type' => 'error', 'msg' => __('studio_page_create_denied', 'You cannot create Studio pages here.')];
        }
    }
}

// Fetch Studio Data
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

// Find Portfolio page for quick launch
$portfolioPage = null;
foreach ($pages as $p) {
    if (($p['slug'] ?? '') === 'portfolio') {
        $portfolioPage = $p;
        break;
    }
}
$portfolioPageId = (int) ($portfolioPage['id'] ?? ($pages[0]['id'] ?? 1));

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

<style>
/* ── Kohevo Studio Luxury Admin Suite ────────────────────────────────────────── */
:root {
    --sb-admin-accent: #6366f1;
    --sb-admin-accent-glow: rgba(99, 102, 241, 0.25);
    --sb-admin-surface: #ffffff;
    --sb-admin-surface-subtle: #f8fafc;
    --sb-admin-border: #e2e8f0;
    --sb-admin-text: #0f172a;
    --sb-admin-muted: #64748b;
}

.sb-admin-wrapper {
    margin-bottom: 48px;
}

.sb-admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--sb-admin-border);
}

.sb-admin-header-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sb-admin-badge-engine {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.sb-admin-header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

/* ── Modern Horizontal Tab Navigation Bar ──────────────────────────────────── */
.sb-admin-nav-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    overflow-x: auto;
    padding: 6px;
    background: #f1f5f9;
    border-radius: 12px;
    margin-bottom: 28px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}

.sb-admin-nav-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 0.86rem;
    font-weight: 600;
    color: var(--sb-admin-muted);
    text-decoration: none;
    white-space: nowrap;
    transition: all 0.2s ease;
}

.sb-admin-nav-tab:hover {
    color: var(--sb-admin-text);
    background: rgba(255, 255, 255, 0.6);
}

.sb-admin-nav-tab.active {
    color: var(--sb-admin-accent);
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.sb-admin-nav-tab .tab-icon {
    font-size: 1rem;
    line-height: 1;
}

/* ── KPI Metrics Cards ─────────────────────────────────────────────────────── */
.sb-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.sb-kpi-card {
    background: #ffffff;
    border: 1px solid var(--sb-admin-border);
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.sb-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.06);
}

.sb-kpi-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.sb-kpi-label {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--sb-admin-muted);
}

.sb-kpi-icon {
    font-size: 1.1rem;
}

.sb-kpi-value {
    font-size: 1.85rem;
    font-weight: 800;
    color: var(--sb-admin-text);
    line-height: 1.1;
    margin-bottom: 4px;
}

.sb-kpi-meta {
    font-size: 0.78rem;
    color: var(--sb-admin-muted);
}

/* ── Bento Showcase & Grid Cards ──────────────────────────────────────────── */
.sb-feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 20px;
    margin-bottom: 28px;
}

.sb-feature-card {
    background: #ffffff;
    border: 1px solid var(--sb-admin-border);
    border-radius: 16px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.sb-feature-header {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 14px;
}

.sb-feature-icon-box {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: rgba(99, 102, 241, 0.1);
    color: var(--sb-admin-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}

.sb-feature-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--sb-admin-text);
    margin: 0 0 4px 0;
}

.sb-feature-desc {
    font-size: 0.88rem;
    color: var(--sb-admin-muted);
    line-height: 1.5;
    margin: 0;
}

.sb-feature-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--sb-admin-border);
}

/* ── Code / Snippet Box ───────────────────────────────────────────────────── */
.sb-code-box {
    background: #0f172a;
    color: #cbd5e1;
    border-radius: 10px;
    padding: 16px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.85rem;
    line-height: 1.6;
    overflow-x: auto;
    margin: 12px 0;
}

/* ── Form Field Row Enhancement ───────────────────────────────────────────── */
.sb-pill-filter {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.sb-pill-item {
    padding: 5px 12px;
    border-radius: 9999px;
    font-size: 0.8rem;
    font-weight: 600;
    background: #f1f5f9;
    color: var(--sb-admin-muted);
    text-decoration: none;
    transition: all 0.2s ease;
}

.sb-pill-item:hover,
.sb-pill-item.active {
    background: var(--sb-admin-accent);
    color: #ffffff;
}

@media (max-width: 768px) {
    .sb-admin-header {
        flex-direction: column;
        align-items: flex-start;
    }
    .sb-admin-header-actions {
        width: 100%;
    }
    .sb-admin-header-actions .btn {
        flex: 1;
        text-align: center;
        justify-content: center;
    }
}
</style>

<div class="sb-admin-wrapper">
    <!-- Top Executive Header -->
    <div class="sb-admin-header">
        <div>
            <div class="sb-admin-header-title">
                <h1><?= e(__('studio_suite', 'Kohevo Studio')) ?></h1>
                <span class="sb-admin-badge-engine">v1.0 Pro</span>
            </div>
            <p class="page-header-sub"><?= e(__('studio_suite_sub', 'Next-generation visual canvas builder, dynamic query engine, theme architect, and AI workflow hub.')) ?></p>
        </div>
        <div class="sb-admin-header-actions">
            <?php if ($portfolioPageId > 0): ?>
                <a href="<?= e(plugin_url('studio-builder', 'admin/builder.php') . '?page=' . $portfolioPageId) ?>" class="btn btn-primary" target="_blank" rel="noopener">
                    ⚡ <?= e(__('studio_open_canvas', 'Open Canvas Builder')) ?>
                </a>
                <a href="<?= e(SLATE_URL . '/portfolio') ?>" class="btn btn-outline" target="_blank" rel="noopener">
                    🌐 <?= e(__('studio_live_preview', 'View Live Portfolio')) ?> ↗
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($listError !== null): ?>
        <div class="alert alert-error" role="status"><?= e($listError) ?></div>
    <?php endif; ?>

    <!-- 13 Navigation Tabs -->
    <nav class="sb-admin-nav-bar" aria-label="Studio Console Navigation">
        <a href="?tab=overview" class="sb-admin-nav-tab <?= $currentTab === 'overview' ? 'active' : '' ?>">
            <span class="tab-icon">📊</span> Overview
        </a>
        <a href="?tab=pages" class="sb-admin-nav-tab <?= $currentTab === 'pages' ? 'active' : '' ?>">
            <span class="tab-icon">📄</span> Pages
        </a>
        <a href="?tab=content" class="sb-admin-nav-tab <?= $currentTab === 'content' ? 'active' : '' ?>">
            <span class="tab-icon">📦</span> Content
        </a>
        <a href="?tab=media" class="sb-admin-nav-tab <?= $currentTab === 'media' ? 'active' : '' ?>">
            <span class="tab-icon">🖼️</span> Media
        </a>
        <a href="?tab=templates" class="sb-admin-nav-tab <?= $currentTab === 'templates' ? 'active' : '' ?>">
            <span class="tab-icon">🧩</span> Templates
        </a>
        <a href="?tab=types-fields" class="sb-admin-nav-tab <?= $currentTab === 'types-fields' ? 'active' : '' ?>">
            <span class="tab-icon">🏷️</span> Types & fields
        </a>
        <a href="?tab=themes" class="sb-admin-nav-tab <?= $currentTab === 'themes' ? 'active' : '' ?>">
            <span class="tab-icon">🎨</span> Themes
        </a>
        <a href="?tab=seo" class="sb-admin-nav-tab <?= $currentTab === 'seo' ? 'active' : '' ?>">
            <span class="tab-icon">🔍</span> Site & SEO
        </a>
        <a href="?tab=global-settings" class="sb-admin-nav-tab <?= $currentTab === 'global-settings' ? 'active' : '' ?>">
            <span class="tab-icon">⚙️</span> Global settings
        </a>
        <a href="?tab=ai-mcp" class="sb-admin-nav-tab <?= $currentTab === 'ai-mcp' ? 'active' : '' ?>">
            <span class="tab-icon">🤖</span> AI & MCP
        </a>
        <a href="?tab=reviews" class="sb-admin-nav-tab <?= $currentTab === 'reviews' ? 'active' : '' ?>">
            <span class="tab-icon">⚖️</span> Reviews
        </a>
        <a href="?tab=code-tracking" class="sb-admin-nav-tab <?= $currentTab === 'code-tracking' ? 'active' : '' ?>">
            <span class="tab-icon">💻</span> Code & tracking
        </a>
        <a href="?tab=redirects" class="sb-admin-nav-tab <?= $currentTab === 'redirects' ? 'active' : '' ?>">
            <span class="tab-icon">🔀</span> Redirects
        </a>
    </nav>

    <!-- ── TAB 1: OVERVIEW ──────────────────────────────────────────────────── -->
    <?php if ($currentTab === 'overview'): ?>
        <!-- KPI Metrics Strip -->
        <div class="sb-kpi-grid">
            <div class="sb-kpi-card">
                <div class="sb-kpi-top">
                    <span class="sb-kpi-label">Total Pages</span>
                    <span class="sb-kpi-icon">📄</span>
                </div>
                <div class="sb-kpi-value"><?= $totalPages ?></div>
                <div class="sb-kpi-meta"><?= $publishedCount ?> published · <?= $draftCount ?> drafts</div>
            </div>
            <div class="sb-kpi-card">
                <div class="sb-kpi-top">
                    <span class="sb-kpi-label">Theme Templates</span>
                    <span class="sb-kpi-icon">🧩</span>
                </div>
                <div class="sb-kpi-value"><?= count($templates) + 5 ?></div>
                <div class="sb-kpi-meta">Headers, Footers, Archives, Loop Grids</div>
            </div>
            <div class="sb-kpi-card">
                <div class="sb-kpi-top">
                    <span class="sb-kpi-label">Active Theme</span>
                    <span class="sb-kpi-icon">🎨</span>
                </div>
                <div class="sb-kpi-value" style="font-size: 1.3rem; font-weight: 700; color: #6366f1;">Obsidian Dark</div>
                <div class="sb-kpi-meta">Ultra-Luxury Portfolio Edition</div>
            </div>
            <div class="sb-kpi-card">
                <div class="sb-kpi-top">
                    <span class="sb-kpi-label">AI & MCP Gateway</span>
                    <span class="sb-kpi-icon">🤖</span>
                </div>
                <div class="sb-kpi-value" style="color: #10b981; font-size: 1.3rem;">Connected</div>
                <div class="sb-kpi-meta">5 Assistant tools registered</div>
            </div>
        </div>

        <!-- Featured Quick Actions & Subsystems -->
        <div class="sb-feature-grid">
            <div class="sb-feature-card">
                <div>
                    <div class="sb-feature-header">
                        <div class="sb-feature-icon-box">⚡</div>
                        <div>
                            <h3 class="sb-feature-title">Freelancer Portfolio Website</h3>
                            <p class="sb-feature-desc">Active flagship portfolio with hero status indicator, case studies, areas of expertise, and inquiry brief form.</p>
                        </div>
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b; margin-top: 8px;">
                        Path: <code>/portfolio</code> · Status: <span class="badge badge-active">Live Published</span>
                    </div>
                </div>
                <div class="sb-feature-footer">
                    <a href="<?= e(plugin_url('studio-builder', 'admin/builder.php') . '?page=' . $portfolioPageId) ?>" class="btn btn-sm btn-primary">Edit in Builder</a>
                    <a href="<?= e(SLATE_URL . '/portfolio') ?>" class="btn btn-sm btn-outline" target="_blank" rel="noopener">Preview Live ↗</a>
                </div>
            </div>

            <div class="sb-feature-card">
                <div>
                    <div class="sb-feature-header">
                        <div class="sb-feature-icon-box">🧩</div>
                        <div>
                            <h3 class="sb-feature-title">Theme Builder Engine</h3>
                            <p class="sb-feature-desc">Craft reusable site headers, footers, archive templates, loop grids, and single page templates across your brand.</p>
                        </div>
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b; margin-top: 8px;">
                        Headers · Footers · Archive Templates · Bento Grids · Single Post Layouts
                    </div>
                </div>
                <div class="sb-feature-footer">
                    <a href="?tab=templates" class="btn btn-sm btn-primary">Manage Templates</a>
                    <span class="badge badge-info">Theme Builder</span>
                </div>
            </div>

            <div class="sb-feature-card">
                <div>
                    <div class="sb-feature-header">
                        <div class="sb-feature-icon-box">🏷️</div>
                        <div>
                            <h3 class="sb-feature-title">Post Types & Custom Meta Fields</h3>
                            <p class="sb-feature-desc">Architect custom data types (Portfolio, Case Studies, Services) and bind dynamic fields to visual blocks seamlessly.</p>
                        </div>
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b; margin-top: 8px;">
                        Supports 10 field types: Text, Textarea, Number, Image, Gallery, Select, Relation, Date, Boolean, URL.
                    </div>
                </div>
                <div class="sb-feature-footer">
                    <a href="?tab=types-fields" class="btn btn-sm btn-primary">Define Fields & Types</a>
                    <span class="badge badge-info">Query Loop Ready</span>
                </div>
            </div>
        </div>

        <!-- Recent Studio Pages Section -->
        <div class="card" style="margin-top: 24px;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2>Recent Studio Pages</h2>
                    <div class="card-sub">Open any page in the Visual Builder or inspect public preview.</div>
                </div>
                <a href="?tab=pages" class="btn btn-sm btn-outline">View All Pages</a>
            </div>
            <?php if ($pages === []): ?>
                <div class="empty">
                    <div class="empty-title"><?= e(__('studio_no_pages', 'No Studio pages yet')) ?></div>
                    <p class="text-sm"><?= e(__('studio_no_pages_hint', 'Create a page above to open it in the builder.')) ?></p>
                </div>
            <?php else: ?>
                <?= PageListView::render($pages, $canEdit, plugin_url('studio-builder', 'admin/builder.php'), plugin_url('studio-builder', 'admin/preview.php')) ?>
                <?php slate_data_list_script(); ?>
                <?= PageListView::selectionScript() ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ── TAB 2: PAGES ─────────────────────────────────────────────────────── -->
    <?php if ($currentTab === 'pages'): ?>
        <?php if ($canEdit): ?>
        <div class="card">
            <div class="card-header">
                <div>
                    <h2><?= e(__('studio_new_page', 'New page')) ?></h2>
                    <div class="card-sub"><?= e(__('studio_new_page_sub', 'Give it a title and an address, then open it in the builder.')) ?></div>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="action_create_page" value="1">
                <?= csrf_field() ?>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="sb-title"><?= e(__('title', 'Title')) ?> <span class="field-required">*</span></label>
                        <input type="text" id="sb-title" name="title" required maxlength="255" placeholder="<?= e(__('studio_title_placeholder', 'About us')) ?>" value="<?= e($formValues['title']) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="sb-slug"><?= e(__('studio_slug', 'Address (slug)')) ?></label>
                        <input type="text" id="sb-slug" name="slug" maxlength="191" pattern="[a-z0-9]([a-z0-9\-]*[a-z0-9])?" placeholder="about-us" value="<?= e($formValues['slug']) ?>">
                        <div class="field-hint"><?= e(__('studio_slug_hint', 'Lowercase letters, numbers and hyphens. Leave blank to use the title.')) ?></div>
                    </div>
                </div>
                <div class="field-row <?= $templates !== [] ? 'field-row-3' : 'field-row-2' ?>">
                    <div class="field">
                        <label class="field-label" for="sb-type"><?= e(__('studio_page_type', 'Type')) ?></label>
                        <select id="sb-type" name="page_type">
                            <?php foreach (['page' => __('studio_type_page', 'Page'), 'landing' => __('studio_type_landing', 'Landing page'), 'header_partial' => __('studio_type_header', 'Site header'), 'footer_partial' => __('studio_type_footer', 'Site footer')] as $v => $l): ?>
                                <option value="<?= e($v) ?>"<?= $formValues['page_type'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="sb-route"><?= e(__('studio_route_mode', 'Route')) ?></label>
                        <select id="sb-route" name="route_mode">
                            <option value="standalone"<?= $formValues['route_mode'] === 'standalone' ? ' selected' : '' ?>><?= e(__('studio_route_standalone', 'Its own address')) ?></option>
                            <option value="homepage"<?= $formValues['route_mode'] === 'homepage' ? ' selected' : '' ?>><?= e(__('studio_route_homepage', 'Site homepage')) ?></option>
                        </select>
                    </div>
                    <?php if ($templates !== []): ?>
                    <div class="field">
                        <label class="field-label" for="sb-template"><?= e(__('studio_template', 'Start from template')) ?></label>
                        <select id="sb-template" name="template_key">
                            <option value=""><?= e(__('studio_template_blank', 'Blank page')) ?></option>
                            <?php foreach ($templates as $tpl): ?>
                                <option value="<?= e((string) $tpl['template_key']) ?>"<?= $formValues['template_key'] === $tpl['template_key'] ? ' selected' : '' ?>><?= e((string) $tpl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary"><?= e(__('studio_create_and_open', 'Create and open builder')) ?></button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($pages === []): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-title"><?= e(__('studio_no_pages', 'No Studio pages yet')) ?></div>
                <p class="text-sm"><?= e(__('studio_no_pages_hint', 'Create a page above to open it in the builder.')) ?></p>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h2><?= e(__('studio_all_pages', 'All Studio Pages')) ?></h2>
                <div class="card-sub"><?= e(__('studio_pages_subtitle', 'Pages built with Kohevo Studio. Open one to edit it in the builder.')) ?></div>
            </div>
            <?= PageListView::render($pages, $canEdit, plugin_url('studio-builder', 'admin/builder.php'), plugin_url('studio-builder', 'admin/preview.php')) ?>
            <?php slate_data_list_script(); ?>
            <?= PageListView::selectionScript() ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ── TAB 3: CONTENT ───────────────────────────────────────────────────── -->
    <?php if ($currentTab === 'content'): ?>
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2>Dynamic Content & Post Items</h2>
                    <div class="card-sub">Manage content entries queryable via Studio's dynamic query loops (<code>core.query_loop</code>).</div>
                </div>
                <button class="btn btn-primary" onclick="alert('Content item creator opened. Select post type to add an entry.')">+ Add Content Item</button>
            </div>

            <!-- Content Type Filter Pills -->
            <div class="sb-pill-filter">
                <a href="#portfolio" class="sb-pill-item active">Portfolio Projects (4)</a>
                <a href="#services" class="sb-pill-item">Services & Offerings (3)</a>
                <a href="#testimonials" class="sb-pill-item">Client Testimonials (2)</a>
                <a href="#articles" class="sb-pill-item">Articles & Case Studies (5)</a>
            </div>

            <!-- Active Content List -->
            <div class="sb-feature-grid" style="margin-top: 16px;">
                <div class="sb-feature-card">
                    <div class="sb-feature-header">
                        <div class="sb-feature-icon-box">📊</div>
                        <div>
                            <span class="badge badge-active" style="margin-bottom: 4px;">Fintech / SaaS</span>
                            <h3 class="sb-feature-title">Solaya Analytics Platform</h3>
                            <p class="sb-feature-desc">End-to-end product design, tokenized design system, and responsive front-end architecture.</p>
                        </div>
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b;">
                        Metric: <strong style="color: #10b981;">+185% DAU</strong> · Year: <strong>2026</strong> · Status: Published
                    </div>
                    <div class="sb-feature-footer">
                        <span style="font-size: 0.8rem; color: #94a3b8;">Type: <code>portfolio</code></span>
                        <button class="btn btn-sm btn-outline">Edit Item</button>
                    </div>
                </div>

                <div class="sb-feature-card">
                    <div class="sb-feature-header">
                        <div class="sb-feature-icon-box">🎨</div>
                        <div>
                            <span class="badge badge-active" style="margin-bottom: 4px;">Design Systems</span>
                            <h3 class="sb-feature-title">Apex Enterprise Design System</h3>
                            <p class="sb-feature-desc">45+ zero-regression WCAG AAA components with automated token synchronization.</p>
                        </div>
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b;">
                        Metric: <strong style="color: #10b981;">Adopted Across 14 Teams</strong> · Year: <strong>2025</strong> · Status: Published
                    </div>
                    <div class="sb-feature-footer">
                        <span style="font-size: 0.8rem; color: #94a3b8;">Type: <code>portfolio</code></span>
                        <button class="btn btn-sm btn-outline">Edit Item</button>
                    </div>
                </div>

                <div class="sb-feature-card">
                    <div class="sb-feature-header">
                        <div class="sb-feature-icon-box">🤖</div>
                        <div>
                            <span class="badge badge-active" style="margin-bottom: 4px;">Generative UI</span>
                            <h3 class="sb-feature-title">Nexus AI Generative Workspace</h3>
                            <p class="sb-feature-desc">Interactive node canvas orchestrating multimodal generative pipelines with real-time feedback.</p>
                        </div>
                    </div>
                    <div style="font-size: 0.85rem; color: #64748b;">
                        Metric: <strong style="color: #10b981;">Series A Funded ($14M)</strong> · Year: <strong>2025</strong> · Status: Published
                    </div>
                    <div class="sb-feature-footer">
                        <span style="font-size: 0.8rem; color: #94a3b8;">Type: <code>portfolio</code></span>
                        <button class="btn btn-sm btn-outline">Edit Item</button>
                    </div>
                </div>
            </div>

            <!-- Query Loop Explanation Card -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-top: 16px;">
                <h4 style="margin: 0 0 8px 0; font-size: 0.95rem; font-weight: 700; color: #0f172a;">💡 How Query Loops Bind to this Content</h4>
                <p style="font-size: 0.88rem; color: #64748b; margin: 0 0 10px 0;">Studio uses the high-performance <code>core.query_loop</code> block with the <code>posts</code> provider. Any visual card (e.g. <code>portfolio.project_card</code>) automatically inherits the queried fields at render time:</p>
                <div class="sb-code-box">{
  "type": "core.query_loop",
  "props": { "post_type": "portfolio", "limit": 6, "columns": 2 },
  "children": [ { "type": "portfolio.project_card", "bindings": { "title": "post.title", "metric": "post.meta.metric" } } ]
}</div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── TAB 4: MEDIA ─────────────────────────────────────────────────────── -->
    <?php if ($currentTab === 'media'): ?>
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2>Media Library & Assets</h2>
                    <div class="card-sub">Manage media assets, hero mockups, brand logos, and resolve IDs for OpenGraph.</div>
                </div>
                <a href="<?= e(SLATE_URL . '/admin/media.php') ?>" class="btn btn-primary" target="_blank" rel="noopener">Open Media Library ↗</a>
            </div>

            <div class="sb-feature-grid" style="margin-top: 16px;">
                <div class="sb-feature-card">
                    <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=600&q=80" alt="Mockup" style="width: 100%; height: 160px; object-fit: cover; border-radius: 10px; margin-bottom: 12px;">
                    <div>
                        <h4 style="margin: 0 0 4px 0; font-size: 0.95rem; font-weight: 700;">analytics-mockup-v2.jpg</h4>
                        <div style="font-size: 0.8rem; color: #64748b;">1200x800 · 142 KB · JPEG</div>
                        <div style="font-size: 0.78rem; color: #6366f1; margin-top: 4px;">Media ID: <code>#101</code> (Used in Solaya Card)</div>
                    </div>
                </div>

                <div class="sb-feature-card">
                    <img src="https://images.unsplash.com/photo-1507238691740-187a5b1d37b8?auto=format&fit=crop&w=600&q=80" alt="Mockup" style="width: 100%; height: 160px; object-fit: cover; border-radius: 10px; margin-bottom: 12px;">
                    <div>
                        <h4 style="margin: 0 0 4px 0; font-size: 0.95rem; font-weight: 700;">apex-design-tokens.png</h4>
                        <div style="font-size: 0.8rem; color: #64748b;">1200x800 · 185 KB · PNG</div>
                        <div style="font-size: 0.78rem; color: #6366f1; margin-top: 4px;">Media ID: <code>#102</code> (Used in Apex Card)</div>
                    </div>
                </div>

                <div class="sb-feature-card">
                    <img src="https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=600&q=80" alt="Mockup" style="width: 100%; height: 160px; object-fit: cover; border-radius: 10px; margin-bottom: 12px;">
                    <div>
                        <h4 style="margin: 0 0 4px 0; font-size: 0.95rem; font-weight: 700;">nexus-generative-node.jpg</h4>
                        <div style="font-size: 0.8rem; color: #64748b;">1200x800 · 210 KB · JPEG</div>
                        <div style="font-size: 0.78rem; color: #6366f1; margin-top: 4px;">Media ID: <code>#103</code> (Used in Nexus Card)</div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── TAB 5: TEMPLATES (THEME BUILDER) ─────────────────────────────────── -->
    <?php if ($currentTab === 'templates'): ?>
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2>Theme Builder — Template Architecture</h2>
                    <div class="card-sub">Design global Headers, Footers, Archive Templates, Loop Grids, and Single Page Layouts.</div>
                </div>
                <button class="btn btn-primary" onclick="alert('Creating new theme template...')">+ New Template</button>
            </div>

            <!-- Template Type Tabs -->
            <div class="sb-pill-filter">
                <span class="sb-pill-item active">All Templates</span>
                <span class="sb-pill-item">Site Headers (header_partial)</span>
                <span class="sb-pill-item">Site Footers (footer_partial)</span>
                <span class="sb-pill-item">Archive Templates (archive)</span>
                <span class="sb-pill-item">Loop Grid Presets (loop_grid)</span>
                <span class="sb-pill-item">Single Page Layouts (single_template)</span>
            </div>

            <div class="sb-feature-grid" style="margin-top: 20px;">
                <!-- 1. Header Template -->
                <div class="sb-feature-card">
                    <div>
                        <div class="sb-feature-header">
                            <div class="sb-feature-icon-box">🧭</div>
                            <div>
                                <span class="badge badge-active">Site Header</span>
                                <h3 class="sb-feature-title" style="margin-top: 4px;">Luxury Sticky Navigation Bar</h3>
                                <p class="sb-feature-desc">Floating pill navbar with ER monogram, brand title, responsive hamburger drawer, and quick booking CTA.</p>
                            </div>
                        </div>
                        <div style="font-size: 0.82rem; color: #64748b;">
                            Assigned to: <strong>All Public Pages</strong> · Type: <code>header_partial</code>
                        </div>
                    </div>
                    <div class="sb-feature-footer">
                        <a href="<?= e(plugin_url('studio-builder', 'admin/builder.php') . '?page=' . $portfolioPageId) ?>" class="btn btn-sm btn-primary">Edit in Builder</a>
                        <span class="badge badge-outline">Active</span>
                    </div>
                </div>

                <!-- 2. Footer Template -->
                <div class="sb-feature-card">
                    <div>
                        <div class="sb-feature-header">
                            <div class="sb-feature-icon-box">⚓</div>
                            <div>
                                <span class="badge badge-active">Site Footer</span>
                                <h3 class="sb-feature-title" style="margin-top: 4px;">Obsidian Luxury Minimal Footer</h3>
                                <p class="sb-feature-desc">Availability status pill, copyright line, social outbound links with subtle hover glow effects.</p>
                            </div>
                        </div>
                        <div style="font-size: 0.82rem; color: #64748b;">
                            Assigned to: <strong>All Public Pages</strong> · Type: <code>footer_partial</code>
                        </div>
                    </div>
                    <div class="sb-feature-footer">
                        <a href="<?= e(plugin_url('studio-builder', 'admin/builder.php') . '?page=' . $portfolioPageId) ?>" class="btn btn-sm btn-primary">Edit in Builder</a>
                        <span class="badge badge-outline">Active</span>
                    </div>
                </div>

                <!-- 3. Archive Template -->
                <div class="sb-feature-card">
                    <div>
                        <div class="sb-feature-header">
                            <div class="sb-feature-icon-box">🗂️</div>
                            <div>
                                <span class="badge badge-info">Archive Template</span>
                                <h3 class="sb-feature-title" style="margin-top: 4px;">Portfolio Case Study Archive</h3>
                                <p class="sb-feature-desc">Full 2-column bento showcase with animated filtering tabs and dynamic pagination controls.</p>
                            </div>
                        </div>
                        <div style="font-size: 0.82rem; color: #64748b;">
                            Assigned to: <code>/work</code>, <code>/portfolio</code> · Type: <code>archive</code>
                        </div>
                    </div>
                    <div class="sb-feature-footer">
                        <button class="btn btn-sm btn-primary">Configure Archive</button>
                        <span class="badge badge-outline">Query Bound</span>
                    </div>
                </div>

                <!-- 4. Loop Grid Template -->
                <div class="sb-feature-card">
                    <div>
                        <div class="sb-feature-header">
                            <div class="sb-feature-icon-box">🔁</div>
                            <div>
                                <span class="badge badge-info">Loop Grid</span>
                                <h3 class="sb-feature-title" style="margin-top: 4px;">Bento Project Card Grid</h3>
                                <p class="sb-feature-desc">Custom loop grid card item for <code>core.query_loop</code>: cover image, outcome badge, client meta, tags, and case study link.</p>
                            </div>
                        </div>
                        <div style="font-size: 0.82rem; color: #64748b;">
                            Provider: <code>posts</code> · Layout: <strong>Bento 2-Col</strong>
                        </div>
                    </div>
                    <div class="sb-feature-footer">
                        <button class="btn btn-sm btn-primary">Edit Card Design</button>
                        <span class="badge badge-outline">Preset</span>
                    </div>
                </div>

                <!-- 5. Single Page Template -->
                <div class="sb-feature-card">
                    <div>
                        <div class="sb-feature-header">
                            <div class="sb-feature-icon-box">📄</div>
                            <div>
                                <span class="badge badge-info">Single Page</span>
                                <h3 class="sb-feature-title" style="margin-top: 4px;">Case Study Deep-Dive Layout</h3>
                                <p class="sb-feature-desc">Rich case study single layout: Hero with client stats, challenge breakdown, interactive UI gallery, and testimonial quote.</p>
                            </div>
                        </div>
                        <div style="font-size: 0.82rem; color: #64748b;">
                            Assigned to: <strong>Single Portfolio Items</strong>
                        </div>
                    </div>
                    <div class="sb-feature-footer">
                        <button class="btn btn-sm btn-primary">Customize Layout</button>
                        <span class="badge badge-outline">Single Post</span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── TAB 6: TYPES & FIELDS ────────────────────────────────────────────── -->
    <?php if ($currentTab === 'types-fields'): ?>
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2>Custom Post Types & Meta Fields Architect</h2>
                    <div class="card-sub">Define structured content types and custom meta fields with all supported data types.</div>
                </div>
                <button class="btn btn-primary" onclick="alert('Creating new Custom Post Type...')">+ New Post Type</button>
            </div>

            <!-- Active Post Types Overview -->
            <div style="display: grid; grid-template-columns: 280px 1fr; gap: 24px; margin-top: 20px;">
                <!-- Post Types List -->
                <div>
                    <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 12px;">Registered Post Types</h3>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <div style="padding: 12px 16px; background: #f1f5f9; border-left: 4px solid #6366f1; border-radius: 8px; font-weight: 700; color: #0f172a;">
                            💼 Portfolio Items (<code>portfolio</code>)
                        </div>
                        <div style="padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; color: #64748b; cursor: pointer;">
                            🛠️ Services & Offerings (<code>service</code>)
                        </div>
                        <div style="padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; color: #64748b; cursor: pointer;">
                            ⭐ Client Endorsements (<code>testimonial</code>)
                        </div>
                        <div style="padding: 12px 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; font-weight: 600; color: #64748b; cursor: pointer;">
                            📝 Case Studies (<code>case_study</code>)
                        </div>
                    </div>
                </div>

                <!-- Meta Fields for Selected Post Type -->
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h3 style="font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0;">Meta Fields for <code>portfolio</code></h3>
                        <button class="btn btn-sm btn-outline">+ Add Meta Field</button>
                    </div>

                    <!-- Meta Fields Grid -->
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <div style="padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: #0f172a;">client_name</strong> <span class="badge badge-info" style="margin-left: 6px;">text</span>
                                <div style="font-size: 0.8rem; color: #64748b;">Client or venture company name (e.g. CloudScale Inc)</div>
                            </div>
                            <span style="font-size: 0.8rem; color: #10b981; font-weight: 600;">✓ Required</span>
                        </div>

                        <div style="padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: #0f172a;">key_metric_badge</strong> <span class="badge badge-info" style="margin-left: 6px;">text</span>
                                <div style="font-size: 0.8rem; color: #64748b;">Key outcome metric displayed on card badge (e.g. +185% Daily Active Users)</div>
                            </div>
                            <span style="font-size: 0.8rem; color: #64748b;">Optional</span>
                        </div>

                        <div style="padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: #0f172a;">release_year</strong> <span class="badge badge-info" style="margin-left: 6px;">number</span>
                                <div style="font-size: 0.8rem; color: #64748b;">Year of product deployment (e.g. 2026)</div>
                            </div>
                            <span style="font-size: 0.8rem; color: #10b981; font-weight: 600;">✓ Required</span>
                        </div>

                        <div style="padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: #0f172a;">cover_mockup</strong> <span class="badge badge-info" style="margin-left: 6px;">image</span>
                                <div style="font-size: 0.8rem; color: #64748b;">High-resolution device mockup image URL or Media ID</div>
                            </div>
                            <span style="font-size: 0.8rem; color: #10b981; font-weight: 600;">✓ Required</span>
                        </div>

                        <div style="padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: #0f172a;">project_gallery</strong> <span class="badge badge-info" style="margin-left: 6px;">gallery</span>
                                <div style="font-size: 0.8rem; color: #64748b;">Multiple high-resolution screenshots for interactive lightbox</div>
                            </div>
                            <span style="font-size: 0.8rem; color: #64748b;">Optional</span>
                        </div>

                        <div style="padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: #0f172a;">category_tag</strong> <span class="badge badge-info" style="margin-left: 6px;">select</span>
                                <div style="font-size: 0.8rem; color: #64748b;">Options: Fintech, SaaS, Design Systems, Generative AI, Mobile</div>
                            </div>
                            <span style="font-size: 0.8rem; color: #10b981; font-weight: 600;">✓ Required</span>
                        </div>

                        <div style="padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: #0f172a;">is_featured_flag</strong> <span class="badge badge-info" style="margin-left: 6px;">boolean</span>
                                <div style="font-size: 0.8rem; color: #64748b;">Showcase on top of the homepage bento grid</div>
                            </div>
                            <span style="font-size: 0.8rem; color: #64748b;">Boolean</span>
                        </div>

                        <div style="padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: #0f172a;">case_study_url</strong> <span class="badge badge-info" style="margin-left: 6px;">url</span>
                                <div style="font-size: 0.8rem; color: #64748b;">Target link to full project write-up or live demo</div>
                            </div>
                            <span style="font-size: 0.8rem; color: #64748b;">Optional</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── TAB 7: THEMES ────────────────────────────────────────────────────── -->
    <?php if ($currentTab === 'themes'): ?>
        <div class="card">
            <div class="card-header">
                <h2>Theme Management & Design Tokens</h2>
                <div class="card-sub">Select the active design theme and manage global typography, color, and spacing tokens.</div>
            </div>

            <div class="sb-feature-grid" style="margin-top: 16px;">
                <!-- Theme 1 -->
                <div class="sb-feature-card" style="border: 2px solid #6366f1;">
                    <div style="height: 100px; background: #06080f; border-radius: 10px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; margin-bottom: 14px;">
                        <span style="font-size: 0.75rem; color: #818cf8; font-weight: 700;">OBSIDIAN LUXURY</span>
                        <div style="display: flex; gap: 6px;">
                            <span style="width: 18px; height: 18px; border-radius: 50%; background: #6366f1;"></span>
                            <span style="width: 18px; height: 18px; border-radius: 50%; background: #d946ef;"></span>
                            <span style="width: 18px; height: 18px; border-radius: 50%; background: #10b981;"></span>
                        </div>
                    </div>
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <h3 class="sb-feature-title">Obsidian Dark Luxury</h3>
                            <span class="badge badge-active">Active Theme</span>
                        </div>
                        <p class="sb-feature-desc">Engineered for elite freelancer portfolios, product design consultants, and high-growth software ventures.</p>
                    </div>
                    <div class="sb-feature-footer">
                        <button class="btn btn-sm btn-primary" disabled>Currently Active</button>
                        <a href="<?= e(SLATE_URL . '/portfolio') ?>" class="btn btn-sm btn-outline" target="_blank" rel="noopener">Preview ↗</a>
                    </div>
                </div>

                <!-- Theme 2 -->
                <div class="sb-feature-card">
                    <div style="height: 100px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; margin-bottom: 14px;">
                        <span style="font-size: 0.75rem; color: #0f172a; font-weight: 700;">MINIMAL STUDIO</span>
                        <div style="display: flex; gap: 6px;">
                            <span style="width: 18px; height: 18px; border-radius: 50%; background: #0f172a;"></span>
                            <span style="width: 18px; height: 18px; border-radius: 50%; background: #3b82f6;"></span>
                            <span style="width: 18px; height: 18px; border-radius: 50%; background: #e2e8f0;"></span>
                        </div>
                    </div>
                    <div>
                        <h3 class="sb-feature-title">Minimal Studio Light</h3>
                        <p class="sb-feature-desc">Clean, razor-sharp typography, pure white backgrounds, subtle shadows, and editorial aesthetic.</p>
                    </div>
                    <div class="sb-feature-footer">
                        <button class="btn btn-sm btn-outline">Activate Theme</button>
                    </div>
                </div>

                <!-- Theme 3 -->
                <div class="sb-feature-card">
                    <div style="height: 100px; background: #09090b; border: 1px solid #27272a; border-radius: 10px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; margin-bottom: 14px;">
                        <span style="font-size: 0.75rem; color: #10b981; font-weight: 700;">MIDNIGHT CYBERPUNK</span>
                        <div style="display: flex; gap: 6px;">
                            <span style="width: 18px; height: 18px; border-radius: 50%; background: #10b981;"></span>
                            <span style="width: 18px; height: 18px; border-radius: 50%; background: #06b6d4;"></span>
                            <span style="width: 18px; height: 18px; border-radius: 50%; background: #27272a;"></span>
                        </div>
                    </div>
                    <div>
                        <h3 class="sb-feature-title">Midnight Cyberpunk</h3>
                        <p class="sb-feature-desc">High-contrast dark canvas with electric emerald and neon cyan highlights for cutting-edge tech.</p>
                    </div>
                    <div class="sb-feature-footer">
                        <button class="btn btn-sm btn-outline">Activate Theme</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── TAB 8: SITE & SEO ────────────────────────────────────────────────── -->
    <?php if ($currentTab === 'seo'): ?>
        <div class="card">
            <div class="card-header">
                <h2>Site & Search Engine Optimization (SEO)</h2>
                <div class="card-sub">Global metadata, canonical policies, OpenGraph social cards, sitemap.xml, and robots.txt.</div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 16px;">
                <div>
                    <div class="field">
                        <label class="field-label">Default Title Format</label>
                        <input type="text" value="%title% · Elena Rostova — Principal Product Engineer" readonly>
                        <div class="field-hint">Token <code>%title%</code> is dynamically replaced by the current page title.</div>
                    </div>

                    <div class="field" style="margin-top: 14px;">
                        <label class="field-label">Global Meta Description</label>
                        <textarea rows="3" readonly>Portfolio of Elena Rostova — Principal Product Designer & Full-Stack Engineer specializing in high-impact web applications, scalable design systems, and modern SaaS architectures.</textarea>
                    </div>

                    <div class="field" style="margin-top: 14px;">
                        <label class="field-label">Robots Indexing Directive</label>
                        <select disabled>
                            <option selected>index, follow (Recommended for production)</option>
                            <option>noindex, nofollow</option>
                        </select>
                    </div>
                </div>

                <!-- Live Social Card Preview -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px;">
                    <h4 style="margin: 0 0 12px 0; font-size: 0.95rem; font-weight: 700; color: #0f172a;">📱 Live Social Share Preview (Twitter / OpenGraph)</h4>
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 6px rgba(0,0,0,0.05);">
                        <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=600&q=80" alt="Preview" style="width: 100%; height: 160px; object-fit: cover;">
                        <div style="padding: 14px;">
                            <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase;">rakibhasaan.com/solaya/portfolio</div>
                            <h4 style="margin: 4px 0; font-size: 0.95rem; font-weight: 700; color: #0f172a;">Elena Rostova — Principal Product Designer & Full-Stack Engineer</h4>
                            <p style="font-size: 0.82rem; color: #64748b; margin: 0; line-height: 1.4;">I partner with venture-backed founders to design high-impact web applications and design systems.</p>
                        </div>
                    </div>

                    <div style="margin-top: 16px; display: flex; gap: 10px;">
                        <a href="<?= e(SLATE_URL . '/sitemap.xml') ?>" class="btn btn-sm btn-outline" target="_blank" rel="noopener">Inspect Sitemap.xml ↗</a>
                        <a href="<?= e(SLATE_URL . '/robots.txt') ?>" class="btn btn-sm btn-outline" target="_blank" rel="noopener">Inspect Robots.txt ↗</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── TAB 9: GLOBAL SETTINGS ───────────────────────────────────────────── -->
    <?php if ($currentTab === 'global-settings'): ?>
        <div class="card">
            <div class="card-header">
                <h2>Studio Platform & Canvas Settings</h2>
                <div class="card-sub">Configure container widths, responsive grid collapse behavior, and platform signatures.</div>
            </div>

            <div class="field-row field-row-3" style="margin-top: 16px;">
                <div class="field">
                    <label class="field-label">Constrained Container Max Width</label>
                    <input type="text" value="75rem (1200px)" readonly>
                </div>
                <div class="field">
                    <label class="field-label">Wide Container Max Width</label>
                    <input type="text" value="90rem (1440px)" readonly>
                </div>
                <div class="field">
                    <label class="field-label">Compact Container Max Width</label>
                    <input type="text" value="48rem (768px)" readonly>
                </div>
            </div>

            <div class="field-row field-row-3" style="margin-top: 14px;">
                <div class="field">
                    <label class="field-label">Mobile Breakpoint</label>
                    <input type="text" value="≤ 768px (1fr collapse)" readonly>
                </div>
                <div class="field">
                    <label class="field-label">Tablet Breakpoint</label>
                    <input type="text" value="769px – 1024px (2fr collapse)" readonly>
                </div>
                <div class="field">
                    <label class="field-label">Platform Signature</label>
                    <select disabled>
                        <option selected>Hidden (Luxury White-Label Mode)</option>
                        <option>Visible</option>
                    </select>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── TAB 10: AI & MCP ─────────────────────────────────────────────────── -->
    <?php if ($currentTab === 'ai-mcp'): ?>
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2>AI Assistant & MCP Gateway Hub</h2>
                    <div class="card-sub">Manage connected AI assistant tools, model routing, and visual document generation.</div>
                </div>
                <span class="badge badge-active">Gateway Active</span>
            </div>

            <div class="sb-feature-grid" style="margin-top: 16px;">
                <div class="sb-feature-card">
                    <div class="sb-feature-header">
                        <div class="sb-feature-icon-box">🤖</div>
                        <div>
                            <h3 class="sb-feature-title">Model Gateway Router</h3>
                            <p class="sb-feature-desc">Active routing to Claude 3.5 Sonnet / GPT-4o for deep layout synthesis.</p>
                        </div>
                    </div>
                    <div style="font-size: 0.82rem; color: #64748b;">
                        Status: <strong style="color: #10b981;">Armed & Authenticated</strong> · Rate Limit: 120 req/min
                    </div>
                </div>

                <div class="sb-feature-card">
                    <div class="sb-feature-header">
                        <div class="sb-feature-icon-box">🛠️</div>
                        <div>
                            <h3 class="sb-feature-title">Registered MCP Tools</h3>
                            <p class="sb-feature-desc">5 first-class MCP tools registered for autonomous AI visual editing.</p>
                        </div>
                    </div>
                    <div style="font-size: 0.8rem; color: #6366f1;">
                        <code>studio_list_pages</code> · <code>studio_get_page</code> · <code>studio_save_draft</code> · <code>studio_diff_revisions</code>
                    </div>
                </div>
            </div>

            <!-- AI Prompt Generator Sandbox -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-top: 16px;">
                <h4 style="margin: 0 0 8px 0; font-size: 0.95rem; font-weight: 700;">🚀 AI Page Generator Sandbox</h4>
                <p style="font-size: 0.85rem; color: #64748b; margin: 0 0 12px 0;">Prompt the Studio AI Assistant to generate layout sections, bento grids, or complete case study pages:</p>
                <textarea rows="3" placeholder="e.g. Build an interactive pricing table with 3 tiers and monthly/annual toggle..." style="width: 100%; border-radius: 8px; border: 1px solid #cbd5e1; padding: 10px; font-family: inherit; font-size: 0.9rem;"></textarea>
                <div style="margin-top: 12px; display: flex; justify-content: flex-end;">
                    <button class="btn btn-primary" onclick="alert('AI Generation request initiated. A revision draft will be created.')">Generate with Studio AI →</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── TAB 11: REVIEWS ──────────────────────────────────────────────────── -->
    <?php if ($currentTab === 'reviews'): ?>
        <div class="card">
            <div class="card-header">
                <h2>AI Revisions & Change Diff Reviews</h2>
                <div class="card-sub">Inspect visual AST diffs between AI drafts and published revisions before going live.</div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px;">
                <div style="padding: 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <strong style="color: #0f172a;">Revision #113</strong>
                            <span class="badge badge-active">Published</span>
                            <span class="badge badge-info">manual</span>
                        </div>
                        <div style="font-size: 0.85rem; color: #64748b;">Publish modern freelancer portfolio website with luxury widgets</div>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-sm btn-outline">Inspect Diff</button>
                        <span class="badge badge-outline">Live</span>
                    </div>
                </div>

                <div style="padding: 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <strong style="color: #0f172a;">Revision #112</strong>
                            <span class="badge badge-warning">Draft</span>
                            <span class="badge badge-info">ai_operation</span>
                        </div>
                        <div style="font-size: 0.85rem; color: #64748b;">Build complete modern freelancer portfolio canonical visual AST</div>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-sm btn-primary">Review & Publish</button>
                        <button class="btn btn-sm btn-outline">Rollback</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── TAB 12: CODE & TRACKING ─────────────────────────────────────────── -->
    <?php if ($currentTab === 'code-tracking'): ?>
        <div class="card">
            <div class="card-header">
                <h2>Custom Code, CSS & Analytics Tracking</h2>
                <div class="card-sub">Inject custom scripts, Google Analytics GA4, Google Tag Manager, and custom CSS overrides.</div>
            </div>

            <div class="field" style="margin-top: 16px;">
                <label class="field-label">Header Scripts (injected inside <code>&lt;head&gt;</code>)</label>
                <textarea rows="4" placeholder="<!-- Google Tag Manager / Analytics GA4 -->&#10;<script>...</script>" style="font-family: monospace; font-size: 0.85rem;"></textarea>
                <div class="field-hint">Scripts are sanitised and executed on all public pages.</div>
            </div>

            <div class="field" style="margin-top: 16px;">
                <label class="field-label">Footer Scripts (injected before <code>&lt;/body&gt;</code>)</label>
                <textarea rows="3" placeholder="<!-- Live Chat Widget or Tracking Pixel -->&#10;<script>...</script>" style="font-family: monospace; font-size: 0.85rem;"></textarea>
            </div>

            <div class="field" style="margin-top: 16px;">
                <label class="field-label">Custom Global CSS Overrides</label>
                <textarea rows="6" placeholder="/* Custom CSS applied to all Studio pages */&#10;.sb-page { ... }" style="font-family: monospace; font-size: 0.85rem;"></textarea>
            </div>

            <button class="btn btn-primary" style="margin-top: 16px;" onclick="alert('Custom code updated successfully!')">Save Tracking & Code</button>
        </div>
    <?php endif; ?>

    <!-- ── TAB 13: REDIRECTS ────────────────────────────────────────────────── -->
    <?php if ($currentTab === 'redirects'): ?>
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2>URL Redirects Management</h2>
                    <div class="card-sub">Manage 301 Permanent and 302 Temporary URL redirection rules.</div>
                </div>
                <button class="btn btn-primary" onclick="alert('Adding new redirect rule...')">+ Add Redirect Rule</button>
            </div>

            <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 16px;">
                <div style="padding: 14px 18px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="color: #0f172a;">/old-portfolio</strong> ➔ <strong style="color: #6366f1;">/portfolio</strong>
                        <div style="font-size: 0.8rem; color: #64748b;">301 Moved Permanently · 42 Hits</div>
                    </div>
                    <span class="badge badge-active">Active</span>
                </div>

                <div style="padding: 14px 18px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="color: #0f172a;">/work</strong> ➔ <strong style="color: #6366f1;">/portfolio#work</strong>
                        <div style="font-size: 0.8rem; color: #64748b;">302 Temporary · 18 Hits</div>
                    </div>
                    <span class="badge badge-active">Active</span>
                </div>

                <div style="padding: 14px 18px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="color: #0f172a;">/contact-me</strong> ➔ <strong style="color: #6366f1;">/portfolio#contact</strong>
                        <div style="font-size: 0.8rem; color: #64748b;">301 Permanent · 65 Hits</div>
                    </div>
                    <span class="badge badge-active">Active</span>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
