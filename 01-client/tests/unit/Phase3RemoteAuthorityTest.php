<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/client/RemoteLicenseClient.php';

final class Phase3MemoryCache implements LicenseCacheStoreInterface {
    public array $state;
    public int $writes = 0;
    public function __construct(array $state = []) { $this->state = $state; }
    public function load(): ?array { return $this->state ?: null; }
    public function save(array $status): void { $this->state = $status; $this->writes++; }
}

function p3_signed_config(string $public, string $key = 'key'): array {
    return ['server_url'=>'https://license.test','public_key'=>$public,'product'=>'kohevo','license_key'=>$key,'install_id'=>str_repeat('a',32),'domain'=>'example.com'];
}

unit('Phase 3 remote client: valid signed state updates cache with exact server mapping', function (): void {
    $pair = sodium_crypto_sign_keypair();
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $secret = sodium_crypto_sign_secretkey($pair);
    $payload = json_encode(['status'=>'active','plan'=>'pro','entitlements'=>['white_label'],'expires_at'=>null,'checked_at'=>'2026-09-25T00:00:00Z','next_check_after'=>86400], JSON_UNESCAPED_SLASHES);
    $sig = base64_encode(sodium_crypto_sign_detached($payload, $secret));
    $store = new Phase3MemoryCache(['status'=>'suspended','entitlements'=>[]]);
    $client = new RemoteLicenseClient(p3_signed_config($public), $store, fn() => [200, json_encode(['payload'=>$payload,'signature'=>$sig])]);
    assert_true($client->checkIn());
    assert_eq(1, $store->writes);
    assert_eq('active', $store->state['status']);
    assert_eq(['white_label'], $store->state['entitlements']);
    assert_eq('2026-09-25T00:00:00Z', $store->state['remote_checked_at']);
    assert_eq(86400, $store->state['next_check_after']);
});

unit('Phase 3 remote client: invalid signature never overwrites last verified state', function (): void {
    $pair = sodium_crypto_sign_keypair();
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    $store = new Phase3MemoryCache(['status'=>'active','plan'=>'pro','entitlements'=>['white_label']]);
    $before = $store->state;
    $client = new RemoteLicenseClient(p3_signed_config($public), $store, fn() => [200, json_encode(['payload'=>'{"status":"suspended"}','signature'=>base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES))])]);
    assert_false($client->checkIn());
    assert_eq($before, $store->state);
    assert_eq(0, $store->writes);
});

unit('Phase 3 remote client: network failure and malformed response never overwrite last verified state', function (): void {
    $pair = sodium_crypto_sign_keypair();
    $public = base64_encode(sodium_crypto_sign_publickey($pair));
    foreach ([fn() => null, fn() => [200, '{not-json']] as $transport) {
        $store = new Phase3MemoryCache(['status'=>'active','plan'=>'pro','entitlements'=>['white_label']]);
        $before = $store->state;
        $client = new RemoteLicenseClient(p3_signed_config($public), $store, $transport);
        assert_false($client->checkIn());
        assert_eq($before, $store->state);
        assert_eq(0, $store->writes);
    }
});
