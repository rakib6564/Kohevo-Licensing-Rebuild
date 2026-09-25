<?php
/**
 * Unit tests for the Phase 2 shell integration — structural/source checks
 * only (no DB, no session: admin/partials/header.php and
 * customer/partials/header.php both require a full app boot to render, so
 * actually rendering them is covered by the integration suite instead; see
 * tests/integration/PlatformSignatureAdminShellTest.php).
 *
 * These assert the *shape* of the integration: the shell files call
 * PlatformSignature rather than hardcoding an asset path or the platform
 * name, and the customer integration is scoped to the 'dashboard' variant
 * only, never 'auth-split' (that's Phase 3/login work).
 *
 * Boots via the autoloader only (tests/unit/run.php).
 */

declare(strict_types=1);

$adminHeaderSrc    = file_get_contents(__DIR__ . '/../../admin/partials/header.php');
$customerHeaderSrc = file_get_contents(__DIR__ . '/../../customer/partials/header.php');

unit('admin sidebar calls PlatformSignature::render() rather than hardcoding a platform asset path', function () use ($adminHeaderSrc) {
    assert_true(str_contains($adminHeaderSrc, 'PlatformSignature::render('), 'admin/partials/header.php must render the platform signature through PlatformSignature');
    assert_false(str_contains($adminHeaderSrc, '/assets/platform/brand/'), 'admin/partials/header.php must never name a platform asset path directly');
});

unit('customer header calls PlatformSignature::render() rather than hardcoding a platform asset path', function () use ($customerHeaderSrc) {
    assert_true(str_contains($customerHeaderSrc, 'PlatformSignature::render('), 'customer/partials/header.php must render the platform signature through PlatformSignature');
    assert_false(str_contains($customerHeaderSrc, '/assets/platform/brand/'), 'customer/partials/header.php must never name a platform asset path directly');
});

unit('admin sidebar signature sits after the nav loop and before the existing sidebar-footer (tenant user block untouched)', function () use ($adminHeaderSrc) {
    $navEnd    = strpos($adminHeaderSrc, '<?php endforeach; ?>');
    $signature = strpos($adminHeaderSrc, 'PlatformSignature::render(');
    $footer    = strpos($adminHeaderSrc, '<div class="sidebar-footer">');
    assert_true($navEnd !== false && $signature !== false && $footer !== false, 'expected markers not found');
    assert_true($navEnd < $signature, 'signature must come after the nav loop');
    assert_true($signature < $footer, 'signature must come before the existing tenant sidebar-footer (user info/logout)');
});

unit('customer PlatformSignature call is inside the dashboard branch, not the auth-split branch', function () use ($customerHeaderSrc) {
    $dashboardStart = strpos($customerHeaderSrc, "\$customerPageVariant === 'dashboard'");
    $authSplitStart = strpos($customerHeaderSrc, "\$customerPageVariant === 'auth-split'");
    $signature      = strpos($customerHeaderSrc, 'PlatformSignature::render(');
    assert_true($dashboardStart !== false && $authSplitStart !== false && $signature !== false, 'expected markers not found');
    assert_true($dashboardStart < $signature && $signature < $authSplitStart, 'the PlatformSignature call must be inside the dashboard branch, strictly before the auth-split branch begins');
});

unit('customer header calls PlatformSignature::render() exactly once (no accidental duplication)', function () use ($customerHeaderSrc) {
    assert_eq(1, substr_count($customerHeaderSrc, 'PlatformSignature::render('));
});

unit('admin sidebar calls PlatformSignature::render() exactly once (no accidental duplication)', function () use ($adminHeaderSrc) {
    assert_eq(1, substr_count($adminHeaderSrc, 'PlatformSignature::render('));
});

unit('admin footer.php was not touched by the Phase 2 shell integration (still true as of Phase 3)', function () {
    // Phase 2 scope: admin/partials/header.php was the target; the mobile tab
    // bar/overflow sheet in admin/partials/footer.php has no existing brand
    // slot to hook into without inventing one, so it needed no change then —
    // and Phase 3 (auth/login) has no reason to touch it either.
    $adminFooterSrc = file_get_contents(__DIR__ . '/../../admin/partials/footer.php');
    assert_false(str_contains($adminFooterSrc, 'PlatformSignature'), 'admin/partials/footer.php was intentionally left out of Phase 2 and Phase 3 — see docs/09-Roadmap/phase-kohevo-identity-p2.md');
});

unit('customer footer.php: the PlatformSignature call sits strictly inside the auth-split branch, not the dashboard branch (Phase 2 dashboard scope unaffected by Phase 3)', function () {
    // Phase 2 note this test previously encoded is now stale: Phase 3
    // legitimately added a PlatformSignature call to customer/partials/footer.php's
    // auth-split branch (see docs/09-Roadmap/phase-kohevo-identity-p3.md). What
    // must still hold is that Phase 2's dashboard-variant scope is unaffected.
    $customerFooterSrc = file_get_contents(__DIR__ . '/../../customer/partials/footer.php');
    $dashboardBranch = strpos($customerFooterSrc, "'dashboard'");
    $authSplitBranch = strpos($customerFooterSrc, "'auth-split'");
    $signature       = strpos($customerFooterSrc, 'PlatformSignature::render(');
    $elseBranch      = strpos($customerFooterSrc, '<?php else: ?>');
    assert_true($dashboardBranch !== false && $authSplitBranch !== false && $signature !== false && $elseBranch !== false, 'expected markers not found');
    assert_true($dashboardBranch < $authSplitBranch, 'sanity: dashboard branch must come first in the file');
    assert_true($authSplitBranch < $signature && $signature < $elseBranch, 'the PlatformSignature call must be strictly inside the auth-split branch, not the dashboard branch above it');
});
