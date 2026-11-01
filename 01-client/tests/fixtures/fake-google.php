<?php
/**
 * A stand-in for Google, for trying Site Insights in a local sandbox: the consent screen, tokens and made-up reports.
 *
 *   php -S 127.0.0.1:9191 tests/fixtures/fake-google.php
 *   then STUDIO_GOOGLE_FAKE_URL=http://127.0.0.1:9191 in the sandbox's .env
 *
 * To see an error state, set FAKE_GOOGLE_FAIL to 'disabled', 'quota' or 'revoked', or write that word to
 * <temp dir>/fake-google-fail while it runs.
 */

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$json = static function (mixed $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
};

$fail = (string) (getenv('FAKE_GOOGLE_FAIL') ?: @file_get_contents(sys_get_temp_dir() . '/fake-google-fail'));
if ($fail === 'revoked' && str_ends_with($path, '/token') && ($_POST['grant_type'] ?? '') === 'refresh_token') {
    $json(['error' => 'invalid_grant'], 400);
}
if ($fail === 'disabled' && str_contains($path, 'analyticsdata')) {
    $json(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'SERVICE_DISABLED']]]], 403);
}
if ($fail === 'quota' && str_contains($path, 'pagespeedonline')) {
    $json(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429);
}

if (str_ends_with($path, '/o/oauth2/v2/auth')) {
    $back = (string) $_GET['redirect_uri'] . '?' . http_build_query(['code' => 'fake-code', 'state' => $_GET['state'] ?? '']);
    $cancel = (string) $_GET['redirect_uri'] . '?' . http_build_query(['error' => 'access_denied', 'state' => $_GET['state'] ?? '']);
    echo '<!doctype html><title>Fake Google</title><h1>Fake Google consent</h1><p>' . htmlspecialchars((string) $_GET['scope']) . '</p>'
        . '<a id="allow" href="' . htmlspecialchars($back) . '">Allow</a> <a id="deny" href="' . htmlspecialchars($cancel) . '">Cancel</a>';
    exit;
}
if (str_ends_with($path, '/token')) {
    if (($_POST['client_secret'] ?? '') === '') {
        $json(['error' => 'invalid_client'], 401); // only a holder of the secret gets tokens
    }
    if (($_POST['grant_type'] ?? '') === 'authorization_code' && ($_POST['code_verifier'] ?? '') === '') {
        $json(['error' => 'invalid_grant'], 400);
    }
    $json(['access_token' => 'fake-access-' . bin2hex(random_bytes(4)), 'refresh_token' => 'fake-refresh', 'expires_in' => 3600, 'scope' => 'openid email https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly']);
}
if (str_ends_with($path, '/revoke')) {
    $json([]);
}
if (str_ends_with($path, '/userinfo')) {
    $json(['email' => 'owner@example.com']);
}
if (str_ends_with($path, '/webmasters/v3/sites')) {
    $json(['siteEntry' => [['siteUrl' => 'sc-domain:chefgregory.com', 'permissionLevel' => 'siteOwner'], ['siteUrl' => 'https://other.example/', 'permissionLevel' => 'siteFullUser']]]);
}
if (str_ends_with($path, '/searchAnalytics/query')) {
    $dimension = $input['dimensions'][0] ?? '';
    $rows = [];
    if ($dimension === 'date') {
        for ($d = strtotime((string) $input['startDate']), $end = strtotime((string) $input['endDate']), $i = 0; $d <= $end; $d += 86400, $i++) {
            $clicks = 20 + ($i * 7) % 23;
            $rows[] = ['keys' => [date('Y-m-d', $d)], 'clicks' => $clicks, 'impressions' => $clicks * 31, 'ctr' => 1 / 31, 'position' => 11.5];
        }
    } else {
        foreach ($dimension === 'query' ? ['private chef los angeles', 'chef gregory', 'wedding catering', 'home chef near me'] : ['https://chefgregory.com/', 'https://chefgregory.com/catering/', 'https://chefgregory.com/about/'] as $i => $key) {
            $rows[] = ['keys' => [$key], 'clicks' => 120 - $i * 30, 'impressions' => 3100 - $i * 500, 'ctr' => 0.04, 'position' => 4.2 + $i];
        }
    }
    $json(['rows' => $rows]);
}
if (str_ends_with($path, '/accountSummaries')) {
    $json(['accountSummaries' => [['account' => 'accounts/1', 'displayName' => 'Chef Gregory', 'propertySummaries' => [['property' => 'properties/123456', 'displayName' => 'chefgregory.com - GA4']]]]]);
}
if (str_ends_with($path, '/dataStreams')) {
    $json(['dataStreams' => [['type' => 'WEB_DATA_STREAM', 'webStreamData' => ['measurementId' => 'G-FAKE12345', 'defaultUri' => 'https://chefgregory.com']]]]);
}
if (str_ends_with($path, ':runReport')) {
    $dimension = $input['dimensions'][0]['name'] ?? '';
    $metrics = count($input['metrics'] ?? []);
    $row = static fn(array $dims, array $values): array => ['dimensionValues' => array_map(static fn($v) => ['value' => (string) $v], $dims), 'metricValues' => array_map(static fn($v) => ['value' => (string) $v], $values)];
    if ($dimension === '' ) {
        $json(['rows' => [$row(['current'], [4210, 3180, 9920, 0.62]), $row(['previous'], [3890, 3300, 9100, 0.58])]]);
    }
    if ($dimension === 'date') {
        $rows = [];
        for ($d = strtotime((string) $input['dateRanges'][0]['startDate']), $end = strtotime((string) $input['dateRanges'][0]['endDate']), $i = 0; $d <= $end; $d += 86400, $i++) {
            if ($i % 11 !== 5) { // a day without visits is left out, as Google does
                $rows[] = $row([date('Ymd', $d)], [100 + ($i * 37) % 90]);
            }
        }
        $json(['rows' => $rows]);
    }
    $labels = [
        'pagePath' => ['/', '/catering/', '/about/', '/menus/', '/contact/'],
        'sessionDefaultChannelGroup' => ['Organic Search', 'Direct', 'Referral', 'Organic Social', 'Email'],
        'country' => ['United States', 'Canada', 'Belgium', 'France'],
        'deviceCategory' => ['mobile', 'desktop', 'tablet'],
    ][$dimension] ?? [];
    $rows = [];
    foreach ($labels as $i => $label) {
        $rows[] = $metrics === 2 ? $row([$label], [0.8 - $i * 0.09, 900 - $i * 180]) : $row([$label], [2100 - $i * 430]);
    }
    $json(['rows' => $rows]);
}
if (str_ends_with($path, '/runPagespeed')) {
    $mobile = ($_GET['strategy'] ?? '') === 'mobile';
    $json([
        'loadingExperience' => ['metrics' => [
            'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => $mobile ? 3100 : 1800, 'category' => $mobile ? 'AVERAGE' : 'FAST'],
            'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 180, 'category' => 'FAST'],
            'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 4, 'category' => 'FAST'],
        ]],
        'lighthouseResult' => ['categories' => ['performance' => ['score' => $mobile ? 0.71 : 0.93]], 'audits' => [
            'first-contentful-paint' => ['displayValue' => '1.9 s'],
            'largest-contentful-paint' => ['displayValue' => '3.2 s'],
            'total-blocking-time' => ['displayValue' => '210 ms'],
            'cumulative-layout-shift' => ['displayValue' => '0.04'],
            'speed-index' => ['displayValue' => '3.8 s'],
        ]],
    ]);
}
$json(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => $path]], 404);
