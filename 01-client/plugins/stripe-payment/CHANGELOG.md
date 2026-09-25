# Stripe Payment Gateway changelog

## 1.1.2 — smart paste UX with live validation + auto-verify on save

Adds protection against the #1 setup mistake: pasting publishable
and secret keys from different Stripe accounts or sandboxes. The
1.1.1 release added the right diagnostics in the browser console
("Account mismatch ..."), but the keys still got saved and Stripe.js
failed silently for end users. 1.1.2 catches mismatches BEFORE saving.

### What's new on the admin settings page

- **"Open Stripe API Keys ↗" button** — one click opens your Stripe
  dashboard in a new tab. The URL is account-aware when keys are
  already saved.

- **Quick-paste field** — dump your Publishable + Secret keys into
  one textarea (e.g. copy the whole "API keys" section from Stripe),
  JS extracts each by prefix and routes them into the right fields
  automatically.

- **Live account markers** — each key field shows a small badge
  with its account marker and mode (e.g. `51TCoPYBqTcGep · test`).
  Markers turn green when both match, red when they don't.

- **Validation banner** — appears live as you paste/type:
  - 🟢 "Keys match: account 51TCoPYBqTcGep, test mode."
  - 🔴 "Account mismatch — your publishable key belongs to account
    51AAAA but your secret key belongs to account 51BBBB."
  - 🔴 "Mode mismatch — publishable is test mode, secret is live mode."

- **Save button disabled while validation is red.** Can't accidentally
  save mismatched keys.

- **Server-side auto-verify on save**. When you click "Save and
  verify", the server immediately hits Stripe's `/v1/balance` with
  the new secret. If it fails, the save is rejected and Stripe's
  actual error is shown inline. Your existing valid keys stay in
  place — no half-saved bad state.

- **Connection status card** at the top of the page. After a
  successful verification, shows:
  ```
  ✓ Connected
  Account: 51TCoPYBqTcGep · TEST mode
  last verified 2026-05-25 06:30:00
  ```
  Plus "Re-test" and "Disconnect" buttons.

- **Show/hide secret key** toggle next to the secret field. Default
  is hidden (password type); customer-support sessions can reveal
  to verify without re-pasting.

- **Provider visibility toggles** moved into the main form: show
  embedded form / show hosted redirect / both. Both default to on.
  Lets you disable one flow without uninstalling the plugin.

### Save-rejection cases (server-side)

The save will be rejected with a specific message in any of these
cases (and the old keys stay intact):

| Case | Error message |
|------|----------|
| Publishable doesn't start with `pk_…` | "Publishable key must start with pk_test_ or pk_live_." |
| Secret doesn't start with `sk_…` | "Secret key must start with sk_test_ or sk_live_." |
| pk_test_ paired with sk_live_ | "Mode mismatch: publishable is test mode, secret is live mode." |
| Different account markers | "Account mismatch: publishable belongs to X, secret belongs to Y." |
| Stripe rejects the secret | "Stripe rejected the key: <Stripe's own message>" |
| Network can't reach Stripe | "Could not reach Stripe: <transport error>" |

### Why not OAuth / Stripe Connect

Stripe Connect (the "Connect with Stripe" OAuth button you see on
Shopify etc.) is for platforms connecting OTHER people's Stripe
accounts. For a single-merchant install (this one), it adds 0.25% +
0.25¢ per transaction in extra fees, requires a Stripe platform
review, and gives the merchant nothing in return — they'd still
manage their account from the same dashboard. The smart-paste
approach solves the actual problem (account mismatch) without the
overhead. If Slate ever becomes a multi-merchant SaaS, Connect
becomes the right answer; until then, this is.

### No schema changes

Three new settings keys are written by successful saves:
`stripe-payment.verified_at`, `verified_account`, `verified_mode`.
These are cache; if they're missing, the status card shows "Not
connected" and a save will re-populate them. No migration step.

---

## 1.1.1 — embedded form: state-machine guards, ready-event gating, loaderror

Fixes the production error
`Invalid value for stripe.confirmPayment(): elements should have a
mounted Payment Element or Express Checkout Element` reported by users
hitting "Place order" before the Stripe Payment Element fully mounted.

### Root cause

The 1.1.0 JS used a single `initialised` boolean as both "init has
started" and "init has finished". It was set to `true` immediately on
entering `initElement()`, before `stripe.elements()` returned or the
async `paymentElement.mount(mount)` actually rendered an iframe. The
form's submit handler treated `initialised === true` as "safe to call
confirmPayment", but `stripe`/`elements` were still null OR the
iframe hadn't loaded yet → Stripe.js threw "elements should have a
mounted Payment Element".

A related bug: the form's `loaderror` event from Stripe.js (fired when
the iframe fails to load — bad keys, CSP, network) was never listened
to. If create-intent succeeded but Stripe.js couldn't render the
iframe, the form area looked empty with no error shown, and clicks on
Place Order produced the cryptic message.

### Fix

1. **Explicit state machine** with five states: idle → initializing →
   mounting → ready → submitting. The submit handler refuses to call
   confirmPayment unless state === 'ready'. Each state transition is
   logged to console for diagnostics.

2. **Three Stripe element event handlers wired BEFORE mount:**
   - `ready` — flips state to 'ready', enables submit
   - `change` — clears any earlier validation error when the form
     becomes complete
   - `loaderror` — surfaces the actual Stripe error and re-enables
     submit so the customer can pick another method

3. **Visible loading state.** A spinner + "Loading secure payment
   form…" text is shown while init is in progress. The mount div is
   hidden until `ready` fires. Customers see something happening
   rather than an empty area + grey submit button.

4. **Submit button disabled until ready.** Pre-1.1.1 the Place Order
   button was always clickable, even mid-init. Now it shows "Loading
   payment form…" while initializing and only becomes clickable when
   the element is ready or when the customer switches to a different
   payment method.

5. **Better error messages.** Network errors include the underlying
   exception message. HTTP errors include the status code. Stripe
   loaderror surfaces Stripe's own error string (typically: API key
   mismatch, CSP blocking js.stripe.com, or wallet config issue).

### Console diagnostics

Open browser devtools → Console tab. The flow logs:

```
[slate-stripe] initElement: start
[slate-stripe] initElement: got client_secret, mounting
[slate-stripe] mount called
[slate-stripe] paymentElement ready
```

If `paymentElement ready` never appears but `mount called` did, the
issue is on Stripe's side (bad keys, CSP, network). The `loaderror`
event handler will report the specific reason.

### Most likely fix on the live host

If the form was showing up empty before 1.1.1, the most likely cause
is a publishable_key / secret_key mode mismatch (one is `_test_`, the
other is `_live_`). 1.1.1 surfaces this via the loaderror handler
with Stripe's own message: "The client_secret provided does not
match a valid PaymentIntent on this account."

Re-paste your keys at Shop → Stripe in admin, making sure both are
from the same Stripe environment (test or live).

### No schema changes

Pure JS / PHP. Migration step does nothing; applied_version bumps
from 1.1.0 to 1.1.1 on first boot.

---

## 1.1.0 — embedded Payment Element flow

Adds Stripe's Payment Element as a SECOND payment provider alongside
the existing Stripe Checkout (hosted, redirect) flow. Customers can
now pick which they prefer at checkout:

- **"Pay with card / wallet"** (new) — Card, Apple Pay, Google Pay,
  and Link rendered inline on the Slate checkout page. Customer
  never leaves your site. Powered by Stripe Payment Element + Stripe.js.
- **"Credit or debit card"** (existing) — Hosted redirect to
  stripe.com. Preserved for accounts that prefer maximum PCI scope
  reduction or want Stripe's hosted page features.
- **"Manual / Pay later"** — Default Slate behaviour, no card capture.

Both Stripe flows share the same admin settings (API keys, webhook
secret, allowed countries), the same `stripepayment_sessions` mapping
table, and the same webhook endpoint. Admins can disable either flow
via the `show_hosted` / `show_embedded` settings (both default on).

### How the embedded flow works

1. Page load on /shop/checkout: if `stripe_embedded` is the default
   provider (or once the customer picks it), Stripe.js loads,
   PaymentIntent is created server-side via `/public/create-intent.php`,
   and the Payment Element widget mounts inline.
2. Customer fills card / picks wallet on the page, hits "Place order".
3. JS calls `stripe.confirmPayment({redirect: 'if_required'})` — 3DS
   handled in a modal popup when possible, redirect to the bank only
   when the bank demands it.
4. On client-side success, the form re-submits with hidden
   `stripe_payment_intent_id`. Server re-verifies status with Stripe
   (don't trust the client), creates the order, redirects to
   confirmation.
5. The webhook (`payment_intent.succeeded`) fires server-to-server
   as a separate path; whichever path (browser confirm or webhook)
   gets to the mapping row first creates the order. Idempotent.

### New endpoints

- `public/create-intent.php` — POST-only, returns a JSON
  `{client_secret, payment_intent_id}` for the embedded form to
  consume. Idempotent: reuses an existing pending PI for the cart
  if one exists, updating its amount rather than creating new
  intents on every page refresh.
- `public/return.php` — destination for the rare bank-redirect
  3DS path. Looks up the PI, verifies status, creates order or
  bounces back to checkout with an error.

### New webhook event

- `payment_intent.succeeded` — the source-of-truth event for the
  embedded flow. Subscribe to it alongside `checkout.session.completed`
  in your Stripe dashboard.

### Schema migration

Three changes to `stripepayment_sessions`:

- `stripe_session_id` relaxed from NOT NULL to NULL (embedded rows
  don't have one)
- New `payment_intent_id VARCHAR(255) NULL UNIQUE` column
- New `flow VARCHAR(16) NOT NULL DEFAULT 'hosted'` column

Migration runs automatically on next plugin boot after upgrade;
existing 1.0.1 rows keep `flow='hosted'` and `payment_intent_id=NULL`.

### Verified

- Embedded form renders inline on /shop/checkout
- Stripe.js loads from js.stripe.com (the only external resource needed)
- create-intent.php fails gracefully when Stripe is unreachable
  (502 JSON, no PHP 500)
- create-intent.php rejects GET (405), rejects empty carts (400)
- Webhook with valid signed `payment_intent.succeeded` payload creates
  the order, stamps the mapping row, returns HTTP 200
- Replay of the same webhook returns "already processed", no
  duplicate orders
- Hosted flow (`checkout.session.completed`) still works unchanged
- Manual flow still works unchanged
- All 8 shop admin pages + storefront pages still 200

### Limitations

I couldn't verify the JS bootstrap against a real Stripe.js load
because the sandbox can't reach js.stripe.com. The PHP server-side
paths are fully tested with mocked PI/Session data; the JS will need
a real Stripe test-mode key on the live host to fully validate. Test
with `4242 4242 4242 4242`.

---

## 1.0.1 — table rename to satisfy plugin namespace validator

Slate's plugin installer enforces that every CREATE TABLE in install.sql
starts with the slug-derived prefix (slug with hyphens stripped + `_`).
For this plugin that's `stripepayment_`. v1.0.0 used `shop_stripe_sessions`,
which was both wrong (would be wiped if Shop ever uninstalled) and
rejected by the validator. Renamed to `stripepayment_sessions`.

---

## 1.0.0 — initial release (BLOCKED by validator, see 1.0.1)
