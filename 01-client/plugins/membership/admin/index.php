<?php
/**
 * Membership — admin overview.
 *
 * KPI snapshot + quick links. Member management and reports land in later
 * phases; this is the landing page the nav points at.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/MembershipAPI.php';

Auth::require();
if (!Auth::can('membership.view') && !Auth::isSuperAdmin()) {
    Auth::requirePerm('membership.view');
}
MembershipAPI::ensureSchema();

$pageTitle  = 'Membership';
$currentNav = 'membership';
$tid = current_tenant_id();

$active = (int) Database::value(
    "SELECT COUNT(*) FROM membership_subscriptions
      WHERE tenant_id = ? AND status = 'active'
        AND (expires_at IS NULL OR COALESCE(grace_until, expires_at) >= NOW())", [$tid]);
$expiring = (int) Database::value(
    "SELECT COUNT(*) FROM membership_subscriptions
      WHERE tenant_id = ? AND status = 'active' AND expires_at IS NOT NULL
        AND expires_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY", [$tid]);
$plansActive = (int) Database::value(
    "SELECT COUNT(*) FROM membership_plans WHERE tenant_id = ? AND is_active = 1", [$tid]);
$members = (int) Database::value(
    "SELECT COUNT(*) FROM membership_profiles WHERE tenant_id = ?", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('membership', 'Membership')],
]); ?>

<div class="page-header">
    <div><h1><?= __('membership', 'Membership') ?></h1></div>
    <a href="<?= e(plugin_url('membership', 'admin/plans.php')) ?>" class="btn btn-primary"><?= __('membership_manage_plans', 'Manage plans') ?></a>
</div>

<div class="dwidget-kpis" style="margin-bottom:var(--space-4);">
    <div class="dwidget-kpi">
        <div class="dwidget-kpi-k"><?= __('membership_active_members', 'Active memberships') ?></div>
        <div class="dwidget-kpi-v"><?= $active ?></div>
    </div>
    <div class="dwidget-kpi">
        <div class="dwidget-kpi-k"><?= __('membership_expiring_7', 'Expiring ≤7d') ?></div>
        <div class="dwidget-kpi-v"><?= $expiring ?></div>
    </div>
    <div class="dwidget-kpi">
        <div class="dwidget-kpi-k"><?= __('membership_members', 'Members') ?></div>
        <div class="dwidget-kpi-v"><?= $members ?></div>
    </div>
    <div class="dwidget-kpi">
        <div class="dwidget-kpi-k"><?= __('membership_active_plans', 'Active plans') ?></div>
        <div class="dwidget-kpi-v"><?= $plansActive ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2><?= __('membership_quick_links', 'Quick links') ?></h2></div>
    <div class="flex gap-2" style="flex-wrap:wrap;">
        <a href="<?= e(plugin_url('membership', 'admin/plans.php')) ?>" class="btn"><?= __('membership_plans', 'Plans') ?></a>
        <a href="<?= e(plugin_url('membership', 'admin/members.php')) ?>" class="btn"><?= __('membership_members', 'Members') ?></a>
        <a href="<?= e(plugin_url('membership', 'admin/settings.php')) ?>" class="btn"><?= __('membership_settings', 'Settings') ?></a>
    </div>
    <p class="text-sm text-muted" style="margin-top:var(--space-3);">
        <?= __('membership_phase_note', 'Member checkout, the onboarding wizard and the booking gate are configured in upcoming phases.') ?>
    </p>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
