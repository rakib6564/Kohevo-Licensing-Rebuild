<?php
/**
 * Shop — the business layer: catalogue, the session cart, checkout, payment completion and order actions.
 *
 * Every admin screen, public endpoint and Studio widget goes through this class; none of them touches a shop_* table.
 *
 * Money: integer cents. A product's price is VAT-inclusive and carries its own currency; an order snapshots currency,
 * prices, titles and SKUs. A cart holds one currency (the first product's): a product priced in another is refused.
 *
 * Cart: the visitor's PHP session (`$_SESSION['kohevo_shop_cart'][tenant id] = [product id => qty]`). Only ids and
 * quantities are kept; prices, titles and stock are read again every time the cart is shown or checked out.
 *
 * Payment: Stripe Checkout through the stripe-payment plugin's generic facade (`StripePaymentAPI::createCheckout`),
 * like Booking and Membership. An order is created `pending_payment`; the webhook (`stripe_webhook_event`) or the
 * return page's session check moves it to `paid` through one conditional UPDATE, so whichever runs second does
 * nothing. Stock is taken when the order is paid. The class is deliberately not called `ShopAPI`: stripe-payment
 * still carries a back-compat path for an archived Shop of that name (`shop_payment_providers`,
 * `stripepayment_sessions`), and it stays inert.
 *
 * Statuses: pending_payment → paid → fulfilled; pending_payment → cancelled; paid|fulfilled → refunded.
 */

class ShopStoreAPI {

    public const STATUSES = ['pending_payment', 'paid', 'fulfilled', 'cancelled', 'refunded'];
    public const PRODUCT_STATUSES = ['draft', 'published'];
    public const MAX_QTY = 99;
    public const MAX_CART_LINES = 50;
    public const MAX_IMAGES = 12;
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
            'notify_email'      => '',
        ];
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

    /** A Studio page of the site, by the slug a setting names (`cart`, `checkout`, `order-received`). */
    public static function pageUrl(string $setting): string {
        $slug = strtolower(trim(self::setting($setting), " /"));
        if (!preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $slug)) $slug = self::settingDefaults()[$setting] ?? '';
        return rtrim((string) SLATE_URL, '/') . '/' . $slug;
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

    /** The price a customer pays now: the sale price when one is set and lower. */
    public static function effectivePrice(array $row): int {
        $sale = $row['sale_price_cents'] ?? null;
        return ($sale !== null && $sale !== '' && (int) $sale < (int) $row['price_cents']) ? (int) $sale : (int) $row['price_cents'];
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
     */
    public static function publicProduct(array $row, ?array $categories = null): array {
        $id = (int) $row['id'];
        $images = self::images($row);
        $available = self::available($row);
        $price = (int) $row['price_cents'];
        $effective = self::effectivePrice($row);
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
            'in_stock'          => $available === null || $available > 0,
            'categories'        => $categories ?? (self::categoriesOf([$id])[$id] ?? []),
            'url'               => '/' . self::productPrefix() . '/' . $row['slug'],
            'updated_at'        => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Published products for a listing.
     *
     * @param array{category?: string, page?: int, per_page?: int, order?: string, ids?: list<int>} $q
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
        $order = match ((string) ($q['order'] ?? '')) {
            'newest'     => 'p.created_at DESC, p.id DESC',
            'price_asc'  => 'COALESCE(LEAST(p.sale_price_cents, p.price_cents), p.price_cents) ASC, p.id',
            'price_desc' => 'COALESCE(LEAST(p.sale_price_cents, p.price_cents), p.price_cents) DESC, p.id',
            'title'      => 'p.title ASC, p.id',
            default      => 'p.sort_order ASC, p.created_at DESC, p.id DESC',
        };
        $total = (int) Database::value("SELECT COUNT(*) FROM shop_products p WHERE $where", $args);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $rows = Database::rows("SELECT p.* FROM shop_products p WHERE $where ORDER BY $order LIMIT $perPage OFFSET $offset", $args);
        $cats = self::categoriesOf(array_column($rows, 'id'));
        return [
            'rows'  => array_map(static fn(array $r): array => self::publicProduct($r, $cats[(int) $r['id']] ?? []), $rows),
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ];
    }

    /** @return list<array{slug: string, updated_at: string}> every published product, for the sitemap */
    public static function sitemap(int $limit = 5000): array {
        self::ensureSchema();
        $rows = Database::rows("SELECT slug, updated_at FROM shop_products WHERE tenant_id = ? AND status = 'published' ORDER BY id LIMIT " . max(1, $limit), [current_tenant_id()]);
        return array_map(static fn(array $r): array => ['slug' => (string) $r['slug'], 'updated_at' => (string) $r['updated_at']], $rows);
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
            'status'            => $status,
            'sort_order'        => (int) ($in['sort_order'] ?? 0),
        ];
        if ($id > 0) {
            Database::update('shop_products', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
        } else {
            $id = Database::insert('shop_products', $row + ['tenant_id' => $tid]);
        }
        self::setProductCategories($id, array_map('intval', (array) ($in['categories'] ?? [])));
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
        Database::delete('shop_products', 'id = ? AND tenant_id = ?', [$id, $tid]);
        return 'deleted';
    }

    /** `24.90`, `24,90` or `24` as cents; null when it is not a non-negative amount. */
    public static function parseMoney(mixed $raw): ?int {
        $s = str_replace([' ', "\u{00A0}"], '', trim((string) $raw));
        if (!preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $s)) return null;
        return (int) round(((float) str_replace(',', '.', $s)) * 100);
    }

    // ── Cart (session) ────────────────────────────────────────────────────

    /** @return array<int, int> product id => qty, as stored */
    private static function &cartRef(): array {
        $tid = (int) current_tenant_id();
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) $_SESSION[self::SESSION_KEY] = [];
        if (!isset($_SESSION[self::SESSION_KEY][$tid]) || !is_array($_SESSION[self::SESSION_KEY][$tid])) $_SESSION[self::SESSION_KEY][$tid] = [];
        return $_SESSION[self::SESSION_KEY][$tid];
    }

    /** @return array<int, int> product id => qty */
    public static function cartItems(): array {
        $out = [];
        foreach (self::cartRef() as $pid => $qty) {
            if ((int) $pid > 0 && (int) $qty > 0) $out[(int) $pid] = min(self::MAX_QTY, (int) $qty);
        }
        return $out;
    }

    /** @return array{ok: bool, error?: string, qty?: int} */
    public static function cartAdd(int $productId, int $qty = 1): array {
        $product = self::publishedProduct($productId);
        if (!$product) return ['ok' => false, 'error' => __('shop_err_unavailable', 'This product is not available.')];
        if (!$product['in_stock']) return ['ok' => false, 'error' => __('shop_err_sold_out', 'This product is sold out.')];
        $cart = &self::cartRef();
        $lines = self::cartLines();
        if ($lines !== [] && $lines[0]['currency'] !== $product['currency']) {
            return ['ok' => false, 'error' => __('shop_err_currency', 'This product cannot be in the same cart as the others.')];
        }
        if (!isset($cart[$productId]) && count($cart) >= self::MAX_CART_LINES) {
            return ['ok' => false, 'error' => __('shop_err_cart_full', 'Your cart is full.')];
        }
        $want = min(self::MAX_QTY, (int) ($cart[$productId] ?? 0) + max(1, $qty));
        if ($product['stock_qty'] !== null && $want > $product['stock_qty']) {
            $want = $product['stock_qty'];
            $cart[$productId] = $want;
            return ['ok' => false, 'error' => sprintf(__('shop_err_only_n', 'Only %d left in stock.'), $want), 'qty' => $want];
        }
        $cart[$productId] = $want;
        return ['ok' => true, 'qty' => $want];
    }

    /** Set a line's quantity (0 removes it). */
    public static function cartSet(int $productId, int $qty): void {
        $cart = &self::cartRef();
        if ($qty <= 0) {
            unset($cart[$productId]);
        } elseif (isset($cart[$productId])) {
            $cart[$productId] = min(self::MAX_QTY, $qty);
        }
    }

    public static function cartClear(): void {
        $cart = &self::cartRef();
        $cart = [];
    }

    /**
     * The cart as lines, read fresh: products that are gone or unpublished are dropped, quantities are capped at stock.
     *
     * @return list<array{product_id: int, title: string, slug: string, url: string, image: string, sku: string, unit_cents: int, qty: int, line_cents: int, currency: string, max_qty: int, capped: bool}>
     */
    public static function cartLines(): array {
        $items = self::cartItems();
        if ($items === []) return [];
        $ids = array_keys($items);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::rows("SELECT * FROM shop_products WHERE tenant_id = ? AND status = 'published' AND id IN ($marks)", array_merge([current_tenant_id()], $ids));
        $byId = [];
        foreach ($rows as $r) $byId[(int) $r['id']] = $r;
        $lines = [];
        foreach ($items as $pid => $qty) {
            $r = $byId[$pid] ?? null;
            if (!$r) continue;
            $avail = self::available($r);
            $max = $avail === null ? self::MAX_QTY : min(self::MAX_QTY, $avail);
            if ($max <= 0) continue;
            $capped = $qty > $max;
            $qty = min($qty, $max);
            $unit = self::effectivePrice($r);
            $images = self::images($r);
            $lines[] = [
                'product_id' => $pid,
                'title'      => (string) $r['title'],
                'slug'       => (string) $r['slug'],
                'url'        => '/' . self::productPrefix() . '/' . $r['slug'],
                'image'      => $images[0] ?? '',
                'sku'        => (string) ($r['sku'] ?? ''),
                'unit_cents' => $unit,
                'qty'        => $qty,
                'line_cents' => $unit * $qty,
                'currency'   => (string) $r['currency'],
                'max_qty'    => $max,
                'capped'     => $capped,
            ];
        }
        return $lines;
    }

    /** @return list<string> the delivery choices checkout offers: 'shipping' and/or 'pickup' */
    public static function deliveryOptions(): array {
        $out = [];
        if (self::setting('shipping_enabled') !== '0') $out[] = 'shipping';
        if (self::setting('pickup_enabled') !== '0') $out[] = 'pickup';
        return $out === [] ? ['pickup'] : $out;
    }

    /** Shipping for a subtotal and a delivery choice: free for pickup, and above the free-shipping threshold. */
    public static function shippingFor(int $subtotal, string $delivery): int {
        if ($delivery !== 'shipping') return 0;
        $free = max(0, (int) self::setting('free_over_cents'));
        if ($free > 0 && $subtotal >= $free) return 0;
        return max(0, (int) self::setting('shipping_cents'));
    }

    /**
     * @return array{lines: list<array<string, mixed>>, count: int, subtotal_cents: int, shipping_cents: int, total_cents: int, currency: string, delivery: string, free_over_cents: int}
     */
    public static function cartTotals(string $delivery = ''): array {
        $lines = self::cartLines();
        $options = self::deliveryOptions();
        $delivery = in_array($delivery, $options, true) ? $delivery : $options[0];
        $subtotal = array_sum(array_column($lines, 'line_cents'));
        $shipping = $lines === [] ? 0 : self::shippingFor($subtotal, $delivery);
        return [
            'lines'           => $lines,
            'count'           => array_sum(array_column($lines, 'qty')),
            'subtotal_cents'  => $subtotal,
            'shipping_cents'  => $shipping,
            'total_cents'     => $subtotal + $shipping,
            'currency'        => $lines[0]['currency'] ?? self::currency(),
            'delivery'        => $delivery,
            'free_over_cents' => max(0, (int) self::setting('free_over_cents')),
        ];
    }

    // ── Checkout ──────────────────────────────────────────────────────────

    /**
     * Check the checkout form and the cart, then create the order (pending payment) with its lines in one transaction.
     *
     * @param array<string, mixed> $in posted: email, name, phone, delivery, address_line1, address_line2, postal_code, city, country, notes
     * @return array{ok: bool, order?: array<string, mixed>, errors?: array<string, string>}
     */
    public static function placeOrder(array $in, ?int $customerId = null): array {
        self::ensureSchema();
        $errors = [];
        $str = static fn(string $k, int $max): string => mb_substr(trim(str_replace(["\r", "\n", "\0"], ' ', (string) ($in[$k] ?? ''))), 0, $max);
        $email = strtolower($str('email', 190));
        $name = $str('name', 160);
        $delivery = (string) ($in['delivery'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = __('shop_err_email', 'Enter a valid email address.');
        if ($name === '') $errors['name'] = __('shop_err_name', 'Enter your name.');
        if (!in_array($delivery, self::deliveryOptions(), true)) $errors['delivery'] = __('shop_err_delivery', 'Choose how you want to receive your order.');
        $address = ['address_line1' => null, 'address_line2' => null, 'postal_code' => null, 'city' => null, 'country' => null];
        if ($delivery === 'shipping') {
            $address = [
                'address_line1' => $str('address_line1', 190),
                'address_line2' => $str('address_line2', 190) !== '' ? $str('address_line2', 190) : null,
                'postal_code'   => $str('postal_code', 20),
                'city'          => $str('city', 120),
                'country'       => strtoupper($str('country', 2)),
            ];
            if ($address['address_line1'] === '') $errors['address_line1'] = __('shop_err_address', 'Enter your street and number.');
            if ($address['postal_code'] === '') $errors['postal_code'] = __('shop_err_postal', 'Enter your postal code.');
            if ($address['city'] === '') $errors['city'] = __('shop_err_city', 'Enter your town or city.');
            if (!in_array($address['country'], self::countries(), true)) $errors['country'] = __('shop_err_country', 'We do not deliver to that country.');
        }
        $totals = self::cartTotals($delivery);
        if ($totals['lines'] === []) $errors['_'] = __('shop_err_cart_empty', 'Your cart is empty.');
        foreach ($totals['lines'] as $line) {
            if ($line['capped']) {
                $errors['_'] = sprintf(__('shop_err_stock_changed', 'Only %1$d of “%2$s” left: your cart was updated.'), $line['max_qty'], $line['title']);
                self::cartSet($line['product_id'], $line['max_qty']);
                break;
            }
        }
        if ($errors !== []) return ['ok' => false, 'errors' => $errors];

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
                'delivery'       => $delivery,
                'notes'          => trim((string) ($in['notes'] ?? '')) !== '' ? mb_substr(trim((string) $in['notes']), 0, 2000) : null,
                'status'         => 'pending_payment',
                'currency'       => $totals['currency'],
                'subtotal_cents' => $totals['subtotal_cents'],
                'shipping_cents' => $totals['shipping_cents'],
                'total_cents'    => $totals['total_cents'],
            ] + $address);
            foreach ($totals['lines'] as $line) {
                Database::insert('shop_order_items', [
                    'tenant_id'        => $tid,
                    'order_id'         => $orderId,
                    'product_id'       => $line['product_id'],
                    'title'            => $line['title'],
                    'sku'              => $line['sku'] !== '' ? $line['sku'] : null,
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

    /**
     * Start paying an order: Stripe Checkout through the stripe-payment plugin. A zero total is paid at once.
     *
     * @return array{ok: bool, url?: string, error?: string}
     */
    public static function startPayment(array $order): array {
        if ((int) $order['total_cents'] <= 0) {
            self::markPaid((int) $order['id'], 0, (string) $order['currency']);
            return ['ok' => true, 'url' => self::confirmationUrl($order)];
        }
        if (!class_exists('StripePaymentAPI') || !StripePaymentAPI::isConfigured()) {
            return ['ok' => false, 'error' => __('shop_err_payment_unavailable', 'Online payment is not available right now. Please contact us to complete your order.')];
        }
        $lineItems = [];
        foreach (self::orderItems((int) $order['id']) as $item) {
            $lineItems[] = ['name' => (string) $item['title'], 'amount_cents' => (int) $item['unit_price_cents'], 'quantity' => (int) $item['qty']];
        }
        if ((int) $order['shipping_cents'] > 0) {
            $lineItems[] = ['name' => __('shop_shipping', 'Shipping'), 'amount_cents' => (int) $order['shipping_cents'], 'quantity' => 1];
        }
        try {
            $session = StripePaymentAPI::createCheckout($lineItems, [
                'currency'       => strtolower((string) $order['currency']),
                'customer_email' => (string) $order['email'],
                'success_url'    => self::endpoint('return.php') . '?order=' . rawurlencode((string) $order['ref']) . '&key=' . rawurlencode((string) $order['access_key']) . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'     => self::pageUrl('checkout_page') . '?cancelled=1',
                'metadata'       => [
                    'source_plugin' => 'shop',
                    'source_id'     => (string) $order['id'],
                    'shop_order_id' => (string) $order['id'],
                    'order_ref'     => (string) $order['ref'],
                ],
            ]);
        } catch (\Throwable $e) {
            slate_log('Shop: createCheckout failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'error' => __('shop_err_payment_start', 'Payment could not be started. Please try again.')];
        }
        Database::update('shop_orders', ['stripe_session_id' => (string) $session['session_id']], 'id = ? AND tenant_id = ?', [(int) $order['id'], current_tenant_id()]);
        return ['ok' => true, 'url' => (string) $session['url']];
    }

    /** Where a paid order's confirmation is shown: the thank-you page with the order's ref and key. */
    public static function confirmationUrl(array $order): string {
        return self::pageUrl('thanks_page') . '?order=' . rawurlencode((string) $order['ref']) . '&key=' . rawurlencode((string) $order['access_key']);
    }

    /**
     * Move an order from pending_payment to paid. Exactly one caller wins (webhook, return page, zero total); the
     * winner records the charge, takes the stock and sends the emails. Returns whether this call made the change.
     */
    public static function markPaid(int $orderId, int $paidCents, string $paidCurrency, string $sessionId = '', string $paymentIntentId = '', string $email = ''): bool {
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
        $chargeId = null;
        if ($paidCents > 0 && class_exists('StripePaymentAPI')) {
            $chargeId = StripePaymentAPI::recordCharge([
                'source_plugin'            => 'shop',
                'source_id'                => (string) $orderId,
                'stripe_session_id'        => $sessionId,
                'stripe_payment_intent_id' => $paymentIntentId,
                'customer_email'           => $email !== '' ? $email : (string) $order['email'],
                'amount_cents'             => $paidCents,
                'currency'                 => strtoupper($paidCurrency),
                'status'                   => 'succeeded',
            ]);
        }
        foreach (self::orderItems($orderId) as $item) {
            if (empty($item['product_id'])) continue;
            $taken = Database::query(
                "UPDATE shop_products SET stock_qty = stock_qty - ? WHERE id = ? AND tenant_id = ? AND stock_qty IS NOT NULL AND stock_qty >= ?",
                [(int) $item['qty'], (int) $item['product_id'], $tid, (int) $item['qty']])->rowCount();
            if ($taken === 0) {
                $tracked = Database::value("SELECT stock_qty FROM shop_products WHERE id = ? AND tenant_id = ?", [(int) $item['product_id'], $tid]);
                if ($tracked !== null && $tracked !== false) {
                    Database::query("UPDATE shop_products SET stock_qty = 0 WHERE id = ? AND tenant_id = ?", [(int) $item['product_id'], $tid]);
                    $notes[] = sprintf('Short of stock: %s × %d (had %d)', $item['title'], (int) $item['qty'], (int) $tracked);
                }
            }
        }
        $after = array_filter([
            'charge_id'  => $chargeId,
            'stock_note' => $notes !== [] ? mb_substr(implode('; ', $notes), 0, 255) : null,
        ], static fn($v): bool => $v !== null);
        if ($after !== []) {
            Database::update('shop_orders', $after, 'id = ? AND tenant_id = ?', [$orderId, $tid]);
        }
        if (class_exists('AuditLog')) AuditLog::record('shop.order_paid', (string) $orderId, ['amount_cents' => $paidCents]);
        self::sendOrderEmails($orderId);
        return true;
    }

    /** The `stripe_webhook_event` listener: a paid Checkout Session of a Shop order. */
    public static function handleStripeEvent(array $event): void {
        if (($event['type'] ?? '') !== 'checkout.session.completed') return;
        $obj = $event['data']['object'] ?? null;
        if (!is_array($obj)) return;
        $meta = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
        if (($meta['source_plugin'] ?? '') !== 'shop') return;
        $orderId = (int) ($meta['shop_order_id'] ?? 0);
        if ($orderId <= 0 || !in_array((string) ($obj['payment_status'] ?? ''), ['paid', 'no_payment_required'], true)) return;
        $order = self::order($orderId);
        if (!$order || (string) $order['stripe_session_id'] !== (string) ($obj['id'] ?? '')) return;
        self::markPaid($orderId, (int) ($obj['amount_total'] ?? 0), (string) ($obj['currency'] ?? $order['currency']),
            (string) ($obj['id'] ?? ''), (string) ($obj['payment_intent'] ?? ''),
            (string) ($obj['customer_details']['email'] ?? ($obj['customer_email'] ?? '')));
    }

    /**
     * The return page after Stripe: the order must be the visitor's (ref + key), and the session id must be the one
     * Stripe made for it. The session is read back from Stripe, never trusted from the address. The cart is emptied.
     */
    public static function confirmReturn(string $ref, string $key, string $sessionId): ?array {
        $order = self::orderByRefKey($ref, $key);
        if (!$order) return null;
        if ($order['status'] === 'pending_payment' && $sessionId !== '' && hash_equals((string) $order['stripe_session_id'], $sessionId) && class_exists('StripePaymentAPI')) {
            try {
                $s = StripePaymentAPI::getSession($sessionId);
                if (is_array($s) && in_array((string) ($s['payment_status'] ?? ''), ['paid', 'no_payment_required'], true)) {
                    self::markPaid((int) $order['id'], (int) ($s['amount_total'] ?? 0), (string) ($s['currency'] ?? $order['currency']),
                        $sessionId, (string) ($s['payment_intent'] ?? ''), (string) ($s['customer_details']['email'] ?? ''));
                }
            } catch (\Throwable $e) {
                slate_log('Shop: return session check failed: ' . $e->getMessage(), 'warning');
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
    public static function listOrders(string $status = '', int $limit = 200): array {
        self::ensureSchema();
        $args = [current_tenant_id()];
        $where = 'tenant_id = ?';
        if (in_array($status, self::STATUSES, true)) {
            $where .= ' AND status = ?';
            $args[] = $status;
        }
        return Database::rows("SELECT * FROM shop_orders WHERE $where ORDER BY id DESC LIMIT " . max(1, min(1000, $limit)), $args);
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
    public static function fulfil(int $orderId): bool {
        $ok = Database::update('shop_orders', ['status' => 'fulfilled', 'fulfilled_at' => slate_db_now()],
            "id = ? AND tenant_id = ? AND status = 'paid'", [$orderId, current_tenant_id()]) === 1;
        if ($ok && class_exists('AuditLog')) AuditLog::record('shop.order_fulfilled', (string) $orderId);
        return $ok;
    }

    /** pending_payment → cancelled (a checkout that was never paid). */
    public static function cancel(int $orderId): bool {
        $ok = Database::update('shop_orders', ['status' => 'cancelled'],
            "id = ? AND tenant_id = ? AND status = 'pending_payment'", [$orderId, current_tenant_id()]) === 1;
        if ($ok && class_exists('AuditLog')) AuditLog::record('shop.order_cancelled', (string) $orderId);
        return $ok;
    }

    /**
     * paid|fulfilled → refunded: the whole charge is refunded through stripe-payment, and the stock is put back.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function refund(int $orderId): array {
        $order = self::order($orderId);
        if (!$order || !in_array($order['status'], ['paid', 'fulfilled'], true)) return ['ok' => false, 'error' => __('shop_err_cannot_refund', 'Only a paid order can be refunded.')];
        if ((int) $order['total_cents'] > 0) {
            if (empty($order['charge_id']) || !class_exists('StripePaymentAPI')) return ['ok' => false, 'error' => __('shop_err_no_charge', 'No Stripe charge is linked to this order; refund it in Stripe.')];
            $res = StripePaymentAPI::refundCharge((int) $order['charge_id']);
            if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? __('shop_err_refund_failed', 'The refund failed.'))];
        }
        $tid = current_tenant_id();
        if (Database::update('shop_orders', ['status' => 'refunded'], "id = ? AND tenant_id = ? AND status IN ('paid','fulfilled')", [$orderId, $tid]) !== 1) {
            return ['ok' => false, 'error' => __('shop_err_cannot_refund', 'Only a paid order can be refunded.')];
        }
        foreach (self::orderItems($orderId) as $item) {
            if (!empty($item['product_id'])) {
                Database::query("UPDATE shop_products SET stock_qty = stock_qty + ? WHERE id = ? AND tenant_id = ? AND stock_qty IS NOT NULL", [(int) $item['qty'], (int) $item['product_id'], $tid]);
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

    private static function sendOrderEmails(int $orderId): void {
        if (!class_exists('Mailer') || !class_exists('\\Slate\\Services\\Notifications\\EmailTemplate')) return;
        $order = self::order($orderId);
        if (!$order) return;
        try {
            $tpl = '\\Slate\\Services\\Notifications\\EmailTemplate';
            $site = Database::setting('site_name') ?: 'Kohevo';
            $rows = [];
            foreach (self::orderItems($orderId) as $item) {
                $rows[] = [e($item['title']) . ' × ' . (int) $item['qty'], e(self::money((int) $item['line_total_cents'], (string) $order['currency']))];
            }
            if ((int) $order['shipping_cents'] > 0) $rows[] = [e(__('shop_shipping', 'Shipping')), e(self::money((int) $order['shipping_cents'], (string) $order['currency']))];
            $rows[] = ['<strong>' . e(__('shop_total_incl_vat', 'Total (incl. VAT)')) . '</strong>', '<strong>' . e(self::money((int) $order['total_cents'], (string) $order['currency'])) . '</strong>'];
            $subject = sprintf('%s — %s %s', $site, __('shop_email_subject', 'Order confirmed'), $order['ref']);
            $body = $tpl::shell(
                $tpl::heading(__('shop_email_heading', 'Thank you for your order'))
                . $tpl::greeting((string) $order['name'])
                . $tpl::paragraph(e(sprintf(__('shop_email_body', 'We received your payment for order %s.'), $order['ref'])))
                . $tpl::infoCard($rows)
                . $tpl::button(self::confirmationUrl($order), __('shop_view_order', 'View your order')));
            Mailer::send((string) $order['email'], $subject, $body, (string) $order['name']);
            $notify = trim(self::setting('notify_email'));
            if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
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
