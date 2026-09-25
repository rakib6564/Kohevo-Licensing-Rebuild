<?php
/**
 * Slate — demo data seeder.
 *
 * Populates the active plugins with realistic-looking sample data
 * so a fresh install isn't empty when shown to a prospect.
 *
 *   php bin/seed-demo.php
 *
 * Reads tenant id from .env (TENANT_ID), defaults to 1. Idempotent:
 * re-running checks for existing rows by slug/name and only adds
 * what's missing, so it's safe to run repeatedly.
 *
 * Reverse with:   php bin/clean-demo.php
 *
 * Skips plugins that aren't installed/active so this works on any
 * subset of Slate.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/seed-demo.php\n");
    exit(1);
}

require __DIR__ . '/../config.php';

echo "Slate demo seeder — tenant " . current_tenant_id() . "\n";

$count = ['forms' => 0, 'services' => 0, 'providers' => 0, 'appointments' => 0];

// ── Forms ────────────────────────────────────────────────────
if (class_exists('FormsAPI') && PluginLoader::isActive('forms')) {
    echo " · forms\n";
    $existing = Database::row(
        "SELECT id FROM forms_definitions WHERE tenant_id = ? AND slug = ?",
        [current_tenant_id(), 'contact-us']
    );
    if (!$existing) {
        $fields = [
            ['type'=>'text',     'name'=>'full_name', 'label'=>'Your name',  'required'=>true],
            ['type'=>'email',    'name'=>'email',     'label'=>'Email',      'required'=>true],
            ['type'=>'tel',      'name'=>'phone',     'label'=>'Phone',      'required'=>false],
            ['type'=>'select',   'name'=>'topic',     'label'=>'Topic',      'required'=>true,
             'placeholder'=>'Choose…', 'options'=>['Sales','Support','Other']],
            ['type'=>'textarea', 'name'=>'message',   'label'=>'Message',    'required'=>true],
        ];
        Database::insert('forms_definitions', [
            'tenant_id'        => current_tenant_id(),
            'slug'             => 'contact-us',
            'title'            => 'Contact us',
            'description'      => 'Got a question? Send us a note — we read every one.',
            'fields_json'      => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'submit_label'     => 'Send',
            'success_message'  => 'Thanks — we got it. We\'ll reply within one business day.',
            'status'           => 'published',
        ]);
        $count['forms']++;
    }
}

// ── Booking ──────────────────────────────────────────────────
if (class_exists('BookingAPI') && PluginLoader::isActive('booking')) {
    echo " · booking\n";

    $services = [
        ['Initial consultation', 30, 0,    0, '#2563EB'],
        ['Strategy session',     60, 15,  15000, '#10B981'],
        ['Follow-up call',       30, 0,    0, '#8B5CF6'],
    ];
    $serviceIds = [];
    foreach ($services as [$name, $dur, $buf, $price, $col]) {
        $existing = Database::row(
            "SELECT id FROM booking_services WHERE tenant_id = ? AND name = ?",
            [current_tenant_id(), $name]
        );
        if ($existing) { $serviceIds[$name] = (int)$existing['id']; continue; }
        $id = Database::insert('booking_services', [
            'tenant_id'    => current_tenant_id(),
            'name'         => $name,
            'slug'         => BookingAPI::slugify($name),
            'duration_min' => $dur,
            'buffer_min'   => $buf,
            'price_cents'  => $price,
            'currency'     => 'USD',
            'color'        => $col,
            'is_active'    => 1,
        ]);
        $serviceIds[$name] = $id;
        $count['services']++;
    }

    $providers = ['Mariko Osborne', 'Alex Chen'];
    $providerIds = [];
    foreach ($providers as $pname) {
        $existing = Database::row(
            "SELECT id FROM booking_providers WHERE tenant_id = ? AND name = ?",
            [current_tenant_id(), $pname]
        );
        if ($existing) { $providerIds[$pname] = (int)$existing['id']; continue; }
        $pid = Database::insert('booking_providers', [
            'tenant_id' => current_tenant_id(),
            'name'      => $pname,
            'email'     => null,
            'timezone'  => 'UTC',
            'is_active' => 1,
        ]);
        // Hours: Mon-Fri 09:00–17:00
        for ($d = 1; $d <= 5; $d++) {
            Database::insert('booking_provider_hours', [
                'tenant_id'   => current_tenant_id(),
                'provider_id' => $pid,
                'day_of_week' => $d,
                'start_time'  => '09:00:00',
                'end_time'    => '17:00:00',
            ]);
        }
        // Link to all services
        foreach ($serviceIds as $sid) {
            Database::insert('booking_provider_services',
                ['provider_id' => $pid, 'service_id' => $sid]);
        }
        $providerIds[$pname] = $pid;
        $count['providers']++;
    }

    // One sample appointment tomorrow at 10:00 if none exist
    $existingAppt = Database::value(
        "SELECT COUNT(*) FROM booking_appointments WHERE tenant_id = ?",
        [current_tenant_id()]
    );
    if ((int)$existingAppt === 0 && !empty($providerIds) && !empty($serviceIds)) {
        $sid = reset($serviceIds);
        $pid = reset($providerIds);
        // Find the next weekday 10am
        $ts = strtotime('tomorrow 10:00');
        while (in_array((int)date('w', $ts), [0, 6], true)) $ts = strtotime('+1 day', $ts);
        Database::insert('booking_appointments', [
            'tenant_id'      => current_tenant_id(),
            'ref'            => BookingAPI::generateRef(),
            'service_id'     => $sid,
            'provider_id'    => $pid,
            'customer_name'  => 'Demo Customer',
            'customer_email' => 'demo@example.com',
            'starts_at'      => date('Y-m-d H:i:s', $ts),
            'ends_at'        => date('Y-m-d H:i:s', $ts + 1800),
            'status'         => 'confirmed',
        ]);
        $count['appointments']++;
    }
}

echo "\nSeeded:\n";
foreach ($count as $k => $n) printf("  %-13s %d\n", $k, $n);
echo "\nDone. Visit /admin/ to see the data, /forms/contact-us to test the public form,\n";
echo "and /book to walk through a booking.\n";
