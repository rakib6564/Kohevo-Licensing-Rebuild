<?php
/**
 * Unit tests for Slate Modular Capabilities & Gateway Architecture.
 */

declare(strict_types=1);

use Slate\Kernel\Module\PluginLoader;

// ── PluginLoader::validateManifest with Capabilities ────────────────────────

unit('validateManifest accepts valid manifest with capabilities', function () {
    $manifest = [
        'slug'         => 'booking',
        'name'         => 'Booking',
        'version'      => '1.0.0',
        'description'  => 'Appointment scheduling engine',
        'author'       => 'Slate Platform',
        'capabilities' => [
            'client_messaging' => [
                'name'        => 'Client In-App Messaging',
                'description' => 'Two-way in-app direct messaging',
                'default'     => true,
            ],
            'service_rules' => [
                'name'        => 'Custom Service Rules',
                'description' => 'Fine-grained booking limits',
                'default'     => true,
            ],
        ],
    ];

    $res = PluginLoader::validateManifest($manifest);
    assert_true($res['ok'], 'valid manifest with capabilities should pass');
    assert_null($res['error']);
});

unit('validateManifest rejects invalid capability key format', function () {
    $manifest = [
        'slug'         => 'booking',
        'name'         => 'Booking',
        'version'      => '1.0.0',
        'description'  => 'Appointment scheduling engine',
        'author'       => 'Slate Platform',
        'capabilities' => [
            '123_invalid' => [
                'name' => 'Bad Key',
            ],
        ],
    ];

    $res = PluginLoader::validateManifest($manifest);
    assert_false($res['ok'], 'manifest with invalid capability key should fail');
    assert_true(str_contains($res['error'], "invalid capability key '123_invalid'"));
});

unit('validateManifest rejects capability missing name', function () {
    $manifest = [
        'slug'         => 'booking',
        'name'         => 'Booking',
        'version'      => '1.0.0',
        'description'  => 'Appointment scheduling engine',
        'author'       => 'Slate Platform',
        'capabilities' => [
            'client_messaging' => [
                'description' => 'Missing name field',
            ],
        ],
    ];

    $res = PluginLoader::validateManifest($manifest);
    assert_false($res['ok'], 'manifest with capability missing name should fail');
    assert_true(str_contains($res['error'], "capability 'client_messaging' must have a 'name'"));
});

unit('slugToClass converts plugin slugs correctly to PascalCase', function () {
    assert_eq('Booking', PluginLoader::slugToClass('booking'));
    assert_eq('BookingPlus', PluginLoader::slugToClass('booking-plus'));
    assert_eq('SlateMcp', PluginLoader::slugToClass('slate-mcp'));
    assert_eq('ReactSiteBridge', PluginLoader::slugToClass('react-site-bridge'));
});
