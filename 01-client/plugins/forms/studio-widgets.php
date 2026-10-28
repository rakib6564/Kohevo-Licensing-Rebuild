<?php
/**
 * Forms — the widgets this plugin offers in Kohevo Studio (Add › Components).
 *
 * Read by Studio's PluginWidgetCatalog whether or not Forms is active, so pages that hold these
 * blocks keep validating; the `forms` entitlement (plugin active and licensed) decides whether
 * they can be inserted and whether they render.
 */

declare(strict_types=1);

// Only ever included by Studio; a direct web request gets nothing.
if (!defined('SLATE_ROOT')) {
    http_response_code(404);
    exit;
}

use Slate\Module\StudioBuilder\Provider\FormsFormEmbedProvider;
use Slate\Module\StudioBuilder\Provider\FormsFormProvider;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\FormCardRenderer;
use Slate\Module\StudioBuilder\Render\Block\ModuleRenderers\FormEmbedRenderer;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

return [
    'blocks' => [
        new DeclarativeBlockDefinition(
            type: 'forms.form_card',
            version: 1,
            label: 'Form Card',
            title: 'Form Card',
            description: 'A contact or enquiry form in a card',
            category: 'business',
            icon: 'form',
            schema: FieldSchema::define([
                ['key' => 'button_label', 'type' => 'string', 'label' => 'Button label', 'required' => false, 'default' => '', 'max_length' => 80],
                ['key' => 'show_description', 'type' => 'boolean', 'label' => 'Show description', 'required' => false, 'default' => true],
            ]),
            requiredEntitlement: 'forms',
            allowedBindingProviders: ['forms.form'],
            bindingSlots: ['form' => 'forms.form'],
        ),
        new DeclarativeBlockDefinition(
            type: 'forms.embed',
            version: 1,
            label: 'Form',
            title: 'Form',
            description: 'One of your forms, working on the page',
            category: 'business',
            icon: 'form',
            schema: FieldSchema::define([]),
            requiredEntitlement: 'forms',
            allowedBindingProviders: ['forms.form_embed'],
            bindingSlots: ['form' => 'forms.form_embed'],
        ),
    ],
    'renderers' => [
        new FormCardRenderer(),
        new FormEmbedRenderer(),
    ],
    'providers' => [
        new FormsFormProvider(),
        new FormsFormEmbedProvider(),
    ],
];
