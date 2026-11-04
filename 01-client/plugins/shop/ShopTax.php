<?php
/**
 * Shop — tax.
 *
 * The settings' tax_mode says how prices are read:
 *
 *   inclusive  prices include tax (European VAT, the default and the Shop's behaviour before 0.4.0); the cart and
 *              receipt say how much of the total is tax when a rate applies
 *   exclusive  prices are before tax; the tax is worked out for the delivery address at checkout and added (US sales
 *              tax)
 *   none       no tax line and no tax wording
 *
 * A rate (shop_tax_rates) applies to an address by country (`*` = any), region (a state's code; empty = all) and
 * postcodes (as shipping zones read them; empty = all); the most specific match wins (postcode, then region, then
 * country, then `*`). Rates are per tax class: a product names its class ('' standard, `reduced`, `zero`), and a
 * class with no rate is not taxed. Shipping is taxed at the standard rate when that rate says so.
 *
 * The address is where the order goes; for pickup and for orders with nothing to ship, the shop's own address
 * (the ship-from address in the settings).
 *
 * Tax is worked out per line on what the customer pays for it (after any coupon) and rounded per line.
 */

require_once __DIR__ . '/ShopStoreAPI.php';
require_once __DIR__ . '/ShopShipping.php';

class ShopTax {

    public const MODES = ['inclusive', 'exclusive', 'none'];
    public const CLASSES = ['', 'reduced', 'zero'];

    public static function mode(): string {
        $m = ShopStoreAPI::setting('tax_mode');
        return in_array($m, self::MODES, true) ? $m : 'inclusive';
    }

    /** The tax's name on the page: the settings' own, or VAT (inclusive) / Sales tax (exclusive). */
    public static function label(): string {
        $l = trim(ShopStoreAPI::setting('tax_label'));
        if ($l !== '') return $l;
        return self::mode() === 'exclusive' ? __('shop_sales_tax', 'Sales tax') : __('shop_vat', 'VAT');
    }

    /** @return array<string, string> class => its name */
    public static function classLabels(): array {
        return ['' => __('shop_tax_standard', 'Standard'), 'reduced' => __('shop_tax_reduced', 'Reduced rate'), 'zero' => __('shop_tax_zero', 'Zero rate')];
    }

    // ── Rates ─────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public static function rates(): array {
        ShopStoreAPI::ensureSchema();
        return Database::rows("SELECT * FROM shop_tax_rates WHERE tenant_id = ? ORDER BY country = '*', country, region, postcodes, tax_class, id", [current_tenant_id()]);
    }

    /**
     * @param array<string, mixed> $in country, region, postcodes, tax_class, rate (percent), name, shipping
     * @return array{ok: bool, id?: int, errors?: array<string, string>}
     */
    public static function saveRate(array $in, int $id = 0): array {
        ShopStoreAPI::ensureSchema();
        $errors = [];
        $country = strtoupper(trim((string) ($in['country'] ?? '*')));
        if ($country === '') $country = '*';
        if ($country !== '*' && !preg_match('/^[A-Z]{2}$/', $country)) $errors['country'] = __('shop_err_country_code', 'Use a two-letter country code, or * for any.');
        $rate = str_replace(',', '.', trim((string) ($in['rate'] ?? '')));
        if (!preg_match('/^\d{1,2}(\.\d{1,4})?$|^100(\.0{1,4})?$/', $rate)) $errors['rate'] = __('shop_err_rate', 'Enter a percentage, such as 7.25.');
        $class = (string) ($in['tax_class'] ?? '');
        if (!in_array($class, self::CLASSES, true)) $class = '';
        $postcodes = ShopShipping::codes((string) ($in['postcodes'] ?? ''));
        foreach ($postcodes as $p) {
            if (!preg_match('/^[A-Z0-9 -]+\*?$/', $p)) { $errors['postcodes'] = __('shop_err_zone_postcodes', 'Postcodes look like 90210, 90* or 90000-90999.'); break; }
        }
        if ($errors) return ['ok' => false, 'errors' => $errors];
        $row = [
            'country' => $country, 'region' => mb_substr(strtoupper(trim((string) ($in['region'] ?? ''))), 0, 80), 'postcodes' => implode(',', $postcodes),
            'tax_class' => $class, 'rate' => $rate, 'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 80), 'shipping' => !empty($in['shipping']) ? 1 : 0,
        ];
        $tid = current_tenant_id();
        if ($id > 0) Database::update('shop_tax_rates', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
        else $id = (int) Database::insert('shop_tax_rates', $row + ['tenant_id' => $tid]);
        return ['ok' => true, 'id' => $id];
    }

    public static function deleteRate(int $id): void {
        Database::query("DELETE FROM shop_tax_rates WHERE id = ? AND tenant_id = ?", [$id, current_tenant_id()]);
    }

    /** The rate row for an address and a tax class: the most specific match, or null (not taxed). */
    public static function rateFor(array $address, string $class = '', ?array $rates = null): ?array {
        $best = null;
        $bestScore = -1;
        foreach ($rates ?? self::rates() as $r) {
            if ((string) $r['tax_class'] !== $class) continue;
            $zone = ['countries' => (string) $r['country'], 'regions' => (string) $r['region'], 'postcodes' => (string) $r['postcodes']];
            if (!ShopShipping::covers($zone, $address)) continue;
            $score = ($r['country'] !== '*' ? 1 : 0) + ((string) $r['region'] !== '' ? 2 : 0) + ((string) $r['postcodes'] !== '' ? 4 : 0);
            if ($score > $bestScore) { $best = $r; $bestScore = $score; }
        }
        return $best;
    }

    /** The address tax is worked out for: the delivery address, else the shop's own. */
    public static function addressFor(string $delivery, array $address): array {
        if ($delivery === 'shipping' && trim((string) ($address['country'] ?? '')) !== '') return $address;
        return [
            'country' => ShopStoreAPI::setting('from_country') !== '' ? ShopStoreAPI::setting('from_country') : (ShopStoreAPI::countries()[0] ?? ''),
            'region' => ShopStoreAPI::setting('from_region'), 'postal_code' => ShopStoreAPI::setting('from_postcode'),
        ];
    }

    /**
     * The tax on a cart: each line's amount after its discount (`line_cents` − `discount_cents`), at its class's rate,
     * plus shipping at the standard rate when it says so.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{cents: int, inclusive: bool, label: string, rate: ?float, pending: bool}
     */
    public static function compute(array $lines, int $shippingCents, array $address): array {
        $mode = self::mode();
        $out = ['cents' => 0, 'inclusive' => $mode !== 'exclusive', 'label' => self::label(), 'rate' => null, 'pending' => false];
        if ($mode === 'none' || $lines === []) return $out;
        $rates = self::rates();
        if ($rates === []) return $out;
        // Sales tax needs at least the state (or the country, outside states): until then it is "worked out at checkout".
        if ($mode === 'exclusive' && trim((string) ($address['country'] ?? '')) === '') { $out['pending'] = true; return $out; }
        $cents = 0;
        $seen = [];
        $tax = static function (int $amount, float $pct) use ($mode): int {
            if ($amount <= 0 || $pct <= 0) return 0;
            return $mode === 'exclusive' ? (int) round($amount * $pct / 100) : $amount - (int) round($amount / (1 + $pct / 100));
        };
        foreach ($lines as $l) {
            $r = self::rateFor($address, (string) ($l['tax_class'] ?? ''), $rates);
            if ($r === null) continue;
            $seen[] = (float) $r['rate'];
            $cents += $tax((int) $l['line_cents'] - (int) ($l['discount_cents'] ?? 0), (float) $r['rate']);
        }
        if ($shippingCents > 0) {
            $r = self::rateFor($address, '', $rates);
            if ($r !== null && !empty($r['shipping'])) $cents += $tax($shippingCents, (float) $r['rate']);
        }
        $seen = array_values(array_unique($seen));
        $out['cents'] = $cents;
        $out['rate'] = count($seen) === 1 ? $seen[0] : null;
        $r = self::rateFor($address, '', $rates);
        if ($r !== null && trim((string) $r['name']) !== '' && trim(ShopStoreAPI::setting('tax_label')) === '') $out['label'] = trim((string) $r['name']);
        return $out;
    }

    /** `7.25%` from 7.2500. */
    public static function percentText(float $rate): string {
        return rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.') . '%';
    }
}
