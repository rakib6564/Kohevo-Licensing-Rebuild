<?php
/**
 * Backups — Google's OAuth redirect target. The "Authorized redirect URI"
 * configured in the Google Cloud OAuth client must point here exactly:
 *   <site>/plugins/backups/admin/gdrive-callback.php
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/GoogleDriveClient.php';

Auth::require();
if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    echo '<h1>' . e(__('backups_forbidden_title', '403 Forbidden')) . '</h1><p>' . e(__('only_super_admin', 'Super Admin only.')) . '</p>';
    exit;
}

function bgd_back(array $params): void {
    $qs = http_build_query($params);
    header('Location: ' . plugin_url('backups', 'admin/settings.php') . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// Google returns `error` (e.g. access_denied) instead of `code` when the
// admin declines consent on Google's side.
if (!empty($_GET['error'])) {
    unset($_SESSION['backups_gdrive_nonce']);
    bgd_back(['gdrive_error' => __('backups_connection_cancelled', 'Connection cancelled.')]);
}

$code  = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
$nonce = (string)($_SESSION['backups_gdrive_nonce'] ?? '');
unset($_SESSION['backups_gdrive_nonce']); // one-time use regardless of outcome

if ($code === '' || $nonce === '' || !hash_equals($nonce, $state)) {
    http_response_code(400);
    exit(__('backups_link_expired', 'This connection link has expired or was tampered with. Please try connecting again from Backups settings.'));
}

$result = GoogleDriveClient::connect($code);
if (!empty($result['ok'])) {
    bgd_back(['gdrive_connected' => (string)($result['email'] ?? '1')]);
}
bgd_back(['gdrive_error' => (string)($result['error'] ?? __('backups_gdrive_generic_error', 'Something went wrong connecting to Google.'))]);
