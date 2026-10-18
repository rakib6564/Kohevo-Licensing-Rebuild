<?php
// usage: php db-snapshot.php <tree-dir> <out.json>   (run from repo root; read-only)
[$_, $tree, $out] = $argv;
chdir($tree);
require 'config.php';
require 'tests/guard.php';
slate_require_test_database();
$pdo = Database::get();
$tables = ['tenants','tenant_profiles','platform_plans','plan_entitlements','settings','installation_identity','remote_license_cache',
           'roles','role_permissions','users','plugins','platform_admins','studiobuilder_pages','studiobuilder_templates','studiobuilder_revisions'];
$snap = [];
foreach ($tables as $t) {
    $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn();
    if (!$exists) continue;
    $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
    $keyed = [];
    foreach ($rows as $r) {
        $k = isset($r['id']) ? 'id=' . $r['id'] : (isset($r['setting_key']) ? ($r['tenant_id'] ?? '') . ':' . $r['setting_key'] : md5(json_encode($r)));
        if ($t === 'settings') $k = ($r['tenant_id'] ?? '') . ':' . $r['setting_key'];
        $keyed[$k] = $r;
    }
    ksort($keyed);
    $snap[$t] = $keyed;
}
file_put_contents($out, json_encode($snap));
echo "snapshot " . count($snap) . " tables\n";
