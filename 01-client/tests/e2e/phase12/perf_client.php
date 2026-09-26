<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
admin_login();
function q(): int { return (int) client_db()->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1]; }
foreach (['/admin/' => 'admin dashboard', '/plugins/forms/admin/index.php' => 'forms admin', '/book' => 'public /book'] as $path => $label) {
    http('GET', $path, [], 'jar-admin.txt');
    $b = q(); $t = microtime(true);
    for ($i = 0; $i < 50; $i++) $r = http('GET', $path, [], 'jar-admin.txt');
    printf("%-18s status %d  %.1f ms avg  %.0f DB statements/request\n", $label, $r['status'], (microtime(true) - $t) * 20, (q() - $b - 50) / 50);
}
echo 'readTrustState() cost: ' . client_php('$s=new \Slate\Services\Licensing\SlateLicenseCacheStore((int)TENANT_ID); $t=microtime(true); for($i=0;$i<200;$i++){$s->readTrustState();} printf("%.3f ms/call", (microtime(true)-$t)*1000/200);') . "\n";
