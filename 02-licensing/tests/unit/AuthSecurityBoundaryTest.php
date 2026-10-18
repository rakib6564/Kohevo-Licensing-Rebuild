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
    // The unit runner does not load config.php, so SLATE_URL is undefined
    // here; this used to "pass" by skipping. Run the check in a child
    // process that defines the origin first (SLATE_URL is a constant).
    $code = "define('SLATE_URL', 'https://kohevo.test');"
          . 'require ' . var_export(dirname(__DIR__, 2) . '/includes/helpers.php', true) . ';'
          . '$f = "/admin/"; echo json_encode(['
          . 'slate_safe_redirect_target("https://kohevo.test/admin/", $f),'
          . 'slate_safe_redirect_target("https://kohevo.test", $f),'
          . 'slate_safe_redirect_target("https://example.test/admin/", $f),'
          . 'slate_safe_redirect_target("https://kohevo.test.evil.example/admin/", $f),'
          . 'slate_safe_redirect_target("https://kohevo.testevil.example/", $f)]);';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    $r = json_decode($out, true);
    assert_true(is_array($r), 'child output: ' . $out);
    assert_eq('https://kohevo.test/admin/', $r[0], 'an absolute URL on the configured origin is kept');
    assert_eq('https://kohevo.test', $r[1], 'the bare origin is kept');
    assert_eq('/admin/', $r[2], 'another origin falls back');
    assert_eq('/admin/', $r[3], 'a lookalike subdomain of another host falls back');
    assert_eq('/admin/', $r[4], 'a prefix-sharing host falls back');
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
