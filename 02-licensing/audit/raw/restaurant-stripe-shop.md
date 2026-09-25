# Restaurant / Stripe-Payment / Shop audit (read-only)

Scope: `plugins/restaurant/`, `plugins/stripe-payment/`, `plugins/shop/`, plus `shop-emails/`, `shipping-flat-rate/`, `flat-rate-shipping/` (22,174 lines PHP).
Tree audited: `claude/slate-platform-audit-c95b8f` @ c3a90ba.

---

## Inventory

### Tables — all carry `tenant_id`

`restaurant_menu_categories`, `restaurant_items`, `restaurant_modifier_groups`, `restaurant_modifiers`, `restaurant_item_modifier_groups`, `restaurant_sections`, `restaurant_tables`, **`restaurant_customers`** (name/email/phone), `restaurant_orders`, `restaurant_order_items`, `restaurant_order_item_modifiers`, `restaurant_payments`, `restaurant_readers`.

`shop_products`, **`shop_customers`**, `shop_orders` (email + address), `shop_order_items`, `shop_coupons`, `shop_categories`, `shop_product_variants`, `shop_carts`, `shop_cart_items`.

`stripepayment_sessions`, `stripepayment_charges` (email).

`restaurant_customers` and `shop_customers` are two more independent person models, bringing the platform total to six (core `customers`, the `contacts` spine, `booking_customers`, `forms_contacts`, plus these two).

### Public / storefront routes — all unauthenticated by design

Restaurant: `storefront/{router,index,item,cart,checkout,confirm,api}.php`. Shop: `storefront/{router,index,category,product,cart,checkout,order,embed}.php`. Stripe: `public/{webhook,create-intent,return,success}.php`.

### Admin gating

All restaurant, stripe-payment and shop admin screens call `Auth::require()` + `Auth::requirePerm(...)`. No unprotected admin screen found.

### Money representation

- `shop_orders.currency VARCHAR(3)` — the order **snapshots** its currency (`plugins/shop/install.sql:76`). Good.
- `shop_products` has **no currency column**. Catalogue prices are bare integers; the code reads a single global `Database::setting('shop.currency') ?: 'USD'` at eleven sites (`ShopAPI.php:111`, `:499`, `Shop.php:142`, `:220`, `admin/products.php:24`, `admin/index.php:18`, `admin/reports.php:17`, `admin/customers.php:71`, and others).
- `restaurant` has no currency column anywhere; same global-setting pattern.
- `booking_services.currency` exists per service — so three plugins model currency three different ways.

### Messaging

`shop-emails/ShopEmails.php` owns order emails; `restaurant` composes its own confirmations; `stripe-payment` composes none (it fires `stripe_webhook_event`).

---

## Findings

### [medium] Changing the shop currency setting silently re-denominates the entire catalogue without changing a single price

- evidence: products store a bare price with no currency; every display site reads one global setting — e.g. `plugins/shop/ShopAPI.php:111` and `:499`, `plugins/shop/Shop.php:142`, `plugins/shop/admin/products.php:24`:
  ```php
  $currency = Database::setting('shop.currency') ?: 'USD';
  ```
  Only the order snapshots it — `plugins/shop/install.sql:76` `` `currency` VARCHAR(3) NOT NULL DEFAULT 'USD' ``.
- failure case: a merchant selling at USD 40 switches `shop.currency` to `EUR` in settings. No price row changes. Every product page, cart, checkout and admin report immediately reads "EUR 40", and `create-intent.php` charges 40 EUR — roughly a 9% unintended price rise, or a fall, depending on direction. Nothing warns, and nothing in the catalogue records that the number was ever denominated in dollars. Historical orders are unaffected because they snapshot correctly, which makes the break harder to spot: old orders read USD, new ones EUR, with identical underlying numbers.
- blast radius: any shop that changes currency; the same shape applies to restaurant, which has no currency column at all. Booking is the counter-example that shows the codebase already knows better — `booking_services.currency` travels with the service.
- fix: the price and its currency should travel together, as booking already does — a currency column on the product (or an explicit store-level currency that the settings screen refuses to change while priced products exist, offering a conversion pass instead). The cheap interim guard is to make the settings screen state plainly that changing the currency re-denominates rather than converts, and to require confirmation; the correct fix is to stop treating currency as a display preference detached from the amount.
- **verified**

### [medium] The storefront uses a second, weaker CSRF implementation than the rest of the platform, and its token is derivable when `APP_SECRET` is unset

- evidence: core provides session-based `csrf_token()` / `csrf_verify()` (`includes/helpers.php:54-76`), which restaurant's storefront uses correctly — `plugins/restaurant/storefront/checkout.php:29`, `cart.php:7`, `item.php:18`, `api.php:40`. Shop's storefront instead derives its own — `plugins/shop/storefront/includes/layout.php:127`:
  ```php
  return hash_hmac('sha256', 'shop-csrf:' . $sid, APP_SECRET);
  ```
  with `sf_csrf_verify()` at `:135-139` comparing against the same derivation, and a docblock at `:120` asserting it "Is unguessable without APP_SECRET". `APP_SECRET` defaults to the empty string (`config.php:49`) and there is **no guard here at all** — not even the `defined()` test used elsewhere.
- failure case: with `APP_SECRET` empty, `sf_csrf_token()` is `hash_hmac('sha256', 'shop-csrf:'.$sid, '')` — computable by anyone who knows the victim's `shop_sid`, and the attacker controls their own request so they can simply compute the token for any sid they can obtain or induce. Storefront CSRF protection is then absent rather than degraded, on every state-changing storefront POST (add to cart, update quantities, apply coupon, place order). Two implementations of one primitive, and the platform's own one is the sound one.
- blast radius: the shop storefront on any deployment with `APP_SECRET` unset or left at the `.env.example` placeholder. This is the third and most serious of the `APP_SECRET` sites — see the content/forms/RSB report for the other two (`forms/public/router.php:700`, `FormsSpamGuard.php:497`).
- fix: the stated reason for the bespoke scheme — the storefront serves guests without a PHP session — is legitimate, so the answer is not simply to switch to `csrf_verify()`. It is to make the derivation fail closed: refuse to issue or accept a storefront token when the signing key is absent, the way `slate_encrypt_secret()` already throws (`includes/helpers.php:218-219`). Better still, have the storefront and the rest of the platform share one keyed-token helper so there is one implementation to get right, and one place where an unconfigured secret is detected.
- **verified** as a code defect; **exploitability conditional** on the deployed `APP_SECRET`, which I could not read (`.env` is gitignored and absent from this worktree).

---

## What I checked and found clean

This cluster is the strongest in the codebase, and several hypotheses the brief raised are refuted outright:

- **Stripe webhook signature verification is textbook correct.** `plugins/stripe-payment/StripePaymentAPI.php:266-294`: the raw body is read before any parsing (`public/webhook.php:33`, with a comment explaining why), the HMAC is over `t . '.' . $payload`, comparison is `hash_equals`, the tolerance window is checked in **both** directions (`:287`, with a comment noting that guarding only against old timestamps left forged future ones accepted), an empty secret refuses outright (`:272`, `dispatchWebhook:306`), and every outcome is recorded for operator visibility (`recordWebhookHealth:335`) — added, per its own docblock, after an empty signing secret silently swallowed a tuition payment. `StripeAPI::verifyWebhookSignature()` (`StripeAPI.php:137-141`) delegates rather than duplicating.
- **No order-total tampering.** `plugins/stripe-payment/public/create-intent.php` is POST-only (`:41`, to keep the client secret out of the Referer), identifies the cart from the `shop_sid` **cookie** and explicitly refuses a sid from the request body (`:52-54`, with the reasoning in the comment), and derives the amount server-side from `ShopAPI::cartTotals($sid)` (`:56-63`). Restaurant computes totals server-side via `sf_cart_subtotal()`.
- **Idempotency is handled on every path.** `recordCharge()` de-dupes on both the PaymentIntent id and the Checkout Session id before inserting (`StripePaymentAPI.php:468-481`), and the three completion paths (hosted `success.php`, embedded handler, webhook) all converge on the same `stripepayment_sessions` mapping row, whichever runs first stamping `order_id` (`public/webhook.php:17-20`).
- **The two shipping plugins are not a duplicate — they are deliberate alternatives with explicit conflict handling.** `shipping-flat-rate` (box/bin-packing) and `flat-rate-shipping` (weight tiers) have distinct slugs, tables and permission keys. Both register `shop_shipping_rate` at priority 10, and rather than double-charging, the filter contract makes late registrants **abstain** when a rate is already claimed (`ShopAPI::calculateShipping:562-579` returns the first numeric claim; `ShippingFlatRate.php:111-130` documents and implements abstention). `ShippingFlatRate::conflictWidget()` (`:86-105`) additionally raises a dashboard warning when both are active. My "duplicated plugin" hypothesis was wrong.
- **Restaurant's storefront uses the platform's own CSRF helper** on all four state-changing entry points, correctly.
- **Outbound webhooks carry an SSRF guard** (`FormsAPI.php:2470`, cross-cluster but same author pattern).
- **No SQL string concatenation of user input** in any of the six plugins.
- **No committed secrets.** I grepped install.sql seeds, READMEs and CHANGELOGs across the cluster; no live or test API keys, webhook secrets or credentials are present.

### Stripe Terminal — checked, not a finding

The brief flagged Terminal as a suspected stub. `StripeTerminalAPI.php` (280 lines) is a real implementation over the Stripe Terminal REST API, backed by the `restaurant_readers` table, and the restaurant POS screen (`admin/pos.php`) drives it. It is not the Zoom-style "selectable but inert" case found in booking-plus. I did not exercise it against live hardware, so I make no claim about whether it works end to end — only that it is not a stub presented as working.

---

## Notes for the reconciler

1. **The `APP_SECRET` finding is now confirmed at all three sites and should be one platform-level finding**, not three plugin findings. The shop storefront CSRF site is the most serious because it is an authentication control rather than a download token, and it is the only one of the three with no guard whatsoever.
2. **CSRF is implemented twice platform-wide** — core session-based, and shop's storefront HMAC. Restaurant demonstrates the storefront can use the core helper. This is a genuine "two implementations of one UI primitive" instance for the duplication section.
3. **Currency is modelled three ways across four plugins** — per-service (booking), per-order-only (shop), and not at all (restaurant). Combined with three separate money *formatters* (booking's per-file closures, `MembershipAPI::money():142`, shop's own), this is the clearest cross-cutting duplication finding after the person tables.
4. **Person tables now total six.** Add `restaurant_customers` and `shop_customers` to the census.
5. **No tenant-scoping defects found in this cluster.** Every query I read filtered `tenant_id`. Note the webhook path resolves tenant via `current_tenant_id()` → `TENANT_ID` with no session — I initially flagged this as cross-tenant misattribution and then **withdrew it**, because there is no host-based tenant resolution anywhere in the platform (see the tenancy note in `findings.md`): one deployment serves exactly one tenant, so a webhook arriving at a deployment's URL is correctly attributed by construction.
