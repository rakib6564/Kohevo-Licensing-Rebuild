<?php
/**
 * Kohevo Studio (studio-builder) — Dynamic Data Provider Registry.
 *
 * Mirrors `BlockRegistry`'s shape (explicit register/get, duplicate-key and
 * unknown-key fail-closed) plus the checked execution pipeline a provider
 * additionally needs: entitlement → permission → parameter validation →
 * bounded execution. This is the ONLY way anything in Studio may call a
 * dynamic data provider — there is no dynamic `$providerMap[$_POST['key']]`
 * dispatch anywhere; every key must be registered here by name at boot.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Exception\StudioAuthorizationException;
use Slate\Module\StudioBuilder\Exception\StudioEntitlementException;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Tenancy\TenantContext;

final class DataProviderRegistry
{
    /**
     * @var array<string, DataProviderInterface>
     */
    private array $providers = [];

    /**
     * Register a provider. Rejects an invalid key shape or a duplicate key.
     */
    public function register(DataProviderInterface $provider): void
    {
        $key = $provider->key();
        if (preg_match(CanonicalDocumentSchema::PROVIDER_KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException("Invalid data provider key '{$key}'. Expected 'namespace.name'.");
        }
        if ($provider->maxResults() < 1) {
            throw new \InvalidArgumentException("Data provider '{$key}' must declare maxResults() >= 1.");
        }
        if (isset($this->providers[$key])) {
            throw new \InvalidArgumentException("Duplicate Studio data provider registration for key '{$key}'.");
        }
        $this->providers[$key] = $provider;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): ?DataProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * @return array<string, DataProviderInterface>
     */
    public function all(): array
    {
        ksort($this->providers, SORT_STRING);
        return $this->providers;
    }

    /**
     * Run the full checked pipeline for one provider call and return its
     * bounded, normalized result rows. Fails closed at every step.
     *
     * @param array<string, mixed>       $params
     * @param callable(?string): bool    $entitlementCheck required-entitlement check ($moduleKey === null is never passed)
     * @param callable(string): bool     $permissionCheck  required-permission check
     * @return list<array<string, mixed>>
     */
    public function resolve(
        string $key,
        array $params,
        TenantContext $tenants,
        callable $entitlementCheck,
        callable $permissionCheck,
    ): array {
        $provider = $this->get($key);
        if ($provider === null) {
            throw new StudioNotFoundException("Unknown or unregistered Studio data provider '{$key}'.", ['provider' => $key]);
        }

        $requiredEntitlement = $provider->requiredEntitlement();
        if ($requiredEntitlement !== null && !$entitlementCheck($requiredEntitlement)) {
            throw new StudioEntitlementException(
                "Data provider '{$key}' requires commercial entitlement '{$requiredEntitlement}'.",
                ['provider' => $key, 'required_entitlement' => $requiredEntitlement],
            );
        }

        $requiredPermission = $provider->requiredPermission();
        if (!$permissionCheck($requiredPermission)) {
            throw new StudioAuthorizationException($requiredPermission, "Data provider '{$key}' requires permission '{$requiredPermission}'.");
        }

        $validation = $provider->parameterSchema()->validate($params, '$.params');
        if (!$validation->isValid()) {
            throw new StudioValidationException($validation->errors(), "Invalid parameters for Studio data provider '{$key}'.");
        }
        $normalizedParams = $provider->parameterSchema()->normalize($params);

        $rows = $provider->execute($tenants, $normalizedParams);
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException("Data provider '{$key}' must return a list of rows.");
        }

        $maxResults = $provider->maxResults();
        return array_slice($rows, 0, $maxResults);
    }
}
