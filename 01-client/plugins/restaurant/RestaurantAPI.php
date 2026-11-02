<?php
/**
 * Restaurant — core API.
 *
 * Static methods so the admin pages, the POS, and other plugins can share
 * one source of truth for menu reads, the order/check engine, money
 * formatting, and the Stripe Terminal bridge. All money is integer cents.
 *
 *   RestaurantAPI::ensureSchema()
 *   RestaurantAPI::money($cents)            // "$12.50"
 *   RestaurantAPI::slugify($name, $table, $excludeId)
 *   RestaurantAPI::recalcOrder($orderId)    // recompute line + order totals
 *   RestaurantAPI::getOrder($orderId)       // order + items + modifiers + payments
 *   RestaurantAPI::handleStripeEvent($event)
 *
 * Tax model: per-line tax_rate (%) snapshotted from the menu item; tax is
 * computed on each line's net total. Service charge is an order-level
 * percentage of the discounted subtotal. Tip is added on tender.
 */

class RestaurantAPI {

    private static bool $schemaChecked = false;

    // ── Schema ────────────────────────────────────────────────

    /**
     * Lazily create the restaurant_* tables. Defensive against installs
     * where install.sql didn't run or partially failed. Idempotent.
     */
    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            $sqlFile = __DIR__ . '/install.sql';
            if (!is_file($sqlFile)) return;
            $sql = (string) file_get_contents($sqlFile);
            $sql = preg_replace('/^--.*$/m', '', $sql);
            $pdo = Database::get();
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt === '') continue;
                try { $pdo->exec($stmt); } catch (\Throwable $e) {
                    slate_log('Restaurant ensureSchema stmt failed: ' . $e->getMessage(), 'warning');
                }
            }
        } catch (\Throwable $e) {
            slate_log('Restaurant ensureSchema failed: ' . $e->getMessage(), 'error');
        }
    }

    // ── Settings ──────────────────────────────────────────────

    public static function setting(string $key, $default = null) {
        $val = Database::setting('restaurant.' . $key);
        return $val !== null ? $val : $default;
    }

    /**
     * The business / receipt / Terminal-Location settings block, as an array.
     * Keys mirror what admin/settings.php persists; used by the Card-readers
     * page to register a Stripe Terminal Location.
     */
    public static function settings(): array {
        return [
            'biz_name'     => (string) self::setting('biz_name', ''),
            'addr_line1'   => (string) self::setting('addr_line1', ''),
            'addr_line2'   => (string) self::setting('addr_line2', ''),
            'addr_city'    => (string) self::setting('addr_city', ''),
            'addr_state'   => (string) self::setting('addr_state', ''),
            'addr_postal'  => (string) self::setting('addr_postal', ''),
            'addr_country' => (string) self::setting('addr_country', 'US'),
        ];
    }

    public static function currency(): string {
        return strtoupper((string) self::setting('currency', 'USD'));
    }

    /** Format integer cents as a localized money string. */
    public static function money(int $cents): string {
        $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$',
                    'AUD' => 'A$', 'BDT' => '৳', 'INR' => '₹', 'JPY' => '¥'];
        $cur = self::currency();
        $sym = $symbols[$cur] ?? ($cur . ' ');
        $neg = $cents < 0;
        $amt = number_format(abs($cents) / 100, 2);
        return ($neg ? '-' : '') . $sym . $amt;
    }

    public static function defaultTaxRate(): float {
        return (float) self::setting('default_tax_rate', '0');
    }

    public static function serviceChargePct(): float {
        return (float) self::setting('service_charge_pct', '0');
    }

    public static function serviceChargeAuto(): bool {
        return (string) self::setting('service_charge_auto', '0') === '1';
    }

    /** Tip preset percentages, e.g. [15, 18, 20]. */
    public static function tipPresets(): array {
        $raw = (string) self::setting('tip_presets', '15,18,20');
        $out = [];
        foreach (explode(',', $raw) as $p) {
            $n = (int) trim($p);
            if ($n > 0) $out[$n] = true;
        }
        return array_keys($out);
    }

    // ── Helpers ───────────────────────────────────────────────

    /** Unique slug within (tenant_id, slug) for the given table. */
    public static function slugify(string $name, string $table, ?int $excludeId = null): string {
        $tid  = current_tenant_id();
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'item';
        $base = substr($base, 0, 60);
        $slug = $base; $i = 2;
        while (true) {
            $clash = Database::row(
                "SELECT id FROM `{$table}` WHERE tenant_id = ? AND slug = ?" . ($excludeId ? " AND id <> ?" : ""),
                $excludeId ? [$tid, $slug, $excludeId] : [$tid, $slug]
            );
            if (!$clash) return $slug;
            $slug = $base . '-' . $i++;
            if ($i > 999) return $base . '-' . bin2hex(random_bytes(3));
        }
    }

    /** Sequential-ish, human order number unique per tenant (e.g. "A4F2-013"). */
    public static function generateOrderNo(int $tid): string {
        $count = (int) Database::value(
            "SELECT COUNT(*) FROM restaurant_orders WHERE tenant_id = ? AND DATE(opened_at) = CURDATE()",
            [$tid]
        );
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $no = date('md') . '-' . str_pad((string)($count + 1 + $attempt), 3, '0', STR_PAD_LEFT);
            $exists = Database::row(
                "SELECT id FROM restaurant_orders WHERE tenant_id = ? AND order_no = ?", [$tid, $no]);
            if (!$exists) return $no;
        }
        return date('md') . '-' . bin2hex(random_bytes(2));
    }

    // ── Order engine ──────────────────────────────────────────

    /**
     * Recompute one line item's modifier total + line total from its stored
     * modifier rows, then roll up the whole order's subtotal/tax/service/total.
     * Void lines and voided orders contribute nothing.
     */
    public static function recalcOrder(int $orderId): void {
        $tid   = current_tenant_id();
        $order = Database::row(
            "SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;

        $items = Database::rows(
            "SELECT * FROM restaurant_order_items WHERE order_id = ? AND tenant_id = ?",
            [$orderId, $tid]);

        $subtotal = 0;
        $taxTotal = 0;
        foreach ($items as $it) {
            $modTotal = (int) Database::value(
                "SELECT COALESCE(SUM(price_delta_cents),0) FROM restaurant_order_item_modifiers
                  WHERE order_item_id = ?", [(int)$it['id']]);
            $qty       = max(1, (int)$it['qty']);
            $lineTotal = ((int)$it['unit_price_cents'] + $modTotal) * $qty;

            Database::update('restaurant_order_items', [
                'modifiers_total_cents' => $modTotal,
                'line_total_cents'      => $lineTotal,
            ], 'id = ?', [(int)$it['id']]);

            if ($it['kitchen_status'] === 'void') continue;
            $subtotal += $lineTotal;
            $taxTotal += (int) round($lineTotal * ((float)$it['tax_rate'] / 100));
        }

        // Service charge is a percentage of the discounted subtotal. The
        // percent is snapshotted onto the order at open time so later recalcs
        // stay stable even if the global setting changes mid-service.
        $discount = min((int)$order['discount_cents'], $subtotal);
        $svcBase  = max(0, $subtotal - $discount);
        $pct      = (float)($order['service_charge_pct'] ?? 0);
        $service  = $pct > 0 ? (int) round($svcBase * $pct / 100) : 0;
        $tip      = (int)$order['tip_cents'];
        $total    = $subtotal - $discount + $service + $taxTotal + $tip;

        Database::update('restaurant_orders', [
            'subtotal_cents'       => $subtotal,
            'discount_cents'       => $discount,
            'service_charge_cents' => $service,
            'tax_cents'            => $taxTotal,
            'total_cents'          => max(0, $total),
        ], 'id = ? AND tenant_id = ?', [$orderId, $tid]);
    }

    /** Full order with items (+ their modifiers) and payments. Null if missing. */
    public static function getOrder(int $orderId): ?array {
        $tid   = current_tenant_id();
        $order = Database::row(
            "SELECT o.*, t.label AS table_label, c.name AS customer_name, c.phone AS customer_phone
               FROM restaurant_orders o
               LEFT JOIN restaurant_tables t    ON t.id = o.table_id
               LEFT JOIN restaurant_customers c ON c.id = o.customer_id
              WHERE o.id = ? AND o.tenant_id = ?", [$orderId, $tid]);
        if (!$order) return null;

        $items = Database::rows(
            "SELECT * FROM restaurant_order_items
              WHERE order_id = ? AND tenant_id = ? ORDER BY id", [$orderId, $tid]);
        foreach ($items as &$it) {
            $it['modifiers'] = Database::rows(
                "SELECT * FROM restaurant_order_item_modifiers WHERE order_item_id = ? ORDER BY id",
                [(int)$it['id']]);
        }
        unset($it);
        $order['items'] = $items;
        $order['payments'] = Database::rows(
            "SELECT * FROM restaurant_payments WHERE order_id = ? AND tenant_id = ? ORDER BY id",
            [$orderId, $tid]);
        return $order;
    }

    public static function getOpenOrders(): array {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT o.*, t.label AS table_label
               FROM restaurant_orders o
               LEFT JOIN restaurant_tables t ON t.id = o.table_id
              WHERE o.tenant_id = ? AND o.status IN ('open','sent')
           ORDER BY o.opened_at", [$tid]);
    }

    /** Active menu for the POS: categories with their available items. */
    public static function getPosMenu(): array {
        $tid = current_tenant_id();
        $cats = Database::rows(
            "SELECT * FROM restaurant_menu_categories
              WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
        $items = Database::rows(
            "SELECT * FROM restaurant_items
              WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
        return ['categories' => $cats, 'items' => $items];
    }

    /** Modifier groups attached to an item, each with its options. */
    public static function getItemModifierGroups(int $itemId): array {
        $tid    = current_tenant_id();
        $groups = Database::rows(
            "SELECT g.* FROM restaurant_modifier_groups g
               JOIN restaurant_item_modifier_groups l ON l.group_id = g.id
              WHERE l.item_id = ? AND g.tenant_id = ? ORDER BY l.sort_order, g.name",
            [$itemId, $tid]);
        foreach ($groups as &$g) {
            $g['modifiers'] = Database::rows(
                "SELECT * FROM restaurant_modifiers
                  WHERE group_id = ? AND is_active = 1 ORDER BY sort_order, name", [(int)$g['id']]);
        }
        unset($g);
        return $groups;
    }

    // ── Stripe Terminal bridge ────────────────────────────────

    public static function terminalAvailable(): bool {
        return class_exists('StripeTerminalAPI')
            && class_exists('StripePaymentAPI')
            && StripePaymentAPI::isConfigured();
    }

    /** Registered, active card readers for this tenant (for the POS picker). */
    public static function readers(): array {
        return Database::rows(
            "SELECT * FROM restaurant_readers WHERE tenant_id = ? AND is_active = 1 ORDER BY label",
            [current_tenant_id()]);
    }

    /**
     * Settle a restaurant payment when its Terminal/card PaymentIntent
     * succeeds. Fired from the stripe_webhook_event action. Idempotent —
     * a repeated delivery no-ops once the payment is already paid.
     */
    public static function handleStripeEvent(array $event): void {
        $type = (string)($event['type'] ?? '');
        if (!in_array($type, ['payment_intent.succeeded', 'payment_intent.payment_failed'], true)) return;

        $pi = $event['data']['object'] ?? [];
        $piId = (string)($pi['id'] ?? '');
        $meta = $pi['metadata'] ?? [];
        if ($piId === '' || ($meta['source'] ?? '') !== 'restaurant') return;

        $payment = Database::row(
            "SELECT * FROM restaurant_payments WHERE stripe_pi_id = ?", [$piId]);
        if (!$payment) return;

        if ($type === 'payment_intent.payment_failed') {
            if ($payment['status'] !== 'paid') {
                Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [(int)$payment['id']]);
            }
            return;
        }

        if ($payment['status'] === 'paid') return; // already settled
        self::markPaymentPaid((int)$payment['id'], $pi);
    }

    /**
     * Mark a payment row paid, record the charge centrally, then advance the
     * order's paid total and close it if fully covered. Safe to call from the
     * webhook or from the POS poll once the reader reports success.
     */
    public static function markPaymentPaid(int $paymentId, array $pi = []): void {
        $tid     = current_tenant_id();
        $payment = Database::row(
            "SELECT * FROM restaurant_payments WHERE id = ?", [$paymentId]);
        if (!$payment || $payment['status'] === 'paid') return;

        $chargeId = null;
        if (class_exists('StripePaymentAPI') && !empty($payment['stripe_pi_id'])) {
            $chargeId = StripePaymentAPI::recordCharge([
                'source_plugin'            => 'restaurant',
                'source_id'                => (string)$payment['order_id'],
                'stripe_payment_intent_id' => (string)$payment['stripe_pi_id'],
                'amount_cents'             => (int)$payment['amount_cents'] + (int)$payment['tip_cents'],
                'currency'                 => self::currency(),
                'status'                   => 'succeeded',
                'meta'                     => ['method' => 'terminal', 'order_id' => (int)$payment['order_id']],
            ]);
        }

        $charge = !empty($pi['latest_charge']) ? (string)$pi['latest_charge'] : ($payment['stripe_charge_id'] ?? null);
        Database::update('restaurant_payments', [
            'status'           => 'paid',
            'stripe_charge_id' => $charge,
        ], 'id = ?', [$paymentId]);

        AuditLog::record('restaurant.payment_paid', (string)$paymentId, [
            'order_id' => (int)$payment['order_id'],
            'amount'   => (int)$payment['amount_cents'],
            'charge'   => $chargeId,
        ]);

        self::syncOrderPayment((int)$payment['order_id']);
    }

    /**
     * Re-sum paid payments onto the order and close it when fully paid. Tips
     * collected at tender are folded into the order's tip, the totals are
     * recomputed (so total includes tip), and the order closes once the paid
     * amount covers that total.
     */
    public static function syncOrderPayment(int $orderId): void {
        $tid   = current_tenant_id();
        $order = Database::row(
            "SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;

        // Fold tender-time tips into the order tip, then recompute totals so
        // total_cents includes the tip before we compare against paid.
        $tips = (int) Database::value(
            "SELECT COALESCE(SUM(tip_cents),0) FROM restaurant_payments
              WHERE order_id = ? AND tenant_id = ? AND status = 'paid'", [$orderId, $tid]);
        Database::update('restaurant_orders', ['tip_cents' => $tips], 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);

        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        $paid  = (int) Database::value(
            "SELECT COALESCE(SUM(amount_cents + tip_cents),0) FROM restaurant_payments
              WHERE order_id = ? AND tenant_id = ? AND status = 'paid'", [$orderId, $tid]);

        $fields = ['paid_cents' => $paid];
        if ((int)$order['total_cents'] > 0 && $paid >= (int)$order['total_cents']
            && !in_array($order['status'], ['voided', 'closed'], true)) {
            $fields['status']    = 'paid';
            $fields['closed_at'] = slate_db_now();
        }
        Database::update('restaurant_orders', $fields, 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        if (($fields['status'] ?? '') === 'paid') self::freeTableIfSettled((array)$order);
    }

    // ── Order lifecycle ───────────────────────────────────────

    /**
     * Open a new order/check. $args: type, table_id, customer_id, server_id,
     * guest_count, notes, source. The service-charge percent is snapshotted
     * from settings at open time so later recalcs stay stable. Returns the id.
     */
    public static function openOrder(array $args = []): int {
        self::ensureSchema();
        $tid  = current_tenant_id();
        $type = in_array(($args['type'] ?? 'dine_in'), ['dine_in', 'takeout', 'delivery'], true)
            ? ($args['type'] ?? 'dine_in') : 'dine_in';
        $source = in_array(($args['source'] ?? 'pos'), ['pos', 'online'], true)
            ? ($args['source'] ?? 'pos') : 'pos';
        $svcPct = self::serviceChargeAuto() ? self::serviceChargePct() : 0;

        $id = Database::insert('restaurant_orders', [
            'tenant_id'          => $tid,
            'order_no'           => self::generateOrderNo($tid),
            'type'               => $type,
            'table_id'           => !empty($args['table_id']) ? (int)$args['table_id'] : null,
            'customer_id'        => !empty($args['customer_id']) ? (int)$args['customer_id'] : null,
            'server_id'          => !empty($args['server_id']) ? (int)$args['server_id'] : (Auth::userId() ?: null),
            'status'             => 'open',
            'guest_count'        => max(1, (int)($args['guest_count'] ?? 1)),
            'service_charge_pct' => $svcPct,
            'source'             => $source,
            'notes'              => isset($args['notes']) ? mb_substr((string)$args['notes'], 0, 255) : null,
        ]);

        if ($type === 'dine_in' && !empty($args['table_id'])) {
            Database::update('restaurant_tables', ['status' => 'seated'],
                'id = ? AND tenant_id = ?', [(int)$args['table_id'], $tid]);
        }
        AuditLog::record('restaurant.order_opened', (string)$id, ['type' => $type]);
        return $id;
    }

    /** Update editable order meta (guest count, notes, customer). */
    public static function updateOrderMeta(int $orderId, array $fields): void {
        $tid = current_tenant_id();
        $set = [];
        if (array_key_exists('guest_count', $fields)) $set['guest_count'] = max(1, (int)$fields['guest_count']);
        if (array_key_exists('notes', $fields))       $set['notes']       = mb_substr((string)$fields['notes'], 0, 255);
        if (array_key_exists('customer_id', $fields))  $set['customer_id'] = $fields['customer_id'] ? (int)$fields['customer_id'] : null;
        if ($set) Database::update('restaurant_orders', $set, 'id = ? AND tenant_id = ?', [$orderId, $tid]);
    }

    /**
     * Add a line to an order. $line: item_id, qty, seat, check_no, notes,
     * modifiers => [modifier_id, …] (validated against the item's groups).
     * Returns the new line id. Throws on a bad item or modifier rule.
     */
    public static function addLine(int $orderId, array $line): int {
        $tid  = current_tenant_id();
        $itemId = (int)($line['item_id'] ?? 0);
        $item = $itemId > 0
            ? Database::row("SELECT * FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$itemId, $tid])
            : null;
        if (!$item) throw new \RuntimeException('Unknown menu item.');
        if (!empty($item['is_86'])) throw new \RuntimeException($item['name'] . " is 86'd right now.");

        $qty       = max(1, (int)($line['qty'] ?? 1));
        $chosenIds = array_values(array_unique(array_map('intval', (array)($line['modifiers'] ?? []))));

        // Validate chosen modifiers against the item's groups + collect snapshots.
        $groups    = self::getItemModifierGroups($itemId);
        $validMods = [];
        foreach ($groups as $g) {
            $picked = [];
            foreach ($g['modifiers'] as $m) {
                if (in_array((int)$m['id'], $chosenIds, true)) { $picked[] = $m; $validMods[] = $m; }
            }
            $min = (int)$g['min_select']; $max = (int)$g['max_select'];
            if (!empty($g['is_required']) && count($picked) < max(1, $min)) {
                throw new \RuntimeException('Choose at least ' . max(1, $min) . ' for: ' . $g['name']);
            }
            if ($max > 0 && count($picked) > $max) {
                throw new \RuntimeException('Choose at most ' . $max . ' for: ' . $g['name']);
            }
        }

        $lineId = Database::insert('restaurant_order_items', [
            'tenant_id'        => $tid,
            'order_id'         => $orderId,
            'check_no'         => max(1, (int)($line['check_no'] ?? 1)),
            'item_id'          => (int)$item['id'],
            'name_snapshot'    => mb_substr((string)$item['name'], 0, 160),
            'qty'              => $qty,
            'unit_price_cents' => (int)$item['price_cents'],
            'tax_rate'         => (float)$item['tax_rate'],
            'seat'             => !empty($line['seat']) ? (int)$line['seat'] : null,
            'notes'            => isset($line['notes']) ? mb_substr((string)$line['notes'], 0, 255) : null,
            'kitchen_status'   => 'new',
        ]);
        foreach ($validMods as $m) {
            Database::insert('restaurant_order_item_modifiers', [
                'tenant_id'         => $tid,
                'order_item_id'     => $lineId,
                'modifier_id'       => (int)$m['id'],
                'name_snapshot'     => mb_substr((string)$m['name'], 0, 160),
                'price_delta_cents' => (int)$m['price_delta_cents'],
            ]);
        }
        self::recalcOrder($orderId);
        return $lineId;
    }

    public static function setLineQty(int $lineId, int $qty): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) return;
        Database::update('restaurant_order_items', ['qty' => max(1, $qty)], 'id = ?', [$lineId]);
        self::recalcOrder((int)$line['order_id']);
    }

    /** Remove an un-fired line outright; void it instead once it's been sent. */
    public static function removeLine(int $lineId): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) return;
        if ($line['kitchen_status'] === 'new') {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [$lineId]);
            Database::delete('restaurant_order_items', 'id = ? AND tenant_id = ?', [$lineId, $tid]);
            self::recalcOrder((int)$line['order_id']);
        } else {
            self::voidLine($lineId);
        }
    }

    public static function voidLine(int $lineId): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) return;
        Database::update('restaurant_order_items', ['kitchen_status' => 'void'], 'id = ?', [$lineId]);
        AuditLog::record('restaurant.line_voided', (string)$lineId, ['order_id' => (int)$line['order_id']]);
        self::recalcOrder((int)$line['order_id']);
    }

    /** Move a line onto a different check number (split-check support). */
    public static function moveLineToCheck(int $lineId, int $checkNo): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) return;
        Database::update('restaurant_order_items', ['check_no' => max(1, $checkNo)], 'id = ?', [$lineId]);
    }

    /** Fire all 'new' lines to the kitchen. */
    public static function sendToKitchen(int $orderId): void {
        $tid = current_tenant_id();
        Database::query(
            "UPDATE restaurant_order_items SET kitchen_status = 'sent'
              WHERE order_id = ? AND tenant_id = ? AND kitchen_status = 'new'", [$orderId, $tid]);
        Database::update('restaurant_orders', ['status' => 'sent'],
            "id = ? AND tenant_id = ? AND status = 'open'", [$orderId, $tid]);
        AuditLog::record('restaurant.order_sent', (string)$orderId);
    }

    public static function setDiscount(int $orderId, int $discountCents): void {
        $tid = current_tenant_id();
        Database::update('restaurant_orders', ['discount_cents' => max(0, $discountCents)],
            'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);
        AuditLog::record('restaurant.discount_set', (string)$orderId, ['cents' => max(0, $discountCents)]);
    }

    public static function setTip(int $orderId, int $tipCents): void {
        $tid = current_tenant_id();
        Database::update('restaurant_orders', ['tip_cents' => max(0, $tipCents)],
            'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);
    }

    // ── Tender ────────────────────────────────────────────────

    /** Tender cash. Closes the order if the paid total now covers it. */
    public static function recordCashPayment(int $orderId, int $amountCents, int $tipCents = 0): int {
        $tid = current_tenant_id();
        $pid = Database::insert('restaurant_payments', [
            'tenant_id'    => $tid,
            'order_id'     => $orderId,
            'method'       => 'cash',
            'amount_cents' => max(0, $amountCents),
            'tip_cents'    => max(0, $tipCents),
            'status'       => 'paid',
        ]);
        AuditLog::record('restaurant.cash_payment', (string)$orderId, ['cents' => $amountCents, 'tip' => $tipCents]);
        self::syncOrderPayment($orderId);
        return $pid;
    }

    /**
     * Start a card-present charge on a registered reader (server-driven).
     * Creates a pending payment + a manual-capture card_present PaymentIntent,
     * then pushes it to the reader. Returns ['ok','payment_id','pi_id','simulated']
     * or ['ok'=>false,'error'=>…]. Poll with pollTerminalPayment().
     */
    public static function beginTerminalPayment(int $orderId, int $readerRowId, int $amountCents, int $tipCents = 0): array {
        if (!self::terminalAvailable()) {
            return ['ok' => false, 'error' => 'Stripe Terminal is not available. Configure the Stripe plugin first.'];
        }
        $tid    = current_tenant_id();
        $reader = Database::row("SELECT * FROM restaurant_readers WHERE id = ? AND tenant_id = ?", [$readerRowId, $tid]);
        if (!$reader) return ['ok' => false, 'error' => 'Reader not found.'];

        $charge = max(0, $amountCents) + max(0, $tipCents);
        if ($charge <= 0) return ['ok' => false, 'error' => 'Nothing to charge.'];

        $orderNo = (string) Database::value("SELECT order_no FROM restaurant_orders WHERE id = ?", [$orderId]);
        try {
            $pi = StripeTerminalAPI::createCardPresentIntent($charge, [
                'currency' => strtolower(self::currency()),
                'metadata' => ['source' => 'restaurant', 'order_id' => (string)$orderId, 'order_no' => $orderNo],
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $pid = Database::insert('restaurant_payments', [
            'tenant_id'    => $tid,
            'order_id'     => $orderId,
            'method'       => 'terminal',
            'amount_cents' => max(0, $amountCents),
            'tip_cents'    => max(0, $tipCents),
            'status'       => 'pending',
            'stripe_pi_id' => (string)$pi['id'],
            'reader_id'    => $readerRowId,
        ]);

        $proc = StripeTerminalAPI::processOnReader((string)$reader['stripe_reader_id'], (string)$pi['id']);
        if (!$proc['ok']) {
            Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$pid]);
            return ['ok' => false, 'error' => $proc['error'], 'payment_id' => $pid];
        }
        AuditLog::record('restaurant.terminal_begin', (string)$orderId, ['pi' => $pi['id']]);
        return [
            'ok'         => true,
            'payment_id' => $pid,
            'pi_id'      => (string)$pi['id'],
            'simulated'  => str_contains((string)$reader['stripe_reader_id'], 'simulated'),
        ];
    }

    /**
     * Poll a pending terminal payment. Captures the PI once the reader has
     * collected a card, then settles via markPaymentPaid(). Returns a status
     * array: ['ok'=>bool, 'state'=>'pending'|'paid'|'failed', 'error'?].
     */
    public static function pollTerminalPayment(int $paymentId): array {
        if (!self::terminalAvailable()) return ['ok' => false, 'state' => 'failed', 'error' => 'Terminal unavailable.'];
        $tid = current_tenant_id();
        $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ? AND tenant_id = ?", [$paymentId, $tid]);
        if (!$pay) return ['ok' => false, 'state' => 'failed', 'error' => 'Payment not found.'];
        if ($pay['status'] === 'paid')   return ['ok' => true,  'state' => 'paid'];
        if ($pay['status'] === 'failed') return ['ok' => false, 'state' => 'failed', 'error' => 'Payment failed or was cancelled.'];

        $piId = (string)$pay['stripe_pi_id'];
        $pi   = StripePaymentAPI::getPaymentIntent($piId);
        if (!$pi) return ['ok' => true, 'state' => 'pending'];

        $status = (string)($pi['status'] ?? '');
        if ($status === 'requires_capture') {
            $cap = StripeTerminalAPI::capture($piId);
            if (!$cap['ok']) return ['ok' => false, 'state' => 'failed', 'error' => $cap['error']];
            $pi = $cap['payment_intent'];
            $status = (string)($pi['status'] ?? '');
        }

        if ($status === 'succeeded') {
            self::markPaymentPaid($paymentId, $pi);
            return ['ok' => true, 'state' => 'paid'];
        }
        if ($status === 'canceled') {
            Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$paymentId]);
            return ['ok' => false, 'state' => 'failed', 'error' => 'Cancelled at the reader.'];
        }
        if ($status === 'requires_payment_method' && !empty($pi['last_payment_error'])) {
            Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$paymentId]);
            return ['ok' => false, 'state' => 'failed',
                    'error' => (string)($pi['last_payment_error']['message'] ?? 'Card declined.')];
        }
        return ['ok' => true, 'state' => 'pending', 'stripe_status' => $status];
    }

    /** Abort an in-flight terminal payment (cancel the reader + the PI). */
    public static function cancelTerminalPayment(int $paymentId): array {
        $tid = current_tenant_id();
        $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ? AND tenant_id = ?", [$paymentId, $tid]);
        if (!$pay) return ['ok' => false, 'error' => 'Payment not found.'];
        if (self::terminalAvailable()) {
            if (!empty($pay['reader_id'])) {
                $reader = Database::row("SELECT * FROM restaurant_readers WHERE id = ?", [(int)$pay['reader_id']]);
                if ($reader) StripeTerminalAPI::cancelReaderAction((string)$reader['stripe_reader_id']);
            }
            if (!empty($pay['stripe_pi_id'])) StripeTerminalAPI::cancelIntent((string)$pay['stripe_pi_id']);
        }
        Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$paymentId]);
        return ['ok' => true];
    }

    // ── Close / void ──────────────────────────────────────────

    public static function closeOrder(int $orderId): void {
        $tid   = current_tenant_id();
        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;
        Database::update('restaurant_orders',
            ['status' => 'closed', 'closed_at' => slate_db_now()],
            'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::freeTableIfSettled($order);
        AuditLog::record('restaurant.order_closed', (string)$orderId);
    }

    public static function voidOrder(int $orderId, string $reason = ''): void {
        $tid   = current_tenant_id();
        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;
        $note = trim((($order['notes'] ?? '') !== '' ? $order['notes'] . ' · ' : '') . 'VOID: ' . $reason);
        Database::update('restaurant_orders',
            ['status' => 'voided', 'closed_at' => slate_db_now(), 'notes' => mb_substr($note, 0, 255)],
            'id = ? AND tenant_id = ?', [$orderId, $tid]);
        Database::query("UPDATE restaurant_order_items SET kitchen_status = 'void'
                          WHERE order_id = ? AND tenant_id = ?", [$orderId, $tid]);
        self::freeTableIfSettled($order);
        AuditLog::record('restaurant.order_voided', (string)$orderId, ['reason' => $reason]);
    }

    /** Mark a table dirty once no open/sent order remains on it. */
    private static function freeTableIfSettled(array $order): void {
        if (empty($order['table_id'])) return;
        $tid = current_tenant_id();
        $stillOpen = (int) Database::value(
            "SELECT COUNT(*) FROM restaurant_orders
              WHERE tenant_id = ? AND table_id = ? AND id <> ? AND status IN ('open','sent')",
            [$tid, (int)$order['table_id'], (int)$order['id']]);
        if ($stillOpen === 0) {
            Database::update('restaurant_tables', ['status' => 'dirty'],
                'id = ? AND tenant_id = ?', [(int)$order['table_id'], $tid]);
        }
    }

    // ── Customers (light POS lookup) ──────────────────────────

    public static function findOrCreateCustomer(string $name, string $phone = '', string $email = ''): ?int {
        $name = trim($name); $phone = trim($phone); $email = trim($email);
        if ($name === '' && $phone === '' && $email === '') return null;
        $tid = current_tenant_id();
        if ($phone !== '') {
            $row = Database::row("SELECT id FROM restaurant_customers WHERE tenant_id = ? AND phone = ?", [$tid, $phone]);
            if ($row) return (int)$row['id'];
        }
        return Database::insert('restaurant_customers', [
            'tenant_id' => $tid,
            'name'      => mb_substr($name !== '' ? $name : 'Guest', 0, 160),
            'phone'     => $phone !== '' ? mb_substr($phone, 0, 40) : null,
            'email'     => $email !== '' ? mb_substr($email, 0, 200) : null,
        ]);
    }

    // ── Online ordering (Phase 3 — public storefront) ─────────

    /** Is the public online-ordering storefront switched on in settings? */
    public static function onlineOrderingEnabled(): bool {
        return (string) self::setting('online_enabled', '0') === '1';
    }

    /** Can the storefront take card payment online (vs pay-at-pickup only)? */
    public static function onlinePayEnabled(): bool {
        return self::onlineOrderingEnabled()
            && (string) self::setting('online_pay', '1') === '1'
            && class_exists('StripePaymentAPI') && StripePaymentAPI::isConfigured();
    }

    /** Fetch a single online order by its unguessable public token. */
    public static function getOrderByToken(string $token): ?array {
        $token = trim($token);
        if ($token === '') return null;
        $row = Database::row(
            "SELECT id FROM restaurant_orders WHERE public_token = ? AND tenant_id = ?",
            [$token, current_tenant_id()]);
        return $row ? self::getOrder((int)$row['id']) : null;
    }

    /**
     * Create an order from a public storefront cart. $cart is a list of
     * [item_id, qty, modifiers => [ids], notes]. $contact = name/phone/email.
     * $meta = type ('takeout'|'delivery'), requested_time, notes. Validates
     * every line through addLine(). Returns ['order_id','token'] or throws.
     */
    public static function createOnlineOrder(array $cart, array $contact, array $meta = []): array {
        if (empty($cart)) throw new \RuntimeException('Your cart is empty.');
        $tid  = current_tenant_id();
        $type = in_array(($meta['type'] ?? 'takeout'), ['takeout', 'delivery'], true) ? $meta['type'] : 'takeout';

        $custId = self::findOrCreateCustomer(
            (string)($contact['name'] ?? ''), (string)($contact['phone'] ?? ''), (string)($contact['email'] ?? ''));

        $orderId = self::openOrder([
            'type'        => $type,
            'customer_id' => $custId,
            'guest_count' => 1,
            'source'      => 'online',
            'notes'       => (string)($meta['notes'] ?? ''),
        ]);

        try {
            foreach ($cart as $line) {
                self::addLine($orderId, [
                    'item_id'   => (int)($line['item_id'] ?? 0),
                    'qty'       => (int)($line['qty'] ?? 1),
                    'modifiers' => array_map('intval', (array)($line['modifiers'] ?? [])),
                    'notes'     => (string)($line['notes'] ?? ''),
                ]);
            }
        } catch (\Throwable $e) {
            // Roll back the half-built order so a bad line never leaves a ghost.
            self::voidOrder($orderId, 'online build failed');
            throw $e;
        }

        $token = bin2hex(random_bytes(16));
        Database::update('restaurant_orders', [
            'public_token'       => $token,
            'fulfillment_status' => 'new',
            'requested_time'     => mb_substr((string)($meta['requested_time'] ?? ''), 0, 40) ?: null,
        ], 'id = ? AND tenant_id = ?', [$orderId, $tid]);

        AuditLog::record('restaurant.online_order_created', (string)$orderId, ['type' => $type]);

        if (class_exists('Notifications')) {
            $order = self::getOrder($orderId);
            Notifications::add('New ' . $type . ' order · ' . ($order['order_no'] ?? ('#' . $orderId)), [
                'body' => ($order['customer_name'] ?? $contact['name'] ?? 'A customer') . ' — '
                        . self::money((int)($order['total_cents'] ?? 0)),
                'url'  => function_exists('plugin_url') ? plugin_url('restaurant', 'admin/order.php') . '?id=' . $orderId : '',
                'icon' => 'shopping-bag',
            ]);
        }

        return ['order_id' => $orderId, 'token' => $token];
    }

    /**
     * Start a hosted Stripe Checkout for an online order. Creates a pending
     * payment, builds line items from the order, and returns the redirect URL.
     */
    public static function beginOnlinePayment(int $orderId, string $successUrl, string $cancelUrl): array {
        if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
            return ['ok' => false, 'error' => 'Online card payment is not available.'];
        }
        $order = self::getOrder($orderId);
        if (!$order) return ['ok' => false, 'error' => 'Order not found.'];

        $lineItems = [];
        foreach ($order['items'] as $it) {
            if ($it['kitchen_status'] === 'void') continue;
            $modNames = array_map(fn($m) => $m['name_snapshot'], $it['modifiers'] ?? []);
            $perUnit  = (int)$it['unit_price_cents'] + (int)$it['modifiers_total_cents'];
            $lineItems[] = [
                'name'         => (string)$it['name_snapshot'],
                'description'  => $modNames ? implode(', ', $modNames) : '',
                'amount_cents' => $perUnit,
                'quantity'     => max(1, (int)$it['qty']),
            ];
        }
        if ((int)$order['service_charge_cents'] > 0)
            $lineItems[] = ['name' => 'Service charge', 'amount_cents' => (int)$order['service_charge_cents'], 'quantity' => 1];
        if ((int)$order['tax_cents'] > 0)
            $lineItems[] = ['name' => 'Tax', 'amount_cents' => (int)$order['tax_cents'], 'quantity' => 1];
        if (!$lineItems) return ['ok' => false, 'error' => 'Nothing to charge.'];

        $tid = current_tenant_id();
        $pid = Database::insert('restaurant_payments', [
            'tenant_id'    => $tid,
            'order_id'     => $orderId,
            'method'       => 'other',
            'amount_cents' => (int)$order['total_cents'],
            'tip_cents'    => 0,
            'status'       => 'pending',
        ]);

        try {
            $sess = StripePaymentAPI::createCheckout($lineItems, [
                'currency'       => strtolower(self::currency()),
                'success_url'    => $successUrl,
                'cancel_url'     => $cancelUrl,
                'customer_email' => $order['customer_email'] ?? null,
                'metadata'       => ['source' => 'restaurant', 'source_plugin' => 'restaurant',
                                     'order_id' => (string)$orderId, 'payment_id' => (string)$pid],
            ]);
        } catch (\Throwable $e) {
            Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$pid]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        Database::update('restaurant_payments', ['stripe_session_id' => $sess['session_id']], 'id = ?', [$pid]);
        return ['ok' => true, 'url' => $sess['url'], 'payment_id' => $pid];
    }

    /**
     * Settle an online order from its Stripe Checkout session on the return
     * page. Idempotent — safe to call repeatedly. Returns the fresh order.
     */
    public static function settleOnlineFromSession(string $sessionId): ?array {
        $sessionId = trim($sessionId);
        if ($sessionId === '') return null;
        $tid = current_tenant_id();
        $pay = Database::row(
            "SELECT * FROM restaurant_payments WHERE stripe_session_id = ? AND tenant_id = ?", [$sessionId, $tid]);
        if (!$pay) return null;

        if ($pay['status'] !== 'paid' && class_exists('StripePaymentAPI')) {
            $sess = StripePaymentAPI::getSession($sessionId);
            if ($sess && (string)($sess['payment_status'] ?? '') === 'paid') {
                if (!empty($sess['payment_intent'])) {
                    Database::update('restaurant_payments',
                        ['stripe_pi_id' => (string)$sess['payment_intent']], 'id = ?', [(int)$pay['id']]);
                }
                self::markPaymentPaid((int)$pay['id'], ['latest_charge' => $sess['payment_intent'] ?? null]);
            }
        }
        return self::getOrder((int)$pay['order_id']);
    }

    /**
     * Begin an on-page (Payment Element) card payment for an online order.
     * Creates a pending payment row + a PaymentIntent for the order total and
     * returns the client secret + publishable key for Stripe.js. The PI carries
     * `metadata.source = restaurant`, so handleStripeEvent() settles it from the
     * webhook regardless of what the browser does after confirming.
     */
    public static function beginOnlinePaymentIntent(int $orderId): array {
        if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
            return ['ok' => false, 'error' => 'Online card payment is not available.'];
        }
        $order = self::getOrder($orderId);
        if (!$order) return ['ok' => false, 'error' => 'Order not found.'];
        $amount = (int)$order['total_cents'];
        if ($amount <= 0) return ['ok' => false, 'error' => 'Nothing to charge.'];

        $tid = current_tenant_id();
        $pid = Database::insert('restaurant_payments', [
            'tenant_id'    => $tid,
            'order_id'     => $orderId,
            'method'       => 'other',
            'amount_cents' => $amount,
            'tip_cents'    => 0,
            'status'       => 'pending',
        ]);

        try {
            $pi = StripePaymentAPI::createPaymentIntent($amount, [
                'currency'             => strtolower(self::currency()),
                'receipt_email'        => $order['customer_email'] ?? null,
                'payment_method_types' => ['card'],
                'metadata'             => ['source' => 'restaurant', 'source_plugin' => 'restaurant',
                                           'order_id' => (string)$orderId, 'payment_id' => (string)$pid],
            ]);
        } catch (\Throwable $e) {
            Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$pid]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        Database::update('restaurant_payments', ['stripe_pi_id' => (string)$pi['id']], 'id = ?', [$pid]);
        return [
            'ok'             => true,
            'payment_id'     => $pid,
            'pi_id'          => (string)$pi['id'],
            'client_secret'  => (string)$pi['client_secret'],
            'publishable_key'=> StripePaymentAPI::publishableKey(),
        ];
    }

    /**
     * Settle an online order from its PaymentIntent on the return page (the
     * embedded Payment Element flow). Idempotent; the webhook is the backstop.
     */
    public static function settleOnlineFromPaymentIntent(string $piId): ?array {
        $piId = trim($piId);
        if ($piId === '') return null;
        $tid = current_tenant_id();
        $pay = Database::row(
            "SELECT * FROM restaurant_payments WHERE stripe_pi_id = ? AND tenant_id = ?", [$piId, $tid]);
        if (!$pay) return null;

        if ($pay['status'] !== 'paid' && class_exists('StripePaymentAPI')) {
            $pi = StripePaymentAPI::getPaymentIntent($piId);
            if ($pi && (string)($pi['status'] ?? '') === 'succeeded') {
                self::markPaymentPaid((int)$pay['id'], $pi);
            }
        }
        return self::getOrder((int)$pay['order_id']);
    }

    /** Online orders not yet fulfilled, newest first — the kitchen/expo queue. */
    public static function getOnlineOrders(int $limit = 100): array {
        $tid = current_tenant_id();
        return Database::rows(
            "SELECT o.*, c.name AS customer_name, c.phone AS customer_phone
               FROM restaurant_orders o
               LEFT JOIN restaurant_customers c ON c.id = o.customer_id
              WHERE o.tenant_id = ? AND o.source = 'online'
              ORDER BY o.id DESC LIMIT ?", [$tid, max(1, $limit)]);
    }

    /** Advance an online order's fulfilment state (kitchen/expo workflow). */
    public static function setFulfillment(int $orderId, string $status): void {
        $allowed = ['new', 'accepted', 'preparing', 'ready', 'completed', 'cancelled'];
        if (!in_array($status, $allowed, true)) return;
        $tid = current_tenant_id();
        Database::update('restaurant_orders', ['fulfillment_status' => $status],
            'id = ? AND tenant_id = ? AND source = ?', [$orderId, $tid, 'online']);
        AuditLog::record('restaurant.online_fulfillment', (string)$orderId, ['status' => $status]);
    }
}
