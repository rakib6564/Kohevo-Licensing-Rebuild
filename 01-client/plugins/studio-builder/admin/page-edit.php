<?php
/**
 * Kohevo Studio — One page's settings.
 *
 *   page-edit.php?id=<page id>
 *
 * Rename a page, change its address or route mode, duplicate it, or delete it. The content itself is edited in the
 * visual canvas builder, which is one click away; the list is pages.php and a new page is page-new.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Admin\PageMetaForm;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;

Auth::require();

$studioApp   = StudioRuntimeFactory::build()->app;
$studioActor = StudioActor::fromCurrentSession();
$currentNav  = 'studio-pages';
$flash       = null;
$pageId      = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$self        = 'page-edit.php?id=' . $pageId;
$builderUrl  = plugin_url('studio-builder', 'admin/builder.php');
$previewUrl  = plugin_url('studio-builder', 'admin/preview.php');

$errorFor = static function (StudioException $e, string $fallback): string {
    if ($e instanceof StudioValidationException) {
        $first = $e->errors()[0]['message'] ?? '';
        return $fallback . ($first !== '' ? ' ' . $first : '');
    }
    if ($e->httpStatus() === 403 && ($e->details()['required_permission'] ?? '') === StudioPermissions::PUBLISH) {
        return __('studio_page_live_denied', 'This page is published, so this change would go live at once. Ask someone who can publish pages.');
    }
    return $e->httpStatus() === 403 ? __('studio_page_edit_denied', 'You cannot change this page.') : $fallback;
};

$load = static function () use ($studioApp, $studioActor, $pageId): array {
    if ($pageId <= 0) {
        throw new StudioNotFoundException('Studio page id missing.');
    }
    $page = $studioApp->pageStatus($studioActor, $pageId);
    if (($page['status'] ?? '') === 'archived') {
        throw new StudioNotFoundException('Studio page is deleted.', ['page_id' => $pageId]);
    }
    return $page;
};

$page = null;
$loadError = null;
try {
    $page = $load();
} catch (StudioException $e) {
    $loadError = $e->httpStatus() === 403
        ? __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')
        : __('studio_page_not_found', 'This page does not exist, or has been deleted.');
}
$canEdit = $page !== null && $studioActor->can(StudioPermissions::EDIT);
$tenantId = current_tenant_id();
$hasMeta = $page !== null && in_array((string) $page['page_type'], PageMeta::PAGE_TYPES, true);
$metaErrors = [];
$metaPosted = null;
// Read before any POST: a deleted item's type goes with it, and "Back" still returns to its list.
$itemTypeKey = $page !== null ? PageMeta::read($tenantId, $pageId)['type'] : '';

if ($page !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('studio_page_edit_denied', 'You cannot change this page.')];
    } elseif ($action === 'save') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug  = trim((string) ($_POST['slug'] ?? ''));
        $route = trim((string) ($_POST['route_mode'] ?? ''));
        $changes = [];
        if ($title !== (string) $page['title']) {
            $changes['title'] = $title;
        }
        if ($slug !== (string) $page['slug']) {
            $changes['slug'] = $slug;
        }
        if ($route !== '' && $route !== (string) $page['route_mode']) {
            $changes['route_mode'] = $route;
        }
        try {
            if ($changes !== []) {
                $studioApp->updatePageAddress($studioActor, $pageId, $changes);
            }
            header('Location: ' . $self . '&saved=1', true, 303);
            exit;
        } catch (StudioException $e) {
            $flash = ['type' => 'error', 'msg' => $errorFor($e, __('studio_page_save_failed', 'The page could not be saved.'))];
            $page['title'] = $title !== '' ? $title : $page['title'];
            $page['slug'] = $slug !== '' ? $slug : $page['slug'];
            $page['route_mode'] = $route !== '' ? $route : $page['route_mode'];
        }
    } elseif ($action === 'save_meta' && $hasMeta && !$studioApp->canChangeLive($studioActor, $page)) {
        // Custom field values are read by the live page on every request: on a published page they are a live change.
        $flash = ['type' => 'error', 'msg' => __('studio_page_live_denied', 'This page is published, so this change would go live at once. Ask someone who can publish pages.')];
    } elseif ($action === 'save_meta' && $hasMeta) {
        $postedType = trim((string) ($_POST['post_type'] ?? ''));
        $posted = is_array($_POST['meta'] ?? null) ? $_POST['meta'] : [];
        $saved = PageMeta::save($tenantId, $pageId, $postedType, $posted);
        if ($saved['errors'] === []) {
            header('Location: ' . $self . '&meta_saved=1', true, 303);
            exit;
        }
        $metaErrors = $saved['errors'];
        $metaPosted = ['type' => $postedType, 'values' => $posted];
        $flash = ['type' => 'error', 'msg' => __('studio_page_meta_invalid', 'Some custom fields need attention.')];
    } elseif ($action === 'duplicate') {
        $title = trim((string) ($_POST['copy_title'] ?? ''));
        $slug  = trim((string) ($_POST['copy_slug'] ?? ''));
        if ($slug === '') {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
        }
        try {
            $copy = $studioApp->duplicatePage($studioActor, $pageId, $title, $slug);
            PageMeta::copy($tenantId, $pageId, (int) $copy['page']['id']);
            header('Location: page-edit.php?id=' . (int) $copy['page']['id'] . '&duplicated=1', true, 303);
            exit;
        } catch (StudioException $e) {
            $flash = ['type' => 'error', 'msg' => $errorFor($e, __('studio_page_duplicate_failed', 'The page could not be duplicated.'))];
        }
    } elseif ($action === 'delete') {
        try {
            $studioApp->archivePage($studioActor, $pageId);
            PageMeta::forget($tenantId, $pageId);
            header('Location: pages.php?' . http_build_query(array_filter(['type' => $itemTypeKey]) + ['deleted' => 1, 'failed' => 0]), true, 303);
            exit;
        } catch (StudioException $e) {
            $flash = ['type' => 'error', 'msg' => $errorFor($e, __('studio_page_delete_failed', 'The page could not be deleted. A shared component that other pages still use cannot be deleted.'))];
        }
    }
}

$flash = $flash ?? sb_take_flash();
if ($flash === null && isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'msg' => __('studio_page_saved', 'Page saved.')];
} elseif ($flash === null && isset($_GET['meta_saved'])) {
    $flash = ['type' => 'success', 'msg' => __('studio_page_meta_saved', 'Custom fields saved.')];
} elseif ($flash === null && isset($_GET['duplicated'])) {
    $flash = ['type' => 'success', 'msg' => __('studio_page_duplicated', 'Page duplicated. This is the copy.')];
}

$pageTitle = $page !== null ? (string) $page['title'] : __('studio_pages', 'Studio Pages');
$published = $page !== null && !empty($page['is_published']);
// An item of a content type belongs to that type's list: the sidebar, the crumbs and "Back" go there.
$itemTypeKey = $page !== null ? PageMeta::read($tenantId, $pageId)['type'] : $itemTypeKey;
$itemType    = PageMeta::postTypes($tenantId)[$itemTypeKey] ?? null;
$listHref    = $itemType !== null ? 'pages.php?type=' . rawurlencode($itemTypeKey) : 'pages.php';
$listName    = $itemType !== null ? (string) ($itemType['plural'] ?? $itemType['singular']) : __('studio_pages', 'Studio Pages');
if ($itemType !== null) {
    $currentNav = 'studio-type-' . $itemTypeKey;
}
$typeLabels = ['page' => __('studio_type_page', 'Standard Page'), 'landing' => __('studio_type_landing', 'Landing Page'), 'header_partial' => __('studio_type_header', 'Site Header Partial'), 'footer_partial' => __('studio_type_footer', 'Site Footer Partial')];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin-wrapper">
    <?php sb_render_admin_nav($itemType !== null ? 'type-' . $itemTypeKey : 'pages'); ?>
    <?php sb_render_crumbs([[$listName, $listHref], [$page !== null ? (string) $page['title'] : __('studio_page_settings', 'Settings'), null]]); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($loadError !== null): ?>
        <div class="alert alert-error" role="status"><?= e($loadError) ?></div>
        <p><a class="btn btn-outline" href="pages.php"><?= e(__('studio_back_to_pages', 'Back to pages')) ?></a></p>
    <?php else: ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e((string) $page['title']) ?></h2>
            <p><?= e(__('studio_page_edit_sub', 'Name, address and route. The content is edited in the visual builder.')) ?></p>
        </div>
        <div class="sb-screen-actions">
            <?php if ($canEdit): ?>
                <a class="btn btn-primary" href="<?= e($builderUrl . '?page=' . $pageId) ?>"><?= sb_svg('zap', 14) ?> <span><?= e(__('studio_open_builder', 'Open builder')) ?></span></a>
            <?php endif; ?>
            <a class="btn btn-outline" target="_blank" rel="noopener" href="<?= e($previewUrl . '?page=' . $pageId) ?>"><?= sb_svg('eye', 14) ?> <span><?= e(__('studio_preview', 'Preview')) ?></span></a>
        </div>
    </div>

    <div class="sb-meta-strip" data-testid="page-meta">
        <div><?= e(__('status', 'Status')) ?><strong><?= e($published ? __('studio_status_published', 'Published') : __('studio_status_draft', 'Draft')) ?><?= $published && !empty($page['has_unpublished_changes']) ? ' · ' . e(__('studio_unpublished_changes', 'unpublished changes')) : '' ?></strong></div>
        <div><?= e(__('studio_page_type', 'Type')) ?><strong><?= e($typeLabels[(string) $page['page_type']] ?? (string) $page['page_type']) ?></strong></div>
        <div><?= e(__('studio_address', 'Address')) ?><strong><code><?= e((string) $page['public_path']) ?></code></strong></div>
        <?php if (!empty($page['updated_at'])): ?><div><?= e(__('updated', 'Updated')) ?><strong><?= e((string) $page['updated_at']) ?></strong></div><?php endif; ?>
    </div>

    <form method="post" class="card sb-form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $pageId ?>">
        <input type="hidden" name="action" value="save">
        <div class="card-header"><div><h2><?= e(__('studio_page_settings', 'Settings')) ?></h2></div></div>
        <div class="sb-form-grid">
            <div class="sb-field">
                <label class="sb-field__label" for="sb-title"><?= e(__('title', 'Title')) ?> <span class="field-required" style="color:#ef4444;">*</span></label>
                <input type="text" id="sb-title" name="title" required maxlength="255" value="<?= e((string) $page['title']) ?>"<?= $canEdit ? '' : ' disabled' ?>>
            </div>
            <div class="sb-field">
                <label class="sb-field__label" for="sb-slug"><?= e(__('studio_slug', 'Address (slug)')) ?></label>
                <input type="text" id="sb-slug" name="slug" required maxlength="191" pattern="[a-z0-9]([a-z0-9\-]*[a-z0-9])?" value="<?= e((string) $page['slug']) ?>"<?= $canEdit ? '' : ' disabled' ?>>
                <?php if ($published): ?><p class="sb-field__hint"><?= e(__('studio_slug_published_hint', 'This page is live: changing its address changes its public URL.')) ?></p><?php endif; ?>
            </div>
            <div class="sb-field">
                <label class="sb-field__label" for="sb-route"><?= e(__('studio_route_mode', 'Route Mode')) ?></label>
                <select id="sb-route" name="route_mode"<?= $canEdit ? '' : ' disabled' ?>>
                    <option value="standalone"<?= $page['route_mode'] === 'standalone' ? ' selected' : '' ?>><?= e(__('studio_route_standalone', 'Dedicated URL Path')) ?></option>
                    <option value="homepage"<?= $page['route_mode'] === 'homepage' ? ' selected' : '' ?>><?= e(__('studio_route_homepage', 'Site Homepage (/)')) ?></option>
                    <?php if (!in_array((string) $page['route_mode'], ['standalone', 'homepage'], true)): ?>
                        <?php // A route this screen does not offer (a system override) is kept as it is: saving the form must not turn it into a normal page. ?>
                        <option value="<?= e((string) $page['route_mode']) ?>" selected><?= e((string) $page['route_mode'] === 'system_override' ? __('studio_route_system_override', 'System page override') : (string) $page['route_mode']) ?></option>
                    <?php endif; ?>
                </select>
            </div>
        </div>
        <?php if ($canEdit): ?>
        <div class="sb-form-actions">
            <button type="submit" class="btn btn-primary"><?= sb_svg('save', 14) ?> <span><?= e(__('save', 'Save changes')) ?></span></button>
            <a href="<?= e($listHref) ?>" class="btn btn-ghost"><?= e($itemType !== null ? sprintf(__('studio_back_to_list', 'Back to %s'), mb_strtolower($listName)) : __('studio_back_to_pages', 'Back to pages')) ?></a>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($hasMeta):
        $metaTypes = PageMeta::postTypes($tenantId);
        $metaFieldsByType = [];
        $relationPages = [];
        foreach ($metaTypes as $mk => $_t) {
            $metaFieldsByType[$mk] = PageMeta::fieldsFor($tenantId, (string) $mk);
            foreach ($metaFieldsByType[$mk] as $mf) {
                $target = (string) ($mf['options']['target'] ?? '');
                if ($target !== '' && !isset($relationPages[$target])) {
                    $relationPages[$target] = [];
                    $ids = array_flip(PageMeta::pageIdsOfType($tenantId, $target));
                    try {
                        foreach ($studioApp->listPages($studioActor) as $row) {
                            if (isset($ids[(int) $row['id']]) && (int) $row['id'] !== $pageId) {
                                $relationPages[$target][(int) $row['id']] = (string) $row['title'];
                            }
                        }
                    } catch (StudioException) {
                    }
                }
            }
        }
        $savedMeta = $metaPosted ?? PageMeta::read($tenantId, $pageId);
        $savedType = isset($metaTypes[$savedMeta['type']]) ? $savedMeta['type'] : '';
        echo PageMetaForm::card($self, $pageId, $savedType, $metaTypes, $metaFieldsByType, $savedMeta['values'], $metaErrors, $relationPages, $canEdit, 'types-fields.php');
    endif; ?>

    <?php if ($canEdit): ?>
    <form method="post" class="card sb-form-card">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $pageId ?>">
        <input type="hidden" name="action" value="duplicate">
        <div class="card-header"><div><h2><?= e(__('studio_duplicate_page', 'Duplicate')) ?></h2><div class="card-sub"><?= e(__('studio_duplicate_sub', 'Make a draft copy of this page under a new title and address.')) ?></div></div></div>
        <div class="sb-form-grid">
            <div class="sb-field">
                <label class="sb-field__label" for="sb-copy-title"><?= e(__('title', 'Title')) ?></label>
                <input type="text" id="sb-copy-title" name="copy_title" required maxlength="255" value="<?= e(sprintf(__('studio_copy_of', '%s copy'), (string) $page['title'])) ?>">
            </div>
            <div class="sb-field">
                <label class="sb-field__label" for="sb-copy-slug"><?= e(__('studio_slug', 'Address (slug)')) ?></label>
                <input type="text" id="sb-copy-slug" name="copy_slug" maxlength="191" pattern="[a-z0-9]([a-z0-9\-]*[a-z0-9])?" placeholder="<?= e((string) $page['slug']) ?>-copy">
                <p class="sb-field__hint"><?= e(__('studio_slug_hint', 'Lowercase letters, numbers and hyphens. Leave blank to generate from title.')) ?></p>
            </div>
        </div>
        <div class="sb-form-actions">
            <button type="submit" class="btn btn-outline"><?= sb_svg('copy', 14) ?> <span><?= e(__('studio_duplicate_page', 'Duplicate')) ?></span></button>
        </div>
    </form>

    <form method="post" class="card sb-form-card sb-danger-card" onsubmit="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?= e(__('studio_delete_page_confirm', 'Delete this page? It leaves your site and the list. Its history is kept.')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $pageId ?>">
        <input type="hidden" name="action" value="delete">
        <div class="card-header"><div><h3><?= e(__('studio_delete_page', 'Delete page')) ?></h3><div class="card-sub"><?= e(__('studio_delete_page_sub', 'The page leaves your site and the list. Its history is kept.')) ?></div></div></div>
        <div class="sb-form-actions" style="border-top:0; margin-top:0; padding-top:0;">
            <button type="submit" class="btn btn-danger"><?= sb_svg('trash', 14) ?> <span><?= e(__('studio_delete_page', 'Delete page')) ?></span></button>
        </div>
    </form>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
