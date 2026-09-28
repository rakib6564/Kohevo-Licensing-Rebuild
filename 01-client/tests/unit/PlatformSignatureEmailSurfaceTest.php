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

unit('Auth.php: the verification and reset emails use the shared notification template, which supplies the platform signature exactly once each (no hand-appended copy)', function () {
    $src = _p5_src('src/Services/Auth/Auth.php');
    // The template's footer appends the signature. A second, hand-appended copy in Auth.php would print it twice.
    assert_eq(0, substr_count($src, 'PlatformIdentity::signature()'), 'Auth.php must not append the signature itself; the template does');
    assert_false(str_contains($src, '/assets/platform/brand/'));
    assert_eq(2, substr_count($src, 'EmailTemplate::shell('), 'expected exactly one template call per email function');

    $verifyIgnorePos = strpos($src, 'auth_email_verify_ignore');
    $resetIgnorePos  = strpos($src, 'auth_email_reset_ignore');
    assert_true($verifyIgnorePos !== false && $resetIgnorePos !== false);
    assert_true($verifyIgnorePos < $resetIgnorePos, 'sendCustomerVerification is expected to appear before sendCustomerPasswordReset in the file');

    $verifyShellPos = strpos($src, 'EmailTemplate::shell(', $verifyIgnorePos);
    $resetShellPos  = strpos($src, 'EmailTemplate::shell(', $resetIgnorePos);
    assert_true($verifyShellPos !== false && $resetShellPos !== false);
    assert_true($verifyShellPos > $verifyIgnorePos && $verifyShellPos < $resetIgnorePos,
        'verification email must use the template within sendCustomerVerification, not leak into sendCustomerPasswordReset');
    assert_true($resetShellPos > $resetIgnorePos, 'reset email must use the template within sendCustomerPasswordReset');

    // ...and the template really does supply the signature, exactly once.
    $tpl = _p5_src('src/Services/Notifications/EmailTemplate.php');
    assert_eq(1, substr_count($tpl, 'PlatformIdentity::signature()'), 'EmailTemplate::shell must carry the platform signature exactly once');
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

unit('BookingAPI.php: its email chrome delegates to EmailTemplate, whose footer carries the platform signature via PlatformIdentity, after the tenant site-name/year line', function () {
    $src = _p5_src('plugins/booking/BookingAPI.php');
    // The layout moved into the shared core template; BookingAPI must delegate and must not append a second copy.
    assert_eq(0, substr_count($src, 'PlatformIdentity::signature()'));
    assert_false(str_contains($src, '/assets/platform/brand/'));
    assert_eq(1, substr_count($src, 'EmailTemplate::shell('), 'brandedEmailShell() must delegate to the shared template');

    $tpl = _p5_src('src/Services/Notifications/EmailTemplate.php');
    assert_eq(1, substr_count($tpl, 'PlatformIdentity::signature()'));
    assert_false(str_contains($tpl, '/assets/platform/brand/'));
    $tenantLinePos   = strpos($tpl, "self::esc(\$siteName) . ' &middot; ' . \$year . '</p>'");
    // The signature is fetched in a guarded block above the markup; what matters is where it is RENDERED.
    $platformLinePos = strpos($tpl, 'self::esc($signature)');
    assert_true($tenantLinePos !== false && $platformLinePos !== false, 'expected markers not found');
    assert_true($platformLinePos > $tenantLinePos, 'platform signature must render after the tenant site-name/year footer line');
});

// ── MembershipAPI.php: purchase + cancel ────────────────────

unit('MembershipAPI.php: the purchase and cancel emails each use the shared notification template exactly once, in file order (the template supplies the signature)', function () {
    $src = _p5_src('plugins/membership/MembershipAPI.php');
    assert_eq(0, substr_count($src, 'PlatformIdentity::signature()'), 'the template appends the signature; a hand-appended copy would print it twice');
    assert_false(str_contains($src, '/assets/platform/brand/'));
    assert_eq(2, substr_count($src, 'EmailTemplate::shell('));

    $activeBodyPos = strpos($src, 'membership_email_active_body');
    $cancelBodyPos = strpos($src, 'membership_email_cancel_body');
    assert_true($activeBodyPos !== false && $cancelBodyPos !== false);
    assert_true($activeBodyPos < $cancelBodyPos, 'sendPurchaseEmail is expected to appear before sendCancelEmail in the file');

    $purchaseShellPos = strrpos(substr($src, 0, $activeBodyPos), 'EmailTemplate::shell(');
    $cancelShellPos   = strrpos(substr($src, 0, $cancelBodyPos), 'EmailTemplate::shell(');
    assert_true($purchaseShellPos !== false && $cancelShellPos !== false);
    assert_true($purchaseShellPos < $activeBodyPos && $purchaseShellPos < $cancelShellPos,
        'purchase email must use the template within sendPurchaseEmail, not leak into sendCancelEmail');
});

// ── EmailTemplate itself ────────────────────────────────────

unit('EmailTemplate: the layout, escaping and default accent (DB-free)', function () {
    $T = 'Slate\\Services\\Notifications\\EmailTemplate';
    $html = $T::shell($T::heading('Tom & <Jerry>') . $T::paragraph('<b>ok</b>') . $T::infoCard([['A <b>', '<code>x</code>']]) . $T::button('https://ex.test/a?b=1&c=2', 'Go "now"'), 'pre <view>');
    assert_true(str_contains($html, '<!DOCTYPE html>') && str_contains($html, 'max-width:600px'), 'card layout');
    assert_true(str_contains($html, 'Tom &amp; &lt;Jerry&gt;'), 'heading text must be escaped');
    assert_true(str_contains($html, '<b>ok</b>'), 'paragraph html is trusted, passed through');
    assert_true(str_contains($html, 'A &lt;b&gt;') && str_contains($html, '<code>x</code>'), 'info-card label escaped, value trusted');
    assert_true(str_contains($html, 'href="https://ex.test/a?b=1&amp;c=2"'), 'button href escaped');
    assert_true(str_contains($html, 'Go &quot;now&quot;'), 'button label escaped');
    assert_true(str_contains($html, 'pre &lt;view&gt;'), 'preheader escaped');
    assert_true(str_contains($html, ' &middot; ' . date('Y') . '</p>'), 'footer site-name/year line');
    assert_true(str_contains($html, 'background-color:#111111'), 'default accent when no brand colour is configured');
});
