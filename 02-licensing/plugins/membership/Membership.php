<?php
/**
 * Membership plugin — bootstrap.
 *
 * Registers admin nav, an admin dashboard KPI widget, the member dashboard
 * status card, FR/EN language packs, and the customer-registered hook that
 * provisions a member profile + wallet. Schema is kept current by the
 * idempotent MembershipAPI::ensureSchema() self-heal (mirrors Booking/Stripe),
 * stamped per-version so it only replays after an upgrade.
 *
 * Phase 1 surfaces: Plans CRUD, Settings, and the overview index. Purchase /
 * Stripe checkout, the onboarding wizard, and the Booking gate land in later
 * phases on top of MembershipAPI.
 */

require_once __DIR__ . '/MembershipAPI.php';

class Membership extends Plugin {

    public function boot(): void {
        // Schema self-heal, stamped once per version. ensureSchema() is
        // idempotent (CREATE TABLE IF NOT EXISTS), so replaying is safe; the
        // version stamp just skips the work on the hot path.
        if ((string) $this->setting('schema_verified', '') !== $this->version) {
            MembershipAPI::ensureSchema();
            $this->setSetting('schema_verified', $this->version);
        }

        Hook::addFilter('admin_nav_items',            [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets',    [$this, 'addAdminDashboardWidget']);
        Hook::addFilter('customer_dashboard_widgets', [$this, 'addCustomerDashboardWidget']);
        Hook::addFilter('customer_dashboard_kpis',    [$this, 'addCustomerDashboardKpi']);
        Hook::addFilter('customer_nav_items',         [$this, 'addCustomerNav']);
        Hook::addFilter('customer_portal_context_nav', [$this, 'addContextNav'], 10, 2);
        Hook::addFilter('customer_portal_activity',    [$this, 'addCustomerActivity'], 10, 2);
        Hook::addFilter('customer_portal_avatar',     [$this, 'portalAvatar'], 10, 2);
        Hook::addFilter('public_routes',              [$this, 'addPublicRoutes']);

        // Content Builder: advertise plans on any page. Boot order between
        // plugins is not guaranteed, so register now if the builder is already
        // up, else catch its hook — the same pattern forms and booking use, and
        // the thing the reachability test actually guards.
        $this->registerContentBlock();

        // The block's stylesheet, only for pages that carry the block.
        Hook::addFilter('content_head_tags',          [$this, 'injectPlanAssets'], 10, 2);

        // Activate a membership when its Stripe checkout completes (routed
        // through the stripe-payment plugin, same pattern as Booking).
        Hook::addAction('stripe_webhook_event', [$this, 'onStripeEvent']);

        // Booking integration: enforce the active-membership / profile / insurance
        // gate on self-service bookings. Inert until Booking fires the filter.
        Hook::addFilter('booking_can_book', [$this, 'gateBooking'], 10, 2);

        // Flag service cards with a "Membership required" pill when the gate
        // above is on, so the requirement is visible before a customer picks
        // a service, not just after they hit the gate at checkout.
        Hook::addFilter('booking_service_card_badges', [$this, 'addServiceCardBadge'], 10, 2);

        // Bilingual UI — French primary. Register the extra language and our
        // plugin lang-pack directory so __() resolves membership_* keys.
        Hook::addFilter('i18n_supported_languages', [$this, 'addLanguage']);
        Hook::addFilter('i18n_lang_paths',          [$this, 'addLangPath']);

        // When a customer registers, provision their member profile + wallet
        // so the onboarding wizard (Phase 3) has a row to fill in.
        Hook::addAction('customer_registered', [$this, 'onCustomerRegistered']);

        // MCP AI Gateway integration (expose membership tools to AI assistants)
        require_once __DIR__ . '/MembershipMcpHandler.php';
        MembershipMcpHandler::register();
    }

    // ── i18n ─────────────────────────────────────────────────────────────

    public function addLanguage(array $langs): array {
        // multilang-translate, when active, is the authoritative, admin-
        // editable language registry -- it already contributes every
        // enabled language (including French) via this same filter, backed
        // by a real DB table with enable/disable + custom naming. Hardcoding
        // French here too meant two sources of truth for the same entry,
        // with this one blind to whether the admin had actually disabled or
        // renamed it. Only fall back to the hardcode when that plugin isn't
        // installed, so a standalone Membership install (no Translations
        // plugin) still gets its bundled fr.php lang pack recognised.
        if (!class_exists('PluginLoader') || !PluginLoader::isActive('multilang-translate')) {
            if (!isset($langs['fr'])) $langs['fr'] = 'Français';
        }
        return $langs;
    }

    public function addLangPath(array $paths): array {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    // ── Lifecycle hooks ──────────────────────────────────────────────────

    /** Register the "Membership plans" block (order-independent). */
    private function registerContentBlock(): void {
        $register = function ($registry) {
            $registry::register('membership-plans', [
                'label'  => 'Membership plans',
                'group'  => 'Membership',
                'fields' => [
                    ['key' => 'plan', 'type' => 'select', 'label' => 'Plan',
                     'options' => MembershipAPI::blockPickerOptions()],
                ],
                'render'   => ['MembershipAPI', 'renderContentBlock'],
                'defaults' => ['plan' => ''],
            ]);
        };

        if (class_exists('BlockRegistry')) {
            $register('BlockRegistry');                        // builder already booted
        } else {
            Hook::addAction('content_register_blocks', $register);  // builder boots later
        }
    }

    /** Block stylesheet, once, and only where the block is used. */
    public function injectPlanAssets($headTags, $post = null): string {
        $headTags = (string) $headTags;

        $layout = is_array($post) ? ($post['layout'] ?? []) : [];
        if (!class_exists('ContentBuilderAPI')
            || !ContentBuilderAPI::layoutHasBlock($layout, 'membership-plans')) {
            return $headTags;
        }

        return $headTags . MembershipAPI::blockCss();
    }

    public function addPublicRoutes(array $routes): array {
        // Public marketing / join landing page (no login required).
        $routes['membership'] = [
            'handler' => $this->dir('public/landing.php'),
            'methods' => ['GET'],
        ];
        // Feature area in the shared customer portal.
        $routes['member/membership'] = [
            'handler' => $this->dir('public/router.php'),
            'methods' => ['GET', 'POST'],
        ];
        return $routes;
    }

    public function onStripeEvent(array $event): void {
        // Guard the EFFECT, not the webhook receipt (07 §2) — mirrors
        // Booking::onStripeEvent(). The HTTP ack to Stripe happens in the
        // webhook controller regardless; this only stops an unentitled
        // installation from activating a membership subscription.
        if (!ModuleGuard::allows('membership')) return;
        try {
            MembershipAPI::handleStripeEvent($event);
        } catch (\Throwable $e) {
            slate_log('Membership: stripe event handling failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Booking gate. Receives the running ['ok'=>bool,...] decision plus a
     * context array (customer_id, service, …). Returns a blocking result when
     * a membership rule fails; otherwise passes the decision through unchanged.
     *
     * Rules (each toggleable in Membership settings, default on):
     *   • active membership required to book — only for services with an
     *     active course-specific plan attached (MembershipAPI::serviceRequiresMembership)
     *   • completed member profile required to book
     *   • active insurance required for services flagged insurance-required
     */
    public function gateBooking($gate, array $ctx = []) {
        // Respect an earlier listener that already blocked.
        if (is_array($gate) && array_key_exists('ok', $gate) && $gate['ok'] === false) {
            return $gate;
        }

        $cid   = isset($ctx['customer_id']) ? (int)$ctx['customer_id'] : 0;
        $svcId = (int)($ctx['service']['id'] ?? 0);

        // Membership is only required for services an admin actually attached
        // a course-specific plan to — the global toggle is a master switch,
        // not a blanket "every service needs membership" rule.
        $requireMembership = (string) Database::setting('membership.require_membership_to_book') !== '0' // default on
            && MembershipAPI::serviceRequiresMembership($svcId);
        // A profile can only be required while the completion step itself is enabled.
        $requireProfile    = MembershipAPI::profileCompletionEnabled()
            && (string) Database::setting('membership.require_profile_to_book') !== '0'; // default on

        $block = fn(string $msg) => ['ok' => false, 'error' => $msg];

        // Any active gate requires a signed-in member.
        if (($requireMembership || $requireProfile) && $cid <= 0) {
            return $block(__('membership_gate_login', 'Please sign in as a member to book.'));
        }

        if ($requireMembership) {
            // isActive() only recognizes general 'membership'-type plans — a
            // service gated by a 'course'-type plan (the only kind
            // serviceRequiresMembership() reacts to) needs its own check,
            // one that also accounts for the plan's session quota so access
            // correctly re-locks once the credits are used up.
            $access = MembershipAPI::courseSubscriptionStatus($cid, $svcId);
            if (!$access['has_access']) {
                $msg = $access['plan_id'] !== null
                    ? __('membership_gate_sessions_used', 'You have used all the sessions included in your plan. Please renew or upgrade to keep booking.')
                    : __('membership_gate_active', 'An active membership is required to book.');
                return $block($msg);
            }
        }

        if ($requireProfile) {
            $p = MembershipAPI::profile($cid);
            if (!$p || empty($p['onboarding_complete'])) {
                return $block(__('membership_gate_profile', 'Please complete your member profile before booking.'));
            }
        }

        // Insurance: services listed in membership.insurance_required_services
        // need the member to hold an active insurance plan.
        if ($svcId > 0) {
            $insSvcs = array_filter(array_map('intval',
                explode(',', (string) Database::setting('membership.insurance_required_services'))));
            if (in_array($svcId, $insSvcs, true) && !MembershipAPI::hasInsurance($cid)) {
                return $block(__('membership_gate_insurance', 'This course requires active insurance.'));
            }
        }

        return $gate;
    }

    /** Same rule gateBooking() enforces — surfaced as a card badge, not just a checkout-time block. */
    public function addServiceCardBadge(array $badges, array $service): array {
        $requireMembership = (string) Database::setting('membership.require_membership_to_book') !== '0' // default on
            && MembershipAPI::serviceRequiresMembership((int)($service['id'] ?? 0));
        if ($requireMembership) {
            $badges[] = ['label' => __('membership_badge_required', 'Membership required'), 'tone' => 'accent'];
        }
        return $badges;
    }

    public function onCustomerRegistered(int $customerId): void {
        try {
            MembershipAPI::ensureProfile($customerId);
            MembershipAPI::ensureWallet($customerId);
        } catch (\Throwable $e) {
            slate_log('Membership: provisioning on register failed: ' . $e->getMessage(), 'error');
        }
    }

    // ── Admin navigation ─────────────────────────────────────────────────

    public function addAdminNav(array $items): array {
        if (!Auth::can('membership.view') && !Auth::isSuperAdmin()) return $items;

        $items[] = ['slug' => 'membership', 'label' => __('membership_nav_overview', 'Membership overview'),
                    'href' => $this->url('admin/index.php'),
                    'icon' => 'id-card', 'order' => 500, 'group' => 'membership'];
        $items[] = ['slug' => 'membership-members', 'label' => __('membership_members', 'Members'),
                    'href' => $this->url('admin/members.php'),
                    'icon' => 'users', 'perm' => 'membership.view', 'order' => 501, 'group' => 'membership'];
        $items[] = ['slug' => 'membership-plans', 'label' => __('membership_plans', 'Plans'),
                    'href' => $this->url('admin/plans.php'),
                    'icon' => 'tag', 'perm' => 'membership.manage_plans', 'order' => 502, 'group' => 'membership'];
        $items[] = ['slug' => 'membership-settings', 'label' => __('membership_settings', 'Membership settings'),
                    'href' => $this->url('admin/settings.php'),
                    'icon' => 'settings', 'perm' => 'membership.manage_settings', 'order' => 503, 'group' => 'membership'];
        return $items;
    }

    // ── Admin dashboard KPI widget ───────────────────────────────────────

    public function addAdminDashboardWidget(array $widgets): array {
        if (!Auth::can('membership.view') && !Auth::isSuperAdmin()) return $widgets;
        $tid = current_tenant_id();
        try {
            MembershipAPI::ensureSchema();
            $active = (int) Database::value(
                "SELECT COUNT(*) FROM membership_subscriptions
                  WHERE tenant_id = ? AND status = 'active'
                    AND (expires_at IS NULL OR COALESCE(grace_until, expires_at) >= NOW())", [$tid]);
            $expiring = (int) Database::value(
                "SELECT COUNT(*) FROM membership_subscriptions
                  WHERE tenant_id = ? AND status = 'active'
                    AND expires_at IS NOT NULL
                    AND expires_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY", [$tid]);
            $plans = (int) Database::value(
                "SELECT COUNT(*) FROM membership_plans WHERE tenant_id = ? AND is_active = 1", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }
        $membersUrl = $this->url('admin/members.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= __('membership', 'Membership') ?></h2>
                <a href="<?= e($membersUrl) ?>" class="dwidget-all"><?= __('view_all', 'View all') ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('membership_active', 'Active') ?></div>
                    <div class="dwidget-kpi-v"><?= $active ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('membership_expiring_7', 'Expiring ≤7d') ?></div>
                    <div class="dwidget-kpi-v"><?= $expiring ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= __('membership_plans', 'Plans') ?></div>
                    <div class="dwidget-kpi-v"><?= $plans ?></div>
                </div>
            </div>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ── Member dashboard status card ─────────────────────────────────────

    /**
     * Headline KPI: days of membership remaining. Goes amber inside 30 days
     * — a membership about to lapse is the one thing on this dashboard the
     * customer may need to act on.
     */
    public function addCustomerDashboardKpi(array $kpis): array {
        $cid = Auth::customerId();
        if ($cid === null) return $kpis;

        try {
            $status = MembershipAPI::status($cid);
        } catch (\Throwable $e) {
            return $kpis;
        }

        $sub  = $status['sub'] ?? null;
        $days = $status['days_left'] ?? null;
        if (!$sub) return $kpis;

        if ($days === null) {
            // Active with no expiry (lifetime / rolling).
            $kpis[] = [
                'label' => 'Membership',
                'value' => 'Active',
                'icon'  => 'star',
                'tone'  => 'green',
                'meta'  => e((string)($sub['plan_name'] ?? '')),
            ];
            return $kpis;
        }

        $days = (int)$days;
        $kpis[] = [
            'label' => 'Membership',
            'value' => (string)max(0, $days),
            'unit'  => $days === 1 ? 'day left' : 'days left',
            'icon'  => 'star',
            'tone'  => $days <= 0 ? 'amber' : ($days <= 30 ? 'amber' : 'green'),
            'meta'  => !empty($sub['expires_at'])
                ? 'Renews <strong>' . e(slate_format_date((string)$sub['expires_at'])) . '</strong>'
                : e((string)($sub['plan_name'] ?? '')),
        ];
        return $kpis;
    }

    /**
     * "Membership" in the shared customer portal nav.
     *
     * Only for someone who actually holds a subscription — /membership is a
     * marketing landing page and belongs in site navigation, not in the
     * signed-in portal's chrome. The member area keeps its own internal tabs
     * (Plans / Card / Schedule / Profile); this is the way in from /customer.
     */
    public function addCustomerNav(array $items): array {
        $cid = Auth::customerId();
        if ($cid === null) return $items;

        $items[] = ['slug'  => 'membership',
                    'label' => __('membership', 'Membership'),
                    'href'  => SLATE_URL . '/member/membership/overview',
                    'icon'  => 'star', 'order' => 15, 'group' => 'main'];
        return $items;
    }

    public function addContextNav(array $items, array $context): array {
        if (($context['area'] ?? '') !== 'membership') {
            return $items;
        }
        $cid = (int) ($context['customer_id'] ?? 0);
        if ($cid <= 0) {
            return $items;
        }

        $active = (string) ($context['active_tab'] ?? 'overview');
        if ($active === 'home') $active = 'overview';

        $tabs = [
            ['id' => 'overview', 'label' => __('membership_overview', 'Overview'), 'href' => SLATE_URL . '/member/membership/overview', 'icon' => 'grid'],
            ['id' => 'plans',    'label' => __('membership_plans', 'Plans'),       'href' => SLATE_URL . '/member/membership/plans',    'icon' => 'tag'],
        ];
        if (MembershipAPI::cardEnabled()) {
            $tabs[] = ['id' => 'card', 'label' => __('membership_card', 'Card'), 'href' => SLATE_URL . '/member/membership/card', 'icon' => 'qr'];
        }
        $tabs[] = ['id' => 'schedule', 'label' => __('membership_schedule', 'Schedule'), 'href' => SLATE_URL . '/member/membership/schedule', 'icon' => 'calendar'];
        $tabs[] = ['id' => 'profile',  'label' => __('membership_profile', 'Profile'),   'href' => SLATE_URL . '/member/membership/profile',  'icon' => 'user'];

        foreach ($tabs as $t) {
            $items[] = [
                'id'     => $t['id'],
                'label'  => $t['label'],
                'href'   => $t['href'],
                'icon'   => $t['icon'],
                'active' => ($active === $t['id']),
            ];
        }
        return $items;
    }

    public function addCustomerActivity(array $events, array $context): array {
        $cid = (int) ($context['customer_id'] ?? 0);
        if ($cid <= 0) {
            return $events;
        }

        try {
            $subs = Database::rows(
                "SELECT ms.*, mp.name as plan_name, mp.name_fr as plan_name_fr
                   FROM membership_subscriptions ms
                   LEFT JOIN membership_plans mp ON mp.id = ms.plan_id
                  WHERE ms.customer_id = ? AND ms.tenant_id = ?
               ORDER BY ms.created_at DESC LIMIT 5",
                [$cid, current_tenant_id()]
            );
            foreach ($subs as $s) {
                $planName = MembershipAPI::planName(['name' => $s['plan_name'] ?? '', 'name_fr' => $s['plan_name_fr'] ?? '']);
                $events[] = [
                    'id'          => 'membership-sub-' . $s['id'],
                    'type'        => 'membership',
                    'label'       => __('membership', 'Membership') . ': ' . $planName,
                    'description' => ucfirst((string)$s['status']) . ' subscription (' . slate_format_date($s['created_at']) . ')',
                    'occurred_at' => $s['created_at'],
                    'href'        => SLATE_URL . '/member/membership/overview',
                    'icon'        => 'star',
                    'tone'        => $s['status'] === 'active' ? 'green' : 'amber',
                ];
            }
        } catch (\Throwable $e) {}

        return $events;
    }

    /**
     * Membership stores the only customer photo in the product — core's
     * customers table has no avatar column — so hand it to the shared portal
     * shell, which falls back to Gravatar and then initials on its own.
     */
    public function portalAvatar(string $url, int $customerId): string {
        if ($url !== '' || $customerId <= 0) return $url;
        try {
            $path = (string) (MembershipAPI::profile($customerId)['avatar_path'] ?? '');
        } catch (\Throwable $e) {
            return $url;
        }
        return $path !== '' ? SLATE_URL . $path : $url;
    }

    public function addCustomerDashboardWidget(array $widgets): array {
        $cid = Auth::customerId();
        if ($cid === null) return $widgets;

        try {
            $status = MembershipAPI::status($cid);
        } catch (\Throwable $e) {
            return $widgets;
        }

        $plansUrl = $this->url('public/router.php?view=plans');
        $sub      = $status['sub'];

        ob_start(); ?>
        <div class="card">
            <div class="card-header"><h2><?= __('membership_your', 'Your membership') ?></h2></div>
            <?php if ($sub):
                $name = MembershipAPI::planName(['name' => $sub['plan_name'] ?? '', 'name_fr' => $sub['plan_name_fr'] ?? '']);
                $badge = $status['state'] === 'expiring' ? 'badge-warning' : 'badge-active';
                $label = $status['state'] === 'expiring' ? __('membership_expiring', 'Expiring soon') : __('membership_active', 'Active');
            ?>
                <ul class="kv-list">
                    <li class="kv-row">
                        <span class="kv-label"><?= __('membership_plan', 'Plan') ?></span>
                        <span class="kv-value"><strong><?= e($name) ?></strong></span>
                    </li>
                    <li class="kv-row">
                        <span class="kv-label"><?= __('membership_status', 'Status') ?></span>
                        <span class="kv-value"><span class="badge <?= $badge ?>"><?= e($label) ?></span></span>
                    </li>
                    <?php if (!empty($sub['expires_at'])): ?>
                    <li class="kv-row">
                        <span class="kv-label"><?= __('membership_expires', 'Expires') ?></span>
                        <span class="kv-value">
                            <?= e(slate_format_date($sub['expires_at'])) ?>
                            <?php if ($status['days_left'] !== null): ?>
                                <span class="text-xs text-muted">· <?= (int)$status['days_left'] ?> <?= __('membership_days_left', 'days left') ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                    <?php endif; ?>
                </ul>
            <?php else: ?>
                <div class="empty">
                    <div class="empty-title"><?= __('membership_none_title', 'No active membership') ?></div>
                    <p class="text-sm"><?= __('membership_none_sub', 'Choose a plan to start booking sessions.') ?></p>
                    <a href="<?= e($plansUrl) ?>" class="btn btn-primary btn-sm"><?= __('membership_view_plans', 'View plans') ?></a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }
}
