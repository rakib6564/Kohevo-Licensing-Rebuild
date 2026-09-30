<?php
/**
 * Kohevo Studio (studio-builder) — Business-module catalogue block definitions.
 *
 * Declarative metadata for the three dynamic blocks that surface existing
 * business-module data through the Phase 3 provider boundary:
 *
 *   booking.services  -> provider booking.services  (entitlement: booking)
 *   membership.plans  -> provider membership.plans  (entitlement: membership)
 *   forms.form_card   -> provider forms.form        (entitlement: forms)
 *
 * Each block declares its commercial entitlement (enforced at write time by
 * `DocumentValidator` and again, live, at every render) and allowlists exactly
 * one binding provider — no block can bind to an arbitrary provider key.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class ModuleBlockDefinitions
{
    /** @return list<DeclarativeBlockDefinition> */
    public static function all(): array
    {
        return [
            new DeclarativeBlockDefinition(
                type: 'booking.services',
                version: 1,
                label: 'Booking Services',
                category: 'business',
                icon: 'calendar',
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
                type: 'membership.plans',
                version: 1,
                label: 'Membership Plans',
                category: 'business',
                icon: 'id-card',
                schema: FieldSchema::define([
                    ['key' => 'heading', 'type' => 'string', 'label' => 'Heading', 'required' => false, 'default' => '', 'max_length' => 200],
                    ['key' => 'show_prices', 'type' => 'boolean', 'label' => 'Show prices', 'required' => false, 'default' => true],
                    ['key' => 'cta_label', 'type' => 'string', 'label' => 'Button label', 'required' => false, 'default' => '', 'max_length' => 80],
                ]),
                requiredEntitlement: 'membership',
                allowedBindingProviders: ['membership.plans'],
                bindingSlots: ['items' => 'membership.plans'],
            ),
            new DeclarativeBlockDefinition(
                type: 'forms.form_card',
                version: 1,
                label: 'Form Card',
                category: 'business',
                icon: 'clipboard',
                schema: FieldSchema::define([
                    ['key' => 'button_label', 'type' => 'string', 'label' => 'Button label', 'required' => false, 'default' => '', 'max_length' => 80],
                    ['key' => 'show_description', 'type' => 'boolean', 'label' => 'Show description', 'required' => false, 'default' => true],
                ]),
                requiredEntitlement: 'forms',
                allowedBindingProviders: ['forms.form'],
                bindingSlots: ['form' => 'forms.form'],
            ),
        ];
    }

    /** Core foundation blocks plus the business-module catalogue blocks. */
    public static function studioRegistry(): BlockRegistry
    {
        $registry = BlockRegistry::withCoreFoundationBlocks();
        foreach (self::all() as $definition) {
            $registry->register($definition);
        }
        return $registry;
    }

    /** Deterministic identity of a registry's block set (part of the compilation fingerprint). */
    public static function registryFingerprint(BlockRegistry $registry): string
    {
        $keys = [];
        foreach ($registry->all() as $type => $definition) {
            $keys[] = $type . '@' . $definition->version() . ':' . ($definition->requiredEntitlement() ?? '-');
        }
        return hash('sha256', implode('|', $keys));
    }
}
