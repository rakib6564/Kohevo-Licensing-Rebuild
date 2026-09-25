<?php
/**
 * Slate — wipe demo data.
 *
 * Reverses bin/seed-demo.php. Targeted: only deletes rows that
 * match the demo names/slugs/emails so it can't accidentally
 * delete real customer data.
 *
 *   php bin/clean-demo.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI.\n"); exit(1);
}
require __DIR__ . '/../config.php';

$tid = current_tenant_id();
$deleted = ['appointments' => 0, 'providers' => 0, 'services' => 0, 'forms' => 0];

try {
    if (class_exists('BookingAPI') && PluginLoader::isActive('booking')) {
        $deleted['appointments'] += Database::delete(
            'booking_appointments',
            'tenant_id = ? AND customer_email = ?',
            [$tid, 'demo@example.com']
        );

        foreach (['Mariko Osborne', 'Alex Chen'] as $pname) {
            $row = Database::row("SELECT id FROM booking_providers WHERE tenant_id = ? AND name = ?", [$tid, $pname]);
            if ($row) {
                Database::delete('booking_provider_services', 'provider_id = ?', [$row['id']]);
                Database::delete('booking_provider_hours', 'provider_id = ?', [$row['id']]);
                Database::delete('booking_appointments', 'tenant_id = ? AND provider_id = ?', [$tid, $row['id']]);
                Database::delete('booking_providers', 'id = ?', [$row['id']]);
                $deleted['providers']++;
            }
        }

        foreach (['Initial consultation', 'Strategy session', 'Follow-up call'] as $sname) {
            $deleted['services'] += Database::delete('booking_services', 'tenant_id = ? AND name = ?', [$tid, $sname]);
        }
    }

    if (class_exists('FormsAPI') && PluginLoader::isActive('forms')) {
        $row = Database::row("SELECT id FROM forms_definitions WHERE tenant_id = ? AND slug = ?", [$tid, 'contact-us']);
        if ($row) {
            Database::delete('forms_submissions', 'form_id = ? AND tenant_id = ?', [$row['id'], $tid]);
            Database::delete('forms_webhooks',    'form_id = ? AND tenant_id = ?', [$row['id'], $tid]);
            Database::delete('forms_definitions', 'id = ?', [$row['id']]);
            $deleted['forms']++;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Deleted:\n";
foreach ($deleted as $k => $n) printf("  %-13s %d\n", $k, $n);
