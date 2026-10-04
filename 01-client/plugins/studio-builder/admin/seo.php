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
$seoDescription = (string) (Database::setting('studio_seo_description', $tenantId) ?: 'Full-stack software architect & freelancer portfolio crafting high-performance digital products.');
$seoOgMediaId = (string) (Database::setting('studio_seo_og_media_id', $tenantId) ?: '');
$seoRobots = (string) (Database::setting('studio_seo_robots', $tenantId) ?: 'index,follow');
$seoGoogleVerification = (string) (Database::setting('studio_seo_google_verify', $tenantId) ?: '');
$seoBingVerification = (string) (Database::setting('studio_seo_bing_verify', $tenantId) ?: '');
$siteName = (string) (Database::setting('site_name', $tenantId) ?: 'Rakib Hasan');

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to edit SEO settings.')];
    } else {
        $seoTitleFormat = trim((string) ($_POST['title_format'] ?? ''));
        $seoDescription = trim((string) ($_POST['description'] ?? ''));
        $seoOgMediaId = trim((string) ($_POST['og_media_id'] ?? ''));
        $seoRobots = trim((string) ($_POST['robots'] ?? 'index,follow'));
        $seoGoogleVerification = trim((string) ($_POST['google_verify'] ?? ''));
        $seoBingVerification = trim((string) ($_POST['bing_verify'] ?? ''));

        Database::setSetting('studio_seo_title_format', $seoTitleFormat, $tenantId);
        Database::setSetting('studio_seo_description', $seoDescription, $tenantId);
        Database::setSetting('studio_seo_og_media_id', $seoOgMediaId, $tenantId);
        Database::setSetting('studio_seo_robots', $seoRobots, $tenantId);
        Database::setSetting('studio_seo_google_verify', $seoGoogleVerification, $tenantId);
        Database::setSetting('studio_seo_bing_verify', $seoBingVerification, $tenantId);

        $flash = ['type' => 'success', 'msg' => 'Global SEO settings and meta directives saved successfully!'];
    }
}

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

// Resolve OG image preview if ID exists
$ogImageUrl = '';
if ($seoOgMediaId !== '' && is_numeric($seoOgMediaId)) {
    $mediaRow = Database::row("SELECT path FROM media_files WHERE tenant_id = ? AND id = ? LIMIT 1", [$tenantId, (int) $seoOgMediaId]);
    if ($mediaRow && !empty($mediaRow['path'])) {
        $ogImageUrl = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') . '/' . ltrim((string) $mediaRow['path'], '/') : '/' . ltrim((string) $mediaRow['path'], '/');
    }
}

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('seo', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--sb-text); margin: 0 0 4px 0;">Site & Search Engine Optimization</h2>
        <p style="font-size: 0.88rem; color: var(--sb-muted); margin: 0;">Configure global metadata, search bot indexing directives, and social card sharing previews.</p>
    </div>

    <!-- Quick Links & Diagnostics -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 28px;">
        <div class="card" style="border-radius: 14px; border: 1px solid var(--sb-border); padding: 18px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #10b981; letter-spacing: 0.05em;">Live XML Feed</span>
                <h4 style="font-size: 1rem; font-weight: 700; color: var(--sb-text); margin: 2px 0;">sitemap.xml</h4>
                <p style="font-size: 0.8rem; color: var(--sb-muted); margin: 0;">Two-query zero-cost deterministic feed.</p>
            </div>
            <a href="<?= e(SLATE_URL . '/sitemap.xml') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">
                <?= sb_svg('external-link', 14) ?> View
            </a>
        </div>

        <div class="card" style="border-radius: 14px; border: 1px solid var(--sb-border); padding: 18px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--sb-accent); letter-spacing: 0.05em;">Bot Directives</span>
                <h4 style="font-size: 1rem; font-weight: 700; color: var(--sb-text); margin: 2px 0;">robots.txt</h4>
                <p style="font-size: 0.8rem; color: var(--sb-muted); margin: 0;">Configured base crawl permissions.</p>
            </div>
            <a href="<?= e(SLATE_URL . '/robots.txt') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">
                <?= sb_svg('external-link', 14) ?> View
            </a>
        </div>
    </div>

    <!-- SEO Settings Form & Live Social Preview Side-by-Side -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px; margin-bottom: 32px;">
        <!-- Left: Settings Form -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px;">
            <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0 0 18px 0;">Global SEO Controls</h3>

            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/seo.php')) ?>">
                <?= csrf_field() ?>

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Title Format Pattern</label>
                    <input type="text" name="title_format" id="seo-input-title" value="<?= e($seoTitleFormat) ?>" class="form-control" placeholder="%title% | %site_name%">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Supported tags: <code>%title%</code>, <code>%site_name%</code>.</span>
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Global Meta Description</label>
                    <textarea name="description" id="seo-input-desc" rows="3" class="form-control" placeholder="Compelling summary under 160 characters..."><?= e($seoDescription) ?></textarea>
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Default OpenGraph Media ID</label>
                    <input type="number" name="og_media_id" value="<?= e($seoOgMediaId) ?>" class="form-control" placeholder="e.g. 14">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Asset ID from the <a href="media.php">Media Inspector</a> for social cards.</span>
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Search Robots Policy</label>
                    <select name="robots" class="form-control" style="background: #ffffff;">
                        <option value="index,follow" <?= $seoRobots === 'index,follow' ? 'selected' : '' ?>>index, follow (Recommended — Allow Indexing & Links)</option>
                        <option value="noindex,follow" <?= $seoRobots === 'noindex,follow' ? 'selected' : '' ?>>noindex, follow (Hide from search, follow internal links)</option>
                        <option value="index,nofollow" <?= $seoRobots === 'index,nofollow' ? 'selected' : '' ?>>index, nofollow (Index page, do not crawl outbound links)</option>
                        <option value="noindex,nofollow" <?= $seoRobots === 'noindex,nofollow' ? 'selected' : '' ?>>noindex, nofollow (Complete bot blackout)</option>
                    </select>
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Google Site Verification Tag</label>
                    <input type="text" name="google_verify" value="<?= e($seoGoogleVerification) ?>" class="form-control" placeholder="google-site-verification code">
                </div>

                <div style="margin-bottom: 24px;">
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Bing Webmaster Tag</label>
                    <input type="text" name="bing_verify" value="<?= e($seoBingVerification) ?>" class="form-control" placeholder="msvalidate.01 code">
                </div>

                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <?= sb_svg('save', 14) ?> Save SEO Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- Right: Real-time SERP & Social Card Simulator -->
        <div>
            <!-- Google SERP Snippet Preview -->
            <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 22px; margin-bottom: 20px;">
                <div style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: var(--sb-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <?= sb_svg('globe', 14) ?> Google Search Result Simulator
                </div>
                <div style="font-family: Arial, sans-serif;">
                    <div style="font-size: 0.82rem; color: #202124; display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                        <span><?= e(parse_url(SLATE_URL, PHP_URL_HOST) ?: 'rakibhasaan.com') ?></span>
                        <span style="color: #5f6368;">› portfolio</span>
                    </div>
                    <div style="font-size: 1.15rem; color: #1a0dab; line-height: 1.3; font-weight: 400; margin-bottom: 4px;">
                        <?= e(str_replace(['%title%', '%site_name%'], ['Portfolio', $siteName], $seoTitleFormat)) ?>
                    </div>
                    <div style="font-size: 0.85rem; color: #4d5156; line-height: 1.4;">
                        <?= e($seoDescription) ?>
                    </div>
                </div>
            </div>

            <!-- Twitter / OpenGraph Card Preview -->
            <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 22px;">
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
                            <?= e(parse_url(SLATE_URL, PHP_URL_HOST) ?: 'rakibhasaan.com') ?>
                        </div>
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--sb-text); margin-bottom: 4px;">
                            <?= e(str_replace(['%title%', '%site_name%'], ['Portfolio', $siteName], $seoTitleFormat)) ?>
                        </div>
                        <div style="font-size: 0.82rem; color: var(--sb-muted); line-height: 1.4;">
                            <?= e(mb_strimwidth($seoDescription, 0, 110, '...')) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
