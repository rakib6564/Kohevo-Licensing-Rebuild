<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — live style parity.
 *
 * Autoloader only, no database.
 *
 * The editor predicts a block's style on the canvas in `core/liveStyle.mjs` so an edit shows at once. Both sides read
 * `ui/tests/fixtures/live-style.json`: this test proves the `expected` values in it are what the server's renderer really
 * writes (regenerate with `php tests/support/regen-live-style.php`), and `ui/tests/live-style.test.mjs` proves the
 * client reproduces them. A change to the server's style output that the client does not follow fails here first.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}
require_once SLATE_ROOT . '/tests/support/live_style_fixture.php';

unit('live style parity: the fixture holds exactly what the server renderer writes', function (): void {
    $data = json_decode((string) file_get_contents(LIVE_STYLE_FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    assert_true(count($data['cases']) >= 40, 'the fixture has cases');
    assert_eq(live_style_tokens(), $data['tokens'], 'the theme tokens are those of the default theme');
    foreach ($data['cases'] as $case) {
        assert_eq(
            json_encode($case['expected']),
            json_encode(live_style_actual($case['block'])),
            'case "' . $case['name'] . '" drifted from the server: run php tests/support/regen-live-style.php and update core/liveStyle.mjs to match'
        );
    }
});
