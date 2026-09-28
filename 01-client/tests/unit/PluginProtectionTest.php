<?php
/**
 * Plugin protection ("lock") on admin/plugins.php.
 *
 * Deactivate / uninstall / upload are refused unless an admin has unlocked changes with their password, and the
 * unlock expires. The time/identity rules are pure and are RUN here; the server-side wiring is pinned with source
 * checks (the repo's usual style) — most importantly that the refusal happens before any handler and that the
 * typed uninstall confirmation is verified on the server, not only in the browser.
 */

declare(strict_types=1);

if (!class_exists('Auth')) {
    // The real class needs a session/DB; the helper only asks who is logged in.
    class Auth { public static function userId(): ?int { return 42; } }
}
require_once __DIR__ . '/../../includes/plugin_protection.php';

$__page = file_get_contents(__DIR__ . '/../../admin/plugins.php');

unit('lock: changes are locked by default, unlock is time-limited and tied to the admin who unlocked', function () {
    $_SESSION = [];
    assert_true(slate_plugins_locked(), 'a fresh session is locked');
    assert_eq(0, slate_plugins_unlocked_until());

    $uid = (int) Auth::userId();
    $_SESSION = ['plugins_unlocked_until' => time() + 600, 'plugins_unlocked_uid' => $uid];
    assert_true(!slate_plugins_locked(), 'unlocked while the window is open');
    assert_true(slate_plugins_unlocked_until() > time());

    $_SESSION = ['plugins_unlocked_until' => time() - 1, 'plugins_unlocked_uid' => $uid];
    assert_true(slate_plugins_locked(), 'an expired unlock is locked again');

    $_SESSION = ['plugins_unlocked_until' => time() + 600, 'plugins_unlocked_uid' => $uid + 1];
    assert_true(slate_plugins_locked(), 'an unlock made by another admin does not carry over');

    $_SESSION = ['plugins_unlocked_until' => 'garbage', 'plugins_unlocked_uid' => 'x'];
    assert_true(slate_plugins_locked(), 'junk session values fail closed');
    $_SESSION = [];
    assert_eq(900, SLATE_PLUGINS_UNLOCK_SECONDS, 'unlock lasts 15 minutes');
});

unit('icons: every bundled plugin has its own icon, unknown slugs get a neutral one, and the slug is never echoed', function () {
    $known = ['booking', 'forms', 'membership', 'coaching', 'stripe-payment', 'multilang-translate', 'media-library', 'mcp-gateway', 'backups'];
    $seen = [];
    foreach ($known as $slug) {
        $svg = slate_plugin_icon_svg($slug);
        assert_true(str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>'), "$slug icon is an svg");
        $seen[$svg] = true;
    }
    assert_eq(count($known), count($seen), 'each bundled plugin has a distinct icon');
    $fallback = slate_plugin_icon_svg('some-unknown-plugin');
    assert_true(!isset($seen[$fallback]), 'unknown plugins get the neutral package icon');
    assert_true(!str_contains(slate_plugin_icon_svg('"><script>alert(1)</script>'), 'script'), 'the slug is never written into the markup');
});

unit('server: the lock is enforced before any handler, for exactly upload / deactivate / uninstall (never activate)', function () use ($__page) {
    $csrf  = strpos($__page, 'if (!csrf_verify())');
    $guard = strpos($__page, "in_array(\$action, ['upload', 'deactivate', 'uninstall'], true) && slate_plugins_locked()");
    $first = strpos($__page, "elseif (\$action === 'upload')");
    assert_true($csrf !== false && $guard !== false && $first !== false, 'markers present');
    assert_true($csrf < $guard && $guard < $first, 'CSRF check, then the lock, then the handlers');
    assert_true(str_contains($__page, "\$action = '';"), 'a refused action must not fall through to its handler');
    assert_false(str_contains($__page, "['upload', 'deactivate', 'uninstall', 'activate'"), 'activating is never locked');
    assert_true(str_contains($__page, "AuditLog::record('plugin.change_blocked'"), 'blocked attempts are audited');
});

unit('server: unlocking needs the password, is throttled and audited; locking is immediate', function () use ($__page) {
    assert_true(str_contains($__page, "password_verify((string)(\$_POST['password'] ?? ''), (string)\$row['password_hash'])"));
    assert_true(str_contains($__page, "\$fails >= 5") && str_contains($__page, 'plugins_unlock_cooldown'), 'five wrong passwords start a cooldown');
    foreach (['plugin.protection_unlocked', 'plugin.protection_unlock_failed', 'plugin.protection_locked'] as $ev) {
        assert_true(str_contains($__page, "AuditLog::record('$ev'"), "$ev is audited");
    }
    assert_true(str_contains($__page, "unset(\$_SESSION['plugins_unlocked_until'], \$_SESSION['plugins_unlocked_uid'])"), 'lock now clears the unlock');
});

unit('server: uninstall requires the plugin name typed (checked here, not only by the browser prompt)', function () use ($__page) {
    assert_true(str_contains($__page, "trim((string)(\$_POST['confirm_slug'] ?? '')) !== \$slug"));
    assert_true(str_contains($__page, 'data-plug-type='), 'the browser also asks for it');
});

unit('cards: no author, no description, no homepage; the destructive actions are behind the menu', function () use ($__page) {
    foreach (["\$manifest['author']", "\$manifest['description']", "\$manifest['homepage']", 'plug-desc', 'plug-tag'] as $gone) {
        assert_false(str_contains($__page, $gone), "$gone must not be rendered any more");
    }
    assert_true(str_contains($__page, 'class="plug-menu"'), 'download / uninstall live in the ⋯ menu');
    assert_true(str_contains($__page, 'role="switch"'), 'the on/off control is an accessible switch');
    assert_true(str_contains($__page, "\$showUpload      = \$canUpload && !\$locked"), 'the upload box only appears when unlocked');
});
