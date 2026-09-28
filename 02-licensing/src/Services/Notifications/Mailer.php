<?php
/**
 * Slate — Mailer.
 *
 * Minimal email sender. Uses PHPMailer if available (vendor/),
 * falls back to PHP's mail() if not. SMTP credentials are read
 * from the `settings` table:
 *
 *   smtp_host, smtp_port, smtp_user, smtp_pass,
 *   smtp_from_email, smtp_from_name, smtp_encryption
 *
 * Plugins that need queueing, retry, logging, or templates wrap
 * this with their own infrastructure.
 */

declare(strict_types=1);

namespace Slate\Services\Notifications;

// Phase 1 A3: migrated from includes/Mailer.php into Slate\Services\Notifications.
// The old global name `Mailer` resolves via a class_alias in src/compat/aliases.php.
// Behavior identical — cross-namespace static calls qualified to global aliases
// (\\Database::, \\AuditLog::, \\SmtpOAuth::). PHPMailer classes were already
// fully-qualified (\PHPMailer\PHPMailer\PHPMailer). Global functions/constants and
// \Throwable fall back to / are already global.
class Mailer {

    /**
     * Send an email. Returns true on success, false on failure.
     *
     * $toName is optional — most callers don't have the recipient's display
     * name handy, and SMTP doesn't require one. Pass it when you have it
     * (e.g., when sending to a known customer record). The old signature
     * had $toName as the SECOND required argument and exactly one caller
     * (settings.php's test-email button) was calling it with three args
     * `(to, subject, body)`, which PHP mapped positionally as
     * `(to, toName, subject)` and then fatal'd on the missing $bodyHtml
     * — the source of the test-email 500.
     *
     * If $log is true (default), the attempt is recorded to the audit log.
     */
    /**
     * @param array<int,array{name:string,data?:string,path?:string,mime?:string}> $attachments
     *   Each item is either an in-memory file (`data` + `name` [+ `mime`]) or a
     *   disk file (`path` + optional `name`). Defaults to no attachments, so
     *   every existing caller keeps working unchanged.
     */
    public static function send(string $to, string $subject, string $bodyHtml, string $toName = '', bool $log = true, array $attachments = []): bool {
        $primary = self::buildCfg('');
        [$ok, $err, $driver] = self::tryWith($primary, $to, $toName, $subject, $bodyHtml, $attachments);

        // Backup connection: tried only if (a) the user enabled it on the
        // settings page, (b) the primary actually failed (not a no-op), and
        // (c) the backup configuration is distinct from the primary (no
        // point in retrying the same broken path). The audit log records
        // both attempts so the admin can see what happened.
        $backupAttempted = false;
        $backupErr = '';
        if (!$ok && (string)\Database::setting('smtp_backup_enabled') === '1') {
            $backup = self::buildCfg('backup_');
            if ($backup['host'] !== '' || $primary['host'] !== '') {
                if ($backup['host'] !== $primary['host'] || $backup['user'] !== $primary['user']) {
                    $backupAttempted = true;
                    [$ok2, $err2, $driver2] = self::tryWith($backup, $to, $toName, $subject, $bodyHtml, $attachments);
                    if ($ok2) {
                        $ok     = true;
                        $driver = 'backup:' . $driver2;
                        $err    = ''; // backup succeeded; primary err is logged below
                    } else {
                        $backupErr = $err2;
                    }
                }
            }
        }

        if ($log && class_exists('AuditLog')) {
            \AuditLog::record('mail.send', $to, [
                'subject'         => $subject,
                'ok'              => $ok,
                'driver'          => $driver,
                'error'           => $err,
                'backup_tried'    => $backupAttempted,
                'backup_error'    => $backupErr,
            ]);
        }

        return $ok;
    }

    /**
     * Snapshot every setting any driver might read, keyed by a flat array.
     * `$prefix` is "" for primary or "backup_" for the backup connection.
     * From-name / from-email cascade: a blank backup-specific override
     * falls through to the primary value, so admins only have to set the
     * From row once (the typical case).
     *
     * @return array<string,string|bool>
     */
    private static function buildCfg(string $prefix): array {
        $p   = 'smtp_' . $prefix;
        $get = static fn(string $k): string => (string)(\Database::setting($p . $k) ?? '');

        $fromEmail = $get('from_email');
        if ($fromEmail === '' && $prefix !== '') $fromEmail = (string)(\Database::setting('smtp_from_email') ?? '');
        $fromName  = $get('from_name');
        if ($fromName === ''  && $prefix !== '') $fromName  = (string)(\Database::setting('smtp_from_name')  ?? '');

        return [
            'prefix'     => $prefix,
            'host'       => $get('host'),
            'port'       => $get('port'),
            'user'       => $get('user'),
            'pass'       => $get('pass'),
            'encryption' => $get('encryption') ?: 'tls',
            'from_email' => $fromEmail,
            'from_name'  => $fromName,
            'auth_type'  => $get('auth_type'),
            // OAuth fields — only the primary connection currently supports
            // OAuth (Graph / XOAUTH2). The backup never reads these; we
            // pass them anyway so the cfg shape is uniform.
            'oauth_provider'      => $get('oauth_provider'),
            'oauth_client_id'     => $get('oauth_client_id'),
            'oauth_client_secret' => $get('oauth_client_secret'),
            'oauth_email'         => $get('oauth_email'),
            'oauth_refresh_token' => $get('oauth_refresh_token'),
        ];
    }

    /**
     * Route the message through whichever driver matches the cfg's host,
     * returning [ok, err, driverName] so send() can log which path was
     * actually exercised.
     *
     * @return array{0:bool, 1:string, 2:string}
     */
    private static function tryWith(array $cfg, string $to, string $toName, string $subject, string $body, array $attachments): array {
        $host = (string)$cfg['host'];
        if ($host === 'api.resend.com') {
            [$ok, $err] = self::sendViaResend($cfg, $to, $toName, $subject, $body, $attachments);
            return [$ok, $err, 'resend'];
        }
        if ($host === 'graph.microsoft.com') {
            [$ok, $err] = self::sendViaGraph($cfg, $to, $toName, $subject, $body, $attachments);
            return [$ok, $err, 'graph'];
        }
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer') && $host !== '') {
            [$ok, $err] = self::sendViaPHPMailer($cfg, $to, $toName, $subject, $body, $attachments);
            return [$ok, $err, 'smtp'];
        }
        [$ok, $err] = self::sendViaMail($cfg, $to, $toName, $subject, $body, $attachments);
        return [$ok, $err, 'mail'];
    }

    /**
     * Apply SMTP authentication to a PHPMailer instance: OAuth 2.0 (XOAUTH2)
     * when a Microsoft/Gmail account is connected (primary only), otherwise
     * username + decrypted password. Centralised so send and verify behave
     * identically. Reads from the supplied cfg snapshot, not Database.
     */
    private static function applyAuth(\PHPMailer\PHPMailer\PHPMailer $mail, array $cfg): void {
        // OAuth only on the primary connection — backup is password-only.
        if ($cfg['prefix'] === '' && class_exists('SmtpOAuth') && $cfg['auth_type'] === 'xoauth2') {
            $oauth = \SmtpOAuth::fromSettings();
            if ($oauth) {
                $mail->SMTPAuth = true;
                $mail->AuthType = 'XOAUTH2';
                $mail->setOAuth($oauth);
                return;
            }
        }
        $user = (string)$cfg['user'];
        $pass = (string)$cfg['pass'];
        if ($user !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = function_exists('slate_decrypt_secret')
                ? (slate_decrypt_secret($pass) ?? $pass)
                : $pass;
        }
    }

    /** @return array{0:bool, 1:string} [success, errorMessage] */
    private static function sendViaPHPMailer(array $cfg, string $to, string $toName, string $subject, string $body, array $attachments = []): array {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string)$cfg['host'];
            $mail->Port = (int)($cfg['port'] ?: 587);

            self::applyAuth($mail, $cfg);

            $enc = (string)$cfg['encryption'];
            if ($enc === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls' || $enc === 'starttls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $fromEmail = $cfg['from_email'] ?: 'no-reply@example.com';
            $fromName  = $cfg['from_name']  ?: 'Kohevo';
            $mail->setFrom($fromEmail, $fromName);
            // Pass the recipient name only when we have one; PHPMailer
            // accepts an empty string but it produces an awkward
            // "<>name@host" header on some MTAs.
            if ($toName !== '') {
                $mail->addAddress($to, $toName);
            } else {
                $mail->addAddress($to);
            }
            $mail->CharSet  = 'UTF-8';   // otherwise UTF-8 subjects/bodies mojibake (â€" etc.)
            $mail->Encoding = 'base64';
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            foreach ($attachments as $att) {
                $name = (string)($att['name'] ?? 'attachment');
                $mime = (string)($att['mime'] ?? 'application/octet-stream');
                if (isset($att['data'])) {
                    $mail->addStringAttachment((string)$att['data'], $name, \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, $mime);
                } elseif (!empty($att['path']) && is_file($att['path'])) {
                    $mail->addAttachment((string)$att['path'], $name);
                }
            }

            $mail->send();
            return [true, ''];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Send via the Resend HTTPS API (https://resend.com). Bypasses SMTP
     * entirely — uses port 443, which is never blocked by shared hosts.
     * API key lives in the `smtp_pass` setting (same encrypted field used
     * by SMTP password). From address + name come from the same settings.
     *
     * @return array{0:bool, 1:string} [success, errorMessage]
     */
    private static function sendViaResend(array $cfg, string $to, string $toName, string $subject, string $body, array $attachments = []): array {
        $apiKey = (string)$cfg['pass'];
        if ($apiKey !== '' && function_exists('slate_decrypt_secret')) {
            $apiKey = slate_decrypt_secret($apiKey) ?? $apiKey;
        }
        if ($apiKey === '') {
            return [false, 'Resend API key not configured — paste it into the SMTP "Password" field.'];
        }
        $fromEmail = $cfg['from_email'] ?: 'no-reply@example.com';
        $fromName  = (string)$cfg['from_name'];
        $from      = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail;
        $toAddr    = $toName !== '' ? sprintf('%s <%s>', $toName, $to) : $to;

        $payload = [
            'from'    => $from,
            'to'      => [$toAddr],
            'subject' => $subject,
            'html'    => $body,
            'text'    => strip_tags($body),
        ];
        if ($attachments) {
            $atts = [];
            foreach ($attachments as $att) {
                $name = (string)($att['name'] ?? 'attachment');
                if (isset($att['data'])) {
                    $atts[] = ['filename' => $name, 'content' => base64_encode((string)$att['data'])];
                } elseif (!empty($att['path']) && is_file($att['path'])) {
                    $atts[] = ['filename' => $name, 'content' => base64_encode((string)file_get_contents($att['path']))];
                }
            }
            if ($atts) $payload['attachments'] = $atts;
        }

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return [false, 'Resend cURL error: ' . $cerr];
        if ($http >= 200 && $http < 300) return [true, ''];
        // Try to surface Resend's error message instead of a raw blob.
        $decoded = json_decode((string)$resp, true);
        $msg = is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? $resp) : $resp;
        return [false, "Resend HTTP $http: $msg"];
    }

    /**
     * Send via the Microsoft Graph API (https://graph.microsoft.com).
     * Bypasses SMTP entirely — uses HTTPS:443, which is never blocked by
     * shared-host firewalls. Reuses the OAuth refresh-token machinery in
     * SmtpOAuth (same Azure app registration as the SMTP/XOAUTH2 path)
     * to mint a short-lived access token, then POSTs to /me/sendMail.
     *
     * Requires the `Mail.Send` delegated permission on the Azure app
     * (in addition to `SMTP.Send`) with admin consent granted.
     *
     * @return array{0:bool, 1:string} [success, errorMessage]
     */
    private static function sendViaGraph(array $cfg, string $to, string $toName, string $subject, string $body, array $attachments = []): array {
        // Graph is primary-only — backup is password-based. If the backup
        // somehow points at graph.microsoft.com, bail rather than reach
        // for the (primary's) OAuth tokens.
        if ($cfg['prefix'] !== '') {
            return [false, 'Microsoft Graph is not available as a backup connection.'];
        }
        if (!class_exists('SmtpOAuth')) {
            return [false, 'Microsoft Graph driver requires SmtpOAuth helper.'];
        }
        $oauth = \SmtpOAuth::fromSettings();
        if (!$oauth) {
            return [false, 'Microsoft account not connected. Connect via the OAuth 2.0 section of the SMTP tab first.'];
        }
        try {
            $token = $oauth->bearerToken();
        } catch (\Throwable $e) {
            return [false, 'OAuth token refresh failed: ' . $e->getMessage()];
        }

        $fromEmail = $cfg['from_email'] ?: $oauth->email();
        $fromName  = (string)$cfg['from_name'];

        $message = [
            'subject' => $subject,
            'body'    => ['contentType' => 'HTML', 'content' => $body],
            'toRecipients' => [[
                'emailAddress' => array_filter([
                    'address' => $to,
                    'name'    => $toName !== '' ? $toName : null,
                ]),
            ]],
        ];
        // Setting "from" on the Graph message triggers a Send-As permission
        // check inside Exchange. Tenants without that grant 403 the send. So
        // we ONLY override "from" when the address differs from the OAuth
        // account; a display-name difference alone goes on the implicit
        // sender. The user can still rename their mailbox profile in M365
        // for the display name on outbound mail.
        if ($fromEmail !== '' && strcasecmp($fromEmail, $oauth->email()) !== 0) {
            $message['from'] = ['emailAddress' => array_filter([
                'address' => $fromEmail,
                'name'    => $fromName !== '' ? $fromName : null,
            ])];
        }
        if ($attachments) {
            $atts = [];
            foreach ($attachments as $att) {
                $name = (string)($att['name'] ?? 'attachment');
                $mime = (string)($att['mime'] ?? 'application/octet-stream');
                if (isset($att['data'])) {
                    $atts[] = [
                        '@odata.type'  => '#microsoft.graph.fileAttachment',
                        'name'         => $name,
                        'contentType'  => $mime,
                        'contentBytes' => base64_encode((string)$att['data']),
                    ];
                } elseif (!empty($att['path']) && is_file($att['path'])) {
                    $atts[] = [
                        '@odata.type'  => '#microsoft.graph.fileAttachment',
                        'name'         => $name,
                        'contentType'  => $mime,
                        'contentBytes' => base64_encode((string)file_get_contents($att['path'])),
                    ];
                }
            }
            if ($atts) $message['attachments'] = $atts;
        }

        $payload = [
            'message'         => $message,
            'saveToSentItems' => false,
        ];

        // Use /users/{from}/sendMail rather than /me/sendMail so the request
        // is unambiguous about which mailbox sends — required for app-only
        // tokens and harmless for delegated tokens (Microsoft maps it to
        // the authenticated user when it matches).
        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($oauth->email()) . '/sendMail';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return [false, 'Graph cURL error: ' . $cerr];
        // 202 Accepted is the documented success response for sendMail.
        if ($http >= 200 && $http < 300) return [true, ''];
        $decoded = json_decode((string)$resp, true);
        $msg = is_array($decoded) ? ($decoded['error']['message'] ?? ($decoded['message'] ?? $resp)) : $resp;
        return [false, "Graph HTTP $http: $msg"];
    }

    /** @return array{0:bool, 1:string} */
    private static function sendViaMail(array $cfg, string $to, string $toName, string $subject, string $body, array $attachments = []): array {
        $clean = fn(string $s) => str_replace(["\r", "\n", "\0"], '', $s);
        $to       = $clean($to);
        $toName   = $clean($toName);
        // RFC 2047-encode the subject so UTF-8 (em-dashes, accents…) isn't
        // mangled into mojibake by mail() (which assumes ASCII headers).
        $subject  = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($clean($subject), 'UTF-8', 'B', "\r\n")
            : $clean($subject);
        $fromMail = $clean((string)($cfg['from_email'] ?: 'no-reply@example.com'));
        $fromName = $clean((string)($cfg['from_name']  ?: 'Kohevo'));

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: {$fromName} <{$fromMail}>\r\n";
        $headers .= "Reply-To: {$fromMail}\r\n";

        if (empty($attachments)) {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $ok = @mail($to, $subject, $body, $headers);
            return [(bool)$ok, $ok ? '' : 'mail() returned false'];
        }

        // multipart/mixed: HTML part + each attachment as base64.
        $boundary = 'slate_' . bin2hex(random_bytes(12));
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

        $nl  = "\r\n";
        $msg  = "--{$boundary}{$nl}";
        $msg .= "Content-Type: text/html; charset=UTF-8{$nl}";
        $msg .= "Content-Transfer-Encoding: 8bit{$nl}{$nl}";
        $msg .= $body . $nl;
        foreach ($attachments as $att) {
            $name = $clean((string)($att['name'] ?? 'attachment'));
            $mime = (string)($att['mime'] ?? 'application/octet-stream');
            $raw  = isset($att['data']) ? (string)$att['data']
                  : (!empty($att['path']) && is_file($att['path']) ? (string)@file_get_contents($att['path']) : '');
            if ($raw === '') continue;
            $msg .= "--{$boundary}{$nl}";
            $msg .= "Content-Type: {$mime}; name=\"{$name}\"{$nl}";
            $msg .= "Content-Transfer-Encoding: base64{$nl}";
            $msg .= "Content-Disposition: attachment; filename=\"{$name}\"{$nl}{$nl}";
            $msg .= chunk_split(base64_encode($raw)) . $nl;
        }
        $msg .= "--{$boundary}--{$nl}";

        $ok = @mail($to, $subject, $msg, $headers);
        return [(bool)$ok, $ok ? '' : 'mail() returned false'];
    }

    /**
     * Open an SMTP connection and authenticate WITHOUT sending a message, to
     * verify the saved credentials. Returns [ok, message]. Captures PHPMailer's
     * debug transcript so the message names the real failure (DNS, TLS, auth)
     * rather than a generic "it didn't work". Uses the settings already saved
     * in the database — Save before verifying.
     *
     * @return array{0:bool, 1:string}
     */
    public static function verifyConnection(string $which = 'primary'): array {
        $cfg  = self::buildCfg($which === 'backup' ? 'backup_' : '');
        $host = (string)$cfg['host'];
        if (!$host) {
            return [false, ($which === 'backup'
                ? 'No backup SMTP host is configured.'
                : 'No SMTP host is configured. The PHP mail() fallback cannot be connection-tested — use Send test email instead.')];
        }
        // HTTPS-API drivers — verify by exercising the same auth path the real
        // send uses, but without dispatching a message. Refusing to fall back
        // to SMTP makes the failure message specific to the driver.
        if ($host === 'graph.microsoft.com') return self::verifyGraph();
        if ($host === 'api.resend.com')     return self::verifyResend($cfg);
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return [false, 'PHPMailer is not installed, so an SMTP connection cannot be opened.'];
        }

        $debug = '';
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host    = $host;
            $mail->Port    = (int)($cfg['port'] ?: 587);
            $mail->Timeout = 12; // don't hang the admin page on a dead host

            self::applyAuth($mail, $cfg);

            $enc = (string)$cfg['encryption'];
            if ($enc === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'tls' || $enc === 'starttls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure  = '';
                $mail->SMTPAutoTLS = false;
            }

            // Capture the server conversation so a failure can be explained.
            $mail->SMTPDebug   = 2; // SMTP::DEBUG_SERVER
            $mail->Debugoutput = function ($str, $level) use (&$debug) {
                $debug .= trim((string)$str) . "\n";
            };

            // smtpConnect() performs connect + STARTTLS + AUTH (when SMTPAuth),
            // which is exactly the handshake we want to validate.
            $connected = $mail->smtpConnect();
            $mail->smtpClose();

            if ($connected) {
                $authed = (bool)$mail->SMTPAuth;
                return [true, $authed
                    ? 'Connected and authenticated successfully.'
                    : 'Connected successfully (no authentication is configured).'];
            }
            return [false, self::summariseSmtpError((string)$mail->ErrorInfo, $debug)];
        } catch (\Throwable $e) {
            return [false, self::summariseSmtpError($e->getMessage(), $debug)];
        }
    }

    /**
     * Verify the Microsoft Graph driver: refresh the OAuth token and hit
     * GET /users/{mailbox} so a misconfigured scope, an unlicensed mailbox,
     * or a missing Mail.Send grant fails NOW rather than silently on the
     * first real send. Mirrors the WP Mail SMTP "Send Test" preflight.
     *
     * @return array{0:bool, 1:string}
     */
    private static function verifyGraph(): array {
        if (!class_exists('SmtpOAuth')) {
            return [false, 'Microsoft Graph driver requires SmtpOAuth helper.'];
        }
        $oauth = \SmtpOAuth::fromSettings();
        if (!$oauth) {
            return [false, 'Microsoft account is not connected. Click “Connect” in the Modern authentication section first.'];
        }
        try {
            $token = $oauth->bearerToken();
        } catch (\Throwable $e) {
            return [false, 'OAuth token refresh failed: ' . $e->getMessage()
                . ' — disconnect and reconnect the Microsoft account.'];
        }

        $url = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($oauth->email())
             . '?$select=id,mail,userPrincipalName';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return [false, 'Graph cURL error: ' . $cerr];
        if ($http >= 200 && $http < 300) {
            return [true, 'Connected to Microsoft Graph as ' . $oauth->email() . '. Token has Mail.Send. Try “Send test email” next.'];
        }
        $decoded = json_decode((string)$resp, true);
        $msg = is_array($decoded) ? ($decoded['error']['message'] ?? ($decoded['message'] ?? $resp)) : $resp;
        $hint = '';
        if ($http === 401) $hint = ' — token is invalid or scope is missing. Disconnect and reconnect to refresh consent.';
        elseif ($http === 403) $hint = ' — token lacks Mail.Send. Add the permission in Azure → Grant admin consent → reconnect.';
        elseif ($http === 404) $hint = ' — mailbox not found. Check that the connected email matches a real M365 user.';
        return [false, "Graph HTTP $http: $msg" . $hint];
    }

    /** Verify Resend by hitting the no-op /domains endpoint with the API key. */
    private static function verifyResend(array $cfg): array {
        $apiKey = (string)$cfg['pass'];
        if ($apiKey !== '' && function_exists('slate_decrypt_secret')) {
            $apiKey = slate_decrypt_secret($apiKey) ?? $apiKey;
        }
        if ($apiKey === '') return [false, 'Resend API key not configured — paste it into the SMTP "Password" field above.'];
        $ch = curl_init('https://api.resend.com/domains');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) return [false, 'Resend cURL error.'];
        if ($http >= 200 && $http < 300) return [true, 'Resend API key is valid. Try “Send test email” next.'];
        if ($http === 401) return [false, 'Resend rejected the API key (HTTP 401). Generate a new key at resend.com/api-keys.'];
        return [false, "Resend HTTP $http: $resp"];
    }

    /**
     * Turn a raw PHPMailer/SMTP error (plus debug transcript) into one concise,
     * actionable line. Falls back to the last transcript line when ErrorInfo is
     * empty, and appends a provider-aware hint for the common failure classes.
     */
    private static function summariseSmtpError(string $err, string $debug): string {
        $err = trim($err);
        if ($err === '' && $debug !== '') {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $debug))));
            $err = $lines ? (string)end($lines) : '';
        }
        if ($err === '') $err = __('core_err_unknown_smtp_error', 'Unknown SMTP error.');

        $low  = strtolower($err . ' ' . $debug);
        $hint = '';
        if (strpos($low, '535') !== false || strpos($low, '534') !== false
            || strpos($low, 'authenticat') !== false || strpos($low, 'username and password') !== false) {
            $hint = ' — authentication was rejected. For Gmail / Yahoo / iCloud use an App Password (not the account password); for Microsoft 365 make sure SMTP AUTH is enabled for the mailbox.';
        } elseif (strpos($low, 'getaddrinfo') !== false || strpos($low, 'could not connect') !== false
            || strpos($low, 'timed out') !== false || strpos($low, 'refused') !== false
            || strpos($low, 'network is unreachable') !== false) {
            $hint = ' — could not reach the server. Check the host and port, and that the firewall allows outbound SMTP.';
        } elseif (strpos($low, 'certificate') !== false || strpos($low, 'tls') !== false
            || strpos($low, 'ssl') !== false) {
            $hint = ' — TLS negotiation failed. Try the alternative encryption/port (587 STARTTLS vs 465 SSL/TLS).';
        }

        if (strlen($err) > 240) $err = substr($err, 0, 240) . '…';
        return $err . $hint;
    }
}
