<?php
/**
 * Kohevo Studio — Site Insights: what the screen, its panel endpoint and the Google callback share, and the panels.
 *
 * A panel is drawn on the server and fetched on its own by the screen, because a Google answer can take seconds (PageSpeed
 * up to a minute) and the page should not wait for it. Charts are plain SVG and HTML on the admin tokens.
 */

declare(strict_types=1);

require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Insights\CentralGoogleBroker;
use Slate\Module\StudioBuilder\Insights\CurlGoogleTransport;
use Slate\Module\StudioBuilder\Insights\DbInsightsCache;
use Slate\Module\StudioBuilder\Insights\GoogleApiException;
use Slate\Module\StudioBuilder\Insights\GoogleChoices;
use Slate\Module\StudioBuilder\Insights\GoogleClient;
use Slate\Module\StudioBuilder\Insights\GoogleConnection;
use Slate\Module\StudioBuilder\Insights\InsightsRange;
use Slate\Module\StudioBuilder\Insights\InsightsReports;
use Slate\Module\StudioBuilder\Insights\SettingsInsightsStore;

/** The way to Kohevo Central, for a site with a licence to show; a self-hosted site has none and uses its own client. */
function sb_insights_broker(): ?CentralGoogleBroker
{
    require_once SLATE_ROOT . '/includes/installer_flow.php';
    $server = (string) (env('STUDIO_GOOGLE_BROKER_URL', '') ?: env('LICENSE_SERVER_URL', ''));
    $key = (string) env('LICENSE_KEY', '');
    $product = (string) env('LICENSE_PRODUCT', '');
    $install = \Slate\Services\Installation\InstallationService::currentInstallationId();
    if ($server === '' || $key === '' || $product === '' || $install === null) {
        return null;
    }
    return new CentralGoogleBroker(['server_url' => $server, 'product' => $product, 'license_key' => $key, 'install_id' => $install, 'domain' => installer_domain_from_url(SLATE_URL)], new CurlGoogleTransport());
}

function sb_insights_connection(int $tenantId): GoogleConnection
{
    return new GoogleConnection(new SettingsInsightsStore($tenantId), new CurlGoogleTransport(), rtrim(SLATE_URL, '/') . '/plugins/studio-builder/admin/insights-callback.php', sb_insights_broker());
}

function sb_insights_client(int $tenantId): GoogleClient
{
    return new GoogleClient(sb_insights_connection($tenantId), new CurlGoogleTransport());
}

/** Store the chosen properties and find the Analytics tag. The saved figures belong to the old choice. */
function sb_insights_choose(int $tenantId, string $site, string $property, string $propertyName): void
{
    $store = new SettingsInsightsStore($tenantId);
    $store->set(GoogleConnection::SEARCH_SITE, $site);
    $store->set(GoogleConnection::ANALYTICS_PROPERTY, $property);
    $store->set(GoogleConnection::ANALYTICS_NAME, $propertyName);
    $store->set(GoogleConnection::MEASUREMENT_ID, $property === '' ? '' : sb_insights_client($tenantId)->measurementId($property));
    (new DbInsightsCache($tenantId))->clear();
}

/** After a sign-in: pick the properties of this site when the answer is clear. True when at least one was found. */
function sb_insights_auto_choose(int $tenantId): bool
{
    $client = sb_insights_client($tenantId);
    $host = (string) parse_url((string) SLATE_URL, PHP_URL_HOST);
    $site = GoogleChoices::matchSite($client->searchSites(), $host);
    $properties = $client->analyticsProperties();
    $property = GoogleChoices::matchProperty($properties, $host, static fn(string $id): array => array_column($client->webStreams($id), 'uri'));
    if ($site === '' && $property === '') {
        return false;
    }
    sb_insights_choose($tenantId, $site, $property, (string) (array_column($properties, 'name', 'property')[$property] ?? ''));
    return true;
}

/** The address this site's reports are about: the Search Console property's, else the site's own. */
function sb_insights_base(int $tenantId): string
{
    return InsightsReports::publicBase((new SettingsInsightsStore($tenantId))->get(GoogleConnection::SEARCH_SITE), (string) SLATE_URL);
}

function sb_insights_reports(int $tenantId, InsightsRange $range, string $path): InsightsReports
{
    $store = new SettingsInsightsStore($tenantId);
    return new InsightsReports(sb_insights_client($tenantId), new DbInsightsCache($tenantId), $store->get(GoogleConnection::SEARCH_SITE), $store->get(GoogleConnection::ANALYTICS_PROPERTY), $range, $path, sb_insights_connection($tenantId)->apiKey());
}

/**
 * The panels of each tab, as panel => wide. With one page chosen, lists of pages say nothing, so they leave.
 *
 * @return array<string, array<string, bool>>
 */
function sb_insights_tabs(bool $onePage): array
{
    $tabs = [
        'key' => ['analytics-tiles' => true, 'search-tiles' => true, 'popular' => false, 'returning' => false, 'source' => false, 'engaged' => false],
        'traffic' => ['analytics-trend' => true, 'search-trend' => true, 'channels' => false, 'countries' => false, 'devices' => false],
        'content' => ['pages' => true, 'queries' => true, 'search-pages' => true],
        'speed' => ['speed-mobile' => true, 'speed-desktop' => true],
    ];
    if ($onePage) {
        unset($tabs['key']['popular'], $tabs['key']['returning'], $tabs['content']['pages'], $tabs['content']['search-pages']);
    }
    return $tabs;
}

function sb_insights_number(float $value, int $decimals = 0): string
{
    return number_format($value, $decimals, __('studio_insights_decimal_point', '.'), __('studio_insights_thousands_sep', ','));
}

function sb_insights_percent(float $ratio): string
{
    return sb_insights_number($ratio * 100, 1) . ' %';
}

/** One figure with its change from the previous period; the sign says it in text, the colour only repeats it. */
function sb_insights_tile(string $label, string $value, float $current, float $previous, string $period): string
{
    $meta = e($period);
    if ($previous > 0) {
        $change = ($current - $previous) / $previous;
        $class = $change >= 0 ? 'is-up' : 'is-down';
        $meta = '<span class="sb-insights-delta ' . $class . '">' . ($change >= 0 ? '▲ +' : '▼ −') . e(sb_insights_percent(abs($change))) . '</span> ' . $meta;
    }
    return '<div class="sb-kpi-card"><div class="sb-kpi-top"><span class="sb-kpi-label">' . e($label) . '</span></div><div class="sb-kpi-value">' . e($value) . '</div><div class="sb-kpi-meta">' . $meta . '</div></div>';
}

/**
 * Change over time as one line, with the hover readout and the same figures as a table.
 *
 * @param list<array{date: string, value: float}> $days
 */
function sb_insights_chart(array $days, string $name): string
{
    $values = array_column($days, 'value');
    $max = $values === [] ? 0.0 : max($values);
    if ($max <= 0) {
        return sb_insights_empty(__('studio_insights_no_data', 'No data for this period yet.'));
    }
    $width = 600;
    $height = 160;
    $count = count($days);
    $line = [];
    foreach ($values as $i => $value) {
        $line[] = round($count > 1 ? $i / ($count - 1) * $width : 0, 2) . ',' . round($height - $value / $max * ($height - 8), 2);
    }
    $points = [];
    $rows = '';
    foreach ($days as $day) {
        $date = date('d/m', (int) strtotime($day['date']));
        $points[] = [$date, sb_insights_number($day['value'])];
        $rows .= '<tr><td>' . e($date) . '</td><td>' . e(sb_insights_number($day['value'])) . '</td></tr>';
    }
    $polyline = implode(' ', $line);
    $label = sprintf(__('studio_insights_chart_label', '%1$s per day, highest %2$s'), $name, sb_insights_number($max));
    return '<div class="sb-insights-chart" data-points="' . e((string) json_encode($points)) . '">'
        . '<span class="sb-insights-chart__max">' . e(sb_insights_number($max)) . '</span>'
        . '<svg viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . e($label) . '">'
        . '<line class="sb-insights-chart__grid" x1="0" x2="' . $width . '" y1="' . ($height / 2) . '" y2="' . ($height / 2) . '" vector-effect="non-scaling-stroke"/>'
        . '<polygon class="sb-insights-chart__area" points="0,' . $height . ' ' . $polyline . ' ' . $width . ',' . $height . '"/>'
        . '<polyline class="sb-insights-chart__line" points="' . $polyline . '" vector-effect="non-scaling-stroke"/>'
        . '</svg><div class="sb-insights-chart__cross" hidden></div><div class="sb-insights-chart__tip" hidden></div>'
        . '<div class="sb-insights-chart__axis"><span>' . e($points[0][0]) . '</span><span>' . e($points[$count - 1][0]) . '</span></div></div>'
        . '<details class="sb-insights-details"><summary>' . e(__('studio_insights_as_table', 'Show as a table')) . '</summary><table class="sb-insights-table"><thead><tr><th>' . e(__('studio_insights_day', 'Day')) . '</th><th>' . e($name) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></details>';
}

/**
 * Ranked rows with a bar beside each figure, or a plain message when Google has none.
 *
 * @param list<array{label: string, value: float}> $rows
 * @param \Closure(float): string                  $format
 * @param (\Closure(string): string)|null          $link   an address for a row
 */
function sb_insights_bars(array $rows, \Closure $format, ?\Closure $link = null): string
{
    if ($rows === []) {
        return sb_insights_empty(__('studio_insights_no_data', 'No data for this period yet.'));
    }
    $max = max(array_column($rows, 'value')) ?: 1.0;
    $html = '<ol class="sb-insights-bars">';
    foreach ($rows as $row) {
        $name = e($row['label'] !== '' ? $row['label'] : '—');
        $href = $link !== null ? $link($row['label']) : '';
        $html .= '<li><span class="sb-insights-bars__name">' . ($href !== '' ? '<a href="' . e($href) . '">' . $name . '</a>' : $name) . '</span>'
            . '<span class="sb-insights-bars__value">' . e($format($row['value'])) . '</span>'
            . '<span class="sb-insights-bars__bar" style="--sb-share:' . round($row['value'] / $max * 100, 1) . '%"></span></li>';
    }
    return $html . '</ol>';
}

/** @param list<array{label: string, clicks: float, impressions: float, ctr: float, position: float}> $rows */
function sb_insights_search_table(array $rows, string $first, ?\Closure $link = null): string
{
    if ($rows === []) {
        return sb_insights_empty(__('studio_insights_no_data', 'No data for this period yet.'));
    }
    $html = '<div class="sb-insights-scroll"><table class="sb-insights-table"><thead><tr><th>' . e($first) . '</th><th>' . e(__('studio_insights_clicks', 'Clicks')) . '</th><th>' . e(__('studio_insights_impressions', 'Impressions')) . '</th><th>' . e(__('studio_insights_ctr', 'CTR')) . '</th><th>' . e(__('studio_insights_position', 'Position')) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $href = $link !== null ? $link($row['label']) : '';
        $html .= '<tr><td>' . ($href !== '' ? '<a href="' . e($href) . '">' . e($row['label']) . '</a>' : e($row['label'])) . '</td><td>' . e(sb_insights_number($row['clicks'])) . '</td><td>' . e(sb_insights_number($row['impressions'])) . '</td><td>' . e(sb_insights_percent($row['ctr'])) . '</td><td>' . e(sb_insights_number($row['position'], 1)) . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function sb_insights_empty(string $message): string
{
    return '<p class="sb-insights-note">' . e($message) . '</p>';
}

function sb_insights_card(string $title, string $body, string $sub = ''): string
{
    return '<section class="sb-card sb-insights-card"><div class="sb-card__head"><div><div class="sb-card__title">' . e($title) . '</div>'
        . ($sub !== '' ? '<p class="sb-card__sub">' . e($sub) . '</p>' : '') . '</div></div>' . $body . '</section>';
}

/** What a failed Google call means for the person looking at it. */
function sb_insights_problem(GoogleApiException $e): string
{
    return match ($e->reason) {
        GoogleApiException::CENTRAL => __('studio_insights_err_central', 'Kohevo could not sign this site in. Check that its licence is active, or use your own Google client under Advanced.'),
        GoogleApiException::RECONNECT => __('studio_insights_err_reconnect', 'Google no longer accepts this connection. Connect again from Settings.'),
        GoogleApiException::NOT_ENABLED => __('studio_insights_err_not_enabled', 'This API is not enabled in your Google Cloud project. Enable it there, wait a minute and refresh.'),
        GoogleApiException::FORBIDDEN => __('studio_insights_err_forbidden', 'This Google account has no access to the property chosen in Settings.'),
        GoogleApiException::QUOTA => __('studio_insights_err_quota', 'Google\'s limit is reached for now. Try again later, or add a PageSpeed API key in Settings.'),
        GoogleApiException::NOT_FOUND => __('studio_insights_err_not_found', 'Google could not find this property. Choose another one in Settings.'),
        default => __('studio_insights_err_unavailable', 'Google did not answer. Try again in a moment.'),
    };
}

function sb_insights_panel(string $panel, InsightsReports $reports, string $site, string $property, string $url, int $days, string $onePageUrl): string
{
    $period = sprintf(__('studio_insights_vs_previous', 'vs previous %d days'), $days);
    $count = static fn(float $n): string => sb_insights_number($n);
    $rate = static fn(float $n): string => sb_insights_percent($n);
    $pageLink = static fn(string $path): string => str_starts_with($path, '/') ? $onePageUrl . rawurlencode($path) : '';
    $searchPageLink = static function (string $address) use ($onePageUrl): string {
        return $onePageUrl . rawurlencode((string) (parse_url($address, PHP_URL_PATH) ?: '/'));
    };
    $analytics = static fn(string $title, \Closure $body): string => $property === ''
        ? sb_insights_card($title, sb_insights_empty(__('studio_insights_choose_analytics', 'Choose an Analytics property in Settings.')))
        : sb_insights_card($title, $body());
    $search = static fn(string $title, \Closure $body): string => $site === ''
        ? sb_insights_card($title, sb_insights_empty(__('studio_insights_choose_search', 'Choose a Search Console property in Settings.')))
        : sb_insights_card($title, $body());

    switch ($panel) {
        case 'analytics-tiles':
            if ($property === '') {
                return sb_insights_card(__('studio_insights_visitors', 'Visitors'), sb_insights_empty(__('studio_insights_choose_analytics', 'Choose an Analytics property in Settings.')));
            }
            $s = $reports->analyticsSummary();
            $now = $s['current'] + ['sessions' => 0.0, 'users' => 0.0, 'views' => 0.0, 'engagement' => 0.0];
            $was = $s['previous'] + ['sessions' => 0.0, 'users' => 0.0, 'views' => 0.0, 'engagement' => 0.0];
            return '<div class="sb-kpi-grid sb-insights-tiles">'
                . sb_insights_tile(__('studio_insights_sessions', 'Sessions'), $count($now['sessions']), $now['sessions'], $was['sessions'], $period)
                . sb_insights_tile(__('studio_insights_users', 'Total users'), $count($now['users']), $now['users'], $was['users'], $period)
                . sb_insights_tile(__('studio_insights_views', 'Pageviews'), $count($now['views']), $now['views'], $was['views'], $period)
                . sb_insights_tile(__('studio_insights_engagement', 'Engagement rate'), $rate($now['engagement']), $now['engagement'], $was['engagement'], $period)
                . '</div>';
        case 'search-tiles':
            if ($site === '') {
                return sb_insights_card(__('studio_insights_search', 'Google Search'), sb_insights_empty(__('studio_insights_choose_search', 'Choose a Search Console property in Settings.')));
            }
            $s = $reports->searchSummary();
            return '<div class="sb-kpi-grid sb-insights-tiles">'
                . sb_insights_tile(__('studio_insights_search_clicks', 'Search clicks'), $count($s['current']['clicks']), $s['current']['clicks'], $s['previous']['clicks'], $period)
                . sb_insights_tile(__('studio_insights_search_impressions', 'Search impressions'), $count($s['current']['impressions']), $s['current']['impressions'], $s['previous']['impressions'], $period)
                . sb_insights_tile(__('studio_insights_ctr', 'CTR'), $rate($s['current']['ctr']), $s['current']['ctr'], $s['previous']['ctr'], $period)
                . '<div class="sb-kpi-card"><div class="sb-kpi-top"><span class="sb-kpi-label">' . e(__('studio_insights_avg_position', 'Average position')) . '</span></div><div class="sb-kpi-value">' . e(sb_insights_number($s['current']['position'], 1)) . '</div><div class="sb-kpi-meta">' . e(__('studio_insights_lower_better', 'Lower is better')) . '</div></div>'
                . '</div>';
        case 'popular':
            return $analytics(__('studio_insights_popular', 'Most popular content by pageviews'), static fn(): string => sb_insights_bars($reports->analyticsTop('pagePath', 'screenPageViews'), $count, $pageLink));
        case 'returning':
            return $analytics(__('studio_insights_returning', 'Top pages by returning visitors'), static fn(): string => sb_insights_bars($reports->analyticsTop('pagePath', 'activeUsers', true), $count, $pageLink));
        case 'source':
            return $analytics(__('studio_insights_top_source', 'Top traffic source'), static fn(): string => sb_insights_bars($reports->analyticsTop('sessionDefaultChannelGroup', 'totalUsers'), $count));
        case 'engaged':
            return $analytics(__('studio_insights_engaged_source', 'Most engaged traffic source'), static fn(): string => sb_insights_bars($reports->engagedSources(), $rate));
        case 'analytics-trend':
            $name = __('studio_insights_sessions', 'Sessions');
            return $analytics($name, static fn(): string => sb_insights_chart($reports->analyticsTrend(), $name));
        case 'search-trend':
            $name = __('studio_insights_search_clicks', 'Search clicks');
            return $search($name, static fn(): string => sb_insights_chart($reports->searchSummary()['days'], $name));
        case 'channels':
            return $analytics(__('studio_insights_channels', 'Sessions by channel'), static fn(): string => sb_insights_bars($reports->analyticsTop('sessionDefaultChannelGroup', 'sessions'), $count));
        case 'countries':
            return $analytics(__('studio_insights_countries', 'Top countries'), static fn(): string => sb_insights_bars($reports->analyticsTop('country', 'sessions'), $count));
        case 'devices':
            return $analytics(__('studio_insights_devices', 'Devices'), static fn(): string => sb_insights_bars($reports->analyticsTop('deviceCategory', 'sessions'), $count));
        case 'pages':
            return $analytics(__('studio_insights_top_pages', 'Top pages'), static fn(): string => sb_insights_bars($reports->analyticsTop('pagePath', 'screenPageViews'), $count, $pageLink));
        case 'queries':
            return $search(__('studio_insights_queries', 'Top search queries'), static fn(): string => sb_insights_search_table($reports->searchTop('query'), __('studio_insights_query', 'Query')));
        case 'search-pages':
            return $search(__('studio_insights_search_pages', 'Top pages in Google Search'), static fn(): string => sb_insights_search_table($reports->searchTop('page'), __('studio_insights_page', 'Page'), $searchPageLink));
        case 'speed-mobile':
        case 'speed-desktop':
            $strategy = substr($panel, 6);
            return sb_insights_speed($reports->pageSpeed($url, $strategy), $strategy);
    }
    return '';
}

/** @param array{score: ?int, field: array<string, array{value: float, rating: string}>, lab: array<string, string>, url: string} $speed */
function sb_insights_speed(array $speed, string $strategy): string
{
    $ratings = [
        'fast' => ['active', __('studio_insights_good', 'Good')],
        'average' => ['warning', __('studio_insights_needs_work', 'Needs improvement')],
        'slow' => ['danger', __('studio_insights_poor', 'Poor')],
    ];
    $score = $speed['score'];
    $band = $score === null ? null : ($score >= 90 ? 'fast' : ($score >= 50 ? 'average' : 'slow'));
    $head = '<div class="sb-insights-score"><span class="sb-kpi-value">' . ($score === null ? '—' : (int) $score) . '</span><span><span class="sb-kpi-label">' . e(__('studio_insights_score', 'Performance score')) . '</span>'
        . ($band !== null ? ' <span class="badge badge-' . $ratings[$band][0] . '">' . e($ratings[$band][1]) . '</span>' : '') . '</span></div>';

    $fieldNames = ['lcp' => __('studio_insights_lcp', 'Largest Contentful Paint'), 'inp' => __('studio_insights_inp', 'Interaction to Next Paint'), 'cls' => __('studio_insights_cls', 'Cumulative Layout Shift')];
    $field = '';
    foreach ($fieldNames as $key => $name) {
        if (isset($speed['field'][$key])) {
            $metric = $speed['field'][$key];
            $value = match ($key) {
                'lcp' => sb_insights_number($metric['value'] / 1000, 1) . ' s',
                'inp' => sb_insights_number($metric['value']) . ' ms',
                default => sb_insights_number($metric['value'], 2),
            };
            $badge = isset($ratings[$metric['rating']]) ? '<span class="badge badge-' . $ratings[$metric['rating']][0] . '">' . e($ratings[$metric['rating']][1]) . '</span>' : '';
            $field .= '<tr><td>' . e($name) . '</td><td>' . e($value) . '</td><td>' . $badge . '</td></tr>';
        }
    }
    $labNames = ['fcp' => __('studio_insights_fcp', 'First Contentful Paint'), 'lcp' => $fieldNames['lcp'], 'tbt' => __('studio_insights_tbt', 'Total Blocking Time'), 'cls' => $fieldNames['cls'], 'si' => __('studio_insights_si', 'Speed Index')];
    $lab = '';
    foreach ($labNames as $key => $name) {
        if (isset($speed['lab'][$key])) {
            $lab .= '<tr><td>' . e($name) . '</td><td>' . e($speed['lab'][$key]) . '</td></tr>';
        }
    }
    $body = $head . '<div class="sb-two-up sb-insights-speed"><div><h3 class="sb-subhead">' . e(__('studio_insights_field', 'Real visitors')) . '</h3>'
        . ($field !== '' ? '<table class="sb-insights-table"><tbody>' . $field . '</tbody></table>' : sb_insights_empty(__('studio_insights_no_field', 'Chrome has not collected enough visits to this page yet.')))
        . '</div><div><h3 class="sb-subhead">' . e(__('studio_insights_lab', 'Test run')) . '</h3>'
        . ($lab !== '' ? '<table class="sb-insights-table"><tbody>' . $lab . '</tbody></table>' : sb_insights_empty(__('studio_insights_no_data', 'No data for this period yet.'))) . '</div></div>';
    return sb_insights_card($strategy === 'mobile' ? __('studio_insights_mobile', 'Mobile') : __('studio_insights_desktop', 'Desktop'), $body, $speed['url']);
}
