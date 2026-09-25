<?php
/**
 * CON-1 first slice — Booking owns its settings screen; Booking+ contributes.
 *
 * Before this slice there were two settings screens, and they fought over one
 * key. `booking.reminder_leads` was written by BOTH
 * booking/admin/settings.php (normalising to a default of '1440,60') and
 * booking-plus/admin/settings.php (defaulting to
 * BookingPlusAPI::defaultReminderLeads(), '11520,1440,10'), and
 * BookingPlus::seedReminderLeads() re-seeds it at boot when empty. Whichever
 * screen the operator saved last won, and the two screens showed different
 * defaults for the same stored value. On production the stored value is
 * '11520,1440,10' — Booking+ had won.
 *
 * The slice: Booking keeps the screen and the form; Booking+ stops owning one
 * and registers its own fields through a seam Booking owns. `reminder_leads`
 * goes back to having exactly one writer.
 *
 * Booking has since absorbed Booking+ outright, so the seam is now internal:
 * the fields and the cadence filter live in Booking's own copy of
 * BookingPlusAPI. The invariant these cases pin is unchanged and still worth
 * pinning — one settings screen, one writer for `reminder_leads`, and a
 * cadence that is a default rather than a stored write.
 *
 * These cases were RED before the change and the failure output is in the PR.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/booking/BookingAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/booking/BookingPlusAPI.php';

use Slate\Services\Auth\SessionRepository;
use Slate\Tenancy\TenantContext;

/**
 * Sign in as the fixture's admin, for real. Returns the undo.
 *
 * Auth::can() runs through Auth::check(), which validates the session against
 * an `admin_sessions` row (Auth.php:236) — so setting $_SESSION alone does not
 * authenticate anything, it just gets silently unset. The permission gate under
 * test is only exercised by a session the repository accepts.
 */
function bss_login(): callable
{
    $tid   = slate_tenant_id();
    $admin = Database::row("SELECT id FROM users WHERE tenant_id=? AND status='active' AND role_id > 0 LIMIT 1", [$tid]);
    if ($admin === null) throw new RuntimeException('no active admin in the test fixture');
    $userId = (int) $admin['id'];

    Auth::startSession();
    $prior = $_SESSION['slate_user'] ?? null;
    $_SESSION['slate_user'] = ['id' => $userId, 'tenant_id' => $tid, 'email' => 'seam@example.test', 'role_id' => 1];

    $repo = new SessionRepository(new TenantContext());
    $sid  = $repo->register($userId, session_id(), 'Seam test', slate_test_ip('192.0.2.77'), 'SlateTest/1');

    return static function () use ($prior, $sid, $tid): void {
        Database::query('DELETE FROM admin_sessions WHERE id=? AND tenant_id=?', [$sid, $tid]);
        if ($prior !== null) { $_SESSION['slate_user'] = $prior; } else { unset($_SESSION['slate_user']); }
    };
}

/** Files that call setSetting('booking.reminder_leads', …). */
function bss_reminder_lead_writers(): array
{
    $root = dirname(__DIR__, 2);
    $out  = [];
    foreach (glob($root . '/plugins/*/{,admin/,includes/}*.php', GLOB_BRACE) ?: [] as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match("/setSetting\(\s*'booking\.reminder_leads'/", $src)) {
            $out[] = substr($f, strlen($root) + 1);
        }
    }
    sort($out);
    return $out;
}

unit('booking.reminder_leads has exactly one writer', function (): void {
    $writers = bss_reminder_lead_writers();

    // Two screens writing one key is the defect. Booking owns the setting —
    // BookingAPI reads it at BookingAPI.php:1610 — so Booking owns the write.
    assert_eq(
        ['plugins/booking/admin/settings.php'],
        $writers,
        'only Booking writes booking.reminder_leads; found: ' . implode(', ', $writers)
    );
});

unit('exactly one settings nav row exists for booking', function (): void {
    // The duplication CON-1 removed would now reappear inside Booking itself
    // rather than in a second plugin, so this reads Booking's own nav builder.
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/booking/Booking.php');
    $nav = strstr($src, 'function addAdminNav');
    $end = strpos((string) $nav, "\n    }");
    if ($end !== false) $nav = substr((string) $nav, 0, $end);

    assert_eq(
        1,
        substr_count((string) $nav, "'booking-settings'"),
        'one and only one settings nav row'
    );
    assert_true(
        !str_contains((string) $nav, 'bookingplus-settings'),
        'no leftover Booking+ Settings nav row'
    );
});

/** Snapshot the two bookingplus keys and hand back the exact undo. */
function bss_snapshot(): callable
{
    $tid  = slate_tenant_id();
    $keys = ['bookingplus.whatsapp_url', 'bookingplus.nudge_hours'];
    $prior = [];
    foreach ($keys as $k) {
        $prior[$k] = Database::value(
            'SELECT setting_value FROM settings WHERE ' . slate_tenant_clause() . ' AND setting_key = ?',
            [$tid, $k]
        );
    }
    return static function () use ($keys, $prior, $tid): void {
        foreach ($keys as $k) {
            if ($prior[$k] === null || $prior[$k] === false) {
                Database::delete('settings', slate_tenant_clause() . ' AND setting_key = ?', [$tid, $k]);
            } else {
                Database::setSetting($k, (string) $prior[$k], $tid);
            }
        }
    };
}

unit('the seam refuses to write for a caller without the permission', function (): void {
    $restore = bss_snapshot();
    $priorSession = $_SESSION['slate_user'] ?? null;
    unset($_SESSION['slate_user']);

    try {
        Database::setSetting('bookingplus.whatsapp_url', 'https://wa.me/UNTOUCHED');

        BookingPlusAPI::saveSettings(['bookingplus_whatsapp_url' => 'https://wa.me/INTRUDER']);

        // Booking fires booking_settings_save for whoever posted its form, and
        // Booking cannot know what Booking+'s permission is called. So the
        // check has to live in the listener — if it did not, contributing a
        // card would quietly widen who can change these.
        assert_eq(
            'https://wa.me/UNTOUCHED',
            (string) Database::setting('bookingplus.whatsapp_url'),
            'no permission, no write'
        );
    } finally {
        if ($priorSession !== null) $_SESSION['slate_user'] = $priorSession;
        $restore();
    }
});

unit('the absorbed Booking+ fields save through the settings seam', function (): void {
    $restore = bss_snapshot();
    $logout = bss_login();

    try {
        BookingPlusAPI::saveSettings([
            'bookingplus_whatsapp_url' => 'https://wa.me/000000',
            'bookingplus_nudge_hours'  => '13',
            // Must be ignored: reminder_leads is Booking's to write now.
            'reminder_leads'           => '1,2,3',
        ]);

        assert_eq('https://wa.me/000000', (string) Database::setting('bookingplus.whatsapp_url'), 'whatsapp_url saved');
        assert_eq('13', (string) Database::setting('bookingplus.nudge_hours'), 'nudge_hours saved');
    } finally {
        $logout();
        $restore();
    }
});

unit('the seam does not let a contributor write Booking\'s reminder_leads', function (): void {
    $tid = slate_tenant_id();

    // A sentinel this test owns, snapshotted and restored here rather than
    // inherited from whatever ran before. An earlier version compared the key
    // to a value read at the start of THIS case — which an earlier case had
    // already corrupted, so a contributor writing a constant compared equal to
    // itself and the case passed while the defect was present. Order
    // dependence made it blind; the sentinel makes it not.
    $sentinel = 'SEAM-' . bin2hex(random_bytes(3));
    $prior = Database::value(
        'SELECT setting_value FROM settings WHERE ' . slate_tenant_clause() . ' AND setting_key = ?',
        [$tid, 'booking.reminder_leads']
    );
    Database::setSetting('booking.reminder_leads', $sentinel, $tid);

    $logout  = bss_login();
    $restore = bss_snapshot();

    try {
        BookingPlusAPI::saveSettings(['reminder_leads' => '1,2,3', 'booking.reminder_leads' => '1,2,3']);

        // The whole point of the slice. Two writers meant last-save-wins
        // between two screens showing different defaults for one stored value.
        assert_eq($sentinel, (string) Database::setting('booking.reminder_leads'),
            "Booking's setting is untouched by a contributor");
    } finally {
        $logout();
        $restore();
        if ($prior === null || $prior === false) {
            Database::delete('settings', slate_tenant_clause() . ' AND setting_key = ?', [$tid, 'booking.reminder_leads']);
        } else {
            Database::setSetting('booking.reminder_leads', (string) $prior, $tid);
        }
    }
});

unit('the absorbed cadence is still a DEFAULT, never a stored write', function (): void {
    // Booking+ used to force its cadence by writing Booking's setting at boot.
    // It now returns it from a filter Booking applies, so the value is only
    // ever a default — an operator's stored choice always wins.
    $filtered = (string) Hook::applyFilters('booking_default_reminder_leads', '1440,60');
    assert_true(
        in_array($filtered, ['1440,60', BookingPlusAPI::defaultReminderLeads()], true),
        'the default is either core\'s or Booking+\'s, never a written value: ' . $filtered
    );
});

