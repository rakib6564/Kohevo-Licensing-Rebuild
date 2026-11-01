<?php
/**
 * Kohevo Studio — the Google calls Site Insights makes, read-only: Search Console, the Analytics Data and Admin APIs, and
 * PageSpeed Insights. Every failure comes back as a GoogleApiException with a reason.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Insights;

final class GoogleClient
{
    public function __construct(
        private readonly GoogleConnection $connection,
        private readonly GoogleTransport $transport,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** @return list<array{url: string, level: string}> the Search Console properties this account can read */
    public function searchSites(): array
    {
        $sites = [];
        foreach ($this->call('GET', GoogleEndpoints::SEARCH_CONSOLE . '/sites')['siteEntry'] ?? [] as $entry) {
            if (($entry['permissionLevel'] ?? '') !== 'siteUnverifiedUser') {
                $sites[] = ['url' => (string) ($entry['siteUrl'] ?? ''), 'level' => (string) ($entry['permissionLevel'] ?? '')];
            }
        }
        return $sites;
    }

    /** @return list<array{property: string, name: string, account: string}> */
    public function analyticsProperties(): array
    {
        $list = [];
        $next = '';
        do {
            $answer = $this->call('GET', GoogleEndpoints::ANALYTICS_ADMIN . '/accountSummaries?' . http_build_query(['pageSize' => 200, 'pageToken' => $next]));
            foreach ($answer['accountSummaries'] ?? [] as $account) {
                foreach ($account['propertySummaries'] ?? [] as $property) {
                    $list[] = ['property' => (string) ($property['property'] ?? ''), 'name' => (string) ($property['displayName'] ?? ''), 'account' => (string) ($account['displayName'] ?? '')];
                }
            }
            $next = (string) ($answer['nextPageToken'] ?? '');
        } while ($next !== '');
        return $list;
    }

    /** The G- id of the property's web stream, or '' when it has none. */
    public function measurementId(string $property): string
    {
        foreach ($this->webStreams($property) as $stream) {
            if ($stream['id'] !== '') {
                return $stream['id'];
            }
        }
        return '';
    }

    /** @return list<array{id: string, uri: string}> */
    public function webStreams(string $property): array
    {
        $streams = [];
        foreach ($this->call('GET', GoogleEndpoints::ANALYTICS_ADMIN . '/' . $property . '/dataStreams')['dataStreams'] ?? [] as $stream) {
            $streams[] = ['id' => (string) ($stream['webStreamData']['measurementId'] ?? ''), 'uri' => (string) ($stream['webStreamData']['defaultUri'] ?? '')];
        }
        return $streams;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function searchAnalytics(string $site, array $query): array
    {
        return $this->call('POST', GoogleEndpoints::SEARCH_CONSOLE . '/sites/' . rawurlencode($site) . '/searchAnalytics/query', $query);
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function analyticsReport(string $property, array $report): array
    {
        return $this->call('POST', GoogleEndpoints::ANALYTICS_DATA . '/' . $property . ':runReport', $report);
    }

    /**
     * PageSpeed runs a real page load, so it gets a long timeout. It takes a site's own API key; a site signed in through
     * Kohevo Central has none, so the sign-in identifies it, which spends the Central project's PageSpeed quota.
     *
     * @return array<string, mixed>
     */
    public function pageSpeed(string $url, string $strategy, string $apiKey): array
    {
        $query = ['url' => $url, 'strategy' => $strategy, 'category' => 'performance'] + ($apiKey !== '' ? ['key' => $apiKey] : []);
        $signIn = $apiKey === '' && $this->connection->usesCentral();
        $headers = $signIn ? ['Authorization: Bearer ' . $this->connection->accessToken($this->clock !== null ? (int) ($this->clock)() : time())] : [];
        return $this->decode($this->transport->send('GET', GoogleEndpoints::url(GoogleEndpoints::PAGESPEED) . '?' . http_build_query($query), $headers, null, 70), 'pagespeed');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $url, ?array $body = null): array
    {
        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        $send = function (string $token) use ($method, $url, $body): array {
            $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
            return $this->transport->send($method, GoogleEndpoints::url($url), $headers, $body === null ? null : (string) json_encode($body));
        };
        $answer = $send($this->connection->accessToken($now));
        if ($answer['status'] === 401) {
            $answer = $send($this->connection->accessToken($now, true));
        }
        return $this->decode($answer, (string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * @param array{status: int, body: string} $answer
     * @return array<string, mixed>
     */
    private function decode(array $answer, string $service): array
    {
        $body = json_decode($answer['body'], true);
        $status = $answer['status'];
        if ($status >= 200 && $status < 300) {
            if (is_array($body)) {
                return $body;
            }
            throw new GoogleApiException(GoogleApiException::UNAVAILABLE, $service . ': unreadable answer');
        }
        $reason = self::reasonFor($status, is_array($body) ? $body : []);
        throw new GoogleApiException($reason, $service . ': HTTP ' . $status . ' ' . $reason);
    }

    /** @param array<string, mixed> $body */
    public static function reasonFor(int $status, array $body): string
    {
        $words = (string) json_encode([$body['error']['status'] ?? '', $body['error']['errors'] ?? [], $body['error']['details'] ?? []]);
        return match (true) {
            $status === 401 => GoogleApiException::RECONNECT,
            $status === 429, str_contains($words, 'RESOURCE_EXHAUSTED'), stripos($words, 'rateLimit') !== false, stripos($words, 'quota') !== false => GoogleApiException::QUOTA,
            str_contains($words, 'SERVICE_DISABLED'), str_contains($words, 'accessNotConfigured') => GoogleApiException::NOT_ENABLED,
            $status === 403 => GoogleApiException::FORBIDDEN,
            $status === 404 => GoogleApiException::NOT_FOUND,
            default => GoogleApiException::UNAVAILABLE,
        };
    }
}
