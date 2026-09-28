<?php
/**
 * Booking plugin — bootstrap.
 *
 * Registers admin nav, dashboard widget, public route, customer
 * dashboard widget (upcoming + past appointments), and a cron
 * handler for 24h + 1h reminder emails.
 */

require_once __DIR__ . '/BookingAPI.php';
require_once __DIR__ . '/BookingPlusAPI.php';
require_once __DIR__ . '/GoogleCalendarSync.php';

class Booking extends Plugin {

    public function boot(): void {
        // Versioned schema migrations (mirrors the Shop plugin). The base
        // tables come from install.sql / BookingAPI::ensureSchema(); this
        // applies additive changes to existing installs. We only stamp the
        // version once the expected columns exist, so a partial failure
        // retries next request instead of silently sticking.
        $applied  = (string) $this->setting('applied_version', '0.0.0');
        $verified = (string) $this->setting('schema_verified', '');
        $needsUpgrade = version_compare($applied, $this->version, '<');
        // Self-heal: if the version was already stamped (applied == version)
        // but the schema is incomplete — e.g. a prior build stamped the
        // version before a column existed, or schemaIsCurrent() used to be
        // too lenient — `schema_verified` won't match and we force a full
        // additive pass from 0.0.0. Every ensureColumn()/ensureIndex() is
        // idempotent, so replaying them is safe. Once verified, the fast
        // path below skips this entirely on subsequent requests.
        if ($needsUpgrade || $verified !== $this->version) {
            $from = $needsUpgrade ? $applied : '0.0.0';
            $this->runMigrations($from);
            if ($this->schemaIsCurrent()) {
                $this->setSetting('applied_version', $this->version);
                $this->setSetting('schema_verified', $this->version);
            }
        }

        // Schema self-heal for native modular capabilities
        BookingPlusAPI::ensureSchema();

        Hook::addFilter('admin_nav_items',           [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets',   [$this, 'addAdminDashboardWidget']);
        Hook::addFilter('customer_dashboard_widgets',[$this, 'addCustomerDashboardWidget']);
        Hook::addFilter('customer_dashboard_kpis',   [$this, 'addCustomerDashboardKpi']);
        Hook::addFilter('customer_nav_items',        [$this, 'addCustomerNav']);
        Hook::addFilter('customer_portal_activity',   [$this, 'addCustomerActivity'], 10, 2);
        Hook::addFilter('public_routes',             [$this, 'addPublicRoutes']);
        Hook::addFilter('i18n_lang_paths',           [$this, 'addLangPath']);
        Hook::addAction('frequent_cron',             [$this, 'sendReminders']);

        // React to Stripe payments routed through the stripe-payment plugin.
        Hook::addAction('stripe_webhook_event', [$this, 'onStripeEvent']);

        // Google Calendar 2-way sync — push on every appointment change,
        // pull (+ watch-channel renewal + failed-push retries) on every
        // cron tick. Every listener no-ops immediately when the feature
        // isn't configured/enabled (see GoogleCalendarSync::isEnabled()),
        // so this costs nothing on installs that don't use it.
        Hook::addAction('booking_created',     [GoogleCalendarSync::class, 'syncCreated']);
        Hook::addAction('booking_cancelled',   [GoogleCalendarSync::class, 'syncCancelled']);
        Hook::addAction('booking_rescheduled', [GoogleCalendarSync::class, 'syncRescheduled']);
        Hook::addAction('frequent_cron',       [GoogleCalendarSync::class, 'runCron']);

        // Modular capabilities integration
        if ($this->isCapabilityEnabled('client_messaging')) {
            Hook::addAction('booking_created', [$this, 'onBookingCreatedMessage'], 20, 3);
            Hook::addAction('frequent_cron',   [$this, 'runMessagingNudgeCron']);
        }
        if ($this->isCapabilityEnabled('service_rules')) {
            Hook::addFilter('booking_can_book', [$this, 'gateBookingRules'], 20, 2);
        }
        if ($this->isCapabilityEnabled('slot_restrictions')) {
            Hook::addFilter('booking_slot_allowed', [$this, 'applySlotRestriction'], 10, 6);
        }
        if ($this->isCapabilityEnabled('custom_reminders')) {
            Hook::addFilter('booking_reminder_body', [$this, 'overrideReminderBody'], 10, 3);
            Hook::addFilter('booking_default_reminder_leads', [BookingPlusAPI::class, 'defaultReminderLeads'], 10, 1);
        }

        Hook::addFilter('booking_settings_cards', [$this, 'settingsCards']);
        Hook::addAction('booking_settings_save',  [$this, 'saveSettings'], 10, 1);

        // Headless & Mobile API integration (/api/v1/booking/*)
        Hook::addFilter('api_v1_routes', static function (array $routes): array {
            require_once __DIR__ . '/BookingApiHandler.php';
            $routes['booking'] = [BookingApiHandler::class, 'handle'];
            return $routes;
        });

        // MCP AI Gateway integration (expose booking tools to AI assistants)
        require_once __DIR__ . '/BookingMcpHandler.php';
        BookingMcpHandler::register();

        // Content Builder integration: a "Booking" block for embedding the
        // booking flow into any page. Boot order isn't guaranteed, so register
        // now if Content Builder already booted, else catch its hook.
        $this->registerContentBlock();
    }

    /** Register the "Booking" block with Content Builder (order-independent). */
    private function registerContentBlock(): void {
        $register = function ($registry) {
            $opts = BookingAPI::pickerOptions();
            $registry::register('booking', [
                'label'  => __('booking_block', 'Booking'),
                'group'  => 'Booking',
                'fields' => [
                    ['key'=>'service','type'=>'select','label'=>'Service','options'=>$opts],
                    ['key'=>'minHeight','type'=>'text','label'=>'Initial height (px)','placeholder'=>'640'],
                ],
                'render'   => ['BookingAPI', 'renderContentBlock'],
                'defaults' => ['service' => '', 'minHeight' => '640'],
            ]);
        };

        if (class_exists('BlockRegistry')) {
            $register('BlockRegistry');                       // CB already booted
        } else {
            Hook::addAction('content_register_blocks', $register); // CB boots later
        }

        // The block renders inline now, so its stylesheet has to reach the HOST
        // page's head — the iframe used to carry it. Only for pages that have a
        // booking block, so every other page pays nothing.
        if (class_exists('Hook')) {
            Hook::addFilter('content_head_tags', [$this, 'injectBookingAssets'], 10, 2);
        }
    }

    /** Confirm a pending appointment when its Stripe payment succeeds. */
    public function onStripeEvent(array $event): void {
        // Guard the EFFECT, not the webhook receipt (07 §2) — the HTTP 200
        // ack to Stripe happens in the webhook controller regardless of
        // what listeners do; skipping this listener's side effect here
        // just means an unentitled installation's booking does not get
        // confirmed, avoiding a Stripe retry storm that acking-then-
        // rejecting would otherwise cause.
        if (!ModuleGuard::allows('booking')) return;
        try {
            BookingAPI::handleStripeEvent($event);
        } catch (\Throwable $e) {
            slate_log('Booking: stripe event handling failed: ' . $e->getMessage(), 'error');
        }
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('booking.view') && !Auth::isSuperAdmin()) return $items;

        $items[] = ['slug' => 'booking',              'label' => __('booking', 'Booking overview'),
                    'href' => $this->url('admin/index.php'), 'mobile_tab' => true,
                    'icon' => 'calendar',  'order' => 600, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-calendar',     'label' => __('booking_calendar', 'Calendar view'),
                    'href' => $this->url('admin/calendar.php'),
                    'icon' => 'calendar', 'perm' => 'booking.view', 'order' => 600, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-appointments', 'label' => __('booking_appointments', 'Appointments list'),
                    'href' => $this->url('admin/appointments.php'),
                    'icon' => 'clipboard-list', 'perm' => 'booking.view', 'order' => 601, 'group' => 'booking'];

        if ($this->isCapabilityEnabled('client_messaging')) {
            $items[] = ['slug' => 'booking-messages',     'label' => __('booking_messages', 'Messages'),
                        'href' => $this->url('admin/messages.php'),
                        'icon' => 'message-square', 'perm' => 'booking.view', 'order' => 601, 'group' => 'booking'];
        }

        $items[] = ['slug' => 'booking-customers',    'label' => __('booking_customers', 'All Customers'),
                    'href' => $this->url('admin/customers.php'),
                    'icon' => 'users', 'perm' => 'booking.view', 'order' => 601, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-services',     'label' => __('booking_services', 'Services'),
                    'href' => $this->url('admin/services.php'),
                    'icon' => 'tag', 'perm' => 'booking.manage_services', 'order' => 602, 'group' => 'booking'];
        if ($this->isCapabilityEnabled('service_rules')) {
            $items[] = ['slug' => 'booking-service-rules', 'label' => __('booking_service_rules', 'Service rules'),
                        'href' => $this->url('admin/service-rules.php'),
                        'icon' => 'sliders', 'perm' => 'booking.manage_services', 'order' => 602, 'group' => 'booking'];
        }
        $items[] = ['slug' => 'booking-providers',    'label' => __('booking_providers', 'Providers'),
                    'href' => $this->url('admin/providers.php'),
                    'icon' => 'users', 'perm' => 'booking.manage_providers', 'order' => 603, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-categories',   'label' => __('booking_categories', 'Categories'),
                    'href' => $this->url('admin/categories.php'),
                    'icon' => 'folder', 'perm' => 'booking.manage_services', 'order' => 604, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-addons',       'label' => __('booking_addons', 'Add-ons'),
                    'href' => $this->url('admin/addons.php'),
                    'icon' => 'plus', 'perm' => 'booking.manage_services', 'order' => 605, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-fields',       'label' => __('booking_fields', 'Custom fields'),
                    'href' => $this->url('admin/fields.php'),
                    'icon' => 'list', 'perm' => 'booking.manage_services', 'order' => 606, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-locations',    'label' => __('booking_locations', 'Locations'),
                    'href' => $this->url('admin/locations.php'),
                    'icon' => 'map-pin', 'perm' => 'booking.manage_resources', 'order' => 607, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-resources',    'label' => __('booking_resources_nav', 'Resources'),
                    'href' => $this->url('admin/resources.php'),
                    'icon' => 'box', 'perm' => 'booking.manage_resources', 'order' => 608, 'group' => 'booking'];

        if ($this->isCapabilityEnabled('slot_restrictions')) {
            $items[] = ['slug' => 'booking-restrictions', 'label' => __('reserved_slots', 'Reserved slots'),
                        'href' => $this->url('admin/restrictions.php'),
                        'icon' => 'calendar', 'perm' => 'booking.manage_services', 'order' => 609, 'group' => 'booking'];
        }
        $items[] = ['slug' => 'booking-coupons',      'label' => __('booking_coupons', 'Coupons'),
                    'href' => $this->url('admin/coupons.php'),
                    'icon' => 'tag', 'perm' => 'booking.manage_payments', 'order' => 610, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-giftcards',    'label' => __('booking_giftcards', 'Gift cards'),
                    'href' => $this->url('admin/giftcards.php'),
                    'icon' => 'credit-card', 'perm' => 'booking.manage_payments', 'order' => 611, 'group' => 'booking'];
        $items[] = ['slug' => 'booking-settings',     'label' => __('booking_settings', 'Booking settings'),
                    'href' => $this->url('admin/settings.php'),
                    'icon' => 'settings', 'perm' => 'booking.manage_settings', 'order' => 612, 'group' => 'booking'];
        return $items;
    }

    /**
     * The catalogue stylesheet, for pages that actually carry a booking block.
     *
     * INLINED rather than linked, matching the standalone widget: a CDN in front
     * of this install cached public.css by path and ignored the ?v= query string,
     * so linked updates never reached visitors. Inlining ties the CSS to the
     * uncacheable HTML. The <link> stays as the fallback for an unreadable file.
     *
     * The stylesheet defines only .book-* classes plus one .field rule — no bare
     * element selectors — so dropping it into a host page cannot restyle the page
     * around the block.
     */
    public function injectBookingAssets($headTags, $post = null): string {
        $headTags = (string) $headTags;

        $layout = is_array($post) ? ($post['layout'] ?? []) : [];
        if (!class_exists('ContentBuilderAPI')
            || !ContentBuilderAPI::layoutHasBlock($layout, 'booking')) {
            return $headTags;
        }

        $css = @file_get_contents(plugin_dir('booking', 'assets/css/public.css'));
        if ($css !== false && $css !== '') {
            return $headTags . '<style id="booking-block">' . $css . '</style>';
        }

        $v = @filemtime(plugin_dir('booking', 'assets/css/public.css')) ?: 0;
        return $headTags . '<link rel="stylesheet" href="'
             . e(plugin_url('booking', 'assets/css/public.css')) . '?v=' . $v . '">';
    }

    public function addPublicRoutes(array $routes): array {
        $routes['book'] = [
            'handler' => $this->dir('public/router.php'),
            'methods' => ['GET', 'POST'],
        ];
        return $routes;
    }

    /** Registers plugins/booking/lang/<locale>.php with core I18n. */
    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    public function addAdminDashboardWidget(array $widgets): array {
        if (!Auth::can('booking.view')) return $widgets;
        $tid = current_tenant_id();
        try {
            $today = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments
                  WHERE tenant_id = ? AND status='confirmed'
                    AND DATE(starts_at) = CURDATE()", [$tid]);
            $week  = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments
                  WHERE tenant_id = ? AND status='confirmed'
                    AND starts_at >= NOW() AND starts_at <= NOW() + INTERVAL 7 DAY", [$tid]);
            $services = (int) Database::value(
                "SELECT COUNT(*) FROM booking_services
                  WHERE tenant_id = ? AND is_active=1", [$tid]);
            $latest = Database::rows(
                "SELECT a.customer_name, a.customer_email, a.starts_at, a.status, s.name AS service_name, s.name_fr AS service_name_fr
                   FROM booking_appointments a
                   LEFT JOIN booking_services s ON s.id = a.service_id
                  WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT 5", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }
        $apptUrl = $this->url('admin/appointments.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('booking', 'Booking') ?></h2>
                <a href="<?= e($apptUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('booking_today', 'Today') ?></div>
                    <div class="dwidget-kpi-v"><?= $today ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('booking_next_7', 'Next 7 days') ?></div>
                    <div class="dwidget-kpi-v"><?= $week ?></div>
                </div>
            </div>
            <?php if ($latest): ?>
                <div class="dlist">
                    <?php foreach ($latest as $a):
                        $name   = trim((string)($a['customer_name'] ?? '')) ?: __('booking_guest', 'Guest');
                        $colors = ['confirmed' => 'success', 'pending' => 'warning', 'completed' => 'info',
                                   'cancelled' => 'danger', 'no_show' => 'muted'];
                        slate_dlist_row([
                            'avatar_html'  => slate_avatar_overlay_html(mb_substr($name, 0, 2), (string)($a['customer_email'] ?? '')),
                            'avatar_color' => $colors[$a['status']] ?? 'muted',
                            'title'        => $name,
                            'sub'          => trim((string)($a['service_name'] ?? '')) . ' · ' . ucfirst(str_replace('_', ' ', (string)$a['status'])),
                            'amount'       => $a['starts_at'] ? slate_format_time($a['starts_at']) : '',
                            'time'         => $a['starts_at'] ? slate_format_date($a['starts_at']) : '',
                            'href'         => $apptUrl,
                        ]);
                    endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dlist-empty"><?= __('booking_no_appts', 'No appointments yet') ?></div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    /**
     * Headline KPI: confirmed appointments still ahead of the customer.
     * Silent on failure — a dashboard must never 500 because one plugin's
     * table is missing.
     */
    public function addCustomerDashboardKpi(array $kpis): array {
        $cid = Auth::customerId();
        if ($cid === null) return $kpis;

        try {
            $count = (int) Database::value(
                "SELECT COUNT(*) FROM booking_appointments
                  WHERE customer_id = ? AND status = 'confirmed' AND starts_at >= NOW()",
                [$cid]
            );
            $next = Database::value(
                "SELECT MIN(starts_at) FROM booking_appointments
                  WHERE customer_id = ? AND status = 'confirmed' AND starts_at >= NOW()",
                [$cid]
            );
        } catch (\Throwable $e) {
            return $kpis;
        }

        $kpis[] = [
            'label' => 'Upcoming',
            'value' => (string)$count,
            'unit'  => $count === 1 ? 'booking' : 'bookings',
            'icon'  => 'calendar',
            'tone'  => $count > 0 ? 'blue' : '',
            'meta'  => $next
                ? 'Next <strong>' . e(slate_format_datetime((string)$next)) . '</strong>'
                : 'Nothing scheduled',
        ];
        return $kpis;
    }

    /**
     * "Book" in the shared customer portal nav. Always offered to a signed-in
     * customer — booking is the action you come to the portal to take, so
     * unlike a summary widget it shouldn't require existing appointments.
     */
    public function addCustomerNav(array $items): array {
        if (!class_exists('Auth') || Auth::customerId() === null) return $items;

        $items[] = [
            'slug'  => 'booking',
            'label' => __('book_now', 'Book'),
            'href'  => SLATE_URL . '/member/book',
            'icon'  => 'calendar',
            'order' => 100,
            'group' => 'booking',
        ];
        return $items;
    }

    public function addCustomerActivity(array $events, array $context): array {
        $cid = (int) ($context['customer_id'] ?? 0);
        if ($cid <= 0) return $events;

        try {
            $rows = Database::rows(
                "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
                   FROM booking_appointments a
                   JOIN booking_services  s ON s.id = a.service_id
                   JOIN booking_providers p ON p.id = a.provider_id
                  WHERE a.customer_id = ? AND a.tenant_id = ?
               ORDER BY a.starts_at DESC LIMIT 10",
                [$cid, current_tenant_id()]
            );
            foreach ($rows as $r) {
                $status = (string)($r['status'] ?? '');
                $tone = ($status === 'confirmed') ? 'green' : (($status === 'cancelled') ? 'red' : 'amber');
                $events[] = [
                    'id'          => 'booking-' . $r['id'],
                    'type'        => 'booking',
                    'label'       => ($r['service_name'] ?? 'Appointment') . ' with ' . ($r['provider_name'] ?? 'Practitioner'),
                    'description' => ucfirst($status) . ' appointment (' . slate_format_datetime($r['starts_at']) . ')',
                    'occurred_at' => $r['starts_at'],
                    'href'        => SLATE_URL . '/member/book',
                    'icon'        => 'calendar',
                    'tone'        => $tone,
                ];
            }
        } catch (\Throwable $e) {}
        return $events;
    }

    public function addCustomerDashboardWidget(array $widgets): array {
        $cid = Auth::customerId();
        if ($cid === null) return $widgets;

        try {
            // anti-drift-ignore: TENANT — scoped by a.customer_id, which is
            // Auth::customerId() (the current session's own customer row);
            // a customer's appointments can't belong to another tenant.
            $upcoming = Database::rows(
                "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
                   FROM booking_appointments a
                   JOIN booking_services  s ON s.id = a.service_id
                   JOIN booking_providers p ON p.id = a.provider_id
                  WHERE a.customer_id = ? AND a.status = 'confirmed'
                    AND a.starts_at >= NOW()
               ORDER BY a.starts_at LIMIT 5",
                [$cid]
            );
        } catch (\Throwable $e) {
            return $widgets;
        }
        if (!$upcoming) return $widgets;

        ob_start(); ?>
        <div class="card">
            <div class="card-header"><h2>Upcoming appointments</h2></div>
            <ul class="kv-list">
                <?php foreach ($upcoming as $a): ?>
                    <li class="kv-row">
                        <span class="kv-label">
                            <strong style="color:var(--text);"><?= e($a['service_name']) ?></strong><br>
                            <span class="text-xs"><?= e($a['provider_name']) ?> · ref <code><?= e($a['ref']) ?></code></span>
                        </span>
                        <span class="kv-value">
                            <?= e(slate_format_datetime($a['starts_at'])) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    /**
     * Send configurable-lead reminders + post-visit follow-ups. Idempotent:
     * each fired lead is recorded in `reminders_sent` (CSV of lead-minutes)
     * and follow-ups flip `followup_sent`. Run every ~5 minutes; cheap when
     * nothing is due.
     */
    public function sendReminders(): void {
        // Module Guard (07 §5): checked once, at the top of the listener,
        // not per-appointment inside the loop below — an unentitled
        // installation sends no reminder emails at all for this tick, which
        // is expected routine behavior, not a fault, so nothing is logged.
        if (!ModuleGuard::allows('booking')) return;
        try {
            foreach (BookingAPI::reminderLeads() as $lead) {
                // Appointments now within `lead` minutes of starting that
                // haven't had this lead's reminder yet.
                // anti-drift-ignore: TENANT — cron tick, intentionally scans
                // every tenant's due reminders in one pass.
                $rows = Database::rows(
                    "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
                       FROM booking_appointments a
                       JOIN booking_services  s ON s.id = a.service_id
                       JOIN booking_providers p ON p.id = a.provider_id
                      WHERE a.status = 'confirmed'
                        AND a.starts_at > NOW()
                        AND a.starts_at <= NOW() + INTERVAL ? MINUTE
                        AND NOT FIND_IN_SET(?, COALESCE(a.reminders_sent, ''))",
                    [$lead, $lead]
                );
                foreach ($rows as $a) {
                    BookingAPI::sendReminder($a, $this->humanLead($lead), (int)$lead);
                    $sent = array_filter(array_map('trim', explode(',', (string)($a['reminders_sent'] ?? ''))));
                    $sent[] = (string)$lead;
                    Database::update('booking_appointments',
                        ['reminders_sent' => implode(',', array_unique($sent))], 'id = ?', [$a['id']]);
                }
            }

            // Follow-ups: appointments that finished a while ago.
            if (Database::setting('booking.followup_enabled') === '1') {
                $delayH = max(1, (int)(Database::setting('booking.followup_delay_hours') ?: 24));
                // anti-drift-ignore: TENANT — cron tick, intentionally scans
                // every tenant's due follow-ups in one pass.
                $rows = Database::rows(
                    "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, p.name AS provider_name
                       FROM booking_appointments a
                       JOIN booking_services  s ON s.id = a.service_id
                       JOIN booking_providers p ON p.id = a.provider_id
                      WHERE a.status IN ('confirmed','completed') AND a.followup_sent = 0
                        AND a.ends_at <= NOW() - INTERVAL ? HOUR
                        AND a.ends_at >= NOW() - INTERVAL 30 DAY",
                    [$delayH]
                );
                foreach ($rows as $a) {
                    BookingAPI::sendFollowup($a);
                    Database::update('booking_appointments', ['followup_sent' => 1], 'id = ?', [$a['id']]);
                }
            }
        } catch (\Throwable $e) {
            slate_log('Booking: reminder cron failed: ' . $e->getMessage(), 'error');
        }
    }

    /** Human label for a lead time in minutes (e.g. 1440 → "24 hours"). */
    private function humanLead(int $minutes): string {
        if ($minutes % 1440 === 0) { $d = $minutes / 1440; return $d . ' jour' . ($d > 1 ? 's' : ''); }
        if ($minutes % 60 === 0)   { $h = $minutes / 60;   return $h . ' heure' . ($h > 1 ? 's' : ''); }
        return $minutes . ' minutes';
    }

    // ──────────────────────────────────────────────────────
    // Migrations
    // ──────────────────────────────────────────────────────

    private function runMigrations(string $from): void {
        // Step 1 — new tables via the .sql migration files.
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

        // Step 2 — additive columns + indexes on existing tables. Idempotent;
        // split out of the .sql so they stay portable across MySQL/MariaDB.
        if (version_compare('0.2.0', $from, '>')) {
            // services: scheduling + pricing config
            $this->ensureColumn('booking_services', 'category_id',        'INT UNSIGNED NULL AFTER description');
            $this->ensureColumn('booking_services', 'location_id',        'INT UNSIGNED NULL AFTER category_id');
            $this->ensureColumn('booking_services', 'duration_max_min',   'INT UNSIGNED NULL AFTER duration_min');
            $this->ensureColumn('booking_services', 'slot_interval_min',  'INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_max_min');
            $this->ensureColumn('booking_services', 'buffer_before_min',  'INT UNSIGNED NOT NULL DEFAULT 0 AFTER slot_interval_min');
            $this->ensureColumn('booking_services', 'capacity',           'INT UNSIGNED NOT NULL DEFAULT 1 AFTER buffer_min');
            $this->ensureColumn('booking_services', 'min_advance_min',    'INT UNSIGNED NOT NULL DEFAULT 0 AFTER capacity');
            $this->ensureColumn('booking_services', 'max_advance_days',   'INT UNSIGNED NOT NULL DEFAULT 365 AFTER min_advance_min');
            $this->ensureColumn('booking_services', 'is_online',          'TINYINT(1) NOT NULL DEFAULT 0 AFTER max_advance_days');
            $this->ensureColumn('booking_services', 'requires_resource',  'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_online');
            $this->ensureColumn('booking_services', 'payment_mode',       "ENUM('free','full','deposit','onsite') NOT NULL DEFAULT 'free' AFTER currency");
            $this->ensureColumn('booking_services', 'deposit_type',       "ENUM('fixed','percent') NOT NULL DEFAULT 'percent' AFTER payment_mode");
            $this->ensureColumn('booking_services', 'deposit_value',      'INT UNSIGNED NOT NULL DEFAULT 0 AFTER deposit_type');
            $this->ensureColumn('booking_services', 'tax_rate',           'DECIMAL(6,3) NOT NULL DEFAULT 0 AFTER deposit_value');
            $this->ensureColumn('booking_services', 'sort_order',         'INT NOT NULL DEFAULT 0 AFTER color');
            $this->ensureIndex('booking_services', 'tenant_category', '(tenant_id, category_id)');
            $this->ensureIndex('booking_services', 'tenant_sort',     '(tenant_id, sort_order)');

            // providers
            $this->ensureColumn('booking_providers', 'user_id',    'INT UNSIGNED NULL AFTER tenant_id');
            $this->ensureColumn('booking_providers', 'phone',      'VARCHAR(40) NULL AFTER email');
            $this->ensureColumn('booking_providers', 'color',      "VARCHAR(16) NOT NULL DEFAULT '#2563EB' AFTER bio");
            $this->ensureColumn('booking_providers', 'sort_order', 'INT NOT NULL DEFAULT 0 AFTER color');

            // provider_services: per-staff price/duration overrides
            $this->ensureColumn('booking_provider_services', 'price_cents',  'INT UNSIGNED NULL');
            $this->ensureColumn('booking_provider_services', 'duration_min', 'INT UNSIGNED NULL');

            // appointments
            $this->ensureColumn('booking_appointments', 'location_id',      'INT UNSIGNED NULL AFTER provider_id');
            $this->ensureColumn('booking_appointments', 'resource_id',      'INT UNSIGNED NULL AFTER location_id');
            $this->ensureColumn('booking_appointments', 'party_size',       'INT UNSIGNED NOT NULL DEFAULT 1 AFTER customer_phone');
            $this->ensureColumn('booking_appointments', 'addons_json',      'TEXT NULL AFTER notes');
            $this->ensureColumn('booking_appointments', 'custom_json',      'TEXT NULL AFTER addons_json');
            $this->ensureColumn('booking_appointments', 'source',           "ENUM('online','walkin','admin') NOT NULL DEFAULT 'online' AFTER status");
            $this->ensureColumn('booking_appointments', 'recurrence_group', 'VARCHAR(32) NULL AFTER source');
            $this->ensureColumn('booking_appointments', 'price_cents',      'INT UNSIGNED NOT NULL DEFAULT 0 AFTER recurrence_group');
            $this->ensureColumn('booking_appointments', 'tax_cents',        'INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_cents');
            $this->ensureColumn('booking_appointments', 'deposit_cents',    'INT UNSIGNED NOT NULL DEFAULT 0 AFTER tax_cents');
            $this->ensureColumn('booking_appointments', 'paid_cents',       'INT UNSIGNED NOT NULL DEFAULT 0 AFTER deposit_cents');
            $this->ensureColumn('booking_appointments', 'payment_status',   "ENUM('none','pending','deposit_paid','paid','refunded','partially_refunded') NOT NULL DEFAULT 'none' AFTER paid_cents");
            $this->ensureColumn('booking_appointments', 'payment_ref',      'VARCHAR(120) NULL AFTER payment_status');
            $this->ensureColumn('booking_appointments', 'reschedule_count', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_ref');
            $this->ensureColumn('booking_appointments', 'cancel_reason',    'VARCHAR(255) NULL AFTER reschedule_count');
            $this->ensureColumn('booking_appointments', 'cancelled_at',     'DATETIME NULL AFTER cancel_reason');
            $this->ensureIndex('booking_appointments', 'resource_slot', '(resource_id, starts_at, ends_at)');
            $this->ensureIndex('booking_appointments', 'recur_group',   '(recurrence_group)');

            // status ENUM gains 'pending' (awaiting payment).
            $this->modifyColumn('booking_appointments', 'status',
                "ENUM('pending','confirmed','cancelled','no_show','completed') NOT NULL DEFAULT 'confirmed'");
        }

        // v0.3.0 — notifications: configurable reminder tracking, follow-ups,
        // and per-service email template overrides.
        if (version_compare('0.3.0', $from, '>')) {
            $this->ensureColumn('booking_appointments', 'reminders_sent', 'VARCHAR(160) NULL AFTER reminder_1h_sent');
            $this->ensureColumn('booking_appointments', 'followup_sent',  'TINYINT(1) NOT NULL DEFAULT 0 AFTER reminders_sent');
            $this->ensureColumn('booking_services', 'confirm_subject', 'VARCHAR(200) NULL AFTER description');
            $this->ensureColumn('booking_services', 'confirm_body',    'TEXT NULL AFTER confirm_subject');
        }

        // v0.4.0 — customer management: a per-appointment self-service token
        // for guest cancel/reschedule links. The booking_customers table is
        // created by migrations/0.4.0.sql.
        if (version_compare('0.4.0', $from, '>')) {
            $this->ensureColumn('booking_appointments', 'manage_token', 'CHAR(32) NULL AFTER ref');
            $this->ensureIndex('booking_appointments', 'manage_token_idx', '(manage_token)');
        }

        // v0.5.0 — payments & billing: discounts, gift cards, group price
        // tiers, and the Stripe charge link. Tables come from 0.5.0.sql.
        if (version_compare('0.5.0', $from, '>')) {
            $this->ensureColumn('booking_appointments', 'coupon_code',    'VARCHAR(60) NULL AFTER payment_ref');
            $this->ensureColumn('booking_appointments', 'gift_card_code', 'VARCHAR(60) NULL AFTER coupon_code');
            $this->ensureColumn('booking_appointments', 'discount_cents', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER gift_card_code');
            $this->ensureColumn('booking_appointments', 'charge_id',      'INT UNSIGNED NULL AFTER discount_cents');
            $this->ensureColumn('booking_services',     'price_tiers_json', 'TEXT NULL AFTER deposit_value');
        }

        // v0.5.1 — gift-card amount applied, Stripe session correlation,
        // and an invoice number.
        if (version_compare('0.5.1', $from, '>')) {
            $this->ensureColumn('booking_appointments', 'gift_applied_cents', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER gift_card_code');
            $this->ensureColumn('booking_appointments', 'stripe_session_id',  'VARCHAR(255) NULL AFTER charge_id');
            $this->ensureColumn('booking_appointments', 'invoice_no',         'VARCHAR(40) NULL AFTER stripe_session_id');
            $this->ensureIndex('booking_appointments', 'stripe_session_idx', '(stripe_session_id)');
        }

        // v0.6.0 — Google Calendar 2-way sync. The booking_google_busy_blocks
        // table itself comes from migrations/0.6.0.sql (Step 1, above); these
        // are the per-provider OAuth connection state and per-appointment
        // push-sync status columns.
        if (version_compare('0.6.0', $from, '>')) {
            $this->ensureColumn('booking_providers', 'google_calendar_id',      'VARCHAR(255) NULL AFTER color');
            $this->ensureColumn('booking_providers', 'google_access_token',    'TEXT NULL AFTER google_calendar_id');
            $this->ensureColumn('booking_providers', 'google_refresh_token',   'TEXT NULL AFTER google_access_token');
            $this->ensureColumn('booking_providers', 'google_token_expires_at','DATETIME NULL AFTER google_refresh_token');
            $this->ensureColumn('booking_providers', 'google_connected_email', 'VARCHAR(200) NULL AFTER google_token_expires_at');
            $this->ensureColumn('booking_providers', 'google_sync_token',      'TEXT NULL AFTER google_connected_email');
            $this->ensureColumn('booking_providers', 'google_watch_channel_id',  'VARCHAR(64) NULL AFTER google_sync_token');
            $this->ensureColumn('booking_providers', 'google_watch_resource_id','VARCHAR(64) NULL AFTER google_watch_channel_id');
            $this->ensureColumn('booking_providers', 'google_watch_expires_at', 'DATETIME NULL AFTER google_watch_resource_id');
            $this->ensureIndex('booking_providers', 'google_watch_idx', '(google_watch_channel_id, google_watch_resource_id)');

            $this->ensureColumn('booking_appointments', 'google_event_id',    'VARCHAR(255) NULL AFTER invoice_no');
            $this->ensureColumn('booking_appointments', 'google_sync_status', "ENUM('pending','synced','error') NOT NULL DEFAULT 'pending' AFTER google_event_id");
            $this->ensureColumn('booking_appointments', 'google_synced_at',   'DATETIME NULL AFTER google_sync_status');
            $this->ensureIndex('booking_appointments', 'google_event_idx', '(google_event_id)');
        }

        // v0.7.0 — payment-failure visibility + manual confirmation workflow.
        //
        // 'failed' on payment_status distinguishes "we tried to charge this
        // and Stripe declined it" from plain 'pending' ("checkout not
        // attempted yet"), so a declined Bancontact (or card) payment is
        // visible instead of leaving the appointment looking like a normal
        // unpaid booking forever (see BookingAPI::handleStripeEvent()).
        //
        // 'awaiting_approval' on status is a new state distinct from
        // 'pending' (which has always meant "awaiting payment" only, never
        // "awaiting staff review"). It's used when booking.confirmation_mode
        // = 'manual' (BookingAPI::confirmationMode(), settings.php) — a
        // booking that doesn't need online payment lands here instead of
        // auto-confirming, and stays until an admin approves or declines it
        // (BookingAPI::approveAppointment()/declineAppointment()).
        if (version_compare('0.7.0', $from, '>')) {
            $this->modifyColumn('booking_appointments', 'payment_status',
                "ENUM('none','pending','deposit_paid','paid','refunded','partially_refunded','failed') NOT NULL DEFAULT 'none'");
            $this->modifyColumn('booking_appointments', 'status',
                "ENUM('pending','awaiting_approval','confirmed','cancelled','no_show','completed') NOT NULL DEFAULT 'confirmed'");
        }

        // v0.9.0 — optional French name/description per service, so the
        // public catalogue and booking flow can show one language at a time
        // instead of both baked into a single string (mirrors Membership's
        // plan name_fr/description_fr — see MembershipAPI::planName()).
        if (version_compare('0.9.0', $from, '>')) {
            $this->ensureColumn('booking_services', 'name_fr', 'VARCHAR(160) NULL AFTER name');
            $this->ensureColumn('booking_services', 'description_fr', 'TEXT NULL AFTER description');
        }
    }

    private function schemaIsCurrent(): bool {
        try {
            // Probe one representative column from each schema generation,
            // including the newest (0.5.1) payment columns. If any are
            // missing the schema is not fully migrated and the version must
            // not be stamped.
            $cols = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND ((TABLE_NAME = 'booking_services'     AND COLUMN_NAME = 'capacity')
                      OR (TABLE_NAME = 'booking_appointments' AND COLUMN_NAME = 'party_size')
                      OR (TABLE_NAME = 'booking_appointments' AND COLUMN_NAME = 'gift_applied_cents')
                      OR (TABLE_NAME = 'booking_appointments' AND COLUMN_NAME = 'stripe_session_id'))");
            $tbl = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('booking_categories', 'booking_google_busy_blocks')");
            $gcol = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND ((TABLE_NAME = 'booking_providers'    AND COLUMN_NAME = 'google_refresh_token')
                      OR (TABLE_NAME = 'booking_appointments' AND COLUMN_NAME = 'google_sync_status'))");
            $frcol = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'booking_services' AND COLUMN_NAME IN ('name_fr', 'description_fr')");
            // 0.7.0 — both status ENUMs must actually contain the new values,
            // not just exist as columns (they already did before this
            // version); a plain column-presence check would stamp the
            // version without the MODIFY COLUMN having taken effect.
            $enums = (int) Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_appointments'
                    AND ((COLUMN_NAME = 'status'         AND COLUMN_TYPE LIKE '%awaiting_approval%')
                      OR (COLUMN_NAME = 'payment_status' AND COLUMN_TYPE LIKE '%failed%'))");
            return $cols >= 4 && $tbl >= 2 && $gcol >= 2 && $enums >= 2 && $frcol >= 2;
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
                slate_log("Booking migration {$tag} statement error: " . $e->getMessage(), 'error');
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
            slate_log("Booking ensureColumn {$table}.{$column} failed: " . $e->getMessage(), 'error');
        }
    }

    private function modifyColumn(string $table, string $column, string $definition): void {
        try {
            $exists = Database::value(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $column]
            );
            if ((int)$exists > 0) {
                Database::query("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
            }
        } catch (\Throwable $e) {
            slate_log("Booking modifyColumn {$table}.{$column} failed: " . $e->getMessage(), 'error');
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
            slate_log("Booking ensureIndex {$table}.{$indexName} failed: " . $e->getMessage(), 'error');
        }
    }

    // ── Modular Capabilities Handlers ─────────────────────────

    public function gateBookingRules($gate, array $ctx = []) {
        if (is_array($gate) && array_key_exists('ok', $gate) && $gate['ok'] === false) {
            return $gate;
        }
        $service = $ctx['service'] ?? null;
        if (!is_array($service) || empty($service['id'])) return $gate;
        $cfg = BookingPlusAPI::getServiceConfig((int)$service['id']);

        $minDays = (int)($cfg['min_advance_days'] ?? 0);
        if ($minDays > 0) {
            $start = strtotime((string)($ctx['starts_at'] ?? ''));
            if ($start && $start < slate_db_time() + $minDays * 86400) {
                $nextOk = I18n::localDate('l, j F Y', slate_db_time() + $minDays * 86400);
                return [
                    'ok'    => false,
                    'error' => 'This session requires a preparation period of at least '
                             . $minDays . ' days. The earliest we can book is ' . $nextOk . '.',
                ];
            }
        }

        $prereqId = (int)($cfg['prereq_service_id'] ?? 0);
        if ($prereqId > 0) {
            $email = self::extractEmailFromContext($ctx);
            $ok = $email !== '' && BookingPlusAPI::customerHasCompleted($email, $prereqId);
            if (!$ok) {
                $msg = trim((string)($cfg['prereq_message'] ?? ''));
                if ($msg === '') {
                    $msg = 'This session requires a prior consultation. Please book a Discovery Call first — we can talk it through together.';
                }
                // A configured redirect sends her to a banner page that
                // explains the switch and forwards her to the right service's
                // widget, rather than dead-ending on an error. This branch was
                // dropped when the gate was absorbed from Booking+, which left
                // hsr_redirect_service_id configurable but inert.
                $redirectId = (int)($cfg['hsr_redirect_service_id'] ?? 0);
                if ($redirectId > 0 && defined('SLATE_URL')) {
                    return [
                        'ok'          => false,
                        'error'       => $msg,
                        'redirect_to' => SLATE_URL . '/plugins/booking/public/prereq.php'
                                       . '?from=' . (int)($service['id'] ?? 0)
                                       . '&to='   . $redirectId,
                    ];
                }
                return ['ok' => false, 'error' => $msg];
            }
        }
        return $gate;
    }

    private static function extractEmailFromContext(array $ctx): string {
        if (!empty($ctx['customer_email'])) return (string)$ctx['customer_email'];
        $cid = (int)($ctx['customer_id'] ?? 0);
        if ($cid > 0) {
            $row = Database::row("SELECT email FROM customers WHERE id = ? AND tenant_id = ?", [$cid, current_tenant_id()]);
            if ($row && !empty($row['email'])) return (string)$row['email'];
        }
        if (!empty($ctx['payload']['customer_email'])) return (string)$ctx['payload']['customer_email'];
        return '';
    }

    public function applySlotRestriction($allowed, int $serviceId, int $providerId, string $date, int $startTs, int $endTs) {
        if (!$allowed) return false;
        try {
            $dow = (int) date('w', $startTs);
            $rows = Database::rows(
                "SELECT service_id FROM bookingplus_slot_restrictions
                  WHERE tenant_id = ? AND day_of_week = ?
                    AND (provider_id IS NULL OR provider_id = ?)
                    AND start_time < ? AND end_time > ?",
                [current_tenant_id(), $dow, $providerId,
                 date('H:i:s', $endTs), date('H:i:s', $startTs)]
            );
            if (!$rows) return true;
            foreach ($rows as $r) {
                if ((int)$r['service_id'] === $serviceId) return true;
            }
            return false;
        } catch (\Throwable $e) {
            slate_log('Booking slot restriction check failed: ' . $e->getMessage(), 'warning');
            return $allowed;
        }
    }

    public function overrideReminderBody(string $body, array $appt, int $leadMinutes): string {
        try {
            $serviceId = (int)($appt['service_id'] ?? 0);
            if ($serviceId <= 0) return $body;
            $cfg = BookingPlusAPI::getServiceConfig($serviceId);

            $tpl = null;
            if ($leadMinutes === 11520)     $tpl = $cfg['reminder_8day_body']  ?? null;
            elseif ($leadMinutes === 1440) $tpl = $cfg['reminder_1day_body']  ?? null;
            elseif ($leadMinutes === 10)   $tpl = $cfg['reminder_10min_body'] ?? null;
            if (!$tpl || trim((string)$tpl) === '') return $body;

            $whatsapp = trim((string)($cfg['whatsapp_url'] ?? '')) ?: BookingPlusAPI::globalWhatsappUrl();
            $paymentLink = '';
            if (!empty($appt['manage_token']) && defined('SLATE_URL')) {
                $paymentLink = SLATE_URL . '/book/manage?token=' . rawurlencode((string)$appt['manage_token']);
            }

            $meta = BookingPlusAPI::getAppointmentMeta((int)($appt['id'] ?? 0));
            $zoom = trim((string)($meta['zoom_join_url'] ?? '')) ?: trim((string)($cfg['zoom_join_url'] ?? ''));

            $ctx = [
                'customer_name' => $appt['customer_name'] ?? '',
                'service_name'  => $appt['service_name']  ?? '',
                'provider_name' => $appt['provider_name'] ?? '',
                'starts_at'     => $appt['starts_at']     ?? 'now',
                'ref'           => $appt['ref']           ?? '',
                'prep_url'      => (string)($cfg['prep_page_url'] ?? ''),
                'whatsapp_url'  => $whatsapp,
                'zoom_url'      => $zoom,
                'payment_link'  => $paymentLink,
                'payment_note'  => BookingPlusAPI::paymentNote($appt),
            ];
            return BookingPlusAPI::renderTemplate((string)$tpl, $ctx);
        } catch (\Throwable $e) {
            slate_log('Booking reminder overlay failed: ' . $e->getMessage(), 'warning');
            return $body;
        }
    }

    public function onBookingCreatedMessage(int $apptId, int $serviceId, int $providerId): void {
        try {
            $cfg = BookingPlusAPI::getServiceConfig($serviceId);
            $body = trim((string)($cfg['auto_response_body'] ?? ''));
            if ($body === '') return;

            $appt = Database::row(
                "SELECT a.*, s.name AS service_name, s.name_fr AS service_name_fr, s.payment_mode, p.name AS provider_name
                   FROM booking_appointments a
                   JOIN booking_services  s ON s.id = a.service_id
                   JOIN booking_providers p ON p.id = a.provider_id
                  WHERE a.id = ? AND a.tenant_id = ? LIMIT 1",
                [$apptId, current_tenant_id()]
            );
            if (!$appt || empty($appt['customer_email'])) return;

            $whatsapp = trim((string)($cfg['whatsapp_url'] ?? '')) ?: BookingPlusAPI::globalWhatsappUrl();
            $messageUrl = '';
            if (!empty($appt['manage_token']) && defined('SLATE_URL')) {
                $messageUrl = SLATE_URL . '/plugins/booking/public/message.php'
                            . '?t=' . rawurlencode((string)$appt['manage_token']);
            }

            $ctx = [
                'customer_name' => $appt['customer_name'] ?? '',
                'service_name'  => $appt['service_name']  ?? '',
                'provider_name' => $appt['provider_name'] ?? '',
                'starts_at'     => $appt['starts_at']     ?? 'now',
                'ref'           => $appt['ref']           ?? '',
                'prep_url'      => (string)($cfg['prep_page_url'] ?? ''),
                'whatsapp_url'  => $whatsapp,
                'payment_note'  => BookingPlusAPI::paymentNote($appt),
                'message_url'   => $messageUrl,
            ];

            $subject = trim((string)($cfg['auto_response_subject'] ?? '')) ?: __('booking_email_auto_response_subject_default', 'A little more about your booking');
            $subject = BookingPlusAPI::renderTemplate($subject, $ctx);
            $html    = BookingPlusAPI::renderTemplate($body, $ctx);

            if ($messageUrl !== '' && strpos($body, '{{message_url}}') === false) {
                $html .= '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0;">'
                       . '<p style="margin:0 0 6px;font-weight:600;">' . e(__('booking_email_note_prompt_heading', "Anything you'd like me to know?")) . '</p>'
                       . '<p style="margin:0 0 10px;color:#555;">' . e(__('booking_email_note_prompt_body', 'Leave me a short personal note about what brings you — questions, concerns, context. I read every one.')) . '</p>'
                       . '<p style="margin:0;"><a href="' . e($messageUrl)
                       . '" style="display:inline-block;padding:10px 18px;background:#2563EB;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">' . e(__('booking_email_send_message_btn', 'Send me a message')) . '</a></p>';
            }

            Mailer::send(
                (string)$appt['customer_email'],
                $subject,
                $html,
                (string)$appt['customer_name']
            );
        } catch (\Throwable $e) {
            slate_log('Booking capability auto-response failed: ' . $e->getMessage(), 'error');
        }
    }

    public function runMessagingNudgeCron(): void {
        if (!ModuleGuard::allows('booking')) return;
        try {
            $tid   = current_tenant_id();
            $hours = BookingPlusAPI::globalNudgeHours();

            $rows = Database::rows(
                "SELECT m.*, a.customer_name, a.customer_email, s.name AS service_name, s.name_fr AS service_name_fr
                   FROM bookingplus_appointment_meta m
                   JOIN booking_appointments a ON a.id = m.appointment_id
                   JOIN booking_services    s ON s.id = a.service_id
                  WHERE m.tenant_id = ?
                    AND m.client_message IS NOT NULL
                    AND m.therapist_replied_at IS NULL
                    AND m.nudge_sent_at IS NULL
                    AND m.therapist_notified_at IS NOT NULL
                    AND m.therapist_notified_at <= NOW() - INTERVAL ? HOUR",
                [$tid, $hours]
            );
            if (!$rows) return;

            $to = trim((string) (Database::setting('booking.notify_admin_email') ?: Database::setting('site_admin_email') ?: ''));
            if ($to === '') return;
            $siteName = Database::setting('site_name') ?: 'Kohevo';

            foreach ($rows as $m) {
                $subj = $siteName . ' · ' . __('booking_email_nudge_subject', 'client message still waiting for your reply');
                $body = '<p>' . sprintf(__('booking_email_nudge_body_intro', 'A client sent you a message %sh+ ago and no reply is recorded yet.'), $hours) . '</p>'
                      . '<p><strong>' . e((string)$m['customer_name']) . '</strong> · '
                      . e((string)$m['service_name']) . '</p>'
                      . '<blockquote style="border-left:3px solid #ccc;padding:8px 12px;color:#444;">'
                      . nl2br(e((string)$m['client_message']))
                      . '</blockquote>'
                      . '<p><a href="' . e($this->url('admin/messages.php')) . '">' . e(__('booking_email_nudge_open_link', 'Open Booking messages')) . '</a></p>';
                Mailer::send($to, $subj, $body);
                Database::update('bookingplus_appointment_meta',
                    ['nudge_sent_at' => slate_db_now()],
                    'id = ? AND tenant_id = ?', [(int)$m['id'], $tid]
                );
            }
        } catch (\Throwable $e) {
            slate_log('Booking nudge cron failed: ' . $e->getMessage(), 'error');
        }
    }

    public function settingsCards(string $html): string {
        if (!Auth::can('booking.manage_settings') && !Auth::isSuperAdmin()) return $html;

        $caps = $this->capabilities();
        $capHtml = '';
        foreach ($caps as $capKey => $capDef) {
            $checked = $this->isCapabilityEnabled((string)$capKey) ? 'checked' : '';
            $capHtml .= '
            <label style="display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;cursor:pointer;">
                <input type="checkbox" name="booking_cap_' . e($capKey) . '" value="1" ' . $checked . ' style="margin-top:3px;">
                <div>
                    <div style="font-weight:600;font-size:14px;">' . e($capDef['name']) . '</div>
                    <div class="text-sm text-muted">' . e($capDef['description']) . '</div>
                </div>
            </label>';
        }

        $wa    = e(BookingPlusAPI::globalWhatsappUrl());
        $nudge = BookingPlusAPI::globalNudgeHours();

        $html .= '
    <div class="app-card" style="margin-top:var(--space-4);">
        <div class="card-header"><h2>' . __('booking_capabilities', 'Booking Capabilities') . '</h2></div>
        <p class="text-sm text-muted" style="margin-bottom:16px;">' . __('booking_capabilities_desc', 'Enable or disable modular features independently for this organization. Disabled features clean up navigation and bypass all hooks.') . '</p>
        ' . $capHtml . '
    </div>
    <div class="app-card" style="margin-top:var(--space-4);">
        <div class="card-header"><h2>' . __('booking_extras', 'Booking Extended Settings') . '</h2></div>
        <p class="text-sm text-muted" style="margin-bottom:16px;">' . __('booking_extras_sub', 'WhatsApp links and practitioner response timing.') . '</p>
        <div class="field">
            <label class="field-label" for="bookingplus_whatsapp_url">WhatsApp link</label>
            <input type="url" id="bookingplus_whatsapp_url" name="bookingplus_whatsapp_url" maxlength="500" value="' . $wa . '" placeholder="https://wa.me/...">
        </div>
        <div class="field">
            <label class="field-label" for="bookingplus_nudge_hours">Nudge after (hours)</label>
            <input type="number" id="bookingplus_nudge_hours" name="bookingplus_nudge_hours" min="1" max="72" value="' . $nudge . '">
        </div>
    </div>';

        return $html;
    }

    public function saveSettings(array $post): void {
        if (!Auth::can('booking.manage_settings') && !Auth::isSuperAdmin()) return;

        foreach ($this->capabilities() as $capKey => $capDef) {
            $enabled = !empty($post['booking_cap_' . $capKey]);
            $this->setCapabilityEnabled((string)$capKey, $enabled);
        }

        BookingPlusAPI::saveSettings($post);
    }
}
