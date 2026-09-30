<?php
/**
 * Kohevo Studio (studio-builder) — Render-time dynamic binding resolution.
 *
 * Resolves a block's declarative `bindings` exclusively through the Phase 3
 * `DataProviderRegistry::resolve()` pipeline (provider key -> entitlement ->
 * permission -> parameter validation -> bounded execution). On top of it:
 *
 *  - the provider must be in the block definition's own allowlist (re-checked
 *    at render time, not only at write time);
 *  - the permission predicate comes from the RenderContext, so an anonymous
 *    public render can only reach public-marked providers;
 *  - the TenantContext must still be the context's tenant (fail closed);
 *  - any denial or failure becomes a controlled "unavailable" slot (`null`) —
 *    never an exception, an internal message, or partial provider output;
 *  - rows are normalized: flat snake_case keys, scalar values only, strings
 *    capped — nothing nested or executable reaches a renderer.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Exception\StudioException;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockDefinitionInterface;
use Slate\Tenancy\TenantContext;

final class ProviderBindingResolver
{
    public const MAX_STRING_LENGTH = 2000;

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param array<string, mixed> $block normalized block
     * @return array<string, ?list<array<string, scalar|null>>> slot => rows (null = unavailable)
     */
    public function resolve(array $block, BlockDefinitionInterface $definition, RenderContext $context): array
    {
        $bindings = is_array($block['bindings'] ?? null) ? $block['bindings'] : [];
        $out = [];
        foreach ($bindings as $slot => $binding) {
            if (!is_string($slot) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $slot) !== 1) {
                continue;
            }
            $out[$slot] = is_array($binding) ? $this->resolveOne($binding, $definition, $context) : null;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $binding
     * @return ?list<array<string, scalar|null>>
     */
    private function resolveOne(array $binding, BlockDefinitionInterface $definition, RenderContext $context): ?array
    {
        $key = $binding['provider'] ?? null;
        if (!is_string($key) || !in_array($key, $definition->allowedBindingProviders(), true)) {
            return null;
        }
        $provider = $this->providers->get($key);
        if ($provider === null) {
            return null;
        }
        if (!$this->tenants->isScoped() || $this->tenants->id() !== $context->tenantId) {
            return null;
        }

        $params = is_array($binding['params'] ?? null) ? $binding['params'] : [];

        try {
            $rows = $this->providers->resolve(
                $key,
                $params,
                $this->tenants,
                $context->entitlementPredicate(),
                $context->providerPermissionPredicate($provider),
            );
        } catch (StudioException $denied) {
            return null; // entitlement / permission / parameters / unknown provider
        } catch (\Throwable $failure) {
            if (\function_exists('slate_log')) {
                \slate_log('Studio provider ' . $key . ' failed during render: ' . get_class($failure), 'warning');
            }
            return null;
        }

        return self::normalizeRows($rows);
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, scalar|null>>
     */
    public static function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                continue;
            }
            $clean = [];
            foreach ($row as $k => $v) {
                if (!is_string($k) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $k) !== 1) {
                    continue;
                }
                if (is_string($v)) {
                    $clean[$k] = mb_substr($v, 0, self::MAX_STRING_LENGTH, 'UTF-8');
                } elseif (is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                    $clean[$k] = $v;
                }
            }
            $out[] = $clean;
        }
        return $out;
    }
}
