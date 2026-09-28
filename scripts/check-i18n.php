#!/usr/bin/env php
<?php
/**
 * Translation completeness check — keeps the French pack complete.
 *
 *   php scripts/check-i18n.php            # checks 01-client and 02-licensing
 *   php scripts/check-i18n.php 01-client  # one tree
 *
 * Fails (exit 1) when:
 *   1. a key used in code as  __('some_key', 'Fallback')  has no French entry, or
 *   2. a lang/en.php key (core or plugin) is missing from the sibling fr.php.
 *
 * Limits: only literal keys are seen — keys built at runtime (`__($prefix . $x)`) and strings that are
 * hard-coded without __() are not detected. Plugins without an en.php (English is the inline fallback)
 * are covered by check 1 only.
 */

declare(strict_types=1);

$root  = dirname(__DIR__);
$trees = array_slice($argv, 1) ?: ['01-client', '02-licensing'];
$skip  = '#/(vendor|tests|archive|Claude|lang|node_modules)/#';
$fail  = 0;

/** @return array<string,mixed> */
function load_dictionary(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = include $file;
    return is_array($data) ? $data : [];
}

foreach ($trees as $tree) {
    $base = "$root/$tree";
    if (!is_dir($base)) {
        fwrite(STDERR, "Unknown tree: $tree\n");
        exit(2);
    }

    $dicts = array_merge([$base . '/lang'], glob($base . '/plugins/*/lang', GLOB_ONLYDIR) ?: []);

    // Merged French dictionary (core + every plugin pack) — what a French request actually sees.
    $french = [];
    foreach ($dicts as $dir) {
        $french += load_dictionary("$dir/fr.php");
    }

    // 1. Keys used in code.
    $used = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (substr($path, -4) !== '.php' || preg_match($skip, $path)) {
            continue;
        }
        if (preg_match_all("/\b__\(\s*'([A-Za-z0-9_.\-]+)'\s*,/", (string) file_get_contents($path), $m)) {
            foreach ($m[1] as $key) {
                $used[$key] ??= substr($path, strlen($base) + 1);
            }
        }
    }
    $missingUsed = array_diff_key($used, $french);

    // 2. English keys without a French sibling.
    $missingPairs = [];
    foreach ($dicts as $dir) {
        $en = load_dictionary("$dir/en.php");
        if ($en === []) {
            continue;
        }
        $gap = array_diff_key($en, load_dictionary("$dir/fr.php"));
        if ($gap) {
            $missingPairs[substr($dir, strlen($base) + 1)] = array_keys($gap);
        }
    }

    printf("%-14s keys used in code: %4d   French entries: %4d   missing: %d   en→fr gaps: %d\n",
        $tree, count($used), count($french), count($missingUsed), array_sum(array_map('count', $missingPairs)));

    foreach (array_slice($missingUsed, 0, 40, true) as $key => $where) {
        echo "  no French entry for '$key' (used in $where)\n";
    }
    foreach ($missingPairs as $dir => $keys) {
        foreach (array_slice($keys, 0, 40) as $key) {
            echo "  $dir/fr.php is missing '$key'\n";
        }
    }
    $fail += count($missingUsed) + array_sum(array_map('count', $missingPairs));
}

echo $fail === 0 ? "OK — the French pack is complete.\n" : "FAILED — $fail missing French entr" . ($fail === 1 ? 'y' : 'ies') . ".\n";
exit($fail === 0 ? 0 : 1);
