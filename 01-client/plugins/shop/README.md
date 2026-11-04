# Shop

Sell physical, digital and service products from a Kohevo Studio site: products with variants, a session cart, guest
checkout, payment through the shared payment layer (Stripe, PayPal) and an orders screen. The storefront is built in Studio with the Shop widgets; this plugin owns the data and the
per-visitor endpoints.

## Design principles

- **Products are Shop's own rows, not Studio pages.** Price, stock and orders need relational rows and an atomic
  stock update; Studio's content types keep their values as JSON settings per page, and Studio ships no built-in
  post types. A product page is a Theme Builder **Single template whose display condition is Products**
  (`singular` = `product`), served by Studio at `/product/{slug}` (the prefix is a setting) through the
  `singles` key of `studio-widgets.php` (`Registry\PluginSingleInterface`).
- **Payments are the shared ledger.** Checkout uses `StripePaymentAPI::createCheckout()` (Stripe Checkout, hosted),
  like Booking and Membership; the charge lands in `stripepayment_charges` and `shop_orders.charge_id` points at it.
  Refunds go through `StripePaymentAPI::refundCharge()`. The API class is `ShopStoreAPI`, not `ShopAPI`, so the
  stripe-payment plugin's back-compat path for an archived Shop of that name stays inert.
- **One price per product**, in its own currency (changing the shop currency never re-prices a product). Whether it
  includes tax is the shop's tax mode (below). With no shipping zone, shipping is one flat rate with an optional
  free-shipping threshold, or free pickup, as before 0.4.0.
- **Guests check out.** Name and email are enough; a signed-in customer's order is linked to their account. A guest
  sees the order through a link with an unguessable key.

## Catalogue (0.3.0)

- **Product types.** Physical (shipped or collected), digital (files to download) and service (nothing to ship, such
  as a class). A cart holding only digital and service products checks out without a delivery choice or an address
  (the order's `delivery` is `none`).
- **Weight and size** per product and per variant (a variant's empty value is the product's), in the units set under
  Shop settings › Units (kg, g, lb, oz; cm, mm, m, in). Changing a unit does not convert stored numbers.
  `ShopStoreAPI::toGrams()` gives a common weight for shipping rules.
- **Sale dates.** A sale price applies between `sale_starts_at` and `sale_ends_at` (either may be empty); the product's
  dates also govern its variants' sale prices. Listing order by price uses the price paid now.
- **Tags, featured, upsells, cross-sells.** Tags are made as they are typed. The Product Grid's *Show* setting lists
  every product, the featured ones, or, on a product's page, its upsells, cross-sells or related products (same
  category, else same tag); it can also keep only one tag. `ShopStoreAPI::cartCrossSells()` gives the cross-sells of
  a cart. Product Meta can show tags, weight and size.
- **Low stock.** A level per product, else the shop's (Shop settings; empty turns alerts off). The sale that takes
  tracked stock to the level or below sends one email (to the low-stock address, else the new-orders address). The
  products list filters by type and by low stock (`?stock=low`).
- **Digital files.** Uploaded on the product (any of the extensions in `ShopStoreAPI::FILE_EXTS`, 512 MB each), kept
  in `data/shop-files/<tenant>/` (data/ is never served: its `.htaccess` and the dev router refuse it), never under
  `uploads/`. A paid order gets one
  link per file of each digital line (`shop_downloads`, a 40-hex token, the product's download limit and days copied
  in); `public/download.php?t=…` counts and streams it. Links stop working when the order is refunded.
- **Note to buyers** (`purchase_note`): shown on the confirmation page and in the receipt email of a paid order.

## Importing from WooCommerce

Products › Import takes WooCommerce's product CSV (Products › Export) or a JSON list from its REST API, shows a
preview (new, updated, skipped, and a note for anything that does not carry over), then imports. Images are copied
into the Media library (or kept as addresses), weights and sizes are converted from the file's units, and every
product and variant keeps `external_ref` = `woo:<id>`, so a second import of the same file updates instead of
duplicating (failing that, a product is matched by SKU, then address). Variations become variants; combinations the
file does not list are kept but not sold. Grouped and external products, attributes no variation uses, tax and
shipping classes and reviews are not imported. See `ShopImport.php`.

Orders › Import orders brings a WooCommerce shop's orders and customers over (`ShopOrderImport.php`). Export them
on the WooCommerce site with `wp --skip-themes eval-file plugins/shop/tools/woo-export-orders.php > orders.json` (it
only reads; `all` as an argument adds failed and draft orders), or use a REST API order list. Each order keeps its
number as the reference (`WC-1234`), its dates, addresses, lines, shipping, coupon, tax and totals; completed becomes
fulfilled, processing paid, on-hold and pending awaiting payment, cancelled and refunded stay. Order notes, the
payment method and transaction id, fees and refunds go into the owner's notes. Lines link to the imported product and
variant by `woo:<id>`, then SKU; others keep their name and price. Each email becomes one guest customer (core
`customers`, no password, mirrored into Contacts), which becomes a full account if that person signs up with the same
email; an existing customer is linked, never changed. No email is sent and stock does not move. Failed orders (declined
cards, card testing) are left out unless asked for. A second run skips orders already imported. The file holds
personal details: the screen keeps it in `data/shop-import` (never served, mode 600) and deletes it after the import
or a day.

## Order states

`pending_payment` → `paid` → `fulfilled`; `pending_payment` → `cancelled` (also automatic after a day unpaid);
`paid`/`fulfilled` → `refunded`. The webhook (`stripe_webhook_event`) and the return page both try to move an order
to `paid` through one conditional UPDATE: exactly one wins, records the charge, takes the stock and sends the emails.
Stock is checked when the order is placed and taken when it is paid; a shortfall at that moment is noted on the order.

## Studio widgets (Add › Components)

| Widget | Type | Where |
|---|---|---|
| Product Grid | `shop.products` | any page; category (fixed or a visitor filter), columns, order, per page, pages |
| Product Images, Title, Price, Add to Cart, Description, Stock, Meta | `shop.product_*` | the product Single template |
| Mini Cart | `shop.mini_cart` | the header |
| Cart | `shop.cart` | the Cart page |
| Checkout | `shop.checkout` | the Checkout page |
| Order Confirmation | `shop.order_confirmation` | the Order received page |

All need the `shop` entitlement: without it they are locked in Add › Components and render nothing. The catalogue
widgets render on the server and stay cacheable. The cart widgets render a placeholder that studio-runtime.js fills
from `public/cart.php`, `checkout.php` and `order.php` (see "fragments" in `docs/STUDIO-WIDGET-SDK.md`); without
JavaScript they link to the same endpoints' plain pages.

**Shop settings › Create the shop pages** makes and publishes the Cart, Checkout and Order received pages and the
product template, laid out with these widgets (pages that exist are left alone).

## Shipping, tax and coupons (0.4.0)

- **Zones** (Shop › Shipping, `ShopShipping`): where an address is (countries or `*`, optional states and postcodes
  such as `934*` or `93401-93499`); the first zone by sort wins. Each zone's **methods** are what checkout offers:
  flat (plus per extra item), free, by weight (bands in the shop's unit, products plus the empty box), **local pickup**
  (only there; the order becomes a pickup order) and **carrier rates** from EasyPost (carriers and services filtered,
  a percentage and a handling fee added; rates in another currency are dropped). Any method can need a minimum
  subtotal. Carrier rates for one address and parcel are kept in the session for 30 minutes, so the price paid is the
  price shown. The EasyPost key is sealed with the app secret; a `SHOP_EASYPOST_API` constant in config.php points a
  test site at a stand-in. Checkout quotes again when the state, postcode, country or method changes
  (`data-sb-requote`), and through an Update shipping button without JavaScript.
- **Tax** (Shop › Tax, `ShopTax`): prices **include** tax (VAT, the default), tax is **added at checkout** by the
  delivery address (US sales tax; the shop's own address for pickup and downloads), or **none**. Rates by country,
  state and postcode, the most specific winning, per tax class (standard, reduced, zero; set on each product, and
  mapped from WooCommerce's tax status and class by the importer), shipping taxed when the rate says so. Tax is worked
  out per line on what the line costs after a coupon. The cart's wording follows the mode; with tax added but no rate
  set, it promises none.
- **Coupons** (Shop › Coupons, `ShopCoupons`): percentage, amount, or free shipping; a minimum, dates, uses in all
  (counted when an order is paid) and per customer email, the products or categories it applies to, and sale items
  left out. The cart keeps one code in the session and checks it again on every total; one that stops fitting drops
  off with a note. The code box shows only while the shop has an active coupon. A discounted order goes to the
  gateway as one line for the whole order (gateways take no negative lines).
- An order keeps its state or province, discount and code, tax (and whether it was included), shipping method,
  carrier and service.

## Orders (0.4.0)

The orders screen searches by reference, email, name or tracking and exports CSV (spreadsheet-safe). An order:
fulfil with tracking (carrier, number, link; the carrier's own page for USPS, UPS, FedEx, DHL, Canada Post and bpost)
and email the customer that it is on its way or ready to collect; buy a label through EasyPost for the rate the
customer chose; print a packing slip; keep notes only the shop sees; send the receipt again.

## Storefront (0.4.0)

- The Product Grid can show a **search box** and a **sort** choice (`?q=`, `?sort=`); search matches every word in
  the title, SKU, short description or tags.
- Picking a variant shows **its photo** in the product's gallery (`data-sb-shop-gallery`, the fifth entry of a
  `data-sb-variants` row), and the product photo again when the choice is reset.

## Checkout protection (0.4.0)

Card numbers are typed on Stripe's or PayPal's page, so card testers abuse the checkout itself: a script placing order
after order to get payment pages. `ShopGuard` stops that before an order is made, once the form is valid
(`placeOrder($in, $customer, $guard)`): a hidden trap field and a signed time stamp (a form sent back within 3 seconds,
without the stamp or with the trap filled is refused), optional Cloudflare Turnstile (both keys on Shop › Settings; the
secret sealed; studio-runtime.js renders the widget, `[data-sb-turnstile]`), orders per visitor per 10 minutes and per
day (the client address as PayThrottle reads it, counted in files under data/shop-guard), unpaid orders per email per
hour, and a pause of online checkout for an hour when unpaid orders in the last hour reach the limit (the notification
address is emailed, once an hour; Shop › Settings can start it again). Discount codes have their own per-visitor limit.
Every limit is a setting (0 = off). Behind Cloudflare or another proxy, set STUDIO_TRUSTED_PROXIES to its ranges, or every
visitor counts as the proxy's address.

## Search engines (0.4.0)

Each product has its own search title and description (Products › edit › Search engines; empty: its name with the
site name, and its short description cut at a whole word). Its page tells search engines what it sells: a schema.org
Product with its photos, SKU, category, weight and offer (price, currency, stock; the price range of its variants as an
AggregateOffer), and the breadcrumb Home › Shop › product. Studio writes it into the page head with the business set on
Site & SEO (`ShopProductSingle::schema`, `Render\Seo\StructuredData`). Products are in the sitemap.

## Endpoints (`public/`)

| File | What |
|---|---|
| `cart.php` | `?view=mini|cart` fragments; POST `add` (from cached pages: same-site check, no token), `update`/`remove` (CSRF) |
| `cart.php` (0.4.0) | POST `coupon` / `coupon_remove` (CSRF): the cart's discount code |
| `checkout.php` | the checkout fragment; POST `action`: `pay` (default) places the order and answers the gateway's address; `quote` the shipping and tax for the address typed; `coupon`, `coupon_remove` |
| `return.php` | Stripe's success address: checks the session with Stripe, marks paid, empties the cart |
| `order.php` | the confirmation fragment for `?order=<ref>&key=<key>` |

## Tables

`shop_products`, `shop_variants`, `shop_categories`, `shop_product_categories`, `shop_tags`, `shop_product_tags`,
`shop_product_links`, `shop_product_files`, `shop_downloads`, `shop_orders`, `shop_order_items`,
`shop_shipping_zones`, `shop_shipping_methods`, `shop_tax_rates`, `shop_coupons` (all with `tenant_id`; `install.sql`,
kept current by `ShopStoreAPI::ensureSchema()`).

## Not in this version

Customer order history in the portal, partial refunds from the order screen, packing several boxes per order
(one box size is used), automatic tax rate lookup (rates are entered by the shop), and the Stripe Payment Element
(embedded) flow.
