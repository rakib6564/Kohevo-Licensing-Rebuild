<?php
/**
 * Coaching plugin — bootstrap.
 *
 * Wave 1 surface: admin nav, customer nav, "Today" dashboard card,
 * customer_registered → provision empty profile.
 *
 * Access model:
 *   - Practitioner side: gated on the coaching.* permissions.
 *   - Client side: gated on MembershipAPI::isActive() when the membership
 *     plugin is present. Without membership, the plugin degrades to a
 *     preview / testing surface — practitioner still sees everything;
 *     the client just doesn't see nav entries.
 */

require_once __DIR__ . '/CoachingAPI.php';

class Coaching extends Plugin {

    public function boot(): void {
        // Schema self-heal, stamped per-version.
        if ((string) $this->setting('schema_verified', '') !== $this->version) {
            CoachingAPI::ensureSchema();
            $this->setSetting('schema_verified', $this->version);
        }

        // Admin surface.
        Hook::addFilter('admin_nav_items',            [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets',    [$this, 'addAdminDashboardWidget']);
        Hook::addFilter('i18n_lang_paths',            [$this, 'addLangPath']);

        // Customer surface.
        Hook::addFilter('customer_nav_items',         [$this, 'addCustomerNav']);
        Hook::addFilter('customer_dashboard_widgets', [$this, 'addCustomerDashboardWidget']);
        Hook::addFilter('customer_dashboard_kpis',    [$this, 'addCustomerDashboardKpi']);
        Hook::addFilter('customer_portal_context_nav', [$this, 'addContextNav'], 10, 2);
        Hook::addFilter('customer_portal_activity',    [$this, 'addCustomerActivity'], 10, 2);

        // Public route for the client-facing app pages.
        Hook::addFilter('public_routes',              [$this, 'addPublicRoutes']);

        // Provision an empty profile row + chat thread on new customer.
        Hook::addAction('customer_registered',        [$this, 'onCustomerRegistered']);

        // Deliver scheduled chat messages on cron ticks.
        Hook::addAction('frequent_cron',              [$this, 'runCron']);

        // MCP AI Gateway integration (expose coaching tools to AI assistants)
        require_once __DIR__ . '/CoachingMcpHandler.php';
        CoachingMcpHandler::register();

        // Design-system CSS:
        //   /admin/*     → admin.css (coaching admin pages)
        //   /coaching*   → customer.css (bento dashboard)
        //
        // These enqueues only run for an ACTIVE plugin, so the pages also emit
        // their own stylesheets via coaching_emit_css() — see includes/assets.php.
        // customer-shell.css is retired; the note below says why.
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (defined('SLATE_URL')) {
            if (str_contains($uri, '/admin/')) {
                $this->enqueueStyle('admin.css');
            }
            // customer-shell.css is deliberately no longer enqueued anywhere:
            // it is scoped entirely to `body:has(main.cust-content)`, which
            // nothing renders since the customer area moved to the shared
            // portal shell (main.mapp-main). Loading it only cost bandwidth.
            if (str_contains($uri, '/coaching')) {
                $this->enqueueStyle('customer.css');
            }
        }
    }

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    public function runCron(): void {
        try {
            CoachingAPI::deliverScheduled();
            CoachingAPI::generateSummariesForExpiringMemberships();
        } catch (\Throwable $e) {
            slate_log('Coaching cron failed: ' . $e->getMessage(), 'warning');
        }
    }

    // ── Admin nav ────────────────────────────────────────────────────────

    public function addAdminNav(array $items): array {
        if (!Auth::can('coaching.view_clients')
            && !Auth::can('coaching.manage_clients')
            && !Auth::isSuperAdmin()) {
            return $items;
        }
        $items[] = ['slug' => 'coaching',           'label' => 'Coaching overview',
                    'href' => $this->url('admin/index.php'),
                    'icon' => 'user', 'perm' => 'coaching.view_clients',
                    'order' => 620, 'group' => 'nutrition-app'];
        $items[] = ['slug' => 'coaching-clients',   'label' => 'Program clients',
                    'href' => $this->url('admin/clients.php'),
                    'icon' => 'users', 'perm' => 'coaching.view_clients',
                    'order' => 621, 'group' => 'nutrition-app'];
        $items[] = ['slug' => 'coaching-feed',      'label' => 'Client feed',
                    'href' => $this->url('admin/feed.php'),
                    'icon' => 'bell', 'perm' => 'coaching.view_clients',
                    'order' => 622, 'group' => 'nutrition-app'];
        try { $unread = CoachingAPI::totalUnreadForPractitioner(); } catch (\Throwable $e) { $unread = 0; }
        $items[] = ['slug' => 'coaching-chat',      'label' => 'Client chat' . ($unread > 0 ? ' (' . $unread . ')' : ''),
                    'href' => $this->url('admin/chat.php'),
                    'icon' => 'mail', 'perm' => 'coaching.reply_chat',
                    'order' => 623, 'group' => 'nutrition-app'];
        $items[] = ['slug' => 'coaching-library',   'label' => 'Library',
                    'href' => $this->url('admin/library.php'),
                    'icon' => 'folder', 'perm' => 'coaching.manage_library',
                    'order' => 624, 'group' => 'nutrition-app'];
        $items[] = ['slug' => 'coaching-settings',  'label' => 'Coaching settings',
                    'href' => $this->url('admin/settings.php'),
                    'icon' => 'settings', 'perm' => 'coaching.manage_clients',
                    'order' => 625, 'group' => 'nutrition-app'];
        return $items;
    }

    public function addAdminDashboardWidget(array $widgets): array {
        try {
            $tid = current_tenant_id();
            $enrolled = count(CoachingAPI::listEnrolledClients());
            $entriesToday = (int) Database::value(
                "SELECT COUNT(*) FROM coaching_diary_entry WHERE tenant_id = ? AND day = CURDATE()", [$tid]);
        } catch (\Throwable $e) { $enrolled = 0; $entriesToday = 0; }

        $clientsUrl = $this->url('admin/clients.php');
        $feedUrl    = $this->url('admin/feed.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2>Coaching</h2>
                <a href="<?= e($clientsUrl) ?>" class="dwidget-all">View all →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k">Enrolled</div>
                    <div class="dwidget-kpi-v"><?= (int)$enrolled ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k">Diary entries today</div>
                    <div class="dwidget-kpi-v"><a href="<?= e($feedUrl) ?>"><?= (int)$entriesToday ?></a></div>
                </div>
            </div>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ── Customer nav + dashboard ─────────────────────────────────────────

    /**
     * ONE entry in the shared customer nav — the way into the program.
     *
     * This used to register eleven: profile, goals, diary, charts, chat and
     * every optional module. Those are section navigation inside /coaching,
     * not destinations in a portal shared with booking, studio and membership,
     * and pushing them up here wrapped the global bar onto three lines. They
     * now render as a section sub-nav within the program itself, matching how
     * membership separates its own pages from the global bar.
     *
     * The badge carries unread chat so the reason to go in is visible without
     * eleven tabs shouting for it.
     */
    public function addCustomerNav(array $items): array {
        $c = Auth::customer();
        if (!$c) return $items;
        if (!CoachingAPI::isEnrolled((int)$c['id'])) return $items;

        $badge = '';
        try {
            $unread = (int) CoachingAPI::unreadForCustomer((int)$c['id']);
            if ($unread > 0) { $badge = (string) $unread; }
        } catch (\Throwable $e) {
            // A chat lookup failure must not cost the customer their nav entry.
        }

        $items[] = ['slug'  => 'coaching',
                    'label' => __('cc_program', 'Program'),
                    'href'  => SLATE_URL . '/coaching',
                    'icon'  => 'clipboard',
                    'order' => 300,
                    'group' => 'coaching',
                    'badge' => $badge];
        return $items;
    }

    /**
     * Headline KPI: daily goals checked in today. Only for enrolled
     * clients — for everyone else the program isn't a metric that means
     * anything, so no card at all beats a card reading 0.
     */
    public function addCustomerDashboardKpi(array $kpis): array {
        $c = Auth::customer();
        if (!$c) return $kpis;
        $cid = (int)$c['id'];

        try {
            if (!CoachingAPI::isEnrolled($cid)) return $kpis;
            $goals = CoachingAPI::listGoals($cid, 'daily', true);
            $total = is_array($goals) ? count($goals) : 0;

            // Table is singular, and the enum is not_achieved|partial|
            // achieved|exceeded — see plugins/coaching/install.sql:96.
            $done = (int) Database::value(
                "SELECT COUNT(*) FROM coaching_goal_checkin
                  WHERE tenant_id = ? AND customer_id = ? AND day = ?
                    AND status IN ('achieved','exceeded')",
                [current_tenant_id(), $cid, date('Y-m-d')]
            );
        } catch (\Throwable $e) {
            return $kpis;
        }

        $kpis[] = [
            'label' => 'Goals today',
            'value' => $total > 0 ? $done . '/' . $total : '—',
            'icon'  => 'target',
            'tone'  => $total > 0 && $done >= $total ? 'green' : 'blue',
            'meta'  => $total > 0
                ? 'Body &amp; Soul Program'
                : 'No daily goals set yet',
            'href'  => SLATE_URL . '/coaching?view=goals',
        ];
        return $kpis;
    }

    public function addCustomerDashboardWidget(array $widgets): array {
        $c = Auth::customer();
        if (!$c || !CoachingAPI::isEnrolled((int)$c['id'])) return $widgets;
        $cid = (int)$c['id'];

        $profile = CoachingAPI::getProfile($cid);
        $goals   = CoachingAPI::listGoals($cid, 'daily', true);
        $profileComplete = $profile && !empty($profile['dob']) && !empty($profile['height_cm']) && !empty($profile['weight_kg']);

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2>Body &amp; Soul Program</h2>
                <a href="<?= e(SLATE_URL . '/coaching') ?>" class="dwidget-all">Open →</a>
            </div>
            <?php if (!$profileComplete): ?>
                <p style="padding:0 var(--space-4) var(--space-4);font-size:14px;color:#64748b;margin:0;">
                    Your profile isn't complete yet —
                    <a href="<?= e(SLATE_URL . '/coaching?view=profile') ?>">fill it in</a>
                    so I can build your daily plan.
                </p>
            <?php else: ?>
                <p style="padding:0 var(--space-4);font-size:14px;color:#334155;margin:0 0 var(--space-3);">
                    Welcome back — today's goals at a glance:
                </p>
                <?php if (!$goals): ?>
                    <p style="padding:0 var(--space-4) var(--space-4);font-size:13px;color:#94a3b8;margin:0;">
                        No daily goals set yet.
                    </p>
                <?php else: ?>
                    <ul style="margin:0;padding:0 var(--space-4) var(--space-4) 40px;font-size:14px;color:#334155;">
                        <?php foreach (array_slice($goals, 0, 4) as $g): ?>
                            <li><?= e($g['title']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div style="padding:0 var(--space-4) var(--space-4);">
                    <a href="<?= e(SLATE_URL . '/coaching') ?>" class="btn btn-sm btn-primary">Open my program →</a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    public function addContextNav(array $items, array $context): array {
        if (($context['area'] ?? '') !== 'coaching') {
            return $items;
        }
        $cid = (int) ($context['customer_id'] ?? 0);
        if ($cid <= 0 || !CoachingAPI::isEnrolled($cid)) {
            return $items;
        }

        $active = (string) ($context['active_tab'] ?? 'home');
        $unread = 0;
        try { $unread = (int) CoachingAPI::unreadForCustomer($cid); } catch (\Throwable $e) {}

        $base = SLATE_URL . '/coaching?view=';
        $tabs = [
            ['home',    'home',      __('cc_home', 'Home')],
            ['diary',   'clipboard', __('cc_diary', 'Diary')],
            ['goals',   'check',     __('cc_goals', 'Goals')],
            ['charts',  'percent',   __('cc_charts', 'Charts')],
            ['chat',    'mail',      __('cc_chat', 'Chat')],
            ['profile', 'user',      __('cc_profile', 'Profile')],
        ];
        foreach ([['meal_structure', 'structure', 'coffee', 'Meal structure'],
                  ['shopping',       'shopping',  'clipboard', 'Shopping list'],
                  ['recipes',        'recipes',   'gift', 'Recipes']] as [$flag, $slot, $icon, $label]) {
            try {
                if (CoachingAPI::isModuleEnabled($cid, $flag)) { $tabs[] = [$slot, $icon, $label]; }
            } catch (\Throwable $e) {}
        }

        $owner = ['entry' => 'diary', 'recipe' => 'recipes', 'summary' => 'charts', 'motivation' => 'chat'];
        $activeTab = $owner[$active] ?? $active;

        foreach ($tabs as $i) {
            $items[] = [
                'id'     => $i[0],
                'label'  => $i[2] . ($i[0] === 'chat' && $unread > 0 ? ' (' . $unread . ')' : ''),
                'href'   => $base . $i[0],
                'icon'   => $i[1],
                'active' => ($activeTab === $i[0]),
            ];
        }
        return $items;
    }

    public function addCustomerActivity(array $events, array $context): array {
        $cid = (int) ($context['customer_id'] ?? 0);
        if ($cid <= 0 || !CoachingAPI::isEnrolled($cid)) {
            return $events;
        }

        try {
            $checkins = Database::rows(
                "SELECT c.*, g.title as goal_title
                   FROM coaching_goal_checkin c
                   JOIN coaching_goals g ON g.id = c.goal_id
                  WHERE c.customer_id = ? AND c.tenant_id = ?
               ORDER BY c.created_at DESC LIMIT 5",
                [$cid, current_tenant_id()]
            );
            foreach ($checkins as $ch) {
                $status = (string)($ch['status'] ?? '');
                $tone = in_array($status, ['achieved', 'exceeded'], true) ? 'green' : 'amber';
                $events[] = [
                    'id'          => 'coaching-checkin-' . $ch['id'],
                    'type'        => 'coaching',
                    'label'       => 'Goal Check-in: ' . ($ch['goal_title'] ?? 'Goal'),
                    'description' => ucfirst(str_replace('_', ' ', $status)) . ' on ' . I18n::localDate('M j, Y', strtotime($ch['day'] ?? 'now')),
                    'occurred_at' => $ch['created_at'] ?? ($ch['day'] . ' 12:00:00'),
                    'href'        => SLATE_URL . '/coaching?view=goals',
                    'icon'        => 'target',
                    'tone'        => $tone,
                ];
            }
        } catch (\Throwable $e) {}

        return $events;
    }

    // ── Public route registration ────────────────────────────────────────

    public function addPublicRoutes(array $routes): array {
        $routes['coaching'] = [
            'handler' => $this->dir('customer/router.php'),
            'methods' => ['GET', 'POST'],
        ];
        return $routes;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    public function onCustomerRegistered($customerOrId): void {
        try {
            $cid = is_array($customerOrId) ? (int)($customerOrId['id'] ?? 0) : (int)$customerOrId;
            if ($cid > 0) {
                CoachingAPI::provisionProfile($cid);
                CoachingAPI::ensureThread($cid);
            }
        } catch (\Throwable $e) {
            slate_log('Coaching provisionProfile failed: ' . $e->getMessage(), 'warning');
        }
    }
}
