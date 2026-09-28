# Membership

The member layer on top of Slate's core `customers` — plans, lifecycle, member
profile, wallet, and the cross-plugin gates. It **reuses core identity** (no
second login system) and **the shared Stripe ledger** (no second payments
system), so it composes cleanly with the Booking and Stripe Payment plugins.

## Design principles

- **Identity is core `customers`.** Registration, login, email verification,
  password reset and customer sessions all live in core `Auth`. Membership adds
  a profile (`membership_profiles`), plans, subscriptions and a wallet — all
  keyed by `customer_id`. It never duplicates auth.
- **Payments are the shared ledger.** Plan purchases go through
  `StripePaymentAPI::createCheckout()` and land in `stripepayment_charges`;
  `membership_subscriptions.charge_id` points at the charge row. Refunds are a
  one-liner via the Stripe plugin.
- **Billing is fixed-term + manual renew.** A member buys a term (e.g. 365
  days) as a one-time charge; it expires and they re-purchase, prompted by
  reminder emails. (Stripe is used in one-time `payment` mode — no native
  subscriptions required.)

## Integration surfaces

| With | How |
|------|-----|
| **Core Auth** | listens `customer_registered` → provisions `membership_profiles` + `membership_wallet`; injects a status card via `customer_dashboard_widgets` |
| **Stripe Payment** | `createCheckout()` to sell a plan; listens `stripe_webhook_event` to activate the subscription + `recordCharge()` *(Phase 2)* |
| **Booking** | answers the active-membership / insurance / profile gate via `MembershipAPI::isActive()` etc. *(Phase 4)* |
| **i18n** | registers French (`fr`) and a lang pack via `i18n_supported_languages` + `i18n_lang_paths` |

## Public API — `MembershipAPI`

Other plugins should only call this facade, never the tables directly.

```php
MembershipAPI::isActive($customerId);        // active base membership? (Booking gate)
MembershipAPI::hasInsurance($customerId);    // active insurance plan?
MembershipAPI::status($customerId);          // ['state','sub','days_left','has_insurance']
MembershipAPI::activeSubscription($customerId);
MembershipAPI::plans($activeOnly = false);
MembershipAPI::ensureProfile($customerId);
MembershipAPI::ensureWallet($customerId);
MembershipAPI::walletAdjust($customerId, $cents, $type, $desc, $ref);
MembershipAPI::money($cents, $currency);
```

## Tables

- `membership_plans` — sellable plans (bilingual, type, price, duration, grace)
- `membership_subscriptions` — a member's purchased term + lifecycle
- `membership_profiles` — 1:1 member profile + QR card token + locale
- `membership_wallet` / `membership_wallet_txns` — balance + ledger

Schema self-heals on boot via `MembershipAPI::ensureSchema()` (idempotent
`CREATE TABLE IF NOT EXISTS`), so existing installs upgrade without a deploy
step. `uninstall.sql` drops only `membership_*` tables; core `customers` are
never touched.

## Roadmap

1. **✅ Foundation** — scaffold, schema, plan CRUD, settings, FR/EN *(0.1.0)*
2. **✅ Purchase + lifecycle** — Stripe checkout, webhook activation, wallet ledger, admin manual/offline activation, member self-cancel, `/member` area *(0.2.0)*
3. **✅ Member profile + dashboard** — 4-step onboarding wizard, profile screens, QR member card, schedule view *(0.3.0)*
4. **✅ Booking integration** — `booking_can_book` gate (active membership / insurance / profile-complete) *(0.4.0)*
5. **Polish** — expiry-reminder cron (7/3-day, dedup-guarded), admin KPIs/reports, coach surfacing

### Phase 4 — the Booking gate (the one cross-plugin edit)

The Booking plugin's `BookingAPI::createAppointment()` now fires a
`booking_can_book` filter for **online** (self-service) bookings only — admin
and walk-in bookings bypass it. The filter is a no-op when nothing listens, so
Booking is unchanged on installs without Membership.

Membership answers it (`Membership::gateBooking()`), enforcing — each toggle in
Membership → Settings, default on:
- **active membership** required to book (`MembershipAPI::isActive`)
- **completed profile** required (`onboarding_complete`)
- **active insurance** for courses listed in *Insurance-required courses*
  (`MembershipAPI::hasInsurance`)

The QR member card needs one vendored file — see `assets/js/README.md`.

### Phase 2 surfaces

- **`/member`** (requires customer login): `?view=home` status + self-cancel, `?view=plans` buy, `?view=return` Stripe verify/activate, `?view=wallet` ledger.
- **Admin → Members**: list (`members.php`) + single member (`member.php`) with manual/offline activation, cancel, and wallet adjustment.
- **Stripe**: `MembershipAPI::purchase()` opens checkout with `source_plugin=membership` + `membership_sub_id` metadata; `handleStripeEvent()` (on `stripe_webhook_event`) records the charge and activates. The return page also self-heals activation if the webhook lags.

## Permissions

- `membership.view` — view members + memberships
- `membership.manage_plans` — plan CRUD
- `membership.manage_members` — activate / suspend / cancel / wallet
- `membership.manage_settings` — configure settings
