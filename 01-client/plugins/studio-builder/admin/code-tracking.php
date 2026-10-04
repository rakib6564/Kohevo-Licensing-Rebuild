<?php
/**
 * Kohevo Studio — Code & Tracking Injection.
 *
 * Dedicated admin page for managing head/footer scripts, GA4 / GTM IDs,
 * tracking pixels, and tenant-wide custom CSS stylesheets.
 */

declare(strict_types=1);

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
$headScripts = (string) (Database::setting('studio_code_head', $tenantId) ?: '');
$footerScripts = (string) (Database::setting('studio_code_footer', $tenantId) ?: '');
$customCss = (string) (Database::setting('studio_code_custom_css', $tenantId) ?: '');
$ga4Id = (string) (Database::setting('studio_code_ga4_id', $tenantId) ?: '');
$gtmId = (string) (Database::setting('studio_code_gtm_id', $tenantId) ?: '');

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage scripts and CSS.')];
    } else {
        $headScripts = (string) ($_POST['head_scripts'] ?? '');
        $footerScripts = (string) ($_POST['footer_scripts'] ?? '');
        $customCss = (string) ($_POST['custom_css'] ?? '');
        $ga4Id = trim((string) ($_POST['ga4_id'] ?? ''));
        $gtmId = trim((string) ($_POST['gtm_id'] ?? ''));

        Database::setSetting('studio_code_head', $headScripts, $tenantId);
        Database::setSetting('studio_code_footer', $footerScripts, $tenantId);
        Database::setSetting('studio_code_custom_css', $customCss, $tenantId);
        Database::setSetting('studio_code_ga4_id', $ga4Id, $tenantId);
        Database::setSetting('studio_code_gtm_id', $gtmId, $tenantId);

        $flash = ['type' => 'success', 'msg' => 'Custom code, analytics IDs, and CSS stylesheet saved successfully!'];
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

        <!-- Section 2: Header & Footer Script Injections -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('code-tracking', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Raw Script Injection</h3>
            </div>

            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Header Scripts (Appended inside <code>&lt;head&gt;</code>)</label>
                    <textarea name="head_scripts" rows="4" class="form-control" style="font-family: ui-monospace, monospace; font-size: 0.85rem;" placeholder="<script>/* custom head scripts */</script>"><?= e($headScripts) ?></textarea>
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Useful for Meta Pixel, Hotjar, custom typography link tags, or verification snippets.</span>
                </div>

                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Footer Scripts (Appended before closing <code>&lt;/body&gt;</code>)</label>
                    <textarea name="footer_scripts" rows="4" class="form-control" style="font-family: ui-monospace, monospace; font-size: 0.85rem;" placeholder="<script>/* custom footer scripts */</script>"><?= e($footerScripts) ?></textarea>
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Recommended for interactive chat widgets, CRM tracking, or deferred scripts.</span>
                </div>
            </div>
        </div>

        <!-- Section 3: Global Custom CSS -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 28px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('edit', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Global Custom CSS</h3>
            </div>

            <p style="font-size: 0.85rem; color: var(--sb-muted); margin-bottom: 12px;">
                Cascading style rules defined here are compiled and loaded with highest specificity across all Studio Builder pages.
            </p>

            <textarea name="custom_css" rows="6" class="form-control" style="font-family: ui-monospace, monospace; font-size: 0.85rem; background: #0f172a; color: #cbd5e1;" placeholder="/* Custom CSS overrides */
.sb-custom-highlight {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
}"><?= e($customCss) ?></textarea>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary">
                <?= sb_svg('save', 14) ?> Save Scripts & CSS
            </button>
        </div>
    </form>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
