<?php
/**
 * Coaching — settings.
 *
 * Picks the membership plan that gates program access. Without a plan
 * selected, isEnrolled() always returns false and no client sees the
 * program surface. Also sets a default activity_factor for the BMR→TDEE
 * calculation.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';
require_once dirname(__DIR__) . '/includes/assets.php';

Auth::require();
Auth::requirePerm('coaching.manage_clients');
CoachingAPI::ensureSchema();

$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        Database::setSetting('coaching.program_plan_id', (string) max(0, (int)($_POST['program_plan_id'] ?? 0)));
        $af = (float)($_POST['activity_factor'] ?? 1.4);
        if ($af < 1.0) $af = 1.0;
        if ($af > 2.5) $af = 2.5;
        Database::setSetting('coaching.default_activity_factor', (string) $af);
        $flash = ['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')];
    }
}

$plans = [];
if (class_exists('MembershipAPI')) {
    $plans = Database::rows(
        "SELECT id, name, duration_days FROM membership_plans WHERE tenant_id = ? AND is_active = 1 ORDER BY name",
        [current_tenant_id()]
    );
}
$currentPlanId = (int) (Database::setting('coaching.program_plan_id') ?? 0);
$activityFactor = (float) (Database::setting('coaching.default_activity_factor') ?? 1.4);

$pageTitle  = __('cc_coaching_title', 'Coaching') . ' · ' . __('settings', 'Settings');
$currentNav = 'coaching-settings';

require SLATE_ROOT . '/admin/partials/header.php';

// The plugin's design, emitted by the page rather than relying on a boot-time
// enqueue that never runs while coaching is inactive.
coaching_emit_css('admin.css');

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cc_coaching_title', 'Coaching'),  'href' => plugin_url('coaching', 'admin/index.php')],
    ['label' => __('settings', 'Settings')],
]);
?>

<div class="page-header">
    <div><h1><?= __('cc_settings_h1', 'Coaching settings') ?></h1></div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : 'danger') ?>" style="margin-bottom:var(--space-4);">
        <?= e($flash['msg']) ?>
    </div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

    <div class="card" style="padding:var(--space-4);margin-bottom:var(--space-4);">
        <h2 style="margin-top:0;"><?= __('cc_program_access_gate', 'Program access gate') ?></h2>
        <?php if (!class_exists('MembershipAPI')): ?>
            <div class="alert alert-warning">
                <?= __('cc_membership_inactive_pre', 'The') ?> <strong><?= __('cc_membership_plugin_name', 'membership') ?></strong> <?= __('cc_membership_inactive_post', 'plugin isn\'t active. Coaching needs it to gate program access.') ?>
                <?= __('cc_membership_install_hint', 'Install and activate the Membership plugin, create a "Body &amp; Soul Program — 3 months" plan, then come back.') ?>
            </div>
        <?php elseif (!$plans): ?>
            <div class="alert alert-warning">
                <?= __('cc_no_plans_found', 'No active membership plans found. Create a plan named "Body &amp; Soul Program — 3 months" (duration 90 days) in') ?>
                <a href="<?= e(plugin_url('membership', 'admin/plans.php')) ?>"><?= __('cc_membership_plans_link', 'Membership · Plans') ?></a>, <?= __('cc_no_plans_reload', 'then reload.') ?>
            </div>
        <?php else: ?>
            <div class="field" style="max-width:600px;">
                <label class="field-label" for="program_plan_id"><?= __('cc_program_gate_label', 'Membership plan used as the program gate') ?></label>
                <select id="program_plan_id" name="program_plan_id">
                    <option value="0"><?= __('cc_gate_none_option', '— none — clients see no program surface —') ?></option>
                    <?php foreach ($plans as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= ($currentPlanId === (int)$p['id']) ? 'selected' : '' ?>>
                            <?= e($p['name']) ?>
                            <?php if (!empty($p['duration_days'])): ?>
                                (<?= (int)$p['duration_days'] ?> <?= __('cc_days_suffix', 'days') ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint"><?= __('cc_gate_hint', 'Only clients holding an active subscription to this plan appear as enrolled.') ?></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" style="padding:var(--space-4);margin-bottom:var(--space-4);">
        <h2 style="margin-top:0;"><?= __('cc_tdee_default_h2', 'TDEE default') ?></h2>
        <div class="field" style="max-width:400px;">
            <label class="field-label" for="activity_factor"><?= __('cc_default_activity_factor_label', 'Default activity factor') ?></label>
            <input type="number" id="activity_factor" name="activity_factor"
                   min="1.0" max="2.5" step="0.05" value="<?= e(number_format($activityFactor, 2)) ?>">
            <div class="field-hint">
                <?= __('cc_activity_factor_hint_1', 'Multiplier applied to BMR to estimate TDEE (Total Daily Energy Expenditure).') ?>
                <?= __('cc_activity_factor_hint_values', 'Common values: 1.2 sedentary · 1.4 light · 1.55 moderate · 1.75 active · 1.9 very active.') ?>
                <?= __('cc_activity_factor_hint_override', 'Overridable per client.') ?>
            </div>
        </div>
    </div>

    <div style="text-align:right;">
        <button type="submit" class="btn btn-primary"><?= __('cc_save_settings', 'Save settings') ?></button>
    </div>
</form>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
