# Shop

Sell physical products from a Kohevo Studio site: products, a session cart, guest checkout, Stripe payment and an
orders screen. The storefront is built in Studio with the Shop widgets; this plugin owns the data and the
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
- **Prices include VAT.** One price per product, in its own currency (changing the shop currency never re-prices a
  product). Shipping is one flat rate with an optional free-shipping threshold, or free pickup.
- **Guests check out.** Name and email are enough; a signed-in customer's order is linked to their account. A guest
  sees the order through a link with an unguessable key.

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

## Endpoints (`public/`)

| File | What |
|---|---|
| `cart.php` | `?view=mini|cart` fragments; POST `add` (from cached pages: same-site check, no token), `update`/`remove` (CSRF) |
| `checkout.php` | the checkout fragment; POST places the order and answers the Stripe Checkout address |
| `return.php` | Stripe's success address: checks the session with Stripe, marks paid, empties the cart |
| `order.php` | the confirmation fragment for `?order=<ref>&key=<key>` |

## Tables

`shop_products`, `shop_categories`, `shop_product_categories`, `shop_orders`, `shop_order_items` (all with
`tenant_id`; `install.sql`, kept current by `ShopStoreAPI::ensureSchema()`).

## Not in this version

Digital products, variants (size/colour), coupons, tax rates other than VAT-inclusive prices, shipping zones or
weights, customer order history in the portal, partial refunds from the order screen, and the Stripe Payment Element
(embedded) flow.
