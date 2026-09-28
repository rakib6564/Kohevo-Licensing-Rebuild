<?php
/**
 * Licensing — dashboard overview.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';
require_once dirname(__DIR__) . '/ModuleCatalog.php';
require_once dirname(__DIR__) . '/PlanService.php';
require_once dirname(__DIR__) . '/LicenseService.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

$pageTitle  = __('licensing_nav_dashboard', 'Licensing');
$currentNav = 'licensing';

$counts = [
    'products' => (int) Database::value("SELECT COUNT(*) FROM licensing_products"),
    'clients'  => (int) Database::value("SELECT COUNT(*) FROM licensing_clients"),
    'plans_active' => (int) Database::value("SELECT COUNT(*) FROM licensing_plans WHERE is_active = 1"),
    'plans_total'  => (int) Database::value("SELECT COUNT(*) FROM licensing_plans"),
    'bound_installations' => (int) Database::value("SELECT COUNT(*) FROM licensing_installations WHERE status = 'active'"),
    'active'   => (int) Database::value("SELECT COUNT(*) FROM licensing_installs WHERE status IN ('trial','active')"),
    'total'    => (int) Database::value("SELECT COUNT(*) FROM licensing_installs"),
    'checkins_24h' => (int) Database::value("SELECT COUNT(*) FROM licensing_checkins WHERE checked_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)"),
    // Phase 2/3 schema (licensing_licenses) — separate from the legacy
    // licensing_installs counts above; see docs/02-architecture/
    // 13-MIGRATION-STRATEGY.md §4 for why both coexist.
    'licenses_active' => (int) Database::value("SELECT COUNT(*) FROM licensing_licenses WHERE status IN ('trial','active')"),
    'licenses_total'  => (int) Database::value("SELECT COUNT(*) FROM licensing_licenses"),
];
$hasKeypair = LicensingAPI::hasSigningKeypair();

$selectionCatalog = ModuleCatalog::selectionCatalog();
$infrastructureCatalog = ModuleCatalog::supportingInfrastructure();

$recentLicenses = Database::rows(
    "SELECT l.*, c.name AS client_name, p.name AS product_name,
            pl.name AS plan_name, pl.slug AS plan_slug,
            bi.installation_id AS bound_installation_id, bi.domain AS bound_domain
       FROM licensing_licenses l
       JOIN licensing_clients c  ON c.id = l.client_id
       JOIN licensing_products p ON p.id = l.product_id
  LEFT JOIN licensing_plans pl   ON pl.id = l.plan_id
  LEFT JOIN licensing_installations bi ON bi.license_id = l.id AND bi.status = 'active'
   ORDER BY l.created_at DESC, l.id DESC
      LIMIT 10"
);

$recentCheckins = Database::rows(
    "SELECT ck.*, i.label, i.domain, c.name AS client_name
       FROM licensing_checkins ck
       JOIN licensing_installs i ON i.id = ck.install_id
       JOIN licensing_clients c ON c.id = i.client_id
      ORDER BY ck.checked_at DESC LIMIT 10"
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('licensing_nav_dashboard', 'Licensing')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('licensing_nav_dashboard', 'Licensing') ?></h1>
        <p class="page-header-sub"><?= __('licensing_dashboard_sub', 'This install is a central license server — it issues and verifies licenses for other product installs.') ?></p>
    </div>
    <div class="toolbar">
        <a href="<?= e(plugin_url('licensing', 'admin/plans.php')) ?>" class="btn"><?= e(__('licensing_manage_plans', 'Manage plans')) ?></a>
        <a href="<?= e(plugin_url('licensing', 'admin/licenses.php')) ?>" class="btn btn-primary"><?= e(__('licensing_manage_licenses', 'Manage licenses')) ?></a>
    </div>
</div>

<?php if (!$hasKeypair): ?>
    <div class="alert alert-error" role="status">
        <?= __('licensing_no_keypair_warning', 'No signing keypair is provisioned yet — every check-in will fail. Run') ?>
        <code>php bin/licensing-generate-keys.php</code> <?= __('licensing_no_keypair_warning2', 'over SSH once.') ?>
    </div>
<?php endif; ?>

<div class="dash-stats">
    <?php slate_stat_card([
        'icon'    => 'shield',
        'number'  => (int) $counts['licenses_active'] . ' / ' . (int) $counts['licenses_total'],
        'label'   => __('licensing_active_licenses', 'Active / total licenses'),
        'tone'    => $counts['licenses_active'] > 0 ? 'success' : '',
    ]); ?>
    <?php slate_stat_card([
        'icon'    => 'tag',
        'number'  => (int) $counts['plans_active'] . ' / ' . (int) $counts['plans_total'],
        'label'   => __('licensing_plans_nav', 'Active / total plans'),
    ]); ?>
    <?php slate_stat_card([
        'icon'    => 'check-circle',
        'number'  => (int) $counts['bound_installations'],
        'label'   => __('licensing_bound_installations', 'Bound installations'),
    ]); ?>
    <?php slate_stat_card([
        'icon'    => 'users',
        'number'  => (int) $counts['clients'],
        'label'   => __('licensing_clients_nav', 'Clients'),
        'caption' => (int) $counts['products'] . ' ' . strtolower(__('licensing_products_nav', 'Products')) . ' · ' . (int) $counts['checkins_24h'] . ' check-ins (24h)',
    ]); ?>
</div>

<section class="card mb-3">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h2><?= e(__('licensing_module_catalog_heading', 'Commercial Module Catalog')) ?></h2>
        <span class="badge"><?= count($selectionCatalog) + count($infrastructureCatalog) ?> <?= e(__('modules', 'modules')) ?></span>
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

<section class="card mb-3">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h2><?= e(__('licensing_recent_licenses_heading', 'Commercial Licenses & Entitlements')) ?></h2>
        <a href="<?= e(plugin_url('licensing', 'admin/licenses.php')) ?>" class="btn btn-sm"><?= e(__('view_all', 'View all')) ?></a>
    </div>
    <?php if (!$recentLicenses): ?>
        <p class="text-muted"><?= e(__('licensing_no_licenses', 'No licenses issued yet.')) ?></p>
    <?php else: ?>
        <?php
        $statusTone = [
            'active'    => 'active',
            'trial'     => 'info',
            'suspended' => 'warning',
            'expired'   => 'muted',
            'revoked'   => 'danger',
        ];
        ?>
        <div class="data-list" data-single-open>
            <?php foreach ($recentLicenses as $lic):
                $licId      = (int) $lic['id'];
                $licModules = LicenseService::modules($licId);
                $tone       = $statusTone[$lic['status']] ?? 'muted';
                $licLabel   = $lic['label'] ?: ('License #' . $licId);
                $boundLabel = !empty($lic['bound_installation_id'])
                    ? ($lic['bound_domain'] ?: $lic['bound_installation_id'])
                    : 'Unbound';
                $modsLabel  = $licModules ? implode(', ', $licModules) : 'Core only';
                $actions    = '<a href="' . e(plugin_url('licensing', 'admin/license.php?id=' . $licId)) . '" class="btn btn-sm">' . e(__('licensing_view', 'View license')) . '</a>';
                slate_data_row([
                    'avatar'       => mb_strtoupper(mb_substr((string) $lic['client_name'], 0, 1)),
                    'avatar_color' => $tone === 'active' ? 'accent' : 'muted',
                    'title'        => $licLabel . ' — ' . $lic['client_name'],
                    'meta'         => ($lic['plan_name'] ?: $lic['product_name']) . ' · ' . $boundLabel . ' · ' . $modsLabel,
                    'badge'        => [ucfirst((string) $lic['status']), $tone],
                    'detail'       => [
                        __('licensing_col_label', 'License')                   => $licLabel,
                        __('licensing_col_client', 'Client')                   => (string) $lic['client_name'],
                        __('licensing_product', 'Product')                     => (string) $lic['product_name'],
                        __('licensing_col_plan', 'Plan')                       => (string) ($lic['plan_name'] ?: '—'),
                        __('licensing_col_status', 'Status')                   => ucfirst((string) $lic['status']),
                        __('licensing_col_installation', 'Bound Installation') => $boundLabel,
                        __('licensing_col_modules', 'Entitlements')            => $modsLabel,
                        __('licensing_expires', 'Expires')                     => (string) ($lic['expires_at'] ?: 'Never'),
                    ],
                    'actions'      => $actions,
                ]);
            endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h2><?= e(__('licensing_recent_checkins', 'Recent check-ins')) ?></h2>
    </div>
    <?php if (!$recentCheckins): ?>
        <p class="text-muted"><?= e(__('licensing_no_checkins', 'No check-ins recorded yet.')) ?></p>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($recentCheckins as $ck):
                $ckStatus = (string) ($ck['response_status'] ?? 'unknown');
                $ckTone   = in_array($ckStatus, ['active', 'trial', 'ok'], true) ? 'active' : 'muted';
                slate_data_row([
                    'avatar'       => mb_strtoupper(mb_substr((string) ($ck['client_name'] ?: 'C'), 0, 1)),
                    'avatar_color' => $ckTone === 'active' ? 'accent' : 'muted',
                    'title'        => ($ck['label'] ?: $ck['domain']) . ' — ' . $ck['client_name'],
                    'meta'         => $ck['checked_at'] . ' · ' . ($ck['ip'] ?: '—'),
                    'badge'        => [ucfirst($ckStatus), $ckTone],
                    'detail'       => [
                        __('licensing_client', 'Client')       => (string) $ck['client_name'],
                        __('licensing_domain', 'Domain')       => (string) $ck['domain'],
                        __('licensing_status', 'Response')     => $ckStatus,
                        __('ip_address', 'IP Address')         => (string) ($ck['ip'] ?: '—'),
                        __('checked_at', 'Checked at')         => (string) $ck['checked_at'],
                    ],
                ]);
            endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php slate_data_list_script(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
