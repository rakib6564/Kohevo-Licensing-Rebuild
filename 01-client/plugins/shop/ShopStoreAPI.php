<?php
/**
 * Shop — the business layer: catalogue, the session cart, checkout, payment completion and order actions.
 *
 * Every admin screen, public endpoint and Studio widget goes through this class; none of them touches a shop_* table.
 *
 * Money: integer cents. A product's price is VAT-inclusive and carries its own currency; an order snapshots currency,
 * prices, titles and SKUs. A cart holds one currency (the first product's): a product priced in another is refused.
 *
 * Product types: physical (shipped or collected; has a weight and size in the shop's units), digital (files kept in
 * data/shop-files/ and delivered through per-order download links with an optional limit and expiry) and service
 * (nothing to ship). A cart with no physical product has delivery `none`: no address and no shipping.
 *
 * Catalogue extras: tags, featured, upsells and cross-sells (shop_product_links), sale dates (the sale price applies
 * between sale_starts_at and sale_ends_at), a low-stock level (the product's or the shop's; a sale that reaches it
 * emails the shop once) and a note sent to buyers. ShopImport brings products over from WooCommerce.
 *
 * Variants: a product with attributes (`Couleur: Blanc, Noir`, `Taille: S, M, L`) is sold as variants, one per
 * combination of values (shop_variants). A variant may carry its own SKU, price, sale price, stock and image, and may be
 * disabled (a combination the shop does not sell); what it leaves empty comes from the product. Such a product's own
 * stock is not used: its variants carry the stock.
 *
 * Cart: the visitor's PHP session (`$_SESSION['kohevo_shop_cart'][tenant id] = [line key => qty]`, the key being the
 * product id, or `product id:variant id`). Only ids and quantities are kept; prices, titles and stock are read again
 * every time the cart is shown or checked out.
 *
 * Payment: through the shared payment layer (`Payments`), with whichever gateway the visitor picks among those the
 * site offers for the shop (Stripe, PayPal, …). An order is created `pending_payment`; the `payment_event` listener
 * (fed by the gateway's webhook and by the return page's confirmation) moves it to `paid` through one conditional
 * UPDATE, so whichever runs second does nothing. Stock is taken when the order is paid. The order keeps the gateway
 * and its checkout reference (payment_gateway, payment_ref); a Stripe order also keeps it in stripe_session_id.
 *
 * Statuses: pending_payment → paid → fulfilled; pending_payment → cancelled; paid|fulfilled → refunded.
 */

require_once __DIR__ . '/ShopShipping.php';
require_once __DIR__ . '/ShopTax.php';
require_once __DIR__ . '/ShopCoupons.php';

class ShopStoreAPI {

    public const STATUSES = ['pending_payment', 'paid', 'fulfilled', 'cancelled', 'refunded'];
    public const PRODUCT_STATUSES = ['draft', 'published'];
    public const MAX_QTY = 99;
    public const MAX_CART_LINES = 50;
    public const MAX_IMAGES = 12;
    public const MAX_ATTRIBUTES = 3;
    public const MAX_ATTRIBUTE_VALUES = 30;
    public const MAX_VARIANTS = 150;
    public const SLUG_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,188}[a-z0-9])?$/';
    private const SESSION_KEY = 'kohevo_shop_cart';

    private static bool $schemaChecked = false;

    // ── Schema ────────────────────────────────────────────────────────────

    /** Create any missing table (idempotent; install.sql is the full schema). */
    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        $file = __DIR__ . '/install.sql';
        if (!is_file($file)) return;
        $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $stmt) {
            try {
                Database::query($stmt);
            } catch (\Throwable $e) {
                slate_log('Shop ensureSchema statement failed: ' . $e->getMessage(), 'error');
            }
        }
        // Columns added after 0.1.0 (CREATE TABLE IF NOT EXISTS leaves an existing table as it was).
        self::ensureColumn('shop_products', 'attributes_json', 'TEXT NULL AFTER `images_json`');
        self::ensureColumn('shop_order_items', 'variant_id', 'INT UNSIGNED NULL AFTER `product_id`');
        self::ensureColumn('shop_order_items', 'options_label', 'VARCHAR(255) NULL AFTER `title`');
        self::ensureColumn('shop_orders', 'payment_gateway', 'VARCHAR(32) NULL AFTER `stripe_session_id`');
        self::ensureColumn('shop_orders', 'payment_ref', 'VARCHAR(255) NULL AFTER `payment_gateway`');
        // 0.3.0: product types, weight and sizes, scheduled sales, featured, low stock, downloads, import references.
        foreach ([
            'product_type'   => "VARCHAR(16) NOT NULL DEFAULT 'physical' AFTER `stock_qty`",
            'weight'         => 'DECIMAL(10,3) NULL AFTER `product_type`',
            'length'         => 'DECIMAL(10,2) NULL AFTER `weight`',
            'width'          => 'DECIMAL(10,2) NULL AFTER `length`',
            'height'         => 'DECIMAL(10,2) NULL AFTER `width`',
            'sale_starts_at' => 'DATETIME NULL AFTER `height`',
            'sale_ends_at'   => 'DATETIME NULL AFTER `sale_starts_at`',
            'featured'       => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `sale_ends_at`',
            'low_stock'      => 'INT NULL AFTER `featured`',
            'purchase_note'  => 'TEXT NULL AFTER `low_stock`',
            'download_limit' => 'INT NULL AFTER `purchase_note`',
            'download_days'  => 'INT NULL AFTER `download_limit`',
            'external_ref'   => 'VARCHAR(64) NULL AFTER `download_days`',
        ] as $column => $definition) {
            self::ensureColumn('shop_products', $column, $definition);
        }
        foreach ([
            'weight'       => 'DECIMAL(10,3) NULL AFTER `image`',
            'length'       => 'DECIMAL(10,2) NULL AFTER `weight`',
            'width'        => 'DECIMAL(10,2) NULL AFTER `length`',
            'height'       => 'DECIMAL(10,2) NULL AFTER `width`',
            'external_ref' => 'VARCHAR(64) NULL AFTER `height`',
        ] as $column => $definition) {
            self::ensureColumn('shop_variants', $column, $definition);
        }
        self::ensureColumn('shop_order_items', 'product_type', "VARCHAR(16) NOT NULL DEFAULT 'physical' AFTER `sku`");
        // 0.4.0: tax classes, shipping zones and carrier rates, tax, coupons, tracking and labels.
        self::ensureColumn('shop_products', 'tax_class', "VARCHAR(16) NOT NULL DEFAULT '' AFTER `external_ref`");
        // 0.4.0: a product's own search title and description (empty: its name and short description).
        self::ensureColumn('shop_products', 'seo_title', 'VARCHAR(200) NULL AFTER `tax_class`');
        self::ensureColumn('shop_products', 'seo_description', 'VARCHAR(400) NULL AFTER `seo_title`');
        foreach ([
            'region'           => 'VARCHAR(80) NULL AFTER `country`',
            'discount_cents'   => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `shipping_cents`',
            'coupon_code'      => 'VARCHAR(40) NULL AFTER `discount_cents`',
            'tax_cents'        => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `coupon_code`',
            'tax_label'        => 'VARCHAR(80) NULL AFTER `tax_cents`',
            'tax_inclusive'    => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `tax_label`',
            'shipping_method'  => 'VARCHAR(160) NULL AFTER `total_cents`',
            'shipping_carrier' => 'VARCHAR(40) NULL AFTER `shipping_method`',
            'shipping_service' => 'VARCHAR(80) NULL AFTER `shipping_carrier`',
            'tracking_carrier' => 'VARCHAR(40) NULL AFTER `shipping_service`',
            'tracking_number'  => 'VARCHAR(80) NULL AFTER `tracking_carrier`',
            'tracking_url'     => 'VARCHAR(500) NULL AFTER `tracking_number`',
            'label_url'        => 'VARCHAR(500) NULL AFTER `tracking_url`',
            'shipped_at'       => 'DATETIME NULL AFTER `label_url`',
            'admin_notes'      => 'TEXT NULL AFTER `shipped_at`',
        ] as $column => $definition) {
            self::ensureColumn('shop_orders', $column, $definition);
        }
    }

    private static function ensureColumn(string $table, string $column, string $definition): void {
        try {
            $has = (int) Database::value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]);
            if ($has === 0) Database::query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        } catch (\Throwable $e) {
            slate_log("Shop ensureSchema: adding $table.$column failed: " . $e->getMessage(), 'error');
        }
    }

    // ── Settings ──────────────────────────────────────────────────────────

    /** @return array<string, string> setting => default */
    public static function settingDefaults(): array {
        return [
            'currency'          => '',
            'shipping_enabled'  => '1',
            'shipping_cents'    => '0',
            'free_over_cents'   => '0',
            'pickup_enabled'    => '1',
            'pickup_label'      => '',
            'countries'         => 'BE',
            'product_prefix'    => 'product',
            'cart_page'         => 'cart',
            'checkout_page'     => 'checkout',
            'thanks_page'       => 'order-received',
            'shop_page'         => '',
            'notify_email'      => '',
            // 0.4.0 — checkout protection (ShopGuard): limits (0 = off), Turnstile keys (the secret sealed), the pause.
            'guard_enabled'      => '1',
            'guard_ip_10min'     => '6',
            'guard_ip_day'       => '25',
            'guard_email_hour'   => '4',
            'guard_pause_hour'   => '25',
            'guard_coupon_10min' => '15',
            'turnstile_site_key' => '',
            'turnstile_secret'   => '',
            'guard_paused_until' => '',
            'guard_alerted_at'   => '',
            // 0.4.1 — selling switched off on purpose (out of season, not producing): the catalogue stays visible,
            // nothing can be added to the cart or ordered, and the message shows where Add to cart was.
            'closed'             => '0',
            'closed_message'     => '',
            'weight_unit'       => 'kg',
            'dimension_unit'    => 'cm',
            'low_stock'         => '2',
            'low_stock_email'   => '',
            // 0.4.0 — tax: inclusive (prices include it), exclusive (added at checkout) or none; its name on the page.
            'tax_mode'          => 'inclusive',
            'tax_label'         => '',
            // Where orders ship from (carrier rates, labels) and the shop's own address (tax on pickup and downloads).
            'from_name'         => '',
            'from_company'      => '',
            'from_street1'      => '',
            'from_street2'      => '',
            'from_city'         => '',
            'from_region'       => '',
            'from_postcode'     => '',
            'from_country'      => '',
            'from_phone'        => '',
            // The box a parcel is sent in (the shop's size unit) and what it weighs (the shop's weight unit).
            'box_length'        => '',
            'box_width'         => '',
            'box_height'        => '',
            'box_weight'        => '',
            // EasyPost (carrier rates and labels): the API key, sealed with the app secret.
            'easypost_key'      => '',
        ];
    }

    public const PRODUCT_TYPES = ['physical', 'digital', 'service'];
    public const WEIGHT_UNITS = ['kg', 'g', 'lb', 'oz'];
    public const DIMENSION_UNITS = ['cm', 'mm', 'm', 'in'];
    public const LINK_KINDS = ['upsell', 'cross_sell'];
    public const MAX_TAGS = 20;
    public const MAX_LINKS = 12;

    public static function weightUnit(): string {
        $u = self::setting('weight_unit');
        return in_array($u, self::WEIGHT_UNITS, true) ? $u : 'kg';
    }

    public static function dimensionUnit(): string {
        $u = self::setting('dimension_unit');
        return in_array($u, self::DIMENSION_UNITS, true) ? $u : 'cm';
    }

    /** A weight in the shop's unit converted to grams (null when unknown), for shipping by weight. */
    public static function toGrams(?float $weight, ?string $unit = null): ?float {
        if ($weight === null) return null;
        return $weight * match ($unit ?? self::weightUnit()) { 'g' => 1.0, 'lb' => 453.59237, 'oz' => 28.349523125, default => 1000.0 };
    }

    /** A measurement as people write it: `0.45`, `12`, `5.43` (no trailing zeros). */
    public static function measure(mixed $value): string {
        if ($value === null || $value === '') return '';
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    /** `12 × 7 × 3 in`, or '' when no size is known. */
    public static function dimensionsText(array $row): string {
        $parts = [self::measure($row['length'] ?? null), self::measure($row['width'] ?? null), self::measure($row['height'] ?? null)];
        if (array_filter($parts, static fn(string $p): bool => $p !== '') === []) return '';
        return implode(' × ', array_map(static fn(string $p): string => $p !== '' ? $p : '–', $parts)) . ' ' . self::dimensionUnit();
    }

    /** A measurement typed in a form: a non-negative number (comma or dot), '' for none, or false when it is not one. */
    public static function parseMeasure(mixed $raw, float $max = 100000.0): float|false|null {
        $s = str_replace([' ', "\u{00A0}"], '', trim((string) $raw));
        if ($s === '') return null;
        if (!preg_match('/^\d{1,7}([.,]\d{1,3})?$/', $s)) return false;
        $n = (float) str_replace(',', '.', $s);
        return $n <= $max ? $n : false;
    }

    /** Is selling switched off (Shop settings > Selling)? Products stay visible; cart and checkout refuse. */
    public static function closed(): bool {
        return self::setting('closed') === '1';
    }

    /** The line shown instead of Add to cart while selling is off. */
    public static function closedMessage(): string {
        $m = trim(self::setting('closed_message'));
        return $m !== '' ? $m : __('shop_closed_default', 'Currently not available.');
    }

    public static function setting(string $key): string {
        $defaults = self::settingDefaults();
        $value = Database::setting('shop.' . $key);
        return $value === null || $value === false ? ($defaults[$key] ?? '') : (string) $value;
    }

    public static function setSetting(string $key, string $value): void {
        if (array_key_exists($key, self::settingDefaults())) {
            Database::setSetting('shop.' . $key, $value);
        }
    }

    /** The shop's currency for new products (the site currency unless the shop names its own). */
    public static function currency(): string {
        $c = strtoupper(trim(self::setting('currency')));
        return preg_match('/^[A-Z]{3}$/', $c) ? $c : (function_exists('slate_default_currency') ? slate_default_currency() : 'EUR');
    }

    /** Two-letter country codes checkout accepts for delivery. */
    public static function countries(): array {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtoupper(self::setting('countries'))) ?: [] as $c) {
            if (preg_match('/^[A-Z]{2}$/', $c)) $out[$c] = true;
        }
        return $out === [] ? ['BE'] : array_keys($out);
    }

    /** `/product`: the address prefix of a product page (one to three slug segments). */
    public static function productPrefix(): string {
        $p = strtolower(trim(self::setting('product_prefix'), " /"));
        return preg_match('#^[a-z0-9-]+(/[a-z0-9-]+){0,2}$#', $p) ? $p : 'product';
    }

    /** A product's site-relative address: `/product/{slug}` (with the site's trailing slash when Studio adds one). */
    public static function productPath(string $slug): string {
        return self::withSlash('/' . self::productPrefix() . '/' . $slug);
    }

    /** A Studio page of the site, by the slug a setting names (`cart`, `checkout`, `order-received`). */
    public static function pageUrl(string $setting): string {
        $slug = strtolower(trim(self::setting($setting), " /"));
        if (!preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $slug)) $slug = self::settingDefaults()[$setting] ?? '';
        return rtrim((string) SLATE_URL, '/') . self::withSlash('/' . $slug);
    }

    /** The address "Continue shopping" leads to: the page the Shop page setting names, or '' when none is set. */
    public static function shopPageUrl(): string {
        return trim(self::setting('shop_page'), " /") === '' ? '' : self::pageUrl('shop_page');
    }

    /** A path as Studio writes the site's addresses: with a trailing slash when the site's setting asks for one. */
    private static function withSlash(string $path): string {
        $slash = class_exists(\Slate\Module\StudioBuilder\Meta\PageRoutes::class) && \Slate\Module\StudioBuilder\Meta\PageRoutes::trailingSlash((int) current_tenant_id());
        return $slash ? rtrim($path, '/') . '/' : $path;
    }

    /**
     * An image address as a page outside Studio can use it: a site path (`/uploads/…`) gains the site's base URL, so it
     * still works when the site lives in a subfolder (Studio rebases its own pages; the Shop's fragments are not Studio's).
     */
    public static function assetUrl(string $url): string {
        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? rtrim((string) SLATE_URL, '/') . $url : $url;
    }

    /** A Shop endpoint (`public/cart.php`). */
    public static function endpoint(string $file): string {
        return function_exists('plugin_url') ? plugin_url('shop', 'public/' . $file) : rtrim((string) SLATE_URL, '/') . '/plugins/shop/public/' . $file;
    }

    public static function money(int $cents, ?string $currency = null): string {
        if (!function_exists('slate_format_price_plain')) {
            return trim(number_format($cents / 100, 2, '.', ',') . ' ' . ($currency ?? 'EUR'));
        }
        return slate_format_price_plain($cents, $currency ?? self::currency());
    }

    // ── Categories ────────────────────────────────────────────────────────

    /** @return list<array{id: int, name: string, slug: string, sort_order: int, product_count: int}> */
    public static function categories(): array {
        self::ensureSchema();
        $rows = Database::rows(
            "SELECT c.id, c.name, c.slug, c.sort_order,
                    (SELECT COUNT(*) FROM shop_product_categories pc WHERE pc.category_id = c.id AND pc.tenant_id = c.tenant_id) AS product_count
               FROM shop_categories c WHERE c.tenant_id = ? ORDER BY c.sort_order, c.name",
            [current_tenant_id()]);
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'],
            'sort_order' => (int) $r['sort_order'], 'product_count' => (int) $r['product_count'],
        ], $rows);
    }

    /** @return array{ok: bool, id?: int, error?: string} */
    public static function saveCategory(array $in, int $id = 0): array {
        self::ensureSchema();
        $tid = current_tenant_id();
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') return ['ok' => false, 'error' => __('shop_err_name_required', 'A name is required.')];
        $slug = self::slugify((string) ($in['slug'] ?? '') !== '' ? (string) $in['slug'] : $name, 120);
        $taken = (int) Database::value("SELECT COUNT(*) FROM shop_categories WHERE tenant_id = ? AND slug = ? AND id <> ?", [$tid, $slug, $id]);
        if ($taken > 0) return ['ok' => false, 'error' => __('shop_err_slug_taken', 'That address is already used.')];
        $row = ['name' => mb_substr($name, 0, 120), 'slug' => $slug, 'sort_order' => (int) ($in['sort_order'] ?? 0)];
        if ($id > 0) {
            Database::update('shop_categories', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
        } else {
            $id = Database::insert('shop_categories', $row + ['tenant_id' => $tid]);
        }
        return ['ok' => true, 'id' => $id];
    }

    public static function deleteCategory(int $id): void {
        $tid = current_tenant_id();
        Database::delete('shop_product_categories', 'category_id = ? AND tenant_id = ?', [$id, $tid]);
        Database::delete('shop_categories', 'id = ? AND tenant_id = ?', [$id, $tid]);
    }

    // ── Products ──────────────────────────────────────────────────────────

    public static function slugify(string $text, int $max = 190): string {
        $s = strtolower(trim($text));
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = strtolower($t);
        }
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        $s = substr($s, 0, $max);
        return trim($s, '-') !== '' ? trim($s, '-') : 'item-' . bin2hex(random_bytes(3));
    }

    /** A product row of this tenant (any status), or null. */
    public static function productRow(int $id): ?array {
        self::ensureSchema();
        return Database::row("SELECT * FROM shop_products WHERE id = ? AND tenant_id = ?", [$id, current_tenant_id()]);
    }

    /** A published product, by its slug, in the public shape (publicProduct), or null. */
    public static function productBySlug(string $slug): ?array {
        if (!preg_match(self::SLUG_PATTERN, $slug)) return null;
        self::ensureSchema();
        $row = Database::row("SELECT * FROM shop_products WHERE tenant_id = ? AND slug = ? AND status = 'published'", [current_tenant_id(), $slug]);
        return $row ? self::publicProduct($row) : null;
    }

    /** A published product by id in the public shape, or null. */
    public static function publishedProduct(int $id): ?array {
        $row = self::productRow($id);
        return ($row && $row['status'] === 'published') ? self::publicProduct($row) : null;
    }

    /** @return list<string> image URLs of a product row */
    public static function images(array $row): array {
        $list = json_decode((string) ($row['images_json'] ?? ''), true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $url) {
            if (is_string($url) && self::isImageUrl($url)) $out[] = $url;
        }
        return array_slice($out, 0, self::MAX_IMAGES);
    }

    /** An image address a product may show: a site path, or an http(s) URL. Never `javascript:`, `data:` or a protocol-relative URL. */
    public static function isImageUrl(string $url): bool {
        if ($url === '' || strlen($url) > 500 || preg_match('/[\s"\'<>]/', $url)) return false;
        if (str_starts_with($url, '//')) return false;
        return str_starts_with($url, '/') || (bool) preg_match('#^https?://[^/]+#i', $url);
    }

    /**
     * The price a customer pays now: the sale price when one is set, lower, and (when the row has sale dates) between
     * sale_starts_at and sale_ends_at. $now is for tests.
     */
    public static function effectivePrice(array $row, ?int $now = null): int {
        $sale = $row['sale_price_cents'] ?? null;
        $on = $sale !== null && $sale !== '' && (int) $sale < (int) $row['price_cents'] && self::saleWindowOpen($row, $now);
        return $on ? (int) $sale : (int) $row['price_cents'];
    }

    /** Whether a row's sale dates (when it has any) include the current moment. */
    public static function saleWindowOpen(array $row, ?int $now = null): bool {
        $now ??= time();
        $from = (string) ($row['sale_starts_at'] ?? '');
        $to = (string) ($row['sale_ends_at'] ?? '');
        if ($from !== '' && ($t = strtotime($from)) !== false && $now < $t) return false;
        if ($to !== '' && ($t = strtotime($to)) !== false && $now >= $t) return false;
        return true;
    }

    /** The SQL for "the price paid now" of a shop_products row aliased `p` (one placeholder: the current time). */
    private static function priceNowSql(): string {
        return "CASE WHEN p.sale_price_cents IS NOT NULL AND p.sale_price_cents < p.price_cents
                       AND (p.sale_starts_at IS NULL OR p.sale_starts_at <= ?) AND (p.sale_ends_at IS NULL OR p.sale_ends_at > ?)
                      THEN p.sale_price_cents ELSE p.price_cents END";
    }

    /** Units a visitor can buy (null: stock not tracked). */
    public static function available(array $row): ?int {
        return ($row['stock_qty'] ?? null) === null ? null : max(0, (int) $row['stock_qty']);
    }

    /** @return array<int, list<array{id: int, name: string, slug: string}>> product id => its categories */
    public static function categoriesOf(array $productIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::rows(
            "SELECT pc.product_id, c.id, c.name, c.slug FROM shop_product_categories pc
               JOIN shop_categories c ON c.id = pc.category_id AND c.tenant_id = pc.tenant_id
              WHERE pc.tenant_id = ? AND pc.product_id IN ($marks) ORDER BY c.sort_order, c.name",
            array_merge([current_tenant_id()], $ids));
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['product_id']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug']];
        }
        return $out;
    }

    /**
     * The public shape of a product: everything a visitor may see, nothing else.
     *
     * @param ?list<array{id: int, name: string, slug: string}> $categories (read when null)
     * @param ?list<array{id: int, name: string, slug: string}> $tags (read when null)
     */
    public static function publicProduct(array $row, ?array $categories = null, ?array $variantRows = null, ?array $tags = null): array {
        $id = (int) $row['id'];
        $images = self::images($row);
        $attributes = self::attributes($row);
        $variants = [];
        if ($attributes !== []) {
            foreach ($variantRows ?? self::variantRows($id, true) as $v) {
                $variants[] = self::publicVariant($v, $row);
            }
        }
        $hasVariants = $attributes !== [];
        $available = $hasVariants ? null : self::available($row);
        $price = (int) $row['price_cents'];
        $effective = self::effectivePrice($row);
        $min = $max = $effective;
        if ($variants !== []) {
            $prices = array_column($variants, 'effective_cents');
            [$min, $max] = [min($prices), max($prices)];
        }
        $inStock = $hasVariants
            ? array_filter($variants, static fn(array $v): bool => $v['in_stock']) !== []
            : ($available === null || $available > 0);
        return [
            'id'                => $id,
            'slug'              => (string) $row['slug'],
            'title'             => (string) $row['title'],
            'sku'               => (string) ($row['sku'] ?? ''),
            'price_cents'       => $price,
            'sale_price_cents'  => $effective < $price ? $effective : null,
            'effective_cents'   => $effective,
            'on_sale'           => $effective < $price,
            'currency'          => (string) $row['currency'],
            'short_description' => (string) ($row['short_description'] ?? ''),
            'description'       => (string) ($row['description'] ?? ''),
            'images'            => $images,
            'image'             => $images[0] ?? '',
            'stock_qty'         => $available,
            'in_stock'          => $inStock,
            'has_variants'      => $hasVariants,
            'attributes'        => $attributes,
            'variants'          => $variants,
            'min_cents'         => $min,
            'max_cents'         => $max,
            'categories'        => $categories ?? (self::categoriesOf([$id])[$id] ?? []),
            'tags'              => $tags ?? (self::tagsOf([$id])[$id] ?? []),
            'product_type'      => self::productType($row),
            'needs_shipping'    => self::productType($row) === 'physical',
            'weight'            => self::measure($row['weight'] ?? null),
            'weight_unit'       => self::weightUnit(),
            'dimensions'        => self::dimensionsText($row),
            'featured'          => !empty($row['featured']),
            'sale_ends_at'      => $effective < $price ? (string) ($row['sale_ends_at'] ?? '') : '',
            'seo_title'         => (string) ($row['seo_title'] ?? ''),
            'seo_description'   => (string) ($row['seo_description'] ?? ''),
            'url'               => self::productPath((string) $row['slug']),
            'updated_at'        => (string) ($row['updated_at'] ?? ''),
            'created_at'        => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** physical, digital or service (anything unknown is physical). */
    public static function productType(array $row): string {
        $t = (string) ($row['product_type'] ?? 'physical');
        return in_array($t, self::PRODUCT_TYPES, true) ? $t : 'physical';
    }

    /**
     * Published products for a listing.
     *
     * `ids` keeps only those products (in that order when `order` is `ids`); `exclude` leaves some out; `featured`
     * keeps the featured ones; `tag` keeps those with that tag.
     *
     * @param array{category?: string, tag?: string, featured?: bool, page?: int, per_page?: int, order?: string, ids?: list<int>, exclude?: list<int>} $q
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function listProducts(array $q = []): array {
        self::ensureSchema();
        $tid = current_tenant_id();
        $perPage = max(1, min(48, (int) ($q['per_page'] ?? 12)));
        $page = max(1, (int) ($q['page'] ?? 1));
        $where = "p.tenant_id = ? AND p.status = 'published'";
        $args = [$tid];
        $category = (string) ($q['category'] ?? '');
        if ($category !== '' && preg_match('/^[a-z0-9-]{1,120}$/', $category)) {
            $where .= " AND EXISTS (SELECT 1 FROM shop_product_categories pc JOIN shop_categories c ON c.id = pc.category_id AND c.tenant_id = pc.tenant_id
                                     WHERE pc.product_id = p.id AND pc.tenant_id = p.tenant_id AND c.slug = ?)";
            $args[] = $category;
        }
        $tag = (string) ($q['tag'] ?? '');
        if ($tag !== '' && preg_match('/^[a-z0-9-]{1,80}$/', $tag)) {
            $where .= " AND EXISTS (SELECT 1 FROM shop_product_tags pt JOIN shop_tags t ON t.id = pt.tag_id AND t.tenant_id = pt.tenant_id
                                     WHERE pt.product_id = p.id AND pt.tenant_id = p.tenant_id AND t.slug = ?)";
            $args[] = $tag;
        }
        if (!empty($q['featured'])) $where .= ' AND p.featured = 1';
        // A visitor's search: the words in the title, SKU, short description or tags.
        $search = trim(mb_substr((string) ($q['q'] ?? ''), 0, 80));
        foreach (array_slice(preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
            $like = '%' . addcslashes($word, '%_\\') . '%';
            $where .= " AND (p.title LIKE ? OR p.sku LIKE ? OR p.short_description LIKE ? OR EXISTS (SELECT 1 FROM shop_product_tags pt JOIN shop_tags t ON t.id = pt.tag_id AND t.tenant_id = pt.tenant_id
                                     WHERE pt.product_id = p.id AND pt.tenant_id = p.tenant_id AND t.name LIKE ?))";
            array_push($args, $like, $like, $like, $like);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($q['ids'] ?? [])))));
        if (array_key_exists('ids', $q)) {
            if ($ids === []) return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
            $where .= ' AND p.id IN (' . implode(',', $ids) . ')';
        }
        $exclude = array_values(array_unique(array_filter(array_map('intval', (array) ($q['exclude'] ?? [])))));
        if ($exclude !== []) $where .= ' AND p.id NOT IN (' . implode(',', $exclude) . ')';
        $now = date('Y-m-d H:i:s');
        $priceNow = self::priceNowSql();
        [$order, $orderArgs] = match ((string) ($q['order'] ?? '')) {
            'newest'     => ['p.created_at DESC, p.id DESC', []],
            'price_asc'  => [$priceNow . ' ASC, p.id', [$now, $now]],
            'price_desc' => [$priceNow . ' DESC, p.id', [$now, $now]],
            'title'      => ['p.title ASC, p.id', []],
            'random'     => ['RAND()', []],
            'ids'        => [$ids !== [] ? 'FIELD(p.id, ' . implode(',', $ids) . ')' : 'p.sort_order ASC, p.id', []],
            default      => ['p.sort_order ASC, p.created_at DESC, p.id DESC', []],
        };
        $total = (int) Database::value("SELECT COUNT(*) FROM shop_products p WHERE $where", $args);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $rows = Database::rows("SELECT p.* FROM shop_products p WHERE $where ORDER BY $order LIMIT $perPage OFFSET $offset", array_merge($args, $orderArgs));
        $cats = self::categoriesOf(array_column($rows, 'id'));
        $tags = self::tagsOf(array_column($rows, 'id'));
        $vars = self::variantsOf(array_column($rows, 'id'));
        return [
            'rows'  => array_map(static fn(array $r): array => self::publicProduct($r, $cats[(int) $r['id']] ?? [], $vars[(int) $r['id']] ?? [], $tags[(int) $r['id']] ?? []), $rows),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /** @return list<array{slug: string, title: string, updated_at: string}> every published product, for the sitemap and the menu editor */
    public static function sitemap(int $limit = 5000): array {
        self::ensureSchema();
        $rows = Database::rows("SELECT slug, title, updated_at FROM shop_products WHERE tenant_id = ? AND status = 'published' ORDER BY id LIMIT " . max(1, $limit), [current_tenant_id()]);
        return array_map(static fn(array $r): array => ['slug' => (string) $r['slug'], 'title' => (string) $r['title'], 'updated_at' => (string) $r['updated_at']], $rows);
    }

    // ── Tags ──────────────────────────────────────────────────────────────

    /** @return list<array{id: int, name: string, slug: string, product_count: int}> */
    public static function tags(): array {
        self::ensureSchema();
        $rows = Database::rows(
            "SELECT t.id, t.name, t.slug, (SELECT COUNT(*) FROM shop_product_tags pt WHERE pt.tag_id = t.id AND pt.tenant_id = t.tenant_id) AS product_count
               FROM shop_tags t WHERE t.tenant_id = ? ORDER BY t.name",
            [current_tenant_id()]);
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'product_count' => (int) $r['product_count']], $rows);
    }

    /** @return array<int, list<array{id: int, name: string, slug: string}>> product id => its tags */
    public static function tagsOf(array $productIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($ids === []) return [];
        self::ensureSchema();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::rows(
            "SELECT pt.product_id, t.id, t.name, t.slug FROM shop_product_tags pt JOIN shop_tags t ON t.id = pt.tag_id AND t.tenant_id = pt.tenant_id
              WHERE pt.tenant_id = ? AND pt.product_id IN ($marks) ORDER BY t.name", array_merge([current_tenant_id()], $ids)) as $r) {
            $out[(int) $r['product_id']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug']];
        }
        return $out;
    }

    /** `Vegan, Gift box`: tag names from a form (comma separated), cleaned, repeats dropped. @return list<string> */
    public static function parseTags(mixed $raw): array {
        $list = is_array($raw) ? $raw : (preg_split('/\s*[,;\n]\s*/', (string) $raw) ?: []);
        $out = [];
        foreach ($list as $name) {
            $name = mb_substr(trim(strip_tags((string) $name)), 0, 80);
            if ($name !== '' && !in_array(mb_strtolower($name), array_map('mb_strtolower', $out), true)) $out[] = $name;
        }
        return array_slice($out, 0, self::MAX_TAGS);
    }

    /** Give a product exactly these tags (by name), creating the ones the shop does not have yet. */
    public static function setProductTags(int $productId, array $names): void {
        $tid = current_tenant_id();
        Database::delete('shop_product_tags', 'product_id = ? AND tenant_id = ?', [$productId, $tid]);
        foreach (self::parseTags($names) as $name) {
            $slug = self::slugify($name, 80);
            $tagId = (int) Database::value("SELECT id FROM shop_tags WHERE tenant_id = ? AND slug = ?", [$tid, $slug]);
            if ($tagId === 0) $tagId = Database::insert('shop_tags', ['tenant_id' => $tid, 'name' => $name, 'slug' => $slug]);
            Database::query("INSERT IGNORE INTO shop_product_tags (tenant_id, product_id, tag_id) VALUES (?, ?, ?)", [$tid, $productId, $tagId]);
        }
    }

    /** Remove the tags no product uses any more. */
    public static function pruneTags(): int {
        return Database::query("DELETE FROM shop_tags WHERE tenant_id = ? AND id NOT IN (SELECT tag_id FROM shop_product_tags WHERE tenant_id = ?)",
            [current_tenant_id(), current_tenant_id()])->rowCount();
    }

    // ── Linked products ───────────────────────────────────────────────────

    /** @return list<int> the products linked to a product as upsells or cross-sells, in their order */
    public static function linkedIds(int $productId, string $kind): array {
        if (!in_array($kind, self::LINK_KINDS, true)) return [];
        self::ensureSchema();
        return array_map('intval', array_column(Database::rows(
            "SELECT linked_id FROM shop_product_links WHERE tenant_id = ? AND product_id = ? AND kind = ? ORDER BY sort_order, linked_id",
            [current_tenant_id(), $productId, $kind]), 'linked_id'));
    }

    /** Link products to a product (upsells or cross-sells); itself and unknown ids are dropped. */
    public static function setLinks(int $productId, string $kind, array $ids): void {
        if (!in_array($kind, self::LINK_KINDS, true)) return;
        $tid = current_tenant_id();
        Database::delete('shop_product_links', 'product_id = ? AND kind = ? AND tenant_id = ?', [$productId, $kind, $tid]);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0 && $i !== $productId)));
        if ($ids === []) return;
        $known = array_map('intval', array_column(Database::rows(
            'SELECT id FROM shop_products WHERE tenant_id = ? AND id IN (' . implode(',', $ids) . ')', [$tid]), 'id'));
        $n = 0;
        foreach ($ids as $id) {
            if (!in_array($id, $known, true) || $n >= self::MAX_LINKS) continue;
            Database::insert('shop_product_links', ['tenant_id' => $tid, 'product_id' => $productId, 'kind' => $kind, 'linked_id' => $id, 'sort_order' => $n++]);
        }
    }

    /**
     * Products to show next to a product: its upsells, its cross-sells, or (`related`) others in its categories, and
     * when it has none, others sharing a tag. Published only, the product itself never.
     *
     * @return list<array<string, mixed>> public shapes
     */
    public static function relatedProducts(int $productId, string $source = 'related', int $limit = 4): array {
        $limit = max(1, min(48, $limit));
        if (in_array($source, self::LINK_KINDS, true)) {
            return self::listProducts(['ids' => self::linkedIds($productId, $source), 'order' => 'ids', 'per_page' => $limit])['rows'];
        }
        $cats = array_column(self::categoriesOf([$productId])[$productId] ?? [], 'slug');
        foreach ($cats as $slug) {
            $rows = self::listProducts(['category' => $slug, 'exclude' => [$productId], 'order' => 'random', 'per_page' => $limit])['rows'];
            if ($rows !== []) return $rows;
        }
        foreach (array_column(self::tagsOf([$productId])[$productId] ?? [], 'slug') as $slug) {
            $rows = self::listProducts(['tag' => $slug, 'exclude' => [$productId], 'order' => 'random', 'per_page' => $limit])['rows'];
            if ($rows !== []) return $rows;
        }
        return [];
    }

    /** Cross-sells of what is in the cart that are not in it already. @return list<array<string, mixed>> */
    public static function cartCrossSells(int $limit = 4): array {
        $inCart = array_values(array_unique(array_column(self::cartLines(), 'product_id')));
        $ids = [];
        foreach ($inCart as $pid) foreach (self::linkedIds($pid, 'cross_sell') as $id) if (!in_array($id, $inCart, true)) $ids[] = $id;
        $ids = array_values(array_unique($ids));
        return $ids === [] ? [] : self::listProducts(['ids' => $ids, 'order' => 'ids', 'per_page' => $limit])['rows'];
    }

    /**
     * Create or update a product from the admin form.
     *
     * @param array<string, mixed> $in posted fields: title, slug, sku, price, sale_price, stock_qty, track_stock,
     *                                 short_description, description, images (one URL per line), status, sort_order, categories[]
     * @return array{ok: bool, id?: int, errors?: array<string, string>}
     */
    public static function saveProduct(array $in, int $id = 0): array {
        self::ensureSchema();
        $tid = current_tenant_id();
        $errors = [];
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') $errors['title'] = __('shop_err_title_required', 'A title is required.');
        $slug = trim((string) ($in['slug'] ?? '')) !== '' ? self::slugify((string) $in['slug']) : self::slugify($title);
        if ((int) Database::value("SELECT COUNT(*) FROM shop_products WHERE tenant_id = ? AND slug = ? AND id <> ?", [$tid, $slug, $id]) > 0) {
            $errors['slug'] = __('shop_err_slug_taken', 'That address is already used.');
        }
        $price = self::parseMoney($in['price'] ?? '');
        if ($price === null) $errors['price'] = __('shop_err_price', 'Enter a price, such as 24.90.');
        $sale = trim((string) ($in['sale_price'] ?? '')) === '' ? null : self::parseMoney($in['sale_price']);
        if (trim((string) ($in['sale_price'] ?? '')) !== '' && $sale === null) $errors['sale_price'] = __('shop_err_price', 'Enter a price, such as 24.90.');
        if ($sale !== null && $price !== null && $sale >= $price) $errors['sale_price'] = __('shop_err_sale_lower', 'The sale price must be lower than the price.');
        $stock = null;
        if (!empty($in['track_stock'])) {
            $raw = trim((string) ($in['stock_qty'] ?? ''));
            if (!preg_match('/^\d{1,7}$/', $raw)) {
                $errors['stock_qty'] = __('shop_err_stock', 'Enter the number in stock (0 or more).');
            } else {
                $stock = (int) $raw;
            }
        }
        $images = [];
        foreach (preg_split('/\R/', (string) ($in['images'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!self::isImageUrl($line)) {
                $errors['images'] = __('shop_err_image', 'Each image must be a site path (/media/…) or an https:// address.');
                break;
            }
            $images[] = $line;
        }
        if (count($images) > self::MAX_IMAGES) $errors['images'] = sprintf(__('shop_err_images_many', 'At most %d images.'), self::MAX_IMAGES);
        $status = in_array($in['status'] ?? '', self::PRODUCT_STATUSES, true) ? (string) $in['status'] : 'draft';
        $attributes = null;
        if (array_key_exists('attributes', $in)) {
            $parsed = is_array($in['attributes']) ? ['attributes' => self::attributes(['attributes_json' => json_encode($in['attributes'])])] : self::parseAttributes((string) $in['attributes']);
            if (isset($parsed['error'])) $errors['attributes'] = $parsed['error'];
            $attributes = $parsed['attributes'];
            if (!isset($errors['attributes'])) self::variantRowsFor($attributes, is_array($in['variants'] ?? null) ? $in['variants'] : [], $errors);
        }
        $type = in_array($in['product_type'] ?? '', self::PRODUCT_TYPES, true) ? (string) $in['product_type'] : 'physical';
        $measures = [];
        foreach (['weight', 'length', 'width', 'height'] as $k) {
            $m = self::parseMeasure($in[$k] ?? '');
            if ($m === false) $errors[$k] = __('shop_err_measure', 'Enter a number, such as 0.45, or leave it empty.');
            $measures[$k] = $m === false ? null : $m;
        }
        $saleFrom = self::parseDateTime($in['sale_starts_at'] ?? '');
        $saleTo = self::parseDateTime($in['sale_ends_at'] ?? '');
        if ($saleFrom === false) $errors['sale_starts_at'] = __('shop_err_date', 'Enter a date, such as 2026-12-01 09:00.');
        if ($saleTo === false) $errors['sale_ends_at'] = __('shop_err_date', 'Enter a date, such as 2026-12-01 09:00.');
        if (is_string($saleFrom) && is_string($saleTo) && $saleTo <= $saleFrom) $errors['sale_ends_at'] = __('shop_err_sale_dates', 'The sale must end after it starts.');
        $whole = static function (string $k, int $max) use ($in, &$errors): ?int {
            $raw = trim((string) ($in[$k] ?? ''));
            if ($raw === '') return null;
            if (!preg_match('/^\d{1,7}$/', $raw) || (int) $raw > $max) {
                $errors[$k] = __('shop_err_whole_number', 'Enter a whole number, or leave it empty.');
                return null;
            }
            return (int) $raw;
        };
        $lowStock = $whole('low_stock', 100000);
        $downloadLimit = $whole('download_limit', 1000);
        $downloadDays = $whole('download_days', 3650);
        if ($errors !== []) return ['ok' => false, 'errors' => $errors];

        $existing = $id > 0 ? self::productRow($id) : null;
        if ($id > 0 && !$existing) return ['ok' => false, 'errors' => ['_' => __('shop_err_not_found', 'That product no longer exists.')]];
        $row = [
            'title'             => mb_substr($title, 0, 200),
            'slug'              => $slug,
            'sku'               => trim((string) ($in['sku'] ?? '')) !== '' ? mb_substr(trim((string) $in['sku']), 0, 64) : null,
            'price_cents'       => $price,
            'sale_price_cents'  => $sale,
            'currency'          => $existing['currency'] ?? self::currency(),
            'short_description' => trim((string) ($in['short_description'] ?? '')) !== '' ? mb_substr(trim((string) $in['short_description']), 0, 1000) : null,
            'description'       => trim((string) ($in['description'] ?? '')) !== '' ? mb_substr(trim((string) $in['description']), 0, 20000) : null,
            'images_json'       => json_encode($images, JSON_UNESCAPED_SLASHES),
            'stock_qty'         => $stock,
            'product_type'      => $type,
            'weight'            => $type === 'physical' ? $measures['weight'] : null,
            'length'            => $type === 'physical' ? $measures['length'] : null,
            'width'             => $type === 'physical' ? $measures['width'] : null,
            'height'            => $type === 'physical' ? $measures['height'] : null,
            'sale_starts_at'    => $sale !== null ? $saleFrom : null,
            'sale_ends_at'      => $sale !== null ? $saleTo : null,
            'featured'          => !empty($in['featured']) ? 1 : 0,
            // Kept as it is when not sent (an importer's update), else one of the classes.
            'tax_class'         => array_key_exists('tax_class', $in)
                ? (in_array((string) $in['tax_class'], ShopTax::CLASSES, true) ? (string) $in['tax_class'] : '')
                : ($id > 0 ? (string) (self::productRow($id)['tax_class'] ?? '') : ''),
            // Kept as they are when not sent (an importer's update).
            'seo_title'         => array_key_exists('seo_title', $in) ? (trim((string) $in['seo_title']) !== '' ? mb_substr(trim((string) $in['seo_title']), 0, 200) : null) : ($existing['seo_title'] ?? null),
            'seo_description'   => array_key_exists('seo_description', $in) ? (trim((string) $in['seo_description']) !== '' ? mb_substr(trim((string) $in['seo_description']), 0, 400) : null) : ($existing['seo_description'] ?? null),
            'low_stock'         => $lowStock,
            'purchase_note'     => trim((string) ($in['purchase_note'] ?? '')) !== '' ? mb_substr(trim((string) $in['purchase_note']), 0, 2000) : null,
            'download_limit'    => $type === 'digital' ? $downloadLimit : null,
            'download_days'     => $type === 'digital' ? $downloadDays : null,
            'status'            => $status,
            'sort_order'        => (int) ($in['sort_order'] ?? 0),
        ];
        if (array_key_exists('external_ref', $in)) {
            $row['external_ref'] = trim((string) $in['external_ref']) !== '' ? mb_substr(trim((string) $in['external_ref']), 0, 64) : null;
        }
        if ($id > 0) {
            Database::update('shop_products', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
        } else {
            $id = Database::insert('shop_products', $row + ['tenant_id' => $tid]);
        }
        self::setProductCategories($id, array_map('intval', (array) ($in['categories'] ?? [])));
        if (array_key_exists('tags', $in)) {
            self::setProductTags($id, self::parseTags($in['tags']));
            self::pruneTags();
        }
        foreach (['upsell' => 'upsells', 'cross_sell' => 'cross_sells'] as $kind => $field) {
            if (array_key_exists($field, $in)) self::setLinks($id, $kind, (array) $in[$field]);
        }
        if ($attributes !== null) {
            $res = self::saveVariants($id, $attributes, is_array($in['variants'] ?? null) ? $in['variants'] : []);
            if (!$res['ok']) return ['ok' => false, 'id' => $id, 'errors' => $res['errors'] ?? []];
        }
        return ['ok' => true, 'id' => $id];
    }

    /** @param list<int> $categoryIds */
    public static function setProductCategories(int $productId, array $categoryIds): void {
        $tid = current_tenant_id();
        Database::delete('shop_product_categories', 'product_id = ? AND tenant_id = ?', [$productId, $tid]);
        $known = array_column(self::categories(), 'id');
        foreach (array_unique($categoryIds) as $cid) {
            if (in_array($cid, $known, true)) {
                Database::insert('shop_product_categories', ['tenant_id' => $tid, 'product_id' => $productId, 'category_id' => $cid]);
            }
        }
    }

    /** Delete a product; one that was ever ordered is set back to draft instead, so order history keeps its link. */
    public static function deleteProduct(int $id): string {
        $tid = current_tenant_id();
        $ordered = (int) Database::value("SELECT COUNT(*) FROM shop_order_items WHERE product_id = ? AND tenant_id = ?", [$id, $tid]);
        if ($ordered > 0) {
            Database::update('shop_products', ['status' => 'draft'], 'id = ? AND tenant_id = ?', [$id, $tid]);
            return 'unpublished';
        }
        Database::delete('shop_product_categories', 'product_id = ? AND tenant_id = ?', [$id, $tid]);
        Database::delete('shop_product_tags', 'product_id = ? AND tenant_id = ?', [$id, $tid]);
        Database::query("DELETE FROM shop_product_links WHERE tenant_id = ? AND (product_id = ? OR linked_id = ?)", [$tid, $id, $id]);
        foreach (self::productFiles($id) as $f) self::deleteProductFile((int) $f['id']);
        Database::delete('shop_variants', 'product_id = ? AND tenant_id = ?', [$id, $tid]);
        Database::delete('shop_products', 'id = ? AND tenant_id = ?', [$id, $tid]);
        self::pruneTags();
        return 'deleted';
    }

    // ── Variants ──────────────────────────────────────────────────────────

    /** @return list<array{name: string, values: list<string>}> a product row's attributes (none: a simple product) */
    public static function attributes(array $row): array {
        $list = json_decode((string) ($row['attributes_json'] ?? ''), true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $a) {
            if (!is_array($a) || !is_string($a['name'] ?? null) || !is_array($a['values'] ?? null)) continue;
            $values = array_values(array_filter($a['values'], static fn($v): bool => is_string($v) && $v !== ''));
            if (trim($a['name']) !== '' && $values !== []) $out[] = ['name' => $a['name'], 'values' => $values];
        }
        return array_slice($out, 0, self::MAX_ATTRIBUTES);
    }

    /**
     * Attributes from the admin form's text, one per line: `Couleur: Blanc, Noir`. Values are separated by commas (or
     * `|`); repeated names and values are dropped.
     *
     * @return array{attributes: list<array{name: string, values: list<string>}>, error?: string}
     */
    public static function parseAttributes(string $text): array {
        $out = [];
        $seen = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!str_contains($line, ':')) return ['attributes' => [], 'error' => __('shop_err_attribute_line', 'Write each attribute as “Name: value, value”.')];
            [$name, $raw] = array_map('trim', explode(':', $line, 2));
            $name = mb_substr($name, 0, 60);
            $values = [];
            foreach (preg_split('/\s*[,|]\s*/', $raw) ?: [] as $v) {
                $v = mb_substr(trim($v), 0, 60);
                if ($v !== '' && !in_array(mb_strtolower($v), array_map('mb_strtolower', $values), true)) $values[] = $v;
            }
            if ($name === '' || $values === []) return ['attributes' => [], 'error' => __('shop_err_attribute_line', 'Write each attribute as “Name: value, value”.')];
            if (isset($seen[mb_strtolower($name)])) continue;
            $seen[mb_strtolower($name)] = true;
            if (count($values) > self::MAX_ATTRIBUTE_VALUES) return ['attributes' => [], 'error' => sprintf(__('shop_err_attribute_values', 'At most %d values per attribute.'), self::MAX_ATTRIBUTE_VALUES)];
            $out[] = ['name' => $name, 'values' => $values];
        }
        if (count($out) > self::MAX_ATTRIBUTES) return ['attributes' => [], 'error' => sprintf(__('shop_err_attributes_many', 'At most %d attributes.'), self::MAX_ATTRIBUTES)];
        if (self::combinationCount($out) > self::MAX_VARIANTS) return ['attributes' => [], 'error' => sprintf(__('shop_err_variants_many', 'At most %d combinations.'), self::MAX_VARIANTS)];
        return ['attributes' => $out];
    }

    /** The attributes as the admin form writes them. */
    public static function attributesText(array $attributes): string {
        return implode("\n", array_map(static fn(array $a): string => $a['name'] . ': ' . implode(', ', $a['values']), $attributes));
    }

    private static function combinationCount(array $attributes): int {
        return $attributes === [] ? 0 : (int) array_product(array_map(static fn(array $a): int => count($a['values']), $attributes));
    }

    /** @return list<list<string>> every combination of the attributes' values, first attribute slowest */
    public static function combinations(array $attributes): array {
        $out = [[]];
        foreach ($attributes as $a) {
            $next = [];
            foreach ($out as $prefix) {
                foreach ($a['values'] as $v) $next[] = array_merge($prefix, [$v]);
            }
            $out = $next;
        }
        return $attributes === [] ? [] : $out;
    }

    /** The key of a combination (its values in the attributes' order). */
    public static function optionsKey(array $values): string {
        return sha1(json_encode(array_values(array_map('strval', $values)), JSON_UNESCAPED_UNICODE));
    }

    /**
     * A size typed as `12 x 7 x 3` (also `×`, `*`, commas for decimals): [] when empty, the three measures, or null
     * when it cannot be read. A part may be left out with `-`.
     *
     * @return array{length?: ?float, width?: ?float, height?: ?float}|null
     */
    public static function parseSize(string $text): ?array {
        $text = trim($text);
        if ($text === '') return [];
        $parts = preg_split('/\s*[x×*X]\s*/u', $text) ?: [];
        if (count($parts) !== 3) return null;
        $out = [];
        foreach (['length', 'width', 'height'] as $i => $k) {
            $p = trim($parts[$i]);
            $m = ($p === '' || $p === '-' || $p === '–') ? null : self::parseMeasure($p);
            if ($m === false) return null;
            $out[$k] = $m;
        }
        return $out;
    }

    /** A row's size as the variant table writes it (`12 x 7 x 3`), or ''. */
    public static function sizeText(array $row): string {
        $parts = [self::measure($row['length'] ?? null), self::measure($row['width'] ?? null), self::measure($row['height'] ?? null)];
        return array_filter($parts, static fn(string $p): bool => $p !== '') === [] ? '' : implode(' x ', array_map(static fn(string $p): string => $p !== '' ? $p : '-', $parts));
    }

    /** `Blanc / M`: a combination as customers read it. */
    public static function optionsLabel(array $values): string {
        return implode(' / ', array_map('strval', $values));
    }

    /** @return list<array<string, mixed>> a product's variant rows, in the order of its combinations */
    public static function variantRows(int $productId, bool $enabledOnly = false): array {
        self::ensureSchema();
        return Database::rows("SELECT * FROM shop_variants WHERE tenant_id = ? AND product_id = ?" . ($enabledOnly ? ' AND enabled = 1' : '') . " ORDER BY sort_order, id",
            [current_tenant_id(), $productId]);
    }

    /** @return array<int, list<array<string, mixed>>> product id => its enabled variant rows */
    public static function variantsOf(array $productIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::rows("SELECT * FROM shop_variants WHERE tenant_id = ? AND enabled = 1 AND product_id IN ($marks) ORDER BY sort_order, id", array_merge([current_tenant_id()], $ids)) as $r) {
            $out[(int) $r['product_id']][] = $r;
        }
        return $out;
    }

    /** @return list<string> a variant row's values */
    public static function variantValues(array $variant): array {
        $v = json_decode((string) ($variant['options_json'] ?? ''), true);
        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    /**
     * A variant row with the product's price, sale price, weight and sizes filled in where it leaves them empty. The
     * product's sale dates apply to a variant's sale price too.
     */
    private static function variantEffectiveRow(array $variant, array $product): array {
        $ownPrice = $variant['price_cents'] !== null && $variant['price_cents'] !== '';
        $row = [
            'price_cents'      => $ownPrice ? (int) $variant['price_cents'] : (int) $product['price_cents'],
            'sale_price_cents' => $ownPrice ? ($variant['sale_price_cents'] ?? null) : ($variant['sale_price_cents'] ?? $product['sale_price_cents'] ?? null),
            'stock_qty'        => $variant['stock_qty'],
            'sale_starts_at'   => $product['sale_starts_at'] ?? null,
            'sale_ends_at'     => $product['sale_ends_at'] ?? null,
        ];
        foreach (['weight', 'length', 'width', 'height'] as $k) {
            $row[$k] = ($variant[$k] ?? null) !== null && $variant[$k] !== '' ? $variant[$k] : ($product[$k] ?? null);
        }
        return $row;
    }

    /** The public shape of a variant. */
    public static function publicVariant(array $variant, array $product): array {
        $eff = self::variantEffectiveRow($variant, $product);
        $values = self::variantValues($variant);
        $available = self::available($eff);
        $price = (int) $eff['price_cents'];
        $effective = self::effectivePrice($eff);
        $image = (string) ($variant['image'] ?? '');
        return [
            'id'              => (int) $variant['id'],
            'options'         => $values,
            'label'           => self::optionsLabel($values),
            'sku'             => (string) ($variant['sku'] ?? '') !== '' ? (string) $variant['sku'] : (string) ($product['sku'] ?? ''),
            'price_cents'     => $price,
            'effective_cents' => $effective,
            'on_sale'         => $effective < $price,
            'stock_qty'       => $available,
            'in_stock'        => $available === null || $available > 0,
            'image'           => self::isImageUrl($image) ? $image : '',
            'weight'          => self::measure($eff['weight']),
            'dimensions'      => self::dimensionsText($eff),
        ];
    }

    /**
     * Set a product's attributes and bring its variants in line: a variant per combination (new ones enabled, with the
     * product's price and untracked stock), combinations that no longer exist removed, and the fields given for a
     * combination applied. No attributes makes the product simple again.
     *
     * @param list<array{name: string, values: list<string>}> $attributes
     * @param array<string, array<string, mixed>> $fields options key => sku, price, sale_price, stock (empty: not tracked), image, enabled
     * @return array{ok: bool, errors?: array<string, string>}
     */
    public static function saveVariants(int $productId, array $attributes, array $fields = []): array {
        self::ensureSchema();
        $tid = current_tenant_id();
        $errors = [];
        $rows = self::variantRowsFor($attributes, $fields, $errors);
        if ($errors !== []) return ['ok' => false, 'errors' => $errors];

        $pdo = Database::get();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            Database::update('shop_products', ['attributes_json' => $attributes === [] ? null : json_encode($attributes, JSON_UNESCAPED_UNICODE)], 'id = ? AND tenant_id = ?', [$productId, $tid]);
            $existing = [];
            foreach (self::variantRows($productId) as $v) $existing[(string) $v['options_key']] = (int) $v['id'];
            foreach ($existing as $key => $vid) {
                if (!isset($rows[$key])) Database::delete('shop_variants', 'id = ? AND tenant_id = ?', [$vid, $tid]);
            }
            foreach ($rows as $key => $row) {
                if (isset($existing[$key])) {
                    Database::update('shop_variants', $row, 'id = ? AND tenant_id = ?', [$existing[$key], $tid]);
                } else {
                    Database::insert('shop_variants', $row + ['tenant_id' => $tid, 'product_id' => $productId, 'options_key' => $key]);
                }
            }
            if ($attributes !== []) Database::update('shop_products', ['stock_qty' => null], 'id = ? AND tenant_id = ?', [$productId, $tid]);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true];
    }

    /**
     * The variant rows for a set of attributes, by options key, with the fields given for a combination checked and
     * applied (a combination without fields keeps what it has, or the defaults when it is new).
     *
     * @param array<string, string> $errors filled with what is wrong
     * @return array<string, array<string, mixed>>
     */
    private static function variantRowsFor(array $attributes, array $fields, array &$errors): array {
        $rows = [];
        foreach (self::combinations($attributes) as $i => $values) {
            $key = self::optionsKey($values);
            $f = is_array($fields[$key] ?? null) ? $fields[$key] : null;
            $row = ['options_json' => json_encode($values, JSON_UNESCAPED_UNICODE), 'sort_order' => $i];
            if ($f !== null) {
                $label = self::optionsLabel($values);
                $price = trim((string) ($f['price'] ?? '')) === '' ? null : self::parseMoney($f['price']);
                $sale = trim((string) ($f['sale_price'] ?? '')) === '' ? null : self::parseMoney($f['sale_price']);
                if (trim((string) ($f['price'] ?? '')) !== '' && $price === null) $errors['variants'] = sprintf(__('shop_err_variant_price', '%s: enter a price, such as 24.90, or leave it empty.'), $label);
                if (trim((string) ($f['sale_price'] ?? '')) !== '' && $sale === null) $errors['variants'] = sprintf(__('shop_err_variant_price', '%s: enter a price, such as 24.90, or leave it empty.'), $label);
                $stockRaw = trim((string) ($f['stock'] ?? ''));
                if ($stockRaw !== '' && !preg_match('/^\d{1,7}$/', $stockRaw)) $errors['variants'] = sprintf(__('shop_err_variant_stock', '%s: enter the number in stock, or leave it empty.'), $label);
                $image = trim((string) ($f['image'] ?? ''));
                if ($image !== '' && !self::isImageUrl($image)) $errors['variants'] = sprintf(__('shop_err_variant_image', '%s: the image must be a site path or an https:// address.'), $label);
                $weight = self::parseMeasure($f['weight'] ?? '');
                if ($weight === false) $errors['variants'] = sprintf(__('shop_err_variant_weight', '%s: enter a weight, such as 0.45, or leave it empty.'), $label);
                $size = self::parseSize((string) ($f['size'] ?? ''));
                if ($size === null) $errors['variants'] = sprintf(__('shop_err_variant_size', '%s: write the size as length × width × height, such as 12 x 7 x 3, or leave it empty.'), $label);
                $row += [
                    'sku'              => trim((string) ($f['sku'] ?? '')) !== '' ? mb_substr(trim((string) $f['sku']), 0, 64) : null,
                    'price_cents'      => $price,
                    'sale_price_cents' => $sale,
                    'stock_qty'        => $stockRaw === '' ? null : (int) $stockRaw,
                    'image'            => $image !== '' ? mb_substr($image, 0, 500) : null,
                    'weight'           => $weight === false ? null : $weight,
                    'length'           => $size['length'] ?? null,
                    'width'            => $size['width'] ?? null,
                    'height'           => $size['height'] ?? null,
                    'enabled'          => !empty($f['enabled']) ? 1 : 0,
                ];
                if (array_key_exists('external_ref', $f)) $row['external_ref'] = trim((string) $f['external_ref']) !== '' ? mb_substr(trim((string) $f['external_ref']), 0, 64) : null;
            }
            $rows[$key] = $row;
        }
        return $rows;
    }

    /**
     * The enabled variant of a published product that a visitor chose: by its id, or by one value per attribute
     * (indexed by position or by attribute name). Null when the choice is incomplete or names no enabled variant.
     */
    public static function findVariant(int $productId, int $variantId = 0, array $options = []): ?array {
        $row = self::productRow($productId);
        if (!$row) return null;
        $attributes = self::attributes($row);
        if ($attributes === []) return null;
        $variants = self::variantRows($productId, true);
        if ($variantId > 0) {
            foreach ($variants as $v) if ((int) $v['id'] === $variantId) return $v;
            return null;
        }
        $values = [];
        foreach ($attributes as $i => $a) {
            $v = $options[$i] ?? $options[$a['name']] ?? null;
            if (!is_string($v) || !in_array($v, $a['values'], true)) return null;
            $values[] = $v;
        }
        $key = self::optionsKey($values);
        foreach ($variants as $v) if ((string) $v['options_key'] === $key) return $v;
        return null;
    }

    /**
     * A date (and time) typed in a form or read from an import: `2026-12-01`, `2026-12-01 09:00`, `2026-12-01T09:00`.
     * Returns `Y-m-d H:i:s` in the site's time zone, null when empty, false when it is not a date.
     */
    public static function parseDateTime(mixed $raw): string|false|null {
        $s = trim((string) $raw);
        if ($s === '') return null;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return false;
        if ((int) ($m[4] ?? 0) > 23 || (int) ($m[5] ?? 0) > 59) return false;
        return sprintf('%s-%s-%s %02d:%02d:%02d', $m[1], $m[2], $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
    }

    /** `24.90`, `24,90` or `24` as cents; null when it is not a non-negative amount. */
    public static function parseMoney(mixed $raw): ?int {
        $s = str_replace([' ', "\u{00A0}"], '', trim((string) $raw));
        if (!preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $s)) return null;
        return (int) round(((float) str_replace(',', '.', $s)) * 100);
    }

    // ── Cart (session) ────────────────────────────────────────────────────

    /** @return array<int|string, int> line key => qty, as stored */
    private static function &cartRef(): array {
        $tid = (int) current_tenant_id();
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) $_SESSION[self::SESSION_KEY] = [];
        if (!isset($_SESSION[self::SESSION_KEY][$tid]) || !is_array($_SESSION[self::SESSION_KEY][$tid])) $_SESSION[self::SESSION_KEY][$tid] = [];
        return $_SESSION[self::SESSION_KEY][$tid];
    }

    /** A cart line's key: `12` for a product, `12:34` for one of its variants. */
    public static function lineKey(int $productId, int $variantId = 0): string {
        return $variantId > 0 ? $productId . ':' . $variantId : (string) $productId;
    }

    /** @return array{0: int, 1: int}|null [product id, variant id] of a line key */
    public static function parseLineKey(int|string $key): ?array {
        return preg_match('/^(\d{1,10})(?::(\d{1,10}))?$/', (string) $key, $m) === 1 && (int) $m[1] > 0
            ? [(int) $m[1], (int) ($m[2] ?? 0)] : null;
    }

    /** @return array<int|string, int> line key => qty */
    public static function cartItems(): array {
        $out = [];
        foreach (self::cartRef() as $key => $qty) {
            if (self::parseLineKey($key) !== null && (int) $qty > 0) $out[$key] = min(self::MAX_QTY, (int) $qty);
        }
        return $out;
    }

    /**
     * Add a product to the cart. A product with variants needs the visitor's choice: a variant id, or one value per
     * attribute (`$options`, by position or attribute name).
     *
     * @return array{ok: bool, error?: string, qty?: int}
     */
    public static function cartAdd(int $productId, int $qty = 1, int $variantId = 0, array $options = []): array {
        if (self::closed()) return ['ok' => false, 'error' => self::closedMessage()];
        $product = self::publishedProduct($productId);
        if (!$product) return ['ok' => false, 'error' => __('shop_err_unavailable', 'This product is not available.')];
        $variant = null;
        if ($product['has_variants']) {
            $chosen = $variantId > 0 || array_filter($options, static fn($v): bool => is_string($v) && $v !== '') !== [];
            $row = $chosen ? self::findVariant($productId, $variantId, $options) : null;
            if ($row === null) {
                return ['ok' => false, 'error' => $chosen
                    ? __('shop_err_combination', 'This combination is not available.')
                    : __('shop_err_choose_options', 'Choose your options first.')];
            }
            foreach ($product['variants'] as $v) if ($v['id'] === (int) $row['id']) $variant = $v;
            if ($variant === null) return ['ok' => false, 'error' => __('shop_err_combination', 'This combination is not available.')];
        }
        $item = $variant ?? $product;
        if (!$item['in_stock']) return ['ok' => false, 'error' => __('shop_err_sold_out', 'This product is sold out.')];
        $cart = &self::cartRef();
        $key = self::lineKey($productId, $variant['id'] ?? 0);
        $lines = self::cartLines();
        if ($lines !== [] && $lines[0]['currency'] !== $product['currency']) {
            return ['ok' => false, 'error' => __('shop_err_currency', 'This product cannot be in the same cart as the others.')];
        }
        if (!isset($cart[$key]) && count($cart) >= self::MAX_CART_LINES) {
            return ['ok' => false, 'error' => __('shop_err_cart_full', 'Your cart is full.')];
        }
        $want = min(self::MAX_QTY, (int) ($cart[$key] ?? 0) + max(1, $qty));
        if ($item['stock_qty'] !== null && $want > $item['stock_qty']) {
            $want = $item['stock_qty'];
            $cart[$key] = $want;
            return ['ok' => false, 'error' => sprintf(__('shop_err_only_n', 'Only %d left in stock.'), $want), 'qty' => $want];
        }
        $cart[$key] = $want;
        return ['ok' => true, 'qty' => $want];
    }

    /** Set a line's quantity (0 removes it); the line is named by its key (a product id for a product without variants). */
    public static function cartSet(int|string $key, int $qty): void {
        if (self::parseLineKey($key) === null) return;
        $cart = &self::cartRef();
        $key = (string) $key;
        if ($qty <= 0) {
            unset($cart[$key]);
        } elseif (isset($cart[$key])) {
            $cart[$key] = min(self::MAX_QTY, $qty);
        }
    }

    public static function cartClear(): void {
        $cart = &self::cartRef();
        $cart = [];
    }

    /** `T-shirt — Blanc / M`: a line's title with its options. */
    public static function lineTitle(string $title, ?string $options): string {
        return $options !== null && $options !== '' ? $title . ' — ' . $options : $title;
    }

    /**
     * The cart as lines, read fresh: products and variants that are gone, disabled or unpublished are dropped, and
     * quantities are capped at stock.
     *
     * @return list<array{key: string, product_id: int, variant_id: int, title: string, options_label: string, slug: string, url: string, image: string, sku: string, unit_cents: int, qty: int, line_cents: int, currency: string, max_qty: int, capped: bool}>
     */
    public static function cartLines(): array {
        $items = self::cartItems();
        if ($items === []) return [];
        $pids = [];
        foreach (array_keys($items) as $key) $pids[] = self::parseLineKey($key)[0];
        $pids = array_values(array_unique($pids));
        $marks = implode(',', array_fill(0, count($pids), '?'));
        $rows = Database::rows("SELECT * FROM shop_products WHERE tenant_id = ? AND status = 'published' AND id IN ($marks)", array_merge([current_tenant_id()], $pids));
        $byId = [];
        foreach ($rows as $r) $byId[(int) $r['id']] = $r;
        $variantsById = [];
        foreach (self::variantsOf($pids) as $list) {
            foreach ($list as $v) $variantsById[(int) $v['id']] = $v;
        }
        $lines = [];
        foreach ($items as $key => $qty) {
            [$pid, $vid] = self::parseLineKey($key);
            $r = $byId[$pid] ?? null;
            if (!$r) continue;
            $hasVariants = self::attributes($r) !== [];
            if ($hasVariants !== ($vid > 0)) continue;
            $label = '';
            $sku = (string) ($r['sku'] ?? '');
            $images = self::images($r);
            $image = $images[0] ?? '';
            $priced = $r;
            if ($vid > 0) {
                $v = $variantsById[$vid] ?? null;
                if (!$v || (int) $v['product_id'] !== $pid) continue;
                $pv = self::publicVariant($v, $r);
                $priced = self::variantEffectiveRow($v, $r);
                $label = $pv['label'];
                $sku = $pv['sku'];
                $image = $pv['image'] !== '' ? $pv['image'] : $image;
            }
            $avail = self::available($priced);
            $max = $avail === null ? self::MAX_QTY : min(self::MAX_QTY, $avail);
            if ($max <= 0) continue;
            $capped = $qty > $max;
            $qty = min($qty, $max);
            $unit = self::effectivePrice($vid > 0 ? $priced : $r);
            $regular = (int) (($vid > 0 ? $priced : $r)['price_cents'] ?? $unit);
            $type = self::productType($r);
            $weight = $vid > 0 ? ($priced['weight'] ?? null) : ($r['weight'] ?? null);
            $lines[] = [
                'product_type'  => $type,
                'needs_shipping' => $type === 'physical',
                'tax_class'     => (string) ($r['tax_class'] ?? ''),
                'on_sale'       => $unit < $regular,
                'discount_cents' => 0,
                'unit_weight'   => $weight !== null && $weight !== '' ? (float) $weight : null,
                'key'           => self::lineKey($pid, $vid),
                'product_id'    => $pid,
                'variant_id'    => $vid,
                'title'         => (string) $r['title'],
                'options_label' => $label,
                'slug'          => (string) $r['slug'],
                'url'           => self::productPath((string) $r['slug']),
                'image'         => $image,
                'sku'           => $sku,
                'unit_cents'    => $unit,
                'qty'           => $qty,
                'line_cents'    => $unit * $qty,
                'currency'      => (string) $r['currency'],
                'max_qty'       => $max,
                'capped'        => $capped,
            ];
        }
        return $lines;
    }

    /**
     * The delivery choices checkout offers: 'shipping' and/or 'pickup', or only 'none' when nothing in the cart is
     * shipped (digital and service products).
     *
     * @param ?list<array<string, mixed>> $lines the cart lines (read when null)
     * @return list<string>
     */
    public static function deliveryOptions(?array $lines = null): array {
        $lines ??= self::cartLines();
        if ($lines !== [] && !self::needsShipping($lines)) return ['none'];
        $out = [];
        if (self::setting('shipping_enabled') !== '0') $out[] = 'shipping';
        if (self::setting('pickup_enabled') !== '0') $out[] = 'pickup';
        return $out === [] ? ['pickup'] : $out;
    }

    /** Whether any line of a cart is a physical product (something to ship or collect). */
    public static function needsShipping(array $lines): bool {
        foreach ($lines as $line) if (($line['needs_shipping'] ?? true) !== false) return true;
        return false;
    }

    /** Shipping for a subtotal and a delivery choice: free for pickup, and above the free-shipping threshold. */
    public static function shippingFor(int $subtotal, string $delivery): int {
        if ($delivery !== 'shipping') return 0;
        $free = max(0, (int) self::setting('free_over_cents'));
        if ($free > 0 && $subtotal >= $free) return 0;
        return max(0, (int) self::setting('shipping_cents'));
    }

    /** An order's delivery address on one line: `12 Main St, Paso Robles, CA 93446, US` or `Rue X 1, 1000 Bruxelles, BE`. */
    public static function addressText(array $o): string {
        $region = trim((string) ($o['region'] ?? ''));
        $place = $region !== '' ? trim(trim((string) $o['city']) . ', ' . $region . ' ' . trim((string) $o['postal_code']), ', ') : trim($o['postal_code'] . ' ' . $o['city']);
        return implode(', ', array_filter([(string) $o['address_line1'], (string) ($o['address_line2'] ?? ''), $place, (string) $o['country']], static fn(string $v): bool => trim($v) !== ''));
    }

    /** How a pickup order is collected: the zone's pickup method it chose, else the settings' pickup wording. */
    public static function pickupText(array $o): string {
        if (!empty($o['shipping_method'])) return (string) $o['shipping_method'];
        return self::setting('pickup_label') !== '' ? self::setting('pickup_label') : __('shop_pickup', 'Pick up (free)');
    }

    /** Where a customer follows their parcel: the label's tracking page, else the carrier's own. */
    public static function trackingLink(array $o): string {
        if (!empty($o['tracking_url'])) return (string) $o['tracking_url'];
        return ShopShipping::trackingUrl((string) ($o['tracking_carrier'] ?? ''), (string) ($o['tracking_number'] ?? ''));
    }

    /** Countries whose addresses need a state or province (for shipping and sales tax). */
    public const REGION_COUNTRIES = ['US', 'CA', 'AU'];

    /**
     * The cart's totals: lines, the coupon (from the session, checked again now), shipping for the delivery choice and
     * address (`$rateId` picks one of the rates quoted; the cheapest otherwise), tax for the address, and the total.
     *
     * total = subtotal − discount + shipping (+ tax when the shop adds tax at checkout).
     *
     * @param array<string, mixed> $address country, region, postal_code, city, address_line1 (any may be missing)
     * @return array<string, mixed>
     */
    public static function cartTotals(string $delivery = '', array $address = [], string $rateId = '', string $email = ''): array {
        $lines = self::cartLines();
        $options = self::deliveryOptions($lines);
        $delivery = in_array($delivery, $options, true) ? $delivery : $options[0];
        $subtotal = array_sum(array_column($lines, 'line_cents'));
        $currency = $lines[0]['currency'] ?? self::currency();

        // The coupon: kept only while it still fits the cart; a note says why it went.
        $coupon = null;
        $couponNote = '';
        $eval = null;
        $code = ShopCoupons::sessionCode();
        if ($code !== '' && $lines !== []) {
            $c = ShopCoupons::find($code);
            $eval = $c ? ShopCoupons::evaluate($c, $lines, $email) : null;
            if ($c && $eval['ok']) {
                $coupon = $c;
            } else {
                $couponNote = $eval['error'] ?? __('shop_coupon_unknown', 'That code is not valid.');
                $eval = null;
            }
        }
        foreach ($lines as &$line) $line['discount_cents'] = (int) ($eval['per_line'][$line['key']] ?? 0);
        unset($line);
        $discount = (int) ($eval['discount'] ?? 0);
        $net = $subtotal - $discount;

        // Shipping.
        $rates = [];
        $rate = null;
        $pending = false;
        $shipError = '';
        $shipping = 0;
        if ($lines !== [] && $delivery === 'shipping') {
            $quote = ShopShipping::quote($lines, $address, $net);
            $rates = $quote['rates'];
            $pending = $quote['pending'];
            $shipError = $quote['error'];
            $rate = ($rateId !== '' ? ShopShipping::pick($rates, $rateId) : null) ?? ($rates[0] ?? null);
            $shipping = $rate !== null ? (int) $rate['cents'] : 0;
            if (!empty($eval['free_shipping'])) $shipping = 0;
        }

        // Tax: where the order goes (the shop itself for a pickup, even one chosen among a zone's methods).
        $taxDelivery = $rate !== null && $rate['kind'] === 'pickup' ? 'pickup' : $delivery;
        // Tax added at checkout waits for the address an order ships to (prices that include tax use the shop's own).
        $taxAddress = $taxDelivery === 'shipping' && ShopTax::mode() === 'exclusive' && trim((string) ($address['country'] ?? '')) === ''
            ? [] : ShopTax::addressFor($taxDelivery, $address);
        $tax = ShopTax::compute($lines, $shipping, $taxAddress);
        $total = $net + $shipping + ($tax['inclusive'] ? 0 : $tax['cents']);
        return [
            'lines'            => $lines,
            'count'            => array_sum(array_column($lines, 'qty')),
            'subtotal_cents'   => $subtotal,
            'discount_cents'   => $discount,
            'coupon'           => $coupon ? (string) $coupon['code'] : '',
            'coupon_label'     => $coupon ? ShopCoupons::describe($coupon, $currency) : '',
            'coupon_note'      => $couponNote,
            'free_shipping'    => !empty($eval['free_shipping']),
            'shipping_cents'   => $shipping,
            'shipping_rates'   => $rates,
            'shipping_rate'    => $rate,
            'shipping_pending' => $pending,
            'shipping_error'   => $shipError,
            'tax_cents'        => $tax['cents'],
            'tax_label'        => $tax['label'],
            'tax_inclusive'    => $tax['inclusive'],
            'tax_pending'      => $tax['pending'],
            'tax_rate'         => $tax['rate'],
            'tax_mode'         => ShopTax::mode(),
            'total_cents'      => $total,
            'currency'         => $currency,
            'delivery'         => $delivery,
            'delivery_options' => $options,
            'free_over_cents'  => ShopShipping::hasZones() ? 0 : max(0, (int) self::setting('free_over_cents')),
        ];
    }

    /**
     * The rows under an order's or a cart's lines, for every view and email: subtotal, discount, shipping, tax and
     * the total. A tax that prices include is a note row ("Includes $1.20 VAT"), not added.
     *
     * @param array<string, mixed> $t an order row, or cartTotals()
     * @return list<array{key: string, label: string, cents: int, text: string}>
     */
    public static function totalRows(array $t, string $currency): array {
        $rows = [];
        $discount = (int) ($t['discount_cents'] ?? 0);
        $rows[] = ['key' => 'subtotal', 'label' => __('shop_subtotal', 'Subtotal'), 'cents' => (int) $t['subtotal_cents'], 'text' => self::money((int) $t['subtotal_cents'], $currency)];
        if ($discount > 0) {
            $code = (string) ($t['coupon'] ?? $t['coupon_code'] ?? '');
            $rows[] = ['key' => 'discount', 'label' => $code !== '' ? sprintf(__('shop_discount_code', 'Discount (%s)'), $code) : __('shop_discount', 'Discount'),
                       'cents' => -$discount, 'text' => '−' . self::money($discount, $currency)];
        }
        $delivery = (string) ($t['delivery'] ?? 'shipping');
        if ($delivery === 'shipping') {
            $method = (string) ($t['shipping_method'] ?? ($t['shipping_rate']['label'] ?? ''));
            $cents = (int) $t['shipping_cents'];
            // A cart with no rate yet (no address, or none fits) says so rather than "free".
            $unknown = array_key_exists('shipping_rates', $t) && ($t['shipping_rate'] ?? null) === null && empty($t['free_shipping']);
            $rows[] = ['key' => 'shipping', 'label' => $method !== '' && $method !== __('shop_ship_to_me', 'Ship to my address') ? sprintf(__('shop_shipping_via', 'Shipping (%s)'), $method) : __('shop_shipping', 'Shipping'),
                       'cents' => $cents, 'text' => $unknown ? __('shop_shipping_tbd', 'after your address') : ($cents > 0 ? self::money($cents, $currency) : __('shop_free', 'free'))];
        }
        $taxCents = (int) ($t['tax_cents'] ?? 0);
        $inclusive = !empty($t['tax_inclusive']);
        $label = (string) ($t['tax_label'] ?? '') !== '' ? (string) $t['tax_label'] : ShopTax::label();
        if ($taxCents > 0 && !$inclusive) {
            $rows[] = ['key' => 'tax', 'label' => $label, 'cents' => $taxCents, 'text' => self::money($taxCents, $currency)];
        }
        $rows[] = ['key' => 'total', 'label' => __('shop_total', 'Total'), 'cents' => (int) $t['total_cents'], 'text' => self::money((int) $t['total_cents'], $currency)];
        if ($taxCents > 0 && $inclusive) {
            $rows[] = ['key' => 'tax_included', 'label' => '', 'cents' => 0, 'text' => sprintf(__('shop_includes_tax', 'Includes %1$s %2$s'), self::money($taxCents, $currency), $label)];
        }
        return $rows;
    }

    /** What the cart says under its subtotal, in the shop's tax mode. */
    public static function cartTaxNote(bool $ships): string {
        $mode = ShopTax::mode();
        // Tax added at checkout, but no rate set: no tax is ever added, so the cart does not promise any.
        if ($mode === 'exclusive' && ShopTax::rates() === []) $mode = 'none';
        if ($mode === 'exclusive') {
            return $ships ? __('shop_cart_note_exclusive', 'Tax and shipping are calculated at checkout.') : __('shop_cart_note_exclusive_noship', 'Tax is calculated at checkout.');
        }
        if ($mode === 'none') return $ships ? __('shop_cart_note_none', 'Shipping is calculated at checkout.') : '';
        $label = ShopTax::label();
        return $ships ? sprintf(__('shop_cart_note_inclusive', 'Prices include %s. Shipping is calculated at checkout.'), $label)
                      : sprintf(__('shop_cart_note_inclusive_noship', 'Prices include %s.'), $label);
    }

    // ── Checkout ──────────────────────────────────────────────────────────

    /**
     * Check the checkout form and the cart, then create the order (pending payment) with its lines in one transaction.
     *
     * @param array<string, mixed> $in posted: email, name, phone, delivery, address_line1, address_line2, postal_code, city, country, notes
     * @param ?callable $guard asked once the form and cart are valid, just before the order is made (ShopGuard::check):
     *   returns {ok, error, status}; a refusal is returned as the order's error with its `status`
     * @return array{ok: bool, order?: array<string, mixed>, errors?: array<string, string>, status?: int}
     */
    public static function placeOrder(array $in, ?int $customerId = null, ?callable $guard = null): array {
        self::ensureSchema();
        if (self::closed()) return ['ok' => false, 'errors' => ['_' => self::closedMessage()], 'status' => 409];
        $errors = [];
        $str = static fn(string $k, int $max): string => mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', (string) ($in[$k] ?? ''))), 0, $max);
        $email = strtolower($str('email', 190));
        $name = $str('name', 160);
        $delivery = (string) ($in['delivery'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = __('shop_err_email', 'Enter a valid email address.');
        if ($name === '') $errors['name'] = __('shop_err_name', 'Enter your name.');
        if (!in_array($delivery, self::deliveryOptions(), true)) $errors['delivery'] = __('shop_err_delivery', 'Choose how you want to receive your order.');
        $address = ['address_line1' => null, 'address_line2' => null, 'postal_code' => null, 'city' => null, 'country' => null, 'region' => null];
        if ($delivery === 'shipping') {
            $address = [
                'address_line1' => $str('address_line1', 190),
                'address_line2' => $str('address_line2', 190) !== '' ? $str('address_line2', 190) : null,
                'postal_code'   => $str('postal_code', 20),
                'city'          => $str('city', 120),
                'country'       => strtoupper($str('country', 2)),
                'region'        => strtoupper($str('region', 80)) !== '' ? strtoupper($str('region', 80)) : null,
            ];
            if ($address['region'] === null && in_array($address['country'], self::REGION_COUNTRIES, true)) $errors['region'] = __('shop_err_region', 'Enter your state or province.');
            if ($address['address_line1'] === '') $errors['address_line1'] = __('shop_err_address', 'Enter your street and number.');
            if ($address['postal_code'] === '') $errors['postal_code'] = __('shop_err_postal', 'Enter your postal code.');
            if ($address['city'] === '') $errors['city'] = __('shop_err_city', 'Enter your town or city.');
            if (!in_array($address['country'], self::countries(), true)) $errors['country'] = __('shop_err_country', 'We do not deliver to that country.');
        }
        $totals = self::cartTotals($delivery, $address + ['name' => $name, 'email' => $email, 'phone' => $str('phone', 40)], (string) ($in['shipping_rate'] ?? ''), $email);
        if ($totals['lines'] === []) $errors['_'] = __('shop_err_cart_empty', 'Your cart is empty.');
        if ($totals['coupon_note'] !== '') {
            $errors['coupon'] = $totals['coupon_note'] . ' ' . __('shop_coupon_removed', 'It was removed and your total updated.');
            ShopCoupons::setSessionCode('');
        }
        $rate = null;
        if ($delivery === 'shipping' && $totals['lines'] !== [] && !isset($errors['country'])) {
            $rates = $totals['shipping_rates'];
            $rate = ShopShipping::pick($rates, (string) ($in['shipping_rate'] ?? '')) ?? (count($rates) === 1 ? $rates[0] : null);
            if ($rates === []) {
                $errors['shipping_rate'] = $totals['shipping_error'] !== '' ? $totals['shipping_error']
                    : ($totals['shipping_pending'] ? __('shop_err_rates_need_address', 'Enter your address to see the shipping rates.') : __('shop_err_no_zone', 'We do not ship to that address yet. Contact us and we will find a way.'));
            } elseif ($rate === null) {
                $errors['shipping_rate'] = __('shop_err_choose_rate', 'Choose a shipping method.');
            }
        }
        foreach ($totals['lines'] as $line) {
            if ($line['capped']) {
                $errors['_'] = sprintf(__('shop_err_stock_changed', 'Only %1$d of “%2$s” left: your cart was updated.'), $line['max_qty'], self::lineTitle($line['title'], $line['options_label']));
                self::cartSet($line['key'], $line['max_qty']);
                break;
            }
        }
        if ($errors !== []) return ['ok' => false, 'errors' => $errors];
        if ($guard !== null) {
            $verdict = $guard();
            if (empty($verdict['ok'])) return ['ok' => false, 'errors' => ['_' => (string) ($verdict['error'] ?? '')], 'status' => (int) ($verdict['status'] ?? 400)];
        }

        $tid = current_tenant_id();
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $orderId = Database::insert('shop_orders', [
                'tenant_id'      => $tid,
                'ref'            => self::newRef(),
                'access_key'     => bin2hex(random_bytes(20)),
                'customer_id'    => $customerId,
                'email'          => $email,
                'name'           => $name,
                'phone'          => $str('phone', 40) !== '' ? $str('phone', 40) : null,
                // A zone's pickup method makes a pickup order (the address typed stays on it).
                'delivery'       => $rate !== null && $rate['kind'] === 'pickup' ? 'pickup' : $delivery,
                'notes'          => trim((string) ($in['notes'] ?? '')) !== '' ? mb_substr(trim((string) $in['notes']), 0, 2000) : null,
                'status'         => 'pending_payment',
                'currency'       => $totals['currency'],
                'subtotal_cents' => $totals['subtotal_cents'],
                'shipping_cents' => $totals['shipping_cents'],
                'discount_cents' => $totals['discount_cents'],
                'coupon_code'    => $totals['coupon'] !== '' ? $totals['coupon'] : null,
                'tax_cents'      => $totals['tax_cents'],
                'tax_label'      => $totals['tax_cents'] > 0 ? mb_substr((string) $totals['tax_label'], 0, 80) : null,
                'tax_inclusive'  => $totals['tax_inclusive'] ? 1 : 0,
                'total_cents'    => $totals['total_cents'],
                'shipping_method'  => $rate !== null ? mb_substr((string) $rate['label'], 0, 160) : null,
                'shipping_carrier' => $rate !== null && $rate['carrier'] !== '' ? mb_substr((string) $rate['carrier'], 0, 40) : null,
                'shipping_service' => $rate !== null && $rate['service'] !== '' ? mb_substr((string) $rate['service'], 0, 80) : null,
            ] + $address);
            foreach ($totals['lines'] as $line) {
                Database::insert('shop_order_items', [
                    'tenant_id'        => $tid,
                    'order_id'         => $orderId,
                    'product_id'       => $line['product_id'],
                    'variant_id'       => $line['variant_id'] > 0 ? $line['variant_id'] : null,
                    'title'            => $line['title'],
                    'options_label'    => $line['options_label'] !== '' ? mb_substr($line['options_label'], 0, 255) : null,
                    'sku'              => $line['sku'] !== '' ? $line['sku'] : null,
                    'product_type'     => $line['product_type'],
                    'unit_price_cents' => $line['unit_cents'],
                    'qty'              => $line['qty'],
                    'line_total_cents' => $line['line_cents'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            slate_log('Shop: placeOrder failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'errors' => ['_' => __('shop_err_order_failed', 'Your order could not be created. Please try again.')]];
        }
        if (class_exists('AuditLog')) AuditLog::record('shop.order_placed', (string) $orderId);
        return ['ok' => true, 'order' => self::order($orderId)];
    }

    /** A short order reference customers can quote (`KS-7F3K9Q`), unique per tenant. */
    private static function newRef(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 8; $try++) {
            $ref = 'KS-';
            for ($i = 0; $i < 6; $i++) $ref .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            if ((int) Database::value("SELECT COUNT(*) FROM shop_orders WHERE tenant_id = ? AND ref = ?", [current_tenant_id(), $ref]) === 0) return $ref;
        }
        return 'KS-' . strtoupper(bin2hex(random_bytes(5)));
    }

    /** Whether any payment gateway can take a shop payment right now. */
    public static function canTakePayments(): bool {
        return class_exists('Payments') && Payments::canCharge('shop');
    }

    /**
     * Start paying an order through the gateway the visitor chose (or the first one offered). A zero total is paid
     * at once.
     *
     * @return array{ok: bool, url?: string, error?: string}
     */
    public static function startPayment(array $order, ?string $gateway = null): array {
        if ((int) $order['total_cents'] <= 0) {
            self::markPaid((int) $order['id'], 0, (string) $order['currency']);
            return ['ok' => true, 'url' => self::confirmationUrl($order)];
        }
        $current = self::order((int) $order['id']);
        if (!$current || $current['status'] !== 'pending_payment') {
            return ['ok' => false, 'error' => __('shop_err_not_payable', 'This order is not waiting for payment.')];
        }
        $gw = class_exists('Payments') ? Payments::choose('shop', $gateway) : null;
        if ($gw === null) {
            return ['ok' => false, 'error' => __('shop_err_payment_unavailable', 'Online payment is not available right now. Please contact us to complete your order.')];
        }
        $lineItems = self::paymentLines($order);
        try {
            $start = Payments::startCheckout($gw->id(), new CheckoutRequest($lineItems, [
                'source_plugin'   => 'shop',
                'source_id'       => (string) $order['id'],
                'currency'        => strtolower((string) $order['currency']),
                'customer_email'  => (string) $order['email'],
                'success_url'     => self::endpoint('return.php') . '?order=' . rawurlencode((string) $order['ref']) . '&key=' . rawurlencode((string) $order['access_key'])
                                     . '&gw=' . rawurlencode($gw->id()) . '&ref={PAYMENT_REF}',
                'cancel_url'      => self::pageUrl('checkout_page') . '?cancelled=1',
                'idempotency_key' => 'shop-order-' . $order['id'],
                'metadata'        => ['shop_order_id' => (string) $order['id'], 'order_ref' => (string) $order['ref']],
            ]));
        } catch (\Throwable $e) {
            slate_log('Shop: starting payment with ' . $gw->id() . ' failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'error' => __('shop_err_payment_start', 'Payment could not be started. Please try again.')];
        }
        // The order must carry this checkout's reference before the visitor
        // pays: a payment for any other reference is ignored. Only an order
        // still waiting for payment takes it (not one paid in another tab).
        Database::update('shop_orders', [
            'payment_gateway'   => $gw->id(),
            'payment_ref'       => $start->ref,
            'stripe_session_id' => $gw->id() === 'stripe' ? $start->ref : null,
        ], "id = ? AND tenant_id = ? AND status = 'pending_payment'", [(int) $order['id'], current_tenant_id()]);
        $saved = self::order((int) $order['id']);
        if (!$saved || $saved['status'] !== 'pending_payment' || (string) $saved['payment_ref'] !== $start->ref
            || (string) $saved['payment_gateway'] !== $gw->id()) {
            slate_log('Shop: order ' . $order['id'] . ' could not take its checkout reference; payment not started.', 'error');
            return ['ok' => false, 'error' => __('shop_err_payment_start', 'Payment could not be started. Please try again.')];
        }
        return ['ok' => true, 'url' => $start->redirectUrl];
    }

    /**
     * What the gateway shows the customer. Itemised (products, shipping, tax added at checkout) when that adds up to
     * the total; with a discount, one line for the whole order, since gateways take no negative lines.
     *
     * @return list<array{name: string, amount_cents: int, quantity: int}>
     */
    public static function paymentLines(array $order): array {
        $items = self::orderItems((int) $order['id']);
        if ((int) ($order['discount_cents'] ?? 0) > 0) {
            $n = array_sum(array_map(static fn(array $i): int => (int) $i['qty'], $items));
            return [['name' => sprintf(__('shop_payment_order_line', 'Order %1$s (%2$d items)'), $order['ref'], $n), 'amount_cents' => (int) $order['total_cents'], 'quantity' => 1]];
        }
        $lines = [];
        foreach ($items as $item) {
            $lines[] = ['name' => self::lineTitle((string) $item['title'], $item['options_label'] ?? null), 'amount_cents' => (int) $item['unit_price_cents'], 'quantity' => (int) $item['qty']];
        }
        if ((int) $order['shipping_cents'] > 0) {
            $lines[] = ['name' => (string) ($order['shipping_method'] ?? '') !== '' ? (string) $order['shipping_method'] : __('shop_shipping', 'Shipping'), 'amount_cents' => (int) $order['shipping_cents'], 'quantity' => 1];
        }
        if ((int) ($order['tax_cents'] ?? 0) > 0 && empty($order['tax_inclusive'])) {
            $lines[] = ['name' => (string) ($order['tax_label'] ?? '') !== '' ? (string) $order['tax_label'] : ShopTax::label(), 'amount_cents' => (int) $order['tax_cents'], 'quantity' => 1];
        }
        return $lines;
    }

    /** Where a paid order's confirmation is shown: the thank-you page with the order's ref and key. */
    public static function confirmationUrl(array $order): string {
        return self::pageUrl('thanks_page') . '?order=' . rawurlencode((string) $order['ref']) . '&key=' . rawurlencode((string) $order['access_key']);
    }

    /**
     * Move an order from pending_payment to paid. Exactly one caller wins (webhook, return page, zero total); the
     * winner links the ledger row, takes the stock and sends the emails. Returns whether this call made the change.
     */
    public static function markPaid(int $orderId, int $paidCents, string $paidCurrency, ?int $chargeId = null): bool {
        self::ensureSchema();
        $tid = current_tenant_id();
        $won = Database::update('shop_orders', ['status' => 'paid', 'paid_at' => slate_db_now()],
            "id = ? AND tenant_id = ? AND status = 'pending_payment'", [$orderId, $tid]) === 1;
        if (!$won) return false;
        $order = self::order($orderId);
        if (!$order) return false;

        $notes = [];
        if ($paidCents > 0 && ($paidCents !== (int) $order['total_cents'] || strtoupper($paidCurrency) !== strtoupper((string) $order['currency']))) {
            $notes[] = sprintf('Paid %s, order total %s', self::money($paidCents, strtoupper($paidCurrency)), self::money((int) $order['total_cents'], (string) $order['currency']));
        }
        $low = [];
        foreach (self::orderItems($orderId) as $item) {
            if (empty($item['product_id'])) continue;
            // A variant's own stock, else the product's.
            [$table, $id] = !empty($item['variant_id']) ? ['shop_variants', (int) $item['variant_id']] : ['shop_products', (int) $item['product_id']];
            $before = Database::value("SELECT stock_qty FROM $table WHERE id = ? AND tenant_id = ?", [$id, $tid]);
            $taken = Database::query(
                "UPDATE $table SET stock_qty = stock_qty - ? WHERE id = ? AND tenant_id = ? AND stock_qty IS NOT NULL AND stock_qty >= ?",
                [(int) $item['qty'], $id, $tid, (int) $item['qty']])->rowCount();
            if ($taken === 0) {
                $tracked = Database::value("SELECT stock_qty FROM $table WHERE id = ? AND tenant_id = ?", [$id, $tid]);
                if ($tracked !== null && $tracked !== false) {
                    Database::query("UPDATE $table SET stock_qty = 0 WHERE id = ? AND tenant_id = ?", [$id, $tid]);
                    $notes[] = sprintf('Short of stock: %s × %d (had %d)', self::lineTitle((string) $item['title'], $item['options_label'] ?? null), (int) $item['qty'], (int) $tracked);
                }
            }
            if ($before !== null && $before !== false) {
                $after = (int) Database::value("SELECT stock_qty FROM $table WHERE id = ? AND tenant_id = ?", [$id, $tid]);
                $threshold = self::lowStockThreshold((int) $item['product_id']);
                // Once, when this sale takes the stock to the threshold or below.
                if ($threshold !== null && (int) $before > $threshold && $after <= $threshold) {
                    $low[] = ['title' => self::lineTitle((string) $item['title'], $item['options_label'] ?? null), 'sku' => (string) ($item['sku'] ?? ''), 'left' => $after];
                }
            }
        }
        self::grantDownloads($orderId);
        ShopCoupons::recordUse($order);
        $after = array_filter([
            'charge_id'  => $chargeId,
            'stock_note' => $notes !== [] ? mb_substr(implode('; ', $notes), 0, 255) : null,
        ], static fn($v): bool => $v !== null);
        if ($after !== []) {
            Database::update('shop_orders', $after, 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        }
        if (class_exists('AuditLog')) AuditLog::record('shop.order_paid', (string) $orderId, ['amount_cents' => $paidCents]);
        self::sendOrderEmails($orderId);
        if ($low !== []) self::sendLowStockEmail($low);
        return true;
    }

    // ── Low stock ─────────────────────────────────────────────────────────

    /** The stock level at or below which a product counts as low: its own, else the shop's; null when alerts are off. */
    public static function lowStockThreshold(int $productId): ?int {
        $own = Database::value("SELECT low_stock FROM shop_products WHERE id = ? AND tenant_id = ?", [$productId, current_tenant_id()]);
        if ($own !== null && $own !== false && $own !== '') return max(0, (int) $own);
        $shop = trim(self::setting('low_stock'));
        return $shop === '' ? null : max(0, (int) $shop);
    }

    /**
     * Products and variants whose tracked stock is at or below their low-stock level.
     *
     * @return list<array{product_id: int, variant_id: int, title: string, sku: string, stock: int, threshold: int}>
     */
    public static function lowStock(): array {
        self::ensureSchema();
        $tid = current_tenant_id();
        $shop = trim(self::setting('low_stock'));
        $out = [];
        foreach (Database::rows("SELECT * FROM shop_products WHERE tenant_id = ? AND status = 'published' ORDER BY title", [$tid]) as $p) {
            $threshold = $p['low_stock'] !== null ? (int) $p['low_stock'] : ($shop === '' ? null : (int) $shop);
            if ($threshold === null) continue;
            if (self::attributes($p) === []) {
                if ($p['stock_qty'] !== null && (int) $p['stock_qty'] <= $threshold) {
                    $out[] = ['product_id' => (int) $p['id'], 'variant_id' => 0, 'title' => (string) $p['title'], 'sku' => (string) ($p['sku'] ?? ''), 'stock' => (int) $p['stock_qty'], 'threshold' => $threshold];
                }
                continue;
            }
            foreach (self::variantRows((int) $p['id'], true) as $v) {
                if ($v['stock_qty'] !== null && (int) $v['stock_qty'] <= $threshold) {
                    $out[] = ['product_id' => (int) $p['id'], 'variant_id' => (int) $v['id'], 'title' => self::lineTitle((string) $p['title'], self::optionsLabel(self::variantValues($v))),
                              'sku' => (string) ($v['sku'] ?? $p['sku'] ?? ''), 'stock' => (int) $v['stock_qty'], 'threshold' => $threshold];
                }
            }
        }
        return $out;
    }

    /** @param list<array{title: string, sku: string, left: int}> $items */
    private static function sendLowStockEmail(array $items): void {
        $to = trim(self::setting('low_stock_email')) !== '' ? trim(self::setting('low_stock_email')) : trim(self::setting('notify_email'));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || !class_exists('Mailer') || !class_exists('\\Slate\\Services\\Notifications\\EmailTemplate')) return;
        try {
            $tpl = '\\Slate\\Services\\Notifications\\EmailTemplate';
            $rows = array_map(static fn(array $i): array => [e($i['title'] . ($i['sku'] !== '' ? ' (' . $i['sku'] . ')' : '')), e(sprintf(__('shop_n_left', '%d left'), $i['left']))], $items);
            $site = Database::setting('site_name') ?: 'Kohevo';
            $body = $tpl::shell($tpl::heading(__('shop_low_stock_heading', 'Running low'))
                . $tpl::paragraph(e(__('shop_low_stock_body', 'A sale just took these products to their low-stock level.')))
                . $tpl::infoCard($rows)
                . $tpl::button(function_exists('plugin_url') ? plugin_url('shop', 'admin/products.php') . '?stock=low' : '', __('shop_open_products', 'Open the products')));
            Mailer::send($to, sprintf('%s — %s', $site, __('shop_low_stock_subject', 'Low stock')), $body);
        } catch (\Throwable $e) {
            slate_log('Shop: low-stock email failed: ' . $e->getMessage(), 'warning');
        }
    }

    // ── Digital files and downloads ───────────────────────────────────────

    public const MAX_FILE_BYTES = 512 * 1024 * 1024;
    public const FILE_EXTS = ['pdf', 'zip', 'epub', 'mobi', 'mp3', 'm4a', 'wav', 'mp4', 'mov', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg',
        'txt', 'csv', 'xlsx', 'xls', 'docx', 'doc', 'pptx', 'key', 'pages', 'numbers', 'psd', 'ai', 'indd', 'ttf', 'otf', 'woff', 'woff2', 'stl', 'obj', 'gcode'];

    /**
     * The private folder a tenant's product files live in: under data/, which the site's own .htaccess (and the dev
     * router) refuses to serve. A second deny-all file is written next to them in case data/'s is ever lost.
     */
    public static function filesDir(): string {
        $dir = SLATE_ROOT . '/data/shop-files/' . (int) current_tenant_id();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $deny = dirname($dir) . '/.htaccess';
        if (!is_file($deny)) @file_put_contents($deny, "Require all denied\n");
        return $dir;
    }

    /** @return list<array{id: int, name: string, path: string, size_bytes: int}> */
    public static function productFiles(int $productId): array {
        self::ensureSchema();
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'path' => (string) $r['path'], 'size_bytes' => (int) $r['size_bytes']],
            Database::rows("SELECT * FROM shop_product_files WHERE tenant_id = ? AND product_id = ? ORDER BY id", [current_tenant_id(), $productId]));
    }

    /**
     * Keep a file for a digital product: it is moved (or copied) from $absPath into the private folder under a random
     * name; $name is what the buyer's download is called.
     *
     * @return array{ok: bool, id?: int, error?: string}
     */
    public static function addProductFile(int $productId, string $absPath, string $name): array {
        if (!self::productRow($productId)) return ['ok' => false, 'error' => __('shop_err_not_found', 'That product no longer exists.')];
        $name = mb_substr(trim(basename(str_replace('\\', '/', $name))), 0, 190);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || !in_array($ext, self::FILE_EXTS, true)) return ['ok' => false, 'error' => __('shop_err_file_type', 'This kind of file cannot be sold for download.')];
        clearstatcache(true, $absPath);
        $size = is_file($absPath) ? (int) filesize($absPath) : 0;
        if ($size <= 0) return ['ok' => false, 'error' => __('shop_err_file_missing', 'No file was received.')];
        if ($size > self::MAX_FILE_BYTES) return ['ok' => false, 'error' => __('shop_err_file_large', 'The file is too large.')];
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = self::filesDir() . '/' . $stored;
        if (!(is_uploaded_file($absPath) ? @move_uploaded_file($absPath, $dest) : (@rename($absPath, $dest) || @copy($absPath, $dest)))) {
            return ['ok' => false, 'error' => __('shop_err_file_save', 'The file could not be saved.')];
        }
        @chmod($dest, 0640);
        $id = Database::insert('shop_product_files', ['tenant_id' => current_tenant_id(), 'product_id' => $productId, 'name' => $name, 'path' => $stored, 'size_bytes' => $size]);
        return ['ok' => true, 'id' => $id];
    }

    /** Remove a product file (buyers' links to it stop working). */
    public static function deleteProductFile(int $fileId): void {
        $tid = current_tenant_id();
        $row = Database::row("SELECT * FROM shop_product_files WHERE id = ? AND tenant_id = ?", [$fileId, $tid]);
        if (!$row) return;
        $abs = self::filesDir() . '/' . basename((string) $row['path']);
        if (is_file($abs)) @unlink($abs);
        Database::delete('shop_downloads', 'file_id = ? AND tenant_id = ?', [$fileId, $tid]);
        Database::delete('shop_product_files', 'id = ? AND tenant_id = ?', [$fileId, $tid]);
    }

    /** A paid order's download links: one per file of each digital line (made once; a second call adds nothing). */
    public static function grantDownloads(int $orderId): int {
        $tid = current_tenant_id();
        $made = 0;
        foreach (self::orderItems($orderId) as $item) {
            if (($item['product_type'] ?? '') !== 'digital' || empty($item['product_id'])) continue;
            $product = self::productRow((int) $item['product_id']);
            foreach (self::productFiles((int) $item['product_id']) as $f) {
                $has = (int) Database::value("SELECT COUNT(*) FROM shop_downloads WHERE tenant_id = ? AND order_item_id = ? AND file_id = ?", [$tid, (int) $item['id'], $f['id']]);
                if ($has > 0) continue;
                $days = $product['download_days'] ?? null;
                Database::insert('shop_downloads', [
                    'tenant_id' => $tid, 'order_id' => $orderId, 'order_item_id' => (int) $item['id'], 'file_id' => $f['id'],
                    'token' => bin2hex(random_bytes(20)),
                    'download_limit' => $product['download_limit'] ?? null,
                    'expires_at' => $days !== null && $days !== '' && (int) $days > 0 ? date('Y-m-d H:i:s', time() + (int) $days * 86400) : null,
                ]);
                $made++;
            }
        }
        return $made;
    }

    /**
     * An order's download links, for the confirmation page and email (only while the order is paid or fulfilled).
     *
     * @return list<array{name: string, url: string, left: ?int, expires_at: string, usable: bool}>
     */
    public static function orderDownloads(array $order): array {
        if (!in_array($order['status'] ?? '', ['paid', 'fulfilled'], true)) return [];
        self::ensureSchema();
        $out = [];
        foreach (Database::rows(
            "SELECT d.*, f.name FROM shop_downloads d JOIN shop_product_files f ON f.id = d.file_id AND f.tenant_id = d.tenant_id
              WHERE d.tenant_id = ? AND d.order_id = ? ORDER BY d.id", [current_tenant_id(), (int) $order['id']]) as $d) {
            $left = $d['download_limit'] === null ? null : max(0, (int) $d['download_limit'] - (int) $d['download_count']);
            $expired = $d['expires_at'] !== null && strtotime((string) $d['expires_at']) <= time();
            $out[] = ['name' => (string) $d['name'], 'url' => self::endpoint('download.php') . '?t=' . $d['token'], 'left' => $left,
                      'expires_at' => (string) ($d['expires_at'] ?? ''), 'usable' => !$expired && ($left === null || $left > 0)];
        }
        return $out;
    }

    /**
     * The file a download link names, counted as one download: null when the link is unknown, used up, expired, or
     * its order is no longer paid. The count is taken in one conditional UPDATE, so a limit cannot be overrun.
     *
     * @return array{abs: string, name: string, size: int}|null
     */
    public static function claimDownload(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{40}$/', $token)) return null;
        self::ensureSchema();
        $tid = current_tenant_id();
        $d = Database::row(
            "SELECT d.*, f.name, f.path, o.status FROM shop_downloads d
               JOIN shop_product_files f ON f.id = d.file_id AND f.tenant_id = d.tenant_id
               JOIN shop_orders o ON o.id = d.order_id AND o.tenant_id = d.tenant_id
              WHERE d.tenant_id = ? AND d.token = ?", [$tid, $token]);
        if (!$d || !hash_equals((string) $d['token'], $token) || !in_array($d['status'], ['paid', 'fulfilled'], true)) return null;
        $taken = Database::query(
            "UPDATE shop_downloads SET download_count = download_count + 1
              WHERE id = ? AND tenant_id = ? AND (download_limit IS NULL OR download_count < download_limit) AND (expires_at IS NULL OR expires_at > ?)",
            [(int) $d['id'], $tid, date('Y-m-d H:i:s')])->rowCount();
        if ($taken !== 1) return null;
        $abs = self::filesDir() . '/' . basename((string) $d['path']);
        return is_file($abs) ? ['abs' => $abs, 'name' => (string) $d['name'], 'size' => (int) filesize($abs)] : null;
    }

    /**
     * The `payment_event` listener: a Shop order's payment, from any gateway. The event must be for this order's own
     * checkout (the reference the order was given when payment started), so a payment for an older, abandoned
     * checkout of the same order cannot mark it paid twice over.
     */
    public static function handlePaymentEvent(PaymentEvent $event): void {
        if (!$event->isFor('shop') || !$event->isPaid()) return;
        $orderId = (int) $event->sourceId;
        $order = $orderId > 0 ? self::order($orderId) : null;
        if (!$order) return;
        $expected = (string) ($order['payment_ref'] ?: $order['stripe_session_id']);
        if ($expected === '' || !hash_equals($expected, $event->ref)) return;
        if ($event->amountCents < (int) $order['total_cents'] || strtoupper($event->currency) !== strtoupper((string) $order['currency'])) {
            // Less than the order costs (or another currency): never ship it
            // as paid. The order waits for the shop owner, who sees the note,
            // the payment in Payments → Transactions, and can refund it there.
            self::flagUnderpaid($order, $event);
            return;
        }
        // The payment layer has already written the ledger row; the order only links it.
        self::markPaid($orderId, $event->amountCents, $event->currency, $event->chargeId);
    }

    /** A payment that does not cover the order: leave it unpaid, note why, and tell the owner through the audit log. */
    private static function flagUnderpaid(array $order, PaymentEvent $event): void {
        $note = sprintf('Payment of %s does not cover the order total %s; not marked paid',
            self::money($event->amountCents, strtoupper($event->currency)), self::money((int) $order['total_cents'], (string) $order['currency']));
        $changed = Database::update('shop_orders', ['stock_note' => mb_substr($note, 0, 255)],
            "id = ? AND tenant_id = ? AND status = 'pending_payment'", [(int) $order['id'], current_tenant_id()]);
        if ($changed === 1 && class_exists('AuditLog')) {
            AuditLog::record('shop.payment_mismatch', (string) $order['id'], [
                'paid_cents' => $event->amountCents, 'paid_currency' => strtoupper($event->currency),
                'total_cents' => (int) $order['total_cents'], 'charge_id' => $event->chargeId,
            ]);
        }
        slate_log('Shop: ' . $note . ' (order ' . $order['id'] . ')', 'warning');
    }

    /**
     * The return page after paying: the order must be the visitor's (ref + key), and the payment reference must be
     * the one this order's checkout was given. The payment is confirmed with the gateway (never trusted from the
     * address); a paid one marks the order paid through the payment_event listener. The cart is emptied.
     */
    public static function confirmReturn(string $ref, string $key, string $paymentRef, string $gateway = 'stripe'): ?array {
        $order = self::orderByRefKey($ref, $key);
        if (!$order) return null;
        $expected = (string) ($order['payment_ref'] ?: $order['stripe_session_id']);
        $orderGateway = (string) ($order['payment_gateway'] ?: 'stripe');
        if ($order['status'] === 'pending_payment' && $paymentRef !== '' && $expected !== '' && hash_equals($expected, $paymentRef)
            && $gateway === $orderGateway && class_exists('Payments')) {
            try {
                Payments::confirmReturn($orderGateway, $paymentRef, 'shop', (string) $order['id']);
            } catch (\Throwable $e) {
                slate_log('Shop: return payment check failed: ' . $e->getMessage(), 'warning');
            }
        }
        self::cartClear();
        return self::order((int) $order['id']);
    }

    // ── Orders ────────────────────────────────────────────────────────────

    public static function order(int $id): ?array {
        self::ensureSchema();
        return Database::row("SELECT * FROM shop_orders WHERE id = ? AND tenant_id = ?", [$id, current_tenant_id()]);
    }

    /** The order a confirmation link names: ref and key must both match (the key compared in constant time). */
    public static function orderByRefKey(string $ref, string $key): ?array {
        if (!preg_match('/^KS-[A-Z0-9]{6,10}$/', $ref) || !preg_match('/^[a-f0-9]{40}$/', $key)) return null;
        self::ensureSchema();
        $row = Database::row("SELECT * FROM shop_orders WHERE tenant_id = ? AND ref = ?", [current_tenant_id(), $ref]);
        return ($row && hash_equals((string) $row['access_key'], $key)) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public static function orderItems(int $orderId): array {
        return Database::rows("SELECT * FROM shop_order_items WHERE order_id = ? AND tenant_id = ? ORDER BY id", [$orderId, current_tenant_id()]);
    }

    /** @return list<array<string, mixed>> */
    /** Orders, newest first: by status, and matching `$q` (a reference, an email or a name) when given. */
    public static function listOrders(string $status = '', int $limit = 200, string $q = ''): array {
        self::ensureSchema();
        $args = [current_tenant_id()];
        $where = 'tenant_id = ?';
        if (in_array($status, self::STATUSES, true)) {
            $where .= ' AND status = ?';
            $args[] = $status;
        }
        $q = trim(mb_substr($q, 0, 120));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (ref LIKE ? OR email LIKE ? OR name LIKE ? OR tracking_number LIKE ?)';
            array_push($args, $like, $like, $like, $like);
        }
        return Database::rows("SELECT * FROM shop_orders WHERE $where ORDER BY id DESC LIMIT " . max(1, min(5000, $limit)), $args);
    }

    /** @return array<string, string> status => label */
    public static function statusLabels(): array {
        return [
            'pending_payment' => __('shop_status_pending_payment', 'Awaiting payment'),
            'paid'            => __('shop_status_paid', 'Paid'),
            'fulfilled'       => __('shop_status_fulfilled', 'Fulfilled'),
            'cancelled'       => __('shop_status_cancelled', 'Cancelled'),
            'refunded'        => __('shop_status_refunded', 'Refunded'),
        ];
    }

    /** paid → fulfilled (shipped or collected). */
    /**
     * paid → fulfilled: shipped (with its tracking, when given) or collected. `$notify` emails the customer: on its way,
     * with the tracking link, or ready to collect.
     *
     * @param array{carrier?: string, number?: string, url?: string} $tracking
     */
    public static function fulfil(int $orderId, array $tracking = [], bool $notify = false): bool {
        $ok = Database::update('shop_orders', ['status' => 'fulfilled', 'fulfilled_at' => slate_db_now()],
            "id = ? AND tenant_id = ? AND status = 'paid'", [$orderId, current_tenant_id()]) === 1;
        if (!$ok) return false;
        if (array_filter($tracking, static fn($v): bool => trim((string) $v) !== '') !== []) self::saveTracking($orderId, $tracking);
        $order = self::order($orderId);
        if ($order && $order['delivery'] === 'shipping') {
            Database::update('shop_orders', ['shipped_at' => slate_db_now()], 'id = ? AND tenant_id = ? AND shipped_at IS NULL', [$orderId, current_tenant_id()]);
        }
        if (class_exists('AuditLog')) AuditLog::record('shop.order_fulfilled', (string) $orderId);
        if ($notify) self::sendFulfilledEmail($orderId);
        return true;
    }

    /**
     * Keep an order's tracking: the carrier, the number and, when the shop has one, the tracking page's address (else
     * the carrier's own page is linked).
     *
     * @param array{carrier?: string, number?: string, url?: string} $t
     * @return array{ok: bool, error?: string}
     */
    public static function saveTracking(int $orderId, array $t): array {
        $url = trim((string) ($t['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https://#i', $url)) return ['ok' => false, 'error' => __('shop_err_tracking_url', 'A tracking link starts with https://.')];
        $clean = static fn(string $v, int $max): ?string => ($v = mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', $v)), 0, $max)) !== '' ? $v : null;
        Database::update('shop_orders', [
            'tracking_carrier' => $clean((string) ($t['carrier'] ?? ''), 40),
            'tracking_number'  => $clean((string) ($t['number'] ?? ''), 80),
            'tracking_url'     => $clean($url, 500),
        ], 'id = ? AND tenant_id = ?', [$orderId, current_tenant_id()]);
        if (class_exists('AuditLog')) AuditLog::record('shop.order_tracking', (string) $orderId);
        return ['ok' => true];
    }

    /** The shop's own notes on an order (never shown to the customer). */
    public static function saveAdminNotes(int $orderId, string $notes): void {
        $notes = trim($notes);
        Database::update('shop_orders', ['admin_notes' => $notes !== '' ? mb_substr($notes, 0, 5000) : null], 'id = ? AND tenant_id = ?', [$orderId, current_tenant_id()]);
    }

    /** Send a paid order's receipt to the customer again. */
    public static function resendReceipt(int $orderId): bool {
        $order = self::order($orderId);
        if (!$order || !in_array($order['status'], ['paid', 'fulfilled'], true)) return false;
        self::sendOrderEmails($orderId, false);
        return true;
    }

    /** pending_payment → cancelled (a checkout that was never paid). */
    public static function cancel(int $orderId): bool {
        $ok = Database::update('shop_orders', ['status' => 'cancelled'],
            "id = ? AND tenant_id = ? AND status = 'pending_payment'", [$orderId, current_tenant_id()]) === 1;
        if ($ok && class_exists('AuditLog')) AuditLog::record('shop.order_cancelled', (string) $orderId);
        return $ok;
    }

    /**
     * paid|fulfilled → refunded: the whole charge is refunded through the gateway that took it, and the stock is put back.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function refund(int $orderId): array {
        $order = self::order($orderId);
        if (!$order || !in_array($order['status'], ['paid', 'fulfilled'], true)) return ['ok' => false, 'error' => __('shop_err_cannot_refund', 'Only a paid order can be refunded.')];
        if ((int) $order['total_cents'] > 0) {
            if (empty($order['charge_id']) || !class_exists('Payments')) return ['ok' => false, 'error' => __('shop_err_no_payment_charge', 'No payment is linked to this order; refund it in your payment provider\'s dashboard.')];
            $res = Payments::refundCharge((int) $order['charge_id'], null, 'shop-refund-' . $orderId);
            if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? __('shop_err_refund_failed', 'The refund failed.'))];
        }
        $tid = current_tenant_id();
        if (Database::update('shop_orders', ['status' => 'refunded'], "id = ? AND tenant_id = ? AND status IN ('paid','fulfilled')", [$orderId, $tid]) !== 1) {
            return ['ok' => false, 'error' => __('shop_err_cannot_refund', 'Only a paid order can be refunded.')];
        }
        foreach (self::orderItems($orderId) as $item) {
            if (!empty($item['product_id'])) {
                [$table, $id] = !empty($item['variant_id']) ? ['shop_variants', (int) $item['variant_id']] : ['shop_products', (int) $item['product_id']];
                Database::query("UPDATE $table SET stock_qty = stock_qty + ? WHERE id = ? AND tenant_id = ? AND stock_qty IS NOT NULL", [(int) $item['qty'], $id, $tid]);
            }
        }
        if (class_exists('AuditLog')) AuditLog::record('shop.order_refunded', (string) $orderId);
        return ['ok' => true];
    }

    /** Orders still awaiting payment after a day are abandoned checkouts: cancel them (their stock was never taken). */
    public static function cancelStale(int $hours = 24): int {
        self::ensureSchema();
        return Database::query(
            "UPDATE shop_orders SET status = 'cancelled' WHERE tenant_id = ? AND status = 'pending_payment' AND created_at < ?",
            [current_tenant_id(), date('Y-m-d H:i:s', time() - $hours * 3600)])->rowCount();
    }

    // ── Emails ────────────────────────────────────────────────────────────

    /** `3 downloads left · until 1 Dec 2026`, or '' when a link has no limits. */
    public static function downloadTerms(array $download): string {
        $parts = [];
        if (!$download['usable']) return __('shop_download_used', 'No longer available');
        if ($download['left'] !== null) $parts[] = sprintf(__('shop_downloads_left', '%d downloads left'), $download['left']);
        if ($download['expires_at'] !== '') $parts[] = sprintf(__('shop_download_until', 'until %s'), date('j M Y', (int) strtotime($download['expires_at'])));
        return implode(' · ', $parts);
    }

    /** @return array<string, string> product title => its purchase note, for the products of an order that have one */
    public static function purchaseNotes(int $orderId): array {
        $out = [];
        foreach (self::orderItems($orderId) as $item) {
            if (empty($item['product_id'])) continue;
            $note = trim((string) Database::value("SELECT purchase_note FROM shop_products WHERE id = ? AND tenant_id = ?", [(int) $item['product_id'], current_tenant_id()]));
            if ($note !== '') $out[(string) $item['title']] = $note;
        }
        return $out;
    }

    /** Tell the customer their order is on its way (with tracking) or ready to collect. */
    public static function sendFulfilledEmail(int $orderId): void {
        if (!class_exists('Mailer') || !class_exists('\\Slate\\Services\\Notifications\\EmailTemplate')) return;
        $order = self::order($orderId);
        if (!$order || $order['delivery'] === 'none') return;
        try {
            $tpl = '\\Slate\\Services\\Notifications\\EmailTemplate';
            $site = Database::setting('site_name') ?: 'Kohevo';
            $shipped = $order['delivery'] === 'shipping';
            $rows = [];
            if ($shipped && !empty($order['tracking_number'])) {
                $rows[] = [e(__('shop_tracking', 'Tracking')), e(trim((string) $order['tracking_carrier'] . ' ' . $order['tracking_number']))];
            }
            $body = $tpl::heading($shipped ? __('shop_email_shipped_heading', 'Your order is on its way') : __('shop_email_ready_heading', 'Your order is ready to collect'))
                . $tpl::greeting((string) $order['name'])
                . $tpl::paragraph(e(sprintf($shipped ? __('shop_email_shipped_body', 'Order %s has left us.') : __('shop_email_ready_body', 'Order %s is ready for you.'), $order['ref'])
                    . ' ' . ($shipped ? self::addressText($order) : self::pickupText($order))))
                . ($rows !== [] ? $tpl::infoCard($rows) : '')
                . (($link = self::trackingLink($order)) !== '' ? $tpl::button($link, __('shop_track_parcel', 'Track your parcel')) : $tpl::button(self::confirmationUrl($order), __('shop_view_order', 'View your order')));
            $subject = sprintf('%s — %s %s', $site, $shipped ? __('shop_email_shipped_subject', 'Shipped') : __('shop_email_ready_subject', 'Ready to collect'), $order['ref']);
            Mailer::send((string) $order['email'], $subject, $tpl::shell($body), (string) $order['name']);
        } catch (\Throwable $e) {
            slate_log('Shop: fulfilment email failed: ' . $e->getMessage(), 'warning');
        }
    }

    private static function sendOrderEmails(int $orderId, bool $notifyShop = true): void {
        if (!class_exists('Mailer') || !class_exists('\\Slate\\Services\\Notifications\\EmailTemplate')) return;
        $order = self::order($orderId);
        if (!$order) return;
        try {
            $tpl = '\\Slate\\Services\\Notifications\\EmailTemplate';
            $site = Database::setting('site_name') ?: 'Kohevo';
            $rows = [];
            foreach (self::orderItems($orderId) as $item) {
                $rows[] = [e(self::lineTitle((string) $item['title'], $item['options_label'] ?? null)) . ' × ' . (int) $item['qty'], e(self::money((int) $item['line_total_cents'], (string) $order['currency']))];
            }
            foreach (self::totalRows($order, (string) $order['currency']) as $t) {
                if ($t['key'] === 'subtotal') continue;
                $rows[] = $t['key'] === 'total' ? ['<strong>' . e($t['label']) . '</strong>', '<strong>' . e($t['text']) . '</strong>'] : [e($t['label']), e($t['text'])];
            }
            $subject = sprintf('%s — %s %s', $site, __('shop_email_subject', 'Order confirmed'), $order['ref']);
            $extra = '';
            $downloads = self::orderDownloads($order);
            if ($downloads !== []) {
                $links = array_map(static fn(array $d): array => ['<a href="' . e($d['url']) . '">' . e($d['name']) . '</a>', e(self::downloadTerms($d))], $downloads);
                $extra .= $tpl::paragraph('<strong>' . e(__('shop_your_downloads', 'Your downloads')) . '</strong>') . $tpl::infoCard($links);
            }
            foreach (self::purchaseNotes($orderId) as $title => $note) {
                $extra .= $tpl::paragraph('<strong>' . e($title) . '</strong><br>' . nl2br(e($note)));
            }
            $body = $tpl::shell(
                $tpl::heading(__('shop_email_heading', 'Thank you for your order'))
                . $tpl::greeting((string) $order['name'])
                . $tpl::paragraph(e(sprintf(__('shop_email_body', 'We received your payment for order %s.'), $order['ref'])))
                . $tpl::infoCard($rows)
                . $extra
                . $tpl::button(self::confirmationUrl($order), __('shop_view_order', 'View your order')));
            Mailer::send((string) $order['email'], $subject, $body, (string) $order['name']);
            $notify = trim(self::setting('notify_email'));
            if ($notifyShop && $notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
                $admin = $tpl::shell($tpl::heading(sprintf(__('shop_email_admin_heading', 'New order %s'), $order['ref']))
                    . $tpl::paragraph(e($order['name'] . ' <' . $order['email'] . '>'))
                    . $tpl::infoCard($rows)
                    . $tpl::button(function_exists('plugin_url') ? plugin_url('shop', 'admin/orders.php') . '?id=' . (int) $order['id'] : '', __('shop_open_order', 'Open the order')));
                Mailer::send($notify, sprintf('%s — %s %s', $site, __('shop_email_admin_subject', 'New order'), $order['ref']), $admin);
            }
        } catch (\Throwable $e) {
            slate_log('Shop: order email failed: ' . $e->getMessage(), 'warning');
        }
    }
}
