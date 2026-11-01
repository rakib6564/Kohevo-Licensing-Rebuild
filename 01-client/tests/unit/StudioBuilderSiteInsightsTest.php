<?php
/**
 * Kohevo Studio: Site Insights. The Google connection, its secrets, and reports read from Google-shaped answers.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Backup\StudioBackupFormat;
use Slate\Module\StudioBuilder\Infrastructure\StudioSchemaManager;
use Slate\Module\StudioBuilder\Insights\GoogleApiException;
use Slate\Module\StudioBuilder\Insights\GoogleClient;
use Slate\Module\StudioBuilder\Insights\GoogleConnection;
use Slate\Module\StudioBuilder\Insights\GoogleEndpoints;
use Slate\Module\StudioBuilder\Insights\GoogleTransport;
use Slate\Module\StudioBuilder\Insights\InsightsCache;
use Slate\Module\StudioBuilder\Insights\InsightsRange;
use Slate\Module\StudioBuilder\Insights\InsightsReports;
use Slate\Module\StudioBuilder\Insights\InsightsStore;

if (!defined('APP_SECRET')) {
    define('APP_SECRET', 'site-insights-test-secret');
}
require_once SLATE_ROOT . '/includes/helpers.php';

final class SiArrayStore implements InsightsStore
{
    /** @var array<string, string> */
    public array $data = [];

    public function get(string $key): string
    {
        return $this->data[$key] ?? '';
    }

    public function set(string $key, string $value): void
    {
        $this->data[$key] = $value;
    }
}

final class SiArrayCache implements InsightsCache
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function get(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }

    public function set(string $key, array $value, int $ttl): void
    {
        $this->rows[$key] = $value;
    }

    public function clear(): void
    {
        $this->rows = [];
    }
}

/** Answers from a list in order, and remembers what was asked. */
final class SiTransport implements GoogleTransport
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    public array $sent = [];

    /** @param list<array{0: int, 1: array<string, mixed>}> $answers */
    public function __construct(public array $answers)
    {
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $this->sent[] = compact('method', 'url', 'headers', 'body');
        [$status, $answer] = array_shift($this->answers) ?? [500, []];
        return ['status' => $status, 'body' => (string) json_encode($answer)];
    }
}

$siConnected = static function (SiTransport $transport, ?SiArrayStore $store = null): GoogleConnection {
    $store = $store ?? new SiArrayStore();
    $connection = new GoogleConnection($store, $transport, 'https://site.test/cb');
    $connection->saveClient(' id.apps.googleusercontent.com ', 'the-secret', '');
    $store->set(GoogleConnection::REFRESH_TOKEN, slate_encrypt_secret('the-refresh'));
    $store->set(GoogleConnection::ACCESS_TOKEN, slate_encrypt_secret('the-access'));
    $store->set(GoogleConnection::EXPIRES_AT, (string) (time() + 3000));
    return $connection;
};
$siReports = static function (array $answers, string $path = '') use ($siConnected): array {
    $transport = new SiTransport($answers);
    $reports = new InsightsReports(new GoogleClient($siConnected($transport), $transport), new SiArrayCache(), 'sc-domain:site.test', 'properties/1', InsightsRange::of(7, new DateTimeImmutable('2026-11-10')), $path);
    return [$reports, $transport];
};

unit('site insights: the client secret, API key and tokens are never stored in plain text', function (): void {
    $store = new SiArrayStore();
    $transport = new SiTransport([[200, ['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3600, 'scope' => 'openid ' . GoogleConnection::SCOPE_SEARCH]], [200, ['email' => 'a@b.test']]]);
    $connection = new GoogleConnection($store, $transport, 'https://site.test/cb');
    $connection->saveClient('id.apps.googleusercontent.com', 'client-secret', 'api-key');
    $connection->connect('code', 'verifier');
    $all = implode("\n", $store->data);
    foreach (['client-secret', 'api-key', 'at-1', 'rt-1'] as $plain) {
        assert_true(!str_contains($all, $plain), "{$plain} is sealed");
    }
    foreach ([GoogleConnection::CLIENT_SECRET, GoogleConnection::API_KEY, GoogleConnection::ACCESS_TOKEN, GoogleConnection::REFRESH_TOKEN] as $key) {
        assert_true(str_starts_with($store->data[$key], 'enc:v1:'), $key);
    }
    assert_eq('a@b.test', $connection->email());
    assert_true($connection->granted(GoogleConnection::SCOPE_SEARCH));
    assert_true(!$connection->granted(GoogleConnection::SCOPE_ANALYTICS), 'an unticked scope is noticed');
    assert_true(str_contains((string) $transport->sent[0]['body'], 'code_verifier=verifier'));
});

unit('site insights: a blank secret keeps the stored one', function (): void {
    $store = new SiArrayStore();
    $connection = new GoogleConnection($store, new SiTransport([]), 'https://site.test/cb');
    $connection->saveClient('id.apps.googleusercontent.com', 'first', 'key');
    $sealed = $store->data[GoogleConnection::CLIENT_SECRET];
    $connection->saveClient('other.apps.googleusercontent.com', '', '  ');
    assert_eq($sealed, $store->data[GoogleConnection::CLIENT_SECRET]);
    assert_eq('key', $connection->apiKey());
    assert_eq('other.apps.googleusercontent.com', $store->data[GoogleConnection::CLIENT_ID]);
});

unit('site insights: the consent address asks offline, read-only access, with state and PKCE', function (): void {
    $connection = new GoogleConnection(new SiArrayStore(), new SiTransport([]), 'https://site.test/cb');
    $attempt = GoogleConnection::newAttempt();
    parse_str((string) parse_url($connection->authUrl($attempt), PHP_URL_QUERY), $q);
    assert_eq('offline', $q['access_type']);
    assert_eq('consent', $q['prompt']);
    assert_eq($attempt['state'], $q['state']);
    assert_eq('S256', $q['code_challenge_method']);
    assert_true(!str_contains((string) json_encode($q), $attempt['verifier']), 'the verifier stays here');
    assert_true(str_contains($q['scope'], 'webmasters.readonly') && str_contains($q['scope'], 'analytics.readonly'));
});

unit('site insights: a sign-in answer needs the right state, in time', function (): void {
    $attempt = ['state' => 'abc', 'verifier' => 'v', 'at' => 1000];
    assert_true(GoogleConnection::attemptMatches($attempt, 'abc', 1100));
    assert_true(!GoogleConnection::attemptMatches($attempt, 'abd', 1100), 'wrong state');
    assert_true(!GoogleConnection::attemptMatches($attempt, 'abc', 3000), 'too late');
    assert_true(!GoogleConnection::attemptMatches(null, 'abc', 1100), 'no attempt in this session');
    assert_true(!GoogleConnection::attemptMatches($attempt, '', 1100), 'no state');
});

unit('site insights: an expired token is refreshed once; a live one is reused', function () use ($siConnected): void {
    $transport = new SiTransport([[200, ['access_token' => 'fresh', 'expires_in' => 3600]]]);
    $store = new SiArrayStore();
    $connection = $siConnected($transport, $store);
    assert_eq('the-access', $connection->accessToken(time()));
    assert_eq([], $transport->sent, 'no call while the token lives');
    $store->set(GoogleConnection::EXPIRES_AT, (string) (time() + 30));
    assert_eq('fresh', $connection->accessToken(time()));
    assert_true(str_contains((string) $transport->sent[0]['body'], 'grant_type=refresh_token'));
    assert_eq('fresh', $connection->accessToken(time()), 'stored for next time');
    assert_eq(1, count($transport->sent));
});

unit('site insights: a revoked grant asks for a new connection instead of retrying', function () use ($siConnected): void {
    $transport = new SiTransport([[400, ['error' => 'invalid_grant']]]);
    $store = new SiArrayStore();
    $connection = $siConnected($transport, $store);
    $store->set(GoogleConnection::EXPIRES_AT, '1');
    try {
        $connection->accessToken(time());
        throw new Exception('should fail');
    } catch (GoogleApiException $e) {
        assert_eq(GoogleApiException::RECONNECT, $e->reason);
    }
    assert_true($connection->needsReconnect());
});

unit('site insights: a token Google refuses is renewed and the call tried once more', function () use ($siConnected): void {
    $transport = new SiTransport([[401, []], [200, ['access_token' => 'fresh', 'expires_in' => 3600]], [200, ['siteEntry' => [['siteUrl' => 'sc-domain:a.test', 'permissionLevel' => 'siteOwner'], ['siteUrl' => 'b', 'permissionLevel' => 'siteUnverifiedUser']]]]]);
    $sites = (new GoogleClient($siConnected($transport), $transport))->searchSites();
    assert_eq(['sc-domain:a.test'], array_column($sites, 'url'), 'an unverified property cannot be read');
    assert_true(in_array('Authorization: Bearer fresh', $transport->sent[2]['headers'], true));
});

unit('site insights: disconnecting revokes at Google and forgets everything but the client', function () use ($siConnected): void {
    $transport = new SiTransport([[200, []]]);
    $store = new SiArrayStore();
    $connection = $siConnected($transport, $store);
    $store->set(GoogleConnection::SEARCH_SITE, 'x');
    $connection->disconnect();
    assert_true(str_contains((string) $transport->sent[0]['body'], 'token=the-refresh'));
    assert_true(!$connection->isConnected());
    assert_eq('', $store->data[GoogleConnection::ACCESS_TOKEN]);
    assert_eq('', $store->data[GoogleConnection::SEARCH_SITE]);
    assert_true($connection->isConfigured());
});

unit('site insights: Google errors become reasons the screen can explain', function (): void {
    assert_eq(GoogleApiException::NOT_ENABLED, GoogleClient::reasonFor(403, ['error' => ['status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'SERVICE_DISABLED']]]]));
    assert_eq(GoogleApiException::NOT_ENABLED, GoogleClient::reasonFor(403, ['error' => ['errors' => [['reason' => 'accessNotConfigured']]]]));
    assert_eq(GoogleApiException::FORBIDDEN, GoogleClient::reasonFor(403, ['error' => ['errors' => [['reason' => 'forbidden']]]]));
    assert_eq(GoogleApiException::QUOTA, GoogleClient::reasonFor(429, []));
    assert_eq(GoogleApiException::QUOTA, GoogleClient::reasonFor(403, ['error' => ['errors' => [['reason' => 'dailyLimitExceeded', 'message' => 'quota']]]]));
    assert_eq(GoogleApiException::NOT_FOUND, GoogleClient::reasonFor(404, []));
    assert_eq(GoogleApiException::UNAVAILABLE, GoogleClient::reasonFor(500, []));
});

unit('site insights: a fake Google is honoured on loopback only, so a token cannot be sent elsewhere', function (): void {
    assert_eq('http://127.0.0.1:9/oauth2.googleapis.com/token', GoogleEndpoints::url(GoogleEndpoints::TOKEN, 'http://127.0.0.1:9'));
    assert_eq(GoogleEndpoints::TOKEN, GoogleEndpoints::url(GoogleEndpoints::TOKEN, 'https://evil.example'));
    assert_eq(GoogleEndpoints::TOKEN, GoogleEndpoints::url(GoogleEndpoints::TOKEN, ''));
});

unit('site insights: nothing of the connection travels in a backup, and the cache is tenant-scoped', function (): void {
    $keys = (new ReflectionClass(GoogleConnection::class))->getConstants();
    foreach ($keys as $value) {
        if (is_string($value) && str_starts_with($value, 'studio_google_')) {
            assert_true(!in_array($value, StudioBackupFormat::SETTING_KEYS, true), $value);
        }
    }
    assert_true(in_array('tenant_id', StudioSchemaManager::REQUIRED_TABLES['studiobuilder_google_cache'], true));
});

unit('site insights: windows end at the data Google has, and the previous one touches them', function (): void {
    $range = InsightsRange::of('7', new DateTimeImmutable('2026-11-10'));
    assert_eq(['start' => '2026-11-03', 'end' => '2026-11-09', 'previous_start' => '2026-10-27', 'previous_end' => '2026-11-02'], $range->analytics());
    assert_eq('2026-11-08', $range->search()['end'], 'Search Console lags a day more');
    assert_eq(28, InsightsRange::of('1000')->days, 'an unknown range falls back');
});

unit('site insights: URL search takes a path or an address of this site, not of another', function (): void {
    $base = 'https://chefgregory.com';
    assert_eq('', InsightsReports::normalizePath('  ', $base));
    assert_eq('/catering/', InsightsReports::normalizePath('catering/', $base));
    assert_eq('/catering/', InsightsReports::normalizePath('https://www.chefgregory.com/catering/?a=1#b', $base));
    assert_eq('/a', InsightsReports::normalizePath('/a?b=1', $base));
    assert_eq(null, InsightsReports::normalizePath('https://evil.example/a', $base));
    assert_eq(null, InsightsReports::normalizePath("/a\nb", $base));
    assert_eq('https://chefgregory.com', InsightsReports::publicBase('sc-domain:chefgregory.com', 'https://x.test'));
    assert_eq('https://a.test', InsightsReports::publicBase('https://a.test/', 'https://x.test'));
    assert_eq('https://x.test', InsightsReports::publicBase('', 'https://x.test/'));
});

unit('site insights: Analytics totals read both periods; the second ask comes from the cache', function () use ($siReports): void {
    $row = static fn(string $name, array $v): array => ['dimensionValues' => [['value' => $name]], 'metricValues' => array_map(static fn($x) => ['value' => (string) $x], $v)];
    [$reports, $transport] = $siReports([[200, ['rows' => [$row('current', [10, 8, 30, 0.5]), $row('previous', [5, 4, 15, 0.25])]]]]);
    $summary = $reports->analyticsSummary();
    assert_eq(10.0, $summary['current']['sessions']);
    assert_eq(0.25, $summary['previous']['engagement']);
    $reports->analyticsSummary();
    assert_eq(1, count($transport->sent), 'cached');
    assert_true(str_contains($transport->sent[0]['url'], 'properties/1:runReport'));
});

unit('site insights: a day Google leaves out is a zero on the chart', function () use ($siReports): void {
    [$reports] = $siReports([[200, ['rows' => [['dimensionValues' => [['value' => '20261105']], 'metricValues' => [['value' => '9']]]]]]]);
    $days = $reports->analyticsTrend();
    assert_eq(7, count($days));
    assert_eq(['date' => '2026-11-05', 'value' => 9.0], $days[2]);
    assert_eq(0.0, $days[0]['value']);
});

unit('site insights: Search totals split by period, with position weighted by impressions', function () use ($siReports): void {
    [$reports, $transport] = $siReports([[200, ['rows' => [
        ['keys' => ['2026-10-30'], 'clicks' => 1, 'impressions' => 10, 'position' => 9],
        ['keys' => ['2026-11-04'], 'clicks' => 3, 'impressions' => 100, 'position' => 2],
        ['keys' => ['2026-11-05'], 'clicks' => 1, 'impressions' => 300, 'position' => 6],
    ]]]]);
    $s = $reports->searchSummary();
    assert_eq(4.0, $s['current']['clicks']);
    assert_eq(1.0, $s['previous']['clicks']);
    assert_eq(0.01, $s['current']['ctr']);
    assert_eq(5.0, $s['current']['position']);
    assert_eq('2026-10-26', json_decode((string) $transport->sent[0]['body'], true)['startDate']);
});

unit('site insights: one page filters both Google sources', function () use ($siReports): void {
    [$reports, $transport] = $siReports([[200, []], [200, []]], '/catering/');
    $reports->analyticsTop('sessionDefaultChannelGroup', 'sessions', true);
    $reports->searchTop('query');
    $ga = json_decode((string) $transport->sent[0]['body'], true);
    assert_eq('/catering/', $ga['dimensionFilter']['andGroup']['expressions'][1]['filter']['stringFilter']['value']);
    $gsc = json_decode((string) $transport->sent[1]['body'], true);
    assert_eq('includingRegex', $gsc['dimensionFilterGroups'][0]['filters'][0]['operator']);
    assert_eq(1, preg_match('#' . $gsc['dimensionFilterGroups'][0]['filters'][0]['expression'] . '#', 'https://www.site.test/catering/'), 'a domain property matches www too');
    assert_eq('https://a.test/x', InsightsReports::pageFilter('https://a.test/', '/x')['expression'], 'a URL property holds one host');
});

unit('site insights: the most engaged source needs real traffic behind it', function () use ($siReports): void {
    $row = static fn(string $name, float $rate, int $sessions): array => ['dimensionValues' => [['value' => $name]], 'metricValues' => [['value' => (string) $rate], ['value' => (string) $sessions]]];
    [$reports] = $siReports([[200, ['rows' => [$row('Direct', 0.5, 500), $row('Email', 0.9, 40), $row('Tiny', 1.0, 2)]]]]);
    assert_eq(['Email', 'Direct'], array_column($reports->engagedSources(), 'label'));
});

unit('site insights: PageSpeed reads the score, field and lab figures, and copes without field data', function (): void {
    $speed = InsightsReports::parseSpeed([
        'lighthouseResult' => ['categories' => ['performance' => ['score' => 0.71]], 'audits' => ['total-blocking-time' => ['displayValue' => '210 ms']]],
        'loadingExperience' => ['metrics' => ['CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 4, 'category' => 'FAST']]],
    ], 'https://a.test/');
    assert_eq(71, $speed['score']);
    assert_eq(0.04, $speed['field']['cls']['value']);
    assert_eq('fast', $speed['field']['cls']['rating']);
    assert_eq('210 ms', $speed['lab']['tbt']);
    assert_eq(['score' => null, 'field' => [], 'lab' => [], 'url' => 'u'], InsightsReports::parseSpeed([], 'u'));
});

unit('site insights: the PageSpeed key is sent as a query value and only to PageSpeed', function () use ($siConnected): void {
    $transport = new SiTransport([[200, []]]);
    (new GoogleClient($siConnected($transport), $transport))->pageSpeed('https://a.test/', 'mobile', 'k1');
    assert_true(str_contains($transport->sent[0]['url'], 'key=k1') && str_contains($transport->sent[0]['url'], 'runPagespeed'));
    assert_eq([], $transport->sent[0]['headers'], 'no sign-in token goes with it');
});

unit('site insights: every string on the screens has a French entry', function (): void {
    $fr = require SLATE_ROOT . '/plugins/studio-builder/lang/fr.php';
    foreach (glob(SLATE_ROOT . '/plugins/studio-builder/admin/*insights*.php') ?: [] as $path) {
        preg_match_all("/__\('(studio_[a-z0-9_]+)'/", (string) file_get_contents($path), $m);
        foreach ($m[1] as $key) {
            assert_true(isset($fr[$key]), $key);
        }
    }
});

unit('site insights: the callback checks the sign-in before it trades the code', function (): void {
    $src = (string) file_get_contents(SLATE_ROOT . '/plugins/studio-builder/admin/insights-callback.php');
    assert_true(strpos($src, 'attemptMatches') < strpos($src, '->connect('), 'state first');
    assert_true(str_contains($src, "unset(\$_SESSION['sb_google_attempt'])"), 'an attempt is single use');
    foreach (glob(SLATE_ROOT . '/plugins/studio-builder/admin/*insights*.php') ?: [] as $path) {
        assert_true(!preg_match('/error_log\([^;]*(token|secret|\$url)/i', (string) file_get_contents($path)), basename($path) . ' logs no secret');
    }
});
