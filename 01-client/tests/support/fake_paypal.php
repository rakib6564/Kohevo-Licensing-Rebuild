<?php
/**
 * Start tests/fixtures/fake-paypal.php on a free loopback port for one test file, and point PayPalPaymentAPI at it
 * with the fake's sandbox credentials. Returns a handle for fake_paypal_stop(), which restores every
 * paypal-payment.* setting and removes the ledger and session rows the run created.
 */

declare(strict_types=1);

function fake_paypal_start(): array
{
    $state = sys_get_temp_dir() . '/slate-fake-paypal-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($state, 0700, true);
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $proc = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, SLATE_ROOT . '/tests/fixtures/fake-paypal.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $state . '/server.out', 'a'], 2 => ['file', $state . '/server.out', 'a']],
        $pipes, null, ['FAKE_PAYPAL_STATE' => $state, 'PATH' => (string) getenv('PATH')]
    );
    for ($i = 0; $i < 50; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.1);
        if ($c) { fclose($c); break; }
        usleep(100000);
    }

    $tid = current_tenant_id();
    $saved = Database::rows("SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key LIKE 'paypal-payment.%'", [$tid]);
    Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE 'paypal-payment.%'", [$tid]);
    Database::setSetting('paypal-payment.mode', 'sandbox');
    Database::setSetting('paypal-payment.sandbox_client_id', 'fake-client-id-0000000000');
    Database::setSetting('paypal-payment.sandbox_client_secret', slate_encrypt_secret('fake-secret'));
    Database::setSetting('paypal-payment.sandbox_webhook_id', 'WH-FAKE-0001');
    $_ENV['PAYPAL_API_BASE'] = 'http://127.0.0.1:' . $port;
    return ['state' => $state, 'proc' => $proc, 'port' => $port, 'saved' => $saved];
}

/** POST a test hook (approve an order, activate a subscription, …) to the running fake. */
function fake_paypal_hook(array $h, string $path): void
{
    $ch = curl_init('http://127.0.0.1:' . $h['port'] . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    curl_exec($ch);
}

/** Every request the fake received, oldest first. */
function fake_paypal_requests(array $h): array
{
    $f = $h['state'] . '/requests.log';
    return is_file($f) ? array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f)))) : [];
}

function fake_paypal_stop(array $h): void
{
    $tid = current_tenant_id();
    Database::query("DELETE FROM stripepayment_charges WHERE tenant_id = ? AND gateway = 'paypal'", [$tid]);
    Database::query("DELETE FROM payment_sessions WHERE tenant_id = ? AND gateway = 'paypal'", [$tid]);
    Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE 'paypal-payment.%'", [$tid]);
    foreach ($h['saved'] as $row) Database::setSetting($row['setting_key'], $row['setting_value'], $tid);
    unset($_ENV['PAYPAL_API_BASE']);
    proc_terminate($h['proc']);
    proc_close($h['proc']);
    array_map('unlink', glob($h['state'] . '/*') ?: []);
    @rmdir($h['state']);
}
