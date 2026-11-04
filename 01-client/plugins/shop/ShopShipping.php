<?php
/**
 * Shop — shipping zones, their methods, and live carrier rates through EasyPost.
 *
 * A zone is where an address is: countries (or `*`, everywhere the shop ships that no other zone names), optionally
 * regions (states, provinces) and postcodes (`90210`, `90*`, `90000-90999`). The first zone by sort that matches an
 * address is its zone. Its methods are what checkout offers there:
 *
 *   flat     cost, plus per_item for every item after the first
 *   free     nothing to pay
 *   weight   bands on the parcel's weight in the shop's unit (rates_json [[up to, cents], ...]; the last band covers
 *            anything heavier)
 *   carrier  live rates from EasyPost for the parcel (USPS, UPS, FedEx, …), optionally only some carriers and services,
 *            with a markup (rates_json {"carriers": [...], "services": [...], "markup_pct": 0, "markup_cents": 0})
 *   pickup   the customer collects the order (at cost, usually free): pickup offered only where the zone is, such as
 *            the shop's own town. The order is then a pickup order.
 *
 * Any method may need a minimum subtotal (after discounts): free shipping from $50 is a `free` method with one. A shop
 * with no zone at all keeps the flat rate and free-shipping threshold of the settings, as before 0.4.0.
 *
 * A rate is ['id' => 'm12' | 'ep:<carrier>:<service>' | 'flat', 'label', 'cents', 'kind', 'carrier', 'service', 'days'].
 * Carrier rates for one address and parcel are kept in the visitor's session for 30 minutes, so choosing one and
 * paying does not ask EasyPost again, and the price paid is the price shown.
 *
 * EasyPost: the API key is kept sealed with the app secret. A key starting with EZTK is a test key (rates and labels
 * that cost nothing). The parcel is the products' weights plus the box's, in ounces, and the box's size in inches
 * when the settings give one. `ShopShipping::$transport` replaces the HTTP call in tests.
 */

require_once __DIR__ . '/ShopStoreAPI.php';

class ShopShipping {

    public const KINDS = ['flat', 'free', 'weight', 'carrier', 'pickup'];
    public const API = 'https://api.easypost.com/v2';
    private const SESSION_KEY = 'kohevo_shop_rates';
    private const RATE_TTL = 1800;

    /** @var ?callable(string $method, string $path, ?array $body): array{0: int, 1: ?array, 2: ?string} */
    public static $transport = null;

    // ── Zones and methods ─────────────────────────────────────────────────

    /** @return list<array<string, mixed>> the zones in the order they are matched, each with its methods */
    public static function zones(): array {
        ShopStoreAPI::ensureSchema();
        $tid = current_tenant_id();
        $zones = Database::rows("SELECT * FROM shop_shipping_zones WHERE tenant_id = ? ORDER BY sort, id", [$tid]);
        $methods = Database::rows("SELECT * FROM shop_shipping_methods WHERE tenant_id = ? ORDER BY sort, id", [$tid]);
        $by = [];
        foreach ($methods as $m) $by[(int) $m['zone_id']][] = $m;
        foreach ($zones as &$z) $z['methods'] = $by[(int) $z['id']] ?? [];
        return $zones;
    }

    public static function hasZones(): bool {
        ShopStoreAPI::ensureSchema();
        return (int) Database::value("SELECT COUNT(*) FROM shop_shipping_zones WHERE tenant_id = ?", [current_tenant_id()]) > 0;
    }

    public static function zone(int $id): ?array {
        foreach (self::zones() as $z) if ((int) $z['id'] === $id) return $z;
        return null;
    }

    /** A comma/space/newline-separated list, upper-cased, without blanks or repeats. */
    public static function codes(string $raw): array {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtoupper($raw)) ?: [] as $c) {
            $c = trim($c);
            if ($c !== '' && !in_array($c, $out, true)) $out[] = mb_substr($c, 0, 40);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $in name, countries, regions, postcodes, sort
     * @return array{ok: bool, id?: int, errors?: array<string, string>}
     */
    public static function saveZone(array $in, int $id = 0): array {
        ShopStoreAPI::ensureSchema();
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        if ($name === '') $errors['name'] = __('shop_err_zone_name', 'Name the zone.');
        $countries = self::codes((string) ($in['countries'] ?? ''));
        foreach ($countries as $c) {
            if ($c !== '*' && !preg_match('/^[A-Z]{2}$/', $c)) { $errors['countries'] = __('shop_err_zone_countries', 'Use two-letter country codes, or * for everywhere else.'); break; }
        }
        if ($countries === []) $errors['countries'] = __('shop_err_zone_countries', 'Use two-letter country codes, or * for everywhere else.');
        $postcodes = self::codes((string) ($in['postcodes'] ?? ''));
        foreach ($postcodes as $p) {
            if (!preg_match('/^[A-Z0-9 -]+\*?$/', $p)) { $errors['postcodes'] = __('shop_err_zone_postcodes', 'Postcodes look like 90210, 90* or 90000-90999.'); break; }
        }
        if ($errors) return ['ok' => false, 'errors' => $errors];
        $row = [
            'name' => $name, 'countries' => implode(',', $countries), 'regions' => implode(',', self::codes((string) ($in['regions'] ?? ''))),
            'postcodes' => implode(',', $postcodes), 'sort' => (int) ($in['sort'] ?? 0),
        ];
        $tid = current_tenant_id();
        if ($id > 0) {
            Database::update('shop_shipping_zones', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
        } else {
            $id = (int) Database::insert('shop_shipping_zones', $row + ['tenant_id' => $tid]);
        }
        return ['ok' => true, 'id' => $id];
    }

    public static function deleteZone(int $id): void {
        $tid = current_tenant_id();
        Database::query("DELETE FROM shop_shipping_methods WHERE zone_id = ? AND tenant_id = ?", [$id, $tid]);
        Database::query("DELETE FROM shop_shipping_zones WHERE id = ? AND tenant_id = ?", [$id, $tid]);
    }

    /**
     * Weight bands as typed: one per line, `up to: price` (`16: 7.50`), the last one (or `*: 12.00`) covering the rest.
     *
     * @return list<array{0: ?float, 1: int}>|false
     */
    public static function parseBands(string $text): array|false {
        $bands = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^(\*|\d+(?:[.,]\d+)?)\s*[:=]\s*(.+)$/', $line, $m)) return false;
            $cents = ShopStoreAPI::parseMoney(trim($m[2]));
            if ($cents === null) return false;
            $bands[] = [$m[1] === '*' ? null : (float) str_replace(',', '.', $m[1]), $cents];
        }
        usort($bands, static fn(array $a, array $b): int => ($a[0] ?? INF) <=> ($b[0] ?? INF));
        return $bands;
    }

    public static function bandsText(array $bands): string {
        return implode("\n", array_map(static fn(array $b): string => ($b[0] === null ? '*' : ShopStoreAPI::measure($b[0])) . ': ' . number_format($b[1] / 100, 2, '.', ''), $bands));
    }

    /**
     * @param array<string, mixed> $in kind, label, cost, per_item, min_subtotal, bands (weight), carriers, services,
     *                                 markup_pct, markup (carrier), enabled, sort
     * @return array{ok: bool, id?: int, errors?: array<string, string>}
     */
    public static function saveMethod(int $zoneId, array $in, int $id = 0): array {
        ShopStoreAPI::ensureSchema();
        $errors = [];
        if (!self::zone($zoneId)) return ['ok' => false, 'errors' => ['_' => __('shop_err_zone_missing', 'That zone no longer exists.')]];
        $kind = (string) ($in['kind'] ?? 'flat');
        if (!in_array($kind, self::KINDS, true)) $kind = 'flat';
        $money = static function (string $k) use ($in, &$errors): int {
            $raw = trim((string) ($in[$k] ?? ''));
            $v = ShopStoreAPI::parseMoney($raw === '' ? '0' : $raw);
            if ($v === null) { $errors[$k] = __('shop_err_price', 'Enter a price, such as 24.90.'); return 0; }
            return $v;
        };
        $cost = $money('cost');
        $perItem = $money('per_item');
        $min = $money('min_subtotal');
        $rates = null;
        if ($kind === 'weight') {
            $bands = self::parseBands((string) ($in['bands'] ?? ''));
            if ($bands === false || $bands === []) $errors['bands'] = __('shop_err_bands', 'One band per line, such as 16: 7.50 (up to 16, 7.50), and * for anything heavier.');
            else $rates = json_encode($bands);
        } elseif ($kind === 'carrier') {
            $pct = trim((string) ($in['markup_pct'] ?? ''));
            if ($pct !== '' && !preg_match('/^\d{1,3}(\.\d{1,2})?$/', $pct)) $errors['markup_pct'] = __('shop_err_percent', 'Enter a percentage, such as 10.');
            $rates = json_encode([
                'carriers' => self::codes((string) ($in['carriers'] ?? '')),
                'services' => array_values(array_filter(array_map('trim', explode(',', (string) ($in['services'] ?? ''))))),
                'markup_pct' => $pct === '' ? 0 : (float) $pct,
                'markup_cents' => $money('markup'),
            ], JSON_UNESCAPED_SLASHES);
        }
        $label = mb_substr(trim((string) ($in['label'] ?? '')), 0, 120);
        if ($label === '' && $kind !== 'carrier') $errors['label'] = __('shop_err_method_label', 'Name the method, such as Standard shipping.');
        if ($errors) return ['ok' => false, 'errors' => $errors];
        $row = [
            'zone_id' => $zoneId, 'kind' => $kind, 'label' => $label, 'cost_cents' => in_array($kind, ['flat', 'pickup'], true) ? $cost : 0,
            'per_item_cents' => $kind === 'flat' ? $perItem : 0, 'min_subtotal_cents' => $min, 'rates_json' => $rates,
            'enabled' => !empty($in['enabled']) ? 1 : 0, 'sort' => (int) ($in['sort'] ?? 0),
        ];
        $tid = current_tenant_id();
        if ($id > 0) {
            Database::update('shop_shipping_methods', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
        } else {
            $id = (int) Database::insert('shop_shipping_methods', $row + ['tenant_id' => $tid]);
        }
        return ['ok' => true, 'id' => $id];
    }

    public static function deleteMethod(int $id): void {
        Database::query("DELETE FROM shop_shipping_methods WHERE id = ? AND tenant_id = ?", [$id, current_tenant_id()]);
    }

    // ── Matching ──────────────────────────────────────────────────────────

    /** Whether a postcode fits one pattern: exact, a prefix with `*`, or a numeric range `a-b`. */
    public static function postcodeMatches(string $postcode, string $pattern): bool {
        $pc = strtoupper(str_replace(' ', '', $postcode));
        $pat = strtoupper(str_replace(' ', '', $pattern));
        if ($pc === '' || $pat === '') return false;
        if (str_ends_with($pat, '*')) return str_starts_with($pc, substr($pat, 0, -1));
        if (preg_match('/^(\d+)-(\d+)$/', $pat, $m)) {
            $digits = preg_replace('/\D.*$/', '', $pc) ?? '';
            if ($digits === '') return false;
            $n = (int) substr($digits, 0, strlen($m[1]));
            return $n >= (int) $m[1] && $n <= (int) $m[2];
        }
        return $pc === $pat;
    }

    /** Whether a zone (or a tax rate: the same fields) covers an address. */
    public static function covers(array $zone, array $address): bool {
        $country = strtoupper((string) ($address['country'] ?? ''));
        $countries = self::codes((string) ($zone['countries'] ?? ''));
        if (!in_array('*', $countries, true) && !in_array($country, $countries, true)) return false;
        $regions = self::codes((string) ($zone['regions'] ?? ''));
        if ($regions !== [] && !in_array(strtoupper(trim((string) ($address['region'] ?? ''))), $regions, true)) return false;
        $postcodes = self::codes((string) ($zone['postcodes'] ?? ''));
        if ($postcodes !== []) {
            foreach ($postcodes as $p) if (self::postcodeMatches((string) ($address['postal_code'] ?? ''), $p)) return true;
            return false;
        }
        return true;
    }

    /** The zone an address falls in: the first by sort whose own countries name it, then a `*` zone. */
    public static function zoneFor(array $address): ?array {
        $zones = self::zones();
        foreach ([false, true] as $wild) {
            foreach ($zones as $z) {
                if (in_array('*', self::codes((string) $z['countries']), true) !== $wild) continue;
                if (self::covers($z, $address)) return $z;
            }
        }
        return null;
    }

    // ── The parcel ────────────────────────────────────────────────────────

    /** The weight of what ships, in the shop's unit: the products' weights, plus the box. */
    public static function parcelWeight(array $lines): float {
        $w = 0.0;
        foreach ($lines as $l) {
            if (($l['needs_shipping'] ?? true) === false) continue;
            $w += ((float) ($l['unit_weight'] ?? 0)) * (int) ($l['qty'] ?? 1);
        }
        $box = (float) ShopStoreAPI::setting('box_weight');
        return $w + ($w > 0 || $box > 0 ? $box : 0.0);
    }

    /** How many items ship. */
    public static function itemCount(array $lines): int {
        $n = 0;
        foreach ($lines as $l) if (($l['needs_shipping'] ?? true) !== false) $n += (int) ($l['qty'] ?? 1);
        return $n;
    }

    /** The parcel as EasyPost wants it: weight in ounces (at least 1), the box's size in inches when set. */
    public static function parcel(array $lines): array {
        $grams = ShopStoreAPI::toGrams(self::parcelWeight($lines)) ?? 0.0;
        $parcel = ['weight' => max(1.0, round($grams / 28.349523125, 1))];
        $toIn = ['in' => 1.0, 'cm' => 1 / 2.54, 'mm' => 1 / 25.4, 'm' => 100 / 2.54][ShopStoreAPI::dimensionUnit()] ?? 1.0;
        $dims = [];
        foreach (['length', 'width', 'height'] as $d) {
            $v = (float) ShopStoreAPI::setting('box_' . $d);
            if ($v > 0) $dims[$d] = round($v * $toIn, 1);
        }
        return count($dims) === 3 ? $parcel + $dims : $parcel;
    }

    // ── Quoting ───────────────────────────────────────────────────────────

    /**
     * The shipping choices for a cart and an address. `$address` may be partial (country only, or nothing: the shop's
     * first country): flat, free and weight methods need only the zone; carrier rates need a postcode too, and until
     * there is one `pending` says so.
     *
     * @return array{rates: list<array<string, mixed>>, pending: bool, error: string}
     */
    public static function quote(array $lines, array $address, int $subtotal): array {
        if (!self::hasZones()) {
            $cents = ShopStoreAPI::shippingFor($subtotal, 'shipping');
            return ['rates' => [['id' => 'flat', 'label' => __('shop_ship_to_me', 'Ship to my address'), 'cents' => $cents, 'kind' => 'flat', 'carrier' => '', 'service' => '', 'days' => null]], 'pending' => false, 'error' => ''];
        }
        if (trim((string) ($address['country'] ?? '')) === '') $address['country'] = ShopStoreAPI::countries()[0] ?? '';
        $zone = self::zoneFor($address);
        if ($zone === null) return ['rates' => [], 'pending' => false, 'error' => __('shop_err_no_zone', 'We do not ship to that address yet. Contact us and we will find a way.')];
        $rates = [];
        $pending = false;
        $error = '';
        $weight = self::parcelWeight($lines);
        $items = self::itemCount($lines);
        foreach ($zone['methods'] as $m) {
            if (empty($m['enabled'])) continue;
            if ($subtotal < (int) $m['min_subtotal_cents']) continue;
            $base = ['kind' => (string) $m['kind'], 'carrier' => '', 'service' => '', 'days' => null];
            switch ($m['kind']) {
                case 'free':
                    $rates[] = ['id' => 'm' . $m['id'], 'label' => (string) $m['label'], 'cents' => 0] + $base;
                    break;
                case 'pickup':
                    $rates[] = ['id' => 'm' . $m['id'], 'label' => (string) $m['label'], 'cents' => (int) $m['cost_cents']] + $base;
                    break;
                case 'flat':
                    $rates[] = ['id' => 'm' . $m['id'], 'label' => (string) $m['label'], 'cents' => (int) $m['cost_cents'] + max(0, $items - 1) * (int) $m['per_item_cents']] + $base;
                    break;
                case 'weight':
                    $bands = json_decode((string) $m['rates_json'], true) ?: [];
                    foreach ($bands as $b) {
                        if ($b[0] === null || $weight <= (float) $b[0] + 1e-9) {
                            $rates[] = ['id' => 'm' . $m['id'], 'label' => (string) $m['label'], 'cents' => (int) $b[1]] + $base;
                            break;
                        }
                    }
                    break;
                case 'carrier':
                    if (trim((string) ($address['postal_code'] ?? '')) === '') { $pending = true; break; }
                    $res = self::carrierRates($lines, $address, json_decode((string) $m['rates_json'], true) ?: [], (string) $m['label']);
                    if ($res['error'] !== '') $error = $res['error'];
                    foreach ($res['rates'] as $r) $rates[] = $r;
                    break;
            }
        }
        usort($rates, static fn(array $a, array $b): int => $a['cents'] <=> $b['cents']);
        return ['rates' => $rates, 'pending' => $pending, 'error' => $rates === [] ? $error : ''];
    }

    /** One rate from a quote by its id, or null. */
    public static function pick(array $rates, string $id): ?array {
        foreach ($rates as $r) if ($r['id'] === $id) return $r;
        return null;
    }

    // ── EasyPost ──────────────────────────────────────────────────────────

    public static function apiKey(): string {
        $v = ShopStoreAPI::setting('easypost_key');
        if ($v === '') return '';
        return str_starts_with($v, 'enc:v1:') && function_exists('slate_decrypt_secret') ? (string) (slate_decrypt_secret($v) ?? '') : $v;
    }

    /** Keep a new EasyPost key, sealed; '' forgets it. Throws when the app secret is missing. */
    public static function setApiKey(string $key): void {
        $key = trim($key);
        if ($key === '') { ShopStoreAPI::setSetting('easypost_key', ''); return; }
        if (!function_exists('slate_encrypt_secret') || !function_exists('slate_has_app_secret') || !slate_has_app_secret()) {
            throw new \RuntimeException(__('shop_err_no_app_secret', 'Keys cannot be stored: the app secret is not configured.'));
        }
        ShopStoreAPI::setSetting('easypost_key', (string) slate_encrypt_secret($key));
    }

    public static function isTestKey(): bool {
        return str_starts_with(self::apiKey(), 'EZTK');
    }

    /** Whether carrier rates can be asked for: a key and a ship-from address. */
    public static function carrierReady(): bool {
        return self::apiKey() !== '' && ShopStoreAPI::setting('from_postcode') !== '' && ShopStoreAPI::setting('from_country') !== '';
    }

    /**
     * Check the key: the carrier accounts it can rate with.
     *
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(): array {
        if (self::apiKey() === '' && self::$transport === null) return ['ok' => false, 'message' => __('shop_easypost_no_key', 'Add an EasyPost API key first.')];
        [$code, $data, $err] = self::call('GET', '/carrier_accounts');
        if ($code < 200 || $code >= 300 || !is_array($data)) return ['ok' => false, 'message' => self::apiError($code, $data, $err)];
        $names = [];
        foreach ($data as $a) if (is_array($a) && !empty($a['readable'] ?? $a['type'] ?? '')) $names[] = (string) ($a['readable'] ?? $a['type']);
        return ['ok' => true, 'message' => $names === [] ? __('shop_easypost_ok_none', 'Connected. No carrier accounts yet: add one in EasyPost.')
            : sprintf(__('shop_easypost_ok', 'Connected (%1$s key). Carriers: %2$s.'), self::isTestKey() ? __('shop_easypost_test', 'test') : __('shop_easypost_live', 'live'), implode(', ', array_unique($names)))];
    }

    /** The ship-from address in EasyPost's shape. */
    public static function fromAddress(): array {
        $s = static fn(string $k): string => ShopStoreAPI::setting('from_' . $k);
        return array_filter([
            'name' => $s('name'), 'company' => $s('company'), 'street1' => $s('street1'), 'street2' => $s('street2'), 'city' => $s('city'),
            'state' => $s('region'), 'zip' => $s('postcode'), 'country' => $s('country'), 'phone' => $s('phone'),
        ], static fn(string $v): bool => $v !== '');
    }

    /** An order's or checkout's address in EasyPost's shape. */
    public static function toAddress(array $a): array {
        return array_filter([
            'name' => (string) ($a['name'] ?? ''), 'street1' => (string) ($a['address_line1'] ?? ''), 'street2' => (string) ($a['address_line2'] ?? ''),
            'city' => (string) ($a['city'] ?? ''), 'state' => (string) ($a['region'] ?? ''), 'zip' => (string) ($a['postal_code'] ?? ''),
            'country' => (string) ($a['country'] ?? ''), 'phone' => (string) ($a['phone'] ?? ''), 'email' => (string) ($a['email'] ?? ''),
        ], static fn(string $v): bool => $v !== '');
    }

    /** @return array{0: int, 1: ?array, 2: ?string} status, decoded body, transport error */
    private static function call(string $method, string $path, ?array $body = null): array {
        if (self::$transport !== null) return (self::$transport)($method, $path, $body);
        $key = self::apiKey();
        if ($key === '') return [0, null, 'no key'];
        if (!function_exists('curl_init')) return [0, null, 'cURL missing'];
        // SHOP_EASYPOST_API (config.php only, never a setting): a stand-in API for a test site.
        $ch = curl_init((defined('SHOP_EASYPOST_API') ? rtrim((string) SHOP_EASYPOST_API, '/') : self::API) . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERPWD => $key . ':', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $out === false ? curl_error($ch) : null;
        return [$code, is_string($out) ? (json_decode($out, true) ?: null) : null, $err];
    }

    /** An EasyPost answer's error message, for logs and the admin (never shown to shoppers). */
    private static function apiError(int $code, ?array $data, ?string $err): string {
        if ($err) return $err;
        return 'EasyPost ' . $code . ': ' . (string) ($data['error']['message'] ?? $data['error']['code'] ?? 'no answer');
    }

    /**
     * Live rates for a parcel to an address, filtered and marked up as a carrier method says, from the session cache
     * when the same address and parcel were quoted in the last 30 minutes.
     *
     * @return array{rates: list<array<string, mixed>>, error: string}
     */
    public static function carrierRates(array $lines, array $address, array $opts, string $label = ''): array {
        if (!self::carrierReady() && self::$transport === null) {
            return ['rates' => [], 'error' => __('shop_err_rates', 'Carrier rates are not available right now. Please try again in a moment.')];
        }
        $to = self::toAddress($address);
        $parcel = self::parcel($lines);
        $cacheKey = hash('sha256', json_encode([$to['zip'] ?? '', $to['country'] ?? '', $to['state'] ?? '', $to['city'] ?? '', $to['street1'] ?? '', $parcel, self::fromAddress()]));
        $tid = (string) current_tenant_id();
        $cached = $_SESSION[self::SESSION_KEY][$tid][$cacheKey] ?? null;
        if (is_array($cached) && ($cached['at'] ?? 0) > time() - self::RATE_TTL) {
            $raw = $cached['rates'];
        } else {
            [$code, $data, $err] = self::call('POST', '/shipments', ['shipment' => ['to_address' => $to, 'from_address' => self::fromAddress(), 'parcel' => $parcel]]);
            if ($code < 200 || $code >= 300 || !is_array($data)) {
                slate_log('Shop: carrier rates failed: ' . self::apiError($code, $data, $err), 'warning');
                return ['rates' => [], 'error' => __('shop_err_rates', 'Carrier rates are not available right now. Please try again in a moment.')];
            }
            $raw = [];
            foreach ((array) ($data['rates'] ?? []) as $r) {
                $raw[] = ['carrier' => (string) ($r['carrier'] ?? ''), 'service' => (string) ($r['service'] ?? ''), 'rate' => (string) ($r['rate'] ?? ''),
                          'currency' => strtoupper((string) ($r['currency'] ?? '')), 'days' => isset($r['delivery_days']) ? (int) $r['delivery_days'] : null];
            }
            $_SESSION[self::SESSION_KEY][$tid] = array_filter((array) ($_SESSION[self::SESSION_KEY][$tid] ?? []), static fn($c): bool => is_array($c) && ($c['at'] ?? 0) > time() - self::RATE_TTL);
            $_SESSION[self::SESSION_KEY][$tid][$cacheKey] = ['at' => time(), 'rates' => $raw];
        }
        $carriers = array_map('strtoupper', (array) ($opts['carriers'] ?? []));
        $services = array_map('strtolower', (array) ($opts['services'] ?? []));
        $currency = strtoupper(ShopStoreAPI::currency());
        $out = [];
        foreach ($raw as $r) {
            if ($carriers !== [] && !in_array(strtoupper($r['carrier']), $carriers, true)) continue;
            if ($services !== [] && !in_array(strtolower($r['service']), $services, true)) continue;
            if ($r['currency'] !== '' && $currency !== '' && $r['currency'] !== $currency) continue;
            $cents = ShopStoreAPI::parseMoney($r['rate']);
            if ($cents === null) continue;
            $cents = (int) round($cents * (1 + ((float) ($opts['markup_pct'] ?? 0)) / 100)) + (int) ($opts['markup_cents'] ?? 0);
            $name = trim($r['carrier'] . ' ' . self::serviceName($r['service']));
            $out[] = [
                'id' => 'ep:' . $r['carrier'] . ':' . $r['service'], 'label' => $label !== '' ? $label . ' — ' . $name : $name, 'cents' => $cents,
                'kind' => 'carrier', 'carrier' => $r['carrier'], 'service' => $r['service'], 'days' => $r['days'],
            ];
        }
        return ['rates' => $out, 'error' => ''];
    }

    /** `GroundAdvantage` → `Ground Advantage`, `FEDEX_GROUND` → `Fedex Ground`. */
    public static function serviceName(string $service): string {
        if (str_contains($service, '_') || strtoupper($service) === $service) return ucwords(strtolower(str_replace('_', ' ', $service)));
        return preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $service) ?? $service;
    }

    /**
     * Buy a shipping label for a paid order: a fresh shipment to its address, the rate the customer chose (same
     * carrier and service) or, when that is gone or they chose another method, the cheapest. Stores the tracking
     * number, the carrier's tracking page and the label's address on the order.
     *
     * @return array{ok: bool, error?: string, tracking?: string, label_url?: string}
     */
    public static function buyLabel(int $orderId): array {
        $order = ShopStoreAPI::order($orderId);
        if (!$order || !in_array($order['status'], ['paid', 'fulfilled'], true) || $order['delivery'] !== 'shipping') {
            return ['ok' => false, 'error' => __('shop_err_label_order', 'Labels are for paid orders that ship.')];
        }
        if (!empty($order['label_url'])) return ['ok' => false, 'error' => __('shop_err_label_exists', 'This order already has a label.')];
        if (!self::carrierReady() && self::$transport === null) return ['ok' => false, 'error' => __('shop_err_label_setup', 'Add your EasyPost key and ship-from address in Shipping first.')];
        $lines = [];
        foreach (ShopStoreAPI::orderItems($orderId) as $it) {
            $w = null;
            if (!empty($it['variant_id'])) $w = Database::value("SELECT weight FROM shop_variants WHERE id = ? AND tenant_id = ?", [(int) $it['variant_id'], current_tenant_id()]);
            if (($w === null || $w === '') && !empty($it['product_id'])) $w = Database::value("SELECT weight FROM shop_products WHERE id = ? AND tenant_id = ?", [(int) $it['product_id'], current_tenant_id()]);
            $lines[] = ['needs_shipping' => ($it['product_type'] ?? 'physical') === 'physical', 'unit_weight' => $w !== null && $w !== '' ? (float) $w : null, 'qty' => (int) $it['qty']];
        }
        [$code, $data, $err] = self::call('POST', '/shipments', ['shipment' => ['to_address' => self::toAddress($order), 'from_address' => self::fromAddress(), 'parcel' => self::parcel($lines)]]);
        if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['id'])) return ['ok' => false, 'error' => self::apiError($code, $data, $err)];
        $rates = array_values(array_filter((array) ($data['rates'] ?? []), static fn($r): bool => is_array($r) && !empty($r['id'])));
        if ($rates === []) return ['ok' => false, 'error' => __('shop_err_label_no_rates', 'No carrier offered a rate for this parcel.')];
        $chosen = null;
        foreach ($rates as $r) {
            if (strcasecmp((string) $r['carrier'], (string) $order['shipping_carrier']) === 0 && strcasecmp((string) $r['service'], (string) $order['shipping_service']) === 0) $chosen = $r;
        }
        if ($chosen === null) {
            usort($rates, static fn(array $a, array $b): int => (float) $a['rate'] <=> (float) $b['rate']);
            $chosen = $rates[0];
        }
        [$code, $bought, $err] = self::call('POST', '/shipments/' . rawurlencode((string) $data['id']) . '/buy', ['rate' => ['id' => (string) $chosen['id']]]);
        if ($code < 200 || $code >= 300 || !is_array($bought)) return ['ok' => false, 'error' => self::apiError($code, $bought, $err)];
        $tracking = (string) ($bought['tracking_code'] ?? '');
        // Only web addresses are kept: the label opens from the admin, and a tracking page goes to customers (https only).
        $label = (string) ($bought['postage_label']['label_url'] ?? '');
        if (!preg_match('#^https?://#i', $label)) $label = '';
        $public = (string) ($bought['tracker']['public_url'] ?? '');
        if (!preg_match('#^https://#i', $public)) $public = '';
        Database::update('shop_orders', [
            'tracking_carrier' => mb_substr((string) $chosen['carrier'], 0, 40), 'tracking_number' => $tracking !== '' ? mb_substr($tracking, 0, 80) : null,
            'tracking_url' => $public !== '' ? mb_substr($public, 0, 500) : null, 'label_url' => $label !== '' ? mb_substr($label, 0, 500) : null,
        ], 'id = ? AND tenant_id = ?', [$orderId, current_tenant_id()]);
        if (class_exists('AuditLog')) AuditLog::record('shop.label_bought', (string) $orderId);
        return ['ok' => true, 'tracking' => $tracking, 'label_url' => $label];
    }

    /** A carrier's public tracking page for a number, when the shop knows the carrier. */
    public static function trackingUrl(string $carrier, string $number): string {
        if ($number === '') return '';
        $n = rawurlencode($number);
        return match (strtoupper(preg_replace('/\s+/', '', $carrier) ?? '')) {
            'USPS'  => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $n,
            'UPS'   => 'https://www.ups.com/track?tracknum=' . $n,
            'FEDEX' => 'https://www.fedex.com/fedextrack/?trknbr=' . $n,
            'DHL', 'DHLEXPRESS' => 'https://www.dhl.com/global-en/home/tracking.html?tracking-id=' . $n,
            'CANADAPOST' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . $n,
            'BPOST' => 'https://track.bpost.cloud/btr/web/#/search?itemCode=' . $n,
            default => '',
        };
    }
}
