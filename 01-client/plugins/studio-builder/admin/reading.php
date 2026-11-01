<?php
/**
 * Kohevo Studio — Reading: what the home page shows, which page lists the posts, how many items archives and the feed
 * hold, and whether search engines are asked to stay away.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\ReadingSettings;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();
Auth::requirePerm('studio-builder.view');

// These change the public site at once.
$canEdit = Auth::can('studio-builder.publish') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_reading', 'Reading');
$currentNav = 'studio-reading';

$reading = ReadingSettings::read($tenantId);
$types = PageMeta::postTypes($tenantId);
$typed = PageMeta::typesByPage($tenantId);
$pages = [];
foreach (Database::rows("SELECT id, title, slug, route_mode FROM studiobuilder_pages WHERE tenant_id = ? AND status = 'published' AND page_type IN ('page', 'landing') AND published_revision_id IS NOT NULL AND slug NOT LIKE 'template-%' ORDER BY title", [$tenantId]) as $row) {
    if (!isset($typed[(int) $row['id']])) {
        $pages[(int) $row['id']] = $row;
    }
}
$homeId = 0;
foreach ($pages as $id => $row) {
    if ($row['route_mode'] === 'homepage') {
        $homeId = $id;
    }
}
$robots = (string) (Database::setting('studio_seo_robots', $tenantId) ?: 'index,follow');
$hidden = str_starts_with($robots, 'noindex');

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('studio_tf_live_denied', 'These settings change the public site at once. Ask someone who can publish pages.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } else {
        $home = (string) ($_POST['home'] ?? '') === 'posts' ? 'posts' : 'page';
        $postsType = (string) ($_POST['posts_type'] ?? '');
        $postsType = isset($types[$postsType]) ? $postsType : '';
        $postsPage = (int) ($_POST['posts_page'] ?? 0);
        $postsPage = isset($pages[$postsPage]) ? $postsPage : 0;
        $newHome = (int) ($_POST['home_page'] ?? 0);
        $archiveItems = (int) ($_POST['archive_items'] ?? 0);
        $feedItems = (int) ($_POST['feed_items'] ?? 10);

        if ($home === 'posts' && $postsType === '') {
            $flash = ['type' => 'error', 'msg' => __('studio_reading_need_type', 'Choose which content type holds your posts.')];
        } elseif ($home === 'page' && !isset($pages[$newHome])) {
            $flash = ['type' => 'error', 'msg' => __('studio_reading_need_home', 'Choose the page to use as your homepage.')];
        } elseif ($postsPage > 0 && $postsPage === $newHome && $home === 'page') {
            $flash = ['type' => 'error', 'msg' => __('studio_reading_same_page', 'The homepage and the posts page must be different pages.')];
        } elseif ($archiveItems < 0 || $archiveItems > ReadingSettings::MAX_ARCHIVE_ITEMS || $feedItems < 1 || $feedItems > ReadingSettings::MAX_FEED_ITEMS) {
            $flash = ['type' => 'error', 'msg' => sprintf(__('studio_reading_bad_number', 'Enter a number up to %d.'), ReadingSettings::MAX_FEED_ITEMS)];
        } else {
            if ($home === 'page' && $newHome !== $homeId) {
                StudioRuntimeFactory::build()->app->updatePageAddress(StudioActor::fromCurrentSession(), $newHome, ['route_mode' => 'homepage']);
            }
            Database::setSetting(ReadingSettings::SHOW_ON_HOME, $home, $tenantId);
            Database::setSetting(ReadingSettings::POSTS_TYPE, $postsType, $tenantId);
            Database::setSetting(ReadingSettings::POSTS_PAGE, (string) $postsPage, $tenantId);
            Database::setSetting(ReadingSettings::ARCHIVE_ITEMS, (string) $archiveItems, $tenantId);
            Database::setSetting(ReadingSettings::FEED_ITEMS, (string) $feedItems, $tenantId);
            Database::setSetting(ReadingSettings::FEED_CONTENT, (string) ($_POST['feed_content'] ?? '') === 'excerpt' ? 'excerpt' : 'full', $tenantId);
            $discourage = !empty($_POST['discourage']);
            if ($discourage !== $hidden) {
                Database::setSetting('studio_seo_robots', $discourage ? 'noindex,nofollow' : 'index,follow', $tenantId);
            }
            AuditLog::record('settings.updated', 'studio-reading');
            sb_flash_redirect('reading.php', ['type' => 'success', 'msg' => __('studio_reading_saved', 'Reading settings saved.')]);
        }
    }
}
$flash = $flash ?? sb_take_flash();

$dis = $canEdit ? '' : ' disabled';
$noArchive = $reading['posts_type'] !== '' && !StudioRuntimeFactory::build()->publicRuntime->hasTypeArchive($reading['posts_type']);
$options = static function (array $list, int|string $selected, string $none): string {
    $html = $none !== '' ? '<option value="">' . e($none) . '</option>' : '';
    foreach ($list as $value => $label) {
        $html .= '<option value="' . e((string) $value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') . '>' . e((string) $label) . '</option>';
    }
    return $html;
};
$pageLabels = array_map(static fn(array $p): string => (string) $p['title'], $pages);
$typeLabels = array_map(static fn(array $t): string => (string) $t['plural'], $types);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_reading_heading', 'Reading')) ?></h2>
            <p><?= e(__('studio_reading_intro', 'Choose what visitors see first, and how your posts are listed and shared.')) ?></p>
        </div>
    </div>

    <form method="post" class="sb-permalinks" data-testid="reading-form">
        <?= csrf_field() ?>

        <section class="sb-card sb-form-card">
            <div class="sb-card__head">
                <div>
                    <div class="sb-card__title"><?= e(__('studio_reading_home', 'Your homepage')) ?></div>
                    <p class="sb-card__sub"><?= e(__('studio_reading_home_sub', 'What opens at the main address of your site.')) ?></p>
                </div>
            </div>
            <div class="sb-url-choices" role="radiogroup" aria-label="<?= e(__('studio_reading_home', 'Your homepage')) ?>">
                <label class="sb-url-choice">
                    <input type="radio" name="home" value="page" <?= $reading['home'] === 'page' ? 'checked' : '' ?><?= $dis ?>>
                    <span><strong><?= e(__('studio_reading_static', 'A page')) ?></strong><small><?= e(__('studio_reading_static_hint', 'A page you designed in the builder.')) ?></small></span>
                </label>
                <label class="sb-url-choice">
                    <input type="radio" name="home" value="posts" <?= $reading['home'] === 'posts' ? 'checked' : '' ?><?= $dis ?>>
                    <span><strong><?= e(__('studio_reading_latest', 'Your latest posts')) ?></strong><small><?= e(__('studio_reading_latest_hint', 'A list of posts, shown by your Archive template.')) ?></small></span>
                </label>
            </div>
            <div class="sb-form-grid">
                <div class="sb-field" data-home-only="page">
                    <label class="sb-field__label" for="sb-home-page"><?= e(__('studio_reading_home_page', 'Homepage')) ?></label>
                    <select id="sb-home-page" name="home_page"<?= $dis ?>><?= $options($pageLabels, $homeId, __('studio_reading_select', 'Select…')) ?></select>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-posts-type"><?= e(__('studio_reading_posts_type', 'Your posts are')) ?></label>
                    <select id="sb-posts-type" name="posts_type"<?= $dis ?>><?= $options($typeLabels, $reading['posts_type'], __('studio_reading_select', 'Select…')) ?></select>
                </div>
                <div class="sb-field" data-home-only="page">
                    <label class="sb-field__label" for="sb-posts-page"><?= e(__('studio_reading_posts_page', 'Posts page')) ?></label>
                    <select id="sb-posts-page" name="posts_page"<?= $dis ?>><?= $options($pageLabels, $reading['posts_page'], __('studio_reading_none', 'None')) ?></select>
                    <p class="sb-field__hint"><?= e(__('studio_reading_posts_page_hint', 'This page lists your posts through the Archive template, in place of its own content.')) ?></p>
                </div>
            </div>
            <?php if ($noArchive): ?>
                <p class="sb-url-warn" role="status"><?= e(__('studio_reading_no_archive', 'Lists of posts need a published Archive template. Until there is one, your homepage and posts page show as normal pages.')) ?> <a href="templates.php"><?= e(__('studio_nav_templates', 'Templates')) ?></a></p>
            <?php endif; ?>
        </section>

        <section class="sb-card sb-form-card">
            <div class="sb-card__head">
                <div>
                    <div class="sb-card__title"><?= e(__('studio_reading_lists', 'Lists and feed')) ?></div>
                    <p class="sb-card__sub"><?= e(sprintf(__('studio_reading_lists_sub', 'Your feed is at %s'), '/feed/')) ?></p>
                </div>
            </div>
            <div class="sb-form-grid">
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-archive-items"><?= e(__('studio_reading_archive_items', 'Items on archive pages')) ?></label>
                    <input type="number" id="sb-archive-items" name="archive_items" min="0" max="<?= ReadingSettings::MAX_ARCHIVE_ITEMS ?>" value="<?= (int) $reading['archive_items'] ?>"<?= $dis ?>>
                    <p class="sb-field__hint"><?= e(__('studio_reading_archive_items_hint', '0 keeps the number set on each Query Loop.')) ?></p>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-feed-items"><?= e(__('studio_reading_feed_items', 'Items in the feed')) ?></label>
                    <input type="number" id="sb-feed-items" name="feed_items" min="1" max="<?= ReadingSettings::MAX_FEED_ITEMS ?>" value="<?= (int) $reading['feed_items'] ?>"<?= $dis ?>>
                </div>
            </div>
            <div class="sb-url-choices" role="radiogroup" aria-label="<?= e(__('studio_reading_feed_content', 'Each feed item includes')) ?>">
                <label class="sb-url-choice">
                    <input type="radio" name="feed_content" value="full" <?= $reading['feed_content'] === 'full' ? 'checked' : '' ?><?= $dis ?>>
                    <span><strong><?= e(__('studio_reading_feed_full', 'Full text')) ?></strong><small><?= e(__('studio_reading_feed_full_hint', 'The item’s rich text fields, with the excerpt.')) ?></small></span>
                </label>
                <label class="sb-url-choice">
                    <input type="radio" name="feed_content" value="excerpt" <?= $reading['feed_content'] === 'excerpt' ? 'checked' : '' ?><?= $dis ?>>
                    <span><strong><?= e(__('studio_reading_feed_excerpt', 'Excerpt')) ?></strong><small><?= e(__('studio_reading_feed_excerpt_hint', 'A short summary and a link.')) ?></small></span>
                </label>
            </div>
        </section>

        <section class="sb-card sb-form-card">
            <div class="sb-card__head">
                <div>
                    <div class="sb-card__title"><?= e(__('studio_reading_search', 'Search engine visibility')) ?></div>
                    <p class="sb-card__sub"><?= e(__('studio_reading_search_sub', 'It is up to search engines to honour this request.')) ?></p>
                </div>
            </div>
            <label class="sb-toggle-row">
                <input type="checkbox" name="discourage" value="1" <?= $hidden ? 'checked' : '' ?><?= $dis ?>>
                <span><strong><?= e(__('studio_reading_discourage', 'Discourage search engines from indexing this site')) ?></strong></span>
            </label>
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
    var form = document.querySelector('[data-testid="reading-form"]');
    function sync() {
        var home = form.querySelector('[name="home"]:checked').value;
        form.querySelectorAll('[data-home-only]').forEach(function (el) { el.hidden = el.dataset.homeOnly !== home; });
    }
    form.addEventListener('change', sync);
    sync();
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
