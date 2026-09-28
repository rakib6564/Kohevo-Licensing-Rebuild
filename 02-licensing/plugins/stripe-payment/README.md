# Stripe Payment Gateway for Slate Shop

Adds Stripe Checkout (hosted, redirect-based) as a payment option at
the Shop plugin's storefront checkout. Customers pay with a card on
Stripe's hosted page; orders are created in Slate automatically once
payment confirms.

## Requirements

- Shop plugin v1.2.5 or later (provides the `shop_payment_providers`
  filter that this plugin hooks into)
- PHP cURL extension
- HTTPS outbound to api.stripe.com from your web host
- A Stripe account (test keys for development; live keys for
  production)

## Install

1. Drop the plugin folder into `slate/plugins/stripe-payment/`.
2. In the admin → Plugins, activate "Stripe Payment Gateway".
3. Go to Shop → Stripe in the sidebar.
4. Paste your Publishable and Secret keys from the Stripe dashboard
   (Developers → API keys).
5. Click "Save settings", then "Run test" to confirm the secret key
   works against Stripe's API.

## Webhook setup

1. In the Stripe dashboard → Developers → Webhooks, add a new
   endpoint at:
   ```
   https://YOUR-SITE/plugins/stripe-payment/public/webhook.php
   ```
2. Subscribe it to the event `checkout.session.completed`.
3. Copy the signing secret (`whsec_…`) Stripe gives you back into
   the "Webhook signing secret" field on the Slate settings page.
4. Save.

The webhook is what guarantees orders are recorded reliably even if
a customer closes their browser before being redirected back. Both
the webhook path and the browser-redirect path run idempotent order
creation — whichever fires first wins, the other one no-ops.

## Test cards

In test mode:

- `4242 4242 4242 4242` — succeeds
- `4000 0000 0000 9995` — insufficient funds
- `4000 0027 6000 3184` — 3D Secure required

Any future expiry date, any 3-digit CVC, any postal code.

## How it works

When a customer picks "Credit or debit card" at checkout:

1. Shop's checkout.php dispatches to this plugin's
   `handleCheckout()`.
2. We call `StripeAPI::createCheckoutSession()` to create a Stripe
   Checkout Session over the cart line items. The cart's currency,
   shipping, and tax all become line items so the totals match.
3. We save a row in `stripepayment_sessions` linking the new Stripe
   session id back to the customer's cart sid, plus a stashed copy
   of their billing form so we can rebuild the order later.
4. The browser is redirected to Stripe's hosted Checkout page.
5. Customer pays. Stripe redirects them back to `success.php`
   (browser path) AND sends `checkout.session.completed` to
   `webhook.php` (server path).
6. Whichever path arrives first:
   - looks up the mapping row by Stripe session id
   - if `order_id` is already set, it's a duplicate — exit
   - otherwise verify payment status, create the order via
     `ShopAPI::checkoutCart()`, stamp `order_id` onto the mapping
     row, mark status='processing'
7. Browser path redirects to the order confirmation page.

## Security

- All Stripe webhook requests are signature-verified with HMAC-SHA256
  against your configured signing secret. Unsigned and stale (>5min)
  requests are rejected with HTTP 400.
- Card data never touches Slate. Customers enter it on stripe.com
  under Stripe's PCI compliance.
- The secret key is stored in the core `settings` table and rendered
  in a password field on the admin page.

## Uninstall

Deactivating the plugin in admin → Plugins removes the menu item and
unhooks the payment provider. To fully remove:

1. Deactivate via admin.
2. Drop the plugin folder.
3. The `stripepayment_sessions` table will be removed automatically by
   Slate's uninstall mechanism (uses `uninstall.sql`).

Existing orders that were paid via Stripe stay intact; they just
won't be cross-referenceable to their Stripe sessions anymore.
