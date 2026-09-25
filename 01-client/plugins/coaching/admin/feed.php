<?php
/**
 * Coaching — Client feed.
 *
 * Live-ish feed of the most recent diary entries across all program
 * clients. Wave 2 uses simple pagination on `created_at DESC`; a
 * future wave can layer scheduled-message deliveries + goal check-ins
 * into the same stream.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/CoachingAPI.php';
require_once dirname(__DIR__) . '/includes/assets.php';

Auth::require();
Auth::requirePerm('coaching.view_clients');
CoachingAPI::ensureSchema();

$pageTitle  = __('cc_coaching_title', 'Coaching') . ' · ' . __('cc_client_feed', 'Client feed');
$currentNav = 'coaching-feed';

$filterClient = (int)($_GET['client'] ?? 0);
if ($filterClient > 0) {
    $tid = current_tenant_id();
    $entries = Database::rows(
        "SELECT e.id, e.customer_id, e.day, e.meal_type, e.emotion, e.summary, e.created_at,
                c.name AS customer_name, c.email AS customer_email
           FROM coaching_diary_entry e
           JOIN customers c ON c.id = e.customer_id
          WHERE e.tenant_id = ? AND e.customer_id = ?
          ORDER BY e.created_at DESC LIMIT 100",
        [$tid, $filterClient]);
} else {
    $entries = CoachingAPI::recentEntriesAcrossClients(50);
}

require SLATE_ROOT . '/admin/partials/header.php';

// The plugin's design, emitted by the page rather than relying on a boot-time
// enqueue that never runs while coaching is inactive.
coaching_emit_css('admin.css');

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cc_coaching_title', 'Coaching'),  'href' => plugin_url('coaching', 'admin/index.php')],
    ['label' => __('cc_client_feed', 'Client feed')],
]);

$mealTypeLabels = [
    'breakfast' => __('cc_meal_breakfast', 'Breakfast'),
    'lunch'     => __('cc_meal_lunch', 'Lunch'),
    'dinner'    => __('cc_meal_dinner', 'Dinner'),
    'snack'     => __('cc_meal_snack', 'Snack'),
    'binge'     => __('cc_meal_binge', 'Binge'),
    'drink'     => __('cc_meal_drink', 'Drink'),
    'other'     => __('cc_meal_other', 'Other'),
];
$emojiMap = ['breakfast'=>slate_admin_nav_icon('egg'),'lunch'=>slate_admin_nav_icon('salad'),'dinner'=>slate_admin_nav_icon('plate'),'snack'=>slate_admin_nav_icon('cookie'),'binge'=>slate_admin_nav_icon('stack'),'drink'=>slate_admin_nav_icon('coffee'),'other'=>slate_admin_nav_icon('utensils')];
$emotions = CoachingAPI::emotions();
?>

<div class="page-header">
    <div>
        <h1><?= __('cc_client_feed', 'Client feed') ?><?php if ($filterClient > 0 && $entries): ?> · <?= e($entries[0]['customer_name']) ?><?php endif; ?></h1>
        <p class="text-muted"><?= $filterClient > 0 ? __('cc_feed_sub_filtered', 'Diary entries for this client.') : __('cc_feed_sub_all', 'Latest diary entries across everyone in the program. Newest first.') ?></p>
    </div>
    <?php if ($filterClient > 0): ?>
        <a href="<?= e(plugin_url('coaching', 'admin/feed.php')) ?>" class="btn btn-ghost"><?= __('cc_all_clients', 'All clients') ?></a>
    <?php endif; ?>
</div>

<?php if (!$entries): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('cc_no_entries_title', 'No entries yet') ?></div>
            <p class="text-sm"><?= __('cc_no_entries_hint', 'This fills up as your clients start logging meals.') ?></p>
        </div>
    </div>
<?php else: ?>
    <?php
    // Uses the same shared data-row component as Sessions & devices
    // (includes/ui_components.php) instead of a bespoke <table>, so the
    // feed gets the same responsive card-list behaviour (a plain <table>
    // doesn't reflow on mobile) and the same expand-for-detail interaction
    // for free, and stays visually consistent with the rest of the admin.
    ?>
    <div class="data-list" data-single-open>
        <?php foreach ($entries as $e):
            $icon = $emojiMap[$e['meal_type']] ?? slate_admin_nav_icon('utensils');
            $emotionLabel = $e['emotion'] ? ($emotions[$e['emotion']] ?? $e['emotion']) : '';
            $ago = time() - strtotime((string)$e['created_at']);
            if ($ago < 60)   $agoLabel = __('cc_just_now', 'just now');
            elseif ($ago < 3600)   $agoLabel = sprintf(__('cc_minutes_ago', '%dm ago'), floor($ago/60));
            elseif ($ago < 86400)  $agoLabel = sprintf(__('cc_hours_ago', '%dh ago'), floor($ago/3600));
            else                   $agoLabel = I18n::localDate('j M, H:i', strtotime((string)$e['created_at']));

            $mealDay = strtotime((string) $e['day']);
            $initials = mb_strtoupper(mb_substr((string)$e['customer_name'], 0, 2));
            $mealTypeLabel = $mealTypeLabels[$e['meal_type']] ?? ucfirst((string)$e['meal_type']);

            $detail = [
                __('cc_foods', 'Foods')  => !empty($e['summary']) ? (string)$e['summary'] : '—',
                __('cc_logged', 'Logged') => [
                    'label' => __('cc_logged', 'Logged'),
                    'value' => ($mealDay ? I18n::localDate('j M Y', $mealDay) : '—') . ' · ' . $agoLabel,
                ],
                __('email', 'Email') => (string)$e['customer_email'],
            ];

            ob_start(); ?>
                <a class="btn btn-sm btn-ghost"
                   href="<?= e(plugin_url('coaching', 'admin/feed.php') . '?client=' . (int)$e['customer_id']) ?>">
                    <?= __('cc_all_entries_for_client', 'All entries for this client') ?>
                </a>
            <?php $actions = ob_get_clean();

            slate_data_row([
                'avatar'       => $initials,
                'avatar_html'  => '<span style="font-size:18px;">' . $icon . '</span>',
                'title'        => (string)$e['customer_name'],
                'meta'         => $mealTypeLabel . ($emotionLabel ? ' · ' . $emotionLabel : ''),
                'badge'        => $emotionLabel ? [$emotionLabel, 'accent'] : null,
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
