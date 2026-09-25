<?php
/**
 * Phase 3 QA fix round 2 — P2 Blocker 1B: LicensingAPI::normalizeDomain().
 *
 * Pure static-function tests — no DB, no config.php (normalizeDomain() has
 * no framework dependency; it's a pure string function), same convention as
 * LicensingSignatureTest.php.
 *
 * Covers the specific malformed inputs Antigravity's independent
 * verification found sailing through undetected:
 *
 *   - `isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])`
 *     is a multi-argument isset(), which is `isset($a) && isset($b) && ...`
 *     — it only rejected a URL that had ALL FOUR components at once, so a
 *     URL with only a query string, only a fragment, or only userinfo
 *     sailed through unrejected.
 *   - the old hostname regex anchored only the very first and very last
 *     character of the WHOLE hostname string, so an interior label ending
 *     in a hyphen, or an empty label from consecutive dots, went undetected.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../plugins/licensing/LicensingAPI.php';

unit('LicensingAPI::normalizeDomain() rejects a URL carrying only a query string', function () {
    assert_null(LicensingAPI::normalizeDomain('https://acme.example/?query=1'));
});

unit('LicensingAPI::normalizeDomain() rejects a URL carrying only a fragment', function () {
    assert_null(LicensingAPI::normalizeDomain('https://acme.example#frag'));
});

unit('LicensingAPI::normalizeDomain() rejects a URL carrying username/password userinfo', function () {
    assert_null(LicensingAPI::normalizeDomain('http://user:pass@acme.example'));
    assert_null(LicensingAPI::normalizeDomain('http://user@acme.example'), 'a bare username with no password must also be rejected');
});

unit('LicensingAPI::normalizeDomain() rejects a hostname label ending in a hyphen', function () {
    assert_null(LicensingAPI::normalizeDomain('trailinghyphen-.example'));
});

unit('LicensingAPI::normalizeDomain() rejects a hostname with consecutive dots (an empty label)', function () {
    assert_null(LicensingAPI::normalizeDomain('acme..example'));
});

unit('LicensingAPI::normalizeDomain() still accepts legitimate domains (no regression from the stricter checks)', function () {
    assert_eq('acme.example', LicensingAPI::normalizeDomain('acme.example'));
    assert_eq('acme.example', LicensingAPI::normalizeDomain('https://acme.example'));
    assert_eq('acme.example', LicensingAPI::normalizeDomain('https://acme.example/'));
    assert_eq('sub.acme.example', LicensingAPI::normalizeDomain('sub.acme.example'));
    assert_eq('acme-test.example', LicensingAPI::normalizeDomain('acme-test.example'), 'an interior hyphen (not leading/trailing a label) must remain valid');
    assert_eq('localhost', LicensingAPI::normalizeDomain('localhost'));
    assert_eq('localhost:8080', LicensingAPI::normalizeDomain('http://localhost:8080'), 'a non-default port must be preserved');
    assert_eq('acme.example', LicensingAPI::normalizeDomain('ACME.EXAMPLE'), 'hostname must be lower-cased');
});

unit('LicensingAPI::normalizeDomain() still rejects a path (pre-existing behavior, unaffected by this fix)', function () {
    assert_null(LicensingAPI::normalizeDomain('http://acme.example/some/path'));
});
