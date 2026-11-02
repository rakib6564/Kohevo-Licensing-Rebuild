<?php
/**
 * Restaurant — in-app documentation / help.
 *
 * A read-only guide rendered in the admin shell: getting started, the POS
 * register, card payments, settings, and permissions. Linked from the admin
 * nav ("Help & docs") and the Settings page. The developer-facing reference
 * lives in the plugin's README.md.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.view');
ModuleGuard::require('restaurant');

$pageTitle  = 'Restaurant — Help & docs';
$currentNav = 'restaurant-help';

$url = fn(string $p) => plugin_url('restaurant', 'admin/' . $p);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Help & docs'],
]); ?>

<style>
.rdoc { max-width: 880px; }
.rdoc .card { margin-bottom: 16px; }
.rdoc h2 { margin: 0 0 4px; }
.rdoc h3 { margin: 18px 0 6px; font-size: 1rem; }
.rdoc ol, .rdoc ul { margin: 6px 0 6px 20px; }
.rdoc li { margin: 4px 0; }
.rdoc code { font-family: var(--font-mono); background: var(--surface-2,#f3f4f6); padding: 1px 5px; border-radius: 4px; }
.rdoc .toc { display: flex; flex-wrap: wrap; gap: 8px; }
.rdoc .toc a { padding: 5px 11px; border: 1px solid var(--border,#e5e7eb); border-radius: 999px; font-size: .85rem; text-decoration: none; }
.rdoc .kbd-table { width: 100%; border-collapse: collapse; }
.rdoc .kbd-table td { padding: 6px 8px; border-bottom: 1px solid var(--border,#e5e7eb); vertical-align: top; }
.rdoc .kbd-table td:first-child { white-space: nowrap; font-weight: 600; width: 200px; }
.rdoc .note { background: var(--primary-50,#eff6ff); border: 1px solid var(--border,#dbeafe); border-radius: 10px; padding: 10px 14px; }
/* On phones, let the keyword column wrap instead of forcing a 200px nowrap
   cell that crushes the description column. */
@media (max-width:560px){ .rdoc .kbd-table td:first-child { width: auto; white-space: normal; } }
</style>

<div class="rdoc">

    <div class="page-header">
        <div>
            <h1>Help &amp; documentation</h1>
            <p class="page-header-sub">How to run the menu and combos, the floor, the POS register, card payments, reports and customers. Version <?= e((string) RestaurantAPI::setting('applied_version', '0.1.0')) ?>.</p>
        </div>
        <a href="<?= e($url('pos.php')) ?>" class="btn btn-primary">Open POS register</a>
    </div>

    <div class="card">
        <div class="toc">
            <a href="#start">Getting started</a>
            <a href="#pos">Using the POS</a>
            <a href="#pay">Payments &amp; card readers</a>
            <a href="#refunds"><?= e(__('restaurant_help_refunds_toc', 'Refunds & receipts')) ?></a>
            <a href="#combos"><?= e(__('restaurant_combos', 'Combos')) ?></a>
            <a href="#reports"><?= e(__('restaurant_help_reports_toc', 'Reports & payments')) ?></a>
            <a href="#customers"><?= e(__('restaurant_customers', 'Customers')) ?></a>
            <a href="#channels"><?= e(__('restaurant_channels', 'Channels')) ?></a>
            <a href="#couriers"><?= e(__('restaurant_couriers', 'Couriers')) ?></a>
            <a href="#online">Online ordering</a>
            <a href="#settings">Settings</a>
            <a href="#perms">Roles &amp; permissions</a>
            <a href="#faq">FAQ</a>
        </div>
    </div>

    <div class="card" id="start">
        <h2>Getting started</h2>
        <p class="text-sm text-muted">Set the restaurant up once, in this order:</p>
        <ol>
            <li><b>Settings</b> — set currency, your <b>tax rate</b>, tip presets, and the business name + address (the address is required to register a Stripe Terminal card reader). <a href="<?= e($url('settings.php')) ?>">Open Settings →</a></li>
            <li><b>Categories</b> — create the sections your menu is grouped into (e.g. <em>BBQ Plates</em>, <em>Beverages</em>). <a href="<?= e($url('categories.php')) ?>">Categories →</a></li>
            <li><b>Modifier groups</b> — optional. Reusable option sets (e.g. <em>Sides</em>, <em>Temperature</em>) with a required flag and min/max picks. <a href="<?= e($url('modifiers.php')) ?>">Modifier groups →</a></li>
            <li><b>Menu items</b> — name, price, tax, category, photo, and which modifier groups apply. <a href="<?= e($url('items.php')) ?>">Menu items →</a></li>
            <li><b><?= e(__('restaurant_combos', 'Combos')) ?></b> — <?= e(__('restaurant_help_start_combos', 'optional. Bundles such as a plate with two sides and a drink, at one price.')) ?> <a href="<?= e($url('combos.php')) ?>"><?= e(__('restaurant_combos', 'Combos')) ?> →</a></li>
            <li><b>Floor &amp; tables</b> — for dine-in: create sections and the tables in them. <a href="<?= e($url('floor.php')) ?>">Floor &amp; tables →</a></li>
            <li><b>Card readers</b> — optional. Pair a Stripe Terminal reader to take cards in person. <a href="<?= e($url('readers.php')) ?>">Card readers →</a></li>
        </ol>
        <p class="text-sm">You can take orders and cash payments the moment a category and an item exist — card readers and the floor are optional.</p>
    </div>

    <div class="card" id="pos">
        <h2><?= e(__('restaurant_help_pos_title', 'Using the POS register')) ?></h2>
        <p class="text-sm text-muted"><?= e(__('restaurant_help_pos_intro', 'The register opens full screen: open checks on the left, the menu (or the table map) in the middle, the current check on the right. On a phone the three are tabs at the bottom. Press ? for keyboard shortcuts.')) ?></p>
        <h3><?= e(__('restaurant_help_pos_take', 'Take an order')) ?></h3>
        <ol>
            <li><?= e(__('restaurant_help_pos_1', 'New check (or tap a free table on Tables): dine in with a table, takeout or delivery, and the number of guests.')) ?></li>
            <li><?= e(__('restaurant_help_pos_2', 'For dine in, pick the seat above the check before adding each guest\'s items.')) ?></li>
            <li><?= e(__('restaurant_help_pos_3', 'Tap items, or search the menu and press Enter. Items with options ask for them, with a note and quantity.')) ?></li>
            <li><?= e(__('restaurant_help_pos_4', 'Tap a line to change its quantity, seat or check, discount or comp it, or remove it (void it once sent).')) ?></li>
            <li><?= e(__('restaurant_help_pos_5', 'Send fires new lines to the kitchen display.')) ?></li>
        </ol>
        <h3><?= e(__('restaurant_help_pos_adjust', 'Split, discount and close')) ?></h3>
        <ul>
            <li><?= e(__('restaurant_help_pos_split', 'Split puts lines on separate checks (or one check per seat); each check is paid on its own. A check with a payment is locked. More → Merge brings another open check on the same table into this one.')) ?></li>
            <li><?= e(__('restaurant_help_pos_disc', 'Discount takes a percentage or an amount off the check or one line, with a reason.')) ?></li>
            <li><?= e(__('restaurant_help_pos_close', 'Close check only works once the check is paid. Managers can comp the balance (with a reason), comp single items, void a check, or reopen a paid or closed one.')) ?></li>
            <li><b><?= e(__('restaurant_pos_print_check', 'Print check')) ?></b> — <?= e(__('restaurant_help_print_check', 'prints the bill for the guest before they pay. Once paid, the button becomes Print receipt.')) ?></li>
        </ul>
        <h3><?= e(__('restaurant_kds_title', 'Kitchen display')) ?></h3>
        <p class="text-sm"><?= e(__('restaurant_help_kds', 'Each sent check is a ticket, oldest first, green, then amber after 10 minutes, red after 20. Tap a line when it is ready, Ready for the whole ticket, Served once it has gone out. Needs restaurant.kitchen.')) ?></p>
        <div class="note"><?= e(__('restaurant_help_86', 'Sold out? Press and hold the item in the register (or tick 86 on Menu items). It greys out everywhere, online too, until you put it back.')) ?></div>
    </div>

    <div class="card" id="pay">
        <h2>Payments &amp; card readers</h2>
        <h3>Cash</h3>
        <p class="text-sm">In the tender window choose <b>Cash</b>, optionally add a tip (preset % or custom), enter the amount tendered, and the register shows the change due, then records the payment and closes the check when it's covered.</p>
        <h3>Card (Stripe Terminal)</h3>
        <ol>
            <li>Make sure the <b>stripe-payment</b> plugin is configured and a reader is paired on the <a href="<?= e($url('readers.php')) ?>">Card readers</a> page.</li>
            <li>In the tender window choose <b>Card / Terminal</b>, pick the reader, and tap <b>Charge on reader</b>.</li>
            <li>The customer presents their card; the register polls until it's approved, declined, or you cancel. On approval the check closes automatically.</li>
        </ol>
        <p class="text-sm text-muted">In Stripe <b>test mode</b> you can create a <em>simulated</em> reader to run the whole flow without hardware. Card payments also settle via Stripe webhook, so a check still closes even if the browser is interrupted mid-charge.</p>
        <?php if (!RestaurantAPI::terminalAvailable()): ?>
            <div class="note">Stripe Terminal isn't available yet — configure the <b>stripe-payment</b> plugin to enable card payments. Cash works regardless.</div>
        <?php endif; ?>
    </div>

    <div class="card" id="refunds">
        <h2><?= e(__('restaurant_help_refunds_toc', 'Refunds & receipts')) ?></h2>
        <h3><?= e(__('restaurant_refunds', 'Refunds')) ?></h3>
        <ol>
            <li><?= e(__('restaurant_help_refund_1', 'Open the order (Orders → View, or Order details in the POS).')) ?></li>
            <li><?= e(__('restaurant_help_refund_2', 'In Payments, press Refund on the payment, keep or lower the amount, add a reason, and press Issue refund.')) ?></li>
            <li><?= e(__('restaurant_help_refund_3', 'Card payments go back to the card through Stripe. Cash refunds are recorded only: hand the cash back from the drawer.')) ?></li>
        </ol>
        <p class="text-sm"><?= e(__('restaurant_help_refund_4', 'A payment can be refunded in several parts, never for more than was paid. A refund lowers the order\'s paid total and does not reopen the check. Refunding needs the restaurant.manage_orders permission.')) ?></p>
        <h3><?= e(__('restaurant_rcpt_title', 'Receipt')) ?></h3>
        <p class="text-sm"><?= e(__('restaurant_help_receipt', 'Print receipt (in the POS on a paid check, or on the order page) opens a receipt sized for an 80 mm printer. It shows the business name and address, the receipt header and footer from Settings, every item with its options, the money breakdown, payments and refunds.')) ?></p>
    </div>

    <div class="card" id="combos">
        <h2><?= e(__('restaurant_combos', 'Combos')) ?></h2>
        <ol>
            <li><?= e(__('restaurant_help_combo_1', 'Create the combo as a menu item: its name, the combo price, tax and photo (Menu items → New item).')) ?></li>
            <li><?= e(__('restaurant_help_combo_2', 'In Combos → New combo, choose that item, then add slots: a name ("Sides"), how many to pick at least and at most, and the menu items to choose from. An "Extra" amount adds to the price for a premium pick.')) ?></li>
            <li><?= e(__('restaurant_help_combo_3', 'In the register and on the online menu the combo asks for its picks; it cannot be added until every slot has the right number. The picks go to the kitchen display and print on the receipt as "Sides: Mac & cheese".')) ?></li>
        </ol>
        <p class="text-sm"><?= e(__('restaurant_help_combo_4', 'A combo line is priced as the combo price plus any extras, and takes line discounts, comps, splits and kitchen bumps like any other line. A combo cannot hold itself or another combo. If a pick is 86\'d it shows as sold out; deleting an item removes it from every combo.')) ?></p>
    </div>

    <div class="card" id="reports">
        <h2><?= e(__('restaurant_help_reports_toc', 'Reports & payments')) ?></h2>
        <p class="text-sm"><?= e(__('restaurant_help_reports_1', 'Reports shows sales for today, yesterday, this or last week, this month or any range, each figure beside the period before with the change: gross sales, discounts, refunds, net sales, orders, average order, covers, tax, service charges, tips (cash and card), payment methods, top items and categories, POS against online, and online orders by status. Every table has a CSV button that downloads exactly that table.')) ?></p>
        <p class="text-sm"><?= e(__('restaurant_help_reports_2', 'An order counts once it is paid or closed, on the day it was opened; voided orders never count. Net sales are order totals (with tax, service and tips) less comps and refunds, and a refund counts on the day it was made.')) ?></p>
        <p class="text-sm"><?= e(__('restaurant_help_payments_1', 'Payments lists every payment with its order, check, method, amount, tip, refunds and Stripe reference. Filter one day and the card reader (or online card) method, and the Net total is what to set beside that day\'s Stripe payout; pending and failed attempts are listed but never counted. Export CSV takes the same filter.')) ?></p>
        <p class="text-sm"><?= e(__('restaurant_help_reports_perm', 'Both need the restaurant.reports permission.')) ?> <a href="<?= e($url('reports.php')) ?>"><?= e(__('restaurant_reports', 'Reports')) ?> →</a></p>
    </div>

    <div class="card" id="customers">
        <h2><?= e(__('restaurant_customers', 'Customers')) ?></h2>
        <p class="text-sm"><?= e(__('restaurant_help_cust_1', 'Each customer shows their orders, total spend (net of refunds and comps), average spend, last visit and first seen, worked out from their paid and closed orders. Sort by name, spend or last visit; a row opens to their latest orders.')) ?></p>
        <p class="text-sm"><?= e(__('restaurant_help_cust_2', 'Deleting a customer who has orders archives them instead: the orders keep their name and history, and they leave the POS guest lookup. Restore them from the Archived tab. A customer with no orders is deleted.')) ?></p>
        <h3><?= e(__('restaurant_help_cust_import_t', 'Import and export')) ?></h3>
        <ol>
            <li><?= e(__('restaurant_help_cust_import_1', 'Export your customer list from your old system as CSV with the columns Name, Member ID, Email, Phone, Avg. Spend, Total Spend, Last Visit, First Seen.')) ?></li>
            <li><?= e(__('restaurant_help_cust_import_2', 'Customers → Import CSV → Check file. You see how many customers will be created and updated, and every row that will be left out with its line number and the reason. Nothing is saved until you press Import.')) ?></li>
            <li><?= e(__('restaurant_help_cust_import_3', 'A row updates the customer with the same phone (however it is written), else the same Member ID; otherwise it creates one. Email is not used to match, because exports give many walk-in customers the same placeholder address. Rows that share a phone become one customer with their history added together. Importing the same file again updates instead of duplicating.')) ?></li>
        </ol>
        <p class="text-sm"><?= e(__('restaurant_help_cust_import_4', 'Imported spend and visits are history: they add to what Kohevo records, and the later last visit wins. Visits are Total Spend ÷ Avg. Spend. First Seen is kept as the channel the customer first came through (ORD Web, Counter, POS…), or as a date if the file has one. "-" and "N/A" count as empty; a blank name stays blank and the list shows the email, phone or member ID instead. Export CSV writes the same columns with everything known, ready for another system.')) ?></p>
    </div>

    <div class="card" id="channels">
        <h2><?= e(__('restaurant_channels', 'Channels')) ?></h2>
        <p class="text-sm"><?= e(__('restaurant_help_ch_1', 'A channel is an outside system that sends you orders, usually an aggregator such as Deliverect that collects them from marketplaces like DoorDash or Uber Eats under a contract you hold with it. Kohevo does not connect to the marketplaces directly: their order APIs are open only to approved partners.')) ?></p>
        <ol>
            <li><?= e(__('restaurant_help_ch_2', 'Channels → Add channel, then Make the signing secret. Give the channel its Order URL and the secret, then switch it on.')) ?></li>
            <li><?= e(__('restaurant_help_ch_3', 'Map every SKU the channel sends to a menu item, and each modifier SKU to one of that item\'s options. An order with an unmapped SKU is not accepted at all; staff are notified, and once the SKUs are mapped, Retry now creates it.')) ?></li>
            <li><?= e(__('restaurant_help_ch_4', 'Channel orders appear on Online orders with the channel\'s name. Each step (accepted, preparing, ready, completed, cancelled) is sent back to the channel, signed with the same secret. A card warns when the channel charged a different total from ours.')) ?></li>
        </ol>
        <p class="text-sm"><?= e(__('restaurant_help_ch_5', 'The menu feed gives a channel the whole menu as JSON (GET /menu-feed with the token from the Channels page). "Connected" shows only after a channel has sent an order with a correct signature. The order format is in the SOP (section 10).')) ?></p>

        <h3 id="deliverect"><?= e(__('restaurant_help_dl_h', 'Marketplace orders through Deliverect')) ?></h3>
        <p class="text-sm"><?= e(__('restaurant_help_dl_1', 'What you need: your own Deliverect contract for this location, with your marketplaces (Uber Eats, DoorDash and others) connected in Deliverect. Deliverect sends orders only to POS integrations it has registered. Until Kohevo\'s integration is certified by Deliverect, only Deliverect\'s staging (testing) environment works; ask Kohevo support whether production is available before you rely on it.')) ?></p>
        <ol>
            <li><?= e(__('restaurant_help_dl_2', 'Channels → Add channel, type Deliverect. In Deliverect, set the integration\'s order webhook to the Order URL shown on the channel\'s page.')) ?></li>
            <li><?= e(__('restaurant_help_dl_3', 'Paste the secret Deliverect signs orders with: in staging it is your channelLink ID; in production it is the HMAC secret from Deliverect\'s integration settings. Then switch the channel on.')) ?></li>
            <li><?= e(__('restaurant_help_dl_4', 'Under Status updates to Deliverect, choose the environment and enter the API client ID and secret Deliverect issued. Without them orders still arrive, but Deliverect does not hear that they were accepted, ready or completed.')) ?></li>
            <li><?= e(__('restaurant_help_dl_5', 'Kohevo does not send your menu to Deliverect: build it there and give every product and modifier a PLU. Send a test order from Deliverect; its PLUs appear in the SKU map. Map each one, then Retry now.')) ?></li>
        </ol>
        <p class="text-sm"><?= e(__('restaurant_help_dl_6', 'Each step goes back to Deliverect (accepted, preparing, ready, completed, cancelled). An order we cannot take is reported as Failed with the reason, so the marketplace hears of it. A marketplace cancellation voids the order here unless it is already completed. Delivery fees, service fees, tips and marketplace discounts stay with the marketplace: our total is the food and tax, and a card warns when it differs from what the marketplace charged. A modifier ordered twice is taken once and written on the line for the kitchen.')) ?></p>
        <p class="text-sm"><?= e(__('restaurant_help_dl_7', 'An order the marketplace has already collected payment for is recorded as paid via the channel, with any tip the marketplace passes on to you, so it shows as paid in the register and counts in reports. Do not take payment for it. Refunds for it are made in the marketplace; when the marketplace cancels, its payment stops counting.')) ?></p>
    </div>

    <div class="card" id="couriers">
        <h2><?= e(__('restaurant_couriers', 'Couriers')) ?></h2>
        <p class="text-sm"><?= e(__('restaurant_help_cr_1', 'For delivery orders you took yourself, a DoorDash Dasher can carry the order instead of your own driver; DoorDash bills you per delivery. You need your own DoorDash Developer account with Drive access. DoorDash currently limits Drive access and reviews each integration before real deliveries, so until then only its sandbox works; Kohevo has tested this against a stand-in, not DoorDash itself.')) ?></p>
        <ol>
            <li><?= e(__('restaurant_help_cr_2', 'Couriers: paste the Developer ID, Key ID and Signing secret from the DoorDash Developer Portal, pick Sandbox or Production to match, switch it on and press Check the key.')) ?></li>
            <li><?= e(__('restaurant_help_cr_3', 'Enter the restaurant phone for couriers, make the webhook address and paste it into the Developer Portal so courier updates reach Kohevo.')) ?></li>
            <li><?= e(__('restaurant_help_cr_4', 'On Online orders, a delivery card has Request DoorDash courier: it shows the price and times; Confirm courier within five minutes books it. The card and the customer\'s order page then show the courier\'s progress and a tracking link; a delivered order is marked completed.')) ?></li>
        </ol>
        <p class="text-sm"><?= e(__('restaurant_help_cr_5', 'If DoorDash refuses (outside its area, a missing phone), the reason shows on the card and the order is left as it was. Cancel courier works until a Dasher is assigned; after that cancel in DoorDash\'s merchant portal. Automatic requests, if switched on, apply to paid online delivery orders only, never to channel orders.')) ?></p>
        <p class="text-sm"><?= e(__('restaurant_help_cr_6', 'Uber Direct works the same way with an Uber courier: on Couriers, paste the Customer ID, Client ID and Client secret from the Uber Direct dashboard (and the webhook signing key, so only Uber\'s signed updates are read), switch it on and press Check the credentials; paste the Uber webhook address into the dashboard\'s webhooks. Delivery cards then also offer Request Uber courier. Uber approves each business first; Kohevo has tested this against a stand-in, not Uber itself.')) ?></p>
    </div>

    <div class="card" id="online">
        <h2>Online ordering (public storefront)</h2>
        <p class="text-sm">Customers order from your own site at <code><?= e(rtrim(SLATE_URL, '/')) ?>/order</code> — no SpotOn needed.</p>
        <h3>Turn it on</h3>
        <ol>
            <li>In <a href="<?= e($url('settings.php')) ?>">Settings → Online ordering</a>, tick <b>Enable online ordering</b>.</li>
            <li>Choose payment: <b>card online</b> (needs the stripe-payment plugin configured) and/or <b>pay at pickup</b>. Optionally offer <b>delivery</b>.</li>
            <li>Share the link <code><?= e(rtrim(SLATE_URL, '/')) ?>/order</code> (menu, social bio, Google profile, QR code).</li>
        </ol>
        <h3>Handling incoming orders</h3>
        <ul>
            <li>New orders land in <a href="<?= e($url('online.php')) ?>">Online orders</a> with the customer's contact, items, and paid/unpaid status.</li>
            <li><b>Send to kitchen</b> fires the ticket; then advance <b>Accepted → Preparing → Ready → Completed</b> as you go. <b>Cancel</b> if you can't fulfil it.</li>
            <li>Card orders are paid before they arrive (status <b>Paid</b>). Pay-at-pickup orders show <b>Due</b> — collect on the POS or at the counter.</li>
        </ul>
        <p class="text-sm">Only items marked active and not 86'd appear online. Sizes and add-ons show as choices, exactly like the in-house menu.</p>
    </div>

    <div class="card" id="settings">
        <h2>Settings reference</h2>
        <table class="kbd-table">
            <tr><td>Currency</td><td>Currency code used for all prices and receipts (e.g. <code>USD</code>).</td></tr>
            <tr><td>Default tax rate</td><td>Percentage applied per line. New menu items inherit it; you can override per item.</td></tr>
            <tr><td>Service charge</td><td>An optional percentage of the discounted subtotal. Turn on <em>auto-apply</em> to add it to every new check; the percent is snapshotted at open time.</td></tr>
            <tr><td>Tip presets</td><td>The quick-tip buttons in the tender window, as percentages (e.g. <code>15,18,20</code>).</td></tr>
            <tr><td>Receipt header / footer</td><td>Free text printed at the top and bottom of receipts.</td></tr>
            <tr><td>Business name &amp; address</td><td>Printed on receipts, and used to register the Stripe Terminal <b>Location</b> — required before pairing a card reader.</td></tr>
        </table>
        <p class="text-sm"><a href="<?= e($url('settings.php')) ?>">Edit settings →</a></p>
    </div>

    <div class="card" id="perms">
        <h2>Roles &amp; permissions</h2>
        <p class="text-sm text-muted">Grant these to roles under <b>Settings → Roles</b>. A super-admin has all of them.</p>
        <table class="kbd-table">
            <tr><td>restaurant.view</td><td>See orders, the menu, the floor, and the dashboard widget.</td></tr>
            <tr><td>restaurant.pos</td><td>Operate the POS register and take orders.</td></tr>
            <tr><td>restaurant.manage_menu</td><td><?= e(__('restaurant_help_perm_menu', 'Create and edit menu items, categories, modifier groups and combos.')) ?></td></tr>
            <tr><td>restaurant.manage_floor</td><td>Manage sections and tables.</td></tr>
            <tr><td>restaurant.manage_orders</td><td><?= e(__('restaurant_help_perm_orders2', 'Void, comp and reopen checks, comp items, and refund payments.')) ?></td></tr>
            <tr><td>restaurant.kitchen</td><td><?= e(__('restaurant_help_perm_kitchen', 'Use the kitchen display: mark lines ready and served.')) ?></td></tr>
            <tr><td>restaurant.manage_customers</td><td><?= e(__('restaurant_help_perm_customers', 'Manage customer records, archive and restore them, and import or export the customer list.')) ?></td></tr>
            <tr><td>restaurant.manage_settings</td><td>Configure tax, service charge, tips, receipts, and card readers.</td></tr>
            <tr><td>restaurant.channels</td><td><?= e(__('restaurant_help_perm_channels', 'Set up order channels, map their SKUs, retry orders that were not accepted, and manage the menu feed token.')) ?></td></tr>
            <tr><td>restaurant.reports</td><td><?= e(__('restaurant_help_perm_reports2', 'See Reports and Payments, and download their CSV files.')) ?></td></tr>
        </table>
    </div>

    <div class="card" id="faq">
        <h2>FAQ</h2>
        <h3>An item won't add to the check.</h3>
        <p class="text-sm">It's probably <b>86'd</b> (unavailable) or the check is already paid/closed. Un-86 it on its menu-item page, or start a new check.</p>
        <h3>The card button is disabled.</h3>
        <p class="text-sm">No Stripe Terminal reader is available. Configure the stripe-payment plugin and pair a reader; cash always works.</p>
        <h3>How is the total calculated?</h3>
        <p class="text-sm"><code>subtotal − discount + service charge + tax + tip</code>. Tax is summed per line from each item's tax rate; service charge is a percent of the discounted subtotal; tip is added at tender.</p>
        <h3>How do customers order online? What's the link?</h3>
        <p class="text-sm">Share <code><?= e(rtrim(SLATE_URL, '/')) ?>/order</code> — your public storefront. Customers browse the menu, build a cart, and pay by card (secure Stripe) or choose pay-at-pickup. Their orders appear under <a href="<?= e($url('online.php')) ?>">Online orders</a>. Turn it on (and pick payment options) in <a href="<?= e($url('settings.php')) ?>">Settings → Online ordering</a>. The staff POS URL is separate, requires a login, and must not be shared with customers.</p>
        <h3>Where are the operating procedures &amp; developer reference?</h3>
        <p class="text-sm">Staff <b>SOP</b>: <code>plugins/restaurant/docs/SOP.md</code> (opening, taking orders, payments, voids, 86-ing, end-of-day with reports and payments, customer import, troubleshooting). Developer reference: <code>plugins/restaurant/README.md</code> (data model, <code>RestaurantAPI</code>, roadmap).</p>
    </div>

</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
