<?php
/**
 * Slate — SMTP OAuth 2.0 (XOAUTH2) helper.
 *
 * Lets the Mailer authenticate to Gmail / Microsoft 365 with OAuth 2.0
 * instead of a password / app-password. This matters because Microsoft is
 * retiring basic auth, and OAuth avoids storing a reusable password.
 *
 * Self-contained: token refresh is a plain curl call to the provider's token
 * endpoint, so it needs NO composer packages (PHPMailer's bundled OAuth class
 * depends on league/oauth2-client, which is not installed here).
 *
 * As an instance it implements PHPMailer's OAuthTokenProvider so it can be
 * handed to `$mail->setOAuth(...)`. As a set of statics it drives the admin
 * connect flow (authorize URL, code exchange).
 *
 * Settings keys used (all in the `settings` table):
 *   smtp_auth_type            'password' | 'xoauth2'
 *   smtp_oauth_provider       'google' | 'microsoft'
 *   smtp_oauth_client_id
 *   smtp_oauth_client_secret  (encrypted)
 *   smtp_oauth_refresh_token  (encrypted)
 *   smtp_oauth_email          mailbox / SMTP "user="
 *   smtp_oauth_access_token   cached short-lived token (encrypted)
 *   smtp_oauth_expires        unix ts the cached token expires
 */

if (interface_exists('PHPMailer\\PHPMailer\\OAuthTokenProvider')) {
    class SmtpOAuth implements \PHPMailer\PHPMailer\OAuthTokenProvider {
        private string $provider;
        private string $email;
        private string $clientId;
        private string $clientSecret;
        private string $refreshToken;

        public function __construct(string $provider, string $email, string $clientId, string $clientSecret, string $refreshToken) {
            $this->provider     = $provider;
            $this->email        = $email;
            $this->clientId     = $clientId;
            $this->clientSecret = $clientSecret;
            $this->refreshToken = $refreshToken;
        }

        /** Build the SmtpOAuth from saved settings, or null if not connected. */
        public static function fromSettings(): ?self {
            if (Database::setting('smtp_auth_type') !== 'xoauth2') return null;
            $provider = (string)Database::setting('smtp_oauth_provider');
            $email    = (string)Database::setting('smtp_oauth_email');
            $cid      = (string)Database::setting('smtp_oauth_client_id');
            $secEnc   = (string)Database::setting('smtp_oauth_client_secret');
            $rtEnc    = (string)Database::setting('smtp_oauth_refresh_token');
            if ($provider === '' || $email === '' || $cid === '' || $rtEnc === '') return null;
            $dec = function (string $v): string {
                return function_exists('slate_decrypt_secret') ? (string)(slate_decrypt_secret($v) ?? $v) : $v;
            };
            return new self($provider, $email, $cid, $dec($secEnc), $dec($rtEnc));
        }

        /* ── PHPMailer OAuthTokenProvider ──────────────────────── */

        /** XOAUTH2 SASL string: base64("user=<email>^Aauth=Bearer <token>^A^A"). */
        public function getOauth64(): string {
            $token = $this->accessToken();
            return base64_encode('user=' . $this->email . "\001auth=Bearer " . $token . "\001\001");
        }

        /** Raw bearer token for non-SMTP transports (e.g. Microsoft Graph API). */
        public function bearerToken(): string { return $this->accessToken(); }

        /** Account email the OAuth token was issued for (the From address). */
        public function email(): string { return $this->email; }

        /**
         * Return a valid access token, using the cached one until ~60s before
         * expiry, otherwise refreshing and persisting the new token (and the
         * rotated refresh token, which Microsoft returns on every refresh).
         */
        private function accessToken(): string {
            $cachedRaw = (string)Database::setting('smtp_oauth_access_token');
            $cached    = function_exists('slate_decrypt_secret') ? (string)(slate_decrypt_secret($cachedRaw) ?? $cachedRaw) : $cachedRaw;
            $expires   = (int)Database::setting('smtp_oauth_expires');
            if ($cached !== '' && $expires > time() + 60) {
                return $cached;
            }
            [$ok, $data] = self::refresh($this->provider, $this->clientId, $this->clientSecret, $this->refreshToken);
            if (!$ok) {
                // Surface as a PHPMailer-catchable failure; an empty token makes
                // AUTH fail with a clear server message rather than a silent send.
                throw new \RuntimeException('OAuth token refresh failed: ' . (string)($data['error'] ?? 'unknown'));
            }
            $access = (string)($data['access_token'] ?? '');
            $ttl    = (int)($data['expires_in'] ?? 3600);
            Database::setSetting('smtp_oauth_access_token', function_exists('slate_encrypt_secret') ? slate_encrypt_secret($access) : $access);
            Database::setSetting('smtp_oauth_expires', (string)(time() + $ttl));
            // Microsoft rotates refresh tokens; persist the new one if returned.
            if (!empty($data['refresh_token']) && function_exists('slate_encrypt_secret')) {
                Database::setSetting('smtp_oauth_refresh_token', slate_encrypt_secret((string)$data['refresh_token']));
                $this->refreshToken = (string)$data['refresh_token'];
            }
            return $access;
        }

        /* ── Provider config ───────────────────────────────────── */

        /** @return array<string,array<string,mixed>> */
        public static function providers(): array {
            return [
                'google' => [
                    'label'     => 'Google (Gmail / Workspace)',
                    'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
                    'token'     => 'https://oauth2.googleapis.com/token',
                    'scope'     => 'https://mail.google.com/',
                    'authextra' => ['access_type' => 'offline', 'prompt' => 'consent'],
                    'smtp_host' => 'smtp.gmail.com',
                    'smtp_port' => 587,
                    'smtp_enc'  => 'tls',
                ],
                'microsoft' => [
                    // Scope requests Microsoft Graph (Mail.Send + User.Read) so the
                    // token can drive both the Graph sendMail endpoint AND read the
                    // user profile for the connect confirmation. offline_access is
                    // required to receive a refresh token. We deliberately do NOT
                    // request the outlook.office.com SMTP.Send scope — mixing two
                    // resources in one v2.0 consent fails with AADSTS28000, and
                    // Microsoft has deprecated SMTP AUTH anyway. Mailer.php detects
                    // host="graph.microsoft.com" and routes sends through Graph.
                    'label'     => 'Microsoft 365 / Outlook',
                    'authorize' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                    'token'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                    'scope'     => 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read',
                    'authextra' => ['prompt' => 'consent'],
                    // Graph driver delivers over HTTPS:443 instead of SMTP, so the
                    // host the callback writes back into the settings table is the
                    // Graph sentinel host. Port/enc kept for the rare case someone
                    // wants to switch to the SMTP path manually after connecting.
                    'smtp_host' => 'graph.microsoft.com',
                    'smtp_port' => 443,
                    'smtp_enc'  => 'ssl',
                ],
            ];
        }

        public static function config(string $provider): ?array {
            return self::providers()[$provider] ?? null;
        }

        /** Build the provider authorize URL to redirect the admin to. */
        public static function authorizeUrl(string $provider, string $clientId, string $redirectUri, string $state): string {
            $c = self::config($provider);
            if (!$c) return '';
            $params = array_merge([
                'client_id'     => $clientId,
                'redirect_uri'  => $redirectUri,
                'response_type' => 'code',
                'scope'         => $c['scope'],
                'state'         => $state,
            ], $c['authextra']);
            return $c['authorize'] . '?' . http_build_query($params);
        }

        /**
         * Exchange an authorization code for tokens.
         * @return array{0:bool,1:array<string,mixed>}
         */
        public static function exchangeCode(string $provider, string $clientId, string $clientSecret, string $code, string $redirectUri): array {
            $c = self::config($provider);
            if (!$c) return [false, ['error' => 'unknown provider']];
            return self::tokenRequest($c['token'], [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
                'scope'         => $c['scope'],
            ]);
        }

        /** @return array{0:bool,1:array<string,mixed>} */
        public static function refresh(string $provider, string $clientId, string $clientSecret, string $refreshToken): array {
            $c = self::config($provider);
            if (!$c) return [false, ['error' => 'unknown provider']];
            return self::tokenRequest($c['token'], [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
                'scope'         => $c['scope'],
            ]);
        }

        /**
         * POST application/x-www-form-urlencoded to a token endpoint.
         * @return array{0:bool,1:array<string,mixed>} [ok, decodedJson|errorInfo]
         */
        private static function tokenRequest(string $url, array $params): array {
            if (!function_exists('curl_init')) {
                return [false, ['error' => 'curl extension not available']];
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                return [false, ['error' => 'network error: ' . $cerr]];
            }
            $json = json_decode((string)$body, true);
            if (!is_array($json)) {
                return [false, ['error' => 'invalid token response (HTTP ' . $code . ')']];
            }
            if ($code >= 200 && $code < 300 && !empty($json['access_token'])) {
                return [true, $json];
            }
            // OAuth error payload: {error, error_description}
            $msg = (string)($json['error_description'] ?? $json['error'] ?? ('HTTP ' . $code));
            return [false, ['error' => $msg]];
        }
    }
}
