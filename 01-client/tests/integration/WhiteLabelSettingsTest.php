<?php
/**
 * Phase 1E A1 — White-label identity/support settings.
 *
 * admin/help.php (lines 15,16,24) and admin/login.php (lines 513,514) always
 * read brand_owner / brand_owner_url / support_email via Database::setting(),
 * falling back to hardcoded literals ('Rakib Hasan', 'https://rakibhasaan.com',
 * 'info@rakibhasaan.com') when unset. The mechanism was already correct; the
 * only gap was that nothing wrote those settings — this adds a real form on
 * the existing Branding tab (admin/settings.php's save_branding handler) for
 * brand_owner, brand_owner_url, support_email, support_phone, support_url,
 * docs_url, privacy_url, terms_url. Per the approved plan, the hardcoded
 * fallback literals themselves are UNCHANGED (kept as the default value) —
 * this finding is purely about making them configurable.
 */

declare(strict_types=1);

/** Snapshot every white-label setting key for this tenant, return the exact undo. */
function wlst_snapshot(): callable
{
    $tid  = current_tenant_id();
    $keys = ['brand_owner', 'brand_owner_url', 'support_email', 'support_phone',
              'support_url', 'docs_url', 'privacy_url', 'terms_url'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $rows = Database::rows(
        "SELECT setting_key, setting_value FROM settings WHERE tenant_id = ? AND setting_key IN ($placeholders)",
        [$tid, ...$keys]
    );
    return static function () use ($tid, $keys, $rows): void {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key IN ($placeholders)", [$tid, ...$keys]);
        foreach ($rows as $row) {
            Database::setSetting($row['setting_key'], $row['setting_value'], $tid);
        }
    };
}

function wlst_post(array $fields): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-probe.php') . ' '
         . escapeshellarg('admin/settings.php') . ' '
         . escapeshellarg((string) json_encode($fields)) . ' '
         . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    return ['status' => (int) $m[1], 'body' => substr($out, strlen($m[0]))];
}

function wlst_get_help(): string
{
    $cmd = escapeshellarg(PHP_BINARY) . ' '
         . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-probe.php') . ' '
         . escapeshellarg('admin/help.php') . ' ' . escapeshellarg('') . ' '
         . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
    $out = (string) shell_exec($cmd);
    if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
        throw new RuntimeException('probe did not return a STATUS line: ' . $out);
    }
    return substr($out, strlen($m[0]));
}

unit('white-label: saving the branding form stores brand_owner/brand_owner_url/support_* as real settings', function (): void {
    $restore = wlst_snapshot();
    $tid = current_tenant_id();
    $suffix = bin2hex(random_bytes(4));

    try {
        $res = wlst_post([
            '_tab' => 'branding', '_action' => 'save_branding',
            'accent_color' => '', 'button_text_mode' => 'auto', 'sidebar_theme' => 'ink',
            'font_family' => 'system', 'border_radius' => 'medium',
            'brand_owner'     => "Acme Corp $suffix",
            'brand_owner_url' => "https://acme-$suffix.example.com",
            'support_email'   => "support-$suffix@example.com",
            'support_phone'   => '+1 555 0100',
            'support_url'     => "https://acme-$suffix.example.com/support",
            'docs_url'        => "https://acme-$suffix.example.com/docs",
            'privacy_url'     => "https://acme-$suffix.example.com/privacy",
            'terms_url'       => "https://acme-$suffix.example.com/terms",
        ]);
        // Success redirects (POST/redirect/GET pattern, see $redirectWithFlash());
        // only the error paths render inline with a 200 + flash message.
        assert_eq(302, $res['status'], 'saving the branding form must redirect on success: ' . $res['body']);

        assert_eq("Acme Corp $suffix", Database::setting('brand_owner', $tid));
        assert_eq("https://acme-$suffix.example.com", Database::setting('brand_owner_url', $tid));
        assert_eq("support-$suffix@example.com", Database::setting('support_email', $tid));
        assert_eq('+1 555 0100', Database::setting('support_phone', $tid));
        assert_eq("https://acme-$suffix.example.com/support", Database::setting('support_url', $tid));
        assert_eq("https://acme-$suffix.example.com/docs", Database::setting('docs_url', $tid));
        assert_eq("https://acme-$suffix.example.com/privacy", Database::setting('privacy_url', $tid));
        assert_eq("https://acme-$suffix.example.com/terms", Database::setting('terms_url', $tid));
    } finally {
        $restore();
    }
});

unit('white-label: an invalid URL is rejected server-side and nothing is saved', function (): void {
    $restore = wlst_snapshot();
    try {
        $res = wlst_post([
            '_tab' => 'branding', '_action' => 'save_branding',
            'accent_color' => '', 'button_text_mode' => 'auto', 'sidebar_theme' => 'ink',
            'font_family' => 'system', 'border_radius' => 'medium',
            'brand_owner_url' => 'not-a-url',
        ]);
        assert_eq(200, $res['status']);
        assert_true(
            str_contains($res['body'], 'valid URL') || str_contains($res['body'], 'url_invalid') || !str_contains($res['body'], 'Settings saved'),
            'an invalid URL must produce a validation error, not a silent save'
        );
        assert_null(Database::setting('brand_owner_url'), 'the invalid value must not have been persisted');
    } finally {
        $restore();
    }
});

unit('white-label: an invalid support email is rejected server-side', function (): void {
    $restore = wlst_snapshot();
    try {
        $res = wlst_post([
            '_tab' => 'branding', '_action' => 'save_branding',
            'accent_color' => '', 'button_text_mode' => 'auto', 'sidebar_theme' => 'ink',
            'font_family' => 'system', 'border_radius' => 'medium',
            'support_email' => 'not-an-email',
        ]);
        assert_eq(200, $res['status']);
        assert_null(Database::setting('support_email'), 'an invalid email must not have been persisted');
    } finally {
        $restore();
    }
});

unit('white-label: admin/help.php reflects a configured owner/support value over the hardcoded fallback', function (): void {
    $restore = wlst_snapshot();
    $tid = current_tenant_id();
    $suffix = bin2hex(random_bytes(4));
    $owner = "Configured Owner $suffix";
    $email = "configured-$suffix@example.com";

    try {
        Database::setSetting('brand_owner', $owner, $tid);
        Database::setSetting('support_email', $email, $tid);

        $body = wlst_get_help();
        assert_true(str_contains($body, htmlspecialchars($owner, ENT_QUOTES)), 'help.php must render the configured owner name');
        assert_true(str_contains($body, htmlspecialchars($email, ENT_QUOTES)), 'help.php must render the configured support email');
        assert_false(str_contains($body, 'Rakib Hasan'), 'the hardcoded fallback owner must not appear once a real value is configured');
    } finally {
        $restore();
    }
});

unit('white-label: admin/help.php falls back to the existing hardcoded defaults when nothing is configured', function (): void {
    $restore = wlst_snapshot();
    try {
        $body = wlst_get_help();
        // Per the approved plan, the fallback literal is intentionally
        // UNCHANGED — this pins that the pre-existing default behavior
        // survives the addition of the new configurable fields.
        assert_true(str_contains($body, 'Rakib Hasan'), 'with nothing configured, the existing hardcoded owner fallback must still render');
    } finally {
        $restore();
    }
});

unit('white-label: a save_branding POST with an invalid CSRF token is rejected and nothing is saved', function (): void {
    $restore = wlst_snapshot();
    try {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(dirname(__DIR__) . '/fixtures/admin-page-post-badcsrf-probe.php') . ' '
             . escapeshellarg('admin/settings.php') . ' '
             . escapeshellarg((string) json_encode([
                   '_tab' => 'branding', '_action' => 'save_branding',
                   'brand_owner' => 'CSRF bypass attempt',
               ])) . ' '
             . escapeshellarg('1') . ' ' . escapeshellarg('0') . ' 2>/dev/null';
        $out = (string) shell_exec($cmd);
        if (!preg_match('/^STATUS (\d+)\n/', $out, $m)) {
            throw new RuntimeException('probe did not return a STATUS line: ' . $out);
        }
        $body = substr($out, strlen($m[0]));

        assert_false(str_contains($body, 'Settings saved'), 'a forged/invalid CSRF token must not result in a successful save');
        assert_null(Database::setting('brand_owner'), 'the value must never be persisted when csrf_verify() rejects the request');
    } finally {
        $restore();
    }
});
