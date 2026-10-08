<?php
/**
 * Throw-away local sandbox provisioning for the DOM/e2e harness. DEVELOPER TOOL ONLY:
 * it DROPS and recreates the database named in the sandbox's .env, so it refuses to run
 * unless that database name starts with `slate_sbx_`.
 *
 * Run by e2e/sandbox.sh from the sandbox copy of 01-client, never against a real install.
 * Test-only credentials below belong to this disposable database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('SLATE_TESTING', true);
require dirname(__DIR__, 4) . '/config.php';
require dirname(__DIR__, 4) . '/tests/support/license_signing.php';
require_once dirname(__DIR__, 4) . '/includes/Plugin.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Services\Installation\InstallationService;
use Slate\Services\Media\Media;

const SBX_ADMIN_EMAIL = 'admin@sbx.test';
const SBX_ADMIN_PASSWORD = 'sbx-pass-123';

if (!str_starts_with(DB_NAME, 'slate_sbx_')) {
    fwrite(STDERR, 'Refusing to provision: DB_NAME must start with slate_sbx_ (got ' . DB_NAME . ")\n");
    exit(1);
}

$dsn = 'mysql:host=' . DB_HOST . (trim((string) DB_PORT) !== '' ? ';port=' . DB_PORT : '');
$root = new PDO($dsn . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
$root->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4');
$pdo = new PDO($dsn . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
(new MigrationRunner($pdo, dirname(__DIR__, 4) . '/db/migrations'))->migrate();
(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);

$_ENV['LICENSE_SERVER_URL'] = 'https://license.test';
$_ENV['LICENSE_SERVER_PUBLIC_KEY'] = license_test_public_key();
$_ENV['LICENSE_PRODUCT'] = 'kohevo';
$_ENV['LICENSE_KEY'] = 'test-key';

$core = InstallationService::provisionCore();
$tenant = (int) $core['tenant_id'];
InstallationService::createAdminAccount($tenant, 'Sandbox Admin', SBX_ADMIN_EMAIL, password_hash(SBX_ADMIN_PASSWORD, PASSWORD_DEFAULT));
license_test_seed_cache($tenant, [
    'installation_id' => (string) $core['installation_id'], 'status' => 'active', 'plan' => 'sandbox',
    'entitlements' => ['studio-builder'], 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s'),
]);
$act = PluginLoader::installFromDisk('studio-builder');
if (empty($act['ok'])) {
    fwrite(STDERR, 'studio-builder install failed: ' . json_encode($act) . "\n");
    exit(1);
}
Media::ensureSchema();
file_put_contents(dirname(__DIR__, 4) . '/.installed', gmdate('c'));
echo "provisioned tenant {$tenant}\n";
