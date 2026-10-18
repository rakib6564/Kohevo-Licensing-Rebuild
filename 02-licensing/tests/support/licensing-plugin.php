<?php
/**
 * Shared helpers for the integration tests that install and remove the
 * licensing plugin's own tables. They used to be defined inside
 * LicensingPluginSchemaTest.php (unguarded) and again inside
 * DynamicModuleSystemTest.php, while CentralLicensingFoundationTest.php
 * used them without defining them. So that file failed when run on its own,
 * and the single-process runner (tests/integration/run.php) died with
 * "Cannot redeclare licplug_teardown()". Every file that needs them now
 * requires this one.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/licensing/LicensingAPI.php';

if (!function_exists('licplug_teardown')) {
    /** Drop the licensing plugin's tables using its own uninstall.sql. */
    function licplug_teardown(): void
    {
        $file = dirname(__DIR__, 2) . '/plugins/licensing/uninstall.sql';
        $sql  = preg_replace('/^--.*$/m', '', (string) file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '') continue;
            Database::query($stmt);
        }
    }
}

if (!function_exists('licplug_ensure_schema')) {
    /**
     * ensureSchema() short-circuits after its first call per process
     * (LicensingAPI::$schemaChecked), which is right for production but
     * wrong across these tests: each one drops the tables in its teardown,
     * so the next call must really re-run the CREATE TABLEs.
     */
    function licplug_ensure_schema(): void
    {
        $prop = new ReflectionProperty(LicensingAPI::class, 'schemaChecked');
        $prop->setValue(null, false);
        LicensingAPI::ensureSchema();
    }
}
