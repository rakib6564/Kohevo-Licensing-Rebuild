<?php
/**
 * Booking — per-service rules list (glass UI).
 *
 * The rules edited here are the ones gateBookingRules() enforces:
 * minimum advance days, prerequisite service, prep page, WhatsApp and
 * the automatic post-booking response.
 *
 * Uses the shared slate_data_row() list widget for a consistent look
 * with core Booking / Membership lists.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingPlusAPI.php';

Auth::require();
Auth::requirePerm('booking.manage_services');
ModuleGuard::require('booking');

// The capability toggle in Booking → Settings must actually turn this page
// off, not just hide its nav link — a staff member with booking.manage_services
// could otherwise view/edit service rules via direct URL while the practice
// owner believes the feature is disabled. Same check, same 503, same position
// as booking/public/message.php's client_messaging guard.
if (!PluginLoader::isCapabilityEnabled('booking', 'service_rules')) {
    http_response_code(503);
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><div class="empty"><div class="empty-title">' . __('booking_msg_not_available_title', 'Not available') . '</div>'
       . '<p class="text-sm">' . __('booking_service_rule_disabled_body', 'Service rules are currently disabled. Enable them in Booking &rarr; Settings.') . '</p></div></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}

BookingPlusAPI::ensureSchema();

$pageTitle  = __('booking', 'Booking') . ' · ' . __('booking_service_rules', 'Service rules');
$currentNav = 'booking-service-rules';
$tid        = current_tenant_id();

$services = Database::rows(
    "SELECT s.id, s.name, s.duration_min, s.payment_mode, s.is_active, s.currency, s.price_cents,
            c.min_advance_days, c.prereq_service_id, c.auto_response_body,
            c.zoom_mode, c.zoom_join_url, c.prep_page_url
       FROM booking_services s
  LEFT JOIN bookingplus_service_config c
         ON c.service_id = s.id AND c.tenant_id = s.tenant_id
      WHERE s.tenant_id = ?
      ORDER BY s.sort_order, s.name",
    [$tid]
);

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'),   'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_services', 'Services')],
]);
?>

<div class="page-header">
    <div>
        <h1>Booking+ <?= __('booking_services', 'Services') ?></h1>
        <p class="text-muted"><?= __('booking_service_rule_list_subtitle', 'Per-service extras: preparation pages, HSR delays, prerequisites, Zoom, auto-responses.') ?></p>
    </div>
    <a href="<?= e(plugin_url('booking', 'admin/services.php')) ?>" class="btn btn-ghost"><?= __('booking_service_rule_edit_core_services', 'Edit core services') ?></a>
</div>

<?php if (!$services): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('booking_service_rule_no_services_title', 'No booking services yet') ?></div>
            <p class="text-sm"><?= sprintf(__('booking_service_rule_no_services_hint', 'Create services in %s first, then return here to add the extras.'), '<a href="' . e(plugin_url('booking', 'admin/services.php')) . '">' . __('booking', 'Booking') . ' · ' . __('booking_services', 'Services') . '</a>') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list" data-single-open>
    <?php foreach ($services as $s):
        $hasExtras = ((int)$s['min_advance_days'] > 0)
                  || !empty($s['prereq_service_id'])
                  || !empty(trim((string)$s['auto_response_body']))
                  || !empty($s['prep_page_url'])
                  || (!empty($s['zoom_join_url']) && $s['zoom_mode'] === 'manual');

        $prereqName = null;
        if (!empty($s['prereq_service_id'])) {
            $prereqName = (string) Database::value(
                "SELECT name FROM booking_services WHERE id = ? AND tenant_id = ?",
                [(int)$s['prereq_service_id'], $tid]
            );
        }

        $zoomLabel = __('booking_service_rule_zoom_fallback_short', 'Fallback message');
        if (($s['zoom_mode'] ?? '') === 'manual') {
            $zoomLabel = !empty($s['zoom_join_url']) ? __('booking_service_rule_zoom_manual_set', 'Manual (link set)') : __('booking_service_rule_zoom_manual_missing', 'Manual (link missing)');
        } elseif (($s['zoom_mode'] ?? '') === 'api') {
            $zoomLabel = __('booking_service_rule_zoom_api_short', 'API (Phase 1.5)');
        }

        $detail = [
            __('booking_service_rule_stat_duration', 'Duration')        => (int)$s['duration_min'] . ' ' . __('booking_service_rule_min_abbr', 'min'),
            __('booking_service_rule_stat_payment', 'Payment')         => (string)$s['payment_mode'],
            __('booking_service_rule_stat_min_advance', 'Min advance')     => (int)$s['min_advance_days'] > 0 ? sprintf(__('booking_service_rule_days_count', '%d days'), (int)$s['min_advance_days']) : __('booking_service_rule_no_restriction', 'no restriction'),
            __('booking_service_rule_stat_prereq', 'Prereq')          => $prereqName ?: __('booking_service_rule_none_value', 'none'),
            __('booking_service_rule_auto_response_label', 'Auto-response')   => !empty(trim((string)$s['auto_response_body'])) ? __('booking_service_rule_configured', 'configured') : __('booking_service_rule_not_set', 'not set'),
            __('booking_service_rule_zoom', 'Zoom')            => $zoomLabel,
        ];
        if (!empty($s['prep_page_url'])) {
            $detail['Prep page'] = ['label' => __('booking_service_rule_prep_page_detail_label', 'Prep page'), 'html' => '<a href="' . e($s['prep_page_url']) . '" target="_blank" rel="noopener">' . e($s['prep_page_url']) . '</a>'];
        }

        $editUrl = plugin_url('booking', 'admin/service-rule.php') . '?id=' . (int)$s['id'];
        $actions = '<a href="' . e($editUrl) . '" class="btn btn-sm btn-primary">' . e(__('booking_service_rule_edit_extras_btn', 'Edit extras')) . '</a>';

        slate_data_row([
            'avatar'       => mb_strtoupper(mb_substr($s['name'], 0, 1)),
            'avatar_color' => (int)$s['is_active'] ? 'info' : 'muted',
            'title'        => $s['name'],
            'meta'         => (int)$s['duration_min'] . ' ' . __('booking_service_rule_min_abbr', 'min') . ' · ' . e($s['payment_mode']),
            'badge'        => $hasExtras ? [__('booking_service_rule_extras_on_badge', 'Extras on'), 'active'] : [__('booking_service_rule_not_configured_badge', 'Not configured'), 'inactive'],
            'detail'       => $detail,
            'actions'      => $actions,
        ]);
    endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
