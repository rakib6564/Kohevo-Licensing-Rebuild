<?php
/**
 * Bring the test database's booking schema up to the shape a real install has.
 *
 * WHY THIS EXISTS
 *
 * There are three different "booking schemas", and only the third one is what
 * the running code actually writes to:
 *
 *   1. BookingAPI::ensureSchema()  — the original v0.1 tables, 13 columns on
 *      booking_services. Enough to boot, not enough to book.
 *   2. plugins/booking/install.sql — 30 columns on booking_services, but
 *      booking_appointments is still missing party_size, discount_cents,
 *      gift_applied_cents, stripe_session_id and others.
 *   3. install.sql + Booking::runMigrations() — the real thing. The missing
 *      columns exist ONLY as ensureColumn() calls in Booking.php (:428-:519);
 *      they are in no .sql file anywhere, so no amount of loading SQL produces
 *      them. A fresh activation runs install.sql and then this migration pass,
 *      which is why production works and a SQL-only test database does not.
 *
 * CI loaded install.sql alone (ci.yml), so any test that called
 * BookingAPI::createAppointment() died on "Unknown column 'discount_cents' in
 * INSERT INTO". This script closes that gap by running the plugin's own
 * migration, so the column list lives in exactly one place and cannot drift
 * from the code that writes to it.
 *
 * WHY REFLECTION, AND WHY NOT boot()
 *
 * runMigrations() is private, and its only caller is Booking::boot(). Calling
 * boot() would work but would also register booking's ~12 filters and actions
 * for the rest of the process — admin_nav_items, public_routes, frequent_cron
 * — which changes what every other suite in the run sees. (The same hazard as
 * activating small-business-kit in CI, which moves the render goldens.)
 * runMigrations() itself touches no hooks and writes no settings, so invoking
 * it directly provisions the schema and changes nothing else.
 *
 * Every statement is idempotent — CREATE TABLE IF NOT EXISTS, and ensureColumn/
 * ensureIndex both probe INFORMATION_SCHEMA first — so re-running is a no-op.
 *
 * Run after loading plugins/booking/install.sql. Exits non-zero if the schema
 * is still incomplete afterwards, so CI fails here with a clear message rather
 * than inside an unrelated test.
 */

declare(strict_types=1);

require __DIR__ . '/../../config.php';
require __DIR__ . '/../guard.php';
slate_require_test_database();

require_once dirname(__DIR__, 2) . '/plugins/booking/Booking.php';

$root     = dirname(__DIR__, 2) . '/plugins/booking';
$manifest = json_decode((string) file_get_contents($root . '/plugin.json'), true) ?: [];
$plugin   = new Booking('booking', $manifest, $root);

// '0.0.0' replays every version gate from the beginning, which is what a fresh
// activation does and what makes the result complete rather than partial.
$run = new ReflectionMethod(Booking::class, 'runMigrations');
$run->setAccessible(true);
$run->invoke($plugin, '0.0.0');

// The plugin's own definition of "fully migrated" — reused rather than
// restated, so this check cannot disagree with the one boot() makes.
$check = new ReflectionMethod(Booking::class, 'schemaIsCurrent');
$check->setAccessible(true);

if (!$check->invoke($plugin)) {
    fwrite(STDERR, "booking schema is still incomplete after runMigrations()\n");
    exit(1);
}

echo "booking schema migrated to " . ($manifest['version'] ?? '?') . "\n";
