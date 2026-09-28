<?php
/**
 * Booking widget auto-height (iframe embed).
 *
 * The widget reports its height, a small host-page script (assets/js/embed.js) applies it. Two layers of checks:
 *  - the host script is EXECUTED in a sandbox (node) with a fake window, so its security rules — only the
 *    iframe's own window and origin are trusted, heights are clamped — are proven by running them;
 *  - source checks pin the widget side and the admin snippet (the repo's usual style).
 * The sandbox test is skipped, loudly, when node is not installed.
 */

declare(strict_types=1);

$__booking = __DIR__ . '/../../plugins/booking';

unit('embed.js (executed in a sandbox): applies heights from the iframe itself, ignores everything else', function () use ($__booking) {
    $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
    if ($node === '') { assert_true(true, 'node not installed — sandbox test skipped'); return; }
    $out = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/js/embed-host.test.js') . ' ' . escapeshellarg($__booking . '/assets/js/embed.js') . ' 2>&1');
    $r = json_decode(trim($out), true);
    assert_true(is_array($r), 'the sandbox test must produce JSON, got: ' . substr($out, 0, 200));
    foreach ([
        'appliesHeight', 'dropsMinHeight', 'noScrollAttr', 'acceptsLegacyMessageName',
        'ignoresWrongOrigin', 'ignoresOtherWindow', 'ignoresBadHeights', 'ignoresOtherMessages',
        'handshakeSentToIframeOrigin', 'noScrollOnVeryFirstLoad', 'scrollsBackWhenTopIsOutOfView', 'doesNotJumpWhenAlreadyInView',
        'detectsLegacyIframeWithoutAttribute', 'ignoresUnrelatedIframes', 'loadingTwiceRegistersOnce',
    ] as $check) {
        assert_true(($r[$check] ?? null) === true, "embed.js behaviour failed: $check");
    }
});

unit('widget reporter measures the shell (not body/html) so the frame can shrink, and flags each new page load', function () use ($__booking) {
    $src = file_get_contents($__booking . '/public/router.php');
    assert_true(str_contains($src, 'function bookpub_embed_reporter_js(): string'));
    $js = substr($src, (int) strpos($src, 'function bookpub_embed_reporter_js'));
    $js = substr($js, 0, (int) strpos($js, "\n}\n") + 3);
    assert_true(str_contains($js, '.book-shell'), 'height must come from the widget shell');
    assert_true(str_contains($js, 'offsetHeight'), 'layout height, so the fade-in transform does not skew it');
    assert_false(str_contains($js, 'scrollHeight'), 'scrollHeight is never smaller than the iframe and would stop the frame from shrinking');
    assert_false(str_contains($js, 'document.body.getBoundingClientRect'), 'body fills the viewport in this app, so it mirrors the iframe height');
    assert_true(str_contains($js, 'kohevo-booking-height') && str_contains($js, 'first:f'), 'current message + first-load flag');
    assert_true(str_contains($js, 'cb-booking-height'), 'the legacy message stays for the portal / Content Builder listener');
    assert_true(str_contains($js, 'kohevo-booking-host') && str_contains($js, 'is-autosized'), 'the scrollbar is hidden only after the host confirms it is auto-sizing');
    assert_true(str_contains($src, "echo '<script>' . bookpub_embed_reporter_js() . '</script>';"), 'the reporter is emitted in embed mode');
});

unit('admin snippet: iframe + helper script, one source, escaped for display, copyable', function () use ($__booking) {
    $api = file_get_contents($__booking . '/BookingAPI.php');
    assert_true(str_contains($api, 'public static function embedSnippet(): string'));
    assert_true(str_contains($api, "assets/js/embed.js"), 'the snippet loads the helper script');
    assert_true(str_contains($api, 'data-kohevo-booking'), 'the iframe is marked for the helper');
    assert_true(str_contains($api, "e(self::embedSnippet())"), 'displayed text is escaped');
    foreach (['admin/index.php', 'admin/settings.php'] as $page) {
        assert_true(str_contains(file_get_contents("$__booking/$page"), 'BookingAPI::embedSnippetBlock('), "$page uses the shared snippet");
    }
    assert_false(str_contains(file_get_contents($__booking . '/admin/index.php'), 'min-height:640px'), 'the old fixed-height snippet is gone');
});

unit('embedded card CSS: capped width, no global overflow trap, opt-in flush mode, calendar cannot balloon', function () use ($__booking) {
    $css = file_get_contents($__booking . '/assets/css/public.css');
    assert_true(str_contains($css, 'html.is-autosized'), 'scrollbar hiding is gated on the host handshake');
    assert_false((bool) preg_match('/^\s*html\s*\{[^}]*overflow\s*:\s*hidden/m', $css), 'never hide overflow unconditionally: a host without the script would clip the widget');
    assert_true((bool) preg_match('/body\.book-public-embed \.book-cal\s*\{\s*max-width:\s*440px/', $css), 'calendar is capped');
    assert_true(str_contains($css, 'body.book-public-bare'), '?chrome=0 flush mode');
    $router = file_get_contents($__booking . '/public/router.php');
    assert_true(str_contains($router, "book-public-bare"), 'router applies the flush class only for ?chrome=0 in embed mode');
});
