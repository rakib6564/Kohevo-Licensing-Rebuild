<?php
// Phase 12 E2E — concurrency, repetition and per-request cost against the real servers.
declare(strict_types=1);
require __DIR__ . '/lib.php';

function checkin_req(string $key, string $iid, string $domain): string {
    return json_encode(['product' => 'kohevo', 'license_key' => $key, 'install_id' => $iid, 'domain' => $domain, 'app_version' => '1.0.0']);
}
/** Fire N POSTs to the real central at once. @return int[] status codes */
function parallel(array $bodies): array {
    $mh = curl_multi_init(); $hs = [];
    foreach ($bodies as $b) {
        $h = curl_init('http://127.0.0.1:8091/licensing/check');
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $b, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 30]);
        curl_multi_add_handle($mh, $h); $hs[] = $h;
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running);
    return array_map(fn ($h) => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $hs);
}
function questions(): int { return (int) central_db()->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1]; }

// php -S is single-threaded unless PHP_CLI_SERVER_WORKERS is set; start a multi-worker central.
central_stop();
shell_exec('(cd ' . escapeshellarg(E2E_DIR . '/central') . ' && PHP_CLI_SERVER_WORKERS=8 nohup php -S 127.0.0.1:8091 dev-server.php >> ../central-server.log 2>&1 < /dev/null &) > /dev/null 2>&1');
usleep(1200000);

// 1. Twelve DIFFERENT installations race to activate one license
$lic = central('issue', json_encode(['modules' => ['forms']]));
$codes = parallel(array_map(fn () => checkin_req($lic['license_key'], bin2hex(random_bytes(16)), 'race' . random_int(1, 1000000) . '.test'), range(1, 12)));
$ok = count(array_filter($codes, fn ($c) => $c === 200));
check($ok === 1, 'concurrent activation by 12 installations: exactly one succeeds', implode(',', $codes));
check((int) zval("SELECT COUNT(*) FROM licensing_installations WHERE license_id = ? AND status = 'active'", [$lic['license_id']]) === 1, 'exactly one active binding');
check((int) zval("SELECT COUNT(*) FROM licensing_license_events WHERE license_id = ? AND event_type = 'activate'", [$lic['license_id']]) === 1, 'exactly one activate event');

// 2. The SAME installation fires 12 concurrent first check-ins
$lic2 = central('issue', json_encode(['modules' => ['forms']]));
$iid = bin2hex(random_bytes(16));
$codes = parallel(array_fill(0, 12, checkin_req($lic2['license_key'], $iid, 'same.test')));
check((int) zval('SELECT COUNT(*) FROM licensing_installations WHERE license_id = ?', [$lic2['license_id']]) === 1, 'same installation x12 concurrently: one binding row', implode(',', $codes));
check(!in_array(0, $codes, true), 'no request dropped/hung', implode(',', $codes));
$five = count(array_filter($codes, fn ($c) => $c >= 500));
echo "     same-installation race status codes: " . implode(',', $codes) . "\n";

// 3. Repetition: 300 routine refresh check-ins
$before = questions(); $t = microtime(true);
$codes = [];
for ($i = 0; $i < 300; $i++) {
    $h = curl_init('http://127.0.0.1:8091/licensing/check');
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => checkin_req($lic2['license_key'], $iid, 'same.test'), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    curl_exec($h); $codes[] = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
}
$ms = (microtime(true) - $t) * 1000 / 300; $q = (questions() - $before) / 300;
check(count(array_unique($codes)) === 1 && $codes[0] === 200, '300 sequential refresh check-ins all 200');
printf("     refresh check-in: %.1f ms avg, %.1f DB statements/request (global counter)\n", $ms, $q);
check((int) zval("SELECT COUNT(*) FROM licensing_license_events WHERE license_id = ?", [$lic2['license_id']]) <= 3, 'routine refreshes write no license events (no unbounded growth)');
check((int) zval("SELECT COUNT(*) FROM licensing_installations WHERE license_id = ?", [$lic2['license_id']]) === 1, 'still one installation row after 300 refreshes');

// 4. Junk traffic: 300 unknown keys write nothing
$rowsBefore = (int) zval('SELECT (SELECT COUNT(*) FROM licensing_checkins) + (SELECT COUNT(*) FROM licensing_license_events) + (SELECT COUNT(*) FROM licensing_installations)');
$logBefore = @filesize(E2E_DIR . '/central/data/slate.log') ?: 0;
parallel(array_map(fn () => checkin_req('KOHEVO-JUNK-' . bin2hex(random_bytes(6)), bin2hex(random_bytes(16)), 'junk.test'), range(1, 60)));
for ($i = 0; $i < 240; $i++) {
    $h = curl_init('http://127.0.0.1:8091/licensing/check');
    curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => checkin_req('KOHEVO-JUNK-' . $i, bin2hex(random_bytes(16)), 'junk.test'), CURLOPT_RETURNTRANSFER => true]);
    curl_exec($h);
}
clearstatcache();
check((int) zval('SELECT (SELECT COUNT(*) FROM licensing_checkins) + (SELECT COUNT(*) FROM licensing_license_events) + (SELECT COUNT(*) FROM licensing_installations)') === $rowsBefore, '300 unknown-key requests write no DB rows');
check(((@filesize(E2E_DIR . '/central/data/slate.log') ?: 0) - $logBefore) === 0, '300 unknown-key requests write no log lines');

// 5. Client: per-request cost of the guards (re-install happens in scenario_install; license may be revoked by runtime scenario)
summary();
