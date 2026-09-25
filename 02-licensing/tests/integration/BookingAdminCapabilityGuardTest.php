<?php
/**
 * Booking admin pages must go away when the capability they configure is off.
 *
 * service_rules, slot_restrictions and client_messaging all correctly gate the
 * BEHAVIOR they enable — Booking::boot() only registers gateBookingRules(),
 * applySlotRestriction() and the messaging hooks when the capability is on
 * (Booking.php:68-81) — but the admin pages that CONFIGURE each of those
 * capabilities never checked the capability at all, only the generic
 * `booking.manage_services` / `booking.view` permission. Any staff member
 * with that permission could view and edit per-service rules, reserved slot
 * windows, or the messages inbox via a direct URL regardless of whether the
 * practice owner had turned that capability off in Settings — UI hiding
 * (the nav item disappears) was standing in for enforcement.
 *
 * client_messaging's PUBLIC side (plugins/booking/public/message.php) already
 * had this exact guard — see BookingMessagePublicGuardTest.php, the model
 * this file follows. This is the same fix applied to the admin side, for all
 * three capabilities that have a dedicated admin surface (custom_reminders
 * has no separate admin page beyond the Settings toggle itself, which is
 * already permission-gated correctly, so there is nothing to pin for it
 * here).
 *
 * GET-only probes, run in a CHILD process authenticated as a super-admin
 * (tests/fixtures/admin-page-probe.php) — see that file for why.
 */

declare(strict_types=1);

/** Run an admin page in a child process, logged in as super-admin. Returns [status, body]. */
function bacg_probe(string $page, string $query = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($query) . ' 2>/dev/null';

    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) return [0, $out];

    return [(int) $m[1], substr($out, strlen($m[0]))];
}

function bacg_capability_on(string $cap): bool
{
    return PluginLoader::isCapabilityEnabled('booking', $cap);
}

/** Turn a Booking capability off for the duration; hand back the exact undo. */
function bacg_disable_capability(string $cap): callable
{
    $key   = 'booking.cap.' . $cap;
    $prior = Database::row('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?',
                           [current_tenant_id(), $key]);

    Database::query(
        "INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?, ?, '0')
         ON DUPLICATE KEY UPDATE setting_value = '0'",
        [current_tenant_id(), $key]
    );

    return static function () use ($prior, $key): void {
        if ($prior === null) {
            Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?',
                            [current_tenant_id(), $key]);
        } else {
            Database::query('UPDATE settings SET setting_value = ? WHERE tenant_id = ? AND setting_key = ?',
                            [(string) $prior['setting_value'], current_tenant_id(), $key]);
        }
    };
}

// Each page's own <h1> — a reliable "the real page rendered" marker
// regardless of whether the test DB happens to have any rows for it.
$bacgPages = [
    'service_rules'     => ['plugins/booking/admin/service-rules.php', 'Booking+ Services'],
    'slot_restrictions' => ['plugins/booking/admin/restrictions.php',  'Reserved Slots'],
    'client_messaging'  => ['plugins/booking/admin/messages.php',      'Booking Messages'],
];

foreach ($bacgPages as $cap => [$page, $marker]) {
    unit("booking admin: $page serves while $cap is enabled", function () use ($cap, $page, $marker): void {
        assert_true(bacg_capability_on($cap), "precondition: booking $cap is enabled (manifest default)");

        [$status, $body] = bacg_probe($page);

        assert_eq(200, $status, 'an enabled capability lets the admin page render');
        assert_true(str_contains($body, $marker), 'the real page content is rendered');
    });

    unit("booking admin: $page refuses with 503 while $cap is disabled", function () use ($cap, $page, $marker): void {
        $restore = bacg_disable_capability($cap);

        try {
            [$status, $body] = bacg_probe($page);

            assert_eq(503, $status, 'a disabled capability must refuse the admin page too, not just hide its nav link');
            assert_true(!str_contains($body, $marker), 'no configuration UI is rendered');
        } finally {
            $restore();
        }
    });
}

// service-rule.php (singular) is the actual WRITE endpoint service-rules.php
// links to ("Edit extras") — it needs a real service row to render its form
// rather than redirecting, so it gets its own setup instead of sharing the
// loop above.
unit('booking admin: service-rule.php refuses with 503 while service_rules is disabled', function (): void {
    $tid = current_tenant_id();
    $serviceId = (int) Database::insert('booking_services', [
        'tenant_id' => $tid,
        'name'      => 'Capability Guard Probe Service',
        'slug'      => 'capability-guard-probe-' . bin2hex(random_bytes(4)),
    ]);

    try {
        assert_true(bacg_capability_on('service_rules'), 'precondition: booking service_rules is enabled (manifest default)');

        [$onStatus, $onBody] = bacg_probe('plugins/booking/admin/service-rule.php', 'id=' . $serviceId);
        assert_eq(200, $onStatus, 'an enabled capability lets the edit form render');
        assert_true(str_contains($onBody, 'name="min_advance_days"'), 'the real edit form is rendered');

        $restore = bacg_disable_capability('service_rules');
        try {
            [$offStatus, $offBody] = bacg_probe('plugins/booking/admin/service-rule.php', 'id=' . $serviceId);
            assert_eq(503, $offStatus, 'a disabled capability must refuse the edit form too');
            assert_true(!str_contains($offBody, 'name="min_advance_days"'), 'no edit form is rendered');
        } finally {
            $restore();
        }
    } finally {
        Database::query('DELETE FROM bookingplus_service_config WHERE service_id = ?', [$serviceId]);
        Database::query('DELETE FROM booking_services WHERE id = ?', [$serviceId]);
    }
});
