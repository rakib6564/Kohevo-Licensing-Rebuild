<?php
/**
 * Kohevo Studio (studio-builder) — Declarative Block Definition.
 *
 * Immutable value-object implementation of `BlockDefinitionInterface`.
 * Used to register core foundation blocks and future module blocks safely.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Module\StudioBuilder\Dependency\DependencyRecord;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\Schema\ValidationResult;

final class DeclarativeBlockDefinition implements BlockDefinitionInterface
{
    private const ALLOWED_PERMISSIONS = [
        'studio-builder.view',
        'studio-builder.edit',
        'studio-builder.publish',
        'studio-builder.tokens',
        'studio-builder.admin',
    ];

    /**
     * @param list<string> $allowedChildTypes
     * @param list<string> $allowedBindingProviders
     * @param list<string> $styleCapabilities
     * @param array<string, string> $bindingSlots Editor metadata: renderer slot key => the allowlisted
     *                                            provider that feeds it (e.g. ['items' => 'booking.services']).
     * @param ?string $title       Add-panel card title (defaults to the label); translated at the application boundary.
     * @param string $description  One-line Add-panel card description; translated at the application boundary.
     * @param list<array{prop: string, selector: string}> $inlineText The text the canvas may edit in place: a `string`/`text`
     *                                            prop (or `<link field>.label`) and the CSS class selector of the element
     *                                            that renders it. Declared, never guessed from the markup.
     */
    public function __construct(
        private readonly string $type,
        private readonly int $version,
        private readonly string $label,
        private readonly string $category,
        private readonly string $icon,
        private readonly FieldSchema $schema,
        private readonly bool $allowsChildren = false,
        private readonly array $allowedChildTypes = [],
        private readonly ?string $requiredEntitlement = null,
        private readonly string $requiredPermission = 'studio-builder.edit',
        private readonly array $allowedBindingProviders = [],
        private readonly array $styleCapabilities = CanonicalDocumentSchema::ALLOWED_STYLE_KEYS,
        private readonly array $bindingSlots = [],
        private readonly ?string $title = null,
        private readonly string $description = '',
        private readonly array $inlineText = [],
    ) {
        if (preg_match(CanonicalDocumentSchema::BLOCK_TYPE_PATTERN, $this->type) !== 1) {
            throw new \InvalidArgumentException("Invalid namespaced block type '{$this->type}'. Expected 'namespace.name'.");
        }
        if ($this->version < 1) {
            throw new \InvalidArgumentException("Block '{$this->type}' version must be >= 1.");
        }
        if (trim($this->label) === '') {
            throw new \InvalidArgumentException("Block '{$this->type}' label must not be empty.");
        }
        if (!in_array($this->requiredPermission, self::ALLOWED_PERMISSIONS, true)) {
            throw new \InvalidArgumentException("Block '{$this->type}' declares invalid permission '{$this->requiredPermission}'.");
        }
        if (!$this->allowsChildren && $this->allowedChildTypes !== []) {
            throw new \InvalidArgumentException("Block '{$this->type}' cannot specify allowedChildTypes when allowsChildren is false.");
        }
        foreach ($this->allowedBindingProviders as $provider) {
            if (!is_string($provider) || preg_match(CanonicalDocumentSchema::PROVIDER_KEY_PATTERN, $provider) !== 1) {
                throw new \InvalidArgumentException("Block '{$this->type}' declares invalid binding provider '{$provider}'.");
            }
        }
        $this->assertInlineText();
        foreach ($this->bindingSlots as $slot => $provider) {
            if (!is_string($slot) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $slot) !== 1 || !in_array($provider, $this->allowedBindingProviders, true)) {
                throw new \InvalidArgumentException("Block '{$this->type}' declares an invalid binding slot '{$slot}'.");
            }
        }
    }

    /** @throws \InvalidArgumentException when an inline text declaration does not name a text prop of this schema. */
    private function assertInlineText(): void
    {
        $types = [];
        foreach ($this->schema->fields() as $field) {
            $types[(string) ($field['key'] ?? '')] = (string) ($field['type'] ?? '');
        }
        foreach ($this->inlineText as $entry) {
            $prop = is_array($entry) ? ($entry['prop'] ?? null) : null;
            $selector = is_array($entry) ? ($entry['selector'] ?? null) : null;
            if (!is_string($prop) || !is_string($selector) || preg_match('/^\.[a-z][a-z0-9_-]*(?: [>] \.[a-z][a-z0-9_-]*| \.[a-z][a-z0-9_-]*| [a-z][a-z0-9]*)*$/i', $selector) !== 1) {
                throw new \InvalidArgumentException("Block '{$this->type}' declares an invalid inline text entry.");
            }
            $field = explode('.', $prop, 2);
            $ok = isset($types[$field[0]]) && (
                (count($field) === 1 && in_array($types[$field[0]], ['string', 'text'], true))
                || (count($field) === 2 && $field[1] === 'label' && $types[$field[0]] === 'link')
            );
            if (!$ok) {
                throw new \InvalidArgumentException("Block '{$this->type}' declares inline text '{$prop}', which is not a text prop of its schema.");
            }
        }
    }

    /** The schema type of an inline text prop (`link.label` counts as a one-line string). */
    private function inlineTextType(string $prop): string
    {
        foreach ($this->schema->fields() as $field) {
            if (($field['key'] ?? null) === $prop) {
                return (string) ($field['type'] ?? '');
            }
        }
        return 'string';
    }

    public function type(): string
    {
        return $this->type;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function schema(): FieldSchema
    {
        return $this->schema;
    }

    public function allowsChildren(): bool
    {
        return $this->allowsChildren;
    }

    public function allowedChildTypes(): array
    {
        return $this->allowedChildTypes;
    }

    public function requiredEntitlement(): ?string
    {
        return $this->requiredEntitlement;
    }

    public function requiredPermission(): string
    {
        return $this->requiredPermission;
    }

    public function allowedBindingProviders(): array
    {
        return $this->allowedBindingProviders;
    }

    public function styleCapabilities(): array
    {
        return $this->styleCapabilities;
    }

    /**
     * @return array<string, string> slot key => provider key (editor metadata only; the
     *                               validator enforces the provider allowlist on its own)
     */
    public function bindingSlots(): array
    {
        return $this->bindingSlots;
    }

    public function validateProps(array $props, string $basePath = '$.props'): ValidationResult
    {
        return $this->schema->validate($props, $basePath);
    }

    public function normalizeProps(array $props): array
    {
        return $this->schema->normalize($props);
    }

    public function extractDependencies(string $nodeId, array $normalizedProps, array $bindings): array
    {
        $records = [];

        if ($this->requiredEntitlement !== null && $this->requiredEntitlement !== '') {
            $records[] = new DependencyRecord($nodeId, 'module', $this->requiredEntitlement);
        }

        // Recursively inspect normalized props for media_ref and token_ref values
        $this->collectPropDependencies($nodeId, $this->schema->fields(), $normalizedProps, $records);

        // Inspect declarative bindings
        foreach ($bindings as $binding) {
            if (is_array($binding) && !empty($binding['provider']) && is_string($binding['provider'])) {
                $provider = $binding['provider'];
                $records[] = new DependencyRecord($nodeId, 'entity', $provider);
                $parts = explode('.', $provider, 2);
                if (!empty($parts[0]) && $parts[0] !== 'core') {
                    $records[] = new DependencyRecord($nodeId, 'module', $parts[0]);
                }
            }
        }

        return $records;
    }

    public function toEditorManifest(): array
    {
        return [
            'allowed_binding_providers' => $this->allowedBindingProviders,
            'allowed_child_types'       => $this->allowedChildTypes,
            'allows_children'           => $this->allowsChildren,
            'binding_slots'             => array_map(
                static fn(string $slot, string $provider): array => ['provider' => $provider, 'slot' => $slot],
                array_keys($this->bindingSlots),
                array_values($this->bindingSlots),
            ),
            'category'                  => $this->category,
            'default_props'             => $this->schema->defaults(),
            'description'               => $this->description,
            'field_schema'              => $this->schema->toEditorManifest(),
            'icon'                      => $this->icon,
            'inline_text'               => array_map(
                fn(array $e): array => [
                    'prop' => $e['prop'],
                    'selector' => $e['selector'],
                    'multiline' => $this->inlineTextType($e['prop']) === 'text',
                ],
                $this->inlineText,
            ),
            'label'                     => $this->label,
            'required_entitlement'      => $this->requiredEntitlement,
            'required_permission'       => $this->requiredPermission,
            'style_capabilities'        => $this->styleCapabilities,
            'title'                     => $this->title !== null && trim($this->title) !== '' ? $this->title : $this->label,
            'type'                      => $this->type,
            'version'                   => $this->version,
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $props
     * @param list<DependencyRecord> $records
     */
    private function collectPropDependencies(string $nodeId, array $fields, array $props, array &$records): void
    {
        foreach ($fields as $field) {
            $key  = (string) $field['key'];
            $type = (string) $field['type'];
            $val  = $props[$key] ?? null;
            if ($val === null) {
                continue;
            }

            if ($type === 'media_ref' && is_array($val) && isset($val['media_id']) && is_int($val['media_id']) && $val['media_id'] > 0) {
                $records[] = new DependencyRecord($nodeId, 'media', (string) $val['media_id']);
            } elseif ($type === 'token_ref' && is_string($val) && $val !== '') {
                $records[] = new DependencyRecord($nodeId, 'token_group', $val);
            } elseif ($type === 'repeater' && is_array($val) && ($field['item_schema'] ?? null) instanceof FieldSchema) {
                /** @var FieldSchema $itemSchema */
                $itemSchema = $field['item_schema'];
                foreach ($val as $item) {
                    if (is_array($item)) {
                        $this->collectPropDependencies($nodeId, $itemSchema->fields(), $item, $records);
                    }
                }
            } elseif ($type === 'object' && is_array($val) && ($field['properties'] ?? null) instanceof FieldSchema) {
                /** @var FieldSchema $propSchema */
                $propSchema = $field['properties'];
                $this->collectPropDependencies($nodeId, $propSchema->fields(), $val, $records);
            }
        }
    }
}
