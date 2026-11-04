<?php
/**
 * Shop checkout protection (ShopGuard): the trap field, the signed time stamp (too quick, missing, forged, stale), a
 * stamp kept when the form is shown again, Turnstile through a stand-in, the per-visitor and per-email limits, the
 * circuit breaker (pause, its alert once an hour, resume), the discount-code limit, the guard running only once the form
 * is valid, and the whole thing switched off.
 *
 * Synthetic data; visitors are documentation addresses (203.0.113.0/24) made unique per run. Every row and setting this
 * file writes is removed or restored.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/plugins/shop/ShopStoreAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/shop/ShopGuard.php';

$sg = ['settings' => [], 'refs' => [], 'products' => []];
$sgSet = function (string $key, string $value) use (&$sg): void {
    if (!array_key_exists($key, $sg['settings'])) $sg['settings'][$key] = Database::setting('shop.' . $key);
    ShopStoreAPI::setSetting($key, $value);
};
$sgTag = 'sg' . bin2hex(random_bytes(3));
$sgIp = static fn(): string => '203.0.113.' . random_int(1, 254);
$sgServer = static fn(string $ip): array => ['REMOTE_ADDR' => $ip];
$sgForm = static fn(int $shownAgo, array $more = []): array => $more + [ShopGuard::STAMP_FIELD => ShopGuard::stamp(time() - $shownAgo), ShopGuard::TRAP_FIELD => '', 'email' => 'buyer@guard.example.test'];
$sgUnpaid = function (int $count, string $email) use (&$sg, $sgTag): void {
    for ($i = 0; $i < $count; $i++) {
        $ref = strtoupper($sgTag) . '-' . bin2hex(random_bytes(3));
        Database::insert('shop_orders', ['tenant_id' => current_tenant_id(), 'ref' => $ref, 'access_key' => bin2hex(random_bytes(20)), 'email' => $email, 'name' => 'Guard Test', 'status' => 'pending_payment', 'currency' => 'USD']);
        $sg['refs'][] = $ref;
    }
};

try {
    ShopStoreAPI::ensureSchema();
    foreach (['guard_enabled' => '1', 'guard_ip_10min' => '6', 'guard_ip_day' => '25', 'guard_email_hour' => '4', 'guard_pause_hour' => '0', 'guard_coupon_10min' => '15',
              'turnstile_site_key' => '', 'turnstile_secret' => '', 'guard_paused_until' => '', 'guard_alerted_at' => '', 'notify_email' => ''] as $k => $v) {
        $sgSet($k, $v);
    }

    unit('shop guard: the form carries a trap and a signed stamp; a form shown again keeps its stamp', function (): void {
        $html = ShopGuard::formFields();
        assert_true(str_contains($html, 'name="' . ShopGuard::TRAP_FIELD . '"') && str_contains($html, 'tabindex="-1"') && str_contains($html, 'aria-hidden="true"'), 'a hidden trap');
        assert_true((bool) preg_match('/name="' . ShopGuard::STAMP_FIELD . '" value="(\d+)\.[a-f0-9]{32}"/', $html), 'a signed time');
        $old = ShopGuard::stamp(time() - 120);
        assert_true(str_contains(ShopGuard::formFields($old), 'value="' . $old . '"'), 'kept when the form comes back');
        assert_true(!str_contains(ShopGuard::formFields(time() - 120 . '.forged'), 'forged'), 'a forged one is replaced');
        assert_eq('', ShopGuard::challenge(), 'no Turnstile without its keys');
    });

    unit('shop guard: a filled trap, a missing or forged stamp, a too-quick or stale form are refused', function () use ($sgForm, $sgServer, $sgIp): void {
        $ip = $sgIp();
        assert_eq('trap', ShopGuard::check($sgForm(30, [ShopGuard::TRAP_FIELD => 'http://spam.test']), $sgServer($ip))['reason']);
        assert_eq('stamp', ShopGuard::check([ShopGuard::TRAP_FIELD => '', 'email' => 'a@b.test'], $sgServer($ip))['reason']);
        assert_eq('stamp', ShopGuard::check($sgForm(30, [ShopGuard::STAMP_FIELD => (time() - 30) . '.0123456789abcdef0123456789abcdef']), $sgServer($ip))['reason']);
        $fast = ShopGuard::check($sgForm(1), $sgServer($ip));
        assert_eq(['fast', 429], [$fast['reason'], $fast['status']]);
        assert_true($fast['error'] !== '');
        assert_eq('stale', ShopGuard::check($sgForm(ShopGuard::MAX_AGE + 60), $sgServer($ip))['reason']);
        assert_true(ShopGuard::check($sgForm(30), $sgServer($ip))['ok'], 'a person filling the form in 30 seconds goes through');
    });

    unit('shop guard: a visitor may start only so many orders in 10 minutes; another visitor is not affected', function () use ($sgForm, $sgServer, $sgIp, $sgSet): void {
        $sgSet('guard_ip_10min', '2');
        $tag = bin2hex(random_bytes(2));
        // A fresh address per run: the counts live in files that outlast the test.
        $server = ['REMOTE_ADDR' => '2001:db8:' . $tag . '::1'];
        assert_true(ShopGuard::check($sgForm(30), $server)['ok']);
        assert_true(ShopGuard::check($sgForm(30), $server)['ok']);
        $third = ShopGuard::check($sgForm(30), $server);
        assert_eq(['ip', 429], [$third['reason'], $third['status']]);
        assert_eq('ip', ShopGuard::check($sgForm(30), ['REMOTE_ADDR' => '2001:db8:' . $tag . '::99'])['reason'], 'the same /64 counts as one visitor');
        assert_true(ShopGuard::check($sgForm(30), ['REMOTE_ADDR' => '2001:db8:' . $tag . 'f::1'])['ok'], 'another visitor still can');
        $sgSet('guard_ip_10min', '0');
        assert_true(ShopGuard::check($sgForm(30), $server)['ok'], '0 switches the limit off');
        $sgSet('guard_ip_10min', '6');
    });

    unit('shop guard: unpaid orders per email are limited', function () use ($sgForm, $sgServer, $sgIp, $sgUnpaid, $sgTag): void {
        $email = $sgTag . '@guard.example.test';
        $sgUnpaid(4, $email);
        $r = ShopGuard::check($sgForm(30, ['email' => strtoupper($email)]), $sgServer($sgIp()));
        assert_eq('email', $r['reason'], 'the case of the email does not matter');
        assert_true(ShopGuard::check($sgForm(30, ['email' => 'other-' . $email]), $sgServer($sgIp()))['ok']);
    });

    unit('shop guard: unpaid orders piling up pause online checkout for an hour; it can be resumed', function () use ($sgForm, $sgServer, $sgIp, $sgSet): void {
        $now = ShopGuard::unpaidSince(3600);
        $sgSet('guard_pause_hour', (string) max(1, $now));
        $r = ShopGuard::check($sgForm(30, ['email' => 'fresh@guard.example.test']), $sgServer($sgIp()));
        assert_eq(['paused', 503], [$r['reason'], $r['status']]);
        assert_true(ShopGuard::pausedUntil() > time() + 3500, 'paused for an hour');
        $alerted = ShopStoreAPI::setting('guard_alerted_at');
        assert_true($alerted !== '', 'the alert time is kept');
        ShopGuard::trip();
        assert_eq($alerted, ShopStoreAPI::setting('guard_alerted_at'), 'one alert an hour');
        $sgSet('guard_pause_hour', '0');
        assert_eq('paused', ShopGuard::check($sgForm(30), $sgServer($sgIp()))['reason'], 'still paused until the hour has passed');
        ShopGuard::resume();
        assert_true(ShopGuard::check($sgForm(30, ['email' => 'fresh@guard.example.test']), $sgServer($sgIp()))['ok'], 'open again');
    });

    unit('shop guard: with Turnstile keys the check is required and asked of Cloudflare', function () use ($sgForm, $sgServer, $sgIp, $sgSet): void {
        $sgSet('turnstile_site_key', '0x4AAAAAAATestSiteKey');
        $sgSet('turnstile_secret', 'plain-test-secret');
        $asked = [];
        ShopGuard::$turnstileTransport = static function (string $secret, string $token, string $ip) use (&$asked): bool { $asked[] = [$secret, $token]; return $token === 'good-token'; };
        try {
            assert_true(str_contains(ShopGuard::challenge(), 'data-sb-turnstile="0x4AAAAAAATestSiteKey"'), 'the widget is shown');
            assert_eq('turnstile', ShopGuard::check($sgForm(30), $sgServer($sgIp()))['reason'], 'no token');
            assert_eq('turnstile', ShopGuard::check($sgForm(30, ['cf-turnstile-response' => 'bad']), $sgServer($sgIp()))['reason']);
            assert_true(ShopGuard::check($sgForm(30, ['cf-turnstile-response' => 'good-token']), $sgServer($sgIp()))['ok']);
            assert_eq(['plain-test-secret', 'good-token'], end($asked));
        } finally {
            ShopGuard::$turnstileTransport = null;
            $sgSet('turnstile_site_key', '');
            $sgSet('turnstile_secret', '');
        }
    });

    unit('shop guard: discount codes have their own per-visitor limit', function () use ($sgSet): void {
        $sgSet('guard_coupon_10min', '2');
        $server = ['REMOTE_ADDR' => '2001:db8:c' . bin2hex(random_bytes(2)) . '::1'];
        assert_true(ShopGuard::allowCoupon($server) && ShopGuard::allowCoupon($server));
        assert_true(!ShopGuard::allowCoupon($server));
        $sgSet('guard_coupon_10min', '15');
    });

    unit('shop guard: checkout asks the guard only once the form is valid, and a refusal makes no order', function () use (&$sg, $sgTag): void {
        $res = ShopStoreAPI::saveProduct(['title' => $sgTag . ' Guarded', 'price' => '5.00', 'status' => 'published', 'currency' => 'USD', 'slug' => $sgTag . '-guarded']);
        $sg['products'][] = (int) $res['id'];
        ShopStoreAPI::cartClear();
        ShopStoreAPI::cartAdd((int) $res['id'], 1);
        $asked = 0;
        $refuse = static function () use (&$asked): array { $asked++; return ['ok' => false, 'error' => 'refused by guard', 'status' => 429]; };
        $invalid = ShopStoreAPI::placeOrder(['email' => 'not-an-email', 'name' => '', 'delivery' => 'pickup'], null, $refuse);
        assert_eq([false, 0], [$invalid['ok'], $asked], 'field errors first; the guard is not asked');
        assert_true(isset($invalid['errors']['email']));
        $before = (int) Database::value("SELECT COUNT(*) FROM shop_orders WHERE tenant_id = ?", [current_tenant_id()]);
        $refused = ShopStoreAPI::placeOrder(['email' => $sgTag . '@guard.example.test', 'name' => 'Guard Test', 'delivery' => 'pickup'], null, $refuse);
        assert_eq([false, 1, 429, 'refused by guard'], [$refused['ok'], $asked, $refused['status'], $refused['errors']['_']]);
        assert_eq($before, (int) Database::value("SELECT COUNT(*) FROM shop_orders WHERE tenant_id = ?", [current_tenant_id()]), 'no order was made');
        ShopStoreAPI::cartClear();
    });

    unit('shop guard: switched off, nothing is checked and the form carries nothing extra', function () use ($sgForm, $sgSet): void {
        $sgSet('guard_enabled', '0');
        assert_eq('', ShopGuard::formFields());
        assert_true(ShopGuard::check([ShopGuard::TRAP_FIELD => 'filled'], ['REMOTE_ADDR' => '203.0.113.9'])['ok']);
        $sgSet('guard_enabled', '1');
    });
} finally {
    ShopGuard::$turnstileTransport = null;
    $tid = current_tenant_id();
    foreach ($sg['refs'] as $ref) Database::query("DELETE FROM shop_orders WHERE tenant_id = ? AND ref = ?", [$tid, $ref]);
    foreach ($sg['products'] as $pid) ShopStoreAPI::deleteProduct($pid);
    foreach ($sg['settings'] as $key => $orig) {
        if ($orig === null) Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key = ?", [$tid, 'shop.' . $key]);
        else Database::setSetting('shop.' . $key, $orig);
    }
}
