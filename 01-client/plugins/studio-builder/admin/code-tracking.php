<?php
/**
 * Kohevo Studio — Code & Tracking Injection.
 *
 * Dedicated admin page for tenant-wide analytics IDs and a custom CSS
 * stylesheet, both consumed by `StudioCodePolicy` at render time.
 *
 * SCOPE — read this before adding a field here. This page can only store
 * what a renderer will actually emit:
 *   - GA4 / GTM container IDs   → converted to Google's own fixed snippets
 *   - custom CSS                → sanitized, emitted in <head>
 * The raw head/footer `<script>` boxes that used to live here were REMOVED.
 * They were written to settings that no renderer ever read, so tenants were
 * saving code that silently did nothing. `StudioCodePolicy::DEFERRED_SETTINGS`
 * names them; tenant-authored JavaScript needs its own permission and audit
 * story before it can ship, and until then it is absent by construction.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_code_tracking', 'Code & tracking');
$currentNav = 'studio-code-tracking';

$flash = null;

// Read existing settings
$customCss = (string) (Database::setting(StudioCodePolicy::SETTING_CUSTOM_CSS, $tenantId) ?: '');
$ga4Id = (string) (Database::setting(StudioCodePolicy::SETTING_GA4, $tenantId) ?: '');
$gtmId = (string) (Database::setting(StudioCodePolicy::SETTING_GTM, $tenantId) ?: '');

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage scripts and CSS.')];
    } else {
        $customCss = (string) ($_POST['custom_css'] ?? '');
        $ga4Id = trim((string) ($_POST['ga4_id'] ?? ''));
        $gtmId = trim((string) ($_POST['gtm_id'] ?? ''));

        // Validation mirrors StudioCodePolicy exactly, so a malformed ID is
        // rejected here with a message instead of being stored and silently
        // dropped at render time. Never normalized into the snippet.
        $ga4Err = StudioCodePolicy::ga4Id($ga4Id) === null && $ga4Id !== '';
        $gtmErr = StudioCodePolicy::gtmId($gtmId) === null && $gtmId !== '';

        if ($ga4Err || $gtmErr) {
            $flash = ['type' => 'error', 'msg' => 'Analytics ID not saved — GA4 must look like G-XXXXXXXXXX and GTM like GTM-XXXXXXX.'];
        } else {
            Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, StudioCodePolicy::sanitizeCustomCss($customCss), $tenantId);
            Database::setSetting(StudioCodePolicy::SETTING_GA4, $ga4Id, $tenantId);
            Database::setSetting(StudioCodePolicy::SETTING_GTM, $gtmId, $tenantId);

            AuditLog::record('studio.code_tracking_updated', (string) $tenantId, [
                'ga4'   => $ga4Id !== '',
                'gtm'   => $gtmId !== '',
                'css_bytes' => strlen(StudioCodePolicy::sanitizeCustomCss($customCss)),
            ]);

            $flash = ['type' => 'success', 'msg' => 'Analytics IDs and custom CSS saved.'];
        }
    }
}

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('code-tracking', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--sb-text); margin: 0 0 4px 0;">Custom Code, Tracking & CSS</h2>
        <p style="font-size: 0.88rem; color: var(--sb-muted); margin: 0;">Safely inject analytics tags, marketing pixels, head/footer scripts, and global CSS stylesheets.</p>
    </div>

    <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/code-tracking.php')) ?>">
        <?= csrf_field() ?>

        <!-- Section 1: Fast Analytics Integration -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('zap', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Analytics & Tag IDs</h3>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Google Analytics 4 Measurement ID</label>
                    <input type="text" name="ga4_id" value="<?= e($ga4Id) ?>" class="form-control" placeholder="G-XXXXXXXXXX">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Automatically loads gtag.js asynchronously without editing templates.</span>
                </div>

                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Google Tag Manager Container ID</label>
                    <input type="text" name="gtm_id" value="<?= e($gtmId) ?>" class="form-control" placeholder="GTM-XXXXXXX">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Injects official GTM container snippets into head and body.</span>
                </div>
            </div>
        </div>

        <!-- Section 2: Global Custom CSS -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 28px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('edit', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Global Custom CSS</h3>
            </div>

            <p style="font-size: 0.85rem; color: var(--sb-muted); margin-bottom: 12px;">
                Style rules defined here are loaded after Studio's own stylesheet on every
                public and preview page, so they override block styling. They are not applied
                inside the builder canvas, so the canvas always shows the published design.
                <code>@import</code> and script-bearing URLs are stripped on save.
            </p>

            <textarea name="custom_css" rows="6" class="form-control" style="font-family: ui-monospace, monospace; font-size: 0.85rem; background: #0f172a; color: #cbd5e1;" placeholder="/* Custom CSS overrides */
.sb-custom-highlight {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
}"><?= e($customCss) ?></textarea>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary">
                <?= sb_svg('save', 14) ?> Save Analytics &amp; CSS
            </button>
        </div>
    </form>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
