# Restaurant Suite Plugin — Architecture & Build Plan

A SpotOn-style restaurant management plugin for Slate. Slug: **`restaurant`**.
Built phased so each phase ships something usable on its own.

> **Status:** Plan only — no code written yet. Review and adjust scope before Phase 1.

### Locked decisions (2026-06-02)
1. **Single-location** for v1 (multi-location within a tenant is a later upgrade).
2. **Payments: Stripe Terminal (card-present readers) first.** Online/keyed payments
   are a later upgrade. → *Requires extending `stripe-payment`; see §2 and §4 Phase 1.*
3. **Labor: self-contained staff module** inside `restaurant` (no `timeclock` bridge).
4. **Customers: standalone `restaurant_customers`** table (no `clientdesk` tie-in).
5. SMS provider — still to confirm; not needed until Phase 4.

---

## 1. Scope: what this matches vs. what it can't

SpotOn is a **hardware + software + payment-processor** company. A PHP plugin can
replicate the *software* surface but not the hardware or the acquiring/lending business.

### In scope (buildable as a plugin)
- Menu management — items, categories, modifier groups, combos, dayparts, 86-ing
- POS order screen (browser register) — dine-in / takeout / delivery, checks, tips, discounts, split/merge
- Table & floor management — sections, tables, live table status
- Kitchen Display System (KDS) — auto-refreshing kitchen tickets, bump/recall, station routing
- Online ordering — public menu, cart, checkout (rides existing `stripe-payment`)
- Reservations + waitlist — extends `booking` patterns
- Gift cards & loyalty/rewards
- Marketing — email/SMS campaigns, review requests, receipt opt-in
- Labor — staff/roles, shift scheduling, tip pooling (extends `timeclock`)
- Reporting — sales, labor, item-mix, daypart, payment-method analytics

### Out of scope (be upfront)
- ❌ Physical POS terminals, card readers, receipt/kitchen printers, KDS hardware
- ❌ Being the merchant acquirer / payment processor (we ride on Stripe instead)
- ❌ SpotOn Capital (lending/financing)
- ❌ Certified offline-first native POS (browser POS needs connectivity; limited
     localStorage caching is possible, not full parity)
- ❌ Hardware-bound features: cash drawer kick, KDS bump bars, printer firmware

---

## 2. Architecture decisions

**One plugin, phased migrations.** Mirror the `booking` plugin: a full `install.sql`
for fresh installs + version-gated `migrations/*.sql` run from the bootstrap class.
Keeps the suite coherent (shared menu, customers, payments) rather than fragmenting
into 6 plugins that must stay in lockstep.

**Reuse, don't rebuild:**
| Need | Reuse |
|---|---|
| Card payments, refunds, webhooks | `stripe-payment` plugin (`stripe_webhook_event` hook) |
| **Card-present (Terminal) readers** | **`stripe-payment` — NEEDS EXTENSION (see below)** |
| Transactional email | core `Mailer` |
| SMS / WhatsApp reminders | the sender `booking` already uses (lift its integration) |
| Image uploads (menu photos) | core `Uploads` + `media-library` picker |
| Customer CRM | optionally link to `clientdesk` |

**Stripe Terminal extension (new work for Phase 1).** The current `stripe-payment`
plugin only does *online* PaymentIntents (Payment Element + hosted Checkout). Card-present
needs net-new pieces, added to `stripe-payment` so any plugin can reuse them:
- **Connection-token endpoint** — `POST` returning a Terminal connection token.
- **Terminal Location** — register one Stripe Terminal `Location` (Terminal requires it
  even for a single physical location; needs a street address).
- **Reader management** — register/list/forget internet readers (Stripe Reader S700 /
  BBPOS WisePOS E) in a small admin page.
- **Server-driven flow** (best fit for a web POS — no browser SDK, matches Slate's
  fetch-polling style): create PaymentIntent `payment_method_types:['card_present']`,
  `capture_method:'manual'` → `reader.process_payment_intent` → poll reader/PI status →
  capture on success. Tips can be collected on-reader or added pre-tender in the POS.
- Refunds/voids reuse the existing charges/refund path; `stripe_webhook_event` already
  carries `payment_intent.*` events.

> Simulated reader is available for dev, so this is buildable/testable without physical
> hardware — but real card-present transactions need an actual Stripe-supported reader.

**Follow existing conventions (verified against `booking`):**
- Every table `tenant_id INT UNSIGNED NOT NULL DEFAULT 1`, prefixed `restaurant_*`,
  with `UNIQUE KEY (tenant_id, slug)` and `KEY (tenant_id, ...)` index patterns.
- Bootstrap `Restaurant.php extends Plugin`, `boot()` registers:
  `admin_nav_items`, `admin_dashboard_widgets`, `public_routes`,
  `frequent_cron` (KDS/reminder polling), `stripe_webhook_event`.
- Admin record editors built on the shared `includes/record_editor.php` /
  `_editor_ui.php` kit (copy an existing editor like `providers.php` as the template).
- Permissions declared in `plugin.json` (union'd into the core Roles editor).
- Public pages served via `public/router.php` registered under a URL prefix.
- No build step: vanilla PHP/CSS/JS. KDS & POS use plain `fetch` polling.

**Permissions (`plugin.json`):**
```
restaurant.view              View orders, menu, floor
restaurant.pos               Operate the POS / take orders
restaurant.kds               View/operate the kitchen display
restaurant.manage_menu       Create/edit menu, modifiers, combos
restaurant.manage_floor      Manage sections, tables
restaurant.manage_orders     Void/comp/refund, reopen checks
restaurant.manage_reservations  Reservations + waitlist
restaurant.manage_labor      Schedules, tip pools
restaurant.manage_marketing  Campaigns, loyalty, gift cards
restaurant.manage_settings   Taxes, service charges, receipt, config
restaurant.reports           View reports
```

**Public URL prefixes:** `/menu` (online ordering), `/kds` (token-gated kitchen
screen for back-of-house tablets), `/r` (receipt / order status lookup).

---

## 3. Data model (by phase)

### Phase 1 — Menu + tables + POS core
```
restaurant_menus               -- optional multiple menus (lunch/dinner)
restaurant_categories          -- menu sections
restaurant_items               -- name, slug, price_cents, photo, tax_class, is_86, daypart
restaurant_modifier_groups     -- "Choose a side", min/max select, required
restaurant_modifiers           -- option name, price_delta_cents
restaurant_item_modifier_groups-- item ↔ group link (+ sort)
restaurant_sections            -- floor sections (Patio, Bar)
restaurant_tables              -- table no, section, seats, x/y for floor map, status
restaurant_orders              -- type(dine_in/takeout/delivery), table_id, status, totals, tips, server_id
restaurant_order_items         -- order line: item snapshot, qty, price, seat, notes, kitchen_status
restaurant_order_item_modifiers-- chosen modifiers snapshot per line
restaurant_payments            -- amount, method(cash/terminal), tip, stripe PI/charge ref, status
restaurant_checks              -- split-check grouping over order_items
restaurant_customers           -- standalone: name, phone, email, notes (POS lookup)
restaurant_readers             -- registered Stripe Terminal readers (id, label, stripe_reader_id, status)
```
> Terminal Location + connection-token config live in `stripe-payment` settings, not here.

### Phase 2 — KDS
```
restaurant_stations            -- Grill, Fry, Bar, Expo
restaurant_item_station        -- route item/category → station
restaurant_kds_tickets         -- ticket per order/station, fired_at, bumped_at, status, recall
```
(KDS reads `order_items` filtered by station; tickets track bump/recall lifecycle.)

### Phase 3 — Online ordering
```
restaurant_online_settings     -- hours, lead time, delivery zones/fees, min order
restaurant_delivery_zones      -- zip/radius → fee_cents
-- reuses orders/order_items with source='online', plus customer fields
```

### Phase 4 — Reservations / waitlist / gift / loyalty
```
restaurant_reservations        -- party_size, time, table_id, status, guest, source
restaurant_waitlist            -- party, quoted_wait, status, notify token
restaurant_giftcards           -- code, balance_cents, status   (pattern from booking_giftcards)
restaurant_giftcard_txns       -- ledger
restaurant_loyalty_accounts    -- customer_id, points, tier
restaurant_loyalty_txns        -- earn/redeem ledger
restaurant_loyalty_rules       -- earn rate, redemption value, signup bonus
```

### Phase 5 — Labor / marketing / reporting
```
restaurant_staff               -- self-contained: name, role, wage, clock-in pin (no timeclock dep)
restaurant_shifts              -- scheduled shift (date, station, start/end)
restaurant_tip_pools           -- pool config + distribution rule
restaurant_tip_distributions   -- computed per shift/day
restaurant_campaigns           -- email/SMS campaign, audience filter, schedule, status
restaurant_campaign_sends      -- per-recipient log
-- reporting: read-only aggregate queries, no new tables
```

---

## 4. Phase plan & deliverables

Each phase ends with a migration, admin nav entries, and a dashboard widget update.

### Phase 1 — Menu + Floor + POS register  *(foundation, largest)*
- `plugin.json`, `Restaurant.php`, `install.sql`, `RestaurantAPI.php`
- Admin: Menu items editor (w/ modifier groups), Categories, Floor/Tables editor, Settings (tax, service charge, currency, tip presets)
- **POS screen** (`admin/pos.php` + `assets/js/pos.js`): pick table or order type → add items → choose modifiers → running check → send to kitchen → tender → tips/discounts → split/merge check → close
- **Payments — card-present first:** extend `stripe-payment` with the Terminal pieces
  (connection token, one Location, reader management, server-driven process+capture).
  POS tender = **Stripe Terminal reader** or **cash**. *(Online/keyed card entry deferred.)*
- `restaurant_customers` standalone table (light: name, phone, email, notes) — used by
  POS lookup; loyalty/CRM hang off it in later phases.
- Dashboard widget: today's sales, open checks, covers

### Phase 2 — Kitchen Display System
- Stations admin + item→station routing
- `/kds` token-gated screen, columns per station, color-aged tickets, bump/recall
- Auto-fire tickets when POS "sends" an order; `fetch` polling (2–3s)

### Phase 3 — Online ordering
- Public `/menu` storefront (menu browse → cart → checkout → Stripe)
- Online settings: hours, lead time, delivery zones/fees, order throttling
- Online orders flow into the same POS/KDS queues with `source='online'`
- Order-status page `/r?token=…`, confirmation emails (core `Mailer`)

### Phase 4 — Reservations, waitlist, gift cards, loyalty
- Reservations admin + guest `/menu/reserve` flow + SMS/email confirmations
- Waitlist with SMS "table ready" notify
- Gift cards (sell + redeem at POS and online) — lift `booking_giftcards` pattern
- Loyalty: earn on spend, redeem at POS/online, signup bonus

### Phase 5 — Labor, marketing, reporting
- Staff & roles, shift scheduling, self-contained clock-in PIN, tip pooling (no `timeclock` dep)
- Marketing: customer segments, email/SMS campaigns via `frequent_cron`, review requests
- Reports: sales summary, item-mix/86 report, daypart, labor cost %, payment mix, tips — CSV export (match `shop` export style)

---

## 5. Cross-cutting concerns
- **Money:** integer cents everywhere, currency from settings (matches `booking`).
- **Tax/service charge:** per-item tax class + order-level service charge, configurable.
- **Concurrency:** open checks are row-locked on tender; KDS bump is idempotent.
- **Caching:** POS/KDS/checkout responses `Cache-Control: no-store` (as booking's router does).
- **Audit:** voids, comps, refunds, 86-toggles → core `AuditLog`.
- **i18n:** all labels through `__()` with `restaurant_*` keys.
- **Security:** every admin page gates on the right `restaurant.*` permission; `/kds` uses a rotating tenant token, not a login.

---

## 6. Effort estimate (rough)
| Phase | Relative size |
|---|---|
| 1 — Menu/Floor/POS | XL (≈ the whole `shop` plugin) |
| 2 — KDS | M |
| 3 — Online ordering | L |
| 4 — Reservations/gift/loyalty | L |
| 5 — Labor/marketing/reporting | L |

Total is larger than any single existing plugin. Recommend building and shipping
phase-by-phase, validating each in the running admin before the next.

---

## 7. Decisions & remaining items
Resolved (see Locked decisions at top): single-location, Terminal-first payments,
self-contained staff, standalone customers.

Still to confirm (not blocking Phase 1):
- **SMS provider** for Phase 4 reservation/waitlist/marketing texts — reuse whatever
  `booking` is wired to; confirm it's configured before Phase 4.
- **Physical reader model** you'll deploy (Stripe Reader S700 / BBPOS WisePOS E) — only
  matters for live testing; dev uses Stripe's simulated reader.
- **Restaurant street address** for the one Stripe Terminal Location.
