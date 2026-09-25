<?php
/**
 * Phase 1D D1 — Booking admin screens accepted a category/location/service id
 * with no check that it belonged to the acting tenant.
 *
 * plugins/booking/admin/services.php stored `$_POST['category_id']` and
 * `$_POST['location_id']` directly after an (int) cast — no check that the
 * referenced booking_categories / booking_locations row belonged to the
 * current tenant, even though both tables are tenant-scoped and the page's
 * own dropdown only ever offers the current tenant's rows. Likewise
 * plugins/booking/admin/providers.php synced `$_POST['services'][]` into
 * booking_provider_services (a junction table with NO tenant_id column of
 * its own) with only an int cast, no ownership check on `service_id`.
 *
 * Read paths elsewhere already join back through the owning tenant-scoped
 * table, so this was data-hygiene rather than a live cross-tenant read —
 * but a tenant admin submitting a raw id belonging to another tenant's
 * catalog should never be able to link records across the tenant boundary
 * in the first place.
 *
 * Fix validates each submitted id against the current tenant before storing
 * it: services.php now checks category_id/location_id (falling back to
 * null, exactly as if the field had been left blank), and providers.php now
 * filters $_POST['services'] down to ids that actually belong to the tenant
 * before syncing booking_provider_services.
 *
 * Both tables have no tenant FK, so a synthetic foreign tenant id (no real
 * `tenants` row) is enough to prove isolation, matching the pattern already
 * used by NotificationsTenantIsolationTest.php.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/booking/BookingAPI.php';

$balt_tid     = current_tenant_id();
$balt_foreign = $balt_tid + slate_test_tenant(881122);

unit('booking services.php: a category/location id belonging to another tenant is rejected, not stored', function () use ($balt_tid, $balt_foreign): void {
    $slug = 'probe-' . bin2hex(random_bytes(4));
    $foreignCategoryId = Database::insert('booking_categories', [
        'tenant_id' => $balt_foreign, 'name' => 'Foreign category', 'slug' => $slug . '-cat',
    ]);
    $foreignLocationId = Database::insert('booking_locations', [
        'tenant_id' => $balt_foreign, 'name' => 'Foreign location', 'type' => 'in_person',
    ]);

    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
             . escapeshellarg('plugins/booking/admin/services.php') . ' '
             . escapeshellarg((string) json_encode([
                   '_action'     => 'save',
                   'name'        => 'Probe service ' . $slug,
                   'duration_min' => 30,
                   'payment_mode' => 'free',
                   'deposit_type' => 'percent',
                   'category_id' => $foreignCategoryId,
                   'location_id' => $foreignLocationId,
               ])) . ' '
             . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>&1';
        shell_exec($cmd);

        $saved = Database::row(
            "SELECT category_id, location_id FROM booking_services WHERE tenant_id = ? AND name = ?",
            [$balt_tid, 'Probe service ' . $slug]
        );
        assert_true($saved !== null, 'the service must still have been created (the save itself must not be blocked outright)');
        assert_eq(null, $saved['category_id'], 'a category id from another tenant must never be stored — must fall back to null, exactly like an empty field');
        assert_eq(null, $saved['location_id'], 'a location id from another tenant must never be stored — must fall back to null, exactly like an empty field');
    } finally {
        Database::query('DELETE FROM booking_services WHERE tenant_id = ? AND name = ?', [$balt_tid, 'Probe service ' . $slug]);
        Database::query('DELETE FROM booking_categories WHERE id = ?', [$foreignCategoryId]);
        Database::query('DELETE FROM booking_locations WHERE id = ?', [$foreignLocationId]);
    }
});

unit('booking services.php: a category/location id that DOES belong to this tenant still saves correctly (no regression)', function () use ($balt_tid): void {
    $slug = 'probe-' . bin2hex(random_bytes(4));
    $ownCategoryId = Database::insert('booking_categories', [
        'tenant_id' => $balt_tid, 'name' => 'Own category ' . $slug, 'slug' => $slug . '-owncat',
    ]);
    $ownLocationId = Database::insert('booking_locations', [
        'tenant_id' => $balt_tid, 'name' => 'Own location ' . $slug, 'type' => 'in_person',
    ]);

    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
             . escapeshellarg('plugins/booking/admin/services.php') . ' '
             . escapeshellarg((string) json_encode([
                   '_action'     => 'save',
                   'name'        => 'Probe own service ' . $slug,
                   'duration_min' => 30,
                   'payment_mode' => 'free',
                   'deposit_type' => 'percent',
                   'category_id' => $ownCategoryId,
                   'location_id' => $ownLocationId,
               ])) . ' '
             . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>&1';
        shell_exec($cmd);

        $saved = Database::row(
            "SELECT category_id, location_id FROM booking_services WHERE tenant_id = ? AND name = ?",
            [$balt_tid, 'Probe own service ' . $slug]
        );
        assert_true($saved !== null, 'the service must have been created');
        assert_eq($ownCategoryId, (int) $saved['category_id'], 'a category id that legitimately belongs to this tenant must still be stored');
        assert_eq($ownLocationId, (int) $saved['location_id'], 'a location id that legitimately belongs to this tenant must still be stored');
    } finally {
        Database::query('DELETE FROM booking_services WHERE tenant_id = ? AND name = ?', [$balt_tid, 'Probe own service ' . $slug]);
        Database::query('DELETE FROM booking_categories WHERE id = ?', [$ownCategoryId]);
        Database::query('DELETE FROM booking_locations WHERE id = ?', [$ownLocationId]);
    }
});

unit('booking providers.php: a service id belonging to another tenant is never linked via booking_provider_services', function () use ($balt_tid, $balt_foreign): void {
    $slug = 'probe-' . bin2hex(random_bytes(4));
    $foreignServiceId = Database::insert('booking_services', [
        'tenant_id' => $balt_foreign, 'name' => 'Foreign service', 'slug' => $slug . '-svc',
    ]);
    $ownServiceId = Database::insert('booking_services', [
        'tenant_id' => $balt_tid, 'name' => 'Own service ' . $slug, 'slug' => $slug . '-ownsvc',
    ]);
    $providerId = Database::insert('booking_providers', [
        'tenant_id' => $balt_tid, 'name' => 'Probe provider ' . $slug, 'timezone' => 'UTC',
    ]);

    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
             . escapeshellarg('plugins/booking/admin/providers.php') . ' '
             . escapeshellarg((string) json_encode([
                   '_action'  => 'save',
                   'id'       => $providerId,
                   'name'     => 'Probe provider ' . $slug,
                   'timezone' => 'UTC',
                   'services' => [$foreignServiceId, $ownServiceId],
               ])) . ' '
             . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>&1';
        shell_exec($cmd);

        $linked = array_map('intval', array_column(
            Database::rows('SELECT service_id FROM booking_provider_services WHERE provider_id = ?', [$providerId]),
            'service_id'
        ));

        assert_false(in_array($foreignServiceId, $linked, true), 'a service id from another tenant must never be linked to this provider');
        assert_true(in_array($ownServiceId, $linked, true), 'a service id that legitimately belongs to this tenant must still be linked (no regression)');
    } finally {
        Database::query('DELETE FROM booking_provider_services WHERE provider_id = ?', [$providerId]);
        Database::query('DELETE FROM booking_providers WHERE id = ?', [$providerId]);
        Database::query('DELETE FROM booking_services WHERE id IN (?, ?)', [$foreignServiceId, $ownServiceId]);
    }
});
