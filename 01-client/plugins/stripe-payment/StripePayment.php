<?php
/**
 * Stripe Payment Gateway — bootstrap.
 *
 * Loads StripePaymentAPI (the Stripe transport, ledger wrappers and the
 * StripeGateway it registers with the shared payment layer), the StripeAPI
 * key shim and StripeTerminalAPI, runs the schema upgrade, and adds the
 * admin nav, dashboard widget and MCP handler. Plugins take payments
 * through Payments (src/Services/Payments); Stripe-only features (Terminal
 * readers, the on-page Payment Element) stay on StripePaymentAPI.
 *
 * The old Shop checkout providers (hosted + embedded, with their
 * stripepayment_sessions mapping) were removed in 1.3.0: they needed the
 * archived ShopAPI class and could no longer run. The table is kept for
 * existing data and dropped on uninstall.
 */

class StripePayment extends Plugin {

    public function boot(): void {
        // Generic facade first (StripeAPI delegates to it). Order matters
        // because StripeAPI's require_once depends on this file.
        $genericApi = $this->dir('StripePaymentAPI.php');
        if (file_exists($genericApi)) require_once $genericApi;

        $apiFile = $this->dir('StripeAPI.php');
        if (file_exists($apiFile)) require_once $apiFile;

        // Card-present (Terminal) helpers — additive, depends on the
        // generic API above. Reusable by any plugin (e.g. restaurant POS).
        $terminalApi = $this->dir('StripeTerminalAPI.php');
        if (file_exists($terminalApi)) require_once $terminalApi;

        $applied = $this->setting('applied_version', '0.0.0');
        if (version_compare($applied, $this->version, '<')) {
            $this->runMigrations();
            if ($this->schemaIsCurrent()) {
                $this->setSetting('applied_version', $this->version);
            }
        }

        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);
        Hook::addFilter('i18n_lang_paths', [$this, 'addLangPath']);

        // MCP AI Gateway integration (read-only — no refunds, no key changes)
        require_once __DIR__ . '/StripePaymentMcpHandler.php';
        StripePaymentMcpHandler::register();

        // public/webhook.php verifies each delivery and hands it to the
        // payment layer (payment_event) and to stripe_webhook_event
        // listeners; no plugin needs its own endpoint.
    }

    // ── Admin nav ─────────────────────────────────────────────

    public function addAdminNav(array $items): array {
        // Either permission earns a look at this block — the per-item
        // 'perm' below then shows only the one item each caller actually
        // holds. A settings-only or charges-only role must still see its
        // one nav entry; only holding neither hides both.
        if (!Auth::can('stripe.manage_settings') && !Auth::can('stripe.manage_charges') && !Auth::isSuperAdmin()) return $items;

        // Stripe settings live under SHOP if Shop is active (where most
        // users find them), otherwise under SYSTEM. Charges are always
        // their own item — they span multiple plugins.
        $shopActive = PluginLoader::isActive('shop');

        $items[] = [
            'slug'   => 'shop-stripe',
            // Named for the gateway now that core has a "Payment gateways" page.
            'label'  => __('stripe_nav_settings', 'Stripe'),
            'href'   => $this->url('admin/settings.php'),
            'icon'   => 'credit-card',
            'perm'   => 'stripe.manage_settings',
            'order'  => $shopActive ? 519 : 720,
            'group'  => $shopActive ? 'shop' : 'system',
        ];
        // admin/charges.php renders core's cross-gateway Transactions page
        // behind this plugin's own permission; header.php shows only one of
        // the two entries.
        $items[] = [
            'slug'   => 'shop-stripe-charges',
            'label'  => __('stripe_charges', 'Transaction history'),
            'href'   => $this->url('admin/charges.php'),
            'icon'   => 'bar-chart-3',
            'perm'   => 'stripe.manage_charges',
            'order'  => $shopActive ? 521 : 721,
            'group'  => $shopActive ? 'shop' : 'system',
        ];
        return $items;
    }

    // ── Dashboard widget ──────────────────────────────────────

    /** Recent charge activity. Only registered while the plugin is active. */
    public function addDashboardWidget(array $widgets): array {
        if (!Auth::can('stripe.manage_charges') && !Auth::isSuperAdmin()) return $widgets;
        $tid = current_tenant_id();
        try {
            $todayCount = (int) Database::value(
                "SELECT COUNT(*) FROM stripepayment_charges
                  WHERE tenant_id = ? AND status IN ('succeeded','partial_refund')
                    AND DATE(created_at) = CURDATE()", [$tid]);
            $todayVol = (int) Database::value(
                "SELECT COALESCE(SUM(amount_cents - refunded_cents),0) FROM stripepayment_charges
                  WHERE tenant_id = ? AND status <> 'failed'
                    AND DATE(created_at) = CURDATE()", [$tid]);
            $totalCount = (int) Database::value(
                "SELECT COUNT(*) FROM stripepayment_charges
                  WHERE tenant_id = ? AND status IN ('succeeded','partial_refund','refunded')", [$tid]);
            $cur = (string) (Database::value(
                "SELECT currency FROM stripepayment_charges WHERE tenant_id = ? ORDER BY id DESC LIMIT 1", [$tid]) ?: slate_default_currency());
            $latest = Database::rows(
                "SELECT amount_cents, refunded_cents, currency, customer_email, status, created_at
                   FROM stripepayment_charges WHERE tenant_id = ? ORDER BY id DESC LIMIT 5", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }
        $volStr = slate_format_price_plain((int) $todayVol, $cur);
        $chargesUrl = $this->url('admin/charges.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('stripe_charges', 'Stripe') ?></h2>
                <a href="<?= e($chargesUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('stripe_volume_today', 'Volume today') ?></div>
                    <div class="dwidget-kpi-v"><?= e($volStr) ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('stripe_total_charges', 'Total charges') ?></div>
                    <div class="dwidget-kpi-v"><?= $totalCount ?></div>
                </div>
            </div>
            <?php if ($latest): ?>
                <div class="dlist">
                    <?php foreach ($latest as $c):
                        $netCents = (int)$c['amount_cents'] - (int)$c['refunded_cents'];
                        $email = (string)($c['customer_email'] ?? '');
                        $colors = ['succeeded' => 'success', 'refunded' => 'muted', 'partial_refund' => 'warning', 'failed' => 'danger'];
                        slate_dlist_row([
                            'avatar_html'  => slate_avatar_overlay_html($email !== '' ? mb_substr($email, 0, 2) : '?', $email),
                            'avatar_color' => $colors[$c['status']] ?? 'muted',
                            'title'        => $email !== '' ? $email : __('stripe_guest', 'Guest'),
                            'sub'          => ucfirst(str_replace('_', ' ', (string)$c['status'])),
                            'amount'       => slate_format_price_plain($netCents, $c['currency']),
                            'time'         => $c['created_at'] ? I18n::localDate('M j', strtotime($c['created_at'])) : '',
                            'href'         => $chargesUrl,
                        ]);
                    endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dlist-empty"><?= __('stripe_no_charges', 'No charges yet') ?></div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ── Provider registration ─────────────────────────────────

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    // ── Schema ────────────────────────────────────────────────

    private function runMigrations(): void {
        // v1.0.0 — initial table
        try {
            Database::query("
                CREATE TABLE IF NOT EXISTS stripepayment_sessions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    stripe_session_id VARCHAR(255) NULL,
                    shop_sid          VARCHAR(64)  NOT NULL,
                    order_id          INT UNSIGNED NULL,
                    status            VARCHAR(20)  NOT NULL DEFAULT 'pending',
                    billing_json      TEXT NULL,
                    created_at        DATETIME NOT NULL,
                    completed_at      DATETIME NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_stripe_session_id (stripe_session_id),
                    KEY idx_shop_sid (shop_sid),
                    KEY idx_status   (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (\Throwable $e) {
            slate_log('stripe-payment migration (create table) failed: ' . $e->getMessage(), 'error');
        }

        // v1.1.0 — add payment_intent_id (embedded flow) and flow column.
        // stripe_session_id becomes nullable: embedded-only rows don't
        // have a Checkout Session id.
        $this->ensureColumn('stripepayment_sessions', 'payment_intent_id',
            "VARCHAR(255) NULL AFTER stripe_session_id");
        $this->ensureColumn('stripepayment_sessions', 'flow',
            "VARCHAR(16) NOT NULL DEFAULT 'hosted' AFTER shop_sid");
        $this->ensureIndex('stripepayment_sessions', 'uniq_payment_intent_id',
            'UNIQUE (payment_intent_id)');

        // The original create-table made stripe_session_id NOT NULL.
        // For new installs (table just created) we already used NULL.
        // For existing 1.0.0/1.0.1 installs we need to relax it.
        try {
            Database::query(
                "ALTER TABLE stripepayment_sessions
                 MODIFY stripe_session_id VARCHAR(255) NULL"
            );
        } catch (\Throwable $e) {
            // Already nullable? — silent.
            slate_log('stripe-payment migration (relax NOT NULL) note: ' . $e->getMessage(), 'info');
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
            slate_log("stripe-payment ensureColumn $table.$column: " . $e->getMessage(), 'error');
        }
    }

    private function ensureIndex(string $table, string $indexName, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                [$table, $indexName]
            );
            if ((int)$exists === 0) {
                Database::query("ALTER TABLE `{$table}` ADD {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("stripe-payment ensureIndex $table.$indexName: " . $e->getMessage(), 'error');
        }
    }

    private function schemaIsCurrent(): bool {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stripepayment_sessions'"
            );
            if ((int)$exists === 0) return false;
            foreach (['payment_intent_id', 'flow'] as $col) {
                $e = Database::value(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stripepayment_sessions'
                        AND COLUMN_NAME = ?",
                    [$col]
                );
                if ((int)$e === 0) return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
