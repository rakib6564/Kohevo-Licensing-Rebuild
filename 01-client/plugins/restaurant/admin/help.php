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
            <p class="page-header-sub">How to run the menu, the floor, the POS register, and card payments. Version <?= e((string) RestaurantAPI::setting('applied_version', '0.1.0')) ?>.</p>
        </div>
        <a href="<?= e($url('pos.php')) ?>" class="btn btn-primary">Open POS register</a>
    </div>

    <div class="card">
        <div class="toc">
            <a href="#start">Getting started</a>
            <a href="#pos">Using the POS</a>
            <a href="#pay">Payments &amp; card readers</a>
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
            <li><b>Menu items</b> — name, price, tax, category, photo, daypart, and which modifier groups apply. <a href="<?= e($url('items.php')) ?>">Menu items →</a></li>
            <li><b>Floor &amp; tables</b> — for dine-in: create sections and the tables in them. <a href="<?= e($url('floor.php')) ?>">Floor &amp; tables →</a></li>
            <li><b>Card readers</b> — optional. Pair a Stripe Terminal reader to take cards in person. <a href="<?= e($url('readers.php')) ?>">Card readers →</a></li>
        </ol>
        <p class="text-sm">You can take orders and cash payments the moment a category and an item exist — card readers and the floor are optional.</p>
    </div>

    <div class="card" id="pos">
        <h2>Using the POS register</h2>
        <p class="text-sm text-muted">The register has three panes: <b>open checks</b> (left), the <b>menu</b> (middle), and the <b>current check</b> (right).</p>
        <h3>Take an order</h3>
        <ol>
            <li>Click <b>+ New check</b>, choose the type — <em>Dine in</em> (pick a table), <em>Takeout</em>, or <em>Delivery</em> — and the guest count.</li>
            <li>Tap menu items to add them. If an item has required options, a window asks you to choose before it's added; add a note (e.g. <em>no onions</em>) there too.</li>
            <li>Use the <code>−</code> / <code>+</code> steppers to change quantity, or <code>×</code> to remove a line that hasn't been sent.</li>
            <li><b>Send to kitchen</b> fires the new lines (they lock from deletion — void instead).</li>
        </ol>
        <h3>Adjust &amp; close</h3>
        <ul>
            <li><b>Discount</b> — enter a dollar amount off the check.</li>
            <li><b>Pay</b> — opens the tender window for cash or card (see below).</li>
            <li><b>Void check</b> — cancels the whole check (needs the <em>void</em> permission).</li>
            <li><b>Close check</b> — closes it without further payment.</li>
        </ul>
        <div class="note">An <b>86'd</b> item (marked unavailable on its menu-item page) shows greyed out and can't be added until you un-86 it.</div>
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
        <p class="text-sm">Only items marked active and not 86'd appear online. Sizes (Piglet/Porker/…) and add-ons show as choices, exactly like the in-house menu.</p>
    </div>

    <div class="card" id="settings">
        <h2>Settings reference</h2>
        <table class="kbd-table">
            <tr><td>Currency</td><td>Currency code used for all prices and receipts (e.g. <code>USD</code>).</td></tr>
            <tr><td>Default tax rate</td><td>Percentage applied per line. New menu items inherit it; you can override per item.</td></tr>
            <tr><td>Service charge</td><td>An optional percentage of the discounted subtotal. Turn on <em>auto-apply</em> to add it to every new check; the percent is snapshotted at open time.</td></tr>
            <tr><td>Tip presets</td><td>The quick-tip buttons in the tender window, as percentages (e.g. <code>15,18,20</code>).</td></tr>
            <tr><td>Receipt header / footer</td><td>Free text printed at the top and bottom of receipts.</td></tr>
            <tr><td>Business name &amp; address</td><td>Used to register the Stripe Terminal <b>Location</b> — required before pairing a card reader.</td></tr>
        </table>
        <p class="text-sm"><a href="<?= e($url('settings.php')) ?>">Edit settings →</a></p>
    </div>

    <div class="card" id="perms">
        <h2>Roles &amp; permissions</h2>
        <p class="text-sm text-muted">Grant these to roles under <b>Settings → Roles</b>. A super-admin has all of them.</p>
        <table class="kbd-table">
            <tr><td>restaurant.view</td><td>See orders, the menu, the floor, and the dashboard widget.</td></tr>
            <tr><td>restaurant.pos</td><td>Operate the POS register and take orders.</td></tr>
            <tr><td>restaurant.manage_menu</td><td>Create / edit menu items, categories, and modifiers.</td></tr>
            <tr><td>restaurant.manage_floor</td><td>Manage sections and tables.</td></tr>
            <tr><td>restaurant.manage_orders</td><td>Void / comp / reopen checks and issue refunds.</td></tr>
            <tr><td>restaurant.manage_customers</td><td>Manage customer records.</td></tr>
            <tr><td>restaurant.manage_settings</td><td>Configure tax, service charge, tips, receipts, and card readers.</td></tr>
            <tr><td>restaurant.reports</td><td>View reports (coming in a later phase).</td></tr>
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
        <p class="text-sm">Staff <b>SOP</b>: <code>plugins/restaurant/docs/SOP.md</code> (opening, taking orders, payments, voids, 86-ing, end-of-day, troubleshooting). Developer reference: <code>plugins/restaurant/README.md</code> (data model, <code>RestaurantAPI</code>, roadmap).</p>
    </div>

</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
