<?php
/**
 * Restaurant — Deliverect channel adapter.
 *
 * Deliverect is an aggregator: it holds the restaurant's marketplace
 * connections (Uber Eats, DoorDash, …) and forwards their orders to a POS.
 * This adapter reads Deliverect's POS order webhook into the canonical shape
 * in includes/channels.php and reports our fulfilment back in Deliverect's
 * terms. Everything else (idempotency, SKU mapping, all-or-nothing order
 * build, staff alerts) is the shared channel seam.
 *
 * Built from Deliverect's public developer docs (developers.deliverect.com,
 * checked 2 Nov 2026) and tested against recorded-style fixtures only: no
 * live Deliverect account was used. Deliverect sends orders only to POS
 * integrations it has registered; in staging it signs with the channelLink
 * ID, and certified integrations get a production HMAC secret.
 *
 * IN — Deliverect POSTs the order to our channel URL with
 *   x-server-authorization-hmac-sha256: <hex HMAC-SHA256 of the raw body>
 * Amounts are integers with `decimalDigits` decimals (2 = cents). Fields we
 * read: _id (our external order ID: unique, and the ID status updates
 * use), channelOrderDisplayId, status (100 = the channel cancelled),
 * orderType (1 pickup, 2 delivery, 3 eat-in, 4 curbside), _created,
 * pickupTime, customer {name, phoneNumber, email, note}, deliveryAddress
 * {street, extraAddressInfo, postalCode, city}, courier.deliveryBy, table,
 * note, testOrder, orderIsAlreadyPaid (the board then shows "Paid via …"
 * instead of "Due at pickup"), payment {amount, due}, the channel
 * fees (deliveryCost, serviceCharge, bagFee, smallOrderFee, fixedCost, tip,
 * driverTip, discountTotal) and items[] {plu, quantity, remark, subItems[]
 * {plu, name, quantity, subItems[]…}}. Every sub-item at any depth becomes
 * a modifier SKU on its top-level item. Our lines hold each modifier once,
 * so a sub-item ordered more than once is taken once and written on the
 * line ("2× Extra sauce") for the kitchen; the total check then flags the
 * order if the price differs.
 *
 * OUT — POST {api}/orderStatus/{_id} with a bearer token from
 * POST {api}/oauth/token (client credentials, cached until it expires):
 *   {"orderId", "status", "reason", "timeStamp", "receiptId"}
 * using Deliverect's POS codes: accepted 20, preparing 50, ready 70,
 * completed 90, cancelled 110, and 120 (Failed) for an order we refused.
 */

class RestaurantDeliverectAdapter implements RestaurantChannelAdapter {

    public const API_BASES = ['staging' => 'https://api.staging.deliverect.com', 'production' => 'https://api.deliverect.com'];
    public const STATUS_CODES = ['accepted' => 20, 'preparing' => 50, 'ready' => 70, 'completed' => 90, 'cancelled' => 110, 'failed' => 120];
    public const SIGNATURE_HEADER = 'x-server-authorization-hmac-sha256';

    /** Tokens for this process when they cannot be stored (no app secret), by channel slug. */
    private static array $tokens = [];

    public function verify(string $raw, array $headers, string $secret): bool {
        $given = strtolower(trim((string)($headers[self::SIGNATURE_HEADER] ?? '')));
        return $given !== '' && hash_equals(hash_hmac('sha256', $raw, $secret), $given);
    }

    public function toCanonical(array $p): array {
        $id = trim((string)($p['_id'] ?? ''));
        if ((int)($p['status'] ?? 0) === 100) {
            return ['external_order_id' => $id, 'action' => 'cancel', 'reason' => __('restaurant_dl_cancelled', 'Cancelled by the ordering channel')];
        }
        $digits = max(0, min(4, (int)($p['decimalDigits'] ?? 2)));
        $cents = fn($v) => (int) round(((float)$v) * (10 ** (2 - $digits)));

        $items = [];
        foreach ((array)($p['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $mods = []; $extra = [];
            self::flatten((array)($it['subItems'] ?? []), $mods, $extra);
            $items[] = ['external_sku' => trim((string)($it['plu'] ?? '')), 'qty' => max(1, (int)($it['quantity'] ?? 1)),
                        'modifiers' => $mods, 'notes' => implode(' · ', array_filter(array_merge($extra, [trim((string)($it['remark'] ?? ''))])))];
        }

        $type = (int)($p['orderType'] ?? 1);
        $cust = (array)($p['customer'] ?? []);
        $addr = (array)($p['deliveryAddress'] ?? []);
        $pay  = (array)($p['payment'] ?? []);
        $notes = [];
        $display = trim((string)($p['channelOrderDisplayId'] ?? ''));
        if ($display !== '') $notes[] = sprintf(__('restaurant_dl_ref', 'Deliverect #%s'), $display);
        if (!empty($p['testOrder'])) $notes[] = __('restaurant_dl_test', 'TEST ORDER');
        if ($type === 3) $notes[] = trim(__('restaurant_dl_eatin', 'Eat-in') . ' ' . (string)($p['table'] ?? ''));
        if ($type === 4) $notes[] = __('restaurant_dl_curbside', 'Curbside pickup');
        $by = trim((string)($p['courier']['deliveryBy'] ?? ''));
        if ($type === 2 && $by !== '' && strtolower($by) !== 'restaurant') $notes[] = sprintf(__('restaurant_dl_courier', 'Courier: %s'), $by);
        if (array_key_exists('orderIsAlreadyPaid', $p) && !$p['orderIsAlreadyPaid']) {
            $due = $cents($pay['due'] ?? ($pay['amount'] ?? 0));
            $notes[] = sprintf(__('restaurant_dl_collect', 'Not paid: collect %s'), RestaurantAPI::money($due));
        }
        foreach ([$cust['note'] ?? '', $p['note'] ?? ''] as $n) if (trim((string)$n) !== '') $notes[] = trim((string)$n);

        // What the channel charged for the food: the amount paid less the
        // channel's own fees and tips, with its discounts added back (they
        // are not ours to give). This is what our total should match.
        $claimed = null;
        if (isset($pay['amount'])) {
            $claimed = $cents($pay['amount']);
            foreach (['deliveryCost', 'serviceCharge', 'bagFee', 'smallOrderFee', 'fixedCost', 'tip', 'driverTip'] as $k) $claimed -= $cents($p[$k] ?? 0);
            $claimed += abs($cents($p['discountTotal'] ?? 0));
        }

        return [
            'external_order_id' => $id,
            'placed_at'         => (string)($p['_created'] ?? ''),
            'ready_at'          => (string)($p['pickupTime'] ?? ''),
            'type'              => $type === 2 ? 'delivery' : 'pickup',
            'customer'          => ['name' => (string)($cust['name'] ?? ''), 'phone' => (string)($cust['phoneNumber'] ?? ''), 'email' => (string)($cust['email'] ?? '')],
            'delivery_address'  => $type === 2 && $addr ? ['line1' => (string)($addr['street'] ?? ''), 'line2' => (string)($addr['extraAddressInfo'] ?? ''),
                                                           'city' => (string)($addr['city'] ?? ''), 'postal_code' => (string)($addr['postalCode'] ?? '')] : null,
            'items'             => $items,
            'totals'            => $claimed !== null ? ['total_cents' => $claimed] : [],
            'paid'              => array_key_exists('orderIsAlreadyPaid', $p) ? (bool)$p['orderIsAlreadyPaid'] : null,
            'notes'             => implode(' · ', $notes),
        ];
    }

    /** Sub-items at every depth, once each; one ordered more than once is noted as "N× name". */
    private static function flatten(array $subs, array &$out, array &$notes): void {
        foreach ($subs as $s) {
            if (!is_array($s)) continue;
            $sku = trim((string)($s['plu'] ?? ''));
            $out[] = ['external_sku' => $sku];
            $n = (int)($s['quantity'] ?? 1);
            if ($n > 1) $notes[] = $n . '× ' . (trim((string)($s['name'] ?? '')) ?: $sku);
            self::flatten((array)($s['subItems'] ?? []), $out, $notes);
        }
    }

    public function onFulfillmentChanged(array $order, array $intake, string $status): void {
        if (!isset(self::STATUS_CODES[$status])) return;
        $this->send($intake, $status, (string)($order['order_no'] ?? ''), '');
    }

    /** Deliverect asks for Failed (120) whenever the POS cannot take an order, so the marketplace hears of it. */
    public function onRejected(array $intake, string $reason): void {
        $this->send($intake, 'failed', '', $reason);
    }

    private function send(array $intake, string $status, string $receipt, string $reason): void {
        $slug = (string)$intake['channel'];
        $cfg = RestaurantChannels::config($slug);
        $base = rtrim((string)($cfg['api_base'] ?? ''), '/');
        if ($base === '' || trim((string)($cfg['client_id'] ?? '')) === '' || RestaurantChannels::unseal((string)($cfg['client_secret'] ?? '')) === '') {
            RestaurantChannels::logEvent((int)$intake['id'], $status, null, 'Deliverect API keys not set');
            return;
        }
        $body = json_encode(['orderId' => (string)$intake['external_order_id'], 'status' => self::STATUS_CODES[$status],
            'reason' => mb_substr($reason, 0, 250), 'timeStamp' => gmdate('Y-m-d\TH:i:s\Z'), 'receiptId' => $receipt], JSON_UNESCAPED_SLASHES);
        $url = $base . '/orderStatus/' . rawurlencode((string)$intake['external_order_id']);
        for ($try = 0; $try < 2; $try++) {
            [$token, $err] = self::token($slug, $try > 0);
            if ($token === '') { RestaurantChannels::logEvent((int)$intake['id'], $status, null, $err); return; }
            [$code, , $err] = RestaurantChannels::postJson($url, (string)$body, ['Authorization: Bearer ' . $token]);
            if ($code !== 401) break;   // an expired or revoked token: fetch a new one once
        }
        RestaurantChannels::logEvent((int)$intake['id'], $status, $code, $err);
    }

    /** A bearer token for the channel's Deliverect API keys: [token, error]. Cached until a minute before it expires. */
    public static function token(string $slug, bool $fresh = false): array {
        $cfg = RestaurantChannels::config($slug);
        if (!$fresh) {
            $cached = self::$tokens[$slug] ?? ['t' => RestaurantChannels::unseal((string)($cfg['token'] ?? '')), 'exp' => (int)($cfg['token_expires'] ?? 0)];
            if ($cached['t'] !== '' && $cached['exp'] > time() + 60) return [$cached['t'], null];
        }
        $base = rtrim((string)($cfg['api_base'] ?? ''), '/');
        [$code, $json, $err] = RestaurantChannels::postJson($base . '/oauth/token', (string) json_encode([
            'client_id' => (string)($cfg['client_id'] ?? ''), 'client_secret' => RestaurantChannels::unseal((string)($cfg['client_secret'] ?? '')),
            'audience' => $base, 'grant_type' => 'client_credentials']));
        $token = (string)($json['access_token'] ?? '');
        if ($code !== 200 || $token === '') return ['', 'Deliverect token: ' . ($err ?: 'no access_token')];
        $exp = isset($json['expires_at']) ? (int)$json['expires_at'] : time() + max(60, (int)($json['expires_in'] ?? 3600));
        self::$tokens[$slug] = ['t' => $token, 'exp' => $exp];
        try { RestaurantChannels::saveConfig($slug, ['token' => RestaurantChannels::seal($token), 'token_expires' => $exp]); }
        catch (\RuntimeException $e) { /* no app secret: the token lives for this request only */ }
        return [$token, null];
    }

    /**
     * Save the Deliverect API settings for a channel. $env is staging or
     * production; an empty client secret keeps the stored one. Changing
     * them drops any cached token.
     */
    public static function saveSettings(string $slug, string $env, string $clientId, string $clientSecret): void {
        if (!isset(self::API_BASES[$env])) throw new \RuntimeException(__('restaurant_dl_err_env', 'Pick staging or production.'));
        $changes = ['env' => $env, 'api_base' => self::API_BASES[$env], 'client_id' => mb_substr(trim($clientId), 0, 200), 'token' => null, 'token_expires' => null];
        if (trim($clientSecret) !== '') $changes['client_secret'] = RestaurantChannels::seal(trim($clientSecret));
        RestaurantChannels::saveConfig($slug, $changes);
        unset(self::$tokens[$slug]);
        AuditLog::record('restaurant.channel_api_saved', $slug, ['env' => $env]);
    }
}
