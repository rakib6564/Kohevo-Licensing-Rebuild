<?php
/**
 * Embedding / frame policy for the booking widget (cross-site <iframe> support).
 *
 * The origin functions are pure and decide which ?return= addresses are trusted, so they get an adversarial set:
 * lookalike hosts, credentials in the URL, non-https, protocol tricks. The router checks are source inspections
 * (the repo's existing style) that pin the behaviour that matters: the confirm step never renders in an iframe.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';

$__allowed = ['https://solaya.stelaire.be'];

unit('slate_normalize_embed_origins: keeps valid https origins, drops the rest, dedupes', function () {
    assert_eq(['https://a.example', 'https://b.example:8443'], slate_normalize_embed_origins("https://A.example/some/path, https://b.example:8443\nhttps://a.example"));
    assert_eq([], slate_normalize_embed_origins('http://a.example, ftp://a.example, javascript:alert(1), //a.example, a.example, not a url'));
    assert_eq([], slate_normalize_embed_origins('https://user:pw@a.example'), 'credentials in the URL are rejected');
    assert_eq(['http://localhost:3000'], slate_normalize_embed_origins('http://localhost:3000'), 'plain http only for local development');
});

unit('slate_embed_origin_allowed: exact-origin match, any path, so a return URL can never become an open redirect', function () use ($__allowed) {
    assert_true(slate_embed_origin_allowed('https://solaya.stelaire.be/accompagnements/nutrition-pleine-sante/', $__allowed));
    assert_true(slate_embed_origin_allowed('https://SOLAYA.stelaire.be/x?y=1#z', $__allowed), 'host case does not matter');
    foreach ([
        'https://evil.example/',
        'https://solaya.stelaire.be.evil.example/',      // allowed host as a prefix of another
        'https://evil.example/https://solaya.stelaire.be/',
        'https://solaya.stelaire.be@evil.example/',      // credentials trick
        'https://evil.example@solaya.stelaire.be/',
        'http://solaya.stelaire.be/',                     // downgrade
        'https://solaya.stelaire.be:8443/',               // different port = different origin
        '//solaya.stelaire.be/',                          // protocol-relative
        'javascript:alert(1)',
        'https://solaya.stelaire.be\\@evil.example/',     // backslash parser confusion
        "https://solaya.stelaire.be/\r\nLocation: https://evil.example",
        'https://solaya.stelaire.be/ https://evil.example',
        '/relative/path',
        '',
    ] as $bad) {
        assert_false(slate_embed_origin_allowed($bad, $__allowed), 'must be rejected: ' . json_encode($bad));
    }
    assert_false(slate_embed_origin_allowed('https://solaya.stelaire.be/', []), 'an empty allow-list trusts nothing');
});

unit('booking router: the confirm step is never rendered inside an iframe, and slot links leave it', function () {
    $src = file_get_contents(__DIR__ . '/../../plugins/booking/public/router.php');
    assert_eq(3, substr_count($src, 'if (bookpub_is_iframe($embed)) { bookpub_break_out('), 'each of the three step-4 entry points must break out first');
    foreach (['bookpub_render_step4($service, $provider, $soloDate', 'bookpub_render_step4($service, $provider, \'\', \'\'', 'bookpub_render_step4($service, $provider, $date, $slot'] as $render) {
        $call = strpos($src, $render);
        assert_true($call !== false, 'expected call not found: ' . $render);
        $breakout = strrpos(substr($src, 0, $call), 'bookpub_break_out(');
        assert_true($breakout !== false && ($call - $breakout) < 700, 'a break-out must immediately precede: ' . $render);
    }
    assert_true(str_contains($src, "' target=\"_top\" rel=\"noopener\"'"), 'links that need a session must target the top window');
    assert_true(str_contains($src, 'slate_send_frame_policy(true);'), 'the widget relaxes framing only for the allowed sites');
    assert_true(str_contains($src, 'window.top.location.replace('), 'the break-out page navigates the top window');
});

unit('frame policy is applied to every web response by default (config.php) and the Forms embed keeps its own header', function () {
    $cfg = file_get_contents(__DIR__ . '/../../config.php');
    assert_true(str_contains($cfg, "if (PHP_SAPI !== 'cli') slate_send_frame_policy(false);"));
    $forms = file_get_contents(__DIR__ . '/../../plugins/forms/public/router.php');
    assert_true(str_contains($forms, 'frame-ancestors *'), 'Forms embeds are deliberately unchanged by this work');
});
