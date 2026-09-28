<?php
/**
 * Coaching — admin overview.
 *
 * KPI snapshot + link into the full roster / settings.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';
require_once dirname(__DIR__) . '/includes/assets.php';

Auth::require();
if (!Auth::can('coaching.view_clients')
    && !Auth::can('coaching.manage_clients')
    && !Auth::isSuperAdmin()) {
    Auth::requirePerm('coaching.view_clients');
}
CoachingAPI::ensureSchema();

$pageTitle  = __('cc_coaching_title', 'Coaching');
$currentNav = 'coaching';
$tid        = current_tenant_id();

$clients      = CoachingAPI::listEnrolledClients();
$totalClients = count($clients);
$profiles     = (int) Database::value("SELECT COUNT(*) FROM coaching_profile   WHERE tenant_id = ?", [$tid]);
$goals        = (int) Database::value("SELECT COUNT(*) FROM coaching_goal      WHERE tenant_id = ? AND is_active = 1", [$tid]);
$checkins     = (int) Database::value("SELECT COUNT(*) FROM coaching_goal_checkin WHERE tenant_id = ? AND day = CURDATE()", [$tid]);
$planId       = (int) (Database::setting('coaching.program_plan_id') ?? 0);
$planRow      = $planId > 0 && class_exists('MembershipAPI')
    ? Database::row("SELECT name FROM membership_plans WHERE id = ?", [$planId])
    : null;

require SLATE_ROOT . '/admin/partials/header.php';

// The plugin's design, emitted by the page rather than relying on a boot-time
// enqueue that never runs while coaching is inactive.
coaching_emit_css('admin.css');

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cc_coaching_title', 'Coaching')],
]);
?>

<div class="page-header">
    <div>
        <h1><?= __('cc_coaching_title', 'Coaching') ?></h1>
        <p class="text-muted"><?= __('cc_admin_overview_sub', 'The 3-month Body &amp; Soul Program surface. Clients here are gated on their membership.') ?></p>
    </div>
    <div class="toolbar">
        <a href="<?= e(plugin_url('coaching', 'admin/clients.php')) ?>" class="btn btn-secondary"><?= __('cc_program_clients', 'Program clients') ?></a>
        <a href="<?= e(plugin_url('coaching', 'admin/settings.php')) ?>" class="btn btn-primary"><?= __('settings', 'Settings') ?></a>
    </div>
</div>

<?php if (!$planRow): ?>
    <div class="alert alert-warning" style="margin-bottom:var(--space-4);">
        <strong><?= __('cc_setup_incomplete', 'Setup incomplete:') ?></strong> <?= __('cc_setup_incomplete_body', 'no membership plan is wired as the program gate yet.') ?>
        <a href="<?= e(plugin_url('coaching', 'admin/settings.php')) ?>"><?= __('cc_open_settings_link', 'Open Coaching settings') ?></a> <?= __('cc_pick_one', 'to pick one.') ?>
        <?= __('cc_setup_incomplete_note', 'Until then, no client will be marked "enrolled" on this dashboard.') ?>
    </div>
<?php endif; ?>

<div class="kpi-strip" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-3);margin-bottom:var(--space-4);">
    <div class="card kpi-card"><div class="kpi-label"><?= __('cc_kpi_enrolled_clients', 'Enrolled clients') ?></div><div class="kpi-value"><?= $totalClients ?></div></div>
    <div class="card kpi-card"><div class="kpi-label"><?= __('cc_kpi_profiles_on_file', 'Profiles on file') ?></div><div class="kpi-value"><?= $profiles ?></div></div>
    <div class="card kpi-card"><div class="kpi-label"><?= __('cc_kpi_active_goals', 'Active goals') ?></div><div class="kpi-value"><?= $goals ?></div></div>
    <div class="card kpi-card"><div class="kpi-label"><?= __('cc_kpi_checkins_today', 'Check-ins today') ?></div><div class="kpi-value"><?= $checkins ?></div></div>
</div>

<div class="card">
    <div style="padding:var(--space-3) var(--space-4);border-bottom:1px solid var(--border);">
        <strong><?= __('cc_currently_enrolled', 'Currently enrolled') ?></strong>
        <span class="text-muted" style="margin-left:var(--space-2);"><?= __('cc_wave1_note', 'Wave 1 · profile + goals foundation. Diary + chat land in later waves.') ?></span>
    </div>
    <?php if (!$clients): ?>
        <div class="empty">
            <div class="empty-title"><?= __('cc_no_clients_title', 'No clients enrolled yet') ?></div>
            <p class="text-sm"><?= __('cc_no_clients_hint', 'Sell a "Body &amp; Soul Program" membership plan to a customer, then they appear here.') ?></p>
        </div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach (array_slice($clients, 0, 10) as $c):
                if (!empty($c['bmi'])) {
                    $profile = sprintf(__('cc_bmi_value', 'BMI %s'), number_format((float)$c['bmi'], 1));
                } elseif (!empty($c['profile_updated_at'])) {
                    $profile = __('cc_profile_partial', 'Partial');
                } else {
                    $profile = __('cc_profile_empty', 'Empty');
                }

                ob_start(); ?>
                <a href="<?= e(plugin_url('coaching', 'admin/client.php')) ?>?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-secondary"><?= __('cc_open', 'Open') ?></a>
                <?php $rowActions = ob_get_clean();

                slate_data_row([
                    'avatar_html'  => slate_avatar_overlay_html(mb_strtoupper(mb_substr((string)$c['name'], 0, 2)), (string)($c['email'] ?? '')),
                    'avatar_color' => 'accent',
                    'title'        => (string)$c['name'],
                    'meta'         => (string)$c['email'],
                    'value'        => $profile,
                    'detail'       => [
                        __('email', 'Email')                 => (string)$c['email'],
                        __('cc_profile_label', 'Profile')     => $profile,
                    ],
                    'actions'      => $rowActions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
        <?php if ($totalClients > 10): ?>
            <div style="padding:var(--space-3) var(--space-4);text-align:center;">
                <a href="<?= e(plugin_url('coaching', 'admin/clients.php')) ?>"><?= sprintf(__('cc_see_all_clients', 'See all %d clients →'), $totalClients) ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
