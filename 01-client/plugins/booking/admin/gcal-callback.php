<?php
/**
 * Booking — Google's OAuth redirect target (`Authorized redirect URI` in
 * Booking settings must point here exactly).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';

Auth::require();
Auth::requirePerm('booking.manage_providers');
ModuleGuard::require('booking');

function bgc_back(int $providerId, array $params): void {
    $qs = http_build_query($params);
    header('Location: ' . plugin_url('booking', 'admin/providers.php') . '?edit=' . $providerId . ($qs !== '' ? '&' . $qs : ''));
    exit;
}

// Google returns `error` (e.g. access_denied) instead of `code` when the
// user declines consent on the Google side.
if (!empty($_GET['error'])) {
    $state = (string)($_GET['state'] ?? '');
    $nonce = (string)($_SESSION['booking_gcal_nonce'] ?? '');
    $pid   = $nonce !== '' ? (GoogleCalendarSync::decodeState($state, $nonce) ?? 0) : 0;
    unset($_SESSION['booking_gcal_nonce']);
    bgc_back($pid ?: 0, ['gcal_error' => __('booking_gcal_connection_cancelled', 'Connection cancelled.')]);
}

$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
$nonce = (string)($_SESSION['booking_gcal_nonce'] ?? '');
unset($_SESSION['booking_gcal_nonce']); // one-time use regardless of outcome

if ($code === '' || $nonce === '') {
    http_response_code(400);
    exit(__('booking_invalid_request', 'Invalid request.'));
}

$providerId = GoogleCalendarSync::decodeState($state, $nonce);
if ($providerId === null) {
    http_response_code(400);
    exit(__('booking_gcal_link_expired', 'This connection link has expired or was tampered with. Please try connecting again from the provider\'s profile.'));
}

$tid = current_tenant_id();
$provider = Database::row("SELECT id FROM booking_providers WHERE id = ? AND tenant_id = ?", [$providerId, $tid]);
if (!$provider) {
    http_response_code(404);
    exit(__('booking_provider_not_found', 'Provider not found.'));
}

$result = GoogleCalendarSync::connectProvider($providerId, $code);
if (!empty($result['ok'])) {
    bgc_back($providerId, ['gcal_connected' => (string)($result['email'] ?? '1')]);
}
bgc_back($providerId, ['gcal_error' => (string)($result['error'] ?? __('booking_gcal_generic_error', 'Something went wrong connecting to Google.'))]);
