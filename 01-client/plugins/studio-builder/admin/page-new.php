<?php
/**
 * Kohevo Studio — New page.
 *
 * One screen for one job: give a page a title, address, type and (optionally) a starting template, then open it in the
 * visual canvas builder. The list is pages.php; one page's settings, duplicate and delete are page-edit.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();

$studioApp   = StudioRuntimeFactory::build()->app;
$studioActor = StudioActor::fromCurrentSession();
$pageTitle   = __('studio_new_page', 'New page');
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

$templates = [];
$listError = null;
try {
    $studioApp->listPages($studioActor); // authorizes: a viewer without access sees the same notice as the list
    $templates = $studioApp->listTemplates($studioActor, 'page_template');
} catch (StudioException $e) {
    $listError = $e->httpStatus() === 403
        ? __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')
        : __('studio_pages_unavailable', 'Studio pages could not be loaded.');
}
$canEdit = $studioActor->can(StudioPermissions::EDIT) && $listError === null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin-wrapper">
    <?php sb_render_admin_nav('pages'); ?>
    <?php sb_render_crumbs([[__('studio_pages', 'Studio Pages'), 'pages.php'], [__('studio_new_page', 'New page'), null]]); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($listError !== null): ?>
        <div class="alert alert-error" role="status"><?= e($listError) ?></div>
    <?php elseif (!$canEdit): ?>
        <div class="alert alert-error" role="status"><?= e(__('studio_page_create_denied', 'You cannot create Studio pages here.')) ?></div>
    <?php else: ?>
    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_new_page', 'New page')) ?></h2>
            <p><?= e(__('studio_new_page_sub', 'Give it a title and address, then open directly in the Visual Canvas Builder.')) ?></p>
        </div>
    </div>

    <form method="post" class="card sb-form-card">
        <?= csrf_field() ?>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="sb-title"><?= e(__('title', 'Title')) ?> <span class="field-required">*</span></label>
                <input type="text" id="sb-title" name="title" required maxlength="255" autofocus placeholder="<?= e(__('studio_title_placeholder', 'About us')) ?>" value="<?= e($formValues['title']) ?>">
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
        <div class="sb-form-actions">
            <button type="submit" class="btn btn-primary">
                <?= sb_svg('plus', 14) ?>
                <span><?= e(__('studio_create_and_open', 'Create & Open Canvas Builder')) ?></span>
            </button>
            <a href="pages.php" class="btn btn-ghost"><?= e(__('cancel', 'Cancel')) ?></a>
        </div>
    </form>
    <script>
    // The address follows the title until it is typed in by hand.
    (function () {
        var title = document.getElementById('sb-title'), slug = document.getElementById('sb-slug');
        if (!title || !slug) return;
        var touched = slug.value !== '';
        slug.addEventListener('input', function () { touched = slug.value !== ''; });
        title.addEventListener('input', function () {
            if (touched) return;
            slug.placeholder = title.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'about-us';
        });
    })();
    </script>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
