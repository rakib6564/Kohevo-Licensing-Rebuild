<?php
/**
 * Kohevo Studio — Site & SEO Controls.
 *
 * Dedicated admin page for global SEO settings, OpenGraph social card simulator,
 * Google SERP preview, search engine verification, sitemap.xml, and robots.txt.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_seo', 'Site & SEO');
$currentNav = 'studio-seo';

$flash = null;

// Read existing settings
$seoTitleFormat = (string) (Database::setting('studio_seo_title_format', $tenantId) ?: '%title% | %site_name%');
$seoDescription = (string) (Database::setting('studio_seo_description', $tenantId) ?: '');
$seoOgMediaId   = (string) (Database::setting('studio_seo_og_media_id', $tenantId) ?: '');
$seoOgImageUrl  = (string) (Database::setting('studio_seo_og_image_url', $tenantId) ?: '');
$seoRobots      = (string) (Database::setting('studio_seo_robots', $tenantId) ?: 'index,follow');
$seoGoogleVerification = (string) (Database::setting('studio_seo_google_verify', $tenantId) ?: '');
$seoBingVerification   = (string) (Database::setting('studio_seo_bing_verify', $tenantId) ?: '');
// The name the live pages use for %site_name%.
try {
    $siteName = \Slate\Services\Content\TenantBranding::siteName();
} catch (\Throwable $e) {
    $siteName = '';
}

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to edit SEO settings.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } else {
        $seoTitleFormat = trim((string) ($_POST['title_format'] ?? ''));
        $seoDescription = trim((string) ($_POST['description'] ?? ''));
        $seoOgMediaId   = trim((string) ($_POST['og_media_id'] ?? ''));
        $seoOgImageUrl  = trim((string) ($_POST['og_image_url'] ?? ''));
        $seoRobots      = trim((string) ($_POST['robots'] ?? 'index,follow'));
        $seoGoogleVerification = trim((string) ($_POST['google_verify'] ?? ''));
        $seoBingVerification   = trim((string) ($_POST['bing_verify'] ?? ''));

        Database::setSetting('studio_seo_title_format', $seoTitleFormat, $tenantId);
        Database::setSetting('studio_seo_description', $seoDescription, $tenantId);
        Database::setSetting('studio_seo_og_media_id', $seoOgMediaId, $tenantId);
        Database::setSetting('studio_seo_og_image_url', $seoOgImageUrl, $tenantId);
        Database::setSetting('studio_seo_robots', $seoRobots, $tenantId);
        Database::setSetting('studio_seo_google_verify', $seoGoogleVerification, $tenantId);
        Database::setSetting('studio_seo_bing_verify', $seoBingVerification, $tenantId);

        sb_flash_redirect('seo.php', ['type' => 'success', 'msg' => __('studio_seo_saved', 'Saved. Published pages use these settings from their next visit.')]);
    }
}
$flash = $flash ?? sb_take_flash();


// Resolve OG image preview if ID exists
$ogImageUrl = $seoOgImageUrl;
if ($seoOgMediaId !== '' && is_numeric($seoOgMediaId)) {
    $mediaRow = Database::row("SELECT path FROM media_files WHERE tenant_id = ? AND id = ? LIMIT 1", [$tenantId, (int) $seoOgMediaId]);
    if ($mediaRow && !empty($mediaRow['path'])) {
        $ogImageUrl = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') . '/' . ltrim((string) $mediaRow['path'], '/') : '/' . ltrim((string) $mediaRow['path'], '/');
    }
}

// The previews show a real page of this site: the home page, else the latest published page.
$samplePage = Database::row(
    "SELECT title, slug, route_mode FROM studiobuilder_pages WHERE tenant_id = ? AND status = 'published' AND page_type IN ('page', 'landing')
     ORDER BY route_mode = 'homepage' DESC, published_at DESC LIMIT 1",
    [$tenantId]
) ?: null;
$sampleTitle = $samplePage ? (string) $samplePage['title'] : ($siteName !== '' ? $siteName : 'Home');
$siteHost = (string) (parse_url(SLATE_URL, PHP_URL_HOST) ?: '');
$sampleCrumbs = array_values(array_filter(explode('/', (string) (parse_url(SLATE_URL, PHP_URL_PATH) ?: ''))));
if ($samplePage && $samplePage['route_mode'] !== 'homepage') {
    $sampleCrumbs[] = (string) $samplePage['slug'];
}
$sampleTitleText = static fn(string $format): string => str_replace(['%title%', '%site_name%'], [$sampleTitle, $siteName], $format !== '' ? $format : '%title%');

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('seo'); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> sb-alert">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="sb-page-head">
        <h2>Site &amp; Search Engine Optimization</h2>
        <p><?= e(__('studio_seo_intro', 'Site-wide defaults for every Studio page. The title format, description and share image are used when a page leaves its own SEO fields empty. A site set to noindex keeps every page out of search and out of the sitemap. Verification codes are added to every public page.')) ?></p>
    </div>

    <!-- Quick links -->
    <div class="sb-two-up">
        <div class="sb-card sb-link-card">
            <div>
                <span class="sb-link-card__kicker" style="color: #10b981;">Live XML feed</span>
                <h4>sitemap.xml</h4>
                <p>Every published, indexable page, kept up to date automatically.</p>
            </div>
            <a href="<?= e(SLATE_URL . '/sitemap.xml') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">
                <?= sb_svg('external-link', 14) ?> View
            </a>
        </div>

        <div class="sb-card sb-link-card">
            <div>
                <span class="sb-link-card__kicker" style="color: var(--sb-accent);">Bot directives</span>
                <h4>robots.txt</h4>
                <p>Base crawl permissions and the sitemap location.</p>
            </div>
            <a href="<?= e(SLATE_URL . '/robots.txt') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">
                <?= sb_svg('external-link', 14) ?> View
            </a>
        </div>
    </div>

    <!-- SEO settings and a live preview, side by side -->
    <div class="sb-two-up">
        <!-- Left: settings form -->
        <div class="sb-card">
            <div class="sb-card__head">
                <span class="sb-card__icon"><?= sb_svg('seo', 18) ?></span>
                <div>
                    <h3 class="sb-card__title">Global SEO controls</h3>
                    <p class="sb-card__sub">Defaults for every page that does not set its own.</p>
                </div>
            </div>

            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/seo.php')) ?>">
                <?= csrf_field() ?>
                <div class="sb-stack">
                    <div class="sb-field">
                        <label class="sb-field__label" for="seo-input-title">Title format pattern</label>
                        <input type="text" name="title_format" id="seo-input-title" value="<?= e($seoTitleFormat) ?>" class="form-control" placeholder="%title% | %site_name%">
                        <p class="sb-field__hint">Supported tags: <code>%title%</code>, <code>%site_name%</code>.</p>
                    </div>

                    <div class="sb-field">
                        <label class="sb-field__label" for="seo-input-desc">Global meta description</label>
                        <textarea name="description" id="seo-input-desc" rows="3" class="form-control" placeholder="Compelling summary under 160 characters..."><?= e($seoDescription) ?></textarea>
                        <p class="sb-field__hint">Search engines show about 160 characters.</p>
                    </div>

                    <div>
                        <input type="hidden" name="og_media_id" id="seo_og_media_id" value="<?= e($seoOgMediaId) ?>" data-sb-id-for="seo_og_image_url">
                        <?= sb_media_picker_field([
                            'id'          => 'seo_og_image_url',
                            'name'        => 'og_image_url',
                            'label'       => 'Default social share image (OpenGraph)',
                            'value'       => $ogImageUrl,
                            'placeholder' => 'https://... or choose from Media Library',
                            'help'        => 'Select an asset from your Media Library (recommended: 1200×630px). Used for Twitter/X, LinkedIn, and social embeds.',
                        ]) ?>
                    </div>

                    <div class="sb-field">
                        <label class="sb-field__label" for="seo-input-robots">Search robots policy</label>
                        <select name="robots" id="seo-input-robots" class="form-control">
                            <option value="index,follow" <?= $seoRobots === 'index,follow' ? 'selected' : '' ?>>index, follow (Recommended — allow indexing &amp; links)</option>
                            <option value="noindex,follow" <?= $seoRobots === 'noindex,follow' ? 'selected' : '' ?>>noindex, follow (hide from search, follow internal links)</option>
                            <option value="index,nofollow" <?= $seoRobots === 'index,nofollow' ? 'selected' : '' ?>>index, nofollow (index page, do not crawl outbound links)</option>
                            <option value="noindex,nofollow" <?= $seoRobots === 'noindex,nofollow' ? 'selected' : '' ?>>noindex, nofollow (complete bot blackout)</option>
                        </select>
                    </div>

                    <div class="sb-form-grid">
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-input-google">Google site verification tag</label>
                            <input type="text" name="google_verify" id="seo-input-google" value="<?= e($seoGoogleVerification) ?>" class="form-control" placeholder="google-site-verification code">
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-input-bing">Bing Webmaster tag</label>
                            <input type="text" name="bing_verify" id="seo-input-bing" value="<?= e($seoBingVerification) ?>" class="form-control" placeholder="msvalidate.01 code">
                        </div>
                    </div>
                </div>

                <div class="sb-card-actions">
                    <button type="submit" class="btn btn-primary">
                        <?= sb_svg('save', 14) ?> Save SEO settings
                    </button>
                </div>
            </form>
        </div>

        <!-- Right: Real-time SERP & Social Card Simulator -->
        <div>
            <!-- Google SERP Snippet Preview -->
            <div class="sb-card">
                <div style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: var(--sb-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <?= sb_svg('globe', 14) ?> Google Search Result Simulator
                </div>
                <div style="font-family: Arial, sans-serif;">
                    <div style="font-size: 0.82rem; color: #202124; display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                        <span><?= e($siteHost) ?></span>
                        <?php foreach ($sampleCrumbs as $crumb): ?><span style="color: #5f6368;">› <?= e($crumb) ?></span><?php endforeach; ?>
                    </div>
                    <div style="font-size: 1.15rem; color: #1a0dab; line-height: 1.3; font-weight: 400; margin-bottom: 4px;" data-seo-preview="title">
                        <?= e($sampleTitleText($seoTitleFormat)) ?>
                    </div>
                    <div style="font-size: 0.85rem; color: #4d5156; line-height: 1.4;" data-seo-preview="description">
                        <?= e($seoDescription) ?>
                    </div>
                    <p class="sb-field__hint" style="margin-top: 10px;">Shown with <?= e($sampleTitle) ?>. A page's own SEO title and description replace these defaults.</p>
                </div>
            </div>

            <!-- Twitter / OpenGraph Card Preview -->
            <div class="sb-card" style="margin-bottom: 0;">
                <div style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: var(--sb-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <?= sb_svg('eye', 14) ?> OpenGraph / Twitter Card Preview
                </div>
                <div style="border: 1px solid var(--sb-border); border-radius: 12px; overflow: hidden; background: #0f172a;">
                    <div style="height: 180px; background: #1e293b; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                        <?php if ($ogImageUrl !== ''): ?>
                            <img src="<?= e($ogImageUrl) ?>" alt="Social Preview" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <div style="text-align: center; color: #94a3b8;">
                                <?= sb_svg('media', 36) ?>
                                <div style="font-size: 0.75rem; margin-top: 6px;">No OpenGraph Media ID set</div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="padding: 14px; background: #ffffff;">
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--sb-muted); font-weight: 600; margin-bottom: 4px;">
                            <?= e($siteHost) ?>
                        </div>
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--sb-text); margin-bottom: 4px;" data-seo-preview="title">
                            <?= e($sampleTitleText($seoTitleFormat)) ?>
                        </div>
                        <div style="font-size: 0.82rem; color: var(--sb-muted); line-height: 1.4;" data-seo-preview="card-description">
                            <?= e(mb_strimwidth($seoDescription, 0, 110, '...')) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// The previews follow the title format and description as they are typed.
(function () {
  var title = document.getElementById('seo-input-title'), desc = document.getElementById('seo-input-desc');
  var page = <?= json_encode($sampleTitle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, site = <?= json_encode($siteName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  function set(name, text) { document.querySelectorAll('[data-seo-preview="' + name + '"]').forEach(function (el) { el.textContent = text; }); }
  function update() {
    set('title', (title.value.trim() || '%title%').split('%title%').join(page).split('%site_name%').join(site));
    var d = desc.value.trim();
    set('description', d);
    set('card-description', d.length > 110 ? d.slice(0, 107) + '...' : d);
  }
  if (title && desc) { title.addEventListener('input', update); desc.addEventListener('input', update); }
})();
</script>

<?php sb_render_media_picker_modal(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
