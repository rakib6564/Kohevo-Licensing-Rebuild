<?php
/** Rewrites the `expected` values in ui/tests/fixtures/live-style.json from the server's own renderer. Run from 01-client. */

declare(strict_types=1);

define('SLATE_TESTING', true);
define('SLATE_ROOT', dirname(__DIR__, 2));
require_once SLATE_ROOT . '/src/autoload.php';
require_once __DIR__ . '/live_style_fixture.php';

$data = json_decode((string) file_get_contents(LIVE_STYLE_FIXTURE), true, 512, JSON_THROW_ON_ERROR);
$data['tokens'] = live_style_tokens();
foreach ($data['cases'] as &$case) {
    $case['expected'] = live_style_actual($case['block']);
}
unset($case);
file_put_contents(LIVE_STYLE_FIXTURE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo count($data['cases']) . " cases written\n";
