<?php
/**
 * Membership — the widgets this plugin offers in Kohevo Studio (Add › Components).
 *
 * Read by Studio's PluginWidgetCatalog whether or not Membership is active, so pages that hold
 * these blocks keep validating; the `membership` entitlement (plugin active and licensed) decides
 * whether they can be inserted and whether they render.
 */

declare(strict_types=1);

// Only ever included by Studio; a direct web request gets nothing.
if (!defined('SLATE_ROOT')) {
    http_response_code(404);
    exit;
}

use Slate\Module\StudioBuilder\Provider\MembershipPlansProvider;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\MembershipPlansRenderer;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

require_once __DIR__ . '/studio/MembershipPlanProvider.php';
require_once __DIR__ . '/studio/MembershipPlanCardRenderer.php';
require_once __DIR__ . '/studio/MemberLinksRenderer.php';

return [
    'blocks' => [
        new DeclarativeBlockDefinition(
            type: 'membership.plans',
            version: 1,
            label: 'Membership Plans',
            title: 'Membership Plans',
            description: 'Plans and pricing from your membership module',
            category: 'business',
            icon: 'grid-panes',
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
            type: 'membership.plan',
            version: 1,
            label: 'Plan Card',
            title: 'Plan Card',
            description: 'One membership plan with its price and a Join button',
            category: 'business',
            icon: 'grid-panes',
            schema: FieldSchema::define([
                ['key' => 'show_price', 'type' => 'boolean', 'label' => 'Show price', 'required' => false, 'default' => true],
                ['key' => 'cta_label', 'type' => 'string', 'label' => 'Button label', 'required' => false, 'default' => '', 'max_length' => 80],
            ]),
            requiredEntitlement: 'membership',
            allowedBindingProviders: ['membership.plan'],
            bindingSlots: ['plan' => 'membership.plan'],
        ),
        new DeclarativeBlockDefinition(
            type: 'membership.member_links',
            version: 1,
            label: 'Member Sign-in',
            title: 'Member Sign-in',
            description: 'Sign in, Join and My membership buttons',
            category: 'business',
            icon: 'arrow-up-right',
            schema: FieldSchema::define([
                ['key' => 'signin_label', 'type' => 'string', 'label' => 'Sign-in label', 'required' => false, 'default' => '', 'max_length' => 60],
                ['key' => 'show_join', 'type' => 'boolean', 'label' => 'Show Join', 'required' => false, 'default' => true],
                ['key' => 'join_label', 'type' => 'string', 'label' => 'Join label', 'required' => false, 'default' => '', 'max_length' => 60],
                ['key' => 'show_account', 'type' => 'boolean', 'label' => 'Show My membership', 'required' => false, 'default' => false],
                ['key' => 'account_label', 'type' => 'string', 'label' => 'My membership label', 'required' => false, 'default' => '', 'max_length' => 60],
            ]),
            requiredEntitlement: 'membership',
        ),
    ],
    'renderers' => [
        new MembershipPlansRenderer(),
        new \Slate\Plugin\Membership\Studio\MembershipPlanCardRenderer(),
        new \Slate\Plugin\Membership\Studio\MemberLinksRenderer(),
    ],
    'providers' => [
        new MembershipPlansProvider(),
        new \Slate\Plugin\Membership\Studio\MembershipPlanProvider(),
    ],
];
