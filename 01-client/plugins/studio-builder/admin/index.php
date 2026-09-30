<?php
/**
 * Kohevo Studio — page list + "new page" (the builder's entry route).
 *
 *   GET  /plugins/studio-builder/admin/index.php            list the tenant's Studio pages
 *   POST /plugins/studio-builder/admin/index.php            create a page (CSRF), optionally
 *                                                           from an existing page template,
 *                                                           then open it in the builder
 *
 * Every read and write goes through StudioApplicationService (tenant ->
 * authentication -> studio-builder entitlement -> RBAC); this file holds no
 * Studio query of its own. The template library, reusable presets, global
 * components and design tokens are managed inside the builder (Phase 6).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';

use Slate\Module\StudioBuilder\Admin\PageListView;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();

$studioApp   = StudioRuntimeFactory::build()->app;
$studioActor = StudioActor::fromCurrentSession();
$pageTitle   = __('studio_pages', 'Studio pages');
$currentNav  = 'studio-builder';
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
                // The page was created a moment ago: its initial revision is the expected one.
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

require SLATE_ROOT . '/admin/partials/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= e(__('studio_pages', 'Studio pages')) ?></h1>
        <p class="page-header-sub"><?= e(__('studio_pages_subtitle', 'Pages built with Kohevo Studio. Open one to edit it in the builder.')) ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($listError !== null): ?>
    <div class="alert alert-error" role="status"><?= e($listError) ?></div>
<?php else: ?>

<?php if ($canEdit): ?>
<div class="card">
    <h2 class="card-title"><?= e(__('studio_new_page', 'New page')) ?></h2>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <div>
            <label class="field-label" for="sb-title"><?= e(__('title', 'Title')) ?></label>
            <input id="sb-title" name="title" required maxlength="255" value="<?= e($formValues['title']) ?>">
        </div>
        <div>
            <label class="field-label" for="sb-slug"><?= e(__('studio_slug', 'Address (slug)')) ?></label>
            <input id="sb-slug" name="slug" maxlength="191" pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?" placeholder="about-us" value="<?= e($formValues['slug']) ?>">
        </div>
        <div>
            <label class="field-label" for="sb-type"><?= e(__('studio_page_type', 'Type')) ?></label>
            <select id="sb-type" name="page_type">
                <?php foreach (['page' => __('studio_type_page', 'Page'), 'landing' => __('studio_type_landing', 'Landing page'), 'header_partial' => __('studio_type_header', 'Site header'), 'footer_partial' => __('studio_type_footer', 'Site footer')] as $v => $l): ?>
                    <option value="<?= e($v) ?>"<?= $formValues['page_type'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label" for="sb-route"><?= e(__('studio_route_mode', 'Route')) ?></label>
            <select id="sb-route" name="route_mode">
                <option value="standalone"<?= $formValues['route_mode'] === 'standalone' ? ' selected' : '' ?>><?= e(__('studio_route_standalone', 'Its own address')) ?></option>
                <option value="homepage"<?= $formValues['route_mode'] === 'homepage' ? ' selected' : '' ?>><?= e(__('studio_route_homepage', 'Site homepage')) ?></option>
            </select>
        </div>
        <?php if ($templates !== []): ?>
        <div>
            <label class="field-label" for="sb-template"><?= e(__('studio_template', 'Start from template')) ?></label>
            <select id="sb-template" name="template_key">
                <option value=""><?= e(__('studio_template_blank', 'Blank page')) ?></option>
                <?php foreach ($templates as $tpl): ?>
                    <option value="<?= e((string) $tpl['template_key']) ?>"<?= $formValues['template_key'] === $tpl['template_key'] ? ' selected' : '' ?>><?= e((string) $tpl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <button type="submit" class="btn btn-primary"><?= e(__('studio_create_and_open', 'Create and open builder')) ?></button>
        </div>
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
<?= PageListView::render($pages, $canEdit, plugin_url('studio-builder', 'admin/builder.php'), plugin_url('studio-builder', 'admin/preview.php')) ?>
<?php slate_data_list_script(); ?>
<?= PageListView::selectionScript() ?>
<?php endif; ?>

<?php endif; ?>
<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
