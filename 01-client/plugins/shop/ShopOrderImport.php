<?php
/**
 * Shop — import WooCommerce orders and their customers.
 *
 * The file is WooCommerce's REST API order list (GET /wp-json/wc/v3/orders), or what tools/woo-export-orders.php
 * writes, which adds the order notes and refunds. Each order becomes a Shop order with its real reference
 * (`WC-1234`), dates, addresses, lines, shipping, discount, tax and totals; WooCommerce's order notes, payment method
 * and transaction id go into the order's admin notes. Each email becomes a customer: a guest record in the core
 * `customers` table (no password), mirrored into Contacts, which turns into a full account if that person signs up
 * with the same email, so their order history follows them.
 *
 * Nothing is sent and nothing moves: no email, no payment, no stock change. Running the same file again skips the
 * orders it already made (matched by reference). Failed orders (declined cards, card testing) and checkout drafts are
 * left out unless asked for.
 */
require_once __DIR__ . '/ShopStoreAPI.php';

final class ShopOrderImport {

    public const MAX_BYTES = 50 * 1024 * 1024;

    /** WooCommerce status => Shop status. */
    private const STATUSES = [
        'completed'  => 'fulfilled',
        'processing' => 'paid',
        'on-hold'    => 'pending_payment',
        'pending'    => 'pending_payment',
        'cancelled'  => 'cancelled',
        'refunded'   => 'refunded',
        'failed'     => 'cancelled',
    ];

    /** Left out unless include_failed: declined cards, card testing, abandoned checkouts. */
    private const NOISE = ['failed', 'checkout-draft'];

    /**
     * Read the file into orders.
     *
     * @return array{ok: bool, orders: list<array<string, mixed>>, error: string}
     */
    public static function read(string $contents): array {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $data = json_decode($contents, true);
        if (!is_array($data)) return ['ok' => false, 'orders' => [], 'error' => __('shop_orders_import_bad_file', 'This is not a WooCommerce order export (a JSON list of orders).')];
        if (isset($data['orders']) && is_array($data['orders'])) $data = $data['orders'];
        $orders = [];
        foreach ($data as $o) {
            if (!is_array($o) || !isset($o['line_items']) || !is_array($o['line_items'])) continue;
            $orders[] = $o;
        }
        if ($orders === []) return ['ok' => false, 'orders' => [], 'error' => __('shop_orders_import_empty', 'No orders were found in this file.')];
        return ['ok' => true, 'orders' => $orders, 'error' => ''];
    }

    /** `WC-` plus WooCommerce's order number. */
    public static function ref(array $o): string {
        $n = trim((string) ($o['number'] ?? '')) !== '' ? (string) $o['number'] : (string) ($o['id'] ?? '');
        $n = preg_replace('/[^A-Za-z0-9-]/', '', $n) ?? '';
        return mb_substr('WC-' . $n, 0, 24);
    }

    public static function email(array $o): string {
        return strtolower(trim((string) ($o['billing']['email'] ?? '')));
    }

    /**
     * What an import would do, without writing anything.
     *
     * @return array{orders: int, import: int, existing: int, skipped: int, by_status: array<string, int>, customers: int, new_customers: int, total_cents: int, currency: string, warnings: list<string>}
     */
    public static function plan(array $orders, array $opts = []): array {
        $tid = current_tenant_id();
        $includeFailed = !empty($opts['include_failed']);
        $res = ['orders' => count($orders), 'import' => 0, 'existing' => 0, 'skipped' => 0, 'by_status' => [], 'customers' => 0, 'new_customers' => 0, 'total_cents' => 0, 'currency' => '', 'warnings' => []];
        $emails = [];
        foreach ($orders as $o) {
            $status = (string) ($o['status'] ?? '');
            if (!self::wanted($status, $includeFailed)) { $res['skipped']++; continue; }
            if (Database::value("SELECT 1 FROM shop_orders WHERE tenant_id = ? AND ref = ?", [$tid, self::ref($o)]) !== null) { $res['existing']++; continue; }
            $res['import']++;
            $res['by_status'][$status] = ($res['by_status'][$status] ?? 0) + 1;
            $res['total_cents'] += self::cents($o['total'] ?? 0);
            if ($res['currency'] === '') $res['currency'] = strtoupper((string) ($o['currency'] ?? ''));
            $email = self::email($o);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $res['warnings'][] = sprintf(__('shop_orders_import_no_email', 'Order %s has no valid email; it is imported without a customer.'), self::ref($o));
            } else {
                $emails[$email] = true;
            }
            foreach ((array) $o['line_items'] as $line) {
                if (is_array($line) && self::matchLine($line) === [null, null]) {
                    $res['warnings'][] = sprintf(__('shop_orders_import_unmatched', 'Order %1$s: “%2$s” is not a product in this shop; the line keeps its name and price.'), self::ref($o), (string) ($line['name'] ?? ''));
                }
            }
        }
        $res['customers'] = count($emails);
        foreach (array_keys($emails) as $email) {
            if (Database::value("SELECT 1 FROM customers WHERE tenant_id = ? AND email = ?", [$tid, $email]) === null) $res['new_customers']++;
        }
        $res['warnings'] = array_values(array_unique($res['warnings']));
        return $res;
    }

    /**
     * Import the orders.
     *
     * @param array{include_failed?: bool} $opts
     * @return array{created: int, existing: int, skipped: int, customers_created: int, customers_linked: int, lines: int, unmatched: int, errors: list<string>}
     */
    public static function run(array $orders, array $opts = []): array {
        ShopStoreAPI::ensureSchema();
        $tid = current_tenant_id();
        $includeFailed = !empty($opts['include_failed']);
        $res = ['created' => 0, 'existing' => 0, 'skipped' => 0, 'customers_created' => 0, 'customers_linked' => 0, 'lines' => 0, 'unmatched' => 0, 'errors' => []];
        $customerIds = [];
        // Oldest first, so a customer's record carries the date of their first order.
        usort($orders, static fn($a, $b) => strcmp((string) ($a['date_created_gmt'] ?? ''), (string) ($b['date_created_gmt'] ?? '')));

        foreach ($orders as $o) {
            $status = (string) ($o['status'] ?? '');
            if (!self::wanted($status, $includeFailed)) { $res['skipped']++; continue; }
            $ref = self::ref($o);
            if (Database::value("SELECT 1 FROM shop_orders WHERE tenant_id = ? AND ref = ?", [$tid, $ref]) !== null) { $res['existing']++; continue; }

            $pdo = Database::get();
            $pdo->beginTransaction();
            try {
                $email = self::email($o);
                $customerId = null;
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    if (!isset($customerIds[$email])) {
                        [$customerIds[$email], $created] = self::customer($email, $o);
                        $created ? $res['customers_created']++ : $res['customers_linked']++;
                    }
                    $customerId = $customerIds[$email];
                }
                $orderId = Database::insert('shop_orders', self::orderRow($o, $ref, $email, $customerId, $tid));
                foreach ((array) $o['line_items'] as $line) {
                    if (!is_array($line)) continue;
                    [$productId, $variantId] = self::matchLine($line);
                    if ($productId === null) $res['unmatched']++;
                    Database::insert('shop_order_items', self::itemRow($line, $orderId, $productId, $variantId, $tid));
                    $res['lines']++;
                }
                $pdo->commit();
                $res['created']++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $res['errors'][] = sprintf(__('shop_orders_import_failed', 'Order %1$s was not imported: %2$s'), $ref, mb_substr($e->getMessage(), 0, 200));
            }
        }
        // Contacts mirror the new customers (outside the order transactions: it may write other tables).
        foreach (array_unique(array_values($customerIds)) as $id) {
            try { (new \Slate\Services\Identity\ContactSeeder())->syncCustomer((int) $id); } catch (\Throwable $e) { /* Contacts are a mirror; the customer row stands. */ }
        }
        if (class_exists('AuditLog')) {
            AuditLog::record('shop.orders_imported', 'shop', ['created' => $res['created'], 'existing' => $res['existing'], 'skipped' => $res['skipped'], 'customers_created' => $res['customers_created']]);
        }
        return $res;
    }

    // ── Rows ──────────────────────────────────────────────────────────────

    private static function orderRow(array $o, string $ref, string $email, ?int $customerId, int $tid): array {
        $wcStatus = (string) ($o['status'] ?? '');
        $status = self::STATUSES[$wcStatus] ?? 'pending_payment';
        $bill = is_array($o['billing'] ?? null) ? $o['billing'] : [];
        $ship = is_array($o['shipping'] ?? null) ? $o['shipping'] : [];
        $addr = trim((string) ($ship['address_1'] ?? '')) !== '' ? $ship : $bill;
        $str = static fn($v, int $max): ?string => trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;
        $name = trim(self::text($bill['first_name'] ?? '') . ' ' . self::text($bill['last_name'] ?? ''));
        if ($name === '') $name = trim(trim((string) ($ship['first_name'] ?? '')) . ' ' . trim((string) ($ship['last_name'] ?? '')));

        $shippingLines = array_values(array_filter((array) ($o['shipping_lines'] ?? []), 'is_array'));
        $pickup = false;
        foreach ($shippingLines as $s) if (str_starts_with((string) ($s['method_id'] ?? ''), 'local_pickup') || stripos((string) ($s['method_title'] ?? ''), 'pickup') !== false) $pickup = true;
        $delivery = $pickup ? 'pickup' : ($shippingLines === [] && trim((string) ($addr['address_1'] ?? '')) === '' ? 'none' : 'shipping');

        $subtotal = 0;
        foreach ((array) $o['line_items'] as $l) if (is_array($l)) $subtotal += self::cents($l['subtotal'] ?? $l['total'] ?? 0);
        $fees = 0;
        foreach ((array) ($o['fee_lines'] ?? []) as $f) if (is_array($f)) $fees += self::cents($f['total'] ?? 0);
        $coupons = [];
        foreach ((array) ($o['coupon_lines'] ?? []) as $c) if (is_array($c) && trim((string) ($c['code'] ?? '')) !== '') $coupons[] = strtoupper(trim((string) $c['code']));

        $created = self::localTime($o['date_created_gmt'] ?? null) ?? slate_db_now();
        $paid = self::localTime($o['date_paid_gmt'] ?? null);
        $completed = self::localTime($o['date_completed_gmt'] ?? null);

        return [
            'tenant_id'        => $tid,
            'ref'              => $ref,
            'access_key'       => bin2hex(random_bytes(20)),
            'customer_id'      => $customerId,
            'email'            => $email !== '' ? mb_substr($email, 0, 190) : 'unknown@invalid',
            'name'             => mb_substr($name !== '' ? $name : $email, 0, 160),
            'phone'            => $str($bill['phone'] ?? ($ship['phone'] ?? ''), 40),
            'delivery'         => $delivery,
            'address_line1'    => $str($addr['address_1'] ?? '', 190),
            'address_line2'    => $str(trim(((string) ($addr['company'] ?? '')) !== '' ? $addr['company'] . ', ' . ($addr['address_2'] ?? '') : (string) ($addr['address_2'] ?? ''), ', '), 190),
            'postal_code'      => $str($addr['postcode'] ?? '', 20),
            'city'             => $str($addr['city'] ?? '', 120),
            'country'          => $str(strtoupper((string) ($addr['country'] ?? '')), 2),
            'region'           => $str($addr['state'] ?? '', 80),
            'notes'            => $str($o['customer_note'] ?? '', 2000),
            'status'           => $status,
            'currency'         => strtoupper(mb_substr((string) ($o['currency'] ?? 'USD'), 0, 8)) ?: 'USD',
            'subtotal_cents'   => $subtotal,
            'shipping_cents'   => self::cents($o['shipping_total'] ?? 0),
            'discount_cents'   => self::cents($o['discount_total'] ?? 0),
            'coupon_code'      => $coupons !== [] ? mb_substr(implode(', ', $coupons), 0, 40) : null,
            'tax_cents'        => self::cents($o['total_tax'] ?? 0),
            'tax_label'        => self::cents($o['total_tax'] ?? 0) > 0 ? __('shop_tax', 'Tax') : null,
            'tax_inclusive'    => !empty($o['prices_include_tax']) ? 1 : 0,
            'total_cents'      => self::cents($o['total'] ?? 0),
            'shipping_method'  => $shippingLines !== [] ? mb_substr(implode(', ', array_map(static fn($s) => self::text($s['method_title'] ?? ''), $shippingLines)), 0, 160) : null,
            'admin_notes'      => self::adminNotes($o, $fees),
            'paid_at'          => in_array($status, ['paid', 'fulfilled', 'refunded'], true) ? ($paid ?? $created) : $paid,
            'fulfilled_at'     => $status === 'fulfilled' ? ($completed ?? $paid ?? $created) : null,
            'shipped_at'       => $status === 'fulfilled' && $delivery === 'shipping' ? ($completed ?? null) : null,
            'created_at'       => $created,
        ];
    }

    private static function itemRow(array $line, int $orderId, ?int $productId, ?int $variantId, int $tid): array {
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $lineCents = self::cents($line['subtotal'] ?? $line['total'] ?? 0);
        $options = [];
        foreach ((array) ($line['meta_data'] ?? []) as $m) {
            if (!is_array($m)) continue;
            $k = trim((string) ($m['display_key'] ?? $m['key'] ?? ''));
            $v = $m['display_value'] ?? $m['value'] ?? '';
            if ($k === '' || str_starts_with($k, '_') || !is_scalar($v) || trim((string) $v) === '') continue;
            $options[] = self::text($k) . ': ' . self::text($v);
        }
        $type = 'physical';
        if ($productId !== null) $type = (string) (Database::value("SELECT product_type FROM shop_products WHERE id = ?", [$productId]) ?? 'physical');
        return [
            'tenant_id'        => $tid,
            'order_id'         => $orderId,
            'product_id'       => $productId,
            'variant_id'       => $variantId,
            'title'            => mb_substr(self::text($line['name'] ?? '') ?: __('shop_item', 'Item'), 0, 200),
            'options_label'    => $options !== [] ? mb_substr(implode(', ', $options), 0, 255) : null,
            'sku'              => trim((string) ($line['sku'] ?? '')) !== '' ? mb_substr(trim((string) $line['sku']), 0, 64) : null,
            'product_type'     => $type !== '' ? $type : 'physical',
            'unit_price_cents' => (int) round($lineCents / $qty),
            'qty'              => $qty,
            'line_total_cents' => $lineCents,
        ];
    }

    /** WooCommerce's payment details, notes and refunds, for the shop owner only. */
    private static function adminNotes(array $o, int $feeCents): string {
        $id = (string) ($o['id'] ?? '');
        $out = [sprintf(__('shop_orders_import_note', 'Imported from WooCommerce order #%1$s (status: %2$s).'), (string) ($o['number'] ?? $id), (string) ($o['status'] ?? ''))];
        $pay = trim((string) ($o['payment_method_title'] ?? ''));
        $txn = trim((string) ($o['transaction_id'] ?? ''));
        if ($pay !== '' || $txn !== '') $out[] = trim(__('shop_orders_import_paid_with', 'Payment:') . ' ' . $pay . ($txn !== '' ? ' (' . $txn . ')' : ''));
        if ($feeCents !== 0) {
            foreach ((array) ($o['fee_lines'] ?? []) as $f) if (is_array($f)) $out[] = sprintf(__('shop_orders_import_fee', 'Fee: %1$s %2$s'), (string) ($f['name'] ?? ''), (string) ($f['total'] ?? ''));
        }
        foreach ((array) ($o['refunds'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $out[] = sprintf(__('shop_orders_import_refund', 'Refund: %1$s %2$s'), ltrim((string) ($r['total'] ?? ''), '-'), trim((string) ($r['reason'] ?? '')));
        }
        foreach ((array) ($o['notes'] ?? []) as $n) {
            if (!is_array($n) || trim((string) ($n['note'] ?? '')) === '') continue;
            $when = self::localTime($n['date_created_gmt'] ?? null);
            $out[] = ($when !== null ? '[' . $when . '] ' : '') . (!empty($n['customer_note']) ? __('shop_orders_import_to_customer', '(to customer) ') : '') . trim((string) $n['note']);
        }
        return mb_substr(implode("\n", $out), 0, 60000);
    }

    /**
     * The guest customer for an email: an existing customer is linked and left as it is.
     *
     * @return array{0: int, 1: bool} id, created
     */
    private static function customer(string $email, array $o): array {
        $tid = current_tenant_id();
        $id = Database::value("SELECT id FROM customers WHERE tenant_id = ? AND email = ?", [$tid, $email]);
        if ($id !== null) return [(int) $id, false];
        $bill = is_array($o['billing'] ?? null) ? $o['billing'] : [];
        $name = trim(trim((string) ($bill['first_name'] ?? '')) . ' ' . trim((string) ($bill['last_name'] ?? '')));
        $phone = trim((string) ($bill['phone'] ?? ''));
        $id = Database::insert('customers', [
            'tenant_id'      => $tid,
            'email'          => $email,
            'password_hash'  => null,
            'name'           => $name !== '' ? mb_substr($name, 0, 120) : null,
            'phone'          => $phone !== '' ? mb_substr($phone, 0, 40) : null,
            'status'         => 'guest',
            'email_verified' => 0,
            'created_at'     => self::localTime($o['date_created_gmt'] ?? null) ?? slate_db_now(),
        ]);
        return [(int) $id, true];
    }

    /**
     * The Shop product and variant a WooCommerce line was for: by the WooCommerce id the product importer kept
     * (`woo:603`), then by SKU.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function matchLine(array $line): array {
        $tid = current_tenant_id();
        $variation = (int) ($line['variation_id'] ?? 0);
        $product = (int) ($line['product_id'] ?? 0);
        $sku = trim((string) ($line['sku'] ?? ''));
        if ($variation > 0 && ($v = Database::row("SELECT id, product_id FROM shop_variants WHERE tenant_id = ? AND external_ref = ?", [$tid, 'woo:' . $variation]))) {
            return [(int) $v['product_id'], (int) $v['id']];
        }
        if ($sku !== '' && ($v = Database::row("SELECT id, product_id FROM shop_variants WHERE tenant_id = ? AND sku = ?", [$tid, $sku]))) {
            return [(int) $v['product_id'], (int) $v['id']];
        }
        if ($product > 0 && ($p = Database::value("SELECT id FROM shop_products WHERE tenant_id = ? AND external_ref = ?", [$tid, 'woo:' . $product])) !== null) {
            return [(int) $p, null];
        }
        if ($sku !== '' && ($p = Database::value("SELECT id FROM shop_products WHERE tenant_id = ? AND sku = ?", [$tid, $sku])) !== null) {
            return [(int) $p, null];
        }
        return [null, null];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function wanted(string $status, bool $includeFailed): bool {
        if (!isset(self::STATUSES[$status])) return false;
        return $includeFailed || !in_array($status, self::NOISE, true);
    }

    /** Plain text from WooCommerce, which keeps some names as HTML (`Priority Mail&#0174;`). */
    private static function text(mixed $v): string {
        return is_scalar($v) ? trim(html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    /** Money as whole cents, never below zero (the columns are unsigned). */
    public static function cents(mixed $v): int {
        return max(0, (int) round(((float) (is_scalar($v) ? $v : 0)) * 100));
    }

    /** A WooCommerce GMT date (2024-01-21T23:24:02) in the server's time, as the Shop stores dates. */
    private static function localTime(mixed $gmt): ?string {
        if (!is_string($gmt) || trim($gmt) === '') return null;
        $ts = strtotime(rtrim($gmt, 'Z') . ' UTC');
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
