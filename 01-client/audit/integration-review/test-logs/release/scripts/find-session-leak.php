<?php
[$_, $tree, $seed] = $argv;
chdir($tree);
require 'config.php'; require 'tests/guard.php'; slate_require_test_database(); require 'tests/unit/harness.php';
$files = glob('tests/integration/*Test.php'); mt_srand((int)$seed); shuffle($files);
$seen = false;
foreach ($files as $f) {
    if (str_contains($f, 'StripePermissionSplitTest')) { fwrite(STDERR, "reached Stripe; leak=" . ($seen ?: 'none') . "\n"); break; }
    require $f;
    if (!$seen && session_status() === PHP_SESSION_ACTIVE) {
        $n = (int) Database::value('SELECT COUNT(*) FROM admin_sessions WHERE session_hash = ? AND revoked_at IS NULL', [hash('sha256', session_id())]);
        if ($n > 0) { $seen = basename($f); fwrite(STDERR, "LEAK after $seen\n"); }
    }
}
