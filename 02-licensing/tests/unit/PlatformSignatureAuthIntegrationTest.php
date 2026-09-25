<?php
/**
 * Unit tests for the Phase 3 auth/login shell integration — structural/source
 * checks only (no DB, no session: admin/login.php and customer/partials/
 * {header,footer}.php both require a full app boot to render; actually
 * rendering them is covered by the integration suite instead — see
 * tests/integration/PlatformSignatureAuthShellTest.php).
 *
 * Boots via the autoloader only (tests/unit/run.php).
 */

declare(strict_types=1);

$adminLoginSrc      = file_get_contents(__DIR__ . '/../../admin/login.php');
$customerHeaderSrc  = file_get_contents(__DIR__ . '/../../customer/partials/header.php');
$customerFooterSrc  = file_get_contents(__DIR__ . '/../../customer/partials/footer.php');
$customerLoginSrc   = file_get_contents(__DIR__ . '/../../customer/login.php');

// ── Admin login ─────────────────────────────────────────────

unit('admin login calls PlatformSignature::render() rather than hardcoding a platform asset path', function () use ($adminLoginSrc) {
    assert_true(str_contains($adminLoginSrc, 'PlatformSignature::render('), 'admin/login.php must render the platform signature through PlatformSignature');
    assert_false(str_contains($adminLoginSrc, '/assets/platform/brand/'), 'admin/login.php must never name a platform asset path directly');
});

unit('admin login calls PlatformSignature::render() exactly once, using MODE_SIGNATURE', function () use ($adminLoginSrc) {
    assert_eq(1, substr_count($adminLoginSrc, 'PlatformSignature::render('));
    assert_true(str_contains($adminLoginSrc, 'PlatformSignature::MODE_SIGNATURE'), 'the login surface should use the mode PlatformSignature itself names for this context');
});

unit('admin login: the existing "Built & maintained by" attribution (auth-credit) is untouched and comes before the new platform signature', function () use ($adminLoginSrc) {
    assert_true(str_contains($adminLoginSrc, 'auth-credit'), 'the existing developer/owner attribution block must still be present');
    assert_true(str_contains($adminLoginSrc, "__('built_by', 'Built &amp; maintained by')"), 'the existing attribution string must be unchanged, not merged with or replaced by the platform signature');
    assert_true(str_contains($adminLoginSrc, 'brand_owner'), 'brand_owner/brand_owner_url settings must still back this attribution, unchanged');
    $creditPos    = strpos($adminLoginSrc, 'auth-credit');
    $signaturePos = strpos($adminLoginSrc, 'PlatformSignature::render(');
    assert_true($creditPos !== false && $signaturePos !== false && $creditPos < $signaturePos, 'the platform signature must come after the existing attribution, per the required hierarchy');
});

unit('admin login: authentication logic markers are untouched (Auth::check/Auth::attemptLogin/csrf_verify still present)', function () use ($adminLoginSrc) {
    foreach (['Auth::check(', 'Auth::attemptLogin(', 'csrf_verify(', 'Auth::mfaPending('] as $marker) {
        assert_true(str_contains($adminLoginSrc, $marker), "expected authentication marker missing: $marker");
    }
});

// ── Customer authentication (auth-split) ────────────────────

unit('customer auth-split integration lives in the shared partials, not injected directly into customer/login.php', function () use ($customerLoginSrc) {
    assert_false(str_contains($customerLoginSrc, 'PlatformSignature'), 'customer/login.php must not be modified for this integration — the shared auth-split partial owns it');
});

unit('customer partials call PlatformSignature::render() rather than hardcoding a platform asset path', function () use ($customerHeaderSrc, $customerFooterSrc) {
    $combined = $customerHeaderSrc . $customerFooterSrc;
    assert_true(str_contains($combined, 'PlatformSignature::render('), 'the customer auth-split surface must render the platform signature through PlatformSignature');
    assert_false(str_contains($customerHeaderSrc, '/assets/platform/brand/'), 'customer/partials/header.php must never name a platform asset path directly');
    assert_false(str_contains($customerFooterSrc, '/assets/platform/brand/'), 'customer/partials/footer.php must never name a platform asset path directly');
});

unit('customer footer.php calls PlatformSignature::render() exactly once, using MODE_SIGNATURE, only inside the auth-split branch', function () use ($customerFooterSrc) {
    assert_eq(1, substr_count($customerFooterSrc, 'PlatformSignature::render('));
    assert_true(str_contains($customerFooterSrc, 'PlatformSignature::MODE_SIGNATURE'));

    $dashboardBranch = strpos($customerFooterSrc, "'dashboard'");
    $authSplitBranch = strpos($customerFooterSrc, "'auth-split'");
    $signature        = strpos($customerFooterSrc, 'PlatformSignature::render(');
    $elseBranch       = strpos($customerFooterSrc, '<?php else: ?>');
    assert_true($dashboardBranch !== false && $authSplitBranch !== false && $signature !== false && $elseBranch !== false, 'expected markers not found');
    assert_true($authSplitBranch < $signature && $signature < $elseBranch, 'the PlatformSignature call must be inside the auth-split branch only, strictly before the closing else branch');
});

unit('customer header.php: the Phase 2 dashboard signature call is unchanged (still exactly one call, MODE_COMPACT)', function () use ($customerHeaderSrc) {
    assert_eq(1, substr_count($customerHeaderSrc, 'PlatformSignature::render('), 'Phase 3 must not add a second call in header.php — the auth-split signature lives in footer.php');
    assert_true(str_contains($customerHeaderSrc, 'PlatformSignature::MODE_COMPACT'), 'the Phase 2 dashboard integration (MODE_COMPACT) must still be present and unchanged');
});

unit('customer auth-split: the tenant auth-brand block still renders before the (decorative) hero, unaffected by Phase 3', function () use ($customerHeaderSrc) {
    assert_true(str_contains($customerHeaderSrc, 'class="auth-brand"'), 'the tenant brand block inside the auth-split form panel must be untouched');
});

unit('customer auth-split: authentication logic markers are untouched (Auth::customer/Auth::attemptCustomerLogin/csrf_verify still present)', function () use ($customerLoginSrc) {
    foreach (['Auth::customer(', 'Auth::attemptCustomerLogin(', 'csrf_verify('] as $marker) {
        assert_true(str_contains($customerLoginSrc, $marker), "expected authentication marker missing: $marker");
    }
});
