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
 *   RestaurantAPI::getOrder($orderId)       // order + items + modifiers + payments + refunds
 *   RestaurantAPI::saveCombo($itemId, $slots) / validateLineChoices(…)
 *   RestaurantAPI::refundPayment($paymentId, $cents, $reason)
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

    /**
     * Deterministic Stripe Idempotency-Key. $ref names one attempt — a payment
     * row (a retry after a failure opens a new row) or one refund of a payment
     * — so repeating that attempt always sends the same key and Stripe replays
     * its first answer instead of charging or refunding twice. The per-install
     * salt keeps two installs that share a Stripe account from colliding.
     */
    public static function idempotencyKey(string $purpose, string $ref): string {
        $salt = (string) self::setting('idempotency_salt', '');
        if ($salt === '') {
            $salt = bin2hex(random_bytes(8));
            Database::setSetting('restaurant.idempotency_salt', $salt);
        }
        return 'restaurant-' . $salt . '-' . current_tenant_id() . '-' . $purpose . '-' . $ref;
    }

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
     * Recompute every line (modifiers, line discount, line total) and roll up
     * the order: subtotal, order discount, service charge, tax, total.
     *
     *   gross line     = (unit price + modifier deltas) × qty
     *   line discount  = fixed amount (capped at gross) or gross × pct
     *   line total     = gross − line discount         (tax is on this)
     *   subtotal       = Σ non-void line totals
     *   order discount = subtotal × pct, or the fixed amount capped at subtotal
     *   service charge = (subtotal − order discount) × service pct
     *   total          = subtotal − order discount + service + tax + tip
     *
     * Void lines and voided orders contribute nothing. Tax stays on the line
     * totals (after line discounts, before the order discount), as before.
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
            $qty   = max(1, (int)$it['qty']);
            $gross = max(0, ((int)$it['unit_price_cents'] + $modTotal) * $qty);
            $disc  = self::discountOn($gross, (int)($it['discount_fixed_cents'] ?? 0), (float)($it['discount_pct'] ?? 0));
            $lineTotal = $gross - $disc;

            Database::update('restaurant_order_items', [
                'modifiers_total_cents' => $modTotal,
                'discount_cents'        => $disc,
                'line_total_cents'      => $lineTotal,
            ], 'id = ?', [(int)$it['id']]);

            if ($it['kitchen_status'] === 'void') continue;
            $subtotal += $lineTotal;
            $taxTotal += (int) round($lineTotal * ((float)$it['tax_rate'] / 100));
        }

        // Service charge is a percentage of the discounted subtotal. The
        // percent is snapshotted onto the order at open time so later recalcs
        // stay stable even if the global setting changes mid-service.
        $discount = self::discountOn($subtotal, (int)($order['discount_fixed_cents'] ?? 0), (float)($order['discount_pct'] ?? 0));
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

    /** A discount on $base: the percent when set, else the fixed amount; never more than $base. */
    public static function discountOn(int $base, int $fixedCents, float $pct): int {
        if ($base <= 0) return 0;
        $d = $pct > 0 ? (int) round($base * min(100, $pct) / 100) : max(0, $fixedCents);
        return min($base, $d);
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
        $order['refunds'] = Database::rows(
            "SELECT * FROM restaurant_refunds WHERE order_id = ? AND tenant_id = ? ORDER BY id",
            [$orderId, $tid]);
        $order['checks'] = self::checkTotals($order);
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

    // ── Combos ────────────────────────────────────────────────

    /**
     * Slots of a combo item in order, each with its choices: [id, label,
     * min_pick, max_pick, choices => [id (choice), item_id, name,
     * price_delta_cents, is_active, is_86]]. Empty for an item that is not
     * a combo.
     */
    public static function getComboSlots(int $itemId): array {
        $tid  = current_tenant_id();
        $item = Database::row("SELECT is_combo FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$itemId, $tid]);
        if (!$item || empty($item['is_combo'])) return [];
        $slots = Database::rows(
            "SELECT id, label, min_pick, max_pick, sort_order FROM restaurant_combo_slots
              WHERE combo_item_id = ? AND tenant_id = ? ORDER BY sort_order, id", [$itemId, $tid]);
        foreach ($slots as &$sl) {
            $sl['choices'] = Database::rows(
                "SELECT ch.id, ch.item_id, ch.price_delta_cents, i.name, i.is_active, i.is_86
                   FROM restaurant_combo_choices ch
                   JOIN restaurant_items i ON i.id = ch.item_id AND i.tenant_id = ch.tenant_id
                  WHERE ch.slot_id = ? AND ch.tenant_id = ? ORDER BY ch.sort_order, ch.id", [(int)$sl['id'], $tid]);
        }
        unset($sl);
        return $slots;
    }

    /**
     * Check a line's choices against its item, the one rule set the POS, the
     * storefront cart and addLine() all use. $modifierIds are modifier ids;
     * $comboPicks are combo choice ids (one per pick). Every modifier group
     * and combo slot must meet its required / min / max; a pick must belong
     * to the item and its item must be on sale. Returns ['modifiers' =>
     * modifier rows, 'components' => [[slot, choice], …]]; throws a message
     * fit to show the guest otherwise.
     */
    public static function validateLineChoices(int $itemId, array $modifierIds, array $comboPicks = []): array {
        $chosen = array_values(array_unique(array_map('intval', $modifierIds)));
        $mods   = [];
        foreach (self::getItemModifierGroups($itemId) as $g) {
            $picked = [];
            foreach ($g['modifiers'] as $m) {
                if (in_array((int)$m['id'], $chosen, true)) { $picked[] = $m; $mods[] = $m; }
            }
            $min = (int)$g['min_select']; $max = (int)$g['max_select'];
            if (!empty($g['is_required']) && count($picked) < max(1, $min)) {
                throw new \RuntimeException(sprintf(__('restaurant_err_choose_min', 'Choose at least %1$d for: %2$s'), max(1, $min), $g['name']));
            }
            if ($max > 0 && count($picked) > $max) {
                throw new \RuntimeException(sprintf(__('restaurant_err_choose_max', 'Choose at most %1$d for: %2$s'), $max, $g['name']));
            }
        }

        $picks      = array_values(array_unique(array_filter(array_map('intval', $comboPicks))));
        $components = [];
        $known      = [];
        foreach (self::getComboSlots($itemId) as $sl) {
            $inSlot = [];
            foreach ($sl['choices'] as $ch) {
                $known[] = (int)$ch['id'];
                if (!in_array((int)$ch['id'], $picks, true)) continue;
                if (empty($ch['is_active'])) {
                    throw new \RuntimeException(sprintf(__('restaurant_err_choice_off', '%s is not on the menu any more.'), $ch['name']));
                }
                if (!empty($ch['is_86'])) {
                    throw new \RuntimeException(sprintf(__('restaurant_err_choice_86', '%s is sold out right now.'), $ch['name']));
                }
                $inSlot[] = [$sl, $ch];
            }
            $min = (int)$sl['min_pick']; $max = (int)$sl['max_pick'];
            if (count($inSlot) < $min) {
                throw new \RuntimeException(sprintf(__('restaurant_err_choose_min', 'Choose at least %1$d for: %2$s'), $min, $sl['label']));
            }
            if ($max > 0 && count($inSlot) > $max) {
                throw new \RuntimeException(sprintf(__('restaurant_err_choose_max', 'Choose at most %1$d for: %2$s'), $max, $sl['label']));
            }
            array_push($components, ...$inSlot);
        }
        if (array_diff($picks, $known)) {
            throw new \RuntimeException(__('restaurant_err_choice_bad', 'That choice is not part of this combo.'));
        }
        return ['modifiers' => $mods, 'components' => $components];
    }

    /**
     * Delete a menu item with what hangs off it: its modifier-group links, its
     * combo slots if it is a combo, and its place as a choice in any combo.
     * Past order lines keep their name and price snapshots.
     */
    public static function deleteItem(int $itemId): void {
        $tid = current_tenant_id();
        Database::delete('restaurant_item_modifier_groups', 'item_id = ? AND tenant_id = ?', [$itemId, $tid]);
        foreach (array_column(Database::rows("SELECT id FROM restaurant_combo_slots WHERE combo_item_id = ? AND tenant_id = ?", [$itemId, $tid]), 'id') as $sid) {
            Database::delete('restaurant_combo_choices', 'slot_id = ? AND tenant_id = ?', [(int)$sid, $tid]);
        }
        Database::delete('restaurant_combo_slots', 'combo_item_id = ? AND tenant_id = ?', [$itemId, $tid]);
        Database::delete('restaurant_combo_choices', 'item_id = ? AND tenant_id = ?', [$itemId, $tid]);
        Database::delete('restaurant_items', 'id = ? AND tenant_id = ?', [$itemId, $tid]);
        AuditLog::record('restaurant.item_deleted', (string)$itemId);
        self::menuChanged('item_deleted', $itemId);
    }

    /**
     * Price of one unit of a line before discounts: the item's price plus the
     * deltas of its chosen modifiers and combo picks. The storefront cart
     * shows this; recalcOrder() reaches the same number from the stored line.
     * Throws like validateLineChoices().
     */
    public static function lineUnitCents(int $itemId, array $modifierIds, array $comboPicks = []): int {
        $price = (int) Database::value("SELECT price_cents FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$itemId, current_tenant_id()]);
        $c = self::validateLineChoices($itemId, $modifierIds, $comboPicks);
        foreach ($c['modifiers'] as $m) $price += (int)$m['price_delta_cents'];
        foreach ($c['components'] as [, $ch]) $price += (int)$ch['price_delta_cents'];
        return $price;
    }

    /**
     * Make $itemId a combo with these slots, replacing any it had. $slots is
     * a list of [label, min_pick, max_pick, choices => [[item_id,
     * price_delta_cents], …]]; an empty list turns the combo back into a
     * plain item. A choice must be an active item that is not itself a combo
     * (so a combo can never contain itself) and appears once per slot.
     * Throws with the first problem found; nothing is written then.
     */
    public static function saveCombo(int $itemId, array $slots): void {
        $tid  = current_tenant_id();
        $item = Database::row("SELECT * FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$itemId, $tid]);
        if (!$item) throw new \RuntimeException(__('restaurant_err_item', 'Unknown menu item.'));

        $clean = [];
        foreach (array_values($slots) as $n => $sl) {
            $label = trim((string)($sl['label'] ?? ''));
            $min   = max(0, (int)($sl['min_pick'] ?? 0));
            $max   = max(0, (int)($sl['max_pick'] ?? 0));
            $where = sprintf(__('restaurant_combo_slot_n', 'Slot %d'), $n + 1);
            if ($label === '') throw new \RuntimeException($where . ': ' . __('restaurant_combo_err_label', 'give the slot a name.'));
            if ($max < 1 || $min > $max) throw new \RuntimeException($label . ': ' . __('restaurant_combo_err_range', 'picks must be at least 1 and the minimum no more than the maximum.'));
            $seen = []; $choices = [];
            foreach ((array)($sl['choices'] ?? []) as $ch) {
                $cid = (int)($ch['item_id'] ?? 0);
                if ($cid <= 0) continue;
                if ($cid === $itemId) throw new \RuntimeException($label . ': ' . __('restaurant_combo_err_self', 'a combo cannot contain itself.'));
                if (isset($seen[$cid])) throw new \RuntimeException($label . ': ' . __('restaurant_combo_err_dupe', 'each item can be offered once per slot.'));
                $ci = Database::row("SELECT name, is_active, is_combo FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$cid, $tid]);
                if (!$ci) throw new \RuntimeException($label . ': ' . __('restaurant_err_item', 'Unknown menu item.'));
                if (empty($ci['is_active'])) throw new \RuntimeException($label . ': ' . sprintf(__('restaurant_combo_err_inactive', '%s is not active.'), $ci['name']));
                if (!empty($ci['is_combo'])) throw new \RuntimeException($label . ': ' . sprintf(__('restaurant_combo_err_nested', '%s is a combo; combos cannot be put inside combos.'), $ci['name']));
                $seen[$cid] = true;
                $choices[] = ['item_id' => $cid, 'delta' => (int)($ch['price_delta_cents'] ?? 0)];
            }
            if (!$choices) throw new \RuntimeException($label . ': ' . __('restaurant_combo_err_empty', 'add at least one item to choose from.'));
            if ($min > count($choices)) throw new \RuntimeException($label . ': ' . __('restaurant_combo_err_few', 'the minimum is more than the items on offer.'));
            $clean[] = ['label' => mb_substr($label, 0, 120), 'min' => $min, 'max' => $max, 'choices' => $choices];
        }
        if ($clean && (int)Database::value(
                "SELECT COUNT(*) FROM restaurant_combo_choices WHERE item_id = ? AND tenant_id = ?", [$itemId, $tid]) > 0) {
            throw new \RuntimeException(__('restaurant_combo_err_used', 'This item is offered inside another combo, so it cannot become a combo itself.'));
        }

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $old = array_column(Database::rows("SELECT id FROM restaurant_combo_slots WHERE combo_item_id = ? AND tenant_id = ?", [$itemId, $tid]), 'id');
            foreach ($old as $sid) Database::delete('restaurant_combo_choices', 'slot_id = ? AND tenant_id = ?', [(int)$sid, $tid]);
            Database::delete('restaurant_combo_slots', 'combo_item_id = ? AND tenant_id = ?', [$itemId, $tid]);
            foreach ($clean as $i => $sl) {
                $sid = Database::insert('restaurant_combo_slots', [
                    'tenant_id' => $tid, 'combo_item_id' => $itemId, 'label' => $sl['label'],
                    'min_pick' => $sl['min'], 'max_pick' => $sl['max'], 'sort_order' => $i,
                ]);
                foreach ($sl['choices'] as $j => $ch) {
                    Database::insert('restaurant_combo_choices', [
                        'tenant_id' => $tid, 'slot_id' => $sid, 'item_id' => $ch['item_id'],
                        'price_delta_cents' => $ch['delta'], 'sort_order' => $j,
                    ]);
                }
            }
            Database::update('restaurant_items', ['is_combo' => $clean ? 1 : 0], 'id = ? AND tenant_id = ?', [$itemId, $tid]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditLog::record('restaurant.combo_saved', (string)$itemId, ['slots' => count($clean)]);
        self::menuChanged('combo', $itemId);
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
            if (!in_array($payment['status'], ['paid', 'refunded'], true)) {
                Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [(int)$payment['id']]);
            }
            return;
        }

        if (in_array($payment['status'], ['paid', 'refunded'], true)) return; // already settled
        self::markPaymentPaid((int)$payment['id'], $pi);
    }

    /**
     * Mark a payment row paid, record the charge centrally, then advance the
     * order's paid total and close it if fully covered. Safe to call from the
     * webhook or from the POS poll once the reader reports success.
     */
    public static function markPaymentPaid(int $paymentId, array $pi = []): void {
        $payment = Database::row(
            "SELECT * FROM restaurant_payments WHERE id = ?", [$paymentId]);
        if (!$payment || in_array($payment['status'], ['paid', 'refunded'], true)) return;

        // The central charge row is what a refund is later issued against.
        $chargeId = self::recordCentralCharge($payment);

        $charge = !empty($pi['latest_charge']) ? (string)$pi['latest_charge'] : ($payment['stripe_charge_id'] ?? null);
        Database::update('restaurant_payments', [
            'status'            => 'paid',
            'stripe_charge_id'  => $charge,
            'central_charge_id' => $chargeId,
        ], 'id = ?', [$paymentId]);

        AuditLog::record('restaurant.payment_paid', (string)$paymentId, [
            'order_id' => (int)$payment['order_id'],
            'amount'   => (int)$payment['amount_cents'],
            'charge'   => $chargeId,
        ]);

        self::syncOrderPayment((int)$payment['order_id']);
    }

    /**
     * The settled money on an order, net of refunds: ['paid','tips','refunded'].
     * A refund comes out of a payment's amount first and only then its tip, so
     * a partial refund keeps the tip and a full refund takes it back too.
     */
    public static function orderMoney(int $orderId): array {
        $rows = Database::rows(
            "SELECT amount_cents, tip_cents, refunded_cents FROM restaurant_payments
              WHERE order_id = ? AND tenant_id = ? AND status IN ('paid','refunded')",
            [$orderId, current_tenant_id()]);
        $out = ['paid' => 0, 'tips' => 0, 'refunded' => 0];
        foreach ($rows as $r) {
            $amount = (int)$r['amount_cents'];
            $tip    = (int)$r['tip_cents'];
            $ref    = min((int)$r['refunded_cents'], $amount + $tip);
            $out['paid']     += $amount + $tip - $ref;
            $out['tips']     += $tip - max(0, $ref - $amount);
            $out['refunded'] += $ref;
        }
        return $out;
    }

    /**
     * Re-sum settled payments (net of refunds) onto the order and mark it paid
     * when fully covered. Tips collected at tender are folded into the order's
     * tip, the totals are recomputed (so total includes tip), and the order is
     * marked paid once the net paid amount covers that total. A refund never
     * reopens a paid or closed check; it lowers paid_cents and raises
     * refunded_cents.
     */
    public static function syncOrderPayment(int $orderId): void {
        $tid   = current_tenant_id();
        $order = Database::row(
            "SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;

        // Fold tender-time tips into the order tip, then recompute totals so
        // total_cents includes the tip before we compare against paid.
        $money = self::orderMoney($orderId);
        Database::update('restaurant_orders', ['tip_cents' => $money['tips']], 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);

        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        $paid  = $money['paid'];

        $fields = ['paid_cents' => $paid, 'refunded_cents' => $money['refunded']];
        if ((int)$order['total_cents'] > 0 && $paid >= (int)$order['total_cents']
            && !in_array($order['status'], ['voided', 'closed', 'paid'], true)) {
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
        $source = in_array(($args['source'] ?? 'pos'), ['pos', 'online', 'channel'], true)
            ? ($args['source'] ?? 'pos') : 'pos';
        $svcPct = self::serviceChargeAuto() ? self::serviceChargePct() : 0;

        $id = Database::insert('restaurant_orders', [
            'tenant_id'          => $tid,
            'order_no'           => self::generateOrderNo($tid),
            'type'               => $type,
            'table_id'           => !empty($args['table_id']) ? (int)$args['table_id'] : null,
            'customer_id'        => !empty($args['customer_id']) ? (int)$args['customer_id'] : null,
            // An explicit null (a channel order) means no server; otherwise the signed-in user.
            'server_id'          => array_key_exists('server_id', $args) && $args['server_id'] === null
                                    ? null : (!empty($args['server_id']) ? (int)$args['server_id'] : (Auth::userId() ?: null)),
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
     * modifiers => [modifier_id, …], combo => [combo choice id, …] (both
     * checked by validateLineChoices()). A combo's picks are stored with the
     * modifiers as kind 'component', named "Slot: Item", so the kitchen, the
     * receipt and splitLine() carry them like any modifier, and recalcOrder()
     * prices the line as the combo price plus every delta. Returns the new
     * line id. Throws on a bad item, modifier or pick.
     */
    public static function addLine(int $orderId, array $line): int {
        $tid  = current_tenant_id();
        $itemId = (int)($line['item_id'] ?? 0);
        $item = $itemId > 0
            ? Database::row("SELECT * FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$itemId, $tid])
            : null;
        if (!$item) throw new \RuntimeException('Unknown menu item.');
        if (!empty($item['is_86'])) throw new \RuntimeException($item['name'] . " is 86'd right now.");

        $qty    = max(1, (int)($line['qty'] ?? 1));
        $choice = self::validateLineChoices($itemId, (array)($line['modifiers'] ?? []), (array)($line['combo'] ?? []));

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
        foreach ($choice['modifiers'] as $m) {
            Database::insert('restaurant_order_item_modifiers', [
                'tenant_id'         => $tid,
                'order_item_id'     => $lineId,
                'modifier_id'       => (int)$m['id'],
                'name_snapshot'     => mb_substr((string)$m['name'], 0, 160),
                'price_delta_cents' => (int)$m['price_delta_cents'],
            ]);
        }
        foreach ($choice['components'] as [$sl, $ch]) {
            Database::insert('restaurant_order_item_modifiers', [
                'tenant_id'         => $tid,
                'order_item_id'     => $lineId,
                'kind'              => 'component',
                'slot_id'           => (int)$sl['id'],
                'component_item_id' => (int)$ch['item_id'],
                'name_snapshot'     => mb_substr($sl['label'] . ': ' . $ch['name'], 0, 160),
                'price_delta_cents' => (int)$ch['price_delta_cents'],
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

    /** Fire all 'new' lines to the kitchen. */
    public static function sendToKitchen(int $orderId): void {
        $tid = current_tenant_id();
        Database::query(
            "UPDATE restaurant_order_items SET kitchen_status = 'sent', sent_at = ?
              WHERE order_id = ? AND tenant_id = ? AND kitchen_status = 'new'", [slate_db_now(), $orderId, $tid]);
        Database::update('restaurant_orders', ['status' => 'sent'],
            "id = ? AND tenant_id = ? AND status = 'open'", [$orderId, $tid]);
        AuditLog::record('restaurant.order_sent', (string)$orderId);
    }

    /** Fixed-amount order discount (replaces any percentage discount). */
    public static function setDiscount(int $orderId, int $discountCents, string $reason = ''): void {
        $tid = current_tenant_id();
        Database::update('restaurant_orders', [
            'discount_fixed_cents' => max(0, $discountCents),
            'discount_pct'         => 0,
            'discount_reason'      => $reason !== '' ? mb_substr($reason, 0, 160) : null,
        ], 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);
        AuditLog::record('restaurant.discount_set', (string)$orderId, ['cents' => max(0, $discountCents), 'reason' => $reason]);
    }

    /** Percentage order discount, 0–100 (replaces any fixed discount). */
    public static function setDiscountPct(int $orderId, float $pct, string $reason = ''): void {
        $tid = current_tenant_id();
        $pct = max(0, min(100, round($pct, 3)));
        Database::update('restaurant_orders', [
            'discount_fixed_cents' => 0,
            'discount_pct'         => $pct,
            'discount_reason'      => $reason !== '' ? mb_substr($reason, 0, 160) : null,
        ], 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);
        AuditLog::record('restaurant.discount_set', (string)$orderId, ['pct' => $pct, 'reason' => $reason]);
    }

    /**
     * Discount one line: $type 'amount' (cents), 'pct' (0–100) or 'none'.
     * A 100% line discount is how a single item is comped.
     */
    public static function setLineDiscount(int $lineId, string $type, float $value, string $reason = ''): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) throw new \RuntimeException('Line not found.');
        self::assertEditable((int)$line['order_id']);
        $fields = ['discount_fixed_cents' => 0, 'discount_pct' => 0, 'discount_reason' => null];
        if ($type === 'amount' && $value > 0) $fields['discount_fixed_cents'] = (int) round($value);
        if ($type === 'pct' && $value > 0)    $fields['discount_pct'] = max(0, min(100, round($value, 3)));
        if ($type !== 'none' && $reason !== '') $fields['discount_reason'] = mb_substr($reason, 0, 160);
        Database::update('restaurant_order_items', $fields, 'id = ? AND tenant_id = ?', [$lineId, $tid]);
        self::recalcOrder((int)$line['order_id']);
        AuditLog::record('restaurant.line_discount', (string)$lineId, [
            'order_id' => (int)$line['order_id'], 'type' => $type, 'value' => $value, 'reason' => $reason]);
    }

    /** Comp one line: a 100% line discount with the reason, audited as a comp. */
    public static function compLine(int $lineId, string $reason): void {
        self::setLineDiscount($lineId, 'pct', 100, $reason !== '' ? $reason : 'Comp');
        AuditLog::record('restaurant.line_comped', (string)$lineId, ['reason' => $reason]);
    }

    public static function setTip(int $orderId, int $tipCents): void {
        $tid = current_tenant_id();
        Database::update('restaurant_orders', ['tip_cents' => max(0, $tipCents)],
            'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::recalcOrder($orderId);
    }

    // ── Tender ────────────────────────────────────────────────

    /**
     * Tender cash against one check of the order. $tenderedCents is what the
     * guest handed over (null = exact); it must cover amount + tip, and the
     * difference is stored as change. Marks the order paid once covered.
     */
    public static function recordCashPayment(int $orderId, int $amountCents, int $tipCents = 0,
                                             ?int $tenderedCents = null, int $checkNo = 1): int {
        $tid    = current_tenant_id();
        $amount = max(0, $amountCents);
        $tip    = max(0, $tipCents);
        if ($tenderedCents !== null && $tenderedCents < $amount + $tip) {
            throw new \RuntimeException('Cash tendered is less than the amount plus tip.');
        }
        $pid = Database::insert('restaurant_payments', [
            'tenant_id'      => $tid,
            'order_id'       => $orderId,
            'check_no'       => max(1, $checkNo),
            'method'         => 'cash',
            'amount_cents'   => $amount,
            'tip_cents'      => $tip,
            'tendered_cents' => $tenderedCents,
            'change_cents'   => $tenderedCents !== null ? $tenderedCents - $amount - $tip : 0,
            'status'         => 'paid',
        ]);
        AuditLog::record('restaurant.cash_payment', (string)$orderId, [
            'cents' => $amount, 'tip' => $tip, 'tendered' => $tenderedCents, 'check' => max(1, $checkNo)]);
        self::syncOrderPayment($orderId);
        return $pid;
    }

    /**
     * Start a card-present charge on a registered reader (server-driven).
     * Creates a pending payment + a manual-capture card_present PaymentIntent,
     * then pushes it to the reader. Returns ['ok','payment_id','pi_id','simulated']
     * or ['ok'=>false,'error'=>…]. Poll with pollTerminalPayment().
     */
    public static function beginTerminalPayment(int $orderId, int $readerRowId, int $amountCents, int $tipCents = 0,
                                                int $checkNo = 1): array {
        if (!self::terminalAvailable()) {
            return ['ok' => false, 'error' => 'Stripe Terminal is not available. Configure the Stripe plugin first.'];
        }
        $tid    = current_tenant_id();
        $reader = Database::row("SELECT * FROM restaurant_readers WHERE id = ? AND tenant_id = ?", [$readerRowId, $tid]);
        if (!$reader) return ['ok' => false, 'error' => 'Reader not found.'];

        $amountCents = max(0, $amountCents);
        $tipCents    = max(0, $tipCents);
        $charge      = $amountCents + $tipCents;
        if ($charge <= 0) return ['ok' => false, 'error' => 'Nothing to charge.'];

        $orderNo = (string) Database::value(
            "SELECT order_no FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);

        // The payment row comes first so its id can key the Stripe calls. A
        // repeated begin for the same check, reader and amount (a double tap,
        // a retried request) reuses the pending row, so Stripe replays the
        // first PaymentIntent and reader push instead of making new ones.
        $pending = Database::row(
            "SELECT id FROM restaurant_payments
              WHERE tenant_id = ? AND order_id = ? AND method = 'terminal' AND status = 'pending'
                AND reader_id = ? AND amount_cents = ? AND tip_cents = ? AND check_no = ?
                AND created_at >= NOW() - INTERVAL 15 MINUTE
           ORDER BY id DESC LIMIT 1",
            [$tid, $orderId, $readerRowId, $amountCents, $tipCents, max(1, $checkNo)]);
        $pid = $pending ? (int)$pending['id'] : Database::insert('restaurant_payments', [
            'tenant_id'    => $tid,
            'order_id'     => $orderId,
            'check_no'     => max(1, $checkNo),
            'method'       => 'terminal',
            'amount_cents' => $amountCents,
            'tip_cents'    => $tipCents,
            'status'       => 'pending',
            'reader_id'    => $readerRowId,
        ]);

        try {
            $pi = StripeTerminalAPI::createCardPresentIntent($charge, [
                'currency'        => strtolower(self::currency()),
                'metadata'        => ['source' => 'restaurant', 'order_id' => (string)$orderId,
                                      'order_no' => $orderNo, 'payment_id' => (string)$pid],
                'idempotency_key' => self::idempotencyKey('terminal-pi', (string)$pid),
            ]);
        } catch (\Throwable $e) {
            Database::update('restaurant_payments', ['status' => 'failed'], 'id = ?', [$pid]);
            return ['ok' => false, 'error' => $e->getMessage(), 'payment_id' => $pid];
        }
        Database::update('restaurant_payments', ['stripe_pi_id' => (string)$pi['id']], 'id = ?', [$pid]);

        $proc = StripeTerminalAPI::processOnReader((string)$reader['stripe_reader_id'], (string)$pi['id'],
            self::idempotencyKey('terminal-reader', (string)$pid));
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
        if (in_array($pay['status'], ['paid', 'refunded'], true)) return ['ok' => true, 'state' => 'paid'];
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

    // ── Checks (split / merge) ────────────────────────────────

    /** Throw unless the order exists in this tenant and is still open or sent. */
    private static function assertEditable(int $orderId): array {
        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?",
            [$orderId, current_tenant_id()]);
        if (!$order) throw new \RuntimeException('Check not found.');
        if (!in_array($order['status'], ['open', 'sent'], true)) {
            throw new \RuntimeException(__('restaurant_err_not_open', 'This check is no longer open.'));
        }
        return $order;
    }

    /**
     * Totals per check number. Lines carry their own subtotal and tax; the
     * order discount and service charge are shared out in proportion to each
     * check's subtotal, the last check taking the rounding, so the checks
     * always add up to the order exactly. Tips and payments are those
     * recorded against the check. Returns a list of
     * [check_no, subtotal, discount, service, tax, tip, total, paid, balance, lines].
     */
    public static function checkTotals(array $order): array {
        $byCheck = [];
        foreach ($order['items'] ?? [] as $it) {
            $no = max(1, (int)$it['check_no']);
            $byCheck[$no] ??= ['check_no' => $no, 'subtotal' => 0, 'tax' => 0, 'lines' => 0];
            if ($it['kitchen_status'] === 'void') continue;
            $byCheck[$no]['subtotal'] += (int)$it['line_total_cents'];
            $byCheck[$no]['tax']      += (int) round((int)$it['line_total_cents'] * ((float)$it['tax_rate'] / 100));
            $byCheck[$no]['lines']++;
        }
        foreach ($order['payments'] ?? [] as $p) {
            $no = max(1, (int)$p['check_no']);
            $byCheck[$no] ??= ['check_no' => $no, 'subtotal' => 0, 'tax' => 0, 'lines' => 0];
        }
        if (!$byCheck) $byCheck[1] = ['check_no' => 1, 'subtotal' => 0, 'tax' => 0, 'lines' => 0];
        ksort($byCheck);

        $sub   = max(0, (int)$order['subtotal_cents']);
        $share = function (int $whole) use ($byCheck, $sub): array {
            $out = []; $given = 0; $nos = array_keys($byCheck); $last = end($nos);
            foreach ($byCheck as $no => $c) {
                $part = $no === $last ? $whole - $given
                      : ($sub > 0 ? intdiv($whole * $c['subtotal'], $sub) : 0);
                $out[$no] = $part; $given += $part;
            }
            return $out;
        };
        $disc = $share((int)$order['discount_cents']);
        $svc  = $share((int)$order['service_charge_cents']);

        $out = [];
        foreach ($byCheck as $no => $c) {
            $tip = 0; $paid = 0;
            foreach ($order['payments'] ?? [] as $p) {
                if (max(1, (int)$p['check_no']) !== $no || !in_array($p['status'], ['paid', 'refunded'], true)) continue;
                $amount = (int)$p['amount_cents']; $t = (int)$p['tip_cents'];
                $ref    = min((int)$p['refunded_cents'], $amount + $t);
                $tip   += $t - max(0, $ref - $amount);
                $paid  += $amount + $t - $ref;
            }
            $total = $c['subtotal'] - $disc[$no] + $svc[$no] + $c['tax'] + $tip;
            $out[] = [
                'check_no' => $no, 'subtotal' => $c['subtotal'], 'discount' => $disc[$no], 'service' => $svc[$no],
                'tax' => $c['tax'], 'tip' => $tip, 'total' => $total, 'paid' => $paid,
                'balance' => max(0, $total - $paid), 'lines' => $c['lines'],
            ];
        }
        return $out;
    }

    /** True when a check number already holds a settled payment (its lines are locked). */
    private static function checkHasPayments(int $orderId, int $checkNo): bool {
        return (bool) Database::value(
            "SELECT 1 FROM restaurant_payments WHERE order_id = ? AND tenant_id = ? AND check_no = ?
                AND status IN ('paid','refunded','pending') LIMIT 1",
            [$orderId, current_tenant_id(), $checkNo]);
    }

    /** The next unused check number on an order. */
    public static function nextCheckNo(int $orderId): int {
        $tid = current_tenant_id();
        $a = (int) Database::value("SELECT COALESCE(MAX(check_no),0) FROM restaurant_order_items WHERE order_id = ? AND tenant_id = ?", [$orderId, $tid]);
        $b = (int) Database::value("SELECT COALESCE(MAX(check_no),0) FROM restaurant_payments WHERE order_id = ? AND tenant_id = ?", [$orderId, $tid]);
        return max($a, $b, 0) + 1;
    }

    /**
     * Move a line onto another check of the same order (split a check). A
     * check that already holds a payment is locked: lines move neither out
     * of it nor into it, so a settled amount can never change underneath it.
     */
    public static function moveLineToCheck(int $lineId, int $checkNo): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) throw new \RuntimeException('Line not found.');
        self::assertEditable((int)$line['order_id']);
        $checkNo = max(1, $checkNo);
        $from    = max(1, (int)$line['check_no']);
        if ($from === $checkNo) return;
        if (self::checkHasPayments((int)$line['order_id'], $from) || self::checkHasPayments((int)$line['order_id'], $checkNo)) {
            throw new \RuntimeException(__('restaurant_err_check_paid', 'A check with a payment on it is locked. Refund the payment first to change it.'));
        }
        Database::update('restaurant_order_items', ['check_no' => $checkNo], 'id = ? AND tenant_id = ?', [$lineId, $tid]);
        AuditLog::record('restaurant.line_moved', (string)$lineId, ['order_id' => (int)$line['order_id'], 'from' => $from, 'to' => $checkNo]);
    }

    /**
     * Split $qty units off a line onto check $toCheck (e.g. two of three
     * beers onto another guest's check). The new line copies the original's
     * price, tax, modifiers, notes, seat and kitchen state; the original keeps
     * the rest. Returns the new line id.
     */
    public static function splitLine(int $lineId, int $qty, int $toCheck): int {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) throw new \RuntimeException('Line not found.');
        self::assertEditable((int)$line['order_id']);
        if ($line['kitchen_status'] === 'void') throw new \RuntimeException('A void line cannot be split.');
        $have = (int)$line['qty'];
        if ($qty < 1 || $qty >= $have) throw new \RuntimeException(sprintf('Split between 1 and %d.', $have - 1));
        $toCheck = max(1, $toCheck);
        if (self::checkHasPayments((int)$line['order_id'], max(1, (int)$line['check_no']))
            || self::checkHasPayments((int)$line['order_id'], $toCheck)) {
            throw new \RuntimeException(__('restaurant_err_check_paid', 'A check with a payment on it is locked. Refund the payment first to change it.'));
        }

        $copy = $line;
        unset($copy['id'], $copy['created_at']);
        $copy['qty']      = $qty;
        $copy['check_no'] = $toCheck;
        $newId = Database::insert('restaurant_order_items', $copy);
        foreach (Database::rows("SELECT * FROM restaurant_order_item_modifiers WHERE order_item_id = ?", [$lineId]) as $m) {
            unset($m['id']);
            $m['order_item_id'] = $newId;
            Database::insert('restaurant_order_item_modifiers', $m);
        }
        Database::update('restaurant_order_items', ['qty' => $have - $qty], 'id = ? AND tenant_id = ?', [$lineId, $tid]);
        self::recalcOrder((int)$line['order_id']);
        AuditLog::record('restaurant.line_split', (string)$lineId, [
            'order_id' => (int)$line['order_id'], 'qty' => $qty, 'to_check' => $toCheck, 'new_line' => $newId]);
        return $newId;
    }

    /**
     * Merge open check $sourceId into open check $targetId: lines move over
     * on fresh check numbers after the target's, the guest counts add up,
     * and the source is voided with a note pointing at the target. A source
     * that already holds a payment is refused (refund it, or pay it, first).
     */
    public static function mergeOrders(int $targetId, int $sourceId): void {
        $tid = current_tenant_id();
        if ($targetId === $sourceId) throw new \RuntimeException('Pick two different checks.');
        $target = self::assertEditable($targetId);
        $source = self::assertEditable($sourceId);
        if (!empty($target['table_id']) && !empty($source['table_id']) && (int)$target['table_id'] !== (int)$source['table_id']) {
            throw new \RuntimeException(__('restaurant_err_merge_table', 'Only checks on the same table can be merged.'));
        }
        $paid = (int) Database::value(
            "SELECT COUNT(*) FROM restaurant_payments WHERE order_id = ? AND tenant_id = ? AND status IN ('paid','refunded','pending')",
            [$sourceId, $tid]);
        if ($paid > 0) {
            throw new \RuntimeException(__('restaurant_err_merge_paid', 'The check being merged already has a payment. Settle it on its own instead.'));
        }

        $offset = self::nextCheckNo($targetId) - 1;
        Database::query(
            "UPDATE restaurant_order_items SET order_id = ?, check_no = check_no + ? WHERE order_id = ? AND tenant_id = ?",
            [$targetId, $offset, $sourceId, $tid]);
        Database::update('restaurant_orders', [
            'guest_count' => (int)$target['guest_count'] + (int)$source['guest_count'],
            'customer_id' => $target['customer_id'] ?: ($source['customer_id'] ?: null),
            'status'      => ($target['status'] === 'sent' || $source['status'] === 'sent') ? 'sent' : 'open',
        ], 'id = ? AND tenant_id = ?', [$targetId, $tid]);
        $note = trim((($source['notes'] ?? '') !== '' ? $source['notes'] . ' · ' : '') . 'Merged into #' . $target['order_no']);
        Database::update('restaurant_orders', ['status' => 'voided', 'closed_at' => slate_db_now(), 'notes' => mb_substr($note, 0, 255)],
            'id = ? AND tenant_id = ?', [$sourceId, $tid]);
        self::recalcOrder($sourceId);
        self::recalcOrder($targetId);
        AuditLog::record('restaurant.orders_merged', (string)$targetId, ['source' => $sourceId]);
    }

    // ── Comp / reopen ─────────────────────────────────────────

    /**
     * Close a check whose balance will not be collected (a comp), recording
     * the written-off amount and the reason. Needs restaurant.manage_orders
     * at the caller.
     */
    public static function compOrder(int $orderId, string $reason): void {
        $tid   = current_tenant_id();
        $order = self::assertEditable($orderId);
        $reason = trim($reason);
        if ($reason === '') throw new \RuntimeException(__('restaurant_err_reason', 'Enter a reason.'));
        $due = max(0, (int)$order['total_cents'] - (int)$order['paid_cents']);
        Database::update('restaurant_orders', [
            'status' => 'closed', 'closed_at' => slate_db_now(),
            'comp_cents' => $due, 'comp_reason' => mb_substr($reason, 0, 160),
        ], 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        self::freeTableIfSettled($order);
        AuditLog::record('restaurant.order_comped', (string)$orderId, ['cents' => $due, 'reason' => $reason]);
    }

    /**
     * Reopen a paid or closed check so it can be changed and settled again.
     * A comp on it is undone (the balance is owed again). The table, if any,
     * is seated again. Needs restaurant.manage_orders at the caller.
     */
    public static function reopenOrder(int $orderId, string $reason = ''): void {
        $tid   = current_tenant_id();
        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) throw new \RuntimeException('Check not found.');
        if (!in_array($order['status'], ['paid', 'closed'], true)) {
            throw new \RuntimeException(__('restaurant_err_reopen', 'Only a paid or closed check can be reopened.'));
        }
        $sent = (bool) Database::value(
            "SELECT 1 FROM restaurant_order_items WHERE order_id = ? AND tenant_id = ? AND kitchen_status IN ('sent','ready','served') LIMIT 1",
            [$orderId, $tid]);
        Database::update('restaurant_orders', [
            'status' => $sent ? 'sent' : 'open', 'closed_at' => null, 'comp_cents' => 0, 'comp_reason' => null,
        ], 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        if (!empty($order['table_id'])) {
            Database::update('restaurant_tables', ['status' => 'seated'], 'id = ? AND tenant_id = ?', [(int)$order['table_id'], $tid]);
        }
        AuditLog::record('restaurant.order_reopened', (string)$orderId, ['reason' => $reason, 'was' => $order['status']]);
    }

    // ── Kitchen display ───────────────────────────────────────

    /**
     * Advance one line through the kitchen: 'ready' (bumped by the cook),
     * 'served' (run to the guest) or back to 'sent' (recalled). Only the
     * legal steps are taken: sent → ready → served, ready → sent.
     */
    public static function setKitchenStatus(int $lineId, string $to): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT * FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) throw new \RuntimeException('Line not found.');
        $legal = ['sent' => ['ready'], 'ready' => ['served', 'sent']];
        if (!in_array($to, $legal[$line['kitchen_status']] ?? [], true)) {
            throw new \RuntimeException(sprintf('A %s line cannot become %s.', $line['kitchen_status'], $to));
        }
        $fields = ['kitchen_status' => $to];
        if ($to === 'ready')  $fields['ready_at']  = slate_db_now();
        if ($to === 'served') $fields['served_at'] = slate_db_now();
        if ($to === 'sent')   $fields['ready_at']  = null;
        Database::update('restaurant_order_items', $fields, 'id = ? AND tenant_id = ?', [$lineId, $tid]);
        AuditLog::record('restaurant.kitchen_' . $to, (string)$lineId, ['order_id' => (int)$line['order_id']]);
    }

    /** Bump every sent line of an order to ready (or every ready line to served). */
    public static function bumpOrder(int $orderId, string $to = 'ready'): int {
        $from = $to === 'served' ? 'ready' : 'sent';
        $ids  = array_column(Database::rows(
            "SELECT id FROM restaurant_order_items WHERE order_id = ? AND tenant_id = ? AND kitchen_status = ?",
            [$orderId, current_tenant_id(), $from]), 'id');
        foreach ($ids as $id) self::setKitchenStatus((int)$id, $to);
        return count($ids);
    }

    /**
     * Tickets for the kitchen display: every order with a line still in the
     * kitchen (sent or ready), oldest first, each with its lines, modifiers,
     * seats and notes, and the time the oldest line was fired.
     */
    public static function kitchenTickets(): array {
        $tid  = current_tenant_id();
        $rows = Database::rows(
            "SELECT o.id, o.order_no, o.type, o.source, o.notes, o.guest_count, o.requested_time,
                    t.label AS table_label, c.name AS customer_name,
                    MIN(i.sent_at) AS fired_at
               FROM restaurant_orders o
               JOIN restaurant_order_items i ON i.order_id = o.id AND i.tenant_id = o.tenant_id
               LEFT JOIN restaurant_tables t ON t.id = o.table_id
               LEFT JOIN restaurant_customers c ON c.id = o.customer_id
              WHERE o.tenant_id = ? AND o.status <> 'voided' AND i.kitchen_status IN ('sent','ready')
           GROUP BY o.id, o.order_no, o.type, o.source, o.notes, o.guest_count, o.requested_time, t.label, c.name
           ORDER BY fired_at, o.id", [$tid]);
        foreach ($rows as &$r) {
            $r['lines'] = Database::rows(
                "SELECT id, name_snapshot, qty, seat, notes, kitchen_status, sent_at, ready_at, check_no
                   FROM restaurant_order_items
                  WHERE order_id = ? AND tenant_id = ? AND kitchen_status IN ('sent','ready')
               ORDER BY id", [(int)$r['id'], $tid]);
            foreach ($r['lines'] as &$l) {
                $l['modifiers'] = array_column(Database::rows(
                    "SELECT name_snapshot FROM restaurant_order_item_modifiers WHERE order_item_id = ? ORDER BY id",
                    [(int)$l['id']]), 'name_snapshot');
            }
            unset($l);
        }
        unset($r);
        return $rows;
    }

    // ── Guests, seats, tables, 86 ─────────────────────────────

    /** Active customers matching a name, phone or email fragment (POS guest lookup; archived ones are hidden). */
    public static function searchCustomers(string $q, int $limit = 8): array {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        return Database::rows(
            "SELECT id, name, phone, email FROM restaurant_customers
              WHERE tenant_id = ? AND is_active = 1 AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)
           ORDER BY name LIMIT " . max(1, min(25, $limit)),
            [current_tenant_id(), $like, $like, $like]);
    }

    /** Set (or clear, with null) the seat number of a line. */
    public static function setLineSeat(int $lineId, ?int $seat): void {
        $tid  = current_tenant_id();
        $line = Database::row("SELECT order_id FROM restaurant_order_items WHERE id = ? AND tenant_id = ?", [$lineId, $tid]);
        if (!$line) throw new \RuntimeException('Line not found.');
        self::assertEditable((int)$line['order_id']);
        Database::update('restaurant_order_items', ['seat' => ($seat !== null && $seat > 0) ? $seat : null],
            'id = ? AND tenant_id = ?', [$lineId, $tid]);
    }

    /** Mark a menu item 86'd (sold out) or back on, from the register. */
    public static function set86(int $itemId, bool $on): void {
        $tid = current_tenant_id();
        $n = Database::update('restaurant_items', ['is_86' => $on ? 1 : 0], 'id = ? AND tenant_id = ?', [$itemId, $tid]);
        if ($n) { AuditLog::record('restaurant.item_86', (string)$itemId, ['on' => $on]); self::menuChanged('item_86', $itemId); }
    }

    /** Set a table's status by hand (e.g. mark a dirty table clean). */
    public static function setTableStatus(int $tableId, string $status): void {
        if (!in_array($status, ['open', 'seated', 'dirty'], true)) return;
        Database::update('restaurant_tables', ['status' => $status], 'id = ? AND tenant_id = ?', [$tableId, current_tenant_id()]);
    }

    /** Active tables with their section and the open checks on each (POS table map). */
    public static function floorMap(): array {
        $tid = current_tenant_id();
        $tables = Database::rows(
            "SELECT t.id, t.label, t.seats, t.status, t.pos_x, t.pos_y, t.section_id, s.name AS section, s.sort_order AS section_sort
               FROM restaurant_tables t
               LEFT JOIN restaurant_sections s ON s.id = t.section_id
              WHERE t.tenant_id = ? AND t.is_active = 1
           ORDER BY s.sort_order, s.name, t.label", [$tid]);
        $open = Database::rows(
            "SELECT id, table_id, order_no, total_cents, paid_cents, guest_count, opened_at, status
               FROM restaurant_orders WHERE tenant_id = ? AND status IN ('open','sent') AND table_id IS NOT NULL", [$tid]);
        foreach ($tables as &$t) {
            $t['orders'] = array_values(array_filter($open, fn($o) => (int)$o['table_id'] === (int)$t['id']));
        }
        unset($t);
        return $tables;
    }

    // ── Refunds ───────────────────────────────────────────────

    /**
     * Record (or find) the stripe-payment central charge row for a card
     * payment. recordCharge() is idempotent on the PaymentIntent, so calling
     * this again for the same payment returns the same row. Null for payments
     * that never touched Stripe.
     */
    private static function recordCentralCharge(array $payment): ?int {
        if (!class_exists('StripePaymentAPI') || empty($payment['stripe_pi_id'])) return null;
        return StripePaymentAPI::recordCharge([
            'source_plugin'            => 'restaurant',
            'source_id'                => (string)$payment['order_id'],
            'stripe_payment_intent_id' => (string)$payment['stripe_pi_id'],
            'amount_cents'             => (int)$payment['amount_cents'] + (int)$payment['tip_cents'],
            'currency'                 => self::currency(),
            'status'                   => 'succeeded',
            'meta'                     => ['method'   => $payment['method'] === 'terminal' ? 'terminal' : 'online',
                                           'order_id' => (int)$payment['order_id']],
        ]);
    }

    /** Amount of a payment that can still be refunded (amount + tip − refunded). */
    public static function refundableCents(array $payment): int {
        if ($payment['status'] !== 'paid') return 0;
        return max(0, (int)$payment['amount_cents'] + (int)$payment['tip_cents'] - (int)$payment['refunded_cents']);
    }

    /**
     * Refund all or part of a settled payment. A card payment (terminal or
     * online) is refunded through Stripe against the central charge recorded
     * when it settled; a cash payment is recorded only, the money leaves the
     * drawer. $amountCents null refunds what is left. A partial refund keeps
     * the payment 'paid' with refunded_cents set; the last one marks it
     * 'refunded'. Returns ['ok'=>true,'refund_id'=>int,'refunded_cents'=>int]
     * or ['ok'=>false,'error'=>string].
     */
    public static function refundPayment(int $paymentId, ?int $amountCents = null, string $reason = ''): array {
        $tid = current_tenant_id();
        $pay = Database::row("SELECT * FROM restaurant_payments WHERE id = ? AND tenant_id = ?", [$paymentId, $tid]);
        if (!$pay) return ['ok' => false, 'error' => __('restaurant_refund_err_not_found', 'Payment not found.')];
        if ($pay['status'] === 'refunded') {
            return ['ok' => false, 'error' => __('restaurant_refund_err_already', 'This payment is already fully refunded.')];
        }
        if ($pay['status'] !== 'paid') {
            return ['ok' => false, 'error' => __('restaurant_refund_err_not_paid', 'Only a settled payment can be refunded.')];
        }

        $before    = (int)$pay['refunded_cents'];
        $remaining = self::refundableCents($pay);
        $amount    = $amountCents ?? $remaining;
        if ($remaining <= 0) {
            return ['ok' => false, 'error' => __('restaurant_refund_err_already', 'This payment is already fully refunded.')];
        }
        if ($amount <= 0 || $amount > $remaining) {
            return ['ok' => false, 'error' => sprintf(
                __('restaurant_refund_err_range', 'Refund between %1$s and %2$s.'), self::money(1), self::money($remaining))];
        }

        $isCard = !empty($pay['stripe_pi_id']);
        if ($isCard && !(class_exists('StripePaymentAPI') && StripePaymentAPI::isConfigured())) {
            return ['ok' => false, 'error' => __('restaurant_refund_err_stripe', 'Card refunds need the Stripe plugin configured.')];
        }

        // Claim the amount first, in one statement, so two refunds racing on
        // one payment can never together exceed what was paid. The claim is
        // released if Stripe refuses the refund.
        $claimed = Database::query(
            "UPDATE restaurant_payments SET refunded_cents = refunded_cents + ?
              WHERE id = ? AND tenant_id = ? AND status = 'paid' AND refunded_cents = ?
                AND refunded_cents + ? <= amount_cents + tip_cents",
            [$amount, $paymentId, $tid, $before, $amount])->rowCount();
        if ($claimed !== 1) {
            return ['ok' => false, 'error' => __('restaurant_refund_err_changed', 'This payment changed while you were refunding it. Reload and try again.')];
        }

        $refundId = Database::insert('restaurant_refunds', [
            'tenant_id'    => $tid,
            'payment_id'   => $paymentId,
            'order_id'     => (int)$pay['order_id'],
            'method'       => $pay['method'],
            'amount_cents' => $amount,
            'status'       => 'pending',
            'reason'       => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'user_id'      => Auth::userId() ?: null,
        ]);

        $stripeRefundId = null;
        if ($isCard) {
            $chargeId = !empty($pay['central_charge_id']) ? (int)$pay['central_charge_id'] : self::recordCentralCharge($pay);
            if ($chargeId && empty($pay['central_charge_id'])) {
                Database::update('restaurant_payments', ['central_charge_id' => $chargeId], 'id = ?', [$paymentId]);
            }
            // The key is what was refunded before, this amount, and how many
            // refunds Stripe has definitely refused on this payment. Re-sending
            // after a lost answer therefore replays the first refund instead of
            // issuing a second; a retry after a refusal gets a fresh key.
            $refused = (int) Database::value(
                "SELECT COUNT(*) FROM restaurant_refunds WHERE payment_id = ? AND tenant_id = ? AND status = 'failed'",
                [$paymentId, $tid]);
            $res = $chargeId
                ? StripePaymentAPI::refundCharge($chargeId, $amount, [
                      'idempotency_key' => self::idempotencyKey('refund',
                          $paymentId . '-' . $before . '-' . $amount . '-' . $refused),
                  ])
                : ['ok' => false, 'error' => __('restaurant_refund_err_no_charge', 'No Stripe charge is recorded for this payment.')];
            if (empty($res['ok'])) {
                Database::query(
                    "UPDATE restaurant_payments SET refunded_cents = refunded_cents - ?
                      WHERE id = ? AND tenant_id = ? AND refunded_cents >= ?",
                    [$amount, $paymentId, $tid, $amount]);
                $err = (string)($res['error'] ?? 'Refund failed.');
                // No answer from Stripe: the refund may have gone through. The
                // row stays 'pending' (not a refusal), so a retry of the same
                // amount sends the same key and Stripe replays what happened.
                $unknown = str_starts_with($err, 'Stripe HTTP transport error')
                        || str_starts_with($err, 'Stripe response was not JSON');
                Database::update('restaurant_refunds', [
                    'status' => $unknown ? 'pending' : 'failed',
                    'error'  => mb_substr($err, 0, 255),
                ], 'id = ?', [$refundId]);
                AuditLog::record('restaurant.refund_failed', (string)$paymentId, [
                    'order_id' => (int)$pay['order_id'], 'amount' => $amount, 'error' => $err, 'unknown' => $unknown,
                ]);
                if ($unknown) {
                    $err = __('restaurant_refund_err_unknown',
                        'Stripe did not answer, so the refund may or may not have gone through. Retry the same amount: Stripe will not refund it twice.');
                }
                return ['ok' => false, 'error' => $err];
            }
            $stripeRefundId = (string)($res['refund_id'] ?? '') ?: null;
        }

        Database::update('restaurant_refunds', ['status' => 'succeeded', 'stripe_refund_id' => $stripeRefundId],
            'id = ?', [$refundId]);
        Database::query(
            "UPDATE restaurant_payments SET status = 'refunded'
              WHERE id = ? AND tenant_id = ? AND status = 'paid' AND refunded_cents >= amount_cents + tip_cents",
            [$paymentId, $tid]);

        AuditLog::record('restaurant.payment_refunded', (string)$paymentId, [
            'order_id'      => (int)$pay['order_id'],
            'amount'        => $amount,
            'method'        => $pay['method'],
            'refund_id'     => $refundId,
            'stripe_refund' => $stripeRefundId,
            'reason'        => $reason,
        ]);

        self::syncOrderPayment((int)$pay['order_id']);
        return ['ok' => true, 'refund_id' => $refundId, 'refunded_cents' => $before + $amount];
    }

    // ── Close / void ──────────────────────────────────────────

    /**
     * Close a check. Refuses while a balance is due: an underpaid check is
     * closed through compOrder() (with a reason), never silently.
     */
    public static function closeOrder(int $orderId): void {
        $tid   = current_tenant_id();
        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, $tid]);
        if (!$order) return;
        $due = (int)$order['total_cents'] - (int)$order['paid_cents'] - (int)$order['comp_cents'];
        if ($due > 0) {
            throw new \RuntimeException(sprintf(
                __('restaurant_err_close_balance', 'This check still owes %s. Take payment, or comp the balance with a reason.'),
                self::money($due)));
        }
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
                    'combo'     => array_map('intval', (array)($line['combo'] ?? [])),
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
        // A second press of Pay for the same order reuses the session still open.
        $pending = Database::row(
            "SELECT * FROM restaurant_payments
              WHERE tenant_id = ? AND order_id = ? AND method = 'other' AND status = 'pending'
                AND stripe_session_id IS NOT NULL AND amount_cents = ?
           ORDER BY id DESC LIMIT 1", [$tid, $orderId, (int)$order['total_cents']]);
        if ($pending) {
            $open = StripePaymentAPI::getSession((string)$pending['stripe_session_id']);
            if ($open && ($open['status'] ?? '') === 'open' && !empty($open['url'])) {
                return ['ok' => true, 'url' => (string)$open['url'], 'payment_id' => (int)$pending['id']];
            }
        }

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
                'currency'        => strtolower(self::currency()),
                'success_url'     => $successUrl,
                'cancel_url'      => $cancelUrl,
                'customer_email'  => $order['customer_email'] ?? null,
                'metadata'        => ['source' => 'restaurant', 'source_plugin' => 'restaurant',
                                      'order_id' => (string)$orderId, 'payment_id' => (string)$pid],
                'idempotency_key' => self::idempotencyKey('checkout', (string)$pid),
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
                self::markPaymentPaid((int)$pay['id']);
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
        // Reloading the checkout page reuses the pending payment and its
        // PaymentIntent, so the customer can never be holding two to pay.
        $pending = Database::row(
            "SELECT * FROM restaurant_payments
              WHERE tenant_id = ? AND order_id = ? AND method = 'other' AND status = 'pending'
                AND stripe_session_id IS NULL AND amount_cents = ?
           ORDER BY id DESC LIMIT 1", [$tid, $orderId, $amount]);
        if ($pending && !empty($pending['stripe_pi_id'])) {
            $existing = StripePaymentAPI::getPaymentIntent((string)$pending['stripe_pi_id']);
            $state    = (string)($existing['status'] ?? '');
            if ($state === 'succeeded') {
                self::markPaymentPaid((int)$pending['id'], $existing);
                return ['ok' => false, 'error' => 'This order is already paid.'];
            }
            if ($existing && $state !== 'canceled' && !empty($existing['client_secret'])) {
                return [
                    'ok'             => true,
                    'payment_id'     => (int)$pending['id'],
                    'pi_id'          => (string)$existing['id'],
                    'client_secret'  => (string)$existing['client_secret'],
                    'publishable_key'=> StripePaymentAPI::publishableKey(),
                ];
            }
        }
        $pid = ($pending && empty($pending['stripe_pi_id'])) ? (int)$pending['id'] : Database::insert('restaurant_payments', [
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
                'idempotency_key'      => self::idempotencyKey('online-pi', (string)$pid),
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
              WHERE o.tenant_id = ? AND o.source IN ('online','channel')
              ORDER BY o.id DESC LIMIT ?", [$tid, max(1, $limit)]);
    }

    /**
     * Advance an online or channel order's fulfilment state (kitchen/expo
     * workflow). A real change fires restaurant_fulfillment_changed (order
     * id, new status, old status); a channel order's adapter answers it by
     * telling the channel.
     */
    public static function setFulfillment(int $orderId, string $status): void {
        $allowed = ['new', 'accepted', 'preparing', 'ready', 'completed', 'cancelled'];
        if (!in_array($status, $allowed, true)) return;
        $tid = current_tenant_id();
        $was = (string) Database::value("SELECT fulfillment_status FROM restaurant_orders WHERE id = ? AND tenant_id = ? AND source IN ('online','channel')", [$orderId, $tid]);
        if ($was === '' || $was === $status) return;
        Database::update('restaurant_orders', ['fulfillment_status' => $status],
            "id = ? AND tenant_id = ? AND source IN ('online','channel')", [$orderId, $tid]);
        AuditLog::record('restaurant.online_fulfillment', (string)$orderId, ['status' => $status]);
        Hook::doAction('restaurant_fulfillment_changed', $orderId, $status, $was);
    }

    /**
     * Something on the menu changed (an item, category, modifier group or
     * combo was saved or deleted, a price or 86 flag moved). Fires
     * restaurant_menu_changed so feed and push adapters can react.
     */
    public static function menuChanged(string $what, $ref = null): void {
        Hook::doAction('restaurant_menu_changed', $what, $ref);
    }
}
