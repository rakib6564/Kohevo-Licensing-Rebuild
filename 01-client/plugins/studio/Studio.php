<?php
/**
 * Studio plugin — bootstrap.
 *
 * Wires the studio module into the Slate kernel: admin nav, and the cross-plugin
 * reactions it will grow into (booking / forms / stripe / customer lifecycle).
 * Schema is owned by the core migration runner (db/migrations/0005_studio_core.php,
 * `php bin/migrate migrate`) — this class does NOT create tables.
 *
 * The plugin ships INACTIVE; boot() only runs once it is installed + activated.
 * Every cross-plugin call is guarded (the emitters may be inactive), and the
 * event handlers are intentionally thin in Batch 1 — the real booking/membership
 * wiring lands in Batches 2–3 via dedicated adapters.
 *
 * Hook signatures are POSITIONAL (WordPress-style dispatcher), matching each
 * emitter exactly.
 */

declare(strict_types=1);

require_once __DIR__ . '/StudioAPI.php';

class Studio extends Plugin
{
    public function boot(): void
    {
        Hook::addFilter('admin_nav_items',         [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addAdminDashboardWidget']);
        Hook::addFilter('public_routes',           [$this, 'addPublicRoutes']);
        Hook::addFilter('i18n_lang_paths',         [$this, 'addLangPath']);

        // Customer portal surfaces. All three no-op for a signed-in customer
        // with no studio family, so a tenant running Studio alongside other
        // plugins doesn't show dance classes to someone who only books haircuts.
        Hook::addFilter('customer_nav_items',         [$this, 'addCustomerNav']);
        Hook::addFilter('customer_dashboard_kpis',    [$this, 'addCustomerKpis']);
        Hook::addFilter('customer_dashboard_widgets', [$this, 'addCustomerWidget']);

        // Parent notifications (registered / promoted off the waitlist / paid).
        require_once __DIR__ . '/StudioMail.php';
        StudioMail::register();

        // Cross-plugin reactions — positional args, matching the emitter.
        Hook::addAction('booking_created',      [$this, 'onBookingCreated']);     // ($id, $serviceId, $providerId)
        Hook::addAction('customer_registered',  [$this, 'onCustomerRegistered']); // ($customerId)
        Hook::addAction('forms_submitted',      [$this, 'onFormSubmitted']);      // ($submissionId, $formId, $data)
        Hook::addAction('stripe_webhook_event', [$this, 'onStripeEvent']);        // ($event)
    }

    public function addLangPath(array $paths): array
    {
        foreach (['fr', 'en'] as $loc) {
            $paths[$loc][] = $this->dir('lang');
        }
        return $paths;
    }

    /**
     * Public customer-facing area at /studio: class catalog (no login) + the
     * parent portal and self-serve registration (login-gated in the handler).
     */
    public function addPublicRoutes(array $routes): array
    {
        $routes['studio'] = [
            'handler' => $this->dir('public/router.php'),
            'methods' => ['GET', 'POST'],
        ];
        return $routes;
    }

    // ── Customer portal surface ───────────────────────────────

    /**
     * This parent's studio data, or null if they have none.
     *
     * Customers have no permission system, so a plugin gates its own portal
     * contributions on whether it has anything to say about *this* customer —
     * see the contract on Slate\Presentation\CustomerNav. Memoised because all
     * three callbacks below run on the same request.
     */
    private function parentSnapshot(): ?array
    {
        static $snap = false;
        if ($snap !== false) { return $snap; }
        $snap = null;
        if (!ModuleGuard::allows('dance-studio')) { return $snap; }

        if (!class_exists('Auth')) { return $snap; }
        $cid = (int) (Auth::customerId() ?? 0);
        if ($cid <= 0) { return $snap; }

        try {
            // The parent portal keys off the customer id as the parent contact,
            // matching public/views/portal.php.
            if (StudioAPI::getFamilyByParent($cid) === null) { return $snap; }
            $data = StudioAPI::getParentPortal($cid);
        } catch (\Throwable $e) {
            return $snap;   // a broken plugin must not take the whole portal down
        }

        $students = $data['students'] ?? [];
        $classes  = 0;
        $unpaid   = 0;
        $dueCents = 0;
        foreach ($students as $st) {
            foreach ($st['enrollments'] ?? [] as $en) {
                if (($en['status'] ?? '') === 'waitlist') { continue; }
                $classes++;
                if (empty($en['paid'])) { $unpaid++; $dueCents += (int) ($en['tuition_cents'] ?? 0); }
            }
        }

        return $snap = [
            'students'  => $students,
            'dancers'   => count($students),
            'classes'   => $classes,
            'unpaid'    => $unpaid,
            'due_cents' => $dueCents,
        ];
    }

    /** "My studio" in the shared portal nav, badged when tuition is outstanding. */
    public function addCustomerNav(array $items): array
    {
        $s = $this->parentSnapshot();
        if ($s === null) { return $items; }

        $items[] = [
            'slug'  => 'studio',
            'label' => __('studio_my_studio', 'My studio'),
            'href'  => SLATE_URL . '/studio?view=portal',
            'icon'  => 'music',
            'order' => 200,
            'group' => 'studio',
            'badge' => $s['unpaid'] > 0 ? (string) $s['unpaid'] : '',
        ];
        return $items;
    }

    /** Headline numbers for the portal home stat row. */
    public function addCustomerKpis(array $kpis): array
    {
        $s = $this->parentSnapshot();
        if ($s === null) { return $kpis; }

        $kpis[] = ['label' => __('studio_dancers', 'Dancers'),         'value' => (string) $s['dancers'], 'icon' => 'users'];
        $kpis[] = ['label' => __('studio_enrolled_classes', 'Classes'), 'value' => (string) $s['classes'], 'icon' => 'calendar'];
        if ($s['unpaid'] > 0) {
            $kpis[] = ['label' => __('studio_tuition_due', 'Tuition due'),
                       'value' => '$' . number_format($s['due_cents'] / 100, 2), 'icon' => 'card', 'tone' => 'amber'];
        }
        return $kpis;
    }

    /**
     * Summary card for the portal home: each dancer and what they're in, plus
     * an actionable prompt when tuition is outstanding. Previously a parent
     * signing in at /customer saw "No activity yet" while holding live
     * enrollments, because Studio contributed nothing here.
     */
    public function addCustomerWidget(array $widgets): array
    {
        $s = $this->parentSnapshot();
        if ($s === null) { return $widgets; }

        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $out  = '<section class="pcard">'
              . '<p class="pcard-eyebrow">' . e(__('studio_my_studio', 'My studio')) . '</p>';

        if ($s['unpaid'] > 0) {
            $out .= '<div class="upsell" style="margin-bottom:16px"><div class="upsell-txt">'
                  . '<b>' . e(sprintf(__('studio_due_title', '%s tuition outstanding'),
                        '$' . number_format($s['due_cents'] / 100, 2))) . '</b>'
                  . '<p>' . e(__('studio_due_sub', 'Pay online from your studio portal — or settle up at the front desk.')) . '</p>'
                  . '</div><a class="mbtn mbtn-primary" href="' . e(SLATE_URL . '/studio?view=portal') . '">'
                  . e(__('studio_pay_now', 'Pay now')) . '</a></div>';
        }

        foreach ($s['students'] as $st) {
            $name = (string) ($st['name'] ?: ('#' . ($st['id'] ?? '')));
            $enr  = $st['enrollments'] ?? [];
            $out .= '<div class="prog-row"><span class="prog-ic">'
                  . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span><div class="prog-main">'
                  . '<b>' . e($name) . '</b>';
            if (!$enr) {
                $out .= '<div class="prog-foot"><span>' . e(__('studio_not_enrolled', 'Not enrolled yet')) . '</span></div>';
            } else {
                foreach ($enr as $en) {
                    $when = ($days[(int) ($en['day_of_week'] ?? 0)] ?? '') . ' '
                          . substr((string) ($en['start_time'] ?? ''), 0, 5);
                    $out .= '<div class="prog-foot"><span>' . e((string) ($en['series_name'] ?? '')) . '</span>'
                          . '<span>' . e($when) . '</span></div>';
                }
            }
            $out .= '</div></div>';
        }

        $out .= '<p style="margin:16px 0 0"><a class="mbtn mbtn-ghost mbtn-block" href="'
              . e(SLATE_URL . '/studio?view=portal') . '">'
              . e(__('studio_open_portal', 'Open my studio')) . '</a></p></section>';

        $widgets[] = $out;
        return $widgets;
    }

    // ── Admin surface ─────────────────────────────────────────

    /**
     * Add the Studio admin section. Each item is added only if its target file
     * exists — no dead links, so future pages (classes/families/recitals) simply
     * appear as they land. The overview page ships in this build.
     */
    public function addAdminNav(array $items): array
    {
        if (!ModuleGuard::allows('dance-studio')) { return $items; }
        if (class_exists('Auth')
            && !Auth::can('studio.view_reports')
            && !Auth::can('studio.manage_classes')
            && !Auth::isSuperAdmin()) {
            return $items;
        }

        $pages = [
            ['index.php',       'studio',              'Studio',      'music',         'studio.view_reports',    619],
            // Declared before Classes and sharing its order: the sort is
            // stable, so Seasons sits directly above the classes it groups.
            ['seasons.php',     'studio_seasons',      'Seasons',     'calendar',      'studio.manage_classes',  620],
            ['classes.php',     'studio_classes',      'Classes',     'calendar',      'studio.manage_classes',  620],
            ['enrollments.php', 'studio_enrollments',  'Enrollments', 'clipboard-list','studio.manage_classes',  621],
            ['attendance.php',  'studio_attendance',   'Attendance',  'check-square',  'studio.manage_classes',  622],
            ['students.php',    'studio_students',     'Students',    'user',          'studio.manage_families', 623],
            ['families.php',    'studio_families',     'Families',    'users',         'studio.manage_families', 624],
            ['instructors.php', 'studio_instructors',  'Instructors', 'award',         'studio.manage_classes',  625],
            ['recitals.php',    'studio_recitals',     'Recitals',    'sparkles',      'studio.manage_recitals', 626],
            ['fees.php',        'studio_fees',         'Fees',        'tag',           'studio.manage_classes',  627],
            // Ties with Fees on purpose: the sort is stable, so declaring it
            // second places it directly after Fees without renumbering Reports
            // and Settings below.
            ['announcements.php','studio_announcements','Announcements','mail',         'studio.manage_classes',  627],
            ['reports.php',     'studio_reports',      'Reports',     'chart-bar',     'studio.view_reports',    628],
            ['settings.php',    'studio_settings',     'Settings',    'settings',      'studio.manage_classes',  629],
            ['roadmap.php',     'studio_roadmap',      'Features',    'clipboard',     'studio.view_reports',    630],
        ];
        foreach ($pages as [$file, $slug, $label, $icon, $perm, $order]) {
            if (!is_file($this->dir('admin/' . $file))) {
                continue;
            }
            $items[] = [
                'slug'  => $slug,
                'label' => function_exists('__') ? __($slug, $label) : $label,
                'href'  => $this->url('admin/' . $file),
                'icon'  => $icon,
                'perm'  => $perm,
                'order' => $order,
                'group' => 'studio',
            ];
        }
        return $items;
    }

    /**
     * A KPI card for the main admin dashboard: active classes, families and
     * active enrollments. Defensive — a missing table or query error must never
     * 500 the dashboard, so it silently drops out on failure.
     */
    public function addAdminDashboardWidget(array $widgets): array
    {
        if (!ModuleGuard::allows('dance-studio')) { return $widgets; }
        if (class_exists('Auth') && !Auth::can('studio.view_reports') && !Auth::isSuperAdmin()) {
            return $widgets;
        }

        $tid = current_tenant_id();
        try {
            $activeSeries = (int) Database::value(
                "SELECT COUNT(*) FROM studio_class_series WHERE tenant_id = ? AND is_active = 1", [$tid]);
            $families     = (int) Database::value(
                "SELECT COUNT(*) FROM studio_families WHERE tenant_id = ?", [$tid]);
            $activeEnroll = (int) Database::value(
                "SELECT COUNT(*) FROM studio_enrollments WHERE tenant_id = ? AND status IN ('active','trial')", [$tid]);
        } catch (\Throwable $e) {
            return $widgets;
        }

        $url = $this->url('admin/index.php');

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2><?= function_exists('__') ? __('studio', 'Studio') : 'Studio' ?></h2>
                <a href="<?= e($url) ?>" class="dwidget-all"><?= function_exists('__') ? __('view_all', 'View all') : 'View all' ?> →</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= function_exists('__') ? __('studio_active_classes', 'Active classes') : 'Active classes' ?></div>
                    <div class="dwidget-kpi-v"><?= $activeSeries ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= function_exists('__') ? __('studio_families', 'Families') : 'Families' ?></div>
                    <div class="dwidget-kpi-v"><?= $families ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k"><?= function_exists('__') ? __('studio_enrolled', 'Enrolled') : 'Enrolled' ?></div>
                    <div class="dwidget-kpi-v"><?= $activeEnroll ?></div>
                </div>
            </div>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }

    // ── Cross-plugin reactions (thin in Batch 1) ──────────────

    /**
     * A booking appointment was created. If it belongs to a studio class
     * occurrence we linked it ourselves at creation time (Batch 2's
     * BookingAdapter), so there is nothing to do here yet.
     */
    public function onBookingCreated(int $id, int $serviceId, int $providerId): void
    {
        if (!ModuleGuard::allows('dance-studio')) { return; }
        // Batch 2.
    }

    /** A customer/contact registered. Studio onboarding hooks land in Batch 5. */
    public function onCustomerRegistered(int $customerId): void
    {
        if (!ModuleGuard::allows('dance-studio')) { return; }
        // Batch 5.
    }

    /** A form was submitted (e.g. the liability waiver). Handled in Batch 3+. */
    public function onFormSubmitted(int $submissionId, int $formId, array $data): void
    {
        if (!ModuleGuard::allows('dance-studio')) { return; }
        // Batch 3+.
    }

    /**
     * A Stripe webhook event. Reconcile studio tuition checkouts: on a completed
     * session tagged source_plugin=studio, record the charge + mark the enrollment
     * paid (scoped to the tenant carried in the metadata).
     */
    public function onStripeEvent(array $event): void
    {
        if (!ModuleGuard::allows('dance-studio')) { return; }
        try {
            $type = (string) ($event['type'] ?? '');
            if ($type !== 'checkout.session.completed' && $type !== 'payment_intent.succeeded') {
                return;
            }
            $obj = $event['data']['object'] ?? [];
            $md  = $obj['metadata'] ?? [];
            if (($md['source_plugin'] ?? '') !== 'studio') {
                return;
            }
            $enrollmentId = (int) ($md['enrollment_id'] ?? 0);
            if ($enrollmentId <= 0) {
                return;
            }
            $amount    = (int) ($obj['amount_total'] ?? $obj['amount_received'] ?? $obj['amount'] ?? 0);
            $sessionId = (string) ($obj['id'] ?? '');
            $pi        = (string) ($obj['payment_intent'] ?? '');
            $email     = (string) ($obj['customer_details']['email'] ?? $obj['customer_email'] ?? '');
            $tenantId  = (int) ($md['tenant_id'] ?? 0);

            $apply = static function () use ($enrollmentId, $amount, $sessionId, $pi, $email): void {
                StudioAPI::markEnrollmentPaid($enrollmentId, $amount, $sessionId, $pi, $email);
            };
            if ($tenantId > 0 && class_exists('Slate\\Tenancy\\TenantContext')) {
                (new \Slate\Tenancy\TenantContext())->runAs($tenantId, $apply);
            } else {
                $apply();
            }
        } catch (\Throwable $e) {
            slate_log('Studio: stripe event handling failed: ' . $e->getMessage(), 'error');
        }
    }
}
