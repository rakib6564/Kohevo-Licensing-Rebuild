<?php
/**
 * Booking — start the Google Calendar OAuth flow for one provider.
 *
 * GET only (this is a plain link from admin/providers.php, not a form —
 * there's nothing to CSRF-protect on the way OUT, only on the way back in,
 * which gcal-callback.php verifies via the signed state nonce below).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.manage_providers');
ModuleGuard::require('booking');

$providerId = (int)($_GET['provider_id'] ?? 0);
$tid = current_tenant_id();
$provider = $providerId > 0
    ? Database::row("SELECT id FROM booking_providers WHERE id = ? AND tenant_id = ?", [$providerId, $tid])
    : null;

if (!$provider) {
    http_response_code(404);
    exit(__('booking_provider_not_found', 'Provider not found.'));
}

if (!GoogleCalendarSync::isConfigured()) {
    header('Location: ' . plugin_url('booking', 'admin/providers.php') . '?edit=' . $providerId . '&gcal_error=' . urlencode(__('booking_gcal_missing_credentials', 'Add a Google Client ID/Secret in Booking settings first.')));
    exit;
}

// A one-time nonce, bound to this session, carried through Google's
// redirect inside the `state` param and checked in gcal-callback.php —
// this is what stops a forged callback from connecting a provider the
// visitor didn't ask to connect.
$nonce = bin2hex(random_bytes(16));
$_SESSION['booking_gcal_nonce'] = $nonce;

header('Location: ' . GoogleCalendarSync::authUrl($providerId, $nonce));
exit;
