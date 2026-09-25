<?php
/**
 * Coaching — Program clients roster.
 *
 * Full list of enrolled clients. Wave 1: read-only overview with profile
 * KPIs. Per-client detail page + edit lands in a later wave.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';
require_once dirname(__DIR__) . '/includes/assets.php';

Auth::require();
Auth::requirePerm('coaching.view_clients');
CoachingAPI::ensureSchema();

$pageTitle  = __('cc_coaching_title', 'Coaching') . ' · ' . __('cc_program_clients', 'Program clients');
$currentNav = 'coaching-clients';

$clients = CoachingAPI::listEnrolledClients();

require SLATE_ROOT . '/admin/partials/header.php';

// The plugin's design, emitted by the page rather than relying on a boot-time
// enqueue that never runs while coaching is inactive.
coaching_emit_css('admin.css');

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cc_coaching_title', 'Coaching'),  'href' => plugin_url('coaching', 'admin/index.php')],
    ['label' => __('cc_program_clients', 'Program clients')],
]);
?>

<div class="page-header">
    <div>
        <h1><?= __('cc_program_clients', 'Program clients') ?></h1>
        <p class="text-muted"><?= __('cc_clients_page_sub', 'Everyone currently enrolled in the Body &amp; Soul Program.') ?></p>
    </div>
</div>

<?php if (!$clients): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('cc_no_enrolled_clients_title', 'No enrolled clients') ?></div>
            <p class="text-sm">
                <?= __('cc_enrolled_definition', 'A client is "enrolled" when they hold an active membership matching the program plan.') ?>
                <?= __('cc_configure_gate_prefix', 'Configure the gate in') ?> <a href="<?= e(plugin_url('coaching', 'admin/settings.php')) ?>"><?= __('cc_settings_link', 'Coaching settings') ?></a>,
                <?= __('cc_configure_gate_suffix', 'then sell a plan via the Membership plugin.') ?>
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list" data-single-open>
    <?php foreach ($clients as $c):
        $profile = CoachingAPI::getProfile((int)$c['id']);
        $goals   = CoachingAPI::listGoals((int)$c['id'], null, true);
        $detail  = [
            'Email'   => ['label' => __('email', 'Email'), 'html' => '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>'],
            __('cc_height', 'Height')  => !empty($profile['height_cm']) ? number_format((float)$profile['height_cm'], 1) . ' cm' : '—',
            __('cc_weight', 'Weight')  => !empty($profile['weight_kg']) ? number_format((float)$profile['weight_kg'], 1) . ' kg' : '—',
            __('cc_bmi_label', 'BMI')     => !empty($profile['bmi'])       ? number_format((float)$profile['bmi'], 1)              : '—',
            __('cc_bmr_label', 'BMR')     => !empty($profile['bmr'])       ? (int)$profile['bmr'] . ' kcal/day'                    : '—',
            __('cc_tdee_label', 'TDEE')    => !empty($profile['tdee'])      ? (int)$profile['tdee'] . ' kcal/day'                   : '—',
            __('cc_goals', 'Goals')   => sprintf(__('cc_goals_active_count', '%d active'), count($goals)),
            __('cc_profile_updated', 'Profile updated') => !empty($c['profile_updated_at']) ? I18n::localDate('j M Y', strtotime($c['profile_updated_at'])) : '—',
        ];
        $profileFilled = !empty($profile['height_cm']) && !empty($profile['weight_kg']) && !empty($profile['dob']);
        $badge = $profileFilled ? [__('cc_profile_filled', 'Profile filled'), 'active'] : [__('cc_profile_incomplete', 'Profile incomplete'), 'inactive'];

        $actions = '<a href="' . e(plugin_url('coaching', 'admin/client.php')) . '?id=' . (int)$c['id'] . '" class="btn btn-sm btn-primary">' . e(__('cc_open', 'Open')) . '</a>';
        slate_data_row([
            'avatar_html'  => slate_avatar_overlay_html(mb_strtoupper(mb_substr($c['name'], 0, 1)), (string)($c['email'] ?? '')),
            'avatar_color' => $profileFilled ? 'info' : 'muted',
            'title'        => $c['name'],
            'meta'         => e($c['email']),
            'badge'        => $badge,
            'detail'       => $detail,
            'actions'      => $actions,
        ]);
    endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
