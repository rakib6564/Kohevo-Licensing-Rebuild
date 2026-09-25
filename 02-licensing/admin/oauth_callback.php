<?php
/**
 * Slate — SMTP OAuth 2.0 callback.
 *
 * The provider (Google / Microsoft) redirects here with ?code & ?state after
 * the admin grants consent. We verify the state against the nonce we stashed
 * in the session when the "Connect" button was clicked, exchange the code for
 * tokens, persist the (encrypted) refresh token, and flip SMTP into XOAUTH2
 * mode. The registered redirect URI must be exactly this URL.
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
Auth::requirePerm('settings.edit');

$backToSmtp = static function (string $type, string $msg): void {
    $_SESSION['slate_settings_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: ' . (defined('SLATE_URL') ? SLATE_URL : '') . '/admin/settings.php?tab=smtp');
    exit;
};

$pending = $_SESSION['slate_oauth_pending'] ?? null;
unset($_SESSION['slate_oauth_pending']); // single-use, regardless of outcome

// Provider-returned error (e.g. the admin clicked "Deny").
if (isset($_GET['error'])) {
    $backToSmtp('error', 'Authorization was cancelled or denied: '
        . htmlspecialchars((string)($_GET['error_description'] ?? $_GET['error']), ENT_QUOTES));
}

$code  = (string)($_GET['code']  ?? '');
$state = (string)($_GET['state'] ?? '');

if (!is_array($pending) || $code === '' || $state === '') {
    $backToSmtp('error', 'OAuth callback was missing data. Please start the connection again.');
}
// CSRF: the state must match the nonce we issued. hash_equals avoids timing leaks.
if (!hash_equals((string)($pending['nonce'] ?? ''), $state)) {
    $backToSmtp('error', 'OAuth state mismatch (possible CSRF). Please start the connection again.');
}

$provider     = (string)($pending['provider'] ?? '');
$clientId     = (string)($pending['client_id'] ?? '');
$clientSecret = (string)($pending['client_secret'] ?? '');
$email        = (string)($pending['email'] ?? '');
$cfg          = SmtpOAuth::config($provider);
if (!$cfg) {
    $backToSmtp('error', 'Unknown OAuth provider.');
}

$redirectUri = (defined('SLATE_URL') ? SLATE_URL : '') . '/admin/oauth_callback.php';
[$ok, $data] = SmtpOAuth::exchangeCode($provider, $clientId, $clientSecret, $code, $redirectUri);

if (!$ok) {
    if (class_exists('AuditLog')) AuditLog::record('smtp.oauth_connect', $provider, ['ok' => false]);
    $backToSmtp('error', 'Token exchange failed: ' . htmlspecialchars((string)($data['error'] ?? 'unknown'), ENT_QUOTES));
}

$refresh = (string)($data['refresh_token'] ?? '');
if ($refresh === '') {
    // Google only returns a refresh token on the first consent; prompt=consent
    // forces it, but a stale prior grant can still suppress it.
    $backToSmtp('error', 'No refresh token was returned. For Google, remove this app at '
        . 'myaccount.google.com/permissions and connect again so it re-prompts for consent.');
}

$enc = function_exists('slate_encrypt_secret');

// Persist the connection. Client id/secret were already saved by the connect
// action; re-save here so a connection is always self-consistent.
Database::setSetting('smtp_auth_type',        'xoauth2');
Database::setSetting('smtp_oauth_provider',   $provider);
Database::setSetting('smtp_oauth_email',      $email);
Database::setSetting('smtp_oauth_client_id',  $clientId);
Database::setSetting('smtp_oauth_client_secret', $enc ? slate_encrypt_secret($clientSecret) : $clientSecret);
Database::setSetting('smtp_oauth_refresh_token', $enc ? slate_encrypt_secret($refresh) : $refresh);

// Cache the access token we just received so the first send doesn't re-refresh.
$accessToken = (string)($data['access_token'] ?? '');
Database::setSetting('smtp_oauth_access_token', $enc ? slate_encrypt_secret($accessToken) : $accessToken);
Database::setSetting('smtp_oauth_expires', (string)(time() + (int)($data['expires_in'] ?? 3600)));

// Point SMTP transport at the provider and use the mailbox as the From/user.
Database::setSetting('smtp_host',       (string)$cfg['smtp_host']);
Database::setSetting('smtp_port',       (string)$cfg['smtp_port']);
Database::setSetting('smtp_encryption', (string)$cfg['smtp_enc']);
Database::setSetting('smtp_user',       $email);
if ((string)Database::setting('smtp_from_email') === '') {
    Database::setSetting('smtp_from_email', $email);
}

if (class_exists('AuditLog')) AuditLog::record('smtp.oauth_connect', $provider, ['ok' => true, 'email' => $email]);

$backToSmtp('success', '✓ Connected ' . htmlspecialchars($email, ENT_QUOTES) . ' via '
    . htmlspecialchars((string)$cfg['label'], ENT_QUOTES) . '. Run “Verify connection” to confirm sending works.');
