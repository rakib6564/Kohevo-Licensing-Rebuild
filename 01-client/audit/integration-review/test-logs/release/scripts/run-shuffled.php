<?php
// usage: php run-shuffled.php <tree-dir: 01-client|02-licensing> <seed>
// Same bootstrap as tests/integration/run.php, but the *Test.php files run in a seeded shuffled order.
[$_, $tree, $seed] = $argv;
chdir($tree);
require 'config.php';
require 'tests/guard.php';
slate_require_test_database();
require 'tests/unit/harness.php';
$files = glob('tests/integration/*Test.php');
mt_srand((int) $seed);
shuffle($files);
echo "# order seed {$seed}: " . implode(',', array_map('basename', array_slice($files, 0, 5))) . ",...\n";
foreach ($files as $f) require $f;
exit(unit_summary());
