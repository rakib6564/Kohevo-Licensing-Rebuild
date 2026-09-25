<?php
/**
 * Booking — disconnect a provider's Google Calendar. POST only.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.manage_providers');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(__('booking_method_not_allowed', 'Method not allowed.'));
}

$providerId = (int)($_POST['provider_id'] ?? 0);
$tid = current_tenant_id();

if (!csrf_verify()) {
    header('Location: ' . plugin_url('booking', 'admin/providers.php') . '?edit=' . $providerId . '&gcal_error=' . urlencode(__('csrf_failed', 'Security check failed.')));
    exit;
}

$provider = $providerId > 0
    ? Database::row("SELECT id FROM booking_providers WHERE id = ? AND tenant_id = ?", [$providerId, $tid])
    : null;

if ($provider) {
    GoogleCalendarSync::disconnectProvider($providerId);
}

header('Location: ' . plugin_url('booking', 'admin/providers.php') . '?edit=' . $providerId . '&gcal_disconnected=1');
exit;
