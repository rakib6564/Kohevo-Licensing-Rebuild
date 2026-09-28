<?php
/**
 * Installer-facing authoritative commercial module registry.
 *
 * Commercial entitlement keys are deliberately separate from plugin slugs.
 * Client maintains an explicit authoritative module registry (`AUTHORITATIVE_MODULES`)
 * with full classification metadata (`module_key`, `display_name`, `description`,
 * `plugin_slug`, `v1_available`, `commercial`, `required_infrastructure`, `sort_order`).
 *
 * Filesystem scanning (`PluginLoader::discoverOnDisk()`) is used ONLY to:
 *   1. Verify that the mapped plugin code exists on disk for each V1 module, and
 *   2. Detect duplicate `commercial_module.entitlement` collisions across plugins.
 *
 * Filesystem scanning NEVER decides commercial eligibility: an arbitrary plugin
 * folder under `plugins/` is never treated as a commercial module unless it is
 * explicitly defined in the authoritative registry.
 */
declare(strict_types=1);

namespace Slate\Services\Installation;

final class CommercialModuleRegistry
{
    /**
     * Explicit authoritative module registry for Client.
     *
     * @var list<array{
     *   module_key:string,
     *   display_name:string,
     *   description:string,
     *   plugin_slug:string,
     *   v1_available:bool,
     *   commercial:bool,
     *   required_infrastructure:list<string>,
     *   sort_order:int
     * }>
     */
    private const AUTHORITATIVE_MODULES = [
        [
            'module_key'              => 'forms',
            'display_name'            => 'Forms',
            'description'             => 'Form Builder and submissions',
            'plugin_slug'             => 'forms',
            'v1_available'            => true,
            'commercial'              => true,
            'required_infrastructure' => [],
            'sort_order'              => 10,
        ],
        [
            'module_key'              => 'membership',
            'display_name'            => 'Membership',
            'description'             => 'Membership plans, members and subscriptions',
            'plugin_slug'             => 'membership',
            'v1_available'            => true,
            'commercial'              => true,
            'required_infrastructure' => ['stripe-payment'],
            'sort_order'              => 20,
        ],
        [
            'module_key'              => 'booking',
            'display_name'            => 'Booking',
            'description'             => 'Appointments, schedules and bookings',
            'plugin_slug'             => 'booking',
            'v1_available'            => true,
            'commercial'              => true,
            'required_infrastructure' => ['stripe-payment'],
            'sort_order'              => 30,
        ],
        [
            'module_key'              => 'editor',
            'display_name'            => 'Editor',
            'description'             => 'Visual page and layout editor (Future release)',
            'plugin_slug'             => 'editor',
            'v1_available'            => false,
            'commercial'              => false,
            'required_infrastructure' => [],
            'sort_order'              => 40,
        ],
        [
            'module_key'              => 'content',
            'display_name'            => 'Content',
            'description'             => 'Structured content publishing (Future release)',
            'plugin_slug'             => 'content',
            'v1_available'            => false,
            'commercial'              => false,
            'required_infrastructure' => [],
            'sort_order'              => 50,
        ],
    ];

    /**
     * Supporting infrastructure plugins automatically enabled when required by
     * a selected commercial module. Never selectable as standalone commercial modules.
     *
     * @var array<string,array{slug:string,display_name:string,description:string,required_by:list<string>}>
     */
    private const SUPPORTING_INFRASTRUCTURE = [
        'stripe-payment' => [
            'slug'         => 'stripe-payment',
            'display_name' => 'Stripe Payment',
            'description'  => 'Payment gateway infrastructure automatically enabled when Membership or Booking is selected.',
            'required_by'  => ['membership', 'booking'],
        ],
    ];

    /**
     * Core baseline features included with every valid installation.
     *
     * @return list<array{key:string,display_name:string,description:string}>
     */
    public static function includedCoreFeatures(): array
    {
        return [
            [
                'key'          => 'admin-users',
                'display_name' => 'Admin / Users',
                'description'  => 'User accounts, roles, permissions, and authentication.',
            ],
            [
                'key'          => 'dashboard',
                'display_name' => 'Dashboard',
                'description'  => 'Administrative command centre and license overview.',
            ],
            [
                'key'          => 'site-settings',
                'display_name' => 'Site Settings',
                'description'  => 'Core configuration, localization, and email delivery.',
            ],
        ];
    }

    /**
     * Return supporting infrastructure definitions.
     *
     * @return array<string,array{slug:string,display_name:string,description:string,required_by:list<string>}>
     */
    public static function supportingInfrastructureDefinitions(): array
    {
        return self::SUPPORTING_INFRASTRUCTURE;
    }

    /**
     * Validate and build the full catalog (V1 commercial + Future modules).
     * Explicitly rejects duplicate `module_key` or `plugin_slug` collisions.
     *
     * @param list<array<string,mixed>>|null $rawCatalog
     * @return array<string,array<string,mixed>>
     */
    public static function buildValidatedCatalog(?array $rawCatalog = null): array
    {
        $entries = $rawCatalog ?? self::AUTHORITATIVE_MODULES;
        $seenKeys = [];
        $seenSlugs = [];
        $catalog = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('Module registry entry must be an array.');
            }
            $key = isset($entry['module_key']) && is_string($entry['module_key'])
                ? trim($entry['module_key'])
                : '';
            if ($key === '') {
                throw new \InvalidArgumentException('Module registry entry is missing module_key.');
            }
            if (isset($seenKeys[$key])) {
                throw new \RuntimeException("Duplicate commercial module key '{$key}' in client module registry.");
            }
            $seenKeys[$key] = true;

            $slug = isset($entry['plugin_slug']) && is_string($entry['plugin_slug'])
                ? trim($entry['plugin_slug'])
                : '';
            if ($slug === '') {
                throw new \InvalidArgumentException("Module '{$key}' is missing plugin_slug.");
            }
            if (isset($seenSlugs[$slug])) {
                throw new \RuntimeException(
                    "Duplicate plugin_slug '{$slug}' mapped by both '{$seenSlugs[$slug]}' and '{$key}'."
                );
            }
            $seenSlugs[$slug] = $key;

            $infra = array_values(array_unique(array_filter(
                array_map('strval', (array) ($entry['required_infrastructure'] ?? [])),
                static fn(string $v): bool => $v !== ''
            )));

            $displayName = (string) ($entry['display_name'] ?? $key);
            $description = (string) ($entry['description'] ?? '');

            $catalog[$key] = [
                'module_key'              => $key,
                'entitlement'             => $key,
                'display_name'            => $displayName,
                'label'                   => $displayName,
                'description'             => $description,
                'plugin_slug'             => $slug,
                'plugin'                  => $slug,
                'v1_available'            => (bool) ($entry['v1_available'] ?? false),
                'commercial'              => (bool) ($entry['commercial'] ?? false),
                'required_infrastructure' => $infra,
                'requires_infrastructure' => $infra,
                'sort_order'              => (int) ($entry['sort_order'] ?? 100),
            ];
        }

        uasort($catalog, static fn(array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
        return $catalog;
    }

    /**
     * Return all Future / Non-V1 modules (`editor`, `content`).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function futureDefinitions(): array
    {
        return array_filter(
            self::buildValidatedCatalog(),
            static fn(array $def): bool => !$def['v1_available'] && !$def['commercial']
        );
    }

    /**
     * Return active V1 commercial module definitions verified against plugins on disk.
     *
     * - Explicitly checks all discovered plugins for duplicate `commercial_module.entitlement`
     *   claims and throws `\RuntimeException` on collision (never silently overwrites).
     * - Ignores any plugin on disk that is not in the authoritative V1 commercial catalog.
     *
     * @param list<array<string,mixed>>|null $discoveredPlugins Optional override for testing.
     * @param list<array<string,mixed>>|null $rawCatalog Optional override for testing.
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(?array $discoveredPlugins = null, ?array $rawCatalog = null): array
    {
        $catalog = self::buildValidatedCatalog($rawCatalog);
        $pluginsOnDisk = $discoveredPlugins ?? \PluginLoader::discoverOnDisk();

        // Detect duplicate commercial_module.entitlement declarations across on-disk plugins
        // and index valid on-disk plugins by slug.
        $claimedEntitlements = [];
        $diskBySlug = [];

        foreach ($pluginsOnDisk as $plugin) {
            if (!is_array($plugin)) continue;
            $manifest = (array) ($plugin['manifest'] ?? []);
            $slug = trim((string) ($plugin['slug'] ?? $manifest['slug'] ?? ''));
            if ($slug === '') continue;

            if (isset($diskBySlug[$slug])) {
                throw new \RuntimeException("Duplicate plugin slug '{$slug}' discovered on disk.");
            }
            $diskBySlug[$slug] = $manifest;

            $commercial = $manifest['commercial_module'] ?? null;
            if (!is_array($commercial) || ($commercial['available'] ?? true) === false) {
                continue;
            }
            $entitlement = trim((string) ($commercial['entitlement'] ?? ''));
            if ($entitlement === '') {
                continue;
            }
            if (isset($claimedEntitlements[$entitlement])) {
                $previousSlug = $claimedEntitlements[$entitlement];
                throw new \RuntimeException(
                    "Duplicate commercial module key '{$entitlement}' claimed by plugins '{$previousSlug}' and '{$slug}'."
                );
            }
            $claimedEntitlements[$entitlement] = $slug;
        }

        $out = [];
        foreach ($catalog as $key => $def) {
            if (!$def['commercial'] || !$def['v1_available']) {
                continue;
            }
            $expectedSlug = $def['plugin_slug'];
            if (!isset($diskBySlug[$expectedSlug])) {
                continue;
            }
            $manifest = $diskBySlug[$expectedSlug];
            $commercial = $manifest['commercial_module'] ?? null;
            if (!is_array($commercial) || ($commercial['available'] ?? true) === false) {
                continue;
            }
            $manifestEntitlement = trim((string) ($commercial['entitlement'] ?? ''));
            if ($manifestEntitlement !== $key) {
                continue;
            }

            $out[$key] = $def;
        }

        return $out;
    }

    /**
     * Return V1 commercial modules that are licensed in `$trustedEntitlements`.
     *
     * @param list<array<string,mixed>>|null $discoveredPlugins
     * @return array<string,array<string,mixed>>
     */
    public static function entitledDefinitions(array $trustedEntitlements, ?array $discoveredPlugins = null): array
    {
        $licensed = self::trustedStrings($trustedEntitlements);
        return array_filter(
            self::definitions($discoveredPlugins),
            static fn(array $definition): bool => in_array($definition['entitlement'], $licensed, true)
        );
    }

    /**
     * Validate a client installer module selection against the verified license snapshot.
     *
     * Rejects:
     *   - non-strings or empty strings
     *   - duplicate module keys
     *   - Future / Non-V1 modules (`editor`, `content`)
     *   - Supporting Infrastructure (`stripe`, `stripe-payment`)
     *   - Core or unknown module keys
     *   - Commercial modules not included in `$trustedEntitlements`
     *
     * @param list<array<string,mixed>>|null $discoveredPlugins
     * @return array{ok:bool,selected?:list<string>,error?:string}
     */
    public static function validateSelection(
        array $requested,
        array $trustedEntitlements,
        ?array $discoveredPlugins = null
    ): array {
        $definitions = self::definitions($discoveredPlugins);
        $future = self::futureDefinitions();
        $entitled = self::entitledDefinitions($trustedEntitlements, $discoveredPlugins);

        $seen = [];
        $selected = [];
        foreach ($requested as $raw) {
            if (!is_string($raw) || trim($raw) === '') {
                return ['ok' => false, 'error' => 'Invalid module selection.'];
            }
            $key = trim($raw);
            if (isset($seen[$key])) {
                return ['ok' => false, 'error' => 'A module was selected more than once.'];
            }
            $seen[$key] = true;

            if (isset($future[$key])) {
                return ['ok' => false, 'error' => "Module '{$key}' is a future module and is not available in V1."];
            }
            if ($key === 'stripe' || isset(self::SUPPORTING_INFRASTRUCTURE[$key])) {
                return ['ok' => false, 'error' => "Module '{$key}' is supporting infrastructure and cannot be selected directly."];
            }
            if (!isset($definitions[$key])) {
                return ['ok' => false, 'error' => "One or more selected modules are unknown or not commercial modules."];
            }
            if (!isset($entitled[$key])) {
                return ['ok' => false, 'error' => 'One or more selected modules are not included in this license.'];
            }
            $selected[] = $key;
        }
        return ['ok' => true, 'selected' => $selected];
    }

    /**
     * Resolve supporting infrastructure plugins required by the selected commercial modules.
     * Deduplicates infrastructure slugs (e.g. `stripe-payment` is returned once when both
     * `membership` and `booking` are selected).
     *
     * @param list<array<string,mixed>>|null $discoveredPlugins
     * @return array{ok:bool,infrastructure?:list<string>,error?:string}
     */
    public static function resolveInfrastructure(array $selected, ?array $discoveredPlugins = null): array
    {
        $definitions = self::definitions($discoveredPlugins);
        $pluginsOnDisk = $discoveredPlugins ?? \PluginLoader::discoverOnDisk();

        $needs = [];
        foreach ($selected as $entitlement) {
            if (!is_string($entitlement) || !isset($definitions[$entitlement])) {
                return ['ok' => false, 'error' => 'Unknown commercial module.'];
            }
            foreach ($definitions[$entitlement]['required_infrastructure'] as $slug) {
                $needs[$slug] = true;
            }
        }
        foreach (array_keys($needs) as $slug) {
            $found = false;
            foreach ($pluginsOnDisk as $plugin) {
                if (($plugin['slug'] ?? '') === $slug) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['ok' => false, 'error' => 'Required payment infrastructure is missing from this package.'];
            }
        }
        return ['ok' => true, 'infrastructure' => array_keys($needs)];
    }

    /** @return list<string> */
    private static function trustedStrings(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn($v): string => is_string($v) ? trim($v) : '', $values),
            static fn(string $value): bool => $value !== ''
        )));
    }
}
