# Restaurant — Slate POS & Menu Plugin

A SpotOn-style restaurant management suite for the Slate admin platform.
Phase 1 ships the foundation: a menu (categories, items, modifier groups,
86-ing, dayparts), a floor (sections + tables), standalone customer records,
and a **browser point-of-sale** — open checks, build orders, take cash, and
charge cards in person through **Stripe Terminal**.

**Version 0.2.0** — adds a public **online-ordering storefront** at `/order`
(menu → cart → checkout with hosted Stripe payment or pay-at-pickup) that drops
orders into the POS, plus an admin **Online orders** queue. Phase 1 (POS, menu,
floor, Terminal/cash) remains the foundation. Single-location, self-contained.
Later phases (KDS, reservations, loyalty, labor, reporting) hang additive
migrations and nav items off the same plugin class.

---

## Features

### Menu
- **Categories** — sections that group items; slug, description, sort order, active toggle
- **Items** — name, price, per-item tax rate, photo, **daypart** (all/breakfast/lunch/dinner/late), **86** toggle (mark temporarily unavailable), category and modifier-group assignment
- **Modifier groups** — reusable option sets (e.g. *Temperature*, *Add-ons*) with `min`/`max` select and a *required* flag; each option carries a price delta
- Items snapshot their name, price, and tax onto each order line, so menu edits never rewrite history

### Floor
- **Sections** — named areas (Patio, Bar, Main) with sort order
- **Tables** — label, seat count, section, and live status (`open → seated → dirty`); a table is auto-seated when a dine-in check opens on it and freed (dirty) once it settles

### POS register (`admin/pos.php`)
The centerpiece. A three-pane register that runs entirely in the browser:

- **Open checks** rail — every live check (`open`/`sent`) with its table/type and running total; click to switch
- **Menu grid** — category tabs, tap-to-add; 86'd items are visibly disabled
- **Live check** — line items with qty steppers, modifier and note display, void/remove, and a full totals breakdown (subtotal · discount · service charge · tax · tip · total · paid · balance)
- **New check** — dine-in (with table picker), takeout, or delivery; guest count
- **Modifiers modal** — enforces each group's required/min/max rules before a line is added
- **Send to kitchen** — fires all `new` lines (the KDS hook-in point for Phase 2)
- **Discount & tip** — order-level discount; tip presets (% of pre-tip total) or a custom amount
- **Payment** — **cash** (with change calculation) or **card on a Stripe Terminal reader**, with a live "present card / approved / declined" poll loop and cancel
- Split-check support exists in the engine (`check_no` per line, `moveLineToCheck`); a split UI lands in a later phase

The page is self-contained: a `GET` renders the register, a `POST` with
`_ajax=1` is a JSON endpoint that drives `RestaurantAPI`. No separate API
route or build step.

### Card-present payments
- **Card readers** (`admin/readers.php`) — register Stripe Terminal readers by their Stripe reader id; per-reader active toggle
- Charges are **manual-capture** card-present PaymentIntents tagged `source=restaurant`; the register pushes the intent to the reader, polls until the card is collected, captures, and settles
- The plugin also listens on the shared `stripe_webhook_event` action, so a payment settles even if the browser poll is interrupted — **idempotent**: a repeated webhook never double-charges or double-closes
- Requires the **stripe-payment** plugin to be configured; without it, the POS still takes cash and the card button is disabled with a hint

### Customers
- Light standalone CRUD (`admin/customers.php`): name, phone, email, notes
- The POS can find-or-create by phone when attaching a guest to a check

### Settings (`admin/settings.php`)
- Currency, default tax rate, **service charge** (percent + auto-apply toggle, snapshotted onto each order at open time), tip presets, receipt/location details

### Dashboard widget
- Today's sales, open-check count, and covers, plus the five most recent orders — auto-hides when the plugin is deactivated

### In-app help (`admin/help.php`)
- A staff-facing **Help & docs** page in the admin (nav item + a link on the Settings page): getting started, using the POS, card payments, a settings reference, and the permission list. This developer `README.md` is the technical companion to it.

### Online ordering (`storefront/`, public — Phase 3)
- A public, mobile-friendly storefront at **`/order`** (registered with the core `public_routes` filter — no `.htaccess` change). Customers browse the menu, build a session cart (with sizes + add-ons), and check out.
- **Payment:** hosted **Stripe Checkout** (PCI-safe — card data never touches the app) and/or **pay at pickup**. Order types: pickup and optional delivery.
- Pages: `index.php` (menu) · `item.php` (options) · `cart.php` · `checkout.php` (creates the order + Stripe session) · `confirm.php` (settles the returning session, shows status). Shared layout in `includes/sf.php`; dispatched by `router.php`.
- Orders are created via `RestaurantAPI::createOnlineOrder()` as `source = 'online'`, settle through `settleOnlineFromSession()` (return page) with the Stripe webhook as a backstop, and surface in the admin **Online orders** queue (`admin/online.php`) with a fulfilment workflow (`new → accepted → preparing → ready → completed`).
- Toggle everything in **Settings → Online ordering** (`online_enabled`, `online_pay`, `online_pay_pickup`, `online_delivery`).

### Operating procedure
- **`docs/SOP.md`** — the staff Standard Operating Procedure: ordering channels (incl. the `/order` link), opening, taking orders, cash + card payment, online-order fulfilment, discounts/voids/comps, 86-ing, end-of-day, roles, and troubleshooting.

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
| `restaurant_payments` | cash/terminal tenders — amount, tip, status, Stripe ids |
| `restaurant_readers` | registered Stripe Terminal readers |

### Money & totals
Per-line tax is computed on each line's net total from the snapshotted
`tax_rate`. The order **service charge** is a percentage of the *discounted*
subtotal, with the percent snapshotted at open time so later recalcs stay
stable even if the global setting changes mid-service. Tip is added at
tender and folded into the order total before the paid/closed comparison.

```
line_total      = (unit_price + Σ modifier_deltas) × qty
subtotal        = Σ non-void line_totals
discount        ≤ subtotal
service_charge  = round((subtotal − discount) × service_pct / 100)
tax             = Σ round(line_total × line_tax_rate / 100)
total           = subtotal − discount + service_charge + tax + tip
```

---

## Install & activate

The plugin lives at `plugins/restaurant/`. Activate it from the admin
**Plugins** page (or it's already registered on this install). Activation
runs `install.sql` (13 tables) and registers its permissions; deactivation
preserves data; uninstall runs `uninstall.sql` to drop every
`restaurant_*` table.

### Permissions
| Key | Grants |
|---|---|
| `restaurant.view` | see orders, menu, the floor; the dashboard widget |
| `restaurant.pos` | operate the POS / take orders |
| `restaurant.manage_menu` | create/edit menu, categories, modifiers |
| `restaurant.manage_floor` | manage sections and tables |
| `restaurant.manage_orders` | void / comp / reopen checks and refunds |
| `restaurant.manage_customers` | manage customer records |
| `restaurant.manage_settings` | configure tax, service charge, tips, receipts, readers |
| `restaurant.reports` | view reports (Phase 2+) |

---

## RestaurantAPI

`RestaurantAPI` is the single source of truth — the admin pages, the POS,
and other plugins all call its static methods. Highlights:

- **Reads** — `getPosMenu()`, `getOpenOrders()`, `getOrder($id)`, `getItemModifierGroups($itemId)`
- **Lifecycle** — `openOrder($args)`, `addLine($orderId, $line)`, `setLineQty`, `removeLine`, `voidLine`, `moveLineToCheck`, `sendToKitchen`, `setDiscount`, `setTip`, `closeOrder`, `voidOrder`
- **Tender** — `recordCashPayment`, `beginTerminalPayment`, `pollTerminalPayment`, `cancelTerminalPayment`
- **Stripe bridge** — `handleStripeEvent`, `markPaymentPaid`, `syncOrderPayment`
- **Money** — `money($cents)` → localized string; everything else is integer cents

`addLine()` validates chosen modifiers against the item's groups (required /
min / max) and throws a human-readable error on a bad pick or an 86'd item.

---

## Roadmap (later phases)

KDS (kitchen display) · online ordering · reservations & waitlist · loyalty /
gift cards · labor & timeclock tie-in · reporting. Each is additive: new
tables via version-gated migrations in `Restaurant.php`, new nav items via
the `admin_nav_items` filter — no rewrites of Phase 1.
