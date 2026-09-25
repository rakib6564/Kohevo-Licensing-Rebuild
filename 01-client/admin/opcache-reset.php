<?php
/**
 * Slate — opcache reset.
 *
 * Some shared hosts cache PHP bytecode aggressively. When you upload
 * a fresh `admin/settings.php` (or any other PHP file) and the page
 * still behaves like the old version, opcache is the usual culprit.
 *
 * Visit this URL while signed in as Super Admin to clear the cache.
 * It's a one-shot tool — visit once after each deploy if your host
 * doesn't auto-revalidate.
 */
require_once dirname(__DIR__) . '/config.php';
// Genuinely platform-level (flushes bytecode cache for the whole PHP
// process, not just the current tenant) — gated by the dedicated
// platform-admin concept, not the generic isSuperAdmin() bypass.
// See Phase 0E/0G RBAC audit.
Auth::requirePlatformAdmin();

// Aggressive no-cache so this page itself never gets cached.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$status = [
    'opcache_enabled'   => false,
    'opcache_reset_ok'  => null,
    'realpath_clear_ok' => false,
    'stat_clear_ok'     => false,
    'php_version'       => PHP_VERSION,
];

if (function_exists('opcache_get_status')) {
    $s = @opcache_get_status(false);
    $status['opcache_enabled'] = !empty($s['opcache_enabled']);
    if (function_exists('opcache_reset')) {
        $status['opcache_reset_ok'] = @opcache_reset();
    }
}
if (function_exists('clearstatcache')) {
    clearstatcache(true);
    $status['stat_clear_ok'] = true;
}
if (function_exists('realpath_cache_get')) {
    // No public API to clear; clearstatcache(true) covers it.
    $status['realpath_clear_ok'] = true;
}

$pageTitle  = 'Opcache reset';
$currentNav = 'settings';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Settings',  'href' => SLATE_URL . '/admin/settings.php'],
    ['label' => 'Opcache reset'],
]); ?>

<div class="page-header">
    <div>
        <h1>Opcache reset</h1>
        <p class="page-header-sub">Force PHP to re-read the files you just uploaded.</p>
    </div>
    <a href="<?= e(SLATE_URL) ?>/admin/settings.php" class="btn">← Back to Settings</a>
</div>

<div class="card">
    <ul class="kv-list">
        <li class="kv-row">
            <span class="kv-label">PHP version</span>
            <span class="kv-value kv-mono"><?= e($status['php_version']) ?></span>
        </li>
        <li class="kv-row">
            <span class="kv-label">Opcache enabled</span>
            <span class="kv-value">
                <?php if ($status['opcache_enabled']): ?>
                    <span class="badge badge-active">yes</span>
                <?php else: ?>
                    <span class="badge badge-inactive">no</span>
                <?php endif; ?>
            </span>
        </li>
        <li class="kv-row">
            <span class="kv-label">Opcache reset</span>
            <span class="kv-value">
                <?php if ($status['opcache_reset_ok'] === true): ?>
                    <span class="badge badge-active">cleared ✓</span>
                <?php elseif ($status['opcache_reset_ok'] === false): ?>
                    <span class="badge badge-warning">failed — host may disable opcache_reset()</span>
                <?php else: ?>
                    <span class="badge badge-inactive">opcache not active — nothing to clear</span>
                <?php endif; ?>
            </span>
        </li>
        <li class="kv-row">
            <span class="kv-label">stat cache cleared</span>
            <span class="kv-value"><?= $status['stat_clear_ok'] ? 'yes' : 'no' ?></span>
        </li>
    </ul>
</div>

<div class="card">
    <div class="card-header"><h2>What this fixes</h2></div>
    <p class="text-sm">
        After uploading new PHP files (like the patched <code>admin/settings.php</code>),
        opcache can keep serving the old bytecode until it expires. This page forces
        a flush so the next request reads the fresh files.
    </p>
    <p class="text-sm">
        If opcache_reset is disabled on your host (some shared hosts do this), the only
        alternatives are: change <code>opcache.revalidate_freq</code> in PHP settings,
        rename the file to bust the cache, or wait for the host to recycle PHP-FPM
        (usually within a few minutes).
    </p>
    <p class="text-sm">
        Also: <strong>hard-refresh your browser</strong> (Cmd+Shift+R on Mac,
        Ctrl+F5 on Windows) — sometimes the page is cached at the browser level, not the
        server level.
    </p>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
