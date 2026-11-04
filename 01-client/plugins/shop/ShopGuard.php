<?php
/**
 * Shop — checkout protection against card testing and bots.
 *
 * Card numbers never reach the shop (they are typed on Stripe's or PayPal's page, which screen each try), so what a
 * card tester abuses here is the checkout itself: a script placing order after order to get payment pages to try
 * stolen cards on. A shop hit that way fills with thousands of unpaid orders and its payment account gets flagged.
 * Before an order is made, `check()` asks, in turn:
 *
 *  1. A trap field no person sees or fills, and a signed time stamp of when the form was shown: a form sent back
 *     filled in under 3 seconds, without the stamp, or with the trap filled is a script.
 *  2. Cloudflare Turnstile, when the shop has its keys (Shop › Settings › Checkout protection): a check most visitors
 *     never see, which scripts cannot pass.
 *  3. Limits per visitor (the client address, as PayThrottle reads it behind trusted proxies; counted in small files,
 *     never stored with an order): orders started per 10 minutes and per day.
 *  4. Unpaid orders per email in the last hour.
 *  5. A circuit breaker for the whole shop: when unpaid orders made in the last hour reach the limit, online checkout
 *     pauses until the hour has passed, and the shop's notification address is told (once an hour).
 * Discount codes typed at checkout have their own per-visitor limit, so codes cannot be guessed by trying.
 *
 * Every limit is a setting; 0 switches that one off. `guard_enabled` = 0 switches all of it off.
 */
require_once __DIR__ . '/ShopStoreAPI.php';

final class ShopGuard {

    public const TRAP_FIELD = 'shop_website';
    public const STAMP_FIELD = 'shop_stamp';
    public const MIN_SECONDS = 3;
    public const MAX_AGE = 86400;
    public const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** For tests: (secret, token, ip) => bool, instead of asking Cloudflare. */
    public static ?\Closure $turnstileTransport = null;
    /** For tests: the clock. */
    public static ?int $now = null;

    /** @return array<string, string> setting => default (kept with the Shop's other settings) */
    public static function defaults(): array {
        return array_filter(ShopStoreAPI::settingDefaults(), static fn(string $k): bool => str_starts_with($k, 'guard_') || str_starts_with($k, 'turnstile_'), ARRAY_FILTER_USE_KEY);
    }

    public static function enabled(): bool {
        return ShopStoreAPI::setting('guard_enabled') !== '0';
    }

    private static function limit(string $key): int {
        $v = ShopStoreAPI::setting($key);
        return ctype_digit($v) ? (int) $v : (int) (self::defaults()[$key] ?? 0);
    }

    private static function now(): int {
        return self::$now ?? time();
    }

    // ── The form's side ───────────────────────────────────────────────────

    /** The hidden fields the checkout form carries: the trap and the time stamp. */
    public static function formFields(string $keep = ''): string {
        if (!self::enabled()) return '';
        // A form shown again (a shipping quote, an error) keeps the time it was first shown.
        $at = (int) explode('.', $keep, 2)[0];
        $stamp = $keep !== '' && hash_equals(self::stamp($at), $keep) && self::now() - $at <= self::MAX_AGE ? $keep : self::stamp();
        $html = '<div class="sb-shop-sr" aria-hidden="true"><label for="sb-shop-website">Website</label>'
            . '<input type="text" id="sb-shop-website" name="' . self::TRAP_FIELD . '" value="" tabindex="-1" autocomplete="off"></div>'
            . '<input type="hidden" name="' . self::STAMP_FIELD . '" value="' . e($stamp) . '">';
        return $html;
    }

    /** The Turnstile check, shown above the pay button when the shop has its keys; else ''. */
    public static function challenge(): string {
        $site = self::turnstileSiteKey();
        if (!self::enabled() || $site === '' || self::turnstileSecret() === '') return '';
        // studio-runtime.js renders it (a fragment's scripts do not run); without JavaScript checkout says so.
        return '<div class="sb-shop-checkout__challenge" data-sb-turnstile="' . e($site) . '"></div>';
    }

    /** `time.signature`, signed with the app secret (so a script cannot make one up). */
    public static function stamp(?int $at = null): string {
        $at ??= self::now();
        return $at . '.' . substr(hash_hmac('sha256', 'shop-stamp|' . current_tenant_id() . '|' . $at, self::key()), 0, 32);
    }

    private static function key(): string {
        $secret = function_exists('slate_app_secret') ? (string) (slate_app_secret() ?? '') : '';
        return $secret !== '' ? $secret : hash('sha256', __FILE__ . (defined('SLATE_ROOT') ? SLATE_ROOT : ''));
    }

    // ── The checks ────────────────────────────────────────────────────────

    /**
     * May this checkout make an order? Call it before ShopStoreAPI::placeOrder().
     *
     * @param array<string, mixed> $post the form
     * @param array<string, mixed> $server $_SERVER
     * @return array{ok: bool, error: string, reason: string, status: int}
     */
    public static function check(array $post, array $server): array {
        if (!self::enabled()) return self::pass();
        if (self::pausedUntil() > self::now()) {
            return self::refuse('paused', __('shop_guard_paused', 'Online checkout is paused for a short while. Please try again later, or contact us to order.'), 503);
        }
        if (trim((string) ($post[self::TRAP_FIELD] ?? '')) !== '') {
            return self::refuse('trap', __('shop_guard_failed', 'Your order could not be sent. Please reload the page and try again.'), 400);
        }
        $stamp = (string) ($post[self::STAMP_FIELD] ?? '');
        $at = (int) explode('.', $stamp, 2)[0];
        if ($stamp === '' || !hash_equals(self::stamp($at), $stamp) || $at > self::now() + 60) {
            return self::refuse('stamp', __('shop_guard_failed', 'Your order could not be sent. Please reload the page and try again.'), 400);
        }
        if (self::now() - $at < self::MIN_SECONDS) {
            return self::refuse('fast', __('shop_guard_fast', 'That was quick! Please check your details and press the button again.'), 429);
        }
        if (self::now() - $at > self::MAX_AGE) {
            return self::refuse('stale', __('shop_guard_stale', 'This page has been open a long time. Please reload it and try again.'), 400);
        }
        $visitor = self::visitor($server);
        if (self::turnstileSiteKey() !== '' && self::turnstileSecret() !== '') {
            $token = (string) ($post['cf-turnstile-response'] ?? '');
            if ($token === '' || !self::turnstileValid($token, $visitor)) {
                return self::refuse('turnstile', __('shop_guard_turnstile', 'Please complete the check above the button (it needs JavaScript), then try again.'), 400);
            }
        }
        foreach ([['guard_ip_10min', 600, 'ip10'], ['guard_ip_day', 86400, 'ipday']] as [$key, $window, $dir]) {
            $max = self::limit($key);
            if ($max > 0 && !self::throttle($dir)->allow('ip|' . current_tenant_id() . '|' . $visitor, $max, $window, self::now())) {
                return self::refuse('ip', __('shop_guard_too_many', 'Too many orders were started from here. Please wait a while, or contact us to order.'), 429);
            }
        }
        $email = strtolower(trim((string) ($post['email'] ?? '')));
        $perEmail = self::limit('guard_email_hour');
        if ($email !== '' && $perEmail > 0 && self::unpaidSince(3600, $email) >= $perEmail) {
            return self::refuse('email', __('shop_guard_too_many', 'Too many orders were started from here. Please wait a while, or contact us to order.'), 429);
        }
        $pause = self::limit('guard_pause_hour');
        if ($pause > 0 && self::unpaidSince(3600) >= $pause) {
            self::trip();
            return self::refuse('paused', __('shop_guard_paused', 'Online checkout is paused for a short while. Please try again later, or contact us to order.'), 503);
        }
        return self::pass();
    }

    /** Discount codes typed at checkout: a per-visitor limit, so codes cannot be found by trying. */
    public static function allowCoupon(array $server): bool {
        $max = self::limit('guard_coupon_10min');
        if (!self::enabled() || $max <= 0) return true;
        return self::throttle('coupon')->allow('coupon|' . current_tenant_id() . '|' . self::visitor($server), $max, 600, self::now());
    }

    /** Orders not paid, made in the last `$seconds` (for one email, or the whole shop). */
    public static function unpaidSince(int $seconds, string $email = ''): int {
        $since = date('Y-m-d H:i:s', self::now() - $seconds);
        $sql = "SELECT COUNT(*) FROM shop_orders WHERE tenant_id = ? AND created_at >= ? AND status IN ('pending_payment', 'cancelled') AND paid_at IS NULL AND ref NOT LIKE 'WC-%'";
        $args = [current_tenant_id(), $since];
        if ($email !== '') { $sql .= ' AND LOWER(email) = ?'; $args[] = $email; }
        return (int) Database::value($sql, $args);
    }

    public static function pausedUntil(): int {
        return (int) ShopStoreAPI::setting('guard_paused_until');
    }

    /** Pause online checkout until an hour from now, and tell the shop (at most once an hour). */
    public static function trip(): void {
        $now = self::now();
        ShopStoreAPI::setSetting('guard_paused_until', (string) ($now + 3600));
        if ((int) ShopStoreAPI::setting('guard_alerted_at') > $now - 3600) return;
        ShopStoreAPI::setSetting('guard_alerted_at', (string) $now);
        slate_log('Shop: online checkout paused for an hour: ' . self::unpaidSince(3600) . ' unpaid orders in the last hour.', 'warning');
        if (class_exists('AuditLog')) AuditLog::record('shop.checkout_paused', 'shop', ['unpaid_last_hour' => self::unpaidSince(3600)]);
        $to = trim(ShopStoreAPI::setting('notify_email'));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || !class_exists('Mailer') || !class_exists('\\Slate\\Services\\Notifications\\EmailTemplate')) return;
        try {
            $tpl = '\\Slate\\Services\\Notifications\\EmailTemplate';
            $site = Database::setting('site_name') ?: 'Kohevo';
            $body = $tpl::shell($tpl::heading(__('shop_guard_alert_heading', 'Online checkout is paused'))
                . $tpl::paragraph(e(sprintf(__('shop_guard_alert_body', 'Many orders were started in the last hour without being paid (%d), which is how card testing looks. Online checkout is paused for an hour and starts again by itself. You can start it again sooner on Shop › Settings.'), self::unpaidSince(3600))))
                . $tpl::button(function_exists('plugin_url') ? plugin_url('shop', 'admin/settings.php') . '#checkout-protection' : '', __('shop_guard_alert_button', 'Open the settings')));
            Mailer::send($to, sprintf('%s — %s', $site, __('shop_guard_alert_subject', 'Online checkout paused')), $body);
        } catch (\Throwable $e) {
            slate_log('Shop: the checkout-paused email failed: ' . $e->getMessage(), 'warning');
        }
    }

    public static function resume(): void {
        ShopStoreAPI::setSetting('guard_paused_until', '');
    }

    // ── Turnstile ─────────────────────────────────────────────────────────

    public static function turnstileSiteKey(): string {
        $k = trim(ShopStoreAPI::setting('turnstile_site_key'));
        return preg_match('/^[0-9A-Za-z_-]{10,100}$/', $k) === 1 ? $k : '';
    }

    public static function turnstileSecret(): string {
        $v = ShopStoreAPI::setting('turnstile_secret');
        if ($v === '') return '';
        return str_starts_with($v, 'enc:v1:') && function_exists('slate_decrypt_secret') ? (string) (slate_decrypt_secret($v) ?? '') : $v;
    }

    /** Keep a new Turnstile secret, sealed; '' forgets it. Throws when the app secret is missing. */
    public static function setTurnstileSecret(string $secret): void {
        $secret = trim($secret);
        if ($secret === '') { ShopStoreAPI::setSetting('turnstile_secret', ''); return; }
        if (!function_exists('slate_encrypt_secret') || !function_exists('slate_has_app_secret') || !slate_has_app_secret()) {
            throw new \RuntimeException(__('shop_err_no_app_secret', 'Keys cannot be stored: the app secret is not configured.'));
        }
        ShopStoreAPI::setSetting('turnstile_secret', (string) slate_encrypt_secret($secret));
    }

    private static function turnstileValid(string $token, string $visitor): bool {
        if (strlen($token) > 2048) return false;
        $secret = self::turnstileSecret();
        if (self::$turnstileTransport !== null) return (bool) (self::$turnstileTransport)($secret, $token, $visitor);
        $ch = curl_init(self::TURNSTILE_VERIFY);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token] + (filter_var($visitor, FILTER_VALIDATE_IP) ? ['remoteip' => $visitor] : [])),
        ]);
        $raw = curl_exec($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            // Cloudflare could not be asked: let the order through (the limits still hold) rather than stop real sales.
            slate_log('Shop: Turnstile could not be checked: ' . curl_error($ch), 'warning');
            return true;
        }
        return !empty($data['success']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function visitor(array $server): string {
        $proxies = function_exists('env') ? (string) env('STUDIO_TRUSTED_PROXIES', '') : '';
        return class_exists('\\Slate\\Module\\StudioBuilder\\Payment\\PayThrottle')
            ? \Slate\Module\StudioBuilder\Payment\PayThrottle::visitor($server, $proxies)
            : trim((string) ($server['REMOTE_ADDR'] ?? ''));
    }

    private static function throttle(string $name): \Slate\Module\StudioBuilder\Payment\PayThrottle {
        $root = defined('SLATE_ROOT') ? SLATE_ROOT : dirname(__DIR__, 2);
        return new \Slate\Module\StudioBuilder\Payment\PayThrottle($root . '/data/shop-guard/' . $name, sys_get_temp_dir() . '/kohevo-shop-guard-' . $name);
    }

    /** @return array{ok: bool, error: string, reason: string, status: int} */
    private static function pass(): array {
        return ['ok' => true, 'error' => '', 'reason' => '', 'status' => 200];
    }

    /** @return array{ok: bool, error: string, reason: string, status: int} */
    private static function refuse(string $reason, string $error, int $status): array {
        // Not written to the audit log one by one: an attack would fill it. The pause is (trip()).
        return ['ok' => false, 'error' => $error, 'reason' => $reason, 'status' => $status];
    }
}
