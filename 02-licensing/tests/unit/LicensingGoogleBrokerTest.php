<?php
/**
 * The Google broker: one OAuth client on Central, signed sign-ins bound to an installation, tokens handed over once.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/GoogleBroker.php';

final class GbTest
{
    /** @var array<string, array{0: string, 1: array<string, mixed>, 2: int}> */
    public array $handoffs = [];
    /** @var list<array{0: string, 1: array<string, string>}> */
    public array $posts = [];
    /** @var array{status: int, body: string} */
    public array $google = ['status' => 200, 'body' => '{"access_token":"at","refresh_token":"rt","expires_in":3600,"scope":"openid"}'];

    public function broker(string $key = 'central-key', string $secret = 'client-secret'): GoogleBroker
    {
        return new GoogleBroker(
            'client-id',
            $secret,
            'https://central.test/licensing/google/callback',
            static fn(string $context, string $value): string => hash_hmac('sha256', $context . '|' . $value, $key),
            function (string $url, array $fields): array {
                $this->posts[] = [$url, $fields];
                return $this->google;
            },
            function (string $hash, string $installation, array $tokens, int $expires): void {
                $this->handoffs[$hash] = [$installation, $tokens, $expires];
            },
            function (string $hash, string $installation, int $now): ?array {
                $row = $this->handoffs[$hash] ?? null;
                if ($row === null || $row[0] !== $installation || $row[2] < $now) {
                    return null;
                }
                unset($this->handoffs[$hash]);
                return $row[1];
            },
        );
    }
}

const GB_SITE = ['installation_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'domain' => 'chefgregory.com'];
const GB_RETURN = 'https://chefgregory.com/plugins/studio-builder/admin/insights-callback.php';
define('GB_STATE', str_repeat('ab', 24));

function gb_state(array $started): string
{
    parse_str((string) parse_url($started['body']['auth_url'], PHP_URL_QUERY), $query);
    return (string) $query['state'];
}

unit('google broker: a licensed site gets a consent address with PKCE; no secret leaves Central', function (): void {
    $started = (new GbTest())->broker()->start(GB_SITE, GB_RETURN, GB_STATE, 1000);
    assert_eq(200, $started['status']);
    parse_str((string) parse_url($started['body']['auth_url'], PHP_URL_QUERY), $query);
    assert_eq('client-id', $query['client_id']);
    assert_eq('https://central.test/licensing/google/callback', $query['redirect_uri']);
    assert_eq('S256', $query['code_challenge_method']);
    assert_eq('offline', $query['access_type']);
    assert_true(!str_contains((string) json_encode($started), 'client-secret'));
});

unit('google broker: the return address must be the Insights callback on the licensed domain', function (): void {
    $allowed = static fn(string $url, string $domain = 'chefgregory.com'): bool => GoogleBroker::returnUrlAllowed($url, $domain);
    assert_true($allowed(GB_RETURN));
    assert_true($allowed('https://rakibhasaan.com/chefgreg/plugins/studio-builder/admin/insights-callback.php', 'rakibhasaan.com'), 'a subfolder site');
    assert_true($allowed('http://localhost:8377/plugins/studio-builder/admin/insights-callback.php', 'localhost:8377'), 'local development');
    assert_true(!$allowed('https://evil.example/plugins/studio-builder/admin/insights-callback.php'), 'another domain');
    assert_true(!$allowed('https://chefgregory.com.evil.example/plugins/studio-builder/admin/insights-callback.php'), 'a look-alike domain');
    assert_true(!$allowed('https://www.chefgregory.com/plugins/studio-builder/admin/insights-callback.php'), 'another host of the domain');
    assert_true(!$allowed('http://chefgregory.com/plugins/studio-builder/admin/insights-callback.php'), 'plain http on the internet');
    assert_true(!$allowed('https://chefgregory.com/admin/index.php'), 'another page');
    assert_true(!$allowed(GB_RETURN . '?x=1'), 'a query');
    assert_true(!$allowed('https://user@chefgregory.com/plugins/studio-builder/admin/insights-callback.php'), 'userinfo');
    assert_true(!$allowed('https://chefgregory.com/a/../plugins/studio-builder/admin/insights-callback.php'), 'climbing out');
    assert_eq('invalid_return', (new GbTest())->broker()->start(GB_SITE, 'https://evil.example/plugins/studio-builder/admin/insights-callback.php', GB_STATE, 1000)['body']['error']);
});

unit('google broker: callback exchanges the code with the secret and returns a handoff, never tokens', function (): void {
    $t = new GbTest();
    $broker = $t->broker();
    $result = $broker->callback(['code' => 'c', 'state' => gb_state($broker->start(GB_SITE, GB_RETURN, GB_STATE, 1000))], 1100);
    assert_eq(303, $result['status']);
    assert_true(str_starts_with($result['redirect'], GB_RETURN . '?handoff='));
    assert_true(str_contains($result['redirect'], 'state=' . GB_STATE), 'the site can match its own sign-in');
    assert_true(!str_contains($result['redirect'], 'token'), 'no token in a browser address');
    assert_eq('client-secret', $t->posts[0][1]['client_secret']);
    assert_true(strlen($t->posts[0][1]['code_verifier']) >= 43, 'a PKCE verifier Google accepts');
});

unit('google broker: a tampered, forged or expired state sends nobody anywhere and trades no code', function (): void {
    $t = new GbTest();
    $broker = $t->broker();
    $state = gb_state($broker->start(GB_SITE, GB_RETURN, GB_STATE, 1000));
    [$body, $signature] = explode('.', $state);
    $data = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
    $data['r'] = 'https://evil.example/plugins/studio-builder/admin/insights-callback.php';
    $tampered = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=') . '.' . $signature;
    $forged = gb_state((new GbTest())->broker('other-key')->start(GB_SITE, GB_RETURN, GB_STATE, 1000));
    foreach (['tampered' => $tampered, 'forged' => $forged, 'unsigned' => $body, 'empty' => ''] as $name => $bad) {
        $result = $broker->callback(['code' => 'c', 'state' => $bad], 1100);
        assert_eq(400, $result['status'], $name);
        assert_true(!isset($result['redirect']), $name);
    }
    assert_eq(400, $broker->callback(['code' => 'c', 'state' => $state], 1000 + GoogleBroker::STATE_TTL + 1)['status'], 'expired');
    assert_eq([], $t->posts, 'no code was traded');
});

unit('google broker: refusing at Google goes back to the site without tokens', function (): void {
    $broker = (new GbTest())->broker();
    $result = $broker->callback(['error' => 'access_denied', 'state' => gb_state($broker->start(GB_SITE, GB_RETURN, GB_STATE, 1000))], 1100);
    assert_true(str_contains($result['redirect'], 'error=access_denied'));
    assert_true(!str_contains($result['redirect'], 'handoff'));
});

unit('google broker: a handoff is claimed once, by its own installation, in time', function (): void {
    $t = new GbTest();
    $broker = $t->broker();
    $go = fn(): string => (string) (parse_url($broker->callback(['code' => 'c', 'state' => gb_state($broker->start(GB_SITE, GB_RETURN, GB_STATE, 1000))], 1100)['redirect'], PHP_URL_QUERY));
    parse_str($go(), $q);
    $other = ['installation_id' => str_repeat('b', 32), 'domain' => 'other.test'];
    assert_eq('invalid_handoff', $broker->claim($other, $q['handoff'], 1101)['body']['error'], 'another installation');
    $claimed = $broker->claim(GB_SITE, $q['handoff'], 1101);
    assert_eq('rt', $claimed['body']['refresh_token']);
    assert_eq('invalid_handoff', $broker->claim(GB_SITE, $q['handoff'], 1101)['body']['error'], 'second claim');
    assert_eq([], $t->handoffs, 'Central keeps no tokens');
    parse_str($go(), $late);
    assert_eq(404, $broker->claim(GB_SITE, $late['handoff'], 1100 + GoogleBroker::HANDOFF_TTL + 1)['status'], 'too late');
    assert_eq(404, $broker->claim(GB_SITE, 'not-a-handoff', 1101)['status']);
});

unit('google broker: refresh adds the secret; a dead grant is reported so the site can ask to connect again', function (): void {
    $t = new GbTest();
    $t->google = ['status' => 200, 'body' => '{"access_token":"new","expires_in":3599}'];
    $broker = $t->broker();
    assert_eq(['access_token' => 'new', 'expires_in' => 3599], $broker->refresh(GB_SITE, 'rt')['body']);
    assert_eq('client-secret', $t->posts[0][1]['client_secret']);
    $t->google = ['status' => 400, 'body' => '{"error":"invalid_grant"}'];
    assert_eq('invalid_grant', $broker->refresh(GB_SITE, 'rt')['body']['error']);
    $t->google = ['status' => 500, 'body' => ''];
    assert_eq('google_unavailable', $broker->refresh(GB_SITE, 'rt')['body']['error']);
});

unit('google broker: with no client on Central it says so', function (): void {
    assert_eq('not_configured', (new GbTest())->broker('k', '')->start(GB_SITE, GB_RETURN, GB_STATE, 1000)['body']['error']);
});
