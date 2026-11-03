<?php
/**
 * Restaurant — reports and payment reconciliation.
 *
 * One place computes every figure, so the reports screen, its CSV files,
 * the payments list and the tests can never disagree. The rules follow the
 * money engine:
 *
 *   - An order counts as a sale when its status is paid or closed, by the
 *     day it was opened (opened_at). Voided, open and sent orders never do.
 *   - gross sales  = Σ line amounts before discounts (non-void lines)
 *   - discounts    = Σ line discounts + Σ order discounts (fixed or %)
 *   - order totals = gross − discounts + service charge + tax + tips
 *   - comps        = balances written off with compOrder()
 *   - refunds      = succeeded rows of the refunds ledger, by refund date
 *   - net sales    = order totals − comps − refunds
 *   - Sales by channel split the channel source by the channel each order
 *     came through (orders.channel); cancelled = voided channel orders.
 *   - courier fees = Σ fees of courier jobs booked in the range (by the
 *     job's created_at) that were delivered or are still under way; a cost,
 *     never taken out of net sales.
 *   - Payment mix and the payments list go by the payment's own date;
 *     "online" is a card payment taken on the storefront (method 'other'
 *     with a Stripe PaymentIntent or Checkout session).
 *
 * Day boundaries come from the database clock, the clock every timestamp is
 * stamped with. Money is integer cents throughout.
 */

class RestaurantReports {

    public const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'custom'];

    /** SQL for a payment's method class: cash, terminal, online or other. */
    private const METHOD_SQL = "CASE WHEN p.method = 'other' AND (p.stripe_pi_id IS NOT NULL OR p.stripe_session_id IS NOT NULL)
                                     THEN 'online' ELSE p.method END";

    /**
     * Resolve a period key to dates plus the equivalent previous period:
     * ['key', 'from', 'to', 'prev_from', 'prev_to'] (Y-m-d, inclusive).
     * This week and this month compare against the same number of days at
     * the start of the week / month before; a custom range against the same
     * number of days immediately before it.
     */
    public static function period(string $key, string $from = '', string $to = '', ?string $today = null): array {
        $today = $today ?? substr(slate_db_now(), 0, 10);
        $d   = fn(string $base, int $days) => date('Y-m-d', strtotime($base . ' ' . ($days >= 0 ? '+' : '') . $days . ' days'));
        $mon = $d($today, -((int)date('N', strtotime($today)) - 1));
        $key = in_array($key, self::PERIODS, true) ? $key : 'today';
        switch ($key) {
            case 'yesterday':
                return ['key' => $key, 'from' => $d($today, -1), 'to' => $d($today, -1), 'prev_from' => $d($today, -2), 'prev_to' => $d($today, -2)];
            case 'this_week':
                return ['key' => $key, 'from' => $mon, 'to' => $today, 'prev_from' => $d($mon, -7), 'prev_to' => $d($today, -7)];
            case 'last_week':
                return ['key' => $key, 'from' => $d($mon, -7), 'to' => $d($mon, -1), 'prev_from' => $d($mon, -14), 'prev_to' => $d($mon, -8)];
            case 'this_month':
                $first     = substr($today, 0, 8) . '01';
                $prevFirst = date('Y-m-01', strtotime($first . ' -1 month'));
                $day       = min((int)substr($today, 8, 2), (int)date('t', strtotime($prevFirst)));
                return ['key' => $key, 'from' => $first, 'to' => $today,
                        'prev_from' => $prevFirst, 'prev_to' => substr($prevFirst, 0, 8) . sprintf('%02d', $day)];
            case 'custom':
                $ok = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false;
                $f  = $ok($from) ? $from : $today;
                $t  = $ok($to) ? $to : $f;
                if ($t < $f) [$f, $t] = [$t, $f];
                $days = (int) round((strtotime($t) - strtotime($f)) / 86400) + 1;
                return ['key' => $key, 'from' => $f, 'to' => $t, 'prev_from' => $d($f, -$days), 'prev_to' => $d($f, -1)];
            default:
                return ['key' => 'today', 'from' => $today, 'to' => $today, 'prev_from' => $d($today, -1), 'prev_to' => $d($today, -1)];
        }
    }

    /** Every figure for one date range (inclusive). */
    public static function summary(string $from, string $to): array {
        $tid  = current_tenant_id();
        $lo   = $from . ' 00:00:00';
        $hi   = $to . ' 23:59:59';
        $sale = "o.tenant_id = ? AND o.status IN ('paid','closed') AND o.opened_at BETWEEN ? AND ?";
        $args = [$tid, $lo, $hi];

        $o = Database::row(
            "SELECT COUNT(*) AS orders,
                    COALESCE(SUM(o.subtotal_cents),0)       AS subtotal,
                    COALESCE(SUM(o.discount_cents),0)       AS order_discounts,
                    COALESCE(SUM(o.service_charge_cents),0) AS service,
                    COALESCE(SUM(o.tax_cents),0)            AS tax,
                    COALESCE(SUM(o.tip_cents),0)            AS tips,
                    COALESCE(SUM(o.total_cents),0)          AS totals,
                    COALESCE(SUM(o.comp_cents),0)           AS comps,
                    COALESCE(SUM(CASE WHEN o.type = 'dine_in' THEN o.guest_count ELSE 0 END),0) AS covers
               FROM restaurant_orders o WHERE {$sale}", $args);
        $lineDisc = (int) Database::value(
            "SELECT COALESCE(SUM(i.discount_cents),0)
               FROM restaurant_order_items i JOIN restaurant_orders o ON o.id = i.order_id AND o.tenant_id = i.tenant_id
              WHERE {$sale} AND i.kitchen_status <> 'void'", $args);
        $ref = Database::row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount_cents),0) AS amount FROM restaurant_refunds
              WHERE tenant_id = ? AND status = 'succeeded' AND created_at BETWEEN ? AND ?", $args);

        $orders    = (int)$o['orders'];
        $totals    = (int)$o['totals'];
        $comps     = (int)$o['comps'];
        $refunds   = (int)$ref['amount'];
        $discounts = $lineDisc + (int)$o['order_discounts'];

        // Tips by how they were paid, net of any refund that reached the tip
        // (a refund comes out of the amount first, as in orderMoney()).
        $tipRows = Database::rows(
            "SELECT " . self::METHOD_SQL . " AS m, p.amount_cents, p.tip_cents, p.refunded_cents
               FROM restaurant_payments p JOIN restaurant_orders o ON o.id = p.order_id AND o.tenant_id = p.tenant_id
              WHERE {$sale} AND p.status IN ('paid','refunded') AND p.tip_cents > 0", $args);
        $tipsBy = ['cash' => 0, 'card' => 0];
        foreach ($tipRows as $r) {
            $net = (int)$r['tip_cents'] - max(0, min((int)$r['refunded_cents'], (int)$r['amount_cents'] + (int)$r['tip_cents']) - (int)$r['amount_cents']);
            $tipsBy[$r['m'] === 'cash' ? 'cash' : 'card'] += $net;
        }

        return [
            'from' => $from, 'to' => $to,
            'gross_sales'  => (int)$o['subtotal'] + $lineDisc,
            'discounts'    => $discounts,
            'service'      => (int)$o['service'],
            'tax'          => (int)$o['tax'],
            'tips'         => (int)$o['tips'],
            'tips_cash'    => $tipsBy['cash'],
            'tips_card'    => $tipsBy['card'],
            'order_totals' => $totals,
            'comps'        => $comps,
            'refunds'      => $refunds,
            'refund_count' => (int)$ref['n'],
            'net_sales'    => $totals - $comps - $refunds,
            'orders'       => $orders,
            'avg_order'    => $orders > 0 ? (int) round(($totals - $comps) / $orders) : 0,
            'covers'       => (int)$o['covers'],
            'voided'       => (int) Database::value(
                "SELECT COUNT(*) FROM restaurant_orders WHERE tenant_id = ? AND status = 'voided' AND opened_at BETWEEN ? AND ?", $args),
            'methods'      => self::methodMix($lo, $hi),
            'items'        => self::topItems($sale, $args),
            'categories'   => self::topCategories($sale, $args),
            'sources'      => self::sources($sale, $args),
            'channels'     => self::channels($sale, $args, $lo, $hi),
            'fulfilment'   => self::fulfilment($lo, $hi),
        ] + self::couriers($lo, $hi);
    }

    /**
     * Channel orders by channel slug: orders, sales (totals less comps) and
     * cancelled (voided) orders. Every configured channel is listed, plus any
     * slug an order still carries after its channel was deleted.
     */
    private static function channels(string $sale, array $args, string $lo, string $hi): array {
        $tid = current_tenant_id();
        $out = [];
        foreach (Database::rows("SELECT slug, name FROM restaurant_channels WHERE tenant_id = ? ORDER BY name", [$tid]) as $c) {
            $out[$c['slug']] = ['name' => $c['name'], 'orders' => 0, 'sales' => 0, 'cancelled' => 0];
        }
        $blank = fn(string $slug) => ['name' => $slug, 'orders' => 0, 'sales' => 0, 'cancelled' => 0];
        foreach (Database::rows(
            "SELECT COALESCE(o.channel, '') AS ch, COUNT(*) AS n, COALESCE(SUM(CAST(o.total_cents AS SIGNED) - CAST(o.comp_cents AS SIGNED)),0) AS s
               FROM restaurant_orders o WHERE {$sale} AND o.source = 'channel' GROUP BY ch", $args) as $r) {
            $out[$r['ch']] ??= $blank($r['ch']);
            $out[$r['ch']]['orders'] = (int)$r['n'];
            $out[$r['ch']]['sales']  = (int)$r['s'];
        }
        foreach (Database::rows(
            "SELECT COALESCE(channel, '') AS ch, COUNT(*) AS n FROM restaurant_orders
              WHERE tenant_id = ? AND source = 'channel' AND status = 'voided' AND opened_at BETWEEN ? AND ? GROUP BY ch", [$tid, $lo, $hi]) as $r) {
            $out[$r['ch']] ??= $blank($r['ch']);
            $out[$r['ch']]['cancelled'] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * Courier jobs booked in the range that cost money: delivered or still
     * under way (quotes, cancellations and failures never charged here).
     * Returns courier_fees, courier_jobs and couriers (by provider key:
     * delivered, active, fees).
     */
    private static function couriers(string $lo, string $hi): array {
        $by = [];
        foreach (Database::rows(
            "SELECT provider, SUM(status = 'delivered') AS delivered, SUM(status <> 'delivered') AS active, COALESCE(SUM(fee_cents),0) AS fees
               FROM restaurant_delivery_jobs
              WHERE tenant_id = ? AND created_at BETWEEN ? AND ?
                AND status IN ('requested','courier_assigned','enroute_pickup','picked_up','delivered')
           GROUP BY provider", [current_tenant_id(), $lo, $hi]) as $r) {
            $by[$r['provider']] = ['delivered' => (int)$r['delivered'], 'active' => (int)$r['active'], 'fees' => (int)$r['fees']];
        }
        return ['courier_fees' => array_sum(array_column($by, 'fees')),
                'courier_jobs' => array_sum(array_column($by, 'delivered')) + array_sum(array_column($by, 'active')),
                'couriers' => $by];
    }

    /** Payments taken in the range by method class: count, collected, refunds (by refund date), net. */
    private static function methodMix(string $lo, string $hi): array {
        $tid = current_tenant_id();
        $out = [];
        foreach (['cash', 'terminal', 'online', 'other', 'channel'] as $m) $out[$m] = ['count' => 0, 'collected' => 0, 'refunds' => 0, 'net' => 0];
        foreach (Database::rows(
            "SELECT " . self::METHOD_SQL . " AS m, COUNT(*) AS n, COALESCE(SUM(p.amount_cents + p.tip_cents),0) AS c
               FROM restaurant_payments p
              WHERE p.tenant_id = ? AND p.status IN ('paid','refunded') AND p.created_at BETWEEN ? AND ?
           GROUP BY m", [$tid, $lo, $hi]) as $r) {
            $out[$r['m']]['count'] = (int)$r['n'];
            $out[$r['m']]['collected'] = (int)$r['c'];
        }
        foreach (Database::rows(
            "SELECT " . self::METHOD_SQL . " AS m, COALESCE(SUM(r.amount_cents),0) AS a
               FROM restaurant_refunds r JOIN restaurant_payments p ON p.id = r.payment_id AND p.tenant_id = r.tenant_id
              WHERE r.tenant_id = ? AND r.status = 'succeeded' AND r.created_at BETWEEN ? AND ?
           GROUP BY m", [$tid, $lo, $hi]) as $r) {
            $out[$r['m']]['refunds'] = (int)$r['a'];
        }
        foreach ($out as &$v) $v['net'] = $v['collected'] - $v['refunds'];
        unset($v);
        return $out;
    }

    /** Top 10 items by quantity and by revenue (line totals after line discounts). */
    private static function topItems(string $sale, array $args): array {
        $rows = Database::rows(
            "SELECT COALESCE(i.item_id, 0) AS item_id, MAX(i.name_snapshot) AS name,
                    SUM(i.qty) AS qty, SUM(i.line_total_cents) AS revenue
               FROM restaurant_order_items i JOIN restaurant_orders o ON o.id = i.order_id AND o.tenant_id = i.tenant_id
              WHERE {$sale} AND i.kitchen_status <> 'void'
           GROUP BY COALESCE(i.item_id, 0), CASE WHEN i.item_id IS NULL THEN i.name_snapshot ELSE '' END", $args);
        $rows = array_map(fn($r) => ['name' => (string)$r['name'], 'qty' => (int)$r['qty'], 'revenue' => (int)$r['revenue']], $rows);
        $byQty = $rows;
        usort($byQty, fn($a, $b) => [$b['qty'], $b['revenue'], $a['name']] <=> [$a['qty'], $a['revenue'], $b['name']]);
        $byRev = $rows;
        usort($byRev, fn($a, $b) => [$b['revenue'], $b['qty'], $a['name']] <=> [$a['revenue'], $a['qty'], $b['name']]);
        return ['by_qty' => array_slice($byQty, 0, 10), 'by_revenue' => array_slice($byRev, 0, 10)];
    }

    /** Categories by quantity and revenue, using each item's current category. */
    private static function topCategories(string $sale, array $args): array {
        $rows = Database::rows(
            "SELECT COALESCE(c.id, 0) AS cid, MAX(c.name) AS name, SUM(i.qty) AS qty, SUM(i.line_total_cents) AS revenue
               FROM restaurant_order_items i JOIN restaurant_orders o ON o.id = i.order_id AND o.tenant_id = i.tenant_id
               LEFT JOIN restaurant_items mi ON mi.id = i.item_id AND mi.tenant_id = i.tenant_id
               LEFT JOIN restaurant_menu_categories c ON c.id = mi.category_id AND c.tenant_id = i.tenant_id
              WHERE {$sale} AND i.kitchen_status <> 'void'
           GROUP BY COALESCE(c.id, 0)
           ORDER BY revenue DESC, qty DESC", $args);
        return array_map(fn($r) => [
            'name' => $r['name'] !== null ? (string)$r['name'] : __('restaurant_rep_no_category', 'No category'),
            'qty' => (int)$r['qty'], 'revenue' => (int)$r['revenue']], $rows);
    }

    /** Sales by source: POS, online storefront, outside channels; as orders and order totals less comps. */
    private static function sources(string $sale, array $args): array {
        $out = ['pos' => ['orders' => 0, 'sales' => 0], 'online' => ['orders' => 0, 'sales' => 0], 'channel' => ['orders' => 0, 'sales' => 0]];
        foreach (Database::rows(
            "SELECT o.source, COUNT(*) AS n, COALESCE(SUM(CAST(o.total_cents AS SIGNED) - CAST(o.comp_cents AS SIGNED)),0) AS s
               FROM restaurant_orders o WHERE {$sale} GROUP BY o.source", $args) as $r) {
            $out[$r['source']] = ['orders' => (int)$r['n'], 'sales' => (int)$r['s']];
        }
        return $out;
    }

    /** Online orders placed in the range by fulfilment state (voided ones counted apart). */
    private static function fulfilment(string $lo, string $hi): array {
        $out = array_fill_keys(['new', 'accepted', 'preparing', 'ready', 'completed', 'cancelled', 'voided'], 0);
        foreach (Database::rows(
            "SELECT CASE WHEN status = 'voided' THEN 'voided' ELSE fulfillment_status END AS f, COUNT(*) AS n
               FROM restaurant_orders
              WHERE tenant_id = ? AND source = 'online' AND opened_at BETWEEN ? AND ?
           GROUP BY f", [current_tenant_id(), $lo, $hi]) as $r) {
            $out[$r['f']] = (int)$r['n'];
        }
        return $out;
    }

    /** Percent change from $prev to $cur, one decimal; null when there is nothing to compare with. */
    public static function change(int $cur, int $prev): ?float {
        if ($prev === 0) return $cur === 0 ? 0.0 : null;
        return round(($cur - $prev) / abs($prev) * 100, 1);
    }

    /**
     * The tables the reports screen shows and exports, built once from two
     * summaries so the screen and the CSV files read the very same cells.
     * Each: [title, columns => [[label, type]], rows => [[…]]]; type is
     * text, int, money or pct (money in cents, pct may be null).
     */
    public static function tables(array $cur, array $prev): array {
        $MONEY = 'money'; $INT = 'int'; $PCT = 'pct'; $TEXT = 'text';
        $head = [];
        foreach ([
            ['gross_sales', __('restaurant_rep_gross', 'Gross sales'), $MONEY],
            ['discounts', __('restaurant_rep_discounts', 'Discounts'), $MONEY],
            ['service', __('restaurant_rep_service', 'Service charges'), $MONEY],
            ['tax', __('restaurant_rep_tax', 'Tax collected'), $MONEY],
            ['tips', __('restaurant_rep_tips', 'Tips'), $MONEY],
            ['tips_cash', __('restaurant_rep_tips_cash', 'Tips in cash'), $MONEY],
            ['tips_card', __('restaurant_rep_tips_card', 'Tips on card'), $MONEY],
            ['order_totals', __('restaurant_rep_totals', 'Order totals'), $MONEY],
            ['comps', __('restaurant_rep_comps', 'Comps'), $MONEY],
            ['refunds', __('restaurant_rep_refunds', 'Refunds'), $MONEY],
            ['refund_count', __('restaurant_rep_refund_count', 'Refunds (count)'), $INT],
            ['net_sales', __('restaurant_rep_net', 'Net sales'), $MONEY],
            ['orders', __('restaurant_rep_orders', 'Orders'), $INT],
            ['avg_order', __('restaurant_rep_aov', 'Average order'), $MONEY],
            ['covers', __('restaurant_rep_covers', 'Covers'), $INT],
            ['voided', __('restaurant_rep_voided', 'Voided orders'), $INT],
            ['courier_fees', __('restaurant_rep_courier_fees', 'Courier fees'), $MONEY],
        ] as [$k, $label, $type]) {
            $head[] = [$label, $cur[$k], $prev[$k], self::change((int)$cur[$k], (int)$prev[$k]), $k, $type];
        }
        $METHODS = ['cash' => __('restaurant_rep_m_cash', 'Cash'), 'terminal' => __('restaurant_rep_m_terminal', 'Card reader'),
                    'online' => __('restaurant_rep_m_online', 'Online card'), 'other' => __('restaurant_rep_m_other', 'Other'),
                    'channel' => __('restaurant_rep_m_channel', 'Paid via channel')];
        $FULFIL  = ['new' => __('restaurant_online_new', 'New'), 'accepted' => __('restaurant_online_accepted', 'Accepted'),
                    'preparing' => __('restaurant_online_preparing', 'Preparing'), 'ready' => __('restaurant_online_ready', 'Ready'),
                    'completed' => __('restaurant_online_completed', 'Completed'), 'cancelled' => __('restaurant_online_cancelled', 'Cancelled'),
                    'voided' => __('restaurant_pos_st_voided', 'Voided')];
        $SOURCES = ['pos' => __('restaurant_orders_src_pos', 'POS'), 'online' => __('restaurant_pos_online', 'Online'),
                    'channel' => __('restaurant_rep_src_channel', 'Channels')];
        $chSlugs  = array_keys($cur['channels'] + $prev['channels']);
        $chName   = fn(string $slug) => $slug === '' ? __('restaurant_rep_ch_unknown', 'Unnamed channel') : (string)(($cur['channels'][$slug] ?? $prev['channels'][$slug])['name']);
        $chGet    = fn(array $sum, string $slug, string $k) => (int)($sum['channels'][$slug][$k] ?? 0);
        $provKeys = array_keys($cur['couriers'] + $prev['couriers']);
        $provName = function (string $key): string {
            if (class_exists('RestaurantDelivery') && ($p = RestaurantDelivery::provider($key))) return $p->label();
            return $key;
        };
        $cuGet    = fn(array $sum, string $key, string $k) => (int)($sum['couriers'][$key][$k] ?? 0);
        $itemCols = [[__('restaurant_rep_item', 'Item'), $TEXT], [__('restaurant_rep_qty', 'Quantity'), $INT], [__('restaurant_rep_revenue', 'Revenue'), $MONEY]];

        return [
            'summary' => [
                'title'   => __('restaurant_rep_t_summary', 'Summary'),
                'columns' => [[__('restaurant_rep_figure', 'Figure'), $TEXT], [__('restaurant_rep_this', 'This period'), 'mixed'],
                              [__('restaurant_rep_prev', 'Previous period'), 'mixed'], [__('restaurant_rep_change', 'Change'), $PCT]],
                'rows'    => array_map(fn($h) => [$h[0], $h[1], $h[2], $h[3]], $head),
                'types'   => array_column($head, 5),
                'keys'    => array_column($head, 4),
            ],
            'payments' => [
                'title'   => __('restaurant_rep_t_methods', 'Payment methods'),
                'columns' => [[__('restaurant_rep_method', 'Method'), $TEXT], [__('restaurant_rep_count', 'Payments'), $INT],
                              [__('restaurant_rep_collected', 'Collected'), $MONEY], [__('restaurant_rep_refunds', 'Refunds'), $MONEY],
                              [__('restaurant_rep_net_col', 'Net'), $MONEY], [__('restaurant_rep_prev_net', 'Previous net'), $MONEY],
                              [__('restaurant_rep_change', 'Change'), $PCT]],
                'rows'    => array_map(fn($m) => [$METHODS[$m], $cur['methods'][$m]['count'], $cur['methods'][$m]['collected'],
                              $cur['methods'][$m]['refunds'], $cur['methods'][$m]['net'], $prev['methods'][$m]['net'],
                              self::change($cur['methods'][$m]['net'], $prev['methods'][$m]['net'])], array_keys($METHODS)),
            ],
            'items_qty' => [
                'title' => __('restaurant_rep_t_items_qty', 'Top items by quantity'), 'columns' => $itemCols,
                'rows'  => array_map(fn($r) => [$r['name'], $r['qty'], $r['revenue']], $cur['items']['by_qty']),
            ],
            'items_revenue' => [
                'title' => __('restaurant_rep_t_items_rev', 'Top items by revenue'), 'columns' => $itemCols,
                'rows'  => array_map(fn($r) => [$r['name'], $r['qty'], $r['revenue']], $cur['items']['by_revenue']),
            ],
            'categories' => [
                'title'   => __('restaurant_rep_t_categories', 'Categories'),
                'columns' => [[__('restaurant_rep_category', 'Category'), $TEXT], [__('restaurant_rep_qty', 'Quantity'), $INT], [__('restaurant_rep_revenue', 'Revenue'), $MONEY]],
                'rows'    => array_map(fn($r) => [$r['name'], $r['qty'], $r['revenue']], $cur['categories']),
            ],
            'sources' => [
                'title'   => __('restaurant_rep_t_sources', 'Sales by source'),
                'columns' => [[__('restaurant_rep_source', 'Source'), $TEXT], [__('restaurant_rep_orders', 'Orders'), $INT],
                              [__('restaurant_rep_sales', 'Sales'), $MONEY], [__('restaurant_rep_prev', 'Previous period'), $MONEY],
                              [__('restaurant_rep_change', 'Change'), $PCT]],
                'rows'    => array_map(fn($s) => [$SOURCES[$s], $cur['sources'][$s]['orders'], $cur['sources'][$s]['sales'],
                              $prev['sources'][$s]['sales'], self::change($cur['sources'][$s]['sales'], $prev['sources'][$s]['sales'])], array_keys($SOURCES)),
            ],
            'channels' => [
                'title'   => __('restaurant_rep_t_channels', 'Sales by channel'),
                'columns' => [[__('restaurant_rep_channel', 'Channel'), $TEXT], [__('restaurant_rep_orders', 'Orders'), $INT],
                              [__('restaurant_rep_sales', 'Sales'), $MONEY], [__('restaurant_rep_aov', 'Average order'), $MONEY],
                              [__('restaurant_rep_ch_cancelled', 'Cancelled'), $INT], [__('restaurant_rep_prev', 'Previous period'), $MONEY],
                              [__('restaurant_rep_change', 'Change'), $PCT]],
                'rows'    => array_map(fn($c) => [$chName($c), $chGet($cur, $c, 'orders'), $chGet($cur, $c, 'sales'),
                              $chGet($cur, $c, 'orders') > 0 ? (int) round($chGet($cur, $c, 'sales') / $chGet($cur, $c, 'orders')) : 0,
                              $chGet($cur, $c, 'cancelled'), $chGet($prev, $c, 'sales'),
                              self::change($chGet($cur, $c, 'sales'), $chGet($prev, $c, 'sales'))], $chSlugs),
            ],
            'couriers' => [
                'title'   => __('restaurant_rep_t_couriers', 'Courier deliveries'),
                'columns' => [[__('restaurant_rep_provider', 'Courier service'), $TEXT], [__('restaurant_rep_delivered', 'Delivered'), $INT],
                              [__('restaurant_rep_underway', 'Under way'), $INT], [__('restaurant_rep_fees', 'Fees'), $MONEY],
                              [__('restaurant_rep_prev', 'Previous period'), $MONEY], [__('restaurant_rep_change', 'Change'), $PCT]],
                'rows'    => array_map(fn($k) => [$provName($k), $cuGet($cur, $k, 'delivered'), $cuGet($cur, $k, 'active'), $cuGet($cur, $k, 'fees'),
                              $cuGet($prev, $k, 'fees'), self::change($cuGet($cur, $k, 'fees'), $cuGet($prev, $k, 'fees'))], $provKeys),
            ],
            'fulfilment' => [
                'title'   => __('restaurant_rep_t_fulfilment', 'Online orders by status'),
                'columns' => [[__('restaurant_rep_status', 'Status'), $TEXT], [__('restaurant_rep_orders', 'Orders'), $INT],
                              [__('restaurant_rep_prev', 'Previous period'), $INT]],
                'rows'    => array_map(fn($f) => [$FULFIL[$f], $cur['fulfilment'][$f], $prev['fulfilment'][$f]], array_keys($FULFIL)),
            ],
        ];
    }

    /** One cell as the CSV writes it: money as a plain decimal, percent with one decimal, empty for n/a. */
    public static function csvCell($value, string $type): string {
        if ($value === null) return '';
        return match ($type) {
            'money' => number_format((int)$value / 100, 2, '.', ''),
            'pct'   => number_format((float)$value, 1, '.', ''),
            default => (string)$value,
        };
    }

    /** A report table as CSV text (header row first). */
    public static function tableCsv(array $table): string {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, array_map(fn($c) => $c[0], $table['columns']), ',', '"', '');
        foreach ($table['rows'] as $i => $row) {
            $cells = [];
            foreach ($row as $j => $v) {
                $type = $table['columns'][$j][1];
                if ($type === 'mixed') $type = $table['types'][$i] ?? 'text';
                $cells[] = self::csvSafe(self::csvCell($v, $type));
            }
            fputcsv($fh, $cells, ',', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /** Stop a spreadsheet running a cell as a formula (names and notes are user text). */
    public static function csvSafe(string $s): string {
        return ($s !== '' && in_array($s[0], ['=', '+', '@', "\t", "\r"], true)) || ($s !== '' && $s[0] === '-' && !is_numeric($s))
            ? "'" . $s : $s;
    }

    // ── Payments reconciliation ────────────────────────────

    public const PAY_STATUSES = ['paid', 'partial', 'refunded', 'pending', 'failed'];
    public const PAY_METHODS  = ['cash', 'terminal', 'online', 'other', 'channel'];

    /**
     * Payments for the reconciliation list. $f: from, to (Y-m-d, by the
     * payment's date), method (a PAY_METHODS key), status (a PAY_STATUSES
     * key; partial = paid with part refunded), source (pos|online), q (order
     * number, or a Stripe PaymentIntent id). Returns ['rows', 'count',
     * 'totals' => [gross, refunded, net, settled]] where the totals cover the
     * whole filter (not just the page) and only settled payments (paid,
     * partly or fully refunded): gross = amount + tip, net = gross − refunded.
     */
    public static function payments(array $f, int $limit = 50, int $offset = 0): array {
        $where = ['p.tenant_id = ?']; $args = [current_tenant_id()];
        $date  = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
        if ($date($f['from'] ?? null)) { $where[] = 'p.created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }
        if ($date($f['to'] ?? null))   { $where[] = 'p.created_at <= ?'; $args[] = $f['to'] . ' 23:59:59'; }
        if (in_array($f['method'] ?? '', self::PAY_METHODS, true)) { $where[] = self::METHOD_SQL . ' = ?'; $args[] = $f['method']; }
        switch ($f['status'] ?? '') {
            case 'paid':     $where[] = "p.status = 'paid' AND p.refunded_cents = 0"; break;
            case 'partial':  $where[] = "p.status = 'paid' AND p.refunded_cents > 0"; break;
            case 'refunded': case 'pending': case 'failed':
                $where[] = 'p.status = ?'; $args[] = $f['status']; break;
        }
        if (in_array($f['source'] ?? '', ['pos', 'online', 'channel'], true)) { $where[] = 'o.source = ?'; $args[] = $f['source']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(o.order_no LIKE ? OR p.stripe_pi_id = ? OR p.stripe_charge_id = ?)';
            array_push($args, '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%', $q, $q);
        }
        $from = "FROM restaurant_payments p
                 JOIN restaurant_orders o ON o.id = p.order_id AND o.tenant_id = p.tenant_id
                 LEFT JOIN restaurant_readers r ON r.id = p.reader_id AND r.tenant_id = p.tenant_id
                WHERE " . implode(' AND ', $where);

        $t = Database::row(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') THEN 1 ELSE 0 END),0) AS settled,
                    COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') THEN p.amount_cents + p.tip_cents ELSE 0 END),0) AS gross,
                    COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') THEN p.refunded_cents ELSE 0 END),0) AS refunded
               {$from}", $args);
        $rows = Database::rows(
            "SELECT p.id, p.created_at, p.order_id, o.order_no, o.source, p.check_no, " . self::METHOD_SQL . " AS method,
                    p.amount_cents, p.tip_cents, p.refunded_cents, p.status, p.stripe_pi_id, p.central_charge_id,
                    r.label AS reader
               {$from} ORDER BY p.created_at DESC, p.id DESC LIMIT " . max(1, $limit) . " OFFSET " . max(0, $offset), $args);
        foreach ($rows as &$r) {
            $settled = in_array($r['status'], ['paid', 'refunded'], true);
            $r['net_cents'] = $settled ? (int)$r['amount_cents'] + (int)$r['tip_cents'] - (int)$r['refunded_cents'] : 0;
            $r['state']     = $r['status'] === 'paid' && (int)$r['refunded_cents'] > 0 ? 'partial' : $r['status'];
        }
        unset($r);
        return ['rows' => $rows, 'count' => (int)$t['n'], 'totals' => [
            'gross' => (int)$t['gross'], 'refunded' => (int)$t['refunded'],
            'net' => (int)$t['gross'] - (int)$t['refunded'], 'settled' => (int)$t['settled']]];
    }

    /** The filtered payments list as CSV (every row, not one page). */
    public static function paymentsCsv(array $f): string {
        $res = self::payments($f, 100000, 0);
        $fh  = fopen('php://temp', 'w+');
        fputcsv($fh, ['date', 'order_no', 'check_no', 'method', 'amount', 'tip', 'gross', 'refunded', 'net', 'status',
                      'stripe_pi_id', 'central_charge_id', 'reader']);
        $m = fn(int $c) => number_format($c / 100, 2, '.', '');
        foreach ($res['rows'] as $r) {
            fputcsv($fh, array_map([self::class, 'csvSafe'], [
                (string)$r['created_at'], (string)$r['order_no'], (string)$r['check_no'], (string)$r['method'],
                $m((int)$r['amount_cents']), $m((int)$r['tip_cents']), $m((int)$r['amount_cents'] + (int)$r['tip_cents']),
                $m((int)$r['refunded_cents']), $m((int)$r['net_cents']),
                (string)$r['state'], (string)($r['stripe_pi_id'] ?? ''), (string)($r['central_charge_id'] ?? ''), (string)($r['reader'] ?? ''),
            ]));
        }
        // Totals cover settled payments only, as on screen.
        fputcsv($fh, ['TOTAL', '', '', '', '', '', $m($res['totals']['gross']), $m($res['totals']['refunded']), $m($res['totals']['net']), '', '', '', ''], ',', '"', '');
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }
}
