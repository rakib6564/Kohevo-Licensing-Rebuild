<?php
/**
 * Kohevo Studio — one site's connection to Google: its own OAuth client, the grant, and the access token.
 *
 * Normally a site signs in through Kohevo Central, which holds one OAuth client for every licensed site, so nobody sets up
 * Google Cloud (CentralGoogleBroker, docs/STUDIO-SITE-INSIGHTS.md). A self-hosted site can use its own client instead.
 * The client secret, the PageSpeed key and both tokens are encrypted at rest with APP_SECRET, and they are never rendered:
 * the screen only says whether one is stored.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

final class GoogleConnection
{
    public const CLIENT_ID = 'studio_google_client_id';
    public const CLIENT_SECRET = 'studio_google_client_secret';
    public const API_KEY = 'studio_google_api_key';
    public const REFRESH_TOKEN = 'studio_google_refresh_token';
    public const ACCESS_TOKEN = 'studio_google_access_token';
    public const EXPIRES_AT = 'studio_google_expires_at';
    public const EMAIL = 'studio_google_email';
    public const SCOPES = 'studio_google_scopes';
    public const MODE = 'studio_google_mode';
    public const NEEDS_RECONNECT = 'studio_google_needs_reconnect';
    public const SEARCH_SITE = 'studio_google_search_site';
    public const ANALYTICS_PROPERTY = 'studio_google_analytics_property';
    public const ANALYTICS_NAME = 'studio_google_analytics_name';
    public const MEASUREMENT_ID = 'studio_google_measurement_id';

    /** Every setting that belongs to the connection. */
    public const GRANT_KEYS = [self::REFRESH_TOKEN, self::ACCESS_TOKEN, self::EXPIRES_AT, self::EMAIL, self::SCOPES, self::NEEDS_RECONNECT, self::MODE];
    public const CHOICE_KEYS = [self::SEARCH_SITE, self::ANALYTICS_PROPERTY, self::ANALYTICS_NAME, self::MEASUREMENT_ID];

    public const MODE_CENTRAL = 'central';
    public const MODE_OWN = 'own';

    public const SCOPE_SEARCH = 'https://www.googleapis.com/auth/webmasters.readonly';
    public const SCOPE_ANALYTICS = 'https://www.googleapis.com/auth/analytics.readonly';
    private const SCOPE_LIST = ['openid', 'email', self::SCOPE_SEARCH, self::SCOPE_ANALYTICS];

    public function __construct(
        private readonly InsightsStore $store,
        private readonly GoogleTransport $transport,
        private readonly string $redirectUri,
        private readonly ?CentralGoogleBroker $broker = null,
    ) {
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /** Whether this site can sign in through Kohevo Central: it has a licence to show. */
    public function canUseCentral(): bool
    {
        return $this->broker !== null;
    }

    public function usesCentral(): bool
    {
        return $this->store->get(self::MODE) === self::MODE_CENTRAL;
    }

    /** Whether this site has its own OAuth client (Advanced). */
    public function isConfigured(): bool
    {
        return $this->store->get(self::CLIENT_ID) !== '' && $this->store->get(self::CLIENT_SECRET) !== '';
    }

    public function isConnected(): bool
    {
        return $this->store->get(self::REFRESH_TOKEN) !== '';
    }

    public function needsReconnect(): bool
    {
        return $this->store->get(self::NEEDS_RECONNECT) === '1';
    }

    public function email(): string
    {
        return $this->store->get(self::EMAIL);
    }

    public function hasApiKey(): bool
    {
        return $this->store->get(self::API_KEY) !== '';
    }

    public function apiKey(): string
    {
        return $this->open(self::API_KEY);
    }

    /** Whether the grant includes a scope: Google's consent screen lets a person untick one. */
    public function granted(string $scope): bool
    {
        return in_array($scope, explode(' ', $this->store->get(self::SCOPES)), true);
    }

    /** The client from the settings form. A blank secret or key keeps the stored one. */
    public function saveClient(string $clientId, string $clientSecret, string $apiKey): void
    {
        foreach ([self::CLIENT_SECRET => $clientSecret, self::API_KEY => $apiKey] as $key => $plain) {
            if (trim($plain) !== '') {
                $this->store->set($key, $this->seal(trim($plain)));
            }
        }
        $this->store->set(self::CLIENT_ID, trim($clientId));
    }

    /** A random `state` and PKCE verifier for one sign-in. The caller keeps them in the session. */
    public static function newAttempt(): array
    {
        return ['state' => bin2hex(random_bytes(24)), 'verifier' => rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '='), 'at' => time()];
    }

    /** @param array{state: string, verifier: string} $attempt */
    public function authUrl(array $attempt): string
    {
        return GoogleEndpoints::url(GoogleEndpoints::AUTH) . '?' . http_build_query([
            'client_id' => $this->store->get(self::CLIENT_ID),
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPE_LIST),
            'access_type' => 'offline',
            'prompt' => 'consent', // a refresh token even when this account connected before
            'state' => $attempt['state'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $attempt['verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Google's consent address, by way of Kohevo Central. */
    public function centralAuthUrl(string $state): string
    {
        if ($this->broker === null) {
            throw new GoogleApiException(GoogleApiException::CENTRAL, 'no licence to sign in with');
        }
        return $this->broker->start($this->redirectUri, $state);
    }

    /** The answer to a sign-in: right `state`, not stale, and each attempt used once by the caller. */
    public static function attemptMatches(mixed $attempt, string $state, int $now): bool
    {
        return is_array($attempt) && is_string($attempt['state'] ?? null) && is_string($attempt['verifier'] ?? null)
            && $state !== '' && hash_equals($attempt['state'], $state) && $now - (int) ($attempt['at'] ?? 0) <= 900;
    }

    /** Trade the callback's code for the grant and store it, sealed. */
    public function connect(string $code, string $verifier): void
    {
        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $verifier,
        ]);
        $this->finishConnecting($tokens, self::MODE_OWN);
    }

    /** Collect the tokens Kohevo Central holds for a moment after a sign-in, and store them, sealed. */
    public function connectWithHandoff(string $handoff): void
    {
        if ($this->broker === null) {
            throw new GoogleApiException(GoogleApiException::CENTRAL, 'no licence to sign in with');
        }
        $this->finishConnecting($this->broker->claim($handoff), self::MODE_CENTRAL);
    }

    /** @param array<string, mixed> $tokens */
    private function finishConnecting(array $tokens, string $mode): void
    {
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        if ($refresh === '' || !is_string($tokens['access_token'] ?? null)) {
            throw new GoogleApiException(GoogleApiException::RECONNECT, 'no refresh token in the answer');
        }
        $this->storeAccess($tokens);
        $this->store->set(self::REFRESH_TOKEN, $this->seal($refresh));
        $this->store->set(self::SCOPES, (string) ($tokens['scope'] ?? ''));
        $this->store->set(self::NEEDS_RECONNECT, '');
        $this->store->set(self::MODE, $mode);
        $who = $this->transport->send('GET', GoogleEndpoints::url(GoogleEndpoints::USERINFO), ['Authorization: Bearer ' . (string) $tokens['access_token']]);
        $this->store->set(self::EMAIL, (string) (json_decode($who['body'], true)['email'] ?? ''));
    }

    /** A live access token, refreshed when it is about to end. `$force` is for a token Google has just refused. */
    public function accessToken(int $now, bool $force = false): string
    {
        if (!$this->isConnected()) {
            throw new GoogleApiException(GoogleApiException::RECONNECT, 'not connected');
        }
        if (!$force && (int) $this->store->get(self::EXPIRES_AT) > $now + 60) {
            $token = $this->open(self::ACCESS_TOKEN);
            if ($token !== '') {
                return $token;
            }
        }
        try {
            $tokens = $this->usesCentral() ? $this->centralRefresh() : $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->open(self::REFRESH_TOKEN),
            ]);
        } catch (GoogleApiException $e) {
            if ($e->reason === GoogleApiException::RECONNECT) {
                $this->store->set(self::NEEDS_RECONNECT, '1');
            }
            throw $e;
        }
        $this->storeAccess($tokens, $now);
        $this->store->set(self::NEEDS_RECONNECT, '');
        return (string) $tokens['access_token'];
    }

    /** @return array<string, mixed> */
    private function centralRefresh(): array
    {
        if ($this->broker === null) {
            throw new GoogleApiException(GoogleApiException::RECONNECT, 'signed in through Central, but no licence now');
        }
        return $this->broker->refresh($this->open(self::REFRESH_TOKEN));
    }

    /** Forget the grant here and, as far as Google answers, there. The client stays, so connecting again takes one click. */
    public function disconnect(): void
    {
        $refresh = $this->open(self::REFRESH_TOKEN);
        if ($refresh !== '') {
            try {
                $this->transport->send('POST', GoogleEndpoints::url(GoogleEndpoints::REVOKE), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $refresh]));
            } catch (GoogleApiException) {
                // The local copy goes either way; Google's side can be removed from the Google account.
            }
        }
        foreach ([...self::GRANT_KEYS, ...self::CHOICE_KEYS] as $key) {
            $this->store->set($key, '');
        }
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function tokenRequest(array $fields): array
    {
        $fields += ['client_id' => $this->store->get(self::CLIENT_ID), 'client_secret' => $this->open(self::CLIENT_SECRET)];
        $answer = $this->transport->send('POST', GoogleEndpoints::url(GoogleEndpoints::TOKEN), ['Content-Type: application/x-www-form-urlencoded'], http_build_query($fields));
        $body = json_decode($answer['body'], true);
        if ($answer['status'] === 200 && is_string($body['access_token'] ?? null) && $body['access_token'] !== '') {
            return $body;
        }
        $error = is_string($body['error'] ?? null) ? preg_replace('/[^a-z_]/', '', $body['error']) : 'unknown';
        // invalid_grant: revoked, expired (7 days while the consent screen is in Testing) or a wrong code.
        // invalid_client: the Client ID or secret on this site is wrong. Both need the admin, not a retry.
        $reason = in_array($error, ['invalid_grant', 'invalid_client', 'unauthorized_client'], true) ? GoogleApiException::RECONNECT : GoogleApiException::UNAVAILABLE;
        throw new GoogleApiException($reason, 'token request: ' . $answer['status'] . ' ' . $error);
    }

    /** @param array<string, mixed> $tokens */
    private function storeAccess(array $tokens, ?int $now = null): void
    {
        $this->store->set(self::ACCESS_TOKEN, $this->seal((string) $tokens['access_token']));
        $this->store->set(self::EXPIRES_AT, (string) (($now ?? time()) + (int) ($tokens['expires_in'] ?? 3600)));
    }

    private function seal(string $plain): string
    {
        try {
            return slate_encrypt_secret($plain);
        } catch (\Throwable) {
            throw new GoogleApiException(GoogleApiException::NOT_SECURE, 'APP_SECRET is not configured');
        }
    }

    private function open(string $key): string
    {
        $sealed = $this->store->get($key);
        return $sealed === '' ? '' : (string) slate_decrypt_secret($sealed);
    }
}
