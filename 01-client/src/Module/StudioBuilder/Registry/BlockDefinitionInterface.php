<?php
/**
 * Kohevo Studio (studio-builder) — Block Definition Contract.
 *
 * Defines the contract for all Studio blocks:
 * - Namespaced block identity (`namespace.name`)
 * - Version integer (`>= 1`)
 * - Typed declarative `FieldSchema`
 * - Strict prop validation & deterministic prop normalization
 * - Transport-safe editor manifest (never exposes PHP classes, callables, or SQL)
 * - Required commercial module entitlement (`?string`)
 * - Required Studio RBAC permission (`string`)
 * - Allowed dynamic data-provider keys (`list<string>`)
 * - Children / nesting capability (`allowsChildren`, `allowedChildTypes`)
 * - Dependency extraction hook (`extractDependencies`)
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

use Slate\Module\StudioBuilder\Dependency\DependencyRecord;
use Slate\Module\StudioBuilder\Schema\FieldSchema;
use Slate\Module\StudioBuilder\Schema\ValidationResult;

interface BlockDefinitionInterface
{
    /**
     * Namespaced block type identifier (e.g. 'core.hero', 'core.rich_text', 'core.container').
     */
    public function type(): string;

    /**
     * Block schema version (`>= 1`).
     */
    public function version(): int;

    /**
     * Human-readable block label for the Studio palette.
     */
    public function label(): string;

    /**
     * Palette category key (e.g. 'layout', 'content', 'media').
     */
    public function category(): string;

    /**
     * Symbolic icon identifier for the Studio palette.
     */
    public function icon(): string;

    /**
     * Declarative FieldSchema governing this block's `props`.
     */
    public function schema(): FieldSchema;

    /**
     * Whether this block may contain nested child blocks in `children`.
     */
    public function allowsChildren(): bool;

    /**
     * Optional allowlist of block `type` keys allowed as direct children when `allowsChildren()` is true.
     * An empty list means any registered block is allowed as a child (within max nesting depth).
     *
     * @return list<string>
     */
    public function allowedChildTypes(): array;

    /**
     * Commercial module entitlement key required beyond `studio-builder` (e.g. 'booking', 'forms'),
     * or `null` for core Studio blocks.
     */
    public function requiredEntitlement(): ?string;

    /**
     * Studio RBAC permission key required to insert or mutate this block (default 'studio-builder.edit').
     */
    public function requiredPermission(): string;

    /**
     * Allowlist of declarative dynamic-data provider keys this block may bind to.
     *
     * @return list<string>
     */
    public function allowedBindingProviders(): array;

    /**
     * Supported style token capabilities for this block.
     *
     * @return list<string>
     */
    public function styleCapabilities(): array;

    /**
     * Validate raw block `props` against `schema()` and any block-specific invariants.
     *
     * @param array<string, mixed> $props
     */
    public function validateProps(array $props, string $basePath = '$.props'): ValidationResult;

    /**
     * Deterministically normalize validated block `props`.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    public function normalizeProps(array $props): array;

    /**
     * Extract block-level dependencies from normalized `props` and `bindings`.
     *
     * @param array<string, mixed> $normalizedProps
     * @param array<string, mixed> $bindings
     * @return list<DependencyRecord>
     */
    public function extractDependencies(string $nodeId, array $normalizedProps, array $bindings): array;

    /**
     * Produce a transport-safe JSON manifest for the visual editor palette.
     * Must NEVER include PHP class names, closures, or SQL.
     *
     * @return array<string, mixed>
     */
    public function toEditorManifest(): array;
}
