<?php
/**
 * Restaurant — order channels: canonical intake, SKU mapping, status
 * pushback and the menu feed.
 *
 * A channel is an outside system (an aggregator such as Deliverect, or a
 * thin adapter in front of one) that sends orders here. It never talks to
 * DoorDash or Uber Eats marketplaces directly; those are partner-gated and
 * reach a restaurant through the aggregator the restaurant contracts with.
 *
 * CANONICAL ORDER — POST /order-channel/{slug}, JSON body, header
 * X-Channel-Signature: sha256=<hex HMAC-SHA256 of the raw body, keyed with
 * the channel's secret>:
 *
 *   {
 *     "external_order_id": "AGG-1001",              required, unique per channel
 *     "placed_at": "2026-11-02T18:30:00Z",          optional
 *     "type": "pickup" | "delivery",                default pickup
 *     "customer": {"name": "", "phone": "", "email": ""},
 *     "delivery_address": "12 Main St, Springfield" or {"line1", "line2", "city", "state", "postal_code"}, nullable
 *     "items": [{"external_sku": "BRISKET", "qty": 2,
 *                "modifiers": [{"external_sku": "SIDE-MAC"}], "notes": ""}],
 *     "totals": {"total_cents": 4406},              what the channel charged, for the cross-check
 *     "callback_url": "https://…",                  optional; where status updates go
 *     "notes": ""                                   optional
 *   }
 *
 * Answers: 200 {"ok":true,"order_no","order_id"} once created; 401 bad
 * signature; 404 unknown or disabled channel; 409 still being processed;
 * 422 {"ok":false,"error","unmapped":[…]} rejected. A repeat of the same
 * external_order_id returns the first answer unchanged, whatever the body.
 *
 * Every item SKU (and every modifier SKU on it) must map to our menu
 * through restaurant_item_mappings; one missing mapping rejects the whole
 * order — nothing is created and nothing is substituted — and staff are
 * notified. Once mapped, staff retry it from the Channels screen.
 *
 * STATUS CALLBACK — when the order's fulfilment moves (accepted, preparing,
 * ready, completed, cancelled) the channel's adapter is told. The base
 * adapter POSTs {"external_order_id","order_no","status","updated_at"} to
 * the order's callback_url (else the channel's), signed the same way.
 */

require_once __DIR__ . '/../RestaurantAPI.php';

/** What a channel needs to provide: read its orders in, and hear about fulfilment. */
interface RestaurantChannelAdapter {
    /** Turn the channel's order payload into the canonical shape above. */
    public function toCanonical(array $payload): array;

    /** The order's fulfilment changed; tell the channel. $order is getOrder(), $intake the channel-order row. */
    public function onFulfillmentChanged(array $order, array $intake, string $status): void;
}

/** Canonical in, signed canonical status callbacks out. Used for any slug without its own adapter. */
class RestaurantChannelBaseAdapter implements RestaurantChannelAdapter {

    public function toCanonical(array $payload): array {
        return $payload;
    }

    public function onFulfillmentChanged(array $order, array $intake, string $status): void {
        $channel = RestaurantChannels::channel((string)$intake['channel']);
        $url = trim((string)($intake['callback_url'] ?: ($channel['callback_url'] ?? '')));
        if ($url === '') {
            RestaurantChannels::logEvent((int)$intake['id'], $status, null, 'no callback URL');
            return;
        }
        $body = json_encode([
            'external_order_id' => (string)$intake['external_order_id'],
            'order_no'          => (string)$order['order_no'],
            'status'            => $status,
            'updated_at'        => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        [$code, $err] = RestaurantChannels::postSigned($url, (string)$body, RestaurantChannels::secret((string)$intake['channel']));
        RestaurantChannels::logEvent((int)$intake['id'], $status, $code, $err);
    }
}

class RestaurantChannels {

    public const STATUSES_OUT = ['accepted', 'preparing', 'ready', 'completed', 'cancelled'];

    // ── Channels ──────────────────────────────────────────

    public static function channel(string $slug): ?array {
        return Database::row("SELECT * FROM restaurant_channels WHERE tenant_id = ? AND slug = ?", [current_tenant_id(), $slug]) ?: null;
    }

    public static function channels(): array {
        return Database::rows("SELECT * FROM restaurant_channels WHERE tenant_id = ? ORDER BY name", [current_tenant_id()]);
    }

    /** A slug is lower-case letters, digits and dashes, 2–40 long. */
    public static function validSlug(string $slug): bool {
        return preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $slug) === 1;
    }

    /**
     * Create or update a channel. $data: name, is_enabled, callback_url.
     * A callback URL must be https (plain http is accepted only for this
     * machine, for local testing). Throws on a bad slug, name or URL.
     */
    public static function saveChannel(string $slug, array $data): void {
        if (!self::validSlug($slug)) throw new \RuntimeException(__('restaurant_ch_err_slug', 'Use 2–40 lower-case letters, digits or dashes for the channel ID.'));
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') throw new \RuntimeException(__('restaurant_ch_err_name', 'Give the channel a name.'));
        $url = trim((string)($data['callback_url'] ?? ''));
        if ($url !== '' && !self::allowedUrl($url)) throw new \RuntimeException(__('restaurant_ch_err_url', 'The status URL must start with https://.'));
        $row = ['name' => mb_substr($name, 0, 120), 'is_enabled' => !empty($data['is_enabled']) ? 1 : 0, 'callback_url' => $url ?: null];
        if (self::channel($slug)) {
            Database::update('restaurant_channels', $row, 'tenant_id = ? AND slug = ?', [current_tenant_id(), $slug]);
        } else {
            Database::insert('restaurant_channels', $row + ['tenant_id' => current_tenant_id(), 'slug' => $slug]);
        }
        AuditLog::record('restaurant.channel_saved', $slug, ['enabled' => $row['is_enabled']]);
    }

    /** Make and store a new signing secret for the channel; returns it (shown once). */
    public static function rotateSecret(string $slug): string {
        if (!self::channel($slug)) throw new \RuntimeException(__('restaurant_ch_err_missing', 'Channel not found.'));
        $secret = 'chs_' . bin2hex(random_bytes(24));
        $stored = (function_exists('slate_encrypt_secret') && slate_has_app_secret()) ? slate_encrypt_secret($secret) : null;
        if ($stored === null) throw new \RuntimeException(__('restaurant_ch_err_encrypt', 'Secrets cannot be stored: the app secret is not configured.'));
        Database::update('restaurant_channels', ['secret' => $stored], 'tenant_id = ? AND slug = ?', [current_tenant_id(), $slug]);
        AuditLog::record('restaurant.channel_secret_rotated', $slug);
        return $secret;
    }

    /** The channel's signing secret in clear, '' when none is set. Never logged or shown again. */
    public static function secret(string $slug): string {
        $c = self::channel($slug);
        $v = (string)($c['secret'] ?? '');
        if ($v === '') return '';
        return str_starts_with($v, 'enc:v1:') ? (string)(slate_decrypt_secret($v) ?? '') : $v;
    }

    public static function sign(string $body, string $secret): string {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /** Outgoing calls go to https, or plain http on this machine only (local testing). */
    public static function allowedUrl(string $url): bool {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || empty($p['scheme'])) return false;
        if ($p['scheme'] === 'https') return true;
        return $p['scheme'] === 'http' && in_array($p['host'], ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    /** The adapter for a slug: one registered on restaurant_channel_adapters, else the base adapter. */
    public static function adapter(string $slug): RestaurantChannelAdapter {
        $map = Hook::applyFilters('restaurant_channel_adapters', []);
        $a = is_array($map) ? ($map[$slug] ?? null) : null;
        return $a instanceof RestaurantChannelAdapter ? $a : new RestaurantChannelBaseAdapter();
    }

    // ── Intake ────────────────────────────────────────────

    /**
     * Take one delivery from a channel. Verify first (an unverifiable
     * delivery changes nothing), then record it or return the recorded
     * answer, then process. Returns [http status, body array].
     */
    public static function intake(string $slug, string $raw, string $signature): array {
        $c = self::channel($slug);
        if (!$c || empty($c['is_enabled'])) return [404, ['ok' => false, 'error' => 'Unknown channel.']];
        $secret = self::secret($slug);
        if ($secret === '' || !hash_equals(self::sign($raw, $secret), trim($signature))) {
            return [401, ['ok' => false, 'error' => 'Signature does not match.']];
        }
        $tid = current_tenant_id();
        Database::update('restaurant_channels', ['last_verified_at' => slate_db_now()], 'tenant_id = ? AND slug = ?', [$tid, $slug]);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) return [400, ['ok' => false, 'error' => 'Body is not JSON.']];
        $payload = self::adapter($slug)->toCanonical($payload);
        $ext = trim((string)($payload['external_order_id'] ?? ''));
        if ($ext === '' || mb_strlen($ext) > 120) return [400, ['ok' => false, 'error' => 'external_order_id is required (up to 120 characters).']];

        $seen = Database::row("SELECT * FROM restaurant_channel_orders WHERE tenant_id = ? AND channel = ? AND external_order_id = ?", [$tid, $slug, $ext]);
        if ($seen) return self::recorded($seen);
        try {
            $id = Database::insert('restaurant_channel_orders', [
                'tenant_id' => $tid, 'channel' => $slug, 'external_order_id' => $ext, 'intake_status' => 'received',
                'callback_url' => self::cleanUrl($payload['callback_url'] ?? null),
                'claimed_total_cents' => isset($payload['totals']['total_cents']) ? (int)$payload['totals']['total_cents'] : null,
                'raw_payload' => $raw,
            ]);
        } catch (\Throwable $e) {
            // Two deliveries raced: the unique key let one in; answer as the other's replay.
            $seen = Database::row("SELECT * FROM restaurant_channel_orders WHERE tenant_id = ? AND channel = ? AND external_order_id = ?", [$tid, $slug, $ext]);
            if ($seen) return self::recorded($seen);
            throw $e;
        }
        return self::process($id, $payload);
    }

    /** The answer a recorded intake gave, for a replayed delivery. */
    private static function recorded(array $row): array {
        if ($row['intake_status'] === 'received') return [409, ['ok' => false, 'error' => 'This order is still being processed.']];
        $body = json_decode((string)$row['response'], true) ?: ['ok' => $row['intake_status'] === 'created'];
        return [(int)($row['http_status'] ?: 200), $body + ['replay' => true]];
    }

    /** A callback URL from a payload, kept only when it is one we may call. */
    private static function cleanUrl($url): ?string {
        $url = trim((string)$url);
        return $url !== '' && mb_strlen($url) <= 500 && self::allowedUrl($url) ? $url : null;
    }

    /**
     * Resolve every SKU through the mappings: returns [lines, unmapped] where
     * lines are addLine() arguments and unmapped a list of
     * ['sku', 'kind' => item|modifier, 'item_sku'].
     */
    public static function resolve(string $slug, array $items): array {
        $tid = current_tenant_id();
        $maps = [];
        foreach (Database::rows("SELECT * FROM restaurant_item_mappings WHERE tenant_id = ? AND channel = ? AND is_active = 1", [$tid, $slug]) as $m) {
            $maps[(string)$m['external_sku']] = $m;
        }
        $lines = []; $unmapped = [];
        foreach ($items as $it) {
            $sku = trim((string)($it['external_sku'] ?? ''));
            $map = $maps[$sku] ?? null;
            if ($sku === '' || !$map || empty($map['item_id'])) { $unmapped[] = ['sku' => $sku, 'kind' => 'item', 'item_sku' => null]; continue; }
            $modMap = json_decode((string)($map['modifier_map'] ?? ''), true) ?: [];
            $mods = []; $combo = [];
            foreach ((array)($it['modifiers'] ?? []) as $m) {
                $msku = trim((string)($m['external_sku'] ?? ''));
                $to = $modMap[$msku] ?? null;
                if ($msku === '' || !is_array($to) || empty($to['id'])) { $unmapped[] = ['sku' => $msku, 'kind' => 'modifier', 'item_sku' => $sku]; continue; }
                if (($to['type'] ?? '') === 'choice') $combo[] = (int)$to['id']; else $mods[] = (int)$to['id'];
            }
            $lines[] = ['item_id' => (int)$map['item_id'], 'qty' => max(1, (int)($it['qty'] ?? 1)),
                        'modifiers' => $mods, 'combo' => $combo, 'notes' => mb_substr((string)($it['notes'] ?? ''), 0, 255)];
        }
        return [$lines, $unmapped];
    }

    /**
     * Build the order for a recorded intake row, or reject it. All or
     * nothing: a line the menu refuses (an 86'd item, a combo short of
     * picks) leaves no order behind.
     */
    public static function process(int $intakeId, ?array $payload = null): array {
        $tid = current_tenant_id();
        $row = Database::row("SELECT * FROM restaurant_channel_orders WHERE id = ? AND tenant_id = ?", [$intakeId, $tid]);
        if (!$row) return [404, ['ok' => false, 'error' => 'Not found.']];
        $slug = (string)$row['channel'];
        $payload = $payload ?? self::adapter($slug)->toCanonical(json_decode((string)$row['raw_payload'], true) ?: []);

        $items = (array)($payload['items'] ?? []);
        if (!$items) return self::reject($row, __('restaurant_ch_rej_empty', 'The order has no items.'), []);
        [$lines, $unmapped] = self::resolve($slug, $items);
        if ($unmapped) {
            $list = array_map(fn($u) => $u['kind'] === 'item' ? $u['sku'] : $u['item_sku'] . ' › ' . $u['sku'], $unmapped);
            return self::reject($row, sprintf(__('restaurant_ch_rej_unmapped', 'Not on the menu map: %s'), implode(', ', $list)), $unmapped);
        }

        $cust = (array)($payload['customer'] ?? []);
        $type = ($payload['type'] ?? 'pickup') === 'delivery' ? 'delivery' : 'takeout';
        $addr = $payload['delivery_address'] ?? null;
        if (is_array($addr)) $addr = implode(', ', array_filter(array_map('trim', array_map('strval', [$addr['line1'] ?? '', $addr['line2'] ?? '', $addr['city'] ?? '', $addr['state'] ?? '', $addr['postal_code'] ?? '']))));
        $notes = trim(($type === 'delivery' && $addr ? 'Deliver to: ' . $addr . ' · ' : '') . (string)($payload['notes'] ?? ''), ' ·');

        // All or nothing, by cleanup rather than a database transaction: in a
        // signed-in request the core session check issues CREATE TABLE on
        // every Auth::user() call (openOrder and AuditLog both make one), and
        // MySQL commits an open transaction on any DDL. So a failed build is
        // removed row by row, with the customer if this attempt made it.
        $newCustomerFrom = (int) Database::value("SELECT COALESCE(MAX(id), 0) FROM restaurant_customers");
        $orderId = 0; $custId = null;
        try {
            $custId = RestaurantAPI::findOrCreateCustomer((string)($cust['name'] ?? ''), (string)($cust['phone'] ?? ''), (string)($cust['email'] ?? ''));
            $orderId = RestaurantAPI::openOrder(['type' => $type, 'customer_id' => $custId, 'source' => 'channel', 'notes' => $notes, 'server_id' => null]);
            foreach ($lines as $l) RestaurantAPI::addLine($orderId, $l);
            Database::update('restaurant_orders', ['channel' => $slug, 'external_ref' => mb_substr((string)$row['external_order_id'], 0, 80),
                'fulfillment_status' => 'new', 'requested_time' => mb_substr((string)($payload['placed_at'] ?? ''), 0, 40) ?: null],
                'id = ? AND tenant_id = ?', [$orderId, $tid]);
        } catch (\Throwable $e) {
            if ($orderId) self::discardOrder($orderId);
            if ($custId && $custId > $newCustomerFrom) Database::delete('restaurant_customers', 'id = ? AND tenant_id = ?', [$custId, $tid]);
            return self::reject($row, sprintf(__('restaurant_ch_rej_menu', 'The menu refused a line: %s'), $e->getMessage()), []);
        }

        $order = Database::row("SELECT * FROM restaurant_orders WHERE id = ?", [$orderId]);
        $claimed = $row['claimed_total_cents'];
        if ($claimed !== null && abs((int)$claimed - (int)$order['total_cents']) > 1) {
            $flag = sprintf(__('restaurant_ch_flag_total', 'The channel charged %1$s; our total is %2$s.'),
                RestaurantAPI::money((int)$claimed), RestaurantAPI::money((int)$order['total_cents']));
            Database::update('restaurant_orders', ['flag_reason' => mb_substr($flag, 0, 255)], 'id = ?', [$orderId]);
            AuditLog::record('restaurant.channel_total_mismatch', (string)$orderId, ['channel' => $slug, 'claimed' => (int)$claimed, 'ours' => (int)$order['total_cents']]);
        }
        $body = ['ok' => true, 'order_no' => (string)$order['order_no'], 'order_id' => $orderId];
        Database::update('restaurant_channel_orders', ['intake_status' => 'created', 'order_id' => $orderId, 'reject_reason' => null,
            'unmapped' => null, 'http_status' => 200, 'response' => json_encode($body)], 'id = ?', [$intakeId]);
        AuditLog::record('restaurant.channel_order_created', (string)$orderId, ['channel' => $slug, 'external' => (string)$row['external_order_id']]);
        self::notify(sprintf(__('restaurant_ch_note_new', 'New %1$s order · %2$s'), self::channelName($slug), $order['order_no']),
            RestaurantAPI::money((int)$order['total_cents']), plugin_url('restaurant', 'admin/order.php') . '?id=' . $orderId);
        return [200, $body];
    }

    /** Remove an order this intake started but could not finish (no payment can exist on it yet). */
    private static function discardOrder(int $orderId): void {
        $tid = current_tenant_id();
        foreach (array_column(Database::rows("SELECT id FROM restaurant_order_items WHERE order_id = ? AND tenant_id = ?", [$orderId, $tid]), 'id') as $lid) {
            Database::delete('restaurant_order_item_modifiers', 'order_item_id = ?', [(int)$lid]);
        }
        Database::delete('restaurant_order_items', 'order_id = ? AND tenant_id = ?', [$orderId, $tid]);
        Database::delete('restaurant_orders', 'id = ? AND tenant_id = ?', [$orderId, $tid]);
    }

    private static function reject(array $row, string $reason, array $unmapped): array {
        $body = ['ok' => false, 'error' => $reason, 'unmapped' => array_values(array_unique(array_column($unmapped, 'sku')))];
        Database::update('restaurant_channel_orders', ['intake_status' => 'rejected', 'reject_reason' => mb_substr($reason, 0, 500),
            'unmapped' => $unmapped ? json_encode($unmapped) : null, 'http_status' => 422, 'response' => json_encode($body)], 'id = ?', [(int)$row['id']]);
        AuditLog::record('restaurant.channel_order_rejected', (string)$row['external_order_id'], ['channel' => $row['channel'], 'reason' => $reason]);
        self::notify(sprintf(__('restaurant_ch_note_rejected', '%1$s order %2$s was not accepted'), self::channelName((string)$row['channel']), $row['external_order_id']),
            $reason, plugin_url('restaurant', 'admin/channels.php') . '?channel=' . rawurlencode((string)$row['channel']));
        return [422, $body];
    }

    /** Try a rejected (or stuck) intake again, e.g. after mapping its SKUs. */
    public static function retry(int $intakeId): array {
        $row = Database::row("SELECT * FROM restaurant_channel_orders WHERE id = ? AND tenant_id = ?", [$intakeId, current_tenant_id()]);
        if (!$row || $row['intake_status'] === 'created') return [409, ['ok' => false, 'error' => 'Nothing to retry.']];
        AuditLog::record('restaurant.channel_order_retry', (string)$row['external_order_id'], ['channel' => $row['channel']]);
        return self::process($intakeId);
    }

    private static function channelName(string $slug): string {
        return (string)(self::channel($slug)['name'] ?? $slug);
    }

    private static function notify(string $title, string $body, string $url): void {
        if (!class_exists('Notifications')) return;
        try { Notifications::add($title, ['body' => $body, 'url' => $url, 'icon' => 'shopping-bag']); } catch (\Throwable $e) { /* a notice never blocks an order */ }
    }

    // ── Mappings ──────────────────────────────────────────

    /**
     * Map a channel SKU to a menu item. $modifierMap: [modifier SKU =>
     * ['type' => modifier|choice, 'id' => …]]; every id must belong to that
     * item (one of its modifier options or combo choices).
     */
    public static function saveMapping(string $slug, string $sku, ?int $itemId, array $modifierMap = []): void {
        $tid = current_tenant_id();
        $sku = trim($sku);
        if ($sku === '' || mb_strlen($sku) > 120) throw new \RuntimeException(__('restaurant_ch_err_sku', 'Enter the SKU, up to 120 characters.'));
        if ($itemId && !Database::value("SELECT 1 FROM restaurant_items WHERE id = ? AND tenant_id = ?", [$itemId, $tid])) {
            throw new \RuntimeException(__('restaurant_err_item', 'Unknown menu item.'));
        }
        $ok = $itemId ? self::mappableOptions($itemId) : [];
        $clean = [];
        foreach ($modifierMap as $msku => $to) {
            $msku = trim((string)$msku);
            if ($msku === '' || !is_array($to) || empty($to['id'])) continue;
            $key = (($to['type'] ?? '') === 'choice' ? 'choice' : 'modifier') . ':' . (int)$to['id'];
            if (!isset($ok[$key])) throw new \RuntimeException(sprintf(__('restaurant_ch_err_mod', '%s does not map to an option of that item.'), $msku));
            $clean[$msku] = ['type' => explode(':', $key)[0], 'id' => (int)$to['id']];
        }
        $row = ['item_id' => $itemId ?: null, 'modifier_map' => $clean ? json_encode($clean) : null, 'is_active' => 1];
        if (Database::value("SELECT 1 FROM restaurant_item_mappings WHERE tenant_id = ? AND channel = ? AND external_sku = ?", [$tid, $slug, $sku])) {
            Database::update('restaurant_item_mappings', $row, 'tenant_id = ? AND channel = ? AND external_sku = ?', [$tid, $slug, $sku]);
        } else {
            Database::insert('restaurant_item_mappings', $row + ['tenant_id' => $tid, 'channel' => $slug, 'external_sku' => $sku]);
        }
        AuditLog::record('restaurant.channel_mapping_saved', $slug . ':' . $sku, ['item' => $itemId]);
    }

    /** The options a channel modifier can map to on an item: ['modifier:12' => 'Size: Large', 'choice:4' => 'Sides: Slaw']. */
    public static function mappableOptions(int $itemId): array {
        $out = [];
        foreach (RestaurantAPI::getItemModifierGroups($itemId) as $g) {
            foreach ($g['modifiers'] as $m) $out['modifier:' . (int)$m['id']] = $g['name'] . ': ' . $m['name'];
        }
        foreach (RestaurantAPI::getComboSlots($itemId) as $sl) {
            foreach ($sl['choices'] as $c) $out['choice:' . (int)$c['id']] = $sl['label'] . ': ' . $c['name'];
        }
        return $out;
    }

    /**
     * Every SKU the channel has used or that is mapped: [sku => ['mapping' =>
     * row|null, 'modifiers' => [modifier SKUs seen with it], 'seen' => n]],
     * built from the mappings and the SKUs that rejected intakes named.
     */
    public static function skus(string $slug): array {
        $tid = current_tenant_id();
        $out = [];
        foreach (Database::rows("SELECT * FROM restaurant_item_mappings WHERE tenant_id = ? AND channel = ? ORDER BY external_sku", [$tid, $slug]) as $m) {
            $out[(string)$m['external_sku']] = ['mapping' => $m, 'modifiers' => array_keys(json_decode((string)$m['modifier_map'], true) ?: []), 'seen' => 0];
        }
        foreach (Database::rows("SELECT raw_payload FROM restaurant_channel_orders WHERE tenant_id = ? AND channel = ? ORDER BY id DESC LIMIT 500", [$tid, $slug]) as $r) {
            $p = self::adapter($slug)->toCanonical(json_decode((string)$r['raw_payload'], true) ?: []);
            foreach ((array)($p['items'] ?? []) as $it) {
                $sku = trim((string)($it['external_sku'] ?? ''));
                if ($sku === '') continue;
                $out[$sku] = $out[$sku] ?? ['mapping' => null, 'modifiers' => [], 'seen' => 0];
                $out[$sku]['seen']++;
                foreach ((array)($it['modifiers'] ?? []) as $m) {
                    $msku = trim((string)($m['external_sku'] ?? ''));
                    if ($msku !== '' && !in_array($msku, $out[$sku]['modifiers'], true)) $out[$sku]['modifiers'][] = $msku;
                }
            }
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    /** How many SKUs (item or modifier) on a channel have no mapping yet. */
    public static function unmappedCount(string $slug): int {
        $n = 0;
        foreach (self::skus($slug) as $info) {
            $m = $info['mapping'];
            if (!$m || empty($m['item_id'])) { $n++; continue; }
            $mm = json_decode((string)$m['modifier_map'], true) ?: [];
            foreach ($info['modifiers'] as $msku) if (!isset($mm[$msku])) $n++;
        }
        return $n;
    }

    // ── Status pushback ───────────────────────────────────

    /** Hooked to restaurant_fulfillment_changed: tell the channel of a channel order. */
    public static function fulfillmentChanged(int $orderId, string $status, string $was = ''): void {
        if (!in_array($status, self::STATUSES_OUT, true)) return;
        $tid = current_tenant_id();
        $intake = Database::row("SELECT * FROM restaurant_channel_orders WHERE tenant_id = ? AND order_id = ? AND intake_status = 'created'", [$tid, $orderId]);
        if (!$intake) return;
        $order = RestaurantAPI::getOrder($orderId);
        if (!$order) return;
        try {
            self::adapter((string)$intake['channel'])->onFulfillmentChanged($order, $intake, $status);
        } catch (\Throwable $e) {
            self::logEvent((int)$intake['id'], $status, null, mb_substr($e->getMessage(), 0, 250));
        }
    }

    /** POST a JSON body signed with the secret; returns [http status|null, error|null]. Never throws. */
    public static function postSigned(string $url, string $body, string $secret): array {
        if (!self::allowedUrl($url)) return [null, 'callback URL not allowed'];
        if (!function_exists('curl_init')) return [null, 'cURL missing'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Channel-Signature: ' . self::sign($body, $secret)],
            CURLOPT_USERAGENT => 'Kohevo-Restaurant-Channel/1',
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $out === false ? curl_error($ch) : null;
        return [$code ?: null, $err ?: ($code >= 400 ? 'HTTP ' . $code : null)];
    }

    public static function logEvent(int $intakeId, string $status, ?int $http, ?string $error): void {
        Database::insert('restaurant_channel_events', ['tenant_id' => current_tenant_id(), 'channel_order_id' => $intakeId,
            'status' => $status, 'http_status' => $http, 'error' => $error !== null ? mb_substr($error, 0, 255) : null]);
    }

    // ── Menu feed ─────────────────────────────────────────

    public static function feedToken(): string {
        return (string) Database::setting('restaurant.feed_token');
    }

    public static function rotateFeedToken(): string {
        $t = 'mft_' . bin2hex(random_bytes(20));
        Database::setSetting('restaurant.feed_token', $t);
        AuditLog::record('restaurant.feed_token_rotated', '');
        return $t;
    }

    /** True when $given matches the feed token (constant time); false while no token exists. */
    public static function feedAuthorised(string $given): bool {
        $t = self::feedToken();
        return $t !== '' && $given !== '' && hash_equals($t, $given);
    }

    /**
     * The whole menu for a channel to read: categories, active items (with
     * 86 state, price, tax, photo URL, modifier group ids and combo slots),
     * modifier groups with their options. 'version' is the latest menu
     * update time; 'etag' changes whenever any of it does.
     */
    public static function menuFeed(): array {
        $tid = current_tenant_id();
        $base = rtrim(SLATE_URL, '/') . '/';
        $abs = fn($p) => ($p = trim((string)$p)) === '' ? null : (preg_match('#^https?://#', $p) ? $p : $base . ltrim($p, '/'));
        $cats = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'sort_order' => (int)$c['sort_order']],
            Database::rows("SELECT * FROM restaurant_menu_categories WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]));
        $links = [];
        foreach (Database::rows("SELECT item_id, group_id FROM restaurant_item_modifier_groups WHERE tenant_id = ? ORDER BY sort_order", [$tid]) as $l) {
            $links[(int)$l['item_id']][] = (int)$l['group_id'];
        }
        $items = [];
        foreach (Database::rows("SELECT * FROM restaurant_items WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]) as $i) {
            $row = ['id' => (int)$i['id'], 'category_id' => $i['category_id'] !== null ? (int)$i['category_id'] : null,
                    'name' => $i['name'], 'description' => (string)($i['description'] ?? ''), 'price_cents' => (int)$i['price_cents'],
                    'tax_rate' => (float)$i['tax_rate'], 'daypart' => $i['daypart'], 'is_86' => !empty($i['is_86']),
                    'photo_url' => $abs($i['photo_path'] ?? ''), 'modifier_group_ids' => $links[(int)$i['id']] ?? []];
            if (!empty($i['is_combo'])) {
                $row['combo'] = array_map(fn($sl) => ['slot_id' => (int)$sl['id'], 'label' => $sl['label'], 'min' => (int)$sl['min_pick'], 'max' => (int)$sl['max_pick'],
                    'choices' => array_values(array_map(fn($c) => ['choice_id' => (int)$c['id'], 'item_id' => (int)$c['item_id'], 'name' => $c['name'],
                        'price_delta_cents' => (int)$c['price_delta_cents'], 'is_86' => !empty($c['is_86'])],
                        array_filter($sl['choices'], fn($c) => !empty($c['is_active']))))], RestaurantAPI::getComboSlots((int)$i['id']));
            }
            $items[] = $row;
        }
        $groups = [];
        foreach (Database::rows("SELECT * FROM restaurant_modifier_groups WHERE tenant_id = ? ORDER BY sort_order, name", [$tid]) as $g) {
            $groups[] = ['id' => (int)$g['id'], 'name' => $g['name'], 'min' => (int)$g['min_select'], 'max' => (int)$g['max_select'],
                'required' => !empty($g['is_required']),
                'modifiers' => array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['name'], 'price_delta_cents' => (int)$m['price_delta_cents']],
                    Database::rows("SELECT * FROM restaurant_modifiers WHERE group_id = ? AND tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [(int)$g['id'], $tid]))];
        }
        $version = (string) Database::value(
            "SELECT MAX(t) FROM (SELECT MAX(updated_at) AS t FROM restaurant_items WHERE tenant_id = ?
                                 UNION ALL SELECT MAX(updated_at) FROM restaurant_menu_categories WHERE tenant_id = ?
                                 UNION ALL SELECT MAX(updated_at) FROM restaurant_modifier_groups WHERE tenant_id = ?) x", [$tid, $tid, $tid]);
        $menu = ['currency' => RestaurantAPI::currency(), 'categories' => $cats, 'items' => $items, 'modifier_groups' => $groups];
        return ['version' => $version, 'etag' => sha1((string) json_encode($menu))] + $menu;
    }
}
