<?php
/**
 * Booking — the widgets this plugin offers in Kohevo Studio (Add › Components).
 *
 * Read by Studio's PluginWidgetCatalog whether or not Booking is active, so pages that hold these
 * blocks keep validating; the `booking` entitlement (plugin active and licensed) decides whether
 * they can be inserted and whether they render.
 */

declare(strict_types=1);

// Only ever included by Studio; a direct web request gets nothing.
if (!defined('SLATE_ROOT')) {
    http_response_code(404);
    exit;
}

use Slate\Module\StudioBuilder\Provider\BookingEmbedTargetProvider;
use Slate\Module\StudioBuilder\Provider\BookingServicesProvider;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\BookingEmbedRenderer;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\BookingServicesRenderer;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

require_once __DIR__ . '/studio/BookingServiceCardRenderer.php';

return [
    'blocks' => [
        new DeclarativeBlockDefinition(
            type: 'booking.services',
            version: 1,
            label: 'Booking Services',
            title: 'Booking Services',
            description: 'Bookable services from your catalogue',
            category: 'business',
            icon: 'list-check',
            schema: FieldSchema::define([
                ['key' => 'heading', 'type' => 'string', 'label' => 'Heading', 'required' => false, 'default' => '', 'max_length' => 200],
                ['key' => 'show_prices', 'type' => 'boolean', 'label' => 'Show prices', 'required' => false, 'default' => true],
                ['key' => 'cta_label', 'type' => 'string', 'label' => 'Button label', 'required' => false, 'default' => '', 'max_length' => 80],
            ]),
            requiredEntitlement: 'booking',
            allowedBindingProviders: ['booking.services'],
            bindingSlots: ['items' => 'booking.services'],
        ),
        new DeclarativeBlockDefinition(
            type: 'booking.embed',
            version: 1,
            label: 'Booking',
            title: 'Booking',
            description: 'Your booking flow, or one service, on the page',
            category: 'business',
            icon: 'calendar',
            schema: FieldSchema::define([
                ['key' => 'min_height', 'type' => 'number', 'label' => 'Minimum height (px)', 'required' => false, 'default' => 560, 'integer_only' => true, 'min' => 200, 'max' => 2400],
            ]),
            requiredEntitlement: 'booking',
            allowedBindingProviders: ['booking.embed_target'],
            bindingSlots: ['service' => 'booking.embed_target'],
        ),
        new DeclarativeBlockDefinition(
            type: 'booking.service',
            version: 1,
            label: 'Service Card',
            title: 'Service Card',
            description: 'One service with its price, length and a Book button',
            category: 'business',
            icon: 'calendar',
            schema: FieldSchema::define([
                ['key' => 'show_price', 'type' => 'boolean', 'label' => 'Show price', 'required' => false, 'default' => true],
                ['key' => 'show_duration', 'type' => 'boolean', 'label' => 'Show length', 'required' => false, 'default' => true],
                ['key' => 'cta_label', 'type' => 'string', 'label' => 'Button label', 'required' => false, 'default' => '', 'max_length' => 80],
            ]),
            requiredEntitlement: 'booking',
            allowedBindingProviders: ['booking.embed_target'],
            bindingSlots: ['service' => 'booking.embed_target'],
        ),
    ],
    'renderers' => [
        new BookingServicesRenderer(),
        new BookingEmbedRenderer(),
        new \Slate\Plugin\Booking\Studio\BookingServiceCardRenderer(),
    ],
    'providers' => [
        new BookingServicesProvider(),
        new BookingEmbedTargetProvider(),
    ],
];
