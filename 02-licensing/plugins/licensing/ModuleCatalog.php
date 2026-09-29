<?php
/**
 * Licensing plugin — Authoritative Central Module Catalog.
 *
 * Defines the canonical classification of every module and plugin known to the
 * Kohevo platform so Plan templates, License entitlements, Central Admin UI,
 * and signed payload generation share one strict source of truth.
 *
 * Classifications:
 *   - V1 Commercial (`commercial = true, v1_available = true, status = 'available'`):
 *       forms, membership, booking
 *   - Future / Non-V1 (`commercial = false, v1_available = false, status = 'future'`):
 *       editor, content
 *   - Supporting Infrastructure (`commercial = false, v1_available = false, status = 'infrastructure'`):
 *       stripe-payment (required by membership and booking; never a commercial entitlement)
 *   - System / Platform (`commercial = false, v1_available = false, status = 'system'`):
 *       media-library, backups, mcp-gateway, multilang-translate, coaching, licensing
 */

declare(strict_types=1);

final class ModuleCatalog
{
    /**
     * Core feature keys. Core is always included with a valid license and can
     * never be stored or granted as an optional module entitlement (INV-06).
     */
    public const CORE_MODULE_KEYS = [
        'core',
        'admin-user',
        'admin_user',
        'admin',
        'users',
        'dashboard',
        'site-settings',
        'site_settings',
        'settings',
    ];

    /**
     * Authoritative catalog definitions.
     *
     * @var list<array{
     *     module_key:string,
     *     display_name:string,
     *     description:string,
     *     status:string,
     *     commercial:bool,
     *     v1_available:bool,
     *     plugin_identifier:string,
     *     dependencies:list<string>,
     *     sort_order:int
     * }>
     */
    private const RAW_DEFINITIONS = [
        [
            'module_key'        => 'forms',
            'display_name'      => 'Forms',
            'description'       => 'Multi-step forms, submissions, PDF export, and conditional logic.',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'forms',
            'dependencies'      => [],
            'sort_order'        => 10,
        ],
        [
            'module_key'        => 'membership',
            'display_name'      => 'Membership',
            'description'       => 'Plans, subscriptions, member portal, and recurring access.',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'membership',
            'dependencies'      => ['stripe-payment'],
            'sort_order'        => 20,
        ],
        [
            'module_key'        => 'booking',
            'display_name'      => 'Booking',
            'description'       => 'Services, providers, calendar availability, and appointments.',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'booking',
            'dependencies'      => ['stripe-payment'],
            'sort_order'        => 30,
        ],
        [
            'module_key'        => 'studio-builder',
            'display_name'      => 'Kohevo Studio',
            'description'       => 'Structured visual page, layout, and experience builder.',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'studio-builder',
            'dependencies'      => [],
            'sort_order'        => 39,
        ],
        [
            'module_key'        => 'editor',
            'display_name'      => 'Editor',
            'description'       => 'Visual page editor (Future / Not in V1).',
            'status'            => 'future',
            'commercial'        => false,
            'v1_available'      => false,
            'plugin_identifier' => 'editor',
            'dependencies'      => [],
            'sort_order'        => 40,
        ],
        [
            'module_key'        => 'content',
            'display_name'      => 'Content',
            'description'       => 'Pages, posts, templates, and navigation (Future / Not in V1).',
            'status'            => 'future',
            'commercial'        => false,
            'v1_available'      => false,
            'plugin_identifier' => 'content',
            'dependencies'      => [],
            'sort_order'        => 50,
        ],
        [
            'module_key'        => 'stripe-payment',
            'display_name'      => 'Stripe Payment',
            'description'       => 'Supporting payment gateway infrastructure automatically enabled for Membership and Booking.',
            'status'            => 'infrastructure',
            'commercial'        => false,
            'v1_available'      => false,
            'plugin_identifier' => 'stripe-payment',
            'dependencies'      => [],
            'sort_order'        => 60,
        ],
        [
            'module_key'        => 'media-library',
            'display_name'      => 'Media Library',
            'description'       => 'System media management plugin.',
            'status'            => 'system',
            'commercial'        => false,
            'v1_available'      => false,
            'plugin_identifier' => 'media-library',
            'dependencies'      => [],
            'sort_order'        => 100,
        ],
        [
            'module_key'        => 'backups',
            'display_name'      => 'Backups',
            'description'       => 'Platform backup utility.',
            'status'            => 'system',
            'commercial'        => false,
            'v1_available'      => false,
            'plugin_identifier' => 'backups',
            'dependencies'      => [],
            'sort_order'        => 110,
        ],
        [
            'module_key'        => 'mcp-gateway',
            'display_name'      => 'MCP Gateway',
            'description'       => 'Model Context Protocol gateway and AI assistant hub.',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'mcp-gateway',
            'dependencies'      => [],
            'sort_order'        => 35,
        ],
        [
            'module_key'        => 'multilang-translate',
            'display_name'      => 'Multi Language Visual Translation',
            'description'       => 'Visual translation utility.',
            'status'            => 'system',
            'commercial'        => false,
            'v1_available'      => false,
            'plugin_identifier' => 'multilang-translate',
            'dependencies'      => [],
            'sort_order'        => 130,
        ],
        [
            'module_key'        => 'coaching',
            'display_name'      => 'Coaching',
            'description'       => 'Client coaching session, goal, and exercise management.',
            'status'            => 'available',
            'commercial'        => true,
            'v1_available'      => true,
            'plugin_identifier' => 'coaching',
            'dependencies'      => [],
            'sort_order'        => 38,
        ],
        [
            'module_key'        => 'licensing',
            'display_name'      => 'Licensing',
            'description'       => 'Central licensing server plugin.',
            'status'            => 'system',
            'commercial'        => false,
            'v1_available'      => false,
            'plugin_identifier' => 'licensing',
            'dependencies'      => [],
            'sort_order'        => 150,
        ],
    ];

    /**
     * Validate and index a list of module catalog definitions, rejecting any
     * duplicate `module_key` or `plugin_identifier` collision rather than
     * silently overwriting an earlier definition.
     *
     * @param list<array<string,mixed>> $entries
     * @return array<string,array{
     *     module_key:string,
     *     display_name:string,
     *     description:string,
     *     status:string,
     *     commercial:bool,
     *     v1_available:bool,
     *     plugin_identifier:string,
     *     dependencies:list<string>,
     *     sort_order:int
     * }>
     */
    public static function buildValidatedCatalog(array $entries): array
    {
        $out = [];
        $seenPlugins = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('Catalog entry must be an array.');
            }
            $key = isset($entry['module_key']) && is_string($entry['module_key']) ? trim($entry['module_key']) : '';
            if ($key === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key)) {
                throw new \InvalidArgumentException('Invalid catalog module_key.');
            }
            if (isset($out[$key])) {
                throw new \InvalidArgumentException("Duplicate commercial module key \"{$key}\" in catalog.");
            }
            if (in_array($key, self::CORE_MODULE_KEYS, true)) {
                throw new \InvalidArgumentException("Core key \"{$key}\" cannot be registered in the module catalog.");
            }

            $pluginId = isset($entry['plugin_identifier']) && is_string($entry['plugin_identifier'])
                ? trim($entry['plugin_identifier'])
                : '';
            if ($pluginId === '') {
                throw new \InvalidArgumentException("Catalog entry \"{$key}\" is missing plugin_identifier.");
            }
            if (isset($seenPlugins[$pluginId])) {
                throw new \InvalidArgumentException(
                    "Duplicate plugin_identifier \"{$pluginId}\" shared by \"{$seenPlugins[$pluginId]}\" and \"{$key}\"."
                );
            }
            $seenPlugins[$pluginId] = $key;

            $status = isset($entry['status']) && is_string($entry['status']) ? $entry['status'] : '';
            if (!in_array($status, ['available', 'future', 'infrastructure', 'system'], true)) {
                throw new \InvalidArgumentException("Catalog entry \"{$key}\" has invalid status \"{$status}\".");
            }

            $commercial  = (bool) ($entry['commercial'] ?? false);
            $v1Available = (bool) ($entry['v1_available'] ?? false);
            if ($commercial && (!$v1Available || $status !== 'available')) {
                throw new \InvalidArgumentException("Commercial module \"{$key}\" must be v1_available and status=available.");
            }

            $deps = [];
            foreach (($entry['dependencies'] ?? []) as $dep) {
                if (!is_string($dep) || trim($dep) === '') {
                    throw new \InvalidArgumentException("Invalid dependency on module \"{$key}\".");
                }
                $depKey = trim($dep);
                if (!in_array($depKey, $deps, true)) {
                    $deps[] = $depKey;
                }
            }

            $out[$key] = [
                'module_key'        => $key,
                'display_name'      => (string) ($entry['display_name'] ?? $key),
                'description'       => (string) ($entry['description'] ?? ''),
                'status'            => $status,
                'commercial'        => $commercial,
                'v1_available'      => $v1Available,
                'plugin_identifier' => $pluginId,
                'dependencies'      => $deps,
                'sort_order'        => (int) ($entry['sort_order'] ?? 100),
            ];
        }

        uasort($out, static fn (array $a, array $b): int => ($a['sort_order'] <=> $b['sort_order']) ?: strcmp($a['module_key'], $b['module_key']));
        return $out;
    }

    /**
     * Return all known catalog definitions keyed by `module_key`.
     */
    public static function all(): array
    {
        static $catalog = null;
        return $catalog ??= self::buildValidatedCatalog(self::RAW_DEFINITIONS);
    }

    /**
     * Return a single catalog definition or null if unknown.
     */
    public static function get(string $moduleKey): ?array
    {
        return self::all()[$moduleKey] ?? null;
    }

    /**
     * Return only V1 selectable commercial modules (`forms`, `membership`, `booking`).
     */
    public static function v1Commercial(): array
    {
        return array_filter(
            self::all(),
            static fn (array $def): bool => $def['commercial'] === true && $def['v1_available'] === true && $def['status'] === 'available'
        );
    }

    /**
     * Return the list of V1 commercial module keys in canonical sort order.
     *
     * @return list<string>
     */
    public static function v1CommercialKeys(): array
    {
        return array_values(array_keys(self::v1Commercial()));
    }

    /**
     * Return Future / Non-V1 modules (`editor`, `content`).
     */
    public static function futureModules(): array
    {
        return array_filter(
            self::all(),
            static fn (array $def): bool => $def['status'] === 'future'
        );
    }

    /**
     * Return Supporting Infrastructure modules (`stripe-payment`).
     */
    public static function infrastructureModules(): array
    {
        return array_filter(
            self::all(),
            static fn (array $def): bool => $def['status'] === 'infrastructure'
        );
    }

    /**
     * Alias for infrastructureModules().
     */
    public static function supportingInfrastructure(): array
    {
        return self::infrastructureModules();
    }

    /**
     * Return the UI catalog for Central Plan and License screens:
     * V1 commercial modules (selectable) + Future modules (disabled, "Not V1 / Future").
     */
    public static function selectionCatalog(): array
    {
        $items = [];
        foreach (self::all() as $key => $def) {
            if ($def['commercial'] && $def['v1_available']) {
                $items[$key] = $def + [
                    'selectable' => true,
                    'badge'      => 'V1 Commercial',
                ];
            } elseif ($def['status'] === 'future') {
                $items[$key] = $def + [
                    'selectable' => false,
                    'badge'      => 'Not V1 / Future',
                ];
            }
        }
        return $items;
    }

    /**
     * Strictly validate a list of requested commercial module keys for a Plan
     * or License.
     *
     * Rejects:
     *   - non-string or empty keys
     *   - duplicate module keys
     *   - Core keys (`core`, `dashboard`, `admin`, `users`, `settings`, ...)
     *   - Future / Non-V1 keys (`editor`, `content`)
     *   - Supporting infrastructure keys (`stripe`, `stripe-payment`)
     *   - System / non-commercial / unknown keys (`media-library`, `backups`, ...)
     *
     * @param array<mixed> $requestedKeys
     * @return list<string> Validated V1 commercial keys in canonical catalog order
     * @throws \InvalidArgumentException
     */
    public static function validateCommercialSelection(array $requestedKeys): array
    {
        $catalog = self::all();
        $seen = [];

        foreach ($requestedKeys as $rawKey) {
            if (!is_string($rawKey)) {
                throw new \InvalidArgumentException('Module key must be a non-empty string.');
            }
            $key = trim($rawKey);
            if ($key === '') {
                throw new \InvalidArgumentException('Module key must be a non-empty string.');
            }
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("Duplicate module key \"{$key}\" is not allowed.");
            }
            if (in_array($key, self::CORE_MODULE_KEYS, true)) {
                throw new \InvalidArgumentException(
                    "Core module \"{$key}\" is always included with a valid license and cannot be granted as an optional module."
                );
            }
            if ($key === 'stripe' || $key === 'stripe-payment' || (($catalog[$key]['status'] ?? '') === 'infrastructure')) {
                throw new \InvalidArgumentException(
                    "Supporting infrastructure \"{$key}\" is managed automatically and cannot be selected as a commercial module."
                );
            }
            if (!isset($catalog[$key])) {
                throw new \InvalidArgumentException("Unknown module key \"{$key}\".");
            }
            $def = $catalog[$key];
            if ($def['status'] === 'future' || !$def['v1_available']) {
                throw new \InvalidArgumentException(
                    "Module \"{$def['display_name']}\" ({$key}) is Not V1 / Future and cannot be enabled."
                );
            }
            if (!$def['commercial'] || $def['status'] !== 'available') {
                throw new \InvalidArgumentException(
                    "Module \"{$key}\" is not a selectable commercial module."
                );
            }
            $seen[$key] = true;
        }

        $ordered = [];
        foreach (self::v1CommercialKeys() as $validKey) {
            if (isset($seen[$validKey])) {
                $ordered[] = $validKey;
            }
        }
        return $ordered;
    }

    /**
     * Resolve supporting infrastructure dependencies for a set of commercial
     * module keys, deduplicated in catalog order.
     *
     * @param list<string> $commercialKeys
     * @return list<string>
     */
    public static function resolveDependencies(array $commercialKeys): array
    {
        $catalog = self::all();
        $deps = [];
        foreach ($commercialKeys as $key) {
            if (!isset($catalog[$key])) {
                continue;
            }
            foreach ($catalog[$key]['dependencies'] as $dep) {
                $deps[$dep] = true;
            }
        }
        return array_values(array_keys($deps));
    }
}
