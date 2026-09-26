<?php
// Phase 12 E2E helpers (see README.md): real HTTP against php -S servers, plus central/client DB access.
declare(strict_types=1);

// Work directory holding the throwaway app copies, cookie jars and state (run.sh creates it).
define('E2E_DIR', rtrim((string) (getenv('P12_WORKDIR') ?: sys_get_temp_dir() . '/kohevo-p12-e2e'), '/'));
const CLIENT = 'http://127.0.0.1:8092';
const CENTRAL_FIXTURE = E2E_DIR . '/central/tests/fixtures/phase10-central.php';
define('P12_DB_USER', (string) (getenv('P12_DB_USER') ?: 'root'));
define('P12_DB_PASS', (string) (getenv('P12_DB_PASS') ?: ''));

$GLOBALS['E2E_PASS'] = 0; $GLOBALS['E2E_FAIL'] = 0;

function check(bool $ok, string $name, string $detail = ''): void {
    if ($ok) { $GLOBALS['E2E_PASS']++; echo "PASS $name\n"; }
    else { $GLOBALS['E2E_FAIL']++; echo "FAIL $name" . ($detail !== '' ? "  -- $detail" : '') . "\n"; }
}

function summary(): void {
    echo "# e2e passed {$GLOBALS['E2E_PASS']} / " . ($GLOBALS['E2E_PASS'] + $GLOBALS['E2E_FAIL']) . "\n";
    exit($GLOBALS['E2E_FAIL'] > 0 ? 1 : 0);
}

/** @return array{status:int, location:string, body:string} */
function http(string $method, string $path, array $post = [], string $jar = 'jar.txt', array $headers = []): array {
    $ch = curl_init(CLIENT . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => E2E_DIR . '/' . $jar,
        CURLOPT_COOKIEFILE => E2E_DIR . '/' . $jar,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $head = substr($raw, 0, $hsize);
    preg_match('/^Location:\s*(\S+)/mi', $head, $m);
    return ['status' => $status, 'location' => $m[1] ?? '', 'body' => substr($raw, $hsize)];
}

function csrf(string $body): string {
    preg_match('/name="_csrf" value="([^"]+)"/', $body, $m);
    return $m[1] ?? '';
}

/** GET the resolved installer step; returns [stepNumber, body]. */
function installer_step(string $jar = 'jar.txt'): array {
    $r = http('GET', '/install.php', [], $jar);
    $hops = 0;
    while ($r['location'] !== '' && $hops++ < 3) {
        $loc = $r['location'];
        $path = parse_url($loc, PHP_URL_PATH) . (parse_url($loc, PHP_URL_QUERY) ? '?' . parse_url($loc, PHP_URL_QUERY) : '');
        $r = http('GET', $path, [], $jar);
    }
    $step = preg_match('/Step (\d) of 5/', $r['body'], $m) ? (int) $m[1] : 0;
    if (str_contains($r['body'], 'already installed')) $step = 99;
    return [$step, $r['body']];
}

function installer_post(int $step, array $fields, string $jar = 'jar.txt'): array {
    [, $body] = installer_step($jar);
    if ($step >= 2) $fields['_csrf'] = csrf($body);
    return http('POST', '/install.php?step=' . $step, $fields, $jar);
}

function central(string ...$args): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CENTRAL_FIXTURE);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    $out = (string) shell_exec($cmd . ' 2>&1');
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['raw' => $out];
}

function client_db(): PDO {
    static $pdo = null;
    return $pdo ??= new PDO('mysql:host=127.0.0.1;dbname=p12_client;charset=utf8mb4', P12_DB_USER, P12_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function central_db(): PDO {
    static $pdo = null;
    return $pdo ??= new PDO('mysql:host=127.0.0.1;dbname=p12_central;charset=utf8mb4', P12_DB_USER, P12_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function cval(string $sql, array $p = []) { $s = client_db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function zval(string $sql, array $p = []) { $s = central_db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function client_env(): string { return (string) @file_get_contents(E2E_DIR . '/client/.env'); }
function env_value(string $key): ?string { return preg_match('/^' . $key . '=(.*)$/m', client_env(), $m) ? trim($m[1]) : null; }
function installed(): bool { return is_file(E2E_DIR . '/client/.installed'); }

/** Drop and recreate the client database and remove .env/.installed — a genuinely fresh client. */
function fresh_client(): void {
    client_db()->exec('DROP DATABASE IF EXISTS p12_client');
    client_db()->exec('CREATE DATABASE p12_client CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    client_db()->exec('USE p12_client');
    @unlink(E2E_DIR . '/client/.env');
    @unlink(E2E_DIR . '/client/.installed');
    foreach (glob(E2E_DIR . '/*.txt') as $f) if (str_starts_with(basename($f), 'jar')) @unlink($f);
}

function step1(): array {
    return installer_post(1, [
        'db_host' => '127.0.0.1', 'db_port' => '', 'db_name' => 'p12_client',
        'db_user' => P12_DB_USER, 'db_pass' => P12_DB_PASS, 'app_url' => CLIENT,
    ]);
}

function central_start(): void {
    shell_exec('(cd ' . escapeshellarg(E2E_DIR . '/central') . ' && nohup php -S 127.0.0.1:8091 dev-server.php >> ../central-server.log 2>&1 < /dev/null &) > /dev/null 2>&1');
    usleep(800000);
}
function central_stop(): void { shell_exec('pkill -f "php -S 127.0.0.1:8091"'); usleep(300000); }

function modules(string $op, int $licenseId, string $module): array {
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(E2E_DIR . '/central/tests/fixtures/p12-modules.php') . " $op $licenseId " . escapeshellarg($module) . ' 2>&1');
    return json_decode($out, true) ?: ['raw' => $out];
}

/** The real unattended check-in, exactly as a crontab would run it. */
function license_check(): string {
    $env = 'LICENSE_SERVER_URL=http://127.0.0.1:8091 LICENSE_SERVER_PUBLIC_KEY=' . escapeshellarg(trim((string) file_get_contents(E2E_DIR . '/pubkey.txt'))) . ' LICENSE_PRODUCT=kohevo ';
    return trim((string) shell_exec('cd ' . escapeshellarg(E2E_DIR . '/client') . ' && ' . $env . escapeshellarg(PHP_BINARY) . ' bin/license-check.php 2>&1'));
}

function client_php(string $code): string {
    $env = 'LICENSE_SERVER_URL=http://127.0.0.1:8091 LICENSE_SERVER_PUBLIC_KEY=' . escapeshellarg(trim((string) file_get_contents(E2E_DIR . '/pubkey.txt'))) . ' LICENSE_PRODUCT=kohevo ';
    return trim((string) shell_exec('cd ' . escapeshellarg(E2E_DIR . '/client') . ' && ' . $env . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "config.php"; ' . $code) . ' 2>&1'));
}

function admin_login(string $jar = 'jar-admin.txt'): void {
    @unlink(E2E_DIR . '/' . $jar);
    $login = http('GET', '/admin/login.php', [], $jar);
    http('POST', '/admin/login.php', ['email' => 'admin@p12.test', 'password' => 'correct-horse-p12', '_csrf' => csrf($login['body'])], $jar);
}

function admin_get(string $path): array { return http('GET', $path, [], 'jar-admin.txt'); }
function locked(array $r): bool { return $r['status'] === 403 && str_contains($r['body'], 'License inactive'); }
