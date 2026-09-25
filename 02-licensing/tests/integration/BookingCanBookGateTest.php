<?php
/**
 * `booking_can_book` — the gate Booking+ books through, pinned.
 *
 * CON-1 will move code between Booking and Booking+. This filter is the
 * contract consolidation must not break: BookingAPI::createAppointment() asks
 * listeners for permission before it writes, and a listener returning
 * ['ok' => false, 'error' => …] must stop the booking AND leave no row behind.
 *
 * Two of Booking+'s nine capabilities ride this exact filter — min_advance_days
 * and the prerequisite gate (survey §3). Delete the applyFilters at
 * BookingAPI.php:500 during a refactor and both silently stop enforcing:
 * bookings they should refuse get accepted, no error, no log. Nothing else in
 * the suite notices, which is precisely why this test exists.
 *
 * AND IT IS NOT ONLY BOOKING+. membership registers on the same filter at
 * Membership.php:52, priority 10 — ahead of Booking+'s 20 — and refuses any
 * booking without a signed-in member. So `booking_can_book` has a consumer
 * outside the two plugins CON-1 is consolidating, and deleting or moving the
 * applyFilters silently disables membership's booking rules too. Survey §3 is
 * incomplete on this point; it is logged as a follow-up.
 *
 * PROVEN FALLIBLE before being trusted green, per solaya-slate guardrail 4.
 * Deleting that one applyFilters call and running this file gives:
 *
 *     ok   - with no listener, an online booking succeeds
 *     FAIL - a listener returning ok=false refuses the booking … (the booking is refused)
 *     ok   - the gate does not apply to admin bookings
 *
 * Exactly one case red, and it is the one that asserts the gate is consulted —
 * the other two do not depend on the gate existing, so their staying green is
 * what makes the failure diagnostic instead of noise. A test written after the
 * code it guards and never seen to fail asserts nothing.
 *
 * The gate applies only to source === 'online' (BookingAPI.php:499). Admin and
 * walk-in bypass it by design so staff can always take a booking the public
 * rules would refuse — the third case pins that, so a future "just gate
 * everything" change has to be a deliberate decision rather than a quiet one.
 */

declare(strict_types=1);

// Require the class directly rather than relying on the plugin being active:
// activation runs boot filters that would change what every other suite in this
// directory sees.
//
// Schema is the harness's job, not the test's: ci.yml loads
// plugins/booking/install.sql and then runs tests/fixtures/booking-schema.php,
// which applies the ensureColumn() top-ups that exist in no .sql file. If this
// test dies on "Unknown column 'discount_cents'", that second step did not run
// — the fixture's header explains why it is needed.
require_once dirname(__DIR__, 2) . '/plugins/booking/BookingAPI.php';

/**
 * Seed a bookable service + provider. Returns [serviceId, providerId].
 *
 * Uniquely tagged so a parallel run cannot collide, tenant-scoped throughout,
 * and dropped by bcbg_cleanup() in a finally block.
 */
function bcbg_seed(string $tag): array
{
    $tid = slate_tenant_id();

    $serviceId = (int) Database::insert('booking_services', [
        'tenant_id'    => $tid,
        'name'         => "BCBG {$tag}",
        'slug'         => "bcbg-{$tag}",
        'duration_min' => 30,
        'price_cents'  => 0,
        'payment_mode' => 'free',
        'capacity'     => 1,
        'is_active'    => 1,
    ]);

    $providerId = (int) Database::insert('booking_providers', [
        'tenant_id' => $tid,
        'name'      => "BCBG {$tag} provider",
        'email'     => "bcbg-{$tag}@example.invalid",
        'is_active' => 1,
    ]);

    Database::insert('booking_provider_services', [
        'provider_id' => $providerId,
        'service_id'  => $serviceId,
    ]);

    // Open every weekday, all day, so the chosen slot is inside working hours
    // no matter when the suite runs. The hours check sits after the gate, but
    // the success case has to traverse the whole path, not just reach it.
    for ($dow = 0; $dow <= 6; $dow++) {
        Database::insert('booking_provider_hours', [
            'tenant_id'   => $tid,
            'provider_id' => $providerId,
            'day_of_week' => $dow,
            'start_time'  => '00:00:00',
            'end_time'    => '23:59:00',
        ]);
    }

    return [$serviceId, $providerId];
}

function bcbg_cleanup(int $serviceId, int $providerId): void
{
    foreach ([
        ['booking_appointments',      'service_id = ?',  $serviceId],
        ['booking_provider_hours',    'provider_id = ?', $providerId],
        ['booking_provider_services', 'provider_id = ?', $providerId],
        ['booking_providers',         'id = ?',          $providerId],
        ['booking_services',          'id = ?',          $serviceId],
    ] as [$table, $where, $id]) {
        try { Database::delete($table, $where, [$id]); } catch (\Throwable $e) { /* best effort */ }
    }
}

/** Appointment count for a service — backs the "nothing was written" assertion. */
function bcbg_rows(int $serviceId): int
{
    return (int) Database::value(
        'SELECT COUNT(*) FROM booking_appointments WHERE ' . slate_tenant_clause() . ' AND service_id = ?',
        [slate_tenant_id(), $serviceId]
    );
}

/** A start time safely in the future — the gate sits behind a past-time check. */
function bcbg_future(): string
{
    return date('Y-m-d H:i', strtotime(slate_db_now() . ' +3 days'));
}

/** The args every case shares; $source is what varies. */
function bcbg_args(int $svc, int $prov, string $source): array
{
    return [
        'service_id'     => $svc,
        'provider_id'    => $prov,
        'starts_at'      => bcbg_future(),
        'customer_name'  => 'Gate Test',
        'customer_email' => 'gate-test@example.invalid',
        'source'         => $source,
    ];
}

/**
 * Turn off membership's booking rules for the duration, and restore exactly.
 *
 * THIS IS A PRECONDITION, NOT A WORKAROUND. membership registers on
 * `booking_can_book` at Membership.php:52 (priority 10, ahead of Booking+'s
 * 20) and blocks anything with no signed-in member — Membership.php:162,
 * `(($requireMembership || $requireProfile) && $cid <= 0)`. These cases book as
 * an anonymous customer, so with membership active and its defaults in force
 * the answer is "Please sign in as a member to book." before Booking's own gate
 * decision is ever reached.
 *
 * That is correct behaviour, and the test has to say which world it is testing
 * rather than inherit one. Leaving it unstated is exactly why this passed
 * locally, where only content-builder is active, and failed in CI, where
 * membership is: the outcome depended on a setting neither environment
 * declared.
 *
 * Note the defaults are ON — Membership.php:155-156 reads `!== '0'`, so an
 * ABSENT row means required. Restoring therefore has to delete a row that was
 * not there before, not write '0' back; setSetting() alone would leave the
 * database in a state neither this test nor CI's fixture chose.
 */
function bcbg_membership_rules_off(): callable
{
    $tid  = slate_tenant_id();
    $keys = ['membership.require_membership_to_book', 'membership.require_profile_to_book'];

    $prior = [];
    foreach ($keys as $k) {
        $prior[$k] = Database::value(
            'SELECT setting_value FROM settings WHERE ' . slate_tenant_clause() . ' AND setting_key = ?',
            [$tid, $k]
        );
        Database::setSetting($k, '0', $tid);
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

/** A listener that always refuses, with a distinctive message. */
function bcbg_deny(): callable
{
    return static fn ($gate, $ctx = null): array => ['ok' => false, 'error' => 'nope'];
}

/**
 * Register a listener and hand back the undo.
 *
 * Deliberately removeFilter() and not Hook::reset(). The integration runner
 * boots the whole app in one process and then requires every suite into it, so
 * a reset here would silently strip the filters the other twenty-five files
 * depend on — and it would do it invisibly, as a later failure somewhere else.
 * Removing exactly what this test added leaves global state as it was found.
 */
function bcbg_listen(callable $fn): callable
{
    Hook::addFilter('booking_can_book', $fn, 10, 2);
    return static function () use ($fn): void {
        Hook::removeFilter('booking_can_book', $fn, 10);
    };
}

unit('booking_can_book: with no listener, an online booking succeeds', function (): void {
    [$svc, $prov] = bcbg_seed('allow' . bin2hex(random_bytes(3)));

    $restore = bcbg_membership_rules_off();

    try {
        $before = bcbg_rows($svc);
        $res    = BookingAPI::createAppointment(bcbg_args($svc, $prov, 'online'));

        // A failure here means the seed or a precondition is wrong, not the
        // gate — the error text is included so that is obvious rather than a
        // puzzle. "Please sign in as a member to book." means membership's
        // listener spoke first and bcbg_membership_rules_off() did not hold.
        assert_true(!empty($res['ok']), 'booking succeeds with no refusing listener: ' . ($res['error'] ?? ''));
        assert_eq($before + 1, bcbg_rows($svc), 'exactly one row was written');
    } finally {
        $restore();
        bcbg_cleanup($svc, $prov);
    }
});

unit('booking_can_book: a listener returning ok=false refuses the booking and writes nothing', function (): void {
    [$svc, $prov] = bcbg_seed('deny' . bin2hex(random_bytes(3)));

    $restore = bcbg_membership_rules_off();

    try {
        // With membership's rules off, the only listener that can refuse is
        // this one — so 'nope' arriving proves the gate carried THIS decision,
        // not somebody else's.
        $unhook = bcbg_listen(bcbg_deny());

        $before = bcbg_rows($svc);
        $res    = BookingAPI::createAppointment(bcbg_args($svc, $prov, 'online'));

        assert_true(empty($res['ok']), 'the booking is refused');

        // The listener's own message must survive rather than be replaced by a
        // generic one: Booking+ shows these strings to the client verbatim, so
        // "nope" reaching the caller is part of the contract, not decoration.
        assert_eq('nope', $res['error'] ?? null, "the listener's error text is returned");

        // The assertion that matters most. The gate sits before the insert, so
        // a row appearing here means the check has moved and a refusal now
        // half-writes.
        assert_eq($before, bcbg_rows($svc), 'no appointment row was written');
    } finally {
        if (isset($unhook)) $unhook();
        $restore();
        bcbg_cleanup($svc, $prov);
    }
});

unit('booking_can_book: the gate does not apply to admin bookings', function (): void {
    [$svc, $prov] = bcbg_seed('admin' . bin2hex(random_bytes(3)));

    try {
        $unhook = bcbg_listen(bcbg_deny());

        // BookingAPI.php:499 restricts the gate to source === 'online'. Pinned
        // so that staying true is a choice someone makes, not something a
        // refactor decides by accident.
        $before = bcbg_rows($svc);
        $res    = BookingAPI::createAppointment(bcbg_args($svc, $prov, 'admin'));

        assert_true(!empty($res['ok']), 'an admin booking bypasses the gate: ' . ($res['error'] ?? ''));

        // ok alone is not the claim. "Bypasses the gate" means the booking is
        // actually taken, so a change that returned ok while writing nothing
        // would otherwise pass this case unnoticed.
        assert_eq($before + 1, bcbg_rows($svc), 'the admin booking was actually written');
    } finally {
        if (isset($unhook)) $unhook();
        bcbg_cleanup($svc, $prov);
    }
});
