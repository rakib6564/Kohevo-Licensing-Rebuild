<?php
/**
 * Kohevo Studio — signing in through Kohevo Central, which holds the one Google OAuth client (02-licensing GoogleBroker).
 * Each call carries the site's licence, as a licence check-in does. The site never sees the client secret.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

final class CentralGoogleBroker
{
    /** @param array{server_url: string, product: string, license_key: string, install_id: string, domain: string} $site */
    public function __construct(private readonly array $site, private readonly GoogleTransport $transport)
    {
    }

    /** Google's consent address for one sign-in that comes back to `$returnUrl`. */
    public function start(string $returnUrl, string $state): string
    {
        $url = (string) ($this->call('start', ['return_url' => $returnUrl, 'site_state' => $state])['auth_url'] ?? '');
        if ($url === '') {
            throw new GoogleApiException(GoogleApiException::CENTRAL, 'central: no consent address');
        }
        return $url;
    }

    /** @return array<string, mixed> the tokens behind a handoff; a handoff works once */
    public function claim(string $handoff): array
    {
        return $this->call('claim', ['handoff' => $handoff]);
    }

    /** @return array<string, mixed> a new access token */
    public function refresh(string $refreshToken): array
    {
        return $this->call('refresh', ['refresh_token' => $refreshToken]);
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function call(string $action, array $fields): array
    {
        $body = (string) json_encode(['product' => $this->site['product'], 'license_key' => $this->site['license_key'], 'install_id' => $this->site['install_id'], 'domain' => $this->site['domain']] + $fields);
        $answer = $this->transport->send('POST', rtrim($this->site['server_url'], '/') . '/licensing/google/' . $action, ['Content-Type: application/json'], $body);
        $data = json_decode($answer['body'], true);
        if ($answer['status'] === 200 && is_array($data)) {
            return $data;
        }
        $error = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'unknown';
        $reason = match (true) {
            $error === 'invalid_grant' => GoogleApiException::RECONNECT,
            $answer['status'] >= 500 && $error !== 'not_configured' => GoogleApiException::UNAVAILABLE,
            default => GoogleApiException::CENTRAL,
        };
        throw new GoogleApiException($reason, 'central ' . $action . ': HTTP ' . $answer['status'] . ' ' . preg_replace('/[^a-z_]/', '', $error));
    }
}
