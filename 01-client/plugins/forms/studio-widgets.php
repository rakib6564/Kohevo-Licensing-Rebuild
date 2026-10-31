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
            schema: FieldSchema::define([
                ['key' => 'look', 'type' => 'enum', 'label' => 'Look', 'required' => false, 'allowed_values' => ['card', 'flat'], 'default' => 'card'],
            ]),
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
    // The classes of the Forms page in the frame, and of the editor's preview (FormEmbedRenderer); EmbedStyle carries them in.
    'parts' => [
        'forms.embed' => [
            'card'        => ['label' => 'Card', 'selectors' => ['.forms-public-card']],
            'title'       => ['label' => 'Title', 'selectors' => ['.forms-public-header h1', '.sb-form-card__title']],
            'description' => ['label' => 'Description', 'selectors' => ['.forms-public-desc', '.sb-form-card__description']],
            'field'       => ['label' => 'Field', 'selectors' => ['.field']],
            'label'       => ['label' => 'Labels', 'selectors' => ['.field-label', '.forms-check-label']],
            'input'       => ['label' => 'Inputs', 'selectors' => ['.field input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([type="range"])', '.field select', '.field textarea', '.sb-embed-input']],
            // After `input`, so a text area's own height wins over the inputs' at equal weight.
            'textarea'    => ['label' => 'Text areas', 'selectors' => ['.field textarea', '.sb-embed-input--area']],
            'choice'      => ['label' => 'Choices', 'selectors' => ['.forms-check', '.forms-card']],
            'help'        => ['label' => 'Help text', 'selectors' => ['.field-hint']],
            'error'       => ['label' => 'Error messages', 'selectors' => ['.field-error', '.alert-error']],
            'button'      => ['label' => 'Submit button', 'selectors' => ['.btn.btn-primary']],
        ],
    ],
];
