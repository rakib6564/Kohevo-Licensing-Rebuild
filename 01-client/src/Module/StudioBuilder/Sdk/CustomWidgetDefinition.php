<?php
/**
 * Kohevo Studio (studio-builder) — Custom Widget Definition Value Object.
 *
 * Encapsulates the complete definition of a third-party or custom extension widget:
 * - Block schema & props definition
 * - Palette metadata (label, category, icon)
 * - Server renderer (callable or BlockRendererInterface)
 * - Version migration hook (v1 -> v2)
 * - Transport-safe manifest projection
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Sdk;

use Slate\Module\StudioBuilder\Registry\BlockDefinitionInterface;
use Slate\Module\StudioBuilder\Registry\DeclarativeBlockDefinition;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Schema\FieldSchema;

final class CustomWidgetDefinition
{
    private readonly FieldSchema $schema;
    private readonly BlockRendererInterface $rendererAdapter;
    /** @var ?callable */
    private $migrationHandler;

    /**
     * @param array<string, mixed>|FieldSchema $schema
     * @param callable|BlockRendererInterface $renderer
     * @param list<string> $allowedChildTypes
     * @param list<string> $allowedBindingProviders
     * @param array<string, string> $bindingSlots
     * @param array{css?: list<string>, js?: list<string>} $assets
     * @param ?callable $migration
     */
    public function __construct(
        private readonly string $type,
        private readonly int $version,
        private readonly string $label,
        private readonly string $category = 'custom',
        private readonly string $icon = 'box',
        FieldSchema|array $schema = [],
        callable|BlockRendererInterface|null $renderer = null,
        private readonly bool $allowsChildren = false,
        private readonly array $allowedChildTypes = [],
        private readonly ?string $requiredEntitlement = null,
        private readonly string $requiredPermission = 'studio-builder.edit',
        private readonly array $allowedBindingProviders = [],
        private readonly array $bindingSlots = [],
        private readonly array $defaultProps = [],
        private readonly array $assets = [],
        ?callable $migration = null,
    ) {
        WidgetSecurityValidator::validate([
            'type'                => $this->type,
            'version'             => $this->version,
            'label'               => $this->label,
            'category'            => $this->category,
            'icon'                => $this->icon,
            'schema'              => is_array($schema) ? $schema : null,
            'renderer'            => $renderer,
            'required_permission' => $this->requiredPermission,
        ]);

        $this->schema = $schema instanceof FieldSchema ? $schema : FieldSchema::define($schema);
        $this->rendererAdapter = new CustomWidgetRendererAdapter($this->type, $renderer);
        $this->migrationHandler = $migration;
    }

    /**
     * Create from associative configuration array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? $data['key'] ?? '');
        $version = (int) ($data['version'] ?? 1);
        $label = (string) ($data['label'] ?? '');
        $category = (string) ($data['category'] ?? 'custom');
        $icon = (string) ($data['icon'] ?? 'box');
        $schema = $data['schema'] ?? [];
        $renderer = $data['renderer'] ?? null;
        $allowsChildren = (bool) ($data['allows_children'] ?? false);
        $allowedChildTypes = (array) ($data['allowed_child_types'] ?? []);
        $requiredEntitlement = isset($data['required_entitlement']) ? (string) $data['required_entitlement'] : null;
        $requiredPermission = (string) ($data['required_permission'] ?? 'studio-builder.edit');
        $allowedBindingProviders = (array) ($data['allowed_binding_providers'] ?? []);
        $bindingSlots = (array) ($data['binding_slots'] ?? []);
        $defaultProps = (array) ($data['default_props'] ?? []);
        $assets = (array) ($data['assets'] ?? []);
        $migration = isset($data['migration']) && is_callable($data['migration']) ? $data['migration'] : null;

        return new self(
            type: $type,
            version: $version,
            label: $label,
            category: $category,
            icon: $icon,
            schema: $schema,
            renderer: $renderer,
            allowsChildren: $allowsChildren,
            allowedChildTypes: $allowedChildTypes,
            requiredEntitlement: $requiredEntitlement,
            requiredPermission: $requiredPermission,
            allowedBindingProviders: $allowedBindingProviders,
            bindingSlots: $bindingSlots,
            defaultProps: $defaultProps,
            assets: $assets,
            migration: $migration,
        );
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

    public function bindingSlots(): array
    {
        return $this->bindingSlots;
    }

    public function defaultProps(): array
    {
        return $this->defaultProps;
    }

    public function assets(): array
    {
        return $this->assets;
    }

    /**
     * Check if widget supports migration from a given version to target version.
     */
    public function canMigrate(int $fromVersion, int $toVersion): bool
    {
        return $this->migrationHandler !== null && $fromVersion < $toVersion;
    }

    /**
     * Migrate block properties from an older version to target version.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    public function migrate(int $fromVersion, int $toVersion, array $props): array
    {
        if ($this->migrationHandler === null || $fromVersion >= $toVersion) {
            return $props;
        }
        $result = ($this->migrationHandler)($fromVersion, $toVersion, $props);
        return is_array($result) ? $result : $props;
    }

    /**
     * Convert to an instance of BlockDefinitionInterface for BlockRegistry.
     */
    public function toBlockDefinition(): BlockDefinitionInterface
    {
        return new DeclarativeBlockDefinition(
            type: $this->type,
            version: $this->version,
            label: $this->label,
            category: $this->category,
            icon: $this->icon,
            schema: $this->schema,
            allowsChildren: $this->allowsChildren,
            allowedChildTypes: $this->allowedChildTypes,
            requiredEntitlement: $this->requiredEntitlement,
            requiredPermission: $this->requiredPermission,
            allowedBindingProviders: $this->allowedBindingProviders,
            bindingSlots: $this->bindingSlots,
        );
    }

    /**
     * Convert to an instance of BlockRendererInterface for BlockRendererRegistry.
     */
    public function toBlockRenderer(): BlockRendererInterface
    {
        return $this->rendererAdapter;
    }
}
