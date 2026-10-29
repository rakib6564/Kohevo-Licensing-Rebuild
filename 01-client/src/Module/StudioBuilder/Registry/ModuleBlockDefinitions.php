<?php
/**
 * Kohevo Studio (studio-builder) — Business-module catalogue block definitions.
 *
 * Studio's own business blocks:
 *
 *   payment.button    -> no provider: an amount typed in the block (or a range the visitor picks within), one-time or
 *                        monthly, signed at render time (see Payment/PaymentOffer)
 *   payment.link      -> no provider: a Stripe-hosted link typed in the block (see Payment/PaymentLinkUrl)
 *
 * The module widgets (booking.*, membership.*, forms.*) belong to their plugins: each ships
 * `studio-widgets.php` (see PluginWidgetCatalog). A module widget declares its commercial entitlement
 * (enforced at write time by `DocumentValidator` and again, live, at every render) and allowlists
 * exactly one binding provider — no block can bind to an arbitrary provider key.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Module\StudioBuilder\Payment\PaymentLinkUrl;
use Slate\Module\StudioBuilder\Payment\PaymentOffer;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class ModuleBlockDefinitions
{
    /** Studio's own business blocks plus every installed plugin's widgets. @return list<BlockDefinitionInterface> */
    public static function all(): array
    {
        return [...self::studioBlocks(), ...PluginWidgetCatalog::blocks()];
    }

    /** The business blocks Studio itself owns (payments: no plugin, no entitlement). @return list<DeclarativeBlockDefinition> */
    public static function studioBlocks(): array
    {
        return [
            new DeclarativeBlockDefinition(
                type: 'payment.button',
                version: 1,
                label: 'Payment Button',
                title: 'Payment Button',
                description: 'A Stripe Checkout pay button: a set or visitor-chosen amount, once or monthly',
                category: 'business',
                icon: 'arrow-up-right',
                schema: FieldSchema::define([
                    ['key' => 'label', 'type' => 'string', 'label' => 'Button label', 'required' => false, 'default' => 'Pay now', 'max_length' => 80],
                    ['key' => 'amount', 'type' => 'number', 'label' => 'Amount', 'required' => false, 'default' => 10, 'min' => 0.5, 'max' => 999999],
                    ['key' => 'amount_choice', 'type' => 'enum', 'label' => 'Who sets the amount', 'required' => false, 'default' => 'fixed', 'allowed_values' => ['fixed', 'visitor']],
                    ['key' => 'max_amount', 'type' => 'number', 'label' => 'Highest amount a visitor can give', 'required' => false, 'default' => 5000, 'min' => 1, 'max' => 999999],
                    ['key' => 'frequency', 'type' => 'enum', 'label' => 'How often', 'required' => false, 'default' => 'once', 'allowed_values' => PaymentOffer::FREQUENCIES],
                    ['key' => 'amount_label', 'type' => 'string', 'label' => 'Amount box label', 'required' => false, 'default' => '', 'max_length' => 80],
                    ['key' => 'currency', 'type' => 'enum', 'label' => 'Currency', 'required' => false, 'default' => 'USD', 'allowed_values' => PaymentOffer::CURRENCIES],
                    ['key' => 'description', 'type' => 'string', 'label' => 'What the visitor is paying for', 'required' => false, 'default' => '', 'max_length' => PaymentOffer::MAX_DESCRIPTION],
                    ['key' => 'success_path', 'type' => 'string', 'label' => 'Thank-you page path', 'required' => false, 'default' => '', 'max_length' => 120, 'pattern' => '~^(?:[A-Za-z0-9][A-Za-z0-9_\-]*(?:/[A-Za-z0-9][A-Za-z0-9_\-]*){0,5})?$~'],
                ]),
            ),
            new DeclarativeBlockDefinition(
                type: 'payment.link',
                version: 1,
                label: 'Stripe Payment Link',
                title: 'Payment link',
                description: 'A button to a Stripe Payment Link or Buy Button page',
                category: 'business',
                icon: 'link',
                schema: FieldSchema::define([
                    ['key' => 'label', 'type' => 'string', 'label' => 'Button label', 'required' => false, 'default' => 'Pay now', 'max_length' => 80],
                    ['key' => 'url', 'type' => 'string', 'label' => 'Stripe link', 'required' => false, 'default' => '', 'max_length' => 600, 'pattern' => PaymentLinkUrl::PATTERN],
                ]),
            ),
        ];
    }

    /** Core foundation blocks plus layout primitives and the business-module catalogue blocks. */
    public static function studioRegistry(): BlockRegistry
    {
        $registry = BlockRegistry::withCoreFoundationBlocks();
        BlockRegistry::registerLayoutBlocks($registry);
        foreach (self::studioBlocks() as $definition) {
            $registry->register($definition);
        }
        // A plugin cannot take a type Studio (or another plugin) already registered.
        foreach (PluginWidgetCatalog::blocks() as $definition) {
            if (!$registry->has($definition->type())) {
                $registry->register($definition);
            }
        }
        foreach (\Slate\Module\StudioBuilder\Sdk\WidgetSdk::instance()->all() as $customWidget) {
            if (!$registry->has($customWidget->type())) {
                $registry->register($customWidget->toBlockDefinition());
            }
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
