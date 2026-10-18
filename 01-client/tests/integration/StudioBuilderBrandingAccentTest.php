<?php
/**
 * Kohevo Studio — saving Settings › Branding untouched (gap report DT-M6).
 *
 * The Branding form's untouched accent is #111111, and saving the form stores it. The admin theme
 * treats that value as "no accent chosen"; Studio used to apply it, turning its accent near-black after
 * an unrelated Branding save. TenantBranding::resolve() now reports it as unset for every reader.
 * Real MySQL (settings table), tenant 101.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!function_exists('unit')) {
    require_once dirname(__DIR__, 2) . '/config.php';
    require_once dirname(__DIR__) . '/guard.php';
    slate_require_test_database();
    require_once dirname(__DIR__) . '/unit/harness.php';
    $studioBrandingAccentIntStandalone = true;
}

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Services\Content\TenantBranding;
use Slate\Tenancy\TenantContext;

const SBBA_TENANT = 101;

unit('branding: an untouched Branding save (#111111) leaves Studio\'s accent alone; a chosen accent still applies (DT-M6)', function (): void {
    $dbName = 'slate_sbba_' . slate_test_ns();
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $server = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ';charset=' . DB_CHARSET;
    $root = new \PDO($server, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $property = new \ReflectionProperty(Database::class, 'pdo');
    $previous = $property->getValue();
    try {
        $pdo = new \PDO('mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '') . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate(['0001_core_init']);
        $property->setValue(null, $pdo);
        $tenants = new TenantContext();
        $accentFor = static function (string $stored) use ($tenants): array {
            return $tenants->runAs(SBBA_TENANT, static function () use ($stored): array {
                Database::setSetting('brand_accent_color', $stored);
                return [TenantBranding::resolve()['accent'], (new ThemeResolver())->resolve()->tokens()['color.accent']];
            });
        };

        [$brand, $studio] = $accentFor('#111111');
        assert_eq('', $brand, 'the untouched default reads as unset');
        assert_eq(ThemeResolver::DEFAULT_TOKENS['color.accent'], $studio, 'Studio keeps its default accent');
        [$brand] = $accentFor('#111');
        assert_eq('#111', $brand, 'only the exact default is treated as unset');
        [$brand, $studio] = $accentFor(' #111111 ');
        assert_eq('', $brand, 'also with stray spaces');
        [$brand, $studio] = $accentFor('#E4572E');
        assert_eq('#E4572E', $brand, 'a chosen accent is reported');
        assert_eq('#E4572E', $studio, 'and reaches Studio');
    } finally {
        $property->setValue(null, $previous);
        $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }
});

if (!empty($studioBrandingAccentIntStandalone)) {
    exit(unit_summary());
}
