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
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);

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
