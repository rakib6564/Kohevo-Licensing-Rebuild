<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
// require_once, not require — config.php's PluginLoader::boot() already
// requires plugins/licensing/Licensing.php (which itself requires
// LicensingAPI.php) whenever the Licensing plugin is active, so a plain
// require here would attempt to redeclare the LicensingAPI class and fail
// with a fatal error.
require_once __DIR__ . '/../plugins/licensing/LicensingAPI.php';
LicensingAPI::ensureSchema();
LicensingAPI::storeSigningKeypair(LicensingAPI::generateSigningKeypair());
foreach (['licensing_installation_bindings','licensing_checkins','licensing_installs','licensing_plans','licensing_clients','licensing_products'] as $table) Database::query("DELETE FROM `$table`");
$productId = Database::insert('licensing_products', ['slug'=>'kohevo','name'=>'Kohevo']);
$clientId = Database::insert('licensing_clients', ['name'=>'Race Client']);
$installId = Database::insert('licensing_installs', ['client_id'=>$clientId,'product_id'=>$productId,'label'=>'Race','domain'=>'race.example','domain_normalized'=>'race.example','license_key_hash'=>hash('sha256','race-key'),'status'=>'active','activation_limit'=>1]);
$base = __DIR__ . '/phase2-race-worker.php';
$procs = [];
// QA Fix Round 1 (Phase 4, Fix 2): install_id is now format-validated
// (lowercase hex, exactly 32 chars) before handleCheckIn() does anything
// else, so these must be well-formed to exercise the actual race path
// rather than being rejected at the very first validation step.
foreach ([str_repeat('a', 32), str_repeat('b', 32)] as $identity) {
    $pipes = [];
    $procs[] = [proc_open(PHP_BINARY . ' ' . escapeshellarg($base) . ' ' . escapeshellarg($identity), [1=>['pipe','w'],2=>['pipe','w']], $pipes), $pipes];
}
$results = [];
foreach ($procs as [$proc,$pipes]) { $results[] = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc); }
$count = (int) Database::value('SELECT activation_count FROM licensing_installs WHERE id = ?', [$installId]);
$bound = (int) Database::value('SELECT COUNT(*) FROM licensing_installation_bindings WHERE install_id = ?', [$installId]);
if ($count !== 1 || $bound !== 1) { fwrite(STDERR, "race failed: activation_count=$count bindings=$bound\n"); exit(1); }
echo "PASS\nconcurrent_results=" . count($results) . "\nactivation_count=$count\nbindings=$bound\n";
