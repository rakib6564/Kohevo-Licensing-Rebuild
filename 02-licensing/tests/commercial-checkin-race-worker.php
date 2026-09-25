<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
// require_once, not require — config.php's PluginLoader::boot() already
// requires plugins/licensing/Licensing.php (which itself requires
// LicensingAPI.php, LicenseService.php, InstallationService.php) whenever
// the Licensing plugin is active, so a plain require here would attempt to
// redeclare those classes and fail with a fatal error. See
// run-phase2-race.php's identical fix for the legacy-path worker.
require_once __DIR__ . '/../plugins/licensing/LicensingAPI.php';
require_once __DIR__ . '/../plugins/licensing/LicenseService.php';
require_once __DIR__ . '/../plugins/licensing/InstallationService.php';

$licenseKey = (string) ($argv[1] ?? '');
$installId  = (string) ($argv[2] ?? '');

$result = LicensingAPI::handleCheckIn([
    'product' => 'kohevo', 'license_key' => $licenseKey,
    'install_id' => $installId, 'domain' => 'race.commercial.example',
], '203.0.113.78');

echo (string) $result['http_status'];
