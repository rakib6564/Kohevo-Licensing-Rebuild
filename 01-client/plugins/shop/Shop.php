<?php
/**
 * Shop plugin — bootstrap.
 *
 * Registers the admin nav (Products, Categories, Orders, Settings), a dashboard widget, FR/EN language packs and the
 * Stripe listener that marks an order paid. Schema is kept current by the idempotent ShopStoreAPI::ensureSchema()
 * self-heal, stamped per version.
 *
 * The storefront is Kohevo Studio: the widgets are declared in studio-widgets.php (Add › Components), a product page
 * is the Single template whose conditions name Products (`/product/{slug}`), and the cart, checkout and
 * order-received pages are Studio pages holding the Cart, Checkout and Order confirmation widgets. Those widgets show
 * per-visitor content through the endpoints in public/ (cart.php, checkout.php, return.php, order.php).
 */

require_once __DIR__ . '/ShopStoreAPI.php';

class Shop extends Plugin {

    public function boot(): void {
        if ((string) $this->setting('schema_verified', '') !== $this->version) {
            ShopStoreAPI::ensureSchema();
            $this->setSetting('schema_verified', $this->version);
        }

        Hook::addFilter('admin_nav_items',         [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);
        Hook::addFilter('i18n_lang_paths',         [$this, 'addLangPath']);

        // Studio widgets (Add › Components) are declared in studio-widgets.php.

        // Mark an order paid when its Stripe Checkout completes (routed through the stripe-payment plugin).
        Hook::addAction('stripe_webhook_event', [$this, 'onStripeEvent']);
    }

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    public function onStripeEvent(array $event): void {
        // Guard the effect, not the webhook receipt (the controller acknowledges Stripe either way).
        if (!ModuleGuard::allows('shop')) return;
        try {
            ShopStoreAPI::handleStripeEvent($event);
        } catch (\Throwable $e) {
            slate_log('Shop: stripe event handling failed: ' . $e->getMessage(), 'error');
        }
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('shop.view') && !Auth::isSuperAdmin()) return $items;
        $items[] = ['slug' => 'shop-orders', 'label' => __('shop_orders', 'Orders'),
                    'href' => $this->url('admin/orders.php'),
                    'icon' => 'shopping-bag', 'perm' => 'shop.view', 'order' => 510, 'group' => 'shop'];
        $items[] = ['slug' => 'shop-products', 'label' => __('shop_products', 'Products'),
                    'href' => $this->url('admin/products.php'),
                    'icon' => 'tag', 'perm' => 'shop.view', 'order' => 511, 'group' => 'shop'];
        $items[] = ['slug' => 'shop-categories', 'label' => __('shop_categories', 'Categories'),
                    'href' => $this->url('admin/categories.php'),
                    'icon' => 'layers', 'perm' => 'shop.manage_products', 'order' => 512, 'group' => 'shop'];
        $items[] = ['slug' => 'shop-settings', 'label' => __('shop_settings', 'Shop settings'),
                    'href' => $this->url('admin/settings.php'),
                    'icon' => 'settings', 'perm' => 'shop.manage_settings', 'order' => 518, 'group' => 'shop'];
        return $items;
    }

    /** Orders to fulfil and this month's paid total. */
    public function addDashboardWidget(array $widgets): array {
        if ((!Auth::can('shop.view') && !Auth::isSuperAdmin()) || !ModuleGuard::allows('shop')) return $widgets;
        $tid = current_tenant_id();
        try {
            ShopStoreAPI::ensureSchema();
            $toFulfil = (int) Database::value("SELECT COUNT(*) FROM shop_orders WHERE tenant_id = ? AND status = 'paid'", [$tid]);
            $month = Database::row(
                "SELECT COUNT(*) AS n, COALESCE(SUM(total_cents), 0) AS cents, MAX(currency) AS currency FROM shop_orders
                  WHERE tenant_id = ? AND status IN ('paid','fulfilled') AND paid_at >= ?", [$tid, date('Y-m-01 00:00:00')]) ?: [];
        } catch (\Throwable $e) {
            return $widgets;
        }
        $ordersUrl = $this->url('admin/orders.php');
        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('shop_orders', 'Orders') ?></h2>
                <a href="<?= e($ordersUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('shop_to_fulfil', 'To fulfil') ?></div>
                    <div class="dwidget-kpi-v"><?= $toFulfil ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('shop_sales_month', 'Sales this month') ?></div>
                    <div class="dwidget-kpi-v"><?= e(ShopStoreAPI::money((int) ($month['cents'] ?? 0), (string) ($month['currency'] ?? '') ?: null)) ?></div>
                </div>
            </div>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }
}
