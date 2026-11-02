<?php
/**
 * Restaurant — courier delivery for our own delivery orders (white-label
 * fulfilment): staff, or the auto-request setting, ask a courier provider
 * to carry an order the restaurant took itself (online or POS).
 *
 * A job (restaurant_delivery_jobs) is one attempt with one provider. Its
 * external_id is OUR id, sent to the provider as the delivery's id:
 * "kv<tenant>-<order>-<attempt>", unique per provider, so a repeated
 * create is refused by the provider rather than duplicated.
 *
 *   quoted → requested → courier_assigned → enroute_pickup → picked_up → delivered
 *                         (any active state) → cancelled | failed
 *
 * Status only moves forward; cancelled and failed end it. Provider
 * webhooks are treated as hints: the job is re-read from the provider's
 * API and only that answer is applied, so an unauthenticated or stale
 * webhook can never move a job on its own word.
 *
 * Providers implement RestaurantCourierProvider and register on the
 * restaurant_courier_providers filter; DoorDash Drive
 * (RestaurantDoorDashDrive) and Uber Direct (RestaurantUberDirect) ship here.
 */

require_once __DIR__ . '/../RestaurantAPI.php';
require_once __DIR__ . '/channels.php';   // RestaurantChannels::seal()/unseal()/postJson() conventions

interface RestaurantCourierProvider {
    public function key(): string;
    public function label(): string;
    /** Credentials present and the provider switched on. */
    public function enabled(): bool;
    /** Ask for a price and times. Returns ['ok', 'fee_cents', 'currency', 'pickup_eta', 'dropoff_eta', 'expires_at', 'raw'] or ['ok' => false, 'error']. */
    public function quote(array $job, array $pickup, array $dropoff, array $order): array;
    /** Turn the quote into a real delivery. Returns ['ok', 'remote' => normalised state] or ['ok' => false, 'error']. */
    public function accept(array $job): array;
    /** Current state from the provider. Returns ['ok', 'remote'] or ['ok' => false, 'error', 'http']. */
    public function fetch(array $job): array;
    /** Cancel while the provider still allows it. Returns ['ok', 'remote'?] or ['ok' => false, 'error']. */
    public function cancel(array $job): array;
    /** Which delivery a webhook body names: ['external_id' => our id, 'provider_delivery_id' => theirs] (either may be ''). */
    public function webhookRef(array $body): array;
    /** Whether a webhook may be read at all (a signature check where the provider signs; true where it does not). */
    public function verifyWebhook(string $raw, array $headers): bool;
}

class RestaurantDelivery {

    public const STATUSES = ['quoted', 'requested', 'courier_assigned', 'enroute_pickup', 'picked_up', 'delivered', 'cancelled', 'failed'];
    private const RANK    = ['quoted' => 0, 'requested' => 1, 'courier_assigned' => 2, 'enroute_pickup' => 3, 'picked_up' => 4, 'delivered' => 5];
    public const ACTIVE   = ['requested', 'courier_assigned', 'enroute_pickup', 'picked_up'];

    /** @return RestaurantCourierProvider[] by key */
    public static function providers(): array {
        $map = Hook::applyFilters('restaurant_courier_providers', ['doordash_drive' => new RestaurantDoorDashDrive(), 'uber_direct' => new RestaurantUberDirect()]);
        return array_filter(is_array($map) ? $map : [], fn($p) => $p instanceof RestaurantCourierProvider);
    }

    public static function provider(string $key): ?RestaurantCourierProvider {
        return self::providers()[$key] ?? null;
    }

    /** Providers that are switched on and have credentials. */
    public static function enabledProviders(): array {
        return array_filter(self::providers(), fn($p) => $p->enabled());
    }

    // ── Addresses and contacts ────────────────────────────

    /** Phone to E.164: "+" kept, 10 US digits get +1, 11 starting with 1 get +. '' when unusable. */
    public static function e164(string $phone): string {
        $phone = trim($phone);
        $d = preg_replace('/\D+/', '', $phone);
        if ($d === '') return '';
        if (str_starts_with($phone, '+')) return '+' . $d;
        if (strlen($d) === 10) return '+1' . $d;
        if (strlen($d) === 11 && $d[0] === '1') return '+' . $d;
        return strlen($d) >= 8 ? '+' . $d : '';
    }

    /** The restaurant's pickup details from the business settings. */
    public static function pickup(): array {
        $s = RestaurantAPI::settings();
        $addr = implode(', ', array_filter(array_map('trim', [$s['addr_line1'], $s['addr_line2'],
            trim($s['addr_city'] . ', ' . $s['addr_state'] . ' ' . $s['addr_postal'], ', ')])));
        return ['name' => $s['biz_name'], 'address' => $addr, 'phone' => self::e164((string) RestaurantAPI::setting('biz_phone', '')),
                'parts' => ['street' => array_values(array_filter(array_map('trim', [$s['addr_line1'], $s['addr_line2']]))), 'city' => trim($s['addr_city']),
                            'state' => trim($s['addr_state']), 'zip' => trim($s['addr_postal']), 'country' => trim($s['addr_country']) ?: 'US']];
    }

    /**
     * Best-effort split of a one-line address ("12 Main St, Apt 4, Springfield,
     * IL 62701") into street lines, city, state and ZIP, for providers that
     * want parts. The last comma part holding "ST 12345" gives state and ZIP,
     * the part before it the city; anything that does not fit stays a street
     * line.
     */
    public static function splitAddress(string $line, string $country = 'US'): array {
        $bits = array_values(array_filter(array_map('trim', explode(',', $line)), 'strlen'));
        $out = ['street' => $bits, 'city' => '', 'state' => '', 'zip' => '', 'country' => $country];
        if (count($bits) >= 3 && preg_match('/^([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)$/', end($bits), $m)) {
            $out = ['street' => array_slice($bits, 0, -2), 'city' => $bits[count($bits) - 2], 'state' => strtoupper($m[1]), 'zip' => $m[2], 'country' => $country];
        }
        return $out;
    }

    /**
     * The order's drop-off. Checkout and channel intake keep the address as
     * a "Deliver to: …" prefix on the order notes (there is no structured
     * address field yet), so it is read from there; the rest of the notes
     * become drop-off instructions.
     */
    public static function dropoff(array $order): array {
        $notes = trim((string)($order['notes'] ?? ''));
        $addr = ''; $rest = $notes;
        if (preg_match('/^Deliver to:\s*(.+?)(?:\s+·\s+(.*))?$/su', $notes, $m)) { $addr = trim($m[1]); $rest = trim((string)($m[2] ?? '')); }
        $name = trim((string)($order['customer_name'] ?? ''));
        $parts = preg_split('/\s+/', $name, 2) ?: [''];
        return ['address' => $addr, 'phone' => self::e164((string)($order['customer_phone'] ?? '')),
                'given_name' => (string)($parts[0] ?? ''), 'family_name' => (string)($parts[1] ?? ''),
                'instructions' => mb_substr($rest, 0, 250)];
    }

    // ── Jobs ──────────────────────────────────────────────

    public static function job(int $id): ?array {
        return Database::row("SELECT * FROM restaurant_delivery_jobs WHERE id = ? AND tenant_id = ?", [$id, current_tenant_id()]) ?: null;
    }

    /** The order's latest job, or null. */
    public static function latestJob(int $orderId): ?array {
        return Database::row("SELECT * FROM restaurant_delivery_jobs WHERE tenant_id = ? AND order_id = ? ORDER BY id DESC LIMIT 1", [current_tenant_id(), $orderId]) ?: null;
    }

    public static function quoteLive(array $job): bool {
        return $job['status'] === 'quoted' && !empty($job['quote_expires_at']) && strtotime((string)$job['quote_expires_at']) > time();
    }

    /**
     * Ask a provider for a courier quote for a delivery order. Nothing is
     * booked yet; the quote lives a few minutes (DoorDash: 5). A failure
     * (no coverage, a bad address) is recorded on a failed job and its
     * message returned for staff; the order itself is never changed.
     */
    public static function quote(int $orderId, string $providerKey): array {
        $tid = current_tenant_id();
        $p = self::provider($providerKey);
        if (!$p || !$p->enabled()) return ['ok' => false, 'error' => __('restaurant_cr_err_provider', 'That courier service is not set up.')];
        $order = RestaurantAPI::getOrder($orderId);
        if (!$order || $order['type'] !== 'delivery') return ['ok' => false, 'error' => __('restaurant_cr_err_not_delivery', 'Only delivery orders can get a courier.')];
        if (in_array($order['status'], ['voided'], true) || in_array($order['fulfillment_status'] ?? '', ['completed', 'cancelled'], true)) {
            return ['ok' => false, 'error' => __('restaurant_cr_err_done', 'This order is finished or cancelled.')];
        }
        $last = self::latestJob($orderId);
        if ($last && in_array($last['status'], self::ACTIVE, true)) return ['ok' => false, 'error' => __('restaurant_cr_err_active', 'A courier is already on this order.')];
        if ($last && self::quoteLive($last) && $last['provider'] === $providerKey) return ['ok' => true, 'job' => $last];

        $pickup = self::pickup(); $drop = self::dropoff($order);
        if ($pickup['address'] === '' || $pickup['phone'] === '') return ['ok' => false, 'error' => __('restaurant_cr_err_pickup', 'Set the restaurant address and phone first (Couriers page).')];
        if ($drop['address'] === '') return ['ok' => false, 'error' => __('restaurant_cr_err_addr', 'This order has no delivery address.')];
        if ($drop['phone'] === '') return ['ok' => false, 'error' => __('restaurant_cr_err_phone', 'This order has no customer phone number, which the courier needs.')];

        $attempt = (int) Database::value("SELECT COUNT(*) FROM restaurant_delivery_jobs WHERE tenant_id = ? AND order_id = ? AND provider = ?", [$tid, $orderId, $providerKey]) + 1;
        $jobId = Database::insert('restaurant_delivery_jobs', [
            'tenant_id' => $tid, 'order_id' => $orderId, 'provider' => $providerKey, 'attempt' => $attempt,
            'external_id' => 'kv' . $tid . '-' . $orderId . '-' . $attempt, 'status' => 'quoted', 'currency' => RestaurantAPI::currency(),
        ]);
        $job = self::job($jobId);
        $q = $p->quote($job, $pickup, $drop, $order);
        if (empty($q['ok'])) {
            Database::update('restaurant_delivery_jobs', ['status' => 'failed', 'error' => mb_substr((string)$q['error'], 0, 500),
                'raw_last_payload' => isset($q['raw']) ? json_encode($q['raw']) : null], 'id = ?', [$jobId]);
            AuditLog::record('restaurant.courier_quote_failed', (string)$orderId, ['provider' => $providerKey, 'error' => mb_substr((string)$q['error'], 0, 200)]);
            return ['ok' => false, 'error' => (string)$q['error'], 'job' => self::job($jobId)];
        }
        Database::update('restaurant_delivery_jobs', ['fee_cents' => $q['fee_cents'], 'currency' => $q['currency'] ?: RestaurantAPI::currency(),
            'pickup_eta' => $q['pickup_eta'], 'dropoff_eta' => $q['dropoff_eta'], 'quote_expires_at' => $q['expires_at'],
            'provider_delivery_id' => ($q['provider_ref'] ?? '') !== '' ? mb_substr((string)$q['provider_ref'], 0, 120) : null,
            'raw_last_payload' => json_encode($q['raw'] ?? null)], 'id = ?', [$jobId]);
        AuditLog::record('restaurant.courier_quoted', (string)$orderId, ['provider' => $providerKey, 'fee' => $q['fee_cents']]);
        return ['ok' => true, 'job' => self::job($jobId)];
    }

    /** Book the courier on a live quote. An expired quote must be asked for again. */
    public static function accept(int $jobId): array {
        $job = self::job($jobId);
        if (!$job) return ['ok' => false, 'error' => __('restaurant_cr_err_job', 'Courier request not found.')];
        if ($job['status'] !== 'quoted') return ['ok' => false, 'error' => __('restaurant_cr_err_not_quote', 'There is no open quote to confirm.')];
        if (!self::quoteLive($job)) {
            Database::update('restaurant_delivery_jobs', ['status' => 'cancelled', 'error' => 'quote expired'], 'id = ?', [$jobId]);
            return ['ok' => false, 'error' => __('restaurant_cr_err_expired', 'That quote has expired. Request a courier again for a fresh price.')];
        }
        $p = self::provider((string)$job['provider']);
        if (!$p) return ['ok' => false, 'error' => __('restaurant_cr_err_provider', 'That courier service is not set up.')];
        $r = $p->accept($job);
        if (empty($r['ok'])) {
            Database::update('restaurant_delivery_jobs', ['status' => 'failed', 'error' => mb_substr((string)$r['error'], 0, 500)], 'id = ?', [$jobId]);
            AuditLog::record('restaurant.courier_request_failed', (string)$job['order_id'], ['provider' => $job['provider'], 'error' => mb_substr((string)$r['error'], 0, 200)]);
            return ['ok' => false, 'error' => (string)$r['error'], 'job' => self::job($jobId)];
        }
        Database::update('restaurant_delivery_jobs', ['status' => 'requested', 'error' => null], 'id = ?', [$jobId]);
        self::apply(self::job($jobId), $r['remote']);
        AuditLog::record('restaurant.courier_requested', (string)$job['order_id'], ['provider' => $job['provider'], 'external' => $job['external_id']]);
        return ['ok' => true, 'job' => self::job($jobId)];
    }

    /** Quote then book in one step (the auto-request path). */
    public static function request(int $orderId, string $providerKey): array {
        $q = self::quote($orderId, $providerKey);
        return empty($q['ok']) ? $q : self::accept((int)$q['job']['id']);
    }

    /** Drop an unconfirmed quote (nothing was booked). */
    public static function dismiss(int $jobId): void {
        Database::update('restaurant_delivery_jobs', ['status' => 'cancelled', 'error' => null], "id = ? AND tenant_id = ? AND status = 'quoted'", [$jobId, current_tenant_id()]);
    }

    /** Cancel a booked courier. Providers refuse once a courier is far along; their message is returned. */
    public static function cancel(int $jobId): array {
        $job = self::job($jobId);
        if (!$job) return ['ok' => false, 'error' => __('restaurant_cr_err_job', 'Courier request not found.')];
        if ($job['status'] === 'quoted') { self::dismiss($jobId); return ['ok' => true, 'job' => self::job($jobId)]; }
        if (!in_array($job['status'], self::ACTIVE, true)) return ['ok' => false, 'error' => __('restaurant_cr_err_not_active', 'This courier request is already finished.')];
        $p = self::provider((string)$job['provider']);
        $r = $p ? $p->cancel($job) : ['ok' => false, 'error' => 'provider missing'];
        if (empty($r['ok'])) return ['ok' => false, 'error' => (string)$r['error']];
        self::apply($job, ($r['remote'] ?? []) + ['status' => 'cancelled']);
        AuditLog::record('restaurant.courier_cancelled', (string)$job['order_id'], ['provider' => $job['provider']]);
        return ['ok' => true, 'job' => self::job($jobId)];
    }

    /** Re-read a job from its provider and apply what it says. */
    public static function sync(int $jobId): array {
        $job = self::job($jobId);
        if (!$job) return ['ok' => false, 'error' => 'not found'];
        if ($job['status'] === 'quoted') return ['ok' => true, 'job' => $job];
        $p = self::provider((string)$job['provider']);
        $r = $p ? $p->fetch($job) : ['ok' => false, 'error' => 'provider missing'];
        if (empty($r['ok'])) return ['ok' => false, 'error' => (string)$r['error']];
        self::apply($job, $r['remote']);
        return ['ok' => true, 'job' => self::job($jobId)];
    }

    /** Whether a job may move from $from to $to: forward only, and cancelled/failed end it. */
    public static function canMove(string $from, string $to): bool {
        if ($from === $to || in_array($from, ['delivered', 'cancelled', 'failed'], true)) return false;
        if (in_array($to, ['cancelled', 'failed'], true)) return true;
        return isset(self::RANK[$to], self::RANK[$from]) && self::RANK[$to] > self::RANK[$from];
    }

    /**
     * Apply a provider's normalised state ['status', 'tracking_url',
     * 'provider_delivery_id', 'fee_cents', 'pickup_eta', 'dropoff_eta',
     * 'courier_name', 'raw']. Details always refresh; the status only moves
     * forward. A delivered order is marked completed.
     */
    public static function apply(array $job, array $remote): void {
        $set = [];
        foreach (['tracking_url', 'provider_delivery_id', 'courier_name'] as $k) if (!empty($remote[$k])) $set[$k] = mb_substr((string)$remote[$k], 0, $k === 'tracking_url' ? 500 : 120);
        foreach (['pickup_eta', 'dropoff_eta'] as $k) if (!empty($remote[$k])) $set[$k] = $remote[$k];
        if (isset($remote['fee_cents']) && $remote['fee_cents'] !== null) $set['fee_cents'] = (int)$remote['fee_cents'];
        if (array_key_exists('raw', $remote)) $set['raw_last_payload'] = json_encode($remote['raw']);
        $to = (string)($remote['status'] ?? '');
        $moved = $to !== '' && self::canMove((string)$job['status'], $to);
        if ($moved) $set['status'] = $to;
        if ($moved && $to === 'cancelled' && !empty($remote['reason'])) $set['error'] = mb_substr((string)$remote['reason'], 0, 500);
        if ($set) Database::update('restaurant_delivery_jobs', $set, 'id = ? AND tenant_id = ?', [(int)$job['id'], current_tenant_id()]);
        if ($moved) {
            AuditLog::record('restaurant.courier_status', (string)$job['order_id'], ['provider' => $job['provider'], 'from' => $job['status'], 'to' => $to]);
            if ($to === 'delivered') RestaurantAPI::setFulfillment((int)$job['order_id'], 'completed');
        }
    }

    // ── Webhooks ──────────────────────────────────────────

    public static function webhookToken(): string {
        return (string) RestaurantAPI::setting('courier_webhook_token', '');
    }

    public static function rotateWebhookToken(): string {
        $t = bin2hex(random_bytes(20));
        Database::setSetting('restaurant.courier_webhook_token', $t);
        AuditLog::record('restaurant.courier_webhook_token');
        return $t;
    }

    /**
     * A provider's webhook. The URL token must match, and where the
     * provider signs its webhooks (Uber) the signature too (else 401, nothing
     * read); the body only names the delivery. The job is then re-read from
     * the provider and that answer applied, so what the body claims is never
     * trusted. Unknown deliveries are acknowledged and ignored. $headers are
     * lower-case names. Returns [http status, body].
     */
    public static function webhook(string $providerKey, string $token, string $raw, array $headers = []): array {
        $want = self::webhookToken();
        if ($want === '' || !hash_equals($want, $token)) return [401, ['ok' => false]];
        $p = self::provider($providerKey);
        if (!$p) return [404, ['ok' => false]];
        if (!$p->verifyWebhook($raw, $headers)) return [401, ['ok' => false]];
        $body = json_decode($raw, true);
        $ref = is_array($body) ? $p->webhookRef($body) : [];
        [$ext, $pid] = [trim((string)($ref['external_id'] ?? '')), trim((string)($ref['provider_delivery_id'] ?? ''))];
        $job = $ext !== '' ? Database::row("SELECT * FROM restaurant_delivery_jobs WHERE tenant_id = ? AND provider = ? AND external_id = ?", [current_tenant_id(), $providerKey, $ext]) : null;
        if (!$job && $pid !== '') $job = Database::row("SELECT * FROM restaurant_delivery_jobs WHERE tenant_id = ? AND provider = ? AND provider_delivery_id = ? ORDER BY id DESC LIMIT 1", [current_tenant_id(), $providerKey, $pid]);
        if (!$job) return [200, ['ok' => true, 'ignored' => true]];
        $r = self::sync((int)$job['id']);
        return [200, ['ok' => true, 'synced' => !empty($r['ok'])]];
    }

    // ── Auto-request ──────────────────────────────────────

    /**
     * Hooked to restaurant_order_paid: with auto-request on, a paid online
     * delivery order gets a courier from the first enabled provider. Channel
     * orders are left alone (the marketplace may already be sending its own
     * courier). Failures notify staff and never block the payment.
     */
    public static function onOrderPaid(int $orderId): void {
        if (RestaurantAPI::setting('courier_auto', '0') !== '1') return;
        $o = Database::row("SELECT type, source FROM restaurant_orders WHERE id = ? AND tenant_id = ?", [$orderId, current_tenant_id()]);
        if (!$o || $o['type'] !== 'delivery' || $o['source'] !== 'online') return;
        $first = array_key_first(self::enabledProviders());
        if ($first === null) return;
        try {
            $r = self::request($orderId, $first);
            if (empty($r['ok']) && class_exists('Notifications')) {
                $no = (string) Database::value("SELECT order_no FROM restaurant_orders WHERE id = ?", [$orderId]);
                Notifications::add(sprintf(__('restaurant_cr_note_failed', 'No courier for order %s'), $no),
                    ['body' => (string)$r['error'], 'url' => plugin_url('restaurant', 'admin/online.php'), 'icon' => 'truck']);
            }
        } catch (\Throwable $e) {
            slate_log('Restaurant courier auto-request failed: ' . $e->getMessage(), 'error');
        }
    }

    // ── Views ─────────────────────────────────────────────

    /** Label for a job status, for staff and customers. */
    public static function statusLabel(string $s): string {
        return [
            'quoted' => __('restaurant_cr_st_quoted', 'Quote'), 'requested' => __('restaurant_cr_st_requested', 'Finding a courier'),
            'courier_assigned' => __('restaurant_cr_st_assigned', 'Courier assigned'), 'enroute_pickup' => __('restaurant_cr_st_enroute', 'Courier on the way to us'),
            'picked_up' => __('restaurant_cr_st_picked', 'Out for delivery'), 'delivered' => __('restaurant_cr_st_delivered', 'Delivered'),
            'cancelled' => __('restaurant_cr_st_cancelled', 'Courier cancelled'), 'failed' => __('restaurant_cr_st_failed', 'Courier request failed'),
        ][$s] ?? $s;
    }

    /** A job as the board and order page show it (no secrets, no raw payloads). */
    public static function view(?array $job): ?array {
        if (!$job) return null;
        $p = self::provider((string)$job['provider']);
        $t = fn($v) => $v ? date('g:ia', strtotime((string)$v)) : '';
        return [
            'id' => (int)$job['id'], 'provider' => $p ? $p->label() : (string)$job['provider'], 'status' => (string)$job['status'],
            'label' => self::statusLabel((string)$job['status']), 'live_quote' => self::quoteLive($job),
            'active' => in_array($job['status'], self::ACTIVE, true),
            'fee' => $job['fee_cents'] !== null ? RestaurantAPI::money((int)$job['fee_cents']) : '',
            'pickup_eta' => $t($job['pickup_eta']), 'dropoff_eta' => $t($job['dropoff_eta']),
            'tracking_url' => (string)($job['tracking_url'] ?? ''), 'courier' => (string)($job['courier_name'] ?? ''),
            'error' => (string)($job['error'] ?? ''), 'reference' => (string)($job['provider_delivery_id'] ?? ''),
        ];
    }
}

/**
 * DoorDash Drive (API v2, https://openapi.doordash.com/drive/v2/). Auth is a
 * JWT minted per request: HS256, header dd-ver "DD-JWT-V1", claims aud
 * "doordash", iss = developer ID, kid = key ID, iat, exp (5 minutes; Drive
 * allows at most 30), signed with the base64url-decoded signing secret from
 * the Developer Portal. Quotes: POST /quotes, then POST
 * /quotes/{external_delivery_id}/accept within 5 minutes. Status: GET
 * /deliveries/{id}. Cancel: PUT /deliveries/{id}/cancel, refused once a
 * Dasher is assigned. Errors come back as {code, message, field_errors[]}.
 * Checked against developer.doordash.com on 2 Nov 2026, when DoorDash
 * described Drive API access as limited and production access as restricted
 * with no timeline: tested here against a local stand-in only.
 */
class RestaurantDoorDashDrive implements RestaurantCourierProvider {

    /** DoorDash delivery_status → our job status. */
    public const STATUS_MAP = [
        'quote' => 'quoted', 'created' => 'requested', 'confirmed' => 'courier_assigned',
        'enroute_to_pickup' => 'enroute_pickup', 'arrived_at_pickup' => 'enroute_pickup',
        'picked_up' => 'picked_up', 'enroute_to_dropoff' => 'picked_up', 'arrived_at_dropoff' => 'picked_up',
        'enroute_to_return' => 'picked_up', 'delivered' => 'delivered', 'returned' => 'failed', 'cancelled' => 'cancelled',
    ];

    public function key(): string { return 'doordash_drive'; }
    public function label(): string { return 'DoorDash'; }

    public function enabled(): bool {
        return RestaurantAPI::setting('drive_enabled', '0') === '1'
            && trim((string) RestaurantAPI::setting('drive_developer_id', '')) !== ''
            && trim((string) RestaurantAPI::setting('drive_key_id', '')) !== ''
            && self::secret() !== '';
    }

    private static function secret(): string {
        return RestaurantChannels::unseal((string) RestaurantAPI::setting('drive_signing_secret', ''));
    }

    private static function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    /** A fresh JWT for one request. */
    public static function jwt(?int $now = null): string {
        $now = $now ?? time();
        $head = ['alg' => 'HS256', 'typ' => 'JWT', 'dd-ver' => 'DD-JWT-V1'];
        $claims = ['aud' => 'doordash', 'iss' => trim((string) RestaurantAPI::setting('drive_developer_id', '')),
                   'kid' => trim((string) RestaurantAPI::setting('drive_key_id', '')), 'iat' => $now, 'exp' => $now + 300];
        $key = base64_decode(strtr(self::secret(), '-_', '+/'), true);
        $unsigned = self::b64url((string) json_encode($head)) . '.' . self::b64url((string) json_encode($claims));
        return $unsigned . '.' . self::b64url(hash_hmac('sha256', $unsigned, $key === false ? '' : $key, true));
    }

    private static function base(): string {
        return rtrim((string) RestaurantAPI::setting('drive_api_base', 'https://openapi.doordash.com'), '/') . '/drive/v2';
    }

    /** One API call: [http status|null, decoded body|null, error|null]. Never throws, never logs the token. */
    private static function call(string $method, string $path, $body = null): array {
        $url = self::base() . $path;
        if (!RestaurantChannels::allowedUrl($url)) return [null, null, 'API URL not allowed'];
        if (!function_exists('curl_init')) return [null, null, 'cURL missing'];
        $ch = curl_init($url);
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 4,
                 CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Kohevo-Restaurant-Courier/1',
                 CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . self::jwt()]];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $out === false ? curl_error($ch) : null;
        $json = is_string($out) ? json_decode($out, true) : null;
        return [$code ?: null, is_array($json) ? $json : null, $err];
    }

    /** DoorDash's error, readable for staff: its message plus each field error. */
    private static function errorText(?int $code, ?array $json, ?string $err): string {
        if ($err) return sprintf(__('restaurant_cr_err_reach', 'Could not reach %s: %s'), 'DoorDash', $err);
        $msg = trim((string)($json['message'] ?? ''));
        $fields = array_map(fn($f) => trim((string)($f['field'] ?? '') . ': ' . (string)($f['error'] ?? ''), ': '), (array)($json['field_errors'] ?? []));
        $text = trim($msg . ($fields ? ' (' . implode('; ', $fields) . ')' : ''));
        return 'DoorDash: ' . ($text !== '' ? $text : 'HTTP ' . (int)$code);
    }

    private static function when($iso): ?string {
        $t = is_string($iso) && $iso !== '' ? strtotime($iso) : false;
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /** DoorDash delivery → our normalised state. */
    public static function normalise(array $d): array {
        $status = self::STATUS_MAP[(string)($d['delivery_status'] ?? '')] ?? '';
        return ['status' => $status, 'tracking_url' => (string)($d['tracking_url'] ?? ''), 'provider_delivery_id' => (string)($d['support_reference'] ?? ''),
                'fee_cents' => isset($d['fee']) ? (int)$d['fee'] : null, 'pickup_eta' => self::when($d['pickup_time_estimated'] ?? null),
                'dropoff_eta' => self::when($d['dropoff_time_estimated'] ?? null), 'courier_name' => (string)($d['dasher_name'] ?? ''),
                'reason' => (string)($d['cancellation_reason'] ?? ''), 'raw' => $d];
    }

    public function quote(array $job, array $pickup, array $dropoff, array $order): array {
        $body = array_filter([
            'external_delivery_id' => (string)$job['external_id'],
            'pickup_address' => $pickup['address'], 'pickup_business_name' => $pickup['name'], 'pickup_phone_number' => $pickup['phone'],
            'pickup_reference_tag' => 'Order #' . $order['order_no'],
            'dropoff_address' => $dropoff['address'], 'dropoff_phone_number' => $dropoff['phone'],
            'dropoff_contact_given_name' => $dropoff['given_name'], 'dropoff_contact_family_name' => $dropoff['family_name'],
            'dropoff_instructions' => $dropoff['instructions'],
            'order_value' => (int)$order['subtotal_cents'],
        ], fn($v) => $v !== '' && $v !== null);
        [$code, $json, $err] = self::call('POST', '/quotes', $body);
        if ($code < 200 || $code >= 300 || !is_array($json)) return ['ok' => false, 'error' => self::errorText($code, $json, $err), 'raw' => $json];
        $n = self::normalise($json);
        return ['ok' => true, 'fee_cents' => $n['fee_cents'], 'currency' => strtoupper((string)($json['currency'] ?? '')),
                'pickup_eta' => $n['pickup_eta'], 'dropoff_eta' => $n['dropoff_eta'],
                'expires_at' => date('Y-m-d H:i:s', time() + 5 * 60), 'raw' => $json];
    }

    public function accept(array $job): array {
        [$code, $json, $err] = self::call('POST', '/quotes/' . rawurlencode((string)$job['external_id']) . '/accept', new \stdClass());   // {}: tip and drop-off phone are the only fields accept may change
        if ($code < 200 || $code >= 300 || !is_array($json)) return ['ok' => false, 'error' => self::errorText($code, $json, $err)];
        return ['ok' => true, 'remote' => self::normalise($json)];
    }

    public function fetch(array $job): array {
        [$code, $json, $err] = self::call('GET', '/deliveries/' . rawurlencode((string)$job['external_id']));
        if ($code < 200 || $code >= 300 || !is_array($json)) return ['ok' => false, 'error' => self::errorText($code, $json, $err), 'http' => $code];
        return ['ok' => true, 'remote' => self::normalise($json)];
    }

    public function cancel(array $job): array {
        [$code, $json, $err] = self::call('PUT', '/deliveries/' . rawurlencode((string)$job['external_id']) . '/cancel');
        if ($code < 200 || $code >= 300) {
            $msg = self::errorText($code, $json, $err);
            if ($code === 409) $msg .= ' ' . __('restaurant_cr_cancel_portal', 'Once a courier is assigned, cancel in the provider\'s merchant portal or through its support.');
            return ['ok' => false, 'error' => $msg];
        }
        return ['ok' => true, 'remote' => is_array($json) ? self::normalise($json) : ['status' => 'cancelled']];
    }

    public function webhookRef(array $body): array {
        return ['external_id' => (string)($body['external_delivery_id'] ?? ''), 'provider_delivery_id' => ''];
    }

    /** Drive webhooks are not signed; the URL token and the re-fetch protect them. */
    public function verifyWebhook(string $raw, array $headers): bool { return true; }

    /** Check the credentials: a lookup of a delivery that cannot exist is 404 with good keys, 401/403 with bad ones. */
    public static function checkCredentials(): array {
        [$code, , $err] = self::call('GET', '/deliveries/kv-credentials-check-' . bin2hex(random_bytes(4)));
        if ($err) return ['ok' => false, 'error' => $err];
        if ($code === 404) return ['ok' => true];
        return ['ok' => false, 'error' => 'HTTP ' . (int)$code . ($code === 401 ? ' — DoorDash did not accept the key' : '')];
    }
}

/**
 * Uber Direct (DaaS API v1, https://api.uber.com/v1/customers/{customer_id}/).
 * Auth is OAuth client credentials at https://auth.uber.com/oauth/v2/token
 * (scope eats.deliveries); the token lasts 30 days and is cached sealed.
 * Quotes: POST /delivery_quotes (fee in cents, an expiry time). Booking:
 * POST /deliveries with the quote_id and our id as external_id; Uber gives
 * the delivery its own id (del_…), which later calls use. Status: GET
 * /deliveries/{id}. Cancel: POST /deliveries/{id}/cancel. Addresses are
 * JSON strings with street_address[], city, state, zip_code, country.
 * Errors come back as {kind: error, code, message, metadata}. Webhooks
 * (event.delivery_status, event.courier_update) are signed: hex
 * HMAC-SHA256 of the raw body with the webhook's signing key, in
 * x-uber-signature (x-postmates-signature for older ones). Checked against
 * developer.uber.com on 2 Nov 2026; tested here against a local stand-in
 * only.
 */
class RestaurantUberDirect implements RestaurantCourierProvider {

    /** Uber status → our job status ('pending' with a courier on it is courier_assigned; see normalise()). */
    public const STATUS_MAP = [
        'pending' => 'requested', 'pickup' => 'enroute_pickup', 'pickup_complete' => 'picked_up', 'dropoff' => 'picked_up',
        'delivered' => 'delivered', 'canceled' => 'cancelled', 'returned' => 'failed',
    ];

    private static ?array $token = null;

    public function key(): string { return 'uber_direct'; }
    public function label(): string { return 'Uber'; }

    public function enabled(): bool {
        return RestaurantAPI::setting('uber_enabled', '0') === '1'
            && trim((string) RestaurantAPI::setting('uber_customer_id', '')) !== ''
            && trim((string) RestaurantAPI::setting('uber_client_id', '')) !== ''
            && self::clientSecret() !== '';
    }

    private static function clientSecret(): string {
        return RestaurantChannels::unseal((string) RestaurantAPI::setting('uber_client_secret', ''));
    }

    private static function base(): string {
        return rtrim((string) RestaurantAPI::setting('uber_api_base', 'https://api.uber.com'), '/') . '/v1/customers/'
            . rawurlencode(trim((string) RestaurantAPI::setting('uber_customer_id', '')));
    }

    /** Drop the cached token (after new credentials). */
    public static function forgetToken(): void {
        self::$token = null;
        Database::setSetting('restaurant.uber_token', '');
        Database::setSetting('restaurant.uber_token_expires', '0');
    }

    /** One HTTP request: [http status|null, decoded body|null, error|null]. */
    private static function http(string $method, string $url, array $headers, ?string $body): array {
        if (!RestaurantChannels::allowedUrl($url)) return [null, null, 'API URL not allowed'];
        if (!function_exists('curl_init')) return [null, null, 'cURL missing'];
        $ch = curl_init($url);
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 4,
                 CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Kohevo-Restaurant-Courier/1', CURLOPT_HTTPHEADER => $headers];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $out === false ? curl_error($ch) : null;
        $json = is_string($out) ? json_decode($out, true) : null;
        return [$code ?: null, is_array($json) ? $json : null, $err];
    }

    /** A bearer token: [token, error]. Cached (sealed) until a day before it expires. */
    public static function token(bool $fresh = false): array {
        if (!$fresh) {
            $c = self::$token ?? ['t' => RestaurantChannels::unseal((string) RestaurantAPI::setting('uber_token', '')),
                                  'exp' => (int) RestaurantAPI::setting('uber_token_expires', '0')];
            if ($c['t'] !== '' && $c['exp'] > time() + 86400) return [$c['t'], null];
        }
        $url = (string) RestaurantAPI::setting('uber_auth_url', 'https://auth.uber.com/oauth/v2/token');
        [$code, $json, $err] = self::http('POST', $url, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query([
            'client_id' => trim((string) RestaurantAPI::setting('uber_client_id', '')), 'client_secret' => self::clientSecret(),
            'grant_type' => 'client_credentials', 'scope' => 'eats.deliveries']));
        $t = (string)($json['access_token'] ?? '');
        if ($code !== 200 || $t === '') return ['', 'Uber: ' . ($err ?: trim((string)($json['error_description'] ?? ($json['error'] ?? ''))) ?: 'HTTP ' . (int)$code)];
        $exp = time() + max(60, (int)($json['expires_in'] ?? 2592000));
        self::$token = ['t' => $t, 'exp' => $exp];
        try {
            Database::setSetting('restaurant.uber_token', RestaurantChannels::seal($t));
            Database::setSetting('restaurant.uber_token_expires', (string)$exp);
        } catch (\RuntimeException $e) { /* no app secret: the token lives for this request only */ }
        return [$t, null];
    }

    /** One API call with the cached token, fetching a new one once on 401. Never logs the token. */
    private static function call(string $method, string $path, ?array $body = null): array {
        for ($try = 0; $try < 2; $try++) {
            [$t, $terr] = self::token($try > 0);
            if ($t === '') return [null, null, $terr];
            $r = self::http($method, self::base() . $path, ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $t],
                $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_SLASHES));
            if ($r[0] !== 401) return $r;
        }
        return $r;
    }

    /** Uber's error, readable for staff: its message plus any field details. */
    private static function errorText(?int $code, ?array $json, ?string $err): string {
        if ($err) return str_starts_with($err, 'Uber') ? $err : sprintf(__('restaurant_cr_err_reach', 'Could not reach %s: %s'), 'Uber', $err);
        $msg = trim((string)($json['message'] ?? ''));
        $meta = [];
        foreach ((array)($json['metadata'] ?? []) as $k => $v) if (is_scalar($v)) $meta[] = $k . ': ' . $v;
        $text = trim($msg . ($meta ? ' (' . implode('; ', $meta) . ')' : ''));
        return 'Uber: ' . ($text !== '' ? $text : (trim((string)($json['code'] ?? '')) ?: 'HTTP ' . (int)$code));
    }

    private static function when($iso): ?string {
        $t = is_string($iso) && $iso !== '' ? strtotime($iso) : false;
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /** Address parts → the JSON string Uber wants. */
    public static function address(array $parts): string {
        return (string) json_encode(['street_address' => array_values($parts['street'] ?? []), 'city' => (string)($parts['city'] ?? ''),
            'state' => (string)($parts['state'] ?? ''), 'zip_code' => (string)($parts['zip'] ?? ''), 'country' => (string)($parts['country'] ?? 'US')], JSON_UNESCAPED_SLASHES);
    }

    /** Uber delivery → our normalised state. */
    public static function normalise(array $d): array {
        $s = (string)($d['status'] ?? '');
        $status = self::STATUS_MAP[$s] ?? '';
        if ($s === 'pending' && !empty($d['courier']['name'])) $status = 'courier_assigned';
        return ['status' => $status, 'tracking_url' => (string)($d['tracking_url'] ?? ''), 'provider_delivery_id' => (string)($d['id'] ?? ''),
                'fee_cents' => isset($d['fee']) ? (int)$d['fee'] : null, 'pickup_eta' => self::when($d['pickup_eta'] ?? null),
                'dropoff_eta' => self::when($d['dropoff_eta'] ?? null), 'courier_name' => (string)($d['courier']['name'] ?? ''),
                'reason' => (string)($d['undeliverable_reason'] ?? ($d['cancelation_reason']['primary_reason'] ?? '')), 'raw' => $d];
    }

    private static function drop(array $dropoff): array {
        return RestaurantDelivery::splitAddress($dropoff['address'], (string)(RestaurantAPI::settings()['addr_country'] ?? 'US') ?: 'US');
    }

    public function quote(array $job, array $pickup, array $dropoff, array $order): array {
        $body = ['pickup_address' => self::address($pickup['parts']), 'dropoff_address' => self::address(self::drop($dropoff)),
                 'pickup_phone_number' => $pickup['phone'], 'dropoff_phone_number' => $dropoff['phone'],
                 'manifest_total_value' => (int)$order['subtotal_cents'], 'external_store_id' => 'kv' . current_tenant_id()];
        [$code, $json, $err] = self::call('POST', '/delivery_quotes', $body);
        if ($code < 200 || $code >= 300 || !is_array($json)) return ['ok' => false, 'error' => self::errorText($code, $json, $err), 'raw' => $json];
        $exp = self::when($json['expires'] ?? null) ?? date('Y-m-d H:i:s', time() + 15 * 60);
        return ['ok' => true, 'fee_cents' => isset($json['fee']) ? (int)$json['fee'] : null,
                'currency' => strtoupper((string)($json['currency'] ?? ($json['currency_type'] ?? ''))),
                'pickup_eta' => isset($json['pickup_duration']) ? date('Y-m-d H:i:s', time() + 60 * (int)$json['pickup_duration']) : null,
                'dropoff_eta' => self::when($json['dropoff_eta'] ?? null), 'expires_at' => $exp, 'provider_ref' => (string)($json['id'] ?? ''), 'raw' => $json];
    }

    /** Uber has no "accept quote": the delivery is created with the quote id, from the order as it is now. */
    public function accept(array $job): array {
        $order = RestaurantAPI::getOrder((int)$job['order_id']);
        if (!$order) return ['ok' => false, 'error' => __('restaurant_cr_err_job', 'Courier request not found.')];
        $pickup = RestaurantDelivery::pickup(); $drop = RestaurantDelivery::dropoff($order);
        $items = [];
        foreach ($order['items'] as $it) {
            if (($it['kitchen_status'] ?? '') === 'void') continue;
            $items[] = ['name' => mb_substr((string)$it['name_snapshot'], 0, 100), 'quantity' => max(1, (int)$it['qty']), 'size' => 'small',
                        'price' => (int)$it['unit_price_cents']];
        }
        $body = array_filter([
            'quote_id' => (string)$job['provider_delivery_id'], 'external_id' => (string)$job['external_id'],
            'pickup_name' => $pickup['name'], 'pickup_address' => self::address($pickup['parts']), 'pickup_phone_number' => $pickup['phone'],
            'dropoff_name' => trim($drop['given_name'] . ' ' . $drop['family_name']), 'dropoff_address' => self::address(self::drop($drop)),
            'dropoff_phone_number' => $drop['phone'], 'dropoff_notes' => $drop['instructions'],
            'manifest_items' => $items ?: [['name' => 'Order #' . $order['order_no'], 'quantity' => 1, 'size' => 'small']],
            'manifest_reference' => 'Order #' . $order['order_no'], 'manifest_total_value' => (int)$order['subtotal_cents'],
        ], fn($v) => $v !== '' && $v !== null);
        [$code, $json, $err] = self::call('POST', '/deliveries', $body);
        if ($code < 200 || $code >= 300 || !is_array($json)) return ['ok' => false, 'error' => self::errorText($code, $json, $err)];
        return ['ok' => true, 'remote' => self::normalise($json)];
    }

    public function fetch(array $job): array {
        $id = (string)($job['provider_delivery_id'] ?? '');
        if (!str_starts_with($id, 'del_')) return ['ok' => false, 'error' => 'Uber: no delivery id yet', 'http' => null];
        [$code, $json, $err] = self::call('GET', '/deliveries/' . rawurlencode($id));
        if ($code < 200 || $code >= 300 || !is_array($json)) return ['ok' => false, 'error' => self::errorText($code, $json, $err), 'http' => $code];
        return ['ok' => true, 'remote' => self::normalise($json)];
    }

    public function cancel(array $job): array {
        $id = (string)($job['provider_delivery_id'] ?? '');
        if (!str_starts_with($id, 'del_')) return ['ok' => false, 'error' => 'Uber: no delivery id yet'];
        [$code, $json, $err] = self::call('POST', '/deliveries/' . rawurlencode($id) . '/cancel', []);
        if ($code < 200 || $code >= 300) {
            $msg = self::errorText($code, $json, $err);
            if (($json['code'] ?? '') === 'noncancelable_delivery' || $code === 409) $msg .= ' ' . __('restaurant_cr_cancel_portal', 'Once a courier is assigned, cancel in the provider\'s merchant portal or through its support.');
            return ['ok' => false, 'error' => $msg];
        }
        return ['ok' => true, 'remote' => is_array($json) ? self::normalise($json) : ['status' => 'cancelled']];
    }

    /** Delivery status webhooks carry delivery_id at the top and the delivery (with our external_id) in data. */
    public function webhookRef(array $body): array {
        $d = is_array($body['data'] ?? null) ? $body['data'] : [];
        return ['external_id' => (string)($d['external_id'] ?? ($body['external_id'] ?? '')),
                'provider_delivery_id' => (string)($body['delivery_id'] ?? ($d['id'] ?? ''))];
    }

    /** With a signing key saved, the HMAC must match; without one, only the URL token and the re-fetch protect the hook. */
    public function verifyWebhook(string $raw, array $headers): bool {
        $key = RestaurantChannels::unseal((string) RestaurantAPI::setting('uber_webhook_key', ''));
        if ($key === '') return true;
        $sig = strtolower(trim((string)($headers['x-uber-signature'] ?? ($headers['x-postmates-signature'] ?? ''))));
        return $sig !== '' && hash_equals(hash_hmac('sha256', $raw, $key), $sig);
    }

    /** Check the credentials: a token must be issued, then a delivery that cannot exist must answer 404 (not 401/403). */
    public static function checkCredentials(): array {
        self::forgetToken();
        [$code, $json, $err] = self::call('GET', '/deliveries/del_kvcheck' . bin2hex(random_bytes(4)));
        if ($err) return ['ok' => false, 'error' => $err];
        if ($code === 404) return ['ok' => true];
        return ['ok' => false, 'error' => self::errorText($code, $json, null)];
    }
}
