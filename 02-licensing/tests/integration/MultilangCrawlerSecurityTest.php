<?php
/**
 * Phase 1D E1 — Multilang crawler had TLS verification fully disabled and a
 * port-blind same-host check.
 *
 * plugins/multilang-translate/includes/Crawler.php's fetch() called every
 * discovered/manual URL with CURLOPT_SSL_VERIFYPEER=>false and
 * CURLOPT_SSL_VERIFYHOST=>0 — no documented reason, and inconsistent with
 * every other outbound curl call in this codebase (FormsAPI's DNS-pinned
 * webhook probe, FormsSpamGuard's Akismet call), which all verify strictly.
 * The crawler is described in the plugin's own TODO.md as an "internal
 * loopback" self-scan of the app's own configured domain (SLATE_URL) — never
 * a third-party site — so a properly issued certificate is the expected case,
 * and there is no supported deployment scenario requiring self-signed
 * tolerance here (confirmed: no settings toggle, no docs, no other call site
 * in the codebase disables verification for a comparable purpose).
 *
 * Separately, sameHost() — the ONLY gate keeping discoverUrls()/manualUrls()
 * from fetching an arbitrary URL — compared hostnames only, via
 * parse_url(..., PHP_URL_HOST), ignoring the port entirely. An admin with
 * mlt.manage (via the manual-URL scan list, or Hook::applyFilters(
 * 'admin_nav_items'/'customer_nav_items'/'public_routes', which a plugin
 * could poison) could direct the crawler at an arbitrary port on the app's
 * own host — SSRF-adjacent port-scanning capability, carrying the
 * initiating admin's own Authorization/Cookie headers into the request
 * (fetch() forwards both).
 *
 * Fix: strict TLS verification (matching the rest of the codebase), and a
 * port-aware sameHost() that normalizes each URL's effective port by scheme
 * before comparing.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/multilang-translate/MultilangTranslate.php';

unit('MLT_Crawler::fetch() verifies TLS strictly, matching every other outbound curl call in this codebase', function (): void {
    $src = file_get_contents(dirname(__DIR__, 2) . '/plugins/multilang-translate/includes/Crawler.php');

    $fnPos = strpos($src, 'private static function fetch(');
    assert_true($fnPos !== false, 'fetch() must still exist');
    $fnEnd = strpos($src, "\n    }", $fnPos);
    $fnBody = substr($src, $fnPos, ($fnEnd !== false ? $fnEnd - $fnPos : 1500));

    assert_true(str_contains($fnBody, 'CURLOPT_SSL_VERIFYPEER => true'), 'fetch() must verify the peer certificate');
    assert_true(str_contains($fnBody, 'CURLOPT_SSL_VERIFYHOST => 2'), 'fetch() must verify the certificate matches the host (2 = full check)');
    assert_false(str_contains($fnBody, 'CURLOPT_SSL_VERIFYPEER => false'), 'the old disabled-verification value must be gone');
    assert_false(str_contains($fnBody, 'CURLOPT_SSL_VERIFYHOST => 0'), 'the old disabled-verification value must be gone');
});

unit('MLT_Crawler::manualUrls() excludes a same-host URL on a different port from this install\'s own SLATE_URL', function (): void {
    $tid = current_tenant_id();
    $prior = Database::value(
        "SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?",
        [$tid, 'multilang-translate.scan_manual_urls']
    );

    $ownHost = (string) parse_url(SLATE_URL, PHP_URL_HOST);
    $ownPort = parse_url(SLATE_URL, PHP_URL_PORT);
    $scheme  = (string) parse_url(SLATE_URL, PHP_URL_SCHEME);
    $foreignPort = (is_int($ownPort) ? $ownPort : ($scheme === 'https' ? 443 : 80)) + 1;

    $sameHostSamePort     = rtrim(SLATE_URL, '/') . '/admin/index.php';
    $sameHostDifferentPort = $scheme . '://' . $ownHost . ':' . $foreignPort . '/admin/index.php';

    try {
        MLT_Crawler::saveManualUrls($tid, $sameHostSamePort . "\n" . $sameHostDifferentPort);
        $accepted = MLT_Crawler::manualUrls($tid);

        assert_true(in_array($sameHostSamePort, $accepted, true), 'a same-host, same-port URL must still be accepted (no regression)');
        assert_false(
            in_array($sameHostDifferentPort, $accepted, true),
            "a same-host URL on a different port ({$sameHostDifferentPort}) must be rejected, not treated as the same site"
        );
    } finally {
        if ($prior === null || $prior === false) {
            Database::query('DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?', [$tid, 'multilang-translate.scan_manual_urls']);
        } else {
            Database::setSetting('multilang-translate.scan_manual_urls', (string) $prior, $tid);
        }
    }
});

unit('MLT_Crawler::sameHost() treats an implicit default port and its explicit form as equal (fix present, correctly, not just port-string-equal)', function (): void {
    // This install's own SLATE_URL carries an explicit non-default port, so it
    // can't exercise the "no port at all" branch of the fix — reflection lets
    // this test drive sameHost() directly with both forms of the same port.
    $sameHost = new \ReflectionMethod(MLT_Crawler::class, 'sameHost');
    $sameHost->setAccessible(true);

    assert_true(
        (bool) $sameHost->invoke(null, 'https://example.test/page', 'https://example.test:443/other'),
        'an implicit https port (none in the URL) must be treated as 443, matching an explicit :443'
    );
    assert_true(
        (bool) $sameHost->invoke(null, 'http://example.test/page', 'http://example.test:80/other'),
        'an implicit http port (none in the URL) must be treated as 80, matching an explicit :80'
    );
    assert_false(
        (bool) $sameHost->invoke(null, 'https://example.test:8443/page', 'https://example.test/other'),
        'an explicit non-default port must never match the implicit default'
    );
    assert_false(
        (bool) $sameHost->invoke(null, 'https://example.test:8080/page', 'https://example.test:9090/other'),
        'two different explicit ports on the same host must never match'
    );
    assert_true(
        (bool) $sameHost->invoke(null, 'https://example.test:8080/page', 'https://example.test:8080/other'),
        'two identical explicit ports on the same host must still match'
    );
});
