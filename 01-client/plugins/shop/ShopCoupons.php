<?php
/**
 * Shop — coupons.
 *
 * A coupon takes a percentage off, a fixed amount off, or makes shipping free. It may need a minimum subtotal, start
 * and end on given dates, be usable a number of times in all (counted when an order is paid) and per customer email,
 * apply only to some products or categories, and leave out items already on sale.
 *
 * The cart holds one coupon code in the visitor's session; it is checked again every time the totals are worked out,
 * so a coupon that ends, runs out or stops fitting the cart drops off with a note. The discount is shared across the
 * lines it applies to (a fixed amount in proportion to each line, the rounding cents on the largest), so tax is worked
 * out on what each line really costs.
 */

require_once __DIR__ . '/ShopStoreAPI.php';

class ShopCoupons {

    public const KINDS = ['percent', 'fixed', 'free_shipping'];
    private const SESSION_KEY = 'kohevo_shop_coupon';

    public static function normalise(string $code): string {
        return mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9_-]+/', '', $code) ?? ''), 0, 40);
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array {
        ShopStoreAPI::ensureSchema();
        return Database::rows("SELECT * FROM shop_coupons WHERE tenant_id = ? ORDER BY enabled DESC, code", [current_tenant_id()]);
    }

    /** Whether the shop has a coupon a customer could use now: without one, the cart and checkout show no code box. */
    public static function anyActive(): bool {
        static $memo = [];
        $tid = current_tenant_id();
        if (!isset($memo[$tid])) {
            ShopStoreAPI::ensureSchema();
            $memo[$tid] = (int) Database::value("SELECT COUNT(*) FROM shop_coupons WHERE tenant_id = ? AND enabled = 1 AND (ends_at IS NULL OR ends_at > ?)
                AND (usage_limit IS NULL OR used_count < usage_limit)", [$tid, date('Y-m-d H:i:s')]) > 0;
        }
        return $memo[$tid];
    }

    public static function find(string $code): ?array {
        $code = self::normalise($code);
        if ($code === '') return null;
        ShopStoreAPI::ensureSchema();
        return Database::row("SELECT * FROM shop_coupons WHERE tenant_id = ? AND code = ?", [current_tenant_id(), $code]) ?: null;
    }

    public static function get(int $id): ?array {
        return Database::row("SELECT * FROM shop_coupons WHERE tenant_id = ? AND id = ?", [current_tenant_id(), $id]) ?: null;
    }

    /** Comma-separated ids as a list of positive ints. */
    public static function ids(mixed $raw): array {
        if (is_array($raw)) $raw = implode(',', $raw);
        return array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $raw)), static fn(int $i): bool => $i > 0)));
    }

    /**
     * @param array<string, mixed> $in code, kind, value (percent, or a price for fixed), min_subtotal, starts_at,
     *                                 ends_at, usage_limit, per_customer, product_ids[], category_ids[], exclude_sale,
     *                                 enabled, note
     * @return array{ok: bool, id?: int, errors?: array<string, string>}
     */
    public static function save(array $in, int $id = 0): array {
        ShopStoreAPI::ensureSchema();
        $errors = [];
        $code = self::normalise((string) ($in['code'] ?? ''));
        if ($code === '') $errors['code'] = __('shop_err_coupon_code', 'Use letters, digits, hyphens and underscores.');
        elseif (($other = self::find($code)) && (int) $other['id'] !== $id) $errors['code'] = __('shop_err_coupon_taken', 'Another coupon already uses this code.');
        $kind = in_array($in['kind'] ?? '', self::KINDS, true) ? (string) $in['kind'] : 'percent';
        $value = 0;
        $raw = trim((string) ($in['value'] ?? ''));
        if ($kind === 'percent') {
            if (!preg_match('/^\d{1,3}$/', $raw) || (int) $raw < 1 || (int) $raw > 100) $errors['value'] = __('shop_err_coupon_percent', 'Enter a whole percentage from 1 to 100.');
            else $value = (int) $raw;
        } elseif ($kind === 'fixed') {
            $v = ShopStoreAPI::parseMoney($raw);
            if ($v === null || $v <= 0) $errors['value'] = __('shop_err_price', 'Enter a price, such as 24.90.');
            else $value = $v;
        }
        $minRaw = trim((string) ($in['min_subtotal'] ?? ''));
        $min = ShopStoreAPI::parseMoney($minRaw === '' ? '0' : $minRaw);
        if ($min === null) $errors['min_subtotal'] = __('shop_err_price', 'Enter a price, such as 24.90.');
        $dates = [];
        foreach (['starts_at', 'ends_at'] as $k) {
            $d = ShopStoreAPI::parseDateTime($in[$k] ?? '');
            if ($d === false) $errors[$k] = __('shop_err_date', 'Enter a date, such as 2026-12-01 09:00.');
            $dates[$k] = $d === false ? null : $d;
        }
        if ($dates['starts_at'] && $dates['ends_at'] && $dates['ends_at'] <= $dates['starts_at']) $errors['ends_at'] = __('shop_err_coupon_dates', 'The end must come after the start.');
        $whole = static function (string $k) use ($in, &$errors): ?int {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') return null;
            if (!preg_match('/^\d{1,7}$/', $v)) { $errors[$k] = __('shop_err_whole_number', 'Enter a whole number, or leave it empty.'); return null; }
            return (int) $v;
        };
        $limit = $whole('usage_limit');
        $perCustomer = $whole('per_customer');
        if ($errors) return ['ok' => false, 'errors' => $errors];
        $row = [
            'code' => $code, 'kind' => $kind, 'value' => $value, 'min_subtotal_cents' => (int) $min,
            'starts_at' => $dates['starts_at'], 'ends_at' => $dates['ends_at'], 'usage_limit' => $limit, 'per_customer' => $perCustomer,
            'product_ids' => implode(',', self::ids($in['product_ids'] ?? '')) ?: null, 'category_ids' => implode(',', self::ids($in['category_ids'] ?? '')) ?: null,
            'exclude_sale' => !empty($in['exclude_sale']) ? 1 : 0, 'enabled' => !empty($in['enabled']) ? 1 : 0,
            'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null,
        ];
        $tid = current_tenant_id();
        if ($id > 0) Database::update('shop_coupons', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
        else $id = (int) Database::insert('shop_coupons', $row + ['tenant_id' => $tid]);
        return ['ok' => true, 'id' => $id];
    }

    public static function delete(int $id): void {
        Database::query("DELETE FROM shop_coupons WHERE id = ? AND tenant_id = ?", [$id, current_tenant_id()]);
    }

    /** `10% off`, `$5.00 off`, `Free shipping`. */
    public static function describe(array $c, ?string $currency = null): string {
        return match ((string) $c['kind']) {
            'percent' => sprintf(__('shop_coupon_percent_off', '%d%% off'), (int) $c['value']),
            'fixed' => sprintf(__('shop_coupon_amount_off', '%s off'), ShopStoreAPI::money((int) $c['value'], $currency)),
            default => __('shop_coupon_free_shipping', 'Free shipping'),
        };
    }

    // ── The cart's coupon ────────────────────────────────────────────────

    public static function sessionCode(): string {
        return (string) ($_SESSION[self::SESSION_KEY][(string) current_tenant_id()] ?? '');
    }

    public static function setSessionCode(string $code): void {
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_SESSION)) $_SESSION = [];
        $tid = (string) current_tenant_id();
        if ($code === '') unset($_SESSION[self::SESSION_KEY][$tid]);
        else $_SESSION[self::SESSION_KEY][$tid] = self::normalise($code);
    }

    /**
     * Apply a code to the visitor's cart: kept when it exists and fits the cart now.
     *
     * @return array{ok: bool, message: string}
     */
    public static function apply(string $code, array $lines, string $email = ''): array {
        $c = self::find($code);
        if (!$c) return ['ok' => false, 'message' => __('shop_coupon_unknown', 'That code is not valid.')];
        $res = self::evaluate($c, $lines, $email);
        if (!$res['ok']) return ['ok' => false, 'message' => $res['error']];
        self::setSessionCode((string) $c['code']);
        return ['ok' => true, 'message' => sprintf(__('shop_coupon_applied', 'Code %1$s applied: %2$s.'), $c['code'], self::describe($c, $lines[0]['currency'] ?? null))];
    }

    /**
     * Whether a coupon fits a cart now, and what it takes off.
     *
     * @param list<array<string, mixed>> $lines cart lines (line_cents, product_id, on_sale, key)
     * @return array{ok: bool, error: string, discount: int, per_line: array<string, int>, free_shipping: bool}
     */
    public static function evaluate(array $c, array $lines, string $email = '', ?int $now = null): array {
        $none = ['ok' => false, 'error' => '', 'discount' => 0, 'per_line' => [], 'free_shipping' => false];
        $now ??= time();
        $fail = static fn(string $msg): array => ['error' => $msg] + $none;
        if (empty($c['enabled'])) return $fail(__('shop_coupon_unknown', 'That code is not valid.'));
        if (!empty($c['starts_at']) && strtotime((string) $c['starts_at']) > $now) return $fail(__('shop_coupon_not_started', 'That code is not active yet.'));
        if (!empty($c['ends_at']) && strtotime((string) $c['ends_at']) <= $now) return $fail(__('shop_coupon_expired', 'That code has expired.'));
        if ($c['usage_limit'] !== null && (int) $c['used_count'] >= (int) $c['usage_limit']) return $fail(__('shop_coupon_used_up', 'That code has been used up.'));
        if ($email !== '' && $c['per_customer'] !== null) {
            $used = (int) Database::value("SELECT COUNT(*) FROM shop_orders WHERE tenant_id = ? AND coupon_code = ? AND LOWER(email) = ? AND status IN ('paid','fulfilled')",
                [current_tenant_id(), (string) $c['code'], strtolower($email)]);
            if ($used >= (int) $c['per_customer']) return $fail(__('shop_coupon_used_by_you', 'You have already used that code.'));
        }
        $subtotal = array_sum(array_map(static fn(array $l): int => (int) $l['line_cents'], $lines));
        if ($subtotal < (int) $c['min_subtotal_cents']) {
            return $fail(sprintf(__('shop_coupon_min_needed', 'That code needs an order of %s or more.'), ShopStoreAPI::money((int) $c['min_subtotal_cents'], $lines[0]['currency'] ?? null)));
        }
        $products = self::ids($c['product_ids'] ?? '');
        $categories = self::ids($c['category_ids'] ?? '');
        $catsOf = $categories !== [] ? ShopStoreAPI::categoriesOf(array_values(array_unique(array_map(static fn(array $l): int => (int) $l['product_id'], $lines)))) : [];
        $eligible = [];
        foreach ($lines as $l) {
            if (!empty($c['exclude_sale']) && !empty($l['on_sale'])) continue;
            $pid = (int) $l['product_id'];
            if ($products !== [] || $categories !== []) {
                $inProducts = in_array($pid, $products, true);
                $inCats = array_intersect($categories, array_map('intval', array_column($catsOf[$pid] ?? [], 'id'))) !== [];
                if (!$inProducts && !$inCats) continue;
            }
            $eligible[] = $l;
        }
        if ($eligible === []) return $fail(__('shop_coupon_no_items', 'That code does not apply to anything in your cart.'));
        if ($c['kind'] === 'free_shipping') return ['ok' => true, 'free_shipping' => true] + $none;
        $base = array_sum(array_map(static fn(array $l): int => (int) $l['line_cents'], $eligible));
        $total = $c['kind'] === 'percent' ? (int) round($base * (int) $c['value'] / 100) : min($base, (int) $c['value']);
        // Share it across the eligible lines in proportion; the rounding cents go to the largest line.
        $perLine = [];
        $given = 0;
        $largest = null;
        foreach ($eligible as $l) {
            $share = $base > 0 ? intdiv((int) $l['line_cents'] * $total, $base) : 0;
            $perLine[(string) $l['key']] = $share;
            $given += $share;
            if ($largest === null || (int) $l['line_cents'] > (int) $largest['line_cents']) $largest = $l;
        }
        if ($largest !== null) $perLine[(string) $largest['key']] += $total - $given;
        return ['ok' => true, 'error' => '', 'discount' => $total, 'per_line' => $perLine, 'free_shipping' => false];
    }

    /** Count one use of an order's coupon (when the order is paid). */
    public static function recordUse(array $order): void {
        if (empty($order['coupon_code'])) return;
        Database::query("UPDATE shop_coupons SET used_count = used_count + 1 WHERE tenant_id = ? AND code = ?", [current_tenant_id(), (string) $order['coupon_code']]);
    }
}
