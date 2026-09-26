<?php
/**
 * Phase 11: render the Booking / Forms / Membership content blocks in a
 * CHILD process (see phase11-surface-probe.php for why a child) and report
 * how many bytes each produced.
 *
 * Prints one line per module:  <module> <bytes>   (-1 if rendering threw)
 *
 * Usage: php tests/fixtures/phase11-content-block-probe.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (getenv('SLATE_LICENSE_GUARD_LIVE') !== '1') {
    define('SLATE_TESTING', true);
}

// A content block only ever renders inside a page request; without this the
// Module Guard's CLI-without-REQUEST_METHOD bypass would mask its decision.
// public.php is a guarded page, which is where content pages render.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SCRIPT_NAME']    = '/public.php';

$root = dirname(__DIR__, 2);
require $root . '/config.php';
require_once $root . '/plugins/booking/BookingAPI.php';
require_once $root . '/plugins/forms/FormsAPI.php';
require_once $root . '/plugins/membership/MembershipAPI.php';

foreach ([
    'booking'    => static fn (): string => BookingAPI::renderContentBlock([]),
    'forms'      => static fn (): string => FormsAPI::renderContentBlock([]),
    'membership' => static fn (): string => MembershipAPI::renderContentBlock([]),
] as $module => $render) {
    try {
        $html = $render();
    } catch (\Throwable $e) {
        fwrite(STDERR, $module . ': ' . $e->getMessage() . "\n");
        $html = null;
    }
    fwrite(STDOUT, $module . ' ' . ($html === null ? -1 : strlen($html)) . "\n");
}
