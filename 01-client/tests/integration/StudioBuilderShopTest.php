<?php
/**
 * Integration tests for the Shop plugin and its Kohevo Studio widgets (real MySQL).
 *
 * The catalogue (validation, published-only listing, categories, paging, tenant scoping), the session cart (stock
 * caps, sold out, one currency, shipping and the free-shipping threshold), checkout and the payment state machine
 * (one winner for pending → paid, stock taken once, the charge recorded, the Stripe event and the return page only
 * accepted for the order's own session, guarded confirmation links), and the storefront in Studio: the shop pages
 * created in one go, `/product/{slug}` served by the Single template whose conditions name Products, the product grid,
 * the sitemap, and nothing at all without the `shop` entitlement.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!function_exists('unit')) {
    require_once dirname(__DIR__, 2) . '/config.php';
    require_once dirname(__DIR__) . '/guard.php';
    slate_require_test_database();
    require_once dirname(__DIR__) . '/unit/harness.php';
    $studioShopStandalone = true;
}

require_once dirname(__DIR__) . '/support/license_signing.php';
require_once dirname(__DIR__, 2) . '/includes/Plugin.php';
require_once dirname(__DIR__, 2) . '/plugins/studio-builder/StudioBuilder.php';
require_once dirname(__DIR__, 2) . '/plugins/stripe-payment/StripePaymentAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/shop/ShopStoreAPI.php';
require_once dirname(__DIR__, 2) . '/plugins/shop/public/_shop.php';
require_once dirname(__DIR__, 2) . '/plugins/shop/studio/ShopStudioPages.php';

use Slate\Data\Database;
use Slate\Data\MigrationRunner;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Runtime\StudioRuntime;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Plugin\Shop\Studio\ShopStudioPages;
use Slate\Services\Installation\InstallationService;

const SBSH_TENANT = 151;
const SBSH_OTHER = 152;

function sbsh_db(string $dbName, bool $create): ?\PDO
{
    $port = defined('DB_PORT') ? trim((string) DB_PORT) : '';
    $server = 'mysql:host=' . DB_HOST . ($port !== '' ? ';port=' . $port : '');
    $root = new \PDO($server . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    if (!$create) {
        return null;
    }
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4");
    $pdo = new \PDO($server . ";dbname={$dbName};charset=" . DB_CHARSET, DB_USER, DB_PASS, [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
    ]);
    (new MigrationRunner($pdo, SLATE_ROOT . '/db/migrations'))->migrate([
        '0001_core_init', '0002_identity_core', '0011_login_attempts', '0014_tenant_profiles', '0023_installation_identity',
        '0022_remote_license_cache', '0024_remote_license_metadata', '0025_remote_license_cache_installation_id', '0026_remote_license_cache_signed_payload',
    ]);
    $pdo->exec('ALTER TABLE tenants AUTO_INCREMENT = ' . max(1, (int) TENANT_ID));
    return $pdo;
}

/** Run $fn against $pdo with a fresh plugin loader, a test licence server and an empty session; all put back after. */
function sbsh_with(\PDO $pdo, callable $fn): void
{
    $statics = [[Database::class, 'pdo', $pdo], [\PluginLoader::class, 'active', []], [\PluginLoader::class, 'activeSlugs', null], [\PluginLoader::class, 'booted', false]];
    $saved = [];
    foreach ($statics as [$class, $prop, $value]) {
        $p = new \ReflectionProperty($class, $prop);
        $saved[] = [$p, $p->getValue()];
        $p->setValue(null, $value);
    }
    (new \ReflectionProperty(\ShopStoreAPI::class, 'schemaChecked'))->setValue(null, false);
    $env = ['LICENSE_SERVER_URL' => 'https://license.test', 'LICENSE_SERVER_PUBLIC_KEY' => license_test_public_key(), 'LICENSE_PRODUCT' => 'kohevo', 'LICENSE_KEY' => 'test-key'];
    $prevEnv = [];
    foreach ($env as $k => $v) {
        $prevEnv[$k] = $_ENV[$k] ?? null;
        $_ENV[$k] = $v;
    }
    $prevSession = $_SESSION ?? null;
    $_SESSION = [];
    try {
        $fn();
    } finally {
        unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
        PageRoutes::forget();
        $_SESSION = $prevSession ?? [];
        foreach ($saved as [$p, $v]) {
            $p->setValue(null, $v);
        }
        foreach ($prevEnv as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }
}

function sbsh_license(string $installationId, int $tenantId, array $entitlements): void
{
    license_test_seed_cache($tenantId, ['installation_id' => $installationId, 'status' => 'active', 'plan' => 'shop-test',
        'entitlements' => $entitlements, 'expires_at' => null, 'fetched_at' => gmdate('Y-m-d H:i:s')]);
}

/** Run as the shop's tenant (the global current_tenant_id() follows it). */
function sbsh_as(int $tenantId, callable $fn): mixed
{
    $prev = $GLOBALS['SLATE_TENANT_OVERRIDE'] ?? null;
    $GLOBALS['SLATE_TENANT_OVERRIDE'] = $tenantId;
    try {
        return $fn();
    } finally {
        if ($prev === null) {
            unset($GLOBALS['SLATE_TENANT_OVERRIDE']);
        } else {
            $GLOBALS['SLATE_TENANT_OVERRIDE'] = $prev;
        }
    }
}

function sbsh_product(array $in): int
{
    $res = ShopStoreAPI::saveProduct($in + ['status' => 'published']);
    assert_true($res['ok'], 'product saved: ' . json_encode($res['errors'] ?? []));
    return (int) $res['id'];
}

function sbsh_get(StudioRuntime $rt, string $path): ?\Slate\Module\StudioBuilder\Runtime\PublicResponse
{
    return $rt->tenants->runAs(SBSH_TENANT, static fn() => sbsh_as(SBSH_TENANT, static fn() => $rt->publicRuntime->handlePath($path)));
}

unit('shop: catalogue, cart, checkout, payment and the Studio storefront (real MySQL)', function (): void {
    $dbName = 'slate_sbsh_' . slate_test_ns();
    $pdo = sbsh_db($dbName, true);
    try {
        sbsh_with($pdo, static function (): void {
            $core = InstallationService::provisionCore();
            InstallationService::createAdminAccount((int) $core['tenant_id'], 'Shop Admin', 'shop-admin@example.test', password_hash('password123', PASSWORD_DEFAULT));
            Database::query("INSERT INTO tenants (id, name, slug, status) VALUES (?, 'Shop', 'shop-t', 'active'), (?, 'Other', 'other-t', 'active')", [SBSH_TENANT, SBSH_OTHER]);
            $iid = (string) $core['installation_id'];
            foreach ([(int) $core['tenant_id'], SBSH_TENANT, SBSH_OTHER] as $t) {
                sbsh_license($iid, $t, ['studio-builder', 'shop']);
            }
            foreach (['studio-builder', 'stripe-payment', 'shop'] as $slug) {
                assert_true(!empty(\PluginLoader::installFromDisk($slug)['ok']), "install {$slug}");
            }
            ShopStoreAPI::ensureSchema();

            // ── Catalogue ─────────────────────────────────────────────────────────
            sbsh_as(SBSH_TENANT, static function (): void {
                ShopStoreAPI::setSetting('currency', 'EUR');
                $bad = ShopStoreAPI::saveProduct(['title' => '', 'price' => 'abc', 'sale_price' => '1', 'images' => "javascript:alert(1)"]);
                assert_true(!$bad['ok'] && isset($bad['errors']['title'], $bad['errors']['price'], $bad['errors']['images']), 'title, price and image are checked');
                assert_true(isset(ShopStoreAPI::saveProduct(['title' => 'X', 'price' => '10', 'images' => '//evil.test/x.png'])['errors']['images']), 'a protocol-relative image is refused');
                assert_true(isset(ShopStoreAPI::saveProduct(['title' => 'X', 'price' => '10', 'sale_price' => '10'])['errors']['sale_price']), 'a sale price must be lower');
                assert_true(isset(ShopStoreAPI::saveProduct(['title' => 'X', 'price' => '10', 'track_stock' => '1', 'stock_qty' => '-1'])['errors']['stock_qty']), 'stock is 0 or more');

                $cat = ShopStoreAPI::saveCategory(['name' => 'Apparel'])['id'];
                $gear = ShopStoreAPI::saveCategory(['name' => 'Gear'])['id'];
                sbsh_product(['title' => 'Club <b>T-shirt</b>', 'price' => '25,00', 'sale_price' => '19.90', 'sku' => 'TS-1', 'track_stock' => '1', 'stock_qty' => '3',
                    'images' => "/uploads/media/ts-front.jpg\nhttps://cdn.example.test/ts-back.jpg", 'short_description' => 'Soft cotton.', 'description' => "Line one.\n\nSecond <para>.",
                    'categories' => [$cat], 'sort_order' => 1]);
                sbsh_product(['title' => 'Gloves', 'price' => '49.00', 'categories' => [$gear], 'sort_order' => 2]);
                sbsh_product(['title' => 'Sold out cap', 'price' => '15', 'track_stock' => '1', 'stock_qty' => '0', 'categories' => [$cat], 'sort_order' => 3]);
                sbsh_product(['title' => 'Hidden draft', 'price' => '5', 'status' => 'draft']);
                assert_true(isset(ShopStoreAPI::saveProduct(['title' => 'Gloves', 'price' => '1'])['errors']['slug']), 'a taken address is refused');

                $all = ShopStoreAPI::listProducts();
                assert_eq(['club-b-t-shirt-b', 'gloves', 'sold-out-cap'], array_column($all['rows'], 'slug'), 'published only, in the shop\'s order');
                $ts = $all['rows'][0];
                assert_eq([2500, 1990, true, 'TS-1', 3, true], [$ts['price_cents'], $ts['effective_cents'], $ts['on_sale'], $ts['sku'], $ts['stock_qty'], $ts['in_stock']]);
                assert_eq('/uploads/media/ts-front.jpg', $ts['image']);
                assert_eq('/product/club-b-t-shirt-b', $ts['url']);
                assert_eq(['club-b-t-shirt-b', 'sold-out-cap'], array_column(ShopStoreAPI::listProducts(['category' => 'apparel'])['rows'], 'slug'), 'a category filters');
                $p2 = ShopStoreAPI::listProducts(['per_page' => 2, 'page' => 2]);
                assert_eq([3, 2, 2, ['sold-out-cap']], [$p2['total'], $p2['pages'], $p2['page'], array_column($p2['rows'], 'slug')], 'pages');
                assert_eq('gloves', ShopStoreAPI::listProducts(['order' => 'price_desc'])['rows'][0]['slug'], 'price order uses the price paid');
                assert_eq(null, ShopStoreAPI::productBySlug('hidden-draft'), 'a draft has no page');
            });
            sbsh_as(SBSH_OTHER, static function (): void {
                assert_eq(0, ShopStoreAPI::listProducts()['total'], 'another tenant sees none of these products');
                assert_eq(null, ShopStoreAPI::productBySlug('gloves'));
                assert_true(!ShopStoreAPI::cartAdd(2)['ok'], 'nor can it put one in a cart');
            });

            // ── Cart ──────────────────────────────────────────────────────────────
            sbsh_as(SBSH_TENANT, static function (): void {
                $ids = array_column(Database::rows('SELECT id, slug FROM shop_products WHERE tenant_id = ?', [SBSH_TENANT]), 'id', 'slug');
                [$tsId, $glovesId, $capId, $draftId] = [(int) $ids['club-b-t-shirt-b'], (int) $ids['gloves'], (int) $ids['sold-out-cap'], (int) $ids['hidden-draft']];
                assert_true(!ShopStoreAPI::cartAdd($capId)['ok'], 'sold out');
                assert_true(!ShopStoreAPI::cartAdd($draftId)['ok'], 'a draft cannot be bought');
                assert_true(ShopStoreAPI::cartAdd($tsId, 2)['ok']);
                $over = ShopStoreAPI::cartAdd($tsId, 5);
                assert_true(!$over['ok'] && $over['qty'] === 3, 'capped at the stock');
                ShopStoreAPI::cartAdd($glovesId);
                Database::update('shop_products', ['currency' => 'USD'], 'id = ?', [$capId]);
                Database::update('shop_products', ['stock_qty' => 9], 'id = ?', [$capId]);
                assert_true(!ShopStoreAPI::cartAdd($capId)['ok'], 'a product in another currency is refused');
                Database::update('shop_products', ['currency' => 'EUR', 'stock_qty' => 0], 'id = ?', [$capId]);

                ShopStoreAPI::setSetting('shipping_cents', '500');
                ShopStoreAPI::setSetting('free_over_cents', '20000');
                $t = ShopStoreAPI::cartTotals('shipping');
                assert_eq([4, 3 * 1990 + 4900, 500], [$t['count'], $t['subtotal_cents'], $t['shipping_cents']], 'subtotal at the sale price, flat shipping');
                assert_eq(0, ShopStoreAPI::cartTotals('pickup')['shipping_cents'], 'pickup is free');
                ShopStoreAPI::setSetting('free_over_cents', '5000');
                assert_eq(0, ShopStoreAPI::cartTotals('shipping')['shipping_cents'], 'free above the threshold');
                ShopStoreAPI::setSetting('free_over_cents', '0');

                $html = shop_view_cart(ShopStoreAPI::cartTotals());
                assert_true(str_contains($html, 'Club &lt;b&gt;T-shirt&lt;/b&gt;') && !str_contains($html, '<b>T-shirt'), 'the cart escapes product names');
                assert_true(str_contains($html, 'name="_csrf"'), 'its forms carry the session token');
                assert_true(str_contains(shop_view_mini(ShopStoreAPI::cartTotals()), 'data-count="4"'));
                assert_true(str_contains(shop_view_mini(ShopStoreAPI::cartTotals(), false, 'Panier <b>'), '<span class="sb-shop-mini__label">Panier &lt;b&gt;</span>'), 'the widget\'s own label, escaped');

                // ── Checkout ──────────────────────────────────────────────────────
                $bad = ShopStoreAPI::placeOrder(['email' => 'nope', 'name' => '', 'delivery' => 'shipping', 'country' => 'US']);
                assert_true(!$bad['ok'] && isset($bad['errors']['email'], $bad['errors']['name'], $bad['errors']['address_line1'], $bad['errors']['country']), 'details and the address are checked');
                $guest = ['email' => 'Ana@Example.test', 'name' => "Ana\nDupont", 'delivery' => 'shipping', 'address_line1' => 'Rue Haute 1', 'postal_code' => '1000', 'city' => 'Bruxelles', 'country' => 'BE'];
                $placed = ShopStoreAPI::placeOrder($guest);
                assert_true($placed['ok'], 'a guest order is placed: ' . json_encode($placed['errors'] ?? []));
                $order = $placed['order'];
                assert_eq(['pending_payment', 'ana@example.test', 'Ana Dupont', 3 * 1990 + 4900 + 500, 'EUR'], [$order['status'], $order['email'], $order['name'], (int) $order['total_cents'], $order['currency']]);
                assert_true((bool) preg_match('/^KS-[A-Z0-9]{6}$/', $order['ref']) && strlen($order['access_key']) === 40, 'a ref and a secret key');
                $items = ShopStoreAPI::orderItems((int) $order['id']);
                assert_eq([['Club <b>T-shirt</b>', 'TS-1', 1990, 3], ['Gloves', null, 4900, 1]], array_map(static fn($i) => [$i['title'], $i['sku'], (int) $i['unit_price_cents'], (int) $i['qty']], $items), 'lines snapshot title, SKU and price');
                assert_eq(3, (int) Database::value('SELECT stock_qty FROM shop_products WHERE id = ?', [$tsId]), 'no stock is taken before payment');
                assert_true(!ShopStoreAPI::startPayment($order)['ok'], 'without Stripe keys checkout says payment is unavailable');

                // The Stripe event: only this order's own session, from Shop, paid.
                Database::update('shop_orders', ['stripe_session_id' => 'cs_test_A'], 'id = ?', [(int) $order['id']]);
                $event = static fn(string $sid, string $source = 'shop', string $status = 'paid') => ['type' => 'checkout.session.completed', 'data' => ['object' => [
                    'id' => $sid, 'payment_status' => $status, 'amount_total' => (int) $order['total_cents'], 'currency' => 'eur', 'payment_intent' => 'pi_A',
                    'customer_details' => ['email' => 'ana@example.test'], 'metadata' => ['source_plugin' => $source, 'shop_order_id' => (string) $order['id']]]]];
                ShopStoreAPI::handleStripeEvent($event('cs_test_OTHER'));
                ShopStoreAPI::handleStripeEvent($event('cs_test_A', 'membership'));
                ShopStoreAPI::handleStripeEvent($event('cs_test_A', 'shop', 'unpaid'));
                assert_eq('pending_payment', ShopStoreAPI::order((int) $order['id'])['status'], 'another session, another plugin or an unpaid session changes nothing');
                ShopStoreAPI::handleStripeEvent($event('cs_test_A'));
                $paid = ShopStoreAPI::order((int) $order['id']);
                assert_eq('paid', $paid['status'], 'the webhook marks it paid');
                assert_eq(0, (int) Database::value('SELECT stock_qty FROM shop_products WHERE id = ?', [$tsId]), 'stock is taken once');
                assert_true(!ShopStoreAPI::markPaid((int) $order['id'], 100, 'EUR'), 'a second completion (the return page, a retry) does nothing');
                assert_eq(0, (int) Database::value('SELECT stock_qty FROM shop_products WHERE id = ?', [$tsId]), 'and takes no more stock');
                $charge = Database::row("SELECT * FROM stripepayment_charges WHERE source_plugin = 'shop' AND source_id = ?", [(string) $order['id']]);
                assert_true($charge !== null && (int) $charge['amount_cents'] === (int) $order['total_cents'] && (int) $paid['charge_id'] === (int) $charge['id'], 'the charge is in the shared ledger and linked');

                // Confirmation links.
                assert_eq(null, ShopStoreAPI::orderByRefKey($order['ref'], str_repeat('0', 40)), 'a wrong key reveals nothing');
                assert_eq(null, ShopStoreAPI::orderByRefKey($order['ref'], 'not-hex'), 'nor a malformed one');
                ShopStoreAPI::cartAdd($glovesId);
                $back = ShopStoreAPI::confirmReturn($order['ref'], $order['access_key'], 'cs_test_A');
                assert_eq('paid', $back['status'] ?? null);
                assert_eq([], ShopStoreAPI::cartItems(), 'the return page empties the cart');
                $view = shop_view_order($back);
                assert_true(str_contains($view, $order['ref']) && str_contains($view, 'Club &lt;b&gt;T-shirt&lt;/b&gt; × 3'), 'the confirmation lists the order, escaped');

                // Order actions.
                assert_true(!ShopStoreAPI::cancel((int) $order['id']), 'a paid order cannot be cancelled');
                assert_true(ShopStoreAPI::fulfil((int) $order['id']) && !ShopStoreAPI::fulfil((int) $order['id']), 'paid → fulfilled once');
                Database::update('shop_orders', ['charge_id' => null], 'id = ?', [(int) $order['id']]);
                assert_true(!ShopStoreAPI::refund((int) $order['id'])['ok'], 'no linked charge, no refund from here');

                // An oversold item is flagged on the order instead of driving stock below zero.
                ShopStoreAPI::cartAdd($glovesId, 2);
                $o2 = ShopStoreAPI::placeOrder(['email' => 'b@example.test', 'name' => 'B', 'delivery' => 'pickup'])['order'];
                assert_eq(0, (int) $o2['shipping_cents'], 'pickup costs nothing');
                Database::update('shop_products', ['stock_qty' => 1], 'id = ?', [$glovesId]);
                assert_true(ShopStoreAPI::markPaid((int) $o2['id'], 0, 'EUR'));
                assert_true(str_contains((string) ShopStoreAPI::order((int) $o2['id'])['stock_note'], 'Short of stock: Gloves'), 'the shortfall is noted');
                assert_eq(0, (int) Database::value('SELECT stock_qty FROM shop_products WHERE id = ?', [$glovesId]), 'stock stops at zero');

                // A checkout abandoned for a day is cancelled; a fresh one is not.
                ShopStoreAPI::cartClear();
                ShopStoreAPI::cartSet($glovesId, 1);
                Database::update('shop_products', ['stock_qty' => null], 'id = ?', [$glovesId]);
                ShopStoreAPI::cartAdd($glovesId);
                $old = ShopStoreAPI::placeOrder(['email' => 'c@example.test', 'name' => 'C', 'delivery' => 'pickup'])['order'];
                Database::update('shop_orders', ['created_at' => date('Y-m-d H:i:s', time() - 2 * 86400)], 'id = ?', [(int) $old['id']]);
                $new = ShopStoreAPI::placeOrder(['email' => 'd@example.test', 'name' => 'D', 'delivery' => 'pickup'])['order'];
                assert_eq(1, ShopStoreAPI::cancelStale());
                assert_eq(['cancelled', 'pending_payment'], [ShopStoreAPI::order((int) $old['id'])['status'], ShopStoreAPI::order((int) $new['id'])['status']]);
            });

            // ── Variants ──────────────────────────────────────────────────────────
            sbsh_as(SBSH_TENANT, static function (): void {
                ShopStoreAPI::cartClear();
                assert_true(isset(ShopStoreAPI::parseAttributes("Couleur Blanc")['error']), 'an attribute line needs a colon');
                assert_true(isset(ShopStoreAPI::parseAttributes("Couleur:")['error']), 'and values');
                assert_true(isset(ShopStoreAPI::parseAttributes("A: 1\nB: 1\nC: 1\nD: 1")['error']), 'at most three attributes');
                assert_eq([['name' => 'Couleur', 'values' => ['Blanc', 'noir']]], ShopStoreAPI::parseAttributes("Couleur: Blanc, noir, Noir |\ncouleur: Rouge")['attributes'], 'repeated values and attributes are dropped');

                $hoodieId = sbsh_product(['title' => 'Hoodie', 'price' => '35', 'sku' => 'HD', 'images' => '/uploads/media/hoodie.jpg', 'attributes' => "Couleur: Rose, Bleu\nTaille: S, M, L"]);
                $rows = ShopStoreAPI::variantRows($hoodieId);
                assert_eq(6, count($rows), 'one variant per combination');
                assert_eq(['Rose', 'S'], ShopStoreAPI::variantValues($rows[0]), 'first attribute slowest');
                $key = static fn(array $values): string => ShopStoreAPI::optionsKey($values);
                $fields = [];
                foreach ($rows as $r) $fields[(string) $r['options_key']] = ['enabled' => '1'];
                $fields[$key(['Rose', 'S'])] = ['sku' => 'HD-RS', 'stock' => '1', 'enabled' => '1', 'image' => '/uploads/media/hoodie-rose.jpg'];
                $fields[$key(['Bleu', 'L'])] = ['price' => '39,00', 'enabled' => '1'];
                $fields[$key(['Bleu', 'M'])] = ['enabled' => ''];
                $fields[$key(['Rose', 'L'])] = ['stock' => '0', 'enabled' => '1'];
                assert_true(isset(ShopStoreAPI::saveProduct(['title' => 'Hoodie', 'price' => '35', 'attributes' => "Couleur: Rose, Bleu\nTaille: S, M, L", 'variants' => [$key(['Rose', 'M']) => ['price' => 'abc']]], $hoodieId)['errors']['variants']), 'a variant price is checked');
                $res = ShopStoreAPI::saveProduct(['title' => 'Hoodie', 'price' => '35', 'sku' => 'HD', 'status' => 'published', 'images' => '/uploads/media/hoodie.jpg', 'attributes' => "Couleur: Rose, Bleu\nTaille: S, M, L", 'variants' => $fields], $hoodieId);
                assert_true($res['ok'], 'variants saved: ' . json_encode($res['errors'] ?? []));

                $p = ShopStoreAPI::productBySlug('hoodie');
                assert_true($p['has_variants'] && $p['in_stock'], 'a product with variants, in stock');
                assert_eq([['name' => 'Couleur', 'values' => ['Rose', 'Bleu']], ['name' => 'Taille', 'values' => ['S', 'M', 'L']]], $p['attributes']);
                assert_eq(5, count($p['variants']), 'a disabled combination is not offered');
                assert_eq([3500, 3900], [$p['min_cents'], $p['max_cents']], 'the price range');
                $byLabel = array_column($p['variants'], null, 'label');
                assert_eq(['HD-RS', 1, true, '/uploads/media/hoodie-rose.jpg'], [$byLabel['Rose / S']['sku'], $byLabel['Rose / S']['stock_qty'], $byLabel['Rose / S']['in_stock'], $byLabel['Rose / S']['image']]);
                assert_eq(['HD', null, ''], [$byLabel['Rose / M']['sku'], $byLabel['Rose / M']['stock_qty'], $byLabel['Rose / M']['image']], 'what a variant leaves empty comes from the product');
                assert_true(!$byLabel['Rose / L']['in_stock'], 'a variant with no stock is sold out');

                assert_eq('Choose your options first.', ShopStoreAPI::cartAdd($hoodieId)['error'] ?? null, 'a product with variants needs a choice');
                assert_true(!ShopStoreAPI::cartAdd($hoodieId, 1, 0, ['Rose', 'XL'])['ok'], 'an unknown value is refused');
                assert_true(!ShopStoreAPI::cartAdd($hoodieId, 1, 0, ['Bleu', 'M'])['ok'], 'a disabled combination is refused');
                assert_eq('This product is sold out.', ShopStoreAPI::cartAdd($hoodieId, 1, 0, ['Rose', 'L'])['error'] ?? null, 'a sold-out variant is refused');
                assert_true(!ShopStoreAPI::cartAdd($hoodieId, 1, $byLabel['Rose / S']['id'] + 100000)['ok'], 'a variant of another product is refused');
                assert_true(ShopStoreAPI::cartAdd($hoodieId, 1, 0, ['Taille' => 'S', 'Couleur' => 'Rose'])['ok'], 'chosen by attribute name');
                $capped = ShopStoreAPI::cartAdd($hoodieId, 1, $byLabel['Rose / S']['id']);
                assert_true(!$capped['ok'] && $capped['qty'] === 1, 'capped at the variant\'s stock');
                assert_true(ShopStoreAPI::cartAdd($hoodieId, 2, $byLabel['Bleu / L']['id'])['ok'], 'chosen by variant id');
                $lines = ShopStoreAPI::cartLines();
                assert_eq([['Rose / S', 'HD-RS', 3500, 1, '/uploads/media/hoodie-rose.jpg'], ['Bleu / L', 'HD', 3900, 2, '/uploads/media/hoodie.jpg']],
                    array_map(static fn($l) => [$l['options_label'], $l['sku'], $l['unit_cents'], $l['qty'], $l['image']], $lines), 'each variant is its own line, at its own price');
                $cartHtml = shop_view_cart(ShopStoreAPI::cartTotals());
                assert_true(str_contains($cartHtml, '<span class="sb-shop-cart__options">Bleu / L</span>') && str_contains($cartHtml, 'name="line" value="' . $hoodieId . ':' . $byLabel['Bleu / L']['id'] . '"'), 'the cart shows the options and names the line');
                ShopStoreAPI::cartSet($hoodieId . ':' . $byLabel['Bleu / L']['id'], 1);
                assert_eq(1, ShopStoreAPI::cartLines()[1]['qty'], 'a line is updated by its key');
                ShopStoreAPI::cartSet('nonsense', 0);
                assert_eq(2, count(ShopStoreAPI::cartLines()), 'a malformed key changes nothing');

                $placed = ShopStoreAPI::placeOrder(['email' => 'v@example.test', 'name' => 'V', 'delivery' => 'pickup']);
                assert_true($placed['ok'], 'an order with variants');
                $items = ShopStoreAPI::orderItems((int) $placed['order']['id']);
                assert_eq([['Hoodie', 'Rose / S', 'HD-RS', $byLabel['Rose / S']['id']], ['Hoodie', 'Bleu / L', 'HD', $byLabel['Bleu / L']['id']]],
                    array_map(static fn($i) => [$i['title'], $i['options_label'], $i['sku'], (int) $i['variant_id']], $items), 'order lines record the combination');
                assert_true(ShopStoreAPI::markPaid((int) $placed['order']['id'], 0, 'EUR'));
                assert_eq(0, (int) Database::value('SELECT stock_qty FROM shop_variants WHERE id = ?', [$byLabel['Rose / S']['id']]), 'paying takes the variant\'s stock');
                assert_eq(null, Database::value('SELECT stock_qty FROM shop_products WHERE id = ?', [$hoodieId]), 'not the product\'s');
                assert_true(str_contains(shop_view_order(ShopStoreAPI::order((int) $placed['order']['id'])), 'Hoodie — Rose / S × 1'), 'the confirmation names the combination');
                assert_true(!ShopStoreAPI::productBySlug('hoodie')['variants'][0]['in_stock'], 'Rose / S is now sold out');

                assert_eq(['Bleu / L'], array_column(ShopStoreAPI::cartLines(), 'options_label'), 'a sold-out variant leaves the cart');
                // Changing the attributes keeps the combinations that remain and drops the others.
                $keep = (int) Database::value('SELECT id FROM shop_variants WHERE options_key = ?', [$key(['Rose', 'S'])]);
                $res = ShopStoreAPI::saveVariants($hoodieId, [['name' => 'Couleur', 'values' => ['Rose']], ['name' => 'Taille', 'values' => ['S', 'XL']]]);
                assert_true($res['ok']);
                assert_eq([$keep, 'HD-RS'], [(int) ShopStoreAPI::variantRows($hoodieId)[0]['id'], ShopStoreAPI::variantRows($hoodieId)[0]['sku']], 'Rose / S keeps its id and fields');
                assert_eq(['Rose / S', 'Rose / XL'], array_map(static fn($r) => ShopStoreAPI::optionsLabel(ShopStoreAPI::variantValues($r)), ShopStoreAPI::variantRows($hoodieId)));
                assert_eq([], ShopStoreAPI::cartLines(), 'a cart line whose variant is gone is dropped');
                ShopStoreAPI::cartClear();
                $res = ShopStoreAPI::saveVariants($hoodieId, [['name' => 'Couleur', 'values' => ['Rose', 'Bleu']], ['name' => 'Taille', 'values' => ['S', 'M', 'L']]], [$key(['Bleu', 'L']) => ['price' => '39', 'enabled' => '1']]);
                assert_true($res['ok']);
            });

            // ── Studio storefront ─────────────────────────────────────────────────
            $rt = StudioRuntimeFactory::build();
            $actor = StudioActor::authenticated(31, StudioPermissions::ALL, true);
            $base = defined('SLATE_URL') ? rtrim((string) SLATE_URL, '/') : '';

            assert_eq(null, sbsh_get($rt, '/product/gloves'), 'no product template yet: the ordinary 404');

            $made = $rt->tenants->runAs(SBSH_TENANT, static fn() => sbsh_as(SBSH_TENANT, static fn() => ShopStudioPages::create($rt, $actor)));
            assert_eq(['cart', 'checkout', 'order-received', ShopStudioPages::TEMPLATE_SLUG], array_column($made, 'slug'));
            assert_true(!in_array(false, array_column($made, 'created'), true), 'all four are created');
            $again = $rt->tenants->runAs(SBSH_TENANT, static fn() => sbsh_as(SBSH_TENANT, static fn() => ShopStudioPages::create($rt, $actor)));
            assert_true(!in_array(true, array_column($again, 'created'), true), 'running it again changes nothing');

            $r = sbsh_get($rt, '/product/club-b-t-shirt-b');
            assert_true($r !== null && $r->status === 200, 'the product page is served by its Single template');
            $html = (string) $r->body;
            assert_true(str_contains($html, '<h1 class="sb-shop-title">Club &lt;b&gt;T-shirt&lt;/b&gt;</h1>'), 'title, escaped');
            assert_true(str_contains($html, 'sb-shop-price--sale') && str_contains($html, 'sb-shop-stock--out'), 'sale price and stock (sold out after the order)');
            assert_true(str_contains($html, '<title>Club &lt;b&gt;T-shirt&lt;/b&gt;'), 'the page title is the product');
            assert_true(str_contains($html, 'sb-shop-gallery__thumb') && str_contains($html, 'https://cdn.example.test/ts-back.jpg'), 'its other images');
            assert_true(str_contains($html, '<p>Second &lt;para&gt;.</p>'), 'the description, escaped, in paragraphs');
            if ($base !== '') {
                assert_true(str_contains($html, '<link rel="canonical" href="' . $base . '/product/club-b-t-shirt-b">'), 'canonical is the product address');
            }
            $gloves = (string) sbsh_get($rt, '/product/gloves')?->body;
            assert_true(str_contains($gloves, 'action="' . $base . '/plugins/shop/public/cart.php"') && str_contains($gloves, 'data-sb-fragment-group="shop-cart"'), 'Add to cart posts to the cart and refreshes the mini cart');
            assert_eq(null, sbsh_get($rt, '/product/hidden-draft'), 'a draft product is not found');
            assert_eq(null, sbsh_get($rt, '/product/nothing'), 'an unknown product is not found');
            assert_eq(null, sbsh_get($rt, '/product/gloves/extra'), 'nor a deeper address');

            $cart = (string) sbsh_get($rt, '/cart')?->body;
            assert_true(str_contains($cart, 'data-sb-fragment="' . $base . '/plugins/shop/public/cart.php?view=cart"'), 'the Cart page fills itself from the cart endpoint');
            assert_true(!str_contains($cart, 'Gloves'), 'a cached page holds no visitor\'s cart');
            $thanks = (string) sbsh_get($rt, '/order-received')?->body;
            assert_true(str_contains($thanks, 'data-sb-fragment-query="order,key"'), 'the confirmation passes on the order link');

            if ($base !== '') {
                $xml = (string) sbsh_get($rt, '/sitemap.xml?format=xml')?->body;
                assert_true(str_contains($xml, '<loc>' . $base . '/product/gloves</loc>'), 'the sitemap lists published products');
                assert_true(!str_contains($xml, 'hidden-draft'), 'not drafts');
            }

            // A Product grid on an ordinary page: cards, the visitor's category filter and pages.
            $rt->tenants->runAs(SBSH_TENANT, static fn() => sbsh_as(SBSH_TENANT, static function () use ($rt, $actor): void {
                $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Shop');
                $doc['sections'] = [[
                    'blocks' => [[
                        'bindings' => ['items' => ['provider' => 'shop.products']], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(),
                        'props' => ['per_page' => 2, 'columns' => 4, 'show_filter' => true, 'heading' => 'Our <shop>'],
                        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => 'shop.products', 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                    ]],
                    'global_ref' => null, 'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'Main',
                    'layout' => CanonicalDocumentSchema::defaultSectionLayout(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                ]];
                $created = $rt->app->createPage($actor, 'Shop', 'shop-all', 'page', 'standalone');
                $saved = $rt->app->saveDraft($actor, (int) $created['page']['id'], $doc, (int) $created['revision']['id']);
                $rt->app->publish($actor, (int) $created['page']['id'], (int) $saved['revision']['id']);
            }));
            $grid = (string) sbsh_get($rt, '/shop-all')?->body;
            assert_true(str_contains($grid, 'style="--sb-shop-cols:4"') && str_contains($grid, 'Our &lt;shop&gt;'), 'columns and an escaped heading');
            assert_eq(2, substr_count($grid, '<li class="sb-shop-card">'), 'one page of cards');
            assert_true(str_contains($grid, 'class="sb-shop-filter"') && str_contains($grid, 'href="?category=apparel"'), 'the category filter');
            assert_true(str_contains($grid, 'href="?page=2"') && str_contains($grid, 'aria-current="page">1<'), 'pages');
            assert_true(str_contains($grid, 'sb-shop-card__badge') && !str_contains($grid, 'Hidden draft'), 'badges, and no drafts');

            // A product with variants: one picker per attribute, the combinations for the runtime, the price range.
            $hoodie = (string) sbsh_get($rt, '/product/hoodie')?->body;
            assert_true(str_contains($hoodie, 'name="opt[0]"') && str_contains($hoodie, 'name="opt[1]"') && str_contains($hoodie, '<option value="Bleu">Bleu</option>'), 'a picker per attribute');
            assert_true(str_contains($hoodie, 'data-sb-variants="') && str_contains($hoodie, 'name="variant_id"') && str_contains($hoodie, 'data-sb-variant-submit'), 'the combinations for the runtime');
            assert_true(str_contains($hoodie, 'sb-shop-price__range'), 'the price range');
            assert_true(str_contains($grid, 'sb-shop-card__choose') && str_contains($grid, 'href="' . $base . '/product/hoodie"'), 'in a grid, a product with options links to its page');
            $rt->tenants->runAs(SBSH_TENANT, static fn() => sbsh_as(SBSH_TENANT, static function () use ($rt, $actor): void {
                $doc = CanonicalDocumentSchema::emptyDocument('page', 'default', 'Cards');
                $doc['sections'] = [[
                    'blocks' => [[
                        'bindings' => ['items' => ['provider' => 'shop.products']], 'children' => [], 'id' => CanonicalDocumentSchema::newBlockId(),
                        'props' => ['link_label' => 'Acheter →', 'show_icon' => true, 'icon' => 'heart'],
                        'style' => CanonicalDocumentSchema::defaultBlockStyle(), 'type' => 'shop.products', 'version' => 1, 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                    ]],
                    'global_ref' => null, 'id' => CanonicalDocumentSchema::newSectionId(), 'label' => 'Main',
                    'layout' => CanonicalDocumentSchema::defaultSectionLayout(), 'visibility' => CanonicalDocumentSchema::defaultVisibility(),
                ]];
                $created = $rt->app->createPage($actor, 'Cards', 'shop-cards', 'page', 'standalone');
                $saved = $rt->app->saveDraft($actor, (int) $created['page']['id'], $doc, (int) $created['revision']['id']);
                $rt->app->publish($actor, (int) $created['page']['id'], (int) $saved['revision']['id']);
            }));
            $cards = (string) sbsh_get($rt, '/shop-cards')?->body;
            assert_true(str_contains($cards, '<a class="sb-shop-card__link" href="' . $base . '/product/gloves">Acheter →</a>'), 'a text link to the product');
            assert_true(!str_contains($cards, 'class="sb-button sb-button--primary sb-shop-add__button') && str_contains($cards, 'class="sb-shop-card__icon"'), 'in place of the button, with the card icon');

            // A site whose addresses end with a slash (Site settings › SEO) gets its products' addresses the same way.
            sbsh_as(SBSH_TENANT, static fn() => Database::setSetting(\Slate\Module\StudioBuilder\Meta\PageRoutes::SETTING_TRAILING_SLASH, '1', SBSH_TENANT));
            \Slate\Module\StudioBuilder\Meta\PageRoutes::forget();
            assert_eq('/product/gloves/', sbsh_as(SBSH_TENANT, static fn() => ShopStoreAPI::productBySlug('gloves')['url']), 'a product address with the slash');
            $slashed = (string) sbsh_get($rt, '/product/gloves/')?->body;
            if ($base !== '') {
                assert_true(str_contains($slashed, '<link rel="canonical" href="' . $base . '/product/gloves/">'), 'its canonical too');
                assert_true(str_contains((string) sbsh_get($rt, '/sitemap.xml?format=xml')?->body, '<loc>' . $base . '/product/gloves/</loc>'), 'and the sitemap');
            }
            sbsh_as(SBSH_TENANT, static fn() => Database::setSetting(\Slate\Module\StudioBuilder\Meta\PageRoutes::SETTING_TRAILING_SLASH, '0', SBSH_TENANT));
            \Slate\Module\StudioBuilder\Meta\PageRoutes::forget();

            // Without the shop entitlement the product pages are gone.
            sbsh_license((string) $core['installation_id'], SBSH_TENANT, ['studio-builder']);
            assert_eq(null, sbsh_get($rt, '/product/gloves'), 'unlicensed: the ordinary 404');
            sbsh_license((string) $core['installation_id'], SBSH_TENANT, ['studio-builder', 'shop']);
            assert_true(sbsh_get($rt, '/product/gloves')?->status === 200, 'licensed again');
        });
    } finally {
        sbsh_db($dbName, false);
    }
});

if (!empty($studioShopStandalone)) {
    exit(unit_summary());
}
