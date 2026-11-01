<?php
/**
 * Licensing plugin — one Google sign-in for every licensed Kohevo site.
 *
 * Google gives a web client one secret, which cannot ship inside every site. So Central holds a single OAuth client and a
 * site signs in through it (Site Insights in Kohevo Studio):
 *
 *   start     site → Central, server to server, with its licence: Central answers with Google's consent address
 *   callback  Google → Central, in the browser: Central trades the code and sends the browser back with a one-time handoff
 *   claim     site → Central, with its licence: the handoff becomes the tokens, once
 *   refresh   site → Central, with its licence: a new access token for the refresh token the site keeps
 *
 * Tokens never travel through the browser and Central keeps none of them: a handoff lasts two minutes, sealed, and goes
 * when it is claimed or expires. Refresh needs a live licence, because only Central can use the secret.
 *
 * No Database, no HTTP framework: public/google.php wires it to the licence tables and curl, so this runs in tests as is.
 */

declare(strict_types=1);

final class GoogleBroker
{
    public const STATE_TTL = 600;
    public const HANDOFF_TTL = 120;
    /** Sites return here. Central refuses any other page, whatever the host. */
    public const RETURN_PATH = '/plugins/studio-builder/admin/insights-callback.php';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPES = 'openid email https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly';

    /**
     * @param string   $callbackUrl this Central's /licensing/google/callback, as listed on the OAuth client
     * @param callable(string, string): ?string     $sign  context, value → signature (slate_sign)
     * @param callable(string, array<string, string>): array{status: int, body: string} $post  form POST to Google
     * @param callable(string, string, array<string, mixed>, int): void $put   handoff hash, installation id, tokens, expiry
     * @param callable(string, string, int): ?array<string, mixed>      $take  handoff hash, installation id, now: once only
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $callbackUrl,
        private $sign,
        private $post,
        private $put,
        private $take,
        private readonly string $authUrl = self::AUTH_URL,
        private readonly string $tokenUrl = self::TOKEN_URL,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * The return address must be the Insights callback on the installation's own licensed domain. Paths may differ, so a site
     * in a subfolder works; http is for local development only.
     */
    public static function returnUrlAllowed(string $url, string $domain): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $path = (string) ($parts['path'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        return ($scheme === 'https' || ($scheme === 'http' && $local))
            && $host !== '' && hash_equals($domain, $host . $port)
            && str_ends_with($path, self::RETURN_PATH) && !str_contains($path, '..') && !str_contains($path, '//');
    }

    /**
     * @param array{installation_id: string, domain: string} $site a licence check has passed for it
     * @return array{status: int, body: array<string, string>}
     */
    public function start(array $site, string $returnUrl, string $siteState, int $now): array
    {
        if (!$this->isConfigured()) {
            return self::error(503, 'not_configured');
        }
        if (preg_match('/^[a-f0-9]{32,96}$/', $siteState) !== 1 || !self::returnUrlAllowed($returnUrl, $site['domain'])) {
            return self::error(403, 'invalid_return');
        }
        $nonce = bin2hex(random_bytes(16));
        $state = $this->sealState(['i' => $site['installation_id'], 'd' => $site['domain'], 'r' => $returnUrl, 's' => $siteState, 'e' => $now + self::STATE_TTL, 'n' => $nonce]);
        if ($state === null) {
            return self::error(503, 'not_configured');
        }
        return ['status' => 200, 'body' => ['auth_url' => $this->authUrl . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->callbackUrl,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent', // a refresh token even when this account connected before
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier($nonce), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ])]];
    }

    /**
     * Google's answer, in the browser. A state this Central did not sign, or one that is too old, sends nobody anywhere: its
     * return address cannot be trusted.
     *
     * @param array<string, mixed> $query
     * @return array{status: int, redirect?: string, message?: string}
     */
    public function callback(array $query, int $now): array
    {
        $state = $this->openState(is_string($query['state'] ?? null) ? $query['state'] : '', $now);
        if ($state === null) {
            return ['status' => 400, 'message' => 'This sign-in link is not valid or has expired. Go back to your site and try again.'];
        }
        $back = static fn(array $params): string => $state['r'] . '?' . http_build_query($params + ['state' => $state['s']]);
        if (!is_string($query['code'] ?? null) || $query['code'] === '') {
            return ['status' => 303, 'redirect' => $back(['error' => 'access_denied'])];
        }
        $answer = ($this->post)($this->tokenUrl, [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->callbackUrl,
            'code_verifier' => $this->verifier((string) $state['n']),
        ]);
        $tokens = json_decode($answer['body'], true);
        if ($answer['status'] !== 200 || !is_string($tokens['access_token'] ?? null) || !is_string($tokens['refresh_token'] ?? null)) {
            return ['status' => 303, 'redirect' => $back(['error' => 'exchange_failed'])];
        }
        $handoff = bin2hex(random_bytes(32));
        ($this->put)(hash('sha256', $handoff), (string) $state['i'], [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => (int) ($tokens['expires_in'] ?? 3600),
            'scope' => (string) ($tokens['scope'] ?? ''),
        ], $now + self::HANDOFF_TTL);
        return ['status' => 303, 'redirect' => $back(['handoff' => $handoff])];
    }

    /**
     * @param array{installation_id: string, domain: string} $site
     * @return array{status: int, body: array<string, mixed>}
     */
    public function claim(array $site, string $handoff, int $now): array
    {
        $tokens = preg_match('/^[a-f0-9]{64}$/', $handoff) === 1 ? ($this->take)(hash('sha256', $handoff), $site['installation_id'], $now) : null;
        return $tokens === null ? self::error(404, 'invalid_handoff') : ['status' => 200, 'body' => $tokens];
    }

    /**
     * @param array{installation_id: string, domain: string} $site
     * @return array{status: int, body: array<string, mixed>}
     */
    public function refresh(array $site, string $refreshToken): array
    {
        if (!$this->isConfigured()) {
            return self::error(503, 'not_configured');
        }
        if ($refreshToken === '') {
            return self::error(400, 'invalid_request');
        }
        $answer = ($this->post)($this->tokenUrl, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        $tokens = json_decode($answer['body'], true);
        if ($answer['status'] === 200 && is_string($tokens['access_token'] ?? null)) {
            return ['status' => 200, 'body' => ['access_token' => $tokens['access_token'], 'expires_in' => (int) ($tokens['expires_in'] ?? 3600)]];
        }
        $error = is_string($tokens['error'] ?? null) ? $tokens['error'] : '';
        return $error === 'invalid_grant' ? self::error(400, 'invalid_grant') : self::error(502, 'google_unavailable');
    }

    /** The PKCE verifier for one sign-in: derived, so Central stores nothing while the person is at Google. */
    private function verifier(string $nonce): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', (string) ($this->sign)('google_pkce', $nonce), true)), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $state */
    private function sealState(array $state): ?string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode($state, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $signature = ($this->sign)('google_state', $body);
        return $signature === null ? null : $body . '.' . $signature;
    }

    /** @return array<string, mixed>|null */
    private function openState(string $state, int $now): ?array
    {
        [$body, $signature] = array_pad(explode('.', $state, 2), 2, '');
        $expected = ($this->sign)('google_state', $body);
        if ($body === '' || $expected === null || !hash_equals($expected, $signature)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);
        if (!is_array($data) || (int) ($data['e'] ?? 0) < $now || !is_string($data['d'] ?? null) || !is_string($data['r'] ?? null) || !is_string($data['s'] ?? null) || !self::returnUrlAllowed($data['r'], $data['d'])) {
            return null;
        }
        return $data;
    }

    /** @return array{status: int, body: array{error: string}} */
    private static function error(int $status, string $code): array
    {
        return ['status' => $status, 'body' => ['error' => $code]];
    }
}
