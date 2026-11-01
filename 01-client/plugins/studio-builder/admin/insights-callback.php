<?php
/**
 * Kohevo Studio — Site Insights: where Google sends the browser back after sign-in. The address is listed on the OAuth client.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_insights.php';

use Slate\Module\StudioBuilder\Insights\GoogleApiException;
use Slate\Module\StudioBuilder\Insights\GoogleConnection;

Auth::require();
Auth::requirePerm('studio-builder.view');

$back = 'insights.php?tab=settings';
if (!(Auth::can('studio-builder.admin') || Auth::isSuperAdmin()) || !sb_studio_entitled()) {
    sb_flash_redirect('insights.php', ['type' => 'error', 'msg' => __('studio_insights_admin_only', 'Only a Studio administrator can connect Google.')]);
}

// Each sign-in is answered once.
$attempt = $_SESSION['sb_google_attempt'] ?? null;
unset($_SESSION['sb_google_attempt']);
if (!GoogleConnection::attemptMatches($attempt, is_string($_GET['state'] ?? null) ? $_GET['state'] : '', time())) {
    sb_flash_redirect($back, ['type' => 'error', 'msg' => __('studio_insights_state_failed', 'That sign-in could not be verified, so nothing was saved. Connect again from this screen.')]);
}
// A sign-in started through Central comes back with a handoff, one with the site's own client with a code.
$central = ($attempt['mode'] ?? '') === GoogleConnection::MODE_CENTRAL;
$proof = $_GET[$central ? 'handoff' : 'code'] ?? null;
if (isset($_GET['error']) || !is_string($proof) || $proof === '') {
    sb_flash_redirect($back, ['type' => 'warning', 'msg' => __('studio_insights_cancelled', 'Google did not grant access, so nothing was changed.')]);
}

$tenantId = current_tenant_id();
$connection = sb_insights_connection($tenantId);
try {
    $central ? $connection->connectWithHandoff($proof) : $connection->connect($proof, (string) $attempt['verifier']);
} catch (GoogleApiException $e) {
    error_log('[studio-builder] site insights: ' . $e->getMessage());
    sb_flash_redirect($back, ['type' => 'error', 'msg' => $e->reason === GoogleApiException::NOT_SECURE
        ? __('studio_insights_no_secret', 'APP_SECRET is not set on this site, so Google credentials cannot be stored safely.')
        : __('studio_insights_connect_failed', 'Google did not confirm the connection. Check the Client ID, the secret and the redirect address, then try again.')]);
}
AuditLog::record('studio.google_connected', (string) $tenantId, ['email' => $connection->email()]);
$partial = !$connection->granted(GoogleConnection::SCOPE_SEARCH) || !$connection->granted(GoogleConnection::SCOPE_ANALYTICS);
if ($partial) {
    sb_flash_redirect($back, ['type' => 'warning', 'msg' => __('studio_insights_partial', 'Connected, but Google did not grant every permission. Connect again and tick both Search Console and Analytics.')]);
}
try {
    if (sb_insights_auto_choose($tenantId)) {
        sb_flash_redirect('insights.php', ['type' => 'success', 'msg' => __('studio_insights_auto_chosen', 'Connected to Google. Your properties were matched to this site; you can change them in Settings.')]);
    }
} catch (GoogleApiException $e) {
    error_log('[studio-builder] site insights: ' . $e->getMessage());
}
sb_flash_redirect($back, ['type' => 'success', 'msg' => __('studio_insights_connected', 'Connected to Google. Now choose your properties.')]);
