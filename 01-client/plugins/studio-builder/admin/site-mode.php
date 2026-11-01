<?php
/**
 * Kohevo Studio — Maintenance Mode: close the public site as Coming Soon (HTTP 200) or Maintenance (HTTP 503), choose who
 * gets past it, and which template visitors see.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Application\SiteModeTemplates;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Meta\SiteMode;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();
Auth::requirePerm('studio-builder.view');

// Closing the site changes it for every visitor at once.
$canEdit = Auth::can('studio-builder.publish') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_site_mode', 'Maintenance Mode');
$currentNav = 'studio-site-mode';

$templatePages = static function () use ($tenantId): array {
    $list = [];
    foreach (Database::rows("SELECT id, title, status FROM studiobuilder_pages WHERE tenant_id = ? AND page_type = 'system' AND status = 'published' AND slug LIKE 'template-%' AND (slug IN ('template-site-coming-soon', 'template-site-maintenance') OR settings_json LIKE '%\"template_type\":\"maintenance\"%') ORDER BY title", [$tenantId]) as $row) {
        $list[(int) $row['id']] = trim((string) preg_replace('/^\[Theme\]\s*/', '', (string) $row['title']));
    }
    return $list;
};

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('studio_tf_live_denied', 'These settings change the public site at once. Ask someone who can publish pages.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } else {
        $mode = in_array($_POST['mode'] ?? '', [SiteMode::COMING_SOON, SiteMode::MAINTENANCE], true) ? (string) $_POST['mode'] : SiteMode::OFF;
        $template = (int) ($_POST['template'] ?? 0);
        $pages = $templatePages();
        try {
            // A site closed for the first time gets the prebuilt template of that mode.
            if ($mode !== SiteMode::OFF && !isset($pages[$template])) {
                $template = SiteModeTemplates::install(StudioRuntimeFactory::build()->app, StudioActor::fromCurrentSession(), $mode);
            }
            if ((string) ($_POST['_action'] ?? '') === 'install') {
                foreach (array_keys(SiteModeTemplates::KEYS) as $kind) {
                    SiteModeTemplates::install(StudioRuntimeFactory::build()->app, StudioActor::fromCurrentSession(), $kind);
                }
                sb_flash_redirect('site-mode.php', ['type' => 'success', 'msg' => __('studio_site_mode_installed', 'Prebuilt templates added.')]);
            }
            Database::setSetting(SiteMode::MODE, $mode, $tenantId);
            Database::setSetting(SiteMode::ACCESS, (string) ($_POST['access'] ?? '') === 'admins' ? 'admins' : 'logged_in', $tenantId);
            Database::setSetting(SiteMode::TEMPLATE, (string) $template, $tenantId);
            AuditLog::record('settings.updated', 'studio-site-mode', ['mode' => $mode]);
            sb_flash_redirect('site-mode.php', ['type' => 'success', 'msg' => $mode === SiteMode::OFF ? __('studio_site_mode_off_saved', 'Your site is live.') : __('studio_site_mode_on_saved', 'Saved. Visitors now see the template you chose.')]);
        } catch (\Throwable $e) {
            error_log('[studio-builder] site mode: ' . $e::class . ': ' . $e->getMessage());
            $flash = ['type' => 'error', 'msg' => __('studio_site_mode_failed', 'The template could not be created, so nothing was changed. Creating templates needs a Studio administrator.')];
        }
    }
}
$flash = $flash ?? sb_take_flash();

$state = SiteMode::read($tenantId);
$pages = $templatePages();
$dis = $canEdit ? '' : ' disabled';
$choices = [
    SiteMode::OFF => [__('studio_site_mode_disabled', 'Disabled'), __('studio_site_mode_disabled_hint', 'Your site is open to everyone.'), ''],
    SiteMode::COMING_SOON => [__('studio_site_mode_coming_soon', 'Coming Soon'), __('studio_site_mode_coming_soon_hint', 'Not launched yet. Search engines get a normal page.'), 'HTTP 200'],
    SiteMode::MAINTENANCE => [__('studio_site_mode_maintenance', 'Maintenance'), __('studio_site_mode_maintenance_hint', 'Down for a while. Search engines are told to come back.'), 'HTTP 503'],
];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_site_mode_heading', 'Maintenance Mode')) ?></h2>
            <p><?= e(__('studio_site_mode_intro', 'Close your website to visitors while you work on it, with a page of your own.')) ?></p>
        </div>
        <?php if ($state['mode'] !== SiteMode::OFF): ?>
            <span class="badge badge-warning" data-testid="site-closed"><?= e(__('studio_site_mode_closed', 'Site is closed to visitors')) ?></span>
        <?php endif; ?>
    </div>

    <form method="post" class="sb-permalinks" data-testid="site-mode-form">
        <?= csrf_field() ?>

        <section class="sb-card sb-form-card">
            <div class="sb-card__head">
                <div>
                    <div class="sb-card__title"><?= e(__('studio_site_mode_choose', 'Choose mode')) ?></div>
                    <p class="sb-card__sub"><?= e(__('studio_site_mode_choose_sub', 'The change reaches every visitor as soon as you save.')) ?></p>
                </div>
            </div>
            <div class="sb-url-choices" role="radiogroup" aria-label="<?= e(__('studio_site_mode_choose', 'Choose mode')) ?>">
                <?php foreach ($choices as $value => [$label, $hint, $code]): ?>
                    <label class="sb-url-choice">
                        <input type="radio" name="mode" value="<?= e($value) ?>" <?= $state['mode'] === $value ? 'checked' : '' ?><?= $dis ?>>
                        <span><strong><?= e($label) ?></strong><?php if ($code !== ''): ?><code><?= e($code) ?></code><?php endif; ?><small><?= e($hint) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="sb-card sb-form-card">
            <div class="sb-card__head">
                <div>
                    <div class="sb-card__title"><?= e(__('studio_site_mode_details', 'Access and template')) ?></div>
                </div>
            </div>
            <div class="sb-form-grid">
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-mode-access"><?= e(__('studio_site_mode_access', 'Who can access')) ?></label>
                    <select id="sb-mode-access" name="access"<?= $dis ?>>
                        <option value="logged_in" <?= $state['access'] === 'logged_in' ? 'selected' : '' ?>><?= e(__('studio_site_mode_logged_in', 'Logged in')) ?></option>
                        <option value="admins" <?= $state['access'] === 'admins' ? 'selected' : '' ?>><?= e(__('studio_site_mode_admins', 'Studio editors only')) ?></option>
                    </select>
                    <p class="sb-field__hint"><?= e(__('studio_site_mode_access_hint', 'They see the real site. Everyone else sees the template.')) ?></p>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label" for="sb-mode-template"><?= e(__('studio_site_mode_template', 'Choose template')) ?></label>
                    <select id="sb-mode-template" name="template"<?= $dis ?>>
                        <option value=""><?= e(__('studio_site_mode_template_auto', 'Prebuilt for the chosen mode')) ?></option>
                        <?php foreach ($pages as $id => $title): ?>
                            <option value="<?= (int) $id ?>" <?= $state['template'] === $id ? 'selected' : '' ?>><?= e($title) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($state['template'] > 0 && isset($pages[$state['template']])): ?>
                        <p class="sb-field__hint"><a href="builder.php?page=<?= (int) $state['template'] ?>" data-testid="edit-template"><?= e(__('studio_site_mode_edit', 'Edit template')) ?></a></p>
                    <?php elseif ($pages === [] && $canEdit): ?>
                        <p class="sb-field__hint"><button type="submit" class="btn btn-sm btn-outline" name="_action" value="install"><?= e(__('studio_site_mode_install', 'Add the prebuilt templates')) ?></button></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($canEdit): ?>
            <div class="sb-url-save">
                <button type="submit" class="btn btn-primary"><?= e(__('save_changes', 'Save changes')) ?></button>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
