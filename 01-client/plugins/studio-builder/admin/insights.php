<?php
/**
 * Kohevo Studio — Site Insights: Search Console, Analytics and PageSpeed for this site, in the admin.
 *
 * Anyone who can view Studio reads the figures; connecting Google and choosing properties is for Studio administrators.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_insights.php';

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Insights\DbInsightsCache;
use Slate\Module\StudioBuilder\Insights\GoogleApiException;
use Slate\Module\StudioBuilder\Insights\GoogleConnection;
use Slate\Module\StudioBuilder\Insights\InsightsRange;
use Slate\Module\StudioBuilder\Insights\InsightsReports;
use Slate\Module\StudioBuilder\Insights\SettingsInsightsStore;

Auth::require();
Auth::requirePerm('studio-builder.view');

$tenantId = current_tenant_id();
$isAdmin = Auth::can('studio-builder.admin') || Auth::isSuperAdmin();
$canRefresh = $isAdmin || Auth::can('studio-builder.edit');
$pageTitle = __('studio_insights', 'Site Insights');
$currentNav = 'studio-insights';

$connection = sb_insights_connection($tenantId);
$store = new SettingsInsightsStore($tenantId);
$cache = new DbInsightsCache($tenantId);
$settingsUrl = 'insights.php?tab=settings';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['_action'] ?? '');
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } elseif ($action === 'refresh' && $canRefresh) {
        $cache->clear();
        sb_flash_redirect('insights.php?' . http_build_query(['tab' => $_POST['tab'] ?? 'key', 'range' => (int) ($_POST['range'] ?? 0), 'url' => $_POST['url'] ?? '']), ['type' => 'success', 'msg' => __('studio_insights_refreshed', 'Fresh figures are on their way from Google.')]);
    } elseif (!$isAdmin) {
        $flash = ['type' => 'error', 'msg' => __('studio_insights_admin_only', 'Only a Studio administrator can connect Google.')];
    } else {
        try {
            switch ($action) {
                case 'save_client':
                    $clientId = trim((string) ($_POST['client_id'] ?? ''));
                    if (preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $clientId) !== 1) {
                        $flash = ['type' => 'error', 'msg' => __('studio_insights_bad_client', 'That is not a Client ID. It ends with .apps.googleusercontent.com.')];
                        break;
                    }
                    $connection->saveClient($clientId, (string) ($_POST['client_secret'] ?? ''), (string) ($_POST['api_key'] ?? ''));
                    AuditLog::record('studio.google_client_saved', (string) $tenantId);
                    sb_flash_redirect($settingsUrl, ['type' => 'success', 'msg' => __('studio_insights_client_saved', 'Saved. The secret and key are encrypted.')]);
                    // no break: the redirect ends the request
                case 'connect':
                    $attempt = GoogleConnection::newAttempt() + ['mode' => GoogleConnection::MODE_CENTRAL];
                    $address = $connection->centralAuthUrl($attempt['state']);
                    $_SESSION['sb_google_attempt'] = $attempt;
                    header('Location: ' . $address, true, 303);
                    exit;
                case 'connect_own':
                    if ($connection->isConfigured()) {
                        $attempt = GoogleConnection::newAttempt() + ['mode' => GoogleConnection::MODE_OWN];
                        $_SESSION['sb_google_attempt'] = $attempt;
                        header('Location: ' . $connection->authUrl($attempt), true, 303);
                        exit;
                    }
                    break;
                case 'save_choices':
                    $client = sb_insights_client($tenantId);
                    $site = (string) ($_POST['search_site'] ?? '');
                    $property = (string) ($_POST['analytics_property'] ?? '');
                    $sites = $site === '' ? [] : array_column($client->searchSites(), 'url');
                    $properties = $property === '' ? [] : array_column($client->analyticsProperties(), 'name', 'property');
                    if (($site !== '' && !in_array($site, $sites, true)) || ($property !== '' && !isset($properties[$property]))) {
                        $flash = ['type' => 'error', 'msg' => __('studio_insights_bad_choice', 'This Google account cannot read that property.')];
                        break;
                    }
                    sb_insights_choose($tenantId, $site, $property, (string) ($properties[$property] ?? ''));
                    AuditLog::record('studio.google_properties_saved', (string) $tenantId, ['search' => $site, 'analytics' => $property]);
                    sb_flash_redirect('insights.php', ['type' => 'success', 'msg' => __('studio_insights_choices_saved', 'Saved. Your Google data is below.')]);
                    // no break: the redirect ends the request
                case 'use_tag':
                    $tag = StudioCodePolicy::ga4Id($store->get(GoogleConnection::MEASUREMENT_ID));
                    if ($tag !== null && $tag !== '') {
                        Database::setSetting(StudioCodePolicy::SETTING_GA4, $tag, $tenantId);
                        AuditLog::record('studio.code_tracking_updated', (string) $tenantId, ['ga4' => true, 'source' => 'site-insights']);
                        sb_flash_redirect($settingsUrl, ['type' => 'success', 'msg' => __('studio_insights_tag_saved', 'The Google tag now loads on every public page.')]);
                    }
                    break;
                case 'disconnect':
                    $connection->disconnect();
                    $cache->clear();
                    AuditLog::record('studio.google_disconnected', (string) $tenantId);
                    sb_flash_redirect($settingsUrl, ['type' => 'success', 'msg' => __('studio_insights_disconnected', 'Disconnected from Google. The saved figures are gone.')]);
            }
        } catch (GoogleApiException $e) {
            error_log('[studio-builder] site insights: ' . $e->getMessage());
            $flash = ['type' => 'error', 'msg' => $e->reason === GoogleApiException::NOT_SECURE ? __('studio_insights_no_secret', 'APP_SECRET is not set on this site, so Google credentials cannot be stored safely.') : sb_insights_problem($e)];
        }
    }
}
$flash = $flash ?? sb_take_flash();

$site = $store->get(GoogleConnection::SEARCH_SITE);
$property = $store->get(GoogleConnection::ANALYTICS_PROPERTY);
$ready = $connection->isConnected() && ($site !== '' || $property !== '');

$base = sb_insights_base($tenantId);
$urlInput = is_string($_GET['url'] ?? null) ? $_GET['url'] : '';
$path = InsightsReports::normalizePath($urlInput, $base);
if ($path === null) {
    $flash = $flash ?? ['type' => 'warning', 'msg' => __('studio_insights_other_site', 'That address is on another website, so all of this site is shown.')];
    $path = '';
}
$range = InsightsRange::of($_GET['range'] ?? InsightsRange::DEFAULT);
$tabs = sb_insights_tabs($path !== '');
$tab = (string) ($_GET['tab'] ?? '');
$tab = isset($tabs[$tab]) || ($tab === 'settings' && $isAdmin) ? $tab : ($ready ? 'key' : ($isAdmin ? 'settings' : 'key'));
$tabLabels = [
    'key' => __('studio_insights_tab_key', 'Key metrics'),
    'traffic' => __('studio_insights_tab_traffic', 'Traffic'),
    'content' => __('studio_insights_tab_content', 'Content'),
    'speed' => __('studio_insights_tab_speed', 'Speed'),
];
if ($isAdmin) {
    $tabLabels['settings'] = __('studio_insights_tab_settings', 'Settings');
}
$tabUrl = static fn(string $to): string => 'insights.php?' . http_build_query(['tab' => $to, 'range' => $range->days, 'url' => $path]);

// Live lists for the Settings tab; what Google refuses is shown in place of the list.
$sites = $properties = [];
$listProblem = '';
if ($tab === 'settings' && $connection->isConnected()) {
    try {
        $client = sb_insights_client($tenantId);
        $sites = $client->searchSites();
        $properties = $client->analyticsProperties();
    } catch (GoogleApiException $e) {
        error_log('[studio-builder] site insights: ' . $e->getMessage());
        $listProblem = sb_insights_problem($e);
    }
}
$pagePaths = $tab !== 'settings'
    ? array_map(static fn(array $row): string => '/' . ($row['route_mode'] === 'homepage' ? '' : $row['slug']), Database::rows("SELECT slug, route_mode FROM studiobuilder_pages WHERE tenant_id = ? AND page_type IN ('page','landing') AND status = 'published' ORDER BY title LIMIT 200", [$tenantId]))
    : [];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <div class="sb-screen-head">
        <div>
            <h2><?= e(__('studio_insights', 'Site Insights')) ?></h2>
            <p><?= e(__('studio_insights_intro', 'How people find and use your site, from Google Search Console, Analytics and PageSpeed.')) ?></p>
        </div>
        <?php if ($connection->isConnected()): ?>
            <span class="sb-muted"><?= e(sprintf(__('studio_insights_connected_as', 'Connected as %s'), $connection->email())) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($connection->needsReconnect()): ?>
        <div class="alert alert-warning sb-alert" role="alert"><?= e(__('studio_insights_err_reconnect', 'Google no longer accepts this connection. Connect again from Settings.')) ?></div>
    <?php endif; ?>

    <nav class="sb-pill-filter sb-insights-tabs" aria-label="<?= e(__('studio_insights', 'Site Insights')) ?>">
        <?php foreach ($tabLabels as $key => $label): ?>
            <a class="sb-pill-item<?= $key === $tab ? ' active' : '' ?>" href="<?= e($tabUrl($key)) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($tab === 'settings'): ?>
        <?php require __DIR__ . '/_insights_settings.php'; ?>
    <?php elseif (!$ready): ?>
        <div class="sb-empty" data-testid="insights-not-connected">
            <h3><?= e(__('studio_insights_not_connected', 'Google is not connected yet')) ?></h3>
            <p><?= e($isAdmin ? __('studio_insights_not_connected_admin', 'Connect this site to Google in Settings to see its data here.') : __('studio_insights_not_connected_viewer', 'Ask a Studio administrator to connect this site to Google.')) ?></p>
            <?php if ($isAdmin): ?><p><a class="btn btn-primary" href="<?= e($settingsUrl) ?>"><?= e(__('studio_insights_open_settings', 'Open Settings')) ?></a></p><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="sb-toolbar sb-insights-toolbar">
            <form method="get" class="sb-search" role="search" data-insights-filters>
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <?= sb_svg('search', 15) ?>
                <input type="search" name="url" class="form-control" list="sb-insights-pages" value="<?= e($path) ?>" placeholder="<?= e(__('studio_insights_url_search', 'URL search: a page on this site')) ?>" aria-label="<?= e(__('studio_insights_url_search', 'URL search: a page on this site')) ?>">
                <datalist id="sb-insights-pages"><?php foreach ($pagePaths as $option): ?><option value="<?= e($option) ?>"></option><?php endforeach; ?></datalist>
                <select name="range" class="form-control" aria-label="<?= e(__('studio_insights_range', 'Date range')) ?>">
                    <?php foreach (InsightsRange::CHOICES as $days): ?>
                        <option value="<?= $days ?>" <?= $range->days === $days ? 'selected' : '' ?>><?= e(sprintf(__('studio_insights_last_days', 'Last %d days'), $days)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline"><?= e(__('studio_insights_apply', 'Apply')) ?></button>
                <?php if ($path !== ''): ?><a class="btn btn-sm btn-outline" href="<?= e('insights.php?' . http_build_query(['tab' => $tab, 'range' => $range->days])) ?>"><?= e(__('studio_insights_all_site', 'All of the site')) ?></a><?php endif; ?>
            </form>
            <?php if ($canRefresh): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="refresh">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <input type="hidden" name="range" value="<?= $range->days ?>">
                    <input type="hidden" name="url" value="<?= e($path) ?>">
                    <button type="submit" class="btn btn-sm btn-outline"><?= sb_svg('refresh', 14) ?> <?= e(__('studio_insights_refresh', 'Refresh')) ?></button>
                </form>
            <?php endif; ?>
        </div>

        <div class="sb-insights-grid">
            <?php foreach ($tabs[$tab] as $panel => $wide): ?>
                <div class="sb-insights-slot<?= $wide ? ' is-wide' : '' ?>" data-insights-panel="<?= e('insights-data.php?' . http_build_query(['panel' => $panel, 'tab' => $tab, 'range' => $range->days, 'url' => $path])) ?>">
                    <div class="sb-card sb-insights-loading" aria-busy="true"><span class="sb-muted"><?= e(__('studio_insights_loading', 'Loading from Google…')) ?></span></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var failed = <?= json_encode(sb_insights_card(__('studio_insights_panel_failed', 'This panel could not load'), sb_insights_empty(__('studio_insights_err_unavailable', 'Google did not answer. Try again in a moment.'))), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    document.querySelectorAll('[data-insights-panel]').forEach(function (slot) {
        fetch(slot.dataset.insightsPanel, { credentials: 'same-origin' }).then(function (response) {
            if (!response.ok || response.redirected) { throw new Error('panel'); }
            return response.text();
        }).then(function (html) { slot.innerHTML = html; }).catch(function () { slot.innerHTML = failed; });
    });
    var filters = document.querySelector('[data-insights-filters]');
    if (filters) { filters.range.addEventListener('change', function () { filters.requestSubmit(); }); }

    // The chart readout: the day under the pointer.
    function chartAt(event) {
        var chart = event.target.closest ? event.target.closest('.sb-insights-chart') : null;
        document.querySelectorAll('.sb-insights-chart').forEach(function (other) {
            if (other !== chart) { other.querySelector('.sb-insights-chart__cross').hidden = true; other.querySelector('.sb-insights-chart__tip').hidden = true; }
        });
        if (!chart) { return; }
        var points = JSON.parse(chart.dataset.points);
        var box = chart.querySelector('svg').getBoundingClientRect();
        var ratio = Math.max(0, Math.min(1, (event.clientX - box.left) / box.width));
        var index = Math.round(ratio * (points.length - 1));
        var left = points.length > 1 ? index / (points.length - 1) * box.width : 0;
        var cross = chart.querySelector('.sb-insights-chart__cross');
        var tip = chart.querySelector('.sb-insights-chart__tip');
        cross.hidden = tip.hidden = false;
        cross.style.left = left + 'px';
        tip.style.left = Math.min(left, box.width - tip.offsetWidth) + 'px';
        tip.textContent = points[index][0] + ' · ' + points[index][1];
    }
    document.addEventListener('pointermove', chartAt);
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
