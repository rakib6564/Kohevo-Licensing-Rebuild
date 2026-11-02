# Restaurant — Standard Operating Procedure (SOP)

**System:** Kohevo Restaurant plugin v0.3.0 (POS, card + cash payments with refunds and receipts, online ordering)
**Audience:** owners, managers, cashiers/servers
**Before you hand this to staff:** replace `https://your-site.example` below with your own site address.

---

## 0. How a customer places an order (the channels)

| Channel | How it works | Who rings it up |
|---|---|---|
| **In person (counter)** | Customer orders at the register | Staff, on the POS |
| **Phone** | Customer calls; staff take it down | Staff, on the POS (type = Takeout) |
| **Online (your own site)** | Customer orders at **`/order`** — menu, cart, pay by card or at pickup | Self-service; lands in **Online orders** for staff to fire |

**Customer ordering link:** `https://your-site.example/order` — share this on your menu, social bios, Google profile, and as a QR code. Customers pick items (with sizes/add-ons), check out, and pay online (Stripe) or choose pay-at-pickup.

**Staff POS link (NOT for customers):** `https://your-site.example/plugins/restaurant/admin/pos.php` — requires a staff login. Do not share with customers.

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

> Card payments also settle by Stripe webhook, so a check still closes even if the browser hiccups mid-charge. Pressing **Charge on reader** twice for the same amount re-uses the first charge rather than starting a second one, but always confirm the check shows **paid** before re-trying.

### Receipts
- **Print check** (on an open check) prints the bill before payment; **Print receipt** (on a paid check, or the **Print receipt** button on the order page) prints the receipt with payments.
- The receipt shows your business name and address and the **Receipt header / footer** text from **Settings**, every item with its options, subtotal, discount, service charge, tax, tip, total, the payments and any refunds.

---

## 3a. Refunds

Needs `restaurant.manage_orders`.

1. **Restaurant → Orders** → **View** the order (or open it from the POS with **Order details**).
2. In **Payments**, press **Refund** on the payment. The amount defaults to everything not yet refunded; lower it for a partial refund, and enter a reason.
3. **Issue refund** and confirm.
   - **Card** payments go back to the customer's card through Stripe (usually 5–10 days to appear on their statement).
   - **Cash** payments are only recorded: hand the cash back from the drawer.
4. The order shows **Refunded** and its **Paid** total drops. A payment can be refunded in several parts, never for more than was paid. A refund does not reopen the check.

If Stripe refuses a refund (for example the Stripe balance is too low), nothing is recorded as refunded and you can retry. If Stripe does not answer at all, the order page says so: retry the **same amount** — Stripe will not refund it twice.

---

## 4. Discounts and voids

- **Discount** — manager-approved dollar amount off a check (button on the check).
- **Void check** — cancels the entire check (requires `restaurant.manage_orders`). Enter a reason. Use for mistakes/walkouts.
- **Close check** — closes a check with no further payment. There is no separate comp action yet, so use this sparingly and per policy.
- All voids, discounts, payments and refunds are recorded in the **audit log**.

---

## 5. 86-ing an item (sold out)

1. **Menu items** → open the item → toggle **86** on → save.
2. It immediately greys out on the register. Toggle off when restocked.

---

## 6. End of day

1. Make sure **no checks are left open** (open-checks rail on the POS should be empty). Settle or void any stragglers.
2. Reconcile the cash drawer against cash payments (per your cash policy).
3. Card totals reconcile in the Stripe dashboard (stripe-payment plugin). Refunds show on each order page and in Stripe.
4. Log out.

---

## 7. Roles (who can do what)

| Role needs… | To… |
|---|---|
| `restaurant.pos` | run the register, take orders & payment |
| `restaurant.manage_menu` | edit menu, prices, 86 items |
| `restaurant.manage_orders` | void checks, refund payments |
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
| Wrong total/tax | Check the item's **tax rate** and the **Default tax rate** in Settings (verify it against your tax authority). |
| Refund button missing | Your role lacks `restaurant.manage_orders`, or the payment is already fully refunded, pending or failed. |

---

## 9. Online ordering settings

Online ordering is built in (see section 0). Switch it on or off, and choose card online, pay at pickup and delivery, under **Restaurant settings → Online ordering**. Card orders arrive already paid and can be refunded from the order page like any other card payment.
