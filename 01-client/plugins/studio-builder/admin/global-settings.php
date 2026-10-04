<?php
/**
 * Kohevo Studio — Global Settings.
 *
 * Dedicated admin page for layout container max-widths, responsive breakpoints,
 * spacing scales, platform signature toggle, and accessibility preferences.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_global_settings', 'Global settings');
$currentNav = 'studio-global-settings';

$flash = null;

// Read existing settings
$containerWidth = (string) (Database::setting('studio_global_container_width', $tenantId) ?: '1280px');
$narrowWidth = (string) (Database::setting('studio_global_narrow_width', $tenantId) ?: '840px');
$pageGutter = (string) (Database::setting('studio_global_page_gutter', $tenantId) ?: 'clamp(16px, 5vw, 40px)');
$bpMobile = (string) (Database::setting('studio_global_bp_mobile', $tenantId) ?: '768px');
$bpTablet = (string) (Database::setting('studio_global_bp_tablet', $tenantId) ?: '1024px');
$showSignature = (bool) (Database::setting('studio_global_show_signature', $tenantId) ?? false);
$smoothScroll = (bool) (Database::setting('studio_global_smooth_scroll', $tenantId) ?? true);
$respectMotion = (bool) (Database::setting('studio_global_respect_motion', $tenantId) ?? true);

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to edit global settings.')];
    } else {
        $containerWidth = trim((string) ($_POST['container_width'] ?? '1280px'));
        $narrowWidth = trim((string) ($_POST['narrow_width'] ?? '840px'));
        $pageGutter = trim((string) ($_POST['page_gutter'] ?? 'clamp(16px, 5vw, 40px)'));
        $bpMobile = trim((string) ($_POST['bp_mobile'] ?? '768px'));
        $bpTablet = trim((string) ($_POST['bp_tablet'] ?? '1024px'));
        $showSignature = !empty($_POST['show_signature']);
        $smoothScroll = !empty($_POST['smooth_scroll']);
        $respectMotion = !empty($_POST['respect_motion']);

        Database::setSetting('studio_global_container_width', $containerWidth, $tenantId);
        Database::setSetting('studio_global_narrow_width', $narrowWidth, $tenantId);
        Database::setSetting('studio_global_page_gutter', $pageGutter, $tenantId);
        Database::setSetting('studio_global_bp_mobile', $bpMobile, $tenantId);
        Database::setSetting('studio_global_bp_tablet', $bpTablet, $tenantId);
        Database::setSetting('studio_global_show_signature', $showSignature ? '1' : '0', $tenantId);
        Database::setSetting('studio_global_smooth_scroll', $smoothScroll ? '1' : '0', $tenantId);
        Database::setSetting('studio_global_respect_motion', $respectMotion ? '1' : '0', $tenantId);

        $flash = ['type' => 'success', 'msg' => 'Global layout and system settings saved successfully!'];
    }
}

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('global-settings', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--sb-text); margin: 0 0 4px 0;">Layout System & Global Settings</h2>
        <p style="font-size: 0.88rem; color: var(--sb-muted); margin: 0;">Specify structural container bounds, viewport breakpoints, and core platform behaviors.</p>
    </div>

    <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/global-settings.php')) ?>">
        <?= csrf_field() ?>

        <!-- Section 1: Container Widths -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('sliders', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Layout Widths & Spacing</h3>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;">
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Standard Boxed Container (<code>--sb-max-w</code>)</label>
                    <input type="text" name="container_width" value="<?= e($containerWidth) ?>" class="form-control" placeholder="1280px">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Maximum width for hero, features, and grid sections.</span>
                </div>

                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Narrow Prose Container</label>
                    <input type="text" name="narrow_width" value="<?= e($narrowWidth) ?>" class="form-control" placeholder="840px">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Optimum reading width for case studies and single blog posts.</span>
                </div>

                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Horizontal Viewport Gutter</label>
                    <input type="text" name="page_gutter" value="<?= e($pageGutter) ?>" class="form-control" placeholder="clamp(16px, 5vw, 40px)">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Fluid lateral padding preventing edge overflow on mobile.</span>
                </div>
            </div>
        </div>

        <!-- Section 2: Breakpoints -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('overview', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Responsive Breakpoints</h3>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px;">
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Mobile Viewport Max</label>
                    <input type="text" name="bp_mobile" value="<?= e($bpMobile) ?>" class="form-control" placeholder="768px">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Point where grids collapse to single column (default 768px).</span>
                </div>

                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Tablet Viewport Max</label>
                    <input type="text" name="bp_tablet" value="<?= e($bpTablet) ?>" class="form-control" placeholder="1024px">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Intermediate 2-column breakpoint (default 1024px).</span>
                </div>
            </div>
        </div>

        <!-- Section 3: System & Platform Preferences -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 28px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('zap', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Platform Behavior & Motion</h3>
            </div>

            <div style="display: flex; flex-direction: column; gap: 16px;">
                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                    <input type="checkbox" name="smooth_scroll" value="1" <?= $smoothScroll ? 'checked' : '' ?> style="margin-top: 3px;">
                    <div>
                        <div style="font-weight: 600; font-size: 0.9rem; color: var(--sb-text);">Native Smooth Anchor Scrolling</div>
                        <div style="font-size: 0.8rem; color: var(--sb-muted);">Enables fluid scrolling for header navigation links pointing to in-page anchors (e.g. <code>#projects</code>, <code>#contact</code>).</div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                    <input type="checkbox" name="respect_motion" value="1" <?= $respectMotion ? 'checked' : '' ?> style="margin-top: 3px;">
                    <div>
                        <div style="font-weight: 600; font-size: 0.9rem; color: var(--sb-text);">Respect Reduced Motion (Accessibility)</div>
                        <div style="font-size: 0.8rem; color: var(--sb-muted);">Automatically disables heavy entrance keyframe animations when visitor OS has requested reduced motion.</div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                    <input type="checkbox" name="show_signature" value="1" <?= $showSignature ? 'checked' : '' ?> style="margin-top: 3px;">
                    <div>
                        <div style="font-weight: 600; font-size: 0.9rem; color: var(--sb-text);">Display Platform Badge in Footer</div>
                        <div style="font-size: 0.8rem; color: var(--sb-muted);">Renders a discreet "Crafted with Kohevo Studio" signature pill in the site footer.</div>
                    </div>
                </label>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary">
                <?= sb_svg('save', 14) ?> Save Global Settings
            </button>
        </div>
    </form>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
