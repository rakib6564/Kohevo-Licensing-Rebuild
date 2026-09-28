<?php
/**
 * The pure parts of the automatic license sync (includes/license_sync.php):
 * when a check-in is due, the interval clamp, and that the wiring sits where
 * it has to. The full round trip against the Central Server is in
 * tests/integration/Phase10ZAutoRefreshTest.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/license_sync.php';

unit('license sync: due when nothing was ever verified, or the copy is older than the interval', function () {
    $now = 1_000_000;
    assert_true(slate_license_sync_is_due(null, null, $now, 900));
    assert_true(slate_license_sync_is_due($now - 900, null, $now, 900), 'exactly one interval old is due');
    assert_true(slate_license_sync_is_due($now - 5000, null, $now, 900));
    assert_false(slate_license_sync_is_due($now - 899, null, $now, 900), 'fresh copy is not due');
});

unit('license sync: a recent attempt — successful or not — holds the next one off for the retry window only', function () {
    $now = 1_000_000;
    assert_false(slate_license_sync_is_due($now - 5000, $now - 30, $now, 900), 'just tried, do not hammer a struggling server');
    assert_false(slate_license_sync_is_due(null, $now - 299, $now, 900));
    assert_true(slate_license_sync_is_due($now - 5000, $now - 300, $now, 900), 'after the retry window it may try again');
    assert_true(slate_license_sync_is_due($now - 5000, $now - 10_000, $now, 900));
});

unit('license sync: a verified copy timestamped in the future is treated as fresh, not as due', function () {
    $now = 1_000_000;
    assert_false(slate_license_sync_is_due($now + 500, null, $now, 900));
});

unit('license sync: interval defaults to 15 minutes and is clamped to 5 minutes .. 24 hours', function () {
    assert_eq(900, slate_license_sync_interval(''));
    assert_eq(900, slate_license_sync_interval('not-a-number'));
    assert_eq(300, slate_license_sync_interval('10'));
    assert_eq(86400, slate_license_sync_interval('999999'));
    assert_eq(1800, slate_license_sync_interval('1800'));
});

unit('license sync: config.php registers the sync before the guard, and the License page button is CSRF- and permission-gated', function () {
    $config = (string) file_get_contents(dirname(__DIR__, 2) . '/config.php');
    $sync   = strpos($config, 'slate_license_sync_schedule();');
    $guard  = strpos($config, 'slate_license_guard();');
    assert_true($sync !== false && $guard !== false && $sync < $guard, 'sync must be registered before the guard, so a locked install can still sync itself back');

    $page = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/license.php');
    $csrf = strpos($page, 'csrf_verify()');
    $perm = strpos($page, '!$canManageLicense');
    $act  = strpos($page, "'_action'] ?? '') === 'sync'");
    assert_true($csrf !== false && $perm !== false && $act !== false && $csrf < $perm && $perm < $act, 'the sync branch sits behind CSRF and settings.edit');
    assert_true(str_contains($page, 'slate_license_sync_run(true)'), 'the button forces a check');
});
