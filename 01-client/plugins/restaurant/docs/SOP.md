# Restaurant — Standard Operating Procedure (SOP)

**System:** Kohevo Restaurant plugin v0.5.1 (order channels and a menu feed, full-screen POS with split checks and a table map, combos, kitchen display, card + cash payments with refunds and receipts, reports and payments reconciliation, customer history with import/export, online ordering)
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

The register opens full screen. Use the full-screen button (top right) on a tablet; **?** lists the keyboard shortcuts.

1. **New check** (or tap a free table on **Tables**) → choose **Dine in** (pick the table), **Takeout** or **Delivery**, and the number of guests.
2. For dine in, tap the **seat** number above the check before adding each guest's items, so the ticket and any split show who ordered what.
3. Tap menu items to add them (or type in **Search the menu**, then Enter).
   - Items with options open a window: choose, add a **note** (e.g. *no onions*), set the quantity, then **Add**.
   - **Combos** open the same window with a section per slot (e.g. *Sides — choose 2*, *Drink — choose 1*). **Add** stays refused until each slot has the right number of picks; extras show their price. The picks print on the ticket and the receipt as *Sides: Mac & cheese*.
   - Tap a line on the check to change quantity, seat or check, discount it, comp it (manager), or remove it. Lines already sent are **voided** instead of removed.
4. **Guest** and **note** chips under the check: attach a customer by name, phone or email (or create one), and write an order note.
5. Read the order back. **Send** fires the new lines to the kitchen display.

### Tables
**Tables** shows every table by section: green is free, blue is seated (with the check total and how long it has been open), amber needs clearing. Tap a seated table to open its check, a free one to start a check, an amber one to mark it clean.

### Split and merge checks
- **Split** lets you put each line on check 1, 2, 3… or press **One check per seat**. Tap a line to split part of it (e.g. two of three drinks) onto another check.
- Each check shows its own balance and is paid on its own (**Pay** asks which check). The table frees once every check is paid.
- A check that already has a payment is locked: lines cannot move in or out of it.
- **More → Merge** brings another open check on the same table into this one (only if that check has no payment yet).

### Kitchen display
The kitchen screen (**Restaurant → Kitchen display**, permission `restaurant.kitchen`) shows each sent check as a ticket, oldest first, green under 10 minutes, amber under 20, red after. Tap a line when it is ready, **Ready** for the whole ticket, then **Served** when it has gone out; **Recall** puts a ready ticket back. Tickets chime as they arrive.

---

## 3. Taking payment

### Cash
1. **Pay** (choose the check if it is split) → **Cash**.
2. Add a tip if the customer states one (preset % or custom).
3. Tap the bill the guest handed over (**Exact**, $20, $50…) or type it on the keypad → the screen shows **change due**.
4. **Take cash** → the payment, what was handed over and the change are recorded, and the check is paid when covered. A big **change due** screen confirms what to hand back.

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

## 4. Discounts, comps, voids and reopening

- **Discount** — on the whole check or one line (tap the line): a percentage or an amount, with a reason (quick reasons: promotion, staff meal, manager, complaint, regular guest).
- **Comp an item** — tap the line → **Comp this item** (needs `restaurant.manage_orders` and a reason). The item stays on the check at no charge.
- **Comp the balance** — **More → Comp the balance** closes the check and writes off what is still owed, with a reason (manager).
- **Close check** — only closes a check that is fully paid. A check that still owes money must be paid, or comped with a reason.
- **Void check** — cancels the entire check (manager). Enter a reason. Use for mistakes and walkouts.
- **Reopen** — a paid or closed check can be reopened by a manager (from **Recently closed** in the register) to add items or settle again. A comp on it is undone.
- All voids, discounts, comps, reopens, payments and refunds are recorded in the **audit log**.

---

## 5. 86-ing an item (sold out)

- From the register: **press and hold** (or right-click) the item → **86 it**. Do the same to put it back.
- From **Menu items**: tick the **86** box on the item's row, or select several and **Mark sold out**.
- An 86'd item greys out on every register and disappears from the online menu until it is back on.

---

## 6. End of day

1. Make sure **no checks are left open** (open-checks rail on the POS should be empty). Settle or void any stragglers.
2. Open **Reports** (period *Today*) for the day's net sales, tips (cash and card), payment methods and top items, each compared with yesterday. **CSV** on any table downloads it.
3. **Cash drawer:** in **Payments**, filter today and method *Cash*. The **Net** total is the cash that should be in the drawer above the float (cash refunds already taken off).
4. **Cards:** in **Payments**, filter the day and method *Card reader* (and again *Online card*). The **Net** total is what Stripe owes for that day; set it beside the matching payout in the Stripe dashboard. Pending and failed attempts are listed but not counted. **Export CSV** keeps a copy.
5. Log out.

Reports count an order on the day it was opened, once it is paid or closed; voided orders never count. Net sales are order totals (with tax, service charge and tips) less comps and refunds, and a refund counts on the day it was made.

---

## 6a. Customers

- **History:** Customers shows each guest's orders, total spend (after refunds), average spend and last visit. Sort by spend to find regulars; open a row for their latest orders.
- **Deleting:** a customer with orders is **archived**, not deleted, so old orders keep their name. Archived customers leave the POS guest lookup; restore them from the **Archived** tab.
- **Bringing your list over:** export the customer directory from your old POS as CSV (SpotOn's export works as it is: *Name, Member ID, Email, Phone, Avg. Spend, Total Spend, Last Visit, First Seen*), then **Customers → Import CSV → Check file**. Read the check: new customers, updates, and every row left out with its line number and reason. Fix those rows in the file if you want them, and press **Import**. Importing the same file again updates rather than duplicates.
- **Taking your list elsewhere:** **Export CSV** writes the same columns, with spend and visits including orders taken here.

---

## 7. Roles (who can do what)

| Role needs… | To… |
|---|---|
| `restaurant.pos` | run the register, take orders & payment |
| `restaurant.manage_menu` | edit menu, prices, combos, 86 items |
| `restaurant.manage_orders` | void, comp and reopen checks; comp items; refund payments |
| `restaurant.kitchen` | use the kitchen display |
| `restaurant.manage_settings` | change tax, tips, receipts, readers |
| `restaurant.reports` | see Reports and Payments, download their CSV files |
| `restaurant.manage_customers` | edit, archive and restore customers; import and export the list |
| `restaurant.view` | see orders, menu, the help page |

Grant these per role under **Settings → Roles**. Cashiers typically get `restaurant.view` + `restaurant.pos`; shift leads add `restaurant.manage_orders`; owners and managers add `restaurant.reports`.

---

## 8. Troubleshooting

| Symptom | Fix |
|---|---|
| Item won't add | It's **86'd** or the check is already paid/closed. Un-86, or start a new check. |
| Card button disabled | No reader available — configure stripe-payment and pair a reader. Cash still works. |
| Reader offline | Re-pair on **Card readers**; check the reader's network. |
| "Security check failed" in the POS | Session expired — reload the register and log in again. |
| Wrong total/tax | Check the item's **tax rate** and the **Default tax rate** in Settings (verify it against your tax authority). |
| A combo won't add | Every slot needs its number of picks (the window says which is short). A pick that is 86'd shows as sold out. |
| Report and drawer disagree | Reports go by the day an order was **opened**; Payments by the day each payment was **taken**. A check opened before midnight and paid after it lands on different days. |
| Import left rows out | The check names each row by line and reason (bad email, date or amount). Fix them in the file and import again; good rows are already in. |
| Refund button missing | Your role lacks `restaurant.manage_orders`, or the payment is already fully refunded, pending or failed. |

---

## 9. Online ordering settings

Online ordering is built in (see section 0). Switch it on or off, and choose card online, pay at pickup and delivery, under **Restaurant settings → Online ordering**. Card orders arrive already paid and can be refunded from the order page like any other card payment.

---

## 10. Order channels (aggregators) and the menu feed

**What a channel is.** An outside system that sends you orders: usually an aggregator such as Deliverect, which in turn receives orders from marketplaces like DoorDash or Uber Eats under the contract **you** hold with it. Kohevo does not connect to DoorDash or Uber Eats marketplaces directly; those are open only to approved partners.

**Set one up (Restaurant → Channels, permission `restaurant.channels`):**
1. **Add channel** with a name and an ID (e.g. `deliverect`).
2. **Make the signing secret** and give it, with the **Order URL** (`https://your-site.example/order-channel/<id>`), to the channel. Then tick **Take orders from this channel**.
3. Optionally set the **Status URL** where the channel wants accepted / preparing / ready / completed / cancelled updates.
4. When the first orders arrive, **map every SKU** on the channel page to a menu item (and each modifier SKU to one of that item's options). An order with an unmapped SKU is **not accepted at all** (nothing is half-made), staff get a notification, and it waits under **Orders not accepted**; map the SKUs and press **Retry now**.

"Connected" only appears once the channel has sent an order with a correct signature.

**Deliverect (marketplace orders).** You need your own Deliverect contract for this location, with your marketplaces connected in Deliverect. Deliverect sends orders only to POS integrations it has registered: until Kohevo's integration is certified, only Deliverect's staging (testing) environment works, so check with Kohevo support before relying on production.
1. **Add channel** and choose the type **Deliverect**. In Deliverect, set the integration's order webhook to the **Order URL** on the channel page.
2. **Paste the secret** Deliverect signs with (staging: your channelLink ID; production: the HMAC secret from Deliverect's integration settings) and tick **Take orders from this channel**.
3. Under **Status updates to Deliverect**, pick staging or production and enter the API **client ID** and **client secret** Deliverect issued. Without them orders still arrive, but Deliverect never hears they were accepted or ready.
4. Kohevo does not send the menu to Deliverect: build it there with a **PLU** on every product and modifier. Send a test order from Deliverect, map the PLUs it shows, then **Retry now**.

Deliverect hears each step (accepted 20, preparing 50, ready 70, completed 90, cancelled 110). An order we cannot take (an unmapped PLU, a sold-out item) is reported as **Failed** with the reason, so the marketplace can tell the customer. When the marketplace cancels, the order is voided here and the kitchen tickets drop, unless it was already completed. Delivery and service fees, tips and marketplace discounts stay with the marketplace: our total is the food and tax, and the card warns if it differs from what the marketplace charged. Orders the marketplace has collected payment for are recorded as **paid via the channel** (with the marketplace's restaurant tip, if any) and say **Paid via Deliverect** on the card and the order page: take no payment for them. They count in reports like any paid order. Refunds for them happen in the marketplace, not here; a marketplace cancellation removes the payment from the numbers. Unpaid orders say **Due at pickup** and **Not paid: collect $…** in the notes; test orders from Deliverect say **TEST ORDER**. A modifier ordered twice (e.g. 2× sauce cup) is taken once and written on the line, and the card warns about the total.

**Running channel orders.** They arrive on **Online orders** with the channel's name on the card, and work like web orders: **Accept** sends them to the kitchen, then Preparing → Ready → Completed. Each step is sent back to the channel. If the channel's total differs from ours by more than a cent, the card shows a warning: check it before handing over the food.

**The canonical order** (what the channel, or an adapter in front of it, sends): `POST /order-channel/<id>`, JSON, header `X-Channel-Signature: sha256=<hex HMAC-SHA256 of the body with the secret>`:

```json
{
  "external_order_id": "AGG-1001",
  "placed_at": "2026-11-02T18:30:00Z",
  "type": "delivery",
  "customer": {"name": "Sam", "phone": "555-0100", "email": "sam@example.com"},
  "delivery_address": {"line1": "12 Main St", "city": "Springfield", "postal_code": "90000"},
  "items": [{"external_sku": "BRISKET", "qty": 2, "modifiers": [{"external_sku": "SAUCE-HOT"}], "notes": "extra bark"}],
  "totals": {"total_cents": 4070},
  "callback_url": "https://channel.example/orders/AGG-1001"
}
```

Optional: `"ready_at"` (when the channel wants it ready), `"paid"` (`true` when the channel collected payment: the card and the order page then say **Paid via …** instead of a balance due) and `"notes"`. To cancel, send the same `external_order_id` with `"action": "cancel"` (and an optional `"reason"`): the order is voided unless it is already completed (409).

Answers: **200** `{"ok":true,"order_no":…}`; **401** wrong signature (nothing is recorded); **404** unknown or switched-off channel; **409** still processing; **422** `{"ok":false,"error":…,"unmapped":[…]}`. Sending the same `external_order_id` again returns the first answer and never makes a second order. Status updates go out as `{"external_order_id","order_no","status","updated_at"}`, signed the same way.

**Menu feed.** On the Channels page, **Turn on the feed** to get a token. A channel reads the whole menu (categories, items with price, tax, photo and sold-out state, modifier groups, combos) from `GET /menu-feed` with the header `Authorization: Bearer <token>`. Making a new token stops the old one at once.

## 11. Couriers for your own delivery orders (DoorDash Drive, Uber Direct)

**What it is.** For delivery orders you took yourself (website or register), you can have a DoorDash Dasher carry the order instead of your own driver. DoorDash bills you for each delivery. This is not about orders placed in the DoorDash app (those come through a channel, section 10).

**What you need.** Your own DoorDash Developer account with Drive access. DoorDash currently limits Drive API access and reviews every integration before allowing real deliveries; until then its sandbox and Delivery Simulator only make test deliveries. Kohevo's Drive connection has been tested against a stand-in, not against DoorDash itself.

**Set it up (Restaurant → Couriers, permission `restaurant.channels`):**
1. In the DoorDash Developer Portal, make an access key. Paste its **Developer ID**, **Key ID** and **Signing secret**, pick **Sandbox** or **Production** to match the key, tick **Offer DoorDash couriers** and save. **Check the key** confirms DoorDash accepts it.
2. Check **Couriers collect from** (your business name and address from Settings) and enter the **restaurant phone** the courier can call.
3. **Make the webhook address** and paste it into the Developer Portal's webhooks, so courier updates reach Kohevo.
4. Leave **automatic** off to choose per order, or tick it to book a courier as soon as an online delivery order is paid.

**Sending an order.** On **Online orders**, a delivery card shows **Request DoorDash courier**. Press it to see the price and the pickup and drop-off times; **Confirm courier** within five minutes books it (after that, ask again for a fresh price). The card then shows the courier's progress (finding a courier, courier assigned, on the way, out for delivery, delivered) and a **Track** link; the customer's order page shows the same tracking link. When the courier delivers, the order is marked completed.

**When something goes wrong.** If DoorDash refuses (address outside its area, missing phone), the reason shows on the card and nothing about the order changes: fix the details or use your own driver. **Cancel courier** works until a Dasher is assigned; after that, cancel in DoorDash's merchant portal or through its support.

**Uber Direct.** The same, with an Uber courier. You need an Uber Direct account; Uber approves each business, and test credentials only make test deliveries. Kohevo's Uber connection has been tested against a stand-in, not against Uber itself.
1. In the Uber Direct dashboard (Developer), copy the **Customer ID**, **Client ID** and **Client secret** into the Uber Direct card on Couriers, pick **Test** or **Production** to match, tick **Offer Uber couriers** and save. **Check the credentials** confirms Uber accepts them.
2. In the dashboard's webhooks, add the **Uber** webhook address from the Couriers page for delivery status, and paste the webhook's **signing key** into the card: Kohevo then refuses any update without Uber's signature.
3. Delivery cards on Online orders now also show **Request Uber courier**. Uber's quote lasts about fifteen minutes. **Cancel courier** works until a courier is on the way; after that, cancel in the Uber Direct dashboard.
4. Uber wants the address in parts (street, city, state, ZIP). Kohevo splits the one-line delivery address; write it as "street, city, ST 12345" so it splits cleanly.


## 12. Channels & delivery (the whole picture)

**Two different things.** *Marketplace orders* are placed by customers in apps such as DoorDash or Uber Eats; they reach you through a channel (section 10). *Courier delivery* is for orders you took yourself (website or register): a DoorDash or Uber courier carries them instead of your own driver (section 11). Use either, both or neither.

**How channel orders come in.** The channel (usually an aggregator such as Deliverect) sends each order to your Order URL, signed with a secret only it and Kohevo know. Kohevo checks the signature, turns the channel's product codes (SKUs or PLUs) into your menu items and options, and puts the order on **Online orders** with the channel's name. Every step you take (accepted, preparing, ready, completed, cancelled) goes back to the channel. An order the marketplace already charged for is marked **paid via the channel**: never take payment for it, and refund it in the marketplace.

**How mapping works.** Every product and option code the channel sends must point at one of your menu items, or one of that item's options (Channels → the channel → SKU map). An order with an unmapped code is held, never half-made: staff are notified, and once the code is mapped, **Retry now** creates it.

**What you sign up for.**
- Marketplace orders: a contract with an aggregator for this location (for example Deliverect), with your marketplaces connected there. The aggregator and the marketplaces bill you under those contracts.
- Couriers: your own DoorDash Drive developer account, your own Uber Direct account, or both. Each bills you per delivery.
- Kohevo holds none of these contracts and does not connect to the DoorDash or Uber Eats marketplaces directly.

**DoorDash today, in plain words.** DoorDash currently limits who may use its Drive API and restricts real (production) deliveries, with no published timeline. Until DoorDash approves your account, DoorDash couriers only make test deliveries. Uber approves each Uber Direct business before real deliveries too. Kohevo's DoorDash, Uber and Deliverect connections have been tested against stand-ins of those services, not the services themselves: confirm with Kohevo support before relying on them for real orders.

**Judging them in Reports.** **Sales by channel** shows each channel's orders, sales, average order and cancellations beside the period before. **Courier deliveries** shows deliveries and fees per courier service, and a **Courier fees** card appears once you have used a courier. Courier fees are a cost: they are shown on their own and not taken out of net sales. Every table has a CSV button.
