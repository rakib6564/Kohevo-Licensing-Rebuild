# Stripe Payment Gateway

Stripe for Kohevo's shared payment layer. It registers a `StripeGateway`
with `Payments` (Payments → Gateways), so Shop, Booking, Membership,
Restaurant online orders and the Studio Pay button can take card payments
through Stripe Checkout without talking to Stripe themselves. Stripe-only
features stay on `StripePaymentAPI`: Terminal card readers (Restaurant POS)
and the on-page Payment Element (Booking, Restaurant).

## Requirements

- PHP cURL extension
- HTTPS outbound to api.stripe.com from your web host
- A Stripe account (test keys for development; live keys for
  production)

## Install

1. Activate "Stripe Payment Gateway" in admin → Plugins.
2. Open Payments → Gateways → Stripe.
3. Paste your Publishable and Secret keys from the Stripe dashboard
   (Developers → API keys), save, then "Run test".

## Webhook setup

1. In the Stripe dashboard → Developers → Webhooks, add an endpoint at:
   ```
   https://YOUR-SITE/plugins/stripe-payment/public/webhook.php
   ```
2. Subscribe it to `checkout.session.completed`,
   `checkout.session.async_payment_succeeded`/`_failed`,
   `checkout.session.expired`, `payment_intent.succeeded`,
   `payment_intent.payment_failed`, `invoice.paid` and `charge.refunded`.
3. Copy the signing secret (`whsec_…`) into the settings page and save.

The webhook guarantees a payment is recorded even if the customer
closes their browser before returning. Each verified delivery is
normalised and passed to `Payments::emit()`, which records the ledger
row once and fires `payment_event` for the plugin that started the
checkout; `stripe_webhook_event` still fires with the raw event for
Stripe-specific listeners.

## Test cards

In test mode:

- `4242 4242 4242 4242` — succeeds
- `4000 0000 0000 9995` — insufficient funds
- `4000 0027 6000 3184` — 3D Secure required

Any future expiry date, any 3-digit CVC, any postal code.

## Security

- All Stripe webhook requests are signature-verified with HMAC-SHA256
  against your configured signing secret. Unsigned and stale (>5min)
  requests are rejected with HTTP 400.
- Card data never touches Slate. Customers enter it on stripe.com
  under Stripe's PCI compliance.
- The secret key is stored in the core `settings` table and rendered
  in a password field on the admin page.

## Uninstall

Deactivate the plugin in admin → Plugins, then remove its folder.
`uninstall.sql` drops its tables, including `stripepayment_sessions`
(the mapping table of the old Shop checkout, removed in 1.3.0, kept
until then for existing data). The shared charge ledger keeps every
payment.
