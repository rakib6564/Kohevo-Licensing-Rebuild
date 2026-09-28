<?php
/**
 * Licensing plugin — bootstrap.
 *
 * The central license server for standalone product installs (see
 * LICENSE-SYSTEM-MASTER-SPEC/ and the narrower build plan agreed in chat).
 * Runs on its own dedicated, single-tenant Kohevo install — never alongside
 * the product plugins (Booking/Forms/etc.) it issues licenses for.
 *
 * The check-in endpoint at /licensing/check, Ed25519 signing, and the data
 * model are joined here by a minimal admin UI for managing products,
 * clients, plans, and issuing/rotating license keys.
 */

require_once __DIR__ . '/LicensingAPI.php';
// Phase 2: Central Licensing Platform Foundation — Plan/License/Installation
// domain services. Not yet wired into any route or admin screen (a later
// phase); required here so they are always available wherever the plugin is
// loaded, same as LicensingAPI above.
require_once __DIR__ . '/PlanService.php';
require_once __DIR__ . '/LicenseService.php';
require_once __DIR__ . '/InstallationService.php';
// Phase 13: the legacy (licensing_installs) screens may only restrict.
require_once __DIR__ . '/LegacyLicensePolicy.php';

class Licensing extends Plugin {

    public function boot(): void {
        // Schema self-heal, stamped once per version — mirrors Membership.
        if ((string) $this->setting('schema_verified', '') !== $this->version) {
            LicensingAPI::ensureSchema();
            $this->setSetting('schema_verified', $this->version);
        }

        Hook::addFilter('public_routes', [$this, 'addPublicRoutes']);
        Hook::addFilter('api_v1_routes', [$this, 'addApiRoutes']);
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addAdminDashboardWidget']);

        // Persists the Active/Trial -> Expired transition once a day for any
        // License nobody happens to view in the admin in the meantime — see
        // LicenseService::sweepExpired(). Mirrors config.php's identical
        // `daily_cron` registration for the unrelated per-tenant
        // \Slate\Services\Licensing\LicenseService.
        Hook::addAction('daily_cron', [$this, 'sweepExpiredLicenses']);
    }

    public function sweepExpiredLicenses(): void {
        try {
            LicenseService::sweepExpired();
        } catch (\Throwable $e) {
            slate_log('Licensing plugin: license expiry sweep failed: ' . $e->getMessage(), 'error');
        }
    }

    public function addPublicRoutes(array $routes): array {
        $routes['licensing/check'] = [
            'handler' => $this->dir('public/check.php'),
            'methods' => ['POST'],
        ];
        return $routes;
    }

    public function addApiRoutes(array $routes): array {
        $routes['licensing'] = function(string $subPath, string $method, $auth) {
            if (($subPath === 'check-in' || $subPath === 'check') && $method === 'POST') {
                $raw = (string) file_get_contents('php://input');
                $input = json_decode($raw, true) ?: [];
                $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $result = LicensingAPI::handleCheckIn($input, $ip);
                http_response_code((int) $result['http_status']);
                echo json_encode($result['body']);
                exit;
            }
            http_response_code(404);
            echo json_encode(['error' => 'not_found']);
            exit;
        };
        return $routes;
    }

    public function addAdminDashboardWidget(array $widgets): array {
        if (!Auth::can('licensing.manage') && !Auth::isSuperAdmin()) return $widgets;

        require_once __DIR__ . '/ModuleCatalog.php';
        $selectionCatalog = ModuleCatalog::selectionCatalog();
        $infrastructureCatalog = ModuleCatalog::supportingInfrastructure();

        ob_start();
        ?>
        <section class="card mb-3">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <h2><?= e(__('licensing_module_catalog_heading', 'Commercial Module Catalog')) ?></h2>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="badge"><?= count($selectionCatalog) + count($infrastructureCatalog) ?> <?= e(__('modules', 'modules')) ?></span>
                    <a href="<?= e(plugin_url('licensing', 'admin/index.php')) ?>" class="btn btn-sm"><?= e(__('view_all', 'View all')) ?></a>
                </div>
            </div>
            <div class="data-list" data-single-open>
                <?php foreach ($selectionCatalog as $mKey => $mMeta):
                    $isV1 = !empty($mMeta['v1_available']);
                    $classification = $isV1 ? 'V1 Commercial' : 'Not V1 / Future';
                    $deps = !empty($mMeta['dependencies']) ? implode(', ', $mMeta['dependencies']) : 'None';
                    slate_data_row([
                        'avatar'       => mb_strtoupper(mb_substr((string) $mMeta['display_name'], 0, 1)),
                        'avatar_color' => $isV1 ? 'accent' : 'muted',
                        'title'        => (string) $mMeta['display_name'],
                        'meta'         => $mKey . ' · ' . (string) $mMeta['description'],
                        'badge'        => [$classification, $isV1 ? 'active' : 'muted'],
                        'detail'       => [
                            __('licensing_col_module', 'Module')                       => (string) $mMeta['display_name'],
                            __('licensing_col_key', 'Entitlement Key')                 => (string) $mKey,
                            __('licensing_col_plugin', 'Plugin Slug')                  => (string) $mMeta['plugin_identifier'],
                            __('licensing_col_status', 'Classification')               => $classification,
                            __('licensing_col_dependencies', 'Infrastructure Dependencies') => $deps,
                            __('description', 'Description')                           => (string) $mMeta['description'],
                        ],
                    ]);
                endforeach; ?>
                <?php foreach ($infrastructureCatalog as $iKey => $iMeta):
                    slate_data_row([
                        'avatar'       => mb_strtoupper(mb_substr((string) $iMeta['display_name'], 0, 1)),
                        'avatar_color' => 'info',
                        'title'        => (string) $iMeta['display_name'],
                        'meta'         => (string) $iMeta['plugin_identifier'] . ' · ' . (string) $iMeta['description'],
                        'badge'        => ['Supporting Infrastructure', 'info'],
                        'detail'       => [
                            __('licensing_col_module', 'Module')                       => (string) $iMeta['display_name'],
                            __('licensing_col_key', 'Entitlement Key')                 => 'N/A (auto-provisioned)',
                            __('licensing_col_plugin', 'Plugin Slug')                  => (string) $iMeta['plugin_identifier'],
                            __('licensing_col_status', 'Classification')               => 'Supporting Infrastructure',
                            __('licensing_col_dependencies', 'Infrastructure Dependencies') => 'Auto-enabled for Membership / Booking',
                            __('description', 'Description')                           => (string) $iMeta['description'],
                        ],
                    ]);
                endforeach; ?>
            </div>
        </section>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('licensing.manage') && !Auth::isSuperAdmin()) return $items;

        $items[] = ['slug' => 'licensing', 'label' => __('licensing_nav_dashboard', 'Dashboard'),
                    'href' => $this->url('admin/index.php'),
                    'icon' => 'key', 'perm' => 'licensing.manage', 'order' => 500, 'group' => 'licensing'];
        // Phase 3: the new schema's screen (licensing_licenses/licensing_
        // installations/licensing_license_modules, via LicenseService) is now
        // the primary "Licenses" entry — it supersedes installs.php for new
        // licenses per docs/02-architecture/13-MIGRATION-STRATEGY.md §2.
        $items[] = ['slug' => 'licensing-licenses', 'label' => __('licensing_nav_licenses', 'Licenses'),
                    'href' => $this->url('admin/licenses.php'),
                    'icon' => 'shield', 'perm' => 'licensing.manage', 'order' => 501, 'group' => 'licensing'];
        // Kept, unmodified: the live public check-in endpoint still validates
        // against licensing_installs (docs/02-architecture/
        // 13-MIGRATION-STRATEGY.md §4), so admins need continued access to
        // manage any license issued through this legacy path.
        $items[] = ['slug' => 'licensing-installs', 'label' => __('licensing_nav_installs', 'Licenses (Legacy)'),
                    'href' => $this->url('admin/installs.php'),
                    'icon' => 'shield', 'perm' => 'licensing.manage', 'order' => 502, 'group' => 'licensing'];
        $items[] = ['slug' => 'licensing-clients', 'label' => __('licensing_nav_clients', 'Clients'),
                    'href' => $this->url('admin/clients.php'),
                    'icon' => 'users', 'perm' => 'licensing.manage', 'order' => 503, 'group' => 'licensing'];
        $items[] = ['slug' => 'licensing-products', 'label' => __('licensing_nav_products', 'Products'),
                    'href' => $this->url('admin/products.php'),
                    'icon' => 'box', 'perm' => 'licensing.manage', 'order' => 504, 'group' => 'licensing'];
        $items[] = ['slug' => 'licensing-plans', 'label' => __('licensing_nav_plans', 'Plans'),
                    'href' => $this->url('admin/plans.php'),
                    'icon' => 'tag', 'perm' => 'licensing.manage', 'order' => 505, 'group' => 'licensing'];
        return $items;
    }
}
