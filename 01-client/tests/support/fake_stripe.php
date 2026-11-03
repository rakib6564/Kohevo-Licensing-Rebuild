<?php
/**
 * Start tests/fixtures/fake-stripe.php on a free loopback port for one test file, and point StripePaymentAPI at it
 * with placeholder test-mode keys. Returns a handle for fake_stripe_stop(), which restores every setting it changed.
 */

declare(strict_types=1);

function fake_stripe_start(): array
{
    $state = sys_get_temp_dir() . '/slate-fake-stripe-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($state, 0700, true);
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $proc = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, SLATE_ROOT . '/tests/fixtures/fake-stripe.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $state . '/server.out', 'a'], 2 => ['file', $state . '/server.out', 'a']],
        $pipes, null, ['FAKE_STRIPE_STATE' => $state, 'PATH' => (string) getenv('PATH')]
    );
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.1);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }
    $settings = [];
    foreach (['stripe-payment.mode' => 'test', 'stripe-payment.test_secret_key' => 'sk_test_fake_restaurant',
              'stripe-payment.test_publishable_key' => 'pk_test_fake_restaurant'] as $k => $v) {
        $settings[$k] = Database::setting($k);
        Database::setSetting($k, $v);
    }
    $_ENV['STRIPE_API_BASE'] = 'http://127.0.0.1:' . $port;
    return ['state' => $state, 'proc' => $proc, 'settings' => $settings];
}

function fake_stripe_stop(array $h): void
{
    $objects = $h['state'] . '/objects.json';
    $run = is_file($objects) ? (string) (json_decode((string) file_get_contents($objects), true)['run'] ?? '') : '';
    if ($run !== '') {
        Database::query("DELETE FROM stripepayment_charges WHERE stripe_payment_intent_id LIKE ?", ['pi_fake' . $run . '\_%']);
    }
    foreach ($h['settings'] as $k => $orig) {
        if ($orig === null) {
            Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [current_tenant_id(), $k]);
        } else {
            Database::setSetting($k, $orig);
        }
    }
    unset($_ENV['STRIPE_API_BASE']);
    proc_terminate($h['proc']);
    proc_close($h['proc']);
    array_map('unlink', glob($h['state'] . '/*') ?: []);
    @rmdir($h['state']);
}
