<?php
/**
 * Licensing — dashboard overview.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

$pageTitle  = __('licensing_nav_dashboard', 'Licensing');
$currentNav = 'licensing';

$counts = [
    'products' => (int) Database::value("SELECT COUNT(*) FROM licensing_products"),
    'clients'  => (int) Database::value("SELECT COUNT(*) FROM licensing_clients"),
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
</div>

<?php if (!$hasKeypair): ?>
    <div class="alert alert-error" role="status">
        <?= __('licensing_no_keypair_warning', 'No signing keypair is provisioned yet — every check-in will fail. Run') ?>
        <code>php bin/licensing-generate-keys.php</code> <?= __('licensing_no_keypair_warning2', 'over SSH once.') ?>
    </div>
<?php endif; ?>

<div class="mcp-stats">
    <a class="stat-card" href="<?= e(plugin_url('licensing', 'admin/licenses.php')) ?>">
        <span class="stat-value"><?= (int) $counts['licenses_active'] ?> / <?= (int) $counts['licenses_total'] ?></span>
        <span class="stat-label"><?= __('licensing_active_licenses', 'Active / total licenses') ?></span>
    </a>
    <a class="stat-card" href="<?= e(plugin_url('licensing', 'admin/products.php')) ?>">
        <span class="stat-value"><?= (int) $counts['products'] ?></span>
        <span class="stat-label"><?= __('licensing_products_nav', 'Products') ?></span>
    </a>
    <a class="stat-card" href="<?= e(plugin_url('licensing', 'admin/clients.php')) ?>">
        <span class="stat-value"><?= (int) $counts['clients'] ?></span>
        <span class="stat-label"><?= __('licensing_clients_nav', 'Clients') ?></span>
    </a>
    <a class="stat-card" href="<?= e(plugin_url('licensing', 'admin/installs.php')) ?>">
        <span class="stat-value"><?= (int) $counts['active'] ?> / <?= (int) $counts['total'] ?></span>
        <span class="stat-label"><?= __('licensing_active_licenses_legacy', 'Active / total (legacy)') ?></span>
    </a>
    <div class="stat-card">
        <span class="stat-value"><?= (int) $counts['checkins_24h'] ?></span>
        <span class="stat-label"><?= __('licensing_checkins_24h', 'Check-ins (24h)') ?></span>
    </div>
</div>

<section class="card mt-3">
    <div class="card-header"><h2><?= e(__('licensing_recent_checkins', 'Recent check-ins')) ?></h2></div>
    <?php if (!$recentCheckins): ?>
        <p class="text-muted"><?= e(__('licensing_no_checkins', 'No check-ins recorded yet.')) ?></p>
    <?php else: ?>
        <div class="data-list">
            <?php foreach ($recentCheckins as $ck): ?>
                <div class="mcp-row">
                    <div>
                        <strong><?= e($ck['label'] ?: $ck['domain']) ?> — <?= e($ck['client_name']) ?></strong>
                        <span><?= e($ck['checked_at']) ?> · <?= e($ck['response_status']) ?> · <?= e($ck['ip'] ?: '—') ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<style>
.mcp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px}
.stat-card{display:flex;flex-direction:column;gap:4px;padding:18px;border:1px solid var(--border,#e5e7eb);border-radius:10px;text-decoration:none;color:inherit}
.stat-value{font-size:1.6rem;font-weight:700}
.stat-label{font-size:.82rem;color:var(--muted,#6b7280)}
.mcp-row{display:flex;justify-content:space-between;align-items:center;gap:16px;border-top:1px solid var(--border,#e5e7eb);padding:14px 0}
.mcp-row:first-child{border-top:0}
.mcp-row strong,.mcp-row span{display:block}
.mcp-row span{margin-top:4px;font-size:.82rem;color:var(--muted,#6b7280)}
@media(max-width:720px){.mcp-stats{grid-template-columns:repeat(2,1fr)}}
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
