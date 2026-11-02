# Restaurant — Slate POS & Menu Plugin

A restaurant management module for the Kohevo (Slate) admin platform, licensed
per install under the `restaurant` entitlement. It ships a menu (categories,
items, modifier groups, 86-ing), a floor (sections + tables), standalone
customer records, a **browser point-of-sale** — open checks, build orders,
take cash, and charge cards in person through **Stripe Terminal** — and a
public **online-ordering storefront** at `/order` that drops orders into the
POS. Single-location, self-contained.

**Version 0.4.0** — a **full-screen POS** (table map, seats, split and merged
checks, line and percentage discounts with reasons, comps and reopening, cash
keypad with change, guest lookup, 86 from the register), a **kitchen display**,
and reworked admin screens (orders list with filters and totals, menu board
with inline editing and bulk actions, live online-order board, tabbed settings
with a receipt preview, floor overview).
0.3.0 added **refunds**, **Idempotency-Keys** on every Stripe object the module
creates, and a **printable receipt**.

Not built yet (do not sell these): combos, reports and payments
reconciliation, customer history and CSV import, ordering hours and online
tips, loyalty, gift cards. Items carry a daypart field that is stored but does
not yet hide anything.

---

## Features

### Menu
- **Categories** — sections that group items; slug, description, sort order, active toggle
- **Items** — name, price, per-item tax rate, photo, **86** toggle (mark temporarily unavailable), category and modifier-group assignment. A daypart (all/breakfast/lunch/dinner/late) is stored but not yet applied to the POS or the storefront
- **Modifier groups** — reusable option sets (e.g. *Temperature*, *Add-ons*) with `min`/`max` select and a *required* flag; each option carries a price delta
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

### Customers
- Light standalone CRUD (`admin/customers.php`): name, phone, email, notes
- The POS can find-or-create by phone when attaching a guest to a check

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
| `restaurant_items` | menu items (price, tax, daypart, 86, photo) |
| `restaurant_modifier_groups` / `restaurant_modifiers` | option sets and their options |
| `restaurant_item_modifier_groups` | which groups attach to which item |
| `restaurant_sections` / `restaurant_tables` | the floor |
| `restaurant_customers` | standalone customer records |
| `restaurant_orders` | checks — type, status, totals, service-charge snapshot |
| `restaurant_order_items` | check lines — name/price/tax **snapshots**, qty, seat, check_no, kitchen status |
| `restaurant_order_item_modifiers` | per-line chosen options with price snapshot |
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
runs `install.sql` (14 tables) and registers its permissions; deactivation
preserves data; uninstall runs `uninstall.sql` to drop every
`restaurant_*` table.

### Permissions
| Key | Grants |
|---|---|
| `restaurant.view` | see orders, menu, the floor; the dashboard widget |
| `restaurant.pos` | operate the POS / take orders |
| `restaurant.manage_menu` | create/edit menu, categories, modifiers |
| `restaurant.manage_floor` | manage sections and tables |
| `restaurant.kitchen` | use the kitchen display |
| `restaurant.manage_orders` | void, comp and reopen checks; comp items; refund payments |
| `restaurant.manage_customers` | manage customer records |
| `restaurant.manage_settings` | configure tax, service charge, tips, receipts, readers |
| `restaurant.reports` | reserved for the reports screen (not built; grants nothing today) |

---

## RestaurantAPI

`RestaurantAPI` is the single source of truth — the admin pages, the POS,
and other plugins all call its static methods. Highlights:

- **Reads** — `getPosMenu()`, `getOpenOrders()`, `getOrder($id)`, `getItemModifierGroups($itemId)`
- **Lifecycle** — `openOrder($args)`, `addLine($orderId, $line)`, `setLineQty`, `removeLine`, `voidLine`, `setLineSeat`, `sendToKitchen`, `setDiscount`, `setDiscountPct`, `setLineDiscount`, `compLine`, `setTip`, `closeOrder`, `compOrder`, `reopenOrder`, `voidOrder`
- **Checks** — `checkTotals`, `nextCheckNo`, `moveLineToCheck`, `splitLine`, `mergeOrders`
- **Kitchen** — `setKitchenStatus`, `bumpOrder`, `kitchenTickets`
- **Floor & guests** — `floorMap`, `setTableStatus`, `searchCustomers`, `set86`
- **Tender** — `recordCashPayment`, `beginTerminalPayment`, `pollTerminalPayment`, `cancelTerminalPayment`
- **Refunds** — `refundPayment`, `refundableCents`, `orderMoney` (net paid, tips, refunded)
- **Stripe bridge** — `handleStripeEvent`, `markPaymentPaid`, `syncOrderPayment`, `idempotencyKey`
- **Money** — `money($cents)` → localized string; everything else is integer cents

`addLine()` validates chosen modifiers against the item's groups (required /
min / max) and throws a human-readable error on a bad pick or an 86'd item.

---

## Tests

`tests/integration/RestaurantMoneyTest.php` runs the refund, idempotency,
Terminal (poll, capture, webhook, decline, cancel) and receipt paths, and
`tests/integration/RestaurantPosEngineTest.php` the discounts, per-check
totals, split/merge, comp/reopen, cash tendered, kitchen lifecycle, seats,
guests and 86, against `tests/fixtures/fake-stripe.php` (a loopback stand-in
for the Stripe API that honours Idempotency-Keys; see
`tests/support/fake_stripe.php`).

## Roadmap

Combos · reports and payments reconciliation · customer
history and CSV import/export · ordering hours, scheduling and online tips ·
dayparts applied · loyalty and gift cards. Each is additive: new tables via
version-gated migrations in `Restaurant.php`, new nav items via the
`admin_nav_items` filter.
