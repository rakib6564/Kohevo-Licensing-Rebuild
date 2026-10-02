<?php
/**
 * Kohevo Studio (studio-builder) — Declarative Widget Definition.
 *
 * Immutable value-object implementing `WidgetDefinitionInterface` and
 * `BlockDefinitionInterface`. Provides rich control schemas, description,
 * assets manifests, and feature capabilities for visual Studio widgets.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Module\StudioBuilder\Dependency\DependencyRecord;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Schema\ControlSchema;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\Schema\ValidationResult;

final class DeclarativeWidgetDefinition implements WidgetDefinitionInterface
{
    private const ALLOWED_PERMISSIONS = [
        'studio-builder.view',
        'studio-builder.edit',
        'studio-builder.publish',
        'studio-builder.tokens',
        'studio-builder.admin',
    ];

    /**
     * @param ControlSchema|array<string, mixed> $controls
     * @param list<string> $allowedChildTypes
     * @param list<string> $allowedBindingProviders
     * @param list<string> $styleCapabilities
     * @param array<string, string> $bindingSlots
     * @param array{css?: list<string>, js?: list<string>, fonts?: list<string>, icons?: list<string>} $assets
     * @param list<string> $supports
     */
    public function __construct(
        private readonly string $type,
        private readonly int $version,
        private readonly string $label,
        private readonly string $category,
        private readonly string $icon,
        private readonly FieldSchema $schema,
        private readonly string $description = '',
        private readonly ControlSchema|array $controls = [],
        private readonly bool $allowsChildren = false,
        private readonly array $allowedChildTypes = [],
        private readonly ?string $requiredEntitlement = null,
        private readonly string $requiredPermission = 'studio-builder.edit',
        private readonly array $allowedBindingProviders = [],
        private readonly array $styleCapabilities = CanonicalDocumentSchema::ALLOWED_STYLE_KEYS,
        private readonly array $bindingSlots = [],
        private readonly array $assets = [],
        private readonly array $supports = [],
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

    public function description(): string
    {
        return $this->description;
    }

    public function schema(): FieldSchema
    {
        return $this->schema;
    }

    public function controls(): array
    {
        if ($this->controls instanceof ControlSchema) {
            return $this->controls->toArray();
        }
        return $this->controls;
    }

    public function assets(): array
    {
        return $this->assets;
    }

    public function supports(): array
    {
        return $this->supports;
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
        $this->collectPropDependencies($nodeId, $this->schema->fields(), $normalizedProps, $records);

        foreach ($bindings as $slot => $binding) {
            if (is_array($binding) && isset($binding['provider']) && is_string($binding['provider'])) {
                $records[] = new DependencyRecord($nodeId, 'module', $binding['provider']);
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
            'assets'                    => $this->assets,
            'binding_slots'             => array_map(
                static fn(string $slot, string $provider): array => ['provider' => $provider, 'slot' => $slot],
                array_keys($this->bindingSlots),
                array_values($this->bindingSlots),
            ),
            'category'                  => $this->category,
            'controls'                  => $this->controls(),
            'default_props'             => $this->schema->defaults(),
            'description'               => $this->description,
            'field_schema'              => $this->schema->toEditorManifest(),
            'icon'                      => $this->icon,
            'label'                     => $this->label,
            'required_entitlement'      => $this->requiredEntitlement,
            'required_permission'       => $this->requiredPermission,
            'style_capabilities'        => $this->styleCapabilities,
            'supports'                  => $this->supports,
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
