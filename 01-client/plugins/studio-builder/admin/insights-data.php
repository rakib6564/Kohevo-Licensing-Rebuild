<?php
/**
 * Kohevo Studio — Site Insights: one panel of the screen, as HTML. Read-only; a report comes from the cache or from Google.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_insights.php';

use Slate\Module\StudioBuilder\Insights\GoogleApiException;
use Slate\Module\StudioBuilder\Insights\GoogleConnection;
use Slate\Module\StudioBuilder\Insights\InsightsRange;
use Slate\Module\StudioBuilder\Insights\InsightsReports;
use Slate\Module\StudioBuilder\Insights\SettingsInsightsStore;

Auth::require();
Auth::requirePerm('studio-builder.view');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if (!sb_studio_entitled()) {
    http_response_code(403);
    exit;
}
// The screen asks for several panels at once; the session must not make them queue.
session_write_close();
set_time_limit(90);

$tenantId = current_tenant_id();
$panel = (string) ($_GET['panel'] ?? '');
$base = sb_insights_base($tenantId);
$path = InsightsReports::normalizePath(is_string($_GET['url'] ?? null) ? $_GET['url'] : '', $base) ?? '';
$known = array_merge(...array_values(array_map('array_keys', sb_insights_tabs(false))));
if (!in_array($panel, $known, true)) {
    http_response_code(404);
    exit;
}
$range = InsightsRange::of($_GET['range'] ?? InsightsRange::DEFAULT);
$store = new SettingsInsightsStore($tenantId);
$onePage = 'insights.php?' . http_build_query(['tab' => $_GET['tab'] ?? 'key', 'range' => $range->days]) . '&url=';

try {
    echo sb_insights_panel($panel, sb_insights_reports($tenantId, $range, $path), $store->get(GoogleConnection::SEARCH_SITE), $store->get(GoogleConnection::ANALYTICS_PROPERTY), $base . ($path === '' ? '/' : $path), $range->days, $onePage);
} catch (GoogleApiException $e) {
    error_log('[studio-builder] site insights: ' . $e->getMessage());
    echo sb_insights_card(__('studio_insights_panel_failed', 'This panel could not load'), sb_insights_empty(sb_insights_problem($e)));
} catch (\Throwable $e) {
    sb_log_error('site insights', $e);
    echo sb_insights_card(__('studio_insights_panel_failed', 'This panel could not load'), sb_insights_empty(__('studio_insights_err_unavailable', 'Google did not answer. Try again in a moment.')));
}
