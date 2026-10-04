<?php
/**
 * Kohevo Studio — Pages Management Console.
 *
 * Full page listing, filtering by route mode/type, new page creation,
 * and direct launch into the visual canvas builder.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Admin\PageListView;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();

$studioApp   = StudioRuntimeFactory::build()->app;
$studioActor = StudioActor::fromCurrentSession();
$pageTitle   = __('studio_pages', 'Studio Pages');
$currentNav  = 'studio-pages';
$flash       = null;
$formValues  = ['title' => '', 'slug' => '', 'page_type' => 'page', 'route_mode' => 'standalone', 'template_key' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
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

$filter = (string) ($_GET['filter'] ?? 'all');
$filteredPages = $pages;
if ($filter === 'page' || $filter === 'landing' || $filter === 'header_partial' || $filter === 'footer_partial') {
    $filteredPages = array_values(array_filter($pages, static fn(array $p) => ($p['page_type'] ?? '') === $filter));
} elseif ($filter === 'homepage') {
    $filteredPages = array_values(array_filter($pages, static fn(array $p) => ($p['route_mode'] ?? '') === 'homepage'));
}

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin-wrapper">
    <?php sb_render_admin_nav('pages'); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($listError !== null): ?>
        <div class="alert alert-error" role="status"><?= e($listError) ?></div>
    <?php else: ?>

    <?php if ($canEdit): ?>
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <div>
                <h2><?= e(__('studio_new_page', 'Create New Page')) ?></h2>
                <div class="card-sub"><?= e(__('studio_new_page_sub', 'Give it a title and address, then open directly in the Visual Canvas Builder.')) ?></div>
            </div>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="sb-title"><?= e(__('title', 'Title')) ?> <span class="field-required">*</span></label>
                    <input type="text" id="sb-title" name="title" required maxlength="255" placeholder="<?= e(__('studio_title_placeholder', 'About us')) ?>" value="<?= e($formValues['title']) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="sb-slug"><?= e(__('studio_slug', 'Address (slug)')) ?></label>
                    <input type="text" id="sb-slug" name="slug" maxlength="191" pattern="[a-z0-9]([a-z0-9\-]*[a-z0-9])?" placeholder="about-us" value="<?= e($formValues['slug']) ?>">
                    <div class="field-hint"><?= e(__('studio_slug_hint', 'Lowercase letters, numbers and hyphens. Leave blank to generate from title.')) ?></div>
                </div>
            </div>
            <div class="field-row <?= $templates !== [] ? 'field-row-3' : 'field-row-2' ?>">
                <div class="field">
                    <label class="field-label" for="sb-type"><?= e(__('studio_page_type', 'Page Type')) ?></label>
                    <select id="sb-type" name="page_type">
                        <?php foreach (['page' => __('studio_type_page', 'Standard Page'), 'landing' => __('studio_type_landing', 'Landing Page'), 'header_partial' => __('studio_type_header', 'Site Header Partial'), 'footer_partial' => __('studio_type_footer', 'Site Footer Partial')] as $v => $l): ?>
                            <option value="<?= e($v) ?>"<?= $formValues['page_type'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="sb-route"><?= e(__('studio_route_mode', 'Route Mode')) ?></label>
                    <select id="sb-route" name="route_mode">
                        <option value="standalone"<?= $formValues['route_mode'] === 'standalone' ? ' selected' : '' ?>><?= e(__('studio_route_standalone', 'Dedicated URL Path')) ?></option>
                        <option value="homepage"<?= $formValues['route_mode'] === 'homepage' ? ' selected' : '' ?>><?= e(__('studio_route_homepage', 'Site Homepage (/)')) ?></option>
                    </select>
                </div>
                <?php if ($templates !== []): ?>
                <div class="field">
                    <label class="field-label" for="sb-template"><?= e(__('studio_template', 'Start from template')) ?></label>
                    <select id="sb-template" name="template_key">
                        <option value=""><?= e(__('studio_template_blank', 'Blank canvas')) ?></option>
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= e((string) $tpl['template_key']) ?>"<?= $formValues['template_key'] === $tpl['template_key'] ? ' selected' : '' ?>><?= e((string) $tpl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary">
                <?= sb_svg('plus', 14) ?>
                <span><?= e(__('studio_create_and_open', 'Create & Open Canvas Builder')) ?></span>
            </button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <h2>All Studio Pages (<?= count($filteredPages) ?>)</h2>
                <div class="card-sub">Manage and edit your published pages, drafts, and system templates.</div>
            </div>
            <div class="sb-pill-filter" style="margin-bottom: 0;">
                <a href="pages.php" class="sb-pill-item <?= $filter === 'all' ? 'active' : '' ?>">All (<?= count($pages) ?>)</a>
                <a href="pages.php?filter=page" class="sb-pill-item <?= $filter === 'page' ? 'active' : '' ?>">Pages</a>
                <a href="pages.php?filter=landing" class="sb-pill-item <?= $filter === 'landing' ? 'active' : '' ?>">Landing</a>
                <a href="pages.php?filter=homepage" class="sb-pill-item <?= $filter === 'homepage' ? 'active' : '' ?>">Homepage</a>
                <a href="pages.php?filter=header_partial" class="sb-pill-item <?= $filter === 'header_partial' ? 'active' : '' ?>">Headers</a>
                <a href="pages.php?filter=footer_partial" class="sb-pill-item <?= $filter === 'footer_partial' ? 'active' : '' ?>">Footers</a>
            </div>
        </div>

        <?php if ($filteredPages === []): ?>
        <div class="empty">
            <div class="empty-title"><?= e(__('studio_no_pages', 'No Studio pages match this filter')) ?></div>
            <p class="text-sm"><?= e(__('studio_no_pages_hint', 'Create a page above to open it in the builder.')) ?></p>
        </div>
        <?php else: ?>
        <?= PageListView::render($filteredPages, $canEdit, plugin_url('studio-builder', 'admin/builder.php'), plugin_url('studio-builder', 'admin/preview.php')) ?>
        <?php slate_data_list_script(); ?>
        <?= PageListView::selectionScript() ?>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
