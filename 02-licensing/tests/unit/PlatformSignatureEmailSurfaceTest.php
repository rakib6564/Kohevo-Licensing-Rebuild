<?php
/**
 * Unit tests for the Phase 5 communications/email Kohevo identity
 * integration — source/structural checks only, no DB.
 *
 * Auth::sendCustomerVerification()/sendCustomerPasswordReset() and
 * MembershipAPI::sendPurchaseEmail()/sendCancelEmail() call \Mailer::send()
 * directly with no injection seam. No existing test in this repo calls them
 * either: tests/integration/CustomerAuthParityTest.php's own docblock
 * documents deliberately avoiding the send path and testing only the
 * token-consuming side, since Mailer::send() has no test-mode gate and would
 * attempt a real send. This file proves the branding integration on the
 * SOURCE instead, the same technique tests/unit/PlatformSignatureErrorSurfaceTest.php
 * already uses for a couple of its assertions. Real rendering proof for the
 * two builder methods that ARE safe to call directly (no Mailer involved) —
 * BrandedEmail::shell() and BookingAPI's private brandedEmailShell() — lives
 * in tests/integration/PlatformSignatureEmailShellTest.php instead.
 */

declare(strict_types=1);

use Slate\Services\Content\PlatformIdentity;

function _p5_src(string $relPath): string
{
    return (string) file_get_contents(__DIR__ . '/../../' . $relPath);
}

// ── BrandedEmail::shell() footer (the one caller today: Forms) ─

unit('BrandedEmail.php: the shell() footer renders the platform signature through PlatformIdentity, with no hardcoded asset path', function () {
    $src = _p5_src('src/Services/Notifications/BrandedEmail.php');
    assert_eq(1, substr_count($src, 'PlatformIdentity::signature()'), 'expected exactly one platform-signature call site');
    assert_true(str_contains($src, '\Slate\Services\Content\PlatformIdentity::signature()'));
    assert_false(str_contains($src, '/assets/platform/brand/'), 'BrandedEmail must never hardcode a platform asset path');
});

unit('BrandedEmail.php: the platform signature is appended after the tenant "Sent by" footer line, not before it', function () {
    $src = _p5_src('src/Services/Notifications/BrandedEmail.php');
    $tenantFooterPos = strpos($src, "'<div>' . \$footFrom . \$footRef . '</div>'");
    $platformUsePos  = strpos($src, '. $footPlatform');
    assert_true($tenantFooterPos !== false && $platformUsePos !== false, 'expected markers not found');
    assert_true($platformUsePos > $tenantFooterPos, 'platform signature must be concatenated after the tenant footer line');
});

// ── Auth.php: verification + password reset ─────────────────

unit('Auth.php: both the verification and reset email bodies carry the platform signature exactly once each, appended after their existing content, in file order', function () {
    $src = _p5_src('src/Services/Auth/Auth.php');
    assert_eq(2, substr_count($src, 'PlatformIdentity::signature()'), 'expected exactly one platform-signature call site per email function');
    assert_false(str_contains($src, '/assets/platform/brand/'));

    $verifyIgnorePos = strpos($src, 'auth_email_verify_ignore');
    $resetIgnorePos  = strpos($src, 'auth_email_reset_ignore');
    assert_true($verifyIgnorePos !== false && $resetIgnorePos !== false);
    assert_true($verifyIgnorePos < $resetIgnorePos, 'sendCustomerVerification is expected to appear before sendCustomerPasswordReset in the file');

    $verifySignaturePos = strpos($src, 'PlatformIdentity::signature()', $verifyIgnorePos);
    $resetSignaturePos  = strpos($src, 'PlatformIdentity::signature()', $resetIgnorePos);
    assert_true($verifySignaturePos !== false && $resetSignaturePos !== false);

    // verification's signature sits between its own "ignore" line and the reset function's "ignore" line
    assert_true($verifySignaturePos > $verifyIgnorePos && $verifySignaturePos < $resetIgnorePos,
        'verification email signature must be appended within sendCustomerVerification, not leak into sendCustomerPasswordReset');
    assert_true($resetSignaturePos > $resetIgnorePos,
        'reset email signature must be appended within sendCustomerPasswordReset');
});

unit('Auth.php: token issuance/consumption and Mailer dispatch are untouched by the Phase 5 branding change (regression guard)', function () {
    $src = _p5_src('src/Services/Auth/Auth.php');
    assert_true(str_contains($src, "self::issueCustomerToken(\$customerId, 'verify_email', self::VERIFY_TOKEN_TTL_SECONDS)"));
    assert_true(str_contains($src, "self::issueCustomerToken((int)\$cust['id'], 'password_reset', self::RESET_TOKEN_TTL_SECONDS)"));
    assert_true(str_contains($src, 'function verifyCustomerEmail(string $token): ?int'));
    assert_true(str_contains($src, 'function resetCustomerPassword('));
    assert_eq(2, substr_count($src, '\Mailer::send('), 'expected exactly the two pre-existing Mailer::send call sites, no new ones added');
});

// ── BookingAPI.php: brandedEmailShell() footer ──────────────

unit('BookingAPI.php: brandedEmailShell() footer carries the platform signature via PlatformIdentity, after the tenant site-name/year line', function () {
    $src = _p5_src('plugins/booking/BookingAPI.php');
    assert_eq(1, substr_count($src, 'PlatformIdentity::signature()'));
    assert_false(str_contains($src, '/assets/platform/brand/'));

    $tenantLinePos   = strpos($src, "e(\$siteName) . ' &middot; ' . \$year . '</p>'");
    $platformLinePos = strpos($src, 'PlatformIdentity::signature()');
    assert_true($tenantLinePos !== false && $platformLinePos !== false, 'expected markers not found');
    assert_true($platformLinePos > $tenantLinePos, 'platform signature must render after the tenant site-name/year footer line');
});

// ── MembershipAPI.php: purchase + cancel ────────────────────

unit('MembershipAPI.php: both the purchase and cancel email bodies carry the platform signature exactly once each, appended before their Mailer::send call, in file order', function () {
    $src = _p5_src('plugins/membership/MembershipAPI.php');
    assert_eq(2, substr_count($src, 'PlatformIdentity::signature()'));
    assert_false(str_contains($src, '/assets/platform/brand/'));

    $activeBodyPos = strpos($src, 'membership_email_active_body');
    $cancelBodyPos = strpos($src, 'membership_email_cancel_body');
    assert_true($activeBodyPos !== false && $cancelBodyPos !== false);
    assert_true($activeBodyPos < $cancelBodyPos, 'sendPurchaseEmail is expected to appear before sendCancelEmail in the file');

    $purchaseSignaturePos = strpos($src, 'PlatformIdentity::signature()', $activeBodyPos);
    $cancelSignaturePos   = strpos($src, 'PlatformIdentity::signature()', $cancelBodyPos);
    assert_true($purchaseSignaturePos !== false && $cancelSignaturePos !== false);
    assert_true($purchaseSignaturePos > $activeBodyPos && $purchaseSignaturePos < $cancelBodyPos,
        'purchase email signature must be appended within sendPurchaseEmail, not leak into sendCancelEmail');
    assert_true($cancelSignaturePos > $cancelBodyPos,
        'cancel email signature must be appended within sendCancelEmail');
});
