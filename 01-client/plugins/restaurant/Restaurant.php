<?php
/**
 * Restaurant plugin — bootstrap.
 *
 * Menu (categories, items, modifier groups, combos), floor (sections,
 * tables), customers with history and CSV import / export, reports and a
 * payments reconciliation list, a browser POS register, card-present (Stripe
 * Terminal) + cash tender with refunds and printable receipts, and the
 * public /order storefront. Registers admin nav, a dashboard widget,
 * versioned schema migrations, and the Stripe webhook bridge so Terminal
 * PaymentIntent events settle local payments.
 *
 * Later features hang additive migrations + nav items off this same class.
 */

require_once __DIR__ . '/RestaurantAPI.php';
require_once __DIR__ . '/includes/channels.php';
require_once __DIR__ . '/includes/delivery.php';
require_once __DIR__ . '/includes/mail.php';

class Restaurant extends Plugin {

    public function boot(): void {
        // Versioned schema migrations (mirrors Booking/Shop). Base tables come
        // from install.sql / RestaurantAPI::ensureSchema(); this applies
        // additive changes to existing installs. We only stamp the version once
        // the expected columns exist, so a partial failure retries next request.
        $applied  = (string) $this->setting('applied_version', '0.0.0');
        $verified = (string) $this->setting('schema_verified', '');
        $needsUpgrade = version_compare($applied, $this->version, '<');
        if ($needsUpgrade || $verified !== $this->version) {
            $from = $needsUpgrade ? $applied : '0.0.0';
            $this->runMigrations($from);
            if ($this->schemaIsCurrent()) {
                $this->setSetting('applied_version', $this->version);
                $this->setSetting('schema_verified', $this->version);
            }
        }

        Hook::addFilter('admin_nav_items',         [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addAdminDashboardWidget']);
        Hook::addFilter('i18n_lang_paths',         [$this, 'addLangPath']);

        // Public online-ordering storefront at /order (Phase 3). Routed via
        // the core PublicRouter — no .htaccess change needed.
        Hook::addFilter('public_routes', [$this, 'addPublicRoutes']);

        // Order channels: tell a channel when its order's fulfilment moves.
        // Once per process: Hook keeps every registration, and a second boot would call the channel twice.
        static $channelsWired = false;
        if (!$channelsWired) {
            $channelsWired = true;
            Hook::addAction('restaurant_fulfillment_changed', [RestaurantChannels::class, 'fulfillmentChanged'], 10, 3);
            Hook::addAction('restaurant_order_paid', [RestaurantDelivery::class, 'onOrderPaid'], 10, 1);
        }
        // Order emails (customer confirmation, kitchen "new order", optional "ready"); wire() runs once per process.
        RestaurantMail::wire();

        // Payment listeners are registered once per process: PluginLoader
        // re-runs boot() after any plugin is switched on or off, and a second
        // registration would settle each payment twice.
        static $paymentsWired = false;
        if (!$paymentsWired) {
            $paymentsWired = true;
            // Settle Terminal/card payments routed through the stripe-payment plugin.
            Hook::addAction('stripe_webhook_event', [$this, 'onStripeEvent']);
            // Settle online orders paid through a hosted checkout (any gateway).
            Hook::addAction('payment_event', [$this, 'onPaymentEvent']);
        }
    }

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    /** Register the /order storefront prefix with the public router. */
    public function addPublicRoutes(array $routes): array {
        $routes['order'] = [
            'handler' => __DIR__ . '/storefront/router.php',
            'methods' => ['GET', 'POST'],
        ];
        // Channel order intake (signed POST) and the token-protected menu feed.
        $routes['order-channel'] = ['handler' => __DIR__ . '/channels/intake.php', 'methods' => ['POST']];
        $routes['menu-feed']     = ['handler' => __DIR__ . '/channels/feed.php', 'methods' => ['GET']];
        // Courier provider webhooks: /courier-webhook/<provider>/<token>.
        $routes['courier-webhook'] = ['handler' => __DIR__ . '/channels/courier-webhook.php', 'methods' => ['POST']];
        return $routes;
    }

    /** Mark a restaurant payment paid when its Stripe PaymentIntent succeeds. */
    public function onStripeEvent(array $event): void {
        // Guard the effect, not the webhook receipt (the controller acknowledges Stripe either way).
        if (!ModuleGuard::allows('restaurant')) return;
        try {
            RestaurantAPI::handleStripeEvent($event);
        } catch (\Throwable $e) {
            slate_log('Restaurant: stripe event handling failed: ' . $e->getMessage(), 'error');
        }
    }

    /** Mark an online order's payment paid (or failed) when its hosted checkout settles, at any gateway. */
    public function onPaymentEvent($event): void {
        if (!($event instanceof \Slate\Services\Payments\PaymentEvent) || !$event->isFor('restaurant')) return;
        if (!ModuleGuard::allows('restaurant')) return;
        try {
            RestaurantAPI::handlePaymentEvent($event);
        } catch (\Throwable $e) {
            slate_log('Restaurant: payment event handling failed: ' . $e->getMessage(), 'error');
        }
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('restaurant.view') && !Auth::isSuperAdmin()) return $items;

        $items[] = ['slug' => 'restaurant',            'label' => __('restaurant', 'Restaurant'),
                    'href' => $this->url('admin/index.php'),
                    'icon' => 'coffee', 'order' => 700, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-pos',        'label' => __('restaurant_pos', 'POS register'),
                    'href' => $this->url('admin/pos.php'),
                    'icon' => 'clipboard', 'perm' => 'restaurant.pos', 'order' => 700, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-kds',        'label' => __('restaurant_kds_title', 'Kitchen display'),
                    'href' => $this->url('admin/kds.php'),
                    'icon' => 'clock', 'perm' => 'restaurant.kitchen', 'order' => 700, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-orders',     'label' => __('restaurant_orders', 'Orders'),
                    'href' => $this->url('admin/orders.php'),
                    'icon' => 'list', 'perm' => 'restaurant.view', 'order' => 701, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-reports',    'label' => __('restaurant_reports', 'Reports'),
                    'href' => $this->url('admin/reports.php'),
                    'icon' => 'bar-chart-2', 'perm' => 'restaurant.reports', 'order' => 701, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-payments',   'label' => __('restaurant_payments', 'Payments'),
                    'href' => $this->url('admin/payments.php'),
                    'icon' => 'credit-card', 'perm' => 'restaurant.reports', 'order' => 701, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-online',     'label' => __('restaurant_online', 'Online orders'),
                    'href' => $this->url('admin/online.php'),
                    'icon' => 'shopping-bag', 'perm' => 'restaurant.view', 'order' => 702, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-items',      'label' => __('restaurant_items', 'Menu items'),
                    'href' => $this->url('admin/items.php'),
                    'icon' => 'tag', 'perm' => 'restaurant.manage_menu', 'order' => 702, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-combos',     'label' => __('restaurant_combos', 'Combos'),
                    'href' => $this->url('admin/combos.php'),
                    'icon' => 'box', 'perm' => 'restaurant.manage_menu', 'order' => 702, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-categories', 'label' => __('restaurant_categories', 'Categories'),
                    'href' => $this->url('admin/categories.php'),
                    'icon' => 'folder', 'perm' => 'restaurant.manage_menu', 'order' => 703, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-modifiers',  'label' => __('restaurant_modifiers', 'Modifier groups'),
                    'href' => $this->url('admin/modifiers.php'),
                    'icon' => 'list', 'perm' => 'restaurant.manage_menu', 'order' => 704, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-floor',      'label' => __('restaurant_floor', 'Floor & tables'),
                    'href' => $this->url('admin/floor.php'),
                    'icon' => 'box', 'perm' => 'restaurant.manage_floor', 'order' => 705, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-channels',   'label' => __('restaurant_channels', 'Channels'),
                    'href' => $this->url('admin/channels.php'),
                    'icon' => 'link', 'perm' => 'restaurant.channels', 'order' => 706, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-couriers',   'label' => __('restaurant_couriers', 'Couriers'),
                    'href' => $this->url('admin/couriers.php'),
                    'icon' => 'truck', 'perm' => 'restaurant.channels', 'order' => 706, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-customers',  'label' => __('restaurant_customers', 'Customers'),
                    'href' => $this->url('admin/customers.php'),
                    'icon' => 'user', 'perm' => 'restaurant.manage_customers', 'order' => 706, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-readers',    'label' => __('restaurant_readers', 'Card readers'),
                    'href' => $this->url('admin/readers.php'),
                    'icon' => 'credit-card', 'perm' => 'restaurant.manage_settings', 'order' => 707, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-settings',   'label' => __('restaurant_settings', 'Restaurant settings'),
                    'href' => $this->url('admin/settings.php'),
                    'icon' => 'settings', 'perm' => 'restaurant.manage_settings', 'order' => 708, 'group' => 'restaurant'];
        $items[] = ['slug' => 'restaurant-help',       'label' => __('restaurant_help', 'Help & docs'),
                    'href' => $this->url('admin/help.php'),
                    'icon' => 'help-circle', 'perm' => 'restaurant.view', 'order' => 709, 'group' => 'restaurant'];
        return $items;
    }

    public function addAdminDashboardWidget(array $widgets): array {
        if (!Auth::can('restaurant.view') || !ModuleGuard::allows('restaurant')) return $widgets;
        $tid = current_tenant_id();
        try {
            $sales = (int) Database::value(
                "SELECT COALESCE(SUM(total_cents),0) FROM restaurant_orders
                  WHERE tenant_id = ? AND status IN ('paid','closed')
                    AND DATE(opened_at) = CURDATE()", [$tid]);
            $open = (int) Database::value(
                "SELECT COUNT(*) FROM restaurant_orders
                  WHERE tenant_id = ? AND status IN ('open','sent')", [$tid]);
            $covers = (int) Database::value(
                "SELECT COALESCE(SUM(guest_count),0) FROM restaurant_orders
                  WHERE tenant_id = ? AND DATE(opened_at) = CURDATE()", [$tid]);
            $latest = Database::rows(
                "SELECT o.order_no, o.type, o.status, o.total_cents, o.opened_at, t.label AS table_label
                   FROM restaurant_orders o
                   LEFT JOIN restaurant_tables t ON t.id = o.table_id
                  WHERE o.tenant_id = ? ORDER BY o.id DESC LIMIT 5", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }
        $ordersUrl = $this->url('admin/index.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('restaurant', 'Restaurant') ?></h2>
                <a href="<?= e($ordersUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('restaurant_today_sales', "Today's sales") ?></div>
                    <div class="dwidget-kpi-v"><?= e(RestaurantAPI::money($sales)) ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('restaurant_open_checks', 'Open checks') ?></div>
                    <div class="dwidget-kpi-v"><?= $open ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('restaurant_covers', 'Covers') ?></div>
                    <div class="dwidget-kpi-v"><?= $covers ?></div>
                </div>
            </div>
            <?php if ($latest): ?>
                <div class="dlist">
                    <?php foreach ($latest as $o):
                        $colors = ['open' => 'warning', 'sent' => 'info', 'paid' => 'success',
                                   'closed' => 'muted', 'voided' => 'danger'];
                        $where = trim((string)($o['table_label'] ?? '')) !== ''
                            ? __('restaurant_table', 'Table') . ' ' . $o['table_label']
                            : ucfirst(str_replace('_', ' ', (string)$o['type']));
                        slate_dlist_row([
                            'avatar'       => (string)$o['order_no'],
                            'avatar_color' => $colors[$o['status']] ?? 'muted',
                            'title'        => '#' . $o['order_no'],
                            'sub'          => $where . ' · ' . ucfirst((string)$o['status']),
                            'amount'       => RestaurantAPI::money((int)$o['total_cents']),
                            'time'         => $o['opened_at'] ? date('g:ia', strtotime($o['opened_at'])) : '',
                            'href'         => $ordersUrl,
                        ]);
                    endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dlist-empty"><?= __('restaurant_no_orders', 'No orders yet') ?></div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ──────────────────────────────────────────────────────
    // Migrations
    // ──────────────────────────────────────────────────────

    private function runMigrations(string $from): void {
        // Step 1 — new tables via .sql migration files (none yet in Phase 1).
        $dir = $this->dir('migrations');
        if (is_dir($dir)) {
            $files = glob($dir . '/*.sql');
            if ($files) {
                sort($files);
                foreach ($files as $file) {
                    $target = basename($file, '.sql');
                    if (version_compare($target, $from, '>')) {
                        $this->runSqlFile($file, $target);
                    }
                }
            }
        }

        // Step 2 — guarantee the base schema exists (fresh installs whose
        // install.sql never ran, or were half-applied). Idempotent.
        RestaurantAPI::ensureSchema();

        // Phase 3 — online ordering: additive columns on existing installs.
        $this->ensureColumn('restaurant_orders', 'public_token', "VARCHAR(40) NULL");
        $this->ensureColumn('restaurant_orders', 'fulfillment_status',
            "ENUM('new','accepted','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'new'");
        $this->ensureColumn('restaurant_orders', 'requested_time', "VARCHAR(40) NULL");
        $this->ensureColumn('restaurant_payments', 'stripe_session_id', "VARCHAR(120) NULL");
        $this->ensureIndex('restaurant_orders', 'public_token', '(`public_token`)');

        // 0.3.0 — refunds. The restaurant_refunds table comes from install.sql
        // through ensureSchema() above; these are the columns it leans on.
        $this->ensureColumn('restaurant_payments', 'refunded_cents', "INT UNSIGNED NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_payments', 'central_charge_id', "INT UNSIGNED NULL");
        $this->ensureColumn('restaurant_orders', 'refunded_cents', "INT UNSIGNED NOT NULL DEFAULT 0");

        // 0.4.0 — POS completeness: line and percentage discounts with a
        // reason, comp, kitchen timestamps, cash tendered and change.
        $hadFixed = $this->hasColumn('restaurant_orders', 'discount_fixed_cents');
        $this->ensureColumn('restaurant_orders', 'discount_fixed_cents', "INT UNSIGNED NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_orders', 'discount_pct', "DECIMAL(6,3) NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_orders', 'discount_reason', "VARCHAR(160) NULL");
        $this->ensureColumn('restaurant_orders', 'comp_cents', "INT UNSIGNED NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_orders', 'comp_reason', "VARCHAR(160) NULL");
        if (!$hadFixed && $this->hasColumn('restaurant_orders', 'discount_fixed_cents')) {
            // Before 0.4.0 discount_cents was both the amount typed in and the
            // amount applied; it becomes the typed-in fixed amount.
            try {
                Database::query("UPDATE restaurant_orders SET discount_fixed_cents = discount_cents WHERE discount_cents > 0");
            } catch (\Throwable $e) {
                slate_log('Restaurant 0.4.0 discount backfill failed: ' . $e->getMessage(), 'error');
            }
        }
        $this->ensureColumn('restaurant_order_items', 'discount_cents', "INT UNSIGNED NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_order_items', 'discount_fixed_cents', "INT UNSIGNED NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_order_items', 'discount_pct', "DECIMAL(6,3) NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_order_items', 'discount_reason', "VARCHAR(160) NULL");
        $this->ensureColumn('restaurant_order_items', 'sent_at', "DATETIME NULL");
        $this->ensureColumn('restaurant_order_items', 'ready_at', "DATETIME NULL");
        $this->ensureColumn('restaurant_order_items', 'served_at', "DATETIME NULL");
        $this->ensureColumn('restaurant_payments', 'tendered_cents', "INT UNSIGNED NULL");
        $this->ensureColumn('restaurant_payments', 'change_cents', "INT UNSIGNED NOT NULL DEFAULT 0");

        // 0.5.0 — combos, customer history and import. The combo tables come
        // from install.sql through ensureSchema() above. Existing customers
        // start active with no imported history; customers deleted before
        // 0.5.0 already had their orders unlinked, which cannot be undone.
        $this->ensureColumn('restaurant_items', 'is_combo', "TINYINT(1) NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_order_item_modifiers', 'kind', "ENUM('modifier','component') NOT NULL DEFAULT 'modifier'");
        $this->ensureColumn('restaurant_order_item_modifiers', 'slot_id', "INT UNSIGNED NULL");
        $this->ensureColumn('restaurant_order_item_modifiers', 'component_item_id', "INT UNSIGNED NULL");
        $this->ensureColumn('restaurant_customers', 'member_ref', "VARCHAR(80) NULL");
        $this->ensureColumn('restaurant_customers', 'first_seen_via', "VARCHAR(60) NULL");
        $this->ensureColumn('restaurant_customers', 'hist_spend_cents', "INT UNSIGNED NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_customers', 'hist_orders', "INT UNSIGNED NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_customers', 'hist_first_seen', "DATE NULL");
        $this->ensureColumn('restaurant_customers', 'hist_last_visit', "DATE NULL");
        $this->ensureColumn('restaurant_customers', 'is_active', "TINYINT(1) NOT NULL DEFAULT 1");
        $this->ensureColumn('restaurant_customers', 'archived_at', "DATETIME NULL");
        $this->ensureIndex('restaurant_customers', 'tenant_member', '(`tenant_id`, `member_ref`)');
        $this->ensureIndex('restaurant_orders', 'tenant_customer', '(`tenant_id`, `customer_id`, `status`)');
        $this->ensureIndex('restaurant_payments', 'tenant_created', '(`tenant_id`, `created_at`)');

        // 0.7.0 — online orders pay through the shared payment layer (Stripe,
        // PayPal, ...): which gateway took the payment and its checkout ref.
        RestaurantAPI::ensurePaymentColumns();

        // 0.5.1 — channel intake: the channel tables come from install.sql
        // through ensureSchema(); orders learn the 'channel' source. Widening
        // the ENUM keeps every existing value.
        try {
            $type = (string) Database::value(
                "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restaurant_orders' AND COLUMN_NAME = 'source'");
            if ($type !== '' && !str_contains($type, "'channel'")) {
                Database::query("ALTER TABLE `restaurant_orders` MODIFY `source` ENUM('pos','online','channel') NOT NULL DEFAULT 'pos'");
            }
        } catch (\Throwable $e) {
            slate_log('Restaurant 0.5.1 source widen failed: ' . $e->getMessage(), 'error');
        }
        $this->ensureColumn('restaurant_orders', 'channel', "VARCHAR(40) NULL");
        $this->ensureColumn('restaurant_orders', 'external_ref', "VARCHAR(80) NULL");
        $this->ensureColumn('restaurant_orders', 'flag_reason', "VARCHAR(255) NULL");

        // 0.5.2 — channel types (the Deliverect adapter), their settings,
        // cancellations sent by a channel, and whether it collected payment.
        $this->ensureColumn('restaurant_channels', 'kind', "VARCHAR(40) NULL");
        $this->ensureColumn('restaurant_channels', 'config', "TEXT NULL");
        $this->ensureColumn('restaurant_channel_orders', 'cancelled_at', "DATETIME NULL");
        $this->ensureColumn('restaurant_channel_orders', 'channel_paid', "TINYINT(1) NULL");
        // A channel that collected payment is recorded as a 'channel' payment.
        foreach (['restaurant_payments', 'restaurant_refunds'] as $tbl) {
            try {
                $type = (string) Database::value(
                    "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'method'", [$tbl]);
                if ($type !== '' && !str_contains($type, "'channel'")) {
                    Database::query("ALTER TABLE `{$tbl}` MODIFY `method` ENUM('cash','terminal','other','channel') NOT NULL DEFAULT 'cash'");
                }
            } catch (\Throwable $e) {
                slate_log("Restaurant 0.5.2 {$tbl}.method widen failed: " . $e->getMessage(), 'error');
            }
        }

        // 0.8.0 — order emails (each sent once: a stamp per email) and pre-order menus on a category.
        $this->ensureColumn('restaurant_orders', 'contact_email', "VARCHAR(200) NULL");
        $this->ensureColumn('restaurant_orders', 'customer_emailed_at', "DATETIME NULL");
        $this->ensureColumn('restaurant_orders', 'kitchen_emailed_at', "DATETIME NULL");
        $this->ensureColumn('restaurant_orders', 'ready_emailed_at', "DATETIME NULL");
        $this->ensureColumn('restaurant_menu_categories', 'preorder', "TINYINT(1) NOT NULL DEFAULT 0");
        $this->ensureColumn('restaurant_menu_categories', 'preorder_from', "DATETIME NULL");
        $this->ensureColumn('restaurant_menu_categories', 'preorder_until', "DATETIME NULL");
        $this->ensureColumn('restaurant_menu_categories', 'preorder_dates', "TEXT NULL");
        $this->ensureColumn('restaurant_menu_categories', 'preorder_note', "VARCHAR(255) NULL");
    }

    private function hasColumn(string $table, string $column): bool {
        try {
            return (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function schemaIsCurrent(): bool {
        try {
            $tbl = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ('restaurant_items','restaurant_orders',
                                       'restaurant_order_items','restaurant_payments',
                                       'restaurant_refunds','restaurant_combo_slots',
                                       'restaurant_combo_choices','restaurant_channels',
                                       'restaurant_channel_orders','restaurant_item_mappings',
                                       'restaurant_delivery_jobs')");
            $col = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND ((TABLE_NAME = 'restaurant_payments' AND COLUMN_NAME IN ('central_charge_id','tendered_cents'))
                      OR (TABLE_NAME = 'restaurant_customers' AND COLUMN_NAME IN ('member_ref','is_active'))
                      OR (TABLE_NAME = 'restaurant_order_item_modifiers' AND COLUMN_NAME = 'kind')
                      OR (TABLE_NAME = 'restaurant_orders' AND COLUMN_NAME = 'channel')
                      OR (TABLE_NAME = 'restaurant_channels' AND COLUMN_NAME = 'config')
                      OR (TABLE_NAME = 'restaurant_channel_orders' AND COLUMN_NAME IN ('cancelled_at','channel_paid'))
                      OR (TABLE_NAME = 'restaurant_orders' AND COLUMN_NAME IN ('contact_email','customer_emailed_at','kitchen_emailed_at','ready_emailed_at'))
                      OR (TABLE_NAME = 'restaurant_menu_categories' AND COLUMN_NAME IN ('preorder','preorder_from','preorder_until','preorder_dates','preorder_note')))");
            return $tbl >= 11 && $col === 18;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function runSqlFile(string $file, string $tag): void {
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '') continue;
            try {
                Database::query($stmt);
            } catch (\Throwable $e) {
                slate_log("Restaurant migration {$tag} statement error: " . $e->getMessage(), 'error');
            }
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("Restaurant ensureColumn {$table}.{$column} failed: " . $e->getMessage(), 'error');
        }
    }

    private function ensureIndex(string $table, string $indexName, string $columnSpec): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                [$table, $indexName]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` {$columnSpec}");
            }
        } catch (\Throwable $e) {
            slate_log("Restaurant ensureIndex {$table}.{$indexName} failed: " . $e->getMessage(), 'error');
        }
    }
}
