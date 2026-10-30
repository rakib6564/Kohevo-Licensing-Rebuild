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
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();

$studioApp   = StudioRuntimeFactory::build()->app;
$studioActor = StudioActor::fromCurrentSession();
$tenantId    = current_tenant_id();
$postTypes   = PageMeta::postTypes($tenantId);
$flash       = null;
// `?type=` (from a content type's list) starts the form as a new item of that type.
$formValues  = ['title' => '', 'slug' => '', 'page_type' => 'page', 'route_mode' => 'standalone', 'template_key' => '', 'content_type' => (string) ($_GET['type'] ?? '')];
if (!isset($postTypes[$formValues['content_type']])) {
    $formValues['content_type'] = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        foreach (array_keys($formValues) as $k) {
            $formValues[$k] = trim((string) ($_POST[$k] ?? ''));
        }
        if (!isset($postTypes[$formValues['content_type']]) || !in_array($formValues['page_type'], PageMeta::PAGE_TYPES, true)) {
            $formValues['content_type'] = '';
        }
        if ($formValues['slug'] === '') {
            $formValues['slug'] = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($formValues['title'])), '-');
        }
        try {
            $created = $studioApp->createPage($studioActor, $formValues['title'], $formValues['slug'], $formValues['page_type'], $formValues['route_mode']);
            $newId = (int) $created['page']['id'];
            if ($formValues['content_type'] !== '') {
                PageMeta::assignType($tenantId, $newId, $formValues['content_type']);
            }
            if ($formValues['template_key'] !== '') {
                // The page exists from here on: a template that cannot be applied leaves it blank, and says so on its
                // settings screen (which links to the builder) instead of reporting that nothing was created.
                try {
                    $studioApp->applyTemplate($studioActor, $formValues['template_key'], $newId, (int) $created['revision']['id']);
                } catch (\Throwable $e) {
                    sb_log_error('new page template', $e);
                    sb_flash_redirect('page-edit.php?id=' . $newId, ['type' => 'warning', 'msg' => __('studio_page_created_template_failed', 'The page was created, but the template could not be applied, so it starts blank. Open it in the builder to add content.')]);
                }
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
$newType  = $postTypes[$formValues['content_type']] ?? null;
$listHref = 'pages.php' . ($newType !== null ? '?type=' . rawurlencode($formValues['content_type']) : '');
$listName = $newType !== null ? (string) ($newType['plural'] ?? $newType['singular']) : __('studio_pages', 'Studio Pages');
$newName  = $newType !== null ? sprintf(__('studio_new_item_button', 'New %s'), (string) $newType['singular']) : __('studio_new_page', 'New page');
$pageTitle  = $newName;
$currentNav = $newType !== null ? 'studio-type-' . $formValues['content_type'] : 'studio-pages';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">
    <?php sb_render_crumbs([[$listName, $listHref], [$newName, null]]); ?>

    <?php sb_render_flash($flash); ?>

    <?php if ($listError !== null): ?>
        <div class="alert alert-error" role="status"><?= e($listError) ?></div>
    <?php elseif (!$canEdit): ?>
        <div class="alert alert-error" role="status"><?= e(__('studio_page_create_denied', 'You cannot create Studio pages here.')) ?></div>
    <?php else: ?>
    <div class="sb-screen-head">
        <div>
            <h2><?= e($newName) ?></h2>
            <p><?= e(__('studio_new_page_sub', 'Give it a title and address, then open directly in the Visual Canvas Builder.')) ?></p>
        </div>
    </div>

    <form method="post" class="card sb-form-card">
        <?= csrf_field() ?>
        <div class="sb-form-grid">
            <div class="sb-field">
                <label class="sb-field__label" for="sb-title"><?= e(__('title', 'Title')) ?> <span class="field-required">*</span></label>
                <input type="text" id="sb-title" name="title" required maxlength="255" autofocus placeholder="<?= e(__('studio_title_placeholder', 'About us')) ?>" value="<?= e($formValues['title']) ?>">
            </div>
            <div class="sb-field">
                <label class="sb-field__label" for="sb-slug"><?= e(__('studio_slug', 'Address (slug)')) ?></label>
                <input type="text" id="sb-slug" name="slug" maxlength="191" pattern="[a-z0-9]([a-z0-9\-]*[a-z0-9])?" placeholder="about-us" value="<?= e($formValues['slug']) ?>">
                <p class="sb-field__hint"><?= e(__('studio_slug_hint', 'Lowercase letters, numbers and hyphens. Leave blank to generate from title.')) ?></p>
            </div>
        </div>
        <div class="sb-form-grid">
            <div class="sb-field">
                <label class="sb-field__label" for="sb-type"><?= e(__('studio_page_type', 'Page Type')) ?></label>
                <select id="sb-type" name="page_type">
                    <?php foreach (['page' => __('studio_type_page', 'Standard Page'), 'landing' => __('studio_type_landing', 'Landing Page'), 'header_partial' => __('studio_type_header', 'Site Header Partial'), 'footer_partial' => __('studio_type_footer', 'Site Footer Partial')] as $v => $l): ?>
                        <option value="<?= e($v) ?>"<?= $formValues['page_type'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($postTypes !== []): ?>
            <div class="sb-field">
                <label class="sb-field__label" for="sb-ctype-new"><?= e(__('studio_page_content_type_short', 'Content type')) ?></label>
                <select id="sb-ctype-new" name="content_type">
                    <option value=""><?= e(__('studio_page_no_type', 'None (a plain page)')) ?></option>
                    <?php foreach ($postTypes as $tKey => $t): ?>
                        <option value="<?= e((string) $tKey) ?>"<?= $formValues['content_type'] === (string) $tKey ? ' selected' : '' ?>><?= e((string) ($t['singular'] ?? $tKey)) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="sb-field__hint"><?= e(__('studio_page_content_type_hint', 'An item of a content type is listed under that type and has its fields.')) ?></p>
            </div>
            <?php endif; ?>
            <div class="sb-field">
                <label class="sb-field__label" for="sb-route"><?= e(__('studio_route_mode', 'Route Mode')) ?></label>
                <select id="sb-route" name="route_mode">
                    <option value="standalone"<?= $formValues['route_mode'] === 'standalone' ? ' selected' : '' ?>><?= e(__('studio_route_standalone', 'Dedicated URL Path')) ?></option>
                    <option value="homepage"<?= $formValues['route_mode'] === 'homepage' ? ' selected' : '' ?>><?= e(__('studio_route_homepage', 'Site Homepage (/)')) ?></option>
                </select>
            </div>
            <?php if ($templates !== []): ?>
            <div class="sb-field">
                <label class="sb-field__label" for="sb-template"><?= e(__('studio_template', 'Start from template')) ?></label>
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
            <a href="<?= e($listHref) ?>" class="btn btn-ghost"><?= e(__('cancel', 'Cancel')) ?></a>
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
