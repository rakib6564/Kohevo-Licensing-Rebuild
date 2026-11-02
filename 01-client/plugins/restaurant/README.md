# Restaurant — Slate POS & Menu Plugin

A restaurant management module for the Kohevo (Slate) admin platform, licensed
per install under the `restaurant` entitlement. It ships a menu (categories,
items, modifier groups, combos, 86-ing), a floor (sections + tables),
customers with spend history and CSV import/export, sales **reports** and a
payments reconciliation list, a **browser point-of-sale** — open checks, build orders,
take cash, and charge cards in person through **Stripe Terminal** — and a
public **online-ordering storefront** at `/order` that drops orders into the
POS. Single-location, self-contained.

**Version 0.5.3** — **couriers for your own delivery orders through DoorDash Drive**: staff request a courier from the online-orders board (quote with fee and times, then confirm), the job follows DoorDash's status through webhooks that are re-checked against the API, and the customer's order page shows the courier and the tracking link. Built to the Drive API v2 docs and tested against a local stand-in only: DoorDash currently limits Drive API access and restricts production.
0.5.2 added a **Deliverect channel adapter**: Deliverect's POS order webhook (its own HMAC header) read into the canonical intake, status updates through Deliverect's order status API, Failed reported for orders we refuse, and marketplace cancellations. Built to Deliverect's published format and tested against synthetic fixtures; not yet run against a live Deliverect account.
0.5.1 added **order channels**: a signed canonical intake for aggregators with SKU mapping, retry and status callbacks, and a token-protected menu feed.
0.5.0 added **combos** (a priced bundle with slots to pick from, in
the POS and online), a **reports** screen (period comparison, CSV per table),
a **payments** list for reconciling Stripe payouts, and **customer history**
(spend, visits, archiving instead of deleting, CSV import and export in the
column shape other POS systems export).
0.4.0 added a **full-screen POS** (table map, seats, split and merged
checks, line and percentage discounts with reasons, comps and reopening, cash
keypad with change, guest lookup, 86 from the register), a **kitchen display**,
and reworked admin screens (orders list with filters and totals, menu board
with inline editing and bulk actions, live online-order board, tabbed settings
with a receipt preview, floor overview).
0.3.0 added **refunds**, **Idempotency-Keys** on every Stripe object the module
creates, and a **printable receipt**.

Not built yet (do not sell these): ordering hours and scheduling, online
tips, order notifications, loyalty, gift cards, delivery-partner
integrations. Items carry a daypart field that is stored but does
not yet hide anything.

---

## Features

### Menu
- **Categories** — sections that group items; slug, description, sort order, active toggle
- **Items** — name, price, per-item tax rate, photo, **86** toggle (mark temporarily unavailable), category and modifier-group assignment. A daypart (all/breakfast/lunch/dinner/late) is stored but not yet applied to the POS or the storefront
- **Modifier groups** — reusable option sets (e.g. *Temperature*, *Add-ons*) with `min`/`max` select and a *required* flag; each option carries a price delta
- **Combos** (`admin/combos.php`) — a menu item flagged `is_combo`, sold at its own price, with slots (label, min/max picks) offering other active menu items, each with an optional price delta. A combo cannot contain itself or another combo. The POS sheet and the storefront panel enforce the slot rules, and the server checks again through `RestaurantAPI::validateLineChoices()`, the one rule set for both
- Items snapshot their name, price, and tax onto each order line, so menu edits never rewrite history

### Floor
- **Sections** — named areas (Patio, Bar, Main) with sort order
- **Tables** — label, seat count, section, and live status (`open → seated → dirty`); a table is auto-seated when a dine-in check opens on it and freed (dirty) once it settles

### POS register (`admin/pos.php`, `assets/pos.js`, `assets/pos.css`)
A full-screen touch register outside the admin chrome, on the shared Kohevo design tokens (brand colour carries through). No build step.

- **Rail** — open checks (filter all / dine in / to go) with age, plus recently closed checks (print, details, reopen)
- **Menu** — category chips, search (`/`, Enter adds the first match), photo tiles, long-press or right-click to 86
- **Table map** — sections, status colours, open-check totals and age; free tables start a check, dirty ones are marked clean
- **Check** — seats, check tabs when split, line sheet (qty, seat, move, split by quantity, discount, comp, served, remove/void), guest and note chips, totals per check or for the order
- **Split / merge** — one check per seat, or line by line; `checkTotals()` shares the order discount and service charge so checks add up exactly; a check with a payment is locked; merge brings another open check on the same table in
- **Discounts** — % or amount, on the check or a line, with a reason; comp an item or the balance (manager); reopen a paid/closed check (manager); close refuses a balance due
- **Pay** — per check: tip presets, cash keypad with quick bills, tendered and change stored; or the Stripe Terminal reader with poll and cancel
- **Shortcuts** — `/` `N` `R` `T` `S` `P` `?` `Esc`, digits on the keypad

### Kitchen display (`admin/kds.php`, permission `restaurant.kitchen`)
- Tickets for every check with a line `sent` or `ready`, oldest first; age colours at 10 and 20 minutes against the server clock
- Tap a line: sent → ready → served (`ready_at` / `served_at` stored); whole-ticket Ready, Served and Recall; filters; 5-second refresh and a chime on new tickets

The page is self-contained: a `GET` renders the register, a `POST` with
`_ajax=1` is a JSON endpoint that drives `RestaurantAPI`. No separate API
route or build step.

### Card-present payments
- **Card readers** (`admin/readers.php`) — register Stripe Terminal readers by their Stripe reader id; per-reader active toggle
- Charges are **manual-capture** card-present PaymentIntents tagged `source=restaurant`; the register pushes the intent to the reader, polls until the card is collected, captures, and settles
- The plugin also listens on the shared `stripe_webhook_event` action, so a payment settles even if the browser poll is interrupted — **idempotent**: a repeated webhook never double-charges or double-closes
- **Idempotency-Keys** — the payment row is written first and its id keys the Stripe calls (`RestaurantAPI::idempotencyKey()`, salted per install). A repeated *begin* for the same check, reader and amount within 15 minutes reuses the pending row, so Stripe replays the first PaymentIntent and reader push instead of creating new ones. Online Payment Element and Checkout begins reuse a still-open pending PaymentIntent or session the same way

### Refunds (`admin/order.php`)
- `RestaurantAPI::refundPayment($paymentId, $cents|null, $reason)`, gated by `restaurant.manage_orders` on the order page
- Card payments (terminal or online) are refunded through `StripePaymentAPI::refundCharge()` against the central `stripepayment_charges` row recorded when the payment settled (`restaurant_payments.central_charge_id`; payments settled before 0.3.0 find theirs by PaymentIntent). Cash refunds are recorded only
- The amount is claimed on the payment row in one conditional `UPDATE` before Stripe is called, so concurrent refunds can never exceed what was paid; the claim is released if Stripe refuses
- Partial refunds keep the payment `paid` with `refunded_cents`; the last one marks it `refunded`. A refund comes out of the amount first and then the tip. The order's `paid_cents` drops, `refunded_cents` rises, and the check is not reopened
- Every attempt is a row in `restaurant_refunds` (`succeeded`, `failed` = refused by Stripe, `pending` = Stripe did not answer). The Stripe Idempotency-Key is the payment, what was refunded before, the amount and the count of refusals — a retry after a lost answer replays, a retry after a refusal is new

### Receipts (`admin/receipt.php`)
- A bare print page (80 mm wide, also fine on A4/Letter) rendered by `includes/receipt.php`: business name and address, **receipt header/footer** settings, lines with modifiers, subtotal · discount · service charge · tax · tip · total, payments and refunds, timestamps
- Requires the **stripe-payment** plugin to be configured; without it, the POS still takes cash and the card button is disabled with a hint

### Admin screens
- **Orders** (`admin/orders.php`) — search, date range, status, type and source filters, totals for the filter (sales, orders and average, collected, tips, discounts and comps, refunds), paging, quick actions
- **Menu items** (`admin/items.php`) — grouped by category, drag to reorder, inline price, 86 and visible switches, thumbnails, search, bulk 86 / show / hide / move / delete
- **Online orders** (`admin/online.php`) — live board by fulfilment state with one-click advance (accepting fires the kitchen), chime, last 24 hours of finished orders
- **Settings** — tabs with a live receipt preview; **Floor** — section cards with table status and open checks

### Reports and payments (`admin/reports.php`, `admin/payments.php`, permission `restaurant.reports`)
- **Reports** — today / yesterday / this week / last week / this month / custom, every figure against the equivalent previous period with the change: gross sales, discounts (line + order), refunds (count and amount, from the refunds ledger), comps, net sales, orders, average order, covers, tax, service charges, tips (cash vs card), payment-method mix net of refunds, top 10 items by quantity and by revenue, categories, POS vs online, online orders by fulfilment state
- Rules (all in `includes/reports.php`, class `RestaurantReports`): an order is a sale when paid or closed, by `opened_at`; voided never counts; net sales = order totals − comps − refunds; refunds count by refund date; payment mix by payment date
- **CSV**: one file per table (`?export=<table>`), written from the same cells the screen shows
- **Payments** — every payment with order, check, method, amount, tip, refunded, net, status (paid / partly refunded / refunded / pending / failed), PaymentIntent id, central charge id and reader; filters for date range, method, status, source and a search on order number or Stripe id; a totals strip over the whole filter (settled payments only) and a CSV with a totals row

### Order channels and menu feed (`admin/channels.php`, `includes/channels.php`, permission `restaurant.channels`)
- **Intake** — `POST /order-channel/{slug}` (public route, `channels/intake.php`) takes the canonical order JSON documented in `includes/channels.php` and `docs/SOP.md` §10, authenticated by `X-Channel-Signature: sha256=<HMAC-SHA256 of the raw body>` with the channel's secret (stored encrypted like Stripe's secret key; compared in constant time). Verify first: a bad signature records nothing. Each `(channel, external_order_id)` gets one `restaurant_channel_orders` row — the idempotency record — so a replay returns the first answer. Items and modifiers resolve through `restaurant_item_mappings`; one unmapped SKU rejects the whole order (422, staff notification, audit) and Retry works once mapped. Orders are built all-or-nothing through `openOrder()`/`addLine()` (`source = 'channel'`, fulfilment `new`; a failed build is removed row by row rather than rolled back, because the core session check issues DDL that commits an open transaction); a channel total more than 1 cent from ours is accepted and flagged (`flag_reason`, audit).
- **Status pushback** — `setFulfillment()` fires `restaurant_fulfillment_changed`; the channel's `RestaurantChannelAdapter` (chosen by the channel's type, `restaurant_channels.kind`, from the `restaurant_channel_adapters` filter; the base adapter otherwise) tells the channel. The base adapter POSTs a signed canonical update to the order's `callback_url` or the channel's. Calls go to https only (plain http only to this machine, for tests) and are logged in `restaurant_channel_events`.
- **Paid by the channel** — a canonical `"paid": true` (Deliverect's `orderIsAlreadyPaid`) records a `'channel'` payment (`RestaurantAPI::recordChannelPayment()`) for our total plus any restaurant tip the channel passes on (`totals.tip_cents`; Deliverect's `tip`, never `driverTip`), so the order is paid everywhere: register, board ("Paid via <channel>"), order page, sales reports (by source and in the method mix as "Paid via channel") and the payments list. A channel payment cannot be refunded here (the marketplace refunds its own customers); a channel cancel marks it failed ("Cancelled by channel") so no money counts for a voided order. The order page names the channel as the source and shows the channel order ID, fulfilment and total warning.
- **Cancellations** — a canonical delivery with `"action": "cancel"` voids the order and marks it cancelled (which sends the cancelled update), unless it is completed (409). A cancel for an order not seen yet is recorded, so the late order is refused. Repeats are no-ops.
- **Deliverect adapter** (`includes/channel-deliverect.php`, type `deliverect`) — verifies `x-server-authorization-hmac-sha256` (hex HMAC-SHA256 of the raw body; the secret is pasted in: the channelLink ID in staging, the integration's HMAC secret in production). Reads `_id` (the external order ID), `orderType`, `customer`, `deliveryAddress`, `pickupTime`, `items[].plu/quantity/remark` and `subItems` at any depth (each a modifier SKU, taken once; one ordered more than once is noted on the line), `decimalDigits`, and `payment.amount` less the channel's fees and tips plus its discounts as the claimed food total. `status: 100` is a cancel. Status goes to `POST {api}/orderStatus/{_id}` as Deliverect codes (accepted 20, preparing 50, ready 70, completed 90, cancelled 110, Failed 120 for a refused order with the reason) with a bearer token from `POST {api}/oauth/token` (client credentials, stored encrypted, cached until a minute before `expires_at`, refetched once on a 401). Staging or production API base, client ID and secret are set on the channel page (`restaurant_channels.config`, secrets sealed). Built from developers.deliverect.com as of 2 Nov 2026; tested with synthetic fixtures (`tests/fixtures/deliverect/`) and a loopback stand-in for the API, not against Deliverect itself. Deliverect only sends orders to POS integrations it has registered, and production needs Deliverect certification of the integration.
- **Menu feed** — `GET /menu-feed` with `Authorization: Bearer <token>` (per install, rotatable): categories, active items (price, tax, daypart, 86, photo URL, modifier group ids, combo slots), modifier groups; `version` (latest menu update) and an `ETag` (304 on match). Every menu save fires `restaurant_menu_changed`.
- **Not built here**: no marketplace (DoorDash / Uber Eats) API clients — marketplace orders come through an aggregator the restaurant contracts with. No menu push to Deliverect (the menu and its PLUs are built in Deliverect and mapped here), and no Deliverect POS registration endpoint. Courier fulfilment is below; Uber Direct is a later part.

### Couriers (`admin/couriers.php`, `includes/delivery.php`, permission `restaurant.channels`)
- **Jobs** — `restaurant_delivery_jobs`, one row per attempt with one provider. `external_id` is ours (`kv<tenant>-<order>-<attempt>`) and is sent as the provider's delivery id, so a repeated create is refused by the provider rather than doubled. Status: quoted → requested → courier_assigned → enroute_pickup → picked_up → delivered, or cancelled/failed; it only moves forward, and a delivered job marks the order completed. One active courier per order.
- **Request** — `RestaurantDelivery::quote()` (pickup from the business settings plus a courier phone, `biz_phone`; drop-off read from the order's "Deliver to:" notes prefix, as checkout and channel intake store it, since there is no structured address yet; customer name and phone in E.164; `order_value` = subtotal), then `accept()` within the quote's 5 minutes. Online-orders board: Request courier → quote card (fee, pickup and drop-off times) → Confirm courier or Not now; the card then shows status, courier, tracking and Cancel courier. Order page and the customer's confirm page show the courier and the tracking link. `courier_auto` books one when an online delivery order is paid (hook `restaurant_order_paid`); channel orders never, since the marketplace may bring its own courier.
- **Failures** — a provider error (no coverage, a bad address, a refused key) is shown to staff, recorded on a failed job, and leaves the order untouched. An expired quote must be asked for again (new attempt id). Cancel works until a courier is assigned; after that the provider's refusal is shown with a pointer to its merchant portal.
- **Webhooks** — `POST /courier-webhook/<provider>/<token>` (public route, `channels/courier-webhook.php`). The per-install token is made on the Couriers page; a wrong one is 401 and nothing is read. Drive webhooks carry no signature, so the body only names the delivery: the job is re-read with `GET /deliveries/{id}` and only that answer is applied. Unknown deliveries are acknowledged; duplicates and stale events change nothing.
- **DoorDash Drive** (`RestaurantDoorDashDrive`) — API v2 at `https://openapi.doordash.com/drive/v2` (`drive_api_base` overrides it for tests). A JWT is minted per request: HS256, header `dd-ver: DD-JWT-V1`, claims `aud: doordash`, `iss` developer ID, `kid` key ID, `iat`, `exp` = iat + 5 min (Drive allows 30), signed with the base64url-decoded signing secret (stored sealed). `POST /quotes`, `POST /quotes/{id}/accept`, `GET /deliveries/{id}`, `PUT /deliveries/{id}/cancel`; errors `{code, message, field_errors[]}` become the staff message. "Check the key" looks up a delivery that cannot exist: 404 means DoorDash accepted the key. Checked against developer.doordash.com on 2 Nov 2026, where DoorDash calls Drive API access limited, says production access is restricted with no certification timeline, and recommends pausing development without a production request: built anyway at the owner's choice, tested against `tests/fixtures/fake-drive.php` (verifies every JWT, plays the Delivery Simulator) and never against DoorDash.
- **Not built** — Business/Store API onboarding (needed only for more than one location), `dropoff_address_components` (needs a structured address), tips to the Dasher, delivery fees passed on to the customer at checkout.

### Customers (`admin/customers.php`, `includes/customers.php`)
- Records with name, phone, email, member ID and notes; the POS finds-or-creates by exact phone when attaching a guest (unchanged)
- **History** — orders, total spend (order total − comp − refunds), average, last visit, first seen, from an aggregate over the customer's paid and closed orders (index `tenant_customer`), so it always agrees with the orders after voids, refunds, merges and reopens. Sort by name, spend or last visit; a row opens to the latest orders
- **Archive, not delete** — a customer any order points at is archived (`is_active = 0`): kept, hidden from the guest lookup, restorable; one with no orders is deleted
- **Import** — the column shape of SpotOn's customer-directory export: `Name, Member ID, Email, Phone, Avg. Spend, Total Spend, Last Visit, First Seen` (case-insensitive header, extra columns ignored). Checked against a real 423-row export: `-` and `N/A` mean empty, Last Visit reads like `4:56 PM 10/31/2026` (also `Y-m-d`, `m/d/Y`), and First Seen is the channel the customer first came through (`ORD Web`, `Counter`, `POS`…; stored in `first_seen_via`; a date there is kept as the first-seen date). Dry run first: creates, updates and every bad row by file line and reason; confirm writes in one transaction and is audited. Match on phone (digits compared), else member ID; otherwise create. Email never matches, because exports put one placeholder address on many walk-in customers. Rows that land on one customer (a shared phone) have their history added; imported spend, visits (Total ÷ Avg.) and last visit are stored as history (`hist_*`) and merged on read, and a re-import replaces them, so importing twice changes nothing. An update only fills in empty fields. A blank name stays blank and the list shows the email, phone or member ID instead
- **Export** — the same columns (money as `$1,040.00`, dates `Y-m-d`, First Seen as the channel when known) with the merged history; importing it into another install recreates the same file (importing it back into the same install would count its Kohevo orders twice)

### Settings (`admin/settings.php`)
- Currency, default tax rate, **service charge** (percent + auto-apply toggle, snapshotted onto each order at open time), tip presets, receipt header/footer, business name and address (printed on receipts, used for the Terminal Location)

### Dashboard widget
- Today's sales, open-check count, and covers, plus the five most recent orders — auto-hides when the plugin is deactivated

### In-app help (`admin/help.php`)
- A staff-facing **Help & docs** page in the admin (nav item + a link on the Settings page): getting started, using the POS, card payments, a settings reference, and the permission list. This developer `README.md` is the technical companion to it.

### Online ordering (`storefront/`, public)
- A public, mobile-friendly storefront at **`/order`** (registered with the core `public_routes` filter — no `.htaccess` change). Customers browse the menu, build a session cart (with sizes + add-ons), and check out.
- **Payment:** hosted **Stripe Checkout** (PCI-safe — card data never touches the app) and/or **pay at pickup**. Order types: pickup and optional delivery.
- Pages: `index.php` (menu) · `item.php` (options) · `cart.php` · `checkout.php` (creates the order + Stripe session) · `confirm.php` (settles the returning session, shows status). Shared layout in `includes/sf.php`; dispatched by `router.php`.
- Orders are created via `RestaurantAPI::createOnlineOrder()` as `source = 'online'`, settle through `settleOnlineFromSession()` (return page) with the Stripe webhook as a backstop, and surface in the admin **Online orders** queue (`admin/online.php`) with a fulfilment workflow (`new → accepted → preparing → ready → completed`).
- Toggle everything in **Settings → Online ordering** (`online_enabled`, `online_pay`, `online_pay_pickup`, `online_delivery`).

### Operating procedure
- **`docs/SOP.md`** — the staff Standard Operating Procedure: ordering channels (incl. the `/order` link), opening, taking orders, cash + card payment, receipts, refunds, online-order fulfilment, discounts and voids, 86-ing, end-of-day, roles, and troubleshooting.

---

## Data model

All tables are `restaurant_`-prefixed, tenant-scoped, and store **money as
integer cents**. Created by `install.sql`; `RestaurantAPI::ensureSchema()`
re-creates any missing table defensively on every admin page load.

| Table | Holds |
|---|---|
| `restaurant_menu_categories` | menu sections |
| `restaurant_items` | menu items (price, tax, daypart, 86, photo, `is_combo`) |
| `restaurant_combo_slots` / `restaurant_combo_choices` | a combo's slots and the items each offers, with a price delta |
| `restaurant_modifier_groups` / `restaurant_modifiers` | option sets and their options |
| `restaurant_item_modifier_groups` | which groups attach to which item |
| `restaurant_sections` / `restaurant_tables` | the floor |
| `restaurant_customers` | customers — contact, member ID, imported history (`hist_*`), `is_active` / `archived_at` |
| `restaurant_orders` | checks — type, status, totals, service-charge snapshot |
| `restaurant_order_items` | check lines — name/price/tax **snapshots**, qty, seat, check_no, kitchen status |
| `restaurant_order_item_modifiers` | per-line chosen options and combo picks (`kind` = modifier / component, named "Slot: Item") with price snapshot |
| `restaurant_payments` | cash/terminal/online tenders — amount, tip, status, refunded amount, Stripe ids, central charge id |
| `restaurant_refunds` | one row per refund attempt — amount, status, Stripe refund id, reason, who |
| `restaurant_readers` | registered Stripe Terminal readers |

### Money & totals
Per-line tax is computed on each line's net total from the snapshotted
`tax_rate`. The order **service charge** is a percentage of the *discounted*
subtotal, with the percent snapshotted at open time so later recalcs stay
stable even if the global setting changes mid-service. Tip is added at
tender and folded into the order total before the paid/closed comparison.
Paid is net of refunds: `paid = Σ (amount + tip − refunded)` over settled
payments, and a refund takes the tip back only once it exceeds the amount.

```
line_total      = (unit_price + Σ modifier_deltas) × qty
subtotal        = Σ non-void line_totals
line_discount   = fixed (≤ line) or line × pct     (line_total is after it)
discount        = subtotal × pct, or fixed ≤ subtotal
service_charge  = round((subtotal − discount) × service_pct / 100)
tax             = Σ round(line_total × line_tax_rate / 100)
total           = subtotal − discount + service_charge + tax + tip
```

---

## Install & activate

The plugin lives at `plugins/restaurant/`. Activate it from the admin
**Plugins** page (or it's already registered on this install). Activation
runs `install.sql` (16 tables) and registers its permissions; deactivation
preserves data; uninstall runs `uninstall.sql` to drop every
`restaurant_*` table.

### Permissions
| Key | Grants |
|---|---|
| `restaurant.view` | see orders, menu, the floor; the dashboard widget |
| `restaurant.pos` | operate the POS / take orders |
| `restaurant.manage_menu` | create/edit menu, categories, modifiers, combos |
| `restaurant.manage_floor` | manage sections and tables |
| `restaurant.kitchen` | use the kitchen display |
| `restaurant.manage_orders` | void, comp and reopen checks; comp items; refund payments |
| `restaurant.manage_customers` | manage customers; archive/restore; import and export |
| `restaurant.manage_settings` | configure tax, service charge, tips, receipts, readers |
| `restaurant.reports` | Reports and Payments, with their CSV exports |
| `restaurant.channels` | order channels, SKU mapping, retries, the menu feed token |

---

## RestaurantAPI

`RestaurantAPI` is the single source of truth — the admin pages, the POS,
and other plugins all call its static methods. Highlights:

- **Reads** — `getPosMenu()`, `getOpenOrders()`, `getOrder($id)`, `getItemModifierGroups($itemId)`, `getComboSlots($itemId)`
- **Combos** — `saveCombo($itemId, $slots)`, `validateLineChoices($itemId, $modifierIds, $comboPicks)`, `lineUnitCents(…)`, `deleteItem($itemId)`
- **Lifecycle** — `openOrder($args)`, `addLine($orderId, $line)`, `setLineQty`, `removeLine`, `voidLine`, `setLineSeat`, `sendToKitchen`, `setDiscount`, `setDiscountPct`, `setLineDiscount`, `compLine`, `setTip`, `closeOrder`, `compOrder`, `reopenOrder`, `voidOrder`
- **Checks** — `checkTotals`, `nextCheckNo`, `moveLineToCheck`, `splitLine`, `mergeOrders`
- **Kitchen** — `setKitchenStatus`, `bumpOrder`, `kitchenTickets`
- **Floor & guests** — `floorMap`, `setTableStatus`, `searchCustomers`, `set86`
- **Tender** — `recordCashPayment`, `beginTerminalPayment`, `pollTerminalPayment`, `cancelTerminalPayment`
- **Refunds** — `refundPayment`, `refundableCents`, `orderMoney` (net paid, tips, refunded)
- **Stripe bridge** — `handleStripeEvent`, `markPaymentPaid`, `syncOrderPayment`, `idempotencyKey`
- **Money** — `money($cents)` → localized string; everything else is integer cents

`addLine()` validates chosen modifiers and combo picks through
`validateLineChoices()` (required / min / max per group and slot) and throws a
human-readable error on a bad pick or an 86'd item. Reports live in
`RestaurantReports` (`includes/reports.php`) and customer history, import and
export in `RestaurantCustomers` (`includes/customers.php`).

---

## Tests

`tests/integration/RestaurantMoneyTest.php` runs the refund, idempotency,
Terminal (poll, capture, webhook, decline, cancel) and receipt paths, and
`tests/integration/RestaurantPosEngineTest.php` the discounts, per-check
totals, split/merge, comp/reopen, cash tendered, kitchen lifecycle, seats,
guests and 86, `RestaurantCombosTest.php` combo setup, pricing, slot rules
(POS and cart alike), online = POS totals, kitchen and receipt,
`RestaurantReportsTest.php` every report figure and the payments list against
hand-computed sums, and `RestaurantCustomersTest.php` history, archiving and a
423-row import, against `tests/fixtures/fake-stripe.php` (a loopback stand-in
for the Stripe API that honours Idempotency-Keys; see
`tests/support/fake_stripe.php`).

## Roadmap

Uber Direct courier fulfilment · ordering hours, scheduling, online tips and order notifications · dayparts
applied · loyalty and gift cards · floor-map editor. Shipped: combos, reports
and payments reconciliation, customer history and CSV import/export (0.5.0), order channels and the menu feed (0.5.1), the Deliverect adapter (0.5.2), DoorDash Drive couriers (0.5.3). Each is additive: new tables via
version-gated migrations in `Restaurant.php`, new nav items via the
`admin_nav_items` filter.
