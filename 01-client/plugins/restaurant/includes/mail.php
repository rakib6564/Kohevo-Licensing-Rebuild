<?php
/**
 * Restaurant — order emails for online orders, sent through the core Mailer (Settings > Email).
 *
 *  - Customer confirmation: when an online order is paid (any gateway, webhook or return page), or placed to pay at
 *    pickup. Only to the email typed at that checkout (restaurant_orders.contact_email), when one was given.
 *  - Kitchen "new order": the same moment, to the addresses in restaurant.online_notify_emails (comma or newline
 *    separated; empty = off), laid out for the kitchen.
 *  - Customer "ready": when staff move the order to Ready on the online board, behind restaurant.online_ready_email
 *    ('1' = on, default off).
 *
 * Each email is sent at most once per order: a stamp column on restaurant_orders (customer_emailed_at,
 * kitchen_emailed_at, ready_emailed_at) is claimed with one UPDATE ... WHERE stamp IS NULL before sending, so the
 * Stripe webhook and the return page settling the same order (or a re-paid order) can never send twice. Channel and
 * POS orders get no emails.
 *
 * Tests swap the transport (RestaurantMail::$transport) to capture messages instead of sending them.
 */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }

final class RestaurantMail
{
    /** @var null|callable(string $to, string $subject, string $html, string $toName): bool  null = the core Mailer */
    public static $transport = null;

    private static bool $wired = false;

    /** Listen for paid and ready orders (once per process: boot() may run more than once). */
    public static function wire(): void
    {
        if (self::$wired) return;
        self::$wired = true;
        Hook::addAction('restaurant_order_paid', [self::class, 'onOrderPaid'], 20, 1);
        Hook::addAction('restaurant_fulfillment_changed', [self::class, 'onFulfillmentChanged'], 20, 3);
    }

    /** The restaurant_order_paid listener: confirmation + kitchen email for an online order. */
    public static function onOrderPaid($orderId): void
    {
        self::orderConfirmed((int) $orderId);
    }

    /** The restaurant_fulfillment_changed listener: the optional "ready" email. */
    public static function onFulfillmentChanged($orderId, $status = '', $was = ''): void
    {
        if ((string) $status === 'ready') self::orderReady((int) $orderId);
    }

    /**
     * An online order is confirmed (paid, or placed to pay at pickup): tell the customer and the kitchen, each once.
     *
     * @return array{customer:bool, kitchen:int}  what was sent now (false/0 when already sent or not wanted)
     */
    public static function orderConfirmed(int $orderId): array
    {
        $sent = ['customer' => false, 'kitchen' => 0];
        try {
            $order = RestaurantAPI::getOrder($orderId);
            if (!$order || (string) $order['source'] !== 'online' || (string) $order['status'] === 'voided') return $sent;

            // Each stamp is claimed at this moment even when there is nobody to email, so a later replay (a re-paid
            // order, the setting switched on afterwards) never sends an old order's email.
            $email = self::customerEmail($order);
            if (self::claim($orderId, 'customer_emailed_at') && $email !== '') {
                $sent['customer'] = self::send($email, self::customerSubject($order), self::customerHtml($order), (string) ($order['customer_name'] ?? ''));
            }

            $to = self::kitchenAddresses();
            if (self::claim($orderId, 'kitchen_emailed_at') && $to !== []) {
                $subject = self::kitchenSubject($order);
                $html = self::kitchenHtml($order);
                foreach ($to as $addr) {
                    if (self::send($addr, $subject, $html)) $sent['kitchen']++;
                }
            }
        } catch (\Throwable $e) {
            slate_log('Restaurant: order email failed: ' . $e->getMessage(), 'warning');
        }
        return $sent;
    }

    /** Staff marked an online order ready: tell the customer once, when the setting is on. */
    public static function orderReady(int $orderId): bool
    {
        try {
            if ((string) RestaurantAPI::setting('online_ready_email', '0') !== '1') return false;
            $order = RestaurantAPI::getOrder($orderId);
            if (!$order || (string) $order['source'] !== 'online') return false;
            $email = self::customerEmail($order);
            if ($email === '' || !self::claim($orderId, 'ready_emailed_at')) return false;
            return self::send($email, self::readySubject($order), self::readyHtml($order), (string) ($order['customer_name'] ?? ''));
        } catch (\Throwable $e) {
            slate_log('Restaurant: ready email failed: ' . $e->getMessage(), 'warning');
            return false;
        }
    }

    /** The email typed at checkout for this order ('' when none): never one guessed from an earlier order. */
    public static function customerEmail(array $order): string
    {
        $email = trim((string) ($order['contact_email'] ?? ''));
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * The kitchen addresses from the setting, cleaned: "a@x.com, b@y.com" or one per line. Invalid ones are dropped.
     *
     * @return list<string>
     */
    public static function kitchenAddresses(?string $raw = null): array
    {
        $raw ??= (string) RestaurantAPI::setting('online_notify_emails', '');
        return self::parseAddresses($raw)['ok'];
    }

    /** @return array{ok:list<string>, bad:list<string>} */
    public static function parseAddresses(string $raw): array
    {
        $ok = [];
        $bad = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $a) {
            $a = trim($a);
            if ($a === '') continue;
            if (filter_var($a, FILTER_VALIDATE_EMAIL)) $ok[strtolower($a)] = $a; else $bad[] = $a;
        }
        return ['ok' => array_values($ok), 'bad' => $bad];
    }

    /** Claim a stamp: true for exactly one caller, ever, per order and stamp. */
    private static function claim(int $orderId, string $column): bool
    {
        if (!in_array($column, ['customer_emailed_at', 'kitchen_emailed_at', 'ready_emailed_at'], true)) return false;
        return Database::query(
            "UPDATE restaurant_orders SET `{$column}` = ? WHERE id = ? AND tenant_id = ? AND `{$column}` IS NULL",
            [slate_db_now(), $orderId, current_tenant_id()])->rowCount() === 1;
    }

    private static function send(string $to, string $subject, string $html, string $toName = ''): bool
    {
        if (self::$transport !== null) return (bool) (self::$transport)($to, $subject, $html, $toName);
        if (!class_exists('Mailer')) return false;
        $ok = Mailer::send($to, $subject, $html, $toName);
        if (!$ok) slate_log('Restaurant: email to ' . $to . ' was not sent (' . $subject . ')', 'warning');
        return $ok;
    }

    // ── Content ──────────────────────────────────────────────

    private const BRANDED = '\\Slate\\Services\\Notifications\\BrandedEmail';
    private const FONT = '-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,Helvetica,Arial,sans-serif';

    /**
     * The whole email in the site's branded shell (BrandedEmail: logo, hero image band, accent colour, dark mode,
     * business address footer). $blocks are trusted HTML pieces, each its own padded row; a block starting with
     * "<table" is a full-width table row (the details table).
     */
    private static function page(string $label, string $heading, array $blocks, string $preheader, string $ref): string
    {
        $cls = self::BRANDED;
        // One left edge (28px, the shell's .fe-pad) and one gap (22px) for every section, so the heading, the details,
        // the items, the totals and the button line up. The label sits in the header band over the hero image.
        $blocks = array_values(array_filter($blocks, static fn($b): bool => $b !== ''));
        $rows = '<tr><td class="fe-pad" style="padding:28px 28px 18px;">'
            . '<h1 class="fe-title" style="margin:0;font:700 22px/1.25 ' . self::FONT . ';color:#0f172a;letter-spacing:-0.02em;">' . e($heading) . '</h1>'
            . '</td></tr>';
        foreach ($blocks as $i => $b) {
            $bottom = $i === count($blocks) - 1 ? 30 : 22;
            $rows .= '<tr><td class="fe-pad fe-text" style="padding:0 28px ' . $bottom . 'px;font:400 15px/1.6 ' . self::FONT . ';color:#334155;">' . $b . '</td></tr>';
        }
        return $cls::shell([
            'title'        => $heading,
            'preheader'    => $preheader,
            'header_label' => $label,
            'sender_label' => __('restaurant_mail_sender', 'online ordering'),
            'footer_ref'   => $ref,
        ], $rows);
    }

    private static function button(string $url, string $label): string
    {
        $cls = self::BRANDED;
        [, $accent, , $on] = $cls::accent();
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0;"><tr>'
            . '<td bgcolor="' . e($accent) . '" style="border-radius:10px;">'
            . '<a href="' . e($url) . '" style="display:inline-block;padding:13px 22px;font:700 14px/1 ' . self::FONT . ';color:' . $on . ';text-decoration:none;border-radius:10px;">'
            . e($label) . ' &rarr;</a></td></tr></table>';
    }

    /** Label / value rows between hairlines, on the same left edge as the heading. $rows: [label, trusted valueHtml]. */
    private static function details(array $rows): string
    {
        $out = '';
        foreach ($rows as $i => [$label, $valueHtml]) {
            $line = $i === 0 ? 'border-top:1px solid #e6e9ef;' : '';
            $out .= '<tr>'
                . '<td class="fe-label" valign="top" style="' . $line . 'border-bottom:1px solid #e6e9ef;padding:12px 16px 12px 0;width:32%;vertical-align:top;font:600 13px/1.5 ' . self::FONT . ';color:#64748b;">' . e($label) . '</td>'
                . '<td class="fe-val" valign="top" style="' . $line . 'border-bottom:1px solid #e6e9ef;padding:12px 0;vertical-align:top;font:400 15px/1.5 ' . self::FONT . ';color:#0f172a;">' . $valueHtml . '</td>'
                . '</tr>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' . $out . '</table>';
    }

    private static function hello(array $order): string
    {
        $name = trim((string) ($order['customer_name'] ?? ''));
        return $name !== '' ? sprintf(__('email_greeting', 'Hello %s,'), e($name)) : e(__('email_greeting_generic', 'Hello,'));
    }

    private static function site(): string
    {
        $biz = trim((string) RestaurantAPI::setting('biz_name', ''));
        return $biz !== '' ? $biz : ((string) (Database::setting('site_name') ?: 'Kohevo'));
    }

    /** The customer's status page. */
    public static function statusUrl(array $order): string
    {
        return rtrim(SLATE_URL, '/') . '/order/confirm?token=' . rawurlencode((string) ($order['public_token'] ?? ''));
    }

    private static function isPaid(array $order): bool
    {
        return (int) $order['total_cents'] > 0 && (int) $order['paid_cents'] >= (int) $order['total_cents'];
    }

    /** The delivery address an online order carries in its notes ("Deliver to: ... · note"), and the rest. */
    public static function splitNotes(array $order): array
    {
        $notes = trim((string) ($order['notes'] ?? ''));
        if ((string) $order['type'] === 'delivery' && str_starts_with($notes, 'Deliver to: ')) {
            $rest = substr($notes, strlen('Deliver to: '));
            $parts = explode(' · ', $rest, 2);
            return ['address' => trim($parts[0]), 'notes' => trim($parts[1] ?? '')];
        }
        return ['address' => '', 'notes' => $notes];
    }

    /** The restaurant's own address on one line. */
    private static function pickupAddress(): string
    {
        $s = RestaurantAPI::settings();
        return trim(implode(', ', array_filter([$s['addr_line1'], $s['addr_line2'], trim($s['addr_city'] . ' ' . $s['addr_state'] . ' ' . $s['addr_postal'])])));
    }

    /** "Pickup" / "Delivery" with the time. */
    private static function whenText(array $order): string
    {
        $kind = (string) $order['type'] === 'delivery' ? __('restaurant_mail_delivery', 'Delivery') : __('restaurant_mail_pickup', 'Pickup');
        $time = trim((string) ($order['requested_time'] ?? ''));
        return $time !== '' ? $kind . ' · ' . $time : $kind;
    }

    /** The order's lines as an email table: qty, name, modifiers, line note, price. */
    private static function itemsHtml(array $order, bool $kitchen): string
    {
        $rows = '';
        $first = true;
        foreach ($order['items'] as $it) {
            if ((string) $it['kitchen_status'] === 'void') continue;
            $mods = array_map(static fn(array $m): string => (string) $m['name_snapshot'], $it['modifiers'] ?? []);
            $sub = $mods !== [] ? '<div style="color:#64748b;font-size:13px;font-weight:400;margin-top:3px;">' . e(implode(', ', $mods)) . '</div>' : '';
            $note = trim((string) ($it['notes'] ?? ''));
            if ($note !== '') $sub .= '<div style="color:#9a2310;font-size:13px;font-weight:600;margin-top:3px;">' . e(__('restaurant_mail_note', 'Note')) . ': ' . e($note) . '</div>';
            $cell = 'border-bottom:1px solid #e6e9ef;' . ($first ? 'border-top:1px solid #e6e9ef;' : '') . 'padding:12px 0;vertical-align:top;';
            $size = $kitchen ? 'font-size:17px;' : 'font-size:15px;';
            $rows .= '<tr>'
                . '<td class="fe-val" valign="top" style="' . $cell . 'width:44px;white-space:nowrap;font-weight:800;' . $size . '">' . (int) $it['qty'] . '×</td>'
                . '<td class="fe-val" valign="top" style="' . $cell . 'font-weight:700;' . $size . '">' . e((string) $it['name_snapshot']) . $sub . '</td>'
                . ($kitchen ? '' : '<td class="fe-val" valign="top" align="right" style="' . $cell . 'padding-left:12px;text-align:right;white-space:nowrap;' . $size . '">' . e(RestaurantAPI::money((int) $it['line_total_cents'])) . '</td>')
                . '</tr>';
            $first = false;
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:' . self::FONT . ';color:#0f172a;">' . $rows . '</table>';
    }

    /** Subtotal, service, tax, tip, total, then paid or due. */
    private static function totalsHtml(array $order): string
    {
        $rows = [[__('restaurant_mail_subtotal', 'Subtotal'), (int) $order['subtotal_cents']]];
        if ((int) $order['discount_cents'] > 0) $rows[] = [__('restaurant_mail_discount', 'Discount'), -(int) $order['discount_cents']];
        if ((int) $order['service_charge_cents'] > 0) $rows[] = [__('restaurant_mail_service', 'Service'), (int) $order['service_charge_cents']];
        $rows[] = [__('restaurant_mail_tax', 'Tax'), (int) $order['tax_cents']];
        if ((int) $order['tip_cents'] > 0) $rows[] = [__('restaurant_mail_tip', 'Tip'), (int) $order['tip_cents']];
        $out = '';
        foreach ($rows as [$label, $cents]) {
            $out .= '<tr><td class="fe-label" style="padding:3px 0;color:#64748b;">' . e($label) . '</td><td class="fe-val" style="padding:3px 0;text-align:right;color:#0f172a;">'
                  . ($cents < 0 ? '−' . e(RestaurantAPI::money(-$cents)) : e(RestaurantAPI::money($cents))) . '</td></tr>';
        }
        $out .= '<tr><td class="fe-val" style="padding:9px 0 3px;font-weight:800;font-size:17px;color:#0f172a;">' . e(__('restaurant_mail_total', 'Total')) . '</td>'
              . '<td class="fe-val" style="padding:9px 0 3px;text-align:right;font-weight:800;font-size:17px;color:#0f172a;">' . e(RestaurantAPI::money((int) $order['total_cents'])) . '</td></tr>';
        $out .= '<tr><td class="fe-label" style="padding:3px 0;color:#64748b;">' . e(self::isPaid($order) ? __('restaurant_mail_paid', 'Paid') : __('restaurant_mail_due', 'Due at pickup')) . '</td>'
              . '<td class="fe-val" style="padding:3px 0;text-align:right;color:#0f172a;">' . e(RestaurantAPI::money(self::isPaid($order) ? (int) $order['paid_cents'] : (int) $order['total_cents'])) . '</td></tr>';
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font:400 14px/1.5 ' . self::FONT . ';color:#334155;margin:0;">' . $out . '</table>';
    }

    public static function customerSubject(array $order): string
    {
        return sprintf(__('restaurant_mail_cust_subject', '%1$s — order #%2$s confirmed'), self::site(), $order['order_no']);
    }

    public static function customerHtml(array $order): string
    {
        $n = self::splitNotes($order);
        $rows = [
            [__('restaurant_mail_order', 'Order'), '#' . e((string) $order['order_no'])],
            [__('restaurant_mail_when', 'When'), '<strong>' . e(self::whenText($order)) . '</strong>'],
        ];
        if ($n['address'] !== '') $rows[] = [__('restaurant_mail_deliver_to', 'Deliver to'), e($n['address'])];
        elseif (($addr = self::pickupAddress()) !== '') $rows[] = [__('restaurant_mail_pickup_at', 'Pickup at'), e($addr)];
        if ($n['notes'] !== '') $rows[] = [__('restaurant_mail_notes', 'Notes'), e($n['notes'])];
        $intro = self::isPaid($order)
            ? __('restaurant_mail_cust_paid', 'Thank you — we received your payment and your order is in.')
            : __('restaurant_mail_cust_due', 'Thank you — your order is in. Please pay when you pick it up.');
        return self::page(
            __('restaurant_mail_cust_label', 'Order confirmed'),
            sprintf(__('restaurant_mail_cust_heading', 'Order #%s confirmed'), $order['order_no']),
            [self::hello($order) . '<br>' . e($intro), self::details($rows), self::itemsHtml($order, false), self::totalsHtml($order),
             self::button(self::statusUrl($order), __('restaurant_mail_status_btn', 'See your order status'))],
            self::whenText($order), '#' . $order['order_no']);
    }

    public static function kitchenSubject(array $order): string
    {
        return sprintf(__('restaurant_mail_kitchen_subject', 'New online order #%1$s · %2$s'), $order['order_no'], self::whenText($order));
    }

    public static function kitchenHtml(array $order): string
    {
        $n = self::splitNotes($order);
        $paid = self::isPaid($order);
        $who = trim((string) ($order['customer_name'] ?? ''));
        $contact = array_filter([trim((string) ($order['customer_phone'] ?? '')), self::customerEmail($order)]);
        $rows = [
            [__('restaurant_mail_when', 'When'), '<strong style="font-size:16px;">' . e(self::whenText($order)) . '</strong>'],
            [__('restaurant_mail_customer', 'Customer'), e($who !== '' ? $who : '—') . ($contact ? '<br>' . implode('<br>', array_map('e', $contact)) : '')],
        ];
        if ($n['address'] !== '') $rows[] = [__('restaurant_mail_deliver_to', 'Deliver to'), e($n['address'])];
        if ($n['notes'] !== '') $rows[] = [__('restaurant_mail_notes', 'Notes'), '<strong style="color:#9a2310;">' . e($n['notes']) . '</strong>'];
        $rows[] = [__('restaurant_mail_payment', 'Payment'), $paid
            ? '<strong>' . e(sprintf(__('restaurant_mail_k_paid', 'PAID online · %s'), RestaurantAPI::money((int) $order['paid_cents']))) . '</strong>'
            : '<strong style="color:#9a2310;">' . e(sprintf(__('restaurant_mail_k_due', 'DUE at pickup · %s'), RestaurantAPI::money((int) $order['total_cents']))) . '</strong>'];
        $board = function_exists('plugin_url') ? plugin_url('restaurant', 'admin/online.php') : rtrim(SLATE_URL, '/') . '/plugins/restaurant/admin/online.php';
        return self::page(
            __('restaurant_mail_kitchen_label', 'New online order'),
            sprintf(__('restaurant_mail_kitchen_heading', 'New order #%s'), $order['order_no']),
            [self::details($rows), self::itemsHtml($order, true), self::button($board, __('restaurant_mail_board_btn', 'Open the online orders board'))],
            self::whenText($order), '#' . $order['order_no']);
    }

    public static function readySubject(array $order): string
    {
        return sprintf(__('restaurant_mail_ready_subject', '%1$s — order #%2$s is ready'), self::site(), $order['order_no']);
    }

    public static function readyHtml(array $order): string
    {
        $delivery = (string) $order['type'] === 'delivery';
        $addr = self::pickupAddress();
        $text = $delivery
            ? __('restaurant_mail_ready_delivery', 'Your order is ready and will be on its way shortly.')
            : ($addr !== '' ? sprintf(__('restaurant_mail_ready_pickup_at', 'Your order is ready to pick up at %s.'), $addr)
                            : __('restaurant_mail_ready_pickup', 'Your order is ready to pick up.'));
        if (!self::isPaid($order)) $text .= ' ' . sprintf(__('restaurant_mail_ready_due', 'Amount due: %s.'), RestaurantAPI::money((int) $order['total_cents']));
        return self::page(
            __('restaurant_mail_ready_label', 'Order ready'),
            sprintf(__('restaurant_mail_ready_heading', 'Order #%s is ready'), $order['order_no']),
            [self::hello($order) . '<br>' . e($text), self::button(self::statusUrl($order), __('restaurant_mail_status_btn', 'See your order status'))],
            $text, '#' . $order['order_no']);
    }
}
