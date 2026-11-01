<?php
/**
 * Kohevo Studio — One page's settings.
 *
 *   page-edit.php?id=<page id>
 *
 * Rename a page, change its address or route mode, duplicate it, or delete it. The content itself is edited in the
 * visual canvas builder, which is one click away; the list is pages.php and a new page is page-new.php.
 *
 * An item of a content type gets the item screen instead: title, the Settings box with every field, then status,
 * publish date, address, template, featured image and SEO, saved together by Update. Field values, title and address
 * change at once; the rest lives in the document, so it goes live when the item is published.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/_media_picker.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Admin\PageMetaForm;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PostTypeSettings;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
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
$itemType    = PageMeta::postTypes($tenantId)[$itemTypeKey] ?? null;
if ($itemType !== null && !PostTypeSettings::hasAdmin($itemType)) {
    sb_flash_redirect('post-type-edit.php?key=' . rawurlencode($itemTypeKey), ['type' => 'warning', 'msg' => __('studio_type_no_admin', 'Show in admin is off for this post type.')]);
}
$canPublish = $page !== null && $studioActor->can(StudioPermissions::PUBLISH);

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
    } elseif ($action === 'save_item' && $itemType !== null && !$studioApp->canChangeLive($studioActor, $page)) {
        $flash = ['type' => 'error', 'msg' => __('studio_page_live_denied', 'This page is published, so this change would go live at once. Ask someone who can publish pages.')];
    } elseif ($action === 'save_item' && $itemType !== null) {
        $postedType = trim((string) ($_POST['post_type'] ?? $itemTypeKey));
        $posted = is_array($_POST['meta'] ?? null) ? $_POST['meta'] : [];
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug  = trim((string) ($_POST['slug'] ?? ''));
        $wasLive = !empty($page['is_published']);
        $pending = !empty($page['has_unpublished_changes']);
        $wasScheduled = ($page['status'] ?? '') === 'scheduled';
        $current = $wasLive ? 'published' : ($wasScheduled ? 'scheduled' : 'draft');
        $wantStatus = $canPublish ? (string) ($_POST['status'] ?? $current) : $current;
        $wantLive = $wantStatus === 'published';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', trim((string) ($_POST['publish_date'] ?? '')));
        $dbNow = new \DateTimeImmutable(slate_db_now());
        $isFuture = $date !== false && $date > $dbNow;
        $dateError = match (true) {
            $wantStatus === 'scheduled' && !$isFuture => __('studio_item_schedule_needs_date', 'Scheduled needs a publish date in the future.'),
            $wantLive && $isFuture                    => __('studio_item_date_future', 'This date is in the future: choose Scheduled to publish it then.'),
            default                                   => null,
        };
        $saved = $dateError === null ? PageMeta::save($tenantId, $pageId, $postedType, $posted) : ['errors' => []];
        if ($dateError !== null || $saved['errors'] !== []) {
            $metaErrors = $saved['errors'];
            $metaPosted = ['type' => $postedType, 'values' => $posted];
            $flash = ['type' => 'error', 'msg' => $dateError ?? __('studio_page_meta_invalid', 'Some custom fields need attention.')];
        } else {
            try {
                $changes = array_filter(['title' => $title, 'slug' => $slug], static fn(string $v, string $k): bool => $v !== (string) $page[$k], ARRAY_FILTER_USE_BOTH);
                if ($changes !== []) {
                    $studioApp->updatePageAddress($studioActor, $pageId, $changes);
                }
                PageMeta::setTemplate($tenantId, $pageId, preg_match('/^[0-9a-f-]{36}$/i', (string) ($_POST['template'] ?? '')) === 1 ? (string) $_POST['template'] : '');
                $imageId = (int) ($_POST['og_image_media_id'] ?? 0);
                $robots  = (string) ($_POST['seo_robots'] ?? '');
                $docChanged = $studioApp->saveItemDocument($studioActor, $pageId, [
                    'title'             => mb_substr(trim((string) ($_POST['seo_title'] ?? '')), 0, 255),
                    'description'       => mb_substr(trim((string) ($_POST['seo_description'] ?? '')), 0, 1000),
                    'robots'            => in_array($robots, ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'], true) ? $robots : null,
                    'og_image_media_id' => $imageId > 0 ? $imageId : null,
                    'excerpt'           => PostTypeSettings::supports($itemType, 'excerpt') ? mb_substr(trim((string) ($_POST['excerpt'] ?? '')), 0, 500) : null,
                ]);
                // Going live publishes the draft. A live item stays current too, unless the draft holds builder work
                // nobody chose to publish yet: that stays a draft, and the screen says so.
                $held = $docChanged && $wasLive && $wantLive && ($pending || !$canPublish);
                if ($wantStatus === 'scheduled') {
                    $studioApp->schedulePublish($studioActor, $pageId, $date->format('Y-m-d H:i:s'));
                } elseif ($wantLive && (!$wasLive || ($docChanged && !$pending))) {
                    $studioApp->publish($studioActor, $pageId, null);
                } elseif ($wantStatus === 'draft' && ($wasLive || $wasScheduled) && $canPublish) {
                    $studioApp->unpublish($studioActor, $pageId);
                }
                if ($date !== false && $canPublish && $wantLive && $date->format('Y-m-d H:i') !== substr((string) ($page['published_at'] ?? ''), 0, 16)) {
                    $studioApp->setPublishDate($studioActor, $pageId, $date->format('Y-m-d H:i:s'));
                }
                $message = match (true) {
                    $wantStatus === 'scheduled'                       => 'item_scheduled',
                    $wantLive && !$wasLive                            => 'item_published',
                    $wantStatus === 'draft' && ($wasLive || $wasScheduled) && $canPublish => 'item_draft',
                    default                                           => 'item_updated',
                };
                sb_flash_redirect($self, $held
                    ? ['type' => 'warning', 'msg' => __('studio_item_held', 'Saved. The SEO, image and excerpt changes go live when this item\'s draft is published in the builder.')]
                    : ['type' => 'success', 'msg' => PostTypeSettings::label($itemType, $message)]);
            } catch (StudioException $e) {
                $flash = ['type' => 'error', 'msg' => $errorFor($e, __('studio_page_save_failed', 'The page could not be saved.'))];
                $page['title'] = $title !== '' ? $title : $page['title'];
                $page['slug'] = $slug !== '' ? $slug : $page['slug'];
            }
        }
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
$listName    = $itemType !== null ? PostTypeSettings::label($itemType, 'all_items') : __('studio_pages', 'Studio Pages');
if ($itemType !== null) {
    $currentNav = 'studio-type-' . $itemTypeKey;
}
$typeLabels = ['page' => __('studio_type_page', 'Standard Page'), 'landing' => __('studio_type_landing', 'Landing Page'), 'header_partial' => __('studio_type_header', 'Site Header Partial'), 'footer_partial' => __('studio_type_footer', 'Site Footer Partial')];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">
    <?php sb_render_crumbs([[$listName, $listHref], [$page !== null ? (string) $page['title'] : __('studio_page_settings', 'Settings'), null]]); ?>

    <?php sb_render_flash($flash); ?>

    <?php if ($loadError !== null): ?>
        <div class="alert alert-error" role="status"><?= e($loadError) ?></div>
        <p><a class="btn btn-outline" href="pages.php"><?= e(__('studio_back_to_pages', 'Back to pages')) ?></a></p>
    <?php else: ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e($itemType !== null ? PostTypeSettings::label($itemType, 'edit_item') : (string) $page['title']) ?></h2>
            <p><?= e($itemType !== null ? (string) $page['title'] : __('studio_page_edit_sub', 'Name, address and route. The content is edited in the visual builder.')) ?></p>
        </div>
        <div class="sb-screen-actions">
            <?php if ($itemType !== null && !empty($itemType['edit_link'])): ?>
                <a class="btn btn-ghost" href="post-type-edit.php?key=<?= e(rawurlencode($itemTypeKey)) ?>" data-testid="item-type-link"><?= e(__('studio_item_edit_type', 'Edit post type')) ?></a>
            <?php endif; ?>
            <?php if ($canEdit && ($itemType === null || PostTypeSettings::supports($itemType, 'editor'))): ?>
                <a class="btn btn-primary" href="<?= e($builderUrl . '?page=' . $pageId) ?>"><?= sb_svg('zap', 14) ?> <span><?= e(__('studio_open_builder', 'Open builder')) ?></span></a>
            <?php endif; ?>
            <a class="btn btn-outline" target="_blank" rel="noopener" href="<?= e($previewUrl . '?page=' . $pageId) ?>"><?= sb_svg('eye', 14) ?> <span><?= e(__('studio_preview', 'Preview')) ?></span></a>
        </div>
    </div>

    <?php if ($itemType === null): ?>
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
                <label class="sb-field__label" for="sb-title"><?= e(__('title', 'Title')) ?> <span class="field-required">*</span></label>
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

    <?php endif; ?>

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
        if ($itemType === null) {
            echo PageMetaForm::card($self, $pageId, $savedType, $metaTypes, $metaFieldsByType, $savedMeta['values'], $metaErrors, $relationPages, $canEdit, 'types-fields.php');
        } else {
            $doc = $studioApp->loadEditorDocument($studioActor, $pageId)['document'];
            $itemSeo = is_array($doc['seo'] ?? null) ? $doc['seo'] : [];
            $excerpt = (string) ($doc['settings']['excerpt'] ?? '');
            $imageId = (int) ($itemSeo['og_image_media_id'] ?? 0);
            $tenants = new \Slate\Tenancy\TenantContext();
            $imageUrl = $imageId > 0 ? (string) $tenants->runAs($tenantId, static fn() => (new CoreMediaResolver($tenants))->resolveImage($imageId)?->url ?? '') : '';
            $singleTemplates = $studioApp->singleTemplates($studioActor);
            $pinned = PageMeta::read($tenantId, $pageId)['template'];
            $editTypeUrl = 'post-type-edit.php?key=' . rawurlencode($itemTypeKey);
            $dis = $canEdit ? '' : ' disabled';
            ?>
    <form method="post" id="sb-item-form" class="sb-edit-layout" data-testid="page-meta-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $pageId ?>">
        <input type="hidden" name="action" value="save_item">
        <div class="sb-edit-main">
            <section class="sb-card">
                <div class="sb-form-grid">
                    <div class="sb-field sb-field--wide">
                        <label class="sb-field__label" for="sb-title"><?= e(__('title', 'Title')) ?> <span class="field-required">*</span></label>
                        <input type="text" id="sb-title" name="title" required maxlength="255" value="<?= e((string) $page['title']) ?>"<?= $dis ?>>
                    </div>
                </div>
            </section>
            <section class="sb-card" data-testid="item-settings">
                <div class="sb-card__head"><div class="sb-card__title"><?= e(__('studio_page_settings', 'Settings')) ?></div></div>
                <?= PageMetaForm::groups($savedType, $metaTypes, $metaFieldsByType, $savedMeta['values'], $metaErrors, $relationPages, $canEdit, $editTypeUrl . '#sb-sec-fields') ?>
            </section>
            <?php if (PostTypeSettings::supports($itemType, 'excerpt')): ?>
            <section class="sb-card" data-testid="item-excerpt">
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-excerpt"><?= e(__('studio_tf_sup_excerpt', 'Excerpt')) ?></label>
                    <textarea id="sb-excerpt" name="excerpt" rows="3" maxlength="500"<?= $dis ?>><?= e($excerpt) ?></textarea>
                </div>
            </section>
            <?php endif; ?>
            <section class="sb-card" data-testid="item-seo">
                <div class="sb-card__head"><div class="sb-card__title"><?= e(__('studio_item_seo', 'SEO')) ?></div></div>
                <div class="sb-form-grid">
                    <div class="sb-field sb-field--wide">
                        <label class="sb-field__label" for="sb-seo-title"><?= e(__('studio_item_seo_title', 'SEO title')) ?></label>
                        <input type="text" id="sb-seo-title" name="seo_title" maxlength="255" value="<?= e((string) ($itemSeo['title'] ?? '')) ?>"<?= $dis ?>>
                    </div>
                    <div class="sb-field sb-field--wide">
                        <label class="sb-field__label" for="sb-seo-desc"><?= e(__('studio_item_seo_description', 'Meta description')) ?></label>
                        <textarea id="sb-seo-desc" name="seo_description" rows="3" maxlength="1000"<?= $dis ?>><?= e((string) ($itemSeo['description'] ?? '')) ?></textarea>
                    </div>
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-seo-robots"><?= e(__('studio_item_seo_robots', 'Search engines')) ?></label>
                        <select id="sb-seo-robots" name="seo_robots"<?= $dis ?>>
                            <?php foreach (['index,follow' => __('studio_item_robots_index', 'Index this item'), 'noindex,follow' => __('studio_item_robots_noindex', 'Keep out of search results'), 'index,nofollow' => __('studio_item_robots_nofollow', 'Index, do not follow its links'), 'noindex,nofollow' => __('studio_item_robots_none', 'Keep out, do not follow its links')] as $value => $text): ?>
                                <option value="<?= e($value) ?>"<?= ($itemSeo['robots'] ?? 'index,follow') === $value ? ' selected' : '' ?>><?= e($text) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>
        </div>
        <aside class="sb-edit-side">
            <div class="sb-card" data-testid="item-publish">
                <div class="sb-card__head"><div class="sb-card__title"><?= e(__('studio_item_publish', 'Publish')) ?></div></div>
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-status"><?= e(__('status', 'Status')) ?></label>
                    <select id="sb-status" name="status"<?= $canPublish ? '' : ' disabled' ?>>
                        <?php $itemStatus = $published ? 'published' : ((string) ($page['status'] ?? '') === 'scheduled' ? 'scheduled' : 'draft'); ?>
                        <option value="draft"<?= $itemStatus === 'draft' ? ' selected' : '' ?>><?= e(__('studio_status_draft', 'Draft')) ?></option>
                        <option value="scheduled"<?= $itemStatus === 'scheduled' ? ' selected' : '' ?>><?= e(__('studio_status_scheduled', 'Scheduled')) ?></option>
                        <option value="published"<?= $itemStatus === 'published' ? ' selected' : '' ?>><?= e(__('studio_status_published', 'Published')) ?></option>
                    </select>
                    <?php if ($published && !empty($page['has_unpublished_changes'])): ?><p class="sb-field__hint"><?= e(__('studio_unpublished_changes', 'unpublished changes')) ?></p><?php endif; ?>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-publish-date"><?= e(__('studio_item_publish_date', 'Publish date')) ?></label>
                    <input type="datetime-local" id="sb-publish-date" name="publish_date" value="<?= e(str_replace(' ', 'T', substr((string) ($page['scheduled_for'] ?? $page['published_at'] ?? ''), 0, 16))) ?>"<?= $canPublish ? '' : ' disabled' ?>>
                    <p class="sb-field__hint"><?= e(sprintf(__('studio_item_publish_date_hint', 'Shown and sorted by in Query Loops. Scheduled: when it goes live. Site clock now: %s.'), substr(slate_db_now(), 0, 16))) ?></p>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-slug"><?= e(__('studio_slug', 'Address (slug)')) ?></label>
                    <input type="text" id="sb-slug" name="slug" required maxlength="191" pattern="[a-z0-9]([a-z0-9\-]*[a-z0-9])?" value="<?= e((string) $page['slug']) ?>"<?= $dis ?>>
                    <p class="sb-field__hint"><code><?= e((string) $page['public_path']) ?></code></p>
                </div>
                <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary sb-block-btn" data-testid="item-update"><?= sb_svg('save', 14) ?> <span><?= e(__('update', 'Update')) ?></span></button>
                <?php endif; ?>
                <?php if ($published && PostTypeSettings::isQueryable($itemType)): ?>
                <a class="btn btn-ghost sb-block-btn" target="_blank" rel="noopener" href="<?= e(rtrim((string) SLATE_URL, '/') . (string) $page['public_path']) ?>"><?= e(PostTypeSettings::label($itemType, 'view_item')) ?></a>
                <?php endif; ?>
            </div>
            <div class="sb-card" data-testid="item-template">
                <?= PageMetaForm::typeSelect($savedType, $metaTypes, $canEdit) ?>
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-template"><?= e(__('studio_item_template', 'Template')) ?></label>
                    <select id="sb-template" name="template"<?= $dis ?>>
                        <option value=""><?= e(__('studio_item_template_auto', 'Chosen by display conditions')) ?></option>
                        <?php foreach ($singleTemplates as $tpl): ?>
                            <option value="<?= e($tpl['uuid']) ?>"<?= $pinned === $tpl['uuid'] ? ' selected' : '' ?>><?= e($tpl['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if (PostTypeSettings::supports($itemType, 'thumbnail')): ?>
            <div class="sb-card" data-testid="item-image">
                <div class="sb-card__head"><div class="sb-card__title"><?= e(PostTypeSettings::label($itemType, 'featured_image')) ?></div></div>
                <input type="hidden" id="sb-item-image" value="<?= e($imageUrl) ?>">
                <input type="hidden" id="sb-item-image_id" name="og_image_media_id" value="<?= $imageId > 0 ? $imageId : '' ?>">
                <div class="sb-media-preview-box sb-item-image" id="preview_sb-item-image">
                    <?php if ($imageUrl !== ''): ?><img src="<?= e($imageUrl) ?>" alt="" class="sb-media-preview-img"><?php else: ?><div class="sb-media-preview-empty"><?= sb_svg('media', 18) ?></div><?php endif; ?>
                </div>
                <?php if ($canEdit): ?>
                <button type="button" class="btn btn-outline sb-block-btn" onclick="SbMediaPicker.open('sb-item-image');"><?= e(PostTypeSettings::label($itemType, 'set_featured_image')) ?></button>
                <button type="button" class="btn btn-ghost sb-block-btn" id="clear_sb-item-image" onclick="SbMediaPicker.clearField('sb-item-image');document.getElementById('sb-item-image_id').value='';" style="<?= $imageUrl !== '' ? '' : 'display:none;' ?>"><?= e(PostTypeSettings::label($itemType, 'remove_featured_image')) ?></button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </aside>
    </form>
    <?= PageMetaForm::script('sb-item-form', $canEdit) ?>
    <?php sb_render_media_picker_modal(); ?>
    <?php
        }
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
