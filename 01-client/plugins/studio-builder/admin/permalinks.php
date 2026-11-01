<?php
/**
 * Kohevo Studio — Permalinks: how the site's addresses are formed. The address base of each content type, and whether
 * addresses end with a slash.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Meta\PostTypeSettings;

Auth::require();
Auth::requirePerm('studio-builder.view');

// Changing an address changes the public site at once, as on the post type screen.
$canEdit = Auth::can('studio-builder.publish') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_permalinks', 'Permalinks');
$currentNav = 'studio-permalinks';

$types = PostTypeSettings::sorted(PageMeta::postTypes($tenantId));
$flash = null;
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = is_array($_POST['base'] ?? null) ? $_POST['base'] : [];
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('studio_tf_live_denied', 'These settings change the public site at once. Ask someone who can publish pages.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } else {
        $errors = [];
        $next = $types;
        $taken = [];
        foreach ($types as $key => $type) {
            if (empty($type['rewrite'])) {
                continue;
            }
            $prefix = PageRoutes::normalizePrefix((string) ($posted[$key] ?? $type['rewrite_slug']));
            if ($prefix === null || $prefix === '') {
                $errors[] = sprintf(__('studio_permalinks_bad_base', '%s: an address base is one to three lowercase words separated by /, like /events.'), (string) $type['plural']);
                continue;
            }
            if (isset($taken[$prefix])) {
                $errors[] = sprintf(__('studio_permalinks_taken', 'Two content types cannot share /%s.'), $prefix);
            }
            $taken[$prefix] = true;
            $next[$key]['route'] = '/' . $prefix;
            $next[$key]['rewrite_slug'] = $prefix;
        }
        if ($errors !== []) {
            $flash = ['type' => 'error', 'msg' => implode(' ', array_unique($errors))];
        } else {
            PageMeta::savePostTypes($tenantId, $next);
            Database::setSetting(PageRoutes::SETTING_TRAILING_SLASH, !empty($_POST['trailing_slash']) ? '1' : '0', $tenantId);
            PageRoutes::forget($tenantId);
            foreach ($next as $key => $type) {
                if (($types[$key]['route'] ?? '') !== $type['route']) {
                    AuditLog::record('studio.post_type_route_changed', (string) $tenantId, ['key' => $key, 'route' => $type['route']]);
                }
            }
            sb_flash_redirect('permalinks.php', ['type' => 'success', 'msg' => __('studio_permalinks_saved', 'Permalinks saved.')]);
        }
    }
}
$flash = $flash ?? sb_take_flash();

$slash = isset($_POST['trailing_slash']) ? !empty($_POST['trailing_slash']) : (string) Database::setting(PageRoutes::SETTING_TRAILING_SLASH, $tenantId) === '1';
$dis = $canEdit ? '' : ' disabled';
$site = rtrim((string) preg_replace('~^https?://~', '', (string) SLATE_URL), '/') . '/';
$end = $slash ? '/' : '';
$sample = __('studio_permalinks_sample', 'item-slug');
$counts = array_count_values(array_map('strval', PageMeta::typesByPage($tenantId)));

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_permalinks_heading', 'Permalinks')) ?></h2>
            <p><?= e(__('studio_permalinks_intro', 'Choose how the addresses of your pages and content look.')) ?></p>
        </div>
    </div>

    <form method="post" class="sb-permalinks" data-testid="permalinks-form">
        <?= csrf_field() ?>

        <section class="sb-card sb-form-card">
            <div class="sb-card__head">
                <div>
                    <div class="sb-card__title"><?= e(__('studio_permalinks_bases', 'Content addresses')) ?></div>
                    <p class="sb-card__sub"><?= e(__('studio_permalinks_bases_sub', 'Each content type keeps its items under one base.')) ?></p>
                </div>
            </div>

            <div class="sb-url-list">
                <div class="sb-url-row">
                    <div class="sb-url-row__name"><strong><?= e(__('studio_permalinks_pages', 'Pages')) ?></strong></div>
                    <div class="sb-url-preview"><span class="sb-url-preview__site"><?= e($site) ?></span><?= e(__('studio_permalinks_page_sample', 'page-slug')) ?><span data-slash><?= e($end) ?></span></div>
                </div>
                <?php foreach ($types as $key => $type): ?>
                    <?php
                    $key = (string) $key;
                    $count = (int) ($counts[$key] ?? 0);
                    $saved = trim((string) $type['rewrite_slug'], '/');
                    ?>
                    <div class="sb-url-row">
                        <div class="sb-url-row__name">
                            <label for="sb-base-<?= e($key) ?>"><strong><?= e((string) $type['plural']) ?></strong></label>
                            <a href="pages.php?type=<?= e(rawurlencode($key)) ?>"><?= e(sprintf($count === 1 ? __('studio_permalinks_one_item', '%d item') : __('studio_permalinks_items', '%d items'), $count)) ?></a>
                        </div>
                        <?php if (!empty($type['rewrite'])): ?>
                            <div>
                                <div class="sb-url-input">
                                    <span class="sb-url-input__site"><?= e($site) ?></span>
                                    <input type="text" id="sb-base-<?= e($key) ?>" name="base[<?= e($key) ?>]" maxlength="120" spellcheck="false" autocomplete="off" value="<?= e(trim((string) ($posted[$key] ?? $saved), '/')) ?>" data-saved="<?= e($saved) ?>" aria-describedby="sb-base-<?= e($key) ?>-warn"<?= $dis ?>>
                                    <span class="sb-url-input__end">/<?= e($sample) ?><span data-slash><?= e($end) ?></span></span>
                                </div>
                                <p class="sb-url-warn" id="sb-base-<?= e($key) ?>-warn" role="status" hidden>
                                    <?= e($count > 0 ? sprintf($count === 1 ? __('studio_permalinks_warn_one', 'This item stops opening at its old address.') : __('studio_permalinks_warn', 'These %d items stop opening at their old addresses.'), $count) : __('studio_permalinks_warn_none', 'This changes the base for new items.')) ?>
                                    <?php if ($count > 0): ?><a href="redirects.php"><?= e(__('studio_permalinks_warn_link', 'Set up redirects')) ?></a><?php endif; ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="sb-url-preview"><span class="sb-url-preview__site"><?= e($site) ?></span><?= e($sample) ?><span data-slash><?= e($end) ?></span> <a href="post-type-edit.php?key=<?= e(rawurlencode($key)) ?>"><?= e(__('studio_permalinks_edit_type', 'Add a base')) ?></a></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($types === []): ?>
                <p class="sb-card__note"><?= e(__('studio_permalinks_no_types', 'Content types you add appear here.')) ?> <a href="types-fields.php"><?= e(__('studio_nav_content_types', 'Content types')) ?></a></p>
            <?php endif; ?>
        </section>

        <section class="sb-card sb-form-card">
            <div class="sb-card__head">
                <div>
                    <div class="sb-card__title"><?= e(__('studio_permalinks_format', 'Address ending')) ?></div>
                    <p class="sb-card__sub"><?= e(__('studio_permalinks_format_sub', 'Both forms open the page. This sets the one used in links, canonical tags and the sitemap.')) ?></p>
                </div>
            </div>
            <div class="sb-url-choices" role="radiogroup" aria-label="<?= e(__('studio_permalinks_format', 'Address ending')) ?>">
                <label class="sb-url-choice">
                    <input type="radio" name="trailing_slash" value="1" <?= $slash ? 'checked' : '' ?><?= $dis ?>>
                    <span><strong><?= e(__('studio_permalinks_slash', 'With a slash')) ?></strong><code>/contact/</code><small><?= e(__('studio_permalinks_slash_hint', 'For a site moved from WordPress, so search engines keep the addresses they know.')) ?></small></span>
                </label>
                <label class="sb-url-choice">
                    <input type="radio" name="trailing_slash" value="0" <?= $slash ? '' : 'checked' ?><?= $dis ?>>
                    <span><strong><?= e(__('studio_permalinks_no_slash', 'Without a slash')) ?></strong><code>/contact</code><small><?= e(__('studio_permalinks_no_slash_hint', 'Shorter addresses, for a new site.')) ?></small></span>
                </label>
            </div>
        </section>

        <?php if ($canEdit): ?>
            <div class="sb-url-save">
                <button type="submit" class="btn btn-primary"><?= e(__('save_changes', 'Save changes')) ?></button>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
(function () {
    var form = document.querySelector('[data-testid="permalinks-form"]');
    form.addEventListener('input', function (event) {
        var target = event.target;
        if (target.name === 'trailing_slash') {
            form.querySelectorAll('[data-slash]').forEach(function (el) { el.textContent = target.value === '1' ? '/' : ''; });
        } else if (target.hasAttribute('data-saved')) {
            document.getElementById(target.id + '-warn').hidden = target.value.replace(/^\/+|\/+$/g, '') === target.dataset.saved;
        }
    });
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
