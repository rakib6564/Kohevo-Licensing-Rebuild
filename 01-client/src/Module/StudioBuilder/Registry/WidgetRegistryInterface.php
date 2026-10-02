<?php
/**
 * Kohevo Studio (studio-builder) — Widget Registry Interface.
 *
 * Authoritative registry contract for all Studio widgets and layout primitives:
 * - register()
 * - get()
 * - has()
 * - all()
 * - categories()
 * - resolve()
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

interface WidgetRegistryInterface
{
    /**
     * Register a block or widget definition. Duplicate registration fails closed.
     */
    public function register(BlockDefinitionInterface $widget): void;

    /**
     * Check if a block/widget type is registered.
     */
    public function has(string $type): bool;

    /**
     * Retrieve a block/widget definition by type, or null if unregistered.
     */
    public function get(string $type): ?BlockDefinitionInterface;

    /**
     * Retrieve a block/widget definition by type, throwing InvalidArgumentException if missing.
     */
    public function resolve(string $type): BlockDefinitionInterface;

    /**
     * Return all registered definitions indexed by type.
     *
     * @return array<string, BlockDefinitionInterface>
     */
    public function all(): array;

    /**
     * Return all registered definitions grouped by category.
     *
     * @return array<string, list<BlockDefinitionInterface>>
     */
    public function categories(): array;
}
