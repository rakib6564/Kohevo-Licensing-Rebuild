# Restaurant — Standard Operating Procedure (SOP)

**Business:** Chef Gregory's BBQ Smokehouse · 6550 El Camino Real, Atascadero, CA 93422
**System:** Slate Restaurant plugin v0.1.0 (in-house POS) · Phase 1
**Audience:** owners, managers, cashiers/servers

---

## 0. How a customer places an order (the channels)

| Channel | How it works | Who rings it up |
|---|---|---|
| **In person (counter)** | Customer orders at the register | Staff, on the POS |
| **Phone** | Customer calls; staff take it down | Staff, on the POS (type = Takeout) |
| **Online (your own site)** | Customer orders at **`/order`** — menu, cart, pay by card or at pickup | Self-service; lands in **Online orders** for staff to fire |

**Customer ordering link:** `https://greenlightinduction.rakibhasaan.com/order` — share this on your menu, social bios, Google profile, and as a QR code. Customers pick items (with sizes/add-ons), check out, and pay online (Stripe) or choose pay-at-pickup.

**Staff POS link (NOT for customers):** `https://greenlightinduction.rakibhasaan.com/plugins/restaurant/admin/pos.php` — requires a staff login. Do not share with customers.

### Handling online orders (staff)
1. New orders appear in **Restaurant → Online orders** (a badge/contact/items/paid status card per order).
2. Tap **Send to kitchen** to fire the ticket, then advance **Accepted → Preparing → Ready → Completed**.
3. **Paid** (card) orders are already paid; **Due** (pay-at-pickup) orders are collected on the POS/counter when the customer arrives.
4. To turn online ordering on/off or change payment options, use **Settings → Online ordering**.

---

## 1. Opening (start of shift)

1. Log in to the admin and open **Restaurant → POS register**.
2. Confirm today's menu is correct. Any item out of stock → mark it **86** (Menu items → toggle *86*). 86'd items grey out on the register so they can't be sold.
3. If taking cards: confirm a **Card reader** shows online (Restaurant → Card readers). If it's offline, re-pair it before service.
4. Count the cash drawer (outside this system; record per your cash policy).

---

## 2. Taking an order (every order)

1. **+ New check** → choose type:
   - **Dine in** → pick the table + guest count (the table turns "seated").
   - **Takeout** / **Delivery** → guest count only.
2. Tap menu items to add them.
   - If an item has required options (e.g. side, sauce), a window prompts for them — choose, add any **note** (e.g. *no onions, extra sauce*), then add.
   - Adjust quantity with **−/+**; remove an un-sent line with **×**.
3. Read the order back to the customer. Check the running **Total** (top of the check).
4. **Send to kitchen** — fires the kitchen ticket. *Sent lines lock; to change one you must **void** it, not delete it.*

---

## 3. Taking payment

### Cash
1. **Pay** → **Cash**.
2. Add a tip if the customer states one (preset % or custom).
3. Enter **cash tendered** → the screen shows **change due**.
4. Confirm → the check records the payment and **closes** when covered. Make change from the drawer.

### Card (Stripe Terminal)
1. **Pay** → **Card / Terminal** → pick the reader.
2. Add tip if applicable → **Charge on reader**.
3. Customer taps/inserts their card on the reader. The screen shows **present card → approved/declined**.
4. On **approved**, the check closes automatically. On **declined**, retry or take another tender. **Cancel charge** aborts a stuck charge.

> Card payments also settle by Stripe webhook, so a check still closes even if the browser hiccups mid-charge. Never charge the same check twice — the system blocks double-settlement, but always confirm the check shows **paid** before re-trying.

---

## 4. Discounts, comps, voids

- **Discount** — manager-approved dollar amount off a check (button on the check).
- **Void check** — cancels the entire check (requires the **Void/comp** permission, `restaurant.manage_orders`). Enter a reason. Use for mistakes/walkouts.
- **Close check** — closes a check with no further payment (e.g. a comp). Use sparingly and per policy.
- All voids/discounts/payments are recorded in the **audit log**.

---

## 5. 86-ing an item (sold out)

1. **Menu items** → open the item → toggle **86** on → save.
2. It immediately greys out on the register. Toggle off when restocked.

---

## 6. End of day

1. Make sure **no checks are left open** (open-checks rail on the POS should be empty). Settle or void any stragglers.
2. Reconcile the cash drawer against cash payments (per your cash policy).
3. Card totals reconcile in the Stripe dashboard (stripe-payment plugin).
4. Log out.

---

## 7. Roles (who can do what)

| Role needs… | To… |
|---|---|
| `restaurant.pos` | run the register, take orders & payment |
| `restaurant.manage_menu` | edit menu, prices, 86 items |
| `restaurant.manage_orders` | void / comp / reopen / refund |
| `restaurant.manage_settings` | change tax, tips, receipts, readers |
| `restaurant.view` | see orders, menu, the help page |

Grant these per role under **Settings → Roles**. Cashiers typically get `restaurant.view` + `restaurant.pos`; shift leads add `restaurant.manage_orders`.

---

## 8. Troubleshooting

| Symptom | Fix |
|---|---|
| Item won't add | It's **86'd** or the check is already paid/closed. Un-86, or start a new check. |
| Card button disabled | No reader available — configure stripe-payment and pair a reader. Cash still works. |
| Reader offline | Re-pair on **Card readers**; check the reader's network. |
| "Security check failed" in the POS | Session expired — reload the register and log in again. |
| Wrong total/tax | Check the item's **tax rate** and the **Default tax rate** in Settings (currently 8.75% — verify vs. CDTFA). |

---

## 9. Enabling native online ordering (future)

To replace the external SpotOn link with a **customer-facing ordering page on your own domain**, the developer builds **Phase 3 (Online ordering)**: a public menu, cart, checkout, and online card payment that drops orders straight into this POS as `source = online`. The order engine, menu, and payments are already in place to support it. Request this when you're ready to bring online ordering in-house.
