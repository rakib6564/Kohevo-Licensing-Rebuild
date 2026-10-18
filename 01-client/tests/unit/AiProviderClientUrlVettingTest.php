<?php
/**
 * AiProviderClient::vetBaseUrl() — the admin-configured AI endpoint must be a
 * public https address, so it cannot be aimed at internal services (SSRF).
 * DNS is injected, so these cases never touch the network.
 */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/plugins/mcp-gateway/AiProviderClient.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

/** A resolver that answers from a fixed table and records what it was asked. */
function apcv_dns(array $table): callable
{
    return static fn(string $host): array => $table[$host] ?? [];
}

unit('AI base URL: a public https endpoint is accepted and pinned to the resolved address', function (): void {
    $r = AiProviderClient::vetBaseUrl('https://api.example.test/v1', apcv_dns(['api.example.test' => ['93.184.216.34']]));
    assert_true($r['ok']);
    assert_eq('api.example.test', $r['host']);
    assert_eq(443, $r['port']);
    assert_eq('93.184.216.34', $r['ip']);
    $r = AiProviderClient::vetBaseUrl('https://api.example.test:8443/v1', apcv_dns(['api.example.test' => ['93.184.216.34']]));
    assert_eq(8443, $r['port'], 'an explicit port is kept for pinning');
    assert_true(AiProviderClient::vetBaseUrl('https://1.1.1.1/v1')['ok'], 'a public IP literal needs no DNS');
});

unit('AI base URL: anything but https, or a URL carrying credentials, is refused', function (): void {
    $dns = apcv_dns(['api.example.test' => ['93.184.216.34']]);
    assert_eq('https_required', AiProviderClient::vetBaseUrl('http://api.example.test/v1', $dns)['error']);
    assert_eq('https_required', AiProviderClient::vetBaseUrl('file:///etc/passwd', $dns)['error']);
    assert_eq('https_required', AiProviderClient::vetBaseUrl('gopher://api.example.test/', $dns)['error']);
    assert_eq('https_required', AiProviderClient::vetBaseUrl('api.example.test/v1', $dns)['error']);
    assert_eq('credentials_in_url', AiProviderClient::vetBaseUrl('https://user:pw@api.example.test/v1', $dns)['error']);
    assert_false(AiProviderClient::vetBaseUrl('https:///v1', $dns)['ok'], 'a URL with no host is refused (parse_url rejects it outright)');
});

unit('AI base URL: private, loopback, link-local and metadata addresses are refused, as literals or via DNS', function (): void {
    foreach (['127.0.0.1', '10.0.0.5', '172.16.3.4', '192.168.1.10', '169.254.169.254', '0.0.0.0', '[::1]', '[fc00::1]', '[fe80::1]'] as $literal) {
        $r = AiProviderClient::vetBaseUrl("https://{$literal}/v1", apcv_dns([]));
        assert_false($r['ok'], "{$literal} must be refused");
        assert_eq('private_address', $r['error'], "{$literal}: reason");
    }
    $dns = apcv_dns([
        'localhost'           => ['127.0.0.1'],
        'internal.corp.test'  => ['10.1.2.3'],
        'metadata.cloud.test' => ['169.254.169.254'],
        'mixed.example.test'  => ['93.184.216.34', '192.168.0.9'],
    ]);
    foreach (['localhost', 'internal.corp.test', 'metadata.cloud.test'] as $host) {
        assert_eq('private_address', AiProviderClient::vetBaseUrl("https://{$host}/v1", $dns)['error'], "{$host} resolves privately");
    }
    assert_eq('private_address', AiProviderClient::vetBaseUrl('https://mixed.example.test/v1', $dns)['error'], 'one private address among public ones is enough to refuse');
});

unit('AI base URL: a host that does not resolve is refused', function (): void {
    assert_eq('host_unresolvable', AiProviderClient::vetBaseUrl('https://nowhere.example.test/v1', apcv_dns([]))['error']);
});

unit('AI base URL: CGNAT, benchmarking, multicast and IPv4-embedding IPv6 prefixes are refused; neighbours stay allowed', function (): void {
    foreach (['100.64.0.1', '100.127.255.255', '198.18.0.1', '198.19.255.254', '224.0.0.1', '[64:ff9b::a9fe:a9fe]', '[2002:7f00:1::]', '[2001:0:4136:e378::1]', '[ff02::1]'] as $literal) {
        assert_eq('private_address', AiProviderClient::vetBaseUrl("https://{$literal}/v1", apcv_dns([]))['error'] ?? null, "{$literal} must be refused");
    }
    foreach (['100.128.0.1', '198.20.0.1', '[2001:4860:4860::8888]', '[2606:4700:4700::1111]'] as $literal) {
        assert_true(AiProviderClient::vetBaseUrl("https://{$literal}/v1", apcv_dns([]))['ok'], "{$literal} is public and must be allowed");
    }
    assert_eq('private_address', AiProviderClient::vetBaseUrl('https://nat64.example.test/v1', apcv_dns(['nat64.example.test' => ['64:ff9b::7f00:1']]))['error'], 'a NAT64-wrapped loopback via DNS is refused');
});
