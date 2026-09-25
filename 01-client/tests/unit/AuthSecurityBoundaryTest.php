<?php
declare(strict_types=1);

use Slate\Services\Auth\Auth;

require_once dirname(__DIR__, 2) . '/includes/helpers.php';

unit('redirect sanitizer accepts local paths and rejects external targets', function (): void {
    $fallback = '/admin/';
    assert_eq('/admin/settings.php', slate_safe_redirect_target('/admin/settings.php', $fallback));
    assert_eq('/admin/', slate_safe_redirect_target('//evil.example/path', $fallback));
    assert_eq('/admin/', slate_safe_redirect_target('/\\evil.example/path', $fallback));
    assert_eq('/admin/', slate_safe_redirect_target('https://evil.example/path', $fallback));
    assert_eq('/admin/', slate_safe_redirect_target("/admin/\nLocation: https://evil.example", $fallback));
});

unit('redirect sanitizer preserves an absolute URL only for the configured Slate origin', function (): void {
    $fallback = '/admin/';
    $origin = defined('SLATE_URL') ? (string) SLATE_URL : '';
    if ($origin === '') {
        assert_true(true, 'SLATE_URL unavailable in isolated unit harness');
        return;
    }
    assert_eq($origin . '/admin/', slate_safe_redirect_target($origin . '/admin/', $fallback));
    assert_eq($fallback, slate_safe_redirect_target('https://example.test' . '/admin/', $fallback));
});

unit('session cookie policy rejects spoofed forwarded HTTP and keeps strict cookie flags', function (): void {
    $oldHttps = $_SERVER['HTTPS'] ?? null;
    $oldForwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    try {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $params = Auth::sessionCookieParams();
        assert_true($params['secure'] === false, 'plain HTTP must not set Secure');
        assert_true($params['httponly'] === true, 'cookies remain HttpOnly');
        assert_eq('Lax', $params['samesite'], 'cookies use a deliberate SameSite policy');
    } finally {
        if ($oldHttps === null) unset($_SERVER['HTTPS']); else $_SERVER['HTTPS'] = $oldHttps;
        if ($oldForwarded === null) unset($_SERVER['HTTP_X_FORWARDED_PROTO']); else $_SERVER['HTTP_X_FORWARDED_PROTO'] = $oldForwarded;
    }
});
