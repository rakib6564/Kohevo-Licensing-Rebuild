<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
// require_once — see run-phase2-race.php's identical fix for why a plain
// require here can redeclare the LicensingAPI class.
require_once __DIR__ . '/../plugins/licensing/LicensingAPI.php';
$result = LicensingAPI::handleCheckIn([
    'product' => 'kohevo', 'license_key' => 'race-key',
    'install_id' => (string) ($argv[1] ?? ''), 'domain' => 'race.example',
], '203.0.113.77');
echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
