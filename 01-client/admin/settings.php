<?php
/**
 * Slate — Settings.
 *
 * Tabbed settings page. Tabs: Profile, SMTP, Security, Branding, System.
 * Settings are stored in the `settings` table (key/value). Each tab's
 * form has its own Save button for clean partial saves.
 *
 * Tab is selected via ?tab=<slug> (GET) and preserved on POST via hidden
 * input so the redirect lands back on the same tab.
 *
 * SMTP password is encrypted via slate_encrypt_secret().
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
Auth::requirePerm('settings.view');

$canEdit    = Auth::can('settings.edit') || Auth::isSuperAdmin();
$pageTitle  = __('settings', 'Settings');
$currentNav = 'settings';

// ─── Tab definitions ─────────────────────────────────────────
$tabs = [
    'general'    => ['label' => __('general',    'General'),     'icon' => 'settings'],
    'permalinks' => ['label' => __('permalinks', 'Permalinks'),  'icon' => 'link'],
    'datetime'   => ['label' => __('datetime',   'Date & Time'), 'icon' => 'clock'],
    'branding'   => ['label' => __('branding',   'Branding'),    'icon' => 'palette'],
    'media'      => ['label' => __('media',      'Media'),       'icon' => 'film'],
    'smtp'       => ['label' => __('smtp',       'SMTP'),        'icon' => 'mail'],
    'security'   => ['label' => __('security',   'Security'),    'icon' => 'shield'],
    'landing'    => ['label' => __('landing',    'Landing'),     'icon' => 'home'],
    'system'     => ['label' => __('system',     'System'),      'icon' => 'sliders'],
];

// Determine active tab — from POST (hidden field), then GET, then default.
$activeTab = 'general';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_tab'])) {
    $activeTab = $_POST['_tab'];
} elseif (isset($_GET['tab'])) {
    $activeTab = $_GET['tab'];
}
if ($activeTab === 'profile') $activeTab = 'general';

// 'translations' appears in the shared tab strip (slate_render_settings_tabs())
// but its content lives on its own page, not as a block in this file --
// send anyone who lands on ?tab=translations here (an old bookmark, a
// stale link) to where the content actually is, instead of a blank tab.
if ($activeTab === 'translations') {
    header('Location: ' . SLATE_URL . '/plugins/multilang-translate/admin/index.php');
    exit;
}

if (!array_key_exists($activeTab, $tabs)) $activeTab = 'general';

// ─── Session flash (Post-Redirect-Get pattern) ────────────────
// After a successful save we redirect so a browser Back/Reload never
// re-submits the form. The flash message survives in $_SESSION.
$flash = null;
if (isset($_SESSION['slate_settings_flash'])) {
    $flash = $_SESSION['slate_settings_flash'];
    unset($_SESSION['slate_settings_flash']);
}

// Helper: redirect back to the same tab with flash stored in session.
$redirectWithFlash = static function (array $f, string $tab) {
    $_SESSION['slate_settings_flash'] = $f;
    $url = (defined('SLATE_URL') ? SLATE_URL : '') . '/admin/settings.php?tab=' . urlencode($tab);
    header('Location: ' . $url);
    exit;
};

// ─── POST handler ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to edit settings.')];
    } else {
        $action = $_POST['_action'] ?? '';

        // ── Profile tab ──────────────────────────────────────
        if ($action === 'save_general') {
            $name = (string)($_POST['site_name'] ?? '');
            // Strip control characters, ALL html-like substrings (including
            // malformed/incomplete tags such as "<script defer src="),
            // then normalise whitespace.
            $name = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/', '', $name) ?? '';
            $name = preg_replace('/<[^>]*>?/', '', $name) ?? ''; // strip complete & incomplete tags
            $name = strip_tags($name);
            $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
            $lang = trim((string)($_POST['default_language'] ?? 'en'));
            if (!preg_match('/^[a-zA-Z_-]{2,16}$/', $lang)) $lang = 'en';
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
            if (!preg_match('/^[A-Z]{3,8}$/', $currency)) $currency = 'USD';
            $currencyPosition = ((string)($_POST['currency_position'] ?? 'before')) === 'after' ? 'after' : 'before';
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => __('site_name_required', 'Site name is required.')];
            } else {
                Database::setSetting('site_name', mb_substr($name, 0, 120));
                Database::setSetting('default_language', preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en');
                Database::setSetting('currency_position', $currencyPosition);
                Database::setSetting('currency', $currency);
                AuditLog::record('settings.updated', 'general');
                $redirectWithFlash(['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')], $activeTab);
            }
        }

        elseif ($action === 'save_business') {
            Database::setSetting('business_name',    mb_substr(trim((string)($_POST['business_name']    ?? '')), 0, 190));
            Database::setSetting('business_email',   mb_substr(trim((string)($_POST['business_email']   ?? '')), 0, 190));
            Database::setSetting('business_phone',   mb_substr(trim((string)($_POST['business_phone']   ?? '')), 0, 64));
            Database::setSetting('business_address', mb_substr(trim((string)($_POST['business_address'] ?? '')), 0, 500));
            Database::setSetting('business_hours',   mb_substr(trim((string)($_POST['business_hours']   ?? '')), 0, 500));
            AuditLog::record('settings.updated', 'business');
            $redirectWithFlash(['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')], $activeTab);
        }

        // ── Permalinks tab ────────────────────────────────────
        elseif ($action === 'save_permalinks') {
            $struct = trim((string)($_POST['permalink_structure'] ?? '/p/%postname%'));
            $postBase = trim((string)($_POST['permalink_post_base'] ?? 'post'));
            $postBase = preg_replace('/[^a-z0-9_-]/i', '', $postBase) ?: 'post';
            $trailing = isset($_POST['permalink_trailing_slash']) ? '1' : '0';

            Database::setSetting('permalink_structure', mb_substr($struct, 0, 100));
            Database::setSetting('permalink_post_base', mb_substr($postBase, 0, 50));
            Database::setSetting('permalink_trailing_slash', $trailing);
            AuditLog::record('settings.updated', 'permalinks');
            $redirectWithFlash(['type' => 'success', 'msg' => __('settings_saved', 'Permalinks settings saved.')], $activeTab);
        }

        // ── Date & Time tab ───────────────────────────────────
        elseif ($action === 'save_datetime') {
            $tz = trim((string)($_POST['timezone'] ?? 'UTC'));
            if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
                $tz = 'UTC';
            }
            $dateFormat = trim((string)($_POST['date_format'] ?? 'F j, Y'));
            if ($dateFormat === 'custom') {
                $dateFormat = trim((string)($_POST['date_format_custom'] ?? 'Y-m-d'));
            }
            $timeFormat = trim((string)($_POST['time_format'] ?? 'g:i a'));
            if ($timeFormat === 'custom') {
                $timeFormat = trim((string)($_POST['time_format_custom'] ?? 'H:i'));
            }
            $startOfWeek = (int)($_POST['start_of_week'] ?? 1);

            Database::setSetting('timezone', mb_substr($tz, 0, 64));
            Database::setSetting('date_format', mb_substr($dateFormat, 0, 32));
            Database::setSetting('time_format', mb_substr($timeFormat, 0, 32));
            Database::setSetting('start_of_week', (string)$startOfWeek);
            AuditLog::record('settings.updated', 'datetime');
            $redirectWithFlash(['type' => 'success', 'msg' => __('settings_saved', 'Date and time settings saved.')], $activeTab);
        }

        // ── Media Settings tab ────────────────────────────────
        elseif ($action === 'save_media_settings') {
            $thumbW = max(10, min(2000, (int)($_POST['media_thumb_w'] ?? 150)));
            $thumbH = max(10, min(2000, (int)($_POST['media_thumb_h'] ?? 150)));
            $mediumW = max(10, min(4000, (int)($_POST['media_medium_w'] ?? 300)));
            $mediumH = max(10, min(4000, (int)($_POST['media_medium_h'] ?? 300)));
            $largeW = max(10, min(8000, (int)($_POST['media_large_w'] ?? 1024)));
            $largeH = max(10, min(8000, (int)($_POST['media_large_h'] ?? 1024)));
            $webp = isset($_POST['media_webp_convert']) ? '1' : '0';
            $maxMb = max(1, min(500, (int)($_POST['media_max_upload_mb'] ?? 20)));

            Database::setSetting('media_thumb_w', (string)$thumbW);
            Database::setSetting('media_thumb_h', (string)$thumbH);
            Database::setSetting('media_medium_w', (string)$mediumW);
            Database::setSetting('media_medium_h', (string)$mediumH);
            Database::setSetting('media_large_w', (string)$largeW);
            Database::setSetting('media_large_h', (string)$largeH);
            Database::setSetting('media_webp_convert', $webp);
            Database::setSetting('media_max_upload_mb', (string)$maxMb);
            AuditLog::record('settings.updated', 'media');
            $redirectWithFlash(['type' => 'success', 'msg' => __('settings_saved', 'Media settings saved.')], $activeTab);
        }

        // ── SMTP tab ─────────────────────────────────────────
        elseif ($action === 'save_smtp') {
            $host      = trim((string)($_POST['smtp_host'] ?? ''));
            $port      = (int)($_POST['smtp_port'] ?? 0);
            $user      = trim((string)($_POST['smtp_user'] ?? ''));
            $pass      = (string)($_POST['smtp_pass'] ?? '');
            $enc       = (string)($_POST['smtp_encryption'] ?? 'tls');
            $fromName  = trim((string)($_POST['smtp_from_name']  ?? ''));
            $fromEmail = trim((string)($_POST['smtp_from_email'] ?? ''));
            $clearPass = isset($_POST['clear_smtp_pass']) && $_POST['clear_smtp_pass'] === '1';
            // OAuth fields — only present when the unified setup form is in
            // Microsoft 365 mode. Saved into the same OAuth setting keys the
            // separate connect form used to write, so the Connect button can
            // pick them up without the admin having to re-enter anything.
            $oauthClientId  = trim((string)($_POST['oauth_client_id'] ?? ''));
            $oauthSecretIn  = (string)($_POST['oauth_client_secret'] ?? '');
            $oauthMailbox   = trim((string)($_POST['oauth_email'] ?? ''));

            if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'error', 'msg' => __('from_email_invalid', 'From email is not a valid address.')];
            } elseif ($port !== 0 && ($port < 1 || $port > 65535)) {
                $flash = ['type' => 'error', 'msg' => __('port_invalid', 'SMTP port must be between 1 and 65535.')];
            } elseif (!in_array($enc, ['none', 'tls', 'ssl'], true)) {
                $flash = ['type' => 'error', 'msg' => __('encryption_invalid', 'Encryption must be none, tls, or ssl.')];
            } else {
                Database::setSetting('smtp_host',       mb_substr($host, 0, 190));
                Database::setSetting('smtp_port',       $port ?: '');
                Database::setSetting('smtp_user',       mb_substr($user, 0, 190));
                Database::setSetting('smtp_encryption', $enc);
                Database::setSetting('smtp_from_name',  mb_substr($fromName,  0, 120));
                Database::setSetting('smtp_from_email', mb_substr($fromEmail, 0, 190));

                if ($clearPass) {
                    // Explicit "clear password" checkbox — wipe stored credential.
                    Database::setSetting('smtp_pass', '');
                } elseif ($pass !== '') {
                    // Encrypt new password. If APP_SECRET is not configured,
                    // slate_encrypt_secret() throws — catch it and warn the user
                    // rather than crashing the entire save.
                    try {
                        Database::setSetting('smtp_pass', slate_encrypt_secret($pass));
                    } catch (\Throwable $e) {
                        // Save everything else but show a warning about the password.
                        AuditLog::record('settings.updated', 'smtp');
                        $redirectWithFlash([
                            'type' => 'warning',
                            'msg'  => __('pass_encrypt_failed',
                                'Settings saved, but the password could not be encrypted — APP_SECRET is not configured. '
                              . 'Add APP_SECRET to your .env / config and re-save the password.'),
                        ], $activeTab);
                    }
                }
                // $pass === '' and not clearing → keep existing password (no-op).

                // HTTPS-API providers: route the Mailer through the right
                // driver immediately so the admin doesn't have to also touch
                // the auth_type setting by hand. For Graph that means xoauth2
                // (which makes Mailer::send pick the Graph branch and call
                // SmtpOAuth for tokens); for Resend it means plain password
                // (the API key sits in smtp_pass).
                if ($host === 'graph.microsoft.com') {
                    Database::setSetting('smtp_auth_type', 'xoauth2');
                    // Persist the unified-form OAuth credentials. Skip the
                    // secret when blank (so re-saving doesn't wipe it) and
                    // demand APP_SECRET for the encryption step.
                    Database::setSetting('smtp_oauth_provider', 'microsoft');
                    if ($oauthClientId !== '') {
                        Database::setSetting('smtp_oauth_client_id', mb_substr($oauthClientId, 0, 255));
                    }
                    if ($oauthMailbox !== '') {
                        Database::setSetting('smtp_oauth_email', mb_substr($oauthMailbox, 0, 190));
                    }
                    if ($oauthSecretIn !== '' && function_exists('slate_encrypt_secret') && slate_has_app_secret()) {
                        try {
                            Database::setSetting('smtp_oauth_client_secret', slate_encrypt_secret($oauthSecretIn));
                        } catch (\Throwable $e) {
                            // Surface the failure but keep everything else saved.
                        }
                    }
                } elseif ($host === 'api.resend.com') {
                    Database::setSetting('smtp_auth_type', 'password');
                }

                AuditLog::record('settings.updated', 'smtp');
                // Different next-step nudge depending on which provider was
                // just saved. Graph needs OAuth still; everything else can go
                // straight to Verify/Test.
                $needsOauth   = ($host === 'graph.microsoft.com'
                              && (string)Database::setting('smtp_oauth_refresh_token') === '');
                $successMsg   = $needsOauth
                    ? __('settings_saved_msgraph_next',
                          'Settings saved. Next step: scroll to "Modern authentication" below and click Connect to authorise your Microsoft mailbox.')
                    : __('settings_saved', 'Settings saved.');
                $redirectWithFlash(['type' => 'success', 'msg' => $successMsg], $activeTab);
            }
        }

        elseif ($action === 'test_smtp') {
            $to = trim((string)($_POST['test_email'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'error', 'msg' => __('test_email_invalid', 'Enter a valid email address.')];
            } else {
                // Mailer::send() logs internally via AuditLog (mail.send).
                // Pass $log=false here to avoid double-logging.
                $ok = Mailer::send(
                    $to,
                    __('smtp_test_subject', 'Kohevo SMTP test'),
                    \Slate\Services\Notifications\EmailTemplate::compose(
                        __('smtp_test_heading', 'Email delivery test'),
                        \Slate\Services\Notifications\EmailTemplate::paragraph(e(__('smtp_test_body_1', 'This is a test message sent from your Kohevo install.')), '0 0 4px')
                      . \Slate\Services\Notifications\EmailTemplate::paragraph(e(__('smtp_test_body_2', 'If you received it, your email delivery is working.')), '0 0 4px'),
                        __('smtp_test_body_2', 'If you received it, your email delivery is working.')
                    ),
                    '',    // toName
                    false  // log — Mailer already records, don't double-log
                );
                if ($ok) {
                    $redirectWithFlash(['type' => 'success', 'msg' => sprintf(__('test_sent', 'Test email sent to %s.'), $to)], $activeTab);
                } else {
                    // Keep flash inline (not redirect) so the error appears next
                    // to the test form rather than disappearing on redirect.
                    $flash = ['type' => 'error', 'msg' => __('test_failed',
                        'Failed to send. Check your SMTP credentials and the server error log.')];
                }
            }
        }

        // ── Verify SMTP connection (handshake + auth, no send) ──
        elseif ($action === 'verify_smtp') {
            [$vok, $vmsg] = Mailer::verifyConnection();
            if (class_exists('AuditLog')) {
                AuditLog::record('smtp.verify', (string)(Database::setting('smtp_host') ?: '(mail)'), ['ok' => $vok]);
            }
            // Inline (not redirect) so the detailed result stays next to the form.
            $flash = ['type' => $vok ? 'success' : 'error', 'msg' => ($vok ? '✓ ' : '') . $vmsg];
        }

        // ── Verify BACKUP connection (same handshake, backup cfg) ──
        // Uses Mailer::verifyConnection('backup'), which reads from the
        // smtp_backup_* settings instead of the primary ones. Surfaces
        // exactly the same error categories (auth, DNS, TLS) so an admin
        // can shake out a misconfigured fallback before relying on it.
        elseif ($action === 'verify_backup_smtp') {
            [$vok, $vmsg] = Mailer::verifyConnection('backup');
            if (class_exists('AuditLog')) {
                AuditLog::record('smtp.verify_backup', (string)(Database::setting('smtp_backup_host') ?: '(none)'), ['ok' => $vok]);
            }
            $flash = ['type' => $vok ? 'success' : 'error', 'msg' => ($vok ? '✓ Backup: ' : 'Backup: ') . $vmsg];
        }

        // ── OAuth 2.0: begin connect (redirect to provider) ────
        elseif ($action === 'oauth_connect') {
            $provider = (string)($_POST['oauth_provider'] ?? '');
            $clientId = trim((string)($_POST['oauth_client_id'] ?? ''));
            $secretIn = (string)($_POST['oauth_client_secret'] ?? '');
            $email    = trim((string)($_POST['oauth_email'] ?? ''));
            $cfg      = class_exists('SmtpOAuth') ? SmtpOAuth::config($provider) : null;

            // Keep the stored secret if the field is left blank (so re-connecting
            // doesn't force the admin to paste it again).
            if ($secretIn === '' && Database::setting('smtp_oauth_client_secret')) {
                $secret = function_exists('slate_decrypt_secret')
                    ? (string)(slate_decrypt_secret((string)Database::setting('smtp_oauth_client_secret')) ?? '')
                    : (string)Database::setting('smtp_oauth_client_secret');
            } else {
                $secret = $secretIn;
            }

            if (!$cfg) {
                $flash = ['type' => 'error', 'msg' => __('oauth_provider_invalid', 'Choose a valid OAuth provider.')];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $flash = ['type' => 'error', 'msg' => __('oauth_email_invalid', 'Enter the mailbox email address to send from.')];
            } elseif ($clientId === '' || $secret === '') {
                $flash = ['type' => 'error', 'msg' => __('oauth_creds_required', 'Enter the Client ID and Client secret from your OAuth app.')];
            } elseif (!function_exists('slate_encrypt_secret') || !slate_has_app_secret()) {
                $flash = ['type' => 'error', 'msg' => __('oauth_needs_appsecret', 'APP_SECRET must be configured before OAuth credentials can be stored securely.')];
            } else {
                // Persist client creds now so the callback (a separate request)
                // can complete the exchange even if the session is trimmed.
                Database::setSetting('smtp_oauth_provider',      $provider);
                Database::setSetting('smtp_oauth_client_id',     $clientId);
                Database::setSetting('smtp_oauth_client_secret', slate_encrypt_secret($secret));
                Database::setSetting('smtp_oauth_email',         $email);

                $nonce = bin2hex(random_bytes(16));
                $_SESSION['slate_oauth_pending'] = [
                    'provider' => $provider, 'client_id' => $clientId,
                    'client_secret' => $secret, 'email' => $email, 'nonce' => $nonce,
                ];
                if (class_exists('AuditLog')) AuditLog::record('smtp.oauth_begin', $provider);
                $redirectUri = (defined('SLATE_URL') ? SLATE_URL : '') . '/admin/oauth_callback.php';
                header('Location: ' . SmtpOAuth::authorizeUrl($provider, $clientId, $redirectUri, $nonce));
                exit;
            }
        }

        // ── OAuth 2.0: disconnect (revert to password auth) ────
        elseif ($action === 'oauth_disconnect') {
            foreach (['smtp_oauth_refresh_token', 'smtp_oauth_access_token', 'smtp_oauth_expires'] as $k) {
                Database::setSetting($k, '');
            }
            Database::setSetting('smtp_auth_type', 'password');
            if (class_exists('AuditLog')) AuditLog::record('smtp.oauth_disconnect', (string)Database::setting('smtp_oauth_provider'));
            $redirectWithFlash(['type' => 'success', 'msg' => __('oauth_disconnected', 'Disconnected. SMTP is back to password authentication.')], 'smtp');
        }

        // ── OAuth 2.0: reauthorize (one-click consent refresh) ──
        // Re-runs the provider's consent screen using the credentials already
        // on file. The common reason to reauthorize is a scope change (e.g.
        // Mail.Send just added in Azure) where the existing refresh token
        // doesn't carry the new permission. Skips the disconnect/reconnect
        // dance entirely.
        elseif ($action === 'oauth_reauthorize') {
            $provider = (string)Database::setting('smtp_oauth_provider');
            $clientId = (string)Database::setting('smtp_oauth_client_id');
            $email    = (string)Database::setting('smtp_oauth_email');
            $secEnc   = (string)Database::setting('smtp_oauth_client_secret');
            $secret   = function_exists('slate_decrypt_secret')
                ? (string)(slate_decrypt_secret($secEnc) ?? '') : $secEnc;
            $cfg      = class_exists('SmtpOAuth') ? SmtpOAuth::config($provider) : null;
            if (!$cfg || $clientId === '' || $secret === '' || $email === '') {
                $redirectWithFlash(['type' => 'error', 'msg' => __('oauth_reauth_missing',
                    'Cannot reauthorize — Client ID, secret, or mailbox email is missing. Disconnect and start fresh.')], 'smtp');
            }
            $nonce = bin2hex(random_bytes(16));
            $_SESSION['slate_oauth_pending'] = [
                'provider' => $provider, 'client_id' => $clientId,
                'client_secret' => $secret, 'email' => $email, 'nonce' => $nonce,
            ];
            if (class_exists('AuditLog')) AuditLog::record('smtp.oauth_reauth', $provider);
            $redirectUri = (defined('SLATE_URL') ? SLATE_URL : '') . '/admin/oauth_callback.php';
            header('Location: ' . SmtpOAuth::authorizeUrl($provider, $clientId, $redirectUri, $nonce));
            exit;
        }

        // ── Backup connection: save fallback mailer ─────────
        // Lives next to the primary settings but writes under the
        // smtp_backup_ prefix. Mailer::send retries through this
        // config when the primary returns an error. Backup is
        // password-based only — no OAuth fallback (that needs a
        // separate Azure app and a separate consent flow).
        elseif ($action === 'save_backup_smtp') {
            $enabled   = isset($_POST['smtp_backup_enabled']) ? '1' : '0';
            $bHost     = trim((string)($_POST['smtp_backup_host'] ?? ''));
            $bPort     = (int)($_POST['smtp_backup_port'] ?? 0);
            $bUser     = trim((string)($_POST['smtp_backup_user'] ?? ''));
            $bPass     = (string)($_POST['smtp_backup_pass'] ?? '');
            $bEnc      = (string)($_POST['smtp_backup_encryption'] ?? 'tls');
            $bClearPw  = isset($_POST['clear_smtp_backup_pass']) && $_POST['clear_smtp_backup_pass'] === '1';

            if ($bPort !== 0 && ($bPort < 1 || $bPort > 65535)) {
                $flash = ['type' => 'error', 'msg' => __('backup_port_invalid', 'Backup SMTP port must be between 1 and 65535.')];
            } elseif (!in_array($bEnc, ['none', 'tls', 'ssl'], true)) {
                $flash = ['type' => 'error', 'msg' => __('backup_enc_invalid', 'Backup encryption must be none, tls, or ssl.')];
            } else {
                Database::setSetting('smtp_backup_enabled',    $enabled);
                Database::setSetting('smtp_backup_host',       mb_substr($bHost, 0, 190));
                Database::setSetting('smtp_backup_port',       $bPort ?: '');
                Database::setSetting('smtp_backup_user',       mb_substr($bUser, 0, 190));
                Database::setSetting('smtp_backup_encryption', $bEnc);
                if ($bClearPw) {
                    Database::setSetting('smtp_backup_pass', '');
                } elseif ($bPass !== '') {
                    try {
                        Database::setSetting('smtp_backup_pass', slate_encrypt_secret($bPass));
                    } catch (\Throwable $e) {
                        $redirectWithFlash([
                            'type' => 'warning',
                            'msg'  => __('backup_pass_encrypt_failed', 'Backup saved, but the password could not be encrypted — APP_SECRET is not configured.'),
                        ], $activeTab);
                    }
                }
                if (class_exists('AuditLog')) AuditLog::record('settings.updated', 'smtp.backup');
                $redirectWithFlash(['type' => 'success', 'msg' => $enabled === '1'
                    ? __('backup_saved_on',  'Backup connection saved and enabled. Mailer will retry failed primary sends through it.')
                    : __('backup_saved_off', 'Backup connection saved (disabled). Enable the checkbox to activate failover.')
                ], $activeTab);
            }
        }

        // ── Security tab ─────────────────────────────────────
        elseif ($action === 'save_security') {
            $sessionTimeout = (int)($_POST['session_timeout_minutes'] ?? 60);
            if ($sessionTimeout < 5)   $sessionTimeout = 5;
            if ($sessionTimeout > 1440) $sessionTimeout = 1440;

            $maxLoginAttempts = (int)($_POST['max_login_attempts'] ?? 5);
            if ($maxLoginAttempts < 1)  $maxLoginAttempts = 1;
            if ($maxLoginAttempts > 20) $maxLoginAttempts = 20;

            $lockoutMinutes = (int)($_POST['lockout_minutes'] ?? 15);
            if ($lockoutMinutes < 1)   $lockoutMinutes = 1;
            if ($lockoutMinutes > 1440) $lockoutMinutes = 1440;

            $forceHttps     = isset($_POST['force_https'])     ? '1' : '0';

            Database::setSetting('session_timeout_minutes', (string)$sessionTimeout);
            Database::setSetting('max_login_attempts',      (string)$maxLoginAttempts);
            Database::setSetting('lockout_minutes',         (string)$lockoutMinutes);
            Database::setSetting('force_https',             $forceHttps);
            AuditLog::record('settings.updated', 'security');
            $redirectWithFlash(['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')], $activeTab);
        }

        // ── Branding tab ─────────────────────────────────────
        elseif ($action === 'save_branding') {
            $accent = trim((string)($_POST['accent_color'] ?? ''));
            if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
                $flash = ['type' => 'error', 'msg' => __('color_invalid', 'Accent color must be a hex value like #B17A3C.')];
            } else {
                Database::setSetting('brand_accent_color', $accent);

                // Manual override for button/badge text color on the accent
                // fill — 'auto' (default) keeps Kohevo's automatic contrast pick.
                $buttonTextMode = (string)($_POST['button_text_mode'] ?? 'auto');
                if (!in_array($buttonTextMode, ['auto', 'light', 'dark'], true)) $buttonTextMode = 'auto';
                Database::setSetting('brand_button_text_mode', $buttonTextMode);

                // Sidebar colour theme (preset key).
                $sidebarTheme = (string)($_POST['sidebar_theme'] ?? 'ink');
                $validThemes  = function_exists('slate_sidebar_themes')
                              ? array_keys(slate_sidebar_themes())
                              : ['ink', 'navy', 'teal', 'slate', 'light'];
                Database::setSetting('sidebar_theme',
                    in_array($sidebarTheme, $validThemes, true) ? $sidebarTheme : 'ink');

                // Folded in from the retired Global Styles page. Same
                // allow-lists it used; still no downstream consumer, so this
                // is a straight carry-over of prior (inert) behavior.
                $fontFamily = (string)($_POST['font_family'] ?? 'system');
                if (!in_array($fontFamily, ['system', 'inter', 'plus-jakarta', 'outfit', 'playfair', 'roboto'], true)) {
                    $fontFamily = 'system';
                }
                Database::setSetting('brand_font_family', $fontFamily);

                $borderRadius = (string)($_POST['border_radius'] ?? 'medium');
                if (!in_array($borderRadius, ['none', 'small', 'medium', 'large', 'pill'], true)) {
                    $borderRadius = 'medium';
                }
                Database::setSetting('brand_border_radius', $borderRadius);

                if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    try {
                        // Raster only — SVGs can carry inline <script> and the
                        // branding dir is web-served, so an SVG logo opened
                        // directly would execute script in this origin.
                        $result = Uploads::handle('logo', 'branding', [
                            'allowed_mimes'  => ['image/png', 'image/jpeg', 'image/webp'],
                            'max_bytes'      => 2 * 1024 * 1024,
                        ]);
                        if ($result['ok']) {
                            Database::setSetting('brand_logo_path', $result['path']);
                            if (class_exists('MediaLibrary')) {
                                $info = @getimagesize(SLATE_ROOT . $result['path']);
                                MediaLibrary::register($result['path'], [
                                    'mime'          => $result['mime']     ?? '',
                                    'size_bytes'    => $result['size']     ?? 0,
                                    'width'         => is_array($info) ? ($info[0] ?? null) : null,
                                    'height'        => is_array($info) ? ($info[1] ?? null) : null,
                                    'original_name' => $result['original'] ?? '',
                                ]);
                            }
                        } else {
                            $flash = ['type' => 'error', 'msg' => $result['error'] ?? __('upload_failed', 'Upload failed.')];
                        }
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => __('upload_failed', 'Logo upload failed.') . ' ' . $e->getMessage()];
                    }
                } elseif (!empty($_POST['picked_logo_path'])) {
                    $picked = trim((string)$_POST['picked_logo_path']);
                    if (class_exists('MediaLibrary') && MediaLibrary::isManagedPath($picked)) {
                        Database::setSetting('brand_logo_path', $picked);
                    } else {
                        $flash = ['type' => 'error', 'msg' =>
                            __('logo_path_invalid', 'Picked logo path is not in the media library.')];
                    }
                }

                if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
                    Database::setSetting('brand_logo_path', '');
                }

                // ── Dark-mode logo variant (optional) ──────────────
                // Shown instead of the logo above when the viewer's OS/browser
                // prefers a dark color scheme (see TenantBranding::logoPicture()).
                // Falls back to the light logo everywhere when unset.
                if (!empty($_FILES['logo_dark']) && $_FILES['logo_dark']['error'] === UPLOAD_ERR_OK) {
                    try {
                        $result = Uploads::handle('logo_dark', 'branding', [
                            'allowed_mimes' => ['image/png', 'image/jpeg', 'image/webp'],
                            'max_bytes'     => 2 * 1024 * 1024,
                        ]);
                        if ($result['ok']) {
                            Database::setSetting('brand_logo_dark_path', $result['path']);
                            if (class_exists('MediaLibrary')) {
                                $info = @getimagesize(SLATE_ROOT . $result['path']);
                                MediaLibrary::register($result['path'], [
                                    'mime'          => $result['mime']     ?? '',
                                    'size_bytes'    => $result['size']     ?? 0,
                                    'width'         => is_array($info) ? ($info[0] ?? null) : null,
                                    'height'        => is_array($info) ? ($info[1] ?? null) : null,
                                    'original_name' => $result['original'] ?? '',
                                ]);
                            }
                        } else {
                            $flash = ['type' => 'error', 'msg' => $result['error'] ?? __('upload_failed', 'Dark logo upload failed.')];
                        }
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => __('upload_failed', 'Dark logo upload failed.') . ' ' . $e->getMessage()];
                    }
                } elseif (!empty($_POST['picked_logo_dark_path'])) {
                    $picked = trim((string)$_POST['picked_logo_dark_path']);
                    if (class_exists('MediaLibrary') && MediaLibrary::isManagedPath($picked)) {
                        Database::setSetting('brand_logo_dark_path', $picked);
                    } else {
                        $flash = ['type' => 'error', 'msg' =>
                            __('logo_dark_path_invalid', 'Picked dark logo path is not in the media library.')];
                    }
                }
                if (isset($_POST['remove_logo_dark']) && $_POST['remove_logo_dark'] === '1') {
                    Database::setSetting('brand_logo_dark_path', '');
                }

                // ── Favicon (browser tab icon) ─────────────────────
                if (!empty($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
                    try {
                        $fres = Uploads::handle('favicon', 'branding', [
                            'allowed_mimes' => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
                            'max_bytes'     => 512 * 1024,
                        ]);
                        if ($fres['ok']) {
                            Database::setSetting('brand_favicon_path', $fres['path']);
                        } else {
                            $flash = ['type' => 'error', 'msg' => $fres['error'] ?? __('upload_failed', 'Favicon upload failed.')];
                        }
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => __('upload_failed', 'Favicon upload failed.') . ' ' . $e->getMessage()];
                    }
                } elseif (!empty($_POST['picked_favicon_path'])) {
                    $picked = trim((string)$_POST['picked_favicon_path']);
                    if (class_exists('MediaLibrary') && MediaLibrary::isManagedPath($picked)) {
                        Database::setSetting('brand_favicon_path', $picked);
                    } else {
                        $flash = ['type' => 'error', 'msg' =>
                            __('favicon_path_invalid', 'Picked favicon path is not in the media library.')];
                    }
                }
                if (isset($_POST['remove_favicon']) && $_POST['remove_favicon'] === '1') {
                    Database::setSetting('brand_favicon_path', '');
                }

                // ── Login page hero image ──────────────────────────
                // Shown in the left column of the admin login screen. Raster
                // only (the branding dir is web-served, so an SVG could carry
                // inline <script>). Allow a larger budget than the logo since
                // this is a full-bleed photo.
                if (!empty($_FILES['login_image']) && $_FILES['login_image']['error'] === UPLOAD_ERR_OK) {
                    try {
                        $result = Uploads::handle('login_image', 'branding', [
                            'allowed_mimes' => ['image/png', 'image/jpeg', 'image/webp'],
                            'max_bytes'     => 5 * 1024 * 1024,
                        ]);
                        if ($result['ok']) {
                            Database::setSetting('brand_login_image_path', $result['path']);
                            if (class_exists('MediaLibrary')) {
                                $info = @getimagesize(SLATE_ROOT . $result['path']);
                                MediaLibrary::register($result['path'], [
                                    'mime'          => $result['mime']     ?? '',
                                    'size_bytes'    => $result['size']     ?? 0,
                                    'width'         => is_array($info) ? ($info[0] ?? null) : null,
                                    'height'        => is_array($info) ? ($info[1] ?? null) : null,
                                    'original_name' => $result['original'] ?? '',
                                ]);
                            }
                        } else {
                            $flash = ['type' => 'error', 'msg' => $result['error'] ?? __('upload_failed', 'Upload failed.')];
                        }
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => __('upload_failed', 'Login image upload failed.') . ' ' . $e->getMessage()];
                    }
                } elseif (!empty($_POST['picked_login_image_path'])) {
                    $picked = trim((string)$_POST['picked_login_image_path']);
                    if (class_exists('MediaLibrary') && MediaLibrary::isManagedPath($picked)) {
                        Database::setSetting('brand_login_image_path', $picked);
                    } else {
                        $flash = ['type' => 'error', 'msg' =>
                            __('login_image_path_invalid', 'Picked login image is not in the media library.')];
                    }
                }

                if (isset($_POST['remove_login_image']) && $_POST['remove_login_image'] === '1') {
                    Database::setSetting('brand_login_image_path', '');
                }

                // ── Login tagline (overlaid on the hero image) ─────
                Database::setSetting('brand_login_tagline',
                    mb_substr(trim((string)($_POST['login_tagline'] ?? '')), 0, 200));

                // ── White-label identity / support info ────────────
                // Read by admin/help.php and admin/login.php, which fall
                // back to a hardcoded default until one of these is set.
                if (!$flash) {
                    $brandOwner    = mb_substr(trim((string)($_POST['brand_owner'] ?? '')), 0, 120);
                    $brandOwnerUrl = trim((string)($_POST['brand_owner_url'] ?? ''));
                    $supportEmail  = trim((string)($_POST['support_email'] ?? ''));
                    $supportPhone  = mb_substr(trim((string)($_POST['support_phone'] ?? '')), 0, 40);
                    $supportUrl    = trim((string)($_POST['support_url'] ?? ''));
                    $docsUrl       = trim((string)($_POST['docs_url'] ?? ''));
                    $privacyUrl    = trim((string)($_POST['privacy_url'] ?? ''));
                    $termsUrl      = trim((string)($_POST['terms_url'] ?? ''));

                    $urlFields = [
                        'brand_owner_url' => ['value' => $brandOwnerUrl, 'label' => __('owner_website', 'Owner website')],
                        'support_url'     => ['value' => $supportUrl,    'label' => __('support_url', 'Support URL')],
                        'docs_url'        => ['value' => $docsUrl,       'label' => __('docs_url', 'Documentation URL')],
                        'privacy_url'     => ['value' => $privacyUrl,    'label' => __('privacy_url', 'Privacy policy URL')],
                        'terms_url'       => ['value' => $termsUrl,      'label' => __('terms_url', 'Terms of service URL')],
                    ];
                    foreach ($urlFields as $key => $f) {
                        if ($f['value'] !== '' && !filter_var($f['value'], FILTER_VALIDATE_URL)) {
                            $flash = ['type' => 'error', 'msg' => sprintf(__('url_invalid', '%s must be a valid URL.'), $f['label'])];
                            break;
                        }
                    }

                    if (!$flash && $supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
                        $flash = ['type' => 'error', 'msg' => __('support_email_invalid', 'Support email must be a valid email address.')];
                    }

                    if (!$flash) {
                        Database::setSetting('brand_owner', $brandOwner);
                        Database::setSetting('brand_owner_url', $brandOwnerUrl);
                        Database::setSetting('support_email', $supportEmail);
                        Database::setSetting('support_phone', $supportPhone);
                        Database::setSetting('support_url', $supportUrl);
                        Database::setSetting('docs_url', $docsUrl);
                        Database::setSetting('privacy_url', $privacyUrl);
                        Database::setSetting('terms_url', $termsUrl);
                    }
                }

                if (!$flash) {
                    AuditLog::record('settings.updated', 'branding');
                    $redirectWithFlash(['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')], $activeTab);
                }
            }
        }

        // ── Landing page tab ─────────────────────────────────
        elseif ($action === 'save_landing') {
            Database::setSetting('landing_eyebrow',       mb_substr(trim((string)($_POST['landing_eyebrow'] ?? '')), 0, 80));
            Database::setSetting('landing_title',         mb_substr(trim((string)($_POST['landing_title'] ?? '')), 0, 120));
            Database::setSetting('landing_intro',         mb_substr(trim((string)($_POST['landing_intro'] ?? '')), 0, 400));
            Database::setSetting('landing_website_label', mb_substr(trim((string)($_POST['landing_website_label'] ?? '')), 0, 60));
            Database::setSetting('landing_footer',        mb_substr(trim((string)($_POST['landing_footer'] ?? '')), 0, 200));

            $url = trim((string)($_POST['landing_website_url'] ?? ''));
            if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                $flash = ['type' => 'error', 'msg' => __('landing_url_invalid', 'The website URL is not a valid address.')];
            } else {
                Database::setSetting('landing_website_url', mb_substr($url, 0, 300));

                // Build the selected-forms JSON in the order shown in the form.
                $ids     = (array)($_POST['lf_ids']     ?? []);
                $inc     = (array)($_POST['lf_include'] ?? []);
                $labels  = (array)($_POST['lf_label']   ?? []);
                $blurbs  = (array)($_POST['lf_blurb']   ?? []);
                $icons   = (array)($_POST['lf_icon']    ?? []);
                $buttons = (array)($_POST['lf_button']  ?? []);
                $allowedIcons = ['powerboat','sailboat','boat','anchor','clipboard','star','compass'];
                $out = [];
                foreach ($ids as $rawId) {
                    $id = (int)$rawId;
                    if (empty($inc[$id])) continue;
                    $icon = (string)($icons[$id] ?? 'clipboard');
                    if (!in_array($icon, $allowedIcons, true)) $icon = 'clipboard';
                    $out[] = [
                        'id'     => $id,
                        'label'  => mb_substr(trim((string)($labels[$id] ?? '')), 0, 60),
                        'blurb'  => mb_substr(trim((string)($blurbs[$id] ?? '')), 0, 160),
                        'icon'   => $icon,
                        'button' => mb_substr(trim((string)($buttons[$id] ?? '')), 0, 40),
                    ];
                }
                Database::setSetting('landing_forms_json', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                // Optional featured card linking straight to the booking catalog
                // (/book) — separate from the forms cards above since it isn't
                // tied to a forms_definitions row. Only meaningful when the
                // Booking plugin is active; ignored otherwise so a stray POST
                // can't turn on a link to a route that doesn't exist.
                if (class_exists('PluginLoader') && PluginLoader::isActive('booking')) {
                    $bookIcon = (string)($_POST['landing_booking_icon'] ?? 'clipboard');
                    if (!in_array($bookIcon, $allowedIcons, true)) $bookIcon = 'clipboard';
                    Database::setSetting('landing_booking_enabled', isset($_POST['landing_booking_enabled']) ? '1' : '0');
                    Database::setSetting('landing_booking_label',  mb_substr(trim((string)($_POST['landing_booking_label']  ?? '')), 0, 60));
                    Database::setSetting('landing_booking_blurb',  mb_substr(trim((string)($_POST['landing_booking_blurb']  ?? '')), 0, 160));
                    Database::setSetting('landing_booking_button', mb_substr(trim((string)($_POST['landing_booking_button'] ?? '')), 0, 40));
                    Database::setSetting('landing_booking_icon',   $bookIcon);
                }

                // Entry-point tiles (customer/admin login) — on by default; an
                // explicit '0' is only ever written when the admin unchecks one.
                Database::setSetting('landing_show_customer_login', isset($_POST['landing_show_customer_login']) ? '1' : '0');
                Database::setSetting('landing_show_admin_login',    isset($_POST['landing_show_admin_login'])    ? '1' : '0');

                if (!$flash) {
                    AuditLog::record('settings.updated', 'landing');
                    $redirectWithFlash(['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')], $activeTab);
                }
            }
        }

        // ── System tab ───────────────────────────────────────
        elseif ($action === 'save_system') {
            $maintenanceMode = isset($_POST['maintenance_mode']) ? '1' : '0';
            $debugMode       = isset($_POST['debug_mode'])       ? '1' : '0';
            $timezone        = trim((string)($_POST['timezone'] ?? 'UTC'));
            // Validate timezone string
            if (!in_array($timezone, timezone_identifiers_list(), true)) {
                $timezone = 'UTC';
            }

            Database::setSetting('maintenance_mode', $maintenanceMode);
            Database::setSetting('debug_mode',       $debugMode);
            Database::setSetting('timezone',         $timezone);
            AuditLog::record('settings.updated', 'system');
            $redirectWithFlash(['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')], $activeTab);
        }
    }
}

// ─── Load current values ─────────────────────────────────────
$_safeText = static function ($v, int $max = 500): string {
    $s = is_string($v) ? $v : (string) $v;
    // Drop control characters
    $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/', '', $s) ?? '';
    // Strip complete AND incomplete HTML tags (e.g. "<script defer src=" with no closing >)
    $s = preg_replace('/<[^>]*>?/', '', $s) ?? '';
    // belt-and-suspenders strip_tags for anything the regex missed
    $s = strip_tags($s);
    // Normalise whitespace
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
};

$genSet = [
    'site_name'        => $_safeText(Database::setting('site_name'), 120) ?: 'Kohevo',
    'default_language' => preg_replace('/[^a-zA-Z_-]/', '', (string)(Database::setting('default_language') ?? 'en')) ?: 'en',
    'currency'         => slate_default_currency(),
    'currency_position'=> slate_currency_position(),
];
$biz = [
    'name'    => $_safeText(Database::setting('business_name'),    190),
    'email'   => $_safeText(Database::setting('business_email'),   190),
    'phone'   => $_safeText(Database::setting('business_phone'),    64),
    'address' => $_safeText(Database::setting('business_address'), 500),
    'hours'   => $_safeText(Database::setting('business_hours'),   500),
];
$smtp = [
    'host'       => $_safeText(Database::setting('smtp_host'),       190),
    'port'       => preg_replace('/\D+/', '', (string)Database::setting('smtp_port')),
    'user'       => $_safeText(Database::setting('smtp_user'),       190),
    'has_pass'   => (bool)Database::setting('smtp_pass'),
    'encryption' => in_array((string)Database::setting('smtp_encryption'), ['tls','ssl','none'], true)
                     ? Database::setting('smtp_encryption') : 'tls',
    'from_name'  => $_safeText(Database::setting('smtp_from_name'),  190),
    'from_email' => $_safeText(Database::setting('smtp_from_email'), 190),
];
$oauth = [
    'provider'   => (string)Database::setting('smtp_oauth_provider'),
    'client_id'  => $_safeText(Database::setting('smtp_oauth_client_id'), 190),
    'email'      => $_safeText(Database::setting('smtp_oauth_email'),     190),
    'has_secret' => (bool)Database::setting('smtp_oauth_client_secret'),
    'connected'  => Database::setting('smtp_auth_type') === 'xoauth2'
                     && (bool)Database::setting('smtp_oauth_refresh_token'),
];
$backup = [
    'enabled'    => (string)Database::setting('smtp_backup_enabled') === '1',
    'host'       => $_safeText(Database::setting('smtp_backup_host'),       190),
    'port'       => preg_replace('/\D+/', '', (string)Database::setting('smtp_backup_port')),
    'user'       => $_safeText(Database::setting('smtp_backup_user'),       190),
    'has_pass'   => (bool)Database::setting('smtp_backup_pass'),
    'encryption' => in_array((string)Database::setting('smtp_backup_encryption'), ['tls','ssl','none'], true)
                     ? Database::setting('smtp_backup_encryption') : 'tls',
];
// ── Landing page settings ────────────────────────────────────
$landing = [
    'eyebrow' => $_safeText(Database::setting('landing_eyebrow'),       80),
    'title'   => $_safeText(Database::setting('landing_title'),         120),
    'intro'   => $_safeText(Database::setting('landing_intro'),         400),
    'url'     => $_safeText(Database::setting('landing_website_url'),    300),
    'label'   => $_safeText(Database::setting('landing_website_label'),  60),
    'footer'  => $_safeText(Database::setting('landing_footer'),         200),
];
// Saved per-form overrides, keyed by form id (for pre-filling the picker).
$landingFormMap = [];
foreach ((array)json_decode((string)Database::setting('landing_forms_json'), true) as $lf) {
    if (isset($lf['id'])) $landingFormMap[(int)$lf['id']] = $lf;
}
// Featured "book online" card (only offered when the Booking plugin is active).
// Defaults ON (unset !== explicit '0') so the entry-point hub shows all three
// tiles out of the box; an admin who has never saved this tab yet still sees
// them live, matching what the settings form itself shows as checked below.
$bookingPluginActive = class_exists('PluginLoader') && PluginLoader::isActive('booking');
$landingBooking = [
    'enabled' => Database::setting('landing_booking_enabled') !== '0',
    'label'   => $_safeText(Database::setting('landing_booking_label'),  60),
    'blurb'   => $_safeText(Database::setting('landing_booking_blurb'),  160),
    'button'  => $_safeText(Database::setting('landing_booking_button'), 40),
    'icon'    => in_array((string)Database::setting('landing_booking_icon'), ['clipboard','powerboat','sailboat','boat','anchor','compass','star'], true)
                  ? Database::setting('landing_booking_icon') : 'clipboard',
];
$landingShowCustomerLogin = Database::setting('landing_show_customer_login') !== '0';
$landingShowAdminLogin    = Database::setting('landing_show_admin_login')    !== '0';
// Every published form the admin can choose to feature.
$publishedForms = (class_exists('FormsAPI') && function_exists('current_tenant_id'))
    ? Database::rows("SELECT id, slug, title FROM forms_definitions WHERE tenant_id = ? AND status = 'published' ORDER BY title", [current_tenant_id()])
    : [];
$sec = [
    'session_timeout'   => max(5,  min(1440, (int)(Database::setting('session_timeout_minutes') ?: 60))),
    'max_login_attempts'=> max(1,  min(20,   (int)(Database::setting('max_login_attempts')      ?: 5))),
    'lockout_minutes'   => max(1,  min(1440, (int)(Database::setting('lockout_minutes')         ?: 15))),
    'force_https'       => Database::setting('force_https')     === '1',
];
$brandSet = [
    'accent_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)Database::setting('brand_accent_color'))
                       ? Database::setting('brand_accent_color') : '#111111',
    'logo_path'        => $_safeText(Database::setting('brand_logo_path'), 500),
    'logo_dark_path'   => $_safeText(Database::setting('brand_logo_dark_path'), 500),
    'favicon_path'     => $_safeText(Database::setting('brand_favicon_path'), 500),
    'login_image_path' => $_safeText(Database::setting('brand_login_image_path'), 500),
    'login_tagline'    => $_safeText(Database::setting('brand_login_tagline'), 200),
    'sidebar_theme'    => (string)(Database::setting('sidebar_theme') ?: 'ink'),
    'button_text_mode' => in_array((string)Database::setting('brand_button_text_mode'), ['light', 'dark'], true)
                          ? (string)Database::setting('brand_button_text_mode') : 'auto',
    // Folded in from the retired Global Styles page (admin/styles.php).
    // Neither has a downstream consumer anywhere in the app today (confirmed
    // by repo-wide grep before the merge) -- preserved as-is, not wired up,
    // so this merge changes nothing about their (lack of) live effect.
    'font_family'   => in_array((string)Database::setting('brand_font_family'),
                          ['system', 'inter', 'plus-jakarta', 'outfit', 'playfair', 'roboto'], true)
                        ? (string)Database::setting('brand_font_family') : 'system',
    'border_radius' => in_array((string)Database::setting('brand_border_radius'),
                          ['none', 'small', 'medium', 'large', 'pill'], true)
                        ? (string)Database::setting('brand_border_radius') : 'medium',
    // White-label identity / support info. help.php and login.php fall back
    // to a hardcoded default until one of these is actually set.
    'owner'         => $_safeText(Database::setting('brand_owner'), 120),
    'owner_url'     => $_safeText(Database::setting('brand_owner_url'), 300),
    'support_email' => $_safeText(Database::setting('support_email'), 190),
    'support_phone' => $_safeText(Database::setting('support_phone'), 40),
    'support_url'   => $_safeText(Database::setting('support_url'), 300),
    'docs_url'      => $_safeText(Database::setting('docs_url'), 300),
    'privacy_url'   => $_safeText(Database::setting('privacy_url'), 300),
    'terms_url'     => $_safeText(Database::setting('terms_url'), 300),
];
$sys = [
    'maintenance_mode' => Database::setting('maintenance_mode') === '1',
    'debug_mode'       => Database::setting('debug_mode')       === '1',
    'timezone'         => $_safeText(Database::setting('timezone') ?: 'UTC', 64),
    'php_version'      => PHP_VERSION,
    'slate_version'    => defined('SLATE_VERSION') ? SLATE_VERSION : '1.0',
];
$permalinksSet = [
    'structure'      => $_safeText(Database::setting('permalink_structure') ?: '/p/%postname%', 100),
    'post_base'      => $_safeText(Database::setting('permalink_post_base') ?: 'post', 50),
    'trailing_slash' => Database::setting('permalink_trailing_slash') === '1',
];
$dateTimeSet = [
    'timezone'      => $_safeText(Database::setting('timezone') ?: 'UTC', 64),
    'date_format'   => $_safeText(Database::setting('date_format') ?: 'F j, Y', 32),
    'time_format'   => $_safeText(Database::setting('time_format') ?: 'g:i a', 32),
    'start_of_week' => (int)(Database::setting('start_of_week') ?? 1),
];
$mediaSet = [
    'thumb_w'        => (int)(Database::setting('media_thumb_w') ?: 150),
    'thumb_h'        => (int)(Database::setting('media_thumb_h') ?: 150),
    'medium_w'       => (int)(Database::setting('media_medium_w') ?: 300),
    'medium_h'       => (int)(Database::setting('media_medium_h') ?: 300),
    'large_w'        => (int)(Database::setting('media_large_w') ?: 1024),
    'large_h'        => (int)(Database::setting('media_large_h') ?: 1024),
    'webp_convert'   => Database::setting('media_webp_convert') === '1',
    'max_upload_mb'  => (int)(Database::setting('media_max_upload_mb') ?: 20),
];

require __DIR__ . '/partials/header.php';

$mediaLibraryActive = class_exists('MediaLibrary')
    && class_exists('PluginLoader')
    && PluginLoader::isActive('media-library');
?>

<?php if ($mediaLibraryActive): ?>
    <link rel="stylesheet" href="<?= e(SLATE_URL) ?>/plugins/media-library/assets/css/picker.css">
    <script src="<?= e(SLATE_URL) ?>/plugins/media-library/assets/js/picker.js"></script>
<?php endif; ?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('settings', 'Settings')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('settings', 'Settings') ?></h1>
        <p class="page-header-sub">
            <?= __('settings_subtitle', 'Manage your application configuration.') ?>
        </p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<style>
/* Settings tab strip CSS moved to includes/ui_components.php (shared with
   the Translations plugin's admin page -- see slate_render_settings_tabs()). */

/* ─── Info row (System tab) ──────────────────────────────── */
.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13.5px;
}
.info-row:last-child { border-bottom: none; }
.info-row-label { color: var(--muted); font-weight: 500; }
.info-row-value { color: var(--text); font-family: var(--font-mono); font-size: 12.5px; }

/* ─── Toggle switch ──────────────────────────────────────── */
.toggle-field {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid var(--border);
}
.toggle-field:last-child { border-bottom: none; }
.toggle-wrap {
    flex: 1;
}
.toggle-title {
    font-size: 13.5px;
    font-weight: 500;
    color: var(--text);
    line-height: 1.4;
}
.toggle-hint {
    font-size: 12px;
    color: var(--muted);
    margin-top: 2px;
}
.toggle-switch {
    position: relative;
    width: 42px;
    height: 24px;
    flex-shrink: 0;
    margin-top: 1px;
}
.toggle-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
.toggle-slider {
    position: absolute;
    inset: 0;
    background: var(--border-strong);
    border-radius: 999px;
    cursor: pointer;
    transition: background 0.2s;
}
.toggle-slider::after {
    content: '';
    position: absolute;
    left: 3px; top: 3px;
    width: 18px; height: 18px;
    border-radius: 50%;
    background: #fff;
    transition: transform 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.25);
}
.toggle-switch input:checked + .toggle-slider { background: var(--accent); }
.toggle-switch input:checked + .toggle-slider::after { transform: translateX(18px); }
.toggle-switch input:focus-visible + .toggle-slider { outline: 2px solid var(--accent); outline-offset: 2px; }

/* Labeled dimension inputs (Media tab: Thumbnail/Medium/Large size, Max
   upload size) -- a bordered pill per value instead of loose
   "W: [input] px" text runs, so the four rows line up in tidy columns
   regardless of label length ("W" vs "Max W"). */
.dim-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.dim-input {
    display: flex; align-items: center; gap: 7px;
    height: 40px; padding: 0 12px;
    border: 1px solid var(--border); border-radius: 10px;
    background: var(--surface);
}
.dim-input-tag {
    flex: none;
    font-family: var(--font-mono); font-size: 10.5px; font-weight: 700;
    letter-spacing: 0.04em; text-transform: uppercase; color: var(--subtle);
}
.dim-input input[type="number"] {
    flex: none; width: 4.5ch; min-width: 44px; border: 0; background: transparent; padding: 0;
    font-size: 14px; font-weight: 600; text-align: right;
    font-variant-numeric: tabular-nums;
    /* Chrome/Safari's native up/down spinner otherwise eats into this
       already-tight width, which is what was clipping 4-digit values
       (e.g. "1024" rendering as "102"). No spinner is a fine trade here --
       these are bounded pixel/MB fields, not something you'd click-step. */
    -moz-appearance: textfield;
}
.dim-input input[type="number"]::-webkit-outer-spin-button,
.dim-input input[type="number"]::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.dim-input input[type="number"]:focus { outline: 0; box-shadow: none; }
.dim-input input[type="number"]:disabled { color: var(--subtle); }
.dim-input-unit { flex: none; font-size: 12px; color: var(--muted); }
</style>

<!-- ─── Tab strip ──────────────────────────────────────────── -->
<?php
// Rendering (icons, markup, CSS) now lives in slate_render_settings_tabs()
// (includes/ui_components.php) so the Translations plugin's admin page can
// render the identical strip with itself marked active, instead of a
// second, separately-maintained top-level nav item. $tabs (the local
// content-tab list above, used for the array_key_exists($activeTab, $tabs)
// validation) intentionally does NOT include 'translations' -- that tab's
// content lives on its own page; see the redirect guard near the top of
// this file for direct ?tab=translations requests.
slate_render_settings_tabs($activeTab);
?>

<?php /* ══════════════════════════════════════════════════════
   TAB: GENERAL / PROFILE
══════════════════════════════════════════════════════ */ ?>
<?php if ($activeTab === 'general' || $activeTab === 'profile'): ?>

    <div class="card">
        <div class="card-header"><h2><?= __('general', 'General') ?></h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_general">
            <input type="hidden" name="_tab"    value="general">

            <div class="field">
                <label class="field-label" for="site_name"><?= __('site_name', 'Site name') ?></label>
                <input type="text" id="site_name" name="site_name" required maxlength="120"
                       value="<?= e($genSet['site_name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('site_name_hint', 'Shown in the sidebar, on the login screen, and in browser tabs.') ?></div>
            </div>

            <div class="field">
                <label class="field-label" for="default_language"><?= __('default_language', 'Default language') ?></label>
                <select id="default_language" name="default_language" <?= $canEdit ? '' : 'disabled' ?>>
                    <?php foreach (I18n::supportedLanguages() as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $code === $genSet['default_language'] ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (class_exists('StudioBuilder', false) && StudioBuilder::isEntitled()): ?>
                    <div class="field-hint"><?= __('default_language_studio_hint', 'Also the language of your public Kohevo Studio pages. Visitors switching language does not change them.') ?></div>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label" for="currency"><?= __('currency', 'Currency') ?></label>
                <input type="text" id="currency" name="currency" maxlength="8"
                       value="<?= e($genSet['currency']) ?>" placeholder="USD" <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('currency_hint', 'Tenant-wide default currency. All plugins (Membership, Booking, Gift cards, ...) use this — there is no per-plugin currency setting.') ?></div>
            </div>

            <div class="field">
                <label class="field-label"><?= __('currency_position', 'Currency symbol position') ?></label>
                <div class="field-row field-row-2" style="margin-bottom:0;">
                    <label class="radio-card" style="display:flex;align-items:center;gap:8px;border:1px solid var(--border,#e2ddd0);border-radius:8px;padding:10px 14px;cursor:pointer;">
                        <input type="radio" name="currency_position" value="before" <?= $genSet['currency_position'] === 'before' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                        <span><?= __('currency_position_before', 'Before amount') ?> <span class="text-muted text-xs">(<?= e(slate_currency_symbol($genSet['currency'])) ?>50.00)</span></span>
                    </label>
                    <label class="radio-card" style="display:flex;align-items:center;gap:8px;border:1px solid var(--border,#e2ddd0);border-radius:8px;padding:10px 14px;cursor:pointer;">
                        <input type="radio" name="currency_position" value="after" <?= $genSet['currency_position'] === 'after' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                        <span><?= __('currency_position_after', 'After amount') ?> <span class="text-muted text-xs">(50.00&nbsp;<?= e(slate_currency_symbol($genSet['currency'])) ?>)</span></span>
                    </label>
                </div>
                <div class="field-hint"><?= __('currency_position_hint', 'Controls whether the currency symbol shows before or after every price, site-wide.') ?></div>
            </div>

            <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary"><?= __('save_changes', 'Save changes') ?></button>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('business_details', 'Business details') ?></h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_business">
            <input type="hidden" name="_tab"    value="general">

            <div class="field">
                <label class="field-label" for="business_name"><?= __('business_name', 'Business name') ?></label>
                <input type="text" id="business_name" name="business_name" maxlength="190"
                       value="<?= e($biz['name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="business_email"><?= __('business_email', 'Contact email') ?></label>
                    <input type="email" id="business_email" name="business_email" maxlength="190"
                           value="<?= e($biz['email']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
                <div class="field">
                    <label class="field-label" for="business_phone"><?= __('business_phone', 'Phone') ?></label>
                    <input type="tel" id="business_phone" name="business_phone" maxlength="64"
                           value="<?= e($biz['phone']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="business_address"><?= __('business_address', 'Address') ?></label>
                <textarea id="business_address" name="business_address" maxlength="500"
                          rows="3" <?= $canEdit ? '' : 'disabled' ?>><?= e($biz['address']) ?></textarea>
            </div>

            <div class="field">
                <label class="field-label" for="business_hours"><?= __('business_hours', 'Business hours') ?></label>
                <textarea id="business_hours" name="business_hours" maxlength="500"
                          rows="3" placeholder="Mon–Fri 9:00–17:00&#10;Sat 10:00–14:00"
                          <?= $canEdit ? '' : 'disabled' ?>><?= e($biz['hours']) ?></textarea>
                <div class="field-hint"><?= __('hours_hint', 'Free-form text. One line per day or range.') ?></div>
            </div>

            <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary"><?= __('save_changes', 'Save changes') ?></button>
            <?php endif; ?>
        </form>
    </div>

<?php /* ══════════════════════════════════════════════════════
   TAB: PERMALINKS
══════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'permalinks'): ?>

    <div class="card">
        <div class="card-header">
            <h2><?= __('permalinks', 'Permalinks & URL Structure') ?></h2>
            <p class="card-header-sub"><?= __('permalinks_sub', 'Configure how URLs are formed for published pages, posts, and custom content types.') ?></p>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_permalinks">
            <input type="hidden" name="_tab"    value="permalinks">

            <div class="field">
                <label class="field-label"><?= __('url_structure', 'URL Structure') ?></label>
                <div class="radio-group" style="display:flex;flex-direction:column;gap:12px;margin-top:8px;">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="radio" name="permalink_structure" value="/p/%postname%" <?= $permalinksSet['structure'] === '/p/%postname%' ? 'checked' : '' ?>>
                        <span><strong>Post name (Recommended)</strong> &mdash; <code><?= e(SLATE_URL) ?>/p/sample-post/</code></span>
                    </label>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="radio" name="permalink_structure" value="/%year%/%monthnum%/%postname%" <?= $permalinksSet['structure'] === '/%year%/%monthnum%/%postname%' ? 'checked' : '' ?>>
                        <span><strong>Date and name</strong> &mdash; <code><?= e(SLATE_URL) ?>/<?= date('Y/m') ?>/sample-post/</code></span>
                    </label>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="radio" name="permalink_structure" value="/?p=%id%" <?= $permalinksSet['structure'] === '/?p=%id%' ? 'checked' : '' ?>>
                        <span><strong>Plain</strong> &mdash; <code><?= e(SLATE_URL) ?>/?p=123</code></span>
                    </label>
                </div>
            </div>

            <div class="field-row field-row-2" style="margin-top:20px;">
                <div class="field">
                    <label class="field-label" for="permalink_post_base"><?= __('blog_post_slug_base', 'Post Slug Base') ?></label>
                    <input type="text" id="permalink_post_base" name="permalink_post_base" maxlength="50"
                           value="<?= e($permalinksSet['post_base']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="field-hint">e.g. <code>post</code> or <code>blog</code> (default: <code>post</code>)</div>
                </div>
                <div class="field" style="display:flex;align-items:center;padding-top:24px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" name="permalink_trailing_slash" value="1" <?= $permalinksSet['trailing_slash'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                        <span><strong><?= __('trailing_slash', 'Enforce trailing slashes') ?></strong> (e.g. <code>/about/</code>)</span>
                    </label>
                </div>
            </div>

            <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary" style="margin-top:16px;"><?= __('save_changes', 'Save changes') ?></button>
            <?php endif; ?>
        </form>
    </div>

<?php /* ══════════════════════════════════════════════════════
   TAB: DATE & TIME
══════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'datetime'): ?>

    <div class="card">
        <div class="card-header">
            <h2><?= __('date_and_time', 'Date & Time Settings') ?></h2>
            <p class="card-header-sub"><?= __('date_and_time_sub', 'Choose your local timezone and preferred date/time formatting across the admin and front-end.') ?></p>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_datetime">
            <input type="hidden" name="_tab"    value="datetime">

            <div class="field">
                <label class="field-label" for="timezone"><?= __('timezone', 'Timezone') ?></label>
                <select id="timezone" name="timezone" class="select-searchable" <?= $canEdit ? '' : 'disabled' ?>>
                    <?php foreach (DateTimeZone::listIdentifiers() as $tzId): ?>
                        <option value="<?= e($tzId) ?>" <?= $tzId === $dateTimeSet['timezone'] ? 'selected' : '' ?>>
                            <?= e($tzId) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint">Current local server time: <strong><?= e(I18n::localDate('Y-m-d H:i:s T')) ?></strong></div>
            </div>

            <div class="field" style="margin-top:16px;">
                <label class="field-label"><?= __('date_format', 'Date Format') ?></label>
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                    <?php
                    $dFormats = ['F j, Y', 'Y-m-d', 'm/d/Y', 'd/m/Y'];
                    $isCustomD = !in_array($dateTimeSet['date_format'], $dFormats, true);
                    foreach ($dFormats as $df): ?>
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="radio" name="date_format" value="<?= e($df) ?>" <?= $dateTimeSet['date_format'] === $df ? 'checked' : '' ?>>
                            <span><?= e(I18n::localDate($df)) ?> &mdash; <code><?= e($df) ?></code></span>
                        </label>
                    <?php endforeach; ?>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="radio" name="date_format" value="custom" <?= $isCustomD ? 'checked' : '' ?>>
                        <span>Custom: <input type="text" name="date_format_custom" value="<?= e($dateTimeSet['date_format']) ?>" style="width:140px;display:inline-block;padding:3px 8px;font-size:13px;" <?= $canEdit ? '' : 'disabled' ?>></span>
                    </label>
                </div>
            </div>

            <div class="field" style="margin-top:16px;">
                <label class="field-label"><?= __('time_format', 'Time Format') ?></label>
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                    <?php
                    $tFormats = ['g:i a', 'g:i A', 'H:i'];
                    $isCustomT = !in_array($dateTimeSet['time_format'], $tFormats, true);
                    foreach ($tFormats as $tf): ?>
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="radio" name="time_format" value="<?= e($tf) ?>" <?= $dateTimeSet['time_format'] === $tf ? 'checked' : '' ?>>
                            <span><?= e(I18n::localDate($tf)) ?> &mdash; <code><?= e($tf) ?></code></span>
                        </label>
                    <?php endforeach; ?>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="radio" name="time_format" value="custom" <?= $isCustomT ? 'checked' : '' ?>>
                        <span>Custom: <input type="text" name="time_format_custom" value="<?= e($dateTimeSet['time_format']) ?>" style="width:140px;display:inline-block;padding:3px 8px;font-size:13px;" <?= $canEdit ? '' : 'disabled' ?>></span>
                    </label>
                </div>
            </div>

            <div class="field" style="margin-top:16px;">
                <label class="field-label" for="start_of_week"><?= __('start_of_week', 'Week Starts On') ?></label>
                <select id="start_of_week" name="start_of_week" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="0" <?= $dateTimeSet['start_of_week'] === 0 ? 'selected' : '' ?>><?= __('sunday', 'Sunday') ?></option>
                    <option value="1" <?= $dateTimeSet['start_of_week'] === 1 ? 'selected' : '' ?>><?= __('monday', 'Monday') ?></option>
                </select>
            </div>

            <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary" style="margin-top:16px;"><?= __('save_changes', 'Save changes') ?></button>
            <?php endif; ?>
        </form>
    </div>

<?php /* ══════════════════════════════════════════════════════
   TAB: MEDIA
══════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'media'): ?>

    <div class="card">
        <div class="card-header">
            <h2><?= __('media_settings', 'Media & Upload Settings') ?></h2>
            <p class="card-header-sub"><?= __('media_settings_sub', 'Configure dimensions for automatically generated image sizes, compression options, and file upload limits.') ?></p>
        </div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_media_settings">
            <input type="hidden" name="_tab"    value="media">

            <div class="field-group-title" style="font-weight:600;margin-bottom:12px;"><?= __('image_sizes', 'Image Sizes') ?></div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label"><?= __('thumbnail_size', 'Thumbnail Size') ?></label>
                    <div class="dim-group">
                        <div class="dim-input">
                            <span class="dim-input-tag">W</span>
                            <input type="number" name="media_thumb_w" value="<?= (int)$mediaSet['thumb_w'] ?>" min="10" max="2000" <?= $canEdit ? '' : 'disabled' ?>>
                            <span class="dim-input-unit">px</span>
                        </div>
                        <div class="dim-input">
                            <span class="dim-input-tag">H</span>
                            <input type="number" name="media_thumb_h" value="<?= (int)$mediaSet['thumb_h'] ?>" min="10" max="2000" <?= $canEdit ? '' : 'disabled' ?>>
                            <span class="dim-input-unit">px</span>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label"><?= __('medium_size', 'Medium Size') ?></label>
                    <div class="dim-group">
                        <div class="dim-input">
                            <span class="dim-input-tag">Max W</span>
                            <input type="number" name="media_medium_w" value="<?= (int)$mediaSet['medium_w'] ?>" min="10" max="4000" <?= $canEdit ? '' : 'disabled' ?>>
                            <span class="dim-input-unit">px</span>
                        </div>
                        <div class="dim-input">
                            <span class="dim-input-tag">Max H</span>
                            <input type="number" name="media_medium_h" value="<?= (int)$mediaSet['medium_h'] ?>" min="10" max="4000" <?= $canEdit ? '' : 'disabled' ?>>
                            <span class="dim-input-unit">px</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="field-row field-row-2" style="margin-top:16px;">
                <div class="field">
                    <label class="field-label"><?= __('large_size', 'Large Size') ?></label>
                    <div class="dim-group">
                        <div class="dim-input">
                            <span class="dim-input-tag">Max W</span>
                            <input type="number" name="media_large_w" value="<?= (int)$mediaSet['large_w'] ?>" min="10" max="8000" <?= $canEdit ? '' : 'disabled' ?>>
                            <span class="dim-input-unit">px</span>
                        </div>
                        <div class="dim-input">
                            <span class="dim-input-tag">Max H</span>
                            <input type="number" name="media_large_h" value="<?= (int)$mediaSet['large_h'] ?>" min="10" max="8000" <?= $canEdit ? '' : 'disabled' ?>>
                            <span class="dim-input-unit">px</span>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="media_max_upload_mb"><?= __('max_upload_size', 'Max Upload File Size (MB)') ?></label>
                    <div class="dim-group">
                        <div class="dim-input">
                            <input type="number" id="media_max_upload_mb" name="media_max_upload_mb" value="<?= (int)$mediaSet['max_upload_mb'] ?>" min="1" max="500" <?= $canEdit ? '' : 'disabled' ?>>
                            <span class="dim-input-unit">MB</span>
                        </div>
                    </div>
                    <div class="field-hint">Server limit: <?= ini_get('upload_max_filesize') ?></div>
                </div>
            </div>

            <div class="field" style="margin-top:20px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="media_webp_convert" value="1" <?= $mediaSet['webp_convert'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                    <span><strong><?= __('webp_conversion', 'Automatically convert uploaded JPEG & PNG images to WebP') ?></strong> (reduces bandwidth and improves page load speed)</span>
                </label>
            </div>

            <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary" style="margin-top:16px;"><?= __('save_changes', 'Save changes') ?></button>
            <?php endif; ?>
        </form>
    </div>

<?php /* ══════════════════════════════════════════════════════
   TAB: SMTP
══════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'smtp'): ?>

<?php
    /* ── SMTP status flags for the status card ─────────────── */
    $_phpmailerInstalled = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    $_appSecretOk        = slate_has_app_secret();
    $_smtpMode           = $_phpmailerInstalled && $smtp['host'] !== '';
    // Report the ACTUAL driver Mailer::send will use, not just "SMTP via
    // PHPMailer" for every non-empty host. The HTTPS-API drivers (Graph,
    // Resend) bypass PHPMailer entirely, and the status card lying about
    // the active path made debugging "why isn't my Graph send working"
    // pointlessly hard.
    if ($smtp['host'] === 'graph.microsoft.com') {
        $_deliveryMode = $oauth['connected']
            ? 'Microsoft Graph API (OAuth connected)'
            : 'Microsoft Graph API (OAuth NOT connected)';
    } elseif ($smtp['host'] === 'api.resend.com') {
        $_deliveryMode = 'Resend HTTPS API';
    } elseif ($_smtpMode) {
        $_deliveryMode = Database::setting('smtp_auth_type') === 'xoauth2'
            ? 'SMTP + OAuth 2.0 (XOAUTH2)'
            : 'SMTP via PHPMailer';
    } else {
        $_deliveryMode = $_phpmailerInstalled ? 'PHP mail() (no host set)' : 'PHP mail() (PHPMailer not installed)';
    }

    /* ── Provider quick-setup presets ───────────────────────────
       Picking one auto-fills host/port/encryption (and a fixed
       username where the provider mandates one, e.g. SendGrid's
       literal "apikey"). `hint` is trusted static HTML rendered
       server-side and toggled by JS — never user input, so the
       embedded links/markup are safe. */
    $smtpProviders = [
        'gmail' => [
            'label' => 'Gmail / Google Workspace',
            'host'  => 'smtp.gmail.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_gmail', 'Use an <strong>App Password</strong>, not your normal password. Turn on 2-Step Verification, then generate one at <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a>. The username is your full Gmail/Workspace address.'),
        ],
        'outlook' => [
            'label' => 'Outlook.com / Hotmail / Live',
            'host'  => 'smtp-mail.outlook.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_outlook', 'Personal Microsoft accounts now require an <strong>app password</strong> (Security &rarr; Advanced security options) and that SMTP AUTH be allowed. The username is your full email address.'),
        ],
        'office365' => [
            'label' => 'Microsoft 365 / Office 365',
            'host'  => 'smtp.office365.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_office365', 'Basic auth is deprecated. An admin must enable <strong>Authenticated SMTP</strong> for the mailbox (Microsoft 365 admin &rarr; Active users &rarr; Mail), and the account usually needs an app password. The username is the full mailbox address.'),
        ],
        'yahoo' => [
            'label' => 'Yahoo Mail',
            'host'  => 'smtp.mail.yahoo.com', 'port' => 465, 'enc' => 'ssl',
            'hint'  => __('smtp_hint_yahoo', 'Generate an <strong>app password</strong> at Account Security &rarr; Generate app password. The username is your full Yahoo address.'),
        ],
        'icloud' => [
            'label' => 'iCloud Mail',
            'host'  => 'smtp.mail.me.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_icloud', 'Requires an <strong>app-specific password</strong> created at <a href="https://appleid.apple.com" target="_blank" rel="noopener">appleid.apple.com</a> (Sign-In &amp; Security). The username is your iCloud email address.'),
        ],
        'zoho' => [
            'label' => 'Zoho Mail',
            'host'  => 'smtp.zoho.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_zoho', 'Enable IMAP/SMTP access in Zoho settings. With 2FA on, create an <strong>app-specific password</strong>. Some regions use <code>smtp.zoho.eu</code> / <code>smtp.zoho.in</code>.'),
        ],
        'sendgrid' => [
            'label' => 'SendGrid',
            'host'  => 'smtp.sendgrid.net', 'port' => 587, 'enc' => 'tls', 'user' => 'apikey',
            'hint'  => __('smtp_hint_sendgrid', 'Username is the literal word <code>apikey</code> (already filled in); the password is your <strong>API key</strong>. Create one under Settings &rarr; API Keys with Mail Send permission.'),
        ],
        'mailgun' => [
            'label' => 'Mailgun',
            'host'  => 'smtp.mailgun.org', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_mailgun', 'Use the SMTP credentials from your domain&rsquo;s Sending &rarr; Domain settings (e.g. <code>postmaster@your-domain</code>). EU domains use <code>smtp.eu.mailgun.org</code>.'),
        ],
        'ses' => [
            'label' => 'Amazon SES',
            'host'  => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_ses', 'Change the host region to match yours (e.g. <code>email-smtp.eu-west-1.amazonaws.com</code>). Generate dedicated <strong>SMTP credentials</strong> in the SES console &mdash; these are NOT your AWS access keys.'),
        ],
        'brevo' => [
            'label' => 'Brevo (Sendinblue)',
            'host'  => 'smtp-relay.brevo.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_brevo', 'Username is your Brevo account email; the password is the <strong>SMTP key</strong> from Transactional &rarr; Settings &rarr; SMTP &amp; API.'),
        ],
        'postmark' => [
            'label' => 'Postmark',
            'host'  => 'smtp.postmarkapp.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_postmark', 'Use your <strong>Server API Token</strong> as BOTH the username and the password. Found under your server&rsquo;s API Tokens tab.'),
        ],
        'mailjet' => [
            'label' => 'Mailjet',
            'host'  => 'in-v3.mailjet.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_mailjet', 'Username is your <strong>API Key</strong> and password is your <strong>Secret Key</strong> from Account &rarr; API Key Management.'),
        ],
        'fastmail' => [
            'label' => 'Fastmail',
            'host'  => 'smtp.fastmail.com', 'port' => 465, 'enc' => 'ssl',
            'hint'  => __('smtp_hint_fastmail', 'Create an <strong>app password</strong> (Settings &rarr; Privacy &amp; Security &rarr; App passwords) scoped to SMTP. The username is your full Fastmail address.'),
        ],
        'smtp2go' => [
            'label' => 'SMTP2GO',
            'host'  => 'mail.smtp2go.com', 'port' => 587, 'enc' => 'tls',
            'hint'  => __('smtp_hint_smtp2go', 'Create an SMTP user under Sending &rarr; SMTP Users and use those credentials. Port 2525 also works if 587 is blocked.'),
        ],
        // Resend uses HTTPS-only delivery — no SMTP port involved. Useful when
        // the server's outbound 587/465 are firewalled (most shared hosts).
        // Mailer.php detects host="api.resend.com" and routes through cURL
        // instead of PHPMailer. Username is unused; password = API key.
        'resend' => [
            'label' => 'Resend (HTTPS API — bypasses SMTP block)',
            'host'  => 'api.resend.com', 'port' => 443, 'enc' => 'ssl',
            'hint'  => __('smtp_hint_resend', 'Sends over HTTPS (port 443) instead of SMTP — use this if your host blocks outbound port 587/465. Free up to 3K emails/month. Create an API key at <a href="https://resend.com/api-keys" target="_blank" rel="noopener">resend.com/api-keys</a> and paste it into the <strong>Password</strong> field below. Verify your sending domain at <a href="https://resend.com/domains" target="_blank" rel="noopener">resend.com/domains</a> first or Resend will reject sends from unverified addresses. Username is ignored.'),
        ],
        // Microsoft Graph — HTTPS-only delivery for Microsoft 365 mailboxes.
        // Reuses the OAuth tokens already connected via the "Modern auth"
        // section below; bypasses port 587 entirely. Requires the Mail.Send
        // delegated permission in the Azure app + admin consent.
        'msgraph' => [
            'label' => 'Microsoft 365 (Graph API — recommended)',
            'host'  => 'graph.microsoft.com', 'port' => 443, 'enc' => 'ssl',
            'hint'  => __('smtp_hint_msgraph', 'The easy way to send from Microsoft 365 / Outlook — like WP Mail SMTP. Delivers over HTTPS:443 (works even when your host blocks port 587/465) and uses OAuth, so no app password is needed. <strong>Three steps:</strong> (1) save this provider choice; (2) below in <em>Modern authentication</em> paste your Azure <strong>Client ID + Client secret</strong> and the mailbox email, then click <strong>Connect</strong>; (3) accept the Microsoft consent screen. The Azure app needs the <strong>Mail.Send</strong> delegated permission with admin consent — nothing else. SMTP username and password fields are ignored.'),
        ],
    ];

    /* Detect which preset (if any) matches the saved host, to preselect it. */
    $smtpCurrentProvider = '';
    $_h = strtolower(trim((string)$smtp['host']));
    if ($_h !== '') {
        foreach ($smtpProviders as $_k => $_p) {
            if ($_h === strtolower($_p['host'])) { $smtpCurrentProvider = $_k; break; }
        }
        if ($smtpCurrentProvider === ''
            && strncmp($_h, 'email-smtp.', 11) === 0
            && substr($_h, -14) === '.amazonaws.com') {
            $smtpCurrentProvider = 'ses';
        }
    }

    /* Compact map the picker JS reads to fill the fields. */
    $smtpProviderJs = [];
    foreach ($smtpProviders as $_k => $_p) {
        $smtpProviderJs[$_k] = [
            'host' => $_p['host'], 'port' => $_p['port'],
            'enc'  => $_p['enc'],  'user' => $_p['user'] ?? '',
        ];
    }
?>

    <style>
    /* ─── SMTP status card ──────────────────────────────────── */
    .smtp-status-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
        margin-bottom: 4px;
    }
    .smtp-status-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 14px;
        background: var(--surface-2, #f8f9fa);
        border: 1px solid var(--border);
        border-radius: var(--radius);
    }
    .smtp-status-icon {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-top: 1px;
    }
    .smtp-status-icon.ok   { background: #d1fae5; color: #059669; }
    .smtp-status-icon.warn { background: #fef3c7; color: #d97706; }
    .smtp-status-icon.err  { background: #fee2e2; color: #dc2626; }
    .smtp-status-label { font-size: 11.5px; color: var(--muted); margin-bottom: 2px; }
    .smtp-status-value { font-size: 13px; font-weight: 500; color: var(--text); word-break: break-all; }

    /* ─── Password field with toggle ───────────────────────── */
    .pass-wrap { position: relative; }
    .pass-wrap input[type=password],
    .pass-wrap input[type=text] { padding-right: 40px; width: 100%; box-sizing: border-box; }
    .pass-toggle {
        position: absolute; right: 0; top: 0; bottom: 0;
        width: 38px; display: flex; align-items: center; justify-content: center;
        background: none; border: none; cursor: pointer; color: var(--muted);
        padding: 0; border-radius: 0 var(--radius) var(--radius) 0;
    }
    .pass-toggle:hover { color: var(--text); }
    .pass-toggle svg { width: 16px; height: 16px; }

    /* ─── Warning inline note ───────────────────────────────── */
    .field-warn {
        display: flex; align-items: flex-start; gap: 6px;
        margin-top: 5px; padding: 7px 10px;
        background: #fffbeb; border: 1px solid #fde68a;
        border-radius: var(--radius-sm); font-size: 12px; color: #92400e;
    }
    .field-warn svg { width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px; }

    /* ─── Provider quick-setup hint ─────────────────────────── */
    .smtp-provider-hint {
        display: flex; align-items: flex-start; gap: 8px;
        margin-top: 8px; padding: 10px 12px;
        background: var(--info-soft, #eff6ff);
        border: 1px solid var(--info-border, #bfdbfe);
        border-radius: var(--radius-sm, 8px);
        font-size: 12.5px; line-height: 1.5; color: var(--text-2, #334155);
    }
    .smtp-provider-hint[hidden] { display: none; }
    .smtp-provider-hint svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 2px; color: var(--info, #2563eb); }
    .smtp-provider-hint a { font-weight: 600; }
    .smtp-provider-hint code {
        background: rgba(0,0,0,.06); padding: 1px 5px; border-radius: 4px;
        font-size: 11.5px; word-break: break-all;
    }
    /* ── Step-2 OAuth CTA banner (msgraph mode) ────────────── */
    .oauth-step-cta {
        display: flex; align-items: flex-start; gap: 10px;
        margin: 0 0 14px; padding: 12px 14px;
        background: linear-gradient(135deg, rgba(37,99,235,.10), rgba(59,130,246,.05));
        border: 1px solid var(--info-border, #bfdbfe);
        border-left: 3px solid var(--info, #2563eb);
        border-radius: var(--radius-sm, 8px);
        font-size: 13px; line-height: 1.45; color: var(--text, #1e293b);
    }
    .oauth-step-cta[hidden] { display: none; }
    .oauth-step-cta svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px; color: var(--info, #2563eb); }
    .oauth-step-cta strong { display: block; margin-bottom: 2px; font-size: 13.5px; }
    /* When msgraph is the chosen provider, the OAuth card IS the setup —
       give it a subtle highlight so it doesn't read as an optional aside. */
    .card.oauth-card-primary {
        box-shadow: 0 0 0 2px rgba(37,99,235,.18), var(--shadow-sm, 0 1px 2px rgba(0,0,0,.06));
    }
    /* ── API-mode banner inside the SMTP form (Graph / Resend) ──── */
    .api-mode-banner {
        display: flex; align-items: flex-start; gap: 10px;
        margin: 0 0 14px; padding: 12px 14px;
        background: linear-gradient(135deg, rgba(37,99,235,.10), rgba(59,130,246,.05));
        border: 1px solid var(--info-border, #bfdbfe);
        border-left: 3px solid var(--info, #2563eb);
        border-radius: var(--radius-sm, 8px);
        font-size: 13px; line-height: 1.45; color: var(--text, #1e293b);
    }
    .api-mode-banner[hidden] { display: none; }
    .api-mode-banner svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px; color: var(--info, #2563eb); }
    .api-mode-banner strong { display: block; margin-bottom: 2px; font-size: 13.5px; }
    /* ── In-form section dividers (Sender identity / Connection) ── */
    .setup-section-divider {
        display: flex; align-items: center; gap: 10px;
        margin: 18px 0 10px;
        font-size: 11.5px; font-weight: 600; letter-spacing: .04em;
        text-transform: uppercase;
        color: var(--muted, #94a3b8);
    }
    .setup-section-divider::after {
        content: ''; flex: 1; height: 1px;
        background: var(--border, #e2e8f0);
    }
    /* ── Connection status pills (inline, below the form) ────────── */
    .conn-status {
        display: flex; align-items: flex-start; gap: 10px;
        padding: 12px 14px; border-radius: var(--radius-sm, 8px);
        font-size: 13px; line-height: 1.45;
    }
    .conn-status svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
    .conn-status strong { font-size: 13.5px; }
    .conn-status-ok {
        background: var(--success-soft, #ecfdf5);
        border: 1px solid var(--success-border, #a7f3d0);
        color: var(--text, #064e3b);
    }
    .conn-status-ok svg { color: var(--success, #059669); }
    .conn-status-warn {
        background: var(--warn-soft, #fffbeb);
        border: 1px solid var(--warn-border, #fcd34d);
        color: var(--text, #78350f);
    }
    .conn-status-warn svg { color: var(--warn, #d97706); }
    /* ── Test delivery two-column grid (Verify | Send test) ──── */
    .test-delivery-grid {
        display: grid; gap: 14px;
        grid-template-columns: 1fr 1fr;
    }
    @media (max-width: 640px) {
        .test-delivery-grid { grid-template-columns: 1fr; }
    }
    .test-delivery-cell {
        padding: 14px;
        background: var(--surface-2, #f8fafc);
        border: 1px solid var(--border, #e2e8f0);
        border-radius: var(--radius-sm, 8px);
    }
    .test-delivery-h3 {
        margin: 0 0 6px; font-size: 14px; font-weight: 600;
        color: var(--text, #1e293b);
    }
    /* ─── Mailer card grid (WP Mail SMTP-style picker) ──────── */
    .mailer-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px;
        margin-top: 4px;
    }
    @media (max-width: 1100px) { .mailer-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    @media (max-width: 760px)  { .mailer-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 460px)  { .mailer-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .mailer-card {
        position: relative;
        display: flex; flex-direction: column; align-items: center;
        gap: 8px; padding: 14px 8px 12px;
        background: var(--surface, #fff);
        border: 2px solid var(--border, #e2e8f0);
        border-radius: var(--radius-sm, 10px);
        cursor: pointer; user-select: none;
        transition: border-color .15s, transform .1s, box-shadow .15s;
    }
    .mailer-card:hover {
        border-color: var(--info-border, #93c5fd);
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(0,0,0,.05);
    }
    .mailer-card.is-selected {
        border-color: var(--info, #2563eb);
        background: rgba(37,99,235,.04);
        box-shadow: 0 0 0 1px var(--info, #2563eb) inset;
    }
    .mailer-card input[type=radio] {
        position: absolute; opacity: 0; pointer-events: none;
    }
    .mailer-logo {
        display: flex; align-items: center; justify-content: center;
        width: 100%; height: 56px;
        border-radius: 6px;
        overflow: hidden;
    }
    .mailer-name {
        font-size: 12.5px; font-weight: 600;
        color: var(--text, #1e293b);
        text-align: center; line-height: 1.2;
    }
    /* Microsoft 4-square mark (universally readable as "Microsoft"
       without copying the official logo's exact proportions). */
    .ms-grid {
        display: grid;
        grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr;
        gap: 2px; width: 36px; height: 36px;
    }
    .ms-grid > span { border-radius: 1px; }
    /* RECOMMENDED ribbon, top-right corner */
    .mailer-badge {
        position: absolute; top: -8px; right: -2px;
        background: linear-gradient(135deg, #f97316, #ea580c);
        color: #fff;
        font-size: 9px; font-weight: 800; letter-spacing: .07em;
        padding: 3px 7px;
        border-radius: 3px;
        box-shadow: 0 1px 3px rgba(234,88,12,.4);
        text-transform: uppercase;
        white-space: nowrap;
        pointer-events: none;
    }
    .mailer-card.is-selected .mailer-badge {
        background: linear-gradient(135deg, #ea580c, #c2410c);
    }
    /* ─── Setup Wizard launcher banner ─────────────────────── */
    .wizard-launcher {
        display: flex; align-items: center; gap: 14px;
        padding: 14px 18px; margin-bottom: 16px;
        background: linear-gradient(135deg, rgba(37,99,235,.10), rgba(99,102,241,.08));
        border: 1px solid var(--info-border, #bfdbfe);
        border-left: 3px solid var(--info, #2563eb);
        border-radius: var(--radius-sm, 10px);
    }
    .wizard-launcher.is-compact {
        background: var(--surface-2, #f8fafc);
        border-color: var(--border, #e2e8f0);
        border-left: 3px solid var(--border, #cbd5e1);
    }
    .wizard-launcher-icon {
        flex: none;
        display: flex; align-items: center; justify-content: center;
        width: 36px; height: 36px;
        background: var(--info, #2563eb); color: #fff;
        border-radius: 8px;
    }
    .wizard-launcher.is-compact .wizard-launcher-icon { background: var(--success, #10b981); }
    .wizard-launcher-icon svg { width: 18px; height: 18px; }
    .wizard-launcher-body { flex: 1; min-width: 0; font-size: 13px; line-height: 1.45; }
    .wizard-launcher-body strong { display: block; font-size: 14px; margin-bottom: 1px; color: var(--text, #1e293b); }
    .wizard-launcher-body div { color: var(--muted, #64748b); }
    .wizard-launcher-btn { flex: none; }
    @media (max-width: 640px) {
        .wizard-launcher { flex-direction: column; align-items: stretch; text-align: center; }
        .wizard-launcher-icon { align-self: center; }
    }
    /* ════════════════════════════════════════════════════════
       SETUP WIZARD — fullscreen modal with modern progress bar
       Desktop: nearly fullscreen with reasonable safe-area
       margins. Mobile: edge-to-edge, no rounding, full viewport.
    ════════════════════════════════════════════════════════ */
    .wizard-overlay {
        position: fixed; inset: 0; z-index: 1000;
        display: flex; align-items: center; justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.58);
        backdrop-filter: blur(10px) saturate(140%);
        -webkit-backdrop-filter: blur(10px) saturate(140%);
        overscroll-behavior: contain;
        animation: wiz-fade-in .18s ease-out;
    }
    .wizard-overlay[hidden] { display: none; }
    @keyframes wiz-fade-in { from { opacity: 0; } to { opacity: 1; } }
    .wizard-modal {
        position: relative;
        width: 100%;
        max-width: 1280px;
        height: calc(100vh - 48px);
        max-height: 920px;
        display: flex; flex-direction: column;
        background: var(--surface, #fff);
        border-radius: 18px;
        box-shadow:
            0 32px 96px -16px rgba(15, 23, 42, 0.45),
            0 0 0 1px rgba(255, 255, 255, 0.06);
        overflow: hidden;
        animation: wiz-pop .22s cubic-bezier(.22,1.2,.36,1);
    }
    @keyframes wiz-pop {
        from { opacity: 0; transform: translateY(12px) scale(.985); }
        to   { opacity: 1; transform: translateY(0)    scale(1);    }
    }
    /* ── Mobile: full-screen takeover, no rounding ─────────── */
    @media (max-width: 768px) {
        .wizard-overlay { padding: 0; }
        .wizard-modal {
            width: 100vw; height: 100vh;
            max-width: 100vw; max-height: 100vh;
            border-radius: 0;
            box-shadow: none;
        }
    }

    /* ── Close button (top-right floating) ─────────────────── */
    .wizard-close {
        position: absolute; top: 18px; right: 20px;
        width: 38px; height: 38px;
        display: flex; align-items: center; justify-content: center;
        background: rgba(255, 255, 255, 0.7);
        border: 1px solid var(--border, #e2e8f0);
        font-size: 22px; line-height: 1;
        color: var(--muted, #64748b);
        cursor: pointer;
        border-radius: 10px;
        backdrop-filter: blur(4px);
        z-index: 5;
        transition: background .15s, color .15s, transform .12s;
    }
    .wizard-close:hover {
        background: #fff;
        color: var(--text, #1e293b);
        transform: scale(1.05);
    }
    .wizard-close:active { transform: scale(.95); }

    /* ── Header (title + step bar) ──────────────────────────── */
    .wizard-header {
        padding: 26px 32px 22px;
        border-bottom: 1px solid var(--border, #e2e8f0);
        background: linear-gradient(180deg, #fafbfc 0%, #ffffff 100%);
        flex-shrink: 0;
    }
    .wizard-header h2 {
        margin: 0 0 22px;
        font-size: 22px; font-weight: 700;
        color: var(--text, #1e293b);
        letter-spacing: -0.01em;
    }

    /* ── Modern step bar ──────────────────────────────────────
       Each step: circular badge + label, connected by a track
       that fills with primary color as the user progresses.
       Active step gets a soft ring + scale-up to make the
       current position visually obvious at a glance. */
    .wizard-steps {
        display: flex;
        gap: 0;
        margin: 0;
        padding: 0;
        list-style: none;
        font-size: 12.5px;
    }
    .wizard-step {
        flex: 1;
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 10px;
        padding: 0;
        color: var(--muted, #94a3b8);
        font-weight: 600;
        letter-spacing: 0.01em;
        position: relative;
        min-width: 0;
    }
    /* Connecting track between steps */
    .wizard-step:not(:last-child)::after {
        content: '';
        flex: 1;
        height: 2px;
        margin: 0 10px;
        background: var(--border, #e2e8f0);
        border-radius: 2px;
        transition: background .25s ease;
    }
    .wizard-step.is-done::after,
    .wizard-step.is-active:not(:last-child)::after {
        background: linear-gradient(90deg, var(--success, #10b981) 0%, var(--info, #2563eb) 100%);
    }
    .wizard-step-num {
        flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        width: 30px; height: 30px;
        border-radius: 50%;
        background: var(--surface-2, #f1f5f9);
        color: var(--muted, #94a3b8);
        font-size: 13px; font-weight: 800;
        position: relative;
        transition: all .25s ease;
        border: 2px solid transparent;
    }
    .wizard-step.is-active {
        color: var(--info, #2563eb);
    }
    .wizard-step.is-active .wizard-step-num {
        background: var(--info, #2563eb);
        color: #fff;
        box-shadow: 0 0 0 5px rgba(37, 99, 235, 0.14),
                    0 4px 12px -2px rgba(37, 99, 235, 0.4);
        transform: scale(1.08);
    }
    .wizard-step.is-done {
        color: var(--success, #059669);
    }
    .wizard-step.is-done .wizard-step-num {
        background: var(--success, #10b981);
        color: transparent;
        position: relative;
    }
    .wizard-step.is-done .wizard-step-num::before {
        content: '';
        position: absolute;
        width: 14px; height: 8px;
        border-left: 2.5px solid #fff;
        border-bottom: 2.5px solid #fff;
        transform: rotate(-45deg) translateY(-2px);
    }
    @media (max-width: 640px) {
        .wizard-header { padding: 20px 18px 18px; }
        .wizard-header h2 { font-size: 18px; margin-bottom: 18px; }
        .wizard-step { font-size: 11px; gap: 6px; }
        .wizard-step:not(:last-child)::after { margin: 0 6px; }
        .wizard-step-num { width: 26px; height: 26px; font-size: 12px; }
    }
    @media (max-width: 460px) {
        /* Hide step labels on tiny screens, keep just the numbered dots */
        .wizard-step { color: transparent; gap: 0; }
        .wizard-step.is-active, .wizard-step.is-done { color: transparent; }
    }

    /* ── Form / panels ──────────────────────────────────────── */
    .wizard-form {
        flex: 1; min-height: 0;
        display: flex; flex-direction: column;
    }
    .wizard-panel {
        padding: 36px 40px;
        overflow-y: auto;
        flex: 1; min-height: 0;
        animation: wiz-panel-in .25s ease-out;
    }
    @keyframes wiz-panel-in {
        from { opacity: 0; transform: translateY(6px); }
        to   { opacity: 1; transform: translateY(0);   }
    }
    .wizard-panel[hidden] { display: none; }
    .wizard-panel-title {
        margin: 0 0 8px;
        font-size: 24px; font-weight: 700;
        color: var(--text, #1e293b);
        letter-spacing: -0.01em;
    }
    .wizard-panel-intro {
        margin: 0 0 28px;
        font-size: 15px; line-height: 1.55;
        color: var(--muted, #64748b);
        max-width: 720px;
    }
    .wizard-panel .field { margin-bottom: 18px; }
    .wizard-panel .field-label {
        font-size: 13.5px;
        font-weight: 600;
        margin-bottom: 6px;
        color: var(--text, #1e293b);
    }
    .wizard-panel input[type=text],
    .wizard-panel input[type=email],
    .wizard-panel input[type=password],
    .wizard-panel input[type=number],
    .wizard-panel select {
        font-size: 14.5px;
        padding: 10px 14px;
    }
    /* Wizard's mailer grid: more breathing room than the main
       page since the modal is wider. */
    .wizard-panel .mailer-grid {
        gap: 14px;
    }
    .wizard-panel .mailer-card {
        padding: 18px 10px 14px;
    }
    .wizard-panel .mailer-logo {
        height: 64px;
    }

    @media (max-width: 768px) {
        .wizard-panel { padding: 24px 20px; }
        .wizard-panel-title { font-size: 20px; }
        .wizard-panel-intro { font-size: 14px; margin-bottom: 20px; }
    }

    /* ── Footer (action row) ───────────────────────────────── */
    .wizard-footer {
        display: flex; align-items: center; gap: 10px;
        padding: 18px 32px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        border-top: 1px solid var(--border, #e2e8f0);
        flex-shrink: 0;
    }
    .wizard-footer .btn {
        font-size: 14px;
        padding: 10px 20px;
        min-height: 40px;
    }
    .wizard-footer .btn-primary {
        font-weight: 600;
        box-shadow: 0 1px 3px rgba(37, 99, 235, 0.2),
                    0 4px 12px -2px rgba(37, 99, 235, 0.25);
    }
    @media (max-width: 640px) {
        .wizard-footer { padding: 14px 18px; }
        .wizard-footer .btn { padding: 10px 14px; font-size: 13.5px; }
    }

    /* ── Summary box on step 4 ─────────────────────────────── */
    .wizard-summary {
        margin: 0;
        display: grid;
        grid-template-columns: max-content 1fr;
        gap: 12px 20px;
        padding: 20px 22px;
        background: linear-gradient(180deg, #fafbfc 0%, #f4f6fa 100%);
        border: 1px solid var(--border, #e2e8f0);
        border-radius: 12px;
        font-size: 14px;
        max-width: 720px;
    }
    .wizard-summary dt {
        color: var(--muted, #64748b);
        font-weight: 500;
        font-size: 13px;
    }
    .wizard-summary dd {
        margin: 0;
        color: var(--text, #1e293b);
        font-weight: 600;
        word-break: break-all;
    }
    .wizard-next-step {
        margin-top: 18px;
        padding: 14px 18px;
        background: linear-gradient(135deg, rgba(37,99,235,0.08), rgba(99,102,241,0.05));
        border: 1px solid var(--info-border, #bfdbfe);
        border-left: 3px solid var(--info, #2563eb);
        border-radius: 10px;
        font-size: 14px; line-height: 1.55;
        color: var(--text, #1e293b);
        max-width: 720px;
    }
    .wizard-next-step strong { display: block; margin-bottom: 4px; }
    body.wizard-open { overflow: hidden; }
    /* ─── Backup Connection (collapsible details card) ──────── */
    .backup-card[open] { padding-bottom: 22px; }
    .backup-summary {
        list-style: none;
        display: flex; align-items: center; gap: 14px;
        cursor: pointer;
        padding: 16px 4px 6px;
        margin: -6px 0 6px;
        user-select: none;
    }
    .backup-summary::-webkit-details-marker { display: none; }
    .backup-summary::after {
        content: '▾';
        margin-left: auto;
        color: var(--muted, #94a3b8);
        font-size: 13px;
        transition: transform .15s;
    }
    .backup-card[open] .backup-summary::after { transform: rotate(180deg); }
    .backup-summary-title {
        font-size: 16px; font-weight: 600;
        color: var(--text, #1e293b);
        display: inline-flex; align-items: center;
    }
    .backup-summary-state {
        display: flex; align-items: center; gap: 10px;
        font-size: 13px;
    }
    .pill {
        display: inline-flex; align-items: center;
        padding: 2px 8px; border-radius: 999px;
        font-size: 11.5px; font-weight: 600;
        letter-spacing: .02em;
    }
    .pill-ok    { background: var(--success-soft, #ecfdf5); color: var(--success, #059669); }
    .pill-warn  { background: var(--warn-soft,    #fffbeb); color: var(--warn,    #d97706); }
    .pill-muted { background: var(--surface-2,    #f1f5f9); color: var(--muted,   #64748b); }
    </style>

    <!-- ── Status card ──────────────────────────────────────── -->
    <div class="card">
        <div class="card-header">
            <h2><?= __('email_delivery_status', 'Email delivery status') ?></h2>
        </div>
        <div class="smtp-status-grid">

            <!-- PHPMailer -->
            <div class="smtp-status-item">
                <div class="smtp-status-icon <?= $_phpmailerInstalled ? 'ok' : 'warn' ?>">
                    <?php if ($_phpmailerInstalled): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/><path d="M12 3L2 21h20L12 3z"/></svg>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="smtp-status-label">PHPMailer</div>
                    <div class="smtp-status-value">
                        <?= $_phpmailerInstalled ? __('installed', 'Installed') : __('not_installed', 'Not installed') ?>
                    </div>
                </div>
            </div>

            <!-- APP_SECRET -->
            <div class="smtp-status-item">
                <div class="smtp-status-icon <?= $_appSecretOk ? 'ok' : 'warn' ?>">
                    <?php if ($_appSecretOk): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/><path d="M12 3L2 21h20L12 3z"/></svg>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="smtp-status-label">APP_SECRET</div>
                    <div class="smtp-status-value">
                        <?= $_appSecretOk ? __('configured', 'Configured') : __('not_configured', 'Not configured') ?>
                    </div>
                </div>
            </div>

            <!-- Delivery mode -->
            <?php
                // Icon state reflects readiness, not just "is something set":
                // Graph driver with no OAuth connection = warn (sends will 401);
                // Resend with no API key = warn; otherwise ok if a host is set.
                $_deliveryReady =
                    ($smtp['host'] === 'graph.microsoft.com')
                        ? $oauth['connected']
                        : (($smtp['host'] === 'api.resend.com') ? $smtp['has_pass'] : $_smtpMode);
            ?>
            <div class="smtp-status-item">
                <div class="smtp-status-icon <?= $_deliveryReady ? 'ok' : 'warn' ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                </div>
                <div>
                    <div class="smtp-status-label"><?= __('delivery_mode', 'Delivery mode') ?></div>
                    <div class="smtp-status-value"><?= e($_deliveryMode) ?></div>
                </div>
            </div>

            <!-- From address -->
            <div class="smtp-status-item">
                <div class="smtp-status-icon <?= $smtp['from_email'] !== '' ? 'ok' : 'warn' ?>">
                    <?php if ($smtp['from_email'] !== ''): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/><path d="M12 3L2 21h20L12 3z"/></svg>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="smtp-status-label"><?= __('from_address', 'From address') ?></div>
                    <div class="smtp-status-value">
                        <?= $smtp['from_email'] !== '' ? e($smtp['from_email']) : __('not_set', 'Not set') ?>
                    </div>
                </div>
            </div>

        </div><!-- /.smtp-status-grid -->

        <?php if (!$_phpmailerInstalled): ?>
            <p class="text-muted text-sm" style="margin-top:12px">
                <?= __('phpmailer_hint', 'PHPMailer is not installed. Install it via <code>composer require phpmailer/phpmailer</code> in your project root to enable full SMTP support. Without it, Kohevo falls back to PHP\'s built-in <code>mail()</code> function.') ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- ── Setup Wizard launcher ─────────────────────────────
         Banner that appears above the form. Targets first-time
         users — once a mailer is selected and From email is set,
         the banner switches to a soft "Re-run setup wizard"
         affordance so the visual noise on a configured site is
         minimal. -->
    <?php
        $_wizardCompleted = ($smtp['from_email'] !== '' && (
            $smtp['host'] !== ''
            || $oauth['connected']
        ));
    ?>
    <?php if ($canEdit): ?>
    <div class="wizard-launcher<?= $_wizardCompleted ? ' is-compact' : '' ?>" id="wizardLauncher">
        <div class="wizard-launcher-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        </div>
        <div class="wizard-launcher-body">
            <?php if ($_wizardCompleted): ?>
                <strong><?= __('wizard_done_title', 'Email is set up') ?></strong>
                <div><?= __('wizard_done_body', 'Need to switch providers, change the From address, or start over? Run the setup wizard again.') ?></div>
            <?php else: ?>
                <strong><?= __('wizard_intro_title', 'First time setting up email?') ?></strong>
                <div><?= __('wizard_intro_body', 'The Setup Wizard walks you through choosing a mailer, entering credentials, and sending a test — one screen at a time.') ?></div>
            <?php endif; ?>
        </div>
        <button type="button" class="btn btn-primary wizard-launcher-btn" id="wizardOpen">
            <svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            <?= $_wizardCompleted
                ? e(__('rerun_wizard', 'Re-run wizard'))
                : e(__('launch_wizard', 'Launch Setup Wizard')) ?>
        </button>
    </div>
    <?php endif; ?>

    <!-- ── SMTP settings form ────────────────────────────────── -->
    <div class="card">
        <div class="card-header"><h2><?= __('email_smtp', 'Email (SMTP)') ?></h2></div>
        <p class="text-muted text-sm mb-3">
            <?= __('smtp_intro', "Configure outbound email. Leave the host blank to use the server's <code>mail()</code> function as a fallback.") ?>
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_smtp">
            <input type="hidden" name="_tab"    value="smtp">

            <!-- ── Mailer picker (WP Mail SMTP-style card grid) ─────
                 Brand metadata: each card carries a short_label, the
                 visual treatment (a tinted tile with a stylised mark),
                 and a "recommended" flag for the orange ribbon. We DO
                 NOT use real provider logos to avoid trademark risk —
                 the marks are minimal geometric / typographic glyphs
                 that read as "that provider" without copying their
                 actual artwork. -->
            <?php
                // Brand strip rendered inside each card's logo tile.
                // Keys match $smtpProviders keys plus 'mail' (the PHP
                // mail() fallback) and 'custom' (manual host config).
                $_brands = [
                    'mail'      => ['short' => 'Default (PHP mail)', 'bg' => '#f3eef9', 'fg' => '#8a73c7', 'mark' => '<span style="font-weight:800;font-size:22px;letter-spacing:-1px;color:#8a73c7;font-family:Georgia,serif">php</span>'],
                    'msgraph'   => ['short' => 'Microsoft 365',      'bg' => '#ffffff', 'fg' => '#000',    'recommended' => true,
                                    'mark' => '<span class="ms-grid"><span style="background:#F25022"></span><span style="background:#7FBA00"></span><span style="background:#00A4EF"></span><span style="background:#FFB900"></span></span>'],
                    'gmail'     => ['short' => 'Gmail / Workspace',  'bg' => '#fff',    'fg' => '#EA4335', 'mark' => '<svg viewBox="0 0 24 18" style="width:40px;height:30px"><path fill="#4285F4" d="M2 2v14h4V8l6 4.5L18 8v8h4V2L12 9.5z"/><path fill="#EA4335" d="M2 2v3.5L12 13l10-7.5V2L12 9.5z"/></svg>'],
                    'outlook'   => ['short' => 'Outlook.com',        'bg' => '#fff',    'fg' => '#0078D4', 'mark' => '<span style="font:800 22px system-ui;color:#0078D4">Outlook</span>'],
                    'office365' => ['short' => 'Office 365 (SMTP)',  'bg' => '#fff',    'fg' => '#D83B01', 'mark' => '<span style="font:800 22px system-ui;color:#D83B01">Office</span>'],
                    'resend'    => ['short' => 'Resend',              'bg' => '#fff',    'fg' => '#000',    'recommended' => true,
                                    'mark' => '<span style="font:800 24px ui-monospace,monospace;color:#000;letter-spacing:-1px">Resend</span>'],
                    'sendgrid'  => ['short' => 'SendGrid',            'bg' => '#fff',    'fg' => '#1A82E2', 'mark' => '<span style="font:800 22px system-ui;color:#1A82E2">SendGrid</span>'],
                    'brevo'     => ['short' => 'Brevo',               'bg' => '#fff',    'fg' => '#0B996E', 'mark' => '<span style="font:800 22px system-ui;color:#0B996E">Brevo</span>'],
                    'mailgun'   => ['short' => 'Mailgun',             'bg' => '#fff',    'fg' => '#F06B66', 'mark' => '<span style="font:800 22px system-ui;color:#F06B66">Mailgun</span>'],
                    'ses'       => ['short' => 'Amazon SES',          'bg' => '#fff',    'fg' => '#FF9900', 'mark' => '<span style="font:800 22px system-ui;color:#232F3E">aws<span style="color:#FF9900">·</span></span>'],
                    'postmark'  => ['short' => 'Postmark',            'bg' => '#fff',    'fg' => '#FFD800', 'mark' => '<span style="font:800 22px system-ui;color:#444">Postmark</span>'],
                    'mailjet'   => ['short' => 'Mailjet',             'bg' => '#fff',    'fg' => '#FFB60C', 'mark' => '<span style="font:800 22px system-ui;color:#FFB60C">Mailjet</span>'],
                    'yahoo'     => ['short' => 'Yahoo Mail',          'bg' => '#fff',    'fg' => '#720E9E', 'mark' => '<span style="font:800 24px system-ui;color:#720E9E">Yahoo!</span>'],
                    'icloud'    => ['short' => 'iCloud Mail',         'bg' => '#fff',    'fg' => '#0091FF', 'mark' => '<span style="font:800 22px system-ui;color:#0091FF">iCloud</span>'],
                    'zoho'      => ['short' => 'Zoho Mail',           'bg' => '#fff',    'fg' => '#D44A38', 'mark' => '<span style="font:800 22px system-ui;color:#D44A38">Zoho</span>'],
                    'fastmail'  => ['short' => 'Fastmail',            'bg' => '#fff',    'fg' => '#0067B9', 'mark' => '<span style="font:800 22px system-ui;color:#0067B9">Fastmail</span>'],
                    'smtp2go'   => ['short' => 'SMTP2GO',             'bg' => '#fff',    'fg' => '#E91E63', 'mark' => '<span style="font:800 22px system-ui;color:#E91E63">SMTP2GO</span>'],
                    'custom'    => ['short' => 'Other SMTP',          'bg' => '#f4f6fa', 'fg' => '#64748b', 'mark' => '<svg viewBox="0 0 24 24" style="width:36px;height:36px;color:#64748b" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/></svg>'],
                ];

                // Card order in the grid. Recommended providers come
                // first so first-time users see the easiest path.
                $_cardOrder = [
                    'msgraph', 'resend', 'gmail',
                    'sendgrid', 'brevo', 'mailgun',
                    'ses', 'postmark', 'mailjet',
                    'outlook', 'office365', 'yahoo',
                    'icloud', 'zoho', 'fastmail',
                    'smtp2go', 'custom', 'mail',
                ];

                // The hidden, screen-reader-friendly select is what the
                // existing setApiMode() JS reads from. Cards sync to it
                // on change so we don't have to rewrite that logic.
                $_currentMailer = $smtpCurrentProvider;
                // "mail" represents the no-host PHP mail() fallback.
                if ($_currentMailer === '' && $smtp['host'] === '') {
                    $_currentMailer = 'mail';
                }
            ?>
            <div class="field">
                <label class="field-label" for="smtp_provider"><?= __('mailer_pick', 'Choose your mailer') ?></label>
                <div class="field-hint" style="margin-bottom:10px"><?= __('mailer_pick_hint', 'Pick how Kohevo sends mail. The fields below adapt to your choice — no SMTP credentials needed for OAuth and HTTPS-API providers.') ?></div>

                <!-- Visually-hidden select that drives the existing JS
                     contract. Card clicks below sync into it. -->
                <select id="smtp_provider" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none" <?= $canEdit ? '' : 'disabled' ?> tabindex="-1" aria-hidden="true">
                    <option value=""></option>
                    <?php foreach ($smtpProviders as $_pk => $_pv): ?>
                        <option value="<?= e($_pk) ?>" <?= $_currentMailer === $_pk ? 'selected' : '' ?>><?= e($_pv['label']) ?></option>
                    <?php endforeach; ?>
                    <option value="mail"   <?= $_currentMailer === 'mail' ? 'selected' : '' ?>>Default (PHP mail)</option>
                    <option value="custom" <?= $_currentMailer === 'custom' ? 'selected' : '' ?>>Custom / other</option>
                </select>

                <div class="mailer-grid" role="radiogroup" aria-labelledby="smtp_provider_label">
                    <?php foreach ($_cardOrder as $_pk):
                        $_b = $_brands[$_pk] ?? null;
                        if (!$_b) continue;
                        // Short label preferred for card; fall back to the
                        // long label from the providers array.
                        $_lbl = (string)($_b['short'] ?? '');
                        if ($_lbl === '' && isset($smtpProviders[$_pk])) {
                            $_lbl = (string)$smtpProviders[$_pk]['label'];
                        }
                        $_sel = ($_currentMailer === $_pk);
                    ?>
                        <label class="mailer-card<?= $_sel ? ' is-selected' : '' ?>" data-key="<?= e($_pk) ?>">
                            <input type="radio" name="mailer_choice" value="<?= e($_pk) ?>" <?= $_sel ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                            <?php if (!empty($_b['recommended'])): ?>
                                <span class="mailer-badge"><?= __('recommended', 'RECOMMENDED') ?></span>
                            <?php endif; ?>
                            <div class="mailer-logo" style="background: <?= e((string)($_b['bg'] ?? '#fff')) ?>">
                                <?= $_b['mark'] /* trusted, locally-defined SVG/text */ ?>
                            </div>
                            <div class="mailer-name"><?= e($_lbl) ?></div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- Provider hints (existing, unchanged contract) - one
                     of these unhides when the matching card is picked. -->
                <?php foreach ($smtpProviders as $_pk => $_pv): ?>
                    <div class="smtp-provider-hint" data-provider="<?= e($_pk) ?>" <?= $_currentMailer === $_pk ? '' : 'hidden' ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <div><?= $_pv['hint'] /* trusted static HTML */ ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="smtp-provider-hint" data-provider="mail" <?= $_currentMailer === 'mail' ? '' : 'hidden' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <div><?= __('mail_default_hint', 'Uses PHP\'s built-in <code>mail()</code> function — works without setup but delivery is unreliable (most receiving mail servers route it to spam, and there\'s no auth or DKIM). Switch to any other mailer for production use.') ?></div>
                </div>
            </div>

            <!-- When MS Graph / Resend is chosen, this banner replaces the
                 entire SMTP credential block. Keeps the form short and makes
                 the next required action unambiguous. -->
            <div class="api-mode-banner" data-api-banner hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                <div>
                    <strong data-api-banner-title><?= __('api_mode_title', 'No SMTP credentials needed') ?></strong>
                    <div data-api-banner-body><?= __('api_mode_body_msgraph',
                        'Fill in the Azure credentials below, click <strong>Save changes</strong>, then click <strong>Connect to Microsoft</strong> to authorise sending. Mail delivers over HTTPS via Microsoft Graph — port 587 / 465 don\'t matter.') ?></div>
                </div>
            </div>

            <!-- Classic SMTP credentials. Hidden in API mode by the
                 setApiMode() JS — the API drivers don't use any of them. -->
            <div data-smtp-credentials>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="smtp_host">SMTP <?= __('host', 'host') ?></label>
                        <input type="text" id="smtp_host" name="smtp_host" maxlength="190"
                               placeholder="smtp.example.com"
                               value="<?= e($smtp['host']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    </div>
                    <div class="field">
                        <label class="field-label" for="smtp_port"><?= __('port', 'Port') ?></label>
                        <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
                               placeholder="587"
                               value="<?= e((string)$smtp['port']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                        <div class="field-hint" id="smtp_port_hint"></div>
                    </div>
                </div>

                <div class="field">
                    <label class="field-label" for="smtp_encryption"><?= __('encryption', 'Encryption') ?></label>
                    <select id="smtp_encryption" name="smtp_encryption" <?= $canEdit ? '' : 'disabled' ?>>
                        <option value="tls"  <?= $smtp['encryption'] === 'tls'  ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                        <option value="ssl"  <?= $smtp['encryption'] === 'ssl'  ? 'selected' : '' ?>>SSL / TLS (port 465)</option>
                        <option value="none" <?= $smtp['encryption'] === 'none' ? 'selected' : '' ?>>None / plain (port 25)</option>
                    </select>
                </div>

                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="smtp_user"><?= __('username', 'Username') ?></label>
                    <input type="text" id="smtp_user" name="smtp_user" maxlength="190" autocomplete="off"
                           value="<?= e($smtp['user']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
                <div class="field">
                    <label class="field-label" for="smtp_pass"><?= __('password', 'Password') ?></label>
                    <div class="pass-wrap">
                        <input type="password" id="smtp_pass" name="smtp_pass" autocomplete="new-password"
                               placeholder="<?= $smtp['has_pass'] ? '••••••••' : '' ?>"
                               <?= $canEdit ? '' : 'disabled' ?>>
                        <?php if ($canEdit): ?>
                        <button type="button" class="pass-toggle" id="smtp_pass_toggle"
                                aria-label="<?= __('toggle_password_visibility', 'Toggle password visibility') ?>">
                            <!-- eye icon (shown by default) -->
                            <svg id="smtp_eye_show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <!-- eye-off icon (hidden by default) -->
                            <svg id="smtp_eye_hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="field-hint"><?= e($smtp['has_pass']
                        ? __('pass_change_hint', 'Leave blank to keep the existing password.')
                        : __('pass_set_hint',    'Stored encrypted with the app secret.')) ?></div>
                    <?php if (!$_appSecretOk): ?>
                    <div class="field-warn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/><path d="M12 3L2 21h20L12 3z"/></svg>
                        <?= __('app_secret_missing_warn',
                            'APP_SECRET is not configured. Passwords cannot be encrypted and will not be saved until APP_SECRET is set in your <code>.env</code> or <code>config.php</code>.') ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($smtp['has_pass'] && $canEdit): ?>
                    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:13px;cursor:pointer">
                        <input type="checkbox" name="clear_smtp_pass" value="1" id="clear_smtp_pass">
                        <?= __('clear_smtp_password', 'Remove saved password') ?>
                    </label>
                    <?php endif; ?>
                </div>
            </div>
            </div><!-- /[data-smtp-credentials] -->

            <!-- Microsoft 365 OAuth credentials — appears INSIDE the same
                 form when msgraph mode is active. Submitting Save persists
                 these to the OAuth settings keys; the Connect button below
                 the form then begins the consent flow with the saved values.
                 Wrapped so the JS can toggle it as one unit. -->
            <div data-msgraph-setup hidden>
                <div class="setup-section-divider">
                    <span><?= __('msgraph_setup_section', 'Microsoft Azure app credentials') ?></span>
                </div>
                <div class="field">
                    <label class="field-label" for="oauth_email"><?= __('mailbox_email', 'Mailbox email (the address Kohevo will send from)') ?></label>
                    <input type="email" id="oauth_email" name="oauth_email" maxlength="190"
                           placeholder="you@yourdomain.com" value="<?= e($oauth['email']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="oauth_client_id"><?= __('client_id', 'Application (client) ID') ?></label>
                        <input type="text" id="oauth_client_id" name="oauth_client_id" maxlength="255"
                               autocomplete="off" value="<?= e($oauth['client_id']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    </div>
                    <div class="field">
                        <label class="field-label" for="oauth_client_secret"><?= __('client_secret', 'Client secret value') ?></label>
                        <input type="password" id="oauth_client_secret" name="oauth_client_secret"
                               autocomplete="new-password" placeholder="<?= $oauth['has_secret'] ? '••••••••' : '' ?>" <?= $canEdit ? '' : 'disabled' ?>>
                        <div class="field-hint"><?= $oauth['has_secret']
                            ? e(__('oauth_secret_keep', 'Leave blank to keep the saved secret.'))
                            : e(__('oauth_secret_enc', 'Stored encrypted with the app secret.')) ?></div>
                    </div>
                </div>
                <?php
                    $_redirectUri = (defined('SLATE_URL') ? SLATE_URL : '') . '/admin/oauth_callback.php';
                ?>
                <div class="field">
                    <label class="field-label" for="oauth_redirect_uri"><?= __('redirect_uri', 'Redirect URI (paste this into Azure)') ?></label>
                    <div style="display:flex;gap:8px;align-items:stretch">
                        <input type="text" id="oauth_redirect_uri" readonly onclick="this.select()" value="<?= e($_redirectUri) ?>"
                               style="flex:1;min-width:0;font-family:var(--font-mono,monospace);font-size:12px;background:var(--surface-2)">
                        <button type="button" class="btn" id="oauth_redirect_copy" style="flex:none;white-space:nowrap">
                            <svg style="width:14px;height:14px;vertical-align:-2px;margin-right:4px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            <span class="lbl"><?= __('copy', 'Copy') ?></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- From identity row — shown for every provider. -->
            <div class="setup-section-divider"><span><?= __('from_section', 'Sender identity') ?></span></div>
            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="smtp_from_name"><?= __('from_name', 'From name') ?></label>
                    <input type="text" id="smtp_from_name" name="smtp_from_name" maxlength="120"
                           value="<?= e($smtp['from_name']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
                <div class="field">
                    <label class="field-label" for="smtp_from_email"><?= __('from_email', 'From email') ?></label>
                    <input type="email" id="smtp_from_email" name="smtp_from_email" maxlength="190"
                           placeholder="no-reply@example.com"
                           value="<?= e($smtp['from_email']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
            </div>

            <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary"><?= __('save_changes', 'Save changes') ?></button>
            <?php endif; ?>
        </form>

        <!-- ── Connection panel ──────────────────────────────────
             Lives INSIDE the setup card so the OAuth connect /
             connected state isn't a separate concern. Shown only
             when an OAuth-based provider is selected. Reads from
             the SAVED settings, so the admin must Save first when
             entering creds for the first time. -->
        <?php $_oauthAvail = class_exists('SmtpOAuth'); ?>
        <?php if ($_oauthAvail && $canEdit): ?>
            <div data-conn-panel hidden>
                <div class="setup-section-divider"><span><?= __('connection_section', 'Connection') ?></span></div>

                <?php if ($oauth['connected']): ?>
                    <?php $_pc = SmtpOAuth::config($oauth['provider']); ?>
                    <div class="conn-status conn-status-ok">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <div>
                            <strong><?= __('oauth_connected', 'Connected') ?></strong> —
                            <?= e($oauth['email']) ?> <?= __('via', 'via') ?> <?= e((string)($_pc['label'] ?? $oauth['provider'])) ?>.
                            <div class="text-muted text-sm" style="margin-top:2px">
                                <?= __('oauth_connected_hint', 'Kohevo will send through Microsoft Graph using this account. Use “Test delivery” below to send a test message.') ?>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
                        <form method="post" style="margin:0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="oauth_reauthorize">
                            <input type="hidden" name="_tab"    value="smtp">
                            <button type="submit" class="btn">
                                <svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                <?= __('oauth_reauthorize', 'Reauthorize') ?>
                            </button>
                        </form>
                        <form method="post" style="margin:0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="oauth_disconnect">
                            <input type="hidden" name="_tab"    value="smtp">
                            <button type="submit" class="btn btn-danger-ghost"
                                    onclick="return confirm('<?= e(__('oauth_disconnect_confirm', 'Disconnect this account and revert to password authentication?')) ?>')">
                                <?= __('oauth_disconnect', 'Disconnect') ?>
                            </button>
                        </form>
                    </div>

                <?php else: ?>
                    <?php $_credsReady = $oauth['client_id'] !== '' && $oauth['has_secret'] && $oauth['email'] !== ''; ?>
                    <div class="conn-status conn-status-warn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <div>
                            <strong><?= __('oauth_not_connected', 'Not connected yet') ?></strong>
                            <div class="text-muted text-sm" style="margin-top:2px">
                                <?php if ($_credsReady): ?>
                                    <?= __('oauth_ready_to_connect', 'Credentials saved. Click <strong>Connect to Microsoft</strong> to authorise sending — you\'ll be sent to Microsoft to approve.') ?>
                                <?php else: ?>
                                    <?= __('oauth_fill_first', 'Fill in the mailbox email, Client ID, and Client secret above and click <strong>Save changes</strong> first. Then this Connect button will start the Microsoft sign-in.') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <form method="post" style="margin-top:12px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="oauth_reauthorize">
                        <input type="hidden" name="_tab"    value="smtp">
                        <button type="submit" class="btn btn-primary"<?= $_credsReady ? '' : ' disabled' ?>>
                            <svg style="width:15px;height:15px;vertical-align:-3px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                            <?= __('connect_to_microsoft', 'Connect to Microsoft') ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Test delivery (Verify + Send test, merged) ────────── -->
    <?php if ($canEdit): ?>
    <div class="card">
        <div class="card-header"><h2><?= __('test_delivery', 'Test delivery') ?></h2></div>
        <p class="text-muted text-sm mb-3">
            <?= __('test_delivery_intro', 'After saving your settings above, run these to confirm the delivery path actually works. <strong>Verify</strong> checks the connection silently (no email sent); <strong>Send test</strong> dispatches a real message.') ?>
        </p>

        <div class="test-delivery-grid">
            <!-- Verify connection: connection-only handshake -->
            <div class="test-delivery-cell">
                <h3 class="test-delivery-h3"><?= __('verify_connection', '1. Verify connection') ?></h3>
                <p class="text-muted text-sm" style="margin:0 0 10px">
                    <?= __('verify_intro_short', 'Tries to sign in with the saved credentials without sending anything. Catches scope, password, and reachability problems early.') ?>
                </p>
                <form method="post" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="verify_smtp">
                    <input type="hidden" name="_tab"    value="smtp">
                    <button type="submit" class="btn"<?= $smtp['host'] === '' ? ' disabled title="Save provider settings above first"' : '' ?>>
                        <svg style="width:15px;height:15px;vertical-align:-3px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                        <?= __('verify_now', 'Verify') ?>
                    </button>
                </form>
            </div>

            <!-- Send test email -->
            <div class="test-delivery-cell">
                <h3 class="test-delivery-h3"><?= __('send_test', '2. Send test email') ?></h3>
                <p class="text-muted text-sm" style="margin:0 0 10px">
                    <?= __('test_email_short', 'Sends a real "Kohevo SMTP test" message to whatever address you enter — the truest end-to-end check.') ?>
                </p>
                <form method="post" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="test_smtp">
                    <input type="hidden" name="_tab"    value="smtp">
                    <div class="field" style="margin-bottom:8px">
                        <label class="field-label" for="test_email_addr"><?= __('send_to', 'Send to') ?></label>
                        <input type="email" id="test_email_addr" name="test_email" required
                               value="<?= e(Auth::user()['email'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn">
                        <svg style="width:15px;height:15px;vertical-align:-3px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                        <?= __('send_test_btn', 'Send test') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Backup Connection card ────────────────────────────
         A second mailer config that Mailer::send() retries
         through when the primary returns an error. Collapsed
         by default; opens to a small "what to send through"
         form. Password-based providers only — no OAuth.
    ──────────────────────────────────────────────────────── -->
    <?php if ($canEdit): ?>
    <details class="card backup-card" <?= $backup['enabled'] || $backup['host'] !== '' ? 'open' : '' ?>>
        <summary class="backup-summary">
            <span class="backup-summary-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;vertical-align:-3px;margin-right:6px"><path d="M3 12a9 9 0 0 1 9-9 9 9 0 0 1 6.3 2.6L21 3"/><path d="M21 12a9 9 0 0 1-9 9 9 9 0 0 1-6.3-2.6L3 21"/><path d="M21 3v6h-6"/><path d="M3 21v-6h6"/></svg>
                <?= __('backup_connection', 'Backup connection') ?>
            </span>
            <span class="backup-summary-state">
                <?php if ($backup['enabled'] && $backup['host'] !== ''): ?>
                    <span class="pill pill-ok">●&nbsp;<?= __('backup_active', 'Active') ?></span>
                    <span class="text-muted text-sm"><?= e($backup['host']) ?></span>
                <?php elseif ($backup['host'] !== ''): ?>
                    <span class="pill pill-warn">●&nbsp;<?= __('backup_disabled', 'Configured but disabled') ?></span>
                <?php else: ?>
                    <span class="pill pill-muted">●&nbsp;<?= __('backup_unset', 'Not configured') ?></span>
                <?php endif; ?>
            </span>
        </summary>

        <p class="text-muted text-sm" style="margin:4px 0 14px">
            <?= __('backup_intro', 'If the primary mailer fails (timeouts, rate-limits, outages), <strong>Kohevo retries the same email through this backup connection</strong>. Both attempts are recorded in the audit log so you can see which path delivered. Backup is SMTP-only — Microsoft Graph / OAuth is not supported as a backup driver.') ?>
        </p>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_backup_smtp">
            <input type="hidden" name="_tab"    value="smtp">

            <label class="field-inline-check" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;padding:10px 12px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;cursor:pointer">
                <input type="checkbox" name="smtp_backup_enabled" value="1" <?= $backup['enabled'] ? 'checked' : '' ?>>
                <strong><?= __('enable_backup', 'Enable backup failover') ?></strong>
                <span class="text-muted text-sm"><?= __('enable_backup_hint', '(retries failed primary sends through the credentials below)') ?></span>
            </label>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="smtp_backup_host"><?= __('backup_host', 'Backup SMTP host') ?></label>
                    <input type="text" id="smtp_backup_host" name="smtp_backup_host" maxlength="190"
                           placeholder="smtp.fallback-provider.com"
                           value="<?= e($backup['host']) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="smtp_backup_port"><?= __('port', 'Port') ?></label>
                    <input type="number" id="smtp_backup_port" name="smtp_backup_port" min="1" max="65535"
                           placeholder="587"
                           value="<?= e((string)$backup['port']) ?>">
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="smtp_backup_encryption"><?= __('encryption', 'Encryption') ?></label>
                <select id="smtp_backup_encryption" name="smtp_backup_encryption">
                    <option value="tls"  <?= $backup['encryption'] === 'tls'  ? 'selected' : '' ?>>STARTTLS (port 587)</option>
                    <option value="ssl"  <?= $backup['encryption'] === 'ssl'  ? 'selected' : '' ?>>SSL / TLS (port 465)</option>
                    <option value="none" <?= $backup['encryption'] === 'none' ? 'selected' : '' ?>>None / plain (port 25)</option>
                </select>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="smtp_backup_user"><?= __('username', 'Username') ?></label>
                    <input type="text" id="smtp_backup_user" name="smtp_backup_user" maxlength="190" autocomplete="off"
                           value="<?= e($backup['user']) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="smtp_backup_pass"><?= __('password', 'Password') ?></label>
                    <input type="password" id="smtp_backup_pass" name="smtp_backup_pass" autocomplete="new-password"
                           placeholder="<?= $backup['has_pass'] ? '••••••••' : '' ?>">
                    <div class="field-hint"><?= e($backup['has_pass']
                        ? __('pass_change_hint', 'Leave blank to keep the existing password.')
                        : __('pass_set_hint',    'Stored encrypted with the app secret.')) ?></div>
                    <?php if ($backup['has_pass']): ?>
                    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:13px;cursor:pointer">
                        <input type="checkbox" name="clear_smtp_backup_pass" value="1">
                        <?= __('clear_smtp_password', 'Remove saved password') ?>
                    </label>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary"><?= __('save_backup', 'Save backup connection') ?></button>
            </div>
        </form>

        <!-- Verify the saved backup config without sending anything.
             Separate form because it doesn't carry the save fields —
             it just exercises Mailer::verifyConnection('backup'). -->
        <?php if ($backup['host'] !== ''): ?>
        <form method="post" style="margin-top:10px">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="verify_backup_smtp">
            <input type="hidden" name="_tab"    value="smtp">
            <button type="submit" class="btn">
                <svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?= __('verify_backup', 'Verify backup connection') ?>
            </button>
            <span class="text-muted text-sm" style="margin-left:8px">
                <?= __('verify_backup_hint', 'Opens an SMTP handshake with the backup creds — no email is sent. Save first if you just changed anything.') ?>
            </span>
        </form>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <script>
    (function () {
        /* ── Encryption → port auto-suggest ─────────────────── */
        var encSel  = document.getElementById('smtp_encryption');
        var portIn  = document.getElementById('smtp_port');
        var portHint= document.getElementById('smtp_port_hint');
        var portMap = { tls: 587, ssl: 465, none: 25 };

        function suggestPort() {
            if (!encSel || !portIn) return;
            var enc  = encSel.value;
            var suggested = portMap[enc] || 587;
            var current   = parseInt(portIn.value, 10);
            // Only auto-fill if blank or was a previously auto-filled standard port
            if (!portIn.value || current === 25 || current === 465 || current === 587) {
                portIn.value = suggested;
            }
            if (portHint) {
                portHint.textContent = 'Standard port for this encryption: ' + suggested;
            }
        }

        if (encSel) {
            encSel.addEventListener('change', suggestPort);
            // Show hint on load without overwriting an existing non-standard port
            if (portHint && portIn) {
                var enc = encSel.value;
                portHint.textContent = 'Standard port for this encryption: ' + (portMap[enc] || 587);
            }
        }

        /* ── Password show/hide toggle ───────────────────────── */
        var passIn     = document.getElementById('smtp_pass');
        var passToggle = document.getElementById('smtp_pass_toggle');
        var eyeShow    = document.getElementById('smtp_eye_show');
        var eyeHide    = document.getElementById('smtp_eye_hide');

        if (passToggle && passIn) {
            passToggle.addEventListener('click', function () {
                var isHidden = passIn.type === 'password';
                passIn.type = isHidden ? 'text' : 'password';
                if (eyeShow) eyeShow.style.display = isHidden ? 'none' : '';
                if (eyeHide) eyeHide.style.display = isHidden ? '' : 'none';
                passToggle.setAttribute('aria-label',
                    isHidden ? 'Hide password' : 'Show password');
            });
        }

        /* ── "Remove saved password" disables the pass input ── */
        var clearCb = document.getElementById('clear_smtp_pass');
        if (clearCb && passIn) {
            clearCb.addEventListener('change', function () {
                passIn.disabled = clearCb.checked;
                if (clearCb.checked) {
                    passIn.value = '';
                    passIn.placeholder = '(will be removed on save)';
                } else {
                    passIn.placeholder = '••••••••';
                }
            });
        }

        /* ── Provider quick-setup → fill host/port/encryption ── */
        var provSel = document.getElementById('smtp_provider');
        var hostIn  = document.getElementById('smtp_host');
        var userIn  = document.getElementById('smtp_user');
        var PRESETS = <?= json_encode($smtpProviderJs, JSON_UNESCAPED_SLASHES) ?>;

        function showProviderHint(key) {
            document.querySelectorAll('.smtp-provider-hint').forEach(function (el) {
                el.hidden = el.getAttribute('data-provider') !== key;
            });
        }

        /* WP Mail SMTP-style affordance: when an OAuth provider is chosen,
           the entire SMTP credential block goes away and the Microsoft 365
           setup fields + connection panel slide into the same form. The
           admin sees one card with only the fields that matter. */
        var smtpCredBlock   = document.querySelector('[data-smtp-credentials]');
        var msgraphSetup    = document.querySelector('[data-msgraph-setup]');
        var apiBanner       = document.querySelector('[data-api-banner]');
        var connPanel       = document.querySelector('[data-conn-panel]');

        function setApiMode(key) {
            var isMsGraph = (key === 'msgraph');
            var isResend  = (key === 'resend');
            var isApi     = (isMsGraph || isResend);
            // Classic SMTP creds: hidden only for Graph (OAuth replaces them).
            // Resend reuses the password field for its API key, so we keep
            // that block visible there.
            if (smtpCredBlock) smtpCredBlock.hidden = isMsGraph;
            // MS Graph credential fields appear ONLY for Graph mode.
            if (msgraphSetup)  msgraphSetup.hidden = !isMsGraph;
            // Top-of-form info banner (different message per API driver).
            if (apiBanner) {
                apiBanner.hidden = !isApi;
                var title = apiBanner.querySelector('[data-api-banner-title]');
                var body  = apiBanner.querySelector('[data-api-banner-body]');
                if (title && body) {
                    if (isMsGraph) {
                        title.textContent = 'Microsoft 365 setup — no SMTP password needed';
                        body.innerHTML = 'Fill in the Azure credentials below, click <strong>Save changes</strong>, then click <strong>Connect to Microsoft</strong> to authorise sending. Your mail will deliver over HTTPS via Microsoft Graph — port 587 / 465 don’t matter.';
                    } else if (key === 'resend') {
                        title.textContent = 'Resend setup — paste your API key into the password field';
                        body.innerHTML = 'Resend uses an HTTPS API instead of SMTP. The username field is ignored; paste the Resend API key into the password field and save.';
                    }
                }
            }
            // Connection panel (Connect / Connected status) lives at the
            // bottom of the same card. Shown only for OAuth providers.
            if (connPanel) connPanel.hidden = !isMsGraph;
        }

        if (provSel) {
            provSel.addEventListener('change', function () {
                var key = provSel.value;
                showProviderHint(key);
                setApiMode(key);
                // "mail" = the no-host PHP mail() default. Wipe the host
                // so save_smtp drops the row and the Mailer falls back.
                if (key === 'mail') {
                    if (hostIn) hostIn.value = '';
                    if (portIn) portIn.value = '';
                    return;
                }
                var p = PRESETS[key];
                if (!p) return; // "custom" or blank → leave the fields untouched
                if (hostIn)  hostIn.value = p.host;
                if (encSel)  encSel.value = p.enc;
                if (portIn)  portIn.value = p.port;
                if (portHint) portHint.textContent = 'Standard port for this encryption: ' + (portMap[p.enc] || p.port);
                if (userIn && p.user && userIn.value.trim() === '') userIn.value = p.user;
            });
            // Run on initial load so a saved msgraph/resend choice already
            // hides the irrelevant fields before any interaction.
            setApiMode(provSel.value);
        }

        /* ── Mailer cards: clicking a card drives the hidden select ──
           IMPORTANT: scope to cards OUTSIDE the wizard modal. The
           wizard has its own grid of .mailer-card elements with
           independent state (wizard's own listener writes to
           state.mailer, not the saved settings). If we let this
           handler fire on wizard cards too, picking a mailer inside
           the wizard would silently rewrite the main page's
           contextual visibility — and cancelling the wizard would
           leave the main page in a stale state until next reload. */
        var mainMailerCards = Array.from(document.querySelectorAll('.mailer-card'))
            .filter(function (c) { return !c.closest('#setupWizard'); });
        mainMailerCards.forEach(function (card) {
            card.addEventListener('click', function (e) {
                var key = card.getAttribute('data-key');
                if (!provSel || !key) return;
                if (provSel.value === key) return; // no-op
                provSel.value = key;
                provSel.dispatchEvent(new Event('change', { bubbles: true }));
                // Visual selection state — toggle on the main-page set only.
                mainMailerCards.forEach(function (c) {
                    c.classList.toggle('is-selected', c === card);
                });
                var radio = card.querySelector('input[type=radio]');
                if (radio) radio.checked = true;
            });
            // Arrow-key navigation between cards for keyboard users.
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    card.click();
                }
            });
        });

        /* If the host is hand-edited, drop the picker back to "custom" (or the
           matching preset) so the label never lies about what's configured. */
        if (hostIn && provSel) {
            hostIn.addEventListener('input', function () {
                var v = hostIn.value.trim().toLowerCase();
                var match = '';
                Object.keys(PRESETS).forEach(function (k) {
                    if (PRESETS[k].host.toLowerCase() === v) match = k;
                });
                provSel.value = match || (v ? 'custom' : '');
                showProviderHint(match);
                setApiMode(match);
            });
        }

        /* ════════════════════════════════════════════════════
           SETUP WIZARD CONTROLLER
           Manages the modal's open/close, step navigation, and
           per-mailer credential rendering. Does NOT submit on
           its own — the form's submit button is the only path,
           and it posts to save_smtp like the main page does.
        ══════════════════════════════════════════════════════ */
        var wiz = document.getElementById('setupWizard');
        var wizOpenBtn = document.getElementById('wizardOpen');
        if (wiz && wizOpenBtn) {
            var wizCloseBtn = document.getElementById('wizardClose');
            var wizBackBtn  = document.getElementById('wizBack');
            var wizNextBtn  = document.getElementById('wizNext');
            var wizSaveBtn  = document.getElementById('wizSave');
            var wizPanels   = wiz.querySelectorAll('.wizard-panel');
            var wizSteps    = wiz.querySelectorAll('.wizard-step');
            var wizGrid     = document.getElementById('wizMailerGrid');
            var wizP2Body   = document.getElementById('wizP2Body');
            var wizP2Title  = document.getElementById('wizP2Title');
            var wizP2Intro  = document.getElementById('wizP2Intro');
            var wizSummary  = document.getElementById('wizSummary');
            var wizNextStep = document.getElementById('wizNextStep');

            // Snapshot of current saved values so we pre-fill the wizard
            // for editors who relaunch it. PHP renders these once at page
            // load; the wizard runs entirely client-side until submit.
            var saved = {
                host:       <?= json_encode((string)$smtp['host']) ?>,
                port:       <?= json_encode((string)$smtp['port']) ?>,
                enc:        <?= json_encode((string)$smtp['encryption']) ?>,
                user:       <?= json_encode((string)$smtp['user']) ?>,
                hasPass:    <?= $smtp['has_pass'] ? 'true' : 'false' ?>,
                fromName:   <?= json_encode((string)$smtp['from_name']) ?>,
                fromEmail:  <?= json_encode((string)$smtp['from_email']) ?>,
                oauthEmail: <?= json_encode((string)$oauth['email']) ?>,
                oauthCid:   <?= json_encode((string)$oauth['client_id']) ?>,
                hasSecret:  <?= $oauth['has_secret'] ? 'true' : 'false' ?>,
                connected:  <?= $oauth['connected'] ? 'true' : 'false' ?>,
                mailer:     <?= json_encode((string)$_currentMailer) ?>,
                redirectUri:<?= json_encode((defined('SLATE_URL') ? SLATE_URL : '') . '/admin/oauth_callback.php') ?>
            };

            // Wizard's working state. The wizard fills these as the user
            // moves forward; on the final submit, copyToHiddenFields()
            // pours them into the real form inputs.
            var state = {
                mailer:    saved.mailer || '',
                fields:    {}, // arbitrary key:value per the active mailer
                fromName:  saved.fromName,
                fromEmail: saved.fromEmail
            };

            // Per-mailer credential templates for Step 2. Most providers
            // map to one of three shapes (API key, username+password, or
            // OAuth creds); the rest fall back to a generic SMTP form.
            var wizardFields = {
                msgraph: {
                    title: 'Microsoft 365 — Azure app credentials',
                    intro: 'In <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Entra ID → App registrations</a> create a new app, add the redirect URI below, then under <em>API permissions</em> add Microsoft Graph <code>Mail.Send</code> with admin consent. Paste the Client ID + Secret here.',
                    rows: [
                        { label: 'Mailbox email (send-from)', target: 'oauthEmail', type: 'email', placeholder: 'you@yourdomain.com' },
                        { label: 'Application (client) ID',   target: 'oauthCid',   type: 'text',  placeholder: '00000000-0000-0000-0000-000000000000' },
                        { label: 'Client secret value',       target: 'oauthSec',   type: 'password', placeholder: saved.hasSecret ? '••••••••' : '', optional: saved.hasSecret }
                    ],
                    showRedirectUri: true
                },
                resend: {
                    title: 'Resend API key',
                    intro: 'Sends over HTTPS — no SMTP port needed. Create an API key at <a href="https://resend.com/api-keys" target="_blank" rel="noopener">resend.com/api-keys</a>.',
                    rows: [
                        { label: 'API key', target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : 're_…', optional: saved.hasPass }
                    ]
                },
                gmail: {
                    title: 'Gmail / Workspace — app password',
                    intro: 'Use an App Password, not your account password. Generate one at <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a> (requires 2-Step Verification).',
                    rows: [
                        { label: 'Email address',  target: 'user', type: 'email',    placeholder: 'you@gmail.com' },
                        { label: 'App password',   target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : 'aaaa bbbb cccc dddd', optional: saved.hasPass }
                    ]
                },
                outlook: {
                    title: 'Outlook.com — app password',
                    intro: 'Personal Microsoft accounts need an app password (Security → Advanced security options) and SMTP AUTH allowed on the account.',
                    rows: [
                        { label: 'Email address', target: 'user', type: 'email' },
                        { label: 'App password',  target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ]
                },
                office365: {
                    title: 'Office 365 — mailbox credentials',
                    intro: 'A 365 admin must enable Authenticated SMTP for this mailbox (Microsoft 365 admin → Active users → Mail). For most modern setups, <strong>Microsoft 365 (Graph API)</strong> is a better choice — go back and pick it.',
                    rows: [
                        { label: 'Email address', target: 'user', type: 'email' },
                        { label: 'Password',      target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ]
                },
                yahoo:    sharedAppPasswordTpl('Yahoo Mail',    'Generate an app password at Account Security → Generate app password.'),
                icloud:   sharedAppPasswordTpl('iCloud Mail',   'Create an app-specific password at appleid.apple.com (Sign-In & Security).'),
                zoho:     sharedAppPasswordTpl('Zoho Mail',     'Enable IMAP/SMTP access in Zoho; with 2FA on, create an app-specific password.'),
                fastmail: sharedAppPasswordTpl('Fastmail',      'Create an app password scoped to SMTP under Settings → Privacy & Security → App passwords.'),
                sendgrid: {
                    title: 'SendGrid API key',
                    intro: 'Username is the literal word <code>apikey</code> (auto-filled). Paste your API key into the password field; create it under Settings → API Keys with Mail Send permission.',
                    rows: [
                        { label: 'API key', target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : 'SG.…', optional: saved.hasPass }
                    ],
                    fixedUser: 'apikey'
                },
                brevo: {
                    title: 'Brevo SMTP key',
                    intro: 'Username is your Brevo account email; password is the <strong>SMTP key</strong> from Transactional → Settings → SMTP &amp; API.',
                    rows: [
                        { label: 'Account email', target: 'user', type: 'email' },
                        { label: 'SMTP key',      target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ]
                },
                mailgun: {
                    title: 'Mailgun SMTP credentials',
                    intro: 'Use the SMTP credentials from your domain\'s Sending → Domain settings. EU domains use <code>smtp.eu.mailgun.org</code>.',
                    rows: [
                        { label: 'SMTP login',    target: 'user', type: 'text', placeholder: 'postmaster@mg.yourdomain.com' },
                        { label: 'SMTP password', target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ]
                },
                ses: {
                    title: 'Amazon SES SMTP credentials',
                    intro: 'Generate dedicated SMTP credentials in the SES console (not your AWS access keys). The host region is locked to us-east-1 — change it on the main page after save if you need a different region.',
                    rows: [
                        { label: 'SMTP user', target: 'user', type: 'text' },
                        { label: 'SMTP pass', target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ]
                },
                postmark: {
                    title: 'Postmark server token',
                    intro: 'Postmark uses the same Server API Token as both username and password. Found under your server\'s API Tokens tab.',
                    rows: [
                        { label: 'Server API token', target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ],
                    mirrorPassToUser: true
                },
                mailjet: {
                    title: 'Mailjet API + Secret',
                    intro: 'Username is your <strong>API Key</strong>; password is your <strong>Secret Key</strong> (Account → API Key Management).',
                    rows: [
                        { label: 'API key',    target: 'user', type: 'text' },
                        { label: 'Secret key', target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ]
                },
                smtp2go: {
                    title: 'SMTP2GO credentials',
                    intro: 'Create an SMTP user under Sending → SMTP Users and use those credentials.',
                    rows: [
                        { label: 'SMTP user',     target: 'user', type: 'text' },
                        { label: 'SMTP password', target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ]
                },
                custom: {
                    title: 'Custom SMTP server',
                    intro: 'Enter the values your provider gave you.',
                    rows: [
                        { label: 'SMTP host',  target: 'host', type: 'text', placeholder: 'smtp.example.com' },
                        { label: 'Port',       target: 'port', type: 'number', placeholder: '587' },
                        { label: 'Encryption', target: 'enc',  type: 'select', options: [['tls','STARTTLS (587)'],['ssl','SSL / TLS (465)'],['none','None (25)']] },
                        { label: 'Username',   target: 'user', type: 'text' },
                        { label: 'Password',   target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ]
                },
                mail: {
                    title: 'PHP mail() fallback',
                    intro: 'Uses the server\'s built-in <code>mail()</code> function — works without credentials but delivery is unreliable. Best to pick a real mailer above.',
                    rows: [],
                    skipCreds: true
                }
            };
            function sharedAppPasswordTpl(name, hint) {
                return {
                    title: name + ' — app password',
                    intro: hint,
                    rows: [
                        { label: 'Email address', target: 'user', type: 'email' },
                        { label: 'App password',  target: 'pass', type: 'password', placeholder: saved.hasPass ? '••••••••' : '', optional: saved.hasPass }
                    ]
                };
            }

            function openWizard() {
                wiz.hidden = false;
                wiz.setAttribute('aria-hidden', 'false');
                document.body.classList.add('wizard-open');
                // Pre-select the saved mailer card if any.
                if (state.mailer) {
                    var preCard = wizGrid.querySelector('.mailer-card[data-key="' + state.mailer + '"]');
                    if (preCard) {
                        wizGrid.querySelectorAll('.mailer-card').forEach(function (c) {
                            c.classList.toggle('is-selected', c === preCard);
                        });
                        var r = preCard.querySelector('input[type=radio]');
                        if (r) r.checked = true;
                    }
                }
                gotoStep(1);
            }
            function closeWizard() {
                wiz.hidden = true;
                wiz.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('wizard-open');
            }

            wizOpenBtn.addEventListener('click', openWizard);
            wizCloseBtn.addEventListener('click', closeWizard);
            wiz.addEventListener('click', function (e) {
                // Click on backdrop (overlay) closes; click inside modal doesn't.
                if (e.target === wiz) closeWizard();
            });
            document.addEventListener('keydown', function (e) {
                if (!wiz.hidden && e.key === 'Escape') closeWizard();
            });

            // Step 1: card picker inside the wizard
            wizGrid.querySelectorAll('.mailer-card').forEach(function (card) {
                card.addEventListener('click', function () {
                    var key = card.getAttribute('data-key');
                    wizGrid.querySelectorAll('.mailer-card').forEach(function (c) {
                        c.classList.toggle('is-selected', c === card);
                    });
                    var r = card.querySelector('input[type=radio]');
                    if (r) r.checked = true;
                    state.mailer = key;
                });
            });

            // Render step 2 fields based on the chosen mailer.
            function renderStep2() {
                var key = state.mailer || 'custom';
                var spec = wizardFields[key] || wizardFields.custom;
                wizP2Title.textContent = spec.title || 'Configure your mailer';
                wizP2Intro.innerHTML = spec.intro || '';

                var html = '';
                spec.rows.forEach(function (row, idx) {
                    var id  = 'wizIn_' + row.target + '_' + idx;
                    var val = state.fields[row.target] !== undefined
                        ? state.fields[row.target]
                        : (row.target === 'oauthEmail' ? saved.oauthEmail
                          : row.target === 'oauthCid'   ? saved.oauthCid
                          : row.target === 'host'       ? saved.host
                          : row.target === 'port'       ? saved.port
                          : row.target === 'enc'        ? (saved.enc || 'tls')
                          : row.target === 'user'       ? saved.user : '');
                    html += '<div class="field">';
                    html += '<label class="field-label" for="' + id + '">' + row.label + '</label>';
                    if (row.type === 'select') {
                        html += '<select id="' + id + '" data-target="' + row.target + '">';
                        row.options.forEach(function (op) {
                            var sel = (val === op[0]) ? ' selected' : '';
                            html += '<option value="' + op[0] + '"' + sel + '>' + op[1] + '</option>';
                        });
                        html += '</select>';
                    } else {
                        var attrs = 'id="' + id + '" data-target="' + row.target + '" type="' + row.type + '"';
                        if (row.placeholder) attrs += ' placeholder="' + row.placeholder.replace(/"/g, '&quot;') + '"';
                        if (val) attrs += ' value="' + val.replace(/"/g, '&quot;') + '"';
                        html += '<input ' + attrs + '>';
                    }
                    html += '</div>';
                });
                if (spec.showRedirectUri) {
                    html += '<div class="field">';
                    html += '<label class="field-label">Redirect URI <span class="text-muted text-sm">(paste this into your Azure app)</span></label>';
                    html += '<div style="display:flex;gap:8px;align-items:stretch">';
                    html += '<input type="text" readonly value="' + saved.redirectUri.replace(/"/g, '&quot;') + '" onclick="this.select()" style="flex:1;min-width:0;font-family:monospace;font-size:12px;background:var(--surface-2)">';
                    html += '</div></div>';
                }
                wizP2Body.innerHTML = html;
            }

            // Save step 2 inputs into state.fields before navigating away.
            function captureStep2() {
                wizP2Body.querySelectorAll('[data-target]').forEach(function (el) {
                    state.fields[el.getAttribute('data-target')] = el.value;
                });
            }
            function captureStep3() {
                state.fromName  = document.getElementById('wizIn_fromName').value;
                state.fromEmail = document.getElementById('wizIn_fromEmail').value;
            }

            function gotoStep(n) {
                wizPanels.forEach(function (p) {
                    p.hidden = (parseInt(p.getAttribute('data-panel'), 10) !== n);
                });
                wizSteps.forEach(function (s) {
                    var sn = parseInt(s.getAttribute('data-step'), 10);
                    s.classList.toggle('is-active', sn === n);
                    s.classList.toggle('is-done', sn < n);
                });
                wizBackBtn.hidden = (n === 1);
                wizNextBtn.hidden = (n === 4);
                wizSaveBtn.hidden = (n !== 4);
                if (n === 2) renderStep2();
                if (n === 4) renderSummary();
                wizNextBtn.textContent = (n === 3) ? 'Review' : 'Continue';
            }

            wizBackBtn.addEventListener('click', function () {
                var active = parseInt(wiz.querySelector('.wizard-step.is-active').getAttribute('data-step'), 10);
                if (active === 3 && wizardFields[state.mailer] && wizardFields[state.mailer].skipCreds) {
                    gotoStep(1); // mail mode: step 2 was skipped
                } else {
                    gotoStep(active - 1);
                }
            });
            wizNextBtn.addEventListener('click', function () {
                var active = parseInt(wiz.querySelector('.wizard-step.is-active').getAttribute('data-step'), 10);
                if (active === 1) {
                    if (!state.mailer) { showWizErr('Please pick a mailer first.'); return; }
                    clearWizErr();
                    var spec = wizardFields[state.mailer];
                    if (spec && spec.skipCreds) {
                        gotoStep(3);
                    } else {
                        gotoStep(2);
                    }
                } else if (active === 2) {
                    captureStep2();
                    // Validate: required fields must have a value, unless
                    // a saved value was indicated by row.optional=true (the
                    // user is re-running the wizard and we already have it
                    // encrypted in settings). This stops blank passwords
                    // from quietly overwriting good ones.
                    var spec = wizardFields[state.mailer] || {};
                    var missing = [];
                    (spec.rows || []).forEach(function (row) {
                        if (row.optional) return;
                        var v = (state.fields[row.target] || '').trim();
                        if (!v) missing.push(row.label);
                    });
                    if (missing.length) {
                        showWizErr('Please fill in: ' + missing.join(', '));
                        return;
                    }
                    clearWizErr();
                    gotoStep(3);
                } else if (active === 3) {
                    captureStep3();
                    var fe = (state.fromEmail || '').trim();
                    if (fe && !/^[^@\s]+@[^@\s.]+\.[^@\s]+$/.test(fe)) {
                        showWizErr('From email doesn\'t look like a valid email address.');
                        return;
                    }
                    clearWizErr();
                    gotoStep(4);
                }
            });

            /* Inline error banner above the wizard footer — replaces
               the alert() calls so the message stays in the modal. */
            function showWizErr(msg) {
                var el = document.getElementById('wizError');
                if (!el) {
                    el = document.createElement('div');
                    el.id = 'wizError';
                    el.style.cssText = 'margin:0 28px 8px;padding:9px 12px;font-size:13px;line-height:1.4;background:rgba(220,38,38,.08);border:1px solid #fca5a5;color:#991b1b;border-radius:6px';
                    var footer = wiz.querySelector('.wizard-footer');
                    if (footer && footer.parentNode) footer.parentNode.insertBefore(el, footer);
                }
                el.textContent = msg;
                el.style.display = '';
            }
            function clearWizErr() {
                var el = document.getElementById('wizError');
                if (el) el.style.display = 'none';
            }

            // Build the readable summary on step 4 and the contextual
            // "what happens next" note.
            function renderSummary() {
                var spec = wizardFields[state.mailer] || {};
                var rows = [
                    ['Mailer', (wizardFields[state.mailer] && wizardFields[state.mailer].title) || state.mailer || '(none)'],
                    ['From',   (state.fromName ? state.fromName + ' <' + state.fromEmail + '>' : state.fromEmail || '(not set)')]
                ];
                // Surface a couple of safe identifying fields, never secrets.
                if (state.fields.user)       rows.push(['Username',     state.fields.user]);
                if (state.fields.oauthEmail) rows.push(['Mailbox',      state.fields.oauthEmail]);
                if (state.fields.oauthCid)   rows.push(['Client ID',    state.fields.oauthCid.slice(0, 8) + '…']);
                if (state.fields.host)       rows.push(['SMTP host',    state.fields.host]);
                wizSummary.innerHTML = rows.map(function (r) {
                    return '<dt>' + r[0] + '</dt><dd>' + escapeHtml(r[1]) + '</dd>';
                }).join('');

                // Per-mailer next-step hint shown after save.
                if (state.mailer === 'msgraph' && !saved.connected) {
                    wizNextStep.hidden = false;
                    wizNextStep.innerHTML = '<strong>What happens next:</strong> after saving, the page will reload with a "Connect to Microsoft" button. Click it to grant Kohevo permission to send mail — that\'s the last step.';
                } else if (state.mailer === 'mail') {
                    wizNextStep.hidden = false;
                    wizNextStep.innerHTML = '<strong>Heads up:</strong> PHP <code>mail()</code> delivers without authentication; most receivers route it to spam. Switch to any other mailer for production traffic.';
                } else {
                    wizNextStep.hidden = false;
                    wizNextStep.innerHTML = '<strong>What happens next:</strong> after saving, run "Verify connection" then send a test email from the bottom of the page.';
                }
            }
            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
                });
            }

            // Final submit handler — pour state into hidden form fields
            // and let save_smtp do its job. We also set smtp_host to the
            // sentinel for OAuth/API providers so Mailer routes correctly.
            wizSaveBtn.addEventListener('click', function (e) {
                // captureStep3() already ran; capture step 2 too in case.
                // (defensive — Continue from step 2 already captured).
                var spec = wizardFields[state.mailer] || {};
                var setField = function (id, v) {
                    var el = document.getElementById(id);
                    if (el) el.value = (v === undefined || v === null) ? '' : v;
                };
                // Host / port / enc — preset-driven for non-custom mailers.
                if (state.mailer === 'mail') {
                    setField('wizField_host', '');
                    setField('wizField_port', '');
                } else if (state.mailer === 'custom') {
                    setField('wizField_host', state.fields.host || '');
                    setField('wizField_port', state.fields.port || '');
                    setField('wizField_enc',  state.fields.enc  || 'tls');
                } else if (PRESETS[state.mailer]) {
                    var p = PRESETS[state.mailer];
                    setField('wizField_host', p.host);
                    setField('wizField_port', p.port);
                    setField('wizField_enc',  p.enc);
                }
                // Username: either captured or the mailer-imposed default.
                var userVal = state.fields.user || '';
                if (!userVal && spec.fixedUser) userVal = spec.fixedUser;
                if (!userVal && spec.mirrorPassToUser && state.fields.pass) userVal = state.fields.pass;
                setField('wizField_user', userVal);
                setField('wizField_pass', state.fields.pass || '');
                // OAuth (msgraph only).
                setField('wizField_oauthCid',   state.fields.oauthCid || '');
                setField('wizField_oauthSec',   state.fields.oauthSec || '');
                setField('wizField_oauthEmail', state.fields.oauthEmail || '');
                // From identity.
                setField('wizField_fromName',  state.fromName  || '');
                setField('wizField_fromEmail', state.fromEmail || '');
                // Default success — let the form submit naturally.
            });
        }

        /* ── Redirect-URI copy button (inside the inline MS365 block) ─ */
        var copyBtn = document.getElementById('oauth_redirect_copy');
        var uriIn   = document.getElementById('oauth_redirect_uri');
        if (copyBtn && uriIn) {
            copyBtn.addEventListener('click', function () {
                var lbl  = copyBtn.querySelector('.lbl');
                var orig = lbl ? lbl.textContent : '';
                var done = function () {
                    if (lbl) { lbl.textContent = 'Copied!'; setTimeout(function () { lbl.textContent = orig; }, 1300); }
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(uriIn.value).then(done, function () { uriIn.select(); });
                } else {
                    uriIn.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                }
            });
        }
    })();
    </script>

    <!-- ══════════════════════════════════════════════════════
         SETUP WIZARD (modal)
         WP Mail SMTP-style guided flow. Three steps: pick mailer,
         enter credentials, set sender. Submit POSTs to save_smtp
         which already handles every routing case (SMTP creds,
         OAuth creds, mail() fallback). For OAuth providers, the
         flash after save guides the user to Connect — the wizard
         doesn't try to start consent itself, keeping its scope
         narrow and reusing the proven save path.
    ══════════════════════════════════════════════════════ -->
    <?php if ($canEdit): ?>
    <div class="wizard-overlay" id="setupWizard" hidden aria-hidden="true" aria-labelledby="wizardTitle">
        <div class="wizard-modal" role="dialog" aria-modal="true">
            <button type="button" class="wizard-close" id="wizardClose" aria-label="<?= e(__('close', 'Close')) ?>">&times;</button>

            <header class="wizard-header">
                <h2 id="wizardTitle"><?= __('wizard_title', 'Email Setup Wizard') ?></h2>
                <ol class="wizard-steps" aria-label="<?= e(__('wizard_progress', 'Progress')) ?>">
                    <li class="wizard-step is-active" data-step="1"><span class="wizard-step-num">1</span><?= __('wizard_step_1', 'Mailer') ?></li>
                    <li class="wizard-step" data-step="2"><span class="wizard-step-num">2</span><?= __('wizard_step_2', 'Credentials') ?></li>
                    <li class="wizard-step" data-step="3"><span class="wizard-step-num">3</span><?= __('wizard_step_3', 'Sender') ?></li>
                    <li class="wizard-step" data-step="4"><span class="wizard-step-num">4</span><?= __('wizard_step_4', 'Save') ?></li>
                </ol>
            </header>

            <form method="post" id="wizardForm" class="wizard-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_smtp">
                <input type="hidden" name="_tab"    value="smtp">
                <!-- Form fields. Populated by the wizard's per-step
                     panels; on submit, save_smtp reads them just like
                     it does from the main page. -->
                <input type="hidden" name="smtp_host"       id="wizField_host">
                <input type="hidden" name="smtp_port"       id="wizField_port">
                <input type="hidden" name="smtp_encryption" id="wizField_enc"  value="tls">
                <input type="hidden" name="smtp_user"       id="wizField_user">
                <input type="hidden" name="smtp_pass"       id="wizField_pass">
                <input type="hidden" name="smtp_from_name"  id="wizField_fromName">
                <input type="hidden" name="smtp_from_email" id="wizField_fromEmail">
                <input type="hidden" name="oauth_client_id"     id="wizField_oauthCid">
                <input type="hidden" name="oauth_client_secret" id="wizField_oauthSec">
                <input type="hidden" name="oauth_email"         id="wizField_oauthEmail">

                <!-- ── Panel 1: pick mailer ───────────────────── -->
                <div class="wizard-panel is-active" data-panel="1">
                    <h3 class="wizard-panel-title"><?= __('wizard_p1_title', 'How does Kohevo send mail?') ?></h3>
                    <p class="wizard-panel-intro"><?= __('wizard_p1_intro', 'Choose your provider. We\'ll only ask for the fields you actually need.') ?></p>

                    <!-- Mirror of the main mailer card grid, lightweight
                         clone built from the same brand metadata. -->
                    <div class="mailer-grid" id="wizMailerGrid">
                        <?php foreach ($_cardOrder as $_pk):
                            $_b = $_brands[$_pk] ?? null;
                            if (!$_b) continue;
                            $_lbl = (string)($_b['short'] ?? '');
                        ?>
                            <label class="mailer-card" data-key="<?= e($_pk) ?>">
                                <input type="radio" name="wiz_mailer" value="<?= e($_pk) ?>">
                                <?php if (!empty($_b['recommended'])): ?>
                                    <span class="mailer-badge"><?= __('recommended', 'RECOMMENDED') ?></span>
                                <?php endif; ?>
                                <div class="mailer-logo" style="background: <?= e((string)($_b['bg'] ?? '#fff')) ?>"><?= $_b['mark'] ?></div>
                                <div class="mailer-name"><?= e($_lbl) ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ── Panel 2: credentials (rendered per-mailer) ── -->
                <div class="wizard-panel" data-panel="2" hidden>
                    <h3 class="wizard-panel-title" id="wizP2Title"><?= __('wizard_p2_title', 'Enter your credentials') ?></h3>
                    <p class="wizard-panel-intro" id="wizP2Intro"></p>
                    <div id="wizP2Body"><!-- swapped by JS --></div>
                </div>

                <!-- ── Panel 3: sender identity ──────────────── -->
                <div class="wizard-panel" data-panel="3" hidden>
                    <h3 class="wizard-panel-title"><?= __('wizard_p3_title', 'Who are emails from?') ?></h3>
                    <p class="wizard-panel-intro"><?= __('wizard_p3_intro', 'This is the name and address recipients will see in their inbox.') ?></p>
                    <div class="field">
                        <label class="field-label" for="wizIn_fromName"><?= __('from_name', 'From name') ?></label>
                        <input type="text" id="wizIn_fromName" maxlength="120" value="<?= e($smtp['from_name']) ?>" placeholder="<?= e(Database::setting('business_name') ?: 'My business') ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="wizIn_fromEmail"><?= __('from_email', 'From email') ?></label>
                        <input type="email" id="wizIn_fromEmail" maxlength="190" value="<?= e($smtp['from_email']) ?>" placeholder="hello@yourdomain.com">
                        <div class="field-hint"><?= __('wizard_fromemail_hint', 'Use an address at a domain you control — receivers reject "from" addresses on free providers (Gmail, Yahoo) when the SMTP server doesn\'t match.') ?></div>
                    </div>
                </div>

                <!-- ── Panel 4: review & save ────────────────── -->
                <div class="wizard-panel" data-panel="4" hidden>
                    <h3 class="wizard-panel-title"><?= __('wizard_p4_title', 'Ready to save') ?></h3>
                    <p class="wizard-panel-intro"><?= __('wizard_p4_intro', 'Here\'s what we\'ll save. Press Back to change anything; press Save when you\'re ready.') ?></p>
                    <dl class="wizard-summary" id="wizSummary"></dl>
                    <div class="wizard-next-step" id="wizNextStep" hidden></div>
                </div>

                <footer class="wizard-footer">
                    <button type="button" class="btn" id="wizBack" hidden><?= __('back', 'Back') ?></button>
                    <span style="flex:1"></span>
                    <button type="button" class="btn btn-primary" id="wizNext"><?= __('continue', 'Continue') ?></button>
                    <button type="submit" class="btn btn-primary" id="wizSave" hidden>
                        <svg style="width:14px;height:14px;vertical-align:-2px;margin-right:5px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <?= __('save_finish', 'Save & finish') ?>
                    </button>
                </footer>
            </form>
        </div>
    </div>
    <?php endif; ?>

<?php /* ══════════════════════════════════════════════════════
   TAB: SECURITY
══════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'security'): ?>

    <div class="card">
        <div class="card-header"><h2><?= __('login_security', 'Login &amp; session') ?></h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_security">
            <input type="hidden" name="_tab"    value="security">

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="session_timeout_minutes">
                        <?= __('session_timeout', 'Session timeout (minutes)') ?>
                    </label>
                    <input type="number" id="session_timeout_minutes" name="session_timeout_minutes"
                           min="5" max="1440" value="<?= e((string)$sec['session_timeout']) ?>"
                           <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="field-hint"><?= __('session_timeout_hint', 'Admin sessions expire after this many minutes of inactivity. Min 5, max 1440.') ?></div>
                </div>
                <div class="field">
                    <label class="field-label" for="max_login_attempts">
                        <?= __('max_login_attempts', 'Max login attempts') ?>
                    </label>
                    <input type="number" id="max_login_attempts" name="max_login_attempts"
                           min="1" max="20" value="<?= e((string)$sec['max_login_attempts']) ?>"
                           <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="field-hint"><?= __('max_attempts_hint', 'Failed attempts before account lockout. Min 1, max 20.') ?></div>
                </div>
            </div>

            <div class="field" style="max-width:240px">
                <label class="field-label" for="lockout_minutes">
                    <?= __('lockout_duration', 'Lockout duration (minutes)') ?>
                </label>
                <input type="number" id="lockout_minutes" name="lockout_minutes"
                       min="1" max="1440" value="<?= e((string)$sec['lockout_minutes']) ?>"
                       <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('lockout_hint', 'How long an account stays locked after too many failed attempts.') ?></div>
            </div>

            <div style="padding: 8px 0 4px">
                <div class="toggle-field">
                    <div class="toggle-wrap">
                        <div class="toggle-title"><?= __('force_https', 'Force HTTPS') ?></div>
                        <div class="toggle-hint"><?= __('force_https_hint', 'Redirect all HTTP traffic to HTTPS. Only enable if your server has a valid SSL certificate.') ?></div>
                    </div>
                    <?php if ($canEdit): ?>
                        <label class="toggle-switch">
                            <input type="checkbox" name="force_https" value="1"
                                   <?= $sec['force_https'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    <?php else: ?>
                        <span class="badge <?= $sec['force_https'] ? 'badge-success' : 'badge-muted' ?>">
                            <?= $sec['force_https'] ? __('on', 'On') : __('off', 'Off') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php /* No 2FA toggle: 2FA isn't implemented. When a real TOTP
                         enrolment flow ships, add the control + setting here. */ ?>
            </div>

            <?php if ($canEdit): ?>
                <div style="margin-top:16px">
                    <button type="submit" class="btn btn-primary"><?= __('save_changes', 'Save changes') ?></button>
                </div>
            <?php endif; ?>
        </form>
    </div>

<?php /* ══════════════════════════════════════════════════════
   TAB: BRANDING
══════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'branding'): ?>

    <div class="card">
        <div class="card-header"><h2><?= __('branding', 'Branding') ?></h2></div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_branding">
            <input type="hidden" name="_tab"    value="branding">

            <div class="field">
                <label class="field-label" for="accent_color"><?= __('accent_color', 'Accent color') ?></label>
                <div class="flex gap-2 items-center">
                    <input type="color" id="accent_color_picker" name="_accent_color_picker"
                           value="<?= e($brandSet['accent_color']) ?>"
                           style="height:44px;width:60px;padding:4px;cursor:pointer;border-radius:var(--radius-sm)"
                           <?= $canEdit ? '' : 'disabled' ?>>
                    <input type="text" id="accent_color_text" name="accent_color"
                           value="<?= e($brandSet['accent_color']) ?>"
                           maxlength="7" pattern="^#[0-9a-fA-F]{6}$"
                           style="font-family:var(--font-mono);max-width:160px"
                           <?= $canEdit ? '' : 'disabled' ?>>
                </div>
                <div class="field-hint"><?= __('accent_color_hint', 'Hex color. Used for buttons, active nav items, and badges. Reload after saving.') ?></div>
            </div>

            <div class="field">
                <label class="field-label" for="button_text_mode"><?= __('button_text_mode', 'Button text color') ?></label>
                <select id="button_text_mode" name="button_text_mode" style="max-width:260px" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="auto"  <?= $brandSet['button_text_mode'] === 'auto'  ? 'selected' : '' ?>><?= __('button_text_auto',  'Auto (best contrast)') ?></option>
                    <option value="light" <?= $brandSet['button_text_mode'] === 'light' ? 'selected' : '' ?>><?= __('button_text_light', 'Light (white)') ?></option>
                    <option value="dark"  <?= $brandSet['button_text_mode'] === 'dark'  ? 'selected' : '' ?>><?= __('button_text_dark',  'Dark (near-black)') ?></option>
                </select>
                <div class="field-hint"><?= __('button_text_mode_hint', "Kohevo automatically picks white or dark text for the best contrast against your accent color. Override it here if the automatic pick isn't the one you want. Reload after saving.") ?></div>
            </div>

            <?php if (function_exists('slate_sidebar_themes')): ?>
            <div class="field">
                <label class="field-label" for="sidebar_theme"><?= __('sidebar_theme', 'Sidebar theme') ?></label>
                <div class="flex gap-2 items-center">
                    <span id="sidebar_theme_swatch" aria-hidden="true"
                          style="width:44px;height:44px;border-radius:var(--radius-sm);border:1px solid var(--border);flex:none"></span>
                    <select id="sidebar_theme" name="sidebar_theme" style="max-width:260px" <?= $canEdit ? '' : 'disabled' ?>>
                        <?php foreach (slate_sidebar_themes() as $tk => $tv): ?>
                            <option value="<?= e($tk) ?>" data-swatch="<?= e($tv['swatch']) ?>"
                                <?= $brandSet['sidebar_theme'] === $tk ? 'selected' : '' ?>><?= e($tv['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-hint"><?= __('sidebar_theme_hint', 'Colour scheme for the desktop admin sidebar. Reload after saving to see it.') ?></div>
                <script>
                (function () {
                    var sel = document.getElementById('sidebar_theme'),
                        sw  = document.getElementById('sidebar_theme_swatch');
                    if (!sel || !sw) return;
                    function paint() {
                        var o = sel.options[sel.selectedIndex];
                        sw.style.background = o ? o.getAttribute('data-swatch') : '';
                    }
                    sel.addEventListener('change', paint);
                    paint();
                })();
                </script>
            </div>
            <?php endif; ?>

            <div class="field">
                <label class="field-label" for="font_family"><?= __('typography', 'Typography font family') ?></label>
                <select id="font_family" name="font_family" style="max-width:320px" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="system"       <?= $brandSet['font_family'] === 'system'       ? 'selected' : '' ?>>System Sans (-apple-system, BlinkMacSystemFont)</option>
                    <option value="plus-jakarta" <?= $brandSet['font_family'] === 'plus-jakarta' ? 'selected' : '' ?>>Plus Jakarta Sans (Modern Editorial)</option>
                    <option value="inter"        <?= $brandSet['font_family'] === 'inter'        ? 'selected' : '' ?>>Inter (Clean UI)</option>
                    <option value="outfit"       <?= $brandSet['font_family'] === 'outfit'        ? 'selected' : '' ?>>Outfit (Contemporary Geometric)</option>
                    <option value="playfair"     <?= $brandSet['font_family'] === 'playfair'      ? 'selected' : '' ?>>Playfair Display (Classic Serif)</option>
                </select>
                <div class="field-hint"><?= __('font_hint', 'Not yet wired into any page template -- saved for a future release.') ?></div>
            </div>

            <div class="field">
                <label class="field-label" for="border_radius"><?= __('corner_radius', 'Corner radius') ?></label>
                <select id="border_radius" name="border_radius" style="max-width:320px" <?= $canEdit ? '' : 'disabled' ?>>
                    <option value="none"   <?= $brandSet['border_radius'] === 'none'   ? 'selected' : '' ?>>Sharp (0px)</option>
                    <option value="small"  <?= $brandSet['border_radius'] === 'small'  ? 'selected' : '' ?>>Subtle (6px)</option>
                    <option value="medium" <?= $brandSet['border_radius'] === 'medium' ? 'selected' : '' ?>>Rounded (10px -- Default)</option>
                    <option value="large"  <?= $brandSet['border_radius'] === 'large'  ? 'selected' : '' ?>>Smooth (16px)</option>
                    <option value="pill"   <?= $brandSet['border_radius'] === 'pill'   ? 'selected' : '' ?>>Full Pill (999px)</option>
                </select>
                <div class="field-hint"><?= __('radius_hint', 'Not yet wired into any page template -- saved for a future release.') ?></div>
            </div>

            <div class="field">
                <label class="field-label" for="logo"><?= __('logo', 'Logo') ?></label>
                <?php
                $logoPreviewUrl = $brandSet['logo_path']
                    ? SLATE_URL . '/' . ltrim($brandSet['logo_path'], '/')
                    : '';
                ?>
                <div class="mb-2" style="padding:12px;background:var(--surface-2);border-radius:var(--radius-sm);text-align:center;min-height:60px;display:flex;align-items:center;justify-content:center">
                    <img id="picked_logo_path-preview"
                         src="<?= e($logoPreviewUrl) ?>" alt="Current logo"
                         style="max-height:80px;max-width:240px;<?= $logoPreviewUrl === '' ? 'display:none;' : '' ?>">
                    <?php if ($logoPreviewUrl === ''): ?>
                        <span class="text-muted text-sm"><?= __('no_logo', 'No logo uploaded') ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($brandSet['logo_path']): ?>
                    <label class="flex items-center gap-2 mb-2">
                        <input type="checkbox" name="remove_logo" value="1"
                               style="width:18px;height:18px;accent-color:var(--accent)">
                        <span><?= __('remove_current_logo', 'Remove current logo') ?></span>
                    </label>
                <?php endif; ?>
                <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                       <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('logo_hint', 'PNG, JPEG, SVG, or WebP. Max 2 MB.') ?></div>
                <?php if ($mediaLibraryActive): ?>
                    <input type="hidden" id="picked_logo_path" name="picked_logo_path" value="">
                    <button type="button" class="mlp-trigger-btn"
                            data-mlp-target="picked_logo_path"
                            data-mlp-mode="single"
                            style="margin-top:8px;">
                        <?= __('pick_logo_from_library', 'Or pick from media library…') ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label" for="logo_dark"><?= __('logo_dark', 'Logo (for dark backgrounds)') ?></label>
                <?php
                $logoDarkPreviewUrl = $brandSet['logo_dark_path']
                    ? SLATE_URL . '/' . ltrim($brandSet['logo_dark_path'], '/')
                    : '';
                ?>
                <div class="mb-2" style="padding:12px;background:#0E1117;border-radius:var(--radius-sm);text-align:center;min-height:60px;display:flex;align-items:center;justify-content:center">
                    <img id="picked_logo_dark_path-preview"
                         src="<?= e($logoDarkPreviewUrl) ?>" alt="Current dark-mode logo"
                         style="max-height:80px;max-width:240px;<?= $logoDarkPreviewUrl === '' ? 'display:none;' : '' ?>">
                    <?php if ($logoDarkPreviewUrl === ''): ?>
                        <span class="text-muted text-sm" style="color:#9CA3AF"><?= __('no_logo_dark', 'No dark-mode logo -- the light logo is used everywhere') ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($brandSet['logo_dark_path']): ?>
                    <label class="flex items-center gap-2 mb-2">
                        <input type="checkbox" name="remove_logo_dark" value="1"
                               style="width:18px;height:18px;accent-color:var(--accent)">
                        <span><?= __('remove_current_logo_dark', 'Remove dark-mode logo') ?></span>
                    </label>
                <?php endif; ?>
                <input type="file" id="logo_dark" name="logo_dark" accept="image/png,image/jpeg,image/webp"
                       <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('logo_dark_hint', 'Optional -- a version of your logo for dark backgrounds (the login page\'s photo panel, the public landing page, error pages, and emails). PNG, JPEG, or WebP. Max 2 MB.') ?></div>
                <?php if ($mediaLibraryActive): ?>
                    <input type="hidden" id="picked_logo_dark_path" name="picked_logo_dark_path" value="">
                    <button type="button" class="mlp-trigger-btn"
                            data-mlp-target="picked_logo_dark_path"
                            data-mlp-mode="single"
                            style="margin-top:8px;">
                        <?= __('pick_logo_dark_from_library', 'Or pick from media library…') ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label" for="favicon"><?= __('favicon', 'Favicon') ?></label>
                <?php $faviconUrl = $brandSet['favicon_path'] ? SLATE_URL . '/' . ltrim($brandSet['favicon_path'], '/') : ''; ?>
                <div class="mb-2" style="padding:12px;background:var(--surface-2);border-radius:var(--radius-sm);text-align:center;min-height:48px;display:flex;align-items:center;justify-content:center">
                    <img id="picked_favicon_path-preview" src="<?= e($faviconUrl) ?>" alt="Current favicon"
                         style="width:32px;height:32px;object-fit:contain;<?= $faviconUrl === '' ? 'display:none;' : '' ?>">
                    <?php if ($faviconUrl === ''): ?><span class="text-muted text-sm"><?= __('no_favicon', 'No favicon uploaded') ?></span><?php endif; ?>
                </div>
                <?php if ($brandSet['favicon_path']): ?>
                    <label class="flex items-center gap-2 mb-2">
                        <input type="checkbox" name="remove_favicon" value="1" style="width:18px;height:18px;accent-color:var(--accent)">
                        <span><?= __('remove_favicon', 'Remove current favicon') ?></span>
                    </label>
                <?php endif; ?>
                <input type="file" id="favicon" name="favicon" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico" <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('favicon_hint', 'PNG or ICO, square (32×32 or 64×64). Max 512 KB. Shown in the browser tab.') ?></div>
                <?php if ($mediaLibraryActive): ?>
                    <input type="hidden" id="picked_favicon_path" name="picked_favicon_path" value="">
                    <button type="button" class="mlp-trigger-btn"
                            data-mlp-target="picked_favicon_path"
                            data-mlp-mode="single"
                            style="margin-top:8px;">
                        <?= __('pick_favicon_from_library', 'Or pick from media library…') ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label" for="login_image"><?= __('login_image', 'Login page image') ?></label>
                <?php
                $loginImgUrl = $brandSet['login_image_path']
                    ? SLATE_URL . '/' . ltrim($brandSet['login_image_path'], '/')
                    : '';
                ?>
                <div class="mb-2" style="padding:12px;background:var(--surface-2);border-radius:var(--radius-sm);text-align:center;min-height:120px;display:flex;align-items:center;justify-content:center">
                    <img id="picked_login_image_path-preview"
                         src="<?= e($loginImgUrl) ?>" alt="Current login image"
                         style="max-height:160px;max-width:100%;border-radius:var(--radius-sm);<?= $loginImgUrl === '' ? 'display:none;' : '' ?>">
                    <?php if ($loginImgUrl === ''): ?>
                        <span class="text-muted text-sm"><?= __('no_login_image', 'No image set — a branded gradient is shown instead') ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($brandSet['login_image_path']): ?>
                    <label class="flex items-center gap-2 mb-2">
                        <input type="checkbox" name="remove_login_image" value="1"
                               style="width:18px;height:18px;accent-color:var(--accent)">
                        <span><?= __('remove_current_login_image', 'Remove current login image') ?></span>
                    </label>
                <?php endif; ?>
                <input type="file" id="login_image" name="login_image" accept="image/png,image/jpeg,image/webp"
                       <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('login_image_hint', 'Shown in the left column of the login screen. PNG, JPEG, or WebP. Tall/portrait images look best. Max 5 MB.') ?></div>
                <?php if ($mediaLibraryActive): ?>
                    <input type="hidden" id="picked_login_image_path" name="picked_login_image_path" value="">
                    <button type="button" class="mlp-trigger-btn"
                            data-mlp-target="picked_login_image_path"
                            data-mlp-mode="single"
                            style="margin-top:8px;">
                        <?= __('pick_login_image_from_library', 'Or pick from media library…') ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label" for="login_tagline"><?= __('login_tagline', 'Login tagline') ?></label>
                <input type="text" id="login_tagline" name="login_tagline"
                       value="<?= e($brandSet['login_tagline']) ?>"
                       maxlength="200" placeholder="<?= e(__('login_tagline_ph', 'Capturing moments, creating memories')) ?>"
                       <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('login_tagline_hint', 'Optional. Displayed over the login image. Leave blank to hide.') ?></div>
            </div>

            <h3 style="margin-top:28px;"><?= __('white_label_heading', 'White label & support') ?></h3>
            <p class="field-hint" style="margin-top:-4px;">
                <?= __('white_label_intro', 'Shown on the Help page and login screen instead of the built-in defaults.') ?>
            </p>

            <div class="field">
                <label class="field-label" for="brand_owner"><?= __('owner_name', 'Owner / company name') ?></label>
                <input type="text" id="brand_owner" name="brand_owner"
                       value="<?= e($brandSet['owner']) ?>" maxlength="120"
                       placeholder="<?= e(__('owner_name_ph', 'Your company name')) ?>"
                       <?= $canEdit ? '' : 'disabled' ?>>
            </div>

            <div class="field">
                <label class="field-label" for="brand_owner_url"><?= __('owner_website', 'Owner website') ?></label>
                <input type="url" id="brand_owner_url" name="brand_owner_url"
                       value="<?= e($brandSet['owner_url']) ?>" maxlength="300"
                       placeholder="https://example.com"
                       <?= $canEdit ? '' : 'disabled' ?>>
            </div>

            <div class="field">
                <label class="field-label" for="support_email"><?= __('support_email', 'Support email') ?></label>
                <input type="email" id="support_email" name="support_email"
                       value="<?= e($brandSet['support_email']) ?>" maxlength="190"
                       placeholder="support@example.com"
                       <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('support_email_hint', 'Support tickets from the Help page are sent here.') ?></div>
            </div>

            <div class="field">
                <label class="field-label" for="support_phone"><?= __('support_phone', 'Support phone') ?></label>
                <input type="text" id="support_phone" name="support_phone"
                       value="<?= e($brandSet['support_phone']) ?>" maxlength="40"
                       placeholder="+1 555 0100"
                       <?= $canEdit ? '' : 'disabled' ?>>
            </div>

            <div class="field">
                <label class="field-label" for="support_url"><?= __('support_url', 'Support URL') ?></label>
                <input type="url" id="support_url" name="support_url"
                       value="<?= e($brandSet['support_url']) ?>" maxlength="300"
                       placeholder="https://example.com/support"
                       <?= $canEdit ? '' : 'disabled' ?>>
            </div>

            <div class="field">
                <label class="field-label" for="docs_url"><?= __('docs_url', 'Documentation URL') ?></label>
                <input type="url" id="docs_url" name="docs_url"
                       value="<?= e($brandSet['docs_url']) ?>" maxlength="300"
                       placeholder="https://example.com/docs"
                       <?= $canEdit ? '' : 'disabled' ?>>
            </div>

            <div class="field">
                <label class="field-label" for="privacy_url"><?= __('privacy_url', 'Privacy policy URL') ?></label>
                <input type="url" id="privacy_url" name="privacy_url"
                       value="<?= e($brandSet['privacy_url']) ?>" maxlength="300"
                       placeholder="https://example.com/privacy"
                       <?= $canEdit ? '' : 'disabled' ?>>
            </div>

            <div class="field">
                <label class="field-label" for="terms_url"><?= __('terms_url', 'Terms of service URL') ?></label>
                <input type="url" id="terms_url" name="terms_url"
                       value="<?= e($brandSet['terms_url']) ?>" maxlength="300"
                       placeholder="https://example.com/terms"
                       <?= $canEdit ? '' : 'disabled' ?>>
            </div>

            <?php if ($canEdit): ?>
                <button type="submit" class="btn btn-primary"><?= __('save_changes', 'Save changes') ?></button>
            <?php endif; ?>
        </form>
    </div>

<?php /* ══════════════════════════════════════════════════════
   TAB: LANDING
══════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'landing'): ?>

    <style>
    .lf-row {
        display: grid; gap: 10px 14px; align-items: start;
        grid-template-columns: minmax(0, 260px) minmax(0, 1fr);
        padding: 14px 0; border-top: 1px solid var(--border);
    }
    .lf-row:first-of-type { border-top: 0; }
    .lf-check { display: flex; align-items: flex-start; gap: 9px; cursor: pointer; font-size: 13.5px; }
    .lf-check input { margin-top: 3px; flex: none; }
    .lf-title { display: flex; flex-direction: column; gap: 1px; font-weight: 600; color: var(--text); min-width: 0; }
    .lf-title small { font-family: var(--font-mono, monospace); font-size: 11px; font-weight: 500; color: var(--muted); }
    .lf-fields { display: grid; gap: 8px; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 150px); }
    @media (max-width: 720px) {
        .lf-row { grid-template-columns: minmax(0, 1fr); }
        .lf-fields { grid-template-columns: minmax(0, 1fr); }
    }
    </style>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_landing">
        <input type="hidden" name="_tab"    value="landing">

    <div class="card">
        <div class="card-header"><h2><?= __('landing_content', 'Landing page content') ?></h2></div>
        <p class="text-muted text-sm mb-3">
            <?= __('landing_intro_help', 'Controls the public landing page at your site root.') ?>
            <a href="<?= e(SLATE_URL) ?>/" target="_blank" rel="noopener"><?= __('view_landing', 'View landing page ↗') ?></a>
        </p>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="landing_eyebrow"><?= __('landing_eyebrow', 'Eyebrow (small label above title)') ?></label>
                    <input type="text" id="landing_eyebrow" name="landing_eyebrow" maxlength="80"
                           placeholder="Welcome" value="<?= e($landing['eyebrow']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
                <div class="field">
                    <label class="field-label" for="landing_title"><?= __('landing_title', 'Title') ?></label>
                    <input type="text" id="landing_title" name="landing_title" maxlength="120"
                           placeholder="<?= e(Database::setting('business_name') ?: 'Your business name') ?>"
                           value="<?= e($landing['title']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="field-hint"><?= __('landing_title_hint', 'Leave blank to use your business name.') ?></div>
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="landing_intro"><?= __('landing_intro_text', 'Intro paragraph') ?></label>
                <textarea id="landing_intro" name="landing_intro" rows="2" maxlength="400"
                          placeholder="Choose an option below to get started." <?= $canEdit ? '' : 'disabled' ?>><?= e($landing['intro']) ?></textarea>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="landing_website_url"><?= __('landing_website_url', 'Back-to-website link (main domain)') ?></label>
                    <input type="text" id="landing_website_url" name="landing_website_url" maxlength="300"
                           placeholder="https://www.your-main-domain.com" value="<?= e($landing['url']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="field-hint"><?= __('landing_website_url_hint', 'Shown as a link at the top. Leave blank to hide it.') ?></div>
                </div>
                <div class="field">
                    <label class="field-label" for="landing_website_label"><?= __('landing_website_label', 'Link text') ?></label>
                    <input type="text" id="landing_website_label" name="landing_website_label" maxlength="60"
                           placeholder="Back to website" value="<?= e($landing['label']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="landing_footer"><?= __('landing_footer', 'Footer text') ?></label>
                <input type="text" id="landing_footer" name="landing_footer" maxlength="200"
                       placeholder="<?= e('© ' . date('Y') . ' ' . (Database::setting('business_name') ?: 'Your business') . '. All rights reserved.') ?>"
                       value="<?= e($landing['footer']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('landing_footer_hint', 'Leave blank for an automatic © business name line.') ?></div>
            </div>

    </div><!-- /content card -->

    <?php if ($bookingPluginActive): ?>
    <div class="card">
        <div class="card-header"><h2><?= __('landing_booking', 'Book online') ?></h2></div>
        <p class="text-muted text-sm mb-3">
            <?= __('landing_booking_help', 'Feature a card that links straight to your booking catalog.') ?>
            <a href="<?= e(SLATE_URL) ?>/book" target="_blank" rel="noopener"><?= __('view_booking', 'View booking page ↗') ?></a>
        </p>
        <div class="lf-row">
            <label class="lf-check">
                <input type="checkbox" name="landing_booking_enabled" value="1" <?= $landingBooking['enabled'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                <span class="lf-title"><?= __('feature_booking_card', 'Feature the booking catalog on the landing page') ?><small>/book</small></span>
            </label>
            <div class="lf-fields">
                <input type="text" name="landing_booking_label" maxlength="60"
                       placeholder="<?= __('card_label', 'Card label') ?>" value="<?= e($landingBooking['label']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                <input type="text" name="landing_booking_blurb" maxlength="160"
                       placeholder="<?= __('short_description', 'Short description') ?>" value="<?= e($landingBooking['blurb']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                <select name="landing_booking_icon" <?= $canEdit ? '' : 'disabled' ?>>
                    <?php foreach (['clipboard'=>'Clipboard','powerboat'=>'Powerboat','sailboat'=>'Sailboat','boat'=>'Boat','anchor'=>'Anchor','compass'=>'Compass','star'=>'Star'] as $ik => $il): ?>
                        <option value="<?= $ik ?>" <?= $landingBooking['icon'] === $ik ? 'selected' : '' ?>><?= $il ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="landing_booking_button" maxlength="40" style="grid-column:1/-1"
                       placeholder="<?= __('card_button_ph', 'Button text (blank = “Start ” + card label)') ?>" value="<?= e($landingBooking['button']) ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </div>
        </div>
    </div><!-- /booking card -->
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2><?= __('landing_entry_points', 'Entry points') ?></h2></div>
        <p class="text-muted text-sm mb-3">
            <?= __('landing_entry_points_help', 'Quick-access tiles shown on the landing page. On by default — untick to hide one.') ?>
        </p>
        <label class="lf-check" style="margin-bottom:10px;">
            <input type="checkbox" name="landing_show_customer_login" value="1" <?= $landingShowCustomerLogin ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
            <span class="lf-title"><?= __('landing_show_customer_login', 'Customer login tile') ?><small>/customer/login.php</small></span>
        </label>
        <label class="lf-check">
            <input type="checkbox" name="landing_show_admin_login" value="1" <?= $landingShowAdminLogin ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
            <span class="lf-title"><?= __('landing_show_admin_login', 'Admin login tile') ?><small>/admin/login.php</small></span>
        </label>
    </div><!-- /entry points card -->

    <div class="card">
        <div class="card-header"><h2><?= __('landing_forms', 'Forms shown on the landing page') ?></h2></div>
        <p class="text-muted text-sm mb-3">
            <?= __('landing_forms_help', 'Tick the published forms to feature as cards. The label, description and icon are optional — blank uses the form’s own title.') ?>
        </p>

        <?php if (empty($publishedForms)): ?>
            <div class="empty"><div class="empty-title"><?= __('no_published_forms', 'No published forms') ?></div>
                <p class="text-sm"><?= __('no_published_forms_hint', 'Publish a form first, then come back to feature it here.') ?></p></div>
        <?php else: ?>
            <?php foreach ($publishedForms as $pf):
                $fid  = (int)$pf['id'];
                $ov   = $landingFormMap[$fid] ?? null;
                $isOn = $ov !== null;
            ?>
            <div class="lf-row">
                <input type="hidden" name="lf_ids[]" value="<?= $fid ?>">
                <label class="lf-check">
                    <input type="checkbox" name="lf_include[<?= $fid ?>]" value="1" <?= $isOn ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                    <span class="lf-title"><?= e($pf['title']) ?><small>/forms/<?= e($pf['slug']) ?></small></span>
                </label>
                <div class="lf-fields">
                    <input type="text" name="lf_label[<?= $fid ?>]" maxlength="60"
                           placeholder="<?= __('card_label', 'Card label') ?>" value="<?= e($ov['label'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <input type="text" name="lf_blurb[<?= $fid ?>]" maxlength="160"
                           placeholder="<?= __('short_description', 'Short description') ?>" value="<?= e($ov['blurb'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <select name="lf_icon[<?= $fid ?>]" <?= $canEdit ? '' : 'disabled' ?>>
                        <?php foreach (['clipboard'=>'Clipboard','powerboat'=>'Powerboat','sailboat'=>'Sailboat','boat'=>'Boat','anchor'=>'Anchor','compass'=>'Compass','star'=>'Star'] as $ik => $il): ?>
                            <option value="<?= $ik ?>" <?= ($ov['icon'] ?? 'clipboard') === $ik ? 'selected' : '' ?>><?= $il ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="lf_button[<?= $fid ?>]" maxlength="40" style="grid-column:1/-1"
                           placeholder="<?= __('card_button_ph', 'Button text (blank = “Start ” + card label)') ?>" value="<?= e($ov['button'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($canEdit): ?>
            <button type="submit" class="btn btn-primary mt-3"><?= __('save_changes', 'Save changes') ?></button>
        <?php endif; ?>
    </div><!-- /forms card -->
    </form>

<?php /* ══════════════════════════════════════════════════════
   TAB: SYSTEM
══════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'system'): ?>

    <div class="card">
        <div class="card-header"><h2><?= __('system_settings', 'System settings') ?></h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_system">
            <input type="hidden" name="_tab"    value="system">

            <div class="field">
                <label class="field-label" for="timezone"><?= __('timezone', 'Timezone') ?></label>
                <select id="timezone" name="timezone" <?= $canEdit ? '' : 'disabled' ?>>
                    <?php foreach (timezone_identifiers_list() as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= $tz === $sys['timezone'] ? 'selected' : '' ?>>
                            <?= e($tz) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint"><?= __('timezone_hint', 'Used for displaying dates and times throughout the admin.') ?></div>
            </div>

            <div style="padding: 8px 0 4px">
                <div class="toggle-field">
                    <div class="toggle-wrap">
                        <div class="toggle-title"><?= __('maintenance_mode', 'Maintenance mode') ?></div>
                        <div class="toggle-hint"><?= __('maintenance_hint', 'Show a maintenance page to visitors. Admins can still log in and access the dashboard.') ?></div>
                    </div>
                    <?php if ($canEdit): ?>
                        <label class="toggle-switch">
                            <input type="checkbox" name="maintenance_mode" value="1"
                                   <?= $sys['maintenance_mode'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    <?php else: ?>
                        <span class="badge <?= $sys['maintenance_mode'] ? 'badge-warning' : 'badge-muted' ?>">
                            <?= $sys['maintenance_mode'] ? __('on', 'On') : __('off', 'Off') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="toggle-field">
                    <div class="toggle-wrap">
                        <div class="toggle-title"><?= __('debug_mode', 'Debug mode') ?></div>
                        <div class="toggle-hint"><?= __('debug_hint', 'Show detailed error messages. Disable in production — errors may expose sensitive server paths.') ?></div>
                    </div>
                    <?php if ($canEdit): ?>
                        <label class="toggle-switch">
                            <input type="checkbox" name="debug_mode" value="1"
                                   <?= $sys['debug_mode'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    <?php else: ?>
                        <span class="badge <?= $sys['debug_mode'] ? 'badge-danger' : 'badge-muted' ?>">
                            <?= $sys['debug_mode'] ? __('on', 'On') : __('off', 'Off') ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($canEdit): ?>
                <div style="margin-top:16px">
                    <button type="submit" class="btn btn-primary"><?= __('save_changes', 'Save changes') ?></button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('system_info', 'System information') ?></h2></div>
        <div class="info-row">
            <span class="info-row-label"><?= __('php_version', 'PHP version') ?></span>
            <span class="info-row-value"><?= e($sys['php_version']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-row-label"><?= __('slate_version', 'Kohevo version') ?></span>
            <span class="info-row-value"><?= e($sys['slate_version']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-row-label"><?= __('server_software', 'Server software') ?></span>
            <span class="info-row-value"><?= e(explode(' ', $_SERVER['SERVER_SOFTWARE'] ?? 'unknown')[0]) ?></span>
        </div>
        <div class="info-row">
            <span class="info-row-label"><?= __('memory_limit', 'Memory limit') ?></span>
            <span class="info-row-value"><?= e(ini_get('memory_limit')) ?></span>
        </div>
        <div class="info-row">
            <span class="info-row-label"><?= __('upload_max_filesize', 'Max upload size') ?></span>
            <span class="info-row-value"><?= e(ini_get('upload_max_filesize')) ?></span>
        </div>
        <div class="info-row">
            <span class="info-row-label"><?= __('current_timezone', 'Active timezone') ?></span>
            <span class="info-row-value"><?= e($sys['timezone']) ?></span>
        </div>
    </div>

<?php endif; ?>

<script>
(function () {
    // ── Color picker ↔ text input sync (Branding tab) ──────────
    var picker = document.getElementById('accent_color_picker');
    var text   = document.getElementById('accent_color_text');
    if (picker && text) {
        // picker → text (while dragging and on change)
        picker.addEventListener('input', function () {
            text.value = picker.value;
        });
        // text → picker (when user types a valid hex)
        text.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
                picker.value = text.value;
            }
        });
        text.addEventListener('blur', function () {
            // Normalise to uppercase on blur for consistency
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
                text.value = text.value.toUpperCase();
                picker.value = text.value;
            }
        });
    }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>